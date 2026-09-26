<?php
/**
 * First-activation admin-menu pointer: the activation flag, the per-request show gate, and the
 * "opening the plugin page dismisses it" behaviour.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class QuickConnectPointerTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_menu_pointer_active' );
	}

	/**
	 * Activation flags a new install so the pointer is due once.
	 */
	public function test_flag_menu_pointer_sets_option(): void {
		aafm_quickconnect_flag_menu_pointer();
		$this->assertSame( '1', get_option( 'aafm_menu_pointer_active' ) );
	}

	/**
	 * The flag uses add_option, so a later reactivation never re-arms a pointer an install already
	 * turned off.
	 */
	public function test_flag_menu_pointer_does_not_clobber_existing_value(): void {
		add_option( 'aafm_menu_pointer_active', '0' );
		aafm_quickconnect_flag_menu_pointer();
		$this->assertSame( '0', get_option( 'aafm_menu_pointer_active' ) );
	}

	/**
	 * With the flag set, a capable admin on any admin screen other than the plugin's own page,
	 * who has not dismissed the pointer, should see it.
	 */
	public function test_pointer_shows_for_new_install_admin_on_other_screen(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_menu_pointer_active', '1' );
		$this->assertTrue( aafm_quickconnect_pointer_should_show( 'index.php' ) );
	}

	/**
	 * The gate is closed when the install is not flagged new.
	 */
	public function test_pointer_hidden_when_install_not_flagged(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'aafm_menu_pointer_active' );
		$this->assertFalse( aafm_quickconnect_pointer_should_show( 'index.php' ) );
	}

	/**
	 * The gate is closed for a user who cannot manage options.
	 */
	public function test_pointer_hidden_for_non_admin(): void {
		$this->acting_as( 'subscriber' );
		update_option( 'aafm_menu_pointer_active', '1' );
		$this->assertFalse( aafm_quickconnect_pointer_should_show( 'index.php' ) );
	}

	/**
	 * The gate is closed on the plugin's own page (opening it dismisses the pointer instead).
	 */
	public function test_pointer_hidden_on_plugin_page(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_menu_pointer_active', '1' );
		$this->assertFalse( aafm_quickconnect_pointer_should_show( 'toplevel_page_agent-abilities-for-mcp' ) );
	}

	/**
	 * Once the user has dismissed the pointer, the gate stays closed on every screen.
	 */
	public function test_pointer_hidden_after_dismissal(): void {
		$user_id = $this->acting_as( 'administrator' );
		update_option( 'aafm_menu_pointer_active', '1' );
		update_user_meta( $user_id, 'dismissed_wp_pointers', aafm_quickconnect_pointer_id() );
		$this->assertFalse( aafm_quickconnect_pointer_should_show( 'index.php' ) );
	}

	/**
	 * Opening the plugin page records the pointer as dismissed for this user, so it never nags
	 * afterwards even without an explicit click.
	 */
	public function test_visiting_plugin_page_marks_pointer_dismissed(): void {
		$user_id = $this->acting_as( 'administrator' );
		update_option( 'aafm_menu_pointer_active', '1' );

		aafm_maybe_enqueue_menu_pointer( 'toplevel_page_agent-abilities-for-mcp' );

		$this->assertTrue( aafm_quickconnect_pointer_dismissed_for_user() );
		$this->assertStringContainsString(
			aafm_quickconnect_pointer_id(),
			(string) get_user_meta( $user_id, 'dismissed_wp_pointers', true )
		);
	}

	/**
	 * A failed read of the dismissal list writes nothing, so the pointers this user already
	 * dismissed stay dismissed.
	 */
	public function test_a_failed_dismissal_read_leaves_other_dismissals_intact(): void {
		$user_id = $this->acting_as( 'administrator' );
		update_user_meta( $user_id, 'dismissed_wp_pointers', 'wp390_widgets,theme_editor_notice' );
		wp_cache_delete( $user_id, 'user_meta' );
		QueryFaultInjector::reset_fired_count();

		QueryFaultInjector::break_query_with_real_error(
			array( 'user_id, meta_key, meta_value FROM', 'usermeta' ),
			static function (): void {
				aafm_quickconnect_mark_pointer_dismissed_for_user();
			},
			1
		);
		wp_cache_delete( $user_id, 'user_meta' );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertSame( 'wp390_widgets,theme_editor_notice', (string) get_user_meta( $user_id, 'dismissed_wp_pointers', true ) );
	}

	/**
	 * With no stored list yet, the dismissal writes the pointer id alone.
	 */
	public function test_dismissal_with_no_stored_list_writes_the_pointer_id(): void {
		$user_id = $this->acting_as( 'administrator' );
		delete_user_meta( $user_id, 'dismissed_wp_pointers' );

		aafm_quickconnect_mark_pointer_dismissed_for_user();

		$this->assertSame( aafm_quickconnect_pointer_id(), (string) get_user_meta( $user_id, 'dismissed_wp_pointers', true ) );
	}

	/**
	 * The dismissal appends to the stored list and keeps every earlier entry.
	 */
	public function test_dismissal_appends_to_the_stored_list(): void {
		$user_id = $this->acting_as( 'administrator' );
		update_user_meta( $user_id, 'dismissed_wp_pointers', 'wp390_widgets' );

		aafm_quickconnect_mark_pointer_dismissed_for_user();

		$this->assertSame( 'wp390_widgets,' . aafm_quickconnect_pointer_id(), (string) get_user_meta( $user_id, 'dismissed_wp_pointers', true ) );
	}
}
