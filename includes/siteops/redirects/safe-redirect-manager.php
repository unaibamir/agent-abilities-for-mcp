<?php
/**
 * Redirect adapter: Safe Redirect Manager (safe-redirect-manager), read only. Benched on 2.3.0.
 *
 * Defines the five adapter functions and nothing else. The plugin's own cached list getter and
 * its own matcher are never called: on a cold cache both store the rule list in a 30 day
 * transient, and the getter can put disabled rules into it. Rules are read with one WP_Query per
 * page and matched in this process.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether the plugin's functions and its post type are present. The post type registers on init.
 *
 * @return bool
 */
function aafm_redirect_safe_redirect_manager_ready(): bool {
	return function_exists( 'srm_get_redirect_data' ) && function_exists( 'srm_get_max_redirects' ) && post_type_exists( 'redirect_rule' );
}

/**
 * Whether the current user holds the plugin's own capability. The plugin keeps no not-found log.
 *
 * @param string $action 'read' or 'read_404'.
 * @return bool
 */
function aafm_redirect_safe_redirect_manager_can( string $action ): bool {
	if ( 'read' !== $action ) {
		return false;
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own capability filter, applied as its own screens apply it.
	$capability = apply_filters( 'srm_restrict_to_capability', 'srm_manage_redirects' );

	return is_string( $capability ) && current_user_can( $capability );
}

/**
 * Every rule, normalised, in the order the plugin evaluates them. At most 25 pages of 200.
 *
 * Each row carries one extra key, 'regex', which match() needs and the output rule drops. An id
 * is only ever one this query returned, and its post type is checked again on the loaded post.
 *
 * @return array{rows:array<int,array<string,mixed>>,truncated:bool}
 */
function aafm_redirect_safe_redirect_manager_all(): array {
	$rows      = array();
	$truncated = false;
	for ( $paged = 1; $paged <= 25; $paged++ ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'redirect_rule',
				'post_status'            => array( 'publish', 'draft' ),
				'fields'                 => 'ids',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A bounded listing read: at most 25 pages, ids only.
				'posts_per_page'         => 200,
				'paged'                  => $paged,
				'orderby'                => 'menu_order ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'suppress_filters'       => false,
				'update_post_term_cache' => false,
			)
		);
		$ids = wp_parse_id_list( (array) $query->posts );
		if ( array() === $ids ) {
			break;
		}

		// One query for the page's meta instead of several per rule.
		update_postmeta_cache( $ids );
		foreach ( $ids as $id ) {
			$post = aafm_exact_object( 'post', $id );
			if ( ! $post instanceof WP_Post || 'redirect_rule' !== $post->post_type ) {
				continue;
			}
			$data = srm_get_redirect_data( $id );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$source = isset( $data['redirect_from'] ) ? (string) $data['redirect_from'] : '';
			$regex  = ! empty( $data['enable_regex'] );
			$rows[] = array(
				'id'      => (string) $id,
				'source'  => $source,
				'target'  => isset( $data['redirect_to'] ) ? (string) $data['redirect_to'] : '',
				'code'    => isset( $data['status_code'] ) ? (int) $data['status_code'] : 0,
				'enabled' => 'publish' === $post->post_status,
				'kind'    => ( $regex || false !== strpos( $source, '*' ) ) ? 'pattern' : 'exact',
				'hits'    => null,
				'regex'   => $regex,
			);
		}

		if ( count( $ids ) < 200 ) {
			break;
		}
		if ( 25 === $paged ) {
			$truncated = true;
		}
	}

	return array(
		'rows'      => $rows,
		'truncated' => $truncated,
	);
}

/**
 * Which rule a path would hit, evaluated in this process from the plugin's own matching code with
 * its writes and its response left out.
 *
 * Differences from the plugin, on purpose: the home path is quoted before it is used as a
 * pattern; the query string branches are left out because the tool takes no query string; and the
 * answer is 'unknown' when a site filter could change live matching, or when the path holds a
 * non-ASCII character, because the plugin strips percent-encoded bytes from a request before it
 * matches and this function is given the decoded path.
 *
 * The rule's "force HTTPS" switch is not read: the plugin applies it to the target only when it
 * sends the redirect, so the target reported here keeps the scheme it was stored with.
 *
 * @param string              $path  Full canonical decoded path, home path included.
 * @param array<string,mixed> $cache Per-call cache; holds the enabled rows after the first call.
 * @return array<string,mixed> A match shape.
 */
function aafm_redirect_safe_redirect_manager_match( string $path, array &$cache ): array {
	foreach ( array( 'srm_registered_redirects', 'srm_match_query_params', 'srm_redirect_to', 'srm_redirect_only_on_404', 'srm_requested_path' ) as $hook ) {
		if ( false !== has_filter( $hook ) ) {
			// A site filter can change live matching.
			return array( 'state' => 'unknown' );
		}
	}
	if ( 1 === preg_match( '/[^\x00-\x7F]/', $path ) ) {
		// The plugin strips percent-encoded bytes from the request before matching; not modelled.
		return array( 'state' => 'unknown' );
	}
	if ( ! isset( $cache['rows'] ) ) {
		$all  = aafm_redirect_safe_redirect_manager_all();
		$rows = array();
		foreach ( $all['rows'] as $rule ) {
			if ( ! empty( $rule['enabled'] ) ) {
				$rows[] = $rule;
			}
		}
		// The plugin's own cap on how many rules it evaluates.
		$cache['rows'] = array_slice( $rows, 0, max( 0, (int) srm_get_max_redirects() ) );
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, applied exactly as the plugin applies it.
	$insensitive = (bool) apply_filters( 'srm_case_insensitive_redirects', true );
	$flag        = $insensitive ? 'i' : '';
	$home_path   = (string) wp_parse_url( home_url(), PHP_URL_PATH );
	// The plugin removes the trailing slash from the request before it matches.
	$request = untrailingslashit( $path );
	if ( '' !== $home_path && '/' !== $home_path ) {
		$request = (string) preg_replace( '@' . preg_quote( $home_path, '@' ) . '@i', '', $request, 1 );
	}
	$request = '' === $request ? '/' : $request;
	$normal  = untrailingslashit( $insensitive ? strtolower( $request ) : $request );
	$unknown = false;
	foreach ( (array) $cache['rows'] as $rule ) {
		$from = untrailingslashit( (string) $rule['source'] );
		$from = '' === $from ? '/' : $from;
		$to   = (string) $rule['target'];
		$code = (int) $rule['code'];
		if ( '' === $to && ! in_array( $code, array( 403, 404, 410 ), true ) ) {
			continue;
		}
		if ( 'pattern' === $rule['kind'] && ! empty( $rule['regex'] ) ) {
			$hit = @preg_match( '@' . $from . '@' . $flag, $request ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A stored pattern that does not compile is reported as unknown, not as a warning.
			if ( false === $hit ) {
				$unknown = true;
				continue;
			}
			if ( 1 !== $hit ) {
				continue;
			}
			$to = (string) preg_replace( '@' . $from . '@' . $flag, $to, $request );
			if ( ! filter_var( $to, FILTER_VALIDATE_URL ) ) {
				$to = '/' . ltrim( $to, '/' );
			}
		} else {
			$from_cmp = $insensitive ? strtolower( $from ) : $from;
			$hit      = ( ( '' === $normal ? '/' : $normal ) === $from_cmp );
			if ( ! $hit && '*' === substr( $from_cmp, -1 ) ) {
				$base = substr( $from_cmp, 0, -1 );
				$hit  = ( substr( trailingslashit( $normal ), 0, strlen( $base ) ) === $base );
				if ( $hit && '*' === substr( $to, -1 ) ) {
					$to_base = rtrim( $to, '*' );
					$parsed  = wp_parse_url( $to_base );
					if ( is_array( $parsed ) && ! empty( $parsed['host'] ) && empty( $parsed['path'] ) ) {
						// A wildcard may extend the path, never the host.
						$to_base = trailingslashit( $to_base );
					}
					$suffix = substr( $request, strlen( $base ) );
					$to     = $to_base . ( '/' === substr( $to_base, -1 ) ? ltrim( $suffix, '/' ) : $suffix );
				}
			}
			if ( ! $hit ) {
				continue;
			}
		}
		if ( function_exists( 'srm_get_valid_status_codes' ) && ! in_array( $code, (array) srm_get_valid_status_codes(), true ) ) {
			if ( false !== has_filter( 'srm_default_direct_status' ) ) {
				return array( 'state' => 'unknown' );
			}
			// What the plugin sends for a stored code it does not accept.
			$code = 302;
		}
		return $unknown
			? array( 'state' => 'unknown' )
			: array(
				'state'  => 'matched',
				'id'     => (string) $rule['id'],
				'target' => esc_url_raw( $to ),
				'code'   => $code,
			);
	}

	return array( 'state' => $unknown ? 'unknown' : 'no_match' );
}

/**
 * The plugin keeps no not-found log. The registry's has_404 is false, so this is never called.
 *
 * @return array<int,array{path:string,count:int,last_seen:int|null}>
 */
function aafm_redirect_safe_redirect_manager_list_404s(): array {
	return array();
}
