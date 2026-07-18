<?php
/**
 * Activity tab renders rows including denials, escaped.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ActivityTabTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function test_tab_lists_a_denied_row(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability'  => 'aafm/trash-post',
				'status'   => 'denied',
				'arg_keys' => array( 'post_id' ),
			)
		);

		ob_start();
		aafm_render_activity_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aafm/trash-post', $html );
		$this->assertStringContainsString( 'denied', $html );
		$this->assertStringContainsString( 'post_id', $html );
		// Status renders inside a pill, and the presentational filter control is present.
		$this->assertStringContainsString( 'aafm-pill', $html );
		$this->assertStringContainsString( 'aafm-seg', $html );
	}

	public function test_tab_escapes_ability_names(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => '<script>x</script>',
				'status'  => 'error',
			)
		);

		ob_start();
		aafm_render_activity_tab();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>x</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_empty_log_shows_placeholder_row(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_activity_tab();
		$html = (string) ob_get_clean();

		// A clear-log control is always present; the empty state renders without fataling.
		$this->assertStringContainsString( 'aafm-clear-log', $html );
		$this->assertStringContainsString( 'aafm-log-table', $html );
	}

	/**
	 * L4: clearing the log used to leave no trace of its own clearing - an operator (or an
	 * attacker with manage_options) could wipe the audit trail without the log itself ever
	 * showing it happened. aafm_ajax_clear_log() must write one final row recording the clear,
	 * who did it, and when, so the emptied log is never completely silent about its own history.
	 */
	public function test_clearing_the_log_leaves_a_tamper_marker_row(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$this->assertGreaterThan( 0, aafm_activity_count(), 'Seed row should be present before clearing.' );

		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;

		$json = $this->run_handler( 'aafm_ajax_clear_log' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );

		// Exactly one row survives: the marker, not the seeded call.
		$this->assertSame( 1, aafm_activity_count() );
		$rows = aafm_query_activity( array() );
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( $admin, (int) $rows[0]['principal_user_id'] );
		$this->assertNotSame( 'aafm/get-posts', $rows[0]['ability'], 'The marker must not be mistaken for the cleared call.' );
	}

	/**
	 * Route wp_send_json through a throwing wp_die so the handler is observable in-process.
	 * Mirrors the pattern in BridgeDirectorySaveTest / OauthRevokeAjaxTest.
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
}
