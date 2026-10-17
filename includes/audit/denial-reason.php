<?php
/**
 * Refusal reasons for denied activity rows.
 *
 * The Activity Log is admin-only, so a denied row can say WHY a call was refused without telling the
 * caller anything: the wire message stays the adapter's generic "Permission denied". The reason is
 * one of a closed set of codes whose labels are fixed, translated strings, so the detail column stays
 * identifier-only. The meta-key reasons come from calling the production gate
 * (aafm_validate_scoped_meta_key()) with substituted callables, never from a second copy of its rules.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The closed set of refusal reasons: code => label. Labels must not contain "#" followed by a digit
 * (the detail linker reads that as an object id).
 *
 * @return array<string,string>
 */
function aafm_denial_reason_labels(): array {
	return array(
		'not_allowlisted'     => __( 'key is not on the allowlist', 'agent-abilities-for-mcp' ),
		'key_denied'          => __( 'key is on the deny list', 'agent-abilities-for-mcp' ),
		'key_protected'       => __( 'key is protected or reserved', 'agent-abilities-for-mcp' ),
		'reserved_key'        => __( 'key is reserved by WordPress', 'agent-abilities-for-mcp' ),
		'object_not_editable' => __( 'item missing or not editable by this user', 'agent-abilities-for-mcp' ),
		'scope_list'          => __( 'ability is outside this role, connection list or access preset', 'agent-abilities-for-mcp' ),
		'rate_limit'          => __( 'rate limit reached', 'agent-abilities-for-mcp' ),
		'missing_argument'    => __( 'a required argument was missing', 'agent-abilities-for-mcp' ),
		'capability'          => __( 'WordPress capability check failed', 'agent-abilities-for-mcp' ),
		'source_plugin'       => __( 'the source plugin check refused', 'agent-abilities-for-mcp' ),
		'connection_paused'   => __( 'this connection is paused', 'agent-abilities-for-mcp' ),
		'access_paused'       => __( 'all access is paused', 'agent-abilities-for-mcp' ),
	);
}

/**
 * The label for one reason code, or null for an unknown code.
 *
 * @param string $code Reason code.
 * @return string|null
 */
function aafm_denial_reason_label( string $code ): ?string {
	return aafm_denial_reason_labels()[ $code ] ?? null;
}

/**
 * Append the refusal reason to a denied row's detail.
 *
 * The clause is stored as the fixed marker "Refused: " plus the reason CODE, never a translated label,
 * so aafm_redact_denial_reason() finds it on a site in any language. aafm_localize_denied_detail()
 * turns the code into the translated label when an admin surface shows the row.
 *
 * @param string|null $base Detail the row would carry anyway (may be null).
 * @param string|null $code Reason code, or null for none.
 * @return string|null
 */
function aafm_compose_denied_detail( ?string $base, ?string $code ): ?string {
	if ( null === $code || null === aafm_denial_reason_label( $code ) ) {
		return $base;
	}
	return null === $base || '' === $base ? 'Refused: ' . $code : $base . '. Refused: ' . $code;
}

/**
 * Split a stored detail into what the call tried and the refusal code.
 *
 * @param string $detail Stored detail.
 * @return array{0:string,1:string}|null Base (may be empty) and code, or null when there is no refusal clause.
 */
function aafm_split_denied_detail( string $detail ): ?array {
	if ( 1 !== preg_match( '/\A(?:(.*)\. )?Refused: ([a-z_]*)\z/su', $detail, $m ) ) {
		return null;
	}
	return array( $m[1], $m[2] );
}

/**
 * A stored detail with its refusal code replaced by the translated label, for the admin table and the CSV.
 * A detail with no clause, or a code that is not in the closed list, comes back unchanged.
 *
 * @param string $detail Stored detail.
 * @return string
 */
function aafm_localize_denied_detail( string $detail ): string {
	$parts = aafm_split_denied_detail( $detail );
	$label = null === $parts ? null : aafm_denial_reason_label( $parts[1] );
	if ( null === $parts || null === $label ) {
		return $detail;
	}
	if ( '' === $parts[0] ) {
		/* translators: %s: why a call was refused, for example "rate limit reached". */
		return sprintf( __( 'Refused: %s', 'agent-abilities-for-mcp' ), $label );
	}
	/* translators: 1: what the call tried to do, such as "Attempted: Updated meta key `x` on post #5". 2: why it was refused. */
	return sprintf( __( '%1$s. Refused: %2$s', 'agent-abilities-for-mcp' ), $parts[0], $label );
}

/**
 * Why the production meta-key gate refuses a key, or null when it does not.
 *
 * Re-runs aafm_validate_scoped_meta_key() with substituted callables: first as the gate does; then
 * with the allow-all switch forced on (a pass means only the allowlist refused it); then with the
 * hard block also forced off (a pass means the hard block refused it); anything left is the deny
 * floor. The gate itself is not touched, so this cannot drift from it.
 *
 * @param string $scope One of post, term, user.
 * @param string $key   The requested meta key.
 * @return string|null not_allowlisted, key_denied, key_protected, or null when the key passes.
 */
function aafm_meta_key_refusal_code( string $scope, string $key ): ?string {
	$sets = array(
		'post' => array(
			'hard'       => 'aafm_hard_blocked_meta_key',
			'deny_star'  => 'aafm_meta_deny_has_star',
			'denied'     => 'aafm_denied_meta_keys',
			'allow_star' => 'aafm_meta_allow_has_star',
			'allowed'    => 'aafm_allowed_meta_keys',
		),
		'term' => array(
			'hard'       => 'aafm_hard_blocked_meta_key',
			'deny_star'  => 'aafm_term_meta_deny_has_star',
			'denied'     => 'aafm_denied_term_meta_keys',
			'allow_star' => 'aafm_term_meta_allow_has_star',
			'allowed'    => 'aafm_allowed_term_meta_keys',
		),
		'user' => array(
			'hard'       => 'aafm_hard_blocked_user_meta_key',
			'deny_star'  => 'aafm_user_meta_deny_has_star',
			'denied'     => 'aafm_denied_user_meta_keys',
			'allow_star' => 'aafm_user_meta_allow_has_star',
			'allowed'    => 'aafm_allowed_user_meta_keys',
		),
	);
	if ( ! isset( $sets[ $scope ] ) ) {
		return null;
	}
	$s   = $sets[ $scope ];
	$run = static function ( $hard, $allow_star ) use ( $s, $key ) {
		return aafm_validate_scoped_meta_key( $key, $hard, $s['deny_star'], $s['denied'], $allow_star, $s['allowed'], 'aafm_probe', 'probe' );
	};

	if ( is_string( $run( $s['hard'], $s['allow_star'] ) ) ) {
		return null;
	}
	$trimmed = trim( $key );
	if ( '' === $trimmed || '*' === $trimmed ) {
		return 'key_protected'; // Never addressable: the engine's first floor.
	}
	if ( is_string( $run( $s['hard'], '__return_true' ) ) ) {
		return 'not_allowlisted';
	}
	if ( is_string( $run( '__return_false', '__return_true' ) ) ) {
		return 'key_protected';
	}
	return 'key_denied';
}

/**
 * The reason for a refusal that came from an ability's own permission callback.
 *
 * Meta abilities get the key reason, or "item not editable" when the key passes (the callback's only
 * other condition). A bridged ability's own check decides for it. Everything else is a WordPress
 * capability or object check, which the callback does not distinguish.
 *
 * @param string              $name  Ability name.
 * @param array<string,mixed> $input The call's input.
 * @return string A reason code.
 */
function aafm_denial_reason_for_permission( string $name, array $input ): string {
	// The nine meta abilities: aafm/{get|update|delete}-{post|term|user}-meta. The user ones name their key `key`, the rest `meta_key`.
	if ( 1 === preg_match( '#^aafm/(?:get|update|delete)-(post|term|user)-meta\z#', $name, $m ) ) {
		$param = 'user' === $m[1] ? 'key' : 'meta_key';
		$key   = isset( $input[ $param ] ) && is_scalar( $input[ $param ] ) ? (string) $input[ $param ] : '';
		$code  = aafm_meta_key_refusal_code( $m[1], $key );
		return null !== $code ? $code : 'object_not_editable';
	}
	if ( 0 === strpos( $name, 'aafm-bridge/' ) ) {
		return 'source_plugin';
	}
	return 'capability';
}

/**
 * A stored detail with the admin-only refusal reason removed.
 *
 * The aafm/get-activity-log ability is readable by any principal with manage_options, including an agent. The meta
 * gate gives every rejection the same generic message so a caller cannot tell which rule refused it, and
 * the reason must not become readable through the audit ability instead. The Activity Log screen and the
 * CSV export are human surfaces and keep the full detail. The stored clause is the untranslated marker
 * "Refused: <code>" (see aafm_compose_denied_detail()), so this works under any site language.
 *
 * @param string|null $detail Stored detail.
 * @return string|null Null when nothing is left, so the wire still carries "detail":null.
 */
function aafm_redact_denial_reason( ?string $detail ): ?string {
	if ( null === $detail ) {
		return null;
	}
	$parts = aafm_split_denied_detail( $detail );
	if ( null === $parts ) {
		return $detail;
	}
	return '' === $parts[0] ? null : $parts[0];
}
