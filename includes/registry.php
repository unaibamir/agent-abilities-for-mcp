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
	/**
	 * Filters the static ability registry.
	 *
	 * @param array<string,array<string,mixed>> $registry Registry keyed by ability name.
	 */
	return (array) apply_filters( 'aafm_abilities_registry', array() );
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
