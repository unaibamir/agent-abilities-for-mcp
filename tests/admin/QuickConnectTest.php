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
		delete_option( 'aafm_oauth_dcr_enabled' );
		// Finishing the wizard sets read-only mode, and that is audited like any other flip of the
		// switch, so the log table has to exist or the write surfaces as raw wpdb output.
		aafm_install_activity_log();
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

	/**
	 * Invoke an AJAX handler and report whether it called wp_die (denied or completed). Unlike
	 * {@see invoke_ajax()}, the nonce is caller-controlled so the check_ajax_referer gate can be
	 * exercised too. Any option side effects the handler applies before it dies are already in
	 * place when this returns, so the caller asserts on state, not just on the die.
	 *
	 * @param callable $handler    The AJAX handler to invoke.
	 * @param array    $post       $_POST fields to set.
	 * @param bool     $with_nonce Whether to supply a valid aafm_admin nonce.
	 * @return bool True if the handler called wp_die.
	 */
	private function run_ajax( callable $handler, array $post, bool $with_nonce ): bool {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );

		$nonce             = $with_nonce ? wp_create_nonce( 'aafm_admin' ) : 'not-a-valid-nonce';
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		foreach ( $post as $key => $value ) {
			$_POST[ $key ] = $value;
		}

		$died = false;
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			$died = true;
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
		return $died;
	}

	/**
	 * The OAuth handler is nonce + manage_options gated: a subscriber (with a valid nonce) is denied
	 * and, critically, neither aafm_oauth_enabled nor aafm_oauth_dcr_enabled is written. Fails if the
	 * cap gate is removed - a subscriber would then flip the security-relevant OAuth option, and (since
	 * issue #90 has the handler write DCR in the same breath) the DCR option too.
	 */
	public function test_oauth_handler_denies_subscriber_and_never_writes_oauth(): void {
		$this->acting_as( 'subscriber' );

		$died = $this->run_ajax( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '1' ), true );

		$this->assertTrue( $died, 'The handler must deny a subscriber (wp_die at 403).' );
		$this->assertFalse( get_option( 'aafm_oauth_enabled', false ), 'A subscriber must not write aafm_oauth_enabled.' );
		$this->assertFalse( get_option( 'aafm_oauth_dcr_enabled', false ), 'A subscriber must not write aafm_oauth_dcr_enabled either.' );
		$this->assertFalse( aafm_oauth_enabled(), 'OAuth must stay off after a denied wizard call.' );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'DCR must stay off after a denied wizard call.' );
	}

	/**
	 * A bad nonce is rejected before either OAuth option is ever written, even for an admin. Fails if
	 * the check_ajax_referer gate is removed - the DCR option (written in lockstep since issue #90) is
	 * guarded by the same gate and pinned here alongside aafm_oauth_enabled.
	 */
	public function test_oauth_handler_rejects_bad_nonce_and_never_writes_oauth(): void {
		$this->acting_as( 'administrator' );

		$died = $this->run_ajax( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '1' ), false );

		$this->assertTrue( $died, 'A bad nonce must be rejected (wp_die).' );
		$this->assertFalse( get_option( 'aafm_oauth_enabled', false ), 'A bad-nonce call must not write aafm_oauth_enabled.' );
		$this->assertFalse( get_option( 'aafm_oauth_dcr_enabled', false ), 'A bad-nonce call must not write aafm_oauth_dcr_enabled.' );
	}

	/**
	 * The finish handler is nonce + manage_options gated: a subscriber is denied, the completion
	 * flag is never set, and no ability bundle is applied. Fails if the cap gate is removed.
	 */
	public function test_finish_handler_denies_subscriber_and_makes_no_state_change(): void {
		$this->acting_as( 'subscriber' );
		delete_option( 'aafm_enabled_abilities' );

		$died = $this->run_ajax( 'aafm_ajax_quickconnect_finish', array( 'write' => '1' ), true );

		$this->assertTrue( $died, 'The handler must deny a subscriber (wp_die at 403).' );
		$this->assertFalse( get_option( 'aafm_quickconnect_finished', false ), 'A subscriber must not set the completion flag.' );
		$this->assertNotContains( 'aafm/create-post', aafm_get_enabled_abilities(), 'A denied finish must not apply the write bundle.' );
		$this->assertNotContains( 'aafm/get-posts', aafm_get_enabled_abilities(), 'A denied finish must not apply the read bundle.' );
	}

	/**
	 * A bad nonce is rejected before the finish handler applies anything, even for an admin. Fails
	 * if the check_ajax_referer gate is removed.
	 */
	public function test_finish_handler_rejects_bad_nonce_and_makes_no_state_change(): void {
		$this->acting_as( 'administrator' );
		delete_option( 'aafm_enabled_abilities' );

		$died = $this->run_ajax( 'aafm_ajax_quickconnect_finish', array( 'write' => '1' ), false );

		$this->assertTrue( $died, 'A bad nonce must be rejected (wp_die).' );
		$this->assertFalse( get_option( 'aafm_quickconnect_finished', false ), 'A bad-nonce call must not set the completion flag.' );
		$this->assertNotContains( 'aafm/create-post', aafm_get_enabled_abilities(), 'A bad-nonce finish must not apply the write bundle.' );
	}

	/**
	 * The dismiss handler is nonce + manage_options gated: a subscriber is denied and the permanent
	 * opt-out flag is never set. Fails if the cap gate is removed.
	 */
	public function test_dismiss_handler_denies_subscriber_and_never_sets_flag(): void {
		$this->acting_as( 'subscriber' );

		$died = $this->run_ajax( 'aafm_ajax_quickconnect_dismiss', array(), true );

		$this->assertTrue( $died, 'The handler must deny a subscriber (wp_die at 403).' );
		$this->assertFalse( get_option( 'aafm_quickconnect_dismissed', false ), 'A subscriber must not set the dismissed flag.' );
	}

	/**
	 * A bad nonce is rejected before the dismiss flag is set, even for an admin. Fails if the
	 * check_ajax_referer gate is removed.
	 */
	public function test_dismiss_handler_rejects_bad_nonce_and_never_sets_flag(): void {
		$this->acting_as( 'administrator' );

		$died = $this->run_ajax( 'aafm_ajax_quickconnect_dismiss', array(), false );

		$this->assertTrue( $died, 'A bad nonce must be rejected (wp_die).' );
		$this->assertFalse( get_option( 'aafm_quickconnect_dismissed', false ), 'A bad-nonce call must not set the dismissed flag.' );
	}

	/**
	 * Invoke an AJAX handler and return the decoded wp_send_json_* payload. Same in-process routing
	 * as {@see invoke_ajax()}, but the echoed JSON body is captured and decoded instead of discarded,
	 * so a caller can assert on `success` and the returned state - not just on option side effects.
	 *
	 * @param callable $handler The AJAX handler to invoke.
	 * @param array    $post    $_POST fields to set (the nonce is added automatically).
	 * @return array The decoded JSON response.
	 */
	private function capture_ajax_json( callable $handler, array $post = array() ): array {
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
			unset( $e );
		}
		$body = (string) ob_get_clean();

		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_REQUEST['nonce'] );
		foreach ( array_keys( $post ) as $key ) {
			unset( $_POST[ $key ] );
		}

		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * On a valid privileged call the OAuth handler returns a success payload carrying the resulting
	 * enabled state, so the wizard's Continue step can trust the write before it advances. Turning it
	 * on returns success + '1'; turning it back off returns success + '0'. This is the contract the
	 * JS relies on to stop advancing on a failed write.
	 */
	public function test_oauth_handler_returns_success_and_resulting_state(): void {
		$this->acting_as( 'administrator' );

		$on = $this->capture_ajax_json( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '1' ) );
		$this->assertTrue( $on['success'] ?? false, 'A valid privileged enable call must report success.' );
		$this->assertSame( '1', $on['data']['aafm_oauth_enabled'] ?? null, 'The payload must carry the resulting on state.' );
		$this->assertTrue( aafm_oauth_enabled() );

		$off = $this->capture_ajax_json( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '0' ) );
		$this->assertTrue( $off['success'] ?? false, 'A valid privileged disable call must report success.' );
		$this->assertSame( '0', $off['data']['aafm_oauth_enabled'] ?? null, 'The payload must carry the resulting off state.' );
		$this->assertFalse( aafm_oauth_enabled() );
	}

	/**
	 * A denied (subscriber) call returns a failure payload and changes no state, so the JS keeps the
	 * operator on the connection step. Pairs with the enable-path success contract above.
	 */
	public function test_oauth_handler_denied_call_reports_failure_without_state_change(): void {
		$this->acting_as( 'subscriber' );

		$json = $this->capture_ajax_json( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '1' ) );

		$this->assertFalse( $json['success'] ?? true, 'A denied call must report failure.' );
		$this->assertFalse( get_option( 'aafm_oauth_enabled', false ), 'A denied call must not write the OAuth option.' );
	}

	/**
	 * Issue #90 - the wizard-only path used to leave a NON-connectable state.
	 *
	 * The wizard's connection step enables OAuth, but Dynamic Client Registration used to live in a
	 * SEPARATE option the wizard never touched, so it stayed off. A user who set OAuth up entirely
	 * through the wizard - the wizard's whole purpose, since its copy says this "is what lets ChatGPT,
	 * Claude, and Manus connect" - ended up with DCR silently unavailable: the AS metadata omitted
	 * registration_endpoint and POST /register 404'd, which is the DCR failure the issue reports. DCR
	 * now follows OAuth, so enabling OAuth from the wizard makes registration effective with no
	 * separate option to write.
	 */
	public function test_wizard_oauth_enable_also_enables_dcr_and_advertises_registration(): void {
		$this->acting_as( 'administrator' );

		$this->assertFalse( aafm_oauth_dcr_enabled(), 'Fixture: DCR starts off (OAuth off, so DCR follows off).' );
		$this->assertArrayNotHasKey(
			'registration_endpoint',
			aafm_oauth_authorization_server_metadata(),
			'Fixture: with DCR off the AS metadata must not advertise a dead registration endpoint.'
		);

		$this->invoke_ajax( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '1' ) );

		$this->assertTrue( aafm_oauth_enabled(), 'The explicit connection action turns OAuth on.' );
		$this->assertTrue(
			aafm_oauth_dcr_enabled(),
			'Enabling OAuth through the wizard must also make DCR effective, or the wizard leaves a non-connectable state.'
		);
		$this->assertFalse(
			get_option( 'aafm_oauth_dcr_enabled', false ),
			'DCR follows OAuth - the wizard no longer writes a separate DCR option.'
		);

		$meta = aafm_oauth_authorization_server_metadata();
		$this->assertArrayHasKey(
			'registration_endpoint',
			$meta,
			'With DCR now on, discovery must advertise registration_endpoint so a client can self-register.'
		);
		$this->assertStringContainsString( 'agent-abilities-for-mcp/oauth/register', (string) $meta['registration_endpoint'] );
	}

	/**
	 * Issue #90, end to end over the real REST stack: after the wizard enables OAuth, a DCR client
	 * can actually POST /oauth/register and get a 201 + client_id, instead of the disabled-route 404
	 * that the wizard-only path produced before. This is the assertion a real MCP client
	 * (claude.ai / ChatGPT) depends on to complete its first connect.
	 */
	public function test_wizard_oauth_enable_makes_register_endpoint_reachable(): void {
		if ( ! defined( 'AAFM_OAUTH_ALLOW_HTTP' ) ) {
			define( 'AAFM_OAUTH_ALLOW_HTTP', true );
		}
		aafm_install_oauth_tables();

		$this->acting_as( 'administrator' );

		// Wizard-only setup: the connection step is the ONLY thing the operator does.
		$this->invoke_ajax( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '1' ) );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook fired to populate the REST server in the test.
		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'POST', '/agent-abilities-for-mcp/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'redirect_uris' => array( 'https://app.example/cb' ),
					'client_name'   => 'Issue90 Client',
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame(
			201,
			$response->get_status(),
			'A client must be able to self-register after the wizard connects OAuth; a 404 here is the issue #90 bug.'
		);
		$data = $response->get_data();
		$this->assertNotEmpty( $data['client_id'] ?? '', 'Registration must return a client_id.' );
	}

	/**
	 * The wizard's connection step stays authoritative for both halves of the connection: turning
	 * OAuth off again in the wizard turns DCR off with it (DCR follows OAuth), so the wizard never
	 * leaves a stray self-registration surface on without the OAuth server that backs it. An advanced
	 * operator can still force DCR off while OAuth is on with the aafm_oauth_dcr_enabled filter.
	 */
	public function test_wizard_oauth_disable_also_disables_dcr(): void {
		$this->acting_as( 'administrator' );

		$this->invoke_ajax( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '1' ) );
		$this->assertTrue( aafm_oauth_dcr_enabled(), 'Precondition: enabling turned DCR on.' );

		$this->invoke_ajax( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '0' ) );

		$this->assertFalse( aafm_oauth_enabled(), 'Turning the connection off turns OAuth off.' );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'It turns DCR off in the same step, leaving no orphaned registration surface.' );
	}

	/**
	 * The OAuth handler's success payload reports the resulting DCR state alongside OAuth, so the
	 * wizard's Continue step and success receipt can reflect the real connectable state rather than
	 * assuming it.
	 */
	public function test_oauth_handler_payload_reports_dcr_state(): void {
		$this->acting_as( 'administrator' );

		$on = $this->capture_ajax_json( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '1' ) );
		$this->assertTrue( $on['success'] ?? false );
		$this->assertSame( '1', $on['data']['aafm_oauth_dcr_enabled'] ?? null, 'Enabling reports DCR on.' );

		$off = $this->capture_ajax_json( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '0' ) );
		$this->assertTrue( $off['success'] ?? false );
		$this->assertSame( '0', $off['data']['aafm_oauth_dcr_enabled'] ?? null, 'Disabling reports DCR off.' );
	}

	/**
	 * The finish handler's success payload reports the real DCR state alongside OAuth, so the success
	 * receipt reflects a connectable state rather than assuming one (issue #90). Pinned both ways: a
	 * finish after the wizard connected OAuth (which turns DCR on) reports dcr_enabled 1; a finish with
	 * no connection reports 0. Removing the finish payload's dcr_enabled field fails this.
	 */
	public function test_finish_handler_payload_reports_dcr_state(): void {
		$this->acting_as( 'administrator' );

		// No connection step run: DCR is off, and the finish receipt must say so.
		$off = $this->capture_ajax_json( 'aafm_ajax_quickconnect_finish', array( 'write' => '0' ) );
		$this->assertTrue( $off['success'] ?? false, 'A privileged finish must report success.' );
		$this->assertArrayHasKey( 'dcr_enabled', $off['data'] ?? array(), 'The finish payload must carry dcr_enabled.' );
		$this->assertSame( 0, $off['data']['dcr_enabled'] ?? null, 'With no connection, the finish receipt reports DCR off.' );

		// Now run the connection step (turns OAuth and DCR on), then finish: the receipt reports DCR on.
		delete_option( 'aafm_quickconnect_finished' );
		$this->invoke_ajax( 'aafm_ajax_quickconnect_oauth', array( 'enabled' => '1' ) );
		$this->assertTrue( aafm_oauth_dcr_enabled(), 'Precondition: the connection step turned DCR on.' );

		$on = $this->capture_ajax_json( 'aafm_ajax_quickconnect_finish', array( 'write' => '0' ) );
		$this->assertTrue( $on['success'] ?? false );
		$this->assertSame( 1, $on['data']['dcr_enabled'] ?? null, 'After the wizard connects OAuth, the finish receipt reports DCR on.' );
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
	 * must NEVER flip either the OAuth option or the DCR option - the off-by-default posture (1.3.0
	 * for OAuth, issue #90 for DCR) is preserved on load. A render that began writing DCR would let
	 * the off-by-default DCR posture regress silently; this pins it.
	 */
	public function test_render_outputs_markup_without_enabling_oauth(): void {
		$this->acting_as( 'administrator' );
		$this->assertFalse( aafm_oauth_enabled(), 'Fixture: OAuth starts off.' );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'Fixture: DCR starts off.' );

		ob_start();
		aafm_quickconnect_render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="aafm-qc"', $html );
		$this->assertStringContainsString( aafm_endpoint_url(), $html );
		// The load did not turn OAuth on.
		$this->assertFalse( aafm_oauth_enabled(), 'Rendering the wizard must not enable OAuth.' );
		$this->assertFalse( get_option( 'aafm_oauth_enabled', false ), 'No aafm_oauth_enabled row may be written on render.' );
		$this->assertFalse( aafm_oauth_dcr_enabled(), 'Rendering the wizard must not enable DCR.' );
		$this->assertFalse( get_option( 'aafm_oauth_dcr_enabled', false ), 'No aafm_oauth_dcr_enabled row may be written on render.' );
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
