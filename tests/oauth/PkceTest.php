<?php
/**
 * Tests for the PKCE S256 verification helpers.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Verifies PKCE S256 challenge derivation and challenge-format validation.
 */
class PkceTest extends TestCase {

	/**
	 * Build the S256 challenge for a verifier the way an RFC 7636 client would.
	 *
	 * @param string $verifier The code verifier.
	 * @return string The base64url-encoded SHA-256 challenge.
	 */
	private function challenge_for( string $verifier ): string {
		// base64url encode of the raw SHA-256 digest, mirroring an RFC 7636 client (not obfuscation).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * A correct verifier matches its challenge; a wrong one does not.
	 */
	public function test_s256_verifier_matches_challenge(): void {
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$this->assertTrue( aafm_pkce_verify( $verifier, $challenge ) );
		$this->assertFalse( aafm_pkce_verify( 'wrong-verifier', $challenge ) );
	}

	/**
	 * An empty verifier or empty challenge never verifies.
	 */
	public function test_verify_rejects_empty_inputs(): void {
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $this->challenge_for( $verifier );

		$this->assertFalse( aafm_pkce_verify( '', $challenge ) );
		$this->assertFalse( aafm_pkce_verify( $verifier, '' ) );
		$this->assertFalse( aafm_pkce_verify( '', '' ) );
	}

	/**
	 * A well-formed 43-character base64url challenge is accepted.
	 */
	public function test_valid_challenge_accepts_real_challenge(): void {
		$challenge = $this->challenge_for( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' );

		$this->assertSame( 43, strlen( $challenge ) );
		$this->assertTrue( aafm_pkce_is_valid_challenge( $challenge ) );
	}

	/**
	 * The 43- and 128-character length boundaries are both accepted.
	 */
	public function test_valid_challenge_accepts_length_boundaries(): void {
		$this->assertTrue( aafm_pkce_is_valid_challenge( str_repeat( 'a', 43 ) ) );
		$this->assertTrue( aafm_pkce_is_valid_challenge( str_repeat( 'a', 128 ) ) );
	}

	/**
	 * Challenges shorter than 43 or longer than 128 characters are rejected.
	 */
	public function test_valid_challenge_rejects_out_of_range_lengths(): void {
		$this->assertFalse( aafm_pkce_is_valid_challenge( str_repeat( 'a', 42 ) ) );
		$this->assertFalse( aafm_pkce_is_valid_challenge( str_repeat( 'a', 129 ) ) );
		$this->assertFalse( aafm_pkce_is_valid_challenge( '' ) );
	}

	/**
	 * Challenges containing characters outside the unreserved set are rejected.
	 */
	public function test_valid_challenge_rejects_disallowed_characters(): void {
		$base = str_repeat( 'a', 42 );

		$this->assertFalse( aafm_pkce_is_valid_challenge( $base . '+' ) );
		$this->assertFalse( aafm_pkce_is_valid_challenge( $base . '/' ) );
		$this->assertFalse( aafm_pkce_is_valid_challenge( $base . '=' ) );
		$this->assertFalse( aafm_pkce_is_valid_challenge( $base . ' ' ) );
	}

	/**
	 * A challenge ending in a trailing newline is rejected.
	 *
	 * PCRE treats `$` as matching before a trailing newline, so the validator must
	 * anchor with `\z` to reject a newline at the OAuth authorization trust boundary.
	 */
	public function test_valid_challenge_rejects_trailing_newline(): void {
		$this->assertFalse( aafm_pkce_is_valid_challenge( str_repeat( 'a', 43 ) . "\n" ) );
	}
}
