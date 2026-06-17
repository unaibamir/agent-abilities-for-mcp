<?php
/**
 * SEO integration abilities — the unified set routed across Yoast / Rank Math / AIOSEO.
 *
 * Drives the SEO slice (W4-S). The host SEO plugins are NOT installed on the test site, so
 * each test forces the integration active through its per-slug filter and defines the minimal
 * host signal via the IntegrationStubs trait (for SEO that is just the active-plugin marker —
 * the abilities read/write the mapped keys with core get_post_meta/update_post_meta once the
 * key map resolves).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use WP_Error;

final class SeoTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'seo' );  // aafm_integration_active_seo => true.
		$this->stub_seo_plugin( 'yoast' );  // Defines WPSEO_VERSION so the Yoast key map applies.
		aafm_registry_cache_should_flush( true );
		$this->register_seo();
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
	 * Enable + register the SEO set so the abilities can be invoked.
	 */
	private function register_seo(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/seo-get-post', 'aafm/seo-update-post', 'aafm/seo-get-schema', 'aafm/seo-update-schema', 'aafm/seo-get-head' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_seo_get_post_reads_mapped_fields_per_object_gated(): void {
		$editor_id = $this->acting_as( 'editor' );
		$post_id   = (int) self::factory()->post->create(
			array(
				'post_author' => $editor_id,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_yoast_wpseo_title', 'SEO Title' );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'A description.' );

		$res = wp_get_ability( 'aafm/seo-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'yoast', $res['plugin'] );
		$this->assertSame( $post_id, $res['post_id'] );
		$this->assertSame( 'SEO Title', $res['title'] );
		$this->assertSame( 'A description.', $res['description'] );
		// Every unified field for the active plugin is present in the shape (empty when unset).
		$this->assertArrayHasKey( 'focus_keyword', $res );
		$this->assertArrayHasKey( 'canonical', $res );
		$this->assertArrayHasKey( 'og_title', $res );
	}

	public function test_seo_get_post_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-get-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_seo_get_post_denies_an_editor_on_anothers_post(): void {
		// Per-object gate: an editor can edit_post in general, but this proves the gate is
		// genuinely per-object (an editor DOES have edit_others_posts, so use an author whose
		// floor clears edit_posts yet is denied on another author's post).
		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		// A different author cannot edit the first author's post.
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-get-post' )->check_permissions( array( 'post_id' => $post_id ) ),
			'An author must be denied SEO read on another author\'s post.'
		);
	}

	public function test_seo_get_post_rejects_a_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/seo-get-post' )->execute( array( 'post_id' => $post_id, 'plugin' => 'rankmath' ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema rejects a smuggled field.' );
	}

	public function test_seo_abilities_absent_when_host_inactive(): void {
		// HIGH-2: assert at the REGISTRY level, not via aafm_user_can_discover_ability().
		// The discovery helper falls through to aafm_user_can_call_ability → the process-wide
		// static raw-permission $store stashed at registration, which persists for the
		// lifetime of the process once any test registered the SEO set. The registry is the
		// honest source of truth: a host-inactive integration contributes zero entries.
		//
		// The Yoast stub define()s WPSEO_VERSION process-wide (a constant cannot be undefined),
		// so real detection still reports yoast active here — drive the predicate to inactive
		// through its own filter (the same seam production detection passes through).
		$this->reset_integration_stubs();
		add_filter( 'aafm_integration_active_seo', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'seo' ) );
		aafm_registry_cache_should_flush( true ); // Rebuild the memo with SEO now inactive.
		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey(
			'aafm/seo-get-post',
			$registry,
			'A host-inactive integration ability must not be in the registry.'
		);
	}
}
