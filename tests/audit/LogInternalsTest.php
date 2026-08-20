<?php
/**
 * Audit-log internals not covered by LogTest: source-IP validation, status
 * normalization, the ability/status query filters, query pagination clamping,
 * the no-op update guard, and the started -> success transition driven through a
 * real ability call (end-to-end, not just a hand-written row).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class LogInternalsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * B5: the schema self-heal must ride the paths that actually take traffic. admin_init only
	 * fires on admin requests, so a headless site that auto-updates over cron and serves MCP over
	 * REST would keep failing every audit insert against a stale schema while calls keep
	 * succeeding, and nobody would ever open wp-admin to trigger the heal. The audit log is a
	 * security control, so the same option-version gate (one autoloaded option compare per
	 * request before any dbDelta) is hooked on rest_api_init too.
	 */
	public function test_schema_self_heals_on_rest_traffic_not_only_admin(): void {
		$this->assertNotFalse(
			has_action( 'rest_api_init', 'aafm_maybe_upgrade_activity_log' ),
			'A REST-only site must self-heal the audit schema without an admin page load.'
		);

		// And the heal actually runs from that hook: simulate a stale recorded version, fire the
		// init the MCP transport rides in on, and the recorded version must be current again.
		update_option( 'aafm_activity_log_schema_version', '1' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately fire core's own REST init to prove the heal rides it.
		do_action( 'rest_api_init' );
		$this->assertSame(
			AAFM_ACTIVITY_LOG_SCHEMA_VERSION,
			get_option( 'aafm_activity_log_schema_version' ),
			'Firing rest_api_init must bring a stale audit-log schema current.'
		);
	}

	public function test_source_ip_validates_remote_addr(): void {
		// Stashing the real value to restore after the test; no sanitization needed.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$original = $_SERVER['REMOTE_ADDR'] ?? null;

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( '198.51.100.7', aafm_source_ip() );

		// A non-IP value is rejected (returns empty), never stored verbatim.
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip; DROP TABLE';
		$this->assertSame( '', aafm_source_ip() );

		if ( null === $original ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $original;
		}
	}

	public function test_unknown_status_is_normalized_to_error(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'bananas',
			)
		);
		$rows = aafm_query_activity( array() );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	public function test_update_status_with_unknown_value_is_normalized_to_error(): void {
		$id = aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'started',
			)
		);
		aafm_update_activity_status( $id, 'wat' );
		$rows = aafm_query_activity( array() );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	public function test_update_status_on_zero_row_is_a_noop(): void {
		// Guard branch: a 0 row id must not touch the table.
		aafm_update_activity_status( 0, 'success' );
		$this->assertCount( 0, aafm_query_activity( array() ) );
	}

	public function test_query_filters_by_ability(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		aafm_log_activity(
			array(
				'ability' => 'aafm/trash-post',
				'status'  => 'success',
			)
		);

		$rows = aafm_query_activity( array( 'ability' => 'aafm/trash-post' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'aafm/trash-post', $rows[0]['ability'] );
	}

	public function test_query_combines_status_and_ability_filters(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'denied',
			)
		);
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		aafm_log_activity(
			array(
				'ability' => 'aafm/trash-post',
				'status'  => 'denied',
			)
		);

		$rows = aafm_query_activity(
			array(
				'status'  => 'denied',
				'ability' => 'aafm/get-posts',
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 'aafm/get-posts', $rows[0]['ability'] );
		$this->assertSame( 'denied', $rows[0]['status'] );
	}

	public function test_query_per_page_is_clamped_and_paginates(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'success',
				)
			);
		}
		// per_page below 1 is clamped up to 1.
		$first = aafm_query_activity( array( 'per_page' => 0 ) );
		$this->assertCount( 1, $first );

		// Page 2 at per_page 2 returns the next slice.
		$page2 = aafm_query_activity(
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertCount( 2, $page2 );
	}

	public function test_arg_keys_are_sanitized_keys_only_never_values(): void {
		aafm_log_activity(
			array(
				'ability'  => 'aafm/update-post',
				'status'   => 'success',
				'arg_keys' => array( 'Post-ID', 'title', 'secret value!' ),
			)
		);
		$rows = aafm_query_activity( array() );
		// sanitize_key lowercases and strips disallowed chars; no raw value survives.
		$this->assertSame( 'post-id,title,secretvalue', $rows[0]['arg_keys'] );
	}

	public function test_ability_called_action_fires_on_write(): void {
		$fired = 0;
		$cb    = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'aafm_ability_called', $cb );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		remove_action( 'aafm_ability_called', $cb );

		$this->assertSame( 1, $fired );
	}

	public function test_real_ability_call_records_started_then_success_in_one_row(): void {
		// End-to-end: a successful execute should leave exactly one row that has
		// transitioned started -> success (the in-place update contract).
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-taxonomies' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		aafm_clear_activity_log();
		wp_get_ability( 'aafm/get-taxonomies' )->execute( array() );

		$rows = aafm_query_activity( array( 'ability' => 'aafm/get-taxonomies' ) );
		$this->assertCount( 1, $rows, 'One row per call: started is updated in place, not duplicated.' );
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( $user_id, (int) $rows[0]['principal_user_id'] );
	}

	/**
	 * The verify predicate reports the real state of the table (set_up already installed it).
	 */
	public function test_schema_verify_true_when_installed_false_when_table_missing(): void {
		global $wpdb;

		$this->assertTrue( aafm_activity_log_schema_verify(), 'A fully installed schema must verify.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}aafm_activity_log" );
		$this->assertFalse( aafm_activity_log_schema_verify(), 'A missing table must fail verify.' );

		// Restore the table so later tests on this connection keep a healthy schema.
		aafm_install_activity_log();
	}

	/**
	 * A healthy install stamps the version and clears any prior failure flag.
	 */
	public function test_finalize_stamps_and_clears_error_on_healthy_schema(): void {
		set_transient( 'aafm_activity_log_schema_error', time(), DAY_IN_SECONDS );

		aafm_install_activity_log();

		$this->assertSame( AAFM_ACTIVITY_LOG_SCHEMA_VERSION, get_option( 'aafm_activity_log_schema_version' ) );
		$this->assertFalse( get_transient( 'aafm_activity_log_schema_error' ), 'A healthy verify must clear the error flag.' );
	}

	/**
	 * The core of F2: a failed migration must NOT advance the version, and must flag a bounded
	 * error, so the admin_init / rest_api_init self-heal keeps retrying instead of going quiet
	 * and losing the security trail.
	 */
	public function test_finalize_does_not_advance_version_on_broken_schema(): void {
		global $wpdb;

		update_option( 'aafm_activity_log_schema_version', '1' );
		delete_transient( 'aafm_activity_log_schema_error' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$wpdb->prefix}aafm_activity_log" );

		aafm_activity_log_finalize_schema();

		$this->assertSame( '1', get_option( 'aafm_activity_log_schema_version' ), 'A failed verify must leave the prior version so the self-heal retries.' );
		$this->assertNotFalse( get_transient( 'aafm_activity_log_schema_error' ), 'A failed verify must raise the bounded error flag.' );

		// Restore the healthy table and version.
		aafm_install_activity_log();
		$this->assertSame( AAFM_ACTIVITY_LOG_SCHEMA_VERSION, get_option( 'aafm_activity_log_schema_version' ) );
	}
}
