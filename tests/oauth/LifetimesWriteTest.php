<?php
/**
 * Tests for the token lifetimes and client registration save.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Covers aafm_ajax_save_oauth_lifetimes().
 */
class LifetimesWriteTest extends TestCase {

	use AjaxHarness;

	public function set_up(): void {
		parent::set_up();
		// Another suite's DDL can commit one of these past its rollback; start from absent rows.
		delete_option( 'aafm_oauth_access_ttl' );
		delete_option( 'aafm_oauth_refresh_ttl' );
		delete_option( 'aafm_oauth_dcr_enabled' );
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		remove_all_filters( 'query' );
		delete_option( 'aafm_oauth_access_ttl' );
		delete_option( 'aafm_oauth_refresh_ttl' );
		delete_option( 'aafm_oauth_dcr_enabled' );
		parent::tear_down();
	}

	/**
	 * Save as an administrator.
	 *
	 * @param array<string,mixed> $fields POST fields over a valid default payload.
	 * @param string              $role   Acting role.
	 * @return array<string,mixed>
	 */
	private function save( array $fields = array(), string $role = 'administrator' ): array {
		$this->post_as(
			$role,
			array_merge(
				array(
					'aafm_oauth_access_ttl'  => '3600',
					'aafm_oauth_refresh_ttl' => '2592000',
					'aafm_oauth_dcr_enabled' => '1',
				),
				$fields
			)
		);
		return $this->run_handler( 'aafm_ajax_save_oauth_lifetimes' );
	}

	/**
	 * The setting_changed rows this card wrote.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT ability, detail, status FROM %i WHERE event_type = %s ORDER BY id', $wpdb->prefix . 'aafm_activity_log', 'setting_changed' ), ARRAY_A );
	}

	public function test_a_valid_save_writes_both_values_and_one_log_row_per_change(): void {
		$json = $this->save(
			array(
				'aafm_oauth_access_ttl'  => '1800',
				'aafm_oauth_refresh_ttl' => '604800',
			)
		);
		$this->assertTrue( $json['success'] );
		$this->assertSame( 1800, $json['data']['access'] );
		$this->assertSame( '1800', (string) aafm_option_row( 'aafm_oauth_access_ttl' )['value'] );
		$this->assertSame( '604800', (string) aafm_option_row( 'aafm_oauth_refresh_ttl' )['value'] );
		$rows = $this->rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( 'aafm/oauth-lifetimes', $rows[0]['ability'] );
		$this->assertSame( 'Access token lifetime changed from ' . human_time_diff( 0, 3600 ) . ' to ' . human_time_diff( 0, 1800 ), $rows[0]['detail'] );
		$this->assertStringNotContainsString( '604800', $rows[1]['detail'] );
	}

	public function test_an_unchanged_value_writes_nothing_and_logs_nothing(): void {
		$this->assertTrue( $this->save()['success'] );
		$this->assertFalse( aafm_option_row( 'aafm_oauth_access_ttl' )['found'] );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_access_must_be_shorter_than_refresh(): void {
		foreach ( array( '3600', '7200' ) as $access ) {
			$json = $this->save(
				array(
					'aafm_oauth_access_ttl'  => $access,
					'aafm_oauth_refresh_ttl' => '3600',
				)
			);
			$this->assertFalse( $json['success'] );
		}
		$this->assertFalse( aafm_option_row( 'aafm_oauth_refresh_ttl' )['found'] );
	}

	public function test_out_of_range_values_are_refused(): void {
		foreach ( array( '0', '299', '86401', '-5', '1e3', '01', ' 3600', '3600.5', '', '99999999999' ) as $bad ) {
			$this->assertFalse( $this->save( array( 'aafm_oauth_access_ttl' => $bad ) )['success'], var_export( $bad, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- assertion message.
		}
		$this->assertFalse( aafm_option_row( 'aafm_oauth_access_ttl' )['found'] );
	}

	public function test_an_array_param_is_refused_cleanly(): void {
		$json = $this->save( array( 'aafm_oauth_access_ttl' => array( '3600' ) ) );
		$this->assertFalse( $json['success'] );
		$this->assertNotEmpty( $json['data']['message'] );
		$json = $this->save( array( 'aafm_oauth_dcr_enabled' => array( '1' ) ) );
		$this->assertFalse( $json['success'] );
	}

	public function test_the_write_is_certified_against_the_database_row(): void {
		update_option( 'aafm_oauth_access_ttl', 3600 );
		$all                          = wp_load_alloptions();
		$all['aafm_oauth_access_ttl'] = '900';
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertTrue( $this->save( array( 'aafm_oauth_access_ttl' => '1800' ) )['success'] );
		$this->assertSame( '1800', (string) aafm_option_row( 'aafm_oauth_access_ttl' )['value'] );

		global $wpdb;
		$json = QueryFaultInjector::fail_query(
			array( "UPDATE `{$wpdb->options}`", 'aafm_oauth_access_ttl' ),
			fn() => $this->save( array( 'aafm_oauth_access_ttl' => '900' ) )
		);
		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( 'Access token lifetime', $json['data']['message'] );
		$this->assertSame( '1800', (string) aafm_option_row( 'aafm_oauth_access_ttl' )['value'] );
		$this->assertCount( 1, $this->rows(), 'Only the first, certified change is logged.' );
	}

	public function test_dcr_off_is_written_before_the_lifetimes_and_dcr_on_after(): void {
		update_option( 'aafm_oauth_dcr_enabled', '1' );
		$json = QueryFaultInjector::fail_query(
			array( 'INSERT INTO', 'aafm_oauth_access_ttl' ),
			fn() => $this->save(
				array(
					'aafm_oauth_access_ttl'  => '1800',
					'aafm_oauth_dcr_enabled' => '0',
				)
			)
		);
		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( 'Access token lifetime', $json['data']['message'] );
		$this->assertSame( '0', aafm_option_row( 'aafm_oauth_dcr_enabled' )['value'] );

		update_option( 'aafm_oauth_dcr_enabled', '0' );
		$json = QueryFaultInjector::fail_query(
			array( 'INSERT INTO', 'aafm_oauth_access_ttl' ),
			fn() => $this->save(
				array(
					'aafm_oauth_access_ttl'  => '900',
					'aafm_oauth_dcr_enabled' => '1',
				)
			)
		);
		$this->assertFalse( $json['success'] );
		$this->assertSame( '0', aafm_option_row( 'aafm_oauth_dcr_enabled' )['value'], 'Opening registration waits for the lifetimes.' );
	}

	public function test_dcr_value_must_be_exactly_one_or_zero(): void {
		foreach ( array( '', '2', 'on', 'true' ) as $bad ) {
			$this->assertFalse( $this->save( array( 'aafm_oauth_dcr_enabled' => $bad ) )['success'] );
		}
		$this->assertFalse( aafm_option_row( 'aafm_oauth_dcr_enabled' )['found'] );
	}

	public function test_nonce_and_capability_are_checked(): void {
		$json = $this->save( array(), 'editor' );
		$this->assertFalse( $json['success'] );

		$this->post_as( 'administrator', array( 'aafm_oauth_access_ttl' => '1800' ) );
		$_POST['nonce']    = 'bad';
		$_REQUEST['nonce'] = 'bad';
		$this->run_handler( 'aafm_ajax_save_oauth_lifetimes' );
		$this->assertFalse( aafm_option_row( 'aafm_oauth_access_ttl' )['found'] );
	}

	public function test_new_tokens_use_the_new_lifetime_and_old_tokens_keep_theirs(): void {
		global $wpdb;
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		$user  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$mint  = static fn() => aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $user,
				'client_id'  => 'client_ttl',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$first = $mint();
		$this->assertIsArray( $first );
		$this->assertTrue( $this->save( array( 'aafm_oauth_access_ttl' => '600' ) )['success'] );
		aafm_policy_reset_request_state();
		$second = $mint();
		$this->assertIsArray( $second );
		$this->assertSame( 3600, $first['expires_in'] );
		$this->assertSame( 600, $second['expires_in'] );
		$expiries = $wpdb->get_col( $wpdb->prepare( 'SELECT expires_at FROM %i ORDER BY id', $wpdb->prefix . 'aafm_oauth_access_tokens' ) );
		$this->assertCount( 2, $expiries );
		$this->assertGreaterThan( $expiries[1], $expiries[0], 'The first token keeps its longer lifetime.' );
	}
}
