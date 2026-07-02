<?php
/**
 * Structure read abilities: public taxonomies, public post types, redacted site info.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class StructureReadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions, simulated
		// by pushing the action name onto $wp_current_filter - the idiom WP core's own
		// ability test trait uses. wp_register_ability() refuses to run otherwise.
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/get-taxonomies', 'aafm/get-post-types', 'aafm/get-site-info' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
		$this->acting_as( 'subscriber' );
	}

	public function test_structure_reads_are_in_registry(): void {
		$registry = aafm_get_abilities_registry();
		foreach ( array( 'aafm/get-taxonomies', 'aafm/get-post-types', 'aafm/get-site-info' ) as $name ) {
			$this->assertArrayHasKey( $name, $registry );
			$this->assertSame( 'reads', $registry[ $name ]['group'] );
			$this->assertSame( 'read', $registry[ $name ]['risk'] );
		}
	}

	public function test_get_taxonomies_lists_public_only(): void {
		$out   = wp_get_ability( 'aafm/get-taxonomies' )->execute( array() );
		$slugs = wp_list_pluck( $out['taxonomies'], 'slug' );
		$this->assertContains( 'category', $slugs );
		$this->assertNotContains( 'nav_menu', $slugs );
	}

	public function test_get_post_types_lists_public_only(): void {
		$out   = wp_get_ability( 'aafm/get-post-types' )->execute( array() );
		$slugs = wp_list_pluck( $out['post_types'], 'slug' );
		$this->assertContains( 'post', $slugs );
		$this->assertNotContains( 'revision', $slugs );
	}

	public function test_get_post_types_flags_writable_per_allowlist(): void {
		// A public type the operator has NOT exposed to agents. Public so it surfaces in the
		// list, but absent from the allowlist so its writable flag must be false.
		register_post_type(
			'aafm_unlisted',
			array(
				'public'       => true,
				'map_meta_cap' => true,
			)
		);
		// Expose only 'post' (post/page are always-on); aafm_unlisted is deliberately left out.
		update_option( 'aafm_allowed_post_types', array() );

		$out = wp_get_ability( 'aafm/get-post-types' )->execute( array() );
		$by  = array_column( $out['post_types'], null, 'slug' );

		// An allowlisted (always-on) type is writable; a public-but-not-allowlisted type is not.
		$this->assertTrue( $by['post']['writable'] );
		$this->assertFalse( $by['aafm_unlisted']['writable'] );

		unregister_post_type( 'aafm_unlisted' );
		delete_option( 'aafm_allowed_post_types' );
	}

	public function test_get_site_info_is_redacted(): void {
		update_option( 'admin_email', 'admin@example.com' );
		$out  = wp_get_ability( 'aafm/get-site-info' )->execute( array() );
		$json = wp_json_encode( $out );

		// Whitelisted safe fields are present.
		$this->assertArrayHasKey( 'name', $out['site'] );
		$this->assertArrayHasKey( 'url', $out['site'] );
		$this->assertArrayHasKey( 'tagline', $out['site'] );

		// The site descriptor is EXACTLY the whitelist - nothing else leaks.
		$this->assertSame(
			array( 'name', 'tagline', 'url', 'language' ),
			array_keys( $out['site'] )
		);

		// Security-critical: no admin email, no version, no path/server software anywhere.
		$this->assertStringNotContainsString( 'admin@example.com', (string) $json );
		$this->assertArrayNotHasKey( 'admin_email', $out['site'] );
		$this->assertArrayNotHasKey( 'version', $out['site'] );
		$this->assertArrayNotHasKey( 'php_version', $out['site'] );
		$this->assertStringNotContainsString( get_bloginfo( 'version' ), (string) $json );
		$this->assertStringNotContainsString( PHP_VERSION, (string) $json );
		$this->assertStringNotContainsString( ABSPATH, (string) $json );
	}
}
