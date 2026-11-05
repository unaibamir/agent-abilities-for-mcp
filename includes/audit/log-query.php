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
 * The registered bridged plugins as filter keys: bridge:known-<id> for a known plugin with several
 * namespaces, bridge:ns-<namespace> for the rest.
 *
 * @return array<string,list<string>> Filter key => foreign slugs.
 */
function aafm_activity_bridge_groups(): array {
	if ( ! function_exists( 'aafm_bridge_foreign_slugs' ) ) {
		return array();
	}
	$prefix = aafm_bridge_merge_group_key_prefix();
	$out    = array();
	foreach ( aafm_bridge_group_slugs( aafm_bridge_foreign_slugs() ) as $key => $slugs ) {
		$key            = (string) $key;
		$is_known       = 0 === strncmp( $key, $prefix, strlen( $prefix ) );
		$filter         = $is_known ? 'bridge:known-' . substr( $key, strlen( $prefix ) ) : 'bridge:ns-' . $key;
		$out[ $filter ] = $slugs;
	}
	return $out;
}

/**
 * The exact wrapper tool names a bridge: filter key covers.
 *
 * A wrapper name two different plugins' slugs both normalize to (foo/bar-baz and foo-bar/baz) is left
 * out, because the audit row cannot say which plugin it belongs to. Such a row, and a row for a plugin
 * that is no longer registered, still appears under the Other plugins (Bridge) bucket.
 *
 * @param string $key A bridge: filter key.
 * @return list<string>
 */
function aafm_activity_bridge_tools( string $key ): array {
	$groups = aafm_activity_bridge_groups();
	if ( ! isset( $groups[ $key ] ) ) {
		return array();
	}

	$claimants = array();
	foreach ( $groups as $group => $slugs ) {
		foreach ( $slugs as $slug ) {
			$claimants[ aafm_bridge_tool_name( $slug ) ][ $group ] = true;
		}
	}

	$tools = array();
	foreach ( $groups[ $key ] as $slug ) {
		$name = aafm_bridge_tool_name( $slug );
		if ( 1 === count( $claimants[ $name ] ) ) {
			$tools[ $name ] = true;
		}
	}
	return array_map( 'strval', array_keys( $tools ) );
}

/**
 * The valid plugin-filter values. No labels and no plugin headers, so the query path stays cheap.
 *
 * @return list<string>
 */
function aafm_activity_plugin_keys(): array {
	$integrations = function_exists( 'aafm_integration_ability_order' ) ? array_keys( aafm_integration_ability_order() ) : array();
	return array_map( 'strval', array_merge( array( 'core', 'bridge' ), $integrations, array_keys( aafm_activity_bridge_groups() ) ) );
}

/**
 * The plugin-filter choices: slug => label. Only the dropdown calls this, because a bridged label can
 * read plugin headers.
 *
 * `core` is this plugin's own tools that belong to no integration, each integration slug is the
 * abilities listed for it in aafm_integration_ability_order(), `bridge` is every ability bridged from
 * another plugin, and a bridge: key is one plugin registered right now.
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

	$bridged = array();
	foreach ( aafm_activity_bridge_groups() as $key => $slugs ) {
		$ns   = aafm_bridge_namespace_of( (string) reset( $slugs ) );
		$name = aafm_bridge_provider_label( $ns );
		if ( '' === $name ) {
			/* translators: %s: an ability namespace, for example "writer". */
			$name = sprintf( __( '"%s" namespace', 'agent-abilities-for-mcp' ), $ns );
		}
		/* translators: %s: a plugin's name, or a namespace when the plugin is not known. */
		$bridged[ $key ] = sprintf( __( 'Other plugin: %s', 'agent-abilities-for-mcp' ), $name );
	}
	asort( $bridged );

	return $groups + $bridged;
}

/**
 * A strict Y-m-d date, or null.
 *
 * @param mixed $value Candidate.
 * @return string|null
 */
function aafm_activity_valid_date( $value ): ?string {
	if ( ! is_string( $value ) ) {
		return null;
	}
	// The shape check comes first: PHP 8 throws on a NUL byte in createFromFormat(), and \z (not $) refuses a trailing newline.
	if ( 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $value ) ) {
		return null;
	}
	// The round trip rejects 2024-02-30; year 0000 is not a date.
	$date = \DateTime::createFromFormat( '!Y-m-d', $value );
	return $date && $date->format( 'Y-m-d' ) === $value && '0000' !== substr( $value, 0, 4 ) ? $value : null;
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
	if ( isset( $raw['plugin'] ) && is_scalar( $raw['plugin'] ) && in_array( (string) $raw['plugin'], aafm_activity_plugin_keys(), true ) ) {
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
		} elseif ( 0 === strncmp( (string) $f['plugin'], 'bridge:', 7 ) ) {
			// Exact names, never a LIKE on a namespace: that would match ai-engine for ai.
			$tools = aafm_activity_bridge_tools( (string) $f['plugin'] );
			if ( array() === $tools ) {
				$sql .= ' AND 1=0';
			} else {
				$sql   .= ' AND ability IN (' . implode( ',', array_fill( 0, count( $tools ), '%s' ) ) . ')';
				$params = array_merge( $params, $tools );
			}
		} elseif ( 'core' === $f['plugin'] ) {
			$sql     .= ' AND ability LIKE %s';
			$params[] = $wpdb->esc_like( 'aafm/' ) . '%';
			$members  = array_merge( array(), ...array_values( array_map( static fn( array $rows ): array => array_column( $rows, 'name' ), $integration ) ) );
			if ( array() !== $members ) {
				$sql   .= ' AND ability NOT IN (' . implode( ',', array_fill( 0, count( $members ), '%s' ) ) . ')';
				$params = array_merge( $params, $members );
			}
		} elseif ( isset( $integration[ $f['plugin'] ] ) ) {
			$members = array_column( $integration[ $f['plugin'] ], 'name' );
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
		$sql     .= ' AND ( ability LIKE %s OR detail LIKE %s OR arg_keys LIKE %s OR principal_login LIKE %s OR client_id LIKE %s )';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}
	if ( isset( $f['hide_notifications'] ) ) {
		$sql     .= ' AND ability <> %s';
		$params[] = 'aafm/notifications';
		// Also the write-outcome rows about any aafm_notif* option (the settings and the alert bookkeeping),
		// which would give an agent the same view of the alert settings by another route.
		$sql     .= ' AND NOT ( ability = %s AND detail LIKE %s )';
		$params[] = 'aafm/write-outcome';
		$params[] = '%' . $wpdb->esc_like( '"key":"aafm_notif' ) . '%';
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
 * Count the rows a filter set matches. A failed query counts as 0.
 *
 * Routed through aafm_wpdb_scalar() rather than a bare get_var(), so a failed query cannot inherit an
 * earlier query's value and show a plausible but wrong number.
 *
 * @param array<string,mixed> $filters Filters.
 * @return int
 */
function aafm_activity_count_matching( array $filters ): int {
	global $wpdb;
	$where = aafm_activity_filter_where( $filters );
	// The fragment holds only fixed text and placeholders; every value is bound below.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$view = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where['sql'], array_merge( array( aafm_activity_log_table() ), $where['params'] ) ) );
	return $view['ok'] ? max( 0, (int) $view['value'] ) : 0;
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
		// A memo with fewer keys than we write now (an older release's) is a miss; success_rate may legitimately be null.
		if ( is_array( $memo ) && array() === array_diff_key( array_flip( array( 'ok', 'since', 'total', 'success', 'error', 'denied', 'success_rate', 'top_tools' ) ), $memo ) ) {
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
 * An app's name with the start of its client id after it. Apps choose their own names when they
 * register, so two can share one; the id is what tells them apart, and the Search box matches it.
 *
 * @param string $name      The registered name ('' falls back to the id alone).
 * @param string $client_id The OAuth client id.
 * @return string
 */
function aafm_activity_app_label( string $name, string $client_id ): string {
	$short = substr( $client_id, 0, 8 );
	if ( '' === $name ) {
		return $short;
	}
	/* translators: 1: the name an app registered under. 2: the first eight characters of its OAuth client id. */
	return sprintf( __( '%1$s (%2$s)', 'agent-abilities-for-mcp' ), $name, $short );
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
			return aafm_activity_app_label( $names[ $client ], $client );
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
