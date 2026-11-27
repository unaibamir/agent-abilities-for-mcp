<?php
/**
 * A tools/call with invalid input is still governed when the adapter validates before permissions.
 *
 * Adapter 0.7.2 validates input inside McpTool::check_permission(), before it consults the
 * ability's permission callback. Without our validate_input() override that refusal never reached
 * the decorated permission callback or execute(): no activity row, no rate token, and a caller the
 * permission callback would refuse got the schema reason instead of the denial. These pin the
 * 0.7.1 outcomes on the real tools/call path.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\Support\McpToolsHandlerShim;
use AAFM\Tests\TestCase;

final class AdapterEarlyValidationTest extends TestCase {

	public function tear_down(): void {
		delete_option( 'aafm_rate_limit_per_min' );
		aafm_policy_reset_request_state();
		parent::tear_down();
	}

	/**
	 * A test server carrying the given abilities.
	 *
	 * @param string[] $names Ability names.
	 * @return McpToolsHandlerShim
	 */
	private function handler( array $names ): McpToolsHandlerShim {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-early-validation-' . $counter;
		$tools     = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( $names ) );
		$adapter   = \WP\MCP\Core\McpAdapter::instance();
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Early validation test',
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
	 * The Activity Log rows for one ability.
	 *
	 * @param string $ability Ability name.
	 * @return list<array<string,mixed>>
	 */
	private function rows( string $ability ): array {
		global $wpdb;
		$table = aafm_activity_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ability = %s ORDER BY id ASC", $ability ), ARRAY_A );
	}

	/**
	 * One tools/call.
	 *
	 * @param McpToolsHandlerShim $handler   Handler.
	 * @param string              $tool      Wire tool name.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return mixed
	 */
	private function call( McpToolsHandlerShim $handler, string $tool, array $arguments ) {
		return $handler->call_tool(
			array(
				'name'      => $tool,
				'arguments' => $arguments,
			)
		);
	}

	public function test_a_malformed_call_still_writes_an_audit_row(): void {
		$this->register_enabled( array( 'aafm/get-posts' ) );
		$this->acting_as( 'administrator' );

		$result = $this->call( $this->handler( array( 'aafm/get-posts' ) ), 'aafm-get-posts', array( 'search' => array( 'not', 'a', 'string' ) ) );

		$this->assertTrue( $result->getIsError() );
		$this->assertStringContainsString( 'invalid input', $result->getContent()[0]->getText() );
		$rows = $this->rows( 'aafm/get-posts' );
		$this->assertCount( 1, $rows, 'The refused call has exactly one row.' );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertStringContainsString( 'rejected by WP_Ability::validate_input(): ability_invalid_input', (string) $rows[0]['detail'] );
	}

	public function test_a_malformed_call_still_costs_a_rate_token(): void {
		$this->register_enabled( array( 'aafm/get-posts' ) );
		$this->acting_as( 'administrator' );
		update_option( 'aafm_rate_limit_per_min', 1 );
		aafm_policy_reset_request_state();
		$handler = $this->handler( array( 'aafm/get-posts' ) );

		$this->assertTrue( $this->call( $handler, 'aafm-get-posts', array( 'search' => array( 'x' ) ) )->getIsError() );
		$next = $this->call( $handler, 'aafm-get-posts', array() );

		$this->assertTrue( $next->getIsError(), 'The malformed call spent the only token, so the next call is refused.' );
		$this->assertStringContainsString( '1 call a minute', $next->getContent()[0]->getText() );
	}

	public function test_an_unauthorised_malformed_call_is_answered_as_a_denial(): void {
		$this->register_enabled( array( 'aafm/delete-post' ) );
		$post_id = self::factory()->post->create();
		// Built as an administrator so the tool is on the server; the call comes from a subscriber.
		$this->acting_as( 'administrator' );
		$handler = $this->handler( array( 'aafm/delete-post' ) );
		$this->acting_as( 'subscriber' );

		$result = $this->call( $handler, 'aafm-delete-post', array( 'post_id' => 'not-a-number' ) );

		$this->assertTrue( $result->getIsError() );
		$this->assertStringNotContainsString( 'invalid input', $result->getContent()[0]->getText(), 'A caller the gate refuses is not told about the schema.' );
		$rows = $this->rows( 'aafm/delete-post' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'denied', $rows[0]['status'] );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	public function test_a_standalone_validation_probe_has_no_side_effects(): void {
		$this->register_enabled( array( 'aafm/get-posts' ) );
		$this->acting_as( 'administrator' );
		update_option( 'aafm_rate_limit_per_min', 1 );
		aafm_policy_reset_request_state();

		$probe = wp_get_ability( 'aafm/get-posts' )->validate_input( array( 'search' => array( 'x' ) ) );

		$this->assertWPError( $probe );
		$this->assertSame( 'ability_invalid_input', $probe->get_error_code(), 'A probe gets the plain validation answer.' );
		$this->assertCount( 0, $this->rows( 'aafm/get-posts' ), 'A probe is not a call, so it writes no row.' );
		$this->assertFalse( $this->call( $this->handler( array( 'aafm/get-posts' ) ), 'aafm-get-posts', array() )->getIsError(), 'And it spent no token.' );
	}

	public function test_a_nested_tool_call_is_governed_while_another_ability_executes(): void {
		$this->register_enabled( array( 'aafm/get-posts' ) );
		$this->acting_as( 'administrator' );
		$handler = $this->handler( array( 'aafm/get-posts' ) );

		aafm_executing_ability_push( 'aafm-bridge/some-foreign-ability' );
		try {
			$result = $this->call( $handler, 'aafm-get-posts', array( 'search' => array( 'x' ) ) );
		} finally {
			aafm_executing_ability_pop();
		}

		$this->assertTrue( $result->getIsError() );
		$rows = $this->rows( 'aafm/get-posts' );
		$this->assertCount( 1, $rows, 'The nested call gets its own row.' );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	public function test_a_normalize_refusal_is_governed_like_a_validation_refusal(): void {
		if ( ! class_exists( 'WP_Filter_Sentinel' ) ) {
			$this->markTestSkipped( 'wp_ability_normalize_input exists on WordPress 7.1 and later.' );
		}
		$this->register_enabled( array( 'aafm/delete-post' ) );
		$post_id = self::factory()->post->create();
		$this->acting_as( 'administrator' );
		$handler = $this->handler( array( 'aafm/delete-post' ) );
		$refuse  = static function ( $input, $name ) {
			return 'aafm/delete-post' === $name ? new \WP_Error( 'test_normalize_refusal', 'Refused by a normalize filter.' ) : $input;
		};
		add_filter( 'wp_ability_normalize_input', $refuse, 10, 2 );

		$this->acting_as( 'subscriber' );
		$denied = $this->call( $handler, 'aafm-delete-post', array( 'post_id' => $post_id ) );
		$this->acting_as( 'administrator' );
		$refused = $this->call( $handler, 'aafm-delete-post', array( 'post_id' => $post_id ) );
		remove_filter( 'wp_ability_normalize_input', $refuse, 10 );

		$this->assertTrue( $denied->getIsError() );
		$this->assertStringNotContainsString( 'normalize filter', $denied->getContent()[0]->getText(), 'A caller the gate refuses is denied, not shown the filter error.' );
		$this->assertSame( 'Refused by a normalize filter.', $refused->getContent()[0]->getText() );
		$rows = $this->rows( 'aafm/delete-post' );
		$this->assertCount( 2, $rows, 'Each refused call has its own row.' );
		$this->assertSame( 'denied', $rows[0]['status'] );
		$this->assertSame( 'error', $rows[1]['status'] );
		$this->assertStringContainsString( 'rejected by WP_Ability::normalize_input(): test_normalize_refusal', (string) $rows[1]['detail'] );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	public function test_a_missing_argument_is_a_denied_row_with_our_message(): void {
		$this->register_enabled( array( 'aafm/delete-post' ) );
		$this->acting_as( 'administrator' );

		$result = $this->call( $this->handler( array( 'aafm/delete-post' ) ), 'aafm-delete-post', array() );

		$this->assertTrue( $result->getIsError() );
		$this->assertSame( aafm_missing_input_error( 'aafm/delete-post', array( 'post_id' ) )->get_error_message(), $result->getContent()[0]->getText() );
		$rows = $this->rows( 'aafm/delete-post' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'denied', $rows[0]['status'] );
	}
}
