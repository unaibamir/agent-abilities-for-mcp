<?php
/**
 * A rate-limited call tells the agent it hit the rate limit, naming the bucket and the limit, and
 * core's internal re-check never sees a WP_Error.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\Support\McpToolsHandlerShim;
use AAFM\Tests\TestCase;

final class RateLimitMessageTest extends TestCase {

	public function tear_down(): void {
		delete_option( 'aafm_rate_limit_per_min' );
		remove_all_filters( 'query' );
		parent::tear_down();
	}

	/**
	 * A test server carrying aafm/get-site-info.
	 *
	 * @return McpToolsHandlerShim
	 */
	private function handler(): McpToolsHandlerShim {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-rate-message-' . $counter;
		$tools     = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/get-site-info' ) ) );
		$adapter   = \WP\MCP\Core\McpAdapter::instance();
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Rate message test',
					'Test-only server.',
					AAFM_VERSION,
					array( \WP\MCP\Transport\HttpTransport::class ),
					\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
					\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
					$tools,
					array(),
					array(),
					'aafm_transport_permission_callback'
				);
			}
		);
		return new McpToolsHandlerShim( $adapter->get_server( $server_id ) );
	}

	/**
	 * One tools/call of get-site-info.
	 *
	 * @param McpToolsHandlerShim $handler Handler.
	 * @return mixed
	 */
	private function call( McpToolsHandlerShim $handler ) {
		return $handler->call_tool(
			array(
				'name'      => 'aafm-get-site-info',
				'arguments' => array(),
			)
		);
	}

	public function test_user_limit_message_reaches_the_agent(): void {
		$this->register_enabled( array( 'aafm/get-site-info' ) );
		$this->acting_as( 'administrator' );
		update_option( 'aafm_rate_limit_per_min', 1 );
		aafm_policy_reset_request_state();
		$handler = $this->handler();
		$this->assertFalse( $this->call( $handler )->getIsError() );
		$refused = $this->call( $handler );
		$this->assertTrue( $refused->getIsError() );
		/* translators: %d: calls allowed per minute. */
		$want = sprintf( _n( 'This WordPress account has reached the site\'s limit of %d call a minute. Wait for the next minute, then try again.', 'This WordPress account has reached the site\'s limit of %d calls a minute. Wait for the next minute, then try again.', 1, 'agent-abilities-for-mcp' ), 1 );
		$this->assertSame( $want, $refused->getContent()[0]->getText() );
		$this->assertStringContainsString( '1 call a minute', $want );
	}

	public function test_rate_limit_wire_shape(): void {
		$this->register_enabled( array( 'aafm/get-site-info' ) );
		$this->acting_as( 'administrator' );
		update_option( 'aafm_rate_limit_per_min', 1 );
		aafm_policy_reset_request_state();
		$handler = $this->handler();
		$this->call( $handler );
		$refused = $this->call( $handler );
		$this->assertNull( $refused->getStructuredContent() );
		$this->assertCount( 1, $refused->getContent() );
		$this->assertSame( array( 'content', 'isError' ), array_keys( json_decode( (string) wp_json_encode( $refused->record() ), true ) ) );
	}

	public function test_app_limit_message_names_the_app(): void {
		$user = $this->acting_as( 'administrator' );
		aafm_oauth_current_grant(
			array_merge(
				aafm_oauth_grant_defaults(),
				array(
					'rate'      => 1,
					'client_id' => 'rate-app',
					'user_id'   => $user,
				)
			)
		);
		$this->assertTrue( aafm_rate_limit_consume( $user ) );
		$this->assertFalse( aafm_rate_limit_consume( $user ) );
		$this->assertSame(
			array(
				'scope' => 'app',
				'limit' => 1,
			),
			aafm_rate_limit_last_refusal()
		);
		$this->assertStringContainsString( 'This app has reached its limit of 1 call a minute', aafm_rate_limit_error()->get_error_message() );
	}

	public function test_unreadable_counter_has_its_own_message(): void {
		$user = $this->acting_as( 'administrator' );
		update_option( 'aafm_rate_limit_per_min', 5 );
		aafm_policy_reset_request_state();
		add_filter(
			'query',
			static function ( string $query ): string {
				return false !== strpos( $query, '_transient_aafm_rl_' ) && 0 === strpos( ltrim( $query ), 'SELECT' ) ? 'SELECT * FROM aafm_missing_table_for_test' : $query;
			}
		);
		ob_start();
		$allowed = aafm_rate_limit_consume( $user );
		ob_end_clean();
		$this->assertFalse( $allowed );
		$this->assertSame( 'unreadable', aafm_rate_limit_last_refusal()['scope'] );
		$this->assertSame( 'The site could not check its rate limit, so the call was refused. Try again shortly.', aafm_rate_limit_error()->get_error_message() );
	}

	public function test_core_recheck_never_sees_a_wp_error(): void {
		$this->register_enabled( array( 'aafm/get-site-info' ) );
		$this->acting_as( 'administrator' );
		update_option( 'aafm_rate_limit_per_min', 1 );
		aafm_policy_reset_request_state();
		$ability = wp_get_ability( 'aafm/get-site-info' );
		$this->assertTrue( $ability->check_permissions() );
		aafm_rate_limit_call_reset( 'aafm/get-site-info' );
		aafm_executing_ability_push( 'aafm/get-site-info' );
		try {
			$this->assertFalse( $ability->check_permissions() );
		} finally {
			aafm_executing_ability_pop();
		}
		aafm_rate_limit_call_reset( 'aafm/get-site-info' );
		$this->assertWPError( $ability->check_permissions(), 'Outside execute() the adapter fire gets the named error.' );
	}

	public function test_denied_row_and_reason_unchanged(): void {
		$this->register_enabled( array( 'aafm/get-site-info' ) );
		$this->acting_as( 'administrator' );
		update_option( 'aafm_rate_limit_per_min', 1 );
		aafm_policy_reset_request_state();
		$handler = $this->handler();
		$this->call( $handler );
		$this->call( $handler );
		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm/get-site-info',
				'status'  => 'denied',
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'rate_limit', (string) $rows[0]['detail'] );
	}
}
