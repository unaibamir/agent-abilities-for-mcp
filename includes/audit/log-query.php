<?php
/**
 * Activity log queries: the filter normalizer, the one WHERE builder every reader shares, counts,
 * the agent-call clause, the 7-day summary, and connection names.
 *
 * The table, the page count, the CSV export and the summary all build their WHERE clause here, so
 * they cannot disagree. Values never reach the SQL text: the fragment is fixed strings plus
 * %s / %d placeholders, and the caller binds the params through $wpdb->prepare().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AAFM_ACTIVITY_SUMMARY_TTL' ) ) {
	define( 'AAFM_ACTIVITY_SUMMARY_TTL', 60 );
}

/**
 * The plugin-filter choices: slug => label.
 *
 * `core` is this plugin's own tools that belong to no integration, each integration slug is the
 * abilities listed for it in aafm_integration_ability_order(), and `bridge` is every ability
 * bridged from another plugin. Per-bridged-plugin entries are deliberately absent (spec D7).
 *
 * @return array<string,string>
 */
function aafm_activity_plugin_groups(): array {
	$groups = array( 'core' => __( 'This plugin: core tools', 'agent-abilities-for-mcp' ) );
	if ( function_exists( 'aafm_integration_ability_order' ) ) {
		$cards = function_exists( 'aafm_integration_cards' ) ? aafm_integration_cards() : array();
		foreach ( array_keys( aafm_integration_ability_order() ) as $slug ) {
			$groups[ (string) $slug ] = isset( $cards[ $slug ]['label'] ) ? (string) $cards[ $slug ]['label'] : (string) $slug;
		}
	}
	$groups['bridge'] = __( 'Other plugins (Bridge)', 'agent-abilities-for-mcp' );
	return $groups;
}

/**
 * A strict Y-m-d date, or null.
 *
 * @param mixed $value Candidate.
 * @return string|null
 */
function aafm_activity_valid_date( $value ): ?string {
	if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return null;
	}
	return $value;
}

/**
 * Reduce raw filter input to the known, valid keys. An invalid value is dropped, never an error.
 *
 * Accepts the legacy `ability` key as an alias of `tool`, so every existing aafm_query_activity()
 * caller keeps working. Idempotent: normalizing a normalized array returns it unchanged.
 *
 * @param array<string,mixed> $raw Raw filters.
 * @return array<string,string|int>
 */
function aafm_activity_filters_normalize( array $raw ): array {
	$out = array();

	if ( isset( $raw['status'] ) && is_scalar( $raw['status'] ) && '' !== (string) $raw['status'] ) {
		$out['status'] = sanitize_key( (string) $raw['status'] );
	}
	if ( isset( $raw['user'] ) && is_scalar( $raw['user'] ) && '' !== trim( (string) $raw['user'] ) ) {
		$out['user'] = mb_substr( trim( (string) $raw['user'] ), 0, 60 );
	}
	if ( isset( $raw['conn'] ) && is_scalar( $raw['conn'] ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,191}\z/', (string) $raw['conn'] ) ) {
		$out['conn'] = (string) $raw['conn'];
	}
	$tool = $raw['tool'] ?? ( $raw['ability'] ?? '' );
	if ( is_scalar( $tool ) && '' !== trim( (string) $tool ) ) {
		$out['tool'] = mb_substr( trim( (string) $tool ), 0, 191 );
	}
	if ( isset( $raw['plugin'] ) && is_scalar( $raw['plugin'] ) && isset( aafm_activity_plugin_groups()[ (string) $raw['plugin'] ] ) ) {
		$out['plugin'] = (string) $raw['plugin'];
	}
	$from = aafm_activity_valid_date( $raw['from'] ?? null );
	if ( null !== $from ) {
		$out['from'] = $from;
	}
	$to = aafm_activity_valid_date( $raw['to'] ?? null );
	if ( null !== $to ) {
		$out['to'] = $to;
	}
	if ( isset( $raw['q'] ) && is_scalar( $raw['q'] ) ) {
		// Not sanitize_text_field(): it strips percent-encoded octets, so a search for "%ab" would silently become empty.
		// The value is bound and esc_like'd, never interpolated, so only tags and control characters need to go.
		$q = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', wp_strip_all_tags( (string) $raw['q'] ) ) );
		if ( mb_strlen( $q ) >= 2 ) {
			$out['q'] = mb_substr( $q, 0, 64 );
		}
	}
	// Set by code only (aafm_exec_get_activity_log()); aafm_activity_filters_from_request() never passes it.
	if ( ! empty( $raw['hide_notifications'] ) ) {
		$out['hide_notifications'] = 1;
	}
	if ( isset( $raw['row'] ) && is_scalar( $raw['row'] ) && absint( $raw['row'] ) > 0 ) {
		$out['row'] = absint( $raw['row'] );
	}
	if ( isset( $raw['max_id'] ) && is_scalar( $raw['max_id'] ) && absint( $raw['max_id'] ) > 0 ) {
		$out['max_id'] = absint( $raw['max_id'] );
	}
	return $out;
}

/**
 * Filters from a request array (POST for the AJAX handler, GET for the export and the tab).
 *
 * The status travels as `filter` (the segmented buttons' long-standing name); `all` and anything
 * outside the status list mean no status filter. Arrays are ignored, never cast.
 *
 * @param array<string,mixed> $source Unslashed request array.
 * @return array<string,string|int>
 */
function aafm_activity_filters_from_request( array $source ): array {
	$raw = array();
	foreach ( array( 'user', 'conn', 'tool', 'plugin', 'from', 'to', 'q', 'row' ) as $key ) {
		if ( isset( $source[ $key ] ) && is_string( $source[ $key ] ) ) {
			$raw[ $key ] = $source[ $key ];
		}
	}
	if ( isset( $source['filter'] ) && is_string( $source['filter'] ) && in_array( $source['filter'], aafm_activity_statuses(), true ) ) {
		$raw['status'] = $source['filter'];
	}
	return aafm_activity_filters_normalize( $raw );
}

/**
 * Build the WHERE fragment for a filter set.
 *
 * @param array<string,mixed> $filters Filters (normalized again here, so a raw array is safe).
 * @return array{sql:string,params:list<mixed>} `sql` starts with 1=1; every value is a placeholder.
 */
function aafm_activity_filter_where( array $filters ): array {
	global $wpdb;
	$f      = aafm_activity_filters_normalize( $filters );
	$sql    = '1=1';
	$params = array();

	if ( isset( $f['status'] ) ) {
		$sql     .= ' AND status = %s';
		$params[] = (string) $f['status'];
	}
	if ( isset( $f['user'] ) ) {
		$sql     .= ' AND principal_login = %s';
		$params[] = (string) $f['user'];
	}
	if ( isset( $f['conn'] ) && 'none' === $f['conn'] ) {
		// Only real agent calls, so every listed row shows "App Password or direct" in the Connection
		// column and never "-" (the same rule aafm_activity_connection_label() applies).
		$agent    = aafm_agent_call_clause();
		$sql     .= ' AND client_id = %s AND ' . $agent['sql'];
		$params[] = '';
		$params   = array_merge( $params, $agent['params'] );
	} elseif ( isset( $f['conn'] ) ) {
		$sql     .= ' AND client_id = %s';
		$params[] = (string) $f['conn'];
	}
	if ( isset( $f['tool'] ) ) {
		$sql     .= ' AND ability = %s';
		$params[] = (string) $f['tool'];
	}
	if ( isset( $f['plugin'] ) ) {
		$integration = function_exists( 'aafm_integration_ability_order' ) ? aafm_integration_ability_order() : array();
		if ( 'bridge' === $f['plugin'] ) {
			$sql     .= ' AND ability LIKE %s';
			$params[] = $wpdb->esc_like( 'aafm-bridge/' ) . '%';
		} elseif ( 'core' === $f['plugin'] ) {
			$sql     .= ' AND ability LIKE %s';
			$params[] = $wpdb->esc_like( 'aafm/' ) . '%';
			$members  = array();
			foreach ( $integration as $rows ) {
				foreach ( $rows as $row ) {
					$members[] = (string) $row['name'];
				}
			}
			if ( array() !== $members ) {
				$sql   .= ' AND ability NOT IN (' . implode( ',', array_fill( 0, count( $members ), '%s' ) ) . ')';
				$params = array_merge( $params, $members );
			}
		} elseif ( isset( $integration[ $f['plugin'] ] ) ) {
			$members = array_map(
				static function ( array $row ): string {
					return (string) $row['name'];
				},
				$integration[ $f['plugin'] ]
			);
			$sql    .= ' AND ability IN (' . implode( ',', array_fill( 0, count( $members ), '%s' ) ) . ')';
			$params  = array_merge( $params, $members );
		}
	}
	if ( isset( $f['from'] ) ) {
		$sql     .= ' AND created_at >= %s';
		$params[] = $f['from'] . ' 00:00:00';
	}
	if ( isset( $f['to'] ) ) {
		$end      = new DateTimeImmutable( $f['to'] . ' 00:00:00', new DateTimeZone( 'UTC' ) );
		$sql     .= ' AND created_at < %s';
		$params[] = $end->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
	}
	if ( isset( $f['q'] ) ) {
		$like     = '%' . $wpdb->esc_like( (string) $f['q'] ) . '%';
		$sql     .= ' AND ( ability LIKE %s OR detail LIKE %s OR arg_keys LIKE %s OR principal_login LIKE %s )';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}
	if ( isset( $f['hide_notifications'] ) ) {
		$sql     .= ' AND ability <> %s';
		$params[] = 'aafm/notifications';
	}
	if ( isset( $f['row'] ) ) {
		$sql     .= ' AND id = %d';
		$params[] = (int) $f['row'];
	}
	if ( isset( $f['max_id'] ) ) {
		$sql     .= ' AND id <= %d';
		$params[] = (int) $f['max_id'];
	}
	// ponytail: user, conn and q have no index of their own, so they scan whatever the date, status
	// and tool filters leave. Fine at default retention (30 days). Add (client_id, created_at) and
	// (principal_user_id, created_at) indexes if a site with millions of rows needs them.
	return array(
		'sql'    => $sql,
		'params' => $params,
	);
}

/**
 * Count the rows a filter set matches, honest about a failed query.
 *
 * @param array<string,mixed> $filters Filters.
 * @return array{ok:bool,value:int}
 */
function aafm_activity_count_matching_result( array $filters ): array {
	global $wpdb;
	$where = aafm_activity_filter_where( $filters );
	// The fragment holds only fixed text and placeholders; every value is bound below.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$view = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where['sql'], array_merge( array( aafm_activity_log_table() ), $where['params'] ) ) );
	return array(
		'ok'    => $view['ok'],
		'value' => $view['ok'] ? max( 0, (int) $view['value'] ) : 0,
	);
}

/**
 * Count the rows a filter set matches.
 *
 * @param array<string,mixed> $filters Filters.
 * @return int
 */
function aafm_activity_count_matching( array $filters ): int {
	return aafm_activity_count_matching_result( $filters )['value'];
}

/**
 * The one WHERE clause for "what counts as an agent tool call".
 *
 * Event type ability_call, minus the three synthetic names that land under that default type: the
 * (transport) refusals, the oauth:* ceremony rows and the log-cleared marker. aafm_agent_call_count()
 * and the 7-day summary both use this, so a fourth default-type writer is excluded in one place.
 *
 * @param string|null $status Optional status to narrow to.
 * @return array{sql:string,params:list<mixed>}
 */
function aafm_agent_call_clause( ?string $status = null ): array {
	global $wpdb;
	$sql    = 'event_type = %s AND ability <> %s AND ability <> %s AND ability NOT LIKE %s';
	$params = array(
		'ability_call',
		'(transport)',
		'aafm/activity-log-cleared',
		$wpdb->esc_like( 'oauth:' ) . '%',
	);
	if ( null !== $status && '' !== $status ) {
		$sql     .= ' AND status = %s';
		$params[] = $status;
	}
	return array(
		'sql'    => $sql,
		'params' => $params,
	);
}

/**
 * Drop the summary memo. Called when rows are removed (clear, prune).
 *
 * @return void
 */
function aafm_activity_summary_flush(): void {
	delete_transient( 'aafm_activity_summary' );
}

/**
 * The 7-day summary of agent tool calls: total, per-status counts, success rate, top five tools.
 *
 * Two bounded queries over the event_created index range, memoized for a minute. success_rate is
 * success / (success + error + denied) as a one-decimal percentage, null when that denominator is
 * zero (a call still in flight is neither a success nor a failure).
 *
 * @param bool $refresh Skip the memo.
 * @return array{ok:bool,since:string,total:int,success:int,error:int,denied:int,success_rate:?float,top_tools:list<array{ability:string,count:int}>}
 */
function aafm_activity_summary( bool $refresh = false ): array {
	global $wpdb;
	if ( ! $refresh ) {
		$memo = get_transient( 'aafm_activity_summary' );
		if ( is_array( $memo ) && isset( $memo['total'], $memo['top_tools'] ) ) {
			// Our own shape, written by the set_transient() below.
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort
			/** @var array{ok:bool,since:string,total:int,success:int,error:int,denied:int,success_rate:?float,top_tools:list<array{ability:string,count:int}>} $memo */
			return $memo;
		}
	}

	$since  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
	$table  = aafm_activity_log_table();
	$clause = aafm_agent_call_clause();
	$base   = array_merge( array( $table ), $clause['params'], array( $since ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$by_status = aafm_wpdb_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM %i WHERE ' . $clause['sql'] . ' AND created_at >= %s GROUP BY status', $base ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$top = aafm_wpdb_results( $wpdb->prepare( 'SELECT ability, COUNT(*) AS c FROM %i WHERE ' . $clause['sql'] . ' AND created_at >= %s GROUP BY ability ORDER BY c DESC, ability ASC LIMIT 5', $base ) );

	$out = array(
		'ok'           => $by_status['ok'] && $top['ok'],
		'since'        => $since,
		'total'        => 0,
		'success'      => 0,
		'error'        => 0,
		'denied'       => 0,
		'success_rate' => null,
		'top_tools'    => array(),
	);
	if ( ! $out['ok'] ) {
		return $out; // Never memoized: a failed read must not be served for a minute.
	}
	foreach ( (array) $by_status['value'] as $row ) {
		$count         = (int) $row['c'];
		$out['total'] += $count;
		if ( isset( $out[ (string) $row['status'] ] ) && in_array( (string) $row['status'], array( 'success', 'error', 'denied' ), true ) ) {
			$out[ (string) $row['status'] ] = $count;
		}
	}
	$finished = $out['success'] + $out['error'] + $out['denied'];
	if ( $finished > 0 ) {
		$out['success_rate'] = round( 100 * $out['success'] / $finished, 1 );
	}
	foreach ( (array) $top['value'] as $row ) {
		$out['top_tools'][] = array(
			'ability' => (string) $row['ability'],
			'count'   => (int) $row['c'],
		);
	}
	set_transient( 'aafm_activity_summary', $out, AAFM_ACTIVITY_SUMMARY_TTL );
	return $out;
}

/**
 * Resolve OAuth client ids to their names in one query.
 *
 * @param array<int,mixed> $client_ids Client ids (empty strings are ignored).
 * @return array<string,string> client_id => client_name for the ids that still exist.
 */
function aafm_activity_connection_names( array $client_ids ): array {
	global $wpdb;
	$ids = array();
	foreach ( $client_ids as $id ) {
		if ( is_scalar( $id ) && '' !== (string) $id ) {
			$ids[ (string) $id ] = (string) $id;
		}
	}
	if ( array() === $ids ) {
		return array();
	}
	$ids        = array_values( $ids );
	$suppressed = $wpdb->suppress_errors( true ); // Tolerate a not-yet-installed OAuth table.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$view = aafm_wpdb_results( $wpdb->prepare( 'SELECT client_id, client_name FROM %i WHERE client_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')', array_merge( array( $wpdb->prefix . 'aafm_oauth_clients' ), $ids ) ) );
	$wpdb->suppress_errors( $suppressed );

	$names = array();
	if ( $view['ok'] && is_array( $view['value'] ) ) {
		foreach ( $view['value'] as $row ) {
			$names[ (string) $row['client_id'] ] = (string) $row['client_name'];
		}
	}
	return $names;
}

/**
 * The Connection column text for one log row.
 *
 * @param array<string,mixed>  $row   A log row.
 * @param array<string,string> $names Result of aafm_activity_connection_names() for the page.
 * @return string
 */
function aafm_activity_connection_label( array $row, array $names ): string {
	$client = (string) ( $row['client_id'] ?? '' );
	if ( '' !== $client ) {
		if ( isset( $names[ $client ] ) && '' !== $names[ $client ] ) {
			return $names[ $client ];
		}
		/* translators: %s: the first eight characters of an OAuth client id whose app was removed. */
		return sprintf( __( 'Removed app (%s)', 'agent-abilities-for-mcp' ), substr( $client, 0, 8 ) );
	}
	$event   = (string) ( $row['event_type'] ?? 'ability_call' );
	$ability = (string) ( $row['ability'] ?? '' );
	if ( 'ability_call' === $event && 0 !== strpos( $ability, 'oauth:' ) && '(transport)' !== $ability && 'aafm/activity-log-cleared' !== $ability ) {
		return __( 'App Password or direct', 'agent-abilities-for-mcp' );
	}
	return '-';
}
