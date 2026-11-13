<?php
/**
 * The record of which other-plugin tools the owner has reviewed, and how a new one is detected.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\ForeignAbilities;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class BridgeReviewTest extends TestCase {

	use ForeignAbilities;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_bridge_review' );
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		$this->unregister_foreign_abilities();
		remove_all_filters( 'pre_update_option_aafm_bridge_review' );
		parent::tear_down();
	}

	public function test_the_first_sync_baselines_everything_and_flags_nothing(): void {
		$this->register_foreign_abilities( 'demo', array( 'one' => array( 'readonly' => true ) ) );
		$this->assertFalse( aafm_bridge_review_state()['baselined'] );
		$this->assertSame( array(), aafm_bridge_new_tools() );

		aafm_bridge_review_sync();

		$state = aafm_bridge_review_state();
		$this->assertTrue( $state['baselined'] );
		$this->assertContains( 'demo/one', $state['reviewed'] );
		$this->assertSame( array(), aafm_bridge_new_tools() );
	}

	public function test_a_tool_enabled_while_its_plugin_is_inactive_at_first_load_is_not_new(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo-away/delete-thing' ) );

		aafm_bridge_review_sync();
		$this->register_foreign_abilities( 'demo-away', array( 'delete-thing' => array( 'destructive' => true ) ) );

		$this->assertSame( array(), aafm_bridge_new_tools(), 'The tab already listed it in the Unavailable group.' );
		aafm_bridge_review_sync();
		$this->assertSame( array(), aafm_bridge_review_state()['notified'], 'Reactivating its plugin announces nothing.' );
	}

	public function test_a_first_mark_also_counts_a_stored_tool_whose_plugin_is_inactive(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo-away/delete-thing' ) );

		$this->assertTrue( aafm_bridge_mark_reviewed( array() ) );
		$this->register_foreign_abilities( 'demo-away', array( 'delete-thing' => array( 'destructive' => true ) ) );

		$this->assertSame( array(), aafm_bridge_new_tools() );
	}

	public function test_a_tool_registered_after_the_baseline_is_new(): void {
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );
		aafm_bridge_review_sync();

		$this->register_foreign_abilities( 'demo', array( 'two' => array() ) );

		$this->assertSame( array( 'demo/two' ), aafm_bridge_new_tools() );
	}

	public function test_foreign_slugs_leave_out_our_own_and_the_adapters(): void {
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );
		$slugs = aafm_bridge_foreign_slugs();

		$this->assertContains( 'demo/one', $slugs );
		foreach ( $slugs as $slug ) {
			$this->assertNotContains( aafm_bridge_namespace_of( $slug ), array( 'aafm', AAFM_BRIDGE_NAMESPACE, 'mcp-adapter' ), $slug );
		}
	}

	public function test_slugs_group_the_way_the_directory_merges_them(): void {
		$groups = aafm_bridge_group_slugs( array( 'aioseo-posts/a', 'aioseo-settings/b', 'wpforms/c' ) );

		$this->assertSame( array( 'aioseo-posts/a', 'aioseo-settings/b' ), $groups[ aafm_bridge_merge_group_key( 'aioseo' ) ] );
		$this->assertSame( array( 'wpforms/c' ), $groups['wpforms'] );
	}

	public function test_marking_reviewed_clears_only_the_named_slugs(): void {
		aafm_bridge_review_sync();
		$this->register_foreign_abilities(
			'demo',
			array(
				'one' => array(),
				'two' => array(),
			)
		);

		$this->assertTrue( aafm_bridge_mark_reviewed( array( 'demo/one' ) ) );

		$this->assertSame( array( 'demo/two' ), aafm_bridge_new_tools() );
	}

	public function test_a_first_mark_does_not_make_everything_else_new(): void {
		$this->register_foreign_abilities(
			'demo',
			array(
				'one' => array(),
				'two' => array(),
			)
		);

		$this->assertTrue( aafm_bridge_mark_reviewed( array( 'demo/one' ) ) );

		$this->assertSame( array(), aafm_bridge_new_tools() );
	}

	public function test_new_tools_are_never_enabled_or_registered(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/kept' ) );
		aafm_bridge_review_sync();
		$before = get_option( 'aafm_enabled_bridged_abilities' );

		$this->register_foreign_abilities( 'demo', array( 'fresh' => array( 'readonly' => true ) ) );
		aafm_bridge_review_sync();
		aafm_bridge_mark_reviewed( array( 'demo/fresh' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities' );

		$this->assertSame( $before, get_option( 'aafm_enabled_bridged_abilities' ) );
		$this->assertFalse( wp_has_ability( 'aafm-bridge/demo-fresh' ) );
	}

	/**
	 * Turn the new-tools notification on for the admin notice only.
	 *
	 * @return void
	 */
	private function notify_on(): void {
		update_option(
			'aafm_notifications',
			array(
				'mode'     => 'notice',
				'email'    => '',
				'triggers' => array( 'new_tools' => true ),
			)
		);
		delete_option( 'aafm_notify_pending' );
	}

	/**
	 * How many times a new-tools event reached the admin notice. Repeats of one trigger and subtype
	 * fold into one entry with a repeat count (aafm_notify_pending_push()).
	 *
	 * @return int
	 */
	private function announcements(): int {
		$total = 0;
		foreach ( wp_list_filter( aafm_notify_pending_list(), array( 'trigger' => 'new_tools' ) ) as $entry ) {
			$total += 1 + (int) ( $entry['repeat'] ?? 0 );
		}
		return $total;
	}

	public function test_a_notification_fires_once_per_new_tool(): void {
		aafm_bridge_review_sync();
		$this->notify_on();
		$this->register_foreign_abilities(
			'demo',
			array(
				'one' => array(),
				'two' => array(),
			)
		);

		aafm_bridge_review_sync();
		$this->assertSame( 1, $this->announcements() );
		$this->assertSame( 2, wp_list_filter( aafm_notify_pending_list(), array( 'trigger' => 'new_tools' ) )[0]['count'] );

		aafm_bridge_review_sync();
		$this->assertSame( 1, $this->announcements(), 'A second sync adds none.' );

		$this->register_foreign_abilities( 'demo', array( 'three' => array() ) );
		aafm_bridge_review_sync();
		$this->assertSame( 2, $this->announcements() );
		$this->assertCount( 3, aafm_bridge_review_state()['notified'] );
	}

	public function test_the_first_load_after_upgrading_raises_no_notification(): void {
		$this->notify_on();
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );

		aafm_bridge_review_sync();

		$this->assertSame( 0, $this->announcements() );
	}

	public function test_reviewing_one_plugins_tools_does_not_re_announce_the_others(): void {
		aafm_bridge_review_sync();
		$this->notify_on();
		$this->register_foreign_abilities( 'plugina', array( 'one' => array() ) );
		$this->register_foreign_abilities( 'pluginb', array( 'two' => array() ) );
		aafm_bridge_review_sync();
		$this->assertSame( 1, $this->announcements() );

		$this->assertTrue( aafm_bridge_mark_reviewed( array( 'plugina/one' ) ) );
		$this->assertNotContains( 'plugina/one', aafm_bridge_review_state()['notified'], 'A reviewed slug leaves the announced list.' );
		aafm_bridge_review_sync();
		$this->assertSame( 1, $this->announcements(), 'The remaining tool is not announced again.' );

		$this->register_foreign_abilities( 'pluginb', array( 'three' => array() ) );
		aafm_bridge_review_sync();
		$this->assertSame( 2, $this->announcements() );
	}

	public function test_a_plugin_deactivated_before_review_is_not_announced_twice_on_reactivation(): void {
		aafm_bridge_review_sync();
		$this->notify_on();
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );
		aafm_bridge_review_sync();
		$this->assertSame( 1, $this->announcements() );

		$this->unregister_foreign_ability( 'demo/one' );
		aafm_bridge_review_sync();
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );
		aafm_bridge_review_sync();

		$this->assertSame( 1, $this->announcements() );
		$this->assertSame( array( 'demo/one' ), aafm_bridge_new_tools(), 'It is still waiting for review.' );
	}

	public function test_no_notification_when_the_trigger_is_off(): void {
		aafm_bridge_review_sync();
		$this->notify_on();
		update_option(
			'aafm_notifications',
			array(
				'mode'     => 'notice',
				'email'    => '',
				'triggers' => array( 'switch_flip' => true ),
			)
		);
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );

		aafm_bridge_review_sync();

		$this->assertSame( 0, $this->announcements() );
		$this->assertSame( array( 'demo/one' ), aafm_bridge_new_tools(), 'It still shows as new on the screen.' );
	}

	public function test_a_write_that_keeps_failing_does_not_announce_on_every_page_load(): void {
		aafm_bridge_review_sync();
		$this->notify_on();
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );
		add_filter( 'pre_update_option_aafm_bridge_review', static fn( $value, $old ) => $old, 10, 2 );

		aafm_bridge_review_sync();
		aafm_bridge_review_sync();

		$this->assertSame( 0, $this->announcements() );
	}

	public function test_a_deactivated_then_reactivated_plugin_is_not_new_again(): void {
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );
		aafm_bridge_review_sync();

		$this->unregister_foreign_ability( 'demo/one' );
		aafm_bridge_review_sync();
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );
		aafm_bridge_review_sync();

		$this->assertSame( array(), aafm_bridge_new_tools() );
		$this->assertContains( 'demo/one', aafm_bridge_review_state()['reviewed'] );
	}

	public function test_a_stale_object_cache_cannot_hide_a_new_tool(): void {
		aafm_bridge_review_sync();
		$this->register_foreign_abilities( 'demo', array( 'fresh' => array() ) );

		// A cache copy that says the new tool was already reviewed, in both places core could read it.
		$stale = array(
			'v'        => 1,
			'reviewed' => array( 'demo/fresh' ),
			'notified' => array(),
		);
		wp_cache_set( 'aafm_bridge_review', $stale, 'options' );
		$all                       = wp_load_alloptions( true );
		$all['aafm_bridge_review'] = maybe_serialize( $stale );
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertSame( $stale, get_option( 'aafm_bridge_review' ), 'Precondition: the stale copy is what get_option() sees.' );

		$this->assertSame( array( 'demo/fresh' ), aafm_bridge_new_tools() );
		wp_cache_delete( 'alloptions', 'options' );
	}

	public function test_a_stale_cache_with_no_row_still_baselines_to_the_database(): void {
		global $wpdb;
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'aafm_bridge_review' ) );
		$all                       = wp_load_alloptions( true );
		$all['aafm_bridge_review'] = maybe_serialize(
			array(
				'v'        => 1,
				'reviewed' => array(),
				'notified' => array(),
			)
		);
		wp_cache_set( 'alloptions', $all, 'options' );

		aafm_bridge_review_sync();

		$this->assertTrue( aafm_option_row( 'aafm_bridge_review' )['found'] );
		$this->assertContains( 'demo/one', aafm_option_row( 'aafm_bridge_review' )['value']['reviewed'] );
		wp_cache_delete( 'alloptions', 'options' );
	}

	public function test_a_failed_write_is_reported_not_swallowed(): void {
		aafm_bridge_review_sync();
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );

		add_filter( 'pre_update_option_aafm_bridge_review', static fn( $value, $old ) => $old, 10, 2 );

		$this->assertFalse( aafm_bridge_mark_reviewed( array( 'demo/one' ) ) );
		$this->assertSame( array( 'demo/one' ), aafm_bridge_new_tools(), 'The tool was not reviewed.' );
	}

	public function test_a_failed_baseline_writes_a_bounded_error_row(): void {
		add_filter( 'pre_update_option_aafm_bridge_review', static fn( $value, $old ) => $old, 10, 2 );

		aafm_bridge_review_sync();

		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm_bridge_review',
				'status'  => 'error',
			)
		);
		$this->assertCount( 1, $rows );
	}

	public function test_an_unreadable_row_writes_nothing_and_shows_more_not_less(): void {
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );

		$new    = null;
		$marked = null;
		QueryFaultInjector::fail_query(
			'aafm_bridge_review',
			static function () use ( &$new, &$marked ): void {
				$new = aafm_bridge_new_tools();
				aafm_bridge_review_sync();
				$marked = aafm_bridge_mark_reviewed( array( 'demo/one' ) );
			}
		);

		$this->assertContains( 'demo/one', $new );
		$this->assertFalse( $marked );
		$this->assertFalse( aafm_option_row( 'aafm_bridge_review' )['found'], 'No write from an unreadable view.' );
	}

	public function test_the_plugin_reset_clears_the_state(): void {
		$this->assertContains( 'aafm_bridge_review', aafm_config_option_names() );
	}

	public function test_the_sync_skips_ajax_and_non_admins(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		aafm_bridge_review_sync();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( aafm_option_row( 'aafm_bridge_review' )['found'], 'AJAX.' );

		add_filter( 'wp_doing_cron', '__return_true' );
		aafm_bridge_review_sync();
		remove_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( aafm_option_row( 'aafm_bridge_review' )['found'], 'Cron.' );

		$this->acting_as( 'editor' );
		aafm_bridge_review_sync();
		$this->assertFalse( aafm_option_row( 'aafm_bridge_review' )['found'], 'A user without manage_options.' );
	}

	public function test_the_sync_is_hooked_on_admin_init(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local source file.
		$main = (string) file_get_contents( AAFM_PLUGIN_DIR . 'agent-abilities-for-mcp.php' );
		$this->assertStringContainsString( "add_action( 'admin_init', 'aafm_bridge_review_sync', 20 );", $main );
	}

	/**
	 * Seed a real new-tools notice: a baseline, then one tool from a new plugin.
	 *
	 * @return void
	 */
	private function seed_new_tools_notice(): void {
		aafm_bridge_review_sync();
		$this->notify_on();
		$this->register_foreign_abilities(
			'demo',
			array(
				'one' => array(),
				'two' => array(),
			)
		);
		aafm_bridge_review_sync();
		$this->assertSame( 1, $this->announcements(), 'The notice is there to clear.' );
	}

	public function test_marking_reviewed_clears_the_pending_notice(): void {
		// The suite detaches the write-outcome observer; attach it, with its table, as a real request has it.
		aafm_install_activity_log();
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$this->seed_new_tools_notice();

		$this->assertTrue( aafm_bridge_mark_reviewed( array( 'demo/one', 'demo/two' ) ) );

		$this->assertSame( 0, $this->announcements() );
		$this->assertFalse( aafm_option_row( 'aafm_notify_pending' )['found'], 'With nothing else pending, the row is gone.' );
		$outcomes = wp_list_pluck( aafm_query_activity( array( 'ability' => 'aafm/write-outcome' ) ), 'detail' );
		$this->assertNotSame( array(), $outcomes, 'Precondition: the review-state write is logged, so the observer is attached.' );
		foreach ( $outcomes as $detail ) {
			$this->assertStringNotContainsString( 'aafm_notify_pending', (string) $detail, 'Clearing the notice is not logged as an agent write.' );
		}
	}

	public function test_partial_review_keeps_the_notice(): void {
		$this->seed_new_tools_notice();
		$before = aafm_notify_pending_list();

		$this->assertTrue( aafm_bridge_mark_reviewed( array( 'demo/one' ) ) );

		$this->assertSame( $before, aafm_notify_pending_list() );
	}

	public function test_clearing_new_tools_keeps_other_pending_alerts(): void {
		aafm_bridge_review_sync();
		update_option(
			'aafm_notifications',
			array(
				'mode'     => 'notice',
				'email'    => '',
				'triggers' => array(
					'new_tools'   => true,
					'switch_flip' => true,
				),
			)
		);
		delete_option( 'aafm_notify_pending' );
		aafm_notify_event( 'switch_flip', 'read_only_on' );
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );
		aafm_bridge_review_sync();
		$this->assertCount( 2, aafm_notify_pending_list() );

		$this->assertTrue( aafm_bridge_mark_reviewed( array( 'demo/one' ) ) );

		$left = aafm_notify_pending_list();
		$this->assertCount( 1, $left );
		$this->assertSame( 'switch_flip', $left[0]['trigger'] );
		$this->assertSame( array( 'read_only_on' ), wp_list_pluck( aafm_option_row( 'aafm_notify_pending' )['value'], 'subtype' ), 'The database row keeps the switch flip.' );
	}

	public function test_two_batches_within_throttle_window_are_both_counted_in_email(): void {
		aafm_bridge_review_sync();
		update_option(
			'aafm_notifications',
			array(
				'mode'     => 'digest',
				'email'    => 'owner@example.com',
				'triggers' => array( 'new_tools' => true ),
			)
		);
		delete_option( 'aafm_notify_pending' );
		delete_option( 'aafm_notify_queue' );
		$this->register_foreign_abilities( 'demo', array( 'one' => array() ) );
		aafm_bridge_review_sync();
		$this->register_foreign_abilities(
			'other',
			array(
				'two'   => array(),
				'three' => array(),
			)
		);
		aafm_bridge_review_sync();

		$message = aafm_notify_build_message( aafm_notify_queue_list(), true );
		$this->assertStringContainsString(
			aafm_notify_event_label(
				array(
					'trigger' => 'new_tools',
					'subtype' => 'detected',
				)
			) . ': 3',
			$message['body']
		);
	}

	public function test_a_stale_cache_cannot_make_the_notice_clear_drop_another_alert(): void {
		$flip  = array(
			'id'      => 'flip',
			'trigger' => 'switch_flip',
			'subtype' => 'read_only_on',
			'time'    => time(),
		);
		$tools = array(
			'id'      => 'tools',
			'trigger' => 'new_tools',
			'subtype' => 'detected',
			'time'    => time(),
			'count'   => 1,
		);
		$this->assertTrue( aafm_update_option_verified( 'aafm_notify_pending', array( $tools, $flip ), false ) );
		// A cache copy that missed the switch flip, in both places core could read it.
		wp_cache_set( 'aafm_notify_pending', array( $tools ), 'options' );
		$all                        = wp_load_alloptions( true );
		$all['aafm_notify_pending'] = maybe_serialize( array( $tools ) );
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertSame( array( $tools ), aafm_notify_pending_list(), 'Precondition: the stale copy is what get_option() sees.' );

		$this->assertTrue( aafm_notify_pending_forget( 'new_tools' ) );

		$row = aafm_option_row( 'aafm_notify_pending' );
		$this->assertTrue( $row['found'], 'The row with the switch flip was not deleted.' );
		$this->assertSame( array( $flip ), $row['value'] );
		$this->assertSame( array( $flip ), aafm_notify_pending_list(), 'The cache now agrees with the row.' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	public function test_the_notice_clear_refuses_an_unreadable_row(): void {
		$this->assertTrue( aafm_update_option_verified( 'aafm_notify_pending', array( array( 'trigger' => 'new_tools' ) ), false ) );

		$result = QueryFaultInjector::fail_query( 'aafm_notify_pending', static fn() => aafm_notify_pending_forget( 'new_tools' ) );

		$this->assertFalse( $result );
		$this->assertTrue( aafm_option_row( 'aafm_notify_pending' )['found'], 'Nothing was deleted.' );
	}
}
