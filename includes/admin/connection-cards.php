<?php
/**
 * The Connection tab's OAuth and Application Passwords tabs and their cards.
 *
 * Each card is a data function that returns plain arrays (what a REST route would serve) plus a
 * renderer that takes only that data.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Data for the "Registered clients" card.
 *
 * @return array{id:string,title:string,rule:string,count:int,rows:list<array<string,mixed>>,empty:array{title:string,hint:string}}
 */
function aafm_connection_clients_data(): array {
	$rows = array_values( aafm_oauth_list_clients() );
	return array(
		'id'    => 'clients',
		'title' => __( 'Registered clients', 'agent-abilities-for-mcp' ),
		'rule'  => sprintf(
			/* translators: %s: how long an unapproved registration is kept, for example "1 week". */
			__( 'A registration nobody approved is deleted automatically after %s. An app someone approved is kept until you revoke it.', 'agent-abilities-for-mcp' ),
			human_time_diff( 0, aafm_oauth_client_reap_ttl() )
		),
		'count' => count( $rows ),
		'rows'  => $rows,
		'empty' => array(
			'title' => __( 'No registered clients yet', 'agent-abilities-for-mcp' ),
			'hint'  => __( 'When an AI app registers over OAuth, it shows up here.', 'agent-abilities-for-mcp' ),
		),
	);
}

/**
 * Data for the "Active grants" card. Each row is an aafm_oauth_list_grants() row plus its status.
 *
 * @return array{id:string,title:string,rule:string,count:int,rows:list<array<string,mixed>>,empty:array{title:string,hint:string}}
 */
function aafm_connection_grants_data(): array {
	$rows = array_values(
		array_map(
			static function ( array $row ): array {
				$row['status'] = $row['expired'] ? 'expired' : ( $row['paused'] ? 'paused' : 'active' );
				return $row;
			},
			aafm_oauth_list_grants()
		)
	);
	return array(
		'id'    => 'grants',
		'title' => __( 'Active grants', 'agent-abilities-for-mcp' ),
		'rule'  => __( 'Each row is one person who approved one app. Revoking signs that app out for that person at once.', 'agent-abilities-for-mcp' ),
		'count' => count( $rows ),
		'rows'  => $rows,
		'empty' => array(
			'title' => __( 'No active grants yet', 'agent-abilities-for-mcp' ),
			'hint'  => __( 'When someone approves an AI app, the approval shows up here.', 'agent-abilities-for-mcp' ),
		),
	);
}

/**
 * A card's title with its row count, when there is anything to count.
 *
 * @param array{title:string,count:int} $data Card data.
 * @return string
 */
function aafm_connection_card_title( array $data ): string {
	if ( 0 === $data['count'] ) {
		return $data['title'];
	}
	return sprintf( '%1$s (%2$s)', $data['title'], number_format_i18n( $data['count'] ) );
}

/**
 * The empty state a card shows when it has no rows.
 *
 * @param array{title:string,hint:string} $text Empty-state text.
 * @return string Escaped HTML.
 */
function aafm_connection_empty_state( array $text ): string {
	return sprintf( '<div class="aafm-empty-state"><strong>%1$s</strong><br>%2$s</div>', esc_html( $text['title'] ), esc_html( $text['hint'] ) );
}

/**
 * Render the "Registered clients" card.
 *
 * @param array{id:string,title:string,rule:string,count:int,rows:list<array<string,mixed>>,empty:array{title:string,hint:string}} $data aafm_connection_clients_data().
 * @return void
 */
function aafm_render_clients_card( array $data ): void {
	ob_start();
	if ( array() === $data['rows'] ) {
		echo aafm_connection_empty_state( $data['empty'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper.
	} else {
		echo '<div class="aafm-table-wrap">';
		echo '<table class="widefat striped aafm-oauth-table aafm-clients-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Client', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th>' . esc_html__( 'Client ID', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th>' . esc_html__( 'Redirect URIs', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th>' . esc_html__( 'Active tokens', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'agent-abilities-for-mcp' ) . '</th>';
		printf(
			'<th><span title="%1$s">%2$s</span></th>',
			esc_attr__( 'Marks this client as an AI agent connection rather than an ordinary human client, surfaced on the activity log.', 'agent-abilities-for-mcp' ),
			esc_html__( 'Agent', 'agent-abilities-for-mcp' )
		);
		echo '<th>' . esc_html__( 'Action', 'agent-abilities-for-mcp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $data['rows'] as $client ) {
			$full_id  = $client['client_id'];
			$short_id = strlen( $full_id ) > 14 ? substr( $full_id, 0, 14 ) . '…' : $full_id;
			$name     = '' !== $client['client_name'] ? $client['client_name'] : __( '(unnamed client)', 'agent-abilities-for-mcp' );

			printf( '<tr data-client-row="%s">', esc_attr( $full_id ) );
			printf( '<td>%s</td>', esc_html( $name ) );
			// The cell cannot hold a full client id without breaking the table layout, so the
			// column stays truncated; a copy control carries the full id.
			echo '<td>';
			echo wp_kses(
				sprintf(
					'<span class="aafm-field-mono aafm-client-id-cell"><code title="%1$s">%2$s</code><button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm aafm-copy" data-copy="%1$s" aria-label="%5$s">%3$s<span class="aafm-copy-label">%4$s</span></button></span>',
					esc_attr( $full_id ),
					esc_html( $short_id ),
					aafm_icon( 'copy' ),
					esc_html__( 'Copy', 'agent-abilities-for-mcp' ),
					esc_attr__( 'Copy the full client ID', 'agent-abilities-for-mcp' )
				),
				aafm_admin_allowed_html()
			);
			echo '</td>';

			echo '<td>';
			if ( empty( $client['redirect_uris'] ) ) {
				echo '<span class="aafm-muted">' . esc_html__( 'None', 'agent-abilities-for-mcp' ) . '</span>';
			} else {
				$links = array();
				foreach ( $client['redirect_uris'] as $uri ) {
					$links[] = '<a href="' . esc_url( $uri ) . '" target="_blank" rel="noreferrer noopener">' . esc_html( $uri ) . '</a>';
				}
				echo wp_kses( implode( '<br>', $links ), aafm_admin_allowed_html() );
			}
			echo '</td>';

			printf( '<td>%s</td>', esc_html( aafm_format_admin_datetime( $client['created_at'] ) ) );
			printf( '<td class="aafm-client-tokens">%s</td>', esc_html( number_format_i18n( $client['active_tokens'] ) ) );

			echo '<td class="aafm-status-cell">';
			if ( $client['is_active'] ) {
				echo '<span class="aafm-pill aafm-pill-success">' . esc_html__( 'Active', 'agent-abilities-for-mcp' ) . '</span>';
			} else {
				echo '<span class="aafm-pill aafm-pill-neutral">' . esc_html__( 'Revoked', 'agent-abilities-for-mcp' ) . '</span>';
			}
			echo '</td>';

			printf(
				'<td><label class="aafm-switch"><input type="checkbox" class="aafm-client-agent-toggle" data-client-id="%1$s" aria-label="%3$s"%2$s><span class="aafm-switch-track"></span></label></td>',
				esc_attr( $full_id ),
				checked( ! empty( $client['is_agent_identity'] ), true, false ),
				esc_attr__( 'Agent identity', 'agent-abilities-for-mcp' )
			);

			echo '<td>';
			if ( $client['is_active'] ) {
				printf(
					'<button type="button" class="aafm-btn aafm-btn-secondary aafm-revoke-client" data-client-id="%s">%s</button>',
					esc_attr( $full_id ),
					esc_html__( 'Revoke', 'agent-abilities-for-mcp' )
				);
			} else {
				echo '<span class="aafm-muted">' . esc_html__( 'Revoked', 'agent-abilities-for-mcp' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
	aafm_render_section(
		array(
			'id'          => 'aafm-card-clients',
			'icon'        => 'groups',
			'title'       => aafm_connection_card_title( $data ),
			'description' => $data['rule'],
			'body'        => (string) ob_get_clean(),
		)
	);
}

/**
 * The status pill for one grant.
 *
 * @param string $status 'active', 'paused' or 'expired'.
 * @return string Escaped HTML.
 */
function aafm_connection_grant_pill( string $status ): string {
	$pills = array(
		'active'  => array( 'aafm-pill-success', __( 'Active', 'agent-abilities-for-mcp' ) ),
		'paused'  => array( 'aafm-pill-warn', __( 'Paused', 'agent-abilities-for-mcp' ) ),
		'expired' => array( 'aafm-pill-neutral', __( 'Expired', 'agent-abilities-for-mcp' ) ),
	);
	$pill  = $pills[ $status ] ?? $pills['active'];
	return sprintf( '<span class="aafm-pill %1$s">%2$s</span>', esc_attr( $pill[0] ), esc_html( $pill[1] ) );
}

/**
 * Render the "Active grants" card.
 *
 * The "Current role" column reads each identity's LIVE role (aafm_oauth_list_grants()), never a
 * value captured at approval: a token acts with whatever the identity can do right now.
 *
 * @param array{id:string,title:string,rule:string,count:int,rows:list<array<string,mixed>>,empty:array{title:string,hint:string}} $data aafm_connection_grants_data().
 * @return void
 */
function aafm_render_grants_card( array $data ): void {
	ob_start();
	if ( array() === $data['rows'] ) {
		echo aafm_connection_empty_state( $data['empty'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper.
	} else {
		$scope_hint     = __( 'The app can only do what this user\'s role allows and what you have turned on under Abilities.', 'agent-abilities-for-mcp' );
		$role_names_map = wp_roles()->get_names();

		echo '<div class="aafm-table-wrap">';
		echo '<table class="widefat striped aafm-oauth-table aafm-grants-table"><thead><tr>';
		echo '<th>' . esc_html__( 'User', 'agent-abilities-for-mcp' ) . '</th>';
		printf(
			'<th><span title="%1$s">%2$s</span></th>',
			esc_attr__( "The user's role right now, not necessarily what it was when they approved this connection. A token always acts with whatever this identity can currently do.", 'agent-abilities-for-mcp' ),
			esc_html__( 'Current role', 'agent-abilities-for-mcp' )
		);
		echo '<th>' . esc_html__( 'Client', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th>' . esc_html__( 'Scope', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th>' . esc_html__( 'Granted', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'agent-abilities-for-mcp' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'agent-abilities-for-mcp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $data['rows'] as $grant ) {
			$client_name = '' !== $grant['client_name'] ? $grant['client_name'] : __( '(unnamed client)', 'agent-abilities-for-mcp' );

			printf(
				'<tr data-grant-row="%1$s|%2$s">',
				esc_attr( (string) $grant['user_id'] ),
				esc_attr( $grant['client_id'] )
			);
			printf(
				'<td>%1$s <span class="aafm-muted">(%2$s)</span></td>',
				esc_html( $grant['user_display'] ),
				esc_html( $grant['user_login'] )
			);

			echo '<td>';
			$role_names = array_map(
				static function ( $role_slug ) use ( $role_names_map ) {
					return isset( $role_names_map[ $role_slug ] ) ? translate_user_role( $role_names_map[ $role_slug ] ) : (string) $role_slug;
				},
				(array) $grant['user_roles']
			);
			$role_label = ! empty( $role_names ) ? implode( ', ', $role_names ) : __( 'No role', 'agent-abilities-for-mcp' );
			if ( ! empty( $grant['is_high_privilege'] ) ) {
				printf(
					'<span class="aafm-pill aafm-pill-warn" title="%1$s">%2$s</span>',
					esc_attr__( 'This connection currently acts with full administrator access.', 'agent-abilities-for-mcp' ),
					esc_html( $role_label )
				);
			} else {
				echo esc_html( $role_label );
			}
			echo '</td>';

			printf( '<td>%s</td>', esc_html( $client_name ) );
			printf(
				'<td><span title="%1$s">%2$s</span></td>',
				esc_attr( $scope_hint ),
				esc_html__( 'Full access', 'agent-abilities-for-mcp' )
			);
			printf( '<td>%s</td>', esc_html( aafm_format_admin_datetime( $grant['granted_at'] ) ) );
			echo '<td class="aafm-status-cell">' . aafm_connection_grant_pill( $grant['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper.
			printf(
				'<td><button type="button" class="aafm-btn aafm-btn-secondary aafm-revoke-grant" data-user-id="%1$s" data-client-id="%2$s">%3$s</button></td>',
				esc_attr( (string) $grant['user_id'] ),
				esc_attr( $grant['client_id'] ),
				esc_html__( 'Revoke', 'agent-abilities-for-mcp' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
	aafm_render_section(
		array(
			'id'          => 'aafm-card-grants',
			'icon'        => 'connection',
			'title'       => aafm_connection_card_title( $data ),
			'description' => $data['rule'],
			'body'        => (string) ob_get_clean(),
		)
	);
}

/**
 * Render the OAuth and Application Passwords tabs and both panels.
 *
 * @param bool $oauth_on Whether OAuth is enabled; it decides which tab starts selected and whether
 *                       the OAuth panel lists clients and grants.
 * @return void
 */
function aafm_render_connection_tabs( bool $oauth_on ): void {
	$tabs   = array(
		'oauth'         => __( 'OAuth', 'agent-abilities-for-mcp' ),
		'app-passwords' => __( 'Application Passwords', 'agent-abilities-for-mcp' ),
	);
	$active = $oauth_on ? 'oauth' : 'app-passwords';

	echo '<div class="aafm-conn-tabs aafm-subtabs" role="tablist" aria-label="' . esc_attr__( 'How agents connect', 'agent-abilities-for-mcp' ) . '">';
	foreach ( $tabs as $slug => $label ) {
		printf(
			'<button type="button" role="tab" class="aafm-conn-tab%1$s" id="aafm-conn-tab-%2$s" aria-controls="aafm-conn-panel-%2$s" aria-selected="%3$s" tabindex="%4$s" data-conn-tab="%2$s">%5$s</button>',
			$slug === $active ? ' is-active' : '',
			esc_attr( $slug ),
			$slug === $active ? 'true' : 'false',
			$slug === $active ? '0' : '-1',
			esc_html( $label )
		);
	}
	echo '</div>';

	$panel = static function ( string $slug, string $extra_class ) use ( $active ): void {
		printf(
			'<div role="tabpanel" class="aafm-conn-panel%3$s" id="aafm-conn-panel-%1$s" aria-labelledby="aafm-conn-tab-%1$s" data-conn-panel="%1$s"%2$s>',
			esc_attr( $slug ),
			$slug === $active ? '' : ' hidden',
			esc_attr( $extra_class )
		);
	};

	$panel( 'oauth', '' );
	echo '<div class="aafm-oauth-manage">';
	wp_nonce_field( 'aafm_admin', 'aafm_oauth_admin_nonce' );
	aafm_render_lifetimes_card( aafm_connection_lifetimes_data() );
	if ( $oauth_on ) {
		aafm_render_clients_card( aafm_connection_clients_data() );
		aafm_render_grants_card( aafm_connection_grants_data() );
	}
	echo '</div>'; // .aafm-oauth-manage
	echo '</div>'; // OAuth panel.

	// The class stays on the panel: admin.js scopes the Step 1 to 3 controls to it.
	$panel( 'app-passwords', ' aafm-app-password-fallback' );
	aafm_render_app_password_steps();
	echo '</div>'; // Application Passwords panel.
}

/**
 * The allowed token lifetimes, in seconds: access 5 minutes to 24 hours, refresh 1 hour to 90 days.
 *
 * @return array{access:array{0:int,1:int},refresh:array{0:int,1:int}}
 */
function aafm_oauth_ttl_bounds(): array {
	return array(
		'access'  => array( 5 * MINUTE_IN_SECONDS, DAY_IN_SECONDS ),
		'refresh' => array( HOUR_IN_SECONDS, 90 * DAY_IN_SECONDS ),
	);
}

/**
 * A stored lifetime as the database holds it, or the default when there is no usable row.
 *
 * @param string $option   Lifetime option name.
 * @param int    $fallback Default lifetime in seconds.
 * @return int
 */
function aafm_oauth_stored_ttl( string $option, int $fallback ): int {
	$row = aafm_option_row( $option );
	return $row['ok'] && $row['found'] && is_scalar( $row['value'] ) ? (int) $row['value'] : $fallback;
}

/**
 * Data for the "Token lifetimes and client registration" card.
 *
 * @return array{access:int,refresh:int,access_default:int,refresh_default:int,bounds:array{access:array{0:int,1:int},refresh:array{0:int,1:int}},dcr:bool,oauth_enabled:bool}
 */
function aafm_connection_lifetimes_data(): array {
	return array(
		'access'          => aafm_oauth_stored_ttl( 'aafm_oauth_access_ttl', (int) AAFM_OAUTH_ACCESS_TTL ),
		'refresh'         => aafm_oauth_stored_ttl( 'aafm_oauth_refresh_ttl', (int) AAFM_OAUTH_REFRESH_TTL ),
		'access_default'  => (int) AAFM_OAUTH_ACCESS_TTL,
		'refresh_default' => (int) AAFM_OAUTH_REFRESH_TTL,
		'bounds'          => aafm_oauth_ttl_bounds(),
		'dcr'             => aafm_oauth_dcr_enabled(),
		'oauth_enabled'   => aafm_oauth_enabled(),
	);
}

/**
 * The number input and unit select for one lifetime. The unit is the largest that divides the
 * seconds evenly.
 *
 * @param string $kind    'access' or 'refresh'.
 * @param string $label   Row label.
 * @param int    $seconds Stored lifetime.
 * @param int    $fallback Default lifetime.
 * @return string Escaped HTML.
 */
function aafm_connection_ttl_row( string $kind, string $label, int $seconds, int $fallback ): string {
	$units = array(
		DAY_IN_SECONDS    => __( 'days', 'agent-abilities-for-mcp' ),
		HOUR_IN_SECONDS   => __( 'hours', 'agent-abilities-for-mcp' ),
		MINUTE_IN_SECONDS => __( 'minutes', 'agent-abilities-for-mcp' ),
	);
	$unit  = MINUTE_IN_SECONDS;
	foreach ( array_keys( $units ) as $size ) {
		if ( $seconds > 0 && 0 === $seconds % $size ) {
			$unit = $size;
			break;
		}
	}
	// ponytail: a stored value that is not a whole number of minutes shows rounded; saving the card rewrites it.
	$options = '';
	foreach ( $units as $size => $name ) {
		$options .= sprintf( '<option value="%1$d"%2$s>%3$s</option>', $size, selected( $size, $unit, false ), esc_html( $name ) );
	}
	$id = 'aafm-oauth-' . $kind . '-ttl';
	return sprintf(
		'<div class="aafm-set-row"><div class="aafm-set-label"><label for="%1$s">%2$s</label></div><div class="aafm-set-control"><input type="number" id="%1$s" class="small-text aafm-ttl-value" data-ttl="%3$s" data-saved="%4$d" min="1" step="1" value="%5$d"> <select class="aafm-ttl-unit" data-ttl="%3$s" aria-label="%6$s">%7$s</select><p class="help">%8$s</p></div></div>',
		esc_attr( $id ),
		esc_html( $label ),
		esc_attr( $kind ),
		$seconds,
		(int) round( $seconds / $unit ),
		/* translators: %s: which lifetime, for example "Access token lifetime". */
		esc_attr( sprintf( __( '%s unit', 'agent-abilities-for-mcp' ), $label ) ),
		$options,
		/* translators: %s: the default lifetime, for example "1 hour". */
		esc_html( sprintf( __( 'Default: %s', 'agent-abilities-for-mcp' ), human_time_diff( 0, $fallback ) ) )
	);
}

/**
 * Render the "Token lifetimes and client registration" card, collapsed.
 *
 * @param array<string,mixed> $data aafm_connection_lifetimes_data().
 * @return void
 */
function aafm_render_lifetimes_card( array $data ): void {
	$body  = '<p class="help">' . esc_html__( 'Applies to tokens issued from now on. Lowering a lifetime does not shorten tokens already issued; Revoke on the grants card below ends a connection at once.', 'agent-abilities-for-mcp' ) . '</p>';
	$body .= aafm_connection_ttl_row( 'access', __( 'Access token lifetime', 'agent-abilities-for-mcp' ), (int) $data['access'], (int) $data['access_default'] );
	$body .= aafm_connection_ttl_row( 'refresh', __( 'Refresh token lifetime', 'agent-abilities-for-mcp' ), (int) $data['refresh'], (int) $data['refresh_default'] );
	$body .= sprintf(
		'<div class="aafm-set-row"><div class="aafm-set-label" id="aafm-lifetimes-dcr-title">%1$s</div><div class="aafm-set-control"><label class="aafm-switch"><input type="checkbox" id="aafm-lifetimes-dcr" data-saved="%2$s" aria-labelledby="aafm-lifetimes-dcr-title aafm-lifetimes-dcr-desc"%3$s><span class="aafm-switch-track"></span></label> <label for="aafm-lifetimes-dcr" id="aafm-lifetimes-dcr-desc">%4$s</label><p class="help">%5$s</p></div></div>',
		esc_html__( 'Dynamic client registration', 'agent-abilities-for-mcp' ),
		$data['dcr'] ? '1' : '0',
		checked( (bool) $data['dcr'], true, false ),
		esc_html__( 'Let agents register themselves. ChatGPT and Claude need this to connect.', 'agent-abilities-for-mcp' ),
		esc_html__( 'Turning this off closes a public endpoint that anyone can reach without logging in. New connections from apps that register themselves fail until you turn it back on.', 'agent-abilities-for-mcp' )
	);
	$body .= sprintf(
		'<p class="aafm-lifetimes-actions"><button type="button" class="aafm-btn aafm-btn-primary" id="aafm-lifetimes-save" disabled>%1$s</button> <span class="aafm-lifetimes-status" role="status"></span></p>',
		esc_html__( 'Save', 'agent-abilities-for-mcp' )
	);
	aafm_render_section(
		array(
			'id'          => 'aafm-oauth-lifetimes',
			'collapsible' => true,
			'open'        => false,
			'title'       => __( 'Token lifetimes and client registration', 'agent-abilities-for-mcp' ),
			'body'        => $body,
		)
	);
}

/**
 * AJAX: save the token lifetimes and the dynamic client registration switch.
 *
 * Restrictive first: closing registration is written before anything else and opening it after
 * everything else certified.
 *
 * @return void
 */
function aafm_ajax_save_oauth_lifetimes(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; each value is type-checked and matched against a strict pattern below.
	$whole   = static function ( string $key ): ?int {
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
		return is_string( $raw ) && 1 === preg_match( '/\A[1-9][0-9]{0,9}\z/', $raw ) ? (int) $raw : null;
	};
	$access  = $whole( 'aafm_oauth_access_ttl' );
	$refresh = $whole( 'aafm_oauth_refresh_ttl' );
	$dcr_raw = isset( $_POST['aafm_oauth_dcr_enabled'] ) ? wp_unslash( $_POST['aafm_oauth_dcr_enabled'] ) : null;
	// phpcs:enable
	$bounds = aafm_oauth_ttl_bounds();
	if ( null === $access || null === $refresh || $access < $bounds['access'][0] || $access > $bounds['access'][1] || $refresh < $bounds['refresh'][0] || $refresh > $bounds['refresh'][1] || $access >= $refresh || ! in_array( $dcr_raw, array( '0', '1' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Use whole numbers inside the allowed range, and keep the access token shorter than the refresh token.', 'agent-abilities-for-mcp' ) ) );
	}
	$dcr_on     = '1' === $dcr_raw;
	$dcr_before = aafm_oauth_dcr_enabled();
	if ( ! $dcr_on && $dcr_before ) {
		if ( ! aafm_update_option_verified( 'aafm_oauth_dcr_enabled', '0' ) ) {
			wp_send_json_error( array( 'message' => aafm_switch_not_persisted_message( __( 'Dynamic client registration', 'agent-abilities-for-mcp' ) ) ) );
		}
		aafm_log_setting_change( 'aafm/oauth-dcr', __( 'Dynamic client registration turned off', 'agent-abilities-for-mcp' ) );
	}
	$lifetimes = array(
		'aafm_oauth_access_ttl'  => array( $access, (int) AAFM_OAUTH_ACCESS_TTL, __( 'Access token lifetime', 'agent-abilities-for-mcp' ) ),
		'aafm_oauth_refresh_ttl' => array( $refresh, (int) AAFM_OAUTH_REFRESH_TTL, __( 'Refresh token lifetime', 'agent-abilities-for-mcp' ) ),
	);
	foreach ( $lifetimes as $option => $lifetime ) {
		list( $new, $default, $label ) = $lifetime;
		$old                           = aafm_oauth_stored_ttl( $option, $default ); // The database value, not the cache.
		if ( $old === $new ) {
			continue;
		}
		if ( ! aafm_update_option_verified( $option, $new ) ) {
			wp_send_json_error( array( 'message' => aafm_switch_not_persisted_message( $label ) ) );
		}
		aafm_log_setting_change(
			'aafm/oauth-lifetimes',
			sprintf(
				/* translators: 1: which lifetime, 2: old value, 3: new value. */
				__( '%1$s changed from %2$s to %3$s', 'agent-abilities-for-mcp' ),
				$label,
				human_time_diff( 0, $old ),
				human_time_diff( 0, $new )
			)
		);
	}
	if ( $dcr_on && ! $dcr_before ) {
		if ( ! aafm_update_option_verified( 'aafm_oauth_dcr_enabled', '1' ) ) {
			wp_send_json_error( array( 'message' => aafm_paired_write_partial_failure_message( __( 'The token lifetimes', 'agent-abilities-for-mcp' ), __( 'Dynamic client registration', 'agent-abilities-for-mcp' ) ) ) );
		}
		aafm_log_setting_change( 'aafm/oauth-dcr', __( 'Dynamic client registration turned on', 'agent-abilities-for-mcp' ) );
	}
	wp_send_json_success( aafm_connection_lifetimes_data() );
}

/**
 * One grant row of aafm_connection_grants_data(), or null when the grant is not listed.
 *
 * @param int    $user_id   Approving user.
 * @param string $client_id OAuth client id.
 * @return array<string,mixed>|null
 */
function aafm_connection_grant_row( int $user_id, string $client_id ): ?array {
	foreach ( aafm_connection_grants_data()['rows'] as $row ) {
		if ( $user_id === $row['user_id'] && $client_id === $row['client_id'] ) {
			return $row;
		}
	}
	return null;
}

/**
 * AJAX: change one grant's pause, access preset, end date or rate override.
 *
 * Each field is optional; an absent field is left alone. preset, expires and rate must arrive with
 * the value the browser last saw (expect_*), and nothing is written when the stored value has moved,
 * so a stale tab cannot undo another admin's change.
 *
 * @return void
 */
function aafm_ajax_oauth_update_grant(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; every caller type-checks the raw value, so an array is refused, never cast.
	$field = static function ( string $key ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
	};
	// phpcs:enable
	$user_id = is_string( $field( 'user_id' ) ) ? absint( $field( 'user_id' ) ) : 0;
	$client  = is_string( $field( 'client_id' ) ) ? sanitize_text_field( $field( 'client_id' ) ) : '';
	if ( $user_id <= 0 || '' === $client || ! aafm_oauth_has_consent( $user_id, $client ) ) {
		wp_send_json_error( array( 'message' => __( 'Missing grant.', 'agent-abilities-for-mcp' ) ) );
	}
	$refuse  = __( 'That value is not allowed.', 'agent-abilities-for-mcp' );
	$changes = array();
	$logs    = array();

	$paused = $field( 'paused' );
	if ( null !== $paused ) {
		if ( ! in_array( $paused, array( '0', '1' ), true ) ) {
			wp_send_json_error( array( 'message' => $refuse ) );
		}
		$changes['paused'] = '1' === $paused;
		$logs[]            = $changes['paused'] ? __( 'Connection paused', 'agent-abilities-for-mcp' ) : __( 'Connection resumed', 'agent-abilities-for-mcp' );
	}
	$state = aafm_oauth_grant_state( $user_id, $client );
	if ( null === $state ) {
		wp_send_json_error( array( 'message' => $refuse ) );
	}
	// ponytail: the baseline compare happens in PHP before the UPDATE, a window of milliseconds; a
	// compare-and-set in the UPDATE's WHERE is the upgrade if two admins ever collide that tightly.
	$moved    = __( 'This connection was changed in another tab or by another admin. Reload the page to see the current settings.', 'agent-abilities-for-mcp' );
	$baseline = static function ( string $key, string $current ) use ( $field, $moved ): void {
		$expected = $field( 'expect_' . $key );
		if ( ! is_string( $expected ) || $expected !== $current ) {
			wp_send_json_error( array( 'message' => $moved ) );
		}
	};

	$preset = $field( 'preset' );
	if ( null !== $preset ) {
		if ( ! is_string( $preset ) || ! isset( aafm_access_presets()[ $preset ] ) ) {
			wp_send_json_error( array( 'message' => $refuse ) );
		}
		$baseline( 'preset', '' === $state['preset'] ? 'custom' : $state['preset'] );
		$changes['preset'] = aafm_preset_normalize( $preset );
		/* translators: %s: access preset name, for example "Read only". */
		$logs[] = sprintf( __( 'Access preset changed to %s', 'agent-abilities-for-mcp' ), aafm_access_presets()[ $preset ]['label'] );
	}

	$day = $field( 'expires' );
	if ( null !== $day ) {
		$baseline( 'expires', null === $state['expires_at'] ? '' : get_date_from_gmt( $state['expires_at'], 'Y-m-d' ) );
		if ( '' === $day ) {
			$changes['expires_at'] = null;
			$logs[]                = __( 'End date removed', 'agent-abilities-for-mcp' );
		} else {
			$valid = aafm_activity_valid_date( $day );
			$utc   = null === $valid ? '' : get_gmt_from_date( $valid . ' 23:59:59' );
			if ( '' === $utc || $utc <= gmdate( 'Y-m-d H:i:s' ) ) {
				wp_send_json_error( array( 'message' => __( 'Pick a date in the future. To stop a connection now, pause or revoke it.', 'agent-abilities-for-mcp' ) ) );
			}
			$changes['expires_at'] = $utc;
			/* translators: %s: the end date, Y-m-d. */
			$logs[] = sprintf( __( 'End date set to %s', 'agent-abilities-for-mcp' ), $valid );
		}
	}

	$rate = $field( 'rate' );
	if ( null !== $rate ) {
		$baseline( 'rate', $state['rate'] > 0 ? (string) $state['rate'] : '' );
		if ( '' === $rate ) {
			$changes['rate'] = 0;
			$logs[]          = __( 'Rate limit override removed', 'agent-abilities-for-mcp' );
		} elseif ( is_string( $rate ) && 1 === preg_match( '/\A[1-9][0-9]{0,4}\z/', $rate ) && (int) $rate <= 10000 ) {
			$changes['rate'] = (int) $rate;
			/* translators: %d: calls per minute. */
			$logs[] = sprintf( __( 'Rate limit set to %d a minute', 'agent-abilities-for-mcp' ), (int) $rate );
		} else {
			wp_send_json_error( array( 'message' => $refuse ) );
		}
	}

	if ( array() === $changes ) {
		wp_send_json_error( array( 'message' => __( 'Nothing to change.', 'agent-abilities-for-mcp' ) ) );
	}
	if ( ! aafm_oauth_grant_update( $user_id, $client, $changes ) ) {
		wp_send_json_error( array( 'message' => __( 'The change did not take. Reload the page and try again.', 'agent-abilities-for-mcp' ) ) );
	}
	foreach ( $logs as $detail ) {
		aafm_log_setting_change( 'aafm/oauth-grant', $detail . sprintf( ' (user ID %1$d, app %2$s)', $user_id, substr( $client, 0, 8 ) ) );
	}
	wp_send_json_success( aafm_connection_grant_row( $user_id, $client ) );
}
