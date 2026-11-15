<?php
/**
 * The per-plugin read-only starter and Mark as reviewed, server side.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\Support\ForeignAbilities;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class BridgeStarterTest extends TestCase {

	use AjaxHarness;
	use ForeignAbilities;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_bridge_review' );
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->acting_as( 'administrator' );
		$this->register_foreign_abilities(
			'plugina',
			array(
				'read-one'  => array( 'readonly' => true ),
				'read-two'  => array( 'readonly' => true ),
				'write-one' => array( 'destructive' => false ),
				'delete'    => array( 'destructive' => true ),
				'unlabeled' => array(),
			)
		);
		$this->register_foreign_abilities(
			'pluginb',
			array(
				'b-read'   => array( 'readonly' => true ),
				'b-delete' => array( 'destructive' => true ),
			)
		);
		aafm_bridge_review_sync();
	}

	public function tear_down(): void {
		$this->unregister_foreign_abilities();
		remove_all_filters( 'pre_update_option_aafm_enabled_bridged_abilities' );
		remove_all_filters( 'pre_update_option_aafm_bridge_review' );
		$this->reset_ajax_harness();
		wp_cache_delete( 'alloptions', 'options' );
		parent::tear_down();
	}

	/**
	 * The stored allow-list row.
	 *
	 * @return array<int,string>
	 */
	private function stored(): array {
		$row = aafm_option_row( 'aafm_enabled_bridged_abilities' );
		return $row['found'] ? $row['value'] : array();
	}

	/**
	 * Slugs in the toggle rows of one event type.
	 *
	 * @param string $event ability_enabled or ability_disabled.
	 * @return array<int,string>
	 */
	private function toggled( string $event ): array {
		return array_values( wp_list_pluck( wp_list_filter( aafm_query_activity( array() ), array( 'event_type' => $event ) ), 'ability' ) );
	}

	/**
	 * Run a handler with a valid nonce.
	 *
	 * @param callable               $handler AJAX callback.
	 * @param string                 $plugin  Group key.
	 * @param array<int,string>|null $slugs   The shown slugs to post; null posts what is registered now.
	 * @return array<string,mixed>
	 */
	private function post( callable $handler, string $plugin, ?array $slugs = null ): array {
		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		$_POST['plugin']   = $plugin;
		$_POST['slugs']    = $slugs ?? $this->shown( $plugin );
		return $this->run_handler( $handler );
	}

	/**
	 * The slugs a screen rendered now would show for a group key, or for every group on the all key.
	 *
	 * @param string $plugin Group key.
	 * @return array<int,string>
	 */
	private function shown( string $plugin ): array {
		if ( aafm_bridge_review_all_key() === $plugin ) {
			return aafm_bridge_foreign_slugs();
		}
		return aafm_bridge_group_slugs( aafm_bridge_foreign_slugs() )[ $plugin ] ?? array();
	}

	public function test_it_enables_only_the_groups_reads_and_turns_its_other_tools_off(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'plugina/delete', 'plugina/write-one' ) );

		$result = aafm_bridge_apply_read_only_starter( 'plugina', $this->shown( 'plugina' ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['reads'] );
		$this->assertTrue( $result['reviewed'] );
		$this->assertSame( array( 'plugina/read-one', 'plugina/read-two' ), $this->stored() );
		$this->assertSame( array( 'plugina/read-one', 'plugina/read-two' ), $result['enabled'] );
		$this->assertSame( array( 'plugina/delete', 'plugina/write-one' ), $result['disabled'] );
	}

	public function test_other_plugins_selections_are_untouched(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'pluginb/b-delete', 'plugina/delete' ) );

		aafm_bridge_apply_read_only_starter( 'plugina', $this->shown( 'plugina' ) );

		$this->assertContains( 'pluginb/b-delete', $this->stored() );
		$this->assertNotContains( 'pluginb/b-read', $this->stored(), 'The starter does not add to another plugin either.' );
	}

	public function test_it_uses_the_read_only_preset_classifier(): void {
		aafm_bridge_apply_read_only_starter( 'plugina', $this->shown( 'plugina' ) );

		$stored = $this->stored();
		foreach ( array( 'read-one', 'read-two', 'write-one', 'delete', 'unlabeled' ) as $tool ) {
			$slug = 'plugina/' . $tool;
			$this->assertSame( aafm_preset_permits( 'read_only', $slug ), in_array( $slug, $stored, true ), $slug );
		}
	}

	public function test_an_unlabelled_plugin_gets_zero_tools_and_a_plain_message(): void {
		$this->register_foreign_abilities( 'silent', array( 'do-thing' => array() ) );
		update_option( 'aafm_enabled_bridged_abilities', array( 'silent/do-thing' ) );

		$result = aafm_bridge_apply_read_only_starter( 'silent', $this->shown( 'silent' ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, $result['reads'] );
		$this->assertSame( array(), $this->stored() );
		$this->assertStringContainsString( 'labels none of its tools as read-only', $result['message'] );
	}

	public function test_it_refuses_without_the_nonce_or_manage_options(): void {
		foreach ( array( 'aafm_ajax_bridge_read_only_starter', 'aafm_ajax_bridge_mark_reviewed' ) as $handler ) {
			$this->intercept_die();
			$_POST['nonce']    = 'bad';
			$_REQUEST['nonce'] = 'bad';
			$_POST['plugin']   = 'plugina';
			$this->assertSame( array(), $this->run_handler( $handler ), $handler . ' with a bad nonce.' );
			$this->assertSame( array(), $this->stored() );
		}

		$this->acting_as( 'editor' );
		foreach ( array( 'aafm_ajax_bridge_read_only_starter', 'aafm_ajax_bridge_mark_reviewed' ) as $handler ) {
			$json = $this->post( $handler, 'plugina' );
			$this->assertFalse( $json['success'] ?? true, $handler );
			$this->assertSame( 'You are not allowed to do this.', $json['data']['message'] ?? '', $handler );
		}
		$this->assertSame( array(), $this->stored() );
	}

	public function test_an_unknown_group_key_is_refused(): void {
		foreach ( array( 'aafm_ajax_bridge_read_only_starter', 'aafm_ajax_bridge_mark_reviewed' ) as $handler ) {
			$json = $this->post( $handler, 'not-a-plugin' );
			$this->assertFalse( $json['success'] ?? true, $handler );
		}
		$this->assertFalse( aafm_option_row( 'aafm_enabled_bridged_abilities' )['found'] );
	}

	public function test_it_logs_one_toggle_row_per_change(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'plugina/delete', 'plugina/read-one' ) );

		aafm_bridge_apply_read_only_starter( 'plugina', $this->shown( 'plugina' ) );

		$this->assertSame( array( 'plugina/read-two' ), $this->toggled( 'ability_enabled' ), 'A tool already on writes no row.' );
		$this->assertSame( array( 'plugina/delete' ), $this->toggled( 'ability_disabled' ) );
	}

	public function test_a_persist_failure_returns_an_error_and_logs_no_success_rows(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'plugina/delete' ) );
		add_filter( 'pre_update_option_aafm_enabled_bridged_abilities', static fn( $value, $old ) => $old, 10, 2 );
		$this->register_foreign_abilities( 'plugina', array( 'read-new' => array( 'readonly' => true ) ) );

		$json = $this->post( 'aafm_ajax_bridge_read_only_starter', 'plugina' );

		$this->assertFalse( $json['success'] ?? true );
		$this->assertSame( array(), $this->toggled( 'ability_enabled' ) );
		$this->assertSame( array(), $this->toggled( 'ability_disabled' ) );
		$this->assertCount( 1, aafm_query_activity( array( 'ability' => 'aafm_enabled_bridged_abilities' ) ) );
		$this->assertContains( 'plugina/read-new', aafm_bridge_new_tools(), 'Nothing was marked reviewed.' );
	}

	public function test_it_marks_the_groups_new_tools_reviewed_but_not_other_groups(): void {
		$this->register_foreign_abilities( 'plugina', array( 'read-new' => array( 'readonly' => true ) ) );
		$this->register_foreign_abilities( 'pluginb', array( 'b-new' => array( 'readonly' => true ) ) );

		aafm_bridge_apply_read_only_starter( 'plugina', $this->shown( 'plugina' ) );

		$this->assertSame( array( 'pluginb/b-new' ), aafm_bridge_new_tools() );
	}

	public function test_a_plugin_whose_namespace_is_all_marks_only_its_own_tools(): void {
		$this->register_foreign_abilities( 'all', array( 'read-new' => array( 'readonly' => true ) ) );
		$this->register_foreign_abilities( 'pluginb', array( 'b-new' => array( 'readonly' => true ) ) );

		$json = $this->post( 'aafm_ajax_bridge_mark_reviewed', 'all' );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( array( 'pluginb/b-new' ), aafm_bridge_new_tools() );
	}

	public function test_the_reserved_key_is_one_core_cannot_register_as_a_namespace(): void {
		$this->assertDoesNotMatchRegularExpression( '/^[a-z0-9-]+$/', aafm_bridge_review_all_key() );
		$this->assertSame( aafm_bridge_review_all_key(), sanitize_text_field( aafm_bridge_review_all_key() ), 'It survives the handler.' );
	}

	public function test_mark_reviewed_all_clears_every_new_tool_and_enables_nothing(): void {
		$this->register_foreign_abilities( 'plugina', array( 'read-new' => array( 'readonly' => true ) ) );
		$this->register_foreign_abilities( 'pluginb', array( 'b-new' => array( 'readonly' => true ) ) );

		$json = $this->post( 'aafm_ajax_bridge_mark_reviewed', aafm_bridge_review_all_key() );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( 0, $json['data']['count'] );
		$this->assertSame( array(), aafm_bridge_new_tools() );
		$this->assertFalse( aafm_option_row( 'aafm_enabled_bridged_abilities' )['found'], 'The enable list was never written.' );
	}

	public function test_mark_reviewed_for_one_group_leaves_the_other(): void {
		$this->register_foreign_abilities( 'plugina', array( 'read-new' => array( 'readonly' => true ) ) );
		$this->register_foreign_abilities( 'pluginb', array( 'b-new' => array( 'readonly' => true ) ) );

		$json = $this->post( 'aafm_ajax_bridge_mark_reviewed', 'plugina' );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( array( 'pluginb/b-new' ), $json['data']['new'] );
	}

	public function test_the_starter_never_adds_a_tool_that_is_not_registered(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'plugina/ghost' ) );

		$result = aafm_bridge_apply_read_only_starter( 'plugina', $this->shown( 'plugina' ) );

		$this->assertNotContains( 'plugina/ghost', $this->stored() );
		$this->assertNotContains( 'plugina/ghost', $result['enabled'] );
	}

	public function test_a_stored_slug_of_the_plugin_that_is_not_registered_right_now_is_removed_and_stays_off_when_it_returns(): void {
		$this->unregister_foreign_ability( 'plugina/delete' );
		update_option( 'aafm_enabled_bridged_abilities', array( 'plugina/delete', 'plugina/read-one', 'pluginb/not-here-now' ) );

		aafm_bridge_apply_read_only_starter( 'plugina', $this->shown( 'plugina' ) );

		$this->assertNotContains( 'plugina/delete', $this->stored() );
		$this->assertContains( 'pluginb/not-here-now', $this->stored(), 'Another plugin\'s unregistered slug stays.' );

		$this->register_foreign_abilities( 'plugina', array( 'delete' => array( 'destructive' => true ) ) );
		$this->assertNotContains( 'plugina/delete', aafm_get_enabled_bridged_abilities() );
	}

	public function test_another_plugins_row_only_selection_survives_a_stale_cache(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'pluginb/b-delete', 'pluginb/b-read' ) );
		$stale = array( 'plugina/delete' );
		wp_cache_set( 'aafm_enabled_bridged_abilities', $stale, 'options' );
		$all                                   = wp_load_alloptions( true );
		$all['aafm_enabled_bridged_abilities'] = maybe_serialize( $stale );
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertSame( $stale, get_option( 'aafm_enabled_bridged_abilities' ), 'Precondition: the stale copy is what get_option() sees.' );

		$result = aafm_bridge_apply_read_only_starter( 'plugina', $this->shown( 'plugina' ) );

		$this->assertTrue( $result['ok'] );
		$this->assertContains( 'pluginb/b-delete', $this->stored() );
		$this->assertContains( 'pluginb/b-read', $this->stored() );
		$this->assertContains( 'plugina/read-one', $this->stored() );
	}

	public function test_the_starter_refuses_and_writes_nothing_when_the_row_cannot_be_read(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'plugina/delete' ) );

		$result = QueryFaultInjector::fail_query(
			'aafm_enabled_bridged_abilities',
			fn() => aafm_bridge_apply_read_only_starter( 'plugina', $this->shown( 'plugina' ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'plugina/delete' ), $this->stored() );
	}

	public function test_a_review_mark_that_fails_is_said_out_loud_and_the_selection_still_stands(): void {
		$this->register_foreign_abilities( 'plugina', array( 'read-new' => array( 'readonly' => true ) ) );
		add_filter( 'pre_update_option_aafm_bridge_review', static fn( $value, $old ) => $old, 10, 2 );

		$result = aafm_bridge_apply_read_only_starter( 'plugina', $this->shown( 'plugina' ) );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'could not be marked as reviewed', $result['message'] );
		$this->assertFalse( $result['reviewed'], 'The screen keeps the tools in the card.' );
		$this->assertContains( 'plugina/read-new', $this->stored() );
		$this->assertContains( 'plugina/read-new', aafm_bridge_new_tools() );
	}

	public function test_starter_rejects_slugs_from_another_group(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'plugina/delete' ) );

		$result = aafm_bridge_apply_read_only_starter( 'plugina', array( 'plugina/read-one', 'pluginb/b-read' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'plugina/delete' ), $this->stored(), 'Nothing was written.' );
		$json = $this->post( 'aafm_ajax_bridge_read_only_starter', 'plugina', array( 'pluginb/b-read' ) );
		$this->assertFalse( $json['success'] ?? true );
	}

	public function test_starter_acts_only_on_shown_slugs(): void {
		$shown = $this->shown( 'plugina' );
		$this->register_foreign_abilities( 'plugina', array( 'read-late' => array( 'readonly' => true ) ) );

		$json = $this->post( 'aafm_ajax_bridge_read_only_starter', 'plugina', $shown );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertEqualsCanonicalizing( array( 'plugina/read-one', 'plugina/read-two' ), $this->stored(), 'The read tool registered after the page rendered stays off.' );
	}

	public function test_mark_reviewed_acts_only_on_shown_slugs(): void {
		$this->register_foreign_abilities( 'plugina', array( 'seen' => array( 'readonly' => true ) ) );
		$shown = $this->shown( 'plugina' );
		$this->register_foreign_abilities( 'plugina', array( 'late' => array( 'readonly' => true ) ) );

		$json = $this->post( 'aafm_ajax_bridge_mark_reviewed', 'plugina', $shown );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( array( 'plugina/late' ), aafm_bridge_new_tools() );
		$this->assertFalse( aafm_option_row( 'aafm_enabled_bridged_abilities' )['found'], 'Marking reviewed enables nothing.' );
	}

	public function test_tool_added_after_render_stays_unreviewed(): void {
		$this->register_foreign_abilities( 'plugina', array( 'seen' => array( 'readonly' => true ) ) );
		$this->register_foreign_abilities( 'pluginb', array( 'b-seen' => array( 'readonly' => true ) ) );
		$all = $this->shown( aafm_bridge_review_all_key() );
		$this->register_foreign_abilities( 'pluginb', array( 'b-late' => array( 'readonly' => true ) ) );

		$this->post( 'aafm_ajax_bridge_mark_reviewed', aafm_bridge_review_all_key(), $all );
		$this->assertSame( array( 'pluginb/b-late' ), aafm_bridge_new_tools(), 'Mark all covers only what was shown.' );

		$shown = $this->shown( 'pluginb' );
		$this->register_foreign_abilities( 'pluginb', array( 'b-later' => array( 'readonly' => true ) ) );
		aafm_bridge_apply_read_only_starter( 'pluginb', $shown );
		$this->assertSame( array( 'pluginb/b-later' ), aafm_bridge_new_tools() );
		$this->assertNotContains( 'pluginb/b-later', $this->stored() );
	}

	public function test_unknown_posted_slug_is_ignored(): void {
		$json = $this->post( 'aafm_ajax_bridge_read_only_starter', 'plugina', array_merge( $this->shown( 'plugina' ), array( 'ghost/tool', 'plugina/ghost', '<b>x</b>' ) ) );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertEqualsCanonicalizing( array( 'plugina/read-one', 'plugina/read-two' ), $this->stored() );
		$this->assertNotContains( 'plugina/ghost', aafm_bridge_review_state()['reviewed'] );
		$this->assertNotContains( 'ghost/tool', aafm_bridge_review_state()['reviewed'] );
	}

	public function test_a_request_with_no_shown_slugs_changes_nothing(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'plugina/delete' ) );

		$this->assertFalse( $this->post( 'aafm_ajax_bridge_read_only_starter', 'plugina', array() )['success'] ?? true );
		$this->assertFalse( $this->post( 'aafm_ajax_bridge_mark_reviewed', 'plugina', array() )['success'] ?? true );
		$this->assertSame( array( 'plugina/delete' ), $this->stored() );
	}
}
