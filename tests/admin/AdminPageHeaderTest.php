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

	/**
	 * The page as an administrator sees it.
	 *
	 * @return string
	 */
	private function page_html(): string {
		$this->acting_as( 'administrator' );
		ob_start();
		aafm_render_admin_page();
		return (string) ob_get_clean();
	}

	/**
	 * The bundled admin stylesheet.
	 *
	 * @return string
	 */
	private function admin_css(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test.
		return (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.css' );
	}

	/**
	 * The rule block that opens at $selector, up to its matching closing brace.
	 *
	 * @param string $css      Stylesheet.
	 * @param string $selector Text that opens the block, including its "{".
	 * @param int    $from     Offset to search from.
	 * @return string
	 */
	private function block( string $css, string $selector, int $from = 0 ): string {
		$at = strpos( $css, $selector, $from );
		$this->assertNotFalse( $at, $selector );
		$depth = 0;
		$len   = strlen( $css );
		for ( $i = (int) $at; $i < $len; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
			} elseif ( '}' === $css[ $i ] && 0 === --$depth ) {
				return substr( $css, (int) $at, $i - (int) $at + 1 );
			}
		}
		return '';
	}

	/**
	 * Option A (operator, 2026-11-13): the four links leave the tab row for the page head, beside
	 * the status pills, so the ten tabs get the whole row.
	 */
	public function test_external_links_render_inside_page_head(): void {
		$html = $this->page_html();
		$head = strpos( $html, '<div class="aafm-page-head">' );
		$end  = strpos( $html, '<hr class="wp-header-end">' );
		$this->assertNotFalse( $head );
		$this->assertNotFalse( $end );
		$head_html = substr( $html, (int) $head, (int) $end - (int) $head );

		$this->assertStringContainsString( 'aafm-page-head-pills', $head_html );
		$this->assertStringContainsString( 'class="aafm-nav-ext-group"', $head_html );
		$this->assertSame( 4, substr_count( $head_html, 'class="aafm-nav-ext"' ) );
		$this->assertLessThan( strpos( $head_html, 'aafm-nav-ext-group' ), strpos( $head_html, 'aafm-page-head-pills' ), 'The links sit after the pills.' );
	}

	public function test_nav_tab_wrapper_contains_only_tabs(): void {
		$html = $this->page_html();
		$this->assertSame( 1, preg_match( '#<nav class="nav-tab-wrapper">(.*?)</nav>#s', $html, $nav ) );
		$this->assertStringNotContainsString( 'aafm-nav-ext', $nav[1] );
		$this->assertSame( count( aafm_admin_tabs() ), preg_match_all( '#<a [^>]*class="nav-tab[ "]#', $nav[1] ) );
		$this->assertSame( count( aafm_admin_tabs() ), substr_count( $nav[1], '<a ' ), 'Nothing but the tab links.' );
	}

	public function test_nav_tabs_keep_data_tab_and_dirty_dot_markup(): void {
		$html = $this->page_html();
		$this->assertSame( 1, preg_match( '#<nav class="nav-tab-wrapper">(.*?)</nav>#s', $html, $nav ) );
		foreach ( array_keys( aafm_admin_tabs() ) as $slug ) {
			$this->assertMatchesRegularExpression( '#<a [^>]*class="nav-tab[^"]*" data-tab="' . $slug . '">#', $nav[1], $slug );
		}
		$this->assertSame( count( aafm_admin_tabs() ), substr_count( $nav[1], '<span class="aafm-dirty-dot" hidden aria-hidden="true"></span>' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		$this->assertStringContainsString( "document.querySelector( '.aafm-wrap .nav-tab-wrapper .nav-tab-active' )", $js, '#revealActiveNavTab() is unchanged.' );
		$this->assertStringContainsString( '.nav-tab[data-tab="${ slug }"] .aafm-dirty-dot', $js, 'The dirty-dot selector is unchanged.' );
	}

	public function test_external_link_css_is_scoped_to_page_head(): void {
		$css  = $this->admin_css();
		$head = $this->block( $css, '.aafm-page-head {' );
		$this->assertStringContainsString( '.aafm-nav-ext-group {', $head );
		$this->assertStringContainsString( '.aafm-nav-ext {', $head );

		$nav = $this->block( $css, '.nav-tab-wrapper {' );
		$this->assertStringNotContainsString( 'aafm-nav-ext', $nav );
		// The single scrolling row is the strip's own rule at every width, not a phone-only one.
		$this->assertStringContainsString( 'flex-wrap: nowrap;', $nav );
		$this->assertStringContainsString( 'overflow-x: auto;', $nav );
		$this->assertStringContainsString( 'overflow-y: hidden;', $nav );
		$this->assertStringNotContainsString( 'flex-wrap: wrap;', $nav );
	}

	public function test_phone_rule_targets_links_in_head(): void {
		$css   = $this->admin_css();
		$phone = $this->block( $css, '@media (max-width: 640px) {', (int) strpos( $css, '.aafm-set-row {' ) );
		$head  = $this->block( $phone, '.aafm-page-head {' );
		$this->assertStringContainsString( 'flex-direction: column;', $head );
		$this->assertStringContainsString( '.aafm-nav-ext-group', $head );
		$this->assertStringNotContainsString( '.nav-tab-wrapper', $phone, 'The strip needs no phone-only rule: it is one scrolling row at every width.' );
	}
}
