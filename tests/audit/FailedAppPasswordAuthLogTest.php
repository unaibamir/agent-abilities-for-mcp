<?php
/**
 * Tests for the failed-Application-Password audit log entry.
 *
 * Before this landed, a wrong Application Password against the MCP endpoint produced no audit
 * row at all: the transport gate returns its 401 before aafm_log_activity() is ever reached, so
 * credential stuffing was invisible in the plugin's own log. These tests verify the new
 * `application_password_failed_authentication` hook writes a row only when the failed attempt
 * targeted the MCP route, and that one source IP cannot grow the log past its bounded cap.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;
use WP_Error;

/**
 * Verifies aafm_log_failed_application_password_auth().
 */
class FailedAppPasswordAuthLogTest extends TestCase {

	/**
	 * Saved REQUEST_URI / rest_route / REMOTE_ADDR, restored in tear_down.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_request = array();

	/**
	 * Installs the activity log and points the request at the MCP route by default.
	 */
	public function set_up(): void {
		parent::set_up();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended
		$this->original_request = array(
			'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
			'rest_route'  => $_GET['rest_route'] ?? null,
			'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended

		unset( $_GET['rest_route'] );
		$this->on_mcp_route();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Restore the request keys to exactly their pre-test state.
	 */
	public function tear_down(): void {
		foreach ( array( 'REQUEST_URI', 'REMOTE_ADDR' ) as $key ) {
			if ( null === $this->original_request[ $key ] ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $this->original_request[ $key ];
			}
		}
		if ( null === $this->original_request['rest_route'] ) {
			unset( $_GET['rest_route'] );
		} else {
			$_GET['rest_route'] = $this->original_request['rest_route'];
		}
		parent::tear_down();
	}

	/**
	 * Point the request at the MCP REST route (pretty-permalink form).
	 */
	private function on_mcp_route(): void {
		$_SERVER['REQUEST_URI'] = '/' . trim( rest_get_url_prefix(), '/' ) . '/agent-abilities-for-mcp/mcp';
	}

	/**
	 * A failed Application Password attempt against the MCP endpoint now writes one denied row,
	 * with no principal (nothing authenticated) and no credential-shaped detail.
	 */
	public function test_logs_a_denied_row_on_the_mcp_route(): void {
		aafm_log_failed_application_password_auth( new WP_Error( 'incorrect_password', 'The provided password is an invalid application password.' ) );

		$rows = aafm_query_activity( array( 'per_page' => 5 ) );
		$this->assertCount( 1, $rows, 'A failed attempt on the MCP route must write exactly one row.' );
		$this->assertSame( '(transport)', $rows[0]['ability'] );
		$this->assertSame( 'denied', $rows[0]['status'] );
		$this->assertSame( 0, (int) $rows[0]['principal_user_id'], 'Nothing authenticated, so no principal should be attributed.' );
		$this->assertSame( 'Invalid Application Password', $rows[0]['detail'] );
	}

	/**
	 * The same failure against an unrelated REST route must NOT be logged - this action fires
	 * for any Basic-Auth REST/XML-RPC request, not only ours.
	 */
	public function test_does_not_log_off_the_mcp_route(): void {
		$_SERVER['REQUEST_URI'] = '/' . trim( rest_get_url_prefix(), '/' ) . '/wp/v2/posts';

		aafm_log_failed_application_password_auth( new WP_Error( 'incorrect_password', 'nope' ) );

		$rows = aafm_query_activity( array( 'per_page' => 5 ) );
		$this->assertCount( 0, $rows, 'A failed attempt off the MCP route must never reach this plugin\'s log.' );
	}

	/**
	 * One source IP cannot grow the log past AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW rows inside the
	 * window, so hammering the endpoint with bad credentials cannot inflate the log without limit.
	 */
	public function test_bounded_per_ip_within_the_window(): void {
		for ( $i = 0; $i < AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW + 3; $i++ ) {
			aafm_log_failed_application_password_auth( new WP_Error( 'incorrect_password', 'nope' ) );
		}

		$rows = aafm_query_activity( array( 'per_page' => 50 ) );
		$this->assertCount(
			AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW,
			$rows,
			'A single IP must not be able to write more than the capped number of rows in one window.'
		);
	}

	/**
	 * A different source IP gets its own independent cap - one attacker's flood must not crowd
	 * out a genuine signal from a different address.
	 */
	public function test_different_ips_get_independent_caps(): void {
		for ( $i = 0; $i < AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW; $i++ ) {
			aafm_log_failed_application_password_auth( new WP_Error( 'incorrect_password', 'nope' ) );
		}

		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		aafm_log_failed_application_password_auth( new WP_Error( 'incorrect_password', 'nope' ) );

		$rows = aafm_query_activity( array( 'per_page' => 50 ) );
		$this->assertCount( AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW + 1, $rows );
	}
}
