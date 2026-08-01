<?php
/**
 * Enabled-count staleness fix: after a successful Integrations/Abilities save, the header
 * numbers must reflect what was actually persisted, not what the page showed before the save.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AbilitiesCountRefreshTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// aafm_ajax_save_abilities() logs an audit row for every toggled ability
		// (aafm_log_ability_toggle_diff()), so the activity-log table needs to exist.
		aafm_install_activity_log();
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_REQUEST['nonce'], $_POST['aafm_abilities'], $_POST['aafm_scope'] );
		parent::tear_down();
	}

	/**
	 * Route wp_send_json through a throwing wp_die so the handler is observable in-process.
	 * Mirrors BridgeDirectorySaveTest::intercept_die().
	 *
	 * @return void
	 */
	private function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}

	/**
	 * Run an AJAX handler and return its captured JSON payload.
	 *
	 * @param callable $handler The AJAX callback to invoke.
	 * @return array<string,mixed>
	 */
	private function run_handler( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * The Integrations card header and the Abilities tab's stat box both need the AJAX response
	 * to carry what they display: the server-persisted enabled list (never trust what the client
	 * had checked - a locked ability can be silently dropped from what was submitted) and the
	 * global enabled total. Regression guard for the "Saved" confirmation appearing next to a
	 * stale "0 / 52" that only fixed itself on a manual page reload.
	 */
	public function test_save_abilities_response_carries_enabled_list_and_global_total(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_enabled_abilities', array() );
		$this->intercept_die();
		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['aafm_abilities'] = array( 'aafm/get-posts' );
		$_POST['aafm_scope']     = array( 'content' );

		$json = $this->run_handler( 'aafm_ajax_save_abilities' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$data = $json['data'] ?? array();
		$this->assertArrayHasKey( 'enabled', $data, 'Response must carry the persisted enabled list.' );
		$this->assertContains(
			'aafm/get-posts',
			$data['enabled'],
			'The enabled list must reflect what was actually stored, not merely what was submitted.'
		);
		$this->assertArrayHasKey(
			'ability_enabled_total',
			$data,
			'Response must carry the global enabled-ability total, or the Abilities tab stat box has nothing to repaint itself with after a save.'
		);
		$this->assertSame(
			aafm_enabled_ability_count(),
			$data['ability_enabled_total'],
			'The returned total must match the server-authoritative count.'
		);
	}

	/**
	 * Client wiring guard: both save handlers that share the aafm_save_abilities action must
	 * call the count-refresh helper on a successful save, or the server carrying the right
	 * numbers is moot - nothing on the page ever reads them. Mirrors
	 * SettingsSaveTest::test_settings_save_script_forwards_every_checkbox's static-scan approach,
	 * since this repo has no JS test runner.
	 */
	public function test_save_handlers_call_the_count_refresh_helper(): void {
		$path = AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test, not a remote URL.
		$js = (string) file_get_contents( $path );

		$this->assertStringContainsString(
			'#refreshLocalCounts( scopeRoot, enabledList ) {',
			$js,
			'The count-refresh helper must exist.'
		);

		// Isolate each handler by anchoring on the method definition token and the next
		// method's, so a call forwarded by a different handler cannot mask a regression here.
		$bounds = array(
			'#bindSaveIntegrations() {' => '#bindSaveBridge() {',
			'#bindSaveAbilities() {'    => '#bindSavePostTypes() {',
		);
		foreach ( $bounds as $start_token => $end_token ) {
			$start = strpos( $js, $start_token );
			$end   = strpos( $js, $end_token );
			$this->assertNotFalse( $start, "$start_token not found in admin.js." );
			$this->assertNotFalse( $end, "Could not bound the $start_token handler." );
			$handler = substr( $js, (int) $start, (int) $end - (int) $start );

			$this->assertStringContainsString(
				'this.#refreshLocalCounts(',
				$handler,
				"$start_token must call #refreshLocalCounts after a successful save, or its header counts go stale."
			);
		}
	}
}
