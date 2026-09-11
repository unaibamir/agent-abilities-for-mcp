<?php
/**
 * The allowlist AJAX save handler: nonce/capability gating, row sanitization, and the row cap.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

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
