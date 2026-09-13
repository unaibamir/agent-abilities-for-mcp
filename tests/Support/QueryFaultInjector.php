<?php
/**
 * Reusable $wpdb query fault injector for the failure-path test suite.
 *
 * Eight Codex rounds on `fix/1.7.5-deferred` found real defects almost exclusively in query
 * FAILURE paths (a stale $wpdb->last_result, a token that rotates mid-walk, a row that changes
 * between two statements) while 3,800+ PHPUnit tests, the traffic sim, and every CI leg stayed
 * green throughout - the gate only ever exercised success paths. Several fix lanes each hand-rolled
 * their own version of "make one query fail" to cover their finding (HelpersTest, UninstallTest,
 * AuthorizeTest, AllowlistAdminTest, PairedSecurityWriteOrderTest, OauthRevokeAjaxTest, TokensTest).
 * This factors that shape out into one place so there is one way to do this, and so it can be
 * reused for the decisive-path coverage this bug class actually needs.
 *
 * TWO DISTINCT FAILURE SHAPES, both real bugs have depended on either one:
 *
 * - fail_nth_query() / fail_query(): the 'query' filter returns '' for the targeted query.
 *   wp-includes/class-wpdb.php's query() checks `if ( ! $query )` and returns false
 *   IMMEDIATELY, before its own flush() call - so $wpdb->last_result/last_error are left holding
 *   whatever the PREVIOUS query left there. This is the "no-flush" / stale-result path the
 *   R7-2/R8-1/R8-2/R8-3 defect class exploits, and it raises no real SQL error.
 *
 * - break_query_with_real_error(): the targeted query is redirected at a table that does not
 *   exist, producing a genuine SQL error - last_result IS flushed, last_error IS populated. Use
 *   this when the code (or the assertion) needs a real failure signal rather than a stale prior
 *   one.
 *
 * $needle ALSO accepts an array of substrings instead of one string: every element must be
 * present (AND, not OR) for the query to count as a match. Several real call sites need this -
 * "the UPDATE statement" is not always one contiguous substring of the final SQL once
 * $wpdb->prepare()'s own whitespace/formatting is in play, so two independent substrings (the
 * table name, and separately the SET clause) is the only reliable way to identify them. $exact
 * is ignored when $needle is an array.
 *
 * OUTPUT: the WP PHPUnit bootstrap forces `$wpdb->show_errors = true`, so a real wpdb error
 * prints a raw HTML block. Under phpunit.xml.dist's `beStrictAboutOutputDuringTests`, and in any
 * caller that decodes a captured buffer as JSON, that stray print is a second bug layered on top
 * of whatever the test meant to check (see the `fault-injection-must-buffer-output` project
 * lesson - it cost a full diagnostic cycle here once already). The run()-based methods below
 * suppress wpdb's own error echo AND buffer/discard output for the whole armed window,
 * unconditionally, so a caller never has to remember to do it per test.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

/**
 * Static: no state outside a single call's dynamic scope, so nothing to construct or tear down
 * between tests.
 */
final class QueryFaultInjector {

	/**
	 * Run $callback with the $occurrence-th (1-based) query matching $needle made to fail via
	 * the no-flush path (see class docblock). Every other query - including earlier/later
	 * matches of $needle - passes through unmodified, which is what lets a test fail one read in
	 * a sequence while its neighbours succeed (the shape several of these defects only appear
	 * under).
	 *
	 * @param string|string[] $needle Substring (or, with $exact, the full literal query) identifying
	 *                                 the query/queries to target, or an array of substrings that must
	 *                                 all be present (AND).
	 * @param int             $occurrence 1-based index of the matching query to fail; 0 fails every match.
	 * @param callable        $callback   Code to run with the fault armed.
	 * @param bool            $exact      Match $needle against the whole query string instead of a substring.
	 * @return mixed $callback()'s return value.
	 */
	public static function fail_nth_query( $needle, int $occurrence, callable $callback, bool $exact = false ) {
		return self::run_armed( self::no_flush_filter( $needle, $occurrence, $exact ), $callback );
	}

	/**
	 * Convenience for the common case: fail every query matching $needle for the life of
	 * $callback.
	 *
	 * @param string|string[] $needle   Substring (or array of AND-matched substrings) to target.
	 * @param callable        $callback Code to run with the fault armed.
	 * @param bool            $exact    Match $needle against the whole query string instead of a substring.
	 * @return mixed $callback()'s return value.
	 */
	public static function fail_query( $needle, callable $callback, bool $exact = false ) {
		return self::fail_nth_query( $needle, 0, $callback, $exact );
	}

	/**
	 * Run $callback with the $occurrence-th (1-based) query matching $needle redirected at a
	 * table that does not exist, producing a genuine SQL error (last_result flushed, last_error
	 * populated) - the opposite precondition from fail_nth_query() above.
	 *
	 * @param string|string[] $needle     Substring (or array of AND-matched substrings) to target.
	 * @param callable        $callback   Code to run with the fault armed.
	 * @param int             $occurrence 1-based index of the matching query to break; 0 breaks every match.
	 * @param bool            $exact      Match $needle against the whole query string instead of a substring.
	 * @return mixed $callback()'s return value.
	 */
	public static function break_query_with_real_error( $needle, callable $callback, int $occurrence = 0, bool $exact = false ) {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			return self::run_armed( self::real_error_filter( $needle, $occurrence, $exact ), $callback );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Build, but do not register, the no-flush filter callback fail_nth_query()/fail_query() use.
	 * For call sites that pre-date this class and arm/disarm across several statements rather
	 * than one $callback (they already `add_filter( 'query', ... )` themselves and clean up with
	 * `remove_all_filters( 'query' )` or an explicit `remove_filter()`) - migrating them still
	 * gets every caller onto the same matching/redirect logic instead of a fifth hand-copied one,
	 * without having to restructure each test around a callback.
	 *
	 * @param string|string[] $needle     Substring (or array of AND-matched substrings) to target.
	 * @param int             $occurrence 1-based index of the matching query to fail; 0 fails every match.
	 * @param bool            $exact      Match $needle against the whole query string instead of a substring.
	 * @return callable A `query` filter callback. The caller owns add_filter()/remove_filter().
	 */
	public static function no_flush_filter( $needle, int $occurrence = 0, bool $exact = false ): callable {
		$seen = 0;
		return static function ( string $query ) use ( $needle, $occurrence, $exact, &$seen ): string {
			if ( ! self::query_matches( $query, $needle, $exact ) ) {
				return $query;
			}
			++$seen;
			return ( 0 === $occurrence || $seen === $occurrence ) ? '' : $query;
		};
	}

	/**
	 * Build, but do not register, the real-error filter callback break_query_with_real_error()
	 * uses. Same rationale as no_flush_filter() above; the caller is still responsible for its
	 * own $wpdb->suppress_errors() toggle and output buffering around the armed window.
	 *
	 * @param string|string[] $needle     Substring (or array of AND-matched substrings) to target.
	 * @param int             $occurrence 1-based index of the matching query to break; 0 breaks every match.
	 * @param bool            $exact      Match $needle against the whole query string instead of a substring.
	 * @return callable A `query` filter callback. The caller owns add_filter()/remove_filter().
	 */
	public static function real_error_filter( $needle, int $occurrence = 0, bool $exact = false ): callable {
		$seen = 0;
		return static function ( string $query ) use ( $needle, $occurrence, $exact, &$seen ): string {
			if ( ! self::query_matches( $query, $needle, $exact ) ) {
				return $query;
			}
			++$seen;
			return ( 0 === $occurrence || $seen === $occurrence )
				? 'SELECT * FROM aafm_missing_table_for_test'
				: $query;
		};
	}

	/**
	 * Whether $query is the one fail_nth_query()/break_query_with_real_error() and friends
	 * should count as a match.
	 *
	 * @param string          $query  The query wpdb is about to run.
	 * @param string|string[] $needle Substring, array of AND-matched substrings, or (with $exact)
	 *                                the full literal query to look for.
	 * @param bool            $exact  Match $needle against the whole query string instead of a substring.
	 * @return bool
	 */
	private static function query_matches( string $query, $needle, bool $exact ): bool {
		if ( is_array( $needle ) ) {
			foreach ( $needle as $part ) {
				if ( false === strpos( $query, $part ) ) {
					return false;
				}
			}
			return true;
		}
		return $exact ? $needle === $query : false !== strpos( $query, $needle );
	}

	/**
	 * Arm the 'query' filter, run $callback with output buffered and discarded, then disarm -
	 * unconditionally, even if $callback throws.
	 *
	 * @param callable $filter   The `query` filter callback to register for the duration.
	 * @param callable $callback Code to run with the fault armed.
	 * @return mixed $callback()'s return value.
	 */
	private static function run_armed( callable $filter, callable $callback ) {
		add_filter( 'query', $filter );
		ob_start();
		try {
			return $callback();
		} finally {
			ob_end_clean();
			remove_filter( 'query', $filter );
		}
	}
}
