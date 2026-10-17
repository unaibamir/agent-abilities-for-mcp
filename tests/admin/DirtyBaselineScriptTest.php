<?php
/**
 * Static guard for the unsaved-changes baseline in admin.js.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

/**
 * A save must record what it SENT as saved, not whatever the controls hold when the answer
 * arrives, or an edit made while the request was in flight is marked clean and lost. This repo has
 * no JS runner, so this scans the handlers the way AbilitiesCountRefreshTest does.
 */
class DirtyBaselineScriptTest extends TestCase {

	/**
	 * Each save handler with the element it saves.
	 *
	 * @return array<string,array{0:string,1:string}> Handler name to the variable its snapshot is taken from.
	 */
	public static function handlers(): array {
		return array(
			'abilities form' => array( '#bindSaveIntegrations() {', 'form' ),
			'bridge form'    => array( '#bindSaveBridge() {', 'form' ),
			'settings form'  => array( '#bindSaveSettings() {', 'form' ),
			'lifetimes card' => array( '#bindLifetimesCard() {', 'card' ),
			'grant row'      => array( '#bindGrantControls() {', 'row' ),
		);
	}

	/**
	 * A handler snapshots its root before the request and marks clean from that snapshot.
	 *
	 * @dataProvider handlers
	 *
	 * @param string $token Method definition token.
	 * @param string $root  The element the handler saves.
	 */
	public function test_a_save_marks_clean_from_a_snapshot_taken_before_the_request( string $token, string $root ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test.
		$js    = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		$start = strpos( $js, $token );
		$this->assertNotFalse( $start, "$token not found in admin.js." );
		$next = preg_match( '/\n\t\t#\w+\(.*\) \{/', $js, $m, PREG_OFFSET_CAPTURE, (int) $start + strlen( $token ) );
		$body = substr( $js, (int) $start, 1 === $next ? (int) $m[0][1] - (int) $start : null );

		$this->assertMatchesRegularExpression( '/this\.#snapshot\( ' . $root . ' \)/', $body, "$token must snapshot $root before the request." );
		$this->assertMatchesRegularExpression( '/this\.#markClean\( ' . $root . ', \w+ \)/', $body, "$token must mark clean from the snapshot." );
		$this->assertDoesNotMatchRegularExpression( '/this\.#markClean\( ' . $root . ' \)/', $body, "$token must not mark clean from the current values." );
	}

	/**
	 * A control the server answers for is overwritten only when the user has not edited it since the
	 * request left; the three places that apply a server value must all go through the guard.
	 */
	public function test_a_server_value_never_overwrites_an_edit_made_while_the_save_was_in_flight(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );

		$this->assertDoesNotMatchRegularExpression( '/(siteBrief|allowlist)\.value\s*=\s*json/', $js, 'Settings must not assign a server value straight to the control.' );
		$this->assertDoesNotMatchRegularExpression( '/dcr\.checked\s*=\s*Boolean/', $js, 'The lifetimes card must not assign the server value straight to the switch.' );
		foreach ( array( 'siteBrief', 'allowlist', 'dcr' ) as $control ) {
			$this->assertMatchesRegularExpression( '/this\.#applyServerValue\( sent, ' . $control . ',/', $js, "$control must apply the server value through the guard." );
		}
		$this->assertMatchesRegularExpression( '/#applyServerValue\( sent, control, value \) \{\s*if \( this\.#controlValue\( control \) === sent\.get\( control \) \) \{/', $js );
	}
}
