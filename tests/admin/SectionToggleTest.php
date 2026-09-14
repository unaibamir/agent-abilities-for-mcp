<?php
/**
 * Per-section enable/disable-all control on the Abilities tab: render and localization.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class SectionToggleTest extends TestCase {

	public function test_each_subject_panel_has_a_toggle_all_control(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aafm-section-toggle-all', $html );
		$this->assertStringContainsString( 'data-subject="content"', $html );
		$this->assertStringContainsString( 'data-has-destructive="1"', $html );
	}

	public function test_section_toggle_confirm_string_is_localized(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		aafm_enqueue_admin_assets( 'toplevel_page_agent-abilities-for-mcp' );
		$data = wp_scripts()->get_data( 'aafm-admin', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'sectionToggleConfirm', $data );
	}

	/**
	 * Codex admin-ui-r1 M3: the Abilities search field's only text was placeholder + status live
	 * region text, neither of which is a persistent accessible name for a screen reader or voice
	 * control user. A visually-hidden <label for> pointing at the field is the fix.
	 */
	public function test_the_abilities_search_field_has_a_persistent_label(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<label class="screen-reader-text" for="aafm-abilities-search">[^<]+<\/label>\s*<input type="search" id="aafm-abilities-search"/',
			$html
		);
	}

	/**
	 * Codex admin-ui-r1 L3: a client-side "Add scope" row built its "Role:"/"Connection:" label
	 * from a hardcoded English template literal in admin.js instead of a localized string, so it
	 * showed English on a localized site until reload. This pins the two i18n keys admin.js now
	 * localizes through - it does not exercise the JS itself (see AllowlistAdminTest for the
	 * server-rendered side of the same row shape).
	 */
	public function test_the_allowlist_scope_row_labels_are_localized(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		aafm_enqueue_admin_assets( 'toplevel_page_agent-abilities-for-mcp' );
		$data = wp_scripts()->get_data( 'aafm-admin', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'allowlistRoleLabel', $data );
		$this->assertStringContainsString( 'allowlistConnectionLabel', $data );
	}
}
