<?php
/**
 * Activity Log tab helpers: the one row view both renderers use, duration text, the 7-day summary
 * card and the filter row.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Human text for a call duration. Null means "not measured" and renders as a plain hyphen.
 *
 * @param int|null $ms Milliseconds, or null.
 * @return string
 */
function aafm_format_duration( ?int $ms ): string {
	if ( null === $ms ) {
		return '-';
	}
	if ( $ms < 1000 ) {
		/* translators: %s: a number of milliseconds. */
		return sprintf( __( '%s ms', 'agent-abilities-for-mcp' ), number_format_i18n( $ms ) );
	}
	/* translators: %s: a number of seconds with one decimal. */
	return sprintf( __( '%s s', 'agent-abilities-for-mcp' ), number_format_i18n( $ms / 1000, 1 ) );
}

/**
 * One log row as flat, display-ready values. The PHP row renderer escapes these; the AJAX payload
 * carries them to admin.js, which sets them with textContent. One place decides every cell, so the
 * two renderers cannot drift.
 *
 * @param array<string,mixed>  $row   A log row.
 * @param array<string,string> $names aafm_activity_connection_names() for the page.
 * @return array{id:int,time:string,principal:string,connection:string,ability:string,detail:string,detail_link:?array<string,mixed>,status:string,variant:string,duration:string,ip:string,arg_keys:string}
 */
function aafm_activity_row_view( array $row, array $names ): array {
	$variants   = array(
		'success' => 'success',
		'error'   => 'danger',
		'denied'  => 'warn',
		'started' => 'neutral',
	);
	$status     = (string) ( $row['status'] ?? '' );
	$ability    = (string) ( $row['ability'] ?? '' );
	$detail_raw = aafm_localize_denied_detail( (string) ( $row['detail'] ?? '' ) );
	$ip         = (string) ( $row['source_ip'] ?? '' );
	$duration   = isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : null;

	return array(
		'id'          => (int) ( $row['id'] ?? 0 ),
		'time'        => (string) ( $row['created_at'] ?? '' ),
		'principal'   => (string) ( $row['principal_login'] ?? '' ) . ' (#' . (int) ( $row['principal_user_id'] ?? 0 ) . ')',
		'connection'  => aafm_activity_connection_label( $row, $names ),
		'ability'     => $ability,
		'detail'      => $detail_raw,
		'detail_link' => aafm_activity_detail_link( $ability, $detail_raw ),
		'status'      => $status,
		'variant'     => $variants[ $status ] ?? 'neutral',
		'duration'    => aafm_format_duration( $duration ),
		'ip'          => '' !== $ip ? $ip : '-',
		'arg_keys'    => (string) ( $row['arg_keys'] ?? '' ),
	);
}

/**
 * The summary as plain values for the card and the AJAX payload.
 *
 * @param array<string,mixed> $summary Result of aafm_activity_summary().
 * @return array{ok:bool,total:int,success:int,error:int,denied:int,rate:string,top:list<array{ability:string,count:int}>}
 */
function aafm_activity_summary_view( array $summary ): array {
	$rate = isset( $summary['success_rate'] )
		? number_format_i18n( (float) $summary['success_rate'], 1 ) . '%'
		: '-';
	return array(
		'ok'      => ! empty( $summary['ok'] ),
		'total'   => (int) ( $summary['total'] ?? 0 ),
		'success' => (int) ( $summary['success'] ?? 0 ),
		'error'   => (int) ( $summary['error'] ?? 0 ),
		'denied'  => (int) ( $summary['denied'] ?? 0 ),
		'rate'    => $rate,
		'top'     => isset( $summary['top_tools'] ) && is_array( $summary['top_tools'] ) ? array_values( $summary['top_tools'] ) : array(),
	);
}

/**
 * Render the 7-day summary card. admin.js fills the same [data-summary] nodes on Live refresh.
 *
 * @param array<string,mixed> $summary Result of aafm_activity_summary().
 * @return void
 */
function aafm_render_activity_summary( array $summary ): void {
	$v = aafm_activity_summary_view( $summary );
	echo '<section class="aafm-log-summary" id="aafm-log-summary" aria-label="' . esc_attr__( 'Last 7 days', 'agent-abilities-for-mcp' ) . '">';
	$stats = array(
		'total'  => array( __( 'Calls, last 7 days', 'agent-abilities-for-mcp' ), number_format_i18n( $v['total'] ) ),
		'rate'   => array( __( 'Success rate', 'agent-abilities-for-mcp' ), $v['rate'] ),
		'denied' => array( __( 'Denied', 'agent-abilities-for-mcp' ), number_format_i18n( $v['denied'] ) ),
		'errors' => array( __( 'Errors', 'agent-abilities-for-mcp' ), number_format_i18n( $v['error'] ) ),
	);
	foreach ( $stats as $key => $stat ) {
		echo '<div class="aafm-log-stat"><div class="aafm-log-stat-num" data-summary="' . esc_attr( $key ) . '">' . esc_html( $stat[1] ) . '</div><div class="aafm-log-stat-label">' . esc_html( $stat[0] ) . '</div></div>';
	}
	echo '<div class="aafm-log-top"><div class="aafm-log-stat-label">' . esc_html__( 'Top tools', 'agent-abilities-for-mcp' ) . '</div><ol data-summary="top">';
	if ( array() === $v['top'] ) {
		echo '<li>' . esc_html__( 'No calls in the last 7 days.', 'agent-abilities-for-mcp' ) . '</li>';
	}
	foreach ( $v['top'] as $tool ) {
		echo '<li><code>' . esc_html( (string) $tool['ability'] ) . '</code> ' . esc_html( number_format_i18n( (int) $tool['count'] ) ) . '</li>';
	}
	echo '</ol></div>';
	echo '<p class="aafm-log-summary-note">' . esc_html__( 'Agent tool calls only, updated about once a minute. Times are UTC.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</section>';
}

/**
 * Render the filter row. Every control carries data-filter-key, which admin.js reads; nothing here
 * submits a form. Values come from the already-normalized filters and are escaped.
 *
 * @param array<string,string|int> $filters Normalized filters (from the URL on first render).
 * @return void
 */
function aafm_render_activity_filters( array $filters ): void {
	$field = static function ( string $label, string $control ): void {
		echo '<label class="aafm-log-filter"><span>' . esc_html( $label ) . '</span>' . $control . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $control is built from esc_attr()/esc_html() below.
	};
	$text  = static function ( string $key, string $placeholder, string $type = 'text' ) use ( $filters ): string {
		return sprintf(
			'<input type="%1$s" data-filter-key="%2$s" value="%3$s" placeholder="%4$s" autocomplete="off">',
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( isset( $filters[ $key ] ) ? (string) $filters[ $key ] : '' ),
			esc_attr( $placeholder )
		);
	};

	echo '<div class="aafm-log-filters" role="search" aria-label="' . esc_attr__( 'Filter the activity log', 'agent-abilities-for-mcp' ) . '">';

	$field( __( 'User', 'agent-abilities-for-mcp' ), $text( 'user', __( 'login', 'agent-abilities-for-mcp' ) ) );

	$conn    = '<select data-filter-key="conn">';
	$conn   .= '<option value="">' . esc_html__( 'Any', 'agent-abilities-for-mcp' ) . '</option>';
	$conn   .= '<option value="none"' . selected( $filters['conn'] ?? '', 'none', false ) . '>' . esc_html__( 'App Password or direct', 'agent-abilities-for-mcp' ) . '</option>';
	$clients = aafm_oauth_list_clients();
	// Dynamic registration can create many apps, so the select lists the first 50 plus the one currently
	// filtered on; the Tool and Search boxes reach the rest.
	$shown = array_slice( $clients, 0, 50 );
	foreach ( array_slice( $clients, 50 ) as $client ) {
		if ( ( $filters['conn'] ?? '' ) === $client['client_id'] ) {
			$shown[] = $client;
		}
	}
	foreach ( $shown as $client ) {
		$conn .= '<option value="' . esc_attr( $client['client_id'] ) . '"' . selected( $filters['conn'] ?? '', $client['client_id'], false ) . '>' . esc_html( '' !== $client['client_name'] ? $client['client_name'] : substr( $client['client_id'], 0, 8 ) ) . '</option>';
	}
	$conn .= '</select>';
	$field( __( 'Connection', 'agent-abilities-for-mcp' ), $conn );

	$field( __( 'Tool', 'agent-abilities-for-mcp' ), $text( 'tool', 'aafm/get-posts' ) );

	$plugin = '<select data-filter-key="plugin"><option value="">' . esc_html__( 'Any', 'agent-abilities-for-mcp' ) . '</option>';
	foreach ( aafm_activity_plugin_groups() as $slug => $label ) {
		$plugin .= '<option value="' . esc_attr( (string) $slug ) . '"' . selected( $filters['plugin'] ?? '', (string) $slug, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$plugin .= '</select>';
	$field( __( 'Plugin', 'agent-abilities-for-mcp' ), $plugin );

	$field( __( 'From (UTC)', 'agent-abilities-for-mcp' ), $text( 'from', '', 'date' ) );
	$field( __( 'To (UTC)', 'agent-abilities-for-mcp' ), $text( 'to', '', 'date' ) );
	$field( __( 'Search', 'agent-abilities-for-mcp' ), $text( 'q', __( 'tool, note, user', 'agent-abilities-for-mcp' ) ) );

	if ( isset( $filters['row'] ) ) {
		echo '<input type="hidden" data-filter-key="row" value="' . esc_attr( (string) $filters['row'] ) . '">';
	}
	echo '<div class="aafm-log-filter-actions">';
	echo '<button type="button" class="aafm-btn aafm-btn-primary" id="aafm-log-apply">' . esc_html__( 'Apply', 'agent-abilities-for-mcp' ) . '</button> ';
	echo '<button type="button" class="aafm-btn aafm-btn-secondary" id="aafm-log-reset">' . esc_html__( 'Reset', 'agent-abilities-for-mcp' ) . '</button>';
	echo '</div>';
	echo '<p class="help">' . esc_html__( 'Search and user have no index: add a date range on a very large log. The IP is the address the request came from, so behind a proxy or CDN it is the proxy. Duration is the time inside the tool, not what the agent experienced.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '</div>';
}
