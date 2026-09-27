<?php
/**
 * A stored '' OAuth toggle row is a present row, so the one-time upgrade migration leaves it alone.
 *
 * The migration writes '1' only when the toggle row is absent (aafm_oauth_preserve_toggle_on_upgrade()).
 * The plugin itself stores '0' or '1', but core's update_option( $name, false ), WP-CLI or another
 * plugin can leave ''. Reading that row as absent would switch OAuth on over an off row (ledger
 * s14hunta-1).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class EmptyToggleMigrationTest extends TestCase {

	public function test_an_empty_oauth_toggle_is_not_migrated_on(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- planting and reading the raw rows under test.
		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => 'aafm_oauth_enabled',
				'option_value' => '',
				'autoload'     => 'no',
			)
		);
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'aafm_oauth_toggle_migrated' ) );
		foreach ( array( 'aafm_oauth_enabled', 'aafm_oauth_toggle_migrated', 'alloptions', 'notoptions' ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}

		aafm_oauth_preserve_toggle_on_upgrade();

		$this->assertSame(
			array( 'option_value' => '' ),
			$wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'aafm_oauth_enabled' ), ARRAY_A ),
			'A present off row must stay off.'
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
