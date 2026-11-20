<?php
/**
 * The backup gate: an owner setting that refuses chosen tools unless a checked backup from the
 * last 24 hours exists.
 *
 * Loaded on every request. It calls no vendor code itself: the UpdraftPlus readers live in
 * includes/siteops/backup-updraft.php, which is loaded only when the gate is on and a governed
 * tool is called, when a backup tool runs, or when the background check runs. With the gate off,
 * the whole cost of this file is one switch read per tool call.
 *
 * Nothing here writes. The stored verdicts are written by the background check only.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether UpdraftPlus is loaded.
 *
 * @return bool
 */
function aafm_site_updraft_loaded(): bool {
	return is_a( $GLOBALS['updraftplus'] ?? null, 'UpdraftPlus' ) && class_exists( 'UpdraftPlus_Backup_History' );
}

/**
 * The stored verification verdicts, keyed by backup nonce.
 *
 * Unreadable or malformed reads as an empty map, so nothing is called verified from it.
 *
 * @return array<int|string,array{fingerprint:string,verdict:string,checked_at:int}>
 */
function aafm_backup_verify_read(): array {
	$row = aafm_policy_row( 'aafm_backup_verify' );
	if ( ! $row['ok'] || ! $row['found'] || ! is_array( $row['value'] ) ) {
		return array();
	}

	$out = array();
	foreach ( $row['value'] as $nonce => $record ) {
		// PHP stores an all-digit nonce as an integer key, so the key is compared as a string.
		if ( 1 !== preg_match( '/^[0-9a-f]{12}$/', (string) $nonce ) || ! is_array( $record ) ) {
			continue;
		}
		if ( ! is_string( $record['fingerprint'] ?? null ) || ! in_array( $record['verdict'] ?? null, array( 'verified', 'failed', 'too_large' ), true ) ) {
			continue;
		}
		$out[ $nonce ] = array(
			'fingerprint' => $record['fingerprint'],
			'verdict'     => (string) $record['verdict'],
			'checked_at'  => (int) ( $record['checked_at'] ?? 0 ),
		);
	}

	return $out;
}

/**
 * Whether the hourly background check can be expected to run.
 *
 * @return string 'ok', 'not_scheduled', 'cron_disabled' or 'cron_late'.
 */
function aafm_backup_verify_schedule_state(): string {
	$next = wp_next_scheduled( 'aafm_backup_verify' );
	if ( false === $next ) {
		return 'not_scheduled';
	}
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		return 'cron_disabled';
	}

	return ( time() - (int) $next ) > 900 ? 'cron_late' : 'ok';
}

/**
 * Whether one of the two permissive owner switches is on. An unreadable row reads as off, the
 * closed side for a switch that relaxes the gate.
 *
 * @param string $option 'aafm_backup_gate_accept_remote' or 'aafm_backup_gate_accept_size'.
 * @return bool
 */
function aafm_backup_gate_permissive_on( string $option ): bool {
	$row = aafm_policy_row( $option );

	return $row['ok'] && $row['found'] && ! empty( $row['value'] );
}

/**
 * The tools the owner put behind the gate: native names and foreign slugs.
 *
 * @return list<string>|null Null when the row cannot be read or is not a list: the gate then
 *                           governs every tool that is not a read.
 */
function aafm_backup_gate_list(): ?array {
	$row = aafm_policy_row( 'aafm_backup_gate_abilities' );
	if ( ! $row['ok'] ) {
		return null;
	}
	if ( ! $row['found'] ) {
		return array();
	}
	if ( ! is_array( $row['value'] ) ) {
		return null;
	}

	return array_values( array_map( 'strval', array_filter( $row['value'], 'is_scalar' ) ) );
}

/**
 * The backup answer, shared by the gate, the settings card and site-backup-status. Computed on
 * every call: one history read, one walk of the gate window, and at most the inline budget hashed.
 *
 * @return array{on:bool,provider:string,satisfied:bool,reason:string,last_good:?array<string,mixed>,verify_schedule:string}
 */
function aafm_backup_gate_state(): array {
	$state = array(
		'on'              => aafm_stored_switch_on( 'aafm_backup_gate' ),
		'provider'        => 'none',
		'satisfied'       => false,
		'reason'          => 'no_provider',
		'last_good'       => null,
		'verify_schedule' => aafm_backup_verify_schedule_state(),
	);

	if ( is_multisite() ) {
		$state['provider'] = 'multisite';
		$state['reason']   = 'multisite';
		return $state;
	}
	if ( ! aafm_site_updraft_loaded() ) {
		return $state;
	}
	$state['provider'] = 'updraftplus';
	$state['reason']   = 'no_backup';

	require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-updraft.php';

	$verdicts      = aafm_backup_verify_read();
	$accept_remote = aafm_backup_gate_permissive_on( 'aafm_backup_gate_accept_remote' );
	$accept_size   = aafm_backup_gate_permissive_on( 'aafm_backup_gate_accept_size' );

	$call = aafm_siteops_vendor_call(
		static function () use ( $verdicts, $accept_remote, $accept_size ): array {
			return aafm_site_updraft_window_walk( $verdicts, $accept_remote, $accept_size );
		}
	);
	if ( ! $call['ok'] || ! is_array( $call['value'] ) ) {
		return $state;
	}

	$walk = $call['value'];
	if ( is_array( $walk['good'] ?? null ) ) {
		$state['satisfied'] = true;
		$state['reason']    = 'ok';
		$state['last_good'] = $walk['good'];
	} elseif ( 'pending' === ( $walk['waiting'] ?? '' ) ) {
		$state['reason'] = 'verification_pending';
	} elseif ( 'too_large' === ( $walk['waiting'] ?? '' ) ) {
		$state['reason'] = 'too_large_to_verify';
	}

	return $state;
}

/**
 * Why the gate refuses this call, or '' when it does not.
 *
 * The permission callback fires twice for one tool call: once from the adapter and once from
 * core's re-check inside execute(). The two fires share one backup answer; two tool calls never do.
 *
 * @param string $name Registered ability name.
 * @return string '', 'backup_gate', 'backup_gate_pending' or 'backup_gate_too_large'.
 */
function aafm_backup_gate_refusal( string $name ): string {
	static $memo = null;

	// The adapter's fire is where a tool call begins: drop whatever an earlier call left, before
	// any early return below.
	$adapter_fire = null === aafm_executing_ability();
	if ( $adapter_fire ) {
		$memo = null;
	}

	if ( ! aafm_stored_switch_on( 'aafm_backup_gate' ) ) {
		return '';
	}

	// Never governed, whatever the list says: the two backup reads and every read.
	if ( 'aafm/site-backup-status' === $name || 'aafm/site-list-backups' === $name || aafm_ability_is_read( $name ) ) {
		return '';
	}

	// A bridged wrapper is listed under the other plugin's own slug. One whose slug cannot be
	// recovered is governed.
	$key     = $name;
	$unknown = false;
	if ( 0 === strpos( $name, 'aafm-bridge/' ) ) {
		$source = aafm_remember_bridge_source( $name );
		if ( null === $source || '' === $source ) {
			$unknown = true;
		} else {
			$key = $source;
		}
	}

	$list = aafm_backup_gate_list();
	if ( ! $unknown && null !== $list && ! in_array( $key, $list, true ) ) {
		return '';
	}
	// An empty list holds nothing back, a wrapper whose slug cannot be recovered included.
	if ( $unknown && array() === $list ) {
		return '';
	}

	/**
	 * Filters whether a good backup is vouched for by other means, for one tool call.
	 *
	 * Only an exact true opens the gate. It has no effect while the gate is off.
	 *
	 * @param bool   $vouched Default false.
	 * @param string $name    The ability being called.
	 */
	if ( true === apply_filters( 'aafm_backup_gate_vouch', false, $name ) ) {
		return '';
	}

	if ( $adapter_fire ) {
		$state = aafm_backup_gate_state();
		$memo  = array(
			'name'  => $name,
			'state' => $state,
		);
	} elseif ( is_array( $memo ) && $memo['name'] === $name ) {
		$state = $memo['state'];
		$memo  = null; // Read at most once.
	} else {
		$state = aafm_backup_gate_state();
	}

	if ( $state['satisfied'] ) {
		return '';
	}
	if ( 'verification_pending' === $state['reason'] ) {
		return 'backup_gate_pending';
	}
	if ( 'too_large_to_verify' === $state['reason'] ) {
		return 'backup_gate_too_large';
	}

	return 'backup_gate';
}

/**
 * The refusal an agent sees, for one of the three reasons.
 *
 * @param string $reason 'backup_gate', 'backup_gate_pending' or 'backup_gate_too_large'.
 * @return WP_Error
 */
function aafm_backup_gate_error( string $reason ): WP_Error {
	if ( 'backup_gate_pending' === $reason ) {
		return new WP_Error( 'aafm_backup_gate_pending', __( 'A recent backup exists but the site has not finished checking it yet, so this tool cannot run. The check normally runs about once an hour, and the site owner can start it from the Backup gate card under Settings. Try again later.', 'agent-abilities-for-mcp' ) );
	}
	if ( 'backup_gate_too_large' === $reason ) {
		return new WP_Error( 'aafm_backup_gate_too_large', __( 'A recent backup exists but is too large for this site to check, so this tool cannot run. Waiting will not change that. The site owner can accept large backups on their size alone in the Backup gate card under Settings, or keep a smaller database backup.', 'agent-abilities-for-mcp' ) );
	}

	return new WP_Error( 'aafm_backup_gate', __( 'The site owner requires a checked backup from the last 24 hours before this tool can run, and this site has none that passed. The Backup gate card under Settings says why. This connection cannot start a backup.', 'agent-abilities-for-mcp' ) );
}
