<?php
/**
 * The page cache purge tools refuse on a multisite network before any cache plugin is called,
 * and spend no purge budget. Runs only under tests/multisite.xml.dist.
 *
 * @group ms-required
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use SiteopsCacheStubStore as Stub;

/**
 * Three refusals and one status read on a network.
 *
 * @group ms-required
 */
final class MsSiteCachePurgeRefusalTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
		aafm_siteops_load();
		Stub::reset();
		aafm_current_invocation_row_id( 0 );
		add_filter( 'aafm_integration_active_site_cache', '__return_true' );
		aafm_flush_registry_cache();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/site-cache-status', 'aafm/site-purge-post-cache', 'aafm/site-purge-url-cache', 'aafm/site-purge-site-cache' ) );
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		Stub::reset();
		aafm_current_invocation_row_id( 0 );
		remove_filter( 'aafm_integration_active_site_cache', '__return_true' );
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	public function test_the_purge_tools_refuse_on_a_network_and_spend_nothing(): void {
		Stub::register( array( 'fake_a' => Stub::entry( 'Fake A' ) ) );
		$live = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$calls = array(
			'aafm/site-purge-post-cache' => array( 'post_id' => $live ),
			'aafm/site-purge-url-cache'  => array( 'url' => '/' ),
			'aafm/site-purge-site-cache' => array(),
		);
		foreach ( $calls as $name => $input ) {
			$result = wp_get_ability( $name )->execute( $input );
			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 'aafm_site_multisite', $result->get_error_code(), $name );
		}

		$this->assertSame( array(), Stub::purge_calls( 'fake_a' ) );
		$this->assertSame( 0, aafm_site_purge_budget_state()['used'] );

		$status = wp_get_ability( 'aafm/site-cache-status' )->execute( array() );
		$this->assertIsArray( $status );
		$this->assertTrue( $status['multisite'] );
		$this->assertFalse( $status['purge_supported'] );
		$this->assertSame( 'serving', $status['providers'][0]['status'], 'cache-status still reads on a network.' );
	}
}
