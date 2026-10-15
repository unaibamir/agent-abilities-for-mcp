<?php
/**
 * The activity-log ability hides the alert rows. It is callable by any manage_options principal, an agent included, and the rows that
 * record the owner's alert settings, and the rows that record an alert failing to send, would tell such
 * an agent how loud it can afford to be, so the ability never returns them. The admin screen and the
 * CSV still do.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ActivityLogNotificationRowsTest extends TestCase {

	private function seed(): void {
		aafm_log_activity(
			array(
				'ability'    => 'aafm/get-posts',
				'status'     => 'success',
				'detail'     => 'ordinary-row',
				'event_type' => 'ability_call',
			)
		);
		aafm_log_activity(
			array(
				'ability'    => 'aafm/notifications',
				'status'     => 'success',
				'event_type' => 'setting_changed',
				'detail'     => 'Notifications set to: Off',
			)
		);
		aafm_log_activity(
			array(
				'ability'    => 'aafm/notifications',
				'status'     => 'error',
				'event_type' => 'setting_changed',
				'detail'     => 'A notification email could not be sent',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_the_ability_never_returns_the_alert_setting_or_failure_rows(): void {
		$this->seed();

		$out = aafm_exec_get_activity_log( array() );

		$this->assertSame( array( 'aafm/get-posts' ), array_column( $out['entries'], 'ability' ) );
		$this->assertSame( 1, $out['total'], 'The total does not count them either.' );
	}

	public function test_asking_for_them_by_name_or_status_still_finds_nothing(): void {
		$this->seed();

		$this->assertSame( array(), aafm_exec_get_activity_log( array( 'ability' => 'aafm/notifications' ) )['entries'] );
		$this->assertSame( array(), aafm_exec_get_activity_log( array( 'status' => 'error' ) )['entries'] );
		$this->assertSame( 0, aafm_exec_get_activity_log( array( 'status' => 'error' ) )['total'] );
	}

	public function test_the_admin_screen_query_still_sees_them(): void {
		$this->seed();

		$this->assertCount( 2, aafm_query_activity( array( 'ability' => 'aafm/notifications' ) ) );
		$this->assertSame( 3, aafm_activity_count_matching( array() ) );
	}
}
