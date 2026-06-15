<?php
/**
 * OAuth storage schema: table install and drop.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AAFM_OAUTH_SCHEMA_VERSION' ) ) {
	define( 'AAFM_OAUTH_SCHEMA_VERSION', '1' );
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
		resource VARCHAR(191) NOT NULL DEFAULT '',
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
		resource VARCHAR(191) NOT NULL DEFAULT '',
		expires_at DATETIME NULL,
		refresh_expires_at DATETIME NULL,
		is_active TINYINT(1) NOT NULL DEFAULT 1,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY token_hash (token_hash),
		UNIQUE KEY refresh_hash (refresh_hash)
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
