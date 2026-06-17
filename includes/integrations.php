<?php
/**
 * Third-party-integration detection layer (Wave 4).
 *
 * Every integration ability registers and is discoverable ONLY when its host plugin is
 * active. Detection is centralised here and is FILTERABLE per slug
 * (aafm_integration_active_<slug>) so the PHPUnit suite can force an integration on
 * WITHOUT installing the host plugin, then stub the host API in the fixture. SEO is a
 * unified set routed across Yoast / Rank Math / AIOSEO: aafm_seo_active_plugin() reports
 * which is active, and the aafm_seo_meta_keys filter maps the unified fields to that
 * plugin's meta keys.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether a given integration's host plugin is active (so its abilities should
 * register / discover).
 *
 * @param string $slug One of 'seo' | 'acf' | 'woocommerce'.
 * @return bool
 */
function aafm_integration_active( string $slug ): bool {
	switch ( $slug ) {
		case 'seo':
			$active = '' !== aafm_seo_active_plugin();
			break;
		case 'acf':
			$active = class_exists( 'ACF' ) || function_exists( 'get_field' );
			break;
		case 'woocommerce':
			$active = class_exists( 'WooCommerce' );
			break;
		default:
			return false;
	}

	/**
	 * Filters whether an integration is active. Used by the test suite to force-enable an
	 * integration without installing the host plugin. Production passes the real detection
	 * through unchanged.
	 *
	 * @param bool $active Detected active state.
	 */
	return (bool) apply_filters( 'aafm_integration_active_' . $slug, $active );
}

/**
 * Which SEO plugin is active, '' if none. Sub-detection per the spec table.
 *
 * @return string '' | 'rankmath' | 'yoast' | 'aioseo'.
 */
function aafm_seo_active_plugin(): string {
	if ( class_exists( 'RankMath' ) ) {
		return 'rankmath';
	}
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}
	if ( function_exists( 'aioseo' ) ) {
		return 'aioseo';
	}
	return '';
}

/**
 * Map the unified SEO field names to the active plugin's post-meta keys.
 *
 * The returned array maps unified field => meta key for the active plugin. The
 * aafm_seo_meta_keys filter lets tests inject a map for a stubbed plugin (and lets a site
 * override a key). Only the keys present for the active plugin are returned; an unknown
 * plugin yields an empty map.
 *
 * @param string $plugin Active plugin slug from aafm_seo_active_plugin().
 * @return array<string,string> Unified field => meta key.
 */
function aafm_seo_meta_keys( string $plugin ): array {
	$maps = array(
		'yoast'    => array(
			'title'          => '_yoast_wpseo_title',
			'description'    => '_yoast_wpseo_metadesc',
			'focus_keyword'  => '_yoast_wpseo_focuskw',
			'canonical'      => '_yoast_wpseo_canonical',
			'og_title'       => '_yoast_wpseo_opengraph-title',
			'og_description' => '_yoast_wpseo_opengraph-description',
			'og_image'       => '_yoast_wpseo_opengraph-image',
		),
		'rankmath' => array(
			'title'          => 'rank_math_title',
			'description'    => 'rank_math_description',
			'focus_keyword'  => 'rank_math_focus_keyword',
			'canonical'      => 'rank_math_canonical_url',
			'og_title'       => 'rank_math_facebook_title',
			'og_description' => 'rank_math_facebook_description',
			'og_image'       => 'rank_math_facebook_image',
		),
		'aioseo'   => array(
			'title'       => '_aioseo_title',
			'description' => '_aioseo_description',
		),
	);

	$map = $maps[ $plugin ] ?? array();

	/**
	 * Filters the unified-field → meta-key map for the active SEO plugin.
	 *
	 * @param array<string,string> $map    Field => meta key.
	 * @param string               $plugin Active plugin slug.
	 */
	return (array) apply_filters( 'aafm_seo_meta_keys', $map, $plugin );
}
