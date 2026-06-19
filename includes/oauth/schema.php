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
	// later audience check. Bumping the version makes aafm_maybe_upgrade_oauth_tables()
	// re-run dbDelta so existing installs pick the change up.
	define( 'AAFM_OAUTH_SCHEMA_VERSION', '3' );
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
		UNIQUE KEY client_id (client_id)
	) {$charset_collate};";

	$codes = "CREATE TABLE {$prefix}aafm_oauth_codes (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		code_hash VARCHAR(191) NOT NULL DEFAULT '',
		client_id VARCHAR(191) NOT NULL DEFAULT '',
		wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		redirect_uri TEXT NULL,
		code_challenge VARCHAR(191) NOT NULL DEFAULT '',
		resource VARCHAR(2048) NOT NULL DEFAULT '',
		expires_at DATETIME NULL,
		used_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY code_hash (code_hash)
	) {$charset_collate};";

	$access_tokens = "CREATE TABLE {$prefix}aafm_oauth_access_tokens (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		token_hash VARCHAR(191) NOT NULL DEFAULT '',
		refresh_hash VARCHAR(191) NOT NULL DEFAULT '',
		refresh_parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		client_id VARCHAR(191) NOT NULL DEFAULT '',
		wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		resource VARCHAR(2048) NOT NULL DEFAULT '',
		expires_at DATETIME NULL,
		refresh_expires_at DATETIME NULL,
		is_active TINYINT(1) NOT NULL DEFAULT 1,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY token_hash (token_hash),
		UNIQUE KEY refresh_hash (refresh_hash),
		KEY refresh_parent_id (refresh_parent_id)
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

	update_option( 'aafm_oauth_schema_version', AAFM_OAUTH_SCHEMA_VERSION );
}

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
		// Internal constant table name; esc_sql() makes the safety explicit for analyzers.
		$table = esc_sql( $wpdb->prefix . $suffix );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
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
}

/**
 * Drop all four OAuth tables. Used by uninstall.
 *
 * @return void
 */
function aafm_drop_oauth_tables(): void {
	global $wpdb;

	foreach ( aafm_oauth_table_suffixes() as $suffix ) {
		// Internal constant table name; esc_sql() makes the safety explicit for analyzers.
		$table = esc_sql( $wpdb->prefix . $suffix );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
