<?php
/**
 * Admin nav-tab icon coverage.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;

final class IconsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// oversio_render_admin_page() defaults to the dashboard tab, which counts activity-log
		// rows; install the table so the render is clean rather than emitting a DB notice.
		oversio_install_activity_log();
		oversio_clear_activity_log();
	}

	public function test_integrations_icon_is_a_non_empty_svg(): void {
		$svg = oversio_icon( 'integrations' );
		$this->assertNotSame( '', $svg, 'The integrations icon must not be empty.' );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 24 24"', $svg );
	}

	public function test_integrations_nav_tab_renders_an_icon(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		oversio_render_admin_page();
		$html = (string) ob_get_clean();

		// The Integrations nav-tab anchor must carry an inline <svg> like the other tabs.
		$tab_pos = strpos( $html, 'tab=integrations' );
		$this->assertNotFalse( $tab_pos, 'Integrations nav tab should render.' );
		// The anchor opens before the slug query arg and holds the icon; check the icon sits
		// within the rendered anchor by slicing a window around the integrations link.
		$anchor_start = strrpos( substr( $html, 0, $tab_pos ), '<a ' );
		$this->assertNotFalse( $anchor_start, 'Integrations anchor open tag should render.' );
		$anchor_end = strpos( $html, '</a>', $tab_pos );
		$anchor     = substr( $html, $anchor_start, ( false === $anchor_end ? 0 : $anchor_end - $anchor_start ) );
		$this->assertStringContainsString( '<svg', $anchor, 'Integrations nav tab should contain an SVG icon.' );
	}
}
