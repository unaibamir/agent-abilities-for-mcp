<?php
/**
 * Static guards for the starter and review handlers in admin.js.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

/**
 * This repo has no JS runner, so these scan the handlers the way DirtyBaselineScriptTest does.
 */
final class BridgeStarterScriptTest extends TestCase {

	/**
	 * One method of admin.js, from its definition to the next method.
	 *
	 * @param string $token Method definition token.
	 * @return string
	 */
	private function method( string $token ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test.
		$js    = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		$start = strpos( $js, $token );
		$this->assertNotFalse( $start, "$token not found in admin.js." );
		$next = preg_match( '/\n\t\t(?:async )?#\w+\(.*\) \{/', $js, $m, PREG_OFFSET_CAPTURE, (int) $start + strlen( $token ) );
		return substr( $js, (int) $start, 1 === $next ? (int) $m[0][1] - (int) $start : null );
	}

	public function test_the_starter_handler_confirms_before_it_posts_on_every_path(): void {
		$body = $this->method( '#bindBridgeStarter() {' );

		$post    = strpos( $body, "this.#post( 'aafm_bridge_read_only_starter'" );
		$noop    = strpos( $body, '0 === on && 0 === off' );
		$confirm = strpos( $body, 'window.confirm(' );

		$this->assertNotFalse( $post );
		$this->assertNotFalse( $noop );
		$this->assertNotFalse( $confirm );
		$this->assertLessThan( $confirm, $noop, 'The only path without a confirm is the no-op, which returns.' );
		$this->assertLessThan( $post, $confirm );
		$this->assertMatchesRegularExpression( '/if \( 0 === on && 0 === off \) \{[^}]*return;\s*\}/', $body );
		$this->assertMatchesRegularExpression( '/if \( ! window\.confirm\([^;]*\) \{\s*return;\s*\}/', $body, 'Cancel posts nothing.' );
		$this->assertSame( 1, substr_count( $body, 'this.#post(' ) );
	}

	public function test_the_confirm_names_both_counts_and_the_enable_only_case_has_its_own_string(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
		$page = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/page.php' );
		foreach ( array( 'bridgeStarterOn', 'bridgeStarterOnOne', 'bridgeStarterAlsoOff', 'bridgeStarterAlsoOffOne', 'bridgeStarterOffOnly', 'bridgeStarterOffOnlyOne', 'bridgeStarterNoop' ) as $key ) {
			$this->assertStringContainsString( "'$key'", $page, "$key is not in the i18n bag." );
			$this->assertStringContainsString( "'$key'", $this->method( '#bridgeStarterConfirm( on, off, name ) {' ) . $this->method( '#bindBridgeStarter() {' ), "$key is not used." );
		}
	}

	public function test_the_starter_marks_only_its_group_clean(): void {
		$body = $this->method( '#bindBridgeStarter() {' );

		$this->assertStringContainsString( 'this.#markClean( groupCard )', $body );
		$this->assertDoesNotMatchRegularExpression( '/this\.#markClean\( (?!groupCard )/', $body );
		$this->assertLessThan(
			strpos( $body, 'this.#markClean( groupCard )' ),
			strpos( $body, '.checked =' ),
			'The controls take the server result first, so the baseline records it.'
		);
	}

	public function test_review_actions_never_focus_a_detached_button(): void {
		foreach ( array( '#bindBridgeStarter() {', '#bindBridgeReview() {' ) as $token ) {
			$body = $this->method( $token );
			$this->assertStringContainsString( 'this.#focusAfterReview( btn, card )', $body, $token );
			$this->assertStringNotContainsString( 'btn.focus(', $body, $token );
		}

		$helper = $this->method( '#focusAfterReview( btn, card ) {' );
		$this->assertStringContainsString( 'btn.isConnected', $helper );
		$this->assertStringContainsString( '.aafm-bridge-review-block button', $helper );
		$this->assertStringContainsString( "querySelector( 'h2' )", $helper );
		$this->assertStringContainsString( "'tabindex', '-1'", $helper );
	}

	public function test_the_handlers_build_no_html(): void {
		foreach ( array( '#bindBridgeStarter() {', '#bindBridgeReview() {', '#focusAfterReview( btn, card ) {', '#bridgeReviewBlockDone( key, card ) {' ) as $token ) {
			$this->assertStringNotContainsString( 'innerHTML', $this->method( $token ), $token );
		}
	}

	public function test_both_are_bound_next_to_the_bridge_bulk_handler(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		$this->assertMatchesRegularExpression( '/this\.#bindBridgeBulk\(\);\s*this\.#bindBridgeReview\(\);\s*this\.#bindBridgeStarter\(\);/', $js );
	}
}
