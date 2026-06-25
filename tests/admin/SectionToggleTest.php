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
}
