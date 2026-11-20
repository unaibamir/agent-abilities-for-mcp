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
 * The plugin also redirects from its settings, checked against 1.0.282, in its own order:
 * - with 'general.strip_category_base' on, its request filter sends any address under the old
 *   category base to the same address without it while WordPress parses the request, before
 *   everything below (class-rewrite.php:34-37, :146-154, :168); the setting is read here and the
 *   variable from the rewrite rule, because the plugin's query variable is public only once a
 *   request has been parsed;
 * - a callback on rank_math/redirection/pre_search other than the plugin's own front-end class
 *   runs before the rules and can redirect any path (its WooCommerce base removal adds one):
 *   unknown;
 * - its own front-end class, also before the rules, sends an attachment page to its parent post
 *   or to the default address ('general.attachment_redirect_urls', on when missing), and a
 *   switched-off date or author archive to the home page, with 301; with the Redirections module
 *   off it does the same itself on wp (frontend/class-redirection.php:28-35), and the rules and
 *   the fallback do not run;
 * - after the rules, the 404 fallback ('general.redirections_fallback' homepage, or custom with
 *   an address) sends a page not found to that address with the code of
 *   'general.redirections_header_code', except login, admin and dashboard;
 * - on template_redirect, the sitemap module sends WordPress's own sitemap addresses and a
 *   /sitemap.xml not found to its own sitemaps (sitemap/class-redirect-core-sitemaps.php:40-90,
 *   class-router.php:106-124): unknown.
 * Without a connected or skipped registration the plugin loads none of this. Which kind of page a
 * path is comes from aafm_site_redirect_path_class(), asked only while one of these settings is
 * on; a page it cannot classify gives unknown with the setting's reason, and so do, for the
 * fallback, a term archive (it may be a page not found) and a page not found that other plugin
 * code could serve. A target whose spelling depends on the escapes a browser sends gives unknown
 * too.
 *
 * @param string              $path  The full path, home path included (the registry's match_path is full).
 * @param array<string,mixed> $cache Per-call cache; not used by this adapter.
 * @return array<string,mixed> A match shape.
 */
function aafm_redirect_rankmath_match( string $path, array &$cache ): array {
	unset( $cache );
	global $wp_filter;
	if ( true === \RankMath\Helper::is_invalid_registration() ) {
		return array( 'state' => 'no_match' );
	}
	$module = true === \RankMath\Helper::is_module_active( 'redirections' );

	$strip       = (bool) \RankMath\Helper::get_settings( 'general.strip_category_base' );
	$attachments = (bool) \RankMath\Helper::get_settings( 'general.attachment_redirect_urls', true );
	$dates       = (bool) \RankMath\Helper::get_settings( 'titles.disable_date_archives' );
	$authors     = true === \RankMath\Helper::get_settings( 'titles.disable_author_archives' );
	$class       = null;
	if ( $strip ) {
		$class = aafm_site_redirect_path_class( $path, true );
		if ( 'unclassified' === $class['class'] && array() === $class['vars'] ) {
			// No rewrite rule could be followed, so the old base cannot be told apart.
			return array(
				'state'  => 'unknown',
				'reason' => 'rank_math_category_redirect',
			);
		}
		if ( isset( $class['vars']['rank_math_category_redirect'] ) ) {
			$value = (string) $class['vars']['rank_math_category_redirect'];
			if ( implode( '/', array_map( 'rawurlencode', explode( '/', $value ) ) ) !== $value ) {
				return array(
					'state'  => 'unknown',
					'reason' => 'rank_math_category_redirect',
				);
			}
			return array(
				'state'   => 'matched',
				'id'      => '',
				'target'  => trailingslashit( (string) get_option( 'home' ) ) . user_trailingslashit( $value, 'category' ),
				'code'    => 301,
				'setting' => 'rank_math_category_redirect',
			);
		}
	}

	$hook = $wp_filter['rank_math/redirection/pre_search'] ?? null;
	if ( $module && $hook instanceof WP_Hook ) {
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

	if ( $attachments || $dates || $authors ) {
		$class = $class ?? aafm_site_redirect_path_class( $path );
		if ( 'unclassified' === $class['class'] ) {
			return array(
				'state'  => 'unknown',
				'reason' => $attachments ? 'rank_math_attachment_redirect' : 'rank_math_archive_redirect',
			);
		}
		if ( 'attachment' === $class['class'] && $attachments ) {
			$to = (int) $class['parent'] > 0 ? get_permalink( (int) $class['parent'] ) : \RankMath\Helper::get_settings( 'general.attachment_redirect_default' );
			if ( is_string( $to ) && '' !== $to ) {
				if ( false !== has_filter( 'rank_math/frontend/attachment/redirect_url' ) ) {
					return array(
						'state'  => 'unknown',
						'reason' => 'rank_math_attachment_redirect',
					);
				}
				return array(
					'state'   => 'matched',
					'id'      => '',
					'target'  => $to,
					'code'    => 301,
					'setting' => 'rank_math_attachment_redirect',
				);
			}
		} elseif ( ( 'date' === $class['class'] && $dates ) || ( 'author' === $class['class'] && $authors ) ) {
			return array(
				'state'   => 'matched',
				'id'      => '',
				'target'  => (string) get_bloginfo( 'url' ),
				'code'    => 301,
				'setting' => 'rank_math_archive_redirect',
			);
		}
	}

	if ( $module ) {
		$uri   = trim( (string) \RankMath\Redirections\Redirection::strip_subdirectory( $path ), '/' );
		$found = \RankMath\Redirections\DB::match_redirections( $uri );
		if ( is_array( $found ) && isset( $found['id'] ) ) {
			return array(
				'state'  => 'matched',
				'id'     => (string) $found['id'],
				'target' => isset( $found['url_to'] ) ? (string) $found['url_to'] : '',
				'code'   => isset( $found['header_code'] ) ? (int) $found['header_code'] : 0,
			);
		}

		$fallback = \RankMath\Helper::get_settings( 'general.redirections_fallback' );
		$to       = '';
		if ( 'homepage' === $fallback ) {
			$to = home_url();
		} elseif ( 'default' !== $fallback ) {
			$to = (string) \RankMath\Helper::get_settings( 'general.redirections_custom_url' );
		}
		if ( '' !== $to && '' !== $uri ) {
			if ( false !== has_filter( 'rank_math/redirection/fallback_exclude_locations' ) ) {
				return array(
					'state'  => 'unknown',
					'reason' => 'rank_math_404_fallback',
				);
			}
			if ( ! in_array( $uri, array( 'login', 'admin', 'dashboard' ), true ) ) {
				$class = $class ?? aafm_site_redirect_path_class( $path );
				if ( 'unclassified' === $class['class'] || 'term' === $class['class'] || ! empty( $class['hooked'] ) ) {
					return array(
						'state'  => 'unknown',
						'reason' => 'rank_math_404_fallback',
					);
				}
				if ( 'not_found' === $class['class'] ) {
					return array(
						'state'   => 'matched',
						'id'      => '',
						'target'  => $to,
						'code'    => absint( \RankMath\Helper::get_settings( 'general.redirections_header_code' ) ),
						'setting' => 'rank_math_404_fallback',
					);
				}
			}
		}
	}

	if ( true === \RankMath\Helper::is_module_active( 'sitemap' ) && ( 0 === strpos( $path, '/wp-sitemap' ) || aafm_site_redirect_home_path() . '/sitemap.xml' === $path ) ) {
		return array(
			'state'  => 'unknown',
			'reason' => 'rank_math_sitemap_redirect',
		);
	}

	return array( 'state' => 'no_match' );
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
