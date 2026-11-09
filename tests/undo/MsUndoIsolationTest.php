<?php
/**
 * Change history is per site: never read across sites, and a network user delete purges every site.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\TestCase;

/**
 * Multisite isolation of the change table.
 *
 * @group ms-required
 */
final class MsUndoIsolationTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
	}

	/**
	 * Create a site with the change table and one change row by the given user.
	 *
	 * @param int $user_id Principal.
	 * @return int The blog id.
	 */
	private function site_with_a_change_by( int $user_id ): int {
		$blog_id = (int) self::factory()->blog->create();
		add_user_to_blog( $blog_id, $user_id, 'editor' );
		switch_to_blog( $blog_id );
		aafm_install_activity_log();
		aafm_undo_insert(
			array(
				'ability'           => 'aafm/update-post',
				'principal_user_id' => $user_id,
				'object_type'       => 'post',
				'object_id'         => 1,
				'status'            => 'ready',
			)
		);
		restore_current_blog();
		return $blog_id;
	}

	private function rows_on( int $blog_id ): int {
		global $wpdb;
		switch_to_blog( $blog_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', aafm_changes_table() ) );
		restore_current_blog();
		return $count;
	}

	public function test_a_change_on_one_site_is_not_read_on_another(): void {
		$user = self::factory()->user->create();
		$a    = $this->site_with_a_change_by( $user );
		$b    = (int) self::factory()->blog->create();
		switch_to_blog( $b );
		aafm_install_activity_log();
		restore_current_blog();

		$this->assertSame( 1, $this->rows_on( $a ) );
		$this->assertSame( 0, $this->rows_on( $b ), 'The other site table holds nothing.' );
	}

	public function test_network_user_delete_removes_their_rows_on_every_site(): void {
		$user = self::factory()->user->create();
		$a    = $this->site_with_a_change_by( $user );
		$b    = $this->site_with_a_change_by( $user );
		$this->assertSame( 1, $this->rows_on( $a ) );
		$this->assertSame( 1, $this->rows_on( $b ) );

		require_once ABSPATH . 'wp-admin/includes/ms.php';
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wpmu_delete_user( $user );

		$this->assertSame( 0, $this->rows_on( $a ) );
		$this->assertSame( 0, $this->rows_on( $b ) );
	}

	public function test_network_purge_skips_a_site_without_the_table(): void {
		$user    = self::factory()->user->create();
		$blog_id = (int) self::factory()->blog->create();
		add_user_to_blog( $blog_id, $user, 'editor' );
		switch_to_blog( $blog_id );
		global $wpdb;
		$table = aafm_changes_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table}" );
		restore_current_blog();

		ob_start();
		aafm_undo_on_network_user_delete( $user );
		$printed = ob_get_clean();

		$this->assertSame( '', $printed, 'A site without the table is skipped without a database error.' );
	}
}
