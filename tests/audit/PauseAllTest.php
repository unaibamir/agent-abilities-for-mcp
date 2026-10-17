<?php
/**
 * Tests for the pause-all switch and its REST guard.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Off by default, fail closed on stale or unreadable state, and every authenticated MCP request refused while on.
 */
final class PauseAllTest extends TestCase {

	use AjaxHarness;

	private const DEFAULT_SERVER_ROUTE = '/mcp/mcp-adapter-default-server';

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_pause_all' );
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		wp_cache_delete( 'alloptions', 'options' );
		delete_option( 'aafm_pause_all' );
		parent::tear_down();
	}

	/**
	 * Dispatch a POST to a route as the current user.
	 *
	 * @param string $route REST route.
	 * @return \WP_REST_Response
	 */
	private function dispatch( string $route ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' );
		return rest_do_request( $request );
	}

	/**
	 * Denied rows carrying the access_paused reason.
	 *
	 * @return int
	 */
	private function paused_rows(): int {
		$rows = aafm_query_activity( array( 'status' => 'denied' ) );
		return count( array_filter( $rows, static fn( $row ) => false !== strpos( (string) $row['detail'], 'Refused: access_paused' ) ) );
	}

	public function test_an_absent_row_is_not_paused(): void {
		$this->assertFalse( aafm_pause_all() );
	}

	public function test_set_on_then_off_round_trips_and_off_deletes_the_row(): void {
		global $wpdb;
		$this->assertTrue( aafm_set_pause_all( true ) );
		$this->assertTrue( aafm_pause_all() );
		$this->assertTrue( aafm_set_pause_all( false ) );
		$this->assertFalse( aafm_pause_all() );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'aafm_pause_all' ) ) );
	}

	public function test_a_stale_cached_off_cannot_unpause_all(): void {
		update_option( 'aafm_pause_all', '1' );
		$all = wp_load_alloptions( true );
		unset( $all['aafm_pause_all'] );
		wp_cache_set( 'alloptions', $all, 'options' );
		wp_cache_delete( 'aafm_pause_all', 'options' );
		$notoptions                   = (array) wp_cache_get( 'notoptions', 'options' );
		$notoptions['aafm_pause_all'] = true;
		wp_cache_set( 'notoptions', $notoptions, 'options' );
		$this->route_as_rest_request();

		$this->assertTrue( aafm_pause_all() );
	}

	public function test_a_stale_cached_on_reads_as_paused_until_the_writer_clears_it(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'aafm_pause_all' ) );
		$all                   = wp_load_alloptions( true );
		$all['aafm_pause_all'] = '1';
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->route_as_rest_request();
		$this->assertTrue( aafm_pause_all(), 'A stale cached on reads as paused.' );

		$this->assertTrue( aafm_set_pause_all( false ) );
		aafm_policy_reset_request_state();
		$this->assertFalse( aafm_pause_all() );
	}

	public function test_an_unreadable_row_counts_as_paused(): void {
		$this->route_as_rest_request();
		$on = QueryFaultInjector::fail_query( 'aafm_pause_all', static fn() => aafm_pause_all() );
		$this->assertTrue( $on );
	}

	public function test_the_guard_refuses_every_authenticated_caller_on_both_mcp_routes(): void {
		// The guard runs at rest_pre_dispatch, before route matching, so the adapter's default server
		// is refused by its route shape even where it is not registered.
		aafm_set_pause_all( true );

		$cookie = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$app    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$oauth  = self::factory()->user->create( array( 'role' => 'author' ) );
		foreach ( array( $cookie, $app, $oauth ) as $user ) {
			wp_set_current_user( $user );
			if ( $oauth === $user ) {
				aafm_oauth_current_grant(
					array_merge(
						aafm_oauth_grant_defaults(),
						array(
							'client_id' => 'c',
							'user_id'   => $user,
						)
					)
				);
			}
			foreach ( array( aafm_mcp_rest_route(), self::DEFAULT_SERVER_ROUTE ) as $route ) {
				$response = $this->dispatch( $route );
				$this->assertSame( 403, $response->get_status(), $route );
				$this->assertSame( 'aafm_access_paused', $response->as_error()->get_error_code(), $route );
			}
		}
		$this->assertGreaterThan( 0, $this->paused_rows() );
		$this->assertLessThanOrEqual( AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW, $this->paused_rows(), 'Bounded per source address.' );
	}

	public function test_an_unauthenticated_caller_still_gets_401_not_403(): void {
		aafm_set_pause_all( true );
		wp_set_current_user( 0 );
		$response = $this->dispatch( aafm_mcp_rest_route() );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_a_non_mcp_rest_route_is_not_touched(): void {
		aafm_set_pause_all( true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_pause_all_clears_on_reset_and_uninstall(): void {
		$this->assertContains( 'aafm_pause_all', aafm_config_option_names() );
		aafm_install_oauth_tables();
		aafm_set_pause_all( true );
		$this->assertTrue( aafm_reset_plugin() );
		$this->assertFalse( aafm_pause_all() );
	}

	public function test_the_admin_test_connection_call_says_why_when_paused(): void {
		aafm_set_pause_all( true );
		$this->post_as( 'administrator' );
		$requests = 0;
		$count    = static function ( $pre ) use ( &$requests ) {
			++$requests;
			return $pre;
		};
		add_filter( 'pre_http_request', $count );
		$json = $this->run_handler( 'aafm_ajax_test_connection' );
		remove_filter( 'pre_http_request', $count );

		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( 'paused', (string) $json['data']['message'] );
		$this->assertSame( 0, $requests, 'No request is made while paused.' );
	}
}
