<?php
/**
 * Connection tab logic: diagnostics checks, agent-user creation, snippet building.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;
use WP_Application_Passwords;
use WP_Error;

final class ConnectionTest extends TestCase {

	/**
	 * These render-tab tests were written against the old OAuth-on default. OAuth is now OFF
	 * by default, so enable it here to preserve that baseline; the off-state tests set the
	 * toggle to '0'/'' explicitly and override this.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'aafm_oauth_enabled', '1' );
	}

	public function test_diagnostics_report_adapter_and_endpoint(): void {
		// Note: we do NOT fire rest_api_init here. The adapter creates its server (a
		// process-global, once-per-ID resource) on that action, so re-firing it across the
		// suite trips an "ID already exists" incorrect-usage notice. The diagnostic shape is
		// what matters; the endpoint check legitimately reports pass or fail either way.
		$checks = aafm_diagnostic_checks();
		$ids    = wp_list_pluck( $checks, 'id' );
		$this->assertContains( 'adapter', $ids );
		$this->assertContains( 'endpoint', $ids );
		$this->assertContains( 'auth_header', $ids );

		$by_id = array_column( $checks, null, 'id' );
		// The adapter is bundled and loaded in the test process, so this check always passes.
		$this->assertSame( 'pass', $by_id['adapter']['status'] );
		// Endpoint and auth-header are environment-dependent; assert they yield a known state.
		$this->assertContains( $by_id['endpoint']['status'], array( 'pass', 'fail' ) );
		$this->assertContains( $by_id['auth_header']['status'], array( 'pass', 'warn' ) );
	}

	public function test_create_agent_user_makes_a_low_priv_user(): void {
		$this->acting_as( 'administrator' );
		$result = aafm_create_agent_user( 'mcp-agent' );
		$this->assertIsArray( $result );
		$this->assertIsInt( $result['user_id'] );
		$user = get_userdata( $result['user_id'] );
		$this->assertContains( 'subscriber', $user->roles );
		$this->assertNotContains( 'administrator', $user->roles );
		$this->assertNotContains( 'editor', $user->roles );
	}

	public function test_create_agent_user_rejects_duplicate_login(): void {
		$this->acting_as( 'administrator' );
		aafm_create_agent_user( 'dupe-agent' );
		$again = aafm_create_agent_user( 'dupe-agent' );
		$this->assertInstanceOf( WP_Error::class, $again );
	}

	public function test_create_agent_user_stamps_the_plugin_marker(): void {
		$this->acting_as( 'administrator' );
		$result = aafm_create_agent_user( 'mcp-agent' );
		$this->assertIsArray( $result );
		$uid = (int) $result['user_id'];

		$this->assertSame( '1', (string) get_user_meta( $uid, aafm_agent_user_marker_meta_key(), true ) );
		$this->assertNotEmpty( get_user_meta( $uid, 'aafm_agent_user_created', true ) );
		$this->assertTrue( aafm_has_created_agent_user() );
		$this->assertContains( $uid, aafm_created_agent_users() );
	}

	/**
	 * Regression: the Connection-tab step 1 done-state keys off the plugin marker, not a
	 * hardcoded 'mcp-agent' login. Even a name-matching bare app-password holder (created
	 * outside this plugin) must NOT flip step 1 to done - the create form must still show,
	 * matching the Dashboard checklist.
	 */
	public function test_connection_step_one_ignores_a_bare_app_password_holder(): void {
		$holder = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'mcp-agent',
			)
		);
		WP_Application_Passwords::create_new_application_password( $holder, array( 'name' => 'jetpack' ) );

		$html = $this->render_connection_tab();
		$this->assertStringContainsString( 'id="aafm-create-user"', $html );
		$this->assertStringNotContainsString( 'aafm-agent-done', $html );
	}

	public function test_connection_step_one_done_for_a_marked_agent_user(): void {
		aafm_create_agent_user( 'mcp-agent' );
		$html = $this->render_connection_tab();
		$this->assertStringContainsString( 'aafm-agent-done', $html );
		$this->assertStringNotContainsString( 'id="aafm-create-user"', $html );
	}

	/**
	 * The done-state copy names the actual agent login, not the hardcoded default, so an
	 * operator who picked a custom username still sees the right account referenced.
	 */
	public function test_connection_step_one_names_the_actual_agent_login(): void {
		aafm_create_agent_user( 'robo-agent' );
		$html = $this->render_connection_tab();
		$this->assertStringContainsString( 'aafm-agent-done', $html );
		$this->assertStringContainsString( 'robo-agent', $html );
	}

	/**
	 * The one-time backfill stamps a pre-marker install's agent user ONLY when it matches the
	 * old flow's exact shape (login 'mcp-agent', subscriber, has an application password), and a
	 * same-named account that does not match is left alone.
	 */
	public function test_backfill_marks_only_the_old_flow_shape(): void {
		// Matches the old flow: login mcp-agent, subscriber, with an application password.
		$legacy = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'mcp-agent',
			)
		);
		WP_Application_Passwords::create_new_application_password( $legacy, array( 'name' => 'legacy' ) );

		delete_option( 'aafm_agent_user_marker_backfilled' );
		aafm_backfill_agent_user_marker();

		$this->assertSame( '1', (string) get_user_meta( $legacy, aafm_agent_user_marker_meta_key(), true ) );
		$this->assertContains( $legacy, aafm_created_agent_users() );
		// The guard flag is set so a second pass is a no-op.
		$this->assertSame( '1', get_option( 'aafm_agent_user_marker_backfilled' ) );
	}

	public function test_backfill_leaves_a_non_matching_same_named_user_alone(): void {
		// Same name, but an admin with no application password - NOT the old agent-user shape.
		$impostor = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'mcp-agent',
			)
		);

		delete_option( 'aafm_agent_user_marker_backfilled' );
		aafm_backfill_agent_user_marker();

		$this->assertSame( '', (string) get_user_meta( $impostor, aafm_agent_user_marker_meta_key(), true ) );
		$this->assertFalse( aafm_has_created_agent_user() );
	}

	/**
	 * T3-1: the agent-user AJAX handler must gate on manage_options, not create_users. A
	 * non-admin custom role holding only create_users (plus the nonce) must be denied.
	 */
	public function test_create_agent_user_ajax_requires_manage_options(): void {
		add_role(
			'aafm_creator_test',
			'AAFM Creator Test',
			array(
				'read'         => true,
				'create_users' => true,
			)
		);
		$creator = $this->factory->user->create( array( 'role' => 'aafm_creator_test' ) );
		wp_set_current_user( $creator );

		$this->assertTrue( current_user_can( 'create_users' ), 'Fixture: the role must hold create_users.' );
		$this->assertFalse( current_user_can( 'manage_options' ), 'Fixture: the role must NOT hold manage_options.' );

		// Route wp_send_json through wp_die (not a bare die) and make that throw, so the JSON 403
		// short-circuit is observable in-process instead of exiting. Swallow the echoed body.
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );

		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce; // check_ajax_referer() reads $_REQUEST.
		$_POST['login']    = 'should-not-be-created';

		$before = (int) count_users()['total_users'];
		$thrown = false;
		ob_start();
		try {
			aafm_ajax_create_agent_user();
		} catch ( \WPDieException $e ) {
			$thrown = true;
		} finally {
			ob_end_clean();
		}
		$after = (int) count_users()['total_users'];

		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_POST['login'], $_REQUEST['nonce'] );
		remove_role( 'aafm_creator_test' );

		$this->assertTrue( $thrown, 'A create_users-only user must be denied (the handler must die).' );
		$this->assertSame( $before, $after, 'No agent user may be created for a non-manage_options user.' );
	}

	/**
	 * Self-healing marker: re-running create for a login that already exists as a safe,
	 * low-privilege (subscriber-shape) account stamps the plugin marker on it, so the onboarding
	 * "Connect your agent" step - which keys off the marker - flips to done. The friendly
	 * "already exists" error is still returned. Covers the pre-marker and custom-login regressions.
	 */
	public function test_create_agent_user_heals_marker_on_existing_subscriber(): void {
		$this->acting_as( 'administrator' );

		// An unmarked agent account created outside the marker flow (e.g. a pre-marker install or a
		// custom login), holding no manage_options.
		$existing = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'legacy-agent',
			)
		);
		$this->assertSame( '', (string) get_user_meta( $existing, aafm_agent_user_marker_meta_key(), true ), 'Fixture: starts unmarked.' );

		$result = aafm_create_agent_user( 'legacy-agent' );

		// Still the friendly "already exists" payload, with the edit link.
		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( $existing, (int) $data['user_id'] );
		$this->assertArrayHasKey( 'edit_url', $data );

		// ...but the marker is now healed onto the existing account.
		$this->assertSame( '1', (string) get_user_meta( $existing, aafm_agent_user_marker_meta_key(), true ) );
		$this->assertContains( $existing, aafm_created_agent_users() );
		$this->assertTrue( aafm_has_created_agent_user() );
	}

	/**
	 * Guard rail: a privileged account (holds manage_options) that happens to share the login is
	 * NEVER stamped. Marking an admin as the low-privilege agent user would misreport a full-caps
	 * login as the dedicated agent, so the marker must stay off it.
	 */
	public function test_create_agent_user_never_heals_marker_on_a_privileged_account(): void {
		$this->acting_as( 'administrator' );

		$admin = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'privileged-agent',
			)
		);

		$result = aafm_create_agent_user( 'privileged-agent' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( '', (string) get_user_meta( $admin, aafm_agent_user_marker_meta_key(), true ), 'A manage_options account must never be marked as the agent user.' );
		$this->assertNotContains( $admin, aafm_created_agent_users() );
	}

	public function test_client_snippet_points_at_endpoint_and_username(): void {
		$snippet = aafm_client_snippet( 'claude-code', 'mcp-agent' );
		$this->assertStringContainsString( rest_url( 'agent-abilities-for-mcp/mcp' ), $snippet );
		$this->assertStringContainsString( 'mcp-agent', $snippet );
		// The wizard never embeds a real secret - only the paste placeholder.
		$this->assertStringContainsString( 'PASTE-APPLICATION-PASSWORD-HERE', $snippet );
	}

	public function test_unix_snippet_launches_npx_directly(): void {
		$cfg    = json_decode( aafm_client_snippet( 'claude-code', 'mcp-agent', 'unix' ), true );
		$server = $cfg['mcpServers']['agent-abilities'];
		$this->assertSame( 'npx', $server['command'] );
		$this->assertSame( array( '-y', '@automattic/mcp-wordpress-remote@latest' ), $server['args'] );
	}

	public function test_windows_snippet_wraps_launcher_in_cmd(): void {
		$cfg    = json_decode( aafm_client_snippet( 'claude-code', 'mcp-agent', 'windows' ), true );
		$server = $cfg['mcpServers']['agent-abilities'];
		$this->assertSame( 'cmd', $server['command'] );
		$this->assertSame(
			array( '/c', 'npx', '-y', '@automattic/mcp-wordpress-remote@latest' ),
			$server['args']
		);
	}

	public function test_local_site_snippet_carries_tls_bypass(): void {
		add_filter( 'aafm_site_is_local', '__return_true' );
		$cfg = json_decode( aafm_client_snippet( 'claude-code', 'mcp-agent' ), true );
		remove_filter( 'aafm_site_is_local', '__return_true' );
		$env = $cfg['mcpServers']['agent-abilities']['env'];
		$this->assertSame( '0', $env['NODE_TLS_REJECT_UNAUTHORIZED'] );
	}

	public function test_production_site_snippet_omits_tls_bypass(): void {
		add_filter( 'aafm_site_is_local', '__return_false' );
		$cfg = json_decode( aafm_client_snippet( 'claude-code', 'mcp-agent' ), true );
		remove_filter( 'aafm_site_is_local', '__return_false' );
		$env = $cfg['mcpServers']['agent-abilities']['env'];
		$this->assertArrayNotHasKey( 'NODE_TLS_REJECT_UNAUTHORIZED', $env );
	}

	/**
	 * Capture the rendered Connection tab markup.
	 *
	 * @return string
	 */
	private function render_connection_tab(): string {
		ob_start();
		aafm_render_connection_tab();
		return (string) ob_get_clean();
	}

	public function test_connection_tab_renders_guided_three_step_layout(): void {
		$html = $this->render_connection_tab();

		// New Direction A structure: stepper, client picker, diagnostics rail.
		$this->assertStringContainsString( 'aafm-step-head', $html );
		$this->assertStringContainsString( 'aafm-client-grid', $html );
		$this->assertStringContainsString( 'aafm-diag', $html );

		// The endpoint URL is shown verbatim.
		$this->assertStringContainsString( esc_html( rest_url( 'agent-abilities-for-mcp/mcp' ) ), $html );

		// The proxy package name survives in the primary config block.
		$this->assertStringContainsString( '@automattic/mcp-wordpress-remote', $html );
	}

	public function test_connection_tab_preserves_js_contract_ids(): void {
		$html = $this->render_connection_tab();

		// The create-agent-user controls keep their exact ids/classes for admin.js.
		$this->assertStringContainsString( 'id="aafm-agent-login"', $html );
		$this->assertStringContainsString( 'id="aafm-create-user"', $html );
		$this->assertStringContainsString( 'aafm-user-status', $html );

		// The test-connection controls keep their exact ids/classes.
		$this->assertStringContainsString( 'id="aafm-test-connection"', $html );
		$this->assertStringContainsString( 'aafm-test-status', $html );

		// OS toggle + OS-keyed snippet boxes the OS-tab handler binds to.
		$this->assertStringContainsString( 'aafm-os-tab', $html );
		$this->assertStringContainsString( 'data-os="unix"', $html );
		$this->assertStringContainsString( 'data-os="windows"', $html );

		// Per-client quickstart toggle + grid the quickstart handler binds to.
		$this->assertStringContainsString( 'aafm-quickstart-toggle', $html );
		$this->assertStringContainsString( 'id="aafm-quickstart-grid"', $html );

		// Copy buttons keep their hook class.
		$this->assertStringContainsString( 'aafm-copy', $html );
	}

	public function test_connection_tab_keeps_diagnostic_labels_and_platform_notes(): void {
		$html = $this->render_connection_tab();

		// Diagnostic labels survive the restyle.
		$this->assertStringContainsString( 'MCP adapter active and compatible', $html );
		$this->assertStringContainsString( 'MCP REST endpoint registered', $html );
		$this->assertStringContainsString( 'Authorization header reaches WordPress', $html );

		// Diagnostic rows map state to the status-dot classes.
		$this->assertStringContainsString( 'aafm-diag-row', $html );
		$this->assertStringContainsString( 'dot-lg', $html );

		// Platform-specific notices are preserved.
		$this->assertStringContainsString( 'Windows', $html );
		$this->assertStringContainsString( 'Certificate', $html );
		$this->assertStringContainsString( 'NODE_TLS_REJECT_UNAUTHORIZED', $html );

		// Security framing: the snippet never embeds a real secret.
		$this->assertStringContainsString( 'PASTE-APPLICATION-PASSWORD-HERE', $html );
	}

	public function test_connection_tab_emits_every_client_snippet(): void {
		$html = $this->render_connection_tab();

		// Every quickstart client keeps a presence in the rendered markup so the
		// per-client config is reachable from the picker.
		foreach ( aafm_quickstart_clients() as $slug => $label ) {
			$this->assertStringContainsString( esc_html( $label ), $html, "client {$slug} label missing from render" );
		}

		// VS Code's distinct "servers" key proves per-client shaping reaches the markup.
		$this->assertStringContainsString( 'servers', $html );
	}

	public function test_connection_tab_shows_the_endpoint_once_with_oauth_on(): void {
		// OAuth on (the default) used to render the endpoint twice: once in the OAuth card
		// and once in the standalone endpoint card. The endpoint label is now shown exactly
		// once - in the canonical endpoint card.
		update_option( 'aafm_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		$this->assertSame( 1, substr_count( $html, 'aafm-endpoint-card' ) );
		$this->assertSame( 1, substr_count( $html, '>MCP endpoint<' ) );
		// The labelled endpoint card is unique. The three hosted web-app OAuth panels (ChatGPT,
		// Claude, and Manus) each carry their own inline endpoint field to paste, and nothing else
		// re-renders the canonical card.
		$this->assertSame( 3, substr_count( $html, 'aafm-oauth-endpoint' ) );
	}

	public function test_connection_tab_steps_share_an_alignment_class(): void {
		$html = $this->render_connection_tab();
		// The three numbered steps each carry the shared padding/alignment class so their
		// left and right edges line up top to bottom.
		$this->assertSame( 3, substr_count( $html, 'aafm-conn-step' ) );
	}

	public function test_oauth_client_snippet_carries_no_credentials(): void {
		$snippet = aafm_oauth_client_snippet( 'claude-code', 'unix' );
		$this->assertStringContainsString( 'mcp-remote', $snippet );
		$this->assertStringContainsString( aafm_endpoint_url(), $snippet );
		// OAuth = browser approval, never a stored secret.
		$this->assertStringNotContainsString( 'WP_API_PASSWORD', $snippet );
		$this->assertStringNotContainsString( 'WP_API_USERNAME', $snippet );
		$this->assertStringNotContainsString( 'PASTE-APPLICATION-PASSWORD-HERE', $snippet );
	}

	public function test_oauth_client_snippet_local_adds_ca_placeholder(): void {
		add_filter( 'aafm_site_is_local', '__return_true' );
		$snippet = aafm_oauth_client_snippet( 'claude-code', 'unix' );
		remove_filter( 'aafm_site_is_local', '__return_true' );
		$this->assertStringContainsString( 'NODE_EXTRA_CA_CERTS', $snippet );
		// Path is machine-specific - placeholder only, never a hardcoded real path.
		$this->assertStringContainsString( 'PATH-TO-YOUR-mkcert-rootCA.pem', $snippet );
	}

	public function test_oauth_client_snippet_production_omits_env(): void {
		add_filter( 'aafm_site_is_local', '__return_false' );
		$snippet = aafm_oauth_client_snippet( 'claude-code', 'unix' );
		remove_filter( 'aafm_site_is_local', '__return_false' );
		$this->assertStringNotContainsString( 'NODE_EXTRA_CA_CERTS', $snippet );
	}

	public function test_oauth_client_snippet_windows_wraps_cmd(): void {
		$snippet = aafm_oauth_client_snippet( 'claude-code', 'windows' );
		$this->assertStringContainsString( 'cmd', $snippet );
		$this->assertStringContainsString( '/c', $snippet );
	}

	public function test_oauth_client_snippet_vscode_uses_servers_key(): void {
		$vscode  = aafm_oauth_client_snippet( 'vscode', 'unix' );
		$generic = aafm_oauth_client_snippet( 'generic', 'unix' );
		$this->assertStringContainsString( '"servers"', $vscode );
		$this->assertStringContainsString( '"mcpServers"', $generic );
	}

	public function test_oauth_client_mode_known_for_every_client(): void {
		foreach ( array_keys( aafm_quickstart_clients() ) as $slug ) {
			$this->assertContains( aafm_oauth_client_mode( $slug ), array( 'native', 'bridge' ), $slug );
		}
	}

	public function test_oauth_client_note_present_for_every_client(): void {
		foreach ( array_keys( aafm_quickstart_clients() ) as $slug ) {
			$this->assertNotSame( '', aafm_oauth_client_note( $slug ), $slug );
		}
		$this->assertSame( '', aafm_oauth_client_note( 'does-not-exist' ) );
	}

	public function test_client_list_leads_with_the_web_apps_in_order(): void {
		$slugs = array_keys( aafm_quickstart_clients() );
		$this->assertSame(
			array( 'chatgpt', 'claude', 'claude-code', 'cursor', 'vscode', 'windsurf', 'gemini-cli', 'manus', 'generic' ),
			$slugs
		);
	}

	public function test_chatgpt_claude_and_manus_are_oauth_native_hosted_web_apps(): void {
		$this->assertSame( 'native', aafm_oauth_client_mode( 'chatgpt' ) );
		$this->assertSame( 'native', aafm_oauth_client_mode( 'claude' ) );
		// Manus is a cloud-hosted agent (OAuth-by-URL connectors, no local stdio bridge), so it is a
		// hosted web app in native URL mode - not a bridge client.
		$this->assertSame( 'native', aafm_oauth_client_mode( 'manus' ) );
		$this->assertTrue( aafm_client_is_hosted_web_app( 'chatgpt' ) );
		$this->assertTrue( aafm_client_is_hosted_web_app( 'claude' ) );
		$this->assertTrue( aafm_client_is_hosted_web_app( 'manus' ) );
		// A proxy client is native but not a hosted web app.
		$this->assertFalse( aafm_client_is_hosted_web_app( 'claude-code' ) );
	}

	public function test_chatgpt_oauth_panel_shows_the_endpoint_url_not_a_stdio_snippet(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		// Slice out the ChatGPT OAuth panel (between its marker and the next panel, Claude).
		$start = strpos( $html, 'aafm-oauth-panel" data-client="chatgpt"' );
		$this->assertNotFalse( $start, 'ChatGPT OAuth panel not found.' );
		$next = strpos( $html, 'aafm-oauth-panel" data-client="claude"', $start + 1 );
		$this->assertNotFalse( $next, 'Claude OAuth panel (the next panel) not found.' );
		$panel = substr( $html, $start, $next - $start );

		// The panel offers the endpoint URL to paste...
		$this->assertStringContainsString( esc_html( aafm_endpoint_url() ), $panel );
		$this->assertStringContainsString( 'Developer mode', $panel );
		// ...and never an mcp-remote stdio config (a hosted web app cannot run one).
		$this->assertStringNotContainsString( 'mcp-remote', $panel );
		$this->assertStringNotContainsString( 'npx', $panel );
	}

	public function test_snippet_builders_self_guard_against_hosted_web_apps(): void {
		// Defense in depth: the config-snippet builders must never hand back an mcp-remote config
		// for a hosted web app, even when called directly with such a slug.
		$this->assertSame( '', aafm_client_snippet( 'chatgpt', 'mcp-agent', 'unix' ) );
		$this->assertSame( '', aafm_client_snippet( 'chatgpt', 'mcp-agent', 'windows' ) );
		$this->assertSame( '', aafm_client_snippet( 'claude', 'mcp-agent', 'unix' ) );
		$this->assertSame( '', aafm_client_snippet( 'manus', 'mcp-agent', 'unix' ) );
		$this->assertSame( '', aafm_oauth_client_snippet( 'chatgpt', 'unix' ) );
		$this->assertSame( '', aafm_oauth_client_snippet( 'claude', 'windows' ) );
		$this->assertSame( '', aafm_oauth_client_snippet( 'manus', 'unix' ) );

		// A non-hosted client still builds a real snippet.
		$this->assertStringContainsString( 'mcp-remote', aafm_oauth_client_snippet( 'claude-code', 'unix' ) );
		$this->assertStringContainsString( 'mcpServers', aafm_client_snippet( 'claude-code', 'mcp-agent', 'unix' ) );
	}

	public function test_default_os_tabs_carry_is_active_without_stray_on_class(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		// The default (unix) OS tab in both the OAuth card and the App-Password fallback must carry
		// only the class its JS handler (#bindOsTabs) manages - is-active - and never the stale 'on'
		// token. #bindOsTabs toggles is-active only, so a leftover 'on' (which the CSS also treats as
		// active) would keep macOS highlighted after clicking Windows. Guards render/JS desync.
		$this->assertStringNotContainsString( 'aafm-os-tab is-active on', $html );
		$this->assertSame( 2, substr_count( $html, 'class="aafm-os-tab is-active" data-os="unix"' ) );
	}

	public function test_app_password_grid_excludes_hosted_web_apps(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		// The App-Password mcp-remote grid must not offer a card for a hosted web app.
		$ap_start = strpos( $html, 'id="aafm-clients"' );
		$this->assertNotFalse( $ap_start );
		$ap_region = substr( $html, $ap_start );
		$this->assertStringNotContainsString( 'data-client="chatgpt"', $ap_region );
		$this->assertStringNotContainsString( 'data-client="claude"', $ap_region );
		$this->assertStringNotContainsString( 'data-client="manus"', $ap_region );
		// A proxy client is still present in that grid.
		$this->assertStringContainsString( 'data-client="claude-code"', $ap_region );
	}

	public function test_oauth_off_notice_names_the_hosted_web_apps(): void {
		// With OAuth off, hosted web apps (ChatGPT, Claude, Manus) have no connection path at all -
		// they cannot use the Application Password bridge - so the notice must call them out by name.
		update_option( 'aafm_oauth_enabled', '' );
		$html = $this->render_connection_tab();

		$this->assertStringContainsString( 'aafm-oauth-off-hosted', $html );
		$this->assertStringContainsString( 'ChatGPT', $html );
		$this->assertStringContainsString( 'Claude', $html );
		$this->assertStringContainsString( 'Manus', $html );
		$this->assertStringContainsString( 'OAuth only', $html );
	}

	public function test_connection_tab_leads_with_oauth_when_enabled(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		ob_start();
		aafm_render_connection_tab();
		$html = (string) ob_get_clean();
		// OAuth section is present and marked recommended.
		$this->assertStringContainsString( 'Connect with OAuth', $html );
		$this->assertStringContainsString( 'Recommended', $html );
		// App Password path is present as a collapsed fallback (<details>).
		$this->assertStringContainsString( '<details', $html );
		$this->assertStringContainsString( 'Application Password', $html );
		// Endpoint still shown once.
		$this->assertStringContainsString( aafm_endpoint_url(), $html );
	}

	public function test_connection_tab_oauth_disabled_expands_app_password(): void {
		update_option( 'aafm_oauth_enabled', '0' );
		ob_start();
		aafm_render_connection_tab();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Application Password', $html );
		// The fallback renders open when OAuth is off.
		$this->assertMatchesRegularExpression( '/<details[^>]*\bopen\b/', $html );
		update_option( 'aafm_oauth_enabled', '1' ); // Restore default state.
	}

	/**
	 * Regression guard: the OAuth snippet section must never contain Application Password
	 * credential placeholders, and the App-Password fallback must contain them.
	 *
	 * This locks the critical Defect 1 bug: the App-Password client picker used to overwrite
	 * OAuth snippet content because it selected .aafm-snippet[data-os] globally.  The server-
	 * rendered markup enforces the structural separation the JS scoping relies on.
	 */
	public function test_oauth_section_is_credential_free_and_app_password_section_carries_placeholder(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		// Isolate the OAuth card (everything inside .aafm-oauth-card).
		$oauth_start = strpos( $html, 'aafm-oauth-card' );
		$this->assertNotFalse( $oauth_start, 'OAuth card wrapper not found in rendered HTML.' );

		// Isolate the App-Password fallback (everything inside .aafm-app-password-fallback).
		$ap_start = strpos( $html, 'aafm-app-password-fallback' );
		$this->assertNotFalse( $ap_start, 'App-Password fallback wrapper not found in rendered HTML.' );

		// The two sections must be structurally separate - AP fallback follows OAuth card.
		$this->assertGreaterThan( $oauth_start, $ap_start, 'App-Password fallback must come after the OAuth card.' );

		// Extract the OAuth card region (up to where the app-password fallback starts).
		$oauth_region = substr( $html, $oauth_start, $ap_start - $oauth_start );

		// The OAuth region must be entirely credential-free.
		$this->assertStringNotContainsString(
			'WP_API_PASSWORD',
			$oauth_region,
			'OAuth card must not contain WP_API_PASSWORD - OAuth needs no stored secret.'
		);
		$this->assertStringNotContainsString(
			'PASTE-APPLICATION-PASSWORD-HERE',
			$oauth_region,
			'OAuth card must not contain the app-password placeholder.'
		);
		$this->assertStringNotContainsString(
			'WP_API_USERNAME',
			$oauth_region,
			'OAuth card must not contain WP_API_USERNAME.'
		);

		// The App-Password region must contain the credential placeholder.
		$ap_region = substr( $html, $ap_start );
		$this->assertStringContainsString(
			'PASTE-APPLICATION-PASSWORD-HERE',
			$ap_region,
			'App-Password fallback must contain the credential paste placeholder.'
		);
	}

	/**
	 * Regression guard: the OAuth card must render BOTH OS snippet variants so the OAuth
	 * OS toggle has something to switch to.
	 *
	 * Locks the regression where the OS-tab handler was scoped to .aafm-oauth-picker, which
	 * holds only the tabs - the snippets live in the sibling .aafm-oauth-panels, so a Windows
	 * user on the recommended OAuth path was shown the macOS/Linux command. Asserting both
	 * variants render (npx for unix, cmd for windows) proves the toggle has valid targets in
	 * the card the JS now scopes to (.aafm-oauth-card).
	 */
	public function test_oauth_card_renders_both_os_snippet_variants(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		$oauth_start = strpos( $html, 'aafm-oauth-card' );
		$ap_start    = strpos( $html, 'aafm-app-password-fallback' );
		$this->assertNotFalse( $oauth_start, 'OAuth card wrapper not found.' );
		$this->assertNotFalse( $ap_start, 'App-Password fallback wrapper not found.' );
		$oauth_region = substr( $html, $oauth_start, $ap_start - $oauth_start );

		// Both OS variants must be present in the OAuth card.
		$this->assertStringContainsString( 'data-os="unix"', $oauth_region, 'OAuth card missing the unix snippet variant.' );
		$this->assertStringContainsString( 'data-os="windows"', $oauth_region, 'OAuth card missing the windows snippet variant.' );

		// The two variants must genuinely differ: unix launches npx directly, windows wraps cmd.
		$this->assertStringContainsString( 'npx', $oauth_region, 'OAuth unix snippet should launch npx.' );
		$this->assertStringContainsString( 'cmd', $oauth_region, 'OAuth windows snippet should wrap the launcher in cmd.' );
	}

	/**
	 * Regression guard: the <details> wrapper for the App-Password fallback must be
	 * balanced - the number of <details> opens in the fallback region must equal the
	 * number of </details> closes in that region.
	 *
	 * Finds the full <details ...aafm-app-password-fallback...> tag so the region
	 * starts at the opening angle-bracket and includes the opening tag itself.
	 */
	public function test_app_password_fallback_details_element_is_balanced(): void {
		update_option( 'aafm_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		// Find the <details that carries aafm-app-password-fallback.
		// preg_match with PREG_OFFSET_CAPTURE gives us the byte offset of the match start.
		$matched = preg_match( '/<details[^>]*aafm-app-password-fallback[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE );
		$this->assertSame( 1, $matched, 'Exactly one <details class="aafm-app-password-fallback"> must exist.' );

		$region_start = $m[0][1]; // Byte offset of the match.
		$ap_region    = substr( $html, $region_start );

		$opens  = substr_count( $ap_region, '<details' );
		$closes = substr_count( $ap_region, '</details>' );

		$this->assertSame(
			$opens,
			$closes,
			sprintf(
				'<details> open/close mismatch in .aafm-app-password-fallback region: %d open(s), %d close(s). The outer </details> is likely missing.',
				$opens,
				$closes
			)
		);
	}
}
