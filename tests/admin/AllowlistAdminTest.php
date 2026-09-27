<?php
/**
 * The allowlist AJAX save handler: nonce/capability gating, row sanitization, and the row cap.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class AllowlistAdminTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_ability_allowlist_overrides' );
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_POST['allowlist_json'], $_REQUEST['nonce'] );
		parent::tear_down();
	}

	/**
	 * Route wp_send_json through a throwing wp_die so the handler is observable in-process.
	 * Mirrors OauthRevokeAjaxTest::intercept_die().
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
	 * Run the AJAX handler and return its captured JSON payload.
	 *
	 * @return array<string,mixed>
	 */
	private function run_handler(): array {
		ob_start();
		try {
			aafm_ajax_save_allowlist();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Register a real OAuth client (its id is an auto-generated 32-hex string, never a
	 * human-chosen one) so a test can submit an allowlist row that names an actual client.
	 *
	 * @return string The real client_id.
	 */
	private function register_real_oauth_client(): string {
		aafm_install_oauth_tables();
		$res = aafm_oauth_register_client(
			array(
				'redirect_uris' => array( 'https://app.example/cb' ),
				'client_name'   => 'Test Client',
			)
		);
		$this->assertIsArray( $res );
		return (string) $res['client_id'];
	}

	public function test_a_subscriber_is_refused(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode( array() );

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertFalse( $json['success'] ?? true );
		$this->assertSame( array(), aafm_allowlist_overrides() );
	}

	public function test_a_missing_or_invalid_nonce_is_refused(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['nonce']          = 'not-a-real-nonce';
		$_REQUEST['nonce']       = 'not-a-real-nonce';
		$_POST['allowlist_json'] = wp_json_encode( array() );

		$this->intercept_die();
		ob_start();
		try {
			aafm_ajax_save_allowlist();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		ob_end_clean();

		$this->assertSame( array(), aafm_allowlist_overrides() );
	}

	public function test_an_admin_can_save_a_valid_role_and_client_row(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$client_id = $this->register_real_oauth_client();

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/get-posts', 'aafm/get-posts' ), // duplicate, should dedupe.
				),
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => $client_id,
					'allowed_abilities' => 'all',
				),
			)
		);

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertTrue( $json['success'] ?? false );
		$stored = aafm_allowlist_overrides();
		$this->assertCount( 2, $stored );
		$this->assertSame( array( 'aafm/get-posts' ), $stored[0]['allowed_abilities'] );
		$this->assertSame( 'all', $stored[1]['allowed_abilities'] );
	}

	/**
	 * Codex round-b finding 8: two submitted rows for the same scope used to both reach storage,
	 * and aafm_ability_allowed_for_principal() only ever checks the first match - so an earlier
	 * permissive "all" row would silently defeat a later restrictive one, regardless of which one
	 * the operator actually meant to keep. Saving now keys rows by scope_type:scope_id so only
	 * the LAST submitted row for a given scope survives.
	 */
	public function test_a_duplicate_client_scope_keeps_only_the_last_row(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$client_id = $this->register_real_oauth_client();

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => $client_id,
					'allowed_abilities' => 'all',
				),
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => $client_id,
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertTrue( $json['success'] ?? false );
		$stored = aafm_allowlist_overrides();
		$this->assertCount( 1, $stored, 'Only one row may survive for a single scope.' );
		$this->assertSame( array( 'aafm/get-posts' ), $stored[0]['allowed_abilities'] );
	}

	/**
	 * Codex final round 3 MEDIUM (per the team lead's explicit fix, superseding an earlier
	 * drop-and-report design this lane had shipped first): a row naming an unknown role used to
	 * be silently dropped while the save still reported success, so a restriction the operator
	 * thought they'd applied never actually took effect. The whole save must be rejected instead,
	 * with the PREVIOUSLY stored option left untouched.
	 */
	public function test_a_row_with_an_unknown_role_rejects_the_whole_save(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$previous = array(
			array(
				'scope_type'        => 'role',
				'scope_id'          => 'editor',
				'allowed_abilities' => array( 'aafm/get-posts' ),
			),
		);
		update_option( 'aafm_ability_allowlist_overrides', $previous );

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'not-a-real-role',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertFalse( $json['success'] ?? true );
		$this->assertStringContainsString( 'Row 1', (string) ( $json['data']['message'] ?? '' ) );
		$this->assertSame( $previous, aafm_allowlist_overrides(), 'The previous option value must survive untouched.' );
	}

	/**
	 * Codex final round 2 MEDIUM, tightened per the team lead in round 3: a client id was
	 * accepted as arbitrary free text with no check that it named a real client. A mistyped id
	 * (a real client's id off by one character) matched no OAuth client row, so it added no
	 * restriction at all for that client - and per the allowlist's own intersection precedence,
	 * an unmatched client is unrestricted, i.e. the row silently failed open. The whole save must
	 * be rejected, not merely that one row dropped.
	 */
	public function test_a_row_with_a_mistyped_client_id_rejects_the_whole_save(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$client_id = $this->register_real_oauth_client();
		$mistyped  = substr( $client_id, 0, -1 ); // Off by one character - names no real client.
		$previous  = array(
			array(
				'scope_type'        => 'oauth_client',
				'scope_id'          => $client_id,
				'allowed_abilities' => 'all',
			),
		);
		update_option( 'aafm_ability_allowlist_overrides', $previous );

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => $mistyped,
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertFalse( $json['success'] ?? true );
		$this->assertStringContainsString( 'Row 1', (string) ( $json['data']['message'] ?? '' ) );
		$this->assertSame( $previous, aafm_allowlist_overrides(), 'The previous option value must survive untouched.' );
	}

	/**
	 * Codex final round 7 LOW: a row naming an ability slug not in the registry (typo, or a name
	 * from a removed integration) used to save successfully and then deny every real ability for
	 * that role at read time - the opposite of 228-allowlist-design.md section 6's own fail-closed
	 * statement. Reject the whole save, the same way an unknown role/client already is above.
	 */
	public function test_a_row_with_an_unknown_ability_slug_rejects_the_whole_save(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$previous = array(
			array(
				'scope_type'        => 'role',
				'scope_id'          => 'editor',
				'allowed_abilities' => array( 'aafm/get-posts' ),
			),
		);
		update_option( 'aafm_ability_allowlist_overrides', $previous );

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'author',
					'allowed_abilities' => array( 'aafm/get-postz-typo' ),
				),
			)
		);

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertFalse( $json['success'] ?? true );
		$this->assertStringContainsString( 'Row 1', (string) ( $json['data']['message'] ?? '' ) );
		$this->assertSame( $previous, aafm_allowlist_overrides(), 'The previous option value must survive untouched.' );
	}

	/**
	 * A LATER row's error must not be masked by earlier valid rows - the message names the real
	 * offending row, not always "row 1".
	 */
	public function test_the_rejection_names_the_actual_offending_row(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'not-a-real-role',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertFalse( $json['success'] ?? true );
		$this->assertStringContainsString( 'Row 2', (string) ( $json['data']['message'] ?? '' ) );
	}

	/**
	 * Codex admin-ui-r1 M3: the scope-type, role and OAuth-connection selects had no <label>,
	 * aria-label or aria-labelledby at all - the worst case of the finding, three adjacent
	 * controls with no accessible name between them.
	 */
	public function test_the_new_scope_selects_have_persistent_labels(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_allowlist_section();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<label class="screen-reader-text" for="aafm-allowlist-new-scope-type">', $html );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="aafm-allowlist-new-role">', $html );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="aafm-allowlist-new-client">', $html );
	}

	/**
	 * Makes the direct database SELECT aafm_read_option_views() issues for $option fail (not
	 * merely read absent), via QueryFaultInjector's real-error path. Mirrors
	 * OauthRevokeAjaxTest::fail_query_containing() / PairedSecurityWriteOrderTest::fail_option_read()
	 * / UpgradeMigrationTest::fail_option_read(), all now the same underlying filter.
	 *
	 * @param string $option Option name whose row-fetch query should fail.
	 * @return void
	 */
	private function fail_option_read( string $option ): void {
		add_filter( 'query', QueryFaultInjector::real_error_filter( "option_name = '{$option}'" ) );
	}

	/**
	 * R3-3 (1.7.5 deferred, round 3): a failed read of the allowlist option must never render as
	 * "No scopes narrowed yet" with Add/Save still available - that lookalike empty state is
	 * exactly what let a transient read failure turn into real data loss (see
	 * aafm_allowlist_overrides_for_display()'s docblock). Existing stored rows must survive
	 * untouched, the card must say the read failed, and Add/Save must be disabled rather than
	 * offering an editable empty table.
	 *
	 * What would break this: reverting the renderer to call aafm_allowlist_overrides() (or
	 * otherwise treat a failed read as an empty array) makes it print the ordinary empty-state
	 * paragraph with both controls enabled, and this test's assertions fail.
	 */
	public function test_a_failed_read_shows_an_error_and_disables_editing_instead_of_an_empty_table(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->fail_option_read( 'aafm_ability_allowlist_overrides' );
		ob_start();
		aafm_render_allowlist_section();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'No scopes narrowed yet', $html, 'A read failure must not look like a genuinely empty allowlist.' );
		$this->assertStringContainsString( 'could not be read', $html, 'The card must say the read failed.' );
		$this->assertMatchesRegularExpression( '/id="aafm-allowlist-add-row"[^>]*\bdisabled\b/', $html, 'Add scope must be disabled while the read state is unknown.' );
		$this->assertMatchesRegularExpression( '/id="aafm-allowlist-save"[^>]*\bdisabled\b/', $html, 'Save must be disabled while the read state is unknown.' );
	}

	/**
	 * Store an overrides row that is not a list, the malformed state.
	 *
	 * @return void
	 */
	private function plant_malformed_row(): void {
		global $wpdb;
		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => 'aafm_ability_allowlist_overrides',
				'option_value' => serialize( new \stdClass() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- test fixture: the malformed row shape.
				'autoload'     => 'off',
			)
		);
		wp_cache_delete( 'aafm_ability_allowlist_overrides', 'options' );
	}

	/**
	 * The allowlist card's HTML as the current user sees it.
	 *
	 * @return string
	 */
	private function render_card(): string {
		ob_start();
		aafm_render_allowlist_section();
		return (string) ob_get_clean();
	}

	/**
	 * Submit $rows to the save handler as an administrator and return its JSON.
	 *
	 * @param array<int,array<string,mixed>> $rows The rows to save.
	 * @return array<string,mixed>
	 */
	private function save_rows( array $rows ): array {
		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode( $rows );
		$this->intercept_die();
		return $this->run_handler();
	}

	/**
	 * A stored overrides row that is not a list denies every call and stays that way on a reload,
	 * so the card says so, says where a save moves the policy, and keeps Save enabled: saving is the
	 * only way to replace the row. The empty-state line is present but hidden until such a save.
	 */
	public function test_a_malformed_row_names_itself_and_keeps_save_available(): void {
		$this->plant_malformed_row();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render_card();

		$this->assertStringNotContainsString( 'could not be read', $html );
		$this->assertStringContainsString( 'id="aafm-allowlist-malformed"', $html );
		$this->assertStringContainsString( 'not in the expected format, so every call is denied', $html );
		$this->assertStringContainsString( 'saving with no scopes lets every role and connection reach everything enabled above', $html );
		$this->assertMatchesRegularExpression( '/<p class="aafm-empty-state" id="aafm-allowlist-empty" hidden>/', $html, 'The empty-state line waits, hidden, for a save with no scopes.' );
		$this->assertStringNotContainsString( '<p class="aafm-empty-state" id="aafm-allowlist-empty">', $html, 'A malformed row never shows as an unrestricted site.' );
		$this->assertDoesNotMatchRegularExpression( '/id="aafm-allowlist-save"[^>]*\bdisabled\b/', $html, 'Save replaces the malformed row, so it stays available.' );
		$this->assertDoesNotMatchRegularExpression( '/id="aafm-allowlist-add-row"[^>]*\bdisabled\b/', $html, 'Scopes can be added to the replacement before saving.' );
	}

	/**
	 * No stored row: the visible empty-state line, no table, no notice.
	 */
	public function test_an_empty_allowlist_renders_the_empty_state(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render_card();

		$this->assertStringContainsString( '<p class="aafm-empty-state" id="aafm-allowlist-empty">', $html );
		$this->assertStringNotContainsString( 'id="aafm-allowlist-table"', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
	}

	/**
	 * Stored rows: the table, no empty-state line, no notice.
	 */
	public function test_stored_rows_render_the_table(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render_card();

		$this->assertStringContainsString( 'id="aafm-allowlist-table"', $html );
		$this->assertStringNotContainsString( 'aafm-allowlist-empty', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
	}

	/**
	 * Saving no scopes over a malformed row stores an empty list: the allowlist no longer restricts
	 * anyone, and the card renders the empty state.
	 */
	public function test_saving_no_scopes_over_a_malformed_row_lifts_the_allowlist(): void {
		$this->plant_malformed_row();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->assertFalse( aafm_ability_allowed_for_principal( 'aafm/get-posts', $admin, '' ), 'The malformed row denies.' );

		$json = $this->save_rows( array() );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( array(), get_option( 'aafm_ability_allowlist_overrides' ) );
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/get-posts', $admin, '' ) );
		$html = $this->render_card();
		$this->assertStringContainsString( '<p class="aafm-empty-state" id="aafm-allowlist-empty">', $html );
		$this->assertStringNotContainsString( 'aafm-allowlist-malformed', $html );
	}

	/**
	 * Saving one role row over a malformed row stores it: the card renders the table and no notice.
	 */
	public function test_saving_a_scope_over_a_malformed_row_stores_it(): void {
		$this->plant_malformed_row();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$json = $this->save_rows(
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);

		$this->assertTrue( $json['success'] ?? false );
		$html = $this->render_card();
		$this->assertStringContainsString( 'id="aafm-allowlist-table"', $html );
		$this->assertStringNotContainsString( 'aafm-allowlist-malformed', $html );
		$this->assertStringNotContainsString( 'aafm-allowlist-empty', $html );
	}

	/**
	 * A rejected save over a malformed row leaves the row malformed and the card in that state.
	 */
	public function test_a_rejected_save_over_a_malformed_row_leaves_it_malformed(): void {
		$this->plant_malformed_row();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$json = $this->save_rows(
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'not-a-real-role',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);

		$this->assertFalse( $json['success'] ?? true );
		$this->assertTrue( aafm_allowlist_overrides_for_display()['malformed'] );
		$this->assertStringContainsString( 'id="aafm-allowlist-malformed"', $this->render_card() );
	}

	/**
	 * Makes the ONE query containing $needle fail via wpdb::query()'s OTHER false-without-a-real-
	 * error path (QueryFaultInjector's no-flush filter): the 'query' filter itself returning an
	 * empty string. wp-includes/class-wpdb.php's query() checks `if ( ! $query )` and returns
	 * false immediately - BEFORE its own $this->flush() call that would otherwise reset
	 * last_result - so, unlike redirecting a query to a nonexistent table (which fails for real,
	 * but only after flush() has already run), this leaves $wpdb->last_result holding whatever
	 * the PREVIOUS successful query left there. That is the exact precondition R8-1 exploits, and
	 * the only one of $wpdb->query()'s two "false without clearing last_result" paths a test can
	 * trigger without also faking wpdb::ready.
	 *
	 * @param string $needle Substring identifying the one query to suppress.
	 * @return void
	 */
	private function suppress_query_containing( string $needle ): void {
		add_filter( 'query', QueryFaultInjector::no_flush_filter( $needle ) );
	}

	/**
	 * Codex round 8, R8-1: aafm_oauth_get_client() used to run a bare $wpdb->get_row(), which
	 * hands back the PREVIOUS query's row when the current one fails without clearing last_result.
	 * Reproduces the exact shape Codex described: an allowlist save naming a real client FIRST (so
	 * its lookup succeeds and populates $wpdb->last_result), then a second, nonexistent client
	 * whose OWN lookup query is suppressed - before the fix, the bare get_row() would silently
	 * hand back the first client's row for the second's, is_array() would accept it, and the whole
	 * save (including a row for the nonexistent client) would persist with a success response.
	 *
	 * What would break this: reverting aafm_oauth_get_client() to a bare $wpdb->get_row() makes
	 * $json['success'] true and a row for the nonexistent client lands in
	 * aafm_allowlist_overrides().
	 */
	public function test_a_failed_client_lookup_rejects_the_whole_save_rather_than_reusing_a_prior_row(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$real_client_id = $this->register_real_oauth_client();
		// Syntactically identical to a real client_id (32-char hex) but never stored - so its
		// lookup query is unique in the whole request and only ITS query gets suppressed below.
		$phantom_client_id = bin2hex( random_bytes( 16 ) );

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode(
			array(
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => $real_client_id,
					'allowed_abilities' => 'all',
				),
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => $phantom_client_id,
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);

		$this->suppress_query_containing( "client_id = '{$phantom_client_id}'" );
		$this->intercept_die();
		$json = $this->run_handler();
		remove_all_filters( 'query' );

		$this->assertFalse( $json['success'] ?? true, 'A save containing a client whose lookup itself failed must be rejected, not reported as saved.' );
		$this->assertSame( array(), aafm_allowlist_overrides(), 'Nothing may persist - not even the valid first row - when a later row\'s lookup could not be certified.' );
	}

	public function test_more_than_the_row_cap_is_refused(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$rows = array();
		for ( $i = 0; $i <= AAFM_ALLOWLIST_MAX_ROWS; $i++ ) {
			$rows[] = array(
				'scope_type'        => 'oauth_client',
				'scope_id'          => 'client-' . $i,
				'allowed_abilities' => 'all',
			);
		}

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['allowlist_json'] = wp_json_encode( $rows );

		$this->intercept_die();
		$json = $this->run_handler();

		$this->assertFalse( $json['success'] ?? true );
		$this->assertSame( array(), aafm_allowlist_overrides() );
	}
}
