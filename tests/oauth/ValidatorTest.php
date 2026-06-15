<?php
/**
 * Tests for the OAuth bearer-token validator: the determine_current_user
 * resolver, the access-token row resolver, and the rest_authentication_errors
 * pass-through.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;
use WP_Error;

/**
 * Verifies that a valid `aafm_oat_` bearer resolves to the approving user on the
 * determine_current_user filter, while every non-OAuth path — App Passwords,
 * already-resolved users, foreign bearers, expired/wrong-audience tokens — is
 * left byte-for-byte unchanged.
 */
class ValidatorTest extends TestCase {

	/**
	 * Saved Authorization header values, restored in tear_down so a header set in
	 * one test can never bleed into the next.
	 *
	 * @var array<string,string|null>
	 */
	private array $original_auth = array();

	/**
	 * The WP test suite rewrites plugin CREATE TABLE to its TEMPORARY form, so the
	 * token table must be installed per test before any mint/validate runs.
	 */
	public function set_up(): void {
		parent::set_up();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$this->original_auth = array(
			'HTTP_AUTHORIZATION'          => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
			'REDIRECT_HTTP_AUTHORIZATION' => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		aafm_install_oauth_tables();
	}

	/**
	 * Restore the Authorization header keys to exactly their pre-test state.
	 */
	public function tear_down(): void {
		foreach ( $this->original_auth as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		parent::tear_down();
	}

	/**
	 * Set the incoming Authorization header for the request under test.
	 *
	 * @param string $value Full header value, e.g. "Bearer aafm_oat_...".
	 */
	private function set_bearer( string $value ): void {
		$_SERVER['HTTP_AUTHORIZATION'] = $value;
	}

	/**
	 * Set the credential under the FastCGI-only REDIRECT_HTTP_AUTHORIZATION key,
	 * leaving HTTP_AUTHORIZATION unset (set_up already cleared both). tear_down
	 * restores REDIRECT_HTTP_AUTHORIZATION from $original_auth.
	 *
	 * @param string $value Full header value, e.g. "Bearer aafm_oat_...".
	 */
	private function set_redirect_bearer( string $value ): void {
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = $value;
	}

	/**
	 * Read a token row directly so a test can mutate expires_at.
	 *
	 * @param string $access_raw Raw access token.
	 * @return array<string,mixed>|null
	 */
	private function row_by_access( string $access_raw ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT * FROM {$wpdb->prefix}aafm_oauth_access_tokens WHERE token_hash = %s",
				hash( 'sha256', $access_raw )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A valid access token bound to this endpoint resolves to its user.
	 */
	public function test_resolves_valid_bearer_to_user(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertSame( $uid, aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * The bearer is read from the FastCGI-only REDIRECT_HTTP_AUTHORIZATION key
	 * when HTTP_AUTHORIZATION is absent — a valid token there resolves its user.
	 */
	public function test_resolves_bearer_from_redirect_http_authorization(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		$this->set_redirect_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertSame( $uid, aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * FROZEN INVARIANT: a non-aafm_oat_ bearer is left untouched — App Passwords
	 * and every other scheme resolve undisturbed.
	 */
	public function test_ignores_non_aafm_bearer(): void {
		$this->set_bearer( 'Bearer someoneelsestoken' );

		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * FROZEN INVARIANT: an already-resolved user is never preempted, even when a
	 * valid OAuth bearer for a different user is present.
	 */
	public function test_does_not_preempt_already_resolved_user(): void {
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();

		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $user_a,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertSame( $user_b, aafm_oauth_resolve_current_user( $user_b ) );
	}

	/**
	 * An expired access token does not resolve a user.
	 */
	public function test_expired_token_returns_incoming_value(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'token_hash' => hash( 'sha256', $tokens['access_token'] ) ),
			array( '%s' ),
			array( '%s' )
		);

		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * A token minted for a different audience (resource) is ignored (RFC 8707).
	 */
	public function test_wrong_audience_token_is_ignored(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => 'https://evil.example/mcp',
			)
		);

		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
	}

	/**
	 * With no Authorization header at all, the incoming value passes through
	 * unchanged — for both a falsey and a non-zero incoming user id.
	 */
	public function test_no_bearer_returns_incoming_value(): void {
		$this->assertFalse( aafm_oauth_resolve_current_user( false ) );
		$this->assertSame( 7, aafm_oauth_resolve_current_user( 7 ) );
	}

	/**
	 * When OAuth is disabled, a valid aafm_oat_ bearer is ignored.
	 */
	public function test_disabled_oauth_ignores_bearer(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		update_option( 'aafm_oauth_enabled', '0' );
		$this->set_bearer( 'Bearer ' . $tokens['access_token'] );

		$resolved = aafm_oauth_resolve_current_user( false );

		delete_option( 'aafm_oauth_enabled' );

		$this->assertFalse( $resolved );
	}

	/**
	 * The row resolver returns the audience and user for a known token, and null
	 * for an unknown one.
	 */
	public function test_get_access_token_row_returns_resource_and_user(): void {
		$uid    = self::factory()->user->create();
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $uid,
				'client_id'  => 'c',
				'resource'   => aafm_endpoint_url(),
			)
		);

		$row = aafm_oauth_get_access_token_row( $tokens['access_token'] );

		$this->assertIsArray( $row );
		$this->assertSame( aafm_endpoint_url(), $row['resource'] );
		$this->assertSame( $uid, (int) $row['wp_user_id'] );

		$this->assertNull( aafm_oauth_get_access_token_row( 'aafm_oat_unknown' ) );
	}

	/**
	 * The rest_authentication_errors hook is a pure pass-through: it never turns a
	 * non-error into an error, and never mutates an existing WP_Error.
	 */
	public function test_rest_authentication_errors_passthrough(): void {
		$this->assertNull( aafm_oauth_rest_authentication_errors( null ) );

		$error = new WP_Error( 'x', 'y' );
		$this->assertSame( $error, aafm_oauth_rest_authentication_errors( $error ) );
	}
}
