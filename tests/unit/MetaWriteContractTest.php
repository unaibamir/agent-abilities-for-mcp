<?php
/**
 * The state-matrix table test for the write-and-confirm contract: 24 baseline/intent
 * rows by 7 faults, run for post, term and user meta, plus a set of supplementary rows pinning
 * cases the grid does not reach on its own. Every expected value is a string literal; none is
 * computed by a production function or by the comparator under test.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class MetaWriteContractTest extends TestCase {

	private const KEY = 'aafm_contract_key';

	/**
	 * The raw meta_value column of a row holding the object (object) array( 'k' => 'old' ).
	 */
	private const OBJECT_ROW = 'O:8:"stdClass":1:{s:1:"k";s:3:"old";}';

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
	}

	public function tear_down(): void {
		remove_all_filters( 'update_post_metadata' );
		remove_all_filters( 'update_term_metadata' );
		remove_all_filters( 'update_user_metadata' );
		remove_all_filters( 'delete_post_metadata' );
		remove_all_filters( 'delete_term_metadata' );
		remove_all_filters( 'delete_user_metadata' );
		remove_all_filters( 'sanitize_post_meta_' . self::KEY );
		remove_all_filters( 'sanitize_term_meta_' . self::KEY );
		remove_all_filters( 'sanitize_user_meta_' . self::KEY );
		parent::tear_down();
	}

	/**
	 * Every (type, baseline, intent, fault, shape) combination the state matrix names.
	 *
	 * @return iterable<string, array{0:string,1:string,2:string,3:string,4:?string}>
	 */
	public function data_grid(): iterable {
		$baselines    = array( 'absent', 'present-empty', 'scalar', 'array', 'serialized-empty', 'duplicate' );
		$intents      = array( 'no-op', 'change', 'clear', 'delete' );
		$faults       = array( 'clean', 'veto-false', 'veto-true', 'transform', 'baseline-read-fault', 'write-fault', 'confirm-read-fault' );
		$query_faults = array( 'baseline-read-fault', 'write-fault', 'confirm-read-fault' );

		foreach ( array( 'post', 'term', 'user' ) as $type ) {
			foreach ( $baselines as $baseline ) {
				foreach ( $intents as $intent ) {
					foreach ( $faults as $fault ) {
						if ( in_array( $fault, $query_faults, true ) ) {
							yield "$type/$baseline/$intent/$fault/no-flush" => array( $type, $baseline, $intent, $fault, 'no-flush' );
							yield "$type/$baseline/$intent/$fault/real-error" => array( $type, $baseline, $intent, $fault, 'real-error' );
						} else {
							yield "$type/$baseline/$intent/$fault" => array( $type, $baseline, $intent, $fault, null );
						}
					}
				}
			}
		}
	}

	/**
	 * One grid cell: seed the baseline, arm the fault, run the intent, assert the literal.
	 *
	 * @dataProvider data_grid
	 * @param string      $type     'post', 'term' or 'user'.
	 * @param string      $baseline Baseline shape.
	 * @param string      $intent   Requested operation.
	 * @param string      $fault    Fault column.
	 * @param string|null $shape    Query-fault shape, or null for a non-query fault.
	 */
	public function test_grid_cell( string $type, string $baseline, string $intent, string $fault, ?string $shape ): void {
		list( $expected_status, $expected_rows, $expected_end_state ) = self::GRID[ $baseline ][ $intent ][ $fault ];

		$object_id = $this->make_object( $type );
		$this->seed_baseline( $type, $object_id, $baseline );

		$label = "type=$type baseline=$baseline intent=$intent fault=$fault shape=" . ( $shape ?? 'n/a' );

		$this->assert_raw_rows( $type, $object_id, $this->baseline_values( $baseline ), "precondition: $label" );

		if ( 'transform' === $fault ) {
			$this->register_transform( $type );
		}

		$veto = null;
		if ( 'veto-false' === $fault || 'veto-true' === $fault ) {
			$veto = 'veto-true' === $fault;
		}

		QueryFaultInjector::reset_fired_count();
		$veto_calls = 0;

		$result = $this->run_case( $type, $object_id, $intent, $veto, $fault, $shape, $veto_calls );

		$this->assertSame( $expected_status, $result['status'] ?? null, "status mismatch: $label" );

		if ( null !== $expected_rows ) {
			$this->assertSame( $expected_rows, $result['rows'] ?? null, "rows mismatch: $label" );
		} else {
			$this->assertArrayNotHasKey( 'rows', $result, "rows must be absent: $label" );
		}

		// The fired count, exactly: 0 when the literal is unchanged or absent, 1 otherwise. Veto
		// columns count the test's own filter closure; the three query-fault columns count
		// QueryFaultInjector. Clean and transform carry no counter.
		$fires = ! in_array( $expected_status, array( 'unchanged', 'absent' ), true ) ? 1 : 0;
		if ( in_array( $fault, array( 'veto-false', 'veto-true' ), true ) ) {
			$this->assertSame( $fires, $veto_calls, "veto call count: $label" );
		} elseif ( in_array( $fault, array( 'baseline-read-fault', 'write-fault', 'confirm-read-fault' ), true ) ) {
			$this->assertSame( $fires, QueryFaultInjector::fired_count(), "fault fired count: $label" );
		}

		// Durable end state, read by a direct uncached query, against a literal never read from $result.
		$this->assert_raw_rows( $type, $object_id, $expected_end_state, "end state: $label" );
	}

	/**
	 * Supplementary rows: cases the 24x7 grid does not exercise on its own.
	 */
	public function test_s1_stateful_sanitizer_reports_modified_by_site(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, self::KEY, 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );

		$calls = 0;
		add_filter(
			'sanitize_post_meta_' . self::KEY,
			static function ( $value ) use ( &$calls ) {
				++$calls;
				return $value . '-' . $calls;
			},
			10,
			1
		);

		$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );

		$this->assertSame( 'written', $result['status'] );
		$this->assertTrue( $result['modified_by_site'] ?? false );
		// sanitize_meta() runs twice on this path (the helper's own canonical computation, then
		// core's own call inside update_metadata()); the appending callback sees both, so the
		// row core actually stores is the second invocation's value.
		$this->assert_raw_rows( 'post', $post_id, array( 'new-2' ), 'end state' );
	}

	public function test_s2_duplicate_no_op_makes_no_write_call(): void {
		$post_id = $this->make_object( 'post' );
		$this->write_raw( 'post', $post_id, 'a' );
		$this->write_raw_duplicate( 'post', $post_id, 'a' );
		$this->assert_raw_rows( 'post', $post_id, array( 'a', 'a' ), 'precondition' );

		$fired = false;
		add_filter(
			'update_post_metadata',
			static function ( $check ) use ( &$fired ) {
				$fired = true;
				return $check;
			}
		);

		$result = aafm_meta_set( 'post', $post_id, self::KEY, 'a', 'post', false );

		$this->assertSame( 'unchanged', $result['status'] );
		$this->assertSame( 2, $result['rows'] ?? null );
		$this->assertFalse( $fired, 'a no-op must never call update_metadata' );
		$this->assert_raw_rows( 'post', $post_id, array( 'a', 'a' ), 'end state' );
	}

	public function test_s3_veto_true_plus_a_read_rewriting_filter_reports_modified_by_site(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, self::KEY, 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );

		add_filter( 'update_post_metadata', '__return_true' );
		add_filter(
			'get_post_metadata',
			static function ( $value, $object_id, $meta_key ) {
				if ( self::KEY === $meta_key ) {
					return array( 'zzz' );
				}
				return $value;
			},
			10,
			3
		);

		$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );

		remove_filter( 'update_post_metadata', '__return_true' );

		$this->assertSame( 'written', $result['status'] );
		$this->assertTrue( $result['modified_by_site'] ?? false );
		// The veto blocks core's own UPDATE, so the row on disk never moves; the read-back the
		// helper certifies against is the read-rewriting filter's own value, not the database's.
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state' );
	}

	public function test_s4_veto_true_delete_plus_confirm_read_fault_no_flush_reports_refused(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, self::KEY, 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );

		$armed = false;
		$table = $GLOBALS['wpdb']->postmeta;
		$arm   = function () use ( &$armed, $table ) {
			if ( $armed ) {
				return;
			}
			$armed = true;
			add_filter( 'query', QueryFaultInjector::no_flush_filter( $table, 1 ) );
		};
		add_filter(
			'delete_post_metadata',
			static function () use ( $arm ) {
				$arm();
				return true;
			}
		);

		QueryFaultInjector::reset_fired_count();
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = aafm_meta_delete( 'post', $post_id, self::KEY );
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'the confirm-read fault must fire exactly once' );
		// The veto skips the actual DELETE, so the row on disk survives untouched.
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state' );
	}

	public function test_s4_veto_true_delete_plus_confirm_read_fault_real_error_reports_deleted(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, self::KEY, 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );

		$armed = false;
		$table = $GLOBALS['wpdb']->postmeta;
		$arm   = function () use ( &$armed, $table ) {
			if ( $armed ) {
				return;
			}
			$armed = true;
			add_filter( 'query', QueryFaultInjector::real_error_filter( $table, 1 ) );
		};
		add_filter(
			'delete_post_metadata',
			static function () use ( $arm ) {
				$arm();
				return true;
			}
		);

		QueryFaultInjector::reset_fired_count();
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = aafm_meta_delete( 'post', $post_id, self::KEY );
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 'deleted', $result['status'] );
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'the confirm-read fault must fire exactly once' );
		// The double-fault residual: the veto also skips the actual DELETE, so the row
		// survives even though the acknowledged-delete-plus-failed-read-back rule reports deleted.
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state' );
	}

	public function test_s5_scalar_only_rejects_a_non_scalar_intent(): void {
		$post_id = $this->make_object( 'post' );
		$this->assert_raw_rows( 'post', $post_id, array(), 'precondition' );

		$result = aafm_meta_set( 'post', $post_id, self::KEY, array( 'x' ), 'post', true );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_meta_value_invalid', $result->get_error_code() );
		$this->assert_raw_rows( 'post', $post_id, array(), 'end state' );
	}

	public function test_s6_group_second_key_baseline_read_fails_leaves_every_key_read_failed(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'kept' );
		$this->assert_raw_rows( 'post', $post_id, array( 'kept' ), 'precondition', 'aafm_g_one' );

		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = QueryFaultInjector::fail_query(
			$wpdb->postmeta,
			static function () use ( $post_id ) {
				return aafm_meta_set_group(
					'post',
					$post_id,
					array(
						'aafm_g_one' => 'new',
						'aafm_g_two' => 'new',
					),
					'post'
				); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 'read_failed', $result['status'] );
		$this->assertSame( 'read_failed', $result['keys']['aafm_g_one']['status'] );
		$this->assertSame( 'read_failed', $result['keys']['aafm_g_two']['status'] );
		$this->assert_raw_rows( 'post', $post_id, array( 'kept' ), 'end state: aafm_g_one must stay unwritten', 'aafm_g_one' );
	}

	public function test_s7_group_second_key_invalid_leaves_first_key_unwritten(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'kept' );
		$this->assert_raw_rows( 'post', $post_id, array( 'kept' ), 'precondition', 'aafm_g_one' );

		$queried_meta_table = false;
		$query_counter      = static function ( string $query ) use ( &$queried_meta_table ): string {
			if ( false !== stripos( $query, 'postmeta' ) ) {
				$queried_meta_table = true;
			}
			return $query;
		};
		add_filter( 'query', $query_counter );

		$veto_calls = 0;
		$veto       = static function ( $check ) use ( &$veto_calls ) {
			++$veto_calls;
			return $check;
		};
		add_filter( 'update_post_metadata', $veto );

		$result = aafm_meta_set_group(
			'post',
			$post_id,
			array(
				'aafm_g_one' => 'new',
				'aafm_g_two' => array( 'x' ),
			),
			'post'
		);

		remove_filter( 'query', $query_counter );
		remove_filter( 'update_post_metadata', $veto );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_meta_value_invalid', $result->get_error_code() );
		$this->assertFalse( $queried_meta_table, 'a validation failure on any group member must read nothing from the meta table.' );
		$this->assertSame( 0, $veto_calls, 'a validation failure on any group member must write nothing.' );
		$this->assert_raw_rows( 'post', $post_id, array( 'kept' ), 'end state: aafm_g_one must stay unwritten', 'aafm_g_one' );
	}

	public function test_s8_a_serialized_looking_string_intent_is_stored_and_read_back_as_a_string(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, self::KEY, 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );

		$result = aafm_meta_set( 'post', $post_id, self::KEY, 'a:0:{}', 'post', false );

		$this->assertSame( 'written', $result['status'] );
		$this->assertSame( 'a:0:{}', $result['value'] );
		$this->assertArrayNotHasKey( 'modified_by_site', $result );
		$this->assert_raw_rows( 'post', $post_id, array( 'a:0:{}' ), 'end state: the stored row must decode to the string, never an array.' );
		$this->assert_raw_columns( 'post', $post_id, array( 's:6:"a:0:{}";' ), 'end state: core serializes a serialized-looking string a second time.' );
	}

	public function test_s9_a_veto_true_write_over_a_serialized_looking_baseline_reports_unconfirmed(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, self::KEY, 'a:0:{}' );
		$this->assert_raw_columns( 'post', $post_id, array( 's:6:"a:0:{}";' ), 'precondition: the raw column stores the string serialized a second time.' );

		add_filter( 'update_post_metadata', '__return_true' );
		$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );
		remove_filter( 'update_post_metadata', '__return_true' );

		$this->assertSame( 'unconfirmed', $result['status'] );
		$this->assert_raw_columns( 'post', $post_id, array( 's:6:"a:0:{}";' ), 'end state: the baseline row must survive untouched.' );
	}

	public function test_s10_group_written_plus_unchanged_reports_aggregate_written(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'old' );
		update_post_meta( $post_id, 'aafm_g_two', 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_two', 'aafm_g_two' );

		$result = aafm_meta_set_group(
			'post',
			$post_id,
			array(
				'aafm_g_one' => 'new',
				'aafm_g_two' => 'old',
			),
			'post'
		);

		$this->assertSame( 'written', $result['status'] );
		$this->assertSame( 'written', $result['keys']['aafm_g_one']['status'] );
		$this->assertSame( 'unchanged', $result['keys']['aafm_g_two']['status'] );
		$this->assert_raw_rows( 'post', $post_id, array( 'new' ), 'end state: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state: aafm_g_two', 'aafm_g_two' );
	}

	public function test_s11_group_unchanged_then_refused_reports_aggregate_refused(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'old' );
		update_post_meta( $post_id, 'aafm_g_two', 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_two', 'aafm_g_two' );

		add_filter(
			'update_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return 'aafm_g_two' === $meta_key ? false : $check;
			},
			10,
			3
		);

		$result = aafm_meta_set_group(
			'post',
			$post_id,
			array(
				'aafm_g_one' => 'old',
				'aafm_g_two' => 'new',
			),
			'post'
		);

		remove_all_filters( 'update_post_metadata' );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'unchanged', $result['keys']['aafm_g_one']['status'] );
		$this->assertSame( 'refused', $result['keys']['aafm_g_two']['status'] );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state: aafm_g_two', 'aafm_g_two' );
	}

	public function test_s12_group_written_then_refused_reports_aggregate_partial(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'old' );
		update_post_meta( $post_id, 'aafm_g_two', 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_two', 'aafm_g_two' );

		add_filter(
			'update_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return 'aafm_g_two' === $meta_key ? false : $check;
			},
			10,
			3
		);

		$result = aafm_meta_set_group(
			'post',
			$post_id,
			array(
				'aafm_g_one' => 'new',
				'aafm_g_two' => 'new',
			),
			'post'
		);

		remove_all_filters( 'update_post_metadata' );

		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( 'written', $result['keys']['aafm_g_one']['status'] );
		$this->assertSame( 'refused', $result['keys']['aafm_g_two']['status'] );
		$this->assert_raw_rows( 'post', $post_id, array( 'new' ), 'end state: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state: aafm_g_two', 'aafm_g_two' );
	}

	public function test_s13_group_both_unchanged_reports_aggregate_unchanged(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'old' );
		update_post_meta( $post_id, 'aafm_g_two', 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_two', 'aafm_g_two' );

		$fired = false;
		add_filter(
			'update_post_metadata',
			static function ( $check ) use ( &$fired ) {
				$fired = true;
				return $check;
			}
		);

		$result = aafm_meta_set_group(
			'post',
			$post_id,
			array(
				'aafm_g_one' => 'old',
				'aafm_g_two' => 'old',
			),
			'post'
		);

		remove_all_filters( 'update_post_metadata' );

		$this->assertSame( 'unchanged', $result['status'] );
		$this->assertSame( 'unchanged', $result['keys']['aafm_g_one']['status'] );
		$this->assertSame( 'unchanged', $result['keys']['aafm_g_two']['status'] );
		$this->assertFalse( $fired, 'no key needs a write call when both are already canonical.' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state: aafm_g_two', 'aafm_g_two' );
	}

	public function test_s15a_single_writer_object_baseline_writes_the_scalar_cleanly(): void {
		$post_id = $this->make_object( 'post' );
		$this->write_raw( 'post', $post_id, (object) array( 'k' => 'old' ) );
		$this->assert_raw_columns( 'post', $post_id, array( self::OBJECT_ROW ), 'precondition' );

		$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );

		$this->assertSame( 'written', $result['status'] );
		$this->assert_raw_rows( 'post', $post_id, array( 'new' ), 'end state' );
	}

	public function test_s15b_group_member_object_baseline_writes_the_scalar_cleanly(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'old' );
		update_post_meta( $post_id, 'aafm_g_two', (object) array( 'k' => 'old' ) );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_columns( 'post', $post_id, array( self::OBJECT_ROW ), 'precondition: aafm_g_two', 'aafm_g_two' );

		$result = aafm_meta_set_group(
			'post',
			$post_id,
			array(
				'aafm_g_one' => 'new',
				'aafm_g_two' => 'new',
			),
			'post'
		);

		$this->assertSame( 'written', $result['status'] );
		$this->assertSame( 'written', $result['keys']['aafm_g_one']['status'] );
		$this->assertSame( 'written', $result['keys']['aafm_g_two']['status'] );
		$this->assert_raw_rows( 'post', $post_id, array( 'new' ), 'end state: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array( 'new' ), 'end state: aafm_g_two', 'aafm_g_two' );
	}

	public function test_s15c_veto_true_plus_a_read_rewriting_filter_returning_an_object_row_reports_modified_by_site(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, self::KEY, 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );

		add_filter( 'update_post_metadata', '__return_true' );
		add_filter(
			'get_post_metadata',
			static function ( $value, $object_id, $meta_key ) {
				if ( self::KEY === $meta_key ) {
					return array( (object) array( 'k' => 'old' ) );
				}
				return $value;
			},
			10,
			3
		);

		$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );

		remove_filter( 'update_post_metadata', '__return_true' );

		$this->assertSame( 'written', $result['status'] );
		$this->assertTrue( $result['modified_by_site'] ?? false );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state' );
	}

	public function test_s15d_single_writer_object_baseline_under_veto_true_reports_unconfirmed(): void {
		$post_id = $this->make_object( 'post' );
		$this->write_raw( 'post', $post_id, (object) array( 'k' => 'old' ) );
		$this->assert_raw_columns( 'post', $post_id, array( self::OBJECT_ROW ), 'precondition' );

		add_filter( 'update_post_metadata', '__return_true' );
		$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );
		remove_filter( 'update_post_metadata', '__return_true' );

		// The read-back decodes the untouched row into a second object instance; it still equals the
		// baseline row by value, so nothing moved.
		$this->assertSame( 'unconfirmed', $result['status'] );
		$this->assert_raw_columns( 'post', $post_id, array( self::OBJECT_ROW ), 'end state: the object row must survive untouched.' );
	}

	public function test_s15d_group_member_object_baseline_under_veto_true_reports_unconfirmed(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'old' );
		update_post_meta( $post_id, 'aafm_g_two', (object) array( 'k' => 'old' ) );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_columns( 'post', $post_id, array( self::OBJECT_ROW ), 'precondition: aafm_g_two', 'aafm_g_two' );

		add_filter(
			'update_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return 'aafm_g_two' === $meta_key ? true : $check;
			},
			10,
			3
		);

		$result = aafm_meta_set_group(
			'post',
			$post_id,
			array(
				'aafm_g_one' => 'new',
				'aafm_g_two' => 'new',
			),
			'post'
		);

		remove_all_filters( 'update_post_metadata' );

		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( 'written', $result['keys']['aafm_g_one']['status'] );
		$this->assertSame( 'unconfirmed', $result['keys']['aafm_g_two']['status'] );
		$this->assert_raw_rows( 'post', $post_id, array( 'new' ), 'end state: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_columns( 'post', $post_id, array( self::OBJECT_ROW ), 'end state: aafm_g_two', 'aafm_g_two' );
	}

	public function test_s15e_a_sql_null_row_is_not_equal_to_a_clear_and_is_written(): void {
		global $wpdb;
		$post_id = $this->make_object( 'post' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- planting a raw SQL NULL row core cannot write.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => self::KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- an insert, not a query filter.
				'meta_value' => null, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- an insert, not a query filter.
			)
		);
		$this->assert_raw_columns( 'post', $post_id, array( null ), 'precondition: the meta_value column is SQL NULL.' );

		$result = aafm_meta_set( 'post', $post_id, self::KEY, '', 'post', false );

		$this->assertSame( 'written', $result['status'] );
		$this->assertArrayNotHasKey( 'modified_by_site', $result );
		$this->assert_raw_columns( 'post', $post_id, array( '' ), 'end state' );
	}

	public function test_s15f_veto_true_plus_a_read_filter_returning_an_unserializable_row_reports_modified_by_site(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, self::KEY, 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );

		$emissions = array();
		add_action(
			'aafm_write_completed',
			static function ( $result ) use ( &$emissions ) {
				$emissions[] = $result;
			}
		);
		$unserializable_row = static function ( $value, $object_id, $meta_key ) {
			if ( self::KEY === $meta_key ) {
				// serialize() refuses a SimpleXMLElement.
				return array( new \SimpleXMLElement( '<a/>' ) );
			}
			return $value;
		};
		add_filter( 'update_post_metadata', '__return_true' );
		add_filter( 'get_post_metadata', $unserializable_row, 10, 3 );

		$thrown = '';
		$result = array();
		try {
			$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );
		} catch ( \Throwable $e ) {
			$thrown = get_class( $e ) . ': ' . $e->getMessage();
		}

		remove_filter( 'update_post_metadata', '__return_true' );
		remove_filter( 'get_post_metadata', $unserializable_row, 10 );

		$this->assertSame( '', $thrown, 'a value serialize() refuses must equal nothing, never throw.' );
		$this->assertSame( 'written', $result['status'] );
		$this->assertTrue( $result['modified_by_site'] ?? false );
		$this->assertCount( 1, $emissions, 'the call must emit exactly once.' );
		$this->assertSame( 'written', $emissions[0]['status'] );
		$this->assertTrue( $emissions[0]['modified_by_site'] );
		$this->assertSame( 'old', $emissions[0]['previous'] );
		$this->assertSame(
			array(
				'exists' => true,
				'count'  => 1,
			),
			$emissions[0]['observed']
		);
		$this->assertArrayHasKey( 'value', $emissions[0] );
		$this->assertNull( $emissions[0]['value'] );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state' );
	}

	/**
	 * PHP turns a numeric-string array key into an int, so a group keyed '123' hands the helper an
	 * int key. It has to behave like any other key, not throw after the first member was written.
	 */
	public function test_group_numeric_string_key_as_the_second_member_returns_the_aggregate(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array(), 'precondition: 123', '123' );

		try {
			$result = aafm_meta_set_group(
				'post',
				$post_id,
				array(
					'aafm_g_one' => 'new',
					'123'        => 'new',
				),
				'post'
			);
		} catch ( \TypeError $e ) {
			$this->fail( 'a numeric-string key must not throw: ' . $e->getMessage() );
		}

		$this->assertSame( 'written', $result['status'] );
		$this->assertSame( 'written', $result['keys']['aafm_g_one']['status'] );
		$this->assertSame( 'written', $result['keys']['123']['status'] );
		$this->assert_raw_rows( 'post', $post_id, array( 'new' ), 'end state: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array( 'new' ), 'end state: 123', '123' );
	}

	public function test_group_numeric_string_key_as_the_second_member_on_a_failed_preflight_returns_the_aggregate(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'old' );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_g_one', 'aafm_g_one' );

		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		$thrown     = null;
		ob_start();
		try {
			$result = QueryFaultInjector::fail_query(
				$wpdb->postmeta,
				static function () use ( $post_id ) {
					return aafm_meta_set_group(
						'post',
						$post_id,
						array(
							'aafm_g_one' => 'new',
							'123'        => 'new',
						),
						'post'
					);
				}
			);
		} catch ( \TypeError $e ) {
			$thrown = $e;
		} finally {
			ob_end_clean();
			$wpdb->suppress_errors( $suppressed );
		}

		$this->assertNull( $thrown, 'a numeric-string key must not throw on a failed preflight.' );
		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertSame( 'read_failed', $result['status'] );
		$this->assertSame( array( 'status' => 'read_failed' ), $result['keys']['aafm_g_one'] );
		$this->assertSame( array( 'status' => 'read_failed' ), $result['keys']['123'] );
		$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state: aafm_g_one', 'aafm_g_one' );
		$this->assert_raw_rows( 'post', $post_id, array(), 'end state: 123', '123' );
	}

	public function test_group_numeric_string_key_named_as_an_array_member_takes_an_array_value(): void {
		$post_id = $this->make_object( 'post' );
		$this->assert_raw_rows( 'post', $post_id, array(), 'precondition: 123', '123' );

		try {
			$result = aafm_meta_set_group(
				'post',
				$post_id,
				array(
					'aafm_g_one' => 'new',
					'123'        => array( 'k' => 'b' ),
				),
				'post',
				array( '123' )
			);
		} catch ( \TypeError $e ) {
			$this->fail( 'a numeric-string key must not throw: ' . $e->getMessage() );
		}

		$this->assertIsArray( $result );
		$this->assertSame( 'written', $result['status'] );
		$this->assertSame( 'written', $result['keys']['123']['status'] );
		$this->assert_raw_rows( 'post', $post_id, array( array( 'k' => 'b' ) ), 'end state: 123', '123' );
	}

	/**
	 * The meta_key column compares case-insensitively on a stock install, so a row stored as `Foo`
	 * is the baseline of a request for `foo`. A group member has to see the same baseline the
	 * single-key writer sees for that key.
	 */
	public function test_group_member_case_variant_key_under_veto_false_matches_the_single_writer(): void {
		$post_id = $this->make_object( 'post' );
		update_post_meta( $post_id, 'aafm_g_one', 'old' );
		add_post_meta( $post_id, 'Foo', 'old' );
		$this->assert_raw_columns( 'post', $post_id, array( 'old' ), 'precondition: Foo', 'Foo' );

		$veto_calls = 0;
		add_filter(
			'update_post_metadata',
			static function ( $check, $object_id, $meta_key ) use ( &$veto_calls ) {
				if ( 'foo' !== $meta_key ) {
					return $check;
				}
				++$veto_calls;
				return false;
			},
			10,
			3
		);

		$single = aafm_meta_set( 'post', $post_id, 'foo', 'new', 'post' );
		$group  = aafm_meta_set_group(
			'post',
			$post_id,
			array(
				'aafm_g_one' => 'new',
				'foo'        => 'new',
			),
			'post'
		);

		remove_all_filters( 'update_post_metadata' );

		$expected = array(
			'status'       => 'refused',
			'acknowledged' => false,
			'previous'     => 'old',
		);
		$this->assertSame( $expected, $single );
		$this->assertSame( $expected, $group['keys']['foo'] );
		$this->assertSame( 'partial', $group['status'] );
		$this->assertSame( 2, $veto_calls );
		$this->assert_raw_columns( 'post', $post_id, array( 'old' ), 'end state: Foo', 'Foo' );
		$this->assert_raw_rows( 'post', $post_id, array( 'new' ), 'end state: aafm_g_one', 'aafm_g_one' );
	}

	/**
	 * The literal key set of every producer/status combination, the acknowledged value where one
	 * is shown, and the durable end state a direct query must see.
	 *
	 * @return iterable<string,array{0:string,1:string[],2:?bool,3:array}>
	 */
	public function data_s14_cases(): iterable {
		yield 'set written' => array( 'set_written', array( 'acknowledged', 'observed', 'previous', 'status', 'value' ), true, array( 'new' ) );
		yield 'set written, no baseline row' => array( 'set_written_no_baseline', array( 'acknowledged', 'observed', 'status', 'value' ), true, array( 'new' ) );
		yield 'set written, changed by the site' => array( 'set_written_modified_by_site', array( 'acknowledged', 'modified_by_site', 'observed', 'previous', 'status', 'value' ), true, array( 'new-2' ) );
		yield 'set unchanged' => array( 'set_unchanged', array( 'previous', 'status', 'value' ), null, array( 'old' ) );
		yield 'set refused' => array( 'set_refused', array( 'acknowledged', 'previous', 'status' ), false, array( 'old' ) );
		yield 'set refused, no baseline row' => array( 'set_refused_no_baseline', array( 'acknowledged', 'status' ), false, array() );
		yield 'set unconfirmed' => array( 'set_unconfirmed', array( 'acknowledged', 'observed', 'previous', 'status' ), true, array( 'old' ) );
		yield 'set read_failed' => array( 'set_read_failed', array( 'status' ), null, array( 'old' ) );
		yield 'delete deleted' => array( 'delete_deleted', array( 'acknowledged', 'observed', 'previous', 'status' ), true, array() );
		yield 'delete absent' => array( 'delete_absent', array( 'status' ), null, array() );
		yield 'delete refused by core' => array( 'delete_refused_veto_false', array( 'acknowledged', 'previous', 'status' ), false, array( 'old' ) );
		yield 'delete refused, row survived' => array( 'delete_refused_veto_true', array( 'acknowledged', 'observed', 'previous', 'status' ), true, array( 'old' ) );
		yield 'delete read_failed' => array( 'delete_read_failed', array( 'status' ), null, array( 'old' ) );
		yield 'group member written' => array( 'group_key2_written', array( 'acknowledged', 'observed', 'previous', 'status', 'value' ), true, array( 'new' ) );
		yield 'group member unchanged' => array( 'group_key2_unchanged', array( 'previous', 'status', 'value' ), null, array( 'old' ) );
		yield 'group member refused' => array( 'group_key2_refused', array( 'acknowledged', 'previous', 'status' ), false, array( 'old' ) );
		yield 'group member unconfirmed' => array( 'group_key2_unconfirmed', array( 'acknowledged', 'observed', 'previous', 'status' ), true, array( 'old' ) );
		yield 'group read_failed' => array( 'group_read_failed', array( 'keys', 'status' ), null, array() );
	}

	/**
	 * One key-presence case: the result's own key set, sorted, the acknowledged value where one is
	 * shown, and the durable end state by a direct query.
	 *
	 * @dataProvider data_s14_cases
	 * @param string    $scenario              Scenario name run_s14_scenario() understands.
	 * @param string[]  $expected_keys         The result's own key set, sorted.
	 * @param bool|null $expected_acknowledged The literal 'acknowledged' value, or null when absent.
	 * @param array     $expected_end_state    The affected key's durable rows after the call.
	 */
	public function test_s14_key_presence( string $scenario, array $expected_keys, ?bool $expected_acknowledged, array $expected_end_state ): void {
		list( $result, $post_id, $end_state_key ) = $this->run_s14_scenario( $scenario );

		if ( 'group_read_failed' === $scenario ) {
			$this->assertSame( $expected_keys, $this->sorted_keys( $result ) );
			foreach ( $result['keys'] as $entry ) {
				$this->assertSame( array( 'status' ), $this->sorted_keys( $entry ) );
			}
			$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state: aafm_s14_one', 'aafm_s14_one' );
			$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'end state: aafm_s14_two', 'aafm_s14_two' );
			return;
		}

		$this->assertSame( $expected_keys, $this->sorted_keys( $result ) );
		if ( null !== $expected_acknowledged ) {
			$this->assertSame( $expected_acknowledged, $result['acknowledged'] );
		}
		$this->assert_raw_rows( 'post', $post_id, $expected_end_state, 'end state', $end_state_key );
	}

	/**
	 * A result array's own keys, sorted, for a literal comparison.
	 *
	 * @param array $result The array to read keys from.
	 * @return string[]
	 */
	private function sorted_keys( array $result ): array {
		$keys = array_keys( $result );
		sort( $keys );
		return $keys;
	}

	/**
	 * Run one named key-presence scenario and return the result whose key set the test asserts,
	 * the post id, and the meta key a direct-query end-state check must read.
	 *
	 * @param string $scenario Scenario name.
	 * @return array{0:array,1:int,2:string}
	 */
	private function run_s14_scenario( string $scenario ): array {
		global $wpdb;
		$post_id = $this->make_object( 'post' );

		switch ( $scenario ) {
			case 'set_written':
				update_post_meta( $post_id, self::KEY, 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );
				return array( aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false ), $post_id, self::KEY );

			case 'set_written_no_baseline':
				$this->assert_raw_rows( 'post', $post_id, array(), 'precondition' );
				return array( aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false ), $post_id, self::KEY );

			case 'set_written_modified_by_site':
				// The same appending, stateful sanitize callback the stateful-sanitizer test above
				// uses: it drifts the read-back away from the canonical value core acknowledged writing.
				update_post_meta( $post_id, self::KEY, 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );
				add_filter(
					'sanitize_post_meta_' . self::KEY,
					static function ( $value ) {
						static $calls = 0;
						++$calls;
						return $value . '-' . $calls;
					},
					10,
					1
				);
				$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );
				remove_all_filters( 'sanitize_post_meta_' . self::KEY );
				return array( $result, $post_id, self::KEY );

			case 'set_unchanged':
				update_post_meta( $post_id, self::KEY, 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );
				return array( aafm_meta_set( 'post', $post_id, self::KEY, 'old', 'post', false ), $post_id, self::KEY );

			case 'set_refused':
				update_post_meta( $post_id, self::KEY, 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );
				add_filter( 'update_post_metadata', '__return_false' );
				$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );
				remove_filter( 'update_post_metadata', '__return_false' );
				return array( $result, $post_id, self::KEY );

			case 'set_refused_no_baseline':
				$this->assert_raw_rows( 'post', $post_id, array(), 'precondition' );
				add_filter( 'update_post_metadata', '__return_false' );
				$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );
				remove_filter( 'update_post_metadata', '__return_false' );
				return array( $result, $post_id, self::KEY );

			case 'set_unconfirmed':
				update_post_meta( $post_id, self::KEY, 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );
				add_filter( 'update_post_metadata', '__return_true' );
				$result = aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );
				remove_filter( 'update_post_metadata', '__return_true' );
				return array( $result, $post_id, self::KEY );

			case 'set_read_failed':
				update_post_meta( $post_id, self::KEY, 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );
				$suppressed = $wpdb->suppress_errors( true );
				ob_start();
				$result = QueryFaultInjector::fail_query(
					$wpdb->postmeta,
					static function () use ( $post_id ) {
						return aafm_meta_set( 'post', $post_id, self::KEY, 'new', 'post', false );
					}
				);
				ob_end_clean();
				$wpdb->suppress_errors( $suppressed );
				return array( $result, $post_id, self::KEY );

			case 'delete_deleted':
				update_post_meta( $post_id, self::KEY, 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );
				return array( aafm_meta_delete( 'post', $post_id, self::KEY ), $post_id, self::KEY );

			case 'delete_absent':
				$this->assert_raw_rows( 'post', $post_id, array(), 'precondition' );
				return array( aafm_meta_delete( 'post', $post_id, self::KEY ), $post_id, self::KEY );

			case 'delete_refused_veto_false':
				update_post_meta( $post_id, self::KEY, 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );
				add_filter( 'delete_post_metadata', '__return_false' );
				$result = aafm_meta_delete( 'post', $post_id, self::KEY );
				remove_filter( 'delete_post_metadata', '__return_false' );
				return array( $result, $post_id, self::KEY );

			case 'delete_refused_veto_true':
				update_post_meta( $post_id, self::KEY, 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );
				add_filter( 'delete_post_metadata', '__return_true' );
				$result = aafm_meta_delete( 'post', $post_id, self::KEY );
				remove_filter( 'delete_post_metadata', '__return_true' );
				return array( $result, $post_id, self::KEY );

			case 'delete_read_failed':
				update_post_meta( $post_id, self::KEY, 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition' );
				$suppressed = $wpdb->suppress_errors( true );
				ob_start();
				$result = QueryFaultInjector::fail_query(
					$wpdb->postmeta,
					static function () use ( $post_id ) {
						return aafm_meta_delete( 'post', $post_id, self::KEY );
					}
				);
				ob_end_clean();
				$wpdb->suppress_errors( $suppressed );
				return array( $result, $post_id, self::KEY );

			case 'group_key2_written':
				update_post_meta( $post_id, 'aafm_s14_one', 'old' );
				update_post_meta( $post_id, 'aafm_s14_two', 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_s14_one', 'aafm_s14_one' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_s14_two', 'aafm_s14_two' );
				$result = aafm_meta_set_group(
					'post',
					$post_id,
					array(
						'aafm_s14_one' => 'new',
						'aafm_s14_two' => 'new',
					),
					'post'
				);
				return array( $result['keys']['aafm_s14_two'], $post_id, 'aafm_s14_two' );

			case 'group_key2_unchanged':
				update_post_meta( $post_id, 'aafm_s14_one', 'old' );
				update_post_meta( $post_id, 'aafm_s14_two', 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_s14_one', 'aafm_s14_one' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_s14_two', 'aafm_s14_two' );
				$result = aafm_meta_set_group(
					'post',
					$post_id,
					array(
						'aafm_s14_one' => 'new',
						'aafm_s14_two' => 'old',
					),
					'post'
				);
				return array( $result['keys']['aafm_s14_two'], $post_id, 'aafm_s14_two' );

			case 'group_key2_refused':
				update_post_meta( $post_id, 'aafm_s14_one', 'old' );
				update_post_meta( $post_id, 'aafm_s14_two', 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_s14_one', 'aafm_s14_one' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_s14_two', 'aafm_s14_two' );
				add_filter(
					'update_post_metadata',
					static function ( $check, $object_id, $meta_key ) {
						return 'aafm_s14_two' === $meta_key ? false : $check;
					},
					10,
					3
				);
				$result = aafm_meta_set_group(
					'post',
					$post_id,
					array(
						'aafm_s14_one' => 'new',
						'aafm_s14_two' => 'new',
					),
					'post'
				);
				remove_all_filters( 'update_post_metadata' );
				return array( $result['keys']['aafm_s14_two'], $post_id, 'aafm_s14_two' );

			case 'group_key2_unconfirmed':
				update_post_meta( $post_id, 'aafm_s14_one', 'old' );
				update_post_meta( $post_id, 'aafm_s14_two', 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_s14_one', 'aafm_s14_one' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_s14_two', 'aafm_s14_two' );
				add_filter(
					'update_post_metadata',
					static function ( $check, $object_id, $meta_key ) {
						return 'aafm_s14_two' === $meta_key ? true : $check;
					},
					10,
					3
				);
				$result = aafm_meta_set_group(
					'post',
					$post_id,
					array(
						'aafm_s14_one' => 'new',
						'aafm_s14_two' => 'new',
					),
					'post'
				);
				remove_all_filters( 'update_post_metadata' );
				return array( $result['keys']['aafm_s14_two'], $post_id, 'aafm_s14_two' );

			case 'group_read_failed':
				update_post_meta( $post_id, 'aafm_s14_one', 'old' );
				update_post_meta( $post_id, 'aafm_s14_two', 'old' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_s14_one', 'aafm_s14_one' );
				$this->assert_raw_rows( 'post', $post_id, array( 'old' ), 'precondition: aafm_s14_two', 'aafm_s14_two' );
				$suppressed = $wpdb->suppress_errors( true );
				ob_start();
				$result = QueryFaultInjector::fail_query(
					$wpdb->postmeta,
					static function () use ( $post_id ) {
						return aafm_meta_set_group(
							'post',
							$post_id,
							array(
								'aafm_s14_one' => 'new',
								'aafm_s14_two' => 'new',
							),
							'post'
						);
					}
				);
				ob_end_clean();
				$wpdb->suppress_errors( $suppressed );
				return array( $result, $post_id, '' );

			default:
				$this->fail( 'unknown key-presence scenario: ' . $scenario );
		}
	}

	public function test_write_writers_map_matches_the_literal_map_in_order(): void {
		$writers = aafm_write_writers();

		$this->assertSame(
			array( 'post_meta', 'term_meta', 'user_meta', 'option', 'post_field', 'acf', 'aioseo', 'geodirectory', 'tec', 'woocommerce' ),
			array_keys( $writers )
		);

		$meta_writers = array( 'aafm_meta_set', 'aafm_meta_delete', 'aafm_meta_set_group' );
		$this->assertSame( $meta_writers, $writers['post_meta'] );
		$this->assertSame( $meta_writers, $writers['term_meta'] );
		$this->assertSame( $meta_writers, $writers['user_meta'] );
		$this->assertSame( array( 'aafm_option_write', 'aafm_update_option_verified', 'aafm_persist_operator_switch', 'aafm_delete_option_cache_safe' ), $writers['option'] );
		$this->assertSame( array( 'aafm_post_field_confirm_logged' ), $writers['post_field'] );
		$this->assertSame( array( 'aafm_acf_write_field' ), $writers['acf'] );
		$this->assertSame( array( 'aafm_aioseo_write' ), $writers['aioseo'] );
		$this->assertSame( array( 'aafm_geodir_write' ), $writers['geodirectory'] );
		$this->assertSame( array( 'aafm_tec_write' ), $writers['tec'] );
		$this->assertSame( array( 'aafm_wc_write' ), $writers['woocommerce'] );

		foreach ( array( 'post_meta', 'term_meta', 'user_meta', 'option', 'post_field' ) as $kind ) {
			foreach ( $writers[ $kind ] as $function_name ) {
				$this->assertTrue( function_exists( $function_name ), "$function_name must exist for the $kind writer kind." );
			}
		}
	}

	/**
	 * Create a fresh object of the given type (fixtures below this point).
	 *
	 * @param string $type 'post', 'term' or 'user'.
	 * @return int
	 */
	private function make_object( string $type ): int {
		if ( 'post' === $type ) {
			return self::factory()->post->create();
		}
		if ( 'term' === $type ) {
			return (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		}
		return self::factory()->user->create();
	}

	/**
	 * Write the rows a baseline shape needs, and remember it for apply_intent()'s own use.
	 *
	 * @param string $type     Object type.
	 * @param int    $id       Object id.
	 * @param string $baseline Baseline shape.
	 */
	private function seed_baseline( string $type, int $id, string $baseline ): void {
		$this->last_baseline = $baseline;
		switch ( $baseline ) {
			case 'absent':
				return;
			case 'present-empty':
				$this->write_raw( $type, $id, '' );
				return;
			case 'scalar':
				$this->write_raw( $type, $id, 'old' );
				return;
			case 'array':
				$this->write_raw( $type, $id, array( 'k' => 'a' ) );
				return;
			case 'serialized-empty':
				$this->write_raw( $type, $id, array() );
				return;
			case 'duplicate':
				$this->write_raw( $type, $id, 'a' );
				$this->write_raw_duplicate( $type, $id, 'b' );
				return;
		}
	}

	private function write_raw( string $type, int $id, $value ): void {
		if ( 'post' === $type ) {
			update_post_meta( $id, self::KEY, $value );
		} elseif ( 'term' === $type ) {
			update_term_meta( $id, self::KEY, $value );
		} else {
			update_user_meta( $id, self::KEY, $value );
		}
	}

	private function write_raw_duplicate( string $type, int $id, $value ): void {
		if ( 'post' === $type ) {
			add_post_meta( $id, self::KEY, $value, false );
		} elseif ( 'term' === $type ) {
			add_term_meta( $id, self::KEY, $value, false );
		} else {
			add_user_meta( $id, self::KEY, $value, false );
		}
	}

	private function intended_value( string $baseline, string $intent ) {
		if ( 'clear' === $intent ) {
			return '';
		}
		if ( 'no-op' === $intent ) {
			switch ( $baseline ) {
				case 'present-empty':
					return '';
				case 'scalar':
					return 'old';
				case 'array':
					return array( 'k' => 'a' );
				case 'serialized-empty':
					return array();
				case 'duplicate':
					return 'a';
				default:
					return null; // absent: handled as a delete call by the caller.
			}
		}
		// change.
		if ( 'array' === $baseline ) {
			return array( 'k' => 'b' );
		}
		if ( 'duplicate' === $baseline ) {
			return 'c';
		}
		return 'new';
	}

	private function register_transform( string $type ): void {
		$uppercase = static function ( $value ) {
			if ( is_string( $value ) ) {
				return strtoupper( $value );
			}
			if ( is_array( $value ) ) {
				return array_map(
					static function ( $member ) {
						return is_string( $member ) ? strtoupper( $member ) : $member;
					},
					$value
				);
			}
			return $value;
		};
		add_filter( 'sanitize_' . $type . '_meta_' . self::KEY, $uppercase );
	}

	/**
	 * Run one grid cell's operation, arming veto and query faults as the cell requires.
	 *
	 * @param string      $type       Object type.
	 * @param int         $object_id  Object id.
	 * @param string      $intent     Requested operation.
	 * @param bool|null   $veto       True/false to arm a veto filter, null for no veto.
	 * @param string      $fault      Fault column.
	 * @param string|null $shape      Query-fault shape, or null.
	 * @param int         $veto_calls By reference: incremented once per veto filter invocation.
	 * @return array
	 */
	private function run_case( string $type, int $object_id, string $intent, ?bool $veto, string $fault, ?string $shape, int &$veto_calls = 0 ) {
		$update_hook = 'update_' . $type . '_metadata';
		$delete_hook = 'delete_' . $type . '_metadata';

		if ( null !== $veto ) {
			$veto_value = $veto;
			$counted    = static function () use ( $veto_value, &$veto_calls ) {
				++$veto_calls;
				return $veto_value;
			};
			add_filter( $update_hook, $counted );
			add_filter( $delete_hook, $counted );
		}

		$do = function () use ( $type, $object_id, $intent ) {
			return $this->apply_intent( $type, $object_id, $intent );
		};

		if ( 'baseline-read-fault' === $fault ) {
			$needle = $this->meta_table( $type );
			$result = 'no-flush' === $shape
				? QueryFaultInjector::fail_nth_query( $needle, 1, $do )
				: QueryFaultInjector::break_query_with_real_error( $needle, $do, 1 );
		} elseif ( 'write-fault' === $fault ) {
			$needle     = $this->meta_table( $type );
			$occurrence = 'delete' === $intent ? 3 : 4;
			$result     = 'no-flush' === $shape
				? QueryFaultInjector::fail_nth_query( $needle, $occurrence, $do )
				: QueryFaultInjector::break_query_with_real_error( $needle, $do, $occurrence );
		} elseif ( 'confirm-read-fault' === $fault ) {
			$result = $this->run_with_confirm_read_fault( $type, $do, $shape, null !== $veto );
		} else {
			global $wpdb;
			$suppressed = $wpdb->suppress_errors( true );
			ob_start();
			$result = $do();
			ob_end_clean();
			$wpdb->suppress_errors( $suppressed );
		}

		return is_array( $result ) ? $result : array( 'status' => null );
	}

	/**
	 * Arm the confirm-read fault only after core's own write call has finished: from
	 * the added_/updated_/deleted_{type}_meta action for a real write, or (when $after_veto) it must
	 * already be armed before $run runs because a veto-true write never fires those actions.
	 *
	 * @param string      $type      Object type.
	 * @param callable    $run       The operation to run with the fault armed.
	 * @param string|null $shape     Query-fault shape.
	 * @param bool        $after_veto Whether a veto-true pairing is in play.
	 * @return mixed
	 */
	private function run_with_confirm_read_fault( string $type, callable $run, ?string $shape, bool $after_veto ) {
		$table = $this->meta_table( $type );
		$armed = false;
		$arm   = function () use ( &$armed, $table, $shape ) {
			if ( $armed ) {
				return;
			}
			$armed = true;
			if ( 'no-flush' === $shape ) {
				add_filter( 'query', QueryFaultInjector::no_flush_filter( $table, 1 ) );
			} else {
				add_filter( 'query', QueryFaultInjector::real_error_filter( $table, 1 ) );
			}
		};

		$actions = array( "added_{$type}_meta", "updated_{$type}_meta", "deleted_{$type}_meta" );
		foreach ( $actions as $action ) {
			add_action( $action, $arm, 10, 0 );
		}
		if ( $after_veto ) {
			// A veto short-circuit never fires those actions; arm from inside the veto filter itself.
			$update_hook = 'update_' . $type . '_metadata';
			$delete_hook = 'delete_' . $type . '_metadata';
			add_filter(
				$update_hook,
				function ( $check ) use ( $arm ) {
					$arm();
					return $check;
				},
				20
			);
			add_filter(
				$delete_hook,
				function ( $check ) use ( $arm ) {
					$arm();
					return $check;
				},
				20
			);
		}

		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = $run();
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		foreach ( $actions as $action ) {
			remove_action( $action, $arm, 10 );
		}

		return $result;
	}

	private function apply_intent( string $type, int $object_id, string $intent ) {
		if ( 'delete' === $intent || ( 'no-op' === $intent && 'absent' === $this->last_baseline ) ) {
			return aafm_meta_delete( $type, $object_id, self::KEY );
		}
		$intended = $this->intended_value( $this->last_baseline, $intent );
		$subtype  = 'user' === $type ? '' : ( 'post' === $type ? 'post' : 'category' );
		return aafm_meta_set( $type, $object_id, self::KEY, $intended, $subtype, false );
	}

	/**
	 * The baseline shape seed_baseline() last set up, so apply_intent() knows what a no-op means.
	 *
	 * @var string
	 */
	private $last_baseline = 'absent';

	private function meta_table( string $type ): string {
		global $wpdb;
		if ( 'post' === $type ) {
			return $wpdb->postmeta;
		}
		if ( 'term' === $type ) {
			return $wpdb->termmeta;
		}
		return $wpdb->usermeta;
	}

	private function read_raw_rows( string $type, int $id, string $key = self::KEY ): array {
		global $wpdb;
		$table     = $this->meta_table( $type );
		$column    = $type . '_id';
		$id_column = 'user' === $type ? 'umeta_id' : 'meta_id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE {$column} = %d AND meta_key = %s ORDER BY {$id_column}", $id, $key ), ARRAY_A );
	}

	/**
	 * Assert the raw meta_value column of every row of one key, undecoded, read by a direct
	 * uncached query, against a literal list. Used where a decoded comparison cannot hold: an object
	 * row, which decodes to a new instance on every read, a SQL NULL, and a serialized-looking string.
	 *
	 * @param string $type     Object type.
	 * @param int    $id       Object id.
	 * @param array  $expected Expected raw column values, in meta-id order.
	 * @param string $message  Failure message.
	 * @param string $key      Meta key.
	 */
	private function assert_raw_columns( string $type, int $id, array $expected, string $message, string $key = self::KEY ): void {
		$this->assertSame( $expected, array_column( $this->read_raw_rows( $type, $id, $key ), 'meta_value' ), $message );
	}

	/**
	 * Assert the durable, decoded state of every row of one key, read by a direct uncached
	 * query, against a literal list of values.
	 *
	 * @param string $type     Object type.
	 * @param int    $id       Object id.
	 * @param array  $expected Expected decoded values, in meta-id order.
	 * @param string $message  Failure message.
	 * @param string $key      Meta key.
	 */
	private function assert_raw_rows( string $type, int $id, array $expected, string $message, string $key = self::KEY ): void {
		$actual = array_map(
			static function ( array $row ) {
				return maybe_unserialize( $row['meta_value'] );
			},
			$this->read_raw_rows( $type, $id, $key )
		);
		$this->assertSame( $expected, $actual, $message );
	}

	/**
	 * The literal decoded rows a baseline shape writes, in meta-id order.
	 *
	 * @param string $baseline Baseline shape.
	 * @return array
	 */
	private function baseline_values( string $baseline ): array {
		switch ( $baseline ) {
			case 'present-empty':
				return array( '' );
			case 'scalar':
				return array( 'old' );
			case 'array':
				return array( array( 'k' => 'a' ) );
			case 'serialized-empty':
				return array( array() );
			case 'duplicate':
				return array( 'a', 'b' );
			default: // absent.
				return array();
		}
	}

	/**
	 * The state matrix's own literal expectations, keyed by baseline, intent and fault: [status,
	 * rows-or-null, end-state]. Every value is a string, int or array literal (never a production
	 * status constant, never computed from the baseline/intent/fault by a helper function), so a
	 * production or comparator regression cannot silently follow the expected side.
	 */
	private const GRID = array(
		'absent'           => array(
			'no-op'  => array(
				'clean'               => array( 'absent', null, array() ),
				'veto-false'          => array( 'absent', null, array() ),
				'veto-true'           => array( 'absent', null, array() ),
				'transform'           => array( 'absent', null, array() ),
				'baseline-read-fault' => array( 'read_failed', null, array() ),
				'write-fault'         => array( 'absent', null, array() ),
				'confirm-read-fault'  => array( 'absent', null, array() ),
			),
			'change' => array(
				'clean'               => array( 'written', null, array( 'new' ) ),
				'veto-false'          => array( 'refused', null, array() ),
				'veto-true'           => array( 'unconfirmed', null, array() ),
				'transform'           => array( 'written', null, array( 'NEW' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array() ),
				'write-fault'         => array( 'refused', null, array() ),
				'confirm-read-fault'  => array( 'unconfirmed', null, array( 'new' ) ),
			),
			'clear'  => array(
				'clean'               => array( 'written', null, array( '' ) ),
				'veto-false'          => array( 'refused', null, array() ),
				'veto-true'           => array( 'unconfirmed', null, array() ),
				'transform'           => array( 'written', null, array( '' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array() ),
				'write-fault'         => array( 'refused', null, array() ),
				'confirm-read-fault'  => array( 'unconfirmed', null, array( '' ) ),
			),
			'delete' => array(
				'clean'               => array( 'absent', null, array() ),
				'veto-false'          => array( 'absent', null, array() ),
				'veto-true'           => array( 'absent', null, array() ),
				'transform'           => array( 'absent', null, array() ),
				'baseline-read-fault' => array( 'read_failed', null, array() ),
				'write-fault'         => array( 'absent', null, array() ),
				'confirm-read-fault'  => array( 'absent', null, array() ),
			),
		),
		'present-empty'    => array(
			'no-op'  => array(
				'clean'               => array( 'unchanged', null, array( '' ) ),
				'veto-false'          => array( 'unchanged', null, array( '' ) ),
				'veto-true'           => array( 'unchanged', null, array( '' ) ),
				'transform'           => array( 'unchanged', null, array( '' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( '' ) ),
				'write-fault'         => array( 'unchanged', null, array( '' ) ),
				'confirm-read-fault'  => array( 'unchanged', null, array( '' ) ),
			),
			'change' => array(
				'clean'               => array( 'written', null, array( 'new' ) ),
				'veto-false'          => array( 'refused', null, array( '' ) ),
				'veto-true'           => array( 'unconfirmed', null, array( '' ) ),
				'transform'           => array( 'written', null, array( 'NEW' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( '' ) ),
				'write-fault'         => array( 'refused', null, array( '' ) ),
				'confirm-read-fault'  => array( 'unconfirmed', null, array( 'new' ) ),
			),
			'clear'  => array(
				'clean'               => array( 'unchanged', null, array( '' ) ),
				'veto-false'          => array( 'unchanged', null, array( '' ) ),
				'veto-true'           => array( 'unchanged', null, array( '' ) ),
				'transform'           => array( 'unchanged', null, array( '' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( '' ) ),
				'write-fault'         => array( 'unchanged', null, array( '' ) ),
				'confirm-read-fault'  => array( 'unchanged', null, array( '' ) ),
			),
			'delete' => array(
				'clean'               => array( 'deleted', null, array() ),
				'veto-false'          => array( 'refused', null, array( '' ) ),
				'veto-true'           => array( 'refused', null, array( '' ) ),
				'transform'           => array( 'deleted', null, array() ),
				'baseline-read-fault' => array( 'read_failed', null, array( '' ) ),
				'write-fault'         => array( 'refused', null, array( '' ) ),
				'confirm-read-fault'  => array( 'deleted', null, array() ),
			),
		),
		'scalar'           => array(
			'no-op'  => array(
				'clean'               => array( 'unchanged', null, array( 'old' ) ),
				'veto-false'          => array( 'unchanged', null, array( 'old' ) ),
				'veto-true'           => array( 'unchanged', null, array( 'old' ) ),
				'transform'           => array( 'written', null, array( 'OLD' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( 'old' ) ),
				'write-fault'         => array( 'unchanged', null, array( 'old' ) ),
				'confirm-read-fault'  => array( 'unchanged', null, array( 'old' ) ),
			),
			'change' => array(
				'clean'               => array( 'written', null, array( 'new' ) ),
				'veto-false'          => array( 'refused', null, array( 'old' ) ),
				'veto-true'           => array( 'unconfirmed', null, array( 'old' ) ),
				'transform'           => array( 'written', null, array( 'NEW' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( 'old' ) ),
				'write-fault'         => array( 'refused', null, array( 'old' ) ),
				'confirm-read-fault'  => array( 'unconfirmed', null, array( 'new' ) ),
			),
			'clear'  => array(
				'clean'               => array( 'written', null, array( '' ) ),
				'veto-false'          => array( 'refused', null, array( 'old' ) ),
				'veto-true'           => array( 'unconfirmed', null, array( 'old' ) ),
				'transform'           => array( 'written', null, array( '' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( 'old' ) ),
				'write-fault'         => array( 'refused', null, array( 'old' ) ),
				'confirm-read-fault'  => array( 'unconfirmed', null, array( '' ) ),
			),
			'delete' => array(
				'clean'               => array( 'deleted', null, array() ),
				'veto-false'          => array( 'refused', null, array( 'old' ) ),
				'veto-true'           => array( 'refused', null, array( 'old' ) ),
				'transform'           => array( 'deleted', null, array() ),
				'baseline-read-fault' => array( 'read_failed', null, array( 'old' ) ),
				'write-fault'         => array( 'refused', null, array( 'old' ) ),
				'confirm-read-fault'  => array( 'deleted', null, array() ),
			),
		),
		'array'            => array(
			'no-op'  => array(
				'clean'               => array( 'unchanged', null, array( array( 'k' => 'a' ) ) ),
				'veto-false'          => array( 'unchanged', null, array( array( 'k' => 'a' ) ) ),
				'veto-true'           => array( 'unchanged', null, array( array( 'k' => 'a' ) ) ),
				'transform'           => array( 'written', null, array( array( 'k' => 'A' ) ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( array( 'k' => 'a' ) ) ),
				'write-fault'         => array( 'unchanged', null, array( array( 'k' => 'a' ) ) ),
				'confirm-read-fault'  => array( 'unchanged', null, array( array( 'k' => 'a' ) ) ),
			),
			'change' => array(
				'clean'               => array( 'written', null, array( array( 'k' => 'b' ) ) ),
				'veto-false'          => array( 'refused', null, array( array( 'k' => 'a' ) ) ),
				'veto-true'           => array( 'unconfirmed', null, array( array( 'k' => 'a' ) ) ),
				'transform'           => array( 'written', null, array( array( 'k' => 'B' ) ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( array( 'k' => 'a' ) ) ),
				'write-fault'         => array( 'refused', null, array( array( 'k' => 'a' ) ) ),
				'confirm-read-fault'  => array( 'unconfirmed', null, array( array( 'k' => 'b' ) ) ),
			),
			'clear'  => array(
				'clean'               => array( 'written', null, array( '' ) ),
				'veto-false'          => array( 'refused', null, array( array( 'k' => 'a' ) ) ),
				'veto-true'           => array( 'unconfirmed', null, array( array( 'k' => 'a' ) ) ),
				'transform'           => array( 'written', null, array( '' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( array( 'k' => 'a' ) ) ),
				'write-fault'         => array( 'refused', null, array( array( 'k' => 'a' ) ) ),
				'confirm-read-fault'  => array( 'unconfirmed', null, array( '' ) ),
			),
			'delete' => array(
				'clean'               => array( 'deleted', null, array() ),
				'veto-false'          => array( 'refused', null, array( array( 'k' => 'a' ) ) ),
				'veto-true'           => array( 'refused', null, array( array( 'k' => 'a' ) ) ),
				'transform'           => array( 'deleted', null, array() ),
				'baseline-read-fault' => array( 'read_failed', null, array( array( 'k' => 'a' ) ) ),
				'write-fault'         => array( 'refused', null, array( array( 'k' => 'a' ) ) ),
				'confirm-read-fault'  => array( 'deleted', null, array() ),
			),
		),
		'serialized-empty' => array(
			'no-op'  => array(
				'clean'               => array( 'unchanged', null, array( array() ) ),
				'veto-false'          => array( 'unchanged', null, array( array() ) ),
				'veto-true'           => array( 'unchanged', null, array( array() ) ),
				'transform'           => array( 'unchanged', null, array( array() ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( array() ) ),
				'write-fault'         => array( 'unchanged', null, array( array() ) ),
				'confirm-read-fault'  => array( 'unchanged', null, array( array() ) ),
			),
			'change' => array(
				'clean'               => array( 'written', null, array( 'new' ) ),
				'veto-false'          => array( 'refused', null, array( array() ) ),
				'veto-true'           => array( 'unconfirmed', null, array( array() ) ),
				'transform'           => array( 'written', null, array( 'NEW' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( array() ) ),
				'write-fault'         => array( 'refused', null, array( array() ) ),
				'confirm-read-fault'  => array( 'unconfirmed', null, array( 'new' ) ),
			),
			'clear'  => array(
				'clean'               => array( 'written', null, array( '' ) ),
				'veto-false'          => array( 'refused', null, array( array() ) ),
				'veto-true'           => array( 'unconfirmed', null, array( array() ) ),
				'transform'           => array( 'written', null, array( '' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( array() ) ),
				'write-fault'         => array( 'refused', null, array( array() ) ),
				'confirm-read-fault'  => array( 'unconfirmed', null, array( '' ) ),
			),
			'delete' => array(
				'clean'               => array( 'deleted', null, array() ),
				'veto-false'          => array( 'refused', null, array( array() ) ),
				'veto-true'           => array( 'refused', null, array( array() ) ),
				'transform'           => array( 'deleted', null, array() ),
				'baseline-read-fault' => array( 'read_failed', null, array( array() ) ),
				'write-fault'         => array( 'refused', null, array( array() ) ),
				'confirm-read-fault'  => array( 'deleted', null, array() ),
			),
		),
		'duplicate'        => array(
			'no-op'  => array(
				'clean'               => array( 'written', 2, array( 'a', 'a' ) ),
				'veto-false'          => array( 'refused', 2, array( 'a', 'b' ) ),
				'veto-true'           => array( 'unconfirmed', 2, array( 'a', 'b' ) ),
				'transform'           => array( 'written', 2, array( 'A', 'A' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( 'a', 'b' ) ),
				'write-fault'         => array( 'refused', 2, array( 'a', 'b' ) ),
				'confirm-read-fault'  => array( 'unconfirmed', 2, array( 'a', 'a' ) ),
			),
			'change' => array(
				'clean'               => array( 'written', 2, array( 'c', 'c' ) ),
				'veto-false'          => array( 'refused', 2, array( 'a', 'b' ) ),
				'veto-true'           => array( 'unconfirmed', 2, array( 'a', 'b' ) ),
				'transform'           => array( 'written', 2, array( 'C', 'C' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( 'a', 'b' ) ),
				'write-fault'         => array( 'refused', 2, array( 'a', 'b' ) ),
				'confirm-read-fault'  => array( 'unconfirmed', 2, array( 'c', 'c' ) ),
			),
			'clear'  => array(
				'clean'               => array( 'written', 2, array( '', '' ) ),
				'veto-false'          => array( 'refused', 2, array( 'a', 'b' ) ),
				'veto-true'           => array( 'unconfirmed', 2, array( 'a', 'b' ) ),
				'transform'           => array( 'written', 2, array( '', '' ) ),
				'baseline-read-fault' => array( 'read_failed', null, array( 'a', 'b' ) ),
				'write-fault'         => array( 'refused', 2, array( 'a', 'b' ) ),
				'confirm-read-fault'  => array( 'unconfirmed', 2, array( '', '' ) ),
			),
			'delete' => array(
				'clean'               => array( 'deleted', 2, array() ),
				'veto-false'          => array( 'refused', 2, array( 'a', 'b' ) ),
				'veto-true'           => array( 'refused', 2, array( 'a', 'b' ) ),
				'transform'           => array( 'deleted', 2, array() ),
				'baseline-read-fault' => array( 'read_failed', null, array( 'a', 'b' ) ),
				'write-fault'         => array( 'refused', 2, array( 'a', 'b' ) ),
				'confirm-read-fault'  => array( 'deleted', 2, array() ),
			),
		),
	);
}
