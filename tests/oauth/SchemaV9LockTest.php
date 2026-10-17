<?php
/**
 * Tests for the OAuth schema upgrade lock.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * One request upgrades at a time, and a failed upgrade waits an hour, the same way the activity
 * log's upgrade does.
 */
class SchemaV9LockTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		delete_transient( 'aafm_oauth_upgrade_lock' );
	}

	public function tear_down(): void {
		remove_all_filters( 'query' );
		delete_transient( 'aafm_oauth_upgrade_lock' );
		aafm_install_oauth_tables();
		parent::tear_down();
	}

	/**
	 * Count the installer's DESCRIBE statements (MySQL 8 and MariaDB differ on ALTER), and
	 * optionally refuse every schema change so the upgrade cannot land.
	 *
	 * @param int  $attempts DESCRIBE count, by reference.
	 * @param bool $sabotage Whether to refuse the DDL.
	 * @return callable The filter, to remove afterwards.
	 */
	private function watch_schema_statements( int &$attempts, bool $sabotage ): callable {
		$filter = static function ( $query ) use ( &$attempts, $sabotage ) {
			if ( ! is_string( $query ) ) {
				return $query;
			}
			if ( 1 === preg_match( '/^\s*DESCRIBE\s/i', $query ) ) {
				++$attempts;
			}
			if ( $sabotage && 1 === preg_match( '/^\s*(ALTER|CREATE)\s+(TEMPORARY\s+)?TABLE\s/i', $query ) ) {
				return '';
			}
			return $query;
		};
		add_filter( 'query', $filter, 1 );
		return $filter;
	}

	public function test_a_second_request_while_the_lock_is_held_does_not_run_the_installer(): void {
		SchemaV9Test::downgrade_consents_to_v8();
		update_option( 'aafm_oauth_schema_version', '8' );
		$attempts = 0;
		$filter   = $this->watch_schema_statements( $attempts, false );

		set_transient( 'aafm_oauth_upgrade_lock', 1, MINUTE_IN_SECONDS );
		aafm_maybe_upgrade_oauth_tables();
		$held_attempts = $attempts;
		$this->assertSame( '8', get_option( 'aafm_oauth_schema_version' ), 'Another request holds the lock.' );

		delete_transient( 'aafm_oauth_upgrade_lock' );
		aafm_maybe_upgrade_oauth_tables();
		remove_filter( 'query', $filter, 1 );
		$this->assertSame( 0, $held_attempts );
		$this->assertSame( AAFM_OAUTH_SCHEMA_VERSION, get_option( 'aafm_oauth_schema_version' ) );
		$this->assertFalse( get_transient( 'aafm_oauth_upgrade_lock' ), 'A good upgrade releases the lock.' );
	}

	public function test_a_failed_install_waits_an_hour_before_retrying(): void {
		SchemaV9Test::downgrade_consents_to_v8();
		update_option( 'aafm_oauth_schema_version', '8' );
		$attempts = 0;
		$filter   = $this->watch_schema_statements( $attempts, true );

		aafm_maybe_upgrade_oauth_tables();
		$this->assertSame( '8', get_option( 'aafm_oauth_schema_version' ) );
		$this->assertNotFalse( get_transient( 'aafm_oauth_upgrade_lock' ), 'A failed upgrade leaves the lock as a back-off.' );
		$this->assertGreaterThan( 0, $attempts, 'The first request tries.' );

		$first = $attempts;
		aafm_maybe_upgrade_oauth_tables();
		aafm_maybe_upgrade_oauth_tables();
		$this->assertSame( $first, $attempts, 'Later requests inside the hour do not run dbDelta again.' );

		remove_filter( 'query', $filter, 1 );
		delete_transient( 'aafm_oauth_upgrade_lock' );
		aafm_maybe_upgrade_oauth_tables();
		$this->assertSame( AAFM_OAUTH_SCHEMA_VERSION, get_option( 'aafm_oauth_schema_version' ), 'Once the wait is over the upgrade heals.' );
	}
}
