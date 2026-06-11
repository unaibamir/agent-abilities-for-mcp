<?php
/**
 * Uninstall cleanup for Agent Abilities for MCP.
 *
 * Multisite-aware removal of plugin options and the activity log table is wired
 * up in a later phase. For now this guard ensures the file is only ever run by
 * WordPress during an actual uninstall.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

// Only run when WordPress is uninstalling this plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
