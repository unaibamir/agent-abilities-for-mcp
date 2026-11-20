<?php
/**
 * Site Ops: the canonical URL check and the post to URL helper.
 *
 * Every URL or post id an agent sends to a Site Ops tool passes through here before any other
 * plugin's code sees it. The origin always comes from home_url(), never from the input.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The refusal for a URL that failed the canonical check.
 *
 * The message names the rule class and never repeats the input.
 *
 * @param string $rule One of length, characters, origin, query, fragment, traversal.
 * @return WP_Error Code aafm_site_bad_url, data array( 'rule' => $rule ).
 */
function aafm_siteops_bad_url( string $rule ): WP_Error {
	switch ( $rule ) {
		case 'length':
			$message = __( 'The URL is empty or too long.', 'agent-abilities-for-mcp' );
			break;
		case 'origin':
			$message = __( 'The URL is not on this site. Its scheme, host, port and path must match the site address, and it must carry no user name.', 'agent-abilities-for-mcp' );
			break;
		case 'query':
			$message = __( 'The URL has a query string. These tools address a page by its path only.', 'agent-abilities-for-mcp' );
			break;
		case 'fragment':
			$message = __( 'The URL has a fragment. These tools address a page by its path only.', 'agent-abilities-for-mcp' );
			break;
		case 'traversal':
			$message = __( 'The URL path has a relative step or two dots in a row, which these tools refuse.', 'agent-abilities-for-mcp' );
			break;
		default:
			$rule    = 'characters';
			$message = __( 'The URL has a character these tools refuse. Letters, digits, dots, hyphens, underscores and tildes are allowed in a path.', 'agent-abilities-for-mcp' );
	}

	return new WP_Error( 'aafm_site_bad_url', $message, array( 'rule' => $rule ) );
}

/**
 * Check a URL or path from tool input and return its one canonical form.
 *
 * @param string $input           Raw URL or path from tool input.
 * @param bool   $permalink_slash Apply the site's trailing slash convention to the last segment.
 * @return array{url:string,path:string,path_encoded:string,is_home:bool}|WP_Error Code aafm_site_bad_url.
 */
function aafm_siteops_canonical_url( string $input, bool $permalink_slash = false ) {
	// 1. Length.
	$length = strlen( $input );
	if ( $length < 1 || $length > 2000 ) {
		return aafm_siteops_bad_url( 'length' );
	}

	// 2. No control character and no backslash anywhere.
	if ( 1 === preg_match( '/[\x00-\x1F\x7F\\\\]/', $input ) ) {
		return aafm_siteops_bad_url( 'characters' );
	}

	$home = wp_parse_url( home_url( '/' ) );
	if ( ! is_array( $home ) || empty( $home['scheme'] ) || empty( $home['host'] ) ) {
		return aafm_siteops_bad_url( 'origin' );
	}
	$home_scheme = strtolower( (string) $home['scheme'] );

	// 3. Split. A value that starts with one slash is a path on this site.
	if ( '/' === $input[0] && 0 !== strpos( $input, '//' ) ) {
		if ( false !== strpos( $input, '?' ) ) {
			return aafm_siteops_bad_url( 'query' );
		}
		if ( false !== strpos( $input, '#' ) ) {
			return aafm_siteops_bad_url( 'fragment' );
		}
		$raw_path = $input;
	} else {
		$parts = wp_parse_url( $input );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return aafm_siteops_bad_url( 'origin' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return aafm_siteops_bad_url( 'origin' );
		}
		if ( isset( $parts['query'] ) || false !== strpos( $input, '?' ) ) {
			return aafm_siteops_bad_url( 'query' );
		}
		if ( isset( $parts['fragment'] ) || false !== strpos( $input, '#' ) ) {
			return aafm_siteops_bad_url( 'fragment' );
		}

		// 4. Origin: scheme, host and port must be the site's own.
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( $scheme !== $home_scheme ) {
			return aafm_siteops_bad_url( 'origin' );
		}
		if ( strtolower( (string) $parts['host'] ) !== strtolower( (string) $home['host'] ) ) {
			return aafm_siteops_bad_url( 'origin' );
		}
		$default_port = 'https' === $home_scheme ? 443 : 80;
		$input_port   = isset( $parts['port'] ) ? (int) $parts['port'] : $default_port;
		$home_port    = isset( $home['port'] ) ? (int) $home['port'] : $default_port;
		if ( $input_port !== $home_port ) {
			return aafm_siteops_bad_url( 'origin' );
		}

		$raw_path = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
	}

	// 5. Decode once. A percent sign left over means the input was encoded twice.
	$path = rawurldecode( $raw_path );
	if ( false !== strpos( $path, '%' ) ) {
		return aafm_siteops_bad_url( 'characters' );
	}
	if ( wp_check_invalid_utf8( $path ) !== $path ) {
		return aafm_siteops_bad_url( 'characters' );
	}

	// 6. Collapse runs of slashes.
	$path = (string) preg_replace( '#/+#', '/', $path );
	if ( '' === $path || '/' !== $path[0] ) {
		return aafm_siteops_bad_url( 'characters' );
	}

	// 7. Segments.
	$has_trailing_slash = '/' === substr( $path, -1 );
	$trimmed            = trim( $path, '/' );
	$segments           = '' === $trimmed ? array() : explode( '/', $trimmed );
	foreach ( $segments as $segment ) {
		if ( '.' === $segment || false !== strpos( $segment, '..' ) ) {
			return aafm_siteops_bad_url( 'traversal' );
		}
		if ( 1 !== preg_match( '/^[\p{L}\p{M}\p{N}._~-]+$/u', $segment ) ) {
			return aafm_siteops_bad_url( 'characters' );
		}
	}

	// 8. Home path.
	$home_path = isset( $home['path'] ) ? rtrim( rawurldecode( (string) $home['path'] ), '/' ) : '';
	$bare      = '/' . $trimmed;
	if ( '' !== $home_path && $bare !== $home_path && 0 !== strpos( $bare, $home_path . '/' ) ) {
		return aafm_siteops_bad_url( 'origin' );
	}

	// 9. Decoded length.
	if ( strlen( $path ) > 1024 ) {
		return aafm_siteops_bad_url( 'length' );
	}

	// 10. Trailing slash. The home path always ends in one slash.
	$is_home = ( '' === $home_path && '' === $trimmed ) || ( '' !== $home_path && $bare === $home_path );
	if ( $is_home ) {
		$has_trailing_slash = true;
	} elseif ( $permalink_slash ) {
		$last = (string) end( $segments );
		if ( false === strpos( $last, '.' ) ) {
			$has_trailing_slash = '/' === substr( user_trailingslashit( $bare ), -1 );
		}
	}
	$path = '' === $trimmed ? '/' : $bare . ( $has_trailing_slash ? '/' : '' );

	// 11. The encoded path and the URL.
	$encoded = array();
	foreach ( $segments as $segment ) {
		$encoded[] = (string) preg_replace_callback(
			'/%[0-9A-F]{2}/',
			static function ( array $found ): string {
				return strtolower( $found[0] );
			},
			rawurlencode( $segment )
		);
	}
	$path_encoded = array() === $encoded ? '/' : '/' . implode( '/', $encoded ) . ( $has_trailing_slash ? '/' : '' );
	$origin       = $home_scheme . '://' . (string) $home['host'] . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' );

	return array(
		'url'          => $origin . $path_encoded,
		'path'         => $path,
		'path_encoded' => $path_encoded,
		'is_home'      => $is_home,
	);
}

/**
 * The canonical URL of one published, viewable post.
 *
 * The permalink is always computed here and never taken from input.
 *
 * @param int $post_id Post id from tool input.
 * @return array{url:string,path:string,path_encoded:string,is_home:bool}|WP_Error Codes aafm_site_post_not_public, aafm_site_bad_url.
 */
function aafm_siteops_post_url( int $post_id ) {
	$not_public = new WP_Error(
		'aafm_site_post_not_public',
		__( 'That post is missing, not published, or not of a type visitors can view.', 'agent-abilities-for-mcp' )
	);
	if ( $post_id < 1 ) {
		return $not_public;
	}

	$post = aafm_exact_object( 'post', $post_id );
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
		return $not_public;
	}

	$permalink = get_permalink( $post );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		return $not_public;
	}

	$url = aafm_siteops_canonical_url( $permalink );
	if ( is_wp_error( $url ) ) {
		$data = $url->get_error_data();
		if ( is_array( $data ) && isset( $data['rule'] ) && 'query' === $data['rule'] ) {
			return new WP_Error(
				'aafm_site_bad_url',
				__( 'This site uses plain permalinks, which these tools cannot address.', 'agent-abilities-for-mcp' ),
				array( 'rule' => 'query' )
			);
		}
	}

	return $url;
}
