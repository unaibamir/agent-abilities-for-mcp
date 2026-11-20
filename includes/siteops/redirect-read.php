<?php
/**
 * Site Ops redirect read helpers: the value rules for stored redirect text and for not-found
 * paths, the stored-target model, the protected path list, the chain follower and the path
 * classifier that the adapters use for the redirects a plugin makes from a setting.
 *
 * Nothing here writes, and nothing here gates a write. Loaded on first use by
 * aafm_siteops_redirect_load().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * A string cut to at most $bytes bytes without splitting a UTF-8 character.
 *
 * @param string $s     The string.
 * @param int    $bytes Maximum length in bytes.
 * @return string
 */
function aafm_siteops_utf8_cut( string $s, int $bytes ): string {
	if ( strlen( $s ) <= $bytes ) {
		return $s;
	}
	if ( function_exists( 'mb_strcut' ) ) {
		return mb_strcut( $s, 0, $bytes, 'UTF-8' );
	}

	return aafm_siteops_utf8_cut_bytes( $s, $bytes );
}

/**
 * The cut of aafm_siteops_utf8_cut() for a host without the mbstring extension.
 *
 * @param string $s     The string.
 * @param int    $bytes Maximum length in bytes.
 * @return string
 */
function aafm_siteops_utf8_cut_bytes( string $s, int $bytes ): string {
	$cut = substr( $s, 0, max( 0, $bytes ) );
	if ( 1 !== preg_match( '//u', $cut ) ) {
		$cut = (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut );
	}

	return $cut;
}

/**
 * A string with its control characters (bytes 0x00 to 0x1F and 0x7F) removed.
 *
 * @param string $s The string.
 * @return string
 */
function aafm_site_redirect_strip_controls( string $s ): string {
	return (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $s );
}

/**
 * A string cut before its first '?' or '#'.
 *
 * @param string $s The string.
 * @return string
 */
function aafm_site_redirect_cut_at_query( string $s ): string {
	return substr( $s, 0, strcspn( $s, '?#' ) );
}

/**
 * A stored redirect target made safe to output: no control characters, no sign-in details, no
 * query string or fragment, at most 400 bytes.
 *
 * @param string $target The stored target.
 * @return array{0:string,1:bool} The target, and whether anything other than control characters was removed or cut.
 */
function aafm_site_redirect_output_target( string $target ): array {
	// Leading white space would hide the scheme from the anchored user information rule below.
	$clean   = ltrim( aafm_site_redirect_strip_controls( $target ) );
	$trimmed = false;

	// Everything between the '//' and the last '@' before the path is user information.
	$without_user = (string) preg_replace( '#^([A-Za-z][A-Za-z0-9+.\-]*:)?//[^/?\#]*@#', '$1//', $clean );
	if ( $without_user !== $clean ) {
		$clean   = $without_user;
		$trimmed = true;
	}

	$without_query = aafm_site_redirect_cut_at_query( $clean );
	if ( $without_query !== $clean ) {
		$clean   = $without_query;
		$trimmed = true;
	}

	$short = aafm_siteops_utf8_cut( $clean, 400 );
	if ( $short !== $clean ) {
		$trimmed = true;
	}

	return array( $short, $trimmed );
}

/**
 * A normalised rule with its source and target made safe to output.
 *
 * @param array<string,mixed> $rule The normalised rule an adapter returned, with 'provider' set.
 * @return array<string,mixed> The rule in the output shape, plus 'target_trimmed'.
 */
function aafm_site_redirect_output_rule( array $rule ): array {
	$kind   = ( isset( $rule['kind'] ) && 'exact' === $rule['kind'] ) ? 'exact' : 'pattern';
	$source = aafm_site_redirect_strip_controls( isset( $rule['source'] ) && is_scalar( $rule['source'] ) ? (string) $rule['source'] : '' );
	if ( 'exact' === $kind ) {
		$source = aafm_site_redirect_cut_at_query( $source );
	}
	$source = aafm_siteops_utf8_cut( $source, 400 );

	list( $target, $trimmed ) = aafm_site_redirect_output_target( isset( $rule['target'] ) && is_scalar( $rule['target'] ) ? (string) $rule['target'] : '' );

	$code = isset( $rule['code'] ) && is_numeric( $rule['code'] ) ? (int) $rule['code'] : 0;
	if ( ( $code < 300 || $code > 399 ) && ! in_array( $code, array( 403, 404, 410, 451 ), true ) ) {
		$code = 0;
	}

	return array(
		'provider'       => isset( $rule['provider'] ) && is_scalar( $rule['provider'] ) ? (string) $rule['provider'] : '',
		'id'             => isset( $rule['id'] ) && is_scalar( $rule['id'] ) ? (string) $rule['id'] : '',
		'source'         => $source,
		'target'         => $target,
		'target_trimmed' => $trimmed,
		'code'           => $code,
		'enabled'        => ! empty( $rule['enabled'] ),
		'kind'           => $kind,
		'hits'           => ( isset( $rule['hits'] ) && is_numeric( $rule['hits'] ) ) ? (int) $rule['hits'] : null,
	);
}

/**
 * Comparison key for a path: lower case, no trailing slash. Two paths with the same key are
 * treated as the same page when the chain follower looks for a loop.
 *
 * @param string $path A decoded path.
 * @return string
 */
function aafm_site_redirect_key( string $path ): string {
	$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
	$lower = rtrim( $lower, '/' );

	return '' === $lower ? '/' : $lower;
}

/**
 * The canonical decoded path of a stored redirect target, when it is a page of this site.
 *
 * @param string $stored           The target as the provider stores it.
 * @param string $relative_targets 'host_root' or 'home': what a target without a scheme is relative to.
 * @return array{path:?string,had_query:bool} path is null when the target leaves the site or cannot be modelled.
 */
function aafm_site_redirect_target_path( string $stored, string $relative_targets = 'host_root' ): array {
	$value     = trim( $stored );
	$had_query = false !== strpbrk( $value, '?#' );
	if ( $had_query ) {
		$value = aafm_site_redirect_cut_at_query( $value );
	}
	if ( '' === $value || 0 === strpos( $value, '//' ) ) {
		return array(
			'path'      => null,
			'had_query' => $had_query,
		);
	}

	if ( false === strpos( $value, '://' ) ) {
		if ( 'home' === $relative_targets ) {
			$value = aafm_site_redirect_home_path() . '/' . ltrim( $value, '/' );
		} elseif ( '/' !== $value[0] ) {
			$value = '/' . $value;
		}
	}

	$canonical = aafm_siteops_canonical_url( $value );

	return array(
		'path'      => is_wp_error( $canonical ) ? null : (string) $canonical['path'],
		'had_query' => $had_query,
	);
}

/**
 * The protected path keys: paths a redirect should not take over. In this release the list only
 * sets the protected_path flag of aafm/site-test-redirect.
 *
 * Built from the home path, the site path (WordPress in its own directory) and the paths of the
 * content, includes and admin URLs. A filter can add entries and cannot remove a built-in one.
 *
 * @return list<string> Keys, each starting with '/'.
 */
function aafm_site_redirect_protected_paths(): array {
	$home      = aafm_site_redirect_home_path();
	$site_path = wp_parse_url( site_url( '/' ), PHP_URL_PATH );
	$site      = is_string( $site_path ) ? untrailingslashit( $site_path ) : '';
	$rest      = '/' . trim( rest_get_url_prefix(), '/' );

	$builtin = array( '' === $home ? '/' : $home );
	foreach ( array( $rest, '/.well-known', '/oauth', '/index.php' ) as $tail ) {
		$builtin[] = $home . $tail;
	}
	foreach ( array( '/wp-login.php', '/wp-admin', '/wp-cron.php', '/xmlrpc.php', '/wp-includes', '/wp-signup.php', '/wp-activate.php', '/wp-comments-post.php', '/index.php', $rest ) as $tail ) {
		$builtin[] = $site . $tail;
	}
	foreach ( array( content_url(), includes_url(), admin_url() ) as $url ) {
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $url_path ) && '' !== $url_path ) {
			$builtin[] = $url_path;
		}
	}

	/**
	 * Filters the protected path list. Entries can be added; the built-in ones are re-added afterwards.
	 *
	 * @param list<string> $paths Paths, each starting with '/'.
	 */
	$filtered = apply_filters( 'aafm_site_redirect_protected_paths', $builtin );

	$keys = array();
	foreach ( array_merge( is_array( $filtered ) ? $filtered : array(), $builtin ) as $path ) {
		if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] ) {
			continue;
		}
		$key = aafm_site_redirect_key( $path );
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
		}
	}

	return $keys;
}

/**
 * Whether a canonical path is on the protected list.
 *
 * @param string $path A canonical decoded path.
 * @return bool
 */
function aafm_site_redirect_is_protected( string $path ): bool {
	$key       = aafm_site_redirect_key( $path );
	$home      = aafm_site_redirect_home_path();
	$home_key  = aafm_site_redirect_key( '' === $home ? '/' : $home );
	$site_path = wp_parse_url( site_url( '/' ), PHP_URL_PATH );
	$site      = is_string( $site_path ) ? untrailingslashit( $site_path ) : '';

	foreach ( aafm_site_redirect_protected_paths() as $entry ) {
		if ( $key === $entry ) {
			return true;
		}
		// The home page entry matches by equality only, or every path would be protected.
		if ( $entry !== $home_key && 0 === strpos( $key, $entry . '/' ) ) {
			return true;
		}
	}

	foreach ( array( $home, $site ) as $base ) {
		$base_key = '' === $base ? '' : aafm_site_redirect_key( $base );
		if ( '' !== $base_key && 0 !== strpos( $key . '/', $base_key . '/' ) ) {
			continue;
		}
		$first = ltrim( (string) substr( $key, strlen( $base_key ) ), '/' );
		if ( 0 === strpos( $first, 'wp-' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Follow the stored redirects from one path, for up to ten hops.
 *
 * Every ready provider is asked at every step, in registry order. A hop is the first provider
 * that answers 'matched', provided no provider before it answered 'unknown': an unevaluated rule
 * is never read as "no redirect". A provider whose own capability the caller lacks answers
 * 'unknown'.
 *
 * @param string            $path        Full canonical decoded path.
 * @param array<int,string> $ready_slugs The value of aafm_site_redirect_ready_slugs(), computed once by the calling tool.
 * @return array{evaluated:list<array<string,string>>,first_match:?array<string,mixed>,chain:list<array<string,mixed>>,chain_ends:string,loop_detected:bool,approximate:bool}
 */
function aafm_site_redirect_chain( string $path, array $ready_slugs ): array {
	$adapters = aafm_site_redirect_adapters();
	$slugs    = array();
	$denied   = array();
	$cache    = array();
	foreach ( array_keys( $adapters ) as $slug ) {
		$slug = (string) $slug;
		if ( ! in_array( $slug, $ready_slugs, true ) ) {
			continue;
		}
		$slugs[]         = $slug;
		$cache[ $slug ]  = array();
		$denied[ $slug ] = true !== aafm_site_redirect_call( $slug, 'can', array( 'read' ) );
	}

	$seen        = array( aafm_site_redirect_key( $path ) );
	$current     = $path;
	$evaluated   = array();
	$chain       = array();
	$first_match = null;
	$chain_ends  = 'no_match';
	$loop        = false;
	$approximate = false;
	$first_step  = true;

	while ( true ) {
		if ( count( $chain ) >= 10 ) {
			$chain_ends = 'too_long';
			break;
		}

		$answers = array();
		foreach ( $slugs as $slug ) {
			$answers[ $slug ] = $denied[ $slug ] ? array( 'state' => 'unknown' ) : aafm_site_redirect_match( $slug, $current, $cache[ $slug ] );
		}

		if ( $first_step ) {
			$first_step = false;
			foreach ( $answers as $slug => $answer ) {
				$row = array(
					'provider' => $slug,
					'label'    => isset( $adapters[ $slug ]['label'] ) && is_string( $adapters[ $slug ]['label'] ) ? $adapters[ $slug ]['label'] : $slug,
					'result'   => (string) $answer['state'],
				);
				if ( isset( $answer['reason'] ) ) {
					// The plugin setting or filter that made this provider's answer unknown.
					$row['reason'] = (string) $answer['reason'];
				}
				$evaluated[] = $row;
			}
		}

		$hop     = null;
		$blocked = false;
		foreach ( $answers as $slug => $answer ) {
			if ( 'unknown' === $answer['state'] ) {
				$blocked = true;
				break;
			}
			if ( 'matched' === $answer['state'] ) {
				$hop = array(
					'provider' => $slug,
					'id'       => (string) $answer['id'],
					'from'     => $current,
					'target'   => (string) $answer['target'],
					'code'     => (int) $answer['code'],
				);
				if ( isset( $answer['setting'] ) ) {
					// The plugin setting that redirects, where no stored rule does.
					$hop['setting'] = (string) $answer['setting'];
				}
				break;
			}
		}

		if ( null === $hop ) {
			if ( $blocked ) {
				$chain_ends  = 'leaves_site_or_unknown';
				$approximate = true;
			} else {
				$chain_ends = array() === $chain ? 'no_match' : 'resolves';
			}
			break;
		}

		$chain[] = $hop;
		if ( null === $first_match ) {
			$first_match = array(
				'provider' => $hop['provider'],
				'id'       => $hop['id'],
				'target'   => $hop['target'],
				'code'     => $hop['code'],
			);
			if ( isset( $hop['setting'] ) ) {
				$first_match['setting'] = $hop['setting'];
			}
		}

		if ( in_array( $hop['code'], array( 403, 404, 410 ), true ) ) {
			$chain_ends = 'gone_or_blocked';
			break;
		}

		$relative = isset( $adapters[ $hop['provider'] ]['relative_targets'] ) && 'home' === $adapters[ $hop['provider'] ]['relative_targets'] ? 'home' : 'host_root';
		$next     = aafm_site_redirect_target_path( $hop['target'], $relative );
		if ( $next['had_query'] ) {
			$approximate = true;
		}
		if ( null === $next['path'] ) {
			$chain_ends = 'leaves_site_or_unknown';
			break;
		}

		$key = aafm_site_redirect_key( $next['path'] );
		if ( in_array( $key, $seen, true ) ) {
			$loop       = true;
			$chain_ends = 'loop';
			break;
		}
		$seen[]  = $key;
		$current = $next['path'];
	}

	return array(
		'evaluated'     => $evaluated,
		'first_match'   => $first_match,
		'chain'         => $chain,
		'chain_ends'    => $chain_ends,
		'loop_detected' => $loop,
		'approximate'   => $approximate,
	);
}

/**
 * Whether one path segment of a not-found address looks like a secret.
 *
 * @param string $segment One segment, after the safe-set step.
 * @return bool
 */
function aafm_site_404_segment_is_secret( string $segment ): bool {
	// An encoded '@': an email address in a path.
	if ( false !== strpos( $segment, '%40' ) ) {
		return true;
	}

	// A run of 32 or more letters and digits. Words separated by hyphens never form one.
	if ( 1 === preg_match( '/[A-Za-z0-9]{32,}/', $segment ) ) {
		return true;
	}

	// A run of 20 to 31 letters and digits that mixes both.
	if ( preg_match_all( '/[A-Za-z0-9]+/', $segment, $runs ) > 0 ) {
		foreach ( $runs[0] as $run ) {
			$length = strlen( $run );
			if ( $length >= 20 && $length <= 31 && 1 === preg_match( '/[0-9]/', $run ) && 1 === preg_match( '/[A-Za-z]/', $run ) ) {
				return true;
			}
		}
	}

	// Hex ids, hashes and UUIDs: 16 or more hex characters once the hyphens are gone, one or more of them a digit.
	$bare = str_replace( '-', '', $segment );

	return strlen( $bare ) >= 16 && 1 === preg_match( '/^[0-9a-fA-F]+$/', $bare ) && 1 === preg_match( '/[0-9]/', $bare );
}

/**
 * A not-found path made safe to output. Never empty.
 *
 * The path is text a visitor or a bot supplied. The steps bound its shape and length and remove
 * what looks like a secret. They do not remove meaning: a path of ordinary words is returned.
 *
 * @param string $raw The path or URL as the provider stored it.
 * @return string
 */
function aafm_site_404_output_path( string $raw ): string {
	// 1. Path only.
	$value = aafm_site_redirect_cut_at_query( $raw );
	$parts = wp_parse_url( $value );
	if ( is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) ) {
		$value = isset( $parts['path'] ) ? (string) $parts['path'] : '';
	}
	if ( '' === $value || '/' !== $value[0] ) {
		$value = '/' . $value;
	}

	// 2. Decode once for display; keep the stored form when the result is not valid UTF-8.
	$decoded = rawurldecode( $value );
	if ( wp_check_invalid_utf8( $decoded ) !== $decoded || 1 !== preg_match( '//u', $decoded ) ) {
		$decoded = $value;
	}
	$decoded = (string) preg_replace( '#/+#', '/', $decoded );

	// 3. Letters, marks, digits and . _ ~ - stay; every other byte is written as %XX. Nothing is dropped.
	$encode = static function ( array $found ): string {
		$out = '';
		foreach ( (array) unpack( 'C*', (string) $found[0] ) as $byte ) {
			$out .= sprintf( '%%%02X', (int) $byte );
		}
		return $out;
	};
	$safe   = preg_replace_callback( '/[^\p{L}\p{M}\p{N}._~\/-]/u', $encode, $decoded );
	if ( null === $safe ) {
		// Not valid UTF-8 even undecoded: keep ASCII letters and digits only.
		$safe = (string) preg_replace_callback( '/[^A-Za-z0-9._~\/-]/', $encode, $decoded );
	}

	// 4. Redact what looks like a secret, one segment at a time.
	$segments = explode( '/', $safe );
	foreach ( $segments as $index => $segment ) {
		if ( '' !== $segment && aafm_site_404_segment_is_secret( $segment ) ) {
			$segments[ $index ] = '[redacted]';
		}
	}
	$path = implode( '/', $segments );

	// 5. At most 200 bytes; a cut path ends in three dots.
	if ( strlen( $path ) > 200 ) {
		$path = aafm_siteops_utf8_cut( $path, 197 ) . '...';
	}

	return '' === $path ? '/' : $path;
}

/**
 * A site-local 'Y-m-d H:i:s' value as a UTC Unix timestamp, or null.
 *
 * @param mixed $value The stored value.
 * @return int|null
 */
function aafm_site_local_mysql_to_utc( $value ): ?int {
	if ( ! is_string( $value ) || '' === $value || '0000-00-00 00:00:00' === $value ) {
		return null;
	}

	$timestamp = strtotime( get_gmt_from_date( $value ) . ' UTC' );

	return ( false === $timestamp || $timestamp <= 0 ) ? null : $timestamp;
}

/**
 * Whether a database table can be read. Used by the adapters whose vendor creates its table late.
 *
 * A one-row probe with errors suppressed, so a missing table logs nothing. It reads the table
 * itself and not the table list, so it also holds for a temporary table.
 *
 * @param string $table Full table name, prefix included.
 * @return bool
 */
function aafm_siteops_table_exists( string $table ): bool {
	global $wpdb;

	$suppress = $wpdb->suppress_errors( true );
	$found    = aafm_wpdb_scalar( (string) $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );
	$wpdb->suppress_errors( $suppress );

	return $found['ok'];
}

/**
 * What WordPress's own main query makes of a path, for the redirects a plugin makes from a setting
 * rather than a stored rule: attachment pages, author and date archives, pages not found.
 *
 * It copies the first half of url_to_postid() and WP::parse_request(): the same rewrite rule
 * loop, the same check of verbose page rules, the same public query variables. Then one WP_Query
 * that asks for what a visitor sees (published posts and attachments), then the rules of
 * WP::handle_404(). It never calls WP::parse_request(), whose hooks other plugins use to send
 * headers or redirect, and it writes nothing; callbacks on pre_get_posts run inside the query, as
 * they do inside url_to_postid(). A page that plugin code serves outside the rewrite rules is
 * classed as WordPress alone would class it.
 *
 * Unclassified: plain permalinks, rewrite rules this request has not built, an index.php/ address,
 * a callback on pre_handle_404 when the page would otherwise be a 404 (the callback can stop the
 * 404, and the reset of the flags that comes with it), and any error.
 *
 * @param string $path Full canonical decoded path, home path included.
 * @return array<string,mixed> 'class' is attachment (with 'id' and 'parent'), author (with
 *                             'has_posts'), date, not_found, other or unclassified.
 */
function aafm_site_redirect_path_class( string $path ): array {
	global $wp_rewrite, $wp;

	try {
		$request = trim( $path, '/' );
		$home    = trim( aafm_site_redirect_home_path(), '/' );
		if ( '' !== $home ) {
			$request = trim( (string) preg_replace( '|^' . preg_quote( $home, '|' ) . '|i', '', $request ), '/' );
		}
		if ( '' === $request || 'index.php' === $request ) {
			return array( 'class' => 'other' );
		}

		// The rules this request already holds: WP::parse_request() built them for it. Building them
		// here with wp_rewrite_rules() would store them when the option is empty.
		$rules = ( $wp_rewrite instanceof WP_Rewrite && '' !== (string) $wp_rewrite->permalink_structure && is_array( $wp_rewrite->rules ) ) ? $wp_rewrite->rules : array();
		if ( empty( $rules ) || ! ( $wp instanceof WP ) || 0 === strpos( $request, 'index.php/' ) ) {
			return array( 'class' => 'unclassified' );
		}

		$perma = null;
		foreach ( (array) $rules as $match => $query ) {
			if ( 1 !== preg_match( '#^' . $match . '#', $request, $matches ) ) {
				continue;
			}
			if ( $wp_rewrite->use_verbose_page_rules && 1 === preg_match( '/pagename=\$matches\[([0-9]+)\]/', (string) $query, $varmatch ) ) {
				$page = get_page_by_path( (string) ( $matches[ $varmatch[1] ] ?? '' ) );
				if ( ! $page instanceof WP_Post ) {
					continue;
				}
				$status = get_post_status_object( $page->post_status );
				if ( $status && ! $status->public && ! $status->protected && ! $status->private && $status->exclude_from_search ) {
					continue;
				}
			}
			parse_str( addslashes( WP_MatchesMapRegex::apply( (string) preg_replace( '!^.+\?!', '', (string) $query ), $matches ) ), $perma );
			break;
		}
		if ( ! is_array( $perma ) ) {
			// No rule matched: WordPress answers 404.
			return array( 'class' => 'not_found' );
		}

		$type_vars = array();
		foreach ( get_post_types( array(), 'objects' ) as $type => $object ) {
			if ( is_post_type_viewable( $object ) && $object->query_var ) {
				$type_vars[ $object->query_var ] = $type;
			}
		}
		$vars = array();
		foreach ( (array) $wp->public_query_vars as $var ) {
			if ( ! isset( $perma[ $var ] ) ) {
				continue;
			}
			$vars[ $var ] = $perma[ $var ];
			if ( ! empty( $vars[ $var ] ) && isset( $type_vars[ $var ] ) ) {
				$vars['post_type'] = $type_vars[ $var ];
				$vars['name']      = $vars[ $var ];
			}
		}
		if ( isset( $vars['post_type'] ) && is_string( $vars['post_type'] ) && ! in_array( $vars['post_type'], get_post_types( array( 'publicly_queryable' => true ) ), true ) ) {
			unset( $vars['post_type'] );
		}
		$vars                  = wp_resolve_numeric_slug_conflicts( $vars );
		$vars['post_status']   = array( 'publish', 'inherit' );
		$vars['no_found_rows'] = true;
		$query                 = new WP_Query( $vars );

		if ( $query->is_404() ) {
			return array( 'class' => 'not_found' );
		}
		$set_404 = true;
		$page    = trim( (string) ( $vars['page'] ?? '' ), '/' );
		if ( $query->is_robots() || $query->is_favicon() || '' !== (string) $query->get( 'sitemap' ) || '' !== (string) $query->get( 'sitemap-stylesheet' ) ) {
			$set_404 = false;
		} elseif ( ! empty( $query->posts ) ) {
			$set_404 = false;
			if ( '' !== $page && $query->is_singular() && $query->post instanceof WP_Post ) {
				$next    = substr_count( $query->post->post_content, '<!--nextpage-->' );
				$set_404 = 0 === $next || (int) $page > $next + 1;
			}
			if ( '' !== $page && $query->is_posts_page ) {
				$set_404 = true;
			}
		} elseif ( ! $query->is_paged() ) {
			$author  = $query->get( 'author' );
			$set_404 = ! ( ( $query->is_author() && is_numeric( $author ) && $author > 0 && is_user_member_of_blog( (int) $author ) )
				|| ( ( $query->is_tag() || $query->is_category() || $query->is_tax() || $query->is_post_type_archive() ) && $query->get_queried_object() )
				|| $query->is_home() || $query->is_search() || $query->is_feed() );
		}

		if ( $set_404 ) {
			return array( 'class' => false !== has_filter( 'pre_handle_404' ) ? 'unclassified' : 'not_found' );
		}
		if ( $query->is_attachment() && $query->post instanceof WP_Post ) {
			return array(
				'class'  => 'attachment',
				'id'     => (int) $query->post->ID,
				'parent' => (int) $query->post->post_parent,
			);
		}
		if ( $query->is_author() ) {
			return array(
				'class'     => 'author',
				'has_posts' => ! empty( $query->posts ),
			);
		}

		return array( 'class' => $query->is_date() ? 'date' : 'other' );
	} catch ( \Throwable $e ) {
		return array( 'class' => 'unclassified' );
	}
}
