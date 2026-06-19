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
