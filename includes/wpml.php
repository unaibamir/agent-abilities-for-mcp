<?php
/**
 * WPML-aware language layer. Every function is a no-op when WPML is not loaded,
 * so a non-multilingual site behaves exactly as before this file existed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether WPML core is loaded and usable. Uses the documented `wpml_loaded`
 * action, not the undocumented ICL_* constants.
 */
function aafm_wpml_active(): bool {
	return (bool) did_action( 'wpml_loaded' );
}

/**
 * Active language codes, or an empty array when WPML is off or not ready.
 *
 * @return array<int,string>
 */
function aafm_wpml_active_language_codes(): array {
	if ( ! aafm_wpml_active() ) {
		return array();
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
	$langs = apply_filters( 'wpml_active_languages', null );
	if ( ! is_array( $langs ) ) {
		return array();
	}
	return array_values( array_map( 'strval', array_keys( $langs ) ) );
}

/** The site default language code, or null when WPML is off. */
function aafm_wpml_default_language(): ?string {
	if ( ! aafm_wpml_active() ) {
		return null;
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
	$code = apply_filters( 'wpml_default_language', null );
	return is_string( $code ) && '' !== $code ? $code : null;
}

/** The current request language code, or null when WPML is off. */
function aafm_wpml_current_language(): ?string {
	if ( ! aafm_wpml_active() ) {
		return null;
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
	$code = apply_filters( 'wpml_current_language', null );
	return is_string( $code ) && '' !== $code ? $code : null;
}

/**
 * The reusable `lang` input-schema property. Spread into an ability's
 * `properties` array. Kept as a fragment so the declaration never drifts.
 *
 * @return array<string,mixed>
 */
function aafm_lang_schema_fragment(): array {
	return array(
		'lang' => array(
			'type'        => 'string',
			'description' => __( 'WPML language code to scope the query to (for example "en"), or "all" to span every active language. Ignored when WPML is not active. When omitted, the site default language is used.', 'agent-abilities-for-mcp' ),
		),
	);
}

/**
 * Resolve a requested language from validated input.
 *
 * @param array<string,mixed> $input Ability input.
 * @return string|null A valid active language code, the literal 'all', or null
 *                     (no scoping: WPML off, none requested, or invalid code).
 */
function aafm_resolve_lang( array $input ): ?string {
	if ( ! aafm_wpml_active() || ! isset( $input['lang'] ) ) {
		return null;
	}
	$lang = (string) $input['lang'];
	if ( 'all' === $lang ) {
		return 'all';
	}
	return in_array( $lang, aafm_wpml_active_language_codes(), true ) ? $lang : null;
}

/**
 * Run $fn inside a WPML language scope, then restore the original language.
 * Snapshot-switch-restore per the house wordpress-safety rule; restores even
 * when $fn throws. When $lang is null or WPML is off, $fn runs unscoped.
 *
 * @param string|null $lang Target code, 'all', or null for no switch.
 * @param callable    $fn   Zero-arg callback.
 * @return mixed The callback's return value.
 *
 * phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.fnFound -- $fn is the contracted parameter name in the plan.
 */
function aafm_with_language( ?string $lang, callable $fn ) {
	// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames.fnFound
	if ( null === $lang || ! aafm_wpml_active() ) {
		return $fn();
	}
	$original = aafm_wpml_current_language();
	$switch   = ( null !== $original && $lang !== $original );
	if ( $switch ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
		do_action( 'wpml_switch_language', $lang );
	}
	try {
		return $fn();
	} finally {
		if ( $switch ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
			do_action( 'wpml_switch_language', $original );
		}
	}
}

/**
 * A post's WPML language code, or null when WPML is off.
 *
 * @param int $post_id Post id.
 */
function aafm_wpml_post_language( int $post_id ): ?string {
	if ( ! aafm_wpml_active() ) {
		return null;
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
	$details = apply_filters( 'wpml_post_language_details', null, $post_id );
	return is_array( $details ) && ! empty( $details['language_code'] )
		? (string) $details['language_code']
		: null;
}

/**
 * Per-status counts for a post type, scoped to a WPML language when active.
 * Mirrors the shape of wp_count_posts() (status => int) but, under WPML, is
 * language-correct because wp_count_posts() is language-blind. When WPML is off
 * this delegates to wp_count_posts() so non-multilingual sites are unaffected.
 *
 * @param string      $type Post type (already validated by the caller).
 * @param string|null $lang Resolved language, 'all', or null.
 * @return array<string,int> Status => count.
 */
function aafm_wpml_count_posts_by_status( string $type, ?string $lang ): array {
	if ( ! aafm_wpml_active() ) {
		$counts = (array) wp_count_posts( $type, 'readable' );
		return array_map( 'intval', $counts );
	}
	$statuses = get_post_stati();
	return aafm_with_language(
		'all' === $lang ? 'all' : $lang,
		static function () use ( $type, $statuses ): array {
			$out = array();
			foreach ( $statuses as $status ) {
				$q              = new WP_Query(
					array(
						'post_type'        => $type,
						'post_status'      => $status,
						'posts_per_page'   => 1,
						'fields'           => 'ids',
						'no_found_rows'    => false,
						'suppress_filters' => false,
					)
				);
				$out[ $status ] = (int) $q->found_posts;
			}
			return $out;
		}
	);
}

/**
 * Translate an element id into a target language via wpml_object_id (plain
 * element types only). Returns the original id when WPML is off or when there
 * is no translation (return_original_if_missing = true).
 *
 * @param int         $id   Element id.
 * @param string      $type Plain type: post, page, {cpt}, category, post_tag, attachment.
 * @param string|null $lang Target language, or null for current.
 */
function aafm_wpml_translated_id( int $id, string $type, ?string $lang ): int {
	if ( ! aafm_wpml_active() ) {
		return $id;
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
	$translated = apply_filters( 'wpml_object_id', $id, $type, true, $lang );
	return is_numeric( $translated ) ? (int) $translated : $id;
}
