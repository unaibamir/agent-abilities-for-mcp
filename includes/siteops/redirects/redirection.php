<?php
/**
 * Redirect adapter: Redirection (redirection), read only. Benched on 5.10.1.
 *
 * Defines the five adapter functions and nothing else. Limit: a rule in an Apache-module group is
 * served by .htaccess and not by the plugin's PHP matcher, so match() can miss it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether the plugin is set up, its tables are current and its redirects are not switched off.
 *
 * A read-only probe: the plugin's install and upgrade methods are never called.
 *
 * @return bool
 */
function aafm_redirect_redirection_ready(): bool {
	foreach ( array( 'Red_Item', 'Red_Options', 'Red_Url_Request', 'Red_Url' ) as $class ) {
		if ( ! class_exists( $class ) ) {
			return false;
		}
	}
	$methods = array(
		'Red_Item'        => array( 'get_filtered', 'get_for_url', 'get_url', 'get_id', 'get_action_code' ),
		'Red_Url'         => array( 'is_match' ),
		'Red_Url_Request' => array( 'get_decoded_url', 'get_original_url', 'is_protected_url' ),
	);
	foreach ( $methods as $class => $names ) {
		foreach ( $names as $method ) {
			if ( ! method_exists( $class, $method ) ) {
				return false;
			}
		}
	}
	if ( ! is_callable( array( 'Red_Options', 'reset' ) ) || ! is_callable( array( 'Red_Options', 'get' ) ) || ! defined( 'REDIRECTION_DB_VERSION' ) ) {
		return false;
	}
	if ( function_exists( 'red_is_disabled' ) && red_is_disabled() ) {
		return false;
	}

	// The plugin memoises its options for the request.
	Red_Options::reset();
	$options = Red_Options::get();
	if ( ! is_array( $options ) ) {
		return false;
	}
	$database = ( isset( $options['database'] ) && is_scalar( $options['database'] ) ) ? (string) $options['database'] : '';

	// Not set up: the setup wizard never ran and no tables exist.
	if ( '' === $database && false === get_option( 'redirection_version', false ) ) {
		return false;
	}

	// Needs an upgrade: the tables are older than this version of the plugin expects.
	return ! version_compare( $database, (string) REDIRECTION_DB_VERSION, '<' ) && ! isset( $options['database_stage'] );
}

/**
 * Whether the current user holds the plugin's own capability, by the expression its own
 * capability class uses.
 *
 * @param string $action 'read' or 'read_404'.
 * @return bool
 */
function aafm_redirect_redirection_can( string $action ): bool {
	$capabilities = array(
		'read'     => 'redirection_cap_redirect_manage',
		'read_404' => 'redirection_cap_404_manage',
	);
	if ( ! isset( $capabilities[ $action ] ) ) {
		return false;
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own role filter, applied as its capability class applies it.
	$role = apply_filters( 'redirection_role', 'manage_options' );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own capability filter, applied as its capability class applies it.
	$capability = apply_filters( 'redirection_capability_check', $role, $capabilities[ $action ] );

	return is_string( $capability ) && current_user_can( $capability );
}

/**
 * Every rule, normalised. At most 25 pages of 200.
 *
 * @return array{rows:array<int,array<string,mixed>>,truncated:bool}
 * @throws \RuntimeException When the plugin's reader does not return its { total, items } wrapper.
 */
function aafm_redirect_redirection_all(): array {
	$rows      = array();
	$truncated = false;
	for ( $page = 0; $page < 25; $page++ ) {
		// The page number is zero based.
		$result = Red_Item::get_filtered(
			array(
				'per_page'  => 200,
				'page'      => $page,
				'orderby'   => 'id',
				'direction' => 'asc',
			)
		);
		if ( ! is_array( $result ) || ! isset( $result['items'] ) || ! is_array( $result['items'] ) ) {
			throw new \RuntimeException( 'aafm_redirect_redirection_all' );
		}

		foreach ( $result['items'] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
				continue;
			}
			$exact  = isset( $item['match_type'], $item['action_type'] ) && 'url' === $item['match_type'] && 'url' === $item['action_type'] && empty( $item['regex'] );
			$rows[] = array(
				'id'      => (string) $item['id'],
				'source'  => isset( $item['url'] ) ? (string) $item['url'] : '',
				'target'  => ( isset( $item['action_data'] ) && is_array( $item['action_data'] ) && isset( $item['action_data']['url'] ) && is_string( $item['action_data']['url'] ) ) ? $item['action_data']['url'] : '',
				'code'    => isset( $item['action_code'] ) ? (int) $item['action_code'] : 0,
				'enabled' => ! empty( $item['enabled'] ),
				'kind'    => $exact ? 'exact' : 'pattern',
				'hits'    => isset( $item['hits'] ) ? (int) $item['hits'] : 0,
			);
		}

		if ( count( $result['items'] ) < 200 ) {
			break;
		}
		if ( 24 === $page ) {
			$truncated = true;
		}
	}

	return array(
		'rows'      => $rows,
		'truncated' => $truncated,
	);
}

/**
 * Which rule a path would hit, through the plugin's own matcher. No hit is logged and no counter
 * is changed: the item method that logs a hit is never called.
 *
 * A conditional rule (login status, referrer and the like) is evaluated as the current request
 * would be, which is an administrator's API call and not a visitor.
 *
 * @param string              $path  Full canonical decoded path, home path included.
 * @param array<string,mixed> $cache Per-call cache; not used by this adapter.
 * @return array<string,mixed> A match shape.
 */
function aafm_redirect_redirection_match( string $path, array &$cache ): array {
	unset( $cache );
	$request = new Red_Url_Request( $path );
	foreach ( array( 'get_decoded_url', 'get_original_url', 'is_protected_url' ) as $m ) {
		if ( ! method_exists( $request, $m ) ) {
			return array( 'state' => 'unknown' );
		}
	}
	$decoded  = $request->get_decoded_url();
	$original = $request->get_original_url();
	if ( $request->is_protected_url() ) {
		// The plugin never redirects its protected (REST) URLs.
		return array( 'state' => 'no_match' );
	}

	// The plugin's site settings run on init before its rules (modules/wordpress.php,
	// models/canonical.php, 5.10.1). A site move sends every request outside three protected
	// paths to the same path on another domain, a match from that setting. With no move set, an
	// alias equal to this site's own host is redirected to the site address with a scheme taken
	// from the request, which the tool cannot model. The preferred domain, HTTPS and other aliases
	// act only on another host or scheme, and the tool accepts only this site's own address.
	$options = Red_Options::get();
	$options = is_array( $options ) ? $options : array();
	$host    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$move    = isset( $options['relocate'] ) && is_string( $options['relocate'] ) ? trim( $options['relocate'] ) : '';
	if ( '' !== $move ) {
		if ( strtolower( (string) wp_parse_url( ( false === strpos( $move, '://' ) ? 'http://' : '' ) . $move, PHP_URL_HOST ) ) !== $host ) {
			if ( false === strpos( $move, '://' ) || false !== has_filter( 'redirect_relocate_protected' ) || false !== has_filter( 'redirect_relocate_target' ) ) {
				return array(
					'state'  => 'unknown',
					'reason' => 'redirection_relocate',
				);
			}
			$protected = false;
			foreach ( array( '/wp-admin', '/wp-login.php', '/wp-json/' ) as $base ) {
				$protected = $protected || 0 === strpos( $path, $base );
			}
			if ( ! $protected ) {
				return array(
					'state'   => 'matched',
					'id'      => '',
					'target'  => rtrim( $move, '/' ) . $path,
					'code'    => 301,
					'setting' => 'redirection_relocate',
				);
			}
		}
	} elseif ( isset( $options['aliases'] ) && is_array( $options['aliases'] ) && in_array( $host, array_map( 'strtolower', array_map( 'strval', $options['aliases'] ) ), true ) ) {
		return array(
			'state'  => 'unknown',
			'reason' => 'redirection_site_alias',
		);
	}
	$unknown = false;
	foreach ( Red_Item::get_for_url( $decoded ) as $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_url' ) || ! method_exists( $item, 'get_id' ) || ! method_exists( $item, 'get_action_code' ) || ! property_exists( $item, 'source_flags' ) || ! property_exists( $item, 'match' ) ) {
			$unknown = true;
			continue;
		}
		$source = new Red_Url( $item->get_url() );
		if ( ! $source->is_match( $decoded, $item->source_flags ) ) {
			continue;
		}
		if ( ! is_object( $item->match ) || ! method_exists( $item->match, 'is_match' ) || ! method_exists( $item->match, 'get_target_url' ) ) {
			$unknown = true;
			continue;
		}
		$matched = $item->match->is_match( $decoded );
		$target  = $item->match->get_target_url( $original, $item->get_url(), $item->source_flags, $matched );
		if ( false === $target ) {
			continue;
		}
		return $unknown
			? array( 'state' => 'unknown' )
			: array(
				'state'  => 'matched',
				'id'     => (string) $item->get_id(),
				'target' => (string) $target,
				'code'   => (int) $item->get_action_code(),
			);
	}

	if ( $unknown ) {
		return array( 'state' => 'unknown' );
	}

	// Permalink migration runs on a page not found (pre_handle_404) and parses the address again
	// under each old permalink structure; that is not modelled. A page WordPress finds is never
	// migrated (models/permalinks.php needs_migrating()).
	if ( empty( $options['permalinks'] ) || in_array( aafm_site_redirect_path_class( $path )['class'], array( 'attachment', 'author', 'date', 'other' ), true ) ) {
		return array( 'state' => 'no_match' );
	}

	return array(
		'state'  => 'unknown',
		'reason' => 'redirection_permalink_migration',
	);
}

/**
 * The plugin's not-found log, grouped by address by the plugin itself. The grouped read has no
 * date, IP address or browser name.
 *
 * @return array<int,array{path:string,count:int,last_seen:int|null}>
 */
function aafm_redirect_redirection_list_404s(): array {
	if ( ! class_exists( 'Red_404_Log' ) || ! is_callable( array( 'Red_404_Log', 'get_grouped' ) ) ) {
		return array();
	}

	$result = Red_404_Log::get_grouped(
		'url',
		array(
			'per_page' => 200,
			'page'     => 0,
		)
	);
	if ( ! is_array( $result ) || ! isset( $result['items'] ) || ! is_array( $result['items'] ) ) {
		return array();
	}

	$out = array();
	foreach ( $result['items'] as $item ) {
		// The plugin returns objects; an array is accepted as well.
		$item = is_object( $item ) ? get_object_vars( $item ) : $item;
		if ( ! is_array( $item ) || ! isset( $item['url'] ) || ! is_string( $item['url'] ) ) {
			continue;
		}
		$out[] = array(
			'path'      => $item['url'],
			'count'     => isset( $item['count'] ) ? (int) $item['count'] : 0,
			'last_seen' => null,
		);
	}

	return $out;
}
