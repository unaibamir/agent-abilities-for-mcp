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
}
