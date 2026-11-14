<?php
/**
 * WPBakery Page Builder integration (plugin folder js_composer, formerly Visual Composer): a read
 * ability returning a page's raw shortcode markup and an element outline, and a guarded write
 * ability that replaces text between shortcode tags without changing any element or attribute.
 *
 * Registers ONLY while WPBakery Page Builder is active (aafm_integration_active('wpbakery')).
 * WPBakery keeps its whole layout as shortcodes in post_content, so both abilities gate on the
 * ordinary edit access check every other content ability uses (aafm_perm_avada_post_object(),
 * which is builder neutral). WPBakery's own Role Manager is not consulted.
 *
 * Vendor facts were read from WPBakery Page Builder 9.1 (plugin folder js_composer); see design
 * doc 322 section 4.2. Add-ons map their own tags under any name, so the tag list each call
 * guards is scanned from the content itself (aafm_wpbakery_shortcode_tags()), not taken from a
 * prefix or from WPBakery's element map.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Every distinct shortcode name that appears in the given strings, merged.
 *
 * The scan accepts every name get_shortcode_regex() could match: opening tags, closing tags
 * ([/tag]) and the escaped [[tag]] form. A name is the run of characters add_shortcode() allows
 * (anything but <>&/[]= whitespace and control characters); the leading word part of that run is
 * added too, because get_shortcode_regex() ends a name at any character outside [\w-], so
 * [acme.box] also renders as the shortcode acme. A bracketed word in body text, such as [sic],
 * becomes a name as well; that can only make a later structure check refuse, never pass.
 *
 * @param string ...$contents Strings to scan.
 * @return array<int,string>|null Distinct names, or null above 300 names (treated as unparseable).
 */
function aafm_wpbakery_shortcode_tags( string ...$contents ): ?array {
	$names = array();
	foreach ( $contents as $content ) {
		if ( false === strpos( $content, '[' ) ) {
			continue;
		}
		if ( false === preg_match_all( '/\[\/?([^<>&\/\[\]\x00-\x20=]+)/', $content, $matches ) ) {
			return null;
		}
		foreach ( $matches[1] as $name ) {
			$names[ $name ] = true;
			if ( 1 === preg_match( '/^[\w-]+/', $name, $word ) ) {
				$names[ $word[0] ] = true;
			}
		}
		if ( count( $names ) > 300 ) {
			return null;
		}
	}
	return array_map( 'strval', array_keys( $names ) );
}
