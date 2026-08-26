<?php
/**
 * The wp_ability_invoked audit hook: closing the visibility gap a wp_pre_execute_ability
 * short-circuit opens, since our own decorated execute_callback never runs on that path.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class AbilityInvokedHookTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Register a fixture ability that returns a fixed, cheap-to-assert-on result.
	 *
	 * @param string $name Ability name.
	 */
	private function register_fixture( string $name ): void {
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				aafm_register_ability_with_log(
					$name,
					array(
						'label'               => 'Invoked-hook fixture',
						'description'         => 'Test fixture for the wp_ability_invoked audit hook.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static fn() => array( 'ok' => true ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * THE DEFECT: a third party short-circuiting execute() via wp_pre_execute_ability must still
	 * leave a trace in the audit log - a 'started' row this plugin's own decorated execute_callback
	 * never gets the chance to write, because the short-circuit skips it entirely. Before the fix
	 * there is no wp_ability_invoked listener at all, so this assertion fails against a fresh log.
	 */
	public function test_a_short_circuited_call_still_writes_a_started_row(): void {
		$this->register_fixture( 'aafm-test/invoked-hook-short-circuit' );

		add_filter(
			'wp_pre_execute_ability',
			static function ( $pre, string $ability_name ) {
				if ( 'aafm-test/invoked-hook-short-circuit' === $ability_name ) {
					return array( 'intercepted' => true );
				}
				return $pre;
			},
			10,
			2
		);

		$result = wp_get_ability( 'aafm-test/invoked-hook-short-circuit' )->execute( array() );

		$this->assertSame( array( 'intercepted' => true ), $result, 'Fixture check: the short-circuit itself must actually take effect.' );

		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/invoked-hook-short-circuit' ) );
		$this->assertCount( 1, $rows, 'A short-circuited call must still leave exactly one audit row.' );
		$this->assertSame( 'started', $rows[0]['status'], 'The row can never resolve past started - nothing this plugin owns ran the call.' );
	}

	/**
	 * A NORMAL (non-short-circuited) call must still produce exactly ONE row, not two: the hook
	 * opens it, the decorated execute_callback must reuse that same row rather than open a second
	 * one for the same call.
	 */
	public function test_a_normal_call_still_writes_exactly_one_row(): void {
		$this->register_fixture( 'aafm-test/invoked-hook-normal-call' );

		$result = wp_get_ability( 'aafm-test/invoked-hook-normal-call' )->execute( array() );

		$this->assertSame( array( 'ok' => true ), $result );

		$rows = aafm_query_activity( array( 'ability' => 'aafm-test/invoked-hook-normal-call' ) );
		$this->assertCount( 1, $rows, 'The hook-opened row and the decorated execute_callback must resolve the SAME row, not two.' );
		$this->assertSame( 'success', $rows[0]['status'] );
	}

	/**
	 * The hook must never write a row for an ability this plugin did not register through the
	 * choke point - core's own abilities and other plugins' abilities also fire wp_ability_invoked,
	 * and this plugin's audit log is not theirs to write to.
	 */
	public function test_the_hook_ignores_an_ability_this_plugin_never_registered(): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'demo-things' ) ) {
					wp_register_ability_category(
						'demo-things',
						array(
							'label'       => 'Demo',
							'description' => 'Demo fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				wp_register_ability(
					'demo/not-ours',
					array(
						'label'               => 'Not ours',
						'description'         => 'An ability registered outside the choke point.',
						'category'            => 'demo-things',
						'execute_callback'    => static fn() => array( 'ok' => true ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		wp_get_ability( 'demo/not-ours' )->execute( array() );

		$this->assertSame( array(), aafm_query_activity( array( 'ability' => 'demo/not-ours' ) ) );
		wp_unregister_ability( 'demo/not-ours' );
	}

	/**
	 * Fix round 1, Codex finding 1: aafm_log_ability_invocation() pushes a pending row before core
	 * does any work, but a real WP 7.1 execute() returns directly - without ever reaching
	 * check_permissions() or the decorated execute_callback - when validate_input() fails on a
	 * malformed input. Before the fix that pending entry stayed on the per-name stack, and a LATER
	 * call for the SAME ability, denied at a preliminary permission check that never goes through
	 * execute() (exactly how the MCP adapter's own check_permission() call and this suite's own
	 * test_denied_is_audited cases work), would pop and resolve the FIRST call's row instead of
	 * writing its own - misattributing the second call's denial onto the first call, and leaving the
	 * second call with no audit row of its own at all.
	 */
	public function test_a_validation_failure_leaves_no_dangling_row_for_a_later_denied_call(): void {
		$name = 'aafm-test/invoked-hook-validation-then-denial';
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				aafm_register_ability_with_log(
					$name,
					array(
						'label'               => 'Invoked-hook fixture (validation then denial)',
						'description'         => 'Test fixture: strict input schema, denies on a marker value.',
						'category'            => 'aafm-reads',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array( 'n' => array( 'type' => 'integer' ) ),
							'required'   => array( 'n' ),
						),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static fn() => array( 'ok' => true ),
						'permission_callback' => static fn( $input ) => ! ( is_array( $input ) && 2 === ( $input['n'] ?? null ) ),
					)
				);
			}
		);

		$first = wp_get_ability( $name )->execute( array( 'n' => 'not-an-integer' ) );
		$this->assertTrue( is_wp_error( $first ), 'Fixture check: the malformed input must actually fail core validation before any permission check.' );

		$rows_after_first = aafm_query_activity( array( 'ability' => $name ) );
		$this->assertCount( 1, $rows_after_first, 'Fixture check: the invalid call still opened its own started row.' );
		$this->assertSame( 'started', $rows_after_first[0]['status'] );
		$first_row_id = (int) $rows_after_first[0]['id'];

		// Simulate the adapter's preliminary check_permission() call, exactly like this suite's own
		// test_denied_is_audited cases: called directly, never through execute(), for a call that
		// never opens its own pending row.
		$second = wp_get_ability( $name )->check_permissions( array( 'n' => 2 ) );
		$this->assertNotTrue( $second, 'Fixture check: the marker input must actually be denied.' );

		$rows_after_second = aafm_query_activity( array( 'ability' => $name ) );
		$this->assertCount( 2, $rows_after_second, "The second call must write its OWN row, not resolve the first call's dangling started row." );

		$first_row_again = current( array_filter( $rows_after_second, static fn( $r ) => (int) $r['id'] === $first_row_id ) );
		$this->assertNotFalse( $first_row_again, "The first call's row must still exist, untouched." );
		$this->assertSame( 'started', $first_row_again['status'], "The first call's row must remain at started - the second call must not have resolved it." );

		$second_row = current( array_filter( $rows_after_second, static fn( $r ) => (int) $r['id'] !== $first_row_id ) );
		$this->assertSame( 'denied', $second_row['status'], 'The second call must be attributed as its own, distinct denied row.' );
	}

	/**
	 * Fix round 1, Codex finding 1, short-circuit variant: an intentional wp_pre_execute_ability
	 * short-circuit is the other real WP 7.1 exit path that returns from execute() without ever
	 * reaching check_permissions() or the decorated execute_callback. Same contamination risk as the
	 * validation-failure case above, same fix, separate proof.
	 */
	public function test_a_short_circuited_call_leaves_no_dangling_row_for_a_later_denied_call(): void {
		$name = 'aafm-test/invoked-hook-short-circuit-then-denial';
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				aafm_register_ability_with_log(
					$name,
					array(
						'label'               => 'Invoked-hook fixture (short-circuit then denial)',
						'description'         => 'Test fixture: short-circuited execute(), denies on a marker value.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static fn() => array( 'ok' => true ),
						'permission_callback' => static fn( $input ) => ! ( is_array( $input ) && 2 === ( $input['n'] ?? null ) ),
					)
				);
			}
		);

		add_filter(
			'wp_pre_execute_ability',
			static function ( $pre, string $ability_name ) use ( $name ) {
				if ( $name === $ability_name ) {
					return array( 'intercepted' => true );
				}
				return $pre;
			},
			10,
			2
		);

		$first = wp_get_ability( $name )->execute( array( 'n' => 1 ) );
		$this->assertSame( array( 'intercepted' => true ), $first, 'Fixture check: the short-circuit itself must actually take effect.' );

		$rows_after_first = aafm_query_activity( array( 'ability' => $name ) );
		$this->assertCount( 1, $rows_after_first );
		$this->assertSame( 'started', $rows_after_first[0]['status'] );
		$first_row_id = (int) $rows_after_first[0]['id'];

		$second = wp_get_ability( $name )->check_permissions( array( 'n' => 2 ) );
		$this->assertNotTrue( $second, 'Fixture check: the marker input must actually be denied.' );

		$rows_after_second = aafm_query_activity( array( 'ability' => $name ) );
		$this->assertCount( 2, $rows_after_second, "The second call must write its OWN row, not resolve the short-circuited call's dangling started row." );

		$first_row_again = current( array_filter( $rows_after_second, static fn( $r ) => (int) $r['id'] === $first_row_id ) );
		$this->assertNotFalse( $first_row_again );
		$this->assertSame( 'started', $first_row_again['status'] );

		$second_row = current( array_filter( $rows_after_second, static fn( $r ) => (int) $r['id'] !== $first_row_id ) );
		$this->assertSame( 'denied', $second_row['status'] );
	}

	/**
	 * Test-quality finding 1: the plan's own regression, a denial reached through execute() itself
	 * (not through check_permissions() called directly, which every pre-existing
	 * test_denied_is_audited case uses instead), was never directly pinned. This is the minimal
	 * dedicated proof: exactly one row, not a stuck 'started' row plus a separate 'denied' one.
	 */
	public function test_a_call_denied_through_execute_writes_exactly_one_row(): void {
		$name = 'aafm-test/invoked-hook-denied-through-execute';
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				aafm_register_ability_with_log(
					$name,
					array(
						'label'               => 'Invoked-hook fixture (denied through execute)',
						'description'         => 'Test fixture whose permission callback always refuses.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static fn() => array( 'ok' => true ),
						'permission_callback' => '__return_false',
					)
				);
			}
		);

		$result = wp_get_ability( $name )->execute( array() );
		$this->assertTrue( is_wp_error( $result ) );

		$rows = aafm_query_activity( array( 'ability' => $name ) );
		$this->assertCount( 1, $rows, 'A call denied through execute() must resolve the SAME hook-opened row, not leave it stuck AND write a second one.' );
		$this->assertSame( 'denied', $rows[0]['status'] );
	}

	/**
	 * Test-quality finding 1's third requested case: a denial with genuinely nothing pending (no
	 * wp_ability_invoked fire preceded it, matching every pre-existing test_denied_is_audited case)
	 * must still fall back to a fresh insert rather than finding nothing and writing nothing.
	 */
	public function test_a_denial_with_no_pending_row_uses_the_fallback_insert(): void {
		$name = 'aafm-test/invoked-hook-denied-no-pending-row';
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name ): void {
				aafm_register_ability_with_log(
					$name,
					array(
						'label'               => 'Invoked-hook fixture (denied, no pending row)',
						'description'         => 'Test fixture whose permission callback always refuses, called directly.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static fn() => array( 'ok' => true ),
						'permission_callback' => '__return_false',
					)
				);
			}
		);

		// check_permissions() directly, never through execute(): wp_ability_invoked never fires, so
		// there is genuinely no pending row for this call to find.
		$denied = wp_get_ability( $name )->check_permissions( array() );
		$this->assertNotTrue( $denied );

		$rows = aafm_query_activity( array( 'ability' => $name ) );
		$this->assertCount( 1, $rows, 'With nothing pending, the denial must fall back to a fresh insert.' );
		$this->assertSame( 'denied', $rows[0]['status'] );
	}

	/**
	 * Codex finding 1's suggested second case: a permission callback that throws and is configured
	 * to re-throw must not leak its pending-stack entry into a LATER, unrelated call for the same
	 * ability name that completes normally.
	 *
	 * Core's own WP_Ability::invoke_callback() (since 6.9.0) already wraps every permission_callback
	 * fire in a try/catch and converts any Throwable - including one this plugin's own decorated
	 * closure deliberately re-throws - into a WP_Error before it ever reaches execute(). So the
	 * "rethrow" branch never surfaces a raw PHP exception to a caller; what it actually controls is
	 * whether OUR OWN denial-audit code runs at all (rethrow skips it, matching the documented "no
	 * row on this path" intent - true here for OUR write, though core's own wp_ability_invoked hook
	 * still opened one first). execute() itself then raises _doing_it_wrong() for the WP_Error it
	 * receives back, which is core's ordinary behavior for this case, not part of the defect.
	 */
	public function test_a_rethrown_permission_crash_leaves_no_dangling_row_for_a_later_call(): void {
		$name = 'aafm-test/invoked-hook-crash-then-success';
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_true' );

		$allow = false;
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name, &$allow ): void {
				aafm_register_ability_with_log(
					$name,
					array(
						'label'               => 'Invoked-hook fixture (crash then success)',
						'description'         => 'Test fixture whose permission callback throws until a flag flips.',
						'category'            => 'aafm-reads',
						'input_schema'        => array( 'type' => 'object' ),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static fn() => array( 'ok' => true ),
						'permission_callback' => static function () use ( &$allow ) {
							if ( ! $allow ) {
								throw new \RuntimeException( 'boom from the permission callback' );
							}
							return true;
						},
					)
				);
			}
		);

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$first = wp_get_ability( $name )->execute( array() );
		$this->assertTrue( is_wp_error( $first ), 'Fixture check: the crashed permission callback must deny the first call.' );

		$rows_after_first = aafm_query_activity( array( 'ability' => $name ) );
		$this->assertCount( 1, $rows_after_first, 'The crashed call leaves exactly its own row, stuck at started - the forensic signal core swallowing the rethrow does not erase.' );
		$this->assertSame( 'started', $rows_after_first[0]['status'] );

		$allow  = true;
		$result = wp_get_ability( $name )->execute( array() );
		$this->assertSame( array( 'ok' => true ), $result );

		$rows = aafm_query_activity( array( 'ability' => $name ) );
		// One row stuck at 'started' from the crashed call, one row 'success' from the later call -
		// and critically, the success row must be its OWN row, not the crashed call's row
		// resurrected via a leaked stack entry.
		$this->assertCount( 2, $rows, 'The crashed call and the later successful call must each keep their own row.' );
		$statuses = wp_list_pluck( $rows, 'status' );
		sort( $statuses );
		$this->assertSame( array( 'started', 'success' ), $statuses );
	}

	/**
	 * Final-gate fix, Codex finding 1 (the minimum test it names): a wp_pre_execute_ability filter
	 * that recursively executes the SAME ability is not misuse - the Abilities API places no
	 * restriction on same-name nesting. A per-name-only correlation stack cannot tell whose frame
	 * is on top: the nested call's own cleanup discarded the OUTER call's still-open frame, so the
	 * outer's own execute_callback later found nothing pending and opened a duplicate row, while
	 * the true outer row was left stuck at 'started' forever with no way back to it.
	 *
	 * The filter here returns $pre UNCHANGED after the nested call, so the OUTER call also
	 * proceeds normally through its own decorated execute_callback - this is the shape that
	 * actually exposes the bug: under the pre-fix (name-only) stack, the outer's execute_callback
	 * would find its own frame already gone (eaten by the nested call's cleanup) and open a
	 * replacement, yielding three rows (one stuck, two resolved) instead of the correct two.
	 */
	public function test_a_recursive_same_ability_call_resolves_exactly_two_rows(): void {
		$name = 'aafm-test/invoked-hook-recursive-same-ability';
		$this->register_fixture( $name );

		$recursed = false;
		add_filter(
			'wp_pre_execute_ability',
			static function ( $pre, string $ability_name ) use ( $name, &$recursed ) {
				if ( $name === $ability_name && ! $recursed ) {
					$recursed = true;
					// The nested call must fully resolve - push, tag, resolve, discard - before
					// this filter returns, per ordinary PHP call-stack nesting. Its result is not
					// needed here; only that it runs and cleans up after itself correctly.
					wp_get_ability( $name )->execute( array() );
				}
				return $pre; // Not a short-circuit: let the OUTER call proceed normally.
			},
			10,
			2
		);

		$outer = wp_get_ability( $name )->execute( array() );
		$this->assertSame( array( 'ok' => true ), $outer, 'Fixture check: the outer call must resolve normally (not short-circuited).' );

		$rows = aafm_query_activity( array( 'ability' => $name ) );
		$this->assertCount( 2, $rows, 'The outer call and the nested call must each resolve to exactly their own row - no stuck row, no duplicate.' );

		$statuses = wp_list_pluck( $rows, 'status' );
		$this->assertSame( array( 'success', 'success' ), $statuses, 'Both calls ran to completion and neither was left at started nor duplicated.' );
	}

	/**
	 * The flat-case regression check alongside the recursive test above: nesting a DIFFERENT
	 * ability inside a wp_pre_execute_ability filter must keep working exactly as before, since
	 * the two abilities use entirely separate per-name stacks and neither invocation's token has
	 * any reason to collide with the other's.
	 */
	public function test_nesting_a_different_ability_resolves_both_independently(): void {
		$outer_name = 'aafm-test/invoked-hook-nesting-outer';
		$inner_name = 'aafm-test/invoked-hook-nesting-inner';
		$this->register_fixture( $outer_name );
		$this->register_fixture( $inner_name );

		add_filter(
			'wp_pre_execute_ability',
			static function ( $pre, string $ability_name ) use ( $outer_name, $inner_name ) {
				if ( $outer_name === $ability_name ) {
					wp_get_ability( $inner_name )->execute( array() );
				}
				return $pre;
			},
			10,
			2
		);

		$outer = wp_get_ability( $outer_name )->execute( array() );
		$this->assertSame( array( 'ok' => true ), $outer );

		$outer_rows = aafm_query_activity( array( 'ability' => $outer_name ) );
		$inner_rows = aafm_query_activity( array( 'ability' => $inner_name ) );

		$this->assertCount( 1, $outer_rows, 'The outer ability must resolve to exactly one row.' );
		$this->assertSame( 'success', $outer_rows[0]['status'] );
		$this->assertCount( 1, $inner_rows, 'The nested, different-named ability must resolve to exactly one row of its own.' );
		$this->assertSame( 'success', $inner_rows[0]['status'] );
	}
}
