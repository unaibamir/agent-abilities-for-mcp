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
		'ran'           => __( 'The check ran. The status above is from before the check. Reload to see the result.', 'agent-abilities-for-mcp' ),
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
		if ( aafm_backup_gate_slug_valid( $slug ) ) {
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

/**
 * The tools the picker offers: this plugin's destructive and high-risk tools, and other plugins'
 * own destructive and high-risk tools. Reads and the two backup tools are never offered, because
 * the gate never governs them.
 *
 * @return array{native:array<string,array{label:string,badge:string}>,foreign:array<string,array{label:string,badge:string}>}
 */
function aafm_backup_gate_picker_tools(): array {
	$high_risk = aafm_high_risk_abilities();
	$native    = array();
	foreach ( aafm_get_abilities_registry_full() as $name => $row ) {
		$name = (string) $name;
		$risk = (string) ( $row['risk'] ?? '' );
		if ( 'read' === $risk || 'aafm/site-backup-status' === $name || 'aafm/site-list-backups' === $name ) {
			continue;
		}
		$is_high_risk = in_array( $name, $high_risk, true );
		if ( 'destructive' === $risk || $is_high_risk ) {
			$native[ $name ] = array(
				'label' => (string) ( $row['label'] ?? $name ),
				'badge' => $is_high_risk ? 'high-risk' : 'destructive',
			);
		}
	}

	$foreign_high_risk = aafm_high_risk_bridged_builtin();
	$foreign           = array();
	foreach ( aafm_discover_foreign_abilities() as $group ) {
		foreach ( $group['abilities'] as $ability ) {
			$slug         = (string) ( $ability['slug'] ?? '' );
			$is_high_risk = in_array( $slug, $foreign_high_risk, true );
			if ( '' !== $slug && ( 'destructive' === ( $ability['risk'] ?? '' ) || $is_high_risk ) ) {
				$foreign[ $slug ] = array(
					'label' => (string) ( $ability['label'] ?? $slug ),
					'badge' => $is_high_risk ? 'high-risk' : 'destructive',
				);
			}
		}
	}

	return array(
		'native'  => $native,
		'foreign' => $foreign,
	);
}

/**
 * One group of the tool picker.
 *
 * @param string                                         $legend Group heading.
 * @param array<string,array{label:string,badge:string}> $tools  Value => label and badge.
 * @param array<int,string>                              $chosen Stored list.
 * @return string HTML, or '' for an empty group.
 */
function aafm_backup_gate_picker_group( string $legend, array $tools, array $chosen ): string {
	if ( array() === $tools ) {
		return '';
	}
	$badges = array(
		'destructive' => __( 'destructive', 'agent-abilities-for-mcp' ),
		'high-risk'   => __( 'high-risk', 'agent-abilities-for-mcp' ),
	);
	$items  = '';
	foreach ( $tools as $value => $tool ) {
		$badge  = isset( $badges[ $tool['badge'] ] ) ? sprintf( ' <span class="aafm-badge aafm-badge-%1$s">%2$s</span>', esc_attr( $tool['badge'] ), esc_html( $badges[ $tool['badge'] ] ) ) : '';
		$items .= sprintf(
			'<label class="aafm-allowlist-item"><input type="checkbox" name="aafm_backup_gate_abilities[]" value="%1$s"%2$s> %3$s%4$s</label>',
			esc_attr( (string) $value ),
			checked( in_array( (string) $value, $chosen, true ), true, false ),
			esc_html( $tool['label'] ),
			$badge
		);
	}

	return '<fieldset class="aafm-allowlist-group"><legend>' . esc_html( $legend ) . '</legend>' . $items . '</fieldset>';
}

/**
 * A settings switch with its sentence, in the markup every switch on the Settings tab uses.
 *
 * @param string $id       Input id.
 * @param string $name     Input name.
 * @param bool   $on       Whether it is on.
 * @param string $sentence What it does.
 * @return string
 */
function aafm_backup_gate_switch_html( string $id, string $name, bool $on, string $sentence ): string {
	return sprintf(
		'<label class="aafm-switch"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s><span class="aafm-switch-track"></span></label> <label for="%1$s">%4$s</label>',
		esc_attr( $id ),
		esc_attr( $name ),
		checked( $on, true, false ),
		esc_html( $sentence )
	);
}

/**
 * The "Backup plugin" row: what was detected and what the newest good backup is.
 *
 * @param array{on:bool,provider:string,satisfied:bool,reason:string,last_good:?array<string,mixed>,verify_schedule:string} $state aafm_backup_gate_state().
 * @return string HTML.
 */
function aafm_backup_gate_provider_html( array $state ): string {
	if ( 'multisite' === $state['provider'] ) {
		return '<span class="aafm-pill aafm-pill-warn">' . esc_html__( 'Not available on multisite', 'agent-abilities-for-mcp' ) . '</span>'
			. aafm_get_notice_html( 'warning', __( 'The backup check does not run on a multisite network in this version. While the gate is on, every chosen tool is refused unless a developer vouches for a backup with the aafm_backup_gate_vouch filter.', 'agent-abilities-for-mcp' ), array( 'inline' => true ) );
	}
	if ( 'updraftplus' !== $state['provider'] ) {
		return '<span class="aafm-pill aafm-pill-warn">' . esc_html__( 'No backup provider detected', 'agent-abilities-for-mcp' ) . '</span>'
			. aafm_get_notice_html( 'warning', __( 'This check reads UpdraftPlus only. With no supported backup plugin, every chosen tool is refused while the gate is on. Host backups and other backup plugins are not visible to it; a developer can vouch for them with the aafm_backup_gate_vouch filter.', 'agent-abilities-for-mcp' ), array( 'inline' => true ) );
	}

	$html = '<span class="aafm-pill aafm-pill-success">' . esc_html__( 'UpdraftPlus detected', 'agent-abilities-for-mcp' ) . '</span>';

	$good = $state['last_good'];
	if ( is_array( $good ) ) {
		$checks = array(
			'verified'          => __( 'It matched its checksum when it was checked.', 'agent-abilities-for-mcp' ),
			'size_only'         => __( 'Size checked only; its contents were not compared.', 'agent-abilities-for-mcp' ),
			'trusted_unchecked' => __( 'Kept off-site and not checked here.', 'agent-abilities-for-mcp' ),
		);
		$html  .= '<p class="help">' . esc_html(
			sprintf(
				/* translators: 1: how long ago, for example "3 hours", 2: "database only" or "database and files", 3: a sentence saying which check was made. */
				__( 'Newest good backup: %1$s ago, %2$s. %3$s', 'agent-abilities-for-mcp' ),
				human_time_diff( (int) $good['started_at'] ),
				'full' === $good['scope'] ? __( 'database and files', 'agent-abilities-for-mcp' ) : __( 'database only', 'agent-abilities-for-mcp' ),
				$checks[ (string) $good['database_check'] ] ?? ''
			)
		) . '</p>';
	} else {
		$html .= '<p class="help">' . esc_html__( 'No good backup found in the last 24 hours.', 'agent-abilities-for-mcp' ) . '</p>';
	}

	// While the gate is off nothing is checked and Verify now is not offered, so no line points at either.
	if ( 'too_large_to_verify' === $state['reason'] ) {
		$html .= '<p class="help">' . esc_html(
			$state['on']
				? __( 'A recent backup is too large to check on this server in the time one check is allowed. The gate stays closed for it. You can press Verify now to try once more, or switch on Large backups below to accept it on its size alone.', 'agent-abilities-for-mcp' )
				: __( 'A recent backup is too large to check on this server in the time one check is allowed. Switch on Large backups below to accept it on its size alone.', 'agent-abilities-for-mcp' )
		) . '</p>';
	} elseif ( 'verification_pending' === $state['reason'] ) {
		$schedule = array(
			'ok'            => __( 'The check runs within the hour.', 'agent-abilities-for-mcp' ),
			'not_scheduled' => __( 'The check is not scheduled yet. Reload this page.', 'agent-abilities-for-mcp' ),
			'cron_disabled' => __( 'WP-Cron is switched off in this site\'s configuration, so the check runs only when your server\'s own scheduler calls WordPress. Press Verify now to run it.', 'agent-abilities-for-mcp' ),
			'cron_late'     => __( 'Scheduled tasks on this site are running late, so the check has not run. Press Verify now to run it.', 'agent-abilities-for-mcp' ),
		);
		$when     = $state['on'] ? ( $schedule[ $state['verify_schedule'] ] ?? $schedule['ok'] ) : __( 'Backups are checked once the gate is on.', 'agent-abilities-for-mcp' );
		$html    .= '<p class="help">' . esc_html__( 'A recent backup is waiting to be checked.', 'agent-abilities-for-mcp' ) . ' ' . esc_html( $when ) . '</p>';
	}

	// Not a form control: it sits in an untracked span so it never marks the tab as changed.
	if ( $state['on'] ) {
		$html .= '<span data-aafm-untracked="1"><button type="button" class="aafm-btn aafm-btn-secondary aafm-backup-verify-now">' . esc_html__( 'Verify now', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-backup-verify-status" aria-live="polite"></span></span>';
	}

	return $html;
}

/**
 * Render the Backup gate card on the Settings tab, inside the settings form.
 *
 * @return void
 */
function aafm_render_backup_gate_section(): void {
	$state  = aafm_backup_gate_state();
	$chosen = aafm_backup_gate_list() ?? array();

	if ( ! $state['on'] ) {
		$pill = array( 'neutral', __( 'Off', 'agent-abilities-for-mcp' ) );
	} elseif ( $state['satisfied'] ) {
		$pill = array( 'success', __( 'Backup found', 'agent-abilities-for-mcp' ) );
	} elseif ( 'verification_pending' === $state['reason'] ) {
		$pill = array( 'warn', __( 'Checking backup', 'agent-abilities-for-mcp' ) );
	} elseif ( 'too_large_to_verify' === $state['reason'] ) {
		$pill = array( 'warn', __( 'Backup too large to check', 'agent-abilities-for-mcp' ) );
	} else {
		$pill = array( 'warn', __( 'No recent backup', 'agent-abilities-for-mcp' ) );
	}

	ob_start();

	// The marker the save handler looks for: without it, a save leaves the gate settings alone.
	echo '<input type="hidden" name="aafm_backup_gate_present" value="1">';

	if ( $state['on'] && array() === $chosen ) {
		aafm_render_notice( 'info', __( 'The gate is on and no tool is chosen, so nothing is held back.', 'agent-abilities-for-mcp' ) );
	}

	aafm_render_set_row(
		array(
			'label'   => __( 'Require a recent backup', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Off by default', 'agent-abilities-for-mcp' ),
			'control' => aafm_backup_gate_switch_html( 'aafm-backup-gate', 'aafm_backup_gate', $state['on'], __( 'Refuse the chosen tools unless a good backup from the last 24 hours exists.', 'agent-abilities-for-mcp' ) ),
		)
	);

	aafm_render_set_row(
		array(
			'label'   => __( 'Backup plugin', 'agent-abilities-for-mcp' ),
			'control' => aafm_backup_gate_provider_html( $state ),
		)
	);

	// The picker. A stored slug that is no longer offered is still listed, ticked, so it can be unticked.
	$tools   = aafm_backup_gate_picker_tools();
	$offered = array_merge( array_keys( $tools['native'] ), array_keys( $tools['foreign'] ) );
	$gone    = array();
	foreach ( $chosen as $slug ) {
		if ( ! in_array( $slug, array_map( 'strval', $offered ), true ) ) {
			$gone[ $slug ] = array(
				'label' => $slug,
				'badge' => '',
			);
		}
	}
	$groups = aafm_backup_gate_picker_group( __( 'This plugin\'s tools', 'agent-abilities-for-mcp' ), $tools['native'], $chosen )
		. aafm_backup_gate_picker_group( __( 'Plugins\' own tools', 'agent-abilities-for-mcp' ), $tools['foreign'], $chosen )
		. aafm_backup_gate_picker_group( __( 'Not available right now', 'agent-abilities-for-mcp' ), $gone, $chosen );
	if ( '' === $groups ) {
		$picker = '<p class="aafm-empty-state">' . esc_html__( 'No destructive or high-risk tools are available on this site.', 'agent-abilities-for-mcp' ) . '</p>';
	} else {
		$picker = '<div class="aafm-allowlist-picker" role="group" aria-label="' . esc_attr__( 'Tools that need a backup', 'agent-abilities-for-mcp' ) . '"><div class="aafm-allowlist-picker-groups">' . $groups . '</div></div>';
	}
	aafm_render_set_row(
		array(
			'label'   => __( 'Tools that need a backup', 'agent-abilities-for-mcp' ),
			'control' => $picker,
		)
	);

	aafm_render_set_row(
		array(
			'label'   => __( 'Off-site backups', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Off by default', 'agent-abilities-for-mcp' ),
			'control' => aafm_backup_gate_switch_html( 'aafm-backup-gate-accept-remote', 'aafm_backup_gate_accept_remote', aafm_backup_gate_permissive_on( 'aafm_backup_gate_accept_remote' ), __( 'Accept backups stored off-site that this site cannot check.', 'agent-abilities-for-mcp' ) )
				. aafm_get_set_more_html(
					__( 'See more about off-site backups', 'agent-abilities-for-mcp' ),
					'<p class="help">' . esc_html__( 'A backup kept only in remote storage has no file on this server to look at. With this on, the gate trusts UpdraftPlus\'s own record of that backup.', 'agent-abilities-for-mcp' ) . '</p>'
				),
		)
	);

	aafm_render_set_row(
		array(
			'label'   => __( 'Large backups', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Off by default', 'agent-abilities-for-mcp' ),
			'control' => aafm_backup_gate_switch_html( 'aafm-backup-gate-accept-size', 'aafm_backup_gate_accept_size', aafm_backup_gate_permissive_on( 'aafm_backup_gate_accept_size' ), __( 'Accept a large backup on its size alone when its contents have not been checked yet.', 'agent-abilities-for-mcp' ) )
				. aafm_get_set_more_html(
					__( 'See more about large backups', 'agent-abilities-for-mcp' ),
					'<p class="help">' . esc_html__( 'A backup the site cannot check on the spot is checked in the background, about once an hour. Until that check has run, the gate stays closed for it, and a backup too large to check on this server keeps it closed. With this on, the gate opens as soon as the file is there with the size UpdraftPlus recorded. That catches a missing or cut-short file. It does not catch a file that was damaged without changing size.', 'agent-abilities-for-mcp' ) . '</p>'
				),
		)
	);

	echo '<p class="aafm-card-desc">' . esc_html__( 'A database-only backup counts, and so does a backup kept on this server. A database backup does not protect files such as plugins, themes or uploads, and a backup on the same server is lost if the server is. Only the database file is checked. These tools cannot start a backup; run one in UpdraftPlus or let its schedule do it. Tools offered by other plugins\' own MCP servers are outside this gate.', 'agent-abilities-for-mcp' ) . '</p>';

	aafm_render_section(
		array(
			'id'          => 'aafm-backup-gate-card',
			'icon'        => 'lock',
			'title'       => __( 'Backup gate', 'agent-abilities-for-mcp' ),
			'description' => __( 'Hold back the tools you choose until this site has a recent backup that has been checked. When it is on, a chosen tool is refused unless a good backup from the last 24 hours exists. Agents still see the tool; calling it returns a clear refusal.', 'agent-abilities-for-mcp' ),
			'pill'        => sprintf( '<span class="aafm-pill aafm-pill-%1$s">%2$s</span>', esc_attr( $pill[0] ), esc_html( $pill[1] ) ),
			'body'        => (string) ob_get_clean(),
		)
	);
}
