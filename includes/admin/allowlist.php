<?php
/**
 * Admin UX for the per-role/per-connection ability allowlist (includes/allowlist.php).
 *
 * Rendered as its own card on the Connections tab, per 228-allowlist-design.md section 5 ("the
 * operator is already looking at connections there"). The row editor is a searchable, grouped
 * checkbox picker (see aafm_render_allowlist_section()) rather than a free-text field: the raw
 * ability names it would have asked for are never shown anywhere else in the admin, and one bad
 * name used to reject the whole save.
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
		// Codex final round 7 LOW: a row naming an ability slug absent from the registry (typo,
		// or a name from a since-removed integration) used to save successfully and then, at
		// read time, deny EVERY real ability for that scope - the row matched no real name, so
		// aafm_allowlist_set_permits() refused everything rather than degrading to unrestricted,
		// the opposite of 228-allowlist-design.md section 6's fail-closed statement. Reject the
		// save the same way an unknown role/client is already rejected below, rather than let a
		// typo silently lock out a role. Checked against the FULL registry (every registered
		// ability, including an inactive integration's) so a name is not refused merely because
		// its host plugin happens to be off right now.
		$registry = aafm_get_abilities_registry_full();
		foreach ( $allowed as $name ) {
			if ( ! array_key_exists( $name, $registry ) ) {
				return null;
			}
		}
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
 * Build the abilities catalog the allowlist's ability picker renders from, grouped by subject.
 *
 * Localized to admin.js as aafmAdmin.allowlistCatalog (aafm_enqueue_admin_assets()) so every
 * row's picker, including one added client-side via "Add scope", is built from one shared list
 * instead of duplicating this grouping in both PHP and JS. Reads the FULL registry - the same
 * one aafm_allowlist_sanitize_row() validates a save against - so the picker can never offer,
 * and the validator can never refuse, a name the other side disagrees about.
 *
 * Grouped by subject the way the Abilities tab groups its own sub-tabs, reusing
 * aafm_abilities_subjects()'s labels for the core subjects and aafm_integration_cards()'s
 * labels for everything else (WooCommerce, ACF, the SEO plugins, …) - the full registry carries
 * both, and the allowlist has to offer both.
 *
 * @return list<array{subject:string,label:string,abilities:list<array{name:string,label:string}>}>
 */
function aafm_allowlist_ability_catalog(): array {
	$registry = aafm_get_abilities_registry_full();

	$labels = aafm_abilities_subjects();
	foreach ( aafm_integration_cards() as $slug => $card ) {
		if ( ! isset( $labels[ $slug ] ) ) {
			$labels[ $slug ] = (string) ( $card['label'] ?? $slug );
		}
	}

	$groups = array();
	foreach ( $registry as $name => $meta ) {
		$subject = (string) ( $meta['subject'] ?? '' );
		if ( '' === $subject ) {
			continue;
		}
		if ( ! isset( $groups[ $subject ] ) ) {
			$groups[ $subject ] = array(
				'subject'   => $subject,
				'label'     => $labels[ $subject ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $subject ) ),
				'abilities' => array(),
			);
		}
		$groups[ $subject ]['abilities'][] = array(
			'name'  => (string) $name,
			'label' => (string) ( $meta['label'] ?? $name ),
		);
	}

	// Declared subjects first, in their declared display order (matching the Abilities and
	// Integrations tabs), then any remaining subject alphabetically by label.
	$ordered = array();
	foreach ( array_keys( $labels ) as $slug ) {
		if ( isset( $groups[ $slug ] ) ) {
			$ordered[] = $groups[ $slug ];
			unset( $groups[ $slug ] );
		}
	}
	uasort( $groups, static fn( array $a, array $b ): int => strcasecmp( $a['label'], $b['label'] ) );

	return array_values( array_merge( $ordered, array_values( $groups ) ) );
}

/**
 * Human-readable summary of a row's stored allowed-abilities value, for the no-JS-yet fallback
 * text the picker cell renders before admin.js hydrates it into the interactive control.
 *
 * @param bool                              $is_all True when the row is the literal string "all".
 * @param array<int,string>                 $names  Ability names when not "all".
 * @param array<string,array<string,mixed>> $registry_full The full ability registry, for labels.
 * @return string
 */
function aafm_allowlist_allowed_summary( bool $is_all, array $names, array $registry_full ): string {
	if ( $is_all ) {
		return __( 'All abilities (no narrowing)', 'agent-abilities-for-mcp' );
	}
	if ( empty( $names ) ) {
		return __( 'No abilities selected - this scope can reach nothing.', 'agent-abilities-for-mcp' );
	}
	$labels = array_map(
		static fn( string $name ): string => (string) ( $registry_full[ $name ]['label'] ?? $name ),
		$names
	);
	sort( $labels );
	return implode( ', ', $labels );
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

	// Codex final round 3 MEDIUM: a row naming an unknown role or OAuth client used to be
	// silently dropped while the save still reported success, so the operator could believe a
	// restriction had taken effect when the row that was meant to apply it never reached
	// storage. Reject the WHOLE save with a row-specific error instead, and leave the
	// previously-stored option untouched - a half-applied allowlist under a "saved" report is
	// exactly the silent-wrong-answer shape this project treats as a release blocker.
	//
	// Keyed by "scope_type:scope_id" so two rows for the same scope can never both reach
	// storage: aafm_ability_allowed_for_principal() (includes/allowlist.php) evaluates only the
	// FIRST matching oauth_client row it finds, which would make one scope's effective allowlist
	// depend on row order rather than its own content. A later duplicate in the submitted set
	// wins, matching what the admin UI shows the operator as the current value for that scope.
	$rows = array();
	foreach ( $decoded as $index => $row ) {
		$clean = aafm_allowlist_sanitize_row( $row );
		if ( null === $clean ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: 1-based row number in the submitted allowlist. */
						__( 'Row %d names a role, OAuth client, or ability that does not exist. Nothing was saved - fix that row and try again.', 'agent-abilities-for-mcp' ),
						(int) $index + 1
					),
				),
				400
			);
		}
		$rows[ $clean['scope_type'] . ':' . $clean['scope_id'] ] = $clean;
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
 * Each row's "Allowed abilities" cell renders as a data shell only: a JSON-encoded
 * `data-allowed` attribute (the literal string "all", or the array of ability names) and a
 * human-readable text summary as a no-JS fallback. admin.js hydrates every such cell into the
 * interactive searchable/grouped checkbox picker at bind time, from the same
 * aafmAdmin.allowlistCatalog data a client-side "Add scope" row also builds its picker from -
 * one shared list rather than duplicating the grouped-checkbox markup in both PHP and JS. This
 * mirrors the rest of the card, which is already entirely JS-driven (add/remove/save have no
 * non-JS path either).
 *
 * Hand-matches the shared collapsible-section markup (aafm_render_section() with
 * `collapsible => true`) rather than calling that function: the section component pipes its
 * `body` argument through wp_kses( aafm_admin_allowed_html() ), whose data-* allowlist does not
 * include data-allowlist-row/data-scope-type/data-scope-id/data-allowed - routing this card's
 * rows through it would silently strip all four, and admin.js reads them to remove, hydrate, and
 * save rows. This file already escapes every value it echoes per-leaf, so writing the same
 * head/body markup directly carries no new security surface.
 *
 * @return void
 */
function aafm_render_allowlist_section(): void {
	$rows          = aafm_allowlist_overrides();
	$roles         = wp_roles()->get_names();
	$registry_full = aafm_get_abilities_registry_full();

	echo '<details class="aafm-card aafm-section aafm-section--collapsible aafm-allowlist-card" open>';
	echo '<summary class="aafm-card-head">';
	echo '<span class="aafm-card-head-ic">' . wp_kses( aafm_icon( 'lock' ), aafm_svg_allowed_html() ) . '</span>';
	echo '<div class="aafm-card-head-text">';
	echo '<h3 class="aafm-card-head-title">' . esc_html__( 'Ability allowlist', 'agent-abilities-for-mcp' ) . '</h3>';
	echo '</div>';
	echo '</summary>';

	echo '<div class="aafm-section-body">';

	// The description used to live inside <summary> alongside the title, per the shared
	// component's non-collapsible shape copied here by mistake - the description then collided
	// with the table below it, and being part of the summary's click target meant clicking the
	// explanation toggled the card. It belongs in the body, as prose, not in the disclosure control.
	echo '<p class="aafm-card-head-desc">' . esc_html__( 'Optionally narrow which abilities a role or a specific connection may reach, on top of the abilities enabled above. Leave a scope with no row to leave it unrestricted.', 'agent-abilities-for-mcp' ) . '</p>';

	if ( empty( $rows ) ) {
		echo '<p class="aafm-empty-state" id="aafm-allowlist-empty">' . esc_html__( 'No scopes narrowed yet. Every role and connection can reach everything enabled above.', 'agent-abilities-for-mcp' ) . '</p>';
	} else {
		echo '<div class="aafm-table-wrap" id="aafm-allowlist-table-wrap">';
		echo '<table class="widefat striped aafm-oauth-table aafm-allowlist-table" id="aafm-allowlist-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Scope', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th>' . esc_html__( 'Allowed abilities', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$scope_type  = (string) ( $row['scope_type'] ?? '' );
			$scope_id    = (string) ( $row['scope_id'] ?? '' );
			$allowed_raw = $row['allowed_abilities'] ?? array();
			$is_all      = ! is_array( $allowed_raw ) && 'all' === $allowed_raw;
			$names       = $is_all ? array() : array_values( array_map( 'strval', (array) $allowed_raw ) );
			$summary     = aafm_allowlist_allowed_summary( $is_all, $names, $registry_full );
			$label       = 'role' === $scope_type
				? sprintf( /* translators: %s: role display name. */ __( 'Role: %s', 'agent-abilities-for-mcp' ), $roles[ $scope_id ] ?? $scope_id )
				: sprintf( /* translators: %s: OAuth client id. */ __( 'Connection: %s', 'agent-abilities-for-mcp' ), $scope_id );
			echo '<tr data-allowlist-row data-scope-type="' . esc_attr( $scope_type ) . '" data-scope-id="' . esc_attr( $scope_id ) . '">';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td class="aafm-allowlist-allowed-cell" data-allowed="' . esc_attr( (string) wp_json_encode( $is_all ? 'all' : $names ) ) . '">';
			echo '<p class="aafm-allowlist-allowed-summary">' . esc_html( $summary ) . '</p>';
			echo '</td>';
			echo '<td><button type="button" class="aafm-btn aafm-btn-secondary aafm-allowlist-remove">' . esc_html__( 'Remove', 'agent-abilities-for-mcp' ) . '</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	// The scope-id control offers only real values, following the scope-type select: a free-text
	// field let the operator mistype a role slug, and offered no way at all to discover a client
	// id. Both lists are already loaded on this page - $roles above, and aafm_oauth_list_clients()
	// (used identically for the registered-clients table earlier on this tab) - so this is a
	// second <select>, not new data. The value submitted is always the slug or client id, never
	// the display text; server-side validation (aafm_allowlist_sanitize_row()) is unchanged and is
	// still the real boundary, this only makes the common mistake unreachable through the UI.
	$clients = aafm_oauth_list_clients();

	echo '<div class="aafm-allowlist-add" id="aafm-allowlist-add">';
	echo '<select id="aafm-allowlist-new-scope-type">';
	echo '<option value="role">' . esc_html__( 'Role', 'agent-abilities-for-mcp' ) . '</option>';
	echo '<option value="oauth_client">' . esc_html__( 'OAuth connection', 'agent-abilities-for-mcp' ) . '</option>';
	echo '</select>';

	echo '<select id="aafm-allowlist-new-role">';
	echo '<option value="">' . esc_html__( 'Choose a role…', 'agent-abilities-for-mcp' ) . '</option>';
	foreach ( $roles as $role_slug => $role_label ) {
		printf( '<option value="%1$s">%2$s</option>', esc_attr( $role_slug ), esc_html( $role_label ) );
	}
	echo '</select>';

	echo '<select id="aafm-allowlist-new-client" hidden>';
	if ( empty( $clients ) ) {
		echo '<option value="">' . esc_html__( 'No OAuth connections registered yet', 'agent-abilities-for-mcp' ) . '</option>';
	} else {
		echo '<option value="">' . esc_html__( 'Choose a connection…', 'agent-abilities-for-mcp' ) . '</option>';
		foreach ( $clients as $client ) {
			$client_id       = (string) ( $client['client_id'] ?? '' );
			$client_short_id = strlen( $client_id ) > 14 ? substr( $client_id, 0, 14 ) . '…' : $client_id;
			// Same "(unnamed client)" fallback the registered-clients table already uses, so an
			// unnamed connection is still identifiable rather than showing an empty option label.
			$client_name = '' !== ( $client['client_name'] ?? '' ) ? (string) $client['client_name'] : __( '(unnamed client)', 'agent-abilities-for-mcp' );
			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( $client_id ),
				/* translators: 1: client display name, 2: truncated client id. */
				esc_html( sprintf( __( '%1$s (%2$s)', 'agent-abilities-for-mcp' ), $client_name, $client_short_id ) )
			);
		}
	}
	echo '</select>';

	echo '<button type="button" class="aafm-btn aafm-btn-secondary" id="aafm-allowlist-add-row">' . esc_html__( 'Add scope', 'agent-abilities-for-mcp' ) . '</button>';
	echo '</div>';

	echo '<p><button type="button" class="aafm-btn aafm-btn-primary" id="aafm-allowlist-save">' . esc_html__( 'Save allowlist', 'agent-abilities-for-mcp' ) . '</button> <span id="aafm-allowlist-status" class="aafm-muted" role="status"></span></p>';

	echo '</div>'; // .aafm-section-body
	echo '</details>';
}
