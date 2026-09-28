<?php
/**
 * Wire-level proof that aafm-tec-create-event and aafm-tec-get-events round-trip through a real
 * tools/call, mirroring GetActivityLogWireTest.php's server-construction pattern.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class TecEventsWireTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		$this->stub_tec();
		add_filter( 'aafm_integration_active_tec', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_tec', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Builds a throwaway single-purpose MCP server, mirroring GetActivityLogWireTest's pattern.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-tec-events-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/tec-create-event', 'aafm/tec-get-events' ) ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (TEC events wire test)',
					'Test-only server carrying only the TEC event abilities.',
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

		$server = $adapter->get_server( $server_id );
		if ( ! $server instanceof \WP\MCP\Core\McpServer ) {
			throw new \RuntimeException( 'Failed to build the test-only TEC events wire server.' );
		}
		return $server;
	}

	public function test_create_and_list_events_round_trip_over_a_real_tools_call(): void {
		$this->register_enabled( array( 'aafm/tec-create-event', 'aafm/tec-get-events' ) );
		$this->acting_as( 'administrator' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$create_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/tec-create-event' ),
				'arguments' => array(
					'title'      => 'Wire Test Event',
					'start_date' => '2027-01-01 09:00:00',
					'end_date'   => '2027-01-01 12:00:00',
					// tec-create-event defaults to draft, and tec-get-events' default list is
					// published-only (Codex round-b finding 6) - explicit here so this round trip
					// exercises the ordinary case rather than accidentally depending on both
					// defaults staying "any".
					'status'     => 'publish',
				),
			),
			'req-tec-events-wire-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $create_result );
		$created = $create_result->getStructuredContent();
		$this->assertSame( 'Wire Test Event', $created['event']['title'] );

		$list_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/tec-get-events' ),
				'arguments' => array( 'search' => 'Wire Test Event' ),
			),
			'req-tec-events-wire-2'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $list_result );
		$listed = $list_result->getStructuredContent();
		$this->assertSame( 1, $listed['total'] );
	}

	/**
	 * Both fault shapes.
	 *
	 * @return iterable<string,array{0:string}>
	 */
	public function data_fault_shapes(): iterable {
		yield 'no-flush' => array( 'no-flush' );
		yield 'real-error' => array( 'real-error' );
	}

	/**
	 * Run $run with the checked-read scope's own post meta load faulted once, in the given shape.
	 * Only the scope quotes the table name, so no other metadata load matches.
	 *
	 * @param string   $shape Fault shape.
	 * @param callable $run   The call to make.
	 * @return mixed
	 */
	private function with_scope_load_fault( string $shape, callable $run ) {
		global $wpdb;
		$needle = array( 'meta_key, meta_value FROM `' . $wpdb->postmeta . '`', ' IN (' );
		Support\QueryFaultInjector::reset_fired_count();
		$suppressed = $wpdb->suppress_errors( true );
		try {
			return 'no-flush' === $shape
				? Support\QueryFaultInjector::fail_nth_query( $needle, 1, $run )
				: Support\QueryFaultInjector::break_query_with_real_error( $needle, $run, 1 );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Assert the generic error create and update return when a save fails.
	 *
	 * @param mixed  $out   The ability's return.
	 * @param string $label Case label.
	 */
	private function assert_generic_error( $out, string $label ): void {
		$this->assertInstanceOf( \WP_Error::class, $out, $label );
		$this->assertSame( 'aafm_error', $out->get_error_code(), $label );
		$this->assertSame( 'The request could not be completed.', $out->get_error_message(), $label );
	}

	/**
	 * A failed metadata load while an event response is built fails the call, after a create,
	 * after an update and on the no-change path.
	 *
	 * @dataProvider data_fault_shapes
	 * @param string $shape Fault shape.
	 */
	public function test_a_failed_metadata_load_in_an_event_response_returns_the_generic_error( string $shape ): void {
		$this->acting_as( 'administrator' );
		$event = aafm_exec_tec_create_event(
			array(
				'title'      => 'Launch',
				'start_date' => '2027-01-01 09:00:00',
				'end_date'   => '2027-01-01 12:00:00',
			)
		);
		$this->assertIsArray( $event );
		$id = (int) $event['event']['id'];

		$cases = array(
			'create'    => static fn() => aafm_exec_tec_create_event(
				array(
					'title'      => 'Second',
					'start_date' => '2027-02-01 09:00:00',
					'end_date'   => '2027-02-01 12:00:00',
				)
			),
			'update'    => static fn() => aafm_exec_tec_update_event(
				array(
					'event_id'   => $id,
					'start_date' => '2027-01-02 09:00:00',
				)
			),
			'no change' => static function () use ( $id ) {
				// The ownership guard loads the event's meta before the response is built. Drop that
				// cached set once the shaper asks for its first key, so the response's own load runs.
				$dropped = false;
				$drop    = static function ( $value, $object_id, $meta_key ) use ( &$dropped, $id ) {
					if ( ! $dropped && (int) $object_id === $id && '_EventStartDate' === $meta_key ) {
						$dropped = true;
						wp_cache_delete( $id, 'post_meta' );
					}
					return $value;
				};
				add_filter( 'get_post_metadata', $drop, 10, 3 );
				$out = aafm_exec_tec_update_event( array( 'event_id' => $id ) );
				remove_filter( 'get_post_metadata', $drop, 10 );
				return $out;
			},
		);
		foreach ( $cases as $label => $run ) {
			$out = $this->with_scope_load_fault( $shape, $run );
			$this->assertSame( 1, Support\QueryFaultInjector::fired_count(), "$label: the load was faulted" );
			$this->assert_generic_error( $out, $label );
		}
	}

	public function test_a_failed_post_read_after_an_event_update_returns_the_generic_error(): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		$event = aafm_exec_tec_create_event( array( 'title' => 'Launch' ) );
		$id    = (int) $event['event']['id'];

		$fault = null;
		$arm   = static function ( $post_id ) use ( &$fault, $id, $wpdb ) {
			if ( (int) $post_id !== $id || null !== $fault ) {
				return;
			}
			// The save is done: drop the post from the cache and fail its next read.
			clean_post_cache( $id );
			$fault = Support\QueryFaultInjector::real_error_filter( array( 'FROM ' . $wpdb->posts . ' WHERE ID = ' . $id ), 1 );
			add_filter( 'query', $fault );
		};
		add_action( 'wp_after_insert_post', $arm );
		Support\QueryFaultInjector::reset_fired_count();
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$out = aafm_exec_tec_update_event(
			array(
				'event_id' => $id,
				'title'    => 'Renamed',
			)
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );
		remove_action( 'wp_after_insert_post', $arm );
		if ( null !== $fault ) {
			remove_filter( 'query', $fault );
		}

		$this->assertSame( 1, Support\QueryFaultInjector::fired_count() );
		$this->assert_generic_error( $out, 'update' );
	}

	/**
	 * The all-day clear's read-back load failing leaves nothing cached, so the event response
	 * built after it still reads the stored dates.
	 *
	 * @dataProvider data_fault_shapes
	 * @param string $shape Fault shape.
	 */
	public function test_a_failed_all_day_read_back_still_answers_with_the_stored_event( string $shape ): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		$event = aafm_exec_tec_create_event(
			array(
				'title'      => 'All day',
				'start_date' => '2027-03-01 00:00:00',
				'end_date'   => '2027-03-01 23:59:59',
				'all_day'    => true,
			)
		);
		$id    = (int) $event['event']['id'];
		update_post_meta( $id, '_EventAllDay', 'yes' );

		$fault = null;
		$arm   = static function ( $meta_ids, $object_id, $meta_key ) use ( &$fault, $shape, $id, $wpdb ) {
			if ( '_EventAllDay' !== $meta_key || (int) $object_id !== $id || null !== $fault ) {
				return;
			}
			$needle = array( 'meta_key, meta_value FROM ' . $wpdb->postmeta . ' ', ' IN (' );
			$fault  = 'no-flush' === $shape ? Support\QueryFaultInjector::no_flush_filter( $needle, 1 ) : Support\QueryFaultInjector::real_error_filter( $needle, 1 );
			add_filter( 'query', $fault );
		};
		add_action( 'deleted_post_meta', $arm, 10, 3 );
		Support\QueryFaultInjector::reset_fired_count();
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$out = aafm_exec_tec_update_event(
			array(
				'event_id' => $id,
				'all_day'  => false,
			)
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );
		remove_action( 'deleted_post_meta', $arm, 10 );
		if ( null !== $fault ) {
			remove_filter( 'query', $fault );
		}

		$this->assertSame( 1, Support\QueryFaultInjector::fired_count() );
		$this->assertIsArray( $out );
		$this->assertSame( '2027-03-01 00:00:00', $out['event']['start_date'] );
		$this->assertSame( '2027-03-01 23:59:59', $out['event']['end_date'] );
		$this->assertFalse( $out['event']['all_day'] );
	}

	/**
	 * The write_outcome rows' decoded detail, in insert order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function outcome_details(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT detail FROM %i WHERE event_type = %s ORDER BY id', aafm_activity_log_table(), 'write_outcome' ) );
		return array_map(
			static function ( $detail ): array {
				return (array) json_decode( (string) $detail, true );
			},
			$rows
		);
	}

	/**
	 * The detail of one tec write_outcome row.
	 *
	 * @param string   $entity Logged entity.
	 * @param int|null $id     Object id, or null.
	 * @param string   $status Status.
	 * @return array<string,mixed>
	 */
	private function tec_row( string $entity, ?int $id, string $status ): array {
		return array(
			'kind'             => 'tec',
			'entity'           => $entity,
			'object_id'        => null === $id ? null : (string) $id,
			'key'              => null,
			'status'           => $status,
			'rows'             => null,
			'modified_by_site' => false,
			'key_omitted'      => false,
		);
	}

	public function test_the_tec_writer_exists(): void {
		$this->assertTrue( function_exists( 'aafm_tec_write' ) );
	}

	public function test_an_event_create_and_update_each_log_one_accepted_row(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );

		$created = aafm_exec_tec_create_event(
			array(
				'title'      => 'Launch',
				'start_date' => '2027-01-01 09:00:00',
				'end_date'   => '2027-01-01 12:00:00',
			)
		);
		$this->assertIsArray( $created );
		$id = (int) $created['event']['id'];
		$this->assertSame( array( 'event' => aafm_tec_event_shape( $id ) ), $created );

		$updated = aafm_exec_tec_update_event(
			array(
				'event_id' => $id,
				'title'    => 'Relaunch',
			)
		);
		$this->assertSame( array( 'event' => aafm_tec_event_shape( $id ) ), $updated );
		$this->assertSame( 'Relaunch', $updated['event']['title'] );

		$this->assertSame( array( $this->tec_row( 'event', $id, 'accepted' ), $this->tec_row( 'event', $id, 'accepted' ) ), $this->outcome_details() );
	}

	public function test_a_refused_event_create_and_update_log_refused_and_return_the_generic_error(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		$event = aafm_exec_tec_create_event( array( 'title' => 'Launch' ) );
		$id    = (int) $event['event']['id'];

		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$create = aafm_exec_tec_create_event( array( 'title' => 'Refused' ) );
		$update = aafm_exec_tec_update_event(
			array(
				'event_id' => $id,
				'title'    => 'Refused rename',
			)
		);
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertInstanceOf( \WP_Error::class, $create );
		$this->assertSame( 'aafm_error', $create->get_error_code() );
		$this->assertInstanceOf( \WP_Error::class, $update );
		$this->assertSame( 'aafm_error', $update->get_error_code() );
		$details = $this->outcome_details();
		$this->assertSame( array( $this->tec_row( 'event', null, 'refused' ), $this->tec_row( 'event', $id, 'refused' ) ), array_slice( $details, 1 ) );
	}

	public function test_an_all_day_clear_logs_the_tec_row_then_the_metadata_row(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		$event = aafm_exec_tec_create_event(
			array(
				'title'   => 'All day',
				'all_day' => true,
			)
		);
		$id    = (int) $event['event']['id'];
		update_post_meta( $id, '_EventAllDay', 'yes' );

		$out = aafm_exec_tec_update_event(
			array(
				'event_id' => $id,
				'all_day'  => false,
			)
		);

		$this->assertIsArray( $out );
		$details = array_slice( $this->outcome_details(), 1 );
		$this->assertCount( 2, $details );
		$this->assertSame( $this->tec_row( 'event', $id, 'accepted' ), $details[0] );
		$this->assertSame( array( 'post_meta', '_EventAllDay', 'deleted' ), array( $details[1]['kind'], $details[1]['key'], $details[1]['status'] ) );
	}

	public function test_a_no_change_event_update_logs_no_row(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		$event  = aafm_exec_tec_create_event( array( 'title' => 'Launch' ) );
		$before = count( $this->outcome_details() );

		$out = aafm_exec_tec_update_event( array( 'event_id' => (int) $event['event']['id'] ) );

		$this->assertIsArray( $out );
		$this->assertCount( $before, $this->outcome_details() );
	}

	public function test_an_event_update_with_id_zero_creates_nothing_and_logs_nothing(): void {
		global $wpdb;
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		$count  = static function () use ( $wpdb ): int {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, \Tribe__Events__Main::POSTTYPE ) );
		};
		$before = $count();

		$out = aafm_exec_tec_update_event(
			array(
				'event_id' => 0,
				'title'    => 'Should not exist',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( $before, $count() );
		$this->assertSame( array(), $this->outcome_details() );
	}
}
