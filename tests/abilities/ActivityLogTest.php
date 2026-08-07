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

	/**
	 * Regression pin for the ability half of the 'started' filter: the input enum has named
	 * 'started' since the ability shipped, and it must keep returning only the stuck rows a
	 * crashed call leaves behind. 1.6.2 makes the ADMIN surface filter on it too (page.php);
	 * this pin exists so the two halves cannot drift apart again.
	 */
	public function test_status_filter_started_returns_only_started_rows(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'started',
			)
		);
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'success',
			)
		);

		// No count assertion: the get-activity-log call itself runs through the logging wrapper,
		// whose own row is still 'started' while the inner query executes, so it shows up here too.
		$res      = wp_get_ability( 'aafm/get-activity-log' )->execute( array( 'status' => 'started' ) );
		$statuses = array_unique( array_column( $res['entries'], 'status' ) );
		$this->assertSame( array( 'started' ), $statuses, 'Only started rows may come back.' );
		$abilities = array_column( $res['entries'], 'ability' );
		$this->assertContains( 'aafm/get-posts', $abilities );
		$this->assertNotContains( 'aafm/get-post', $abilities, 'The resolved success row must be filtered out.' );
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

	/**
	 * The crash detail is now reachable by an agent, which is only safe because 1.6.1 made it
	 * identifier-only first: a class name and a throw site, no message, no argument value, no
	 * install path. It is the same string the admin already sees in wp-admin.
	 *
	 * Filtered by ability on purpose. This ability audits itself - its own decorated closure
	 * inserts a 'started' row before the read runs - and aafm_query_activity() orders newest
	 * first, so entries[0] on an unfiltered read is the read's own self-audit row with a null
	 * detail.
	 */
	public function test_activity_log_returns_the_crash_detail(): void {
		$this->acting_as( 'administrator' );
		$row_id = aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'started',
			)
		);
		aafm_update_activity_status( $row_id, 'error', null, 'RuntimeException at foo.php:12' );

		$out = wp_get_ability( 'aafm/get-activity-log' )->execute( array( 'ability' => 'aafm/get-post' ) );

		$this->assertSame( 'RuntimeException at foo.php:12', $out['entries'][0]['detail'] );
	}

	/**
	 * This release exists because of empty-shape bugs, so a new nullable field gets its encoding
	 * pinned on the WIRE form. array() and (object) array() are indistinguishable in PHP and
	 * opposite in JSON, and asserting on the PHP value is how the 22nd instance gets created.
	 */
	public function test_a_row_with_no_detail_encodes_as_null_not_an_empty_array(): void {
		$this->acting_as( 'administrator' );
		$row_id = aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'started',
			)
		);
		aafm_update_activity_status( $row_id, 'success', null, null );

		$out = wp_get_ability( 'aafm/get-activity-log' )->execute( array( 'ability' => 'aafm/get-post' ) );

		$this->assertStringContainsString( '"detail":null', (string) wp_json_encode( $out['entries'][0] ) );
	}

	/**
	 * A field an agent can read has to be named in the two strings a human reads: the tool
	 * description an MCP client shows the agent, and the disclosure the admin consent panel shows
	 * the operator. The output schema is not one of those, so a field documented only there is
	 * undisclosed as far as either reader is concerned.
	 *
	 * This ability grew `detail` without either string being touched, and both still enumerated the
	 * fields and stopped at the timestamp. Pinning the two together so the next field cannot land
	 * the same way.
	 */
	public function test_the_detail_field_is_named_in_the_description_and_the_disclosure(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'success',
				'detail'  => 'Read post #7',
			)
		);

		$out = wp_get_ability( 'aafm/get-activity-log' )->execute( array( 'ability' => 'aafm/get-post' ) );
		$this->assertCount( 1, $out['entries'] );
		$this->assertArrayHasKey(
			'detail',
			$out['entries'][0],
			'Guard on the guard: if the ability stopped returning detail there would be nothing to disclose.'
		);

		$registry = aafm_get_abilities_registry();
		$this->assertStringContainsString( 'detail', $registry['aafm/get-activity-log']['description'] );

		$disclosures = aafm_ability_disclosures();
		$this->assertStringContainsString( 'detail', $disclosures['aafm/get-activity-log'] );
	}
}
