<?php
/**
 * Tests for the unsaved-changes dot markup in the tab bar.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

/**
 * Covers the nav tab links aafm_render_admin_page() prints.
 */
class DirtyDotMarkupTest extends TestCase {

	public function test_every_nav_tab_link_carries_its_slug_and_a_hidden_dot_that_survives_kses(): void {
		$this->acting_as( 'administrator' );
		ob_start();
		aafm_render_admin_page();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, preg_match_all( '#<nav class="nav-tab-wrapper">.*?</nav>#s', $html, $nav ) );
		$this->assertGreaterThan( 3, preg_match_all( '#<a [^>]*class="nav-tab[^"]*"[^>]*>(.*?)</a>#s', $nav[0][0], $links, PREG_SET_ORDER ) );
		foreach ( $links as $link ) {
			$this->assertMatchesRegularExpression( '#data-tab="[a-z-]+"#', $link[0] );
			$this->assertStringContainsString( '<span class="aafm-dirty-dot" hidden aria-hidden="true"></span>', $link[1] );
			$this->assertStringContainsString( '<span class="screen-reader-text aafm-dirty-sr" hidden>Unsaved changes</span>', $link[1] );
		}
		$this->assertStringContainsString( 'data-tab="settings"', $nav[0][0] );
	}
}
