<?php
/**
 * OAuth storage schema: table install and drop.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AAFM_OAUTH_SCHEMA_VERSION' ) ) {
	// v2 adds the refresh_parent_id index on the access-tokens table. v3 widens the
	// resource (audience) column on the codes and access-tokens tables from VARCHAR(191)
	// to VARCHAR(2048) so a long endpoint URL is not truncated, which would break the
	// later audience check. v4 adds a client_id index on the access-tokens table so the
	// admin client listing and the revoke-by-client queries do not scan the whole table.
	// v5 adds index coverage for the GC and revoke scans: a composite (is_active,
	// refresh_expires_at) on access-tokens for the daily token GC; a (wp_user_id,
	// client_id) on access-tokens for the revoke-by-grant scan; an expires_at index plus
	// a client_id and (wp_user_id, client_id) index on codes for the code GC and the
	// revoke-by-client/grant code deletes; and a created_at index on clients for the
	// abandoned-client reaper. v6 adds a `scope` column to the codes and access-tokens
	// tables so the requested OAuth scope is persisted through code -> token and is
	// recorded/auditable (it previously reached the code context but was discarded at mint).
	// The column defaults to '' so existing rows and the current "acts as the approver's
	// full account" behaviour are unchanged; narrowing is opt-in via the
	// aafm_oauth_token_capabilities filter. Bumping the version makes
	// aafm_maybe_upgrade_oauth_tables() re-run dbDelta so existing installs pick the change
	// up (additive - one nullable-defaulted column per table, no data migration).
	define( 'AAFM_OAUTH_SCHEMA_VERSION', '6' );
}

/**
 * The unprefixed suffixes of the four OAuth tables.
 *
 * @return string[]
 */
function aafm_oauth_table_suffixes(): array {
	return array(
		'aafm_oauth_clients',
		'aafm_oauth_codes',
		'aafm_oauth_access_tokens',
		'aafm_oauth_consents',
	);
}

/**
 * Create (or upgrade) the four OAuth tables, then record the schema version.
 *
 * Idempotent: dbDelta() only applies the diff, so repeat calls are no-ops once the
 * tables match. Mirrors aafm_install_activity_log() in includes/audit/log.php.
 *
 * @return void
 */
function aafm_install_oauth_tables(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$prefix          = $wpdb->prefix;
	$charset_collate = $wpdb->get_charset_collate();

	$clients = "CREATE TABLE {$prefix}aafm_oauth_clients (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		client_id VARCHAR(191) NOT NULL DEFAULT '',
		client_name VARCHAR(191) NOT NULL DEFAULT '',
		redirect_uris LONGTEXT NULL,
		grant_types LONGTEXT NULL,
		response_types LONGTEXT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		created_by_ip VARCHAR(45) NOT NULL DEFAULT '',
		is_active TINYINT(1) NOT NULL DEFAULT 1,
		PRIMARY KEY  (id),
		UNIQUE KEY client_id (client_id),
		KEY created_at (created_at)
	) {$charset_collate};";

	$codes = "CREATE TABLE {$prefix}aafm_oauth_codes (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		code_hash VARCHAR(191) NOT NULL DEFAULT '',
		client_id VARCHAR(191) NOT NULL DEFAULT '',
		wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		redirect_uri TEXT NULL,
		code_challenge VARCHAR(191) NOT NULL DEFAULT '',
		resource VARCHAR(2048) NOT NULL DEFAULT '',
		scope VARCHAR(191) NOT NULL DEFAULT '',
		expires_at DATETIME NULL,
		used_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY code_hash (code_hash),
		KEY expires_at (expires_at),
		KEY client_id (client_id),
		KEY user_client (wp_user_id, client_id)
	) {$charset_collate};";

	$access_tokens = "CREATE TABLE {$prefix}aafm_oauth_access_tokens (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		token_hash VARCHAR(191) NOT NULL DEFAULT '',
		refresh_hash VARCHAR(191) NOT NULL DEFAULT '',
		refresh_parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		client_id VARCHAR(191) NOT NULL DEFAULT '',
		wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		resource VARCHAR(2048) NOT NULL DEFAULT '',
		scope VARCHAR(191) NOT NULL DEFAULT '',
		expires_at DATETIME NULL,
		refresh_expires_at DATETIME NULL,
		is_active TINYINT(1) NOT NULL DEFAULT 1,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY token_hash (token_hash),
		UNIQUE KEY refresh_hash (refresh_hash),
		KEY refresh_parent_id (refresh_parent_id),
		KEY client_id (client_id),
		KEY gc (is_active, refresh_expires_at),
		KEY user_client (wp_user_id, client_id)
	) {$charset_collate};";

	$consents = "CREATE TABLE {$prefix}aafm_oauth_consents (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		client_id VARCHAR(191) NOT NULL DEFAULT '',
		granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY user_client (wp_user_id, client_id)
	) {$charset_collate};";

	dbDelta( $clients );
	dbDelta( $codes );
	dbDelta( $access_tokens );
	dbDelta( $consents );

	aafm_oauth_finalize_schema();
}

/**
 * Record the schema version, but only once the tables are verified to be really there.
 *
 * A dbDelta() run reports nothing when an ALTER it wanted is refused - a transient loss of the
 * ALTER privilege mid-upgrade, a full disk, a single failed DDL - so the tables can be left partially
 * upgraded while the call returns as if it succeeded. Stamping the version unconditionally then
 * makes aafm_maybe_upgrade_oauth_tables() early-return forever, so a half-migrated install (say a
 * codes/tokens insert that a missing `scope` column rejects) never self-heals and only a manual
 * option delete recovers it. So verify first: advance the stored version only when the four tables
 * and the latest columns are actually present. On failure leave the prior version in place - the
 * next admin or REST request retries the install - and raise a bounded admin notice rather than
 * silently advancing past a broken schema.
 *
 * @return void
 */
function aafm_oauth_finalize_schema(): void {
	if ( aafm_oauth_schema_verify() ) {
		delete_transient( 'aafm_oauth_schema_error' );
		update_option( 'aafm_oauth_schema_version', AAFM_OAUTH_SCHEMA_VERSION );
		return;
	}

	// Bounded: a day-long flag the next successful verify clears, so the notice shows only while
	// the schema is genuinely behind and disappears the moment the self-heal lands.
	set_transient( 'aafm_oauth_schema_error', time(), DAY_IN_SECONDS );
}

/**
 * Whether the OAuth schema is actually present: all four tables plus the latest columns.
 *
 * Verifies table presence and the v6 `scope` column on codes + access-tokens - the most recent
 * migration, so its absence is the signal a dbDelta run did not fully land. Gates the version
 * stamp in aafm_oauth_finalize_schema().
 *
 * @return bool
 */
function aafm_oauth_schema_verify(): bool {
	global $wpdb;

	foreach ( aafm_oauth_table_suffixes() as $suffix ) {
		if ( ! aafm_oauth_table_present( $wpdb->prefix . $suffix ) ) {
			return false;
		}
	}

	return aafm_oauth_table_has_column( $wpdb->prefix . 'aafm_oauth_codes', 'scope' )
		&& aafm_oauth_table_has_column( $wpdb->prefix . 'aafm_oauth_access_tokens', 'scope' );
}

/**
 * Whether a table exists for the current blog.
 *
 * The PHPUnit harness rewrites plugin CREATE TABLE to its TEMPORARY form, which SHOW TABLES does
 * not list, so existence is probed with a trivial select that sees a temporary table the same way
 * the plugin's own queries do. The %i placeholder quotes the identifier (an internal constant).
 *
 * @param string $table Fully-prefixed table name.
 * @return bool
 */
function aafm_oauth_table_present( string $table ): bool {
	global $wpdb;
	$suppressed = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $table ) );
	$error = $wpdb->last_error;
	$wpdb->suppress_errors( $suppressed );
	return '' === $error;
}

/**
 * Whether a named column exists on a table. Works on the harness's TEMPORARY tables.
 *
 * @param string $table  Fully-prefixed table name (an internal constant).
 * @param string $column Column name to look for (no wildcards; matched exactly by SHOW COLUMNS).
 * @return bool
 */
function aafm_oauth_table_has_column( string $table, string $column ): bool {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
	return null !== $found && '' !== $found;
}

/**
 * Admin notice: the OAuth schema did not finish installing or upgrading.
 *
 * Shown only while aafm_oauth_finalize_schema()'s bounded transient is set (it clears the moment a
 * verify passes), and only to users who can act on it.
 *
 * @return void
 */
function aafm_oauth_schema_admin_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( false === get_transient( 'aafm_oauth_schema_error' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Agent Abilities for MCP could not finish setting up its OAuth database tables. OAuth may not work correctly until the update completes; the plugin retries automatically on the next admin or REST request.', 'agent-abilities-for-mcp' );
	echo '</p></div>';
}
add_action( 'admin_notices', 'aafm_oauth_schema_admin_notice' );

/**
 * Run the installer when the stored schema version is behind the current one.
 *
 * Cheap early return when the option already matches, so this can be hooked on
 * every admin request without churn. dbDelta() is safe to re-run and the
 * installer resets the option. Mirrors the audit log's activation wiring.
 *
 * @return void
 */
function aafm_maybe_upgrade_oauth_tables(): void {
	if ( get_option( 'aafm_oauth_schema_version' ) === AAFM_OAUTH_SCHEMA_VERSION ) {
		return;
	}

	aafm_install_oauth_tables();
}

/**
 * Empty all four OAuth tables, keeping their structure. Used by the plugin reset.
 *
 * Uses DELETE rather than TRUNCATE so it stays compatible with the PHPUnit harness,
 * which rewrites these tables to their TEMPORARY form (TRUNCATE cannot target a
 * temporary table in some MySQL configs). Mirrors aafm_drop_oauth_tables()' escaping.
 *
 * @return void
 */
function aafm_truncate_oauth_tables(): void {
	global $wpdb;

	foreach ( aafm_oauth_table_suffixes() as $suffix ) {
		// Internal table name bound as a SQL identifier via %i (available since WP 6.2).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . $suffix ) );
	}
}

/**
 * Prune dead OAuth artifacts: expired authorization codes and inactive access
 * tokens whose refresh window has lapsed past a small grace period.
 *
 * Run daily by the `aafm_oauth_cleanup` cron event. Two passes:
 *
 * - Codes are one-time and short-lived (60-second TTL), so any row past its
 *   `expires_at` is dead regardless of `used_at` and is deleted.
 * - Access-token rows are deleted only when inactive (`is_active = 0`) AND their
 *   `refresh_expires_at` is older than now minus a grace window. An active row is
 *   never pruned by access-token expiry alone: its refresh token is still valid.
 *   The grace lets a rotated/replaced (now-inactive) refresh row linger briefly so
 *   the token manager's reuse-detection still has the parent chain to walk during a
 *   network race. Filterable via `aafm_oauth_cleanup_grace` (seconds).
 *
 * Times are compared in UTC `Y-m-d H:i:s`, the storage format used across the
 * plugin (matches the token manager and audit log). Mirrors the direct-query and
 * escaping precedent of aafm_truncate_oauth_tables() / aafm_drop_oauth_tables().
 *
 * @return void
 */
function aafm_oauth_cleanup(): void {
	global $wpdb;

	$now = gmdate( 'Y-m-d H:i:s', time() );

	/**
	 * Grace period (in seconds) before an inactive token row is eligible for
	 * deletion after its refresh window expires. Keeps a rotated refresh row around
	 * long enough for reuse detection under a network race.
	 *
	 * @param int $grace Grace period in seconds. Default DAY_IN_SECONDS.
	 */
	$grace        = (int) apply_filters( 'aafm_oauth_cleanup_grace', DAY_IN_SECONDS );
	$token_cutoff = gmdate( 'Y-m-d H:i:s', time() - $grace );

	// Internal constant table names; esc_sql() makes the safety explicit for analyzers.
	$codes_table  = esc_sql( $wpdb->prefix . 'aafm_oauth_codes' );
	$tokens_table = esc_sql( $wpdb->prefix . 'aafm_oauth_access_tokens' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"DELETE FROM {$codes_table} WHERE expires_at IS NOT NULL AND expires_at < %s",
			$now
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			"DELETE FROM {$tokens_table} WHERE is_active = 0 AND refresh_expires_at IS NOT NULL AND refresh_expires_at < %s",
			$token_cutoff
		)
	);

	// Third pass: reap abandoned Dynamic Client Registration rows so the public DCR
	// endpoint cannot grow the clients table without bound.
	aafm_oauth_reap_abandoned_clients();
}

/**
 * Delete Dynamic-Client-Registration rows that were never used and are past a TTL.
 *
 * The DCR endpoint is public (the OAuth grant is the auth), so a client row is created before
 * any human approves it. A client that registered but no one ever consented to - and that holds
 * no token rows of any state - is dead weight. This removes such rows once they are older than
 * the TTL (default 7 days), keeping a generous window for a legitimate register-then-approve
 * flow that spans a session. A client with at least one consent OR any token row is kept, so a
 * live or revoked-but-historical client is never reaped. Deletes the matching codes too.
 *
 * The TTL is filterable via `aafm_oauth_client_reap_ttl` (seconds). All three tables are
 * internal constants; the cutoff is bound.
 *
 * @return int Number of client rows reaped.
 */
function aafm_oauth_reap_abandoned_clients(): int {
	global $wpdb;

	/**
	 * TTL (seconds) before a never-consented, token-less DCR client row is reaped.
	 *
	 * @param int $ttl TTL in seconds. Default 7 days.
	 */
	$ttl    = (int) apply_filters( 'aafm_oauth_client_reap_ttl', 7 * DAY_IN_SECONDS );
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $ttl ) );

	$clients_table  = esc_sql( $wpdb->prefix . 'aafm_oauth_clients' );
	$consents_table = esc_sql( $wpdb->prefix . 'aafm_oauth_consents' );
	$tokens_table   = esc_sql( $wpdb->prefix . 'aafm_oauth_access_tokens' );
	$codes_table    = esc_sql( $wpdb->prefix . 'aafm_oauth_codes' );

	// Find abandoned client_ids: older than the cutoff, with no consent row and no token row.
	// All table names are internal constants; the cutoff is bound.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$abandoned = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT cl.client_id FROM {$clients_table} cl
			 WHERE cl.created_at < %s
			   AND NOT EXISTS ( SELECT 1 FROM {$consents_table} co WHERE co.client_id = cl.client_id )
			   AND NOT EXISTS ( SELECT 1 FROM {$tokens_table} t WHERE t.client_id = cl.client_id )",
			$cutoff
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( empty( $abandoned ) || ! is_array( $abandoned ) ) {
		return 0;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $abandoned ), '%s' ) );

	// Remove the abandoned clients and any stray (unredeemed, now-expired) codes they minted.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$codes_table} WHERE client_id IN ( {$placeholders} )",
			$abandoned
		)
	);
	$deleted = (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$clients_table} WHERE client_id IN ( {$placeholders} )",
			$abandoned
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	return $deleted;
}

/**
 * Drop all four OAuth tables. Used by uninstall.
 *
 * @return void
 */
function aafm_drop_oauth_tables(): void {
	global $wpdb;

	foreach ( aafm_oauth_table_suffixes() as $suffix ) {
		// Internal table name bound as a SQL identifier via %i (available since WP 6.2).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $suffix ) );
	}
}
