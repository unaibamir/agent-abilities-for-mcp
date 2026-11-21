<?php
/**
 * Site Ops redirect adapters: the registry, detection, the wrapped dispatcher and provider
 * resolution.
 *
 * Read only. Nothing in this file, and nothing it calls, creates or changes a redirect. An adapter
 * file under includes/siteops/redirects/ is the only place its vendor is called, and every adapter
 * function is reached through aafm_site_redirect_call() or aafm_site_redirect_match(), so the
 * isolation wrapper is around all of them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The redirect adapters, in the order the plugins were seen to run on a real request.
 *
 * Keys: label (a product name, not translated); plugins (basenames, for the integration card);
 * detect (symbol tests, all of which must pass, tested by aafm_site_cache_detected()); has_404
 * (false means list_404s() is never called); match_path ('full' or 'below_home': the path the
 * adapter's match() is given on a site whose home URL has a path); relative_targets ('host_root'
 * or 'home': what a stored target without a scheme is relative to); ask_when_not_ready (true when
 * the plugin also redirects from settings outside its redirect module, so site-test-redirect asks
 * it while that module is off; its match() then answers for those settings alone).
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_site_redirect_adapters(): array {
	$adapters = array(
		'eps_301'               => array(
			'label'            => '301 Redirects',
			'plugins'          => array( 'eps-301-redirects/eps-301-redirects.php' ),
			'detect'           => array( array( 'class', 'EPS_Redirects' ) ),
			'has_404'          => true,
			'match_path'       => 'full',
			'relative_targets' => 'host_root',
		),
		'redirection'           => array(
			'label'            => 'Redirection',
			'plugins'          => array( 'redirection/redirection.php' ),
			'detect'           => array( array( 'constant', 'REDIRECTION_VERSION' ) ),
			'has_404'          => true,
			'match_path'       => 'full',
			'relative_targets' => 'host_root',
		),
		'safe_redirect_manager' => array(
			'label'            => 'Safe Redirect Manager',
			'plugins'          => array( 'safe-redirect-manager/safe-redirect-manager.php' ),
			'detect'           => array(
				array( 'function', 'srm_get_redirect_data' ),
				array( 'function', 'srm_get_max_redirects' ),
			),
			'has_404'          => false,
			'match_path'       => 'full',
			'relative_targets' => 'host_root',
		),
		'rankmath'              => array(
			'label'              => 'Rank Math',
			'plugins'            => array( 'seo-by-rank-math/rank-math.php' ),
			'detect'             => array( array( 'constant', 'RANK_MATH_VERSION' ) ),
			'has_404'            => true,
			'match_path'         => 'full',
			'relative_targets'   => 'home',
			'ask_when_not_ready' => true,
		),
		'slim_seo'              => array(
			'label'              => 'Slim SEO',
			'plugins'            => array( 'slim-seo/slim-seo.php' ),
			'detect'             => array( array( 'constant', 'SLIM_SEO_VER' ) ),
			'has_404'            => true,
			'match_path'         => 'below_home',
			'relative_targets'   => 'home',
			'ask_when_not_ready' => true,
		),
	);

	/**
	 * Filters the redirect adapter registry. The test suite uses it to register fake adapters.
	 *
	 * @param array<string,array<string,mixed>> $adapters The registry.
	 */
	$filtered = apply_filters( 'aafm_site_redirect_adapters', $adapters );

	return is_array( $filtered ) ? $filtered : $adapters;
}

/**
 * Load the helpers every redirect ability needs. Called first in each execute callback.
 *
 * @return void
 */
function aafm_siteops_redirect_load(): void {
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/url.php';
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/redirect-read.php';
}

/**
 * The path of this site's home URL without a trailing slash: '' on a site at the domain root,
 * '/blog' on a site installed in /blog.
 *
 * @return string
 */
function aafm_site_redirect_home_path(): string {
	$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

	return is_string( $path ) ? untrailingslashit( $path ) : '';
}

/**
 * Whether a redirect id has the shape the five providers use: integer ids and uniqid() keys.
 *
 * @param string $id The id.
 * @return bool
 */
function aafm_site_redirect_valid_id( string $id ): bool {
	return 1 === preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $id );
}

/**
 * One registry entry, when the slug is registered and its detect tests pass.
 *
 * @param string $slug Registry slug.
 * @return array<string,mixed>|null
 */
function aafm_site_redirect_detected_entry( string $slug ): ?array {
	$adapters = aafm_site_redirect_adapters();
	if ( ! isset( $adapters[ $slug ] ) || ! is_array( $adapters[ $slug ] ) ) {
		return null;
	}

	return aafm_site_cache_detected( $adapters[ $slug ] ) ? $adapters[ $slug ] : null;
}

/**
 * The name of one adapter function, after loading the adapter file when it exists.
 *
 * The file is includes/siteops/redirects/<slug with underscores as hyphens>.php. A fake adapter of
 * the test suite has no file; its functions are defined by the stub store.
 *
 * @param string $slug Registry slug.
 * @param string $name One of 'ready', 'can', 'all', 'match', 'list_404s'.
 * @return callable-string|'' Empty when the slug, the name or the function is not usable.
 */
function aafm_site_redirect_function( string $slug, string $name ): string {
	if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $slug ) || ! in_array( $name, array( 'ready', 'can', 'all', 'match', 'list_404s' ), true ) ) {
		return '';
	}

	$file = AAFM_PLUGIN_DIR . 'includes/siteops/redirects/' . str_replace( '_', '-', $slug ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}

	$function = 'aafm_redirect_' . $slug . '_' . $name;
	if ( ! function_exists( $function ) ) {
		return '';
	}

	return $function;
}

/**
 * Call one adapter function through the isolation wrapper.
 *
 * Not used for match(), whose cache must travel by reference.
 *
 * @param string           $slug Registry slug.
 * @param string           $name 'ready', 'can', 'all' or 'list_404s'.
 * @param array<int,mixed> $args Positional arguments.
 * @return mixed The value, or null when the entry is not detected, the function is missing or the call threw.
 */
function aafm_site_redirect_call( string $slug, string $name, array $args = array() ) {
	if ( 'match' === $name || null === aafm_site_redirect_detected_entry( $slug ) ) {
		return null;
	}

	$function = aafm_site_redirect_function( $slug, $name );
	if ( '' === $function ) {
		return null;
	}

	aafm_siteops_redirect_load();
	$args   = array_values( $args );
	$result = aafm_siteops_no_http_call(
		static function () use ( $function, $args ) {
			return $function( ...$args );
		}
	);

	return $result['ok'] ? $result['value'] : null;
}

/**
 * Call one adapter's match() with its per-call cache passed by reference.
 *
 * $path is always the full canonical path, home path included. For an adapter whose registry
 * match_path is 'below_home' it is converted to the path below the home path first.
 *
 * @param string              $slug  Registry slug.
 * @param string              $path  Full canonical decoded path.
 * @param array<string,mixed> $cache The adapter's cache for this tool call.
 * @return array<string,mixed> One of the three match shapes; array( 'state' => 'unknown' ) when the call threw or returned anything else. An unknown answer keeps the adapter's 'reason' when it is a short lower-case code naming a plugin setting or filter the match cannot evaluate.
 */
function aafm_site_redirect_match( string $slug, string $path, array &$cache ): array {
	$unknown = array( 'state' => 'unknown' );
	$entry   = aafm_site_redirect_detected_entry( $slug );
	if ( null === $entry ) {
		return $unknown;
	}

	$function = aafm_site_redirect_function( $slug, 'match' );
	if ( '' === $function ) {
		return $unknown;
	}

	if ( 'below_home' === ( $entry['match_path'] ?? 'full' ) ) {
		$home = aafm_site_redirect_home_path();
		if ( '' !== $home ) {
			$path = '/' . ltrim( (string) substr( $path, strlen( $home ) ), '/' );
		}
	}

	aafm_siteops_redirect_load();
	$result = aafm_siteops_no_http_call(
		static function () use ( $function, $path, &$cache ) {
			return $function( $path, $cache );
		}
	);
	if ( ! $result['ok'] ) {
		return $unknown;
	}

	$value = $result['value'];
	if ( ! is_array( $value ) || ! isset( $value['state'] ) ) {
		return $unknown;
	}
	if ( 'no_match' === $value['state'] ) {
		return array( 'state' => 'no_match' );
	}
	if ( 'unknown' === $value['state'] && isset( $value['reason'] ) && is_string( $value['reason'] ) && 1 === preg_match( '/^[a-z0-9_]{1,64}$/', $value['reason'] ) ) {
		return array(
			'state'  => 'unknown',
			'reason' => $value['reason'],
		);
	}
	if ( 'matched' === $value['state'] && isset( $value['id'], $value['target'], $value['code'] ) && is_string( $value['id'] ) && is_string( $value['target'] ) && is_int( $value['code'] ) ) {
		// A redirect made by a plugin setting rather than a stored rule names that setting; a
		// match must name a rule or a setting.
		$setting = isset( $value['setting'] ) && is_string( $value['setting'] ) && 1 === preg_match( '/^[a-z0-9_]{1,64}$/', $value['setting'] ) ? $value['setting'] : '';
		if ( '' === $value['id'] && '' === $setting ) {
			return $unknown;
		}
		$matched = array(
			'state'  => 'matched',
			'id'     => $value['id'],
			'target' => $value['target'],
			'code'   => $value['code'],
		);
		if ( '' !== $setting ) {
			$matched['setting'] = $setting;
		}
		if ( true === ( $value['approximate'] ?? null ) ) {
			// A WordPress redirect can answer the real request before this one.
			$matched['approximate'] = true;
		}
		return $matched;
	}

	return $unknown;
}

/**
 * Detected slugs whose ready() returned exactly true, in registry order.
 *
 * No static: the calling tool calls this once and passes the list on, so a plugin switched on or
 * off between two tool calls in one process is noticed.
 *
 * @return list<string>
 */
function aafm_site_redirect_ready_slugs(): array {
	$ready = array();
	foreach ( array_keys( aafm_site_redirect_adapters() ) as $slug ) {
		$slug = (string) $slug;
		if ( true === aafm_site_redirect_call( $slug, 'ready' ) ) {
			$ready[] = $slug;
		}
	}

	return $ready;
}

/**
 * A refusal of the redirect tools: a fixed, translated message that contains no input text.
 *
 * @param string            $code  One of the aafm_site_* codes below.
 * @param array<int,string> $slugs Ready slugs, for aafm_site_provider_required only.
 * @return WP_Error
 */
function aafm_site_redirect_error( string $code, array $slugs = array() ): WP_Error {
	switch ( $code ) {
		case 'aafm_site_provider_required':
			/* translators: %s: comma-separated provider names accepted by the provider argument. */
			$message = sprintf( __( 'More than one supported redirect plugin is active. Name one with the provider argument: %s.', 'agent-abilities-for-mcp' ), implode( ', ', $slugs ) );
			break;
		case 'aafm_site_provider_not_ready':
			$message = __( 'That redirect plugin is not active on this site, its redirect feature is off, or it could not be read.', 'agent-abilities-for-mcp' );
			break;
		case 'aafm_site_provider_capability':
			$message = __( 'Your account does not have the capability that redirect plugin requires for this.', 'agent-abilities-for-mcp' );
			break;
		case 'aafm_site_redirect_not_found':
			$message = __( 'No redirect with that id was found in that plugin.', 'agent-abilities-for-mcp' );
			break;
		case 'aafm_site_redirect_too_many_rules':
			$message = __( 'That plugin holds more than 5,000 redirects and the id was not among those read.', 'agent-abilities-for-mcp' );
			break;
		default:
			$code    = 'aafm_site_no_provider';
			$message = __( 'No supported redirect plugin is active and ready on this site.', 'agent-abilities-for-mcp' );
	}

	return new WP_Error( $code, $message );
}

/**
 * One rule by id, found by scanning the provider's own listing, so the only ids that can match
 * are ids the adapter itself produced.
 *
 * @param string $slug Registry slug.
 * @param string $id   The id from tool input.
 * @return array<string,mixed>|WP_Error The normalised rule; aafm_site_redirect_not_found;
 *                                      aafm_site_redirect_too_many_rules when the list was
 *                                      truncated and the id was not in the part that was read;
 *                                      aafm_site_provider_not_ready when the list could not be read.
 */
function aafm_site_redirect_find( string $slug, string $id ) {
	if ( ! aafm_site_redirect_valid_id( $id ) ) {
		return aafm_site_redirect_error( 'aafm_site_redirect_not_found' );
	}

	$all = aafm_site_redirect_call( $slug, 'all' );
	if ( ! is_array( $all ) || ! isset( $all['rows'] ) || ! is_array( $all['rows'] ) ) {
		return aafm_site_redirect_error( 'aafm_site_provider_not_ready' );
	}

	foreach ( $all['rows'] as $row ) {
		if ( is_array( $row ) && isset( $row['id'] ) && is_scalar( $row['id'] ) && (string) $row['id'] === $id ) {
			$row['provider'] = $slug;
			return $row;
		}
	}

	if ( ! empty( $all['truncated'] ) ) {
		return aafm_site_redirect_error( 'aafm_site_redirect_too_many_rules' );
	}

	return aafm_site_redirect_error( 'aafm_site_redirect_not_found' );
}

/**
 * The provider a single-rule read uses.
 *
 * @param string|null $requested Slug from input, or null.
 * @return string|WP_Error The slug; aafm_site_provider_not_ready, aafm_site_provider_capability,
 *                         aafm_site_no_provider or aafm_site_provider_required.
 */
function aafm_site_redirect_resolve_provider( ?string $requested ) {
	$ready = aafm_site_redirect_ready_slugs();

	if ( null === $requested ) {
		if ( array() === $ready ) {
			return aafm_site_redirect_error( 'aafm_site_no_provider' );
		}
		if ( count( $ready ) > 1 ) {
			return aafm_site_redirect_error( 'aafm_site_provider_required', $ready );
		}
		$requested = $ready[0];
	} elseif ( ! in_array( $requested, $ready, true ) ) {
		return aafm_site_redirect_error( 'aafm_site_provider_not_ready' );
	}

	if ( true !== aafm_site_redirect_call( $requested, 'can', array( 'read' ) ) ) {
		return aafm_site_redirect_error( 'aafm_site_provider_capability' );
	}

	return $requested;
}
