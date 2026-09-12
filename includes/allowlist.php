<?php
/**
 * Per-role / per-connection ability allowlist overrides.
 *
 * Storage shape decided in .claude/planning/228-allowlist-design.md (Task 1): a single option,
 * `aafm_ability_allowlist_overrides`, an array of rows:
 *
 *   { "scope_type": "role"|"oauth_client", "scope_id": string, "allowed_abilities": string[]|"all" }
 *
 * Precedence is INTERSECTION, never "last row wins": the effective set for a call is the global
 * `aafm_enabled_abilities` list AND the union of every matching role row's allowed_abilities (or
 * unrestricted if the user holds no role with a row) AND the matching client row's
 * allowed_abilities (or unrestricted if the connection has no client row, e.g. a cookie/
 * app-password session). Both narrowing layers always apply together; neither can widen past the
 * global list, and neither one alone decides when the other is also present.
 *
 * A malformed row (unknown scope_type, or an allowed_abilities value that is neither an array nor
 * the literal string "all") is skipped when evaluating precedence - it degrades to "no additional
 * restriction from that scope," never to "restriction lifted." An option holding no rows at all
 * (the default, `get_option()` returns array()) means "no override rows" - identical to today's
 * behavior, so an operator who has never touched this feature sees no change.
 *
 * This is a SECOND, independent gate alongside the existing OAuth scope-to-capability mechanism
 * (aafm_oauth_apply_token_capability_scope(), includes/oauth/validator.php): that mechanism narrows
 * which WordPress capabilities an OAuth-authenticated request effectively has; this one narrows
 * which ability NAMES a scope/role/client may reach at all, evaluated before an ability's own
 * permission_callback runs. A call must clear both.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Row cap for aafm_ability_allowlist_overrides - enforced by the writer (Task 16's AJAX save
 * handler), not read-side. Roles are single-digit in almost every install; OAuth clients can grow
 * via DCR, so the cap matters more there.
 */
const AAFM_ALLOWLIST_MAX_ROWS = 200;

/**
 * Read the raw override rows straight from the database, tolerating a missing/malformed option.
 *
 * 1.7.5 round 4, R4-8: this docblock used to describe this function as the live permission-gating
 * reader, evaluated on every ability check. It no longer has any production caller - the actual
 * gate is aafm_ability_allowed_for_principal() below, which reads the database view directly
 * rather than through this helper, because this helper's "no rows found" and "the query itself
 * failed" both collapse to the same empty array, and an empty array here reads as unrestricted.
 * That collapse would fail OPEN on a transient read failure if used for authorization (see
 * aafm_ability_allowed_for_principal()'s own docblock for why it must fail the opposite way).
 * What remains here is a plain raw-read helper for callers that only need the stored rows as-is,
 * such as the test suite and aafm_allowlist_overrides_for_display() below (which adds the failure
 * signal this bare read discards). It still reads through aafm_read_option_views() rather than
 * get_option(), for the same stale-persistent-object-cache reason the 1.7.3 hotfix fixed for the
 * read-only-mode and high-risk switches.
 *
 * @return array<int,array<string,mixed>>
 */
function aafm_allowlist_overrides(): array {
	$views = aafm_read_option_views( 'aafm_ability_allowlist_overrides' );
	if ( ! $views['db_found'] ) {
		return array();
	}
	$rows = $views['db_value'];
	return is_array( $rows ) ? $rows : array();
}

/**
 * The override rows for the admin display, alongside whether the read that produced them can be
 * trusted - unlike aafm_allowlist_overrides() above, which collapses "genuinely no rows" and "the
 * query itself failed" to the same empty array.
 *
 * R3-3 (1.7.5 deferred, round 3): that collapse is wrong here, for a display the operator can
 * then edit and Save from - it is also wrong for authorization, which is exactly why
 * aafm_ability_allowed_for_principal() below does not use aafm_allowlist_overrides() either, and
 * instead reads the database view directly so a failed read denies rather than grants
 * unrestricted access (see that function's own docblock). A failed read rendered here as "No
 * scopes narrowed yet", with Add and Save still available, is not cosmetic: if the database
 * recovers before the operator clicks Save, the empty editor submits a full replacement and
 * silently erases every existing restriction. The caller here must be told the read failed, not
 * handed an empty state that looks identical to a genuinely unrestricted site.
 *
 * @return array{ok: bool, rows: array<int,array<string,mixed>>}
 */
function aafm_allowlist_overrides_for_display(): array {
	$views = aafm_read_option_views( 'aafm_ability_allowlist_overrides' );
	if ( $views['db_error'] ) {
		return array(
			'ok'   => false,
			'rows' => array(),
		);
	}
	$rows = $views['db_found'] ? $views['db_value'] : array();
	return array(
		'ok'   => true,
		'rows' => is_array( $rows ) ? $rows : array(),
	);
}

/**
 * The effective allowed set for one scope match: an array of ability names, or the literal
 * string 'all' meaning "no restriction from this row."
 *
 * Returns null for a malformed allowed_abilities value, so the caller can skip the row entirely
 * (fail-closed: a bad row is treated as absent, never as all-permissive).
 *
 * Codex final round 7 LOW, per 228-allowlist-design.md section 6's own fail-closed statement:
 * "an override row that references an ability slug no longer in the registry ... is never
 * interpreted as allow everything. The exact fallback: skip the row entirely." A row whose
 * allowed_abilities lists ONLY a stale or mistyped name (the admin save no longer accepts one at
 * write time - see aafm_allowlist_sanitize_row() - but an ability can still be renamed/removed by
 * a later plugin upgrade after the row was saved) matched no real ability name, so
 * aafm_allowlist_set_permits() denied every call for that scope instead of degrading to
 * unrestricted. Checked against aafm_get_abilities_registry_full() (every registered ability,
 * including an inactive integration's) rather than the live registry, so a row saved while an
 * integration was active does not spuriously look stale the moment that host is deactivated.
 *
 * @param mixed $allowed_abilities Raw value from a stored row.
 * @return array<int,string>|string|null
 */
function aafm_allowlist_normalize_allowed( $allowed_abilities ) {
	if ( 'all' === $allowed_abilities ) {
		return 'all';
	}
	if ( is_array( $allowed_abilities ) ) {
		$names    = array_values( array_map( 'strval', $allowed_abilities ) );
		$registry = aafm_get_abilities_registry_full();
		foreach ( $names as $name ) {
			if ( ! array_key_exists( $name, $registry ) ) {
				return null; // Row references an unknown ability: skip the whole row, per design.
			}
		}
		return $names;
	}
	return null;
}

/**
 * Whether ability $ability_name is allowed under one already-normalized set.
 *
 * @param array<int,string>|string $set          A normalized allowed-abilities value ('all' or a name list).
 * @param string                   $ability_name Ability name, e.g. 'aafm/update-post'.
 * @return bool
 */
function aafm_allowlist_set_permits( $set, string $ability_name ): bool {
	if ( ! is_array( $set ) ) {
		return 'all' === $set;
	}
	return in_array( $ability_name, $set, true );
}

/**
 * Whether ability $ability_name is allowed for principal ($user_id, $oauth_client_id) by this
 * layer ALONE - callers must still clear the global enabled-abilities list and every other
 * existing floor themselves; this function only evaluates the new override option.
 *
 * R2-4 sibling (1.7.5 deferred, round 2): this is a LIVE AUTHORIZATION read, not a migration
 * certification read, so it must fail in the OPPOSITE direction from
 * aafm_oauth_dcr_adopt_on_by_default() (includes/oauth/discovery.php). aafm_allowlist_overrides()
 * collapses "genuinely no rows" and "the query itself failed" to the same empty array, and an
 * empty array here means "unrestricted" - so a transient read failure would silently grant every
 * call through this layer instead of enforcing whatever restriction is actually stored. Reading
 * the database view directly here, rather than through that lenient helper, lets this deny the
 * call when the restriction state cannot be determined.
 *
 * 1.7.5 round 4, R4-8: a failed read has a security consequence for the admin display too (R3-3,
 * above) - it is not exempt from this problem, it has its own dedicated failure-aware reader,
 * aafm_allowlist_overrides_for_display(). Neither production caller of this option still goes
 * through the lenient aafm_allowlist_overrides() helper; it survives only as the plain raw read
 * described on its own docblock.
 *
 * @param string      $ability_name    Ability name, e.g. 'aafm/update-post'.
 * @param int         $user_id         The calling user id (0 for none).
 * @param string|null $oauth_client_id The current request's OAuth client id, or null when the
 *                                     request is not OAuth-authenticated.
 * @return bool
 */
function aafm_ability_allowed_for_principal( string $ability_name, int $user_id, ?string $oauth_client_id ): bool {
	$views = aafm_read_option_views( 'aafm_ability_allowlist_overrides' );
	if ( $views['db_error'] ) {
		return false; // Cannot certify the restriction state: deny rather than fail open.
	}
	$rows = is_array( $views['db_value'] ) ? $views['db_value'] : array();
	if ( array() === $rows ) {
		return true; // No override rows at all: identical to today's behavior.
	}

	$roles = array();
	if ( $user_id > 0 ) {
		$user = get_userdata( $user_id );
		if ( $user instanceof WP_User ) {
			$roles = (array) $user->roles;
		}
	}

	$role_matched = false;
	$role_union   = array();
	$role_all     = false;

	$client_matched = false;
	$client_set     = 'all';

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$scope_type = isset( $row['scope_type'] ) ? (string) $row['scope_type'] : '';
		$scope_id   = isset( $row['scope_id'] ) ? (string) $row['scope_id'] : '';
		$normalized = aafm_allowlist_normalize_allowed( $row['allowed_abilities'] ?? null );
		if ( null === $normalized || '' === $scope_id ) {
			continue; // Malformed row: skip, never treat as permissive.
		}

		if ( 'role' === $scope_type && in_array( $scope_id, $roles, true ) ) {
			$role_matched = true;
			if ( is_array( $normalized ) ) {
				$role_union = array_merge( $role_union, $normalized );
			} else {
				$role_all = true;
			}
		} elseif ( 'oauth_client' === $scope_type && null !== $oauth_client_id && $scope_id === $oauth_client_id ) {
			// Multiple rows for the same client id are not a supported shape; the first
			// (evaluation-order) match wins rather than silently widening via a second union.
			if ( ! $client_matched ) {
				$client_matched = true;
				$client_set     = $normalized;
			}
		}
	}

	if ( $role_matched && ! $role_all && ! aafm_allowlist_set_permits( $role_union, $ability_name ) ) {
		return false;
	}
	if ( $client_matched && ! aafm_allowlist_set_permits( $client_set, $ability_name ) ) {
		return false;
	}
	return true;
}
