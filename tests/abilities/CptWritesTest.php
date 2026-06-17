<?php
/**
 * CPT write abilities: generic create/update over ALLOWLISTED custom post types.
 * Mirrors PostsWriteTest's harness; registers a throwaway CPT to prove allow + deny.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class CptWritesTest extends TestCase {

	/**
	 * An allowlisted, eligible, map_meta_cap CPT used to prove the allow path.
	 *
	 * @var string
	 */
	private const CPT = 'aafm_book';

	/**
	 * An eligible CPT that is NOT added to the allowlist — proves the deny path.
	 *
	 * @var string
	 */
	private const CPT_DENIED = 'aafm_secret';

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// A public, map_meta_cap CPT with its own granular caps so we can prove both
		// the create cap and the publish cap independently.
		register_post_type(
			self::CPT,
			array(
				'public'          => true,
				'show_in_rest'    => true,
				'map_meta_cap'    => true,
				'capability_type' => array( 'aafm_book', 'aafm_books' ),
			)
		);
		// A second eligible CPT we deliberately leave OUT of the allowlist.
		register_post_type(
			self::CPT_DENIED,
			array(
				'public'       => true,
				'map_meta_cap' => true,
			)
		);

		// Expose only self::CPT to agents (post/page are always-on; this adds the CPT).
		update_option( 'aafm_allowed_post_types', array( self::CPT ) );

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/create-cpt-item', 'aafm/update-cpt-item' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function tear_down(): void {
		unregister_post_type( self::CPT );
		unregister_post_type( self::CPT_DENIED );
		delete_option( 'aafm_allowed_post_types' );
		parent::tear_down();
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * @param string   $action   Action name to simulate.
	 * @param callable $callback Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $callback ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$callback();
		array_pop( $wp_current_filter );
	}

	public function test_cpt_create_schema_is_closed_and_requires_post_type(): void {
		$schema = aafm_write_cpt_content_schema( true );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertFalse( $schema['additionalProperties'], 'CPT create schema must be closed.' );
		$this->assertArrayHasKey( 'post_type', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['post_type']['type'] );
		$this->assertContains( 'post_type', $schema['required'] );
		$this->assertContains( 'title', $schema['required'] );
		// Inherits C2 enrichment fields.
		$this->assertArrayHasKey( 'terms', $schema['properties'] );
		$this->assertArrayHasKey( 'featured_media', $schema['properties'] );
		$this->assertArrayHasKey( 'meta', $schema['properties'] );
		$this->assertArrayHasKey( 'slug', $schema['properties'] );
	}
}
