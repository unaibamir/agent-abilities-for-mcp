<?php
/**
 * Third-party-integration detection layer (Wave 4).
 *
 * Every integration ability registers and is discoverable ONLY when its host plugin is
 * active. Detection is centralised here and is FILTERABLE per slug
 * (aafm_integration_active_<slug>) so the PHPUnit suite can force an integration on
 * WITHOUT installing the host plugin, then stub the host API in the fixture. SEO is
 * detected per plugin: yoast (defined WPSEO_VERSION), rankmath (class RankMath),
 * aioseo (function aioseo). Each per-plugin set registers on its own predicate, so a
 * site running two SEO plugins genuinely contributes both surfaces (no first-match-wins
 * exclusivity). The shared SEO permission gates and the recursive JSON-LD sanitizer also
 * live here so the per-plugin ability files can reuse them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether a given integration's host plugin is active (so its abilities should
 * register / discover).
 *
 * @param string $slug One of 'yoast' | 'rankmath' | 'aioseo' | 'acf' | 'woocommerce'.
 * @return bool
 */
function aafm_integration_active( string $slug ): bool {
	switch ( $slug ) {
		case 'yoast':
			$active = aafm_yoast_active();
			break;
		case 'rankmath':
			$active = aafm_rankmath_active();
			break;
		case 'aioseo':
			$active = aafm_aioseo_active();
			break;
		case 'acf':
			$active = aafm_acf_active();
			break;
		case 'woocommerce':
			$active = aafm_woocommerce_active();
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
 * Whether Yoast SEO is active, behind a filterable seam.
 *
 * Real detection is defined('WPSEO_VERSION'). Wrapped in its own filter so the PHPUnit suite can
 * pin it deterministically: the suite stubs Yoast by defining WPSEO_VERSION process-wide, and a
 * defined constant can never be undefined, so the host-inactive registry test cannot flip detection
 * back off by removing the aafm_integration_active_yoast force filter alone. Driving this seam to
 * false through aafm_yoast_active lets that test force detection OFF deterministically. Production
 * passes the real detection through unchanged.
 *
 * @return bool
 */
function aafm_yoast_active(): bool {
	$active = defined( 'WPSEO_VERSION' );

	/**
	 * Filters whether Yoast SEO is reported active.
	 *
	 * @param bool $active Detected active state.
	 */
	return (bool) apply_filters( 'aafm_yoast_active', $active );
}

/**
 * Whether Rank Math is active, behind a filterable seam (see aafm_yoast_active for the rationale;
 * Rank Math is stubbed with a process-wide RankMath marker class).
 *
 * @return bool
 */
function aafm_rankmath_active(): bool {
	$active = class_exists( 'RankMath' );

	/**
	 * Filters whether Rank Math is reported active.
	 *
	 * @param bool $active Detected active state.
	 */
	return (bool) apply_filters( 'aafm_rankmath_active', $active );
}

/**
 * Whether All in One SEO is active, behind a filterable seam (see aafm_yoast_active for the
 * rationale; AIOSEO is stubbed with a process-wide aioseo() marker function).
 *
 * @return bool
 */
function aafm_aioseo_active(): bool {
	$active = function_exists( 'aioseo' );

	/**
	 * Filters whether AIOSEO is reported active.
	 *
	 * @param bool $active Detected active state.
	 */
	return (bool) apply_filters( 'aafm_aioseo_active', $active );
}

/**
 * Per-object permission shared by every per-plugin SEO read/write: the caller may edit THIS post
 * (SEO meta is post content). Relocated here from the deleted unified seo.php so the yoast / rankmath
 * / aioseo ability files reuse one gate.
 *
 * @param array<string,mixed> $input Validated input.
 * @return bool
 */
function aafm_perm_seo_post_object( array $input ): bool {
	$id = absint( $input['post_id'] ?? 0 );
	return $id > 0 && get_post( $id ) instanceof WP_Post && current_user_can( 'edit_post', $id );
}

/**
 * Object-independent floor for the per-plugin *-get-head abilities: the caller can author posts at
 * all. The per-object edit_post($id) refinement runs inside execute. This floor lets discovery
 * (empty input) advertise the tool to a capable user, mirroring the documented FSE/floor pattern.
 *
 * @return bool
 */
function aafm_perm_seo_get_head_floor(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Recursively sanitize a JSON-LD schema array. Relocated here from the deleted unified seo.php so the
 * Rank Math schema writer can reuse it; integrations.php loads before the ability files, so the
 * function exists at registration time.
 *
 * At every depth: arrays recurse; a value under a url-ish key (url / image / logo / sameAs / @id,
 * case-insensitive) is run through esc_url_raw so a javascript: scheme is dropped; every other scalar
 * leaf is run through sanitize_text_field, which strips <script> tags and control noise; anything that
 * is neither scalar nor array (objects, resources) is dropped. So script payloads cannot survive at
 * any level.
 *
 * @param array<int|string,mixed> $schema Schema array.
 * @return array<int|string,mixed>
 */
function aafm_sanitize_schema_array( array $schema ): array {
	$url_keys = array( 'url', 'image', 'logo', 'sameas', '@id', 'contenturl', 'thumbnailurl' );
	$clean    = array();
	foreach ( $schema as $key => $value ) {
		$safe_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;
		if ( is_array( $value ) ) {
			$clean[ $safe_key ] = aafm_sanitize_schema_array( $value );
			continue;
		}
		if ( ! is_scalar( $value ) ) {
			continue; // Drop objects / resources / null.
		}
		$as_string = is_bool( $value ) ? $value : (string) $value;
		if ( is_string( $safe_key ) && in_array( strtolower( $safe_key ), $url_keys, true ) ) {
			$clean[ $safe_key ] = esc_url_raw( (string) $as_string );
		} else {
			$clean[ $safe_key ] = is_bool( $as_string ) ? $as_string : sanitize_text_field( (string) $as_string );
		}
	}
	return $clean;
}

/**
 * Which SEO plugin is active, '' if none. Sub-detection per the spec table.
 *
 * @deprecated Wave 5: superseded by the per-plugin predicates (aafm_yoast_active /
 * aafm_rankmath_active / aafm_aioseo_active). Retained only until the unified seo.php is removed.
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
 * Whether WooCommerce is active, behind a filterable seam.
 *
 * Real detection is class_exists('WooCommerce'). This is wrapped in its own filter for the same
 * reason the ACF sub-detection is: the PHPUnit suite stubs the WooCommerce host API by defining a
 * WooCommerce marker class process-wide, and a defined class can never be undefined. So a test that
 * needs WooCommerce reported INACTIVE (the host-absent default, the tab "Not installed" state)
 * cannot flip detection back off by removing the aafm_integration_active_woocommerce force filter
 * alone — real detection would still see the stubbed class and report WooCommerce active. Driving
 * this seam to false through aafm_woocommerce_active lets those tests force detection OFF
 * deterministically, mirroring how aafm_acf_active and aafm_seo_active_plugin are pinned.
 * Production passes the real detection through unchanged.
 *
 * @return bool
 */
function aafm_woocommerce_active(): bool {
	$active = class_exists( 'WooCommerce' );

	/**
	 * Filters whether WooCommerce is reported active. Production passes real detection through; the
	 * test suite uses this to pin WooCommerce inactive deterministically (the WooCommerce marker
	 * class the stub defines is process-permanent, so removing the force filter alone is not enough).
	 *
	 * @param bool $active Detected active state.
	 */
	return (bool) apply_filters( 'aafm_woocommerce_active', $active );
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
