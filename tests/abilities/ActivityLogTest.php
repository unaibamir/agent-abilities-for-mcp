<?php
/**
 * Slice A: the read-only activity-log ability (get-activity-log).
 *
 * Covers the manage_options gate, most-recent-first ordering, the source_ip omission,
 * the closed-schema rejection of a smuggled field, the status filter pass-through, and
 * that a denied read is itself audited.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class ActivityLogTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->register_activity_log();
	}

	private function register_activity_log(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-activity-log' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_requires_manage_options(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/get-activity-log' )->check_permissions( array() ),
			'get-activity-log must deny an editor (no manage_options).'
		);
		$this->acting_as( 'administrator' );
		$this->assertTrue(
			wp_get_ability( 'aafm/get-activity-log' )->check_permissions( array() ),
			'get-activity-log must allow a manage_options admin.'
		);
	}

	public function test_returns_rows_most_recent_first_without_source_ip(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'status'            => 'success',
				'principal_user_id' => 1,
				'principal_login'   => 'admin',
			)
		);
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-pages',
				'status'            => 'success',
				'principal_user_id' => 1,
				'principal_login'   => 'admin',
			)
		);

		$res = wp_get_ability( 'aafm/get-activity-log' )->execute( array() );
		$this->assertIsArray( $res );
		$this->assertArrayHasKey( 'entries', $res );
		$this->assertNotEmpty( $res['entries'] );
		// Most recent first. The read audits itself, so row 0 is this get-activity-log
		// call; the proof of ordering is that get-pages (logged last of the two seeds)
		// precedes get-posts among the returned rows.
		$order   = array_column( $res['entries'], 'ability' );
		$i_pages = array_search( 'aafm/get-pages', $order, true );
		$i_posts = array_search( 'aafm/get-posts', $order, true );
		$this->assertNotFalse( $i_pages, 'get-pages must be present.' );
		$this->assertNotFalse( $i_posts, 'get-posts must be present.' );
		$this->assertLessThan( $i_posts, $i_pages, 'most-recent-first: get-pages (logged last) precedes get-posts.' );
		// source_ip is never returned (network PII not shown in the admin panel).
		$json = (string) wp_json_encode( $res );
		$this->assertStringNotContainsString( 'source_ip', $json, 'source_ip must not be exposed.' );
		// The fields we DO return.
		foreach ( array( 'id', 'ability', 'status', 'principal_user_id', 'principal_login', 'arg_keys', 'created_at' ) as $k ) {
			$this->assertArrayHasKey( $k, $res['entries'][0], "missing $k" );
		}
	}

	public function test_status_and_ability_filters_pass_through(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability'           => 'aafm/trash-post',
				'status'            => 'denied',
				'principal_user_id' => 2,
				'principal_login'   => 'x',
			)
		);
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'status'            => 'success',
				'principal_user_id' => 1,
				'principal_login'   => 'admin',
			)
		);

		$res = wp_get_ability( 'aafm/get-activity-log' )->execute( array( 'status' => 'denied' ) );
		$this->assertCount( 1, $res['entries'] );
		$this->assertSame( 'aafm/trash-post', $res['entries'][0]['ability'] );
	}

	public function test_rejects_a_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/get-activity-log' )->execute( array( 'principal_user_id' => 5 ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'closed schema must reject a smuggled field.' );
	}

	public function test_denial_is_audited(): void {
		$this->acting_as( 'subscriber' );
		wp_get_ability( 'aafm/get-activity-log' )->execute( array() );
		$denied    = aafm_query_activity(
			array(
				'status'   => 'denied',
				'per_page' => 50,
			)
		);
		$abilities = array_column( $denied, 'ability' );
		$this->assertContains( 'aafm/get-activity-log', $abilities, 'a denied read must be audited.' );
	}
}
