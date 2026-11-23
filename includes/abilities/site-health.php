<?php
/**
 * Site Ops: three read-only site health abilities.
 *
 * The site-health-status tool runs the Site Health tests of WordPress that need no network
 * request and returns each test's name and result. The site-list-updates tool reads the result of
 * the last update check. The site-cron-status tool summarises the scheduled tasks. None of them writes anything, starts
 * a check, or lets a request leave; none returns a path, a version of WordPress, a log line or a
 * task argument.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_site_health_definitions' );

/**
 * Contribute the three site health definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_site_health_definitions( array $registry ): array {
	$registry['aafm/site-health-status'] = array(
		'label'        => __( 'Site health summary', 'agent-abilities-for-mcp' ),
		'description'  => __( "Runs WordPress's own Site Health checks that need no network request and returns each check's name, result (good, recommended or critical) and WordPress's own label for that result, with a count of each. The label says what the check found; do not guess a cause from the check's name (theme_version, for example, also flags inactive themes, not only waiting updates). Also says whether debug mode is on, whether a debug log file exists, whether WordPress is in recovery mode and whether any plugin or theme is paused after a fatal error. Outbound requests are blocked while it runs. Also names the checks it does not run (those that need a network request or a file system connection, and the ones the Site Health screen runs in the background), so its counts can be lower than that screen's. Returns no log lines, no file paths, no version numbers and no server details; use the plugin list tool for plugin versions. Requires the manage-options capability. On a multisite network, a super admin only.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_site_health_status',
	);
	$registry['aafm/site-list-updates']  = array(
		'label'        => __( 'List waiting updates', 'agent-abilities-for-mcp' ),
		'description'  => __( "Lists the updates WordPress already knows are waiting: whether a core update is available and its version, and each plugin and theme with a newer version. It reads the result of WordPress's last update check and says when that check ran; it never starts a check, and outbound requests are blocked while it runs, so the list can be out of date; last_checked says how old it is. Never returns download addresses. It installs nothing. Requires the manage-options capability. On a multisite network, a super admin only.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_site_list_updates',
	);
	$registry['aafm/site-cron-status']   = array(
		'label'        => __( 'Scheduled task status', 'agent-abilities-for-mcp' ),
		'description'  => __( "Summarises WordPress's scheduled tasks: whether WP-Cron is switched off in configuration, how many tasks are scheduled, how many are due now and how many are more than five minutes late, how late the oldest one is, and the 50 hooks with the soonest next run, each with its schedule and how many events it has. Hook names are identifiers chosen by plugin code; names with unusual characters are shown as other. Task arguments are never returned. It runs nothing and changes nothing. Requires the manage-options capability. On a multisite network, a super admin only.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_site_cron_status',
	);
	return $registry;
}

/**
 * Who may run the three health reads: manage_options, and on a multisite network a super admin
 * only. Core shows Site Health (view_site_health_checks) and update data (update_plugins) to
 * nobody below a super admin on a network, and the update transients are network-wide. The
 * check takes no object, so discovery answers the same as a call.
 *
 * @return bool
 */
function aafm_perm_site_health(): bool {
	return current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() );
}

/**
 * The parts every site health ability shares: no input, a read, gated by aafm_perm_site_health().
 *
 * @param string              $name       Ability name.
 * @param array<string,mixed> $properties Output schema properties.
 * @param string              $execute    Execute callback.
 * @return array<string,mixed>
 */
function aafm_site_health_args( string $name, array $properties, string $execute ): array {
	return array(
		'label'               => aafm_ability_label( $name ),
		'description'         => aafm_ability_description( $name ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => $properties,
		),
		'execute_callback'    => $execute,
		'permission_callback' => 'aafm_perm_site_health',
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
 * Args for aafm/site-health-status.
 *
 * @return array<string,mixed>
 */
function aafm_args_site_health_status(): array {
	return aafm_site_health_args(
		'aafm/site-health-status',
		array(
			'tests'             => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'test'   => array( 'type' => 'string' ),
						'status' => array(
							'type' => 'string',
							'enum' => array( 'good', 'recommended', 'critical' ),
						),
						'label'  => array(
							'type'        => 'string',
							'description' => __( 'WordPress\'s own label for this result, as the Site Health screen words it, without any version number; empty when it has none that can be shown. Read this, not the check name, to say what was found.', 'agent-abilities-for-mcp' ),
						),
					),
				),
			),
			'counts'            => array(
				'type'       => 'object',
				'properties' => array(
					'good'        => array( 'type' => 'integer' ),
					'recommended' => array( 'type' => 'integer' ),
					'critical'    => array( 'type' => 'integer' ),
				),
			),
			'not_run'           => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Site Health checks of WordPress itself that this tool does not run. Checks added by plugins are not run either.', 'agent-abilities-for-mcp' ),
			),
			'debug_mode'        => array( 'type' => 'boolean' ),
			'debug_log_exists'  => array( 'type' => 'boolean' ),
			'recovery_mode'     => array( 'type' => 'boolean' ),
			'extensions_paused' => array( 'type' => 'boolean' ),
		),
		'aafm_exec_site_health_status'
	);
}

/**
 * Args for aafm/site-list-updates.
 *
 * @return array<string,mixed>
 */
function aafm_args_site_list_updates(): array {
	$checked = array( 'type' => array( 'string', 'null' ) );
	return aafm_site_health_args(
		'aafm/site-list-updates',
		array(
			'core'         => array(
				'type'       => 'object',
				'properties' => array(
					'update_available' => array( 'type' => 'boolean' ),
					'new_version'      => array( 'type' => 'string' ),
				),
			),
			'plugins'      => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin'      => array( 'type' => 'string' ),
						'new_version' => array( 'type' => 'string' ),
					),
				),
			),
			'themes'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet'  => array( 'type' => 'string' ),
						'new_version' => array( 'type' => 'string' ),
					),
				),
			),
			'last_checked' => array(
				'type'       => 'object',
				'properties' => array(
					'core'    => $checked,
					'plugins' => $checked,
					'themes'  => $checked,
				),
			),
		),
		'aafm_exec_site_list_updates'
	);
}

/**
 * Args for aafm/site-cron-status.
 *
 * @return array<string,mixed>
 */
function aafm_args_site_cron_status(): array {
	return aafm_site_health_args(
		'aafm/site-cron-status',
		array(
			'wp_cron_disabled'       => array( 'type' => 'boolean' ),
			'alternate_cron'         => array( 'type' => 'boolean' ),
			'total_events'           => array( 'type' => 'integer' ),
			'due_now'                => array( 'type' => 'integer' ),
			'overdue'                => array( 'type' => 'integer' ),
			'oldest_overdue_seconds' => array( 'type' => 'integer' ),
			'hooks'                  => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'hook'                => array( 'type' => 'string' ),
						'next_run_in_seconds' => array( 'type' => 'integer' ),
						'schedule'            => array( 'type' => 'string' ),
						'events'              => array( 'type' => 'integer' ),
					),
				),
			),
		),
		'aafm_exec_site_cron_status'
	);
}

/**
 * The Site Health tests this plugin runs: the 18 that make no request, start no update check and
 * open no file system connection.
 *
 * Left out on purpose: update_temp_backup_writable (can open an FTP or SSH connection),
 * http_requests and rest_availability (outbound), php_version (calls the wordpress.org API), and
 * every asynchronous test.
 *
 * @return list<string>
 */
function aafm_site_health_allowlist(): array {
	return array(
		'wordpress_version',
		'plugin_version',
		'theme_version',
		'php_extensions',
		'php_default_timezone',
		'php_sessions',
		'sql_server',
		'ssl_support',
		'scheduled_events',
		'is_in_debug_mode',
		'file_uploads',
		'plugin_theme_auto_updates',
		'available_updates_disk_space',
		'autoloaded_options',
		'insecure_registration',
		'search_engine_visibility',
		'opcode_cache',
		'persistent_object_cache',
	);
}

/**
 * The Site Health tests of WordPress itself that this plugin does not run, by the names the Site
 * Health screen uses: the ones left out of the allowlist above, then every asynchronous test.
 *
 * @return list<string>
 */
function aafm_site_health_not_run(): array {
	return array(
		'update_temp_backup_writable',
		'php_version',
		'http_requests',
		'rest_availability',
		'dotorg_communication',
		'background_updates',
		'loopback_requests',
		'https_status',
		'authorization_header',
		'page_cache',
	);
}

/**
 * Whether any plugin or theme is paused after a fatal error.
 *
 * Reads the stored rows directly, because wp_paused_plugins()->get_all() is always empty outside
 * a recovery mode session. A row that holds an empty array is not a paused extension.
 *
 * @return bool
 */
function aafm_site_health_extensions_paused(): bool {
	global $wpdb;

	$rows = aafm_wpdb_col( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name LIKE %s LIMIT 5', $wpdb->options, '%' . $wpdb->esc_like( '_paused_extensions' ) ) );
	if ( ! $rows['ok'] || ! is_array( $rows['value'] ) ) {
		return false;
	}
	foreach ( $rows['value'] as $raw ) {
		$value = maybe_unserialize( is_string( $raw ) ? $raw : '' );
		if ( is_array( $value ) && array() !== $value ) {
			return true;
		}
	}
	return false;
}

/**
 * The pre_schedule_event filter that refuses core's weekly Site Health event, and no other.
 *
 * @param null|bool|WP_Error $pre   The answer so far.
 * @param object             $event The event being scheduled.
 * @return null|bool|WP_Error
 */
function aafm_site_health_block_weekly_check( $pre, $event ) {
	if ( is_object( $event ) && isset( $event->hook ) && 'wp_site_health_scheduled_check' === $event->hook ) {
		return false;
	}
	return $pre;
}

/**
 * A Site Health result's own label as plain text: core's translated words, with markup and entities
 * removed and a bracketed version dropped ("WordPress update available (7.1.5)"). A label that still
 * carries a version number is not shown at all, so the tool keeps returning no version numbers.
 *
 * @param mixed $result A get_test_*() result.
 * @return string
 */
function aafm_site_health_label( $result ): string {
	$label = is_array( $result ) && isset( $result['label'] ) && is_string( $result['label'] ) ? $result['label'] : '';
	$label = html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES, 'UTF-8' );
	$label = trim( (string) preg_replace( '/\s*\([^()]*\d[^()]*\)/u', '', $label ) );
	return 1 === preg_match( '/\d+\.\d+/', $label ) ? '' : $label;
}

/**
 * Execute aafm/site-health-status.
 *
 * WP_Site_Health::get_instance() is used and never the constructor: WordPress creates the
 * instance on every request, and constructing a second one would schedule its weekly event.
 * Should the instance not exist yet, get_instance() runs the constructor, so the weekly event is
 * refused for the length of that call. Each test keeps its status and its label as plain text
 * without versions (aafm_site_health_label()); descriptions and actions are HTML that can name
 * plugins and versions, and are dropped.
 *
 * @return array<string,mixed>
 */
function aafm_exec_site_health_status(): array {
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';

	add_filter( 'pre_schedule_event', 'aafm_site_health_block_weekly_check', 10, 2 );
	try {
		$health = WP_Site_Health::get_instance();
	} finally {
		remove_filter( 'pre_schedule_event', 'aafm_site_health_block_weekly_check', 10 );
	}
	$tests  = array();
	$counts = array(
		'good'        => 0,
		'recommended' => 0,
		'critical'    => 0,
	);
	foreach ( aafm_site_health_allowlist() as $name ) {
		$method = 'get_test_' . $name;
		if ( ! $health instanceof WP_Site_Health || ! method_exists( $health, $method ) ) {
			continue;
		}
		$call   = aafm_siteops_no_http_call(
			static function () use ( $health, $method ) {
				return $health->$method();
			}
		);
		$status = ( $call['ok'] && is_array( $call['value'] ) && isset( $call['value']['status'] ) && is_string( $call['value']['status'] ) ) ? $call['value']['status'] : '';
		if ( ! isset( $counts[ $status ] ) ) {
			continue;
		}
		$tests[] = array(
			'test'   => $name,
			'status' => $status,
			'label'  => aafm_site_health_label( $call['value'] ),
		);
		++$counts[ $status ];
	}

	$log        = WP_CONTENT_DIR . '/debug.log';
	$configured = defined( 'WP_DEBUG_LOG' ) ? constant( 'WP_DEBUG_LOG' ) : false;
	if ( is_string( $configured ) && '' !== $configured ) {
		$log = $configured;
	}

	return array(
		'tests'             => $tests,
		'counts'            => $counts,
		'not_run'           => aafm_site_health_not_run(),
		'debug_mode'        => defined( 'WP_DEBUG' ) && (bool) constant( 'WP_DEBUG' ),
		'debug_log_exists'  => is_file( $log ),
		'recovery_mode'     => wp_is_recovery_mode(),
		'extensions_paused' => aafm_site_health_extensions_paused(),
	);
}

/**
 * The fields of a stored update record, whether it is an object or an array.
 *
 * @param mixed $value Stored value.
 * @return array<int|string,mixed>
 */
function aafm_site_updates_fields( $value ): array {
	if ( is_object( $value ) ) {
		return get_object_vars( $value );
	}
	return is_array( $value ) ? $value : array();
}

/**
 * A version string that is safe to return: at most 40 characters of digits, letters, dots,
 * hyphens and plus signs. Anything else is ''.
 *
 * @param mixed $raw Stored value.
 * @return string
 */
function aafm_site_updates_version( $raw ): string {
	if ( ! is_string( $raw ) && ! is_int( $raw ) && ! is_float( $raw ) ) {
		return '';
	}
	$version = substr( (string) $raw, 0, 40 );
	return 1 === preg_match( '/^[0-9A-Za-z.\-+]+$/', $version ) ? $version : '';
}

/**
 * The waiting updates of one stored plugin or theme check. The package address is never read.
 *
 * @param mixed  $stored The stored update check.
 * @param string $key    Output key for the name: 'plugin' or 'stylesheet'.
 * @return list<array<string,string>> At most 200 rows.
 */
function aafm_site_updates_rows( $stored, string $key ): array {
	$fields   = aafm_site_updates_fields( $stored );
	$response = isset( $fields['response'] ) && is_array( $fields['response'] ) ? $fields['response'] : array();
	$rows     = array();
	foreach ( $response as $name => $entry ) {
		if ( count( $rows ) >= 200 ) {
			break;
		}
		$name = (string) $name;
		if ( 1 !== preg_match( '/^[A-Za-z0-9._\/-]{1,200}$/', $name ) ) {
			continue;
		}
		$entry_fields = aafm_site_updates_fields( $entry );
		$version      = aafm_site_updates_version( $entry_fields['new_version'] ?? null );
		if ( '' === $version ) {
			continue;
		}
		$rows[] = array(
			$key          => $name,
			'new_version' => $version,
		);
	}
	return $rows;
}

/**
 * When one stored update check ran, as an ISO 8601 UTC string.
 *
 * @param mixed $stored The stored update check.
 * @return string|null
 */
function aafm_site_updates_checked( $stored ): ?string {
	$fields = aafm_site_updates_fields( $stored );
	if ( ! isset( $fields['last_checked'] ) || ! is_numeric( $fields['last_checked'] ) || (int) $fields['last_checked'] < 1 ) {
		return null;
	}
	return gmdate( 'c', (int) $fields['last_checked'] );
}

/**
 * Execute aafm/site-list-updates.
 *
 * Reads the three stored update checks and never starts one. The reads run with outbound
 * requests refused, because reading the plugin check runs a filter where commercial updaters
 * hook and some call their licence server.
 *
 * @return array<string,mixed>
 */
function aafm_exec_site_list_updates(): array {
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';

	$read   = aafm_siteops_no_http_call(
		static function (): array {
			return array(
				'core'    => get_site_transient( 'update_core' ),
				'plugins' => get_site_transient( 'update_plugins' ),
				'themes'  => get_site_transient( 'update_themes' ),
			);
		}
	);
	$stored = ( $read['ok'] && is_array( $read['value'] ) ) ? $read['value'] : array();
	$stored = array_merge(
		array(
			'core'    => false,
			'plugins' => false,
			'themes'  => false,
		),
		$stored
	);

	$core        = array(
		'update_available' => false,
		'new_version'      => '',
	);
	$core_fields = aafm_site_updates_fields( $stored['core'] );
	$updates     = isset( $core_fields['updates'] ) && is_array( $core_fields['updates'] ) ? $core_fields['updates'] : array();
	foreach ( $updates as $update ) {
		$update_fields = aafm_site_updates_fields( $update );
		if ( isset( $update_fields['response'] ) && 'upgrade' === $update_fields['response'] ) {
			$core = array(
				'update_available' => true,
				'new_version'      => aafm_site_updates_version( $update_fields['current'] ?? null ),
			);
			break;
		}
	}

	return array(
		'core'         => $core,
		'plugins'      => aafm_site_updates_rows( $stored['plugins'], 'plugin' ),
		'themes'       => aafm_site_updates_rows( $stored['themes'], 'stylesheet' ),
		'last_checked' => array(
			'core'    => aafm_site_updates_checked( $stored['core'] ),
			'plugins' => aafm_site_updates_checked( $stored['plugins'] ),
			'themes'  => aafm_site_updates_checked( $stored['themes'] ),
		),
	);
}

/**
 * Execute aafm/site-cron-status.
 *
 * Reads the cron array and nothing else. Hook names and schedule slugs are returned only when
 * they are plain identifiers; anything else reads as other. Arguments are never read into the
 * output.
 *
 * @return array<string,mixed>
 */
function aafm_exec_site_cron_status(): array {
	$cron    = _get_cron_array();
	$cron    = is_array( $cron ) ? $cron : array();
	$now     = time();
	$total   = 0;
	$due     = 0;
	$overdue = 0;
	$oldest  = 0;
	$hooks   = array();

	foreach ( $cron as $timestamp => $by_hook ) {
		if ( ! is_array( $by_hook ) ) {
			continue;
		}
		$timestamp = (int) $timestamp;
		foreach ( $by_hook as $hook => $events ) {
			if ( ! is_array( $events ) ) {
				continue;
			}
			$name = 1 === preg_match( '/^[A-Za-z0-9_.:\/-]{1,100}$/', (string) $hook ) ? (string) $hook : 'other';
			foreach ( $events as $event ) {
				++$total;
				if ( $timestamp <= $now ) {
					++$due;
					$oldest = max( $oldest, $now - $timestamp );
				}
				if ( $timestamp < $now - 300 ) {
					++$overdue;
				}

				$schedule = 'single';
				if ( is_array( $event ) && ! empty( $event['schedule'] ) ) {
					$schedule = ( is_string( $event['schedule'] ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $event['schedule'] ) ) ? $event['schedule'] : 'other';
				}

				if ( ! isset( $hooks[ $name ] ) ) {
					$hooks[ $name ] = array(
						'hook'     => $name,
						'next'     => $timestamp,
						'schedule' => $schedule,
						'events'   => 0,
					);
				} elseif ( $timestamp < $hooks[ $name ]['next'] ) {
					$hooks[ $name ]['next']     = $timestamp;
					$hooks[ $name ]['schedule'] = $schedule;
				}
				++$hooks[ $name ]['events'];
			}
		}
	}

	$hooks = array_values( $hooks );
	usort(
		$hooks,
		static function ( array $a, array $b ): int {
			return $a['next'] <=> $b['next'];
		}
	);

	$rows = array();
	foreach ( array_slice( $hooks, 0, 50 ) as $hook ) {
		$rows[] = array(
			'hook'                => $hook['hook'],
			'next_run_in_seconds' => max( 0, $hook['next'] - $now ),
			'schedule'            => $hook['schedule'],
			'events'              => $hook['events'],
		);
	}

	return array(
		'wp_cron_disabled'       => defined( 'DISABLE_WP_CRON' ) && (bool) constant( 'DISABLE_WP_CRON' ),
		'alternate_cron'         => defined( 'ALTERNATE_WP_CRON' ) && (bool) constant( 'ALTERNATE_WP_CRON' ),
		'total_events'           => $total,
		'due_now'                => $due,
		'overdue'                => $overdue,
		'oldest_overdue_seconds' => $oldest,
		'hooks'                  => $rows,
	);
}
