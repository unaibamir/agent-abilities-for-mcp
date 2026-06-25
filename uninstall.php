<?php
/**
 * Uninstall cleanup for Oversio Agent Abilities — multisite-aware.
 *
 * Removes only this plugin's own data: every configuration option, the detected-meta-keys
 * transient, the per-site activity log table, and the OAuth tables. On multisite it loops
 * every blog so nothing is left behind. No other plugin's data is touched.
 *
 * Intentionally left in place: the dedicated agent WordPress user the plugin can create and
 * any application passwords issued to it. That user is a first-class WordPress account the
 * operator may have repurposed or assigned other roles, so deleting it (and silently revoking
 * its credentials) on uninstall would be destructive and surprising. Removing the account is
 * the operator's call, made from the Users screen.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

// Only run when WordPress is uninstalling this plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/admin/settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/audit/log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/oauth/schema.php';

/**
 * Remove this plugin's data from every site, on single-site and multisite alike.
 *
 * Wrapped in a prefixed function so its loop variables stay out of the global scope
 * (uninstall.php executes at file scope).
 *
 * @return void
 */
function oversio_run_uninstall(): void {
	if ( ! is_multisite() ) {
		oversio_uninstall_site_data();
		return;
	}

	$oversio_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $oversio_site_ids as $oversio_site_id ) {
		switch_to_blog( (int) $oversio_site_id );
		oversio_uninstall_site_data();
		restore_current_blog();
	}
}

oversio_run_uninstall();
