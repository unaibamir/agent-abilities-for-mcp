<?php
/**
 * What the owner and the agent are told about another plugin's tools: which plugin they come from.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The namespace of a foreign ability slug: the text before the first slash, "core" when there is none.
 *
 * @param string $slug Foreign ability slug.
 * @return string
 */
function aafm_bridge_namespace_of( string $slug ): string {
	$pos = strpos( $slug, '/' );
	return false === $pos ? 'core' : substr( $slug, 0, $pos );
}

/**
 * Make a plugin's Name header safe to repeat: another plugin wrote it, so it is untrusted text.
 *
 * Tags are stripped, control and invisible formatting characters and runs of whitespace collapse to
 * one space, and the result is capped at 60 characters. HTML output still escapes it.
 *
 * @param string $name Raw header value.
 * @return string Plain text, '' when nothing is left.
 */
function aafm_bridge_clean_plugin_name( string $name ): string {
	$name = wp_strip_all_tags( wp_check_invalid_utf8( $name, true ) );
	$name = trim( (string) preg_replace( '/[\s\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $name ) );
	return trim( mb_substr( $name, 0, 60 ) );
}

/**
 * The Name header of the active plugin whose folder, main-file name or text domain is this namespace.
 *
 * Only for the heading, the source line and the error path: get_plugins() parses every plugin file
 * when the object cache is cold, so the Activity Log's query path must never reach this.
 *
 * @param string $ns Ability namespace.
 * @return string Cleaned name, '' when no active plugin matches.
 */
function aafm_bridge_active_plugin_name( string $ns ): string {
	static $built_for = null;
	static $names     = array();

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$active = array_filter( (array) get_option( 'active_plugins', array() ), 'is_string' );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	$signature = implode( '|', $active );
	if ( $signature !== $built_for ) {
		$built_for = $signature;
		$names     = array();
		$plugins   = get_plugins();
		foreach ( $active as $file ) {
			$file = (string) $file;
			if ( ! isset( $plugins[ $file ] ) ) {
				continue;
			}
			$name = aafm_bridge_clean_plugin_name( (string) ( $plugins[ $file ]['Name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$candidates = array(
				false === strpos( $file, '/' ) ? '' : dirname( $file ),
				basename( $file, '.php' ),
				(string) ( $plugins[ $file ]['TextDomain'] ?? '' ),
			);
			foreach ( $candidates as $candidate ) {
				if ( '' !== $candidate && ! isset( $names[ strtolower( $candidate ) ] ) ) {
					$names[ strtolower( $candidate ) ] = $name;
				}
			}
		}
	}

	return $names[ $ns ] ?? '';
}

/**
 * The plugin name we can stand behind for a namespace: the known map, our integration cards, then an
 * active plugin's header. A Title Case guess is never a plugin name, so this returns '' without a match.
 *
 * @param string $ns Ability namespace.
 * @return string
 */
function aafm_bridge_provider_label( string $ns ): string {
	$known = aafm_bridge_known_plugin_labels();
	if ( isset( $known[ $ns ] ) ) {
		return $known[ $ns ];
	}

	$cards = aafm_integration_cards();
	if ( isset( $cards[ $ns ]['label'] ) ) {
		return (string) $cards[ $ns ]['label'];
	}

	return aafm_bridge_active_plugin_name( $ns );
}
