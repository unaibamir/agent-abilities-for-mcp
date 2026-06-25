<?php
/**
 * Activity log: table install, write, query, and clear.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'OVERSIO_ACTIVITY_LOG_SCHEMA_VERSION' ) ) {
	// v2 adds composite (status, created_at) and (ability, created_at) indexes so the filtered
	// admin query (WHERE status/ability = ? ORDER BY created_at DESC) is index-backed instead of
	// filesorting. Bumping this makes oversio_maybe_upgrade_activity_log() re-run dbDelta so existing
	// installs pick the change up. Mirrors OVERSIO_OAUTH_SCHEMA_VERSION in includes/oauth/schema.php.
	define( 'OVERSIO_ACTIVITY_LOG_SCHEMA_VERSION', '2' );
}

/**
 * The single source of truth for the activity-log status values.
 *
 * 'started' is written only as the initial pending state of an in-flight call; the resolve path
 * (oversio_update_activity_status()) narrows a row to the terminal set. The $include_started flag
 * lets the update path reuse this list minus 'started'.
 *
 * @param bool $include_started Whether to include the pending 'started' status.
 * @return string[] The allowed status values.
 */
function oversio_activity_statuses( bool $include_started = true ): array {
	$terminal = array( 'success', 'error', 'denied' );
	return $include_started ? array_merge( array( 'started' ), $terminal ) : $terminal;
}

/**
 * The activity log table name for the current blog.
 *
 * @return string
 */
function oversio_activity_log_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'oversio_activity_log';
}

/**
 * Create (or upgrade) the activity log table, then record the schema version.
 *
 * Idempotent: dbDelta() only applies the diff. Mirrors oversio_install_oauth_tables().
 *
 * @return void
 */
function oversio_install_activity_log(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = oversio_activity_log_table();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		ability VARCHAR(191) NOT NULL DEFAULT '',
		principal_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		principal_login VARCHAR(191) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT '',
		arg_keys TEXT NULL,
		source_ip VARCHAR(45) NOT NULL DEFAULT '',
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY status_created (status, created_at),
		KEY ability_created (ability, created_at)
	) {$charset_collate};";

	dbDelta( $sql );

	update_option( 'oversio_activity_log_schema_version', OVERSIO_ACTIVITY_LOG_SCHEMA_VERSION );
}

/**
 * Run the activity-log installer when the stored schema version is behind the current one.
 *
 * Cheap early return when the option already matches, so this is safe to hook on every admin
 * request. dbDelta() is safe to re-run. Mirrors oversio_maybe_upgrade_oauth_tables().
 *
 * @return void
 */
function oversio_maybe_upgrade_activity_log(): void {
	if ( get_option( 'oversio_activity_log_schema_version' ) === OVERSIO_ACTIVITY_LOG_SCHEMA_VERSION ) {
		return;
	}

	oversio_install_activity_log();
}

// Keep the activity-log schema current on real upgrades. admin_init only fires on admin
// requests, and the guard above early-returns once the version matches, so this is cheap.
// Registered here at include time (this file is required at plugin load) to mirror the OAuth
// upgrade wiring without touching the main plugin bootstrap.
add_action( 'admin_init', 'oversio_maybe_upgrade_activity_log' );

/**
 * Resolve the request source IP from REMOTE_ADDR only (never a spoofable header).
 *
 * @return string
 */
function oversio_source_ip(): string {
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
function oversio_log_activity( array $record ): int {
	global $wpdb;

	$status   = in_array( $record['status'] ?? '', oversio_activity_statuses(), true ) ? $record['status'] : 'error';
	$arg_keys = isset( $record['arg_keys'] ) && is_array( $record['arg_keys'] )
		? implode( ',', array_map( 'sanitize_key', $record['arg_keys'] ) )
		: '';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->insert(
		oversio_activity_log_table(),
		array(
			'ability'           => isset( $record['ability'] ) ? (string) $record['ability'] : '',
			'principal_user_id' => isset( $record['principal_user_id'] ) ? (int) $record['principal_user_id'] : 0,
			'principal_login'   => isset( $record['principal_login'] ) ? (string) $record['principal_login'] : '',
			'status'            => $status,
			'arg_keys'          => $arg_keys,
			'source_ip'         => oversio_source_ip(),
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
	do_action( 'oversio_ability_called', $record );

	return $row_id;
}

/**
 * Delete activity rows older than the configured retention window.
 *
 * Driven by the daily `oversio_prune_activity_log_daily` cron event, not by the write
 * path, so an insert never pays for a DELETE. When retention is 0 the log is kept
 * forever and this is a no-op. Otherwise it removes every row whose created_at is
 * older than the cutoff in one prepared, index-backed range delete (created_at is
 * indexed) against this plugin's own table.
 *
 * @return void
 */
function oversio_prune_activity_log(): void {
	$days = oversio_log_retention_days();
	if ( 0 === $days ) {
		return; // 0 = keep every entry forever.
	}

	global $wpdb;
	// Internal constant table name; esc_sql() makes the safety explicit for analyzers.
	$table  = esc_sql( oversio_activity_log_table() );
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
}

/**
 * Update an existing activity row's status in place (used to resolve a 'started' row).
 *
 * @param int    $row_id Row id returned by oversio_log_activity().
 * @param string $status one of success|error|denied.
 * @return void
 */
function oversio_update_activity_status( int $row_id, string $status ): void {
	global $wpdb;
	if ( $row_id <= 0 ) {
		return;
	}
	$status = in_array( $status, oversio_activity_statuses( false ), true ) ? $status : 'error';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update( oversio_activity_log_table(), array( 'status' => $status ), array( 'id' => $row_id ), array( '%s' ), array( '%d' ) );
}

/**
 * Query activity rows, most recent first.
 *
 * @param array<string,mixed> $args Query arguments: per_page, page, status, ability.
 * @return array<int,array<string,mixed>>
 */
function oversio_query_activity( array $args ): array {
	global $wpdb;

	$per_page = isset( $args['per_page'] ) ? min( 200, max( 1, (int) $args['per_page'] ) ) : 50;
	$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
	$offset   = ( $page - 1 ) * $per_page;
	// The table name is an internal constant ($wpdb->prefix . 'oversio_activity_log'),
	// never user input; esc_sql() makes that explicit for the static analyzers.
	$table = esc_sql( oversio_activity_log_table() );

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
 * Count activity rows, optionally narrowed to a single status.
 *
 * Mirrors the WHERE clause of oversio_query_activity() (minus paging) so a filtered view can
 * compute its own total and page count. A null or empty status counts every row. Runs as one
 * prepared, index-backed COUNT(*) against this plugin's own audit table.
 *
 * @param string|null $status One of success|error|denied, or null/empty for all rows.
 * @return int Non-negative row count for the (optionally filtered) set.
 */
function oversio_activity_count_filtered( ?string $status = null ): int {
	global $wpdb;
	// The table name is an internal constant ($wpdb->prefix . 'oversio_activity_log'),
	// never user input; esc_sql() makes that explicit for the static analyzers.
	$table = esc_sql( oversio_activity_log_table() );

	if ( null === $status || '' === $status ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		return max( 0, (int) $count );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
	return max( 0, (int) $count );
}

/**
 * Delete every activity row.
 *
 * @return void
 */
function oversio_clear_activity_log(): void {
	global $wpdb;
	// Internal constant table name; esc_sql() is belt-and-suspenders for the analyzers.
	$table = esc_sql( oversio_activity_log_table() );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "TRUNCATE TABLE {$table}" );
}

/**
 * Remove all plugin data for the current blog: every configuration option, the
 * detected-meta-keys transient, and the activity log table. Called once per site by
 * uninstall.php (multisite-aware there).
 *
 * Loops oversio_config_option_names() (the canonical config list) rather than a single
 * hardcoded option, so a newly added option is cleaned up automatically and none leaks on
 * uninstall. uninstall.php requires includes/admin/settings.php so that list is defined here.
 * Only this plugin's own options, transient, and table are touched — never another plugin's data.
 *
 * @return void
 */
function oversio_uninstall_site(): void {
	global $wpdb;
	if ( function_exists( 'oversio_config_option_names' ) ) {
		foreach ( oversio_config_option_names() as $option ) {
			delete_option( $option );
		}
	} else {
		// Defensive fallback if settings.php was not loaded — never leave the core option behind.
		delete_option( 'oversio_enabled_abilities' );
	}
	// Cosmetic detected-keys cache (option-list sibling of the same data class).
	delete_transient( 'oversio_detected_meta_keys' );
	// Internal constant table name; esc_sql() makes the safety explicit for analyzers.
	$table = esc_sql( oversio_activity_log_table() );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
