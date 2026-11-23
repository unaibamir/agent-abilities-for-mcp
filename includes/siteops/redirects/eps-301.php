<?php
/**
 * Redirect adapter: 301 Redirects by EPS (eps-301-redirects), read only. Benched on 2.85.
 *
 * Defines the five adapter functions and nothing else. Every function is called through the
 * isolation wrapper, which empties the request superglobals this plugin's reader looks at.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether the plugin's class and its table are present.
 *
 * @return bool
 */
function aafm_redirect_eps_301_ready(): bool {
	global $wpdb;

	return class_exists( 'EPS_Redirects', false )
		&& is_callable( array( 'EPS_Redirects', 'get_redirects' ) )
		&& aafm_siteops_table_exists( $wpdb->prefix . 'redirects' );
}

/**
 * Whether the current user holds the plugin's own capability.
 *
 * @param string $action 'read' or 'read_404'.
 * @return bool
 */
function aafm_redirect_eps_301_can( string $action ): bool {
	if ( 'read' !== $action && 'read_404' !== $action ) {
		return false;
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own capability filter, applied as its own screens apply it.
	$capability = apply_filters( 'eps_301_redirects_capability', 'manage_options' );

	return is_string( $capability ) && current_user_can( $capability );
}

/**
 * Every rule, normalised. At most 5,000.
 *
 * @return array{rows:array<int,array<string,mixed>>,truncated:bool}
 * @throws \RuntimeException When the plugin's reader returns something other than a list.
 */
function aafm_redirect_eps_301_all(): array {
	$rows = EPS_Redirects::get_redirects( false );
	if ( ! is_array( $rows ) ) {
		throw new \RuntimeException( 'aafm_redirect_eps_301_all' );
	}

	$truncated = count( $rows ) > 5000;
	if ( $truncated ) {
		$rows = array_slice( $rows, 0, 5000 );
	}

	$out = array();
	foreach ( $rows as $row ) {
		if ( ! is_object( $row ) || ! isset( $row->id, $row->url_from, $row->url_to, $row->status ) ) {
			continue;
		}

		// On insert the plugin replaces a target it can resolve with the post id.
		$target = (string) $row->url_to;
		if ( is_numeric( $row->url_to ) ) {
			$permalink = get_permalink( (int) $row->url_to );
			if ( is_string( $permalink ) ) {
				$target = $permalink;
			}
		}

		// While a rule is off the plugin keeps the word 'inactive' where the status code was.
		$enabled = 'inactive' !== $row->status;
		$type    = isset( $row->type ) ? (string) $row->type : '';

		$out[] = array(
			'id'      => (string) $row->id,
			'source'  => '/' . ltrim( (string) $row->url_from, '/' ),
			'target'  => $target,
			'code'    => $enabled ? (int) $row->status : 0,
			'enabled' => $enabled,
			'kind'    => in_array( $type, array( 'url', 'post' ), true ) ? 'exact' : 'pattern',
			'hits'    => isset( $row->count ) ? (int) $row->count : 0,
		);
	}

	return array(
		'rows'      => $out,
		'truncated' => $truncated,
	);
}

/**
 * Which rule a path would hit. The plugin has no match function, so this is its comparison:
 * the whole request against each active rule, newest first. No counter is changed.
 *
 * @param string              $path  Full canonical decoded path, home path included.
 * @param array<string,mixed> $cache Per-call cache; holds the active rows after the first call.
 * @return array<string,mixed> A match shape. Every rule of this plugin is an exact comparison, so it is never 'unknown'.
 */
function aafm_redirect_eps_301_match( string $path, array &$cache ): array {
	if ( ! isset( $cache['rows'] ) ) {
		// Active rows, newest first: the order the front end uses.
		$rows          = EPS_Redirects::get_redirects( true );
		$cache['rows'] = is_array( $rows ) ? $rows : array();
	}

	$req = strtolower( rtrim( trim( $path ), '/' ) );
	// The plugin compares below the home path.
	$base = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	foreach ( (array) $cache['rows'] as $r ) {
		if ( ! is_object( $r ) || ! isset( $r->url_from, $r->url_to, $r->status, $r->id ) ) {
			continue;
		}
		$from = strtolower( rtrim( $base . '/' . trim( urldecode( html_entity_decode( (string) $r->url_from ) ) ), '/' ) );
		if ( $req !== $from ) {
			continue;
		}
		$to = ( 'url' === ( $r->type ?? '' ) && ! is_numeric( $r->url_to ) )
			? urldecode( html_entity_decode( (string) $r->url_to ) )
			: (string) get_permalink( (int) $r->url_to );

		return array(
			'state'  => 'matched',
			'id'     => (string) $r->id,
			'target' => $to,
			'code'   => (int) $r->status,
		);
	}

	return array( 'state' => 'no_match' );
}

/**
 * The plugin's not-found log, grouped by address. The log is one option of at most 50 entries.
 * The browser name it stores is never read into the result.
 *
 * @return array<int,array{path:string,count:int,last_seen:int|null}>
 */
function aafm_redirect_eps_301_list_404s(): array {
	$log = get_option( 'eps_redirects_404_log' );
	if ( ! is_array( $log ) ) {
		return array();
	}

	// The plugin stores a site-local timestamp.
	$offset  = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	$grouped = array();
	foreach ( $log as $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['url'] ) || ! is_string( $entry['url'] ) ) {
			continue;
		}
		$url  = $entry['url'];
		$seen = ( isset( $entry['timestamp'] ) && is_numeric( $entry['timestamp'] ) && (int) $entry['timestamp'] > 0 ) ? (int) $entry['timestamp'] - $offset : null;
		if ( ! isset( $grouped[ $url ] ) ) {
			$grouped[ $url ] = array(
				'path'      => $url,
				'count'     => 0,
				'last_seen' => null,
			);
		}
		++$grouped[ $url ]['count'];
		if ( null !== $seen && ( null === $grouped[ $url ]['last_seen'] || $seen > $grouped[ $url ]['last_seen'] ) ) {
			$grouped[ $url ]['last_seen'] = $seen;
		}
	}

	return array_values( $grouped );
}
