<?php
/**
 * Wire-level proof that aafm-tec-create-venue and aafm-tec-create-organizer round-trip through a
 * real tools/call.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class TecVenuesOrganizersWireTest extends TestCase {

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
		$server_id = 'aafm-server-tec-venues-organizers-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/tec-create-venue', 'aafm/tec-create-organizer' ) ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (TEC venues/organizers wire test)',
					'Test-only server carrying only the TEC venue/organizer create abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only TEC venues/organizers wire server.' );
		}
		return $server;
	}

	public function test_create_venue_and_organizer_round_trip_over_a_real_tools_call(): void {
		$this->register_enabled( array( 'aafm/tec-create-venue', 'aafm/tec-create-organizer' ) );
		$this->acting_as( 'administrator' );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $this->build_server( $adapter );
		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );

		$venue_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/tec-create-venue' ),
				'arguments' => array( 'title' => 'Wire Test Venue' ),
			),
			'req-tec-vo-wire-1'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $venue_result );
		$this->assertSame( 'Wire Test Venue', $venue_result->getStructuredContent()['venue']['title'] );

		$organizer_result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/tec-create-organizer' ),
				'arguments' => array( 'title' => 'Wire Test Organizer' ),
			),
			'req-tec-vo-wire-2'
		);
		$this->assertNotInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $organizer_result );
		$this->assertSame( 'Wire Test Organizer', $organizer_result->getStructuredContent()['organizer']['title'] );
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
	 * Run $run with the object's cached meta dropped when the response shaper asks for $key, so
	 * the response's own metadata load runs even when an earlier read cached the set.
	 *
	 * @param int      $id  Post id.
	 * @param string   $key The first key the shaper reads.
	 * @param callable $run The call to make.
	 * @return mixed
	 */
	private function with_cached_meta_dropped( int $id, string $key, callable $run ) {
		$dropped = false;
		$drop    = static function ( $value, $object_id, $meta_key ) use ( &$dropped, $id, $key ) {
			if ( ! $dropped && (int) $object_id === $id && $key === $meta_key ) {
				$dropped = true;
				wp_cache_delete( $id, 'post_meta' );
			}
			return $value;
		};
		add_filter( 'get_post_metadata', $drop, 10, 3 );
		try {
			return $run();
		} finally {
			remove_filter( 'get_post_metadata', $drop, 10 );
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
	 * A failed metadata load after a save fails the call instead of answering with empty fields.
	 *
	 * @dataProvider data_fault_shapes
	 * @param string $shape Fault shape.
	 */
	public function test_a_failed_metadata_load_after_a_save_returns_the_generic_error( string $shape ): void {
		$this->acting_as( 'administrator' );
		$venue     = aafm_exec_tec_create_venue(
			array(
				'title' => 'Hall',
				'city'  => 'Lahore',
			)
		);
		$organizer = aafm_exec_tec_create_organizer(
			array(
				'title' => 'Org',
				'phone' => '123',
			)
		);
		$this->assertIsArray( $venue );
		$this->assertIsArray( $organizer );

		$cases = array(
			'create venue'     => static fn() => aafm_exec_tec_create_venue(
				array(
					'title' => 'New hall',
					'city'  => 'Karachi',
				)
			),
			'create organizer' => static fn() => aafm_exec_tec_create_organizer(
				array(
					'title' => 'New org',
					'phone' => '456',
				)
			),
			'update venue'     => static fn() => aafm_exec_tec_update_venue(
				array(
					'venue_id' => $venue['venue']['id'],
					'city'     => 'Quetta',
				)
			),
			'update organizer' => static fn() => aafm_exec_tec_update_organizer(
				array(
					'organizer_id' => $organizer['organizer']['id'],
					'phone'        => '789',
				)
			),
			'no-change venue'  => fn() => $this->with_cached_meta_dropped(
				(int) $venue['venue']['id'],
				'_VenueAddress',
				static fn() => aafm_exec_tec_update_venue( array( 'venue_id' => $venue['venue']['id'] ) )
			),
			'no-change org'    => fn() => $this->with_cached_meta_dropped(
				(int) $organizer['organizer']['id'],
				'_OrganizerEmail',
				static fn() => aafm_exec_tec_update_organizer( array( 'organizer_id' => $organizer['organizer']['id'] ) )
			),
		);
		foreach ( $cases as $label => $run ) {
			$out = $this->with_scope_load_fault( $shape, $run );
			$this->assertSame( 1, Support\QueryFaultInjector::fired_count(), "$label: the load was faulted" );
			$this->assert_generic_error( $out, $label );
		}
	}

	public function test_a_failed_post_read_after_an_update_returns_the_generic_error(): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		$venue     = aafm_exec_tec_create_venue( array( 'title' => 'Hall' ) );
		$organizer = aafm_exec_tec_create_organizer( array( 'title' => 'Org' ) );

		$cases = array(
			'venue'     => array(
				(int) $venue['venue']['id'],
				static fn( int $id ) => aafm_exec_tec_update_venue(
					array(
						'venue_id' => $id,
						'title'    => 'Renamed hall',
					)
				),
			),
			'organizer' => array(
				(int) $organizer['organizer']['id'],
				static fn( int $id ) => aafm_exec_tec_update_organizer(
					array(
						'organizer_id' => $id,
						'title'        => 'Renamed org',
					)
				),
			),
		);
		foreach ( $cases as $label => $case ) {
			list( $id, $run ) = $case;
			$fault            = null;
			$arm              = static function ( $post_id ) use ( &$fault, $id, $wpdb ) {
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
			$out = $run( $id );
			ob_end_clean();
			$wpdb->suppress_errors( $suppressed );
			remove_action( 'wp_after_insert_post', $arm );
			if ( null !== $fault ) {
				remove_filter( 'query', $fault );
			}

			$this->assertSame( 1, Support\QueryFaultInjector::fired_count(), "$label: the post read was faulted" );
			$this->assert_generic_error( $out, $label );
		}
	}

	public function test_get_venue_still_reads_its_fields(): void {
		$this->acting_as( 'administrator' );
		$venue = aafm_exec_tec_create_venue(
			array(
				'title' => 'Hall',
				'city'  => 'Lahore',
			)
		);
		$read  = aafm_exec_tec_get_venue( array( 'venue_id' => $venue['venue']['id'] ) );

		$this->assertIsArray( $read );
		$this->assertSame( $venue['venue'], $read['venue'] );
		$this->assertSame( 'Lahore', $read['venue']['city'] );
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

	public function test_venue_and_organizer_creates_and_updates_log_one_accepted_row_each(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );

		$venue = aafm_exec_tec_create_venue(
			array(
				'title' => 'Hall',
				'city'  => 'Lahore',
			)
		);
		$vid   = (int) $venue['venue']['id'];
		$this->assertSame( array( 'venue' => aafm_tec_venue_shape( $vid ) ), $venue );
		$venue_updated = aafm_exec_tec_update_venue(
			array(
				'venue_id' => $vid,
				'city'     => 'Karachi',
			)
		);
		$this->assertSame( array( 'venue' => aafm_tec_venue_shape( $vid ) ), $venue_updated );
		$this->assertSame( 'Karachi', $venue_updated['venue']['city'] );

		$organizer = aafm_exec_tec_create_organizer(
			array(
				'title' => 'Org',
				'phone' => '123',
			)
		);
		$oid       = (int) $organizer['organizer']['id'];
		$this->assertSame( array( 'organizer' => aafm_tec_organizer_shape( $oid ) ), $organizer );
		$organizer_updated = aafm_exec_tec_update_organizer(
			array(
				'organizer_id' => $oid,
				'phone'        => '456',
			)
		);
		$this->assertSame( array( 'organizer' => aafm_tec_organizer_shape( $oid ) ), $organizer_updated );

		$this->assertSame(
			array(
				$this->tec_row( 'venue', $vid, 'accepted' ),
				$this->tec_row( 'venue', $vid, 'accepted' ),
				$this->tec_row( 'organizer', $oid, 'accepted' ),
				$this->tec_row( 'organizer', $oid, 'accepted' ),
			),
			$this->outcome_details()
		);
	}

	public function test_refused_venue_and_organizer_writes_log_refused_and_return_the_generic_error(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		$vid = (int) aafm_exec_tec_create_venue( array( 'title' => 'Hall' ) )['venue']['id'];
		$oid = (int) aafm_exec_tec_create_organizer( array( 'title' => 'Org' ) )['organizer']['id'];

		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$outs = array(
			aafm_exec_tec_create_venue( array( 'title' => 'Refused' ) ),
			aafm_exec_tec_update_venue(
				array(
					'venue_id' => $vid,
					'title'    => 'Refused rename',
				)
			),
			aafm_exec_tec_create_organizer( array( 'title' => 'Refused' ) ),
			aafm_exec_tec_update_organizer(
				array(
					'organizer_id' => $oid,
					'title'        => 'Refused rename',
				)
			),
		);
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		foreach ( $outs as $out ) {
			$this->assertInstanceOf( \WP_Error::class, $out );
			$this->assertSame( 'aafm_error', $out->get_error_code() );
		}
		$this->assertSame(
			array(
				$this->tec_row( 'venue', null, 'refused' ),
				$this->tec_row( 'venue', $vid, 'refused' ),
				$this->tec_row( 'organizer', null, 'refused' ),
				$this->tec_row( 'organizer', $oid, 'refused' ),
			),
			array_slice( $this->outcome_details(), 2 )
		);
	}

	public function test_no_change_venue_and_organizer_updates_log_no_row(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );
		$vid    = (int) aafm_exec_tec_create_venue( array( 'title' => 'Hall' ) )['venue']['id'];
		$oid    = (int) aafm_exec_tec_create_organizer( array( 'title' => 'Org' ) )['organizer']['id'];
		$before = count( $this->outcome_details() );

		$this->assertIsArray( aafm_exec_tec_update_venue( array( 'venue_id' => $vid ) ) );
		$this->assertIsArray( aafm_exec_tec_update_organizer( array( 'organizer_id' => $oid ) ) );
		$this->assertCount( $before, $this->outcome_details() );
	}

	public function test_venue_and_organizer_updates_with_id_zero_create_nothing(): void {
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->acting_as( 'administrator' );

		$venue     = aafm_exec_tec_update_venue(
			array(
				'venue_id' => 0,
				'title'    => 'Should not exist',
			)
		);
		$organizer = aafm_exec_tec_update_organizer(
			array(
				'organizer_id' => 0,
				'title'        => 'Should not exist',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $venue );
		$this->assertInstanceOf( \WP_Error::class, $organizer );
		$this->assertSame( array(), $this->outcome_details() );
	}
}
