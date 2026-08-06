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
	 * Give every case an installed, empty activity log to resolve rows against.
	 */
	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * The hook is additive and fires on every resolve, not only on a crash.
	 */
	public function test_resolving_a_row_fires_the_resolve_action(): void {
		$fired = array();
		add_action(
			'aafm_ability_resolved',
			static function ( $record ) use ( &$fired ): void {
				$fired[] = $record;
			}
		);

		$row_id = aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'started',
			)
		);
		aafm_update_activity_status( $row_id, 'error', null, 'RuntimeException at foo.php:12' );

		$this->assertCount( 1, $fired );
		$this->assertSame( $row_id, $fired[0]['row_id'] );
		$this->assertSame( 'error', $fired[0]['status'] );
		$this->assertSame( 'RuntimeException at foo.php:12', $fired[0]['detail'] );
	}

	/**
	 * A no-op resolve is still a resolve. wpdb::update() returns 0 when the row matched and nothing
	 * changed, which is a SUCCESS, so a naive `if ( $updated )` would suppress the hook on every
	 * legitimate one. Only a literal false counts as failure.
	 */
	public function test_a_no_op_resolve_still_fires(): void {
		$fired = 0;
		add_action(
			'aafm_ability_resolved',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$row_id = aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'started',
			)
		);
		aafm_update_activity_status( $row_id, 'success' );
		aafm_update_activity_status( $row_id, 'success' );

		$this->assertSame( 2, $fired, 'The second write changes no column, and that is still a resolve.' );
	}

	/**
	 * A row id that was never written resolves nothing, so it must fire nothing either.
	 */
	public function test_an_invalid_row_id_fires_nothing(): void {
		$fired = 0;
		add_action(
			'aafm_ability_resolved',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		aafm_update_activity_status( 0, 'error' );

		$this->assertSame( 0, $fired );
	}

	/**
	 * The record carries no ability name, and that is a design decision rather than an oversight.
	 * aafm_update_activity_status() receives no ability name and holds no reference to the row's
	 * other columns, and includes/audit/log.php has no read-by-id helper, so putting `ability` in
	 * the payload would cost a new helper plus an extra SELECT on every single resolve. A consumer
	 * joins on row_id against the aafm_ability_called record instead.
	 */
	public function test_the_record_carries_only_what_the_resolve_already_knows(): void {
		$fired = array();
		add_action(
			'aafm_ability_resolved',
			static function ( $record ) use ( &$fired ): void {
				$fired[] = $record;
			}
		);

		$row_id = aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'started',
			)
		);
		aafm_update_activity_status( $row_id, 'success', 7 );

		$this->assertSame(
			array( 'row_id', 'status', 'result_count', 'detail' ),
			array_keys( $fired[0] )
		);
		$this->assertSame( 7, $fired[0]['result_count'] );
		$this->assertNull( $fired[0]['detail'] );
	}
}
