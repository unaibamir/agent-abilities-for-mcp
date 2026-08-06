<?php
/**
 * The resolve-time hook: an external monitor's only way to see a crash as it happens.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class ResolveHookTest extends TestCase {

	/**
	 * Every fire of the hook during one test, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $fired = array();

	/**
	 * Give every case an installed, empty activity log and a listener on the hook.
	 */
	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();

		$this->fired = array();
		add_action(
			'aafm_ability_resolved',
			function ( $record ): void {
				$this->fired[] = $record;
			}
		);
	}

	/**
	 * Register a fixture ability whose execute callback throws, through the real logging wrapper.
	 *
	 * @param string $name Ability name. Distinct per test: the abilities registry is process-wide
	 *                     and is not reset between cases, so a reused name trips core's
	 *                     already-registered notice.
	 * @return void
	 */
	private function register_throwing_fixture( string $name ): void {
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				aafm_register_ability_with_log(
					$name,
					array(
						'label'               => 'Throws on purpose',
						'description'         => 'Test fixture that throws from its execute callback.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static function () {
							throw new \RuntimeException( 'boom from the ability' );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * The announcer fires when it is invoked, and hands the record through unchanged.
	 *
	 * That is all this case can say, because it calls the announcer directly with literal
	 * arguments. Whether every resolve invokes it is a property of the CALLER, and it is pinned by
	 * the cases below that drive a real registered ability: success, denial, crash, re-throw, a
	 * failed opening insert.
	 */
	public function test_announcing_a_resolve_fires_the_action(): void {
		aafm_announce_ability_resolved( 41, 'error', null, 'RuntimeException at foo.php:12' );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( 41, $this->fired[0]['row_id'] );
		$this->assertSame( 'error', $this->fired[0]['status'] );
		$this->assertSame( 'RuntimeException at foo.php:12', $this->fired[0]['detail'] );
	}

	/**
	 * The single most important property of this hook, and the one no test covered until now: a
	 * CRASHED call announces exactly ONCE, carrying the crash detail rather than the null the tail
	 * resolve passes to protect the column.
	 *
	 * The crash path writes the row twice - aafm_log_ability_exception() then the tail
	 * aafm_update_activity_status() - so an announcement fired from inside the writer fires twice
	 * per crash and the LAST thing a monitor sees is `detail: null`, i.e. a crash report with no
	 * crash information. This test drives the real registration wrapper, which is the only way to
	 * reach that double write; every direct call to the writer misses it entirely.
	 *
	 * The WP_DEBUG rethrow is filter-gated off because WP_DEBUG is true in this project's PHPUnit
	 * environment - without this the rethrow branch runs, which announces nothing by design.
	 */
	public function test_a_crashed_call_announces_once_with_the_surviving_detail(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		$this->register_throwing_fixture( 'aafm-test/resolve-hook-crash' );

		wp_get_ability( 'aafm-test/resolve-hook-crash' )->execute( array() );

		$this->assertCount( 1, $this->fired, 'A crash resolves once, so it announces once.' );
		$this->assertSame( 'error', $this->fired[0]['status'] );
		$this->assertStringStartsWith(
			'RuntimeException at ',
			(string) $this->fired[0]['detail'],
			'The announced detail must be the crash detail that survived on the row, not the null the tail resolve passes.'
		);

		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/resolve-hook-crash' ) );
		$this->assertSame(
			$rows[0]['detail'],
			$this->fired[0]['detail'],
			'A monitor and the admin panel must never disagree about what a crashed call recorded.'
		);
	}

	/**
	 * A SUCCESSFUL call announces too, and the payload's row_id is the only thing a consumer can
	 * join on, so it has to be the row the call actually wrote.
	 *
	 * Neither was pinned. The crash case below is the only one that reached the announcement
	 * through the real wrapper, so narrowing the caller's guard to
	 * `if ( $written && is_wp_error( $result ) )` - silencing the hook for every successful call,
	 * which is most calls - left the whole suite green, and so did announcing a hardcoded row_id of
	 * 0. A record whose row_id joins to nothing is the same defect as the null detail this hook was
	 * fixed for: it fires, it looks right, and it carries nothing usable.
	 */
	public function test_a_successful_call_announces_once_with_the_row_and_count_it_wrote(): void {
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				aafm_register_ability_with_log(
					'aafm-test/resolve-hook-success',
					array(
						'label'               => 'Succeeds on purpose',
						'description'         => 'Test fixture that returns a list cleanly.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static function (): array {
							return array( 'items' => array( 1, 2, 3 ) );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		wp_get_ability( 'aafm-test/resolve-hook-success' )->execute( array() );

		$this->assertCount( 1, $this->fired, 'Every resolve announces, not only the failing ones.' );
		$this->assertSame( 'success', $this->fired[0]['status'] );

		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/resolve-hook-success' ) );
		$this->assertCount( 1, $rows, 'Guard on the guard: one row per call, so there is a single id to match against.' );
		$this->assertSame(
			(int) $rows[0]['id'],
			$this->fired[0]['row_id'],
			'The announced row_id must be the row the call wrote, or a consumer has nothing to join on.'
		);
		$this->assertNotNull( $rows[0]['result_count'], 'Guard on the guard: a read ability must have recorded a magnitude, or the next assertion is vacuous.' );
		$this->assertSame(
			(int) $rows[0]['result_count'],
			$this->fired[0]['result_count'],
			'A monitor and the admin panel must not disagree about how much the call returned.'
		);
	}

	/**
	 * A call whose opening insert never landed has no row to resolve, so it must announce nothing:
	 * row_id would be 0, which joins to nothing, and the record would report a resolve for a call
	 * the log has no trace of. The caller's `if ( $written )` guard is the whole of that, and
	 * dropping it left the whole suite green.
	 *
	 * The insert is failed by rewriting it to a harmless SELECT through wpdb's own `query` filter,
	 * so insert_id stays 0 and aafm_log_activity() returns 0 - the same shape a real insert failure
	 * has, without touching the schema.
	 */
	public function test_a_call_whose_row_never_opened_announces_nothing(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				aafm_register_ability_with_log(
					'aafm-test/resolve-hook-no-row',
					array(
						'label'               => 'Succeeds on purpose',
						'description'         => 'Test fixture whose audit row never opens.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static function (): array {
							return array( 'ok' => true );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$swallow_insert = static function ( $query ) {
			if ( is_string( $query ) && 0 === stripos( $query, 'INSERT' ) && false !== stripos( $query, 'aafm_activity_log' ) ) {
				return 'SELECT 1';
			}
			return $query;
		};
		add_filter( 'query', $swallow_insert );
		try {
			wp_get_ability( 'aafm-test/resolve-hook-no-row' )->execute( array() );
		} finally {
			remove_filter( 'query', $swallow_insert );
		}

		$this->assertCount(
			0,
			aafm_query_activity( array( 'ability' => 'aafm-test/resolve-hook-no-row' ) ),
			'Guard on the guard: the insert must really have been swallowed, or this proves nothing.'
		);
		$this->assertCount(
			0,
			$this->fired,
			'No row means no resolve to announce: a row_id of 0 joins to nothing.'
		);
	}

	/**
	 * A REFUSED call has finished, so it announces - and it announces the row the denial wrote,
	 * because a denial never resolves a 'started' row, it writes its own.
	 *
	 * This was the hook's structural blind spot: aafm_announce_ability_resolved() had exactly one
	 * call site, the execute closure's tail, which a denied call never reaches. The docblock said
	 * "whatever the outcome" and the wp.org listing told operators an uptime monitor would see a
	 * failure through it, while every denial in the plugin was silent.
	 */
	public function test_a_denied_call_announces_the_row_the_denial_wrote(): void {
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				aafm_register_ability_with_log(
					'aafm-test/resolve-hook-denied',
					array(
						'label'               => 'Refuses on purpose',
						'description'         => 'Test fixture whose permission callback says no.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static function (): array {
							return array( 'never' => 'reached' );
						},
						'permission_callback' => '__return_false',
					)
				);
			}
		);

		wp_get_ability( 'aafm-test/resolve-hook-denied' )->execute( array() );

		$this->assertCount( 1, $this->fired, 'A refusal is an outcome, and it announces exactly once.' );
		$this->assertSame( 'denied', $this->fired[0]['status'] );

		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/resolve-hook-denied' ) );
		$this->assertCount( 1, $rows, 'Guard on the guard: one row per denial, so there is a single id to match against.' );
		$this->assertSame( 'denied', $rows[0]['status'] );
		$this->assertSame(
			(int) $rows[0]['id'],
			$this->fired[0]['row_id'],
			'The announced row_id must be the row the denial wrote, or a consumer has nothing to join on.'
		);
	}

	/**
	 * The crashed permission check this release added is the single failure an operator most wants
	 * paged about, and it announced nothing at all.
	 *
	 * 1.6.1's other headline fix exists to turn a throwing vendor user_has_cap filter into a denial
	 * instead of a dead tools/list. Leaving the hook blind to exactly that class of failure would
	 * have shipped a monitoring feature that cannot see the crash the same release introduced a
	 * guard for.
	 */
	public function test_a_crashed_permission_check_announces_the_denial_it_recorded(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				aafm_register_ability_with_log(
					'aafm-test/resolve-hook-perm-crash',
					array(
						'label'               => 'Explodes on purpose',
						'description'         => 'Test fixture whose permission callback throws.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static function (): array {
							return array( 'never' => 'reached' );
						},
						'permission_callback' => static function (): bool {
							throw new \RuntimeException( 'boom from the permission callback' );
						},
					)
				);
			}
		);

		wp_get_ability( 'aafm-test/resolve-hook-perm-crash' )->execute( array() );

		$this->assertCount( 1, $this->fired, 'A crashed check resolves the call once, so it announces once.' );
		$this->assertSame( 'denied', $this->fired[0]['status'] );
		$this->assertStringStartsWith(
			'RuntimeException at ',
			(string) $this->fired[0]['detail'],
			'A monitor must be told what exploded, not merely that something was refused.'
		);

		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/resolve-hook-perm-crash' ) );
		$this->assertCount( 1, $rows, 'Guard on the guard: the crashed check must really have written its row.' );
		$this->assertSame( $rows[0]['detail'], $this->fired[0]['detail'], 'The monitor and the admin panel must agree.' );
		$this->assertSame( (int) $rows[0]['id'], $this->fired[0]['row_id'] );
	}

	/**
	 * The re-throw branch resolves nothing - it leaves the row at 'started' on purpose - so it must
	 * announce nothing either. Reached through the registered ability's decorated closure by
	 * reflection, so the assertion holds on the WP 6.9 floor as well as on 7.0+, where core's own
	 * WP_Ability::invoke_callback() would absorb the re-throw one layer out.
	 */
	public function test_a_rethrown_crash_announces_nothing(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_true' );
		$this->register_throwing_fixture( 'aafm-test/resolve-hook-rethrow' );

		$ability  = wp_get_ability( 'aafm-test/resolve-hook-rethrow' );
		$property = new \ReflectionProperty( $ability, 'execute_callback' );
		$property->setAccessible( true );
		$decorated = $property->getValue( $ability );

		$caught = null;
		try {
			$decorated( array() );
		} catch ( \RuntimeException $e ) {
			$caught = $e;
		}

		$this->assertInstanceOf( \RuntimeException::class, $caught, 'Guard on the guard: the re-throw must actually happen.' );
		$this->assertCount( 0, $this->fired, 'An unresolved row is not a resolve, and the absent announcement is the signal.' );
	}

	/**
	 * A no-op resolve is still a resolve. wpdb::update() returns 0 when the row matched and nothing
	 * changed, and that is a SUCCESS - a naive `(bool) $updated` would report it as a failed write
	 * and the caller would then stay silent on every legitimate one. Only a literal false is failure.
	 */
	public function test_a_no_op_resolve_still_reports_a_successful_write(): void {
		$row_id = aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'started',
			)
		);

		$this->assertTrue( aafm_update_activity_status( $row_id, 'success' ) );
		$this->assertTrue(
			aafm_update_activity_status( $row_id, 'success' ),
			'The second write changes no column, and that is still a resolve.'
		);
	}

	/**
	 * A non-positive row id is refused before the query runs (log.php's `$row_id <= 0` guard), so it
	 * reports no write and the caller announces nothing.
	 *
	 * A positive id matching no row reports no write either, and telling that apart from the no-op
	 * above is the whole reason the zero branch exists. wpdb::update() returns 0 for "no match"
	 * exactly as it does for "matched, unchanged", and the no-op resolve is a real resolve, so the
	 * two cannot share an answer: a row cleared between the opening insert and the resolve would
	 * otherwise announce a row_id that joins to nothing.
	 *
	 * Both halves are pinned here so neither drifts, and so the earlier version of this test - which
	 * passed 0 and therefore stayed green under every mutation, including deleting the hook outright
	 * - cannot come back.
	 */
	public function test_a_row_id_that_matches_no_row_reports_no_write(): void {
		$this->assertFalse(
			aafm_update_activity_status( 0, 'error' ),
			'A non-positive id never reaches the query.'
		);
		$this->assertFalse(
			aafm_update_activity_status( 999999, 'error' ),
			'A row that is not there cannot have been resolved, however positive its id looks.'
		);
	}

	/**
	 * The record carries no ability name, and that is a design decision rather than an oversight.
	 * The resolve path receives no ability name and holds no reference to the row's other columns,
	 * and includes/audit/log.php has no read-by-id helper, so putting `ability` in the payload would
	 * cost a new helper plus an extra SELECT on every single resolve. A consumer joins on row_id
	 * against the aafm_ability_called record instead.
	 */
	public function test_the_record_carries_only_what_the_resolve_already_knows(): void {

		aafm_announce_ability_resolved( 12, 'success', 7 );

		$this->assertSame(
			array( 'row_id', 'status', 'result_count', 'detail' ),
			array_keys( $this->fired[0] )
		);
		$this->assertSame( 7, $this->fired[0]['result_count'] );
		$this->assertNull( $this->fired[0]['detail'] );
	}

	/**
	 * The announced detail is the string the column holds, not the raw argument. The writer
	 * sanitizes on the way in, so announcing the unsanitized value would hand a consumer text the
	 * log itself refused to store - and this is a public extension point, so a future caller passing
	 * something looser must not be able to broadcast it.
	 */
	public function test_the_announced_detail_is_sanitized_like_the_column(): void {

		$raw = "RuntimeException  at\tfoo.php:12\n";
		aafm_announce_ability_resolved( 13, 'error', null, $raw );

		$this->assertSame( aafm_sanitize_activity_detail( $raw ), $this->fired[0]['detail'] );
		$this->assertNotSame( $raw, $this->fired[0]['detail'], 'Guard on the guard: this fixture must actually need cleaning.' );
	}
}
