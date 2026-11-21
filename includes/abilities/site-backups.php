<?php
/**
 * Backup abilities: site-backup-status, site-list-backups.
 *
 * Registers only while UpdraftPlus is loaded (aafm_integration_active( 'site_backups' )). Both
 * abilities read. Neither can start, restore, delete or download a backup, and neither returns a
 * file name, a path, a backup nonce or a storage setting. Both are refused on a multisite network,
 * where UpdraftPlus keeps its job rows in site options and no bench ran.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_site_backups_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_site_backups_full_definitions' );

/**
 * Contribute the backup ability definitions to the registry, only while UpdraftPlus is loaded.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_site_backups_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'site_backups' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_site_backups_registry_definitions() );
}

/**
 * Contribute the backup ability definitions to the host-independent full registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_site_backups_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_site_backups_registry_definitions() );
}

/**
 * The two backup ability rows, shared by the guarded and full registries.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_site_backups_registry_definitions(): array {
	return array(
		'aafm/site-backup-status' => array(
			'label'        => __( 'Backup status', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Says whether UpdraftPlus is the backup plugin on this site, whether a backup is running, waiting to start or stalled and at which stage, whether an earlier backup stopped without finishing, and how old the newest good backup of the last 24 hours is. A backup is good when its job finished, it holds the database, and the database file on this server has exactly the size UpdraftPlus recorded and matched the checksum UpdraftPlus recorded when it was checked. A small file is compared when this tool runs; others are compared by a background check about once an hour, and until that has run the backup is reported as pending. The background check runs only while the backup gate is on. A backup the background check cannot finish is reported as too large to verify. The site owner can choose to accept such backups on their size alone, or a backup kept only off-site without any check; the result says which check was made and when. Also says whether the backup gate is on and satisfied, and why not. These tools cannot start a backup and this tool changes nothing. Never returns file names, paths, storage settings or log text. Refused on multisite. Requires the manage-options capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'site_backups',
			'args_builder' => 'aafm_args_site_backup_status',
		),
		'aafm/site-list-backups'  => array(
			'label'        => __( 'List backups', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the backup sets UpdraftPlus has on record, newest first: when each started, whether it holds the database only or files too, which parts it holds, its total size, whether it is kept on this server, off-site or both, and the result of two checks. The database check: verified means the database file on this server has the recorded size and matched its checksum when the background check last looked, at the time given; pending means a backup of the last 24 hours that has not been checked yet; too_large means the background check could not finish it; size_only means one of those two on a site whose owner accepts large backups on size alone; not_checked means a backup older than 24 hours with the right size, which is not checked; trusted_unchecked means the set is kept only off-site and the site owner chose to trust UpdraftPlus\'s record of it; failed means none of these. The background check runs only while the backup gate is on. This tool never computes a checksum itself, so a small backup can show as pending here while backup-status, which compares small files on the spot, already reports it as verified. The files check compares sizes only: size_ok, failed, not_local, or not_included for a database-only set. It changes nothing. Never returns file names, download links, storage settings or credentials. Refused on multisite. Requires the manage-options capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'site_backups',
			'args_builder' => 'aafm_args_site_list_backups',
		),
	);
}

/**
 * The refusal both backup tools give on a multisite network.
 *
 * @return WP_Error
 */
function aafm_site_backups_multisite_error(): WP_Error {
	return new WP_Error( 'aafm_site_multisite', __( 'The backup tools do not run on a multisite network in this version.', 'agent-abilities-for-mcp' ) );
}

/**
 * The properties of one backup set as site-list-backups returns it.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_site_backup_set_properties(): array {
	return array(
		'started_at'     => array( 'type' => 'string' ),
		'scope'          => array(
			'type' => 'string',
			'enum' => array( 'database', 'full' ),
		),
		'entities'       => array(
			'type'  => 'array',
			'items' => array(
				'type' => 'string',
				'enum' => array( 'db', 'plugins', 'themes', 'uploads', 'others', 'mu-plugins', 'wpcore', 'more' ),
			),
		),
		'total_bytes'    => array( 'type' => 'integer' ),
		'storage'        => array(
			'type' => 'string',
			'enum' => array( 'local', 'remote', 'local_and_remote' ),
		),
		'database_check' => array(
			'type' => 'string',
			'enum' => array( 'verified', 'pending', 'too_large', 'size_only', 'trusted_unchecked', 'not_checked', 'failed' ),
		),
		'files_check'    => array(
			'type' => 'string',
			'enum' => array( 'not_included', 'size_ok', 'failed', 'not_local' ),
		),
		'checked_at'     => array( 'type' => array( 'string', 'null' ) ),
	);
}

/**
 * Args for aafm/site-backup-status.
 *
 * @return array<string,mixed>
 */
function aafm_args_site_backup_status(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/site-backup-status' ),
		'description'         => aafm_ability_description( 'aafm/site-backup-status' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'provider'   => array(
					'type' => 'string',
					'enum' => array( 'updraftplus', 'none' ),
				),
				'running'    => array( 'type' => 'boolean' ),
				'stage'      => array(
					'type' => 'string',
					'enum' => array( '', 'begun', 'files', 'database', 'uploading', 'pruning', 'resuming', 'other' ),
				),
				'queued'     => array( 'type' => 'boolean' ),
				'stalled'    => array( 'type' => 'boolean' ),
				'failed_job' => array( 'type' => 'boolean' ),
				'last_good'  => array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'started_at'     => array( 'type' => 'string' ),
						'age_seconds'    => array( 'type' => 'integer' ),
						'scope'          => array(
							'type' => 'string',
							'enum' => array( 'database', 'full' ),
						),
						'storage'        => array(
							'type' => 'string',
							'enum' => array( 'local', 'remote', 'local_and_remote' ),
						),
						'database_check' => array(
							'type' => 'string',
							'enum' => array( 'verified', 'size_only', 'trusted_unchecked' ),
						),
						'files_check'    => array(
							'type' => 'string',
							'enum' => array( 'not_included', 'size_ok', 'failed', 'not_local' ),
						),
						'checked_at'     => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'gate'       => array(
					'type'       => 'object',
					'properties' => array(
						'on'              => array( 'type' => 'boolean' ),
						'satisfied'       => array( 'type' => 'boolean' ),
						'reason'          => array(
							'type' => 'string',
							'enum' => array( 'ok', 'no_backup', 'verification_pending', 'too_large_to_verify', 'no_provider' ),
						),
						'verify_schedule' => array(
							'type' => 'string',
							'enum' => array( 'ok', 'not_scheduled', 'cron_disabled', 'cron_late' ),
						),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_site_backup_status',
		'permission_callback' => 'aafm_perm_manage_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/site-backup-status.
 *
 * Makes one call of aafm_backup_gate_state(), so it hashes at most the inline budget and nothing
 * once the background check has stored a verdict. It writes nothing.
 *
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_site_backup_status() {
	if ( is_multisite() ) {
		return aafm_site_backups_multisite_error();
	}
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-updraft.php';

	$state = aafm_backup_gate_state();
	$out   = array(
		'provider'   => 'updraftplus' === $state['provider'] ? 'updraftplus' : 'none',
		'running'    => false,
		'stage'      => '',
		'queued'     => false,
		'stalled'    => false,
		'failed_job' => false,
		'last_good'  => null,
		'gate'       => array(
			'on'              => $state['on'],
			'satisfied'       => $state['satisfied'],
			'reason'          => 'multisite' === $state['reason'] ? 'no_provider' : $state['reason'],
			'verify_schedule' => $state['verify_schedule'],
		),
	);
	if ( 'updraftplus' !== $out['provider'] ) {
		return $out;
	}

	$good = $state['last_good'];
	if ( is_array( $good ) ) {
		$started          = (int) $good['started_at'];
		$out['last_good'] = array(
			'started_at'     => gmdate( 'c', $started ),
			'age_seconds'    => max( 0, time() - $started ),
			'scope'          => (string) $good['scope'],
			'storage'        => (string) $good['storage'],
			'database_check' => (string) $good['database_check'],
			'files_check'    => (string) $good['files_check'],
			'checked_at'     => null === $good['checked_at'] ? null : gmdate( 'c', (int) $good['checked_at'] ),
		);
	}

	$call = aafm_siteops_no_http_call( 'aafm_site_updraft_jobs' );
	if ( ! $call['ok'] || ! is_array( $call['value'] ) ) {
		$out['stage'] = 'other';
		return $out;
	}

	$newest = array(
		'running' => null,
		'stalled' => null,
	);
	foreach ( $call['value'] as $job ) {
		$job_state = (string) $job['state'];
		if ( 'running' === $job_state ) {
			$out['running'] = true;
		} elseif ( 'queued' === $job_state ) {
			$out['queued'] = true;
		} elseif ( 'stalled' === $job_state ) {
			$out['stalled'] = true;
		} elseif ( 'failed' === $job_state ) {
			$out['failed_job'] = true;
		}
		if ( array_key_exists( $job_state, $newest ) && ( null === $newest[ $job_state ] || $job['started_at'] > $newest[ $job_state ]['started_at'] ) ) {
			$newest[ $job_state ] = $job;
		}
	}
	if ( null !== $newest['running'] ) {
		$out['stage'] = (string) $newest['running']['stage'];
	} elseif ( null !== $newest['stalled'] ) {
		$out['stage'] = (string) $newest['stalled']['stage'];
	}

	return $out;
}

/**
 * Args for aafm/site-list-backups.
 *
 * @return array<string,mixed>
 */
function aafm_args_site_list_backups(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/site-list-backups' ),
		'description'         => aafm_ability_description( 'aafm/site-list-backups' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_pagination_schema_props(
				20,
				__( 'Number of backup sets per page, from 1 to 20. Defaults to 10 when omitted.', 'agent-abilities-for-mcp' ),
				__( '1-based page number. Defaults to 1. A page past the last one returns an empty list.', 'agent-abilities-for-mcp' )
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'backups'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_site_backup_set_properties(),
					),
				),
				'total'    => array( 'type' => 'integer' ),
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_site_list_backups',
		'permission_callback' => 'aafm_perm_manage_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/site-list-backups.
 *
 * Never hashes: it passes an inline budget of 0, so a set shows verified or failed only from a
 * stored verdict. Only the sets on the requested page are looked at on disk.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_site_list_backups( array $input ) {
	if ( is_multisite() ) {
		return aafm_site_backups_multisite_error();
	}
	$no_provider = new WP_Error( 'aafm_site_backup_no_provider', __( 'UpdraftPlus is not active on this site, or its backup history could not be read.', 'agent-abilities-for-mcp' ) );
	if ( ! aafm_site_updraft_loaded() ) {
		return $no_provider;
	}
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-updraft.php';

	$paging        = aafm_paginate_args( $input, 20 );
	$verdicts      = aafm_backup_verify_read();
	$accept_remote = aafm_backup_gate_permissive_on( 'aafm_backup_gate_accept_remote' );
	$accept_size   = aafm_backup_gate_permissive_on( 'aafm_backup_gate_accept_size' );

	$call = aafm_siteops_no_http_call(
		static function () use ( $paging, $verdicts, $accept_remote, $accept_size ): array {
			$history = aafm_site_updraft_history();
			$rows    = array();
			$budget  = 0;
			foreach ( array_slice( $history, ( $paging['page'] - 1 ) * $paging['per_page'], $paging['per_page'], true ) as $timestamp => $entry ) {
				$rows[] = aafm_site_updraft_set_summary( (int) $timestamp, $entry, $verdicts, $accept_remote, $accept_size, $budget );
			}
			return array(
				'total' => count( $history ),
				'rows'  => $rows,
			);
		}
	);
	if ( ! $call['ok'] || ! is_array( $call['value'] ) ) {
		return $no_provider;
	}

	$backups = array();
	foreach ( $call['value']['rows'] as $row ) {
		$backups[] = array(
			'started_at'     => gmdate( 'c', (int) $row['started_at'] ),
			'scope'          => (string) $row['scope'],
			'entities'       => $row['entities'],
			'total_bytes'    => (int) $row['total_bytes'],
			'storage'        => (string) $row['storage'],
			'database_check' => (string) $row['database_check'],
			'files_check'    => (string) $row['files_check'],
			'checked_at'     => null === $row['checked_at'] ? null : gmdate( 'c', (int) $row['checked_at'] ),
		);
	}

	return array(
		'backups'  => $backups,
		'total'    => (int) $call['value']['total'],
		'page'     => $paging['page'],
		'per_page' => $paging['per_page'],
	);
}
