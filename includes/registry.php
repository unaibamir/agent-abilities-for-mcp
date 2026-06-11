<?php
/**
 * Static ability registry — the single source of truth for the UI and the MCP server.
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
	static $cache = null;

	if ( aafm_registry_cache_should_flush() ) {
		$cache = null;
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
 * Whether the registry memo should be flushed on the next read, consuming the flag.
 *
 * The catalog is fixed once the plugin loads (every domain file adds its filter at
 * include time), so production never raises this. It exists so tests that add or
 * remove an 'aafm_abilities_registry' filter mid-run can force one rebuild.
 *
 * @param bool|null $set Internal: true to raise the flush flag, null to read+consume.
 * @return bool True when the caller should rebuild the memo.
 */
function aafm_registry_cache_should_flush( ?bool $set = null ): bool {
	static $flush = false;

	if ( true === $set ) {
		$flush = true;
		return false;
	}

	if ( $flush ) {
		$flush = false;
		return true;
	}

	return false;
}

/**
 * Flush the in-request registry memo so the next read rebuilds it.
 *
 * @return void
 */
function aafm_flush_registry_cache(): void {
	aafm_registry_cache_should_flush( true );
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
