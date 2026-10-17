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
