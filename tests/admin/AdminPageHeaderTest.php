<?php
/**
 * Admin page header: the right-aligned external-link group carries the demo-video link.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AdminPageHeaderTest extends TestCase {

	public function test_admin_header_links_the_demo_video(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();

		// Slice the ext-links group so the assertions are scoped to the fourth entry, the
		// same "find the group, check within it" idiom IconsTest.php uses for a nav-tab icon
		// (wp_kses may re-serialize the inline SVG, so this checks for the icon's distinctive
		// path data rather than a byte-identical aafm_icon() string).
		$group_start = strpos( $html, 'aafm-nav-ext-group' );
		$this->assertNotFalse( $group_start, 'The external-links group should render.' );
		$group = substr( $html, $group_start );

		$this->assertStringContainsString( 'https://www.youtube.com/watch?v=Raih7X4QgP0', $group );
		$this->assertStringContainsString( 'Watch the demo', $group );
		// Same shared class every aafm-nav-ext entry gets (no per-entry class was introduced).
		$this->assertStringContainsString( 'class="aafm-nav-ext"', $group );
		// The play icon's distinctive path resolves through aafm_icon(), not an empty glyph.
		$this->assertStringContainsString( 'M10 8.5v7l6-3.5-6-3.5Z', $group );
		$this->assertStringContainsString( 'target="_blank"', $group );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $group );
	}
}
