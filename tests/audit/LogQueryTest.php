<?php
/**
 * The shared activity query builder, counts, 7-day summary and connection names.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class LogQueryTest extends TestCase {

	private function log( string $ability, string $status, array $extra = array() ): int {
		return aafm_log_activity(
			array_merge(
				array(
					'ability' => $ability,
					'status'  => $status,
				),
				$extra
			)
		);
	}

	private function set_created_at( int $id, string $when ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( aafm_activity_log_table(), array( 'created_at' => $when ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	private function ids( array $filters ): array {
		$rows = aafm_query_activity( array_merge( $filters, array( 'per_page' => 200 ) ) );
		$ids  = array_map( 'intval', array_column( $rows, 'id' ) );
		sort( $ids );
		return $ids;
	}

	public function test_normalize_drops_unknown_keys_and_invalid_values(): void {
		$clean = aafm_activity_filters_normalize(
			array(
				'status'   => 'denied',
				'ability'  => 'aafm/get-posts',
				'from'     => '2026-02-30',
				'to'       => '2026-03-01',
				'q'        => 'a',
				'plugin'   => 'no-such-plugin',
				'conn'     => 'bad id!',
				'row'      => '0',
				'per_page' => 9,
				'evil'     => "1' OR '1'='1",
			)
		);
		$this->assertSame(
			array(
				'status' => 'denied',
				'tool'   => 'aafm/get-posts',
				'to'     => '2026-03-01',
			),
			$clean
		);
	}

	public function test_a_malformed_date_is_dropped_never_thrown(): void {
		$this->log( 'aafm/get-posts', 'success' );
		$junk = array( "2026-01-01\0", "2026-01-01\n", '2026-1-5', '2026-02-30', '0000-01-01', '2026-01-01 ', '', '..', "\0", array( '2026-01-01' ) );
		foreach ( $junk as $value ) {
			$this->assertSame(
				array(),
				aafm_activity_filters_normalize(
					array(
						'from' => $value,
						'to'   => $value,
					)
				),
				'Dropped: ' . wp_json_encode( $value )
			);
			$this->assertSame(
				$this->ids( array() ),
				$this->ids(
					array(
						'from' => $value,
						'to'   => $value,
					)
				),
				'The query ignores it.'
			);
		}
		$this->assertSame( array( 'from' => '2026-01-01' ), aafm_activity_filters_normalize( array( 'from' => '2026-01-01' ) ) );
	}

	public function test_normalize_is_idempotent(): void {
		$once = aafm_activity_filters_normalize(
			array(
				'status' => 'success',
				'q'      => 'ab',
				'from'   => '2026-03-01',
				'conn'   => 'none',
			)
		);
		$this->assertSame( $once, aafm_activity_filters_normalize( $once ) );
	}

	public function test_request_mapping_reads_filter_as_status_and_ignores_all(): void {
		$this->assertSame( array( 'status' => 'denied' ), aafm_activity_filters_from_request( array( 'filter' => 'denied' ) ) );
		$this->assertSame( array(), aafm_activity_filters_from_request( array( 'filter' => 'all' ) ) );
		$this->assertSame( array(), aafm_activity_filters_from_request( array( 'filter' => array( 'denied' ) ) ) );
		$this->assertSame( array( 'user' => 'ana' ), aafm_activity_filters_from_request( array( 'user' => 'ana' ) ) );
	}

	public function test_status_user_and_tool_filters(): void {
		$a = $this->log( 'aafm/get-posts', 'success', array( 'principal_login' => 'ana' ) );
		$b = $this->log( 'aafm/get-posts', 'denied', array( 'principal_login' => 'bo' ) );
		$c = $this->log( 'aafm/get-pages', 'success', array( 'principal_login' => 'ana' ) );

		$this->assertSame( array( $a, $c ), $this->ids( array( 'status' => 'success' ) ) );
		$this->assertSame( array( $a, $c ), $this->ids( array( 'user' => 'ana' ) ) );
		$this->assertSame( array( $a, $b ), $this->ids( array( 'tool' => 'aafm/get-posts' ) ) );
		$this->assertSame( array( $a, $b ), $this->ids( array( 'ability' => 'aafm/get-posts' ) ), 'The legacy ability key still works.' );
		$this->assertSame(
			array( $a ),
			$this->ids(
				array(
					'tool' => 'aafm/get-posts',
					'user' => 'ana',
				)
			)
		);
	}

	public function test_connection_filter_none_and_a_client_id(): void {
		$app    = $this->log( 'aafm/get-posts', 'success', array( 'client_id' => 'abc123' ) );
		$direct = $this->log( 'aafm/get-posts', 'success' );

		$this->assertSame( array( $app ), $this->ids( array( 'conn' => 'abc123' ) ) );
		$this->assertSame( array( $direct ), $this->ids( array( 'conn' => 'none' ) ) );
	}

	public function test_plugin_filter_separates_core_integration_and_bridge(): void {
		$core   = $this->log( 'aafm/get-posts', 'success' );
		$yoast  = $this->log( 'aafm/yoast-get-post', 'success' );
		$bridge = $this->log( 'aafm-bridge/elementor-get-pages', 'success' );

		$this->assertSame( array( $core ), $this->ids( array( 'plugin' => 'core' ) ) );
		$this->assertSame( array( $yoast ), $this->ids( array( 'plugin' => 'yoast' ) ) );
		$this->assertSame( array( $bridge ), $this->ids( array( 'plugin' => 'bridge' ) ) );
		$this->assertArrayHasKey( 'bridge', aafm_activity_plugin_groups() );
		$this->assertArrayHasKey( 'yoast', aafm_activity_plugin_groups() );
	}

	public function test_date_range_is_inclusive_of_both_utc_days(): void {
		$late  = $this->log( 'aafm/get-posts', 'success' );
		$next  = $this->log( 'aafm/get-posts', 'success' );
		$early = $this->log( 'aafm/get-posts', 'success' );
		$this->set_created_at( $late, '2026-03-01 23:59:59' );
		$this->set_created_at( $next, '2026-03-02 00:00:00' );
		$this->set_created_at( $early, '2026-03-01 00:00:00' );

		$this->assertSame(
			array( $late, $early ),
			$this->ids(
				array(
					'from' => '2026-03-01',
					'to'   => '2026-03-01',
				)
			)
		);
		$this->assertSame( array( $next ), $this->ids( array( 'from' => '2026-03-02' ) ) );
	}

	public function test_search_treats_percent_and_underscore_literally(): void {
		$pct   = $this->log( 'aafm/x', 'success', array( 'detail' => 'Updated 100% done' ) );
		$other = $this->log( 'aafm/x', 'success', array( 'detail' => 'Updated 1000 done' ) );
		$under = $this->log( 'aafm/a_b', 'success' );
		$plain = $this->log( 'aafm/axb', 'success' );

		$this->assertSame( array( $pct ), $this->ids( array( 'q' => '100%' ) ) );
		$this->assertSame( array( $under ), $this->ids( array( 'q' => 'a_b' ) ) );
		$this->assertNotContains( $other, $this->ids( array( 'q' => '100%' ) ) );
		$this->assertNotContains( $plain, $this->ids( array( 'q' => 'a_b' ) ) );
	}

	public function test_the_search_normalizer_keeps_text_that_looks_percent_encoded(): void {
		$this->assertSame( '%ab', aafm_activity_filters_normalize( array( 'q' => '%ab' ) )['q'] );
		$this->assertSame( 'a b', aafm_activity_filters_normalize( array( 'q' => "a\tb" ) )['q'] );
		$this->assertSame( 'bold', aafm_activity_filters_normalize( array( 'q' => '<b>bold</b>' ) )['q'] );
		$this->assertArrayNotHasKey( 'q', aafm_activity_filters_normalize( array( 'q' => 'a' ) ), 'One character is below the two-character floor.' );
	}

	public function test_row_filter_returns_exactly_one_row(): void {
		$one = $this->log( 'aafm/get-posts', 'success' );
		$this->log( 'aafm/get-posts', 'success' );
		$this->assertSame( array( $one ), $this->ids( array( 'row' => $one ) ) );
	}

	public function test_count_matches_the_rows_for_every_filter_class(): void {
		$this->log( 'aafm/get-posts', 'success', array( 'principal_login' => 'ana' ) );
		$this->log(
			'aafm/get-posts',
			'denied',
			array(
				'principal_login' => 'bo',
				'client_id'       => 'abc123',
			)
		);
		$this->log( 'aafm/yoast-get-post', 'error', array( 'detail' => 'Attempted: x' ) );
		foreach ( array(
			array(),
			array( 'status' => 'denied' ),
			array( 'user' => 'ana' ),
			array( 'conn' => 'abc123' ),
			array( 'conn' => 'none' ),
			array( 'plugin' => 'yoast' ),
			array( 'q' => 'Attempted' ),
			array(
				'tool'   => 'aafm/get-posts',
				'status' => 'success',
			),
		) as $filters ) {
			$this->assertSame( count( $this->ids( $filters ) ), aafm_activity_count_matching( $filters ), wp_json_encode( $filters ) );
		}
	}

	public function test_the_count_takes_a_status_or_nothing(): void {
		$this->log( 'aafm/get-posts', 'denied' );
		$this->log( 'aafm/get-posts', 'success' );
		$this->assertSame( 1, aafm_activity_count_matching( array( 'status' => 'denied' ) ) );
		$this->assertSame( 2, aafm_activity_count_matching( array() ) );
		$this->assertSame( 2, aafm_activity_count_matching( array( 'status' => '' ) ) );
		$this->assertCount(
			1,
			aafm_query_activity(
				array(
					'status'  => 'denied',
					'ability' => 'aafm/get-posts',
				)
			)
		);
	}

	public function test_the_agent_call_clause_counts_exactly_what_the_old_count_did(): void {
		$this->log( 'aafm/get-posts', 'success' );
		$this->log( '(transport)', 'denied' );
		$this->log( 'oauth:authorize', 'success' );
		$this->log( 'aafm/activity-log-cleared', 'success' );
		$this->log( 'aafm/read-only-mode', 'success', array( 'event_type' => 'setting_changed' ) );

		$this->assertSame( 1, aafm_agent_call_count() );
		$this->assertSame( 1, aafm_agent_call_count( 'success' ) );
		$this->assertSame( 0, aafm_agent_call_count( 'denied' ) );
	}

	public function test_summary_counts_only_agent_calls_in_the_last_seven_days(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->log( 'aafm/get-posts', 'success' );
		}
		$this->log( 'aafm/get-pages', 'error' );
		$this->log( 'aafm/get-pages', 'error' );
		$this->log( 'aafm/trash-post', 'denied' );
		$this->log( 'aafm/trash-post', 'denied' );
		$this->log( 'oauth:authorize', 'success' );
		$this->log( 'aafm/read-only-mode', 'success', array( 'event_type' => 'setting_changed' ) );
		$old = $this->log( 'aafm/get-posts', 'success' );
		$this->set_created_at( $old, gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) );

		$s = aafm_activity_summary( true );
		$this->assertTrue( $s['ok'] );
		$this->assertSame( 10, $s['total'] );
		$this->assertSame( 6, $s['success'] );
		$this->assertSame( 2, $s['error'] );
		$this->assertSame( 2, $s['denied'] );
		$this->assertSame( 60.0, $s['success_rate'] );
		$this->assertSame( 'aafm/get-posts', $s['top_tools'][0]['ability'] );
		$this->assertSame( 6, $s['top_tools'][0]['count'] );
		$this->assertCount( 3, $s['top_tools'] );
	}

	public function test_summary_rate_is_null_with_no_calls_and_the_memo_is_flushed_by_a_clear(): void {
		$this->assertNull( aafm_activity_summary( true )['success_rate'] );
		$this->log( 'aafm/get-posts', 'success' );
		$this->assertSame( 1, aafm_activity_summary( true )['total'] );
		aafm_activity_summary(); // Memoized.
		$this->log( 'aafm/get-posts', 'success' );
		$this->assertSame( 1, aafm_activity_summary()['total'], 'The memo answers inside its window.' );
		aafm_clear_activity_log();
		$this->assertSame( 0, aafm_activity_summary()['total'], 'A clear drops the memo.' );
	}

	public function test_connection_names_and_labels(): void {
		aafm_install_oauth_tables();
		$client = aafm_oauth_register_client(
			array(
				'redirect_uris' => array( 'https://app.example/cb' ),
				'client_name'   => 'Claude',
			)
		);
		$this->assertIsArray( $client );
		$names = aafm_activity_connection_names( array( $client['client_id'], '', 'gone-client-id-1234' ) );

		$this->assertSame( 'Claude (' . substr( $client['client_id'], 0, 8 ) . ')', aafm_activity_connection_label( array( 'client_id' => $client['client_id'] ), $names ) );
		$this->assertSame( 'Removed app (gone-cli)', aafm_activity_connection_label( array( 'client_id' => 'gone-client-id-1234' ), $names ) );
		$this->assertSame(
			'App Password or direct',
			aafm_activity_connection_label(
				array(
					'client_id'  => '',
					'event_type' => 'ability_call',
					'ability'    => 'aafm/get-posts',
				),
				$names
			)
		);
		$this->assertSame(
			'-',
			aafm_activity_connection_label(
				array(
					'client_id'  => '',
					'event_type' => 'setting_changed',
					'ability'    => 'aafm/read-only-mode',
				),
				$names
			)
		);
		$this->assertSame( array(), aafm_activity_connection_names( array( '' ) ) );
	}

	public function test_two_apps_that_registered_under_the_same_name_get_different_labels(): void {
		aafm_install_oauth_tables();
		$ids = array();
		foreach ( array( 1, 2 ) as $n ) {
			$client = aafm_oauth_register_client(
				array(
					'redirect_uris' => array( 'https://app' . $n . '.example/cb' ),
					'client_name'   => 'Claude',
				)
			);
			$this->assertIsArray( $client );
			$ids[] = $client['client_id'];
		}
		$names = aafm_activity_connection_names( $ids );

		$first  = aafm_activity_connection_label( array( 'client_id' => $ids[0] ), $names );
		$second = aafm_activity_connection_label( array( 'client_id' => $ids[1] ), $names );

		$this->assertNotSame( $first, $second );
		$this->assertStringStartsWith( 'Claude (', $first );
		$this->assertStringContainsString( substr( $ids[1], 0, 8 ), $second );
	}
}
