<?php
/**
 * WordPress's export and erase personal data tools for the change history and the activity log.
 *
 * Exporters page by offset and never carry a value. Erasers ignore the page number: each call removes or
 * rewrites its own matches, so the next call's matches start where the last one stopped and an offset
 * would skip rows.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Rows one exporter page or one eraser call handles.
 */
const AAFM_PRIVACY_BATCH = 500;

/**
 * Register the two exporters.
 *
 * @param array<string,mixed> $exporters Registered exporters.
 * @return array<string,mixed>
 */
function aafm_privacy_register_exporters( $exporters ) {
	$exporters                                     = (array) $exporters;
	$exporters['agent-abilities-for-mcp-changes']  = array(
		'exporter_friendly_name' => __( 'Agent Abilities change history', 'agent-abilities-for-mcp' ),
		'callback'               => 'aafm_privacy_export_changes',
	);
	$exporters['agent-abilities-for-mcp-activity'] = array(
		'exporter_friendly_name' => __( 'Agent Abilities activity log', 'agent-abilities-for-mcp' ),
		'callback'               => 'aafm_privacy_export_activity',
	);
	return $exporters;
}

/**
 * Register the two erasers.
 *
 * @param array<string,mixed> $erasers Registered erasers.
 * @return array<string,mixed>
 */
function aafm_privacy_register_erasers( $erasers ) {
	$erasers                                     = (array) $erasers;
	$erasers['agent-abilities-for-mcp-changes']  = array(
		'eraser_friendly_name' => __( 'Agent Abilities change history', 'agent-abilities-for-mcp' ),
		'callback'             => 'aafm_privacy_erase_changes',
	);
	$erasers['agent-abilities-for-mcp-activity'] = array(
		'eraser_friendly_name' => __( 'Agent Abilities activity log', 'agent-abilities-for-mcp' ),
		'callback'             => 'aafm_privacy_erase_activity',
	);
	return $erasers;
}

/**
 * The user id behind an email address, or 0 when no account has it any more.
 *
 * @param string $email Email address.
 * @return int
 */
function aafm_privacy_user_id( string $email ): int {
	$user = get_user_by( 'email', $email );
	return $user instanceof WP_User ? (int) $user->ID : 0;
}

/**
 * The WHERE fragment for the change rows that belong to a data subject: rows the user made, rows on posts the
 * user authored, and rows whose stored values hold the email address as written or JSON escaped. The export
 * and the eraser share it, so what is offered is what is removed.
 *
 * @param string $email   Email address of the data subject.
 * @param int    $user_id The account's id, or 0 when none has the address.
 * @return string A prepared fragment, or '' when nothing can be matched (no account and no address).
 */
function aafm_privacy_changes_match( string $email, int $user_id ): string {
	global $wpdb;

	$parts = array();
	if ( $user_id > 0 ) {
		$parts[] = $wpdb->prepare( 'principal_user_id = %d', $user_id );
		$parts[] = $wpdb->prepare( "( object_type = 'post' AND object_id IN ( SELECT ID FROM %i WHERE post_author = %d ) )", $wpdb->posts, $user_id );
	}
	// An empty address would match every stored value.
	if ( '' !== $email ) {
		$parts[] = $wpdb->prepare( '( before_values LIKE %s OR before_values LIKE %s )', '%' . $wpdb->esc_like( $email ) . '%', '%' . $wpdb->esc_like( trim( (string) wp_json_encode( $email ), '"' ) ) . '%' );
	}
	return array() === $parts ? '' : '( ' . implode( ' OR ', $parts ) . ' )';
}

/**
 * Export the change history rows that belong to a data subject (the same rows the eraser removes): tool, object, field names and time. Never a value.
 *
 * @param string $email Email address of the data subject.
 * @param int    $page  Page, from 1.
 * @return array{data: list<array<string,mixed>>, done: bool}|WP_Error An error when the history could not be read, so a failed read never passes for an empty, finished export.
 */
function aafm_privacy_export_changes( $email, $page = 1 ) {
	global $wpdb;

	$match = aafm_privacy_changes_match( (string) $email, aafm_privacy_user_id( (string) $email ) );
	if ( '' === $match ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $match is built from prepared fragments.
	$rows = aafm_wpdb_results( $wpdb->prepare( "SELECT id, ability, object_type, object_id, fields, created_at FROM %i WHERE {$match} ORDER BY id ASC LIMIT %d OFFSET %d", aafm_changes_table(), AAFM_PRIVACY_BATCH, max( 0, (int) $page - 1 ) * AAFM_PRIVACY_BATCH ) );
	if ( ! $rows['ok'] ) {
		return new WP_Error( 'aafm_privacy_read_failed', __( 'Agent Abilities could not read its change history, so this export is not complete. Try again in a moment.', 'agent-abilities-for-mcp' ) );
	}
	$data = array();
	foreach ( (array) $rows['value'] as $row ) {
		$data[] = array(
			'group_id'    => 'aafm-change-history',
			'group_label' => __( 'Agent Abilities change history', 'agent-abilities-for-mcp' ),
			'item_id'     => 'aafm-change-' . (int) $row['id'],
			'data'        => array(
				array(
					'name'  => __( 'Tool', 'agent-abilities-for-mcp' ),
					'value' => (string) $row['ability'],
				),
				array(
					'name'  => __( 'Item', 'agent-abilities-for-mcp' ),
					'value' => $row['object_type'] . ' ' . (int) $row['object_id'],
				),
				array(
					'name'  => __( 'Fields', 'agent-abilities-for-mcp' ),
					'value' => implode( ', ', aafm_undo_field_names( (array) $row ) ),
				),
				array(
					'name'  => __( 'Time (UTC)', 'agent-abilities-for-mcp' ),
					'value' => (string) $row['created_at'],
				),
			),
		);
	}
	return array(
		'data' => $data,
		'done' => count( $data ) < AAFM_PRIVACY_BATCH,
	);
}

/**
 * Export a user's activity log rows: tool, status, time and source IP.
 *
 * @param string $email Email address of the data subject.
 * @param int    $page  Page, from 1.
 * @return array{data: list<array<string,mixed>>, done: bool}|WP_Error An error when the log could not be read, so a failed read never passes for an empty, finished export.
 */
function aafm_privacy_export_activity( $email, $page = 1 ) {
	global $wpdb;

	$user_id = aafm_privacy_user_id( (string) $email );
	if ( $user_id < 1 ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}
	$rows = aafm_wpdb_results( $wpdb->prepare( 'SELECT id, ability, status, source_ip, created_at FROM %i WHERE principal_user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', aafm_activity_log_table(), $user_id, AAFM_PRIVACY_BATCH, max( 0, (int) $page - 1 ) * AAFM_PRIVACY_BATCH ) );
	if ( ! $rows['ok'] ) {
		return new WP_Error( 'aafm_privacy_read_failed', __( 'Agent Abilities could not read its activity log, so this export is not complete. Try again in a moment.', 'agent-abilities-for-mcp' ) );
	}
	$data = array();
	foreach ( (array) $rows['value'] as $row ) {
		$data[] = array(
			'group_id'    => 'aafm-activity-log',
			'group_label' => __( 'Agent Abilities activity log', 'agent-abilities-for-mcp' ),
			'item_id'     => 'aafm-log-' . (int) $row['id'],
			'data'        => array(
				array(
					'name'  => __( 'Tool', 'agent-abilities-for-mcp' ),
					'value' => (string) $row['ability'],
				),
				array(
					'name'  => __( 'Status', 'agent-abilities-for-mcp' ),
					'value' => (string) $row['status'],
				),
				array(
					'name'  => __( 'Source IP', 'agent-abilities-for-mcp' ),
					'value' => (string) $row['source_ip'],
				),
				array(
					'name'  => __( 'Time (UTC)', 'agent-abilities-for-mcp' ),
					'value' => (string) $row['created_at'],
				),
			),
		);
	}
	return array(
		'data' => $data,
		'done' => count( $data ) < AAFM_PRIVACY_BATCH,
	);
}

/**
 * Erase change history rows by actor, by author of the object, and by an email inside the stored values.
 *
 * Whole rows go, up to 500 a call; the page number is ignored because each call removes its own matches.
 * The email is matched as written and in its JSON escaped form, which escapes slashes and non-ASCII. A
 * failed statement reports the data as retained.
 *
 * @param string $email Email address of the data subject.
 * @param int    $page  Ignored.
 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
 */
function aafm_privacy_erase_changes( $email, $page = 1 ): array {
	global $wpdb;

	unset( $page );
	$email = (string) $email;
	$match = aafm_privacy_changes_match( $email, aafm_privacy_user_id( $email ) );
	if ( '' === $match ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	$suppressed = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $match is built from prepared fragments above.
	$affected = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE {$match} ORDER BY id LIMIT %d", aafm_changes_table(), AAFM_PRIVACY_BATCH ) );
	$wpdb->suppress_errors( $suppressed );

	if ( false === $affected ) {
		return array(
			'items_removed'  => false,
			'items_retained' => true,
			'messages'       => array( __( 'Agent Abilities could not read its change history, so nothing was erased there.', 'agent-abilities-for-mcp' ) ),
			'done'           => true,
		);
	}
	return array(
		'items_removed'  => (int) $affected > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => (int) $affected < AAFM_PRIVACY_BATCH,
	);
}

/**
 * Blank the login and source IP on a user's activity log rows, keeping the rows so the audit trail keeps its shape.
 *
 * For an account that no longer exists, rows are matched by login against the request's email (sites where
 * the login is the email), and only rows whose user is really gone, so a live account whose login is an old
 * address keeps its attribution. When nothing can be matched the data is reported as retained.
 *
 * @param string $email Email address of the data subject.
 * @param int    $page  Page, from 1. Only used to tell a first call that found nothing from a later one that finished.
 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
 */
function aafm_privacy_erase_activity( $email, $page = 1 ): array {
	global $wpdb;

	$email   = (string) $email;
	$user_id = aafm_privacy_user_id( $email );
	$table   = aafm_activity_log_table();
	if ( '' === $email ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
	$blank = "( principal_login <> '' OR source_ip <> '' )";

	$suppressed = $wpdb->suppress_errors( true );
	if ( $user_id > 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $blank is a constant fragment.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE %i SET principal_login = '', source_ip = '' WHERE principal_user_id = %d AND {$blank} ORDER BY id LIMIT %d", $table, $user_id, AAFM_PRIVACY_BATCH ) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $blank is a constant fragment.
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE %i SET principal_login = '', source_ip = '' WHERE principal_login = %s AND {$blank} AND NOT EXISTS ( SELECT 1 FROM %i u WHERE u.ID = principal_user_id ) ORDER BY id LIMIT %d", $table, $email, $wpdb->users, AAFM_PRIVACY_BATCH ) );
	}
	$wpdb->suppress_errors( $suppressed );

	if ( false === $affected ) {
		return array(
			'items_removed'  => false,
			'items_retained' => true,
			'messages'       => array( __( 'Agent Abilities could not read its activity log, so nothing was erased there.', 'agent-abilities-for-mcp' ) ),
			'done'           => true,
		);
	}
	// A deleted account whose first call finds no row by login leaves rows nobody can tie to this address.
	if ( $user_id < 1 && 0 === (int) $affected && (int) $page <= 1 ) {
		return array(
			'items_removed'  => false,
			'items_retained' => true,
			'messages'       => array( __( 'This account no longer exists, so any activity log rows it left could not be identified by its email address.', 'agent-abilities-for-mcp' ) ),
			'done'           => true,
		);
	}
	return array(
		'items_removed'  => (int) $affected > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => (int) $affected < AAFM_PRIVACY_BATCH,
	);
}

/**
 * The paragraph the privacy policy guide gets about change history.
 *
 * @return string
 */
function aafm_privacy_policy_change_history_text(): string {
	return __( 'Change history keeps, for 30 days while the plugin is active, the values an AI agent replaced or removed on posts, terms and custom fields, together with the acting user and the connection. The values stay in this site\'s database and its backups. They are removed when the plugin is uninstalled and by WordPress\'s erase personal data tool, which also removes the rows by the user who made them, by the author of the post they changed, and by an email address found in the stored values.', 'agent-abilities-for-mcp' );
}
