<?php
/**
 * Active-tab submenu highlighting: the submenu_file filter returns the tab-aware slug.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class SubmenuHighlightTest extends TestCase {

	/**
	 * Reset the request superglobals after each case so tab state never leaks.
	 */
	public function tear_down(): void {
		unset( $_GET['page'], $_GET['tab'] );
		parent::tear_down();
	}

	public function test_dashboard_tab_highlights_the_parent_slug(): void {
		$_GET['page'] = 'oversio-agent-abilities';
		$_GET['tab']  = 'dashboard';
		$this->assertSame( 'oversio-agent-abilities', aafm_highlight_tab_submenu( 'oversio-agent-abilities' ) );
	}

	public function test_missing_tab_highlights_the_parent_slug(): void {
		$_GET['page'] = 'oversio-agent-abilities';
		unset( $_GET['tab'] );
		$this->assertSame( 'oversio-agent-abilities', aafm_highlight_tab_submenu( 'oversio-agent-abilities' ) );
	}

	public function test_known_tab_highlights_its_tab_slug(): void {
		$_GET['page'] = 'oversio-agent-abilities';
		$_GET['tab']  = 'abilities';
		$this->assertSame( 'oversio-agent-abilities&tab=abilities', aafm_highlight_tab_submenu( 'oversio-agent-abilities' ) );
	}

	public function test_unknown_tab_falls_back_to_the_parent_slug(): void {
		$_GET['page'] = 'oversio-agent-abilities';
		$_GET['tab']  = 'bogus';
		$this->assertSame( 'oversio-agent-abilities', aafm_highlight_tab_submenu( 'oversio-agent-abilities' ) );
	}

	public function test_other_page_returns_the_input_unchanged(): void {
		$_GET['page'] = 'edit.php';
		$_GET['tab']  = 'abilities';
		$this->assertSame( 'some-other-file.php', aafm_highlight_tab_submenu( 'some-other-file.php' ) );
	}
}
