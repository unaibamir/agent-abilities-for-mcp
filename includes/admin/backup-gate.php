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

/**
 * Validate the shape of the posted tool list before anything is written.
 *
 * @param array<string,mixed> $posted Raw posted fields.
 * @return string|null A message, or null when the shape is valid or the gate is not part of this save.
 */
function aafm_backup_gate_input_error( array $posted ): ?string {
	if ( empty( $posted['aafm_backup_gate_present'] ) || ! isset( $posted['aafm_backup_gate_abilities'] ) ) {
		return null;
	}
	return is_array( $posted['aafm_backup_gate_abilities'] ) ? null : __( 'The backup gate tool list could not be read. Reload this page and try again.', 'agent-abilities-for-mcp' );
}

/**
 * The posted gate settings, or null when nothing should be read or written.
 *
 * Null when the marker field is absent: the card was not on the page that sent this save (a stale
 * script), so "absent means off" must not switch the gate off.
 *
 * @param array<string,mixed> $posted Raw posted fields.
 * @return array{on:bool,tools:list<string>,accept_remote:bool,accept_size:bool}|null
 */
function aafm_sanitize_backup_gate_input( array $posted ): ?array {
	if ( empty( $posted['aafm_backup_gate_present'] ) ) {
		return null;
	}

	$sent  = isset( $posted['aafm_backup_gate_abilities'] ) && is_array( $posted['aafm_backup_gate_abilities'] ) ? $posted['aafm_backup_gate_abilities'] : array();
	$tools = array();
	foreach ( $sent as $slug ) {
		if ( ! is_string( $slug ) ) {
			continue;
		}
		$slug = aafm_sanitize_plain_text( $slug );
		// A native name (aafm/...) or another plugin's own slug. Stored whether or not it is offered now.
		if ( 1 === preg_match( '/^[a-z0-9-]+\/[a-z0-9\/_-]+$/', $slug ) ) {
			$tools[] = $slug;
		}
	}
	$tools = array_slice( array_values( array_unique( $tools ) ), 0, 200 );
	sort( $tools );

	return array(
		'on'            => ! empty( $posted['aafm_backup_gate'] ),
		'tools'         => $tools,
		'accept_remote' => ! empty( $posted['aafm_backup_gate_accept_remote'] ),
		'accept_size'   => ! empty( $posted['aafm_backup_gate_accept_size'] ),
	);
}

/**
 * Write the gate settings that changed, restrictive changes first, and log each change.
 *
 * Order: stop accepting off-site backups, stop accepting size alone, turn the gate on, the tool
 * list, accept off-site backups, accept size alone, turn the gate off. The first write that does
 * not take stops the rest, so no setting has moved in the permissive direction unless every
 * restrictive change and the list were stored.
 *
 * @param array{on:bool,tools:list<string>,accept_remote:bool,accept_size:bool} $clean aafm_sanitize_backup_gate_input().
 * @return string|null The message for the first setting that did not save, or null when all did.
 */
function aafm_backup_gate_save( array $clean ): ?string {
	$switches = array(
		'on'            => array(
			'option'  => 'aafm_backup_gate',
			'ability' => 'aafm/backup-gate',
			'label'   => __( 'The backup gate', 'agent-abilities-for-mcp' ),
			'on'      => __( 'Backup gate turned on', 'agent-abilities-for-mcp' ),
			'off'     => __( 'Backup gate turned off', 'agent-abilities-for-mcp' ),
		),
		'accept_remote' => array(
			'option'  => 'aafm_backup_gate_accept_remote',
			'ability' => 'aafm/backup-gate-off-site',
			'label'   => __( 'Off-site backups', 'agent-abilities-for-mcp' ),
			'on'      => __( 'Off-site backups accepted', 'agent-abilities-for-mcp' ),
			'off'     => __( 'Off-site backups no longer accepted', 'agent-abilities-for-mcp' ),
		),
		'accept_size'   => array(
			'option'  => 'aafm_backup_gate_accept_size',
			'ability' => 'aafm/backup-gate-large',
			'label'   => __( 'Large backups', 'agent-abilities-for-mcp' ),
			'on'      => __( 'Large backups accepted on size alone', 'agent-abilities-for-mcp' ),
			'off'     => __( 'Large backups no longer accepted on size alone', 'agent-abilities-for-mcp' ),
		),
	);
	$before   = array(
		'on'            => aafm_stored_switch_on( 'aafm_backup_gate' ),
		'accept_remote' => aafm_backup_gate_permissive_on( 'aafm_backup_gate_accept_remote' ),
		'accept_size'   => aafm_backup_gate_permissive_on( 'aafm_backup_gate_accept_size' ),
	);
	$steps    = array(
		array( 'accept_remote', false ),
		array( 'accept_size', false ),
		array( 'on', true ),
		array( 'tools', true ),
		array( 'accept_remote', true ),
		array( 'accept_size', true ),
		array( 'on', false ),
	);

	foreach ( $steps as $step ) {
		list( $field, $to ) = $step;

		if ( 'tools' === $field ) {
			// An unreadable stored list is null, which never equals the requested list, so it is rewritten.
			if ( aafm_backup_gate_list() === $clean['tools'] ) {
				continue;
			}
			$stored = aafm_update_option_verified( 'aafm_backup_gate_abilities', $clean['tools'] );
			/* translators: %d: how many tools are behind the backup gate. */
			aafm_log_setting_change( 'aafm/backup-gate-tools', sprintf( __( 'Backup gate tools changed: %d chosen', 'agent-abilities-for-mcp' ), count( $clean['tools'] ) ), $stored );
			if ( ! $stored ) {
				return aafm_switch_not_persisted_message( __( 'The backup gate tool list', 'agent-abilities-for-mcp' ) );
			}
			continue;
		}

		if ( $clean[ $field ] !== $to || $before[ $field ] === $to ) {
			continue;
		}
		$stored = aafm_persist_operator_switch( $switches[ $field ]['option'], $to );
		aafm_log_setting_change( $switches[ $field ]['ability'], $switches[ $field ][ $to ? 'on' : 'off' ], $stored );
		if ( ! $stored ) {
			return aafm_switch_not_persisted_message( $switches[ $field ]['label'] );
		}
	}

	return null;
}
