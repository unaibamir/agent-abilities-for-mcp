<?php
/**
 * Page shell: header lede, status pill, and inline-SVG-prefixed nav tabs.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ShellTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// Rendering the default (dashboard) tab queries the activity-log table, so install it
		// to keep the suite output clean.
		aafm_install_activity_log();
	}

	public function test_shell_has_lede_and_svg_tabs(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'aafm-page-lede', $html );
		// Tabs are now prefixed with inline SVGs, not Dashicons.
		$this->assertStringContainsString( 'aafm-icon', $html );
		$this->assertStringNotContainsString( 'dashicons', $html );
		// The Dashboard nav tab still renders and is the active one.
		$this->assertStringContainsString( 'tab=dashboard', $html );
		$this->assertStringContainsString( 'nav-tab-active', $html );          // Markup kept.
		$this->assertStringContainsString( 'aafm-status-pill', $html );
	}

	/**
	 * The right-aligned group carries three destinations: the plugin's own site, the review form,
	 * and the support forum. Website leads so the two wordpress.org links stay adjacent. All three
	 * open in a new tab and say so for a screen reader, which is the whole reason they share one
	 * loop rather than being hand-rolled per link.
	 */
	public function test_the_header_carries_the_website_link_beside_review_and_get_help(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'https://agentabilitieswp.com', $html, 'The website link is missing from the page header.' );
		$this->assertSame( 3, substr_count( $html, 'class="aafm-nav-ext"' ), 'The external-link group holds exactly Website, Review and Get Help.' );

		$website_at = strpos( $html, 'https://agentabilitieswp.com' );
		$review_at  = strpos( $html, '/reviews/#new-post' );
		$group_at   = strpos( $html, 'aafm-nav-ext-group' );
		$this->assertNotFalse( $review_at );
		$this->assertNotFalse( $group_at );
		$this->assertLessThan( $website_at, $group_at, 'The website link must render inside the external-link group.' );
		$this->assertLessThan( $review_at, $website_at, 'Website leads the group, keeping the two wordpress.org links together.' );

		// It goes through the shared loop, so it inherits the new-tab attributes and the
		// screen-reader note rather than being a bare anchor.
		$anchor_end = strpos( $html, '</a>', $website_at );
		$anchor     = substr( $html, (int) $website_at, ( false === $anchor_end ? 0 : $anchor_end - (int) $website_at ) );
		$this->assertStringContainsString( 'target="_blank"', $anchor );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $anchor );
		$this->assertStringContainsString( '(opens in a new tab)', $anchor );
		$this->assertStringContainsString( '<svg', $anchor, 'The website link should carry an icon like the other two.' );
	}
}
