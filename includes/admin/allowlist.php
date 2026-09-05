<?php
/**
 * Admin UX for the per-role/per-connection ability allowlist (includes/allowlist.php).
 *
 * Rendered as its own card on the Connections tab, per 228-allowlist-design.md section 5 ("the
 * operator is already looking at connections there"). The row editor is a plain textarea of
 * ability names (one per line, or the literal "all") rather than a full checkbox grid per scope -
 * a deliberate simplification given the size of the full ability catalog; see the docblock on
 * aafm_render_allowlist_section() for the upgrade path.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Sanitize one posted override row into the stored shape, or null when it cannot be salvaged.
 *
 * @param mixed $row Raw decoded row.
 * @return array{scope_type:string,scope_id:string,allowed_abilities:array<int,string>|string}|null
 */
function aafm_allowlist_sanitize_row( $row ) {
	if ( ! is_array( $row ) ) {
		return null;
	}
	$scope_type = isset( $row['scope_type'] ) ? sanitize_key( (string) $row['scope_type'] ) : '';
	if ( ! in_array( $scope_type, array( 'role', 'oauth_client' ), true ) ) {
		return null;
	}
	$scope_id = isset( $row['scope_id'] ) ? aafm_sanitize_plain_text( (string) $row['scope_id'] ) : '';
	if ( '' === $scope_id ) {
		return null;
	}
	if ( 'role' === $scope_type && ! array_key_exists( $scope_id, wp_roles()->roles ) ) {
		return null; // Refuse a role slug that does not exist on this site.
	}
	// Codex final round 2 MEDIUM: an OAuth client id was accepted as arbitrary free text, so a
	// mistyped id saved successfully but matched no real client - the allowlist has no override
	// for that client at all, and per the design's own intersection precedence (global list only,
	// no narrowing), an unmatched client is UNRESTRICTED. Reject an id that names no real client,
	// the same way a nonexistent role slug is already refused above.
	if ( 'oauth_client' === $scope_type && null === aafm_oauth_get_client( $scope_id ) ) {
		return null;
	}

	$raw_allowed = $row['allowed_abilities'] ?? null;
	if ( 'all' === $raw_allowed ) {
		$allowed = 'all';
	} elseif ( is_array( $raw_allowed ) ) {
		$names   = array_filter(
			array_map(
				static fn( $name ): string => aafm_sanitize_plain_text( (string) $name ),
				$raw_allowed
			)
		);
		$allowed = array_values( array_unique( $names ) );
	} else {
		return null;
	}

	return array(
		'scope_type'        => $scope_type,
		'scope_id'          => $scope_id,
		'allowed_abilities' => $allowed,
	);
}

/**
 * AJAX handler: save the full set of allowlist override rows.
 *
 * Mirrors aafm_ajax_save_settings()'s nonce/capability shape. The client posts the WHOLE rows
 * array as a JSON string (allowlist_json) rather than one row at a time, so a delete/reorder/edit
 * in the admin UI is one atomic save with no partial-update ordering to get wrong.
 *
 * @return void
 */
function aafm_ajax_save_allowlist(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON, decoded below; every field is sanitized per-row in aafm_allowlist_sanitize_row().
	$raw     = isset( $_POST['allowlist_json'] ) ? wp_unslash( (string) $_POST['allowlist_json'] ) : '[]';
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not read the submitted allowlist.', 'agent-abilities-for-mcp' ) ), 400 );
	}

	if ( count( $decoded ) > AAFM_ALLOWLIST_MAX_ROWS ) {
		wp_send_json_error(
			array(
				/* translators: %d: the maximum number of allowlist override rows. */
				'message' => sprintf( __( 'An allowlist can hold at most %d rows.', 'agent-abilities-for-mcp' ), AAFM_ALLOWLIST_MAX_ROWS ),
			),
			400
		);
	}

	// Keyed by "scope_type:scope_id" so two rows for the same scope can never both reach
	// storage: aafm_ability_allowed_for_principal() (includes/allowlist.php) evaluates only the
	// FIRST matching oauth_client row it finds, which would make one scope's effective allowlist
	// depend on row order rather than its own content. A later duplicate in the submitted set
	// wins, matching what the admin UI shows the operator as the current value for that scope.
	$rows = array();
	foreach ( $decoded as $row ) {
		$clean = aafm_allowlist_sanitize_row( $row );
		if ( null !== $clean ) {
			$rows[ $clean['scope_type'] . ':' . $clean['scope_id'] ] = $clean;
		}
	}
	$rows = array_values( $rows );

	if ( ! aafm_update_option_verified( 'aafm_ability_allowlist_overrides', $rows ) ) {
		wp_send_json_error( array( 'message' => __( 'The allowlist could not be saved. Please try again.', 'agent-abilities-for-mcp' ) ), 500 );
	}

	wp_send_json_success( array( 'rows' => $rows ) );
}

/**
 * Render the "Ability allowlist" card on the Connections tab.
 *
 * A plain per-row textarea of ability names (or the literal "all") rather than a checkbox grid
 * per scope: the full catalog runs to well over a hundred abilities, and a grid repeated once per
 * role and once per OAuth client would be a large amount of markup for a feature most sites will
 * touch rarely. ponytail: textarea-based row editor, not a full checkbox grid; upgrade to a grid
 * (reusing the existing aafm_ability_toggle_row() component from the Abilities tab) if operators
 * report the plain-text list is hard to use.
 *
 * @return void
 */
function aafm_render_allowlist_section(): void {
	$rows  = aafm_allowlist_overrides();
	$roles = wp_roles()->get_names();

	echo '<section class="aafm-card aafm-card-pad aafm-allowlist-card">';
	echo '<h2>' . esc_html__( 'Ability allowlist', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '<p class="sub">' . esc_html__( 'Optionally narrow which abilities a role or a specific connection may reach, on top of the abilities enabled above. Leave a scope with no row to leave it unrestricted.', 'agent-abilities-for-mcp' ) . '</p>';

	echo '<table class="aafm-allowlist-table" id="aafm-allowlist-table">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Scope', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Allowed abilities', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th></th>';
	echo '</tr></thead><tbody>';
	foreach ( $rows as $i => $row ) {
		$scope_type = (string) ( $row['scope_type'] ?? '' );
		$scope_id   = (string) ( $row['scope_id'] ?? '' );
		$allowed    = $row['allowed_abilities'] ?? array();
		$allowed    = is_array( $allowed ) ? implode( "\n", $allowed ) : (string) $allowed;
		$label      = 'role' === $scope_type
			? sprintf( /* translators: %s: role display name. */ __( 'Role: %s', 'agent-abilities-for-mcp' ), $roles[ $scope_id ] ?? $scope_id )
			: sprintf( /* translators: %s: OAuth client id. */ __( 'Connection: %s', 'agent-abilities-for-mcp' ), $scope_id );
		echo '<tr data-allowlist-row data-scope-type="' . esc_attr( $scope_type ) . '" data-scope-id="' . esc_attr( $scope_id ) . '">';
		echo '<td>' . esc_html( $label ) . '</td>';
		echo '<td><textarea class="aafm-allowlist-allowed" rows="2">' . esc_textarea( $allowed ) . '</textarea></td>';
		echo '<td><button type="button" class="aafm-btn aafm-btn-secondary aafm-allowlist-remove">' . esc_html__( 'Remove', 'agent-abilities-for-mcp' ) . '</button></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';

	echo '<div class="aafm-allowlist-add">';
	echo '<select id="aafm-allowlist-new-scope-type">';
	echo '<option value="role">' . esc_html__( 'Role', 'agent-abilities-for-mcp' ) . '</option>';
	echo '<option value="oauth_client">' . esc_html__( 'OAuth connection', 'agent-abilities-for-mcp' ) . '</option>';
	echo '</select>';
	echo '<input type="text" id="aafm-allowlist-new-scope-id" placeholder="' . esc_attr__( 'Role slug or client id', 'agent-abilities-for-mcp' ) . '">';
	echo '<button type="button" class="aafm-btn aafm-btn-secondary" id="aafm-allowlist-add-row">' . esc_html__( 'Add scope', 'agent-abilities-for-mcp' ) . '</button>';
	echo '</div>';

	echo '<button type="button" class="aafm-btn aafm-btn-primary" id="aafm-allowlist-save">' . esc_html__( 'Save allowlist', 'agent-abilities-for-mcp' ) . '</button>';
	echo '<span id="aafm-allowlist-status" class="aafm-muted" role="status"></span>';
	echo '</section>';
}
