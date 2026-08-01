<?php
/**
 * Activity log: table install, write, query, and clear.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AAFM_ACTIVITY_LOG_SCHEMA_VERSION' ) ) {
	// v2 adds composite (status, created_at) and (ability, created_at) indexes so the filtered
	// admin query (WHERE status/ability = ? ORDER BY created_at DESC) is index-backed instead of
	// filesorting. v3 adds the client_id column (M16) so an OAuth-attributed call can be traced
	// back to the client that made it; NOT NULL DEFAULT '' so every existing row (and every
	// caller that never supplies one) is unaffected. v4 adds the nullable result_count column
	// (L5) so a list/read call's magnitude is observable; NULL by default so an unmeasured or
	// write call is distinguishable from a genuine zero-item result, and every existing row is
	// unaffected. v5 adds event_type and detail so the log can hold events that are not ability
	// calls (an ability being toggled, a security-relevant setting change, the log-cleared
	// marker) and so a row can say what it touched, not only which ability ran. event_type is
	// NOT NULL DEFAULT 'ability_call', so the ALTER writes the correct meaning into every
	// existing row and no backfill is needed; detail is nullable so "no detail declared" stays
	// distinguishable from "declared and empty". The event_created index mirrors v2's composite
	// indexes so an event-filtered query is index-backed. Bumping this makes
	// aafm_maybe_upgrade_activity_log() re-run dbDelta so existing installs pick the change up
	// without a reactivation. Mirrors AAFM_OAUTH_SCHEMA_VERSION in includes/oauth/schema.php.
	define( 'AAFM_ACTIVITY_LOG_SCHEMA_VERSION', '5' );
}

/**
 * The single source of truth for the activity-log status values.
 *
 * 'started' is written only as the initial pending state of an in-flight call; the resolve path
 * (aafm_update_activity_status()) narrows a row to the terminal set. The $include_started flag
 * lets the update path reuse this list minus 'started'.
 *
 * @param bool $include_started Whether to include the pending 'started' status.
 * @return string[] The allowed status values.
 */
function aafm_activity_statuses( bool $include_started = true ): array {
	$terminal = array( 'success', 'error', 'denied' );
	return $include_started ? array_merge( array( 'started' ), $terminal ) : $terminal;
}

/**
 * The single source of truth for the activity-log event_type values.
 *
 * 'ability_call' is the column's SQL default, so every row written before schema v5 and every
 * caller that never supplies one means exactly this. The other five are events that are not
 * ability calls: the two ability-toggle events, a blocked attempt to enable a locked high-risk
 * ability, a security-relevant setting change, and the tamper-evident log-cleared marker. Mirrors
 * aafm_activity_statuses().
 *
 * @return string[] The allowed event_type values.
 */
function aafm_activity_event_types(): array {
	return array( 'ability_call', 'ability_enabled', 'ability_disabled', 'ability_enable_blocked', 'setting_changed', 'log_cleared' );
}

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
 * Create (or upgrade) the activity log table, then record the schema version.
 *
 * Idempotent: dbDelta() only applies the diff. Mirrors aafm_install_oauth_tables().
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
		client_id VARCHAR(191) NOT NULL DEFAULT '',
		result_count BIGINT UNSIGNED NULL,
		event_type VARCHAR(32) NOT NULL DEFAULT 'ability_call',
		detail TEXT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY status_created (status, created_at),
		KEY ability_created (ability, created_at),
		KEY event_created (event_type, created_at)
	) {$charset_collate};";

	dbDelta( $sql );

	update_option( 'aafm_activity_log_schema_version', AAFM_ACTIVITY_LOG_SCHEMA_VERSION );
}

/**
 * Run the activity-log installer when the stored schema version is behind the current one.
 *
 * Cheap early return when the option already matches, so this is safe to hook on every admin
 * request. dbDelta() is safe to re-run. Mirrors aafm_maybe_upgrade_oauth_tables().
 *
 * @return void
 */
function aafm_maybe_upgrade_activity_log(): void {
	if ( get_option( 'aafm_activity_log_schema_version' ) === AAFM_ACTIVITY_LOG_SCHEMA_VERSION ) {
		return;
	}

	aafm_install_activity_log();
}

// Keep the activity-log schema current on real upgrades. admin_init only fires on admin
// requests, and the guard above early-returns once the version matches, so this is cheap.
// Registered here at include time (this file is required at plugin load) to mirror the OAuth
// upgrade wiring without touching the main plugin bootstrap.
add_action( 'admin_init', 'aafm_maybe_upgrade_activity_log' );

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
 * Normalize a detail string before it reaches the column.
 *
 * The allowlist in includes/audit/detail.php is the real guarantee that only identifier-safe
 * values get this far; this is the independent last line, so a caller that builds a detail by
 * hand (the toggle and setting events do) still cannot write markup, a newline, or an unbounded
 * string into an audit row that an admin screen later renders.
 *
 * @param string $detail Raw detail string.
 * @return string Sanitized, single-line, at most 255 characters.
 */
function aafm_sanitize_activity_detail( string $detail ): string {
	$clean = sanitize_text_field( $detail );
	$clean = (string) preg_replace( '/\s+/', ' ', $clean );
	return mb_substr( trim( $clean ), 0, 255 );
}

/**
 * Write one activity row. Records argument KEYS only - never values.
 *
 * @param array<string,mixed> $record {
 *     Activity record.
 *
 *     @type string   $ability            Ability name.
 *     @type int      $principal_user_id  Acting user ID.
 *     @type string   $principal_login    Acting user login.
 *     @type string   $status             One of started|success|error|denied.
 *     @type string[] $arg_keys           Input argument keys (values are never logged).
 *     @type string   $client_id          OAuth client id the call is attributed to, or '' for a
 *                                        non-OAuth (Application Password) call. Optional.
 *     @type string   $event_type         What kind of event the row records, one of
 *                                        aafm_activity_event_types(). Optional; anything else
 *                                        (including an omitted key) means 'ability_call'.
 *     @type string   $detail             Short human-readable note about what the event touched.
 *                                        Optional; omitted leaves the column NULL, so "no detail
 *                                        declared" stays distinct from "declared and empty".
 * }
 * @return int The inserted row id (0 on failure).
 */
function aafm_log_activity( array $record ): int {
	global $wpdb;

	$status   = in_array( $record['status'] ?? '', aafm_activity_statuses(), true ) ? $record['status'] : 'error';
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
			'client_id'         => isset( $record['client_id'] ) ? (string) $record['client_id'] : '',
			'event_type'        => in_array( $record['event_type'] ?? '', aafm_activity_event_types(), true )
				? (string) $record['event_type']
				: 'ability_call',
			'detail'            => isset( $record['detail'] )
				? aafm_sanitize_activity_detail( (string) $record['detail'] )
				: null,
			'created_at'        => current_time( 'mysql', true ),
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	$row_id = (int) $wpdb->insert_id;

	/**
	 * Fires after an activity record is written (SIEM/extensibility seam).
	 *
	 * @param array $record The normalized record.
	 */
	do_action( 'aafm_ability_called', $record );

	return $row_id;
}

/**
 * Delete activity rows older than the configured retention window.
 *
 * Driven by the daily `aafm_prune_activity_log_daily` cron event, not by the write
 * path, so an insert never pays for a DELETE. When retention is 0 the log is kept
 * forever and this is a no-op. Otherwise it removes every row whose created_at is
 * older than the cutoff in one prepared, index-backed range delete (created_at is
 * indexed) against this plugin's own table.
 *
 * @return void
 */
function aafm_prune_activity_log(): void {
	$days = aafm_log_retention_days();
	if ( 0 === $days ) {
		return; // 0 = keep every entry forever.
	}

	global $wpdb;
	$table  = aafm_activity_log_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff ) );
}

/**
 * Update an existing activity row's status in place (used to resolve a 'started' row).
 *
 * $result_count (L5) is written only when the caller supplies one - omitting it leaves the
 * column at its NULL default rather than overwriting it, so a write call or an unmeasured
 * result never gets a fabricated magnitude. $detail follows the same rule: a resolve that has
 * nothing new to say leaves whatever the insert wrote in place rather than blanking it.
 *
 * @param int         $row_id       Row id returned by aafm_log_activity().
 * @param string      $status       One of success|error|denied.
 * @param int|null    $result_count Optional magnitude of a list/read call's result. Null (default)
 *                                  leaves the column untouched.
 * @param string|null $detail       Optional note about what the call touched, known only once it
 *                                  resolved. Null (default) leaves the column untouched.
 * @return void
 */
function aafm_update_activity_status( int $row_id, string $status, ?int $result_count = null, ?string $detail = null ): void {
	global $wpdb;
	if ( $row_id <= 0 ) {
		return;
	}
	$status = in_array( $status, aafm_activity_statuses( false ), true ) ? $status : 'error';

	$data   = array( 'status' => $status );
	$format = array( '%s' );
	if ( null !== $result_count ) {
		$data['result_count'] = $result_count;
		$format[]             = '%d';
	}
	if ( null !== $detail ) {
		$data['detail'] = aafm_sanitize_activity_detail( $detail );
		$format[]       = '%s';
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update( aafm_activity_log_table(), $data, array( 'id' => $row_id ), $format, array( '%d' ) );
}

/**
 * Query activity rows, most recent first.
 *
 * @param array<string,mixed> $args Query arguments: per_page, page, status, ability, max_id.
 *                                  max_id (optional) bounds the result to id <= max_id - a
 *                                  caller paginating across multiple calls (the CSV exporter)
 *                                  uses it to pin every page to a snapshot taken before the
 *                                  first page ran, so a row inserted mid-run can never shift the
 *                                  OFFSET window and appear on two pages.
 * @return array<int,array<string,mixed>>
 */
function aafm_query_activity( array $args ): array {
	global $wpdb;

	$per_page = isset( $args['per_page'] ) ? min( 200, max( 1, (int) $args['per_page'] ) ) : 50;
	$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
	$offset   = ( $page - 1 ) * $per_page;
	// The table name is an internal constant ($wpdb->prefix . 'aafm_activity_log'),
	// never user input; the leading %i placeholder below quotes/escapes the identifier.
	$table = aafm_activity_log_table();

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
	if ( ! empty( $args['max_id'] ) ) {
		$where   .= ' AND id <= %d';
		$params[] = (int) $args['max_id'];
	}

	$params[] = $per_page;
	$params[] = $offset;

	// Bind the table identifier via the leading %i placeholder, so it is the first arg to
	// $wpdb->prepare(). The %s fragments in {$where} are BOUND placeholders - their values
	// (status, ability) were pushed onto $params above and are substituted by prepare(), never
	// interpolated literals. The only interpolation into the SQL string is the static {$where}
	// scaffolding (the "1=1 AND status = %s …" shape), which carries no user input.
	array_unshift( $params, $table );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$sql = "SELECT * FROM %i WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Count activity rows, optionally narrowed to a single status.
 *
 * Mirrors the WHERE clause of aafm_query_activity() (minus paging) so a filtered view can
 * compute its own total and page count. A null or empty status counts every row. Runs as one
 * prepared, index-backed COUNT(*) against this plugin's own audit table.
 *
 * @param string|null $status One of success|error|denied, or null/empty for all rows.
 * @return int Non-negative row count for the (optionally filtered) set.
 */
function aafm_activity_count_filtered( ?string $status = null ): int {
	global $wpdb;
	$table = aafm_activity_log_table();

	if ( null === $status || '' === $status ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		return max( 0, (int) $count );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, $status ) );
	return max( 0, (int) $count );
}

/**
 * The highest activity row id currently in the table, or 0 when empty.
 *
 * A caller paginating with aafm_query_activity() across several calls (the CSV exporter) snapshots
 * this once, before the first page runs, and passes it back as max_id on every page - so a row
 * inserted mid-run can never shift an OFFSET window and be exported twice.
 *
 * @return int
 */
function aafm_activity_max_id(): int {
	global $wpdb;
	$table = aafm_activity_log_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$max = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) );
	return null === $max ? 0 : max( 0, (int) $max );
}

/**
 * Delete every activity row.
 *
 * @return void
 */
function aafm_clear_activity_log(): void {
	global $wpdb;
	$table = aafm_activity_log_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
}

/**
 * Remove all plugin data for the current blog: every configuration option, the
 * detected-meta-keys transient, and the activity log table. Called once per site by
 * uninstall.php (multisite-aware there).
 *
 * Loops aafm_config_option_names() (the canonical config list) rather than a single
 * hardcoded option, so a newly added option is cleaned up automatically and none leaks on
 * uninstall. uninstall.php requires includes/admin/settings.php so that list is defined here.
 * Only this plugin's own options, transient, and table are touched - never another plugin's data.
 *
 * @return void
 */
function aafm_uninstall_site(): void {
	global $wpdb;
	if ( function_exists( 'aafm_config_option_names' ) ) {
		foreach ( aafm_config_option_names() as $option ) {
			delete_option( $option );
		}
	} else {
		// Defensive fallback if settings.php was not loaded - never leave the core option behind.
		delete_option( 'aafm_enabled_abilities' );
	}
	// Cosmetic detected-keys cache (option-list sibling of the same data class).
	delete_transient( 'aafm_detected_meta_keys' );
	$table = aafm_activity_log_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
}
