<?php
/**
 * Yoast SEO per-plugin abilities (Wave 5 Slice B): yoast-get-post, yoast-update-post,
 * yoast-get-head.
 *
 * Yoast is not installed on the test site, so the fixture forces the yoast predicate active and
 * defines the minimal host signal (WPSEO_VERSION + the rendered-head filter) via stub_yoast(). The
 * abilities read/write the _yoast_wpseo_* post meta with core get_post_meta/update_post_meta.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use WP_Error;

final class YoastTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'yoast' );
		$this->stub_yoast();
		aafm_registry_cache_should_flush( true );
		$this->register_yoast();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Enable + register the Yoast set so the abilities can be invoked.
	 */
	private function register_yoast(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/yoast-get-post', 'aafm/yoast-update-post', 'aafm/yoast-get-head' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * The SEO per-object gate must honor the operator's post-type exposure allowlist: a public,
	 * map_meta_cap CPT the admin CAN edit but which is NOT exposed (only post/page are on by
	 * default) is refused - SEO meta on a non-exposed type is out of scope, exactly as the core
	 * content writes refuse it. A normal post stays allowed for the same admin.
	 */
	public function test_seo_gate_denies_a_non_exposed_cpt(): void {
		register_post_type(
			'aafm_seo_secret',
			array(
				'public'       => true,
				'map_meta_cap' => true,
			)
		);
		delete_option( 'aafm_allowed_post_types' ); // post/page always-on; nothing else exposed.

		$admin_id = $this->acting_as( 'administrator' );
		$cpt_id   = (int) self::factory()->post->create(
			array(
				'post_type'   => 'aafm_seo_secret',
				'post_author' => $admin_id,
			)
		);

		$this->assertTrue( current_user_can( 'edit_post', $cpt_id ), 'the admin can edit the CPT post itself.' );
		$this->assertFalse(
			aafm_perm_seo_post_object( array( 'post_id' => $cpt_id ) ),
			'the SEO gate must deny a non-exposed CPT even when the user can edit the post.'
		);

		$post_id = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		$this->assertTrue(
			aafm_perm_seo_post_object( array( 'post_id' => $post_id ) ),
			'a normal, exposed post stays allowed for the same admin.'
		);

		unregister_post_type( 'aafm_seo_secret' );
	}

	public function test_yoast_get_post_reads_mapped_fields_per_object_gated(): void {
		$editor_id = $this->acting_as( 'editor' );
		$post_id   = (int) self::factory()->post->create(
			array(
				'post_author' => $editor_id,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_yoast_wpseo_title', 'SEO Title' );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'A description.' );

		$res = wp_get_ability( 'aafm/yoast-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'yoast', $res['plugin'] );
		$this->assertSame( $post_id, $res['post_id'] );
		$this->assertSame( 'SEO Title', $res['title'] );
		$this->assertSame( 'A description.', $res['description'] );
		$this->assertArrayHasKey( 'focus_keyword', $res );
		$this->assertArrayHasKey( 'canonical', $res );
	}

	public function test_yoast_get_post_exposes_three_robots_keys_distinctly(): void {
		// Yoast splits robots across three meta keys; the read must expose robots_noindex,
		// robots_nofollow, and robots_adv distinctly rather than mush them into one string.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
		update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', '1' );
		update_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', 'noarchive,nosnippet' );

		$res = wp_get_ability( 'aafm/yoast-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( '1', $res['robots_noindex'] );
		$this->assertSame( '1', $res['robots_nofollow'] );
		$this->assertSame( 'noarchive,nosnippet', $res['robots_adv'] );
		$this->assertArrayNotHasKey( 'robots', $res, 'Yoast must not expose a single mushed robots string.' );
	}

	public function test_yoast_get_post_reads_array_meta_as_empty_without_warning(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		update_post_meta( $post_id, '_yoast_wpseo_title', array( 'unexpected', 'array' ) );

		$res = wp_get_ability( 'aafm/yoast-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( '', $res['title'] );
	}

	public function test_yoast_get_post_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/yoast-get-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_yoast_get_post_denies_an_author_on_anothers_post(): void {
		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/yoast-get-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_yoast_get_post_rejects_a_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/yoast-get-post' )->execute(
			array(
				'post_id' => $post_id,
				'plugin'  => 'rankmath',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema rejects a smuggled field.' );
	}

	public function test_yoast_update_post_round_trips_every_field(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$payload = array(
			'post_id'             => $post_id,
			'title'               => 'My Title',
			'description'         => 'My description.',
			'focus_keyword'       => 'widgets',
			'canonical'           => 'https://example.com/canonical',
			'og_title'            => 'OG Title',
			'og_description'      => 'OG description.',
			'og_image'            => 'https://example.com/og.jpg',
			'twitter_title'       => 'TW Title',
			'twitter_description' => 'TW description.',
			'twitter_image'       => 'https://example.com/tw.jpg',
			'robots_noindex'      => '1',
			'robots_nofollow'     => '1',
			'robots_adv'          => 'noarchive,nosnippet',
		);
		$res     = wp_get_ability( 'aafm/yoast-update-post' )->execute( $payload );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A full Yoast write must succeed.' );

		$read = wp_get_ability( 'aafm/yoast-get-post' )->execute( array( 'post_id' => $post_id ) );
		foreach ( $payload as $field => $value ) {
			if ( 'post_id' === $field ) {
				continue;
			}
			$this->assertSame( $value, $read[ $field ], $field . ' did not round-trip.' );
		}
	}

	public function test_yoast_update_post_url_fields_are_url_sanitized(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/yoast-update-post' )->execute(
			array(
				'post_id'       => $post_id,
				'canonical'     => 'javascript:alert(1)',
				'og_image'      => 'javascript:alert(2)',
				'twitter_image' => 'javascript:alert(3)',
			)
		);
		$this->assertSame( '', get_post_meta( $post_id, '_yoast_wpseo_canonical', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_yoast_wpseo_twitter-image', true ) );
	}

	public function test_yoast_update_post_robots_noindex_rejects_an_out_of_enum_value(): void {
		// robots_noindex is an enum 0/1/2; a value outside it must not persist.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/yoast-update-post' )->execute(
			array(
				'post_id'        => $post_id,
				'robots_noindex' => '9',
			)
		);
		$this->assertSame( '', get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ), 'An out-of-enum noindex must be dropped.' );
	}

	/**
	 * Yoast's real interpretation of the stored _yoast_wpseo_meta-robots-noindex value, mirrored from
	 * wordpress-seo/src/builders/indexable-post-builder.php::get_robots_noindex: '1' -> noindex (true),
	 * '2' -> index (false), anything else -> the post-type/site default (null). Yoast is not loaded
	 * under PHPUnit, so this encodes the vendor's verified semantics as the ground-truth oracle.
	 *
	 * @param string $stored The stored meta-robots-noindex value.
	 * @return bool|null True = noindex, false = index, null = default.
	 */
	private function yoast_noindex_meaning( string $stored ): ?bool {
		switch ( (int) $stored ) {
			case 1:
				return true;  // No-index.
			case 2:
				return false; // Index.
			default:
				return null;  // Post-type / site default.
		}
	}

	public function test_yoast_robots_noindex_enum_means_what_yoast_means(): void {
		// MEANING, not round-trip. The value the contract labels "index" must make Yoast actually
		// index the page, and Yoast's stored noindex value must read back as "noindex" under the
		// contract. A pure write-1/read-1 round-trip passes even while the contract's labels are
		// inverted (the executor is a raw passthrough), so it cannot catch this bug.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$meaning = aafm_yoast_robots_noindex_meaning();

		// Write direction: an agent told to INDEX the page uses whatever value the contract says means
		// "index". Once the plugin stores it, Yoast must treat that value as index, not noindex.
		$index_value = (string) array_search( 'index', $meaning, true );
		$this->assertNotSame( '', $index_value, 'The contract must define a value that means index.' );
		wp_get_ability( 'aafm/yoast-update-post' )->execute(
			array(
				'post_id'        => $post_id,
				'robots_noindex' => $index_value,
			)
		);
		$stored = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		$this->assertFalse(
			$this->yoast_noindex_meaning( $stored ),
			'The contract\'s "index" value must make Yoast index the page, not de-index it.'
		);

		// Read direction: seed the value Yoast stores for noindex; the contract must report noindex.
		$yoast_noindex_value = '1'; // wordpress-seo stores 1 = No-index.
		$this->assertTrue( $this->yoast_noindex_meaning( $yoast_noindex_value ), 'Guard: Yoast 1 = noindex.' );
		update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $yoast_noindex_value );
		$read = wp_get_ability( 'aafm/yoast-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame(
			'noindex',
			$meaning[ $read['robots_noindex'] ] ?? 'MISSING',
			'Yoast\'s stored noindex value must read back as noindex under the contract.'
		);
	}

	public function test_yoast_update_post_robots_adv_drops_unknown_tokens(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/yoast-update-post' )->execute(
			array(
				'post_id'    => $post_id,
				'robots_adv' => 'noarchive,evil,nosnippet',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'noarchive,nosnippet', $res['robots_adv'], 'An unknown adv token must be dropped.' );
	}

	public function test_yoast_update_post_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/yoast-update-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_yoast_update_post_denies_an_author_on_anothers_post(): void {
		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/yoast-update-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_yoast_update_post_rejects_a_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/yoast-update-post' )->execute(
			array(
				'post_id'   => $post_id,
				'post_type' => 'attachment',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema rejects a smuggled field.' );
	}

	public function test_yoast_get_head_returns_a_head_string(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/yoast-get-head' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $post_id, $res['post_id'] );
		$this->assertSame( 'yoast', $res['plugin'] );
		// stub_yoast() wires the rendered-head filter, so the head is the stubbed string.
		$this->assertStringContainsString( 'Yoast head', $res['head'] );
	}

	public function test_yoast_get_head_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/yoast-get-head' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_yoast_get_post_unknown_id_is_rejected(): void {
		// An unknown post_id fails the per-object aafm_perm_seo_post_object gate (get_post() is not a
		// WP_Post), so the Abilities API short-circuits with ability_invalid_permissions before the
		// executor's defence-in-depth aafm_generic_error() can run. Either way the read is refused.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/yoast-get-post' )->execute( array( 'post_id' => PHP_INT_MAX ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'ability_invalid_permissions', $res->get_error_code() );
	}

	public function test_yoast_empty_patch_leaves_seeded_fields_unchanged(): void {
		// An update carrying only post_id must be a no-op: the array_key_exists skip per field must
		// NOT blank every key.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		update_post_meta( $post_id, '_yoast_wpseo_title', 'Seeded Title' );

		$res = wp_get_ability( 'aafm/yoast-update-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'An empty PATCH must not error.' );
		$this->assertSame( 'Seeded Title', $res['title'], 'An empty PATCH must leave the seeded title untouched.' );
	}

	public function test_yoast_get_head_denies_an_author_on_anothers_post_at_execute(): void {
		// The get-head abilities advertise on the edit_posts floor (aafm_perm_seo_get_head_floor) and
		// refine to the per-object edit_post($id) gate INSIDE execute. All per-object SEO reads/writes
		// otherwise share the single aafm_perm_seo_post_object gate; this proves the head executor's
		// own per-object refinement denies an author requesting someone else's post.
		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		$this->acting_as( 'author' );

		$res = wp_get_ability( 'aafm/yoast-get-head' )->execute( array( 'post_id' => $post_id ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'An author must be denied the head of another author\'s post.' );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
	}

	public function test_yoast_abilities_absent_when_host_inactive(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_yoast' );
		add_filter( 'aafm_yoast_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'yoast' ) );
		aafm_registry_cache_should_flush( true );
		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey(
			'aafm/yoast-get-post',
			$registry,
			'A host-inactive Yoast ability must not be in the registry.'
		);
	}
}
