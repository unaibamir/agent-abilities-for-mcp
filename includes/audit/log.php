<?php
/**
 * Activity log: table install, write, query, and clear.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The activity log table name for the current blog.
 *
 * @return string
 */
function aafm_activity_log_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'aafm_activity_log';
}

/**
 * Create (or upgrade) the activity log table.
 *
 * @return void
 */
function aafm_install_activity_log(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = aafm_activity_log_table();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		ability VARCHAR(191) NOT NULL DEFAULT '',
		principal_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		principal_login VARCHAR(191) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT '',
		arg_keys TEXT NULL,
		source_ip VARCHAR(45) NOT NULL DEFAULT '',
		created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY ability (ability),
		KEY status (status)
	) {$charset_collate};";

	dbDelta( $sql );
}

/**
 * Resolve the request source IP from REMOTE_ADDR only (never a spoofable header).
 *
 * @return string
 */
function aafm_source_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ip = trim( $ip );
	return ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
}

/**
 * Write one activity row. Records argument KEYS only — never values.
 *
 * @param array<string,mixed> $record {
 *     Activity record.
 *
 *     @type string   $ability            Ability name.
 *     @type int      $principal_user_id  Acting user ID.
 *     @type string   $principal_login    Acting user login.
 *     @type string   $status             One of started|success|error|denied.
 *     @type string[] $arg_keys           Input argument keys (values are never logged).
 * }
 * @return int The inserted row id (0 on failure).
 */
function aafm_log_activity( array $record ): int {
	global $wpdb;

	$status   = in_array( $record['status'] ?? '', array( 'started', 'success', 'error', 'denied' ), true ) ? $record['status'] : 'error';
	$arg_keys = isset( $record['arg_keys'] ) && is_array( $record['arg_keys'] )
		? implode( ',', array_map( 'sanitize_key', $record['arg_keys'] ) )
		: '';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->insert(
		aafm_activity_log_table(),
		array(
			'ability'           => isset( $record['ability'] ) ? (string) $record['ability'] : '',
			'principal_user_id' => isset( $record['principal_user_id'] ) ? (int) $record['principal_user_id'] : 0,
			'principal_login'   => isset( $record['principal_login'] ) ? (string) $record['principal_login'] : '',
			'status'            => $status,
			'arg_keys'          => $arg_keys,
			'source_ip'         => aafm_source_ip(),
			'created_at'        => current_time( 'mysql', true ),
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
	);

	$row_id = (int) $wpdb->insert_id;

	/**
	 * Fires after an activity record is written (SIEM/extensibility seam).
	 *
	 * @param array $record The normalized record.
	 */
	do_action( 'aafm_ability_called', $record );

	// Keep the table bounded. Pruning on every insert would add a DELETE to every
	// call, so only sweep once per prune interval — cheap, index-backed, and enough
	// to stop a deny-loop from growing the table without limit.
	$interval = aafm_log_prune_interval();
	if ( $row_id > 0 && $interval > 0 && 0 === $row_id % $interval ) {
		aafm_prune_activity_log();
	}

	return $row_id;
}

/**
 * The maximum number of activity rows to retain (filterable).
 *
 * @return int
 */
function aafm_log_max_rows(): int {
	$max = defined( 'AAFM_LOG_MAX_ROWS' ) ? (int) AAFM_LOG_MAX_ROWS : 10000;

	/**
	 * Filters the activity-log row ceiling. Rows beyond the newest N are pruned.
	 *
	 * @param int $max Maximum rows to keep.
	 */
	$max = (int) apply_filters( 'aafm_log_max_rows', $max );

	return max( 1, $max );
}

/**
 * How often (every Nth insert) the log auto-prunes (filterable).
 *
 * @return int
 */
function aafm_log_prune_interval(): int {
	$interval = defined( 'AAFM_LOG_PRUNE_INTERVAL' ) ? (int) AAFM_LOG_PRUNE_INTERVAL : 200;

	/**
	 * Filters how often the activity log is pruned, in rows between sweeps.
	 *
	 * @param int $interval Prune every Nth inserted row.
	 */
	$interval = (int) apply_filters( 'aafm_log_prune_interval', $interval );

	return max( 1, $interval );
}

/**
 * Delete activity rows beyond the newest aafm_log_max_rows().
 *
 * Keyed on the PRIMARY KEY (id is monotonic), so this is a single indexed range
 * delete — never a COUNT(*) or a full scan. Touches only this plugin's own table.
 *
 * @return void
 */
function aafm_prune_activity_log(): void {
	global $wpdb;

	$max = aafm_log_max_rows();
	// Internal constant table name; esc_sql() makes the safety explicit for analyzers.
	$table = esc_sql( aafm_activity_log_table() );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
	if ( $max_id <= $max ) {
		return;
	}

	$cutoff = $max_id - $max;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) );
}

/**
 * Update an existing activity row's status in place (used to resolve a 'started' row).
 *
 * @param int    $row_id Row id returned by aafm_log_activity().
 * @param string $status one of success|error|denied.
 * @return void
 */
function aafm_update_activity_status( int $row_id, string $status ): void {
	global $wpdb;
	if ( $row_id <= 0 ) {
		return;
	}
	$status = in_array( $status, array( 'success', 'error', 'denied' ), true ) ? $status : 'error';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update( aafm_activity_log_table(), array( 'status' => $status ), array( 'id' => $row_id ), array( '%s' ), array( '%d' ) );
}

/**
 * Query activity rows, most recent first.
 *
 * @param array<string,mixed> $args Query arguments: per_page, page, status, ability.
 * @return array<int,array<string,mixed>>
 */
function aafm_query_activity( array $args ): array {
	global $wpdb;

	$per_page = isset( $args['per_page'] ) ? min( 200, max( 1, (int) $args['per_page'] ) ) : 50;
	$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
	$offset   = ( $page - 1 ) * $per_page;
	// The table name is an internal constant ($wpdb->prefix . 'aafm_activity_log'),
	// never user input; esc_sql() makes that explicit for the static analyzers.
	$table = esc_sql( aafm_activity_log_table() );

	$where  = '1=1';
	$params = array();
	if ( ! empty( $args['status'] ) ) {
		$where   .= ' AND status = %s';
		$params[] = (string) $args['status'];
	}
	if ( ! empty( $args['ability'] ) ) {
		$where   .= ' AND ability = %s';
		$params[] = (string) $args['ability'];
	}

	$params[] = $per_page;
	$params[] = $offset;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Delete every activity row.
 *
 * @return void
 */
function aafm_clear_activity_log(): void {
	global $wpdb;
	// Internal constant table name; esc_sql() is belt-and-suspenders for the analyzers.
	$table = esc_sql( aafm_activity_log_table() );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "TRUNCATE TABLE {$table}" );
}

/**
 * Remove all plugin data for the current blog: the enabled-abilities option and the
 * activity log table. Called once per site by uninstall.php (multisite-aware there).
 *
 * Only this plugin's own option and table are touched — never another plugin's data.
 *
 * @return void
 */
function aafm_uninstall_site(): void {
	global $wpdb;
	delete_option( 'aafm_enabled_abilities' );
	// Internal constant table name; esc_sql() makes the safety explicit for analyzers.
	$table = esc_sql( aafm_activity_log_table() );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
