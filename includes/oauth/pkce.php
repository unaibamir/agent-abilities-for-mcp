<?php
/**
 * PKCE (RFC 7636) S256 verification helpers.
 *
 * Pure functions with no WordPress or database dependencies. Later OAuth PRs call
 * these from the authorization and token endpoints to enforce the S256 challenge
 * method (the only method this plugin supports).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Verify a PKCE code verifier against a stored S256 code challenge.
 *
 * Recomputes base64url(sha256(verifier)) and compares it to the expected challenge
 * in constant time. The challenge is the known/expected value, so it is the first
 * argument to hash_equals(); the verifier is the untrusted, user-supplied input.
 *
 * @param string $verifier  The code verifier supplied at the token endpoint.
 * @param string $challenge The code challenge captured at the authorization request.
 * @return bool True when the verifier hashes to the challenge.
 */
function aafm_pkce_verify( string $verifier, string $challenge ): bool {
	if ( '' === $verifier || '' === $challenge ) {
		return false;
	}

	// base64url encode of the raw SHA-256 digest, per RFC 7636 S256 (not obfuscation).
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

	return hash_equals( $challenge, $computed );
}

/**
 * Validate that a string is a well-formed PKCE code challenge.
 *
 * RFC 7636 constrains the challenge to the unreserved character set
 * (ALPHA / DIGIT / "-" / "." / "_" / "~") and a length of 43 to 128 characters.
 *
 * @param string $c The candidate code challenge.
 * @return bool True when the challenge matches the RFC 7636 shape.
 */
function aafm_pkce_is_valid_challenge( string $c ): bool {
	return (bool) preg_match( '/^[A-Za-z0-9\-._~]{43,128}\z/', $c );
}
