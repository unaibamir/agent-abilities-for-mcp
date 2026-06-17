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
			$active = aafm_acf_active();
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
		$plugin = 'rankmath';
	} elseif ( defined( 'WPSEO_VERSION' ) ) {
		$plugin = 'yoast';
	} elseif ( function_exists( 'aioseo' ) ) {
		$plugin = 'aioseo';
	} else {
		$plugin = '';
	}

	/**
	 * Filters which SEO plugin is reported active. Production passes the real detection through;
	 * the test suite uses this to pin the active plugin deterministically (the marker stubs it
	 * defines are process-permanent, so detection order alone is not enough to switch back).
	 *
	 * @param string $plugin Detected plugin slug ('' | 'rankmath' | 'yoast' | 'aioseo').
	 */
	return (string) apply_filters( 'aafm_seo_active_plugin', $plugin );
}

/**
 * Whether ACF (or its fork SCF) is active, behind a filterable seam.
 *
 * Real detection is class_exists('ACF') || function_exists('get_field'). This is wrapped in its
 * own filter for the same reason the SEO sub-detection is: the PHPUnit suite stubs the ACF host
 * API by defining get_field() (and friends) process-wide, and a defined function can never be
 * undefined. So the host-inactive registry test cannot flip detection back off by removing the
 * aafm_integration_active_acf force filter alone — real detection would still see the stubbed
 * get_field and report ACF active. Driving this seam to false through aafm_acf_active lets that
 * test force detection OFF deterministically, mirroring how aafm_seo_active_plugin is pinned.
 * Production passes the real detection through unchanged.
 *
 * @return bool
 */
function aafm_acf_active(): bool {
	$active = class_exists( 'ACF' ) || function_exists( 'get_field' );

	/**
	 * Filters whether ACF/SCF is reported active. Production passes real detection through; the
	 * test suite uses this to pin ACF inactive deterministically (the get_field marker stub it
	 * defines is process-permanent, so removing the force filter alone is not enough).
	 *
	 * @param bool $active Detected active state.
	 */
	return (bool) apply_filters( 'aafm_acf_active', $active );
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
			'title'               => '_yoast_wpseo_title',
			'description'         => '_yoast_wpseo_metadesc',
			'focus_keyword'       => '_yoast_wpseo_focuskw',
			'canonical'           => '_yoast_wpseo_canonical',
			'robots'              => '_yoast_wpseo_meta-robots-adv',
			'og_title'            => '_yoast_wpseo_opengraph-title',
			'og_description'      => '_yoast_wpseo_opengraph-description',
			'og_image'            => '_yoast_wpseo_opengraph-image',
			'twitter_title'       => '_yoast_wpseo_twitter-title',
			'twitter_description' => '_yoast_wpseo_twitter-description',
			'twitter_image'       => '_yoast_wpseo_twitter-image',
		),
		'rankmath' => array(
			'title'               => 'rank_math_title',
			'description'         => 'rank_math_description',
			'focus_keyword'       => 'rank_math_focus_keyword',
			'canonical'           => 'rank_math_canonical_url',
			'robots'              => 'rank_math_robots',
			'og_title'            => 'rank_math_facebook_title',
			'og_description'      => 'rank_math_facebook_description',
			'og_image'            => 'rank_math_facebook_image',
			'twitter_title'       => 'rank_math_twitter_title',
			'twitter_description' => 'rank_math_twitter_description',
			'twitter_image'       => 'rank_math_twitter_image',
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
