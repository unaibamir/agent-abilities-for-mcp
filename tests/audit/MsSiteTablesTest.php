<?php
/**
 * Deleting a subsite must drop the plugin's own tables for that site.
 *
 * Core's wpmu_delete_blog() drops only the tables named by the wpmu_drop_tables filter, so
 * without a listener the activity log and the four OAuth tables were orphaned. The suite
 * rewrites plugin CREATE TABLE to temporary tables, so these tests assert the filter output
 * rather than the database.
 *
 * Runs only under tests/multisite.xml.dist; the single-site config skips it.
 *
 * @group ms-required
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

/**
 * Pins the wpmu_drop_tables listener.
 *
 * @group ms-required
 */
final class MsSiteTablesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
	}

	public function test_the_drop_filter_names_this_sites_log_and_oauth_tables(): void {
		global $wpdb;
		$blog_id = (int) self::factory()->blog->create();
		$prefix  = $wpdb->get_blog_prefix( $blog_id );

		$tables = aafm_filter_wpmu_drop_tables( array( $prefix . 'posts' ), $blog_id );

		$this->assertContains( $prefix . 'posts', $tables, 'Core tables already on the list stay.' );
		$this->assertContains( $prefix . 'aafm_activity_log', $tables );
		foreach ( aafm_oauth_table_suffixes() as $suffix ) {
			$this->assertContains( $prefix . $suffix, $tables );
		}
	}

	public function test_the_drop_filter_uses_the_deleted_sites_prefix_not_the_current_one(): void {
		global $wpdb;
		$blog_id = (int) self::factory()->blog->create();

		$tables = aafm_filter_wpmu_drop_tables( array(), $blog_id );

		$this->assertNotSame( $wpdb->prefix, $wpdb->get_blog_prefix( $blog_id ), 'precondition: the test runs on another site.' );
		$this->assertContains( $wpdb->get_blog_prefix( $blog_id ) . 'aafm_activity_log', $tables );
		$this->assertNotContains( $wpdb->prefix . 'aafm_activity_log', $tables, 'The current site\'s table must never be dropped.' );
	}

	public function test_the_drop_filter_is_registered_when_the_plugin_loads(): void {
		$this->assertNotFalse( has_filter( 'wpmu_drop_tables', 'aafm_filter_wpmu_drop_tables' ) );
	}
}
