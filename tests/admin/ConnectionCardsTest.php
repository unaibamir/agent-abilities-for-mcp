<?php
/**
 * Tests for the Connection tab's OAuth and Application Passwords tabs and their cards.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

/**
 * Covers aafm_connection_clients_data(), aafm_connection_grants_data(), the card renderers and
 * aafm_render_connection_tabs().
 */
class ConnectionCardsTest extends TestCase {

	use AjaxHarness;

	private const CLIENT = 'client_cards';

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		update_option( 'aafm_oauth_enabled', '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		unset( $_GET['grants_page'] );
		delete_option( 'aafm_oauth_enabled' );
		parent::tear_down();
	}

	/**
	 * Seed one client and one consent row, with optional v9 column values.
	 *
	 * @param array<string,mixed> $consent Extra consent columns.
	 * @return int The approving user's id.
	 */
	private function seed_grant( array $consent = array() ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => self::CLIENT,
				'client_name' => 'Cards client',
				'is_active'   => 1,
			)
		);
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array_merge(
				array(
					'wp_user_id' => $user,
					'client_id'  => self::CLIENT,
				),
				$consent
			)
		);
		return $user;
	}

	private function render( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	public function test_clients_and_grants_data_are_plain_serialisable_arrays(): void {
		$this->seed_grant();
		foreach ( array( aafm_connection_clients_data(), aafm_connection_grants_data() ) as $data ) {
			$this->assertSame( $data, json_decode( (string) wp_json_encode( $data ), true ) );
			$this->assertSame( 1, $data['count'] );
			foreach ( array( 'title', 'rule' ) as $key ) {
				$this->assertSame( wp_strip_all_tags( $data[ $key ] ), $data[ $key ] );
			}
		}
	}

	public function test_the_clients_rule_sentence_states_the_real_cleanup_rule(): void {
		$rule = aafm_connection_clients_data()['rule'];
		$this->assertStringContainsString( human_time_diff( 0, 7 * DAY_IN_SECONDS ), $rule );
		$this->assertStringContainsString( 'nobody approved', $rule );
		$this->assertStringContainsString( 'until you revoke', $rule );

		add_filter( 'aafm_oauth_client_reap_ttl', static fn() => 3 * DAY_IN_SECONDS );
		$this->assertStringContainsString( human_time_diff( 0, 3 * DAY_IN_SECONDS ), aafm_connection_clients_data()['rule'] );
	}

	public function test_the_grants_rule_sentence(): void {
		$this->assertSame(
			'Each row is one person who approved one app. Revoking signs that app out for that person at once.',
			aafm_connection_grants_data()['rule']
		);
	}

	public function test_empty_states_render_a_title_and_a_hint_for_both_cards(): void {
		$html = $this->render( static fn() => aafm_render_clients_card( aafm_connection_clients_data() ) )
			. $this->render( static fn() => aafm_render_grants_card( aafm_connection_grants_data() ) );
		$this->assertStringContainsString( '<div class="aafm-empty-state"><strong>No registered clients yet</strong><br>When an AI app registers over OAuth, it shows up here.</div>', $html );
		$this->assertStringContainsString( '<div class="aafm-empty-state"><strong>No active grants yet</strong><br>When someone approves an AI app, the approval shows up here.</div>', $html );
	}

	public function test_grant_status_is_derived_from_state(): void {
		$cases = array(
			'active'  => array(),
			'paused'  => array( 'paused_at' => gmdate( 'Y-m-d H:i:s' ) ),
			'expired' => array(
				'paused_at'  => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			),
		);
		foreach ( $cases as $status => $consent ) {
			aafm_truncate_oauth_tables();
			$this->seed_grant( $consent );
			$this->assertSame( $status, aafm_connection_grants_data()['rows'][0]['status'], $status );
		}
		$html = $this->render( static fn() => aafm_render_grants_card( aafm_connection_grants_data() ) );
		$this->assertStringContainsString( '<span class="aafm-pill aafm-pill-neutral">Expired</span>', $html );
	}

	public function test_the_tab_bar_has_two_tabs_with_aria_wiring_and_the_right_default(): void {
		foreach ( array(
			'oauth'         => true,
			'app-passwords' => false,
		) as $active => $oauth_on ) {
			$html = $this->render( static fn() => aafm_render_connection_tabs( $oauth_on ) );
			$this->assertSame( 2, preg_match_all( '/<button type="button" role="tab"[^>]*data-conn-tab=/', $html ) );
			$this->assertSame( 2, substr_count( $html, 'role="tabpanel"' ) );
			foreach ( array( 'oauth', 'app-passwords' ) as $slug ) {
				$this->assertStringContainsString( 'aria-controls="aafm-conn-panel-' . $slug . '"', $html );
				$this->assertMatchesRegularExpression( '/<div role="tabpanel"[^>]*id="aafm-conn-panel-' . $slug . '" aria-labelledby="aafm-conn-tab-' . $slug . '"/', $html );
				$selected = $slug === $active ? 'true' : 'false';
				$this->assertMatchesRegularExpression( '/id="aafm-conn-tab-' . $slug . '"[^>]*aria-selected="' . $selected . '"/', $html );
				$hidden = $slug === $active ? '' : ' hidden';
				$this->assertMatchesRegularExpression( '/data-conn-panel="' . $slug . '"' . $hidden . '>/', $html );
			}
		}
	}

	public function test_the_old_accordion_is_gone_and_its_credential_warning_moved_into_the_tab(): void {
		$html = $this->render( 'aafm_render_connection_tab' );
		$this->assertStringNotContainsString( 'OAuth not working?', $html );
		$this->assertStringNotContainsString( '<details class="aafm-app-password-fallback', $html );
		$this->assertSame( 1, substr_count( $html, 'An Application Password is a whole-site WordPress credential' ) );
		$panel = substr( $html, (int) strpos( $html, 'data-conn-panel="app-passwords"' ) );
		$this->assertStringContainsString( 'An Application Password is a whole-site WordPress credential', $panel );
	}

	public function test_the_hooks_the_existing_script_binds_to_survive(): void {
		$this->seed_grant();
		$html = $this->render( 'aafm_render_connection_tab' );
		foreach ( array( 'class="aafm-oauth-manage"', 'name="aafm_oauth_admin_nonce"', 'data-client-row=', 'data-grant-row=', 'aafm-revoke-client', 'aafm-revoke-grant', 'aafm-clients-table', 'aafm-grants-table', 'aafm-client-agent-toggle', 'aafm-copy' ) as $hook ) {
			$this->assertStringContainsString( $hook, $html, $hook );
		}
	}

	public function test_rendering_the_whole_tab_writes_no_option(): void {
		global $wpdb;
		$this->seed_grant();
		$snapshot = static fn() => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'aafm\\_%' ORDER BY option_name", ARRAY_A );
		$before   = $snapshot();
		$this->render( 'aafm_render_connection_tab' );
		$this->assertSame( $before, $snapshot() );
	}

	public function test_the_endpoint_card_the_doctor_card_and_the_client_picker_stay_above_the_tabs(): void {
		$html = $this->render( 'aafm_render_connection_tab' );
		$tabs = strpos( $html, 'aafm-conn-tabs' );
		$this->assertNotFalse( $tabs );
		foreach ( array( 'aafm-endpoint-card', 'id="aafm-doctor"', 'aafm-oauth-picker' ) as $above ) {
			$at = strpos( $html, $above );
			$this->assertNotFalse( $at, $above );
			$this->assertLessThan( $tabs, $at, $above );
		}
		$this->assertGreaterThan( $tabs, strpos( $html, 'aafm-card-grants' ) );
	}

	public function test_the_app_passwords_panel_keeps_the_class_the_script_selects_on(): void {
		$html = $this->render( 'aafm_render_connection_tab' );
		$this->assertMatchesRegularExpression( '/<div role="tabpanel" class="[^"]*\baafm-app-password-fallback\b[^"]*"[^>]*data-conn-panel="app-passwords"/', $html );
		$panel = substr( $html, (int) strpos( $html, 'data-conn-panel="app-passwords"' ) );
		foreach ( array( 'id="aafm-create-user"', 'id="aafm-clients"', 'id="aafm-test-connection"' ) as $control ) {
			$this->assertStringContainsString( $control, $panel, $control );
		}
	}

	private function grants_html(): string {
		return $this->render( static fn() => aafm_render_grants_card( aafm_connection_grants_data() ) );
	}

	public function test_the_grants_table_has_status_access_last_used_expires_and_limit_columns(): void {
		$this->seed_grant();
		preg_match( '/<table[^>]*aafm-grants-table.*?<\/thead>/s', $this->grants_html(), $m );
		$this->assertNotEmpty( $m );
		preg_match_all( '/<th scope="col">(?:<span[^>]*>)?([^<]+)/', $m[0], $heads );
		$this->assertSame( array( 'User', 'Current role', 'App', 'Access', 'Last used', 'Ends', 'Limit', 'Status', 'Actions' ), $heads[1] );
	}

	/**
	 * The grants card is paged on the server, so a Revoke reloads the current page instead of
	 * recounting the rows left in it, and the reloaded page puts focus back on the card. There is no
	 * JS runner here: this pins the script's use of a one-use marker on both sides of the reload.
	 */
	public function test_revoking_a_grant_reloads_the_server_page_and_returns_focus(): void {
		$js = $this->admin_js();
		$at = strpos( $js, '#bindOauthRevoke() {' );
		$this->assertNotFalse( $at );
		$revoke = substr( $js, (int) $at, (int) strpos( $js, '#paginateTable(', (int) $at ) - (int) $at );
		$this->assertStringContainsString( "sessionStorage.setItem( 'aafmFocusGrants', '1' )", $revoke );
		$this->assertStringContainsString( 'window.location.reload()', $revoke );
		$this->assertStringNotContainsString( "querySelectorAll( '#aafm-card-grants tr[data-grant-row]' ).length", $revoke, 'The card count never comes from rendered rows.' );

		$at = strpos( $js, '#focusGrantsAfterReload() {' );
		$this->assertNotFalse( $at );
		$load = substr( $js, (int) $at, 700 );
		$this->assertStringContainsString( "sessionStorage.removeItem( 'aafmFocusGrants' )", $load );
		$this->assertStringContainsString( '#aafm-card-grants .aafm-card-head-title', $load );
		$this->assertStringContainsString( 'this.#focusGrantsAfterReload();', $js );
	}

	public function test_the_access_select_lists_the_four_presets_with_the_current_one_selected_and_a_custom_default_for_a_null_preset(): void {
		$this->seed_grant();
		$html = $this->grants_html();
		$this->assertStringContainsString( 'class="aafm-grant-preset" data-aafm-saved="custom"', $html );
		foreach ( array( 'read_only', 'content_editor', 'shop_manager', 'custom' ) as $slug ) {
			$this->assertStringContainsString( '<option value="' . $slug . '"', $html );
		}
		$this->assertMatchesRegularExpression( '/<option value="custom" selected/', $html );

		aafm_truncate_oauth_tables();
		$this->seed_grant( array( 'preset' => 'read_only' ) );
		$html = $this->grants_html();
		$this->assertStringContainsString( 'data-aafm-saved="read_only"', $html );
		$this->assertMatchesRegularExpression( '/<option value="read_only" selected/', $html );
	}

	public function test_each_preset_option_states_how_many_of_the_enabled_abilities_it_reaches(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/create-post', 'aafm/delete-post' ) );
		$this->seed_grant();
		$html = $this->grants_html();
		$read = aafm_preset_count( 'read_only' );
		$this->assertStringContainsString( 'Read only (up to ' . $read['allowed'] . ' of ' . $read['enabled'] . ')', $html );
		$this->assertStringContainsString( 'Custom (up to ' . $read['enabled'] . ' of ' . $read['enabled'] . ')', $html );
		$this->assertStringContainsString( 'Access presets only narrow what you have switched on under Abilities.', $html );
	}

	public function test_a_paused_row_shows_Resume_and_an_active_row_shows_Pause(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- names the button labels.
		$this->seed_grant();
		$this->assertMatchesRegularExpression( '/aafm-grant-pause"[^>]*data-paused="0"[^>]*>Pause</', $this->grants_html() );
		aafm_truncate_oauth_tables();
		$this->seed_grant( array( 'paused_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$this->assertMatchesRegularExpression( '/aafm-grant-pause"[^>]*data-paused="1"[^>]*>Resume</', $this->grants_html() );
	}

	public function test_last_used_shows_time_and_ip_or_Never(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- names the label.
		$this->seed_grant();
		$this->assertStringContainsString( '<span class="aafm-muted">Never</span>', $this->grants_html() );
		aafm_truncate_oauth_tables();
		$this->seed_grant(
			array(
				'last_used_at' => '2026-01-02 03:04:05',
				'last_used_ip' => '203.0.113.7',
			)
		);
		$html = $this->grants_html();
		$this->assertStringContainsString( esc_html( aafm_format_admin_datetime( '2026-01-02 03:04:05' ) ) . '<br><span class="aafm-muted">203.0.113.7</span>', $html );
	}

	public function test_an_expired_row_shows_the_Expired_pill_and_the_end_date(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- names the pill.
		$this->seed_grant( array( 'expires_at' => '2026-01-01 18:59:59' ) );
		$html = $this->grants_html();
		$this->assertStringContainsString( '<span class="aafm-pill aafm-pill-neutral">Expired</span>', $html );
		$this->assertStringContainsString( 'value="' . get_date_from_gmt( '2026-01-01 18:59:59', 'Y-m-d' ) . '"', $html );
		$this->assertStringContainsString( 'min="' . wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) . '"', $html );
	}

	public function test_refusal_message_has_one_line_under_the_table(): void {
		$this->seed_grant();
		$html = $this->grants_html();
		$this->assertSame( 1, preg_match_all( '/<p class="aafm-grants-msg" role="status" aria-live="polite" hidden><\/p>/', $html ) );
		$this->assertMatchesRegularExpression( '/<\/table><\/div><p class="aafm-grants-msg"/', $html, 'The message line sits right after the table wrapper.' );
		$this->assertStringNotContainsString( 'aafm-grant-status', $html );
		preg_match( '/<tbody>(.*?)<\/tbody>/s', $html, $body );
		$this->assertSame( count( aafm_connection_grants_data()['grants'] ?? array( 1 ) ), substr_count( $body[1], '<tr' ) );
	}

	public function test_an_editors_card_says_elevated_permissions(): void {
		$this->seed_grant();
		$html = $this->render( static fn() => aafm_render_grants_card( aafm_connection_grants_data() ) );
		$this->assertStringContainsString( 'This connection acts with elevated permissions.', $html );
		$this->assertStringNotContainsString( 'full administrator access', $html );
	}

	/**
	 * The bundled admin script.
	 *
	 * @return string
	 */
	private function admin_js(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test.
		return (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
	}

	/**
	 * Seed $count grants across five active clients, oldest first.
	 *
	 * @param int $count Grants to seed.
	 * @return list<array{0:int,1:string}> [user_id, client_id] pairs, oldest first.
	 */
	private function seed_grants( int $count ): array {
		global $wpdb;
		$clients = array( 'pg_a', 'pg_b', 'pg_c', 'pg_d', 'pg_e' );
		foreach ( $clients as $client ) {
			$wpdb->insert(
				$wpdb->prefix . 'aafm_oauth_clients',
				array(
					'client_id'   => $client,
					'client_name' => 'Paged ' . $client,
					'is_active'   => 1,
				)
			);
		}
		$pairs = array();
		foreach ( self::factory()->user->create_many( (int) ceil( $count / 5 ) ) as $user ) {
			foreach ( $clients as $client ) {
				if ( count( $pairs ) === $count ) {
					break 2;
				}
				$wpdb->insert(
					$wpdb->prefix . 'aafm_oauth_consents',
					array(
						'wp_user_id' => (int) $user,
						'client_id'  => $client,
					)
				);
				$pairs[] = array( (int) $user, $client );
			}
		}
		return $pairs;
	}

	/**
	 * Seed one active access token with the given audience.
	 *
	 * @param string $audience Stored resource.
	 * @return void
	 */
	private function seed_token( string $audience ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array(
				'token_hash'   => hash( 'sha256', 't' . wp_rand() ),
				'refresh_hash' => hash( 'sha256', 'r' . wp_rand() ),
				'client_id'    => self::CLIENT,
				'wp_user_id'   => 1,
				'resource'     => $audience,
				'is_active'    => 1,
			)
		);
	}

	public function test_grants_card_shows_page_2_of_3_with_links(): void {
		$this->seed_grants( 105 );
		$_GET['grants_page'] = '2';
		$html                = $this->grants_html();

		$this->assertStringContainsString( 'Active grants (105)', $html );
		$this->assertStringContainsString( 'Showing 51 to 100 of 105', $html );
		$this->assertSame( 1, preg_match( '/<a [^>]*href="[^"]*grants_page=1[^"]*"[^>]*>Previous<\/a>/', $html ), 'Previous links to page 1.' );
		$this->assertSame( 1, preg_match( '/<a [^>]*href="[^"]*grants_page=3[^"]*"[^>]*>Next<\/a>/', $html ), 'Next links to page 3.' );
		preg_match( '/<tbody>(.*?)<\/tbody>/s', $html, $body );
		$this->assertSame( 50, substr_count( $body[1], '<tr' ) );
	}

	public function test_grants_page_junk_renders_page_1(): void {
		$this->seed_grants( 105 );
		foreach ( array( 'abc', '0', '-5', array( '2' ), '' ) as $junk ) {
			$_GET['grants_page'] = $junk;
			$this->assertSame( 1, aafm_connection_grants_data()['page'], wp_json_encode( $junk ) );
		}
		$_GET['grants_page'] = '9999';
		$this->assertSame( 3, aafm_connection_grants_data()['page'], 'A page past the end clamps to the last page.' );
		$this->assertStringContainsString( 'Showing 101 to 105 of 105', $this->grants_html() );
	}

	public function test_grants_table_marked_server_paged(): void {
		$this->seed_grant();
		$this->assertMatchesRegularExpression( '/<table [^>]*class="[^"]*aafm-grants-table[^"]*"[^>]*data-aafm-server-paged="1"/', $this->grants_html() );
		$this->assertStringNotContainsString( 'aafm-grants-pager', $this->grants_html(), 'One page needs no pager.' );
	}

	public function test_rendered_grants_card_keeps_server_paged_after_kses(): void {
		$this->seed_grant();
		// The card body goes through aafm_render_section(), which filters it with aafm_admin_allowed_html().
		$this->assertStringContainsString( 'data-aafm-server-paged="1"', $this->grants_html() );
		$this->assertArrayHasKey( 'data-aafm-server-paged', aafm_admin_allowed_html()['table'] );
	}

	public function test_clients_and_grants_headers_have_scope_col(): void {
		$this->seed_grant();
		$html = $this->render( static fn() => aafm_render_clients_card( aafm_connection_clients_data() ) ) . $this->grants_html();
		$this->assertSame( 2, preg_match_all( '/<thead>(.*?)<\/thead>/s', $html, $heads ) );
		foreach ( $heads[1] as $head ) {
			$this->assertGreaterThan( 0, substr_count( $head, '<th' ) );
			$this->assertSame( substr_count( $head, '<th' ), substr_count( $head, '<th scope="col"' ) );
		}
	}

	public function test_stale_token_notice_shows_count_and_button_only_when_count_positive(): void {
		$this->seed_grant();
		$this->seed_token( aafm_endpoint_url() );
		$this->assertStringNotContainsString( 'aafm-revoke-stale-tokens', $this->grants_html() );

		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$html = $this->grants_html();
		$this->assertStringContainsString( '2 tokens were issued for an old address of this site', $html );
		$this->assertMatchesRegularExpression( '/<button type="button" class="[^"]*aafm-revoke-stale-tokens[^"]*"/', $html );
	}

	public function test_stale_token_action_requires_nonce_and_capability(): void {
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );

		$this->post_as( 'administrator' );
		$_POST['nonce']    = 'bad';
		$_REQUEST['nonce'] = 'bad';
		$this->assertNotTrue( $this->run_handler( 'aafm_ajax_revoke_stale_tokens' )['success'] ?? null );
		$this->assertSame( 1, aafm_oauth_stale_token_count(), 'A bad nonce changes nothing.' );

		$this->post_as( 'editor' );
		$json = $this->run_handler( 'aafm_ajax_revoke_stale_tokens' );
		$this->assertFalse( $json['success'] );
		$this->assertSame( 1, aafm_oauth_stale_token_count(), 'An editor changes nothing.' );
	}

	public function test_stale_token_action_reports_deactivated_count(): void {
		$this->seed_token( aafm_endpoint_url() );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );

		$this->post_as( 'administrator' );
		$json = $this->run_handler( 'aafm_ajax_revoke_stale_tokens' );
		$this->assertTrue( $json['success'] );
		$this->assertSame( 3, $json['data']['deactivated'] );
		$this->assertStringContainsString( '3 tokens', $json['data']['message'] );
		$this->assertSame( 0, aafm_oauth_stale_token_count() );
	}

	public function test_client_picker_is_a_labelled_radiogroup(): void {
		$html = $this->render( static fn() => aafm_render_client_picker( 'aafm-os-label-test', 'aafm-test-clients', aafm_quickstart_clients() ) );
		$this->assertMatchesRegularExpression( '/<div class="aafm-client-grid" id="aafm-test-clients" role="radiogroup" aria-labelledby="aafm-test-clients-label">/', $html );
		$this->assertStringContainsString( 'id="aafm-test-clients-label"', $html );
	}

	public function test_rendered_client_picker_keeps_radio_and_aria_checked_after_kses(): void {
		$html = $this->render( static fn() => aafm_render_client_picker( 'aafm-os-label-test', 'aafm-test-clients', aafm_quickstart_clients() ) );
		$this->assertSame( count( aafm_quickstart_clients() ), preg_match_all( '/<button type="button" class="aafm-client[^"]*"[^>]*role="radio"[^>]*aria-checked="(true|false)"[^>]*tabindex="(0|-1)"/', $html, $m ) );
		$this->assertSame( array( 'true' ), array_values( array_unique( array_filter( $m[1], static fn( $v ) => 'true' === $v ) ) ) );
		$this->assertSame( 1, substr_count( $html, 'aria-checked="true"' ), 'Exactly one client starts selected.' );
		$this->assertStringNotContainsString( 'aria-pressed', $html );
	}

	public function test_client_picker_script_keeps_aria_checked_and_arrow_keys(): void {
		$js = $this->admin_js();
		foreach ( array( '#bindClientPicker() {', '#bindOauthClientPicker() {' ) as $handler ) {
			$at = strpos( $js, $handler );
			$this->assertNotFalse( $at, $handler );
			$body = substr( $js, (int) $at, (int) strpos( $js, "\n\t\t}\n", (int) $at ) - (int) $at );
			$this->assertStringContainsString( "setAttribute( 'aria-checked'", $body, $handler );
			$this->assertStringNotContainsString( 'aria-pressed', $body, $handler );
			$this->assertStringContainsString( 'this.#wireTablistKeys(', $body, $handler . ' moves the selection with the arrow keys.' );
			$this->assertStringContainsString( 'this.#rovingTabindex(', $body, $handler );
		}
	}

	public function test_save_and_pause_work_on_a_grant_beyond_page_1(): void {
		$pairs = $this->seed_grants( 60 );
		$grant = $pairs[0]; // The oldest grant, number 60 newest first, on page 2.
		$this->assertNull(
			current( array_filter( aafm_connection_grants_data()['rows'], static fn( $r ) => $grant[0] === $r['user_id'] && $grant[1] === $r['client_id'] ) ) ?: null
		);

		$this->post_as(
			'administrator',
			array(
				'user_id'   => (string) $grant[0],
				'client_id' => $grant[1],
				'paused'    => '1',
			)
		);
		$json = $this->run_handler( 'aafm_ajax_oauth_update_grant' );
		$this->assertTrue( $json['success'] );
		$this->assertSame( 'paused', $json['data']['status'] );
		$this->assertSame( $grant[0], $json['data']['user_id'] );
	}

	public function test_switch_thumb_has_an_rtl_rule(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test.
		$css = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.css' );
		$this->assertMatchesRegularExpression( '/\[dir="rtl"\] &:not\(\.aafm-switch--lg\) input:checked \+ \.aafm-switch-track::before \{ transform: translateX\(-16px\); \}/', $css );
		$this->assertSame( 1, substr_count( $css, 'translateX(16px)' ), 'The physical offset appears once, beside its RTL twin.' );
	}

	public function test_grant_update_on_a_revoked_client_is_refused_and_writes_nothing(): void {
		global $wpdb;
		$user = $this->seed_grant();
		$wpdb->update( $wpdb->prefix . 'aafm_oauth_clients', array( 'is_active' => 0 ), array( 'client_id' => self::CLIENT ) );

		$this->post_as(
			'administrator',
			array(
				'user_id'   => (string) $user,
				'client_id' => self::CLIENT,
				'paused'    => '1',
			)
		);
		$json = $this->run_handler( 'aafm_ajax_oauth_update_grant' );
		$this->assertFalse( $json['success'] );
		$this->assertSame( 'Missing grant.', $json['data']['message'] );
		$this->assertFalse( aafm_oauth_grant_state( $user, self::CLIENT )['paused'], 'No pause lands on a grant the card no longer lists.' );
	}

	public function test_stale_token_action_logs_a_pluralised_row_and_a_failure_row(): void {
		global $wpdb;
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$this->post_as( 'administrator' );
		$this->assertTrue( $this->run_handler( 'aafm_ajax_revoke_stale_tokens' )['success'] );
		$detail = $wpdb->get_var( $wpdb->prepare( 'SELECT detail FROM %i WHERE ability = %s ORDER BY id DESC LIMIT 1', aafm_activity_log_table(), 'aafm/oauth-stale-tokens' ) );
		$this->assertStringContainsString( 'Ended 1 token issued for an old site address', (string) $detail );

		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$fail       = static fn( $query ) => false !== strpos( $query, 'SET is_active = 0 WHERE is_active = 1 AND id IN (' ) ? 'SELECT broken FROM nowhere' : $query;
		add_filter( 'query', $fail );
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$this->post_as( 'administrator' );
			$json = $this->run_handler( 'aafm_ajax_revoke_stale_tokens' );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppressed );
		}
		$this->assertFalse( $json['success'] );
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE ability = %s ORDER BY id DESC LIMIT 1', aafm_activity_log_table(), 'aafm/oauth-stale-tokens' ) );
		$this->assertSame( 'error', $status, 'A failed attempt leaves an error row.' );
	}

	public function test_client_revoke_reloads_when_the_grants_card_lists_that_client(): void {
		$js     = $this->admin_js();
		$at     = (int) strpos( $js, '#bindOauthRevoke() {' );
		$revoke = substr( $js, $at, (int) strpos( $js, '#paginateTable(', $at ) - $at );
		$this->assertStringContainsString( 'r.dataset.grantRow?.endsWith( `|${ clientId }` )', $revoke, 'A client revoke checks the grants card for that client\'s rows.' );
		$this->assertSame( 1, substr_count( $revoke, 'window.location.reload()' ), 'One reload path serves both revokes.' );
	}

	public function test_stale_token_success_removes_the_warning(): void {
		$js    = $this->admin_js();
		$at    = (int) strpos( $js, '#bindStaleTokens() {' );
		$stale = substr( $js, $at, 1600 );
		$this->assertStringContainsString( "btn.closest( '.aafm-stale-tokens' )?.querySelector( '.aafm-notice' )?.remove();", $stale, 'The warning goes once the tokens are ended.' );
	}
}
