<?php
/**
 * Tests for the OAuth authorization endpoint: param validation, consent storage,
 * and the standalone consent-screen render.
 *
 * The init handler reads superglobals and calls auth_redirect()/status_header()/exit,
 * which cannot run in-process, so the testable logic lives in pure helpers exercised
 * here: aafm_oauth_validate_authorize_params(), the consent-row reader/writer, and the
 * consent-page renderer.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;
use WP_Error;

/**
 * Verifies authorize-request validation, consent persistence, and the consent render.
 */
class AuthorizeTest extends TestCase {

	/**
	 * Install the OAuth tables before each test (they are TEMPORARY under the suite).
	 */
	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
	}

	/**
	 * Register a public client and return its client_id.
	 *
	 * @param string $redirect The single redirect URI to register.
	 * @return string
	 */
	private function register_client( string $redirect = 'https://app.example/cb' ): string {
		$result = aafm_oauth_register_client(
			array(
				'redirect_uris' => array( $redirect ),
				'client_name'   => 'Test Client',
			)
		);
		$this->assertIsArray( $result );
		return (string) $result['client_id'];
	}

	/**
	 * A valid set of authorize params for the given client/redirect.
	 *
	 * @param string $client_id Registered client_id.
	 * @param string $redirect  Registered redirect URI.
	 * @return array<string,string>
	 */
	private function valid_params( string $client_id, string $redirect = 'https://app.example/cb' ): array {
		return array(
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect,
			'code_challenge'        => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			'code_challenge_method' => 'S256',
			'state'                 => 'xyz',
			'scope'                 => '',
			'resource'              => '',
		);
	}

	/**
	 * Missing code_challenge is rejected: PKCE is mandatory.
	 */
	public function test_missing_code_challenge_is_error(): void {
		$client = $this->register_client();
		$params = $this->valid_params( $client );
		unset( $params['code_challenge'] );

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * A code_challenge_method other than S256 is rejected (no plain).
	 */
	public function test_non_s256_method_is_error(): void {
		$client                          = $this->register_client();
		$params                          = $this->valid_params( $client );
		$params['code_challenge_method'] = 'plain';

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * A response_type other than "code" is a redirectable OAuth error.
	 */
	public function test_bad_response_type_is_redirectable_error(): void {
		$client                  = $this->register_client();
		$params                  = $this->valid_params( $client );
		$params['response_type'] = 'token';

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertTrue( aafm_oauth_error_is_redirectable( $result ) );
	}

	/**
	 * An unknown client_id is a NON-redirectable (local) error.
	 */
	public function test_unknown_client_is_local_error(): void {
		$params              = $this->valid_params( 'does_not_exist' );
		$params['client_id'] = 'does_not_exist';

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( aafm_oauth_error_is_redirectable( $result ) );
	}

	/**
	 * A redirect_uri not in the client's registered set is a NON-redirectable error.
	 */
	public function test_unregistered_redirect_is_local_error(): void {
		$client                 = $this->register_client();
		$params                 = $this->valid_params( $client );
		$params['redirect_uri'] = 'https://evil.example/cb';

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( aafm_oauth_error_is_redirectable( $result ) );
	}

	/**
	 * With no resource param, the normalized resource defaults to the MCP endpoint.
	 */
	public function test_absent_resource_defaults_to_endpoint(): void {
		$client = $this->register_client();
		$params = $this->valid_params( $client );

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertIsArray( $result );
		$this->assertSame( aafm_endpoint_url(), $result['resource'] );
	}

	/**
	 * A resource that equals the MCP endpoint exactly is accepted.
	 */
	public function test_matching_resource_is_accepted(): void {
		$client             = $this->register_client();
		$params             = $this->valid_params( $client );
		$params['resource'] = aafm_endpoint_url();

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertIsArray( $result );
		$this->assertSame( aafm_endpoint_url(), $result['resource'] );
	}

	/**
	 * A resource that differs from the MCP endpoint is rejected with invalid_target.
	 */
	public function test_mismatched_resource_is_invalid_target(): void {
		$client             = $this->register_client();
		$params             = $this->valid_params( $client );
		$params['resource'] = 'https://other.example/mcp';

		$result = aafm_oauth_validate_authorize_params( $params );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_target', $result->get_error_code() );
		$this->assertTrue( aafm_oauth_error_is_redirectable( $result ) );
	}

	/**
	 * Recording then reading consent returns true; a different client returns false.
	 */
	public function test_record_and_read_consent(): void {
		$client = $this->register_client();
		$user   = self::factory()->user->create();

		$this->assertFalse( aafm_oauth_has_consent( $user, $client ) );

		aafm_oauth_record_consent( $user, $client );

		$this->assertTrue( aafm_oauth_has_consent( $user, $client ) );
		$this->assertFalse( aafm_oauth_has_consent( $user, 'some_other_client' ) );
	}

	/**
	 * Recording consent twice for the same pair stays a single row (idempotent upsert).
	 */
	public function test_record_consent_is_idempotent(): void {
		$client = $this->register_client();
		$user   = self::factory()->user->create();

		aafm_oauth_record_consent( $user, $client );
		aafm_oauth_record_consent( $user, $client );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_consents WHERE wp_user_id = %d AND client_id = %s",
				$user,
				$client
			)
		);
		$this->assertSame( 1, $count );
	}

	/**
	 * A code minted with the forced endpoint resource persists that exact string.
	 *
	 * The authorize flow forces resource => aafm_endpoint_url() at the mint call, and
	 * the D1 token validator later audience-checks that exact string. This locks the
	 * audience carry-forward contract at the storage boundary: read the stored row
	 * back by the code's SHA-256 hash and assert the persisted resource is the
	 * endpoint URL byte-for-byte.
	 */
	public function test_minted_code_persists_endpoint_resource(): void {
		$client   = $this->register_client();
		$user     = self::factory()->user->create();
		$endpoint = aafm_endpoint_url();

		$raw = aafm_oauth_mint_code(
			array(
				'client_id'      => $client,
				'wp_user_id'     => $user,
				'redirect_uri'   => 'https://app.example/cb',
				'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
				'resource'       => $endpoint,
			)
		);
		$this->assertIsString( $raw );

		$hash = hash( 'sha256', $raw );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored_resource = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT resource FROM {$wpdb->prefix}aafm_oauth_codes WHERE code_hash = %s",
				$hash
			)
		);

		$this->assertSame( $endpoint, $stored_resource );
	}

	/**
	 * The consent page renders a self-contained, escaped, script-free document.
	 */
	public function test_consent_render_is_escaped_and_self_contained(): void {
		$view = array(
			'client_name'   => '<script>alert(1)</script>',
			'user_login'    => 'mcp-agent',
			'site_name'     => 'Example Site',
			'action_url'    => 'https://site.example/?aafm_oauth=authorize',
			'nonce_field'   => '<input type="hidden" name="_wpnonce" value="abc123" />',
			'hidden_inputs' => array(
				'<input type="hidden" name="response_type" value="code" />',
				'<input type="hidden" name="client_id" value="abc" />',
			),
		);

		ob_start();
		aafm_oauth_render_consent_page( $view );
		$html = (string) ob_get_clean();

		// Document shell and form.
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'method="post"', $html );

		// Direction A token reuse and both buttons.
		$this->assertStringContainsString( '--aafm-accent', $html );
		$this->assertStringContainsString( 'value="approve"', $html );
		$this->assertStringContainsString( 'value="deny"', $html );

		// The agent user login is shown.
		$this->assertStringContainsString( 'mcp-agent', $html );

		// No script tag anywhere: the page is CSP-clean and self-contained.
		$this->assertStringNotContainsString( '<script', $html );

		// The malicious client_name comes back HTML-escaped, never as a raw tag.
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}
}
