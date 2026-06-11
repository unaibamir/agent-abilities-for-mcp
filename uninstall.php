<?php
/**
 * Uninstall cleanup for Agent Abilities for MCP — multisite-aware.
 *
 * Removes only this plugin's own data: the aafm_enabled_abilities option and the
 * per-site activity log table. On multisite it loops every blog so no table is left
 * behind. No other plugin's data is touched.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

// Only run when WordPress is uninstalling this plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/audit/log.php';

/**
 * Remove this plugin's data from every site, on single-site and multisite alike.
 *
 * Wrapped in a prefixed function so its loop variables stay out of the global scope
 * (uninstall.php executes at file scope).
 *
 * @return void
 */
function aafm_run_uninstall(): void {
	if ( ! is_multisite() ) {
		aafm_uninstall_site();
		return;
	}

	$aafm_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $aafm_site_ids as $aafm_site_id ) {
		switch_to_blog( (int) $aafm_site_id );
		aafm_uninstall_site();
		restore_current_blog();
	}
}

aafm_run_uninstall();
