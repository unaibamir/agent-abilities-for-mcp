<?php
/**
 * Admin settings page: menu, tab routing, Abilities + Activity tabs, AJAX handlers.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the settings submenu under Settings.
 *
 * @return void
 */
function aafm_register_admin_menu(): void {
	add_options_page(
		__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ),
		__( 'Agent Abilities', 'agent-abilities-for-mcp' ),
		'manage_options',
		'agent-abilities-for-mcp',
		'aafm_render_admin_page'
	);
}

/**
 * Enqueue admin assets only on our settings page.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function aafm_enqueue_admin_assets( string $hook ): void {
	if ( 'settings_page_agent-abilities-for-mcp' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'aafm-admin', AAFM_PLUGIN_URL . 'includes/admin/assets/admin.css', array(), AAFM_VERSION );
	wp_enqueue_script( 'aafm-admin', AAFM_PLUGIN_URL . 'includes/admin/assets/admin.js', array(), AAFM_VERSION, true );
	wp_localize_script(
		'aafm-admin',
		'aafmAdmin',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aafm_admin' ),
		)
	);
}

/**
 * Sanitize posted ability toggles down to known registry keys.
 *
 * The result is intersected with the live registry, so a stale, unknown, or smuggled
 * key can never enable anything — only abilities that actually exist are honored.
 *
 * @param array<string,mixed> $posted The raw $_POST payload (slashes handled here).
 * @return array<int,string>
 */
function aafm_sanitize_enabled_input( array $posted ): array {
	$known   = array_keys( aafm_get_abilities_registry() );
	$enabled = array();
	if ( isset( $posted['aafm_abilities'] ) && is_array( $posted['aafm_abilities'] ) ) {
		foreach ( wp_unslash( $posted['aafm_abilities'] ) as $name ) {
			$enabled[] = sanitize_text_field( (string) $name );
		}
	}
	return array_values( array_intersect( $enabled, $known ) );
}

/**
 * AJAX: save the enabled-abilities toggles.
 *
 * @return void
 */
function aafm_ajax_save_abilities(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$enabled = aafm_sanitize_enabled_input( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	update_option( 'aafm_enabled_abilities', $enabled );
	wp_send_json_success( array( 'enabled' => $enabled ) );
}

/**
 * AJAX: clear the activity log.
 *
 * @return void
 */
function aafm_ajax_clear_log(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	aafm_clear_activity_log();
	wp_send_json_success();
}

/**
 * Render the page shell + the active tab.
 *
 * @return void
 */
function aafm_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tabs = array(
		'connection' => __( 'Connection', 'agent-abilities-for-mcp' ),
		'abilities'  => __( 'Abilities', 'agent-abilities-for-mcp' ),
		'activity'   => __( 'Activity Log', 'agent-abilities-for-mcp' ),
	);

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing, no state change.
	$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'connection';
	if ( ! isset( $tabs[ $active ] ) ) {
		$active = 'connection';
	}

	echo '<div class="wrap aafm-wrap">';
	echo '<h1>' . esc_html__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ) . '</h1>';
	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $label ) {
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url(
				add_query_arg(
					array(
						'page' => 'agent-abilities-for-mcp',
						'tab'  => $slug,
					),
					admin_url( 'options-general.php' )
				)
			),
			esc_attr( $active === $slug ? 'nav-tab-active' : '' ),
			esc_html( $label )
		);
	}
	echo '</h2>';

	switch ( $active ) {
		case 'abilities':
			aafm_render_abilities_tab();
			break;
		case 'activity':
			aafm_render_activity_tab();
			break;
		default:
			aafm_render_connection_tab();
	}
	echo '</div>';
}

/**
 * Render the Abilities tab: grouped toggles, all OFF by default.
 *
 * @return void
 */
function aafm_render_abilities_tab(): void {
	$registry = aafm_get_abilities_registry();
	$enabled  = aafm_get_enabled_abilities();

	echo '<form id="aafm-abilities-form" class="aafm-abilities">';
	wp_nonce_field( 'aafm_admin', 'aafm_nonce' );

	$groups = array(
		'reads'  => __( 'Reads', 'agent-abilities-for-mcp' ),
		'writes' => __( 'Writes', 'agent-abilities-for-mcp' ),
	);

	foreach ( $groups as $group => $heading ) {
		echo '<h3>' . esc_html( $heading ) . '</h3>';
		echo '<table class="widefat striped aafm-ability-table"><tbody>';
		foreach ( $registry as $name => $meta ) {
			if ( ( $meta['group'] ?? '' ) !== $group ) {
				continue;
			}
			$risk = (string) ( $meta['risk'] ?? 'read' );
			printf(
				'<tr><td><label><input type="checkbox" name="aafm_abilities[]" value="%1$s" %2$s> %3$s</label></td><td><span class="aafm-badge aafm-badge-%4$s">%4$s</span></td><td>%5$s</td></tr>',
				esc_attr( (string) $name ),
				checked( in_array( (string) $name, $enabled, true ), true, false ),
				esc_html( (string) ( $meta['label'] ?? $name ) ),
				esc_attr( $risk ),
				esc_html( (string) ( $meta['description'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
	}

	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status" aria-live="polite"></span></p>';
	echo '</form>';
}

/**
 * Render the Activity Log tab (includes denials and errors).
 *
 * Every cell renders stored audit data, so each value is escaped on output. The log
 * only ever holds argument KEYS (never values) and a REMOTE_ADDR source IP, so there is
 * no PII to redact here beyond standard escaping.
 *
 * @return void
 */
function aafm_render_activity_tab(): void {
	$rows = aafm_query_activity( array( 'per_page' => 100 ) );

	echo '<div class="aafm-activity">';
	wp_nonce_field( 'aafm_admin', 'aafm_log_nonce' );
	echo '<p><button type="button" class="button" id="aafm-clear-log">' . esc_html__( 'Clear log', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-clear-status" aria-live="polite"></span></p>';
	echo '<table class="widefat striped aafm-log-table"><thead><tr>';
	echo '<th>' . esc_html__( 'Time (UTC)', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Principal', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Ability', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Status', 'agent-abilities-for-mcp' ) . '</th>';
	echo '<th>' . esc_html__( 'Arg keys', 'agent-abilities-for-mcp' ) . '</th>';
	echo '</tr></thead><tbody>';

	if ( empty( $rows ) ) {
		echo '<tr><td colspan="5">' . esc_html__( 'No activity recorded yet.', 'agent-abilities-for-mcp' ) . '</td></tr>';
	}
	foreach ( $rows as $row ) {
		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td><span class="aafm-status aafm-status-%4$s">%5$s</span></td><td>%6$s</td></tr>',
			esc_html( (string) ( $row['created_at'] ?? '' ) ),
			esc_html( (string) ( $row['principal_login'] ?? '' ) . ' (#' . (int) ( $row['principal_user_id'] ?? 0 ) . ')' ),
			esc_html( (string) ( $row['ability'] ?? '' ) ),
			esc_attr( (string) ( $row['status'] ?? '' ) ),
			esc_html( (string) ( $row['status'] ?? '' ) ),
			esc_html( (string) ( $row['arg_keys'] ?? '' ) )
		);
	}
	echo '</tbody></table>';
	echo '</div>';
}
