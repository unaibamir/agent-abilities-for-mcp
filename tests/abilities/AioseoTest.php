<?php
/**
 * AIOSEO per-plugin abilities (Wave 5 Slice B): aioseo-get-post, aioseo-update-post,
 * aioseo-get-head.
 *
 * AIOSEO is not installed on the test site, so the fixture forces the aioseo predicate active and
 * defines the minimal host signal (the aioseo() marker function + a stateful
 * AIOSEO\Plugin\Common\Models\Post model backed by AioseoStubStore + the rendered-head filter) via
 * stub_aioseo(). AIOSEO keeps post SEO in a custom table, reached through the Post model — never
 * post meta — so the read/write go through getPost()->set->save(), and the tests prove the write
 * targets the model store, never the _aioseo_* shadow meta, and never raw SQL.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use WP_Error;

final class AioseoTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'aioseo' );
		$this->stub_aioseo();
		aafm_registry_cache_should_flush( true );
		$this->register_aioseo();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * @param string   $action Action name to simulate.
	 * @param callable $cb     Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $cb ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$cb();
		array_pop( $wp_current_filter );
	}

	/**
	 * Enable + register the AIOSEO set so the abilities can be invoked.
	 */
	private function register_aioseo(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/aioseo-get-post', 'aafm/aioseo-update-post', 'aafm/aioseo-get-head' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_aioseo_update_then_get_round_trips_through_the_model(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$payload = array(
			'post_id'             => $post_id,
			'title'               => 'AIO Title',
			'description'         => 'AIO description.',
			'canonical'           => 'https://example.com/aio-canonical',
			'og_title'            => 'AIO OG Title',
			'og_description'      => 'AIO OG description.',
			'og_image'            => 'https://example.com/aio-og.jpg',
			'twitter_title'       => 'AIO TW Title',
			'twitter_description' => 'AIO TW description.',
			'twitter_image'       => 'https://example.com/aio-tw.jpg',
			'robots_noindex'      => true,
			'robots_nofollow'     => true,
		);
		$res     = wp_get_ability( 'aafm/aioseo-update-post' )->execute( $payload );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A full AIOSEO write must succeed through the model.' );

		$read = wp_get_ability( 'aafm/aioseo-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'aioseo', $read['plugin'] );
		$this->assertSame( 'AIO Title', $read['title'] );
		$this->assertSame( 'AIO description.', $read['description'] );
		$this->assertSame( 'https://example.com/aio-canonical', $read['canonical'] );
		$this->assertSame( 'AIO OG Title', $read['og_title'] );
		$this->assertSame( 'https://example.com/aio-og.jpg', $read['og_image'] );
		$this->assertTrue( $read['robots_noindex'] );
		$this->assertTrue( $read['robots_nofollow'] );
	}

	public function test_aioseo_write_does_not_touch_the_shadow_meta(): void {
		// The _aioseo_* post meta keys are WPML-compat shadow copies, not AIOSEO's source of truth.
		// The write must go through the model store, NOT update the shadow meta.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'Model Title',
			)
		);
		$this->assertSame( '', get_post_meta( $post_id, '_aioseo_title', true ), 'The write must not target the shadow meta key.' );

		// The model store DID change (the read reflects it).
		$read = wp_get_ability( 'aafm/aioseo-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'Model Title', $read['title'], 'The model store must hold the written value.' );
	}

	public function test_aioseo_source_uses_no_raw_sql(): void {
		// AIOSEO custom-table writes must go through the model ->save(), never raw $wpdb. A source
		// grep of the ability file must be clean of $wpdb.
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/abilities/aioseo.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local test fixture, not a remote URL.
		$this->assertStringNotContainsString( '$wpdb', $source, 'aioseo.php must never use raw $wpdb.' );
	}

	public function test_aioseo_url_fields_are_url_sanitized(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id'  => $post_id,
				'og_image' => 'javascript:alert(1)',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( '', $res['og_image'], 'A javascript: og_image must be stripped to empty.' );
	}

	public function test_aioseo_no_schema_ability_registers(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/aioseo-get-schema', $registry );
		$this->assertArrayNotHasKey( 'aafm/aioseo-update-schema', $registry );
	}

	public function test_aioseo_update_post_denies_an_author_on_anothers_post(): void {
		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/aioseo-update-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_aioseo_get_post_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/aioseo-get-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_aioseo_update_post_rejects_a_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id'   => $post_id,
				'post_type' => 'attachment',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_aioseo_get_head_returns_a_head_string(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/aioseo-get-head' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aioseo', $res['plugin'] );
		$this->assertStringContainsString( 'AIOSEO head', $res['head'] );
	}

	public function test_aioseo_get_head_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/aioseo-get-head' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_aioseo_get_post_unknown_id_is_rejected(): void {
		// An unknown post_id fails the per-object aafm_perm_seo_post_object gate (get_post() is not a
		// WP_Post), so the Abilities API short-circuits with ability_invalid_permissions before the
		// executor's defence-in-depth aafm_generic_error() can run. Either way the read is refused.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/aioseo-get-post' )->execute( array( 'post_id' => PHP_INT_MAX ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'ability_invalid_permissions', $res->get_error_code() );
	}

	public function test_aioseo_empty_patch_leaves_seeded_fields_unchanged(): void {
		// An update carrying only post_id must be a no-op: the array_key_exists skip per field must
		// NOT blank every key in the model store.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'Seeded Title',
			)
		);

		$res = wp_get_ability( 'aafm/aioseo-update-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'An empty PATCH must not error.' );
		$this->assertSame( 'Seeded Title', $res['title'], 'An empty PATCH must leave the seeded title untouched.' );
	}

	public function test_aioseo_abilities_absent_when_host_inactive(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_aioseo' );
		add_filter( 'aafm_aioseo_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'aioseo' ) );
		aafm_registry_cache_should_flush( true );
		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/aioseo-get-post', $registry );
	}
}
