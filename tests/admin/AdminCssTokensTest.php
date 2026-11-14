<?php
/**
 * Source scan of admin.css for the admin colour scheme rule (1.11.3 SB-20b): the plugin accent
 * follows seven chosen schemes, fresh and modern keep the plugin blue, accent text uses its own
 * token, and the token blocks gain no new colour value.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AdminCssTokensTest extends TestCase {

	private const SCHEMES = array( 'blue', 'coffee', 'ectoplasm', 'light', 'midnight', 'ocean', 'sunrise' );

	/**
	 * Hex colours in the two token blocks (`:root` and the `.aafm-wrap` custom properties) as they
	 * stood at b16dcc96, before the scheme rule existed.
	 */
	private const TOKEN_HEX_AT_BASE = array( '#00a32a', '#007017', '#0a4b78', '#135e96', '#1d2327', '#2271b1', '#50575e', '#646970', '#815f00', '#b32d2e', '#c3c4c7', '#d63638', '#dba617', '#dcdcde', '#edfaef', '#f0f6fc', '#f6f7f7', '#fcf0f1', '#fcf9e8', '#fff' );

	/**
	 * Selectors that may still set a text colour from the raw accent, each with its reason. Empty:
	 * every accent text and icon colour reads --aafm-accent-text.
	 *
	 * @var array<string,string>
	 */
	private const RAW_ACCENT_TEXT_ALLOWED = array();

	private static function css(): string {
		return (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
	}

	private static function squash( string $css ): string {
		return (string) preg_replace( '/\s+/', '', $css );
	}

	/**
	 * The body of the first rule whose selector starts with $selector.
	 *
	 * @param string $css      Stylesheet.
	 * @param string $selector Selector at the start of a line.
	 */
	private static function block( string $css, string $selector ): string {
		$start = strpos( $css, "\n" . $selector . ' {' );
		if ( false === $start ) {
			return '';
		}
		$open  = strpos( $css, '{', $start );
		$close = strpos( $css, '}', (int) $open );
		return substr( $css, (int) $open + 1, (int) $close - (int) $open - 1 );
	}

	/**
	 * The body of the `.aafm-wrap {` block up to its first nested rule (its custom properties).
	 *
	 * @param string $css Stylesheet.
	 */
	private static function wrap_tokens( string $css ): string {
		$start = (int) strpos( $css, "\n.aafm-wrap {" ) + 13;
		$cut   = strpos( $css, '{', $start );
		return substr( $css, $start, (int) $cut - $start );
	}

	public function test_scheme_rule_names_exactly_the_seven_chosen_schemes(): void {
		preg_match_all( '/admin-color-([a-z]+)/', self::css(), $m );
		$found = array_values( array_unique( $m[1] ) );
		sort( $found );

		$this->assertSame( self::SCHEMES, $found );
		$this->assertStringNotContainsString( 'admin-color-fresh', self::css() );
		$this->assertStringNotContainsString( 'admin-color-modern', self::css() );
	}

	public function test_accent_text_and_accent_active_tokens_are_defined(): void {
		$root = self::squash( self::block( self::css(), ':root' ) );

		$this->assertStringContainsString( '--aafm-accent-text:var(--aafm-accent);', $root );
		$this->assertStringContainsString( '--aafm-accent-active:#0a4b78;', $root );
		$this->assertStringNotContainsString( '#0a4b78', self::squash( self::wrap_tokens( self::css() ) ) );
	}

	public function test_scheme_rule_reads_core_variables_with_todays_fallbacks(): void {
		$rule = self::squash( self::block( self::css(), 'body:is(' . implode( ', ', array_map( static fn( string $s ): string => '.admin-color-' . $s, array( 'light', 'blue', 'coffee', 'ectoplasm', 'midnight', 'ocean', 'sunrise' ) ) ) . ')' ) );

		$this->assertNotSame( '', $rule, 'The scheme rule exists on body.' );
		$this->assertStringContainsString( '--aafm-accent:var(--wp-admin-theme-color,#2271b1);', $rule );
		$this->assertStringContainsString( '--aafm-accent-hover:var(--wp-admin-theme-color-darker-10,#135e96);', $rule );
		$this->assertStringContainsString( '--aafm-accent-tint:rgba(var(--wp-admin-theme-color--rgb,34,113,177),0.08);', $rule );
		$this->assertStringContainsString( '--aafm-accent-text:var(--wp-admin-theme-color-darker-10,#135e96);', $rule );
		$this->assertStringContainsString( '--aafm-accent-active:var(--wp-admin-theme-color-darker-20,#0a4b78);', $rule );
	}

	public function test_no_text_colour_uses_the_raw_accent_outside_the_allowlist(): void {
		$offenders = array();
		foreach ( explode( "\n", self::css() ) as $number => $line ) {
			if ( 1 !== preg_match( '/(?<![\w-])color:\s*var\(--aafm-accent\)/', $line ) ) {
				continue;
			}
			$allowed = false;
			foreach ( array_keys( self::RAW_ACCENT_TEXT_ALLOWED ) as $selector ) {
				$allowed = $allowed || false !== strpos( $line, $selector );
			}
			if ( ! $allowed ) {
				$offenders[] = ( $number + 1 ) . ': ' . trim( $line );
			}
		}

		$this->assertSame( array(), $offenders, "Accent text must use --aafm-accent-text:\n" . implode( "\n", $offenders ) );
	}

	public function test_token_blocks_add_no_new_hex(): void {
		$css = self::css();
		preg_match_all( '/#[0-9a-f]{3,6}\b/i', self::block( $css, ':root' ) . self::wrap_tokens( $css ), $m );
		$found = array_values( array_unique( array_map( 'strtolower', $m[0] ) ) );
		sort( $found );
		$base = self::TOKEN_HEX_AT_BASE;
		sort( $base );

		$this->assertSame( $base, $found );
	}
}
