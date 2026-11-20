<?php
/**
 * Redirect adapter: Rank Math (seo-by-rank-math) Redirections and 404 Monitor, read only. Benched
 * on 1.0.282.
 *
 * Defines the five adapter functions and nothing else. The plugin's own matcher reports a match
 * for REST, login and admin paths that the front end never redirects; the tool's protected_path
 * flag covers that.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether the Redirections module is running. The module check, not the stored module list: a
 * fresh install that has neither connected nor skipped registration loads no module.
 *
 * @return bool
 */
function aafm_redirect_rankmath_ready(): bool {
	return class_exists( 'RankMath\Helper' )
		&& class_exists( 'RankMath\Redirections\DB' )
		&& class_exists( 'RankMath\Redirections\Redirection' )
		&& is_callable( array( 'RankMath\Helper', 'is_module_active' ) )
		&& is_callable( array( 'RankMath\Helper', 'get_settings' ) )
		&& is_callable( array( 'RankMath\Redirections\DB', 'get_redirections' ) )
		&& is_callable( array( 'RankMath\Redirections\DB', 'match_redirections' ) )
		&& is_callable( array( 'RankMath\Redirections\Redirection', 'strip_subdirectory' ) )
		&& true === \RankMath\Helper::is_module_active( 'redirections' );
}

/**
 * Whether the current user holds the plugin's own capability.
 *
 * @param string $action 'read' or 'read_404'.
 * @return bool
 */
function aafm_redirect_rankmath_can( string $action ): bool {
	if ( 'read' === $action ) {
		return current_user_can( 'rank_math_redirections' );
	}
	if ( 'read_404' === $action ) {
		return current_user_can( 'rank_math_404_monitor' ) && true === \RankMath\Helper::is_module_active( '404-monitor' );
	}

	return false;
}

/**
 * Every rule that is not in the trash, normalised: the active ones, then the inactive ones. At
 * most 25 pages of 200 across the two.
 *
 * @return array{rows:array<int,array<string,mixed>>,truncated:bool}
 * @throws \RuntimeException When the plugin's reader does not return its { redirections, count } wrapper.
 */
function aafm_redirect_rankmath_all(): array {
	$rows      = array();
	$truncated = false;
	$pages     = 0;
	foreach ( array( 'active', 'inactive' ) as $status ) {
		$paged = 1;
		while ( $pages < 25 ) {
			$result = \RankMath\Redirections\DB::get_redirections(
				array(
					'limit'  => 200,
					'paged'  => $paged,
					'order'  => 'ASC',
					'status' => $status,
				)
			);
			++$pages;
			if ( ! is_array( $result ) || ! isset( $result['redirections'] ) || ! is_array( $result['redirections'] ) ) {
				throw new \RuntimeException( 'aafm_redirect_rankmath_all' );
			}

			foreach ( $result['redirections'] as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
					continue;
				}

				// The list reader returns the sources column as stored; the plugin's own matcher unserializes it the same way.
				$sources = isset( $row['sources'] ) ? maybe_unserialize( $row['sources'] ) : null;
				$first   = ( is_array( $sources ) && isset( $sources[0] ) && is_array( $sources[0] ) ) ? $sources[0] : array();
				$pattern = isset( $first['pattern'] ) && is_scalar( $first['pattern'] ) ? (string) $first['pattern'] : '';
				$exact   = is_array( $sources ) && 1 === count( $sources ) && isset( $first['comparison'] ) && 'exact' === $first['comparison'];

				$rows[] = array(
					'id'      => (string) $row['id'],
					'source'  => $exact ? '/' . ltrim( $pattern, '/' ) : $pattern,
					'target'  => isset( $row['url_to'] ) ? (string) $row['url_to'] : '',
					'code'    => isset( $row['header_code'] ) ? (int) $row['header_code'] : 0,
					'enabled' => isset( $row['status'] ) && 'active' === $row['status'],
					'kind'    => $exact ? 'exact' : 'pattern',
					'hits'    => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
				);
			}

			if ( count( $result['redirections'] ) < 200 ) {
				break;
			}
			if ( 25 === $pages ) {
				$truncated = true;
			}
			++$paged;
		}
	}

	return array(
		'rows'      => $rows,
		'truncated' => $truncated,
	);
}

/**
 * Which rule a path would hit, through the plugin's own matcher, which evaluates every
 * comparison type itself and changes no counter.
 *
 * The path first goes through the plugin's own strip_subdirectory(), as every real request does
 * (Redirection::get_full_uri()). On a site in a subfolder that removes the home directory name
 * and its slash wherever they occur, not only in front: on a site at /sub the request
 * /sub/sub/old-b reaches the matcher as old-b, so a rule stored as sub/old-b never fires.
 *
 * The plugin also redirects without a rule, and none of that can be decided from a path alone,
 * so each case answers 'unknown' with a reason (checked against 1.0.282, class-redirector.php and
 * frontend/class-redirection.php):
 * - a callback on rank_math/redirection/pre_search other than the plugin's own front-end class
 *   runs before the rules and can redirect any path (its WooCommerce base removal adds one);
 * - its own front-end class redirects attachment pages ('general.attachment_redirect_urls', on
 *   when missing) and switched-off date or author archives, before the rules; only a path no
 *   rule matches is reported for these, so an attachment page that also has a rule is a limit;
 * - the 404 fallback ('general.redirections_fallback' homepage, or custom with an address) runs
 *   after the rules on a page that is not found.
 *
 * @param string              $path  The full path, home path included (the registry's match_path is full).
 * @param array<string,mixed> $cache Per-call cache; not used by this adapter.
 * @return array<string,mixed> A match shape.
 */
function aafm_redirect_rankmath_match( string $path, array &$cache ): array {
	unset( $cache );
	global $wp_filter;
	$hook = $wp_filter['rank_math/redirection/pre_search'] ?? null;
	if ( $hook instanceof WP_Hook ) {
		foreach ( $hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( ! ( is_array( $function ) && isset( $function[0] ) && is_object( $function[0] ) && is_a( $function[0], 'RankMath\Frontend\Redirection' ) ) ) {
					return array(
						'state'  => 'unknown',
						'reason' => 'rank_math_pre_search_filter',
					);
				}
			}
		}
	}

	$found = \RankMath\Redirections\DB::match_redirections( trim( \RankMath\Redirections\Redirection::strip_subdirectory( $path ), '/' ) );
	if ( is_array( $found ) && isset( $found['id'] ) ) {
		return array(
			'state'  => 'matched',
			'id'     => (string) $found['id'],
			'target' => isset( $found['url_to'] ) ? (string) $found['url_to'] : '',
			'code'   => isset( $found['header_code'] ) ? (int) $found['header_code'] : 0,
		);
	}

	$reason = '';
	if ( \RankMath\Helper::get_settings( 'general.attachment_redirect_urls', true ) ) {
		$reason = 'rank_math_attachment_redirect';
	} elseif ( \RankMath\Helper::get_settings( 'titles.disable_date_archives' ) || \RankMath\Helper::get_settings( 'titles.disable_author_archives' ) ) {
		$reason = 'rank_math_archive_redirect';
	} else {
		$fallback = \RankMath\Helper::get_settings( 'general.redirections_fallback', 'default' );
		if ( 'homepage' === $fallback || ( 'custom' === $fallback && '' !== (string) \RankMath\Helper::get_settings( 'general.redirections_custom_url', '' ) ) ) {
			$reason = 'rank_math_404_fallback';
		}
	}

	return '' === $reason
		? array( 'state' => 'no_match' )
		: array(
			'state'  => 'unknown',
			'reason' => $reason,
		);
}

/**
 * The 404 Monitor log, grouped by address. The referring address and the browser name the plugin
 * can store are never selected.
 *
 * @return array<int,array{path:string,count:int,last_seen:int|null}>
 * @throws \RuntimeException When the log table cannot be read.
 */
function aafm_redirect_rankmath_list_404s(): array {
	global $wpdb;

	$result = aafm_wpdb_results(
		(string) $wpdb->prepare(
			'SELECT uri, SUM(times_accessed) AS hits, MAX(accessed) AS last_seen FROM %i GROUP BY uri ORDER BY hits DESC LIMIT 200',
			$wpdb->prefix . 'rank_math_404_logs'
		)
	);
	if ( ! $result['ok'] || ! is_array( $result['value'] ) ) {
		throw new \RuntimeException( 'aafm_redirect_rankmath_list_404s' );
	}

	$out = array();
	foreach ( $result['value'] as $row ) {
		if ( ! isset( $row['uri'] ) || ! is_scalar( $row['uri'] ) ) {
			continue;
		}
		$out[] = array(
			'path'      => (string) $row['uri'],
			'count'     => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
			// The plugin writes this column in site-local time.
			'last_seen' => aafm_site_local_mysql_to_utc( $row['last_seen'] ?? null ),
		);
	}

	return $out;
}
