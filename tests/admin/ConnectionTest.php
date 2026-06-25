<?php
/**
 * Connection tab logic: diagnostics checks, agent-user creation, snippet building.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;
use WP_Error;

final class ConnectionTest extends TestCase {

	public function test_diagnostics_report_adapter_and_endpoint(): void {
		// Note: we do NOT fire rest_api_init here. The adapter creates its server (a
		// process-global, once-per-ID resource) on that action, so re-firing it across the
		// suite trips an "ID already exists" incorrect-usage notice. The diagnostic shape is
		// what matters; the endpoint check legitimately reports pass or fail either way.
		$checks = oversio_diagnostic_checks();
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
		$result = oversio_create_agent_user( 'mcp-agent' );
		$this->assertIsArray( $result );
		$this->assertIsInt( $result['user_id'] );
		$user = get_userdata( $result['user_id'] );
		$this->assertContains( 'subscriber', $user->roles );
		$this->assertNotContains( 'administrator', $user->roles );
		$this->assertNotContains( 'editor', $user->roles );
	}

	public function test_create_agent_user_rejects_duplicate_login(): void {
		$this->acting_as( 'administrator' );
		oversio_create_agent_user( 'dupe-agent' );
		$again = oversio_create_agent_user( 'dupe-agent' );
		$this->assertInstanceOf( WP_Error::class, $again );
	}

	/**
	 * T3-1: the agent-user AJAX handler must gate on manage_options, not create_users. A
	 * non-admin custom role holding only create_users (plus the nonce) must be denied.
	 */
	public function test_create_agent_user_ajax_requires_manage_options(): void {
		add_role(
			'oversio_creator_test',
			'OVERSIO Creator Test',
			array(
				'read'         => true,
				'create_users' => true,
			)
		);
		$creator = $this->factory->user->create( array( 'role' => 'oversio_creator_test' ) );
		wp_set_current_user( $creator );

		$this->assertTrue( current_user_can( 'create_users' ), 'Fixture: the role must hold create_users.' );
		$this->assertFalse( current_user_can( 'manage_options' ), 'Fixture: the role must NOT hold manage_options.' );

		// Route wp_send_json through wp_die (not a bare die) and make that throw, so the JSON 403
		// short-circuit is observable in-process instead of exiting. Swallow the echoed body.
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'oversio-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );

		$nonce             = wp_create_nonce( 'oversio_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce; // check_ajax_referer() reads $_REQUEST.
		$_POST['login']    = 'should-not-be-created';

		$before = (int) count_users()['total_users'];
		$thrown = false;
		ob_start();
		try {
			oversio_ajax_create_agent_user();
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
		remove_role( 'oversio_creator_test' );

		$this->assertTrue( $thrown, 'A create_users-only user must be denied (the handler must die).' );
		$this->assertSame( $before, $after, 'No agent user may be created for a non-manage_options user.' );
	}

	public function test_client_snippet_points_at_endpoint_and_username(): void {
		$snippet = oversio_client_snippet( 'claude', 'mcp-agent' );
		$this->assertStringContainsString( rest_url( 'oversio-agent-abilities/mcp' ), $snippet );
		$this->assertStringContainsString( 'mcp-agent', $snippet );
		// The wizard never embeds a real secret — only the paste placeholder.
		$this->assertStringContainsString( 'PASTE-APPLICATION-PASSWORD-HERE', $snippet );
	}

	public function test_unix_snippet_launches_npx_directly(): void {
		$cfg    = json_decode( oversio_client_snippet( 'claude', 'mcp-agent', 'unix' ), true );
		$server = $cfg['mcpServers']['agent-abilities'];
		$this->assertSame( 'npx', $server['command'] );
		$this->assertSame( array( '-y', '@automattic/mcp-wordpress-remote@latest' ), $server['args'] );
	}

	public function test_windows_snippet_wraps_launcher_in_cmd(): void {
		$cfg    = json_decode( oversio_client_snippet( 'claude', 'mcp-agent', 'windows' ), true );
		$server = $cfg['mcpServers']['agent-abilities'];
		$this->assertSame( 'cmd', $server['command'] );
		$this->assertSame(
			array( '/c', 'npx', '-y', '@automattic/mcp-wordpress-remote@latest' ),
			$server['args']
		);
	}

	public function test_local_site_snippet_carries_tls_bypass(): void {
		add_filter( 'oversio_site_is_local', '__return_true' );
		$cfg = json_decode( oversio_client_snippet( 'claude', 'mcp-agent' ), true );
		remove_filter( 'oversio_site_is_local', '__return_true' );
		$env = $cfg['mcpServers']['agent-abilities']['env'];
		$this->assertSame( '0', $env['NODE_TLS_REJECT_UNAUTHORIZED'] );
	}

	public function test_production_site_snippet_omits_tls_bypass(): void {
		add_filter( 'oversio_site_is_local', '__return_false' );
		$cfg = json_decode( oversio_client_snippet( 'claude', 'mcp-agent' ), true );
		remove_filter( 'oversio_site_is_local', '__return_false' );
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
		oversio_render_connection_tab();
		return (string) ob_get_clean();
	}

	public function test_connection_tab_renders_guided_three_step_layout(): void {
		$html = $this->render_connection_tab();

		// New Direction A structure: stepper, client picker, diagnostics rail.
		$this->assertStringContainsString( 'oversio-step-head', $html );
		$this->assertStringContainsString( 'oversio-client-grid', $html );
		$this->assertStringContainsString( 'oversio-diag', $html );

		// The endpoint URL is shown verbatim.
		$this->assertStringContainsString( esc_html( rest_url( 'oversio-agent-abilities/mcp' ) ), $html );

		// The proxy package name survives in the primary config block.
		$this->assertStringContainsString( '@automattic/mcp-wordpress-remote', $html );
	}

	public function test_connection_tab_preserves_js_contract_ids(): void {
		$html = $this->render_connection_tab();

		// The create-agent-user controls keep their exact ids/classes for admin.js.
		$this->assertStringContainsString( 'id="oversio-agent-login"', $html );
		$this->assertStringContainsString( 'id="oversio-create-user"', $html );
		$this->assertStringContainsString( 'oversio-user-status', $html );

		// The test-connection controls keep their exact ids/classes.
		$this->assertStringContainsString( 'id="oversio-test-connection"', $html );
		$this->assertStringContainsString( 'oversio-test-status', $html );

		// OS toggle + OS-keyed snippet boxes the OS-tab handler binds to.
		$this->assertStringContainsString( 'oversio-os-tab', $html );
		$this->assertStringContainsString( 'data-os="unix"', $html );
		$this->assertStringContainsString( 'data-os="windows"', $html );

		// Per-client quickstart toggle + grid the quickstart handler binds to.
		$this->assertStringContainsString( 'oversio-quickstart-toggle', $html );
		$this->assertStringContainsString( 'id="oversio-quickstart-grid"', $html );

		// Copy buttons keep their hook class.
		$this->assertStringContainsString( 'oversio-copy', $html );
	}

	public function test_connection_tab_keeps_diagnostic_labels_and_platform_notes(): void {
		$html = $this->render_connection_tab();

		// Diagnostic labels survive the restyle.
		$this->assertStringContainsString( 'MCP adapter active and compatible', $html );
		$this->assertStringContainsString( 'MCP REST endpoint registered', $html );
		$this->assertStringContainsString( 'Authorization header reaches WordPress', $html );

		// Diagnostic rows map state to the status-dot classes.
		$this->assertStringContainsString( 'oversio-diag-row', $html );
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
		foreach ( oversio_quickstart_clients() as $slug => $label ) {
			$this->assertStringContainsString( esc_html( $label ), $html, "client {$slug} label missing from render" );
		}

		// VS Code's distinct "servers" key proves per-client shaping reaches the markup.
		$this->assertStringContainsString( 'servers', $html );
	}

	public function test_connection_tab_shows_the_endpoint_once_with_oauth_on(): void {
		// OAuth on (the default) used to render the endpoint twice: once in the OAuth card
		// and once in the standalone endpoint card. The endpoint label is now shown exactly
		// once — in the canonical endpoint card.
		update_option( 'oversio_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		$this->assertSame( 1, substr_count( $html, 'oversio-endpoint-card' ) );
		$this->assertSame( 1, substr_count( $html, '>MCP endpoint<' ) );
		// And the endpoint URL itself appears once in a copyable endpoint field.
		$this->assertSame( 1, substr_count( $html, 'oversio-field-mono' ) );
	}

	public function test_connection_tab_steps_share_an_alignment_class(): void {
		$html = $this->render_connection_tab();
		// The three numbered steps each carry the shared padding/alignment class so their
		// left and right edges line up top to bottom.
		$this->assertSame( 3, substr_count( $html, 'oversio-conn-step' ) );
	}

	public function test_oauth_client_snippet_carries_no_credentials(): void {
		$snippet = oversio_oauth_client_snippet( 'claude-desktop', 'unix' );
		$this->assertStringContainsString( 'mcp-remote', $snippet );
		$this->assertStringContainsString( oversio_endpoint_url(), $snippet );
		// OAuth = browser approval, never a stored secret.
		$this->assertStringNotContainsString( 'WP_API_PASSWORD', $snippet );
		$this->assertStringNotContainsString( 'WP_API_USERNAME', $snippet );
		$this->assertStringNotContainsString( 'PASTE-APPLICATION-PASSWORD-HERE', $snippet );
	}

	public function test_oauth_client_snippet_local_adds_ca_placeholder(): void {
		add_filter( 'oversio_site_is_local', '__return_true' );
		$snippet = oversio_oauth_client_snippet( 'claude-desktop', 'unix' );
		remove_filter( 'oversio_site_is_local', '__return_true' );
		$this->assertStringContainsString( 'NODE_EXTRA_CA_CERTS', $snippet );
		// Path is machine-specific — placeholder only, never a hardcoded real path.
		$this->assertStringContainsString( 'PATH-TO-YOUR-mkcert-rootCA.pem', $snippet );
	}

	public function test_oauth_client_snippet_production_omits_env(): void {
		add_filter( 'oversio_site_is_local', '__return_false' );
		$snippet = oversio_oauth_client_snippet( 'claude-desktop', 'unix' );
		remove_filter( 'oversio_site_is_local', '__return_false' );
		$this->assertStringNotContainsString( 'NODE_EXTRA_CA_CERTS', $snippet );
	}

	public function test_oauth_client_snippet_windows_wraps_cmd(): void {
		$snippet = oversio_oauth_client_snippet( 'claude-desktop', 'windows' );
		$this->assertStringContainsString( 'cmd', $snippet );
		$this->assertStringContainsString( '/c', $snippet );
	}

	public function test_oauth_client_snippet_vscode_uses_servers_key(): void {
		$vscode  = oversio_oauth_client_snippet( 'vscode', 'unix' );
		$generic = oversio_oauth_client_snippet( 'generic', 'unix' );
		$this->assertStringContainsString( '"servers"', $vscode );
		$this->assertStringContainsString( '"mcpServers"', $generic );
	}

	public function test_oauth_client_mode_known_for_every_client(): void {
		foreach ( array_keys( oversio_quickstart_clients() ) as $slug ) {
			$this->assertContains( oversio_oauth_client_mode( $slug ), array( 'native', 'bridge' ), $slug );
		}
	}

	public function test_oauth_client_note_present_for_every_client(): void {
		foreach ( array_keys( oversio_quickstart_clients() ) as $slug ) {
			$this->assertNotSame( '', oversio_oauth_client_note( $slug ), $slug );
		}
		$this->assertSame( '', oversio_oauth_client_note( 'does-not-exist' ) );
	}

	public function test_connection_tab_leads_with_oauth_when_enabled(): void {
		update_option( 'oversio_oauth_enabled', '1' );
		ob_start();
		oversio_render_connection_tab();
		$html = (string) ob_get_clean();
		// OAuth section is present and marked recommended.
		$this->assertStringContainsString( 'Connect with OAuth', $html );
		$this->assertStringContainsString( 'Recommended', $html );
		// App Password path is present as a collapsed fallback (<details>).
		$this->assertStringContainsString( '<details', $html );
		$this->assertStringContainsString( 'Application Password', $html );
		// Endpoint still shown once.
		$this->assertStringContainsString( oversio_endpoint_url(), $html );
	}

	public function test_connection_tab_oauth_disabled_expands_app_password(): void {
		update_option( 'oversio_oauth_enabled', '0' );
		ob_start();
		oversio_render_connection_tab();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Application Password', $html );
		// The fallback renders open when OAuth is off.
		$this->assertMatchesRegularExpression( '/<details[^>]*\bopen\b/', $html );
		update_option( 'oversio_oauth_enabled', '1' ); // Restore default state.
	}

	/**
	 * Regression guard: the OAuth snippet section must never contain Application Password
	 * credential placeholders, and the App-Password fallback must contain them.
	 *
	 * This locks the critical Defect 1 bug: the App-Password client picker used to overwrite
	 * OAuth snippet content because it selected .oversio-snippet[data-os] globally.  The server-
	 * rendered markup enforces the structural separation the JS scoping relies on.
	 */
	public function test_oauth_section_is_credential_free_and_app_password_section_carries_placeholder(): void {
		update_option( 'oversio_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		// Isolate the OAuth card (everything inside .oversio-oauth-card).
		$oauth_start = strpos( $html, 'oversio-oauth-card' );
		$this->assertNotFalse( $oauth_start, 'OAuth card wrapper not found in rendered HTML.' );

		// Isolate the App-Password fallback (everything inside .oversio-app-password-fallback).
		$ap_start = strpos( $html, 'oversio-app-password-fallback' );
		$this->assertNotFalse( $ap_start, 'App-Password fallback wrapper not found in rendered HTML.' );

		// The two sections must be structurally separate — AP fallback follows OAuth card.
		$this->assertGreaterThan( $oauth_start, $ap_start, 'App-Password fallback must come after the OAuth card.' );

		// Extract the OAuth card region (up to where the app-password fallback starts).
		$oauth_region = substr( $html, $oauth_start, $ap_start - $oauth_start );

		// The OAuth region must be entirely credential-free.
		$this->assertStringNotContainsString(
			'WP_API_PASSWORD',
			$oauth_region,
			'OAuth card must not contain WP_API_PASSWORD — OAuth needs no stored secret.'
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
	 * Locks the regression where the OS-tab handler was scoped to .oversio-oauth-picker, which
	 * holds only the tabs — the snippets live in the sibling .oversio-oauth-panels, so a Windows
	 * user on the recommended OAuth path was shown the macOS/Linux command. Asserting both
	 * variants render (npx for unix, cmd for windows) proves the toggle has valid targets in
	 * the card the JS now scopes to (.oversio-oauth-card).
	 */
	public function test_oauth_card_renders_both_os_snippet_variants(): void {
		update_option( 'oversio_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		$oauth_start = strpos( $html, 'oversio-oauth-card' );
		$ap_start    = strpos( $html, 'oversio-app-password-fallback' );
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
	 * balanced — the number of <details> opens in the fallback region must equal the
	 * number of </details> closes in that region.
	 *
	 * Finds the full <details ...oversio-app-password-fallback...> tag so the region
	 * starts at the opening angle-bracket and includes the opening tag itself.
	 */
	public function test_app_password_fallback_details_element_is_balanced(): void {
		update_option( 'oversio_oauth_enabled', '1' );
		$html = $this->render_connection_tab();

		// Find the <details that carries oversio-app-password-fallback.
		// preg_match with PREG_OFFSET_CAPTURE gives us the byte offset of the match start.
		$matched = preg_match( '/<details[^>]*oversio-app-password-fallback[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE );
		$this->assertSame( 1, $matched, 'Exactly one <details class="oversio-app-password-fallback"> must exist.' );

		$region_start = $m[0][1]; // Byte offset of the match.
		$ap_region    = substr( $html, $region_start );

		$opens  = substr_count( $ap_region, '<details' );
		$closes = substr_count( $ap_region, '</details>' );

		$this->assertSame(
			$opens,
			$closes,
			sprintf(
				'<details> open/close mismatch in .oversio-app-password-fallback region: %d open(s), %d close(s). The outer </details> is likely missing.',
				$opens,
				$closes
			)
		);
	}
}
