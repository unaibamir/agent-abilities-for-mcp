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
		return 1 === $next ? substr( $js, (int) $start, (int) $m[0][1] - (int) $start ) : substr( $js, (int) $start );
	}

	public function test_the_starter_handler_confirms_before_it_posts_on_every_path(): void {
		$body = $this->method( '#bindBridgeStarter() {' );

		$post    = strpos( $body, "this.#post( 'aafm_bridge_read_only_starter'" );
		$noop    = strpos( $body, '0 === on && 0 === off && 0 === removed' );
		$confirm = strpos( $body, 'window.confirm(' );

		$this->assertNotFalse( $post );
		$this->assertNotFalse( $noop );
		$this->assertNotFalse( $confirm );
		$this->assertLessThan( $confirm, $noop, 'The only path without a confirm is the no-op, which returns.' );
		$this->assertLessThan( $post, $confirm );
		$this->assertMatchesRegularExpression( '/if \( 0 === on && 0 === off && 0 === removed \) \{.*?return;\s*\}/s', $body );
		$this->assertMatchesRegularExpression( '/if \( ! window\.confirm\([^;]*\) \{\s*return;\s*\}/', $body, 'Cancel posts nothing.' );
		$this->assertSame( 1, substr_count( $body, 'this.#post(' ) );
	}

	public function test_the_confirm_names_both_counts_and_the_enable_only_case_has_its_own_string(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
		$page = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/page.php' );
		foreach ( array( 'bridgeStarterOn', 'bridgeStarterOnOne', 'bridgeStarterAlsoOff', 'bridgeStarterAlsoOffOne', 'bridgeStarterOffOnly', 'bridgeStarterOffOnlyOne', 'bridgeStarterNoop', 'bridgeStarterNoopNoReads', 'bridgeStarterRemoved', 'bridgeStarterRemovedOne', 'bridgeStarterAlsoRemoved', 'bridgeStarterOneRemoved', 'bridgeStarterRemovedLine', 'bridgeStarterReload' ) as $key ) {
			$this->assertStringContainsString( "'$key'", $page, "$key is not in the i18n bag." );
			$this->assertStringContainsString( "'$key'", $this->method( '#bridgeStarterConfirm( on, off, removed, name ) {' ) . $this->method( '#bindBridgeStarter() {' ), "$key is not used." );
		}
	}

	public function test_the_starter_turns_off_writes_for_a_plugin_with_no_read_tools(): void {
		$body    = $this->method( '#bindBridgeStarter() {' );
		$confirm = $this->method( '#bridgeStarterConfirm( on, off, removed, name ) {' );

		$this->assertDoesNotMatchRegularExpression( '/if \( 0 === on \) \{[^}]*return;/', $body, 'A plugin with nothing to turn on still posts when it has something to turn off.' );
		$this->assertStringContainsString( 'if ( 0 === on ) {', $confirm );
		$this->assertStringContainsString( "'bridgeStarterOffOnly'", $confirm );
	}

	public function test_a_no_read_plugin_never_hears_it_already_allows_only_its_read_tools(): void {
		$body = $this->method( '#bindBridgeStarter() {' );

		$this->assertStringContainsString( '.aafm-ability-row[data-risk="read"]', $body );
		$this->assertStringContainsString( "'bridgeStarterNoopNoReads'", $body );
	}

	public function test_stored_tools_that_are_not_on_count_as_removals_never_as_turned_off(): void {
		$counts = $this->method( '#bridgeStarterCounts( groupCard ) {' );

		$this->assertMatchesRegularExpression( '/removed:\s*groupCard\.querySelectorAll\( \'\[data-aafm-stored-on\]\' \)\.length \+\s*Number\( groupCard\.dataset\.bridgeUnavailable \?\? 0 \)/', $counts );
		$off = substr( $counts, (int) strpos( $counts, 'off:' ), (int) strpos( $counts, 'removed:' ) - (int) strpos( $counts, 'off:' ) );
		$this->assertStringNotContainsString( 'data-aafm-stored-on', $off );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
		$page = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/page.php' );
		$this->assertMatchesRegularExpression( "/'bridgeStarterRemoved'\s+=> __\( '[^']*removes %1\\\$s saved selections/", $page );
		$this->assertDoesNotMatchRegularExpression( "/'bridgeStarter(Also)?Removed(One)?'\s+=> __\( '[^']*(that (is|are) on now|turns off)/", $page );
	}

	public function test_a_finished_starter_forgets_the_stored_tools_so_a_second_click_is_a_no_op(): void {
		$body = $this->method( '#bindBridgeStarter() {' );

		$this->assertStringContainsString( 'delete groupCard.dataset.bridgeUnavailable', $body );
		$this->assertStringContainsString( "'bridgeStarterReload'", $body );
	}

	public function test_a_failed_review_write_keeps_the_block(): void {
		$body = $this->method( '#bindBridgeStarter() {' );

		$this->assertMatchesRegularExpression( '/const left = card && reviewed \? this\.#bridgeReviewBlockDone\( key, card \) : \'\';/', $body );
	}

	public function test_the_script_uses_the_reserved_key_the_server_takes(): void {
		$js = $this->method( '#bindBridgeReview() {' ) . $this->method( '#bridgeReviewBlockDone( key, card ) {' );

		// The success message, the posted slugs and the block bookkeeping each compare against it.
		$this->assertSame( 3, substr_count( $js, "'" . aafm_bridge_review_all_key() . "'" ) );
		$this->assertStringNotContainsString( "'all'", $js );
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

	public function test_enable_all_skips_a_held_row(): void {
		$body = $this->method( '#bindBridgeBulk() {' );
		$this->assertMatchesRegularExpression( "/if \\( enable && row\\?\\.hasAttribute\\( 'data-high-risk' \\) \\) \\{\\s*return;\\s*\\}/", $body );
		$this->assertLessThan( (int) strpos( $body, 'box.checked = enable;' ), (int) strpos( $body, "hasAttribute( 'data-high-risk' )" ) );
	}

	public function test_script_posts_rendered_slugs(): void {
		$starter = $this->method( '#bindBridgeStarter() {' );
		$review  = $this->method( '#bindBridgeReview() {' );

		$this->assertMatchesRegularExpression( "/this\\.#post\\( 'aafm_bridge_read_only_starter', \\{ plugin: key, 'slugs\\[\\]': [^}]+\\} \\)/", $starter );
		$this->assertStringContainsString( 'input[name="bridged_abilities[]"]', $starter );
		$this->assertMatchesRegularExpression( "/this\\.#post\\( 'aafm_bridge_mark_reviewed', \\{ plugin: key, 'slugs\\[\\]': [^}]+\\} \\)/", $review );
		$this->assertStringContainsString( 'li > code', $review );
		$post = $this->method( 'async #post( action, data = {} ) {' );
		$this->assertMatchesRegularExpression( '/Array\\.isArray\\( value \\)/', $post, 'An array value is sent as repeated fields, not one comma-joined string.' );
	}
}
