<?php
/**
 * Abilities tab save logic: sanitize, intersect with registry, default-off.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AbilitiesSaveTest extends TestCase {

	public function test_sanitize_keeps_only_known_abilities(): void {
		$posted  = array( 'aafm_abilities' => array( 'aafm/get-posts', 'aafm/ghost', '<script>' ) );
		$enabled = aafm_sanitize_enabled_input( $posted );
		$this->assertSame( array( 'aafm/get-posts' ), $enabled );
	}

	public function test_empty_post_disables_everything(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		$enabled = aafm_sanitize_enabled_input( array() );
		$this->assertSame( array(), $enabled );
	}

	public function test_unknown_keys_alone_disable_everything(): void {
		// A payload of only unknown keys must never enable anything (no fall-through).
		$enabled = aafm_sanitize_enabled_input( array( 'aafm_abilities' => array( 'aafm/ghost', 'option_update' ) ) );
		$this->assertSame( array(), $enabled );
	}

	public function test_menu_is_registered_for_admins(): void {
		$this->acting_as( 'administrator' );
		aafm_register_admin_menu();
		global $submenu;
		$slugs = array();
		foreach ( (array) ( $submenu['options-general.php'] ?? array() ) as $item ) {
			$slugs[] = $item[2];
		}
		$this->assertContains( 'agent-abilities-for-mcp', $slugs );
	}

	public function test_abilities_tab_renders_checkboxes_for_registry_entries(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );

		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		// The enabled ability is present and checked; the value attribute is escaped.
		$this->assertStringContainsString( 'value="aafm/get-posts"', $html );
		$this->assertStringContainsString( 'name="aafm_abilities[]"', $html );
		$this->assertStringContainsString( 'checked', $html );
	}
}
