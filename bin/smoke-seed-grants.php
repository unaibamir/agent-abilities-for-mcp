<?php
/**
 * Smoke gate seed: an OAuth client, a consent, a token pair and an unredeemed PKCE code for the
 * disposable site's admin, plus a client allowlist row so the bearer's tools/list is a known subset.
 *
 * Runs under the OLD release in the upgrade leg, so it uses only functions that exist in 1.9.0.
 * Usage: wp eval-file bin/smoke-seed-grants.php <outfile>. Disposable site only.
 *
 * @package AgentAbilitiesForMCP
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- a CLI seed writing one JSON file.

$out = isset( $args[0] ) ? (string) $args[0] : '';
if ( '' === $out ) {
	WP_CLI::error( 'usage: wp eval-file smoke-seed-grants.php <outfile>' );
}

update_option( 'aafm_oauth_enabled', '1' );
$redirect = 'https://example.com/callback';
$client   = aafm_oauth_register_client(
	array(
		'redirect_uris' => array( $redirect ),
		'client_name'   => 'Smoke client',
	)
);
if ( ! is_array( $client ) ) {
	WP_CLI::error( 'client registration failed' );
}
$client_id = (string) $client['client_id'];
$resource  = aafm_endpoint_url();
aafm_oauth_record_consent( 1, $client_id );
$tokens   = aafm_oauth_mint_tokens(
	array(
		'client_id'  => $client_id,
		'wp_user_id' => 1,
		'resource'   => $resource,
		'scope'      => '',
	)
);
$verifier = str_repeat( 'v', 50 );
$code     = aafm_oauth_mint_code(
	array(
		'client_id'      => $client_id,
		'wp_user_id'     => 1,
		'redirect_uri'   => $redirect,
		'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
		'resource'       => $resource,
		'scope'          => '',
	)
);
if ( is_wp_error( $tokens ) || is_wp_error( $code ) ) {
	WP_CLI::error( 'token or code mint failed' );
}
update_option(
	'aafm_ability_allowlist_overrides',
	array(
		array(
			'scope_type'        => 'oauth_client',
			'scope_id'          => $client_id,
			'allowed_abilities' => array( 'aafm/get-posts', 'aafm/count-posts' ),
		),
	)
);
file_put_contents(
	$out,
	wp_json_encode(
		array(
			'client_id'     => $client_id,
			'user_id'       => 1,
			'access'        => $tokens['access_token'],
			'refresh'       => $tokens['refresh_token'],
			'code'          => $code,
			'code_verifier' => $verifier,
			'redirect_uri'  => $redirect,
		)
	)
);
