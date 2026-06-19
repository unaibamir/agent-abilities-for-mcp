<?php
/**
 * Static per-integration ability count manifest.
 *
 * Integration abilities register only while their host plugin is active, so a live registry walk
 * cannot tell you how many abilities WooCommerce "would" expose on a site where it is not
 * installed. This hand-maintained manifest holds those totals independent of host activation, so
 * an Integrations card for an inactive host can still show "0 / 67". It is the single count
 * contract for the integration surface, alongside aafm_available_ability_count() for the whole
 * catalog.
 *
 * KEEP IN LOCKSTEP WITH THE CATALOG. These numbers are NOT derived from the registry (that is the
 * point — they must hold when the host is inactive). The IntegrationManifestTest drift catcher
 * asserts that the manifest's per-slug totals, summed with the core (non-integration) count,
 * equal the catalog-lock total when every host is force-active. If you add or remove an
 * integration ability, update both the registry AND the matching slug here, or that test fails.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Per-integration ability counts, independent of whether the host plugin is active.
 *
 * Each slug maps to {total, read, write, destructive}, where total === read + write +
 * destructive. The slugs match the integration subjects used in the registry and the
 * Integrations cards (see aafm_integration_cards()).
 *
 * @return array<string,array{total:int,read:int,write:int,destructive:int}>
 */
function aafm_integration_manifest(): array {
	return array(
		// WooCommerce: 67 abilities (32 read, 23 write, 12 destructive).
		'woocommerce' => array(
			'total'       => 67,
			'read'        => 32,
			'write'       => 23,
			'destructive' => 12,
		),
		// ACF: 7 abilities (4 read, 3 write, 0 destructive).
		'acf'         => array(
			'total'       => 7,
			'read'        => 4,
			'write'       => 3,
			'destructive' => 0,
		),
		// Yoast SEO: 3 abilities (2 read, 1 write, 0 destructive).
		'yoast'       => array(
			'total'       => 3,
			'read'        => 2,
			'write'       => 1,
			'destructive' => 0,
		),
		// Rank Math: 5 abilities (3 read, 2 write, 0 destructive).
		'rankmath'    => array(
			'total'       => 5,
			'read'        => 3,
			'write'       => 2,
			'destructive' => 0,
		),
		// All in One SEO: 3 abilities (2 read, 1 write, 0 destructive).
		'aioseo'      => array(
			'total'       => 3,
			'read'        => 2,
			'write'       => 1,
			'destructive' => 0,
		),
	);
}

/**
 * The total number of abilities the catalog can expose, counted independently of which host
 * plugins are currently active.
 *
 * The single source of truth the Dashboard and the Abilities tab both read for "available /
 * total", so the two views can never disagree. It is the count of core (non-integration)
 * abilities — taken from the live registry, which always holds every core ability — plus every
 * integration's manifest total, so an inactive integration still contributes its full count.
 *
 * @return int
 */
function aafm_available_ability_count(): int {
	$manifest       = aafm_integration_manifest();
	$manifest_slugs = array_keys( $manifest );

	// Core = registry entries whose subject is not an integration slug. The registry always holds
	// every core ability regardless of host activation, so this is stable.
	$core     = 0;
	$registry = aafm_get_abilities_registry();
	foreach ( $registry as $meta ) {
		if ( ! in_array( (string) ( $meta['subject'] ?? '' ), $manifest_slugs, true ) ) {
			++$core;
		}
	}

	$manifest_total = 0;
	foreach ( $manifest as $counts ) {
		$manifest_total += (int) $counts['total'];
	}

	return $core + $manifest_total;
}
