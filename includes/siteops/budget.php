<?php
/**
 * Site Ops: the site-wide purge budget.
 *
 * The activity log table is the counter and a call's own 'started' row is its reservation. No
 * option, no transient and no cache key is used, so a persistent object cache cannot serve a
 * stale count and a cache flush cannot reset it. The budget is shared by every connection.
 *
 * Each call has a fixed price: one unit for a post or URL purge, site_cost units for a whole-site
 * purge, whatever the number of cache plugins it reaches.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The budget settings, after the filter and the clamps.
 *
 * @return array{window:int,limit:int,site_cost:int,site_cooldown:int,wall_seconds:int,http_cap:int,http_timeout:int}
 */
function aafm_site_purge_budget_settings(): array {
	$defaults = array(
		'window'        => 600,
		'limit'         => 30,
		'site_cost'     => 10,
		'site_cooldown' => 900,
		'wall_seconds'  => 10,
		'http_cap'      => 8,
		'http_timeout'  => 3,
	);

	/**
	 * Filters the purge budget of the Site Ops cache tools.
	 *
	 * Every value is cast to an integer and clamped afterwards, so a filter can tune the budget
	 * and cannot switch it off: window 60 to 3600 seconds, limit 1 to 300 units, site_cost 1 to
	 * limit, site_cooldown 60 to 86400 seconds, wall_seconds 1 to 30, http_cap 1 to 32,
	 * http_timeout 1 to 10 seconds.
	 *
	 * @param array<string,int> $defaults The default settings.
	 */
	$filtered = apply_filters( 'aafm_site_purge_budget', $defaults );
	if ( ! is_array( $filtered ) ) {
		$filtered = array();
	}

	$value = static function ( string $key, int $min, int $max ) use ( $filtered, $defaults ): int {
		$raw = isset( $filtered[ $key ] ) && is_numeric( $filtered[ $key ] ) ? (int) $filtered[ $key ] : $defaults[ $key ];
		return max( $min, min( $max, $raw ) );
	};

	$limit = $value( 'limit', 1, 300 );

	return array(
		'window'        => $value( 'window', 60, 3600 ),
		'limit'         => $limit,
		'site_cost'     => $value( 'site_cost', 1, $limit ),
		'site_cooldown' => $value( 'site_cooldown', 60, 86400 ),
		'wall_seconds'  => $value( 'wall_seconds', 1, 30 ),
		'http_cap'      => $value( 'http_cap', 1, 32 ),
		'http_timeout'  => $value( 'http_timeout', 1, 10 ),
	);
}

/**
 * How many purge calls of each price sit inside the window. Rows count while they are started or
 * succeeded; a refused call resolves as an error and is not counted.
 *
 * @param int $window Window in seconds.
 * @return array{ok:bool,small:int,site:int} ok is false when a query failed.
 */
function aafm_site_purge_budget_counts( int $window ): array {
	global $wpdb;

	$cutoff = aafm_undo_cutoff( $window );
	$small  = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ability IN (%s, %s) AND status IN (%s, %s) AND created_at >= %s', aafm_activity_log_table(), 'aafm/site-purge-post-cache', 'aafm/site-purge-url-cache', 'started', 'success', $cutoff ) );
	$site   = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ability = %s AND status IN (%s, %s) AND created_at >= %s', aafm_activity_log_table(), 'aafm/site-purge-site-cache', 'started', 'success', $cutoff ) );

	return array(
		'ok'    => $small['ok'] && $site['ok'],
		'small' => (int) $small['value'],
		'site'  => (int) $site['value'],
	);
}

/**
 * Whether the purge call now running fits the budget.
 *
 * The call's own 'started' row is already in the count, so two calls racing at the limit each see
 * both rows and both are refused: the limit can be under-used in a race and never exceeded.
 *
 * @param string $op 'post' | 'url' | 'all'.
 * @return true|WP_Error aafm_site_purge_not_recorded | aafm_site_purge_budget | aafm_site_purge_cooldown | aafm_site_budget_unreadable.
 */
function aafm_site_purge_budget_check( string $op ) {
	if ( aafm_current_invocation_row_id() < 1 ) {
		return new WP_Error( 'aafm_site_purge_not_recorded', __( 'This call could not be recorded in the activity log, so the purge budget cannot account for it. Nothing was purged.', 'agent-abilities-for-mcp' ) );
	}

	$unreadable = new WP_Error( 'aafm_site_budget_unreadable', __( 'The purge budget could not be read. Nothing was purged.', 'agent-abilities-for-mcp' ) );
	$settings   = aafm_site_purge_budget_settings();
	$counts     = aafm_site_purge_budget_counts( $settings['window'] );
	if ( ! $counts['ok'] ) {
		return $unreadable;
	}

	$used = $counts['small'] + $counts['site'] * $settings['site_cost'];
	if ( $used > $settings['limit'] ) {
		return new WP_Error( 'aafm_site_purge_budget', __( 'The site-wide purge budget is used up for now. The site-cache-status tool shows how much is left. The cache plugin\'s own screen is not limited.', 'agent-abilities-for-mcp' ) );
	}

	if ( 'all' === $op ) {
		global $wpdb;
		$recent = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ability = %s AND status IN (%s, %s) AND created_at >= %s', aafm_activity_log_table(), 'aafm/site-purge-site-cache', 'started', 'success', aafm_undo_cutoff( $settings['site_cooldown'] ) ) );
		if ( ! $recent['ok'] ) {
			return $unreadable;
		}
		if ( (int) $recent['value'] > 1 ) {
			return new WP_Error( 'aafm_site_purge_cooldown', __( 'The whole page cache was purged a short time ago. The site-cache-status tool shows when a whole-site purge is allowed again.', 'agent-abilities-for-mcp' ) );
		}
	}

	return true;
}

/**
 * The budget as the next call will find it. For cache-status and the purge tools' output. Never
 * refuses.
 *
 * @return array{used:int,remaining:int,site_purge_available_in:int} used is -1 and the other two are 0 when a query failed.
 */
function aafm_site_purge_budget_state(): array {
	global $wpdb;

	$failed   = array(
		'used'                    => -1,
		'remaining'               => 0,
		'site_purge_available_in' => 0,
	);
	$settings = aafm_site_purge_budget_settings();
	$counts   = aafm_site_purge_budget_counts( $settings['window'] );
	if ( ! $counts['ok'] ) {
		return $failed;
	}

	$newest = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT MAX(created_at) FROM %i WHERE ability = %s AND status IN (%s, %s) AND created_at >= %s', aafm_activity_log_table(), 'aafm/site-purge-site-cache', 'started', 'success', aafm_undo_cutoff( $settings['site_cooldown'] ) ) );
	if ( ! $newest['ok'] ) {
		return $failed;
	}

	$available = 0;
	if ( is_string( $newest['value'] ) && '' !== $newest['value'] ) {
		$stamp = strtotime( $newest['value'] . ' UTC' );
		if ( false !== $stamp ) {
			$available = max( 0, $settings['site_cooldown'] - ( time() - $stamp ) );
		}
	}

	$used = $counts['small'] + $counts['site'] * $settings['site_cost'];

	return array(
		'used'                    => $used,
		'remaining'               => max( 0, $settings['limit'] - $used ),
		'site_purge_available_in' => $available,
	);
}
