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
	$id   = absint( $input['post_id'] ?? 0 );
	$post = $id > 0 ? get_post( $id ) : null;
	// Delegate to the shared content-edit gate (not a bare edit_post): it enforces the operator's
	// post-type exposure allowlist AND the map_meta_cap===true fail-open guard, so SEO meta on a
	// non-exposed or non-mapped type is refused exactly as the core content writes are.
	return $post instanceof WP_Post && aafm_can_edit_post_object( $post );
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
 * Recursion is depth-bounded (default 32 levels via AAFM_SCHEMA_MAX_DEPTH): a nested array deeper
 * than the limit is dropped rather than recursed into, so a maliciously or accidentally deep
 * payload cannot exhaust the stack. Real JSON-LD graphs are only a handful of levels deep, so the
 * bound never clips legitimate input.
 *
 * @param array<int|string,mixed> $schema Schema array.
 * @param int                     $depth  Current recursion depth (internal; callers pass 0).
 * @return array<int|string,mixed>
 */
function aafm_sanitize_schema_array( array $schema, int $depth = 0 ): array {
	if ( ! defined( 'AAFM_SCHEMA_MAX_DEPTH' ) ) {
		define( 'AAFM_SCHEMA_MAX_DEPTH', 32 );
	}

	$url_keys = array( 'url', 'image', 'logo', 'sameas', '@id', 'contenturl', 'thumbnailurl' );
	$clean    = array();
	foreach ( $schema as $key => $value ) {
		$safe_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;
		if ( is_array( $value ) ) {
			// Past the depth bound, drop the sub-tree rather than recursing further.
			if ( $depth >= AAFM_SCHEMA_MAX_DEPTH ) {
				continue;
			}
			$clean[ $safe_key ] = aafm_sanitize_schema_array( $value, $depth + 1 );
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
 * Whether ACF (or its fork SCF) is active, behind a filterable seam.
 *
 * Real detection is class_exists('ACF') || function_exists('get_field'). This is wrapped in its
 * own filter for the same reason the SEO sub-detection is: the PHPUnit suite stubs the ACF host
 * API by defining get_field() (and friends) process-wide, and a defined function can never be
 * undefined. So the host-inactive registry test cannot flip detection back off by removing the
 * aafm_integration_active_acf force filter alone - real detection would still see the stubbed
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
 * alone - real detection would still see the stubbed class and report WooCommerce active. Driving
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
