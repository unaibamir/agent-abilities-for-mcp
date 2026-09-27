<?php
/**
 * The checked-read scope (aafm_with_checked_reads()): makes core's own metadata load
 * failure-aware for the life of a response builder, without changing a healthy read.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

final class CheckedReadScopeTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
	}

	public function test_a_healthy_build_returns_the_same_array_as_running_outside_the_scope(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		wp_cache_delete( $post_id, 'post_meta' );

		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$outside = $build();

		wp_cache_delete( $post_id, 'post_meta' );
		$inside = aafm_with_checked_reads( $build, new WP_Error( 'aafm_error', 'unused' ) );

		$this->assertSame( $outside, $inside );
	}

	public function test_a_faulted_load_no_flush_returns_the_passed_error_and_caches_nothing(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		wp_cache_delete( $post_id, 'post_meta' );

		$error = new WP_Error( 'aafm_error', 'scope failed' );
		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = QueryFaultInjector::fail_query(
			$wpdb->postmeta,
			static function () use ( $build, $error ) {
				return aafm_with_checked_reads( $build, $error );
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( $error, $result );
		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$found = null;
		wp_cache_get( $post_id, 'post_meta', false, $found );
		$this->assertFalse( (bool) $found, 'a failed load must leave no cache entry for the object' );
	}

	public function test_a_faulted_load_real_error_returns_the_passed_error_and_caches_nothing(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		wp_cache_delete( $post_id, 'post_meta' );

		$error = new WP_Error( 'aafm_error', 'scope failed' );
		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$result = QueryFaultInjector::break_query_with_real_error(
			$wpdb->postmeta,
			static function () use ( $build, $error ) {
				return aafm_with_checked_reads( $build, $error );
			}
		);

		$this->assertSame( $error, $result );
		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$found = null;
		wp_cache_get( $post_id, 'post_meta', false, $found );
		$this->assertFalse( (bool) $found, 'a failed load must leave no cache entry for the object' );
	}

	public function test_a_faulted_load_under_suspended_cache_addition_still_fails_the_call(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		wp_cache_delete( $post_id, 'post_meta' );

		$error = new WP_Error( 'aafm_error', 'scope failed' );
		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$was_suspended = wp_suspend_cache_addition();
		wp_suspend_cache_addition( true );

		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = QueryFaultInjector::fail_query(
			$wpdb->postmeta,
			static function () use ( $build, $error ) {
				return aafm_with_checked_reads( $build, $error );
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		wp_suspend_cache_addition( $was_suspended );

		$this->assertSame( $error, $result );
	}

	public function test_a_healthy_load_under_suspended_cache_addition_still_populates_the_cache(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		wp_cache_delete( $post_id, 'post_meta' );

		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$was_suspended = wp_suspend_cache_addition();
		wp_suspend_cache_addition( true );

		$result = aafm_with_checked_reads( $build, new WP_Error( 'aafm_error', 'unused' ) );

		wp_suspend_cache_addition( $was_suspended );

		$this->assertSame( array( 'value' => 'value' ), $result );
		$found = null;
		wp_cache_get( $post_id, 'post_meta', false, $found );
		$this->assertNotFalse( $found, 'the scope must use wp_cache_set(), which installs even while additions are suspended' );
	}

	public function test_an_already_cached_object_runs_no_load_query(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		// Warm the cache with an ordinary read first, so the scope's own handler sees a hit.
		get_post_meta( $post_id, 'aafm_scope_key', true );

		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$queries = array();
		$track   = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $track );
		$result = aafm_with_checked_reads( $build, new WP_Error( 'aafm_error', 'unused' ) );
		remove_filter( 'query', $track );

		$this->assertSame( array( 'value' => 'value' ), $result );
		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( (string) $wpdb->postmeta, $query, 'a cached object must not trigger a load query' );
		}
	}

	public function test_a_non_null_value_from_another_callback_passes_through_untouched(): void {
		// Core's own update_meta_cache() (wp-includes/meta.php) casts the whole filter chain's
		// final $check to bool once any callback returns non-null, so the scope's own contribution
		// is proven by two invariants rather than by a value surviving that cast: it must not run
		// its own load query once another callback has already taken over, and it must not treat
		// that takeover as ITS OWN failure (the built result is not swapped for the passed error).
		global $wpdb;
		$post_id = self::factory()->post->create();
		wp_cache_delete( $post_id, 'post_meta' );

		add_filter(
			'update_post_metadata_cache',
			static function () {
				return true; // Another plugin claims the load already happened.
			},
			5,
			1
		);

		$error = new WP_Error( 'aafm_error', 'unused' );
		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$queries = array();
		$track   = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $track );
		$result = aafm_with_checked_reads( $build, $error );
		remove_filter( 'query', $track );

		remove_all_filters( 'update_post_metadata_cache' );

		$this->assertNotSame( $error, $result, 'a foreign takeover must not be reported as the scope\'s own failure' );
		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( (string) $wpdb->postmeta, $query, 'the scope must not run its own load query once another callback already took over' );
		}
	}

	/**
	 * Ledger s14w1-codex-1 (design P3R-1.4): the scope's answers when the object cache does not
	 * keep the rows it installs, keyed by the fault-state table's row ids.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function unkept_rows_provider(): array {
		return array(
			'W1-C2'  => array( 'c2' ),
			'W1-C5'  => array( 'c5' ),
			'W1-C6'  => array( 'c6' ),
			'W1-C7'  => array( 'c7' ),
			'W1-C8'  => array( 'c8' ),
			'W1-C9'  => array( 'c9' ),
			'W1-C10' => array( 'c10' ),
			'served id, key 0 -> raw rows (core no-key answer)' => array( 'c10_key_zero' ),
			'W1-C11' => array( 'c11' ),
			'W1-C12' => array( 'c12' ),
			'W1-C13' => array( 'c13' ),
			'W1-C14' => array( 'c14' ),
			'W1-C15' => array( 'c15' ),
		);
	}

	/**
	 * Runs one row of the table.
	 *
	 * @dataProvider unkept_rows_provider
	 *
	 * @param string $row The fault-state table row to run.
	 */
	public function test_a_cache_that_does_not_keep_the_rows_still_reads_checked( string $row ): void {
		$this->{'row_' . $row}();
	}

	/**
	 * W1-C1b: an object with no meta at all is cached as array(), and the scope's own cache test
	 * (false !== wp_cache_get()) counts that as cached, so two reads run one metadata query.
	 */
	public function test_two_reads_of_a_meta_less_term_run_one_metadata_query(): void {
		global $wpdb;
		$term_id = self::factory()->term->create();
		wp_cache_delete( $term_id, 'term_meta' );

		$queries = array();
		$track   = static function ( $query ) use ( &$queries, $wpdb ) {
			if ( false !== strpos( $query, (string) $wpdb->termmeta ) ) {
				$queries[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $track );
		$result = aafm_with_checked_reads(
			static function () use ( $term_id ) {
				return array(
					'first'  => get_term_meta( $term_id, 'aafm_scope_key', true ),
					'second' => get_term_meta( $term_id, 'aafm_scope_key', true ),
				);
			},
			new WP_Error( 'aafm_error', 'unused' )
		);
		remove_filter( 'query', $track );

		$this->assertSame(
			array(
				'first'  => '',
				'second' => '',
			),
			$result
		);
		$this->assertCount( 1, $queries, 'the second read must find the cached empty set' );
	}

	/**
	 * Ledger s14a1-1 (PM ruling build-1b): a read inside the scope first runs core's
	 * update_post_metadata_cache chain, so a plugin that takes over the load there is called at
	 * most twice per read, with the same arguments both times, the scope runs no load query, and
	 * the read answers as at 9626307 (core reads no meta from a bool takeover).
	 */
	public function test_a_plugin_that_takes_over_the_load_is_called_at_most_twice_with_the_same_arguments(): void {
		global $wpdb;
		$post_id = $this->post_with_meta();

		$calls    = array();
		$takeover = static function ( ...$args ) use ( &$calls ) {
			$calls[] = $args;
			return true;
		};
		add_filter( 'update_post_metadata_cache', $takeover, 5, 2 );

		$queries = array();
		$track   = static function ( $query ) use ( &$queries, $wpdb ) {
			if ( false !== strpos( $query, (string) $wpdb->postmeta ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $track );

		try {
			$result = aafm_with_checked_reads(
				static function () use ( $post_id ) {
					return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
				},
				new WP_Error( 'aafm_error', 'unused' )
			);
		} finally {
			remove_filter( 'query', $track );
			remove_filter( 'update_post_metadata_cache', $takeover, 5 );
		}

		$this->assertSame( array( 'value' => '' ), $result );
		$this->assertGreaterThanOrEqual( 1, count( $calls ) );
		$this->assertLessThanOrEqual( 2, count( $calls ) );
		foreach ( $calls as $call ) {
			$this->assertSame( array( null, array( $post_id ) ), $call );
		}
		$this->assertSame( array(), $queries, 'the scope must not load an object another plugin took over' );
	}

	/**
	 * W1-C2: a failed checked query fails the call, and every read inside the build gets an empty
	 * answer in core's shape with no warning (r1-7, r2-4).
	 */
	private function row_c2(): void {
		global $wpdb;
		$post_id = $this->post_with_meta();
		$error   = new WP_Error( 'aafm_error', 'scope failed' );
		$seen    = array();

		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = QueryFaultInjector::fail_query(
			$wpdb->postmeta,
			static function () use ( $post_id, $error, &$seen ) {
				return aafm_with_checked_reads(
					static function () use ( $post_id, &$seen ) {
						$seen['all']        = get_post_meta( $post_id );
						$seen['all_single'] = get_metadata( 'post', $post_id, '', true );
						$seen['key']        = get_post_meta( $post_id, 'aafm_scope_key', true );
						$seen['key_list']   = get_post_meta( $post_id, 'aafm_scope_key', false );
						return array();
					},
					$error
				);
			}
		);
		$output = (string) ob_get_clean();
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( $error, $result );
		$this->assertSame(
			array(
				'all'        => array(),
				'all_single' => array(),
				'key'        => '',
				'key_list'   => array( '' ),
			),
			$seen
		);
		$this->assertSame( '', $output );
	}

	/**
	 * W1-C5: the read that triggers the load gets the checked rows.
	 */
	private function row_c5(): void {
		$post_id = $this->post_with_meta();

		$result = $this->with_unkept_post_meta(
			static function () use ( $post_id ) {
				return aafm_with_checked_reads(
					static function () use ( $post_id ) {
						return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
					},
					new WP_Error( 'aafm_error', 'unused' )
				);
			}
		);

		$this->assertSame( array( 'value' => 'value' ), $result );
	}

	/**
	 * W1-C6: the cache refuses the rows and core's own SELECT would fail. The scope serves its
	 * checked rows, so core's SELECT never runs.
	 */
	private function row_c6(): void {
		$this->assert_served_under_core_fault( false );
	}

	/**
	 * W1-C7: the cache reports the rows kept and keeps nothing; same answer as C6.
	 */
	private function row_c7(): void {
		$this->assert_served_under_core_fault( true );
	}

	/**
	 * W1-C8: an absent key on a served object falls through to core, and a registered default
	 * is honoured.
	 */
	private function row_c8(): void {
		$post_id = $this->post_with_meta();
		register_post_meta(
			'post',
			'aafm_scope_default',
			array(
				'single'  => true,
				'default' => 'fallback',
			)
		);

		try {
			$result = $this->in_unkept_scope(
				static function () use ( $post_id ) {
					return array(
						'present' => get_post_meta( $post_id, 'aafm_scope_key', true ),
						'absent'  => get_post_meta( $post_id, 'aafm_scope_default', true ),
					);
				}
			);
		} finally {
			unregister_post_meta( 'post', 'aafm_scope_default' );
		}

		$this->assertSame(
			array(
				'present' => 'value',
				'absent'  => 'fallback',
			),
			$result
		);
	}

	/**
	 * W1-C9: a no-key read of a served object returns core's raw rows.
	 */
	private function row_c9(): void {
		$post_id  = $this->post_with_meta();
		$expected = $this->healthy_rows( $post_id );

		$result = $this->in_unkept_scope(
			static function () use ( $post_id ) {
				return array( 'all' => get_post_meta( $post_id ) );
			}
		);

		$this->assertSame( array( 'all' => $expected ), $result );
	}

	/**
	 * W1-C10: a no-key single read of a served object returns the raw rows too, as core does.
	 */
	private function row_c10(): void {
		$post_id  = $this->post_with_meta();
		$expected = $this->healthy_rows( $post_id );

		$result = $this->in_unkept_scope(
			static function () use ( $post_id ) {
				return array(
					'key'        => get_post_meta( $post_id, 'aafm_scope_key', true ),
					'all_single' => get_metadata( 'post', $post_id, '', true ),
				);
			}
		);

		$this->assertSame(
			array(
				'key'        => 'value',
				'all_single' => $expected,
			),
			$result
		);
	}

	/**
	 * A read of key '0' on a served object is core's no-key read (get_metadata_raw() tests
	 * ! $meta_key), so it gets the raw rows from the scope and core's own SELECT never runs.
	 */
	private function row_c10_key_zero(): void {
		global $wpdb;
		$post_id  = $this->post_with_meta();
		$expected = $this->healthy_rows( $post_id );

		$core_select = "FROM {$wpdb->postmeta} WHERE post_id IN";

		$result = $this->with_unkept_post_meta(
			static function () use ( $post_id, $core_select ) {
				return QueryFaultInjector::break_query_with_real_error(
					$core_select,
					static function () use ( $post_id ) {
						return aafm_with_checked_reads(
							static function () use ( $post_id ) {
								return array( 'zero' => get_metadata( 'post', $post_id, '0', false ) );
							},
							new WP_Error( 'aafm_error', 'unused' )
						);
					}
				);
			}
		);

		$this->assertSame( array( 'zero' => $expected ), $result );
		$this->assertSame( 0, QueryFaultInjector::fired_count(), 'core\'s own metadata SELECT must not run for a served object' );
	}

	/**
	 * W1-C11: another plugin's get_post_metadata answer wins.
	 */
	private function row_c11(): void {
		$post_id = $this->post_with_meta();
		$theirs  = static function ( $check, $object_id, $meta_key ) {
			return 'aafm_scope_key' === $meta_key ? array( 'theirs' ) : $check;
		};
		add_filter( 'get_post_metadata', $theirs, 10, 3 );

		try {
			$result = $this->in_unkept_scope(
				static function () use ( $post_id ) {
					return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
				}
			);
		} finally {
			remove_filter( 'get_post_metadata', $theirs, 10 );
		}

		$this->assertSame( array( 'value' => 'theirs' ), $result );
	}

	/**
	 * W1-C12: a metadata write on a served object drops its served rows, so the next read loads
	 * the new value.
	 */
	private function row_c12(): void {
		$post_id = $this->post_with_meta();

		$result = $this->in_unkept_scope(
			static function () use ( $post_id ) {
				$before = get_post_meta( $post_id, 'aafm_scope_key', true );
				update_post_meta( $post_id, 'aafm_scope_key', 'changed' );
				return array(
					'before' => $before,
					'after'  => get_post_meta( $post_id, 'aafm_scope_key', true ),
				);
			}
		);

		$this->assertSame(
			array(
				'before' => 'value',
				'after'  => 'changed',
			),
			$result
		);
	}

	/**
	 * W1-C13: metadata_exists() on a served object with the key present is true.
	 */
	private function row_c13(): void {
		$post_id = $this->post_with_meta();

		$result = $this->in_unkept_scope(
			static function () use ( $post_id ) {
				return array( 'exists' => metadata_exists( 'post', $post_id, 'aafm_scope_key' ) );
			}
		);

		$this->assertSame( array( 'exists' => true ), $result );
	}

	/**
	 * W1-C14: metadata_exists() on a served object with the key absent is false, with no warning.
	 */
	private function row_c14(): void {
		$post_id = $this->post_with_meta();

		ob_start();
		$result = $this->in_unkept_scope(
			static function () use ( $post_id ) {
				return array( 'exists' => metadata_exists( 'post', $post_id, 'aafm_scope_absent' ) );
			}
		);
		$output = (string) ob_get_clean();

		$this->assertSame( array( 'exists' => false ), $result );
		$this->assertSame( '', $output );
	}

	/**
	 * W1-C15: a priority-15 get_post_metadata filter that calls metadata_exists() and migrates
	 * when the key is absent (the shape of Event Tickets' Tickets_Handler::filter_capacity_support())
	 * keeps its answer, with no warning.
	 */
	private function row_c15(): void {
		$post_id = $this->post_with_meta();
		$mimic   = static function ( $value, $object_id, $meta_key, $single = true ) use ( &$mimic ) {
			if ( null !== $value || 'aafm_scope_capacity' !== $meta_key ) {
				return $value;
			}
			remove_filter( 'get_post_metadata', $mimic, 15 );
			if ( metadata_exists( 'post', $object_id, $meta_key ) ) {
				return get_post_meta( $object_id, $meta_key, $single );
			}
			return 'migrated';
		};
		add_filter( 'get_post_metadata', $mimic, 15, 4 );

		try {
			ob_start();
			$result = $this->in_unkept_scope(
				static function () use ( $post_id ) {
					return array( 'capacity' => get_post_meta( $post_id, 'aafm_scope_capacity', true ) );
				}
			);
			$output = (string) ob_get_clean();
		} finally {
			remove_filter( 'get_post_metadata', $mimic, 15 );
		}

		$this->assertSame( array( 'capacity' => 'migrated' ), $result );
		$this->assertSame( '', $output );
	}

	/**
	 * C6 and C7: the cache does not keep the rows and core's own metadata SELECT fails.
	 *
	 * @param bool $claims_kept Whether the cache reports the rows as kept.
	 */
	private function assert_served_under_core_fault( bool $claims_kept ): void {
		global $wpdb;
		$post_id = $this->post_with_meta();

		// Core's own statement is unprepared (no backticks); the scope's checked query names the
		// table through %i, so this needle faults core's SELECT only.
		$core_select = "FROM {$wpdb->postmeta} WHERE post_id IN";

		$result = $this->with_unkept_post_meta(
			static function () use ( $post_id, $core_select ) {
				return QueryFaultInjector::break_query_with_real_error(
					$core_select,
					static function () use ( $post_id ) {
						return aafm_with_checked_reads(
							static function () use ( $post_id ) {
								return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
							},
							new WP_Error( 'aafm_error', 'unused' )
						);
					}
				);
			},
			$claims_kept
		);

		$this->assertSame( array( 'value' => 'value' ), $result );
		$this->assertSame( 0, QueryFaultInjector::fired_count(), 'core\'s own metadata SELECT must not run for a served object' );
	}

	/**
	 * A post carrying the scope's test key, with its meta cache emptied.
	 *
	 * @return int The post id.
	 */
	private function post_with_meta(): int {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		update_post_meta( $post_id, 'aafm_scope_list', array( 'a', 'b' ) );
		wp_cache_delete( $post_id, 'post_meta' );
		return $post_id;
	}

	/**
	 * Core's own no-key answer for a post, read with a working cache, which is emptied again after.
	 *
	 * @param int $post_id Post id.
	 * @return mixed
	 */
	private function healthy_rows( int $post_id ) {
		$rows = get_post_meta( $post_id );
		wp_cache_delete( $post_id, 'post_meta' );
		return $rows;
	}

	/**
	 * Run $build in the checked-read scope with a cache that keeps no post_meta rows.
	 *
	 * @param callable $build The build.
	 * @return mixed
	 */
	private function in_unkept_scope( callable $build ) {
		return $this->with_unkept_post_meta(
			static function () use ( $build ) {
				return aafm_with_checked_reads( $build, new WP_Error( 'aafm_error', 'unused' ) );
			}
		);
	}

	/**
	 * Run $callback with an object cache that stores nothing in the post_meta group, the way the
	 * Redis drop-in behaves when Redis rejects every SET (maxmemory with noeviction). Every other
	 * call reaches the real cache.
	 *
	 * @param callable $callback    Code to run.
	 * @param bool     $claims_kept Whether a refused write reports success.
	 * @return mixed
	 */
	private function with_unkept_post_meta( callable $callback, bool $claims_kept = false ) {
		global $wp_object_cache;
		$real = $wp_object_cache;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double, restored in finally.
		$wp_object_cache = new class( $real, $claims_kept ) {
			/**
			 * The real cache object.
			 *
			 * @var object
			 */
			private $real;

			/**
			 * Whether a refused write reports success.
			 *
			 * @var bool
			 */
			private $claims_kept;

			/**
			 * Wrap the real cache object.
			 *
			 * @param object $real        The real cache object.
			 * @param bool   $claims_kept Whether a refused write reports success.
			 */
			public function __construct( $real, bool $claims_kept ) {
				$this->real        = $real;
				$this->claims_kept = $claims_kept;
			}

			/**
			 * Refuse single writes to post_meta.
			 *
			 * @param int|string $key    Key.
			 * @param mixed      $data   Value.
			 * @param string     $group  Group.
			 * @param int        $expire Expiry.
			 * @return bool
			 */
			public function set( $key, $data, $group = 'default', $expire = 0 ) {
				return 'post_meta' === $group ? $this->claims_kept : $this->real->set( $key, $data, $group, $expire );
			}

			/**
			 * Refuse single additions to post_meta.
			 *
			 * @param int|string $key    Key.
			 * @param mixed      $data   Value.
			 * @param string     $group  Group.
			 * @param int        $expire Expiry.
			 * @return bool
			 */
			public function add( $key, $data, $group = 'default', $expire = 0 ) {
				return 'post_meta' === $group ? $this->claims_kept : $this->real->add( $key, $data, $group, $expire );
			}

			/**
			 * Refuse batched writes to post_meta.
			 *
			 * @param array<int|string,mixed> $data   Values by key.
			 * @param string                  $group  Group.
			 * @param int                     $expire Expiry.
			 * @return array<int|string,bool>
			 */
			public function set_multiple( array $data, $group = '', $expire = 0 ) {
				if ( 'post_meta' !== $group ) {
					return $this->real->set_multiple( $data, $group, $expire );
				}
				return array_fill_keys( array_keys( $data ), $this->claims_kept );
			}

			/**
			 * Refuse batched additions to post_meta.
			 *
			 * @param array<int|string,mixed> $data   Values by key.
			 * @param string                  $group  Group.
			 * @param int                     $expire Expiry.
			 * @return array<int|string,bool>
			 */
			public function add_multiple( array $data, $group = '', $expire = 0 ) {
				if ( 'post_meta' !== $group ) {
					return $this->real->add_multiple( $data, $group, $expire );
				}
				return array_fill_keys( array_keys( $data ), $this->claims_kept );
			}

			/**
			 * Forward every other cache call to the real cache object.
			 *
			 * @param string       $name Method.
			 * @param array<mixed> $args Arguments.
			 * @return mixed
			 */
			public function __call( $name, $args ) {
				return $this->real->$name( ...$args );
			}
		};
		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the real cache object.
			$wp_object_cache = $real;
		}
	}
}
