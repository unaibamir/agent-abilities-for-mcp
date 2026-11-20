<?php
/**
 * The Backup gate settings card, the schedule reconcile step and the "Verify now" handler.
 *
 * The gate check itself lives in includes/siteops/backup-gate.php. This file is the only place
 * that schedules or clears the hourly background check, and it does so from one function that
 * derives only from the stored gate switch.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Keep the hourly background check in step with the gate switch: scheduled while the gate is
 * stored on, absent while it is off. Idempotent.
 *
 * @return void
 */
function aafm_backup_verify_reconcile(): void {
	// The check never runs on a network (the runner returns at once there), so it is not scheduled.
	$on        = aafm_stored_switch_on( 'aafm_backup_gate' ) && ! is_multisite();
	$scheduled = false !== wp_next_scheduled( 'aafm_backup_verify' );
	if ( $on && ! $scheduled ) {
		// A WP_Error is ignored here; the card then shows that the check is not scheduled.
		wp_schedule_event( time() + 60, 'hourly', 'aafm_backup_verify', array(), true );
	} elseif ( ! $on && $scheduled ) {
		wp_clear_scheduled_hook( 'aafm_backup_verify' );
	}
}

/**
 * Reconcile the schedule on a wp-admin page load.
 *
 * The action also fires on admin-ajax.php, for any visitor, so the schedule is not touched there.
 * The settings save, which is itself an AJAX request, calls aafm_backup_verify_reconcile() directly.
 *
 * @return void
 */
function aafm_backup_verify_reconcile_on_admin_init(): void {
	if ( wp_doing_ajax() ) {
		return;
	}
	aafm_backup_verify_reconcile();
}
add_action( 'admin_init', 'aafm_backup_verify_reconcile_on_admin_init' );

/**
 * AJAX: run the background check now, including for a backup an earlier run could not finish.
 *
 * The only way an HTTP request starts a hash. It needs the admin nonce and manage_options, and it
 * is bounded like the scheduled run.
 *
 * @return void
 */
function aafm_ajax_backup_verify_now(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	if ( is_multisite() ) {
		wp_send_json_error( array( 'message' => __( 'The backup check does not run on a multisite network in this version.', 'agent-abilities-for-mcp' ) ) );
	}

	require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-updraft.php';
	$result = aafm_backup_verify_run( 20, true );

	$messages = array(
		'ran'           => __( 'The check ran. Reload to see the new status.', 'agent-abilities-for-mcp' ),
		'nothing_to_do' => __( 'There was nothing new to check. Reload to see the current status.', 'agent-abilities-for-mcp' ),
		'not_stored'    => __( 'The check ran, but its result could not be saved. Flush the object cache and press Verify now again.', 'agent-abilities-for-mcp' ),
		'not_read'      => __( "UpdraftPlus's backup list could not be read. Reload this page and try again.", 'agent-abilities-for-mcp' ),
	);
	wp_send_json_success(
		array(
			'result'  => $result,
			'message' => $messages[ $result ] ?? $messages['nothing_to_do'],
		)
	);
}
