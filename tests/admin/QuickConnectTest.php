<?php
/**
 * Quick Connect wizard: first-run gating, the explicit OAuth-enable path, finish/dismiss flags,
 * the agent-user marker, and reset returning the site to first-run.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class QuickConnectTest extends TestCase {

	/**
	 * Reset the wizard flags and OAuth toggle before each case so gating is deterministic.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_quickconnect_finished' );
		delete_option( 'aafm_quickconnect_dismissed' );
		delete_option( 'aafm_oauth_enabled' );
	}

	/**
	 * Invoke an AJAX handler in-process: route wp_die through an exception so the JSON send does
	 * not exit the test, and swallow the echoed body. The option side effects the handler makes
	 * before wp_send_json_* run are already applied when the exception is caught.
	 *
	 * @param callable $handler The AJAX handler to invoke.
	 * @param array    $post    $_POST fields to set (the nonce is added automatically).
	 * @return void
	 */
	private function invoke_ajax( callable $handler, array $post = array() ): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );

		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		foreach ( $post as $key => $value ) {
			$_POST[ $key ] = $value;
		}

		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			// Expected: wp_send_json_* dies after writing the response.
			unset( $e );
		} finally {
			ob_end_clean();
		}

		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_REQUEST['nonce'] );
		foreach ( array_keys( $post ) as $key ) {
			unset( $_POST[ $key ] );
		}
	}

	public function test_should_render_true_for_admin_on_first_run(): void {
		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_quickconnect_should_render() );
	}

	public function test_should_render_false_after_finished(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_quickconnect_finished', '1' );
		$this->assertFalse( aafm_quickconnect_should_render() );
	}

	public function test_should_render_false_after_permanent_dismiss(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_quickconnect_dismissed', '1' );
		$this->assertFalse( aafm_quickconnect_should_render() );
	}

	public function test_should_render_false_for_non_admin(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_quickconnect_should_render() );
	}

	/**
	 * The wizard renders on first run and its markup carries the live endpoint, but rendering it
	 * must NEVER flip the OAuth option - the 1.3.0 off-by-default posture is preserved on load.
	 */
	public function test_render_outputs_markup_without_enabling_oauth(): void {
		$this->acting_as( 'administrator' );
		$this->assertFalse( aafm_oauth_enabled(), 'Fixture: OAuth starts off.' );

		ob_start();
		aafm_quickconnect_render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="aafm-qc"', $html );
		$this->assertStringContainsString( aafm_endpoint_url(), $html );
		// The load did not turn OAuth on.
		$this->assertFalse( aafm_oauth_enabled(), 'Rendering the wizard must not enable OAuth.' );
		$this->assertFalse( get_option( 'aafm_oauth_enabled', false ), 'No aafm_oauth_enabled row may be written on render.' );
	}

	public function test_render_outputs_nothing_when_finished(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_quickconnect_finished', '1' );
		ob_start();
		aafm_quickconnect_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * OAuth flips on ONLY through the explicit connection-step action, and can be turned off the
	 * same way. Nothing else in the wizard touches the option.
	 */
	public function test_oauth_ajax_enables_only_on_explicit_action(): void {
		$this->acting_as( 'administrator' );
		$this->assertFalse( aafm_oauth_enabled(), 'Off before any explicit action.' );

		$this->invoke_ajax( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '1' ) );
		$this->assertSame( '1', get_option( 'aafm_oauth_enabled' ) );
		$this->assertTrue( aafm_oauth_enabled() );

		$this->invoke_ajax( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '0' ) );
		$this->assertSame( '0', get_option( 'aafm_oauth_enabled' ) );
		$this->assertFalse( aafm_oauth_enabled() );
	}

	/**
	 * Finishing with write off records the completion flag and enables the read bundle only.
	 */
	public function test_finish_without_write_sets_flag_and_reads_only(): void {
		$this->acting_as( 'administrator' );
		$this->invoke_ajax( 'aafm_ajax_quickconnect_finish', array( 'write' => '0' ) );

		$this->assertSame( '1', get_option( 'aafm_quickconnect_finished' ) );
		$this->assertFalse( aafm_quickconnect_should_render(), 'The wizard is done, so it must not reopen.' );

		$enabled = aafm_get_enabled_abilities();
		$this->assertContains( 'aafm/get-posts', $enabled );
		$this->assertNotContains( 'aafm/create-post', $enabled );
	}

	/**
	 * Finishing with write on enables the content write bundle, still never a destructive ability.
	 */
	public function test_finish_with_write_enables_content_writes(): void {
		$this->acting_as( 'administrator' );
		$this->invoke_ajax( 'aafm_ajax_quickconnect_finish', array( 'write' => '1' ) );

		$enabled = aafm_get_enabled_abilities();
		$this->assertContains( 'aafm/create-post', $enabled );
		$this->assertNotContains( 'aafm/delete-post', $enabled );
	}

	/**
	 * "Don't show this again" sets the permanent opt-out flag, and the wizard stops rendering.
	 */
	public function test_dismiss_ajax_sets_permanent_flag(): void {
		$this->acting_as( 'administrator' );
		$this->invoke_ajax( 'aafm_ajax_quickconnect_dismiss' );

		$this->assertSame( '1', get_option( 'aafm_quickconnect_dismissed' ) );
		$this->assertFalse( aafm_quickconnect_should_render() );
	}

	/**
	 * The dedicated agent-user creation path stamps the plugin marker, so the onboarding
	 * "connected" signal recognises it. This is the same path the wizard's app-password branch uses.
	 */
	public function test_agent_user_creation_stamps_marker(): void {
		$this->acting_as( 'administrator' );
		$result = aafm_create_agent_user( aafm_quickconnect_agent_login() );

		$this->assertIsArray( $result );
		$user_id = (int) $result['user_id'];
		$this->assertSame( 1, (int) get_user_meta( $user_id, aafm_agent_user_marker_meta_key(), true ) );
		$this->assertTrue( aafm_has_created_agent_user() );
	}

	/**
	 * The wizard flags are part of the canonical config-option set, so a plugin reset returns the
	 * site to first-run: both flags are cleared and the wizard renders again.
	 */
	public function test_reset_returns_site_to_first_run(): void {
		$this->acting_as( 'administrator' );
		aafm_install_activity_log();
		aafm_install_oauth_tables();

		update_option( 'aafm_quickconnect_finished', '1' );
		update_option( 'aafm_quickconnect_dismissed', '1' );

		$names = aafm_config_option_names();
		$this->assertContains( 'aafm_quickconnect_finished', $names );
		$this->assertContains( 'aafm_quickconnect_dismissed', $names );
		$this->assertContains( 'aafm_menu_pointer_active', $names );

		aafm_reset_plugin();

		$this->assertFalse( get_option( 'aafm_quickconnect_finished', false ) );
		$this->assertFalse( get_option( 'aafm_quickconnect_dismissed', false ) );
		$this->assertTrue( aafm_quickconnect_should_render(), 'After a reset the wizard shows again.' );
	}
}
