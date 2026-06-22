<?php
/**
 * Static ability registry — the single source of truth for the UI and the MCP server.
 *
 * EXTENSION POINTS (the single discoverability anchor for third-party developers):
 *
 * - `aafm_abilities_registry` (filter): add a row to the live, host-gated catalog. Each row is
 *   keyed by ability name (e.g. 'aafm/get-posts') and carries the UI/registration metadata plus
 *   an 'args' builder. Rows added here register as real abilities and appear in the admin UI.
 *   Applied in aafm_get_abilities_registry().
 *
 * - `aafm_abilities_registry_integrations` (filter): add integration rows UNCONDITIONALLY (no
 *   host-active guard) so the Integrations tab and the manifest can enumerate an integration even
 *   when its host plugin is inactive. These rows feed the full catalog view only, never the
 *   registration walk. Applied in aafm_get_abilities_registry_full().
 *
 * Both filters receive an array keyed by ability name and must return the same shape. Domain and
 * integration files in includes/abilities/ are the in-tree examples of registering against them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The full catalog of available abilities, keyed by ability name.
 *
 * Each entry is the metadata the UI needs plus an 'args' builder reference. Domain
 * files contribute their definitions via the 'aafm_abilities_registry' filter.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_get_abilities_registry(): array {
	static $cache    = null;
	static $consumed = 0;

	$generation = aafm_registry_flush_generation();
	if ( $generation > $consumed ) {
		$consumed = $generation;
		$cache    = null;
	}

	if ( null !== $cache ) {
		return $cache;
	}

	/**
	 * Filters the static ability registry.
	 *
	 * @param array<string,array<string,mixed>> $registry Registry keyed by ability name.
	 */
	$cache = (array) apply_filters( 'aafm_abilities_registry', array() );

	return $cache;
}

/**
 * The full catalog of available abilities, INCLUDING integration rows whose host plugin is inactive.
 *
 * The live aafm_get_abilities_registry() is host-gated: an integration contributes its rows only
 * while its host plugin is active, so on a site without WooCommerce the live registry has no
 * WC rows. That gating is correct for registration — actual wp_register_ability() must only fire for
 * active hosts — but the catalog still "counts" inactive integrations, and the Integrations tab and
 * the manifest need every integration's label/description even when its host is off.
 *
 * This view answers that need WITHOUT touching the registration path. It starts from the live
 * registry, then overlays every integration's definitions from the unguarded
 * 'aafm_abilities_registry_integrations' filter — each integration file contributes its rows there
 * with NO host guard. For an active host the overlay is identical to what the live registry already
 * holds; for an inactive host it adds the rows the guard withheld. The registration walk
 * (aafm_register_enabled_abilities()) reads aafm_get_abilities_registry(), never this, so an inactive
 * host still exposes zero tools.
 *
 * Memoized per request and flushed by the same aafm_flush_registry_cache() flag as the live registry,
 * so host-toggling tests stay consistent across both views.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_get_abilities_registry_full(): array {
	static $cache = null;

	if ( aafm_registry_cache_should_flush_full() ) {
		$cache = null;
	}

	if ( null !== $cache ) {
		return $cache;
	}

	$registry = aafm_get_abilities_registry();

	/**
	 * Filters the guard-independent integration definitions overlaid onto the full registry view.
	 *
	 * Integration files contribute their rows here UNCONDITIONALLY (no host-active guard), so the
	 * full view enumerates every integration ability even when its host plugin is inactive.
	 *
	 * @param array<string,array<string,mixed>> $integrations Integration rows keyed by ability name.
	 */
	$integrations = (array) apply_filters( 'aafm_abilities_registry_integrations', array() );

	$cache = array_merge( $registry, $integrations );

	return $cache;
}

/**
 * The canonical label for an ability, taken from its registry definition.
 *
 * The registry entry is the single source of truth for an ability's label, so the args builder, the
 * Integrations manifest, and the admin UI all read it back through this rather than re-typing it. The
 * underlying __() literal stays at the registry-definition site, so this returns the already-translated
 * string and Plugin Check's i18n scanner still sees a real literal at the source. Reads the full view
 * so a derive call works even for an integration whose host is currently inactive.
 *
 * @param string $name Ability name, e.g. 'aafm/get-posts'.
 * @return string The canonical label, or '' when the name is unknown.
 */
function aafm_ability_label( string $name ): string {
	$registry = aafm_get_abilities_registry_full();

	return isset( $registry[ $name ]['label'] ) ? (string) $registry[ $name ]['label'] : '';
}

/**
 * The canonical description for an ability, taken from its registry definition.
 *
 * The counterpart to aafm_ability_label(): the registry description is authoritative, and the args
 * builder and manifest derive from it instead of keeping their own copy. The __() literal stays at the
 * registry site (so i18n extraction and Plugin Check are unaffected); this returns the translated
 * string. Reads the full view so it resolves for an inactive-host integration too.
 *
 * @param string $name Ability name, e.g. 'aafm/get-posts'.
 * @return string The canonical description, or '' when the name is unknown.
 */
function aafm_ability_description( string $name ): string {
	$registry = aafm_get_abilities_registry_full();

	return isset( $registry[ $name ]['description'] ) ? (string) $registry[ $name ]['description'] : '';
}

/**
 * Whether the full-view memo should be flushed on the next read, consuming the flag.
 *
 * Driven off the same flush flag as the live registry: a single aafm_flush_registry_cache() raises a
 * shared counter, and each view consumes it once per raise so both rebuild together. Without this the
 * full view could serve stale rows after a test toggles a host filter and flushes.
 *
 * @return bool True when aafm_get_abilities_registry_full() should rebuild its memo.
 */
function aafm_registry_cache_should_flush_full(): bool {
	static $consumed = 0;

	$generation = aafm_registry_flush_generation();
	if ( $generation > $consumed ) {
		$consumed = $generation;
		return true;
	}

	return false;
}

/**
 * Raise (or read) the registry flush flag.
 *
 * The catalog is fixed once the plugin loads (every domain file adds its filter at
 * include time), so production never raises this. It exists so tests that add or
 * remove an 'aafm_abilities_registry' filter mid-run can force one rebuild.
 *
 * Internally this bumps a monotonic flush generation (aafm_registry_flush_generation()) so that BOTH
 * memoized views — the live registry and the full view — each observe the flush exactly once. The
 * historical signature is preserved: pass true to raise a flush, call with no argument to peek at
 * whether the live registry is currently behind the generation. Callers should prefer
 * aafm_flush_registry_cache(); this stays public because existing tests call it as
 * aafm_registry_cache_should_flush( true ).
 *
 * @param bool|null $set Internal: true to raise a flush generation, null to peek at the live view.
 * @return bool True when the live registry is currently behind the flush generation.
 */
function aafm_registry_cache_should_flush( ?bool $set = null ): bool {
	if ( true === $set ) {
		aafm_registry_flush_generation( true );
		return false;
	}

	return aafm_registry_flush_generation() > 0;
}

/**
 * The current registry flush generation, a monotonic counter raised on every flush.
 *
 * Each memoized view (live registry and full view) keeps its own "last consumed" generation and
 * rebuilds when this counter has advanced past it, so a single flush invalidates both views once.
 *
 * @param bool $raise Internal: true to advance the generation by one.
 * @return int The current generation.
 */
function aafm_registry_flush_generation( bool $raise = false ): int {
	static $generation = 0;

	if ( $raise ) {
		++$generation;
	}

	return $generation;
}

/**
 * Flush the in-request registry memo so the next read rebuilds it.
 *
 * @return void
 */
function aafm_flush_registry_cache(): void {
	aafm_registry_flush_generation( true );
}

/**
 * The option storing the operator's enabled-ability allow-list.
 *
 * @return array<int,string>
 */
function aafm_get_enabled_abilities(): array {
	$stored = get_option( 'aafm_enabled_abilities', array() );
	$stored = is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();

	// Only honor keys that still exist in the registry (stale keys never enable anything).
	$known = array_keys( aafm_get_abilities_registry() );
	return array_values( array_intersect( $stored, $known ) );
}

/**
 * Whether a specific ability is enabled by the operator.
 *
 * @param string $ability_name Ability name.
 * @return bool
 */
function aafm_is_ability_enabled( string $ability_name ): bool {
	return in_array( $ability_name, aafm_get_enabled_abilities(), true );
}
