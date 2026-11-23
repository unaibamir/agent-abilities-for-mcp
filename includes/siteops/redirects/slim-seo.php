<?php
/**
 * Redirect adapter: Slim SEO (slim-seo) redirection, read only. Benched on 4.11.6.
 *
 * Defines the five adapter functions and nothing else. The plugin has no match function, and its
 * own redirect method sends a response and exits, so matching is the plugin's own logic evaluated
 * in this process with the response left out.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether the plugin's redirection feature is on. Separate from the detection of the Slim SEO
 * field abilities: this one also needs the redirection classes and the feature switch.
 *
 * @return bool
 */
function aafm_redirect_slim_seo_ready(): bool {
	if ( ! class_exists( 'SlimSEO\Redirection\Database\Redirects' )
		|| ! in_array( 'list', (array) get_class_methods( 'SlimSEO\Redirection\Database\Redirects' ), true )
		|| ! is_callable( array( 'SlimSEO\Redirection\Helper', 'normalize_url' ) )
		|| ! is_callable( array( 'SlimSEO\Redirection\Settings', 'get' ) ) ) {
		return false;
	}

	// An empty feature list means every feature is on, which is the plugin's default.
	$options = get_option( 'slim_seo' );

	return ! is_array( $options ) || empty( $options['features'] ) || in_array( 'redirection', (array) $options['features'], true );
}

/**
 * Whether the current user holds the capability the plugin's own routes require.
 *
 * @param string $action 'read' or 'read_404'.
 * @return bool
 */
function aafm_redirect_slim_seo_can( string $action ): bool {
	return ( 'read' === $action || 'read_404' === $action ) && current_user_can( 'manage_options' );
}

/**
 * Every rule, normalised, newest first, which is also the order the plugin matches in. At most 5,000.
 *
 * @return array{rows:array<int,array<string,mixed>>,truncated:bool}
 * @throws \RuntimeException When the plugin's reader returns something other than an array.
 */
function aafm_redirect_slim_seo_all(): array {
	// A new instance every time: the constructor takes a snapshot of the stored rules.
	$list = ( new \SlimSEO\Redirection\Database\Redirects() )->list();
	if ( ! is_array( $list ) ) {
		throw new \RuntimeException( 'aafm_redirect_slim_seo_all' );
	}

	$truncated = count( $list ) > 5000;
	if ( $truncated ) {
		$list = array_slice( $list, 0, 5000, true );
	}

	$rows = array();
	foreach ( $list as $id => $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$condition = isset( $entry['condition'] ) ? (string) $entry['condition'] : 'exact-match';
		$rows[]    = array(
			'id'      => (string) $id,
			'source'  => '/' . ltrim( isset( $entry['from'] ) ? (string) $entry['from'] : '', '/' ),
			'target'  => isset( $entry['to'] ) ? (string) $entry['to'] : '',
			'code'    => isset( $entry['type'] ) ? (int) $entry['type'] : 0,
			'enabled' => ! empty( $entry['enable'] ),
			'kind'    => 'exact-match' === $condition ? 'exact' : 'pattern',
			'hits'    => null,
		);
	}

	return array(
		'rows'      => $rows,
		'truncated' => $truncated,
	);
}

/**
 * Which rule a path would hit: the plugin's five conditions, evaluated in list order.
 *
 * The plain string functions stand for the PHP 8 ones the plugin uses, so this runs on PHP 7.4;
 * an empty source matches every request there as it does in the plugin. A 410 rule is a match
 * with an empty target: the plugin answers 410 and does not redirect. A relative target is
 * returned as stored; the plugin prefixes the home URL when it redirects.
 *
 * Before all of it (4.11.6, NoCategoryBase.php:95-113), with the no category base feature on, a
 * request filter sends any address under the old category base to the same address without it
 * while WordPress parses the request. That feature is apart from the redirect module, so it is
 * the only answer while the module is off. The feature switch is read here and the variable from
 * the rewrite rule, because the plugin's query variable is public only once a request has been
 * parsed. With the module on but a class or method of it missing (another plugin version),
 * nothing was evaluated: unknown.
 *
 * @param string              $path  The path BELOW the home path (the registry's match_path is below_home).
 * @param array<string,mixed> $cache Per-call cache; holds the rule list after the first call.
 * @return array<string,mixed> A match shape.
 */
function aafm_redirect_slim_seo_match( string $path, array &$cache ): array {
	$full     = aafm_site_redirect_home_path() . $path;
	$options  = get_option( 'slim_seo' );
	$features = is_array( $options ) && ! empty( $options['features'] ) ? (array) $options['features'] : array();
	// An empty feature list is the plugin's default: every feature on except no category base
	// (Settings.php:120-130).
	if ( in_array( 'no_category_base', $features, true ) ) {
		$base = aafm_site_redirect_path_class( $full, true );
		if ( 'unclassified' === $base['class'] && array() === $base['vars'] ) {
			// No rewrite rule could be followed, so the old base cannot be told apart.
			return array(
				'state'  => 'unknown',
				'reason' => 'slim_seo_category_redirect',
			);
		}
		if ( ! empty( $base['vars']['category_redirect'] ) ) {
			$value = (string) $base['vars']['category_redirect'];
			if ( implode( '/', array_map( 'rawurlencode', explode( '/', $value ) ) ) !== $value ) {
				// The escapes of the address a browser sends make the target.
				return array(
					'state'  => 'unknown',
					'reason' => 'slim_seo_category_redirect',
				);
			}
			return array(
				'state'   => 'matched',
				'id'      => '',
				'target'  => trailingslashit( (string) get_option( 'home' ) ) . user_trailingslashit( $value, 'category' ),
				'code'    => 301,
				'setting' => 'slim_seo_category_redirect',
			);
		}
	}
	if ( ! aafm_redirect_slim_seo_ready() ) {
		return ( array() === $features || in_array( 'redirection', $features, true ) ) ? array( 'state' => 'unknown' ) : array( 'state' => 'no_match' );
	}

	if ( ! isset( $cache['rows'] ) ) {
		$rows          = ( new \SlimSEO\Redirection\Database\Redirects() )->list();
		$cache['rows'] = is_array( $rows ) ? $rows : array();
	}
	if ( false !== has_filter( 'slim_seo_redirection_skip' ) ) {
		// A site filter can suppress any redirect.
		return array( 'state' => 'unknown' );
	}
	$sensitive = (bool) \SlimSEO\Redirection\Settings::get( 'case_sensitive' );
	// The home URL is stripped and the slashes trimmed; the home page itself is '/'.
	$original = \SlimSEO\Redirection\Helper::normalize_url( home_url( $path ) );
	$lowered  = $sensitive ? $original : strtolower( $original );
	$unknown  = false;
	foreach ( (array) $cache['rows'] as $id => $r ) {
		if ( ! is_array( $r ) || empty( $r['enable'] ) || ! isset( $r['from'], $r['to'], $r['type'] ) ) {
			continue;
		}
		$condition = (string) ( $r['condition'] ?? 'exact-match' );
		$is_regex  = 'regex' === $condition;
		$current   = $is_regex ? $original : $lowered;
		if ( ! empty( $r['ignoreParameters'] ) ) {
			$current = rtrim( explode( '?', $current )[0], '/' );
		}
		$from = \SlimSEO\Redirection\Helper::normalize_url( (string) $r['from'], false );
		$from = ( $sensitive || $is_regex ) ? $from : strtolower( $from );
		$to   = (string) $r['to'];
		$hit  = false;
		if ( $is_regex ) {
			$regex = '/' . str_replace( '/', '\/', str_replace( '\/', '/', $from ) ) . '/' . ( $sensitive ? '' : 'i' );
			$m     = @preg_match( $regex, $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A stored pattern that does not compile is reported as unknown.
			if ( false === $m ) {
				$unknown = true;
				continue;
			}
			if ( 1 === $m ) {
				$to  = (string) preg_replace( $regex, $to, $current );
				$hit = true;
			}
		} elseif ( 'contain' === $condition ) {
			$hit = '' === $from || false !== strpos( $current, $from );
		} elseif ( 'start-with' === $condition ) {
			$hit = '' === $from || 0 === strpos( $current, $from );
		} elseif ( 'end-with' === $condition ) {
			$hit = '' === $from || substr( $current, -strlen( $from ) ) === $from;
		} else {
			$hit = ( $current === $from );
		}
		if ( ! $hit ) {
			continue;
		}
		if ( $unknown ) {
			return array( 'state' => 'unknown' );
		}
		$code   = (int) $r['type'];
		$target = 410 === $code ? '' : $to;

		return array(
			'state'  => 'matched',
			'id'     => (string) $id,
			'target' => $target,
			'code'   => $code,
		);
	}

	if ( $unknown ) {
		return array( 'state' => 'unknown' );
	}

	// After the rules (template_redirect priority 1) the plugin redirects from its settings, in this
	// order (4.11.6, Redirection.php and Redirection404.php), each with 301: the www redirect
	// (priority 5, judged on the site's own host, the only one the tool accepts); automatic
	// redirects (priority 10, on by default) of attachment pages to their file, of author archives
	// with no posts or on a site with one user to the home page, and of a page not found whose
	// address is a stored old address of a page; the 404 redirect (priority 20). Which kind of
	// page a path is comes from aafm_site_redirect_path_class(); a page it cannot classify gives
	// unknown with the setting's reason.
	$home   = (string) home_url();
	$host   = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
	$port   = wp_parse_url( $home, PHP_URL_PORT );
	$port   = $port ? ':' . $port : '';
	$scheme = (string) wp_parse_url( $home, PHP_URL_SCHEME );
	// The address as a browser sends it, which the plugin reads without decoding.
	$sent = implode( '/', array_map( 'rawurlencode', explode( '/', $full ) ) );
	$www  = (string) \SlimSEO\Redirection\Settings::get( 'redirect_www' );
	if ( ( 'www-to-non' === $www && 0 === strpos( $host, 'www.' ) ) || ( 'non-to-www' === $www && 0 !== strpos( $host, 'www.' ) ) ) {
		if ( $sent !== $full ) {
			// A browser may send escapes in either case, and the target keeps them as sent.
			return array(
				'state'  => 'unknown',
				'reason' => 'slim_seo_www_redirect',
			);
		}
		$other = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
		return array(
			'state'   => 'matched',
			'id'      => '',
			'target'  => \SlimSEO\Redirection\Helper::normalize_url( $scheme . '://' . $other . $port . $sent ),
			'code'    => 301,
			'setting' => 'slim_seo_www_redirect',
		);
	}

	$auto  = (bool) \SlimSEO\Redirection\Settings::get( 'auto_redirection' );
	$to404 = (string) \SlimSEO\Redirection\Settings::get( 'redirect_404_to' );
	if ( ! $auto && '' === $to404 ) {
		return array( 'state' => 'no_match' );
	}
	$class = aafm_site_redirect_path_class( $full );
	if ( 'unclassified' === $class['class'] ) {
		return array(
			'state'  => 'unknown',
			'reason' => $auto ? 'slim_seo_auto_redirection' : 'slim_seo_404_redirect',
		);
	}
	// A term archive may be a page not found, which only a term lookup could tell (4.6), so the two
	// redirects of a page not found cannot be told for it, as for one other plugin code could serve.
	$maybe   = 'not_found' === $class['class'] || 'term' === $class['class'];
	$open    = ! empty( $class['hooked'] ) || 'term' === $class['class'];
	$auto_to = '';
	if ( $auto && 'attachment' === $class['class'] ) {
		$auto_to = wp_validate_redirect( esc_url_raw( (string) wp_get_attachment_url( (int) $class['id'] ) ), admin_url() );
	} elseif ( $auto && 'author' === $class['class'] && ( empty( $class['has_posts'] ) || 1 === count(
		get_users(
			array(
				'number' => 2,
				'fields' => 'ID',
			)
		)
	) ) ) {
		$auto_to = home_url( '/' );
	} elseif ( $auto && $maybe ) {
		global $wpdb;
		$request = \SlimSEO\Redirection\Helper::normalize_url( $scheme . '://' . (string) wp_parse_url( $home, PHP_URL_HOST ) . $port . $sent );
		$found   = \SlimSEO\Redirection\Settings::get( 'case_sensitive' )
			? aafm_wpdb_scalar( (string) $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s LIMIT 1', $wpdb->postmeta, '_ss_old_permalink', $request ) )
			: aafm_wpdb_scalar( (string) $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND LOWER( meta_value ) = LOWER( %s ) LIMIT 1', $wpdb->postmeta, '_ss_old_permalink', $request ) );
		if ( $found['ok'] && (int) $found['value'] > 0 ) {
			if ( $open ) {
				// The page may be found (a term archive, or a page other plugin code could serve),
				// and then nothing is redirected.
				return array(
					'state'  => 'unknown',
					'reason' => 'slim_seo_auto_redirection',
				);
			}
			$auto_to = wp_validate_redirect( (string) get_permalink( (int) $found['value'] ), admin_url() );
		}
	}
	// On a page not found WordPress's own canonical redirect runs first (template_redirect priority
	// 10, registered before the plugin's) and its guess at the page can answer instead.
	$guess = 'not_found' === $class['class'] ? array( 'approximate' => true ) : array();
	if ( '' === $auto_to && '' !== $to404 && $open ) {
		return array(
			'state'  => 'unknown',
			'reason' => 'slim_seo_404_redirect',
		);
	}
	if ( '' !== $auto_to ) {
		return array(
			'state'   => 'matched',
			'id'      => '',
			'target'  => $auto_to,
			'code'    => 301,
			'setting' => 'slim_seo_auto_redirection',
		) + $guess;
	}

	return ( '' !== $to404 && 'not_found' === $class['class'] )
		? array(
			'state'   => 'matched',
			'id'      => '',
			'target'  => 'custom' === $to404 ? (string) \SlimSEO\Redirection\Settings::get( 'redirect_404_to_url' ) : $home,
			'code'    => 301,
			'setting' => 'slim_seo_404_redirect',
		) + $guess
		: array( 'state' => 'no_match' );
}

/**
 * The plugin's not-found log. Read only when logging is on and the log table exists: the plugin
 * creates the table late, and its own reader fails without it, so the table is read directly.
 *
 * @return array<int,array{path:string,count:int,last_seen:int|null}>
 * @throws \RuntimeException When the log table cannot be read.
 */
function aafm_redirect_slim_seo_list_404s(): array {
	global $wpdb;

	$table = $wpdb->prefix . 'slim_seo_404';
	if ( ! class_exists( 'SlimSEO\Redirection\Database\Log404' ) || ! \SlimSEO\Redirection\Settings::get( 'enable_404_logs' ) || ! aafm_siteops_table_exists( $table ) ) {
		return array();
	}

	$result = aafm_wpdb_results( (string) $wpdb->prepare( 'SELECT url, hit, updated_at FROM %i ORDER BY hit DESC LIMIT 200', $table ) );
	if ( ! $result['ok'] || ! is_array( $result['value'] ) ) {
		throw new \RuntimeException( 'aafm_redirect_slim_seo_list_404s' );
	}

	$out = array();
	foreach ( $result['value'] as $row ) {
		if ( ! isset( $row['url'] ) || ! is_scalar( $row['url'] ) ) {
			continue;
		}
		$out[] = array(
			'path'      => (string) $row['url'],
			'count'     => isset( $row['hit'] ) ? (int) $row['hit'] : 0,
			// The plugin writes this column in site-local time.
			'last_seen' => aafm_site_local_mysql_to_utc( $row['updated_at'] ?? null ),
		);
	}

	return $out;
}
