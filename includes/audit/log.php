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
 * caller that never supplies one means exactly this. The other six are events that are not
 * ability calls: the two ability-toggle events, a blocked attempt to enable a locked high-risk
 * ability, a security-relevant setting change, the tamper-evident log-cleared marker, and a
 * permission callback that crashed during a discovery check (aafm_deny_crashed_permission_check()
 * in includes/server.php - those rows carry a real ability name, so only the type can say they
 * are not agent calls). Mirrors aafm_activity_statuses().
 *
 * @return string[] The allowed event_type values.
 */
function aafm_activity_event_types(): array {
	return array( 'ability_call', 'ability_enabled', 'ability_disabled', 'ability_enable_blocked', 'setting_changed', 'log_cleared', 'permission_check_crashed' );
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

// Keep the activity-log schema current on real upgrades. The guard above early-returns once
// the version matches (one autoloaded option compare), so both hooks are cheap. Registered
// here at include time (this file is required at plugin load) to mirror the OAuth upgrade
// wiring without touching the main plugin bootstrap.
//
// rest_api_init as well as admin_init, deliberately: a headless site that auto-updates over
// cron and takes MCP traffic over REST may never see an admin request, and without the REST
// hook every audit insert after a schema bump silently fails (a missing column rejects the
// row) while the calls themselves keep succeeding. The audit log is a security control, so it
// heals on the path that actually carries the traffic it records.
add_action( 'admin_init', 'aafm_maybe_upgrade_activity_log' );
add_action( 'rest_api_init', 'aafm_maybe_upgrade_activity_log' );

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
 * Cap on how many failed Application Password attempts against the MCP endpoint one source IP
 * can add to the activity log inside AAFM_FAILED_AUTH_LOG_WINDOW. Mirrors the reasoning behind
 * aafm_oauth_log_event()'s 'bearer' skip (includes/oauth/audit.php): a real signal still gets a
 * real audit row, but the number of rows one attacker can generate stays bounded, so hammering
 * the endpoint with bad credentials cannot grow the log without limit.
 */
if ( ! defined( 'AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW' ) ) {
	define( 'AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW', 5 );
}

/**
 * The rolling window, in seconds, the cap above applies to.
 */
if ( ! defined( 'AAFM_FAILED_AUTH_LOG_WINDOW' ) ) {
	define( 'AAFM_FAILED_AUTH_LOG_WINDOW', 10 * MINUTE_IN_SECONDS );
}

/**
 * Whether one more bounded denial row fits inside the per-IP, per-window cap.
 *
 * Shared by the failed-Application-Password logger below and the IP-block denial row in
 * aafm_transport_permission_callback() (includes/server.php), so every transport-level denial
 * class is bounded the same way: AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW rows per source IP per
 * AAFM_FAILED_AUTH_LOG_WINDOW. Consuming a slot and reporting true are one operation - a true
 * return has already counted the row the caller is about to write.
 *
 * The counter is a plain get/set transient, not an atomic increment. Two concurrent requests can
 * both read the same count and one increment can be lost, so the cap is approximate under
 * concurrency - it leaks by at most the number of simultaneous requests, and only while the
 * window is warm. That is a deliberate trade kept from the original failed-auth cap: the row it
 * bounds is advisory observability, and an atomic counter would need its own table or a
 * cache-specific add() dance for a bound nobody reads precisely.
 *
 * @param string $bucket Short key namespace so each denial class gets its own counter:
 *                       'fa' (failed Application Password auth) or 'ipb' (IP-blocked transport).
 * @return bool True when the caller may write its row (the slot is consumed), false when this
 *              IP has used up its cap for the current window.
 */
function aafm_denial_log_within_cap( string $bucket ): bool {
	$ip    = aafm_source_ip();
	$key   = 'aafm_' . $bucket . '_' . ( '' !== $ip ? md5( $ip ) : 'unknown' );
	$count = (int) get_transient( $key );
	if ( $count >= AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW ) {
		return false; // Bounded: this IP already used its cap for the current window.
	}
	set_transient( $key, $count + 1, AAFM_FAILED_AUTH_LOG_WINDOW );
	return true;
}

/**
 * Log a failed Application Password authentication attempt against the MCP endpoint.
 *
 * Hooked on WordPress core's `application_password_failed_authentication` action. Core fires it
 * from `wp_authenticate_application_password()` on the `determine_current_user` filter for an
 * unknown username, application passwords being disabled, or a wrong password - and passes only a
 * WP_Error, never the presented username or password, so there is nothing credential-shaped to log
 * even by accident. Before this hook existed, a wrong Application Password against the endpoint
 * produced no audit row at all: aafm_transport_permission_callback() returns its 401 before
 * aafm_log_activity() is ever reached, so credential stuffing against the endpoint was invisible in
 * the plugin's own log.
 *
 * Two guards keep this narrow, matching how the OAuth bearer resolver treats the same class of
 * signal:
 * - Scoped to the MCP route only, via aafm_oauth_request_targets_mcp_route() - the same
 *   determine_current_user-safe route check the OAuth bearer resolver uses (validator.php).
 *   Core fires this action for ANY REST or XML-RPC request presenting Basic Auth, so without this
 *   guard a failed Application Password attempt against an unrelated route - core's own REST API,
 *   another plugin's - would land in this plugin's audit log too.
 * - Bounded per source IP (AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW), the same anti-flood reasoning
 *   aafm_oauth_log_event() applies to a fabricated OAuth bearer, via a cap rather than an outright
 *   skip: unlike a fabricated bearer, this event is already proven real, because WordPress core
 *   itself just checked a presented credential and rejected it.
 *
 * The function_exists() guards mirror aafm_oauth_resolve_current_user()'s own, and for the same
 * reason: this action can fire during determine_current_user before this plugin's own
 * plugins_loaded callback has required every file it depends on, if another active plugin
 * resolves the current user earlier in the load order. aafm_oauth_request_targets_mcp_route()
 * itself calls aafm_mcp_rest_route() (bootstrap.php) with no internal guard, so that dependency
 * is checked here too, exactly as aafm_oauth_resolve_current_user() checks it before calling the
 * same route helper. When either is missing this simply skips logging rather than fatal on an
 * undefined function.
 *
 * @param mixed $error The authentication WP_Error core just raised. Never read or logged - its
 *                      presence is the whole signal.
 * @return void
 */
function aafm_log_failed_application_password_auth( $error ): void {
	unset( $error );

	if ( ! function_exists( 'aafm_mcp_rest_route' ) || ! function_exists( 'aafm_oauth_request_targets_mcp_route' ) ) {
		return;
	}
	if ( ! aafm_oauth_request_targets_mcp_route() ) {
		return;
	}

	if ( ! aafm_denial_log_within_cap( 'fa' ) ) {
		return; // Bounded: this IP already used its cap for the current window.
	}

	aafm_log_activity(
		array(
			'ability' => '(transport)',
			'status'  => 'denied',
			'detail'  => __( 'Invalid Application Password', 'agent-abilities-for-mcp' ),
		)
	);
}
add_action( 'application_password_failed_authentication', 'aafm_log_failed_application_password_auth' );

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
 * Write one activity row. Records argument KEYS, never their free-text content; the detail
 * column carries identifier-only notes (ids, key names, slugs, enum members - see
 * includes/audit/detail.php for the allowlist that guarantees it).
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
 * This function does NOT announce the resolve. A crashed call writes this row TWICE - once from
 * aafm_log_ability_exception(), once from the tail resolve - so a hook fired from in here would
 * fire twice per crash, and the second fire would carry the null that protects the column. The
 * announcement is aafm_announce_ability_resolved()'s job and the caller's decision; this function
 * only reports whether the write landed, so the caller can stay silent when it did not.
 *
 * @param int         $row_id       Row id returned by aafm_log_activity().
 * @param string      $status       One of success|error|denied.
 * @param int|null    $result_count Optional magnitude of a list/read call's result. Null (default)
 *                                  leaves the column untouched.
 * @param string|null $detail       Optional note about what the call touched, known only once it
 *                                  resolved. Null (default) leaves the column untouched.
 * @return bool Whether the row was written. False means the query failed, the id was unusable, or
 *              no such row exists any more; a matched row whose columns did not change counts as
 *              written.
 */
function aafm_update_activity_status( int $row_id, string $status, ?int $result_count = null, ?string $detail = null ): bool {
	global $wpdb;
	if ( $row_id <= 0 ) {
		return false;
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

	$table = aafm_activity_log_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$updated = $wpdb->update( $table, $data, array( 'id' => $row_id ), $format, array( '%d' ) );

	// Only a literal false is a failure. wpdb::update() returns 0 when the row matched and no
	// column changed, which is a legitimate no-op resolve, and a naive `if ( $updated )` would
	// report every one of them as a failed write.
	//
	// But 0 also comes back when NO row matched, and that is not a resolve at all: the row was
	// pruned or the log was cleared between the opening insert and this call, so reporting success
	// makes the caller announce a row_id that joins to nothing - the same defect as announcing a
	// null detail, dressed differently. The two zeroes are indistinguishable from the return value,
	// so the rare one costs a SELECT to tell them apart. An ordinary resolve changes the status
	// column and never lands here; a crashed call does, because aafm_log_ability_exception() has
	// already set the row to 'error'.
	if ( 0 === $updated ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $table, $row_id ) );
	}

	return false !== $updated;
}

/**
 * Announce that an ability call has resolved.
 *
 * Separate from aafm_update_activity_status() on purpose. The writer runs twice on a crashed call
 * and only the second run knows the final status, while only the first knows the crash detail; a
 * hook fired from inside it therefore fires twice and the last fire carries a null detail. The
 * caller in includes/register.php holds both halves, so it announces, once, after the row is
 * settled.
 *
 * @param int|null    $row_id       The resolved row, or null when the opening insert failed and
 *                                  there is no row at all (since 1.6.2).
 * @param string      $status       One of success|error|denied.
 * @param int|null    $result_count Magnitude of a list/read call's result, or null.
 * @param string|null $detail       The detail the row now carries, or null when it carries none.
 * @return void
 */
function aafm_announce_ability_resolved( ?int $row_id, string $status, ?int $result_count = null, ?string $detail = null ): void {
	/**
	 * Fires once an ability call resolves, whatever the outcome.
	 *
	 * This is the only way anything outside wp-admin can see a crash. Before it existed a crashed
	 * call was visible solely to a human reading the activity log, so an external monitor had no
	 * signal at all.
	 *
	 * Exactly one fire per resolved call. A call that re-throws instead of resolving (the
	 * aafm_rethrow_ability_exceptions filter) fires nothing at all and leaves its row at 'started' -
	 * that absence is the development-time signal, and it is deliberate.
	 *
	 * A DENIED call announces too, including one whose permission callback crashed. That is the
	 * failure an operator most wants to hear about and the reason $status can be 'denied' here even
	 * though a denial writes its own row instead of resolving a 'started' one. What does NOT
	 * announce is discovery: the tools/list visibility probe in includes/server.php runs the raw
	 * permission callback over every enabled ability on every REST request, block editor traffic
	 * included, and a call that was never made has not resolved. A crash there is still audited, just not broadcast - read
	 * the 'denied' rows for it.
	 *
	 * The record deliberately carries no ability name or principal. The resolve path receives
	 * neither, and includes/audit/log.php exposes no read-by-id helper, so including them would mean
	 * a new helper plus an extra SELECT on every single resolve. Join on row_id against the
	 * aafm_ability_called record instead, which carries both.
	 *
	 * Since 1.6.2, row_id can be NULL: it is null when the call's opening audit insert failed, so
	 * there is no row to join to at all - the payload's own status and detail are the whole record
	 * for that call. That state is exactly when an external monitor matters most (the audit table
	 * itself is failing), which is why it announces instead of staying silent as 1.6.1 had it. The
	 * aafm_ability_called record for the same call still fired (aafm_log_activity() runs its
	 * do_action unconditionally), so the ability name and principal are recoverable from it. A
	 * consumer must treat row_id as int-or-null: a real id joins as before, null means "no row".
	 *
	 * $detail is identifier-only and never carries an argument value from any first-party ability:
	 * it is an ability's allowlisted detail, a first-party WP_Error code, or a crash's exception
	 * class and throw site. It is sanitized exactly as the column sanitizes it. It is NOT always
	 * the whole of what the column holds, though: it is the detail this resolve contributed. An
	 * ordinary update or read contributes none and announces null while the row keeps the detail
	 * its opening insert wrote, so a consumer that treats a null detail as "nothing to correlate"
	 * will throw away most of the log. Join on row_id and read the row's own detail. A denial and
	 * a crash both announce the string their row carries, because on those paths the resolve is
	 * what wrote it. See includes/audit/detail.php.
	 *
	 * @since 1.6.1
	 * @since 1.6.2 row_id can be null (the opening insert failed; nothing to join to).
	 * @param array{row_id:int|null,status:string,result_count:int|null,detail:string|null} $record The resolve.
	 */
	do_action(
		'aafm_ability_resolved',
		array(
			'row_id'       => $row_id,
			'status'       => $status,
			'result_count' => $result_count,
			// The same string the column got. aafm_update_activity_status() sanitizes on the way in,
			// so announcing the raw argument would hand a consumer a value the log itself refused.
			'detail'       => null === $detail ? null : aafm_sanitize_activity_detail( $detail ),
		)
	);
}

/**
 * Record a caught ability exception's class and throw site onto its already-started activity row.
 *
 * A crash mid-execute used to leave the row stuck at 'started' forever - that stuck row was
 * itself the only forensic signal a crash had happened (see the comment in
 * aafm_register_ability_with_log() in includes/register.php). Once the catch there resolves
 * every crash to a normal 'error' row like any ordinary validation failure, that signal is gone
 * unless the exception is written down somewhere - here, in the same row's detail column. This
 * does not insert a second row: it updates the one aafm_log_activity() already wrote at
 * 'started', the same "one row per call" contract every other resolve follows.
 *
 * The exception's MESSAGE is never stored, in this row or anywhere else. A vendor exception
 * message routinely interpolates the value that caused it, and the log's promise - stated in the
 * wp.org listing, not only in this codebase - is that free-text argument content is never stored. The class
 * plus the throw site identifies the defect at least as precisely and cannot carry a value. The
 * detail itself is built by aafm_build_activity_detail_from_exception() in includes/audit/detail.php,
 * so the file that documents this column's contract is also the file that produces every string
 * written to it.
 *
 * The same rule governs the WP_Error the caller returns to the client: build it from a fixed,
 * translatable string, never from $e->getMessage().
 *
 * The detail is returned as well as written, so the caller can announce the resolve carrying the
 * detail that survived rather than the null it passes to protect this column on its second write.
 *
 * @param int        $row_id  The 'started' row id returned by aafm_log_activity() for this call.
 * @param \Throwable $e       The caught exception or error.
 * @return string The detail written to the row.
 */
function aafm_log_ability_exception( int $row_id, \Throwable $e ): string {
	$detail = aafm_build_activity_detail_from_exception( $e );
	aafm_update_activity_status( $row_id, 'error', null, $detail );
	return $detail;
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
 * @param string|null $status One of started|success|error|denied, or null/empty for all rows.
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
 * Count rows recording a genuine agent tool call - the "made your first call" signal.
 *
 * The event_type = 'ability_call' filter narrows out every event that carries its own type:
 * the admin-side events schema v5 introduced (ability toggles, blocked enables, setting
 * changes, the log-cleared marker) and the crashed discovery checks 1.7.0 typed as
 * 'permission_check_crashed' (aafm_deny_crashed_permission_check() logs a REAL ability name
 * with status 'denied', and its callers run on every REST request - the adapter builds its
 * server on rest_api_init priority 15 - block editor traffic included and with no MCP traffic
 * needed at all, so a third-party plugin with a throwing cap filter used to flip this count
 * with no agent involved. Ordinary admin and front-end page loads never reach it). The filter
 * is still not sufficient on its own: three writers land rows under
 * the DEFAULT 'ability_call' type that are not tool calls, so each is excluded by the
 * synthetic ability name it carries.
 *
 * - 'oauth:%' rows (includes/oauth/audit.php passes no event_type) record the browser-side
 *   OAuth ceremony (register/authorize/token/...) - that is the connect step's territory,
 *   not a call.
 * - '(transport)' rows record refusals at the front door - a wrong Application Password or
 *   a blocked source IP - before any tool was addressed, so no tool call happened.
 * - 'aafm/activity-log-cleared' is the log-cleared marker's synthetic name; markers written
 *   before schema v5 predate event_type and so carry the 'ability_call' default.
 *
 * One class of historical row stays counted on purpose: crashed discovery checks recorded
 * before 'permission_check_crashed' existed carry the column default under a real ability
 * name, which makes them genuinely indistinguishable by shape from an agent's denied call.
 * No backfill guesses at them; only rows written since the type shipped are excluded.
 *
 * Denied and rate-limited tool calls DO count. Those rows (includes/register.php) mean an
 * agent authenticated, spoke MCP, and invoked a real tool by name - the refusal is the
 * governance layer doing its job, and the connection demonstrably works end to end.
 *
 * The optional $status arg narrows the same exclusion set to a single row status. It exists
 * so callers that need a stricter reading - the review-request notice counts success-only,
 * because a pile of denied calls proves connectivity but not value - share this one WHERE
 * clause instead of hand-copying it. A second copy of the exclusion list would silently
 * drift the moment a fourth default-event_type writer appears, which is exactly the bug
 * class the exclusions above were built to close.
 *
 * @param string|null $status One of started|success|error|denied to narrow to that status,
 *                            or null/empty to count every tool-call row (the default).
 * @return int Non-negative count of agent tool-call rows.
 */
function aafm_agent_call_count( ?string $status = null ): int {
	global $wpdb;
	$table = aafm_activity_log_table();

	// One WHERE clause for every caller: the base exclusion set, plus an optional bound
	// status narrowing. All %-placeholders are bound via $params; nothing user-supplied is
	// ever interpolated into the SQL string itself.
	$sql    = 'SELECT COUNT(*) FROM %i WHERE event_type = %s AND ability <> %s AND ability <> %s AND ability NOT LIKE %s';
	$params = array(
		$table,
		'ability_call',
		'(transport)',
		'aafm/activity-log-cleared',
		$wpdb->esc_like( 'oauth:' ) . '%',
	);
	if ( null !== $status && '' !== $status ) {
		$sql     .= ' AND status = %s';
		$params[] = $status;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$count = $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
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
