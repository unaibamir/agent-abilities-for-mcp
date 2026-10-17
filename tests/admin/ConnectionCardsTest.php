<?php
/**
 * Tests for the Connection tab's OAuth and Application Passwords tabs and their cards.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

/**
 * Covers aafm_connection_clients_data(), aafm_connection_grants_data(), the card renderers and
 * aafm_render_connection_tabs().
 */
class ConnectionCardsTest extends TestCase {

	private const CLIENT = 'client_cards';

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		update_option( 'aafm_oauth_enabled', '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
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
		preg_match_all( '/<th>(?:<span[^>]*>)?([^<]+)/', $m[0], $heads );
		$this->assertSame( array( 'User', 'Current role', 'App', 'Access', 'Last used', 'Ends', 'Limit', 'Status', 'Actions' ), $heads[1] );
	}

	/**
	 * Revoking a grant removes its row, so the script must also lower the "(N)" in the card title.
	 * There is no JS runner here: this pins the markup the script reads and the script's use of it.
	 */
	public function test_revoking_a_grant_updates_the_card_title_count(): void {
		$this->seed_grant();
		$html = $this->grants_html();
		$this->assertMatchesRegularExpression( '/id="aafm-card-grants".*?<h2 class="aafm-card-head-title">Active grants \(1\)<\/h2>/s', $html );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled static asset from disk in a test.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		$at = strpos( $js, 'this.#adjustClientTokens( root, clientId, -revoked );' );
		$this->assertNotFalse( $at );
		$this->assertStringContainsString( '#aafm-card-grants .aafm-card-head-title', substr( $js, (int) $at, 600 ) );
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
		$this->assertStringContainsString( esc_html( aafm_format_admin_datetime( '2026-01-02 03:04:05' ) ) . ' <span class="aafm-muted">203.0.113.7</span>', $html );
	}

	public function test_an_expired_row_shows_the_Expired_pill_and_the_end_date(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- names the pill.
		$this->seed_grant( array( 'expires_at' => '2026-01-01 18:59:59' ) );
		$html = $this->grants_html();
		$this->assertStringContainsString( '<span class="aafm-pill aafm-pill-neutral">Expired</span>', $html );
		$this->assertStringContainsString( 'value="' . get_date_from_gmt( '2026-01-01 18:59:59', 'Y-m-d' ) . '"', $html );
		$this->assertStringContainsString( 'min="' . wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) . '"', $html );
	}
}
