<?php
/**
 * Static per-integration ability descriptor + derived count manifest.
 *
 * Integration abilities register only while their host plugin is active, so a live registry walk
 * cannot tell you how many abilities WooCommerce "would" expose on a site where it is not
 * installed. oversio_integration_ability_manifest() holds the full per-ability picture independent of
 * host activation, and oversio_integration_manifest() DERIVES the per-slug counts from it — one source
 * of truth, no second hand-kept tally to drift. It is the count contract for the integration
 * surface, alongside oversio_available_ability_count() for the whole catalog.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Static per-integration ability ORDER descriptor: an ordered { name, risk } list per integration
 * slug, in registry order.
 *
 * This is the part of the integration surface that CANNOT be derived from the registry alone:
 *  - `name` fixes the per-slug order and the membership set (which abilities belong to which host),
 *    so the Integrations tab can render every ability — disabled — for an inactive host.
 *  - `risk` drives the per-slug read/write/destructive counts (oversio_integration_manifest()), which are
 *    test-locked, so it stays explicit here rather than being read back.
 *
 * The label and description are NOT held here anymore — oversio_integration_ability_manifest() hydrates
 * them from the registry (the single source of truth) per slug, so there is no second copy to drift.
 *
 * KEEP IN LOCKSTEP WITH THE REGISTRY. IntegrationManifestTest force-activates every host and asserts
 * these names, risks, and per-slug counts equal the live registry's integration rows. If you add or
 * remove an integration ability, update the registry definitions AND this order list, or that test
 * fails.
 *
 * @return array<string,list<array{name:string,risk:string}>>
 */
function oversio_integration_ability_order(): array {
	return array(
		'yoast'       => array(
			array(
				'name' => 'oversio/yoast-get-post',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/yoast-update-post',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/yoast-get-head',
				'risk' => 'read',
			),
		),
		'rankmath'    => array(
			array(
				'name' => 'oversio/rankmath-get-post',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/rankmath-update-post',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/rankmath-get-schema',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/rankmath-update-schema',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/rankmath-get-head',
				'risk' => 'read',
			),
		),
		'aioseo'      => array(
			array(
				'name' => 'oversio/aioseo-get-post',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/aioseo-update-post',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/aioseo-get-head',
				'risk' => 'read',
			),
		),
		'acf'         => array(
			array(
				'name' => 'oversio/acf-list-field-groups',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/acf-get-post-fields',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/acf-update-post-fields',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/acf-get-term-fields',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/acf-update-term-fields',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/acf-get-user-fields',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/acf-update-user-fields',
				'risk' => 'write',
			),
		),
		'woocommerce' => array(
			array(
				'name' => 'oversio/wc-list-products',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-product',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-product',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-update-product',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-delete-product',
				'risk' => 'destructive',
			),
			array(
				'name' => 'oversio/wc-list-product-variations',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-product-variation',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-product-variation',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-update-product-variation',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-delete-product-variation',
				'risk' => 'destructive',
			),
			array(
				'name' => 'oversio/wc-list-product-attributes',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-product-attribute',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-update-product-attribute',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-list-orders',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-order',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-order',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-update-order',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-update-order-status',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-list-order-notes',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-order-note',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-list-order-refunds',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-order-refund',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-order-refund',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-list-customers',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-customer',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-customer',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-update-customer',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-list-coupons',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-coupon',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-coupon',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-update-coupon',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-list-shipping-zones',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-shipping-zone',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-shipping-zone',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-update-shipping-zone',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-list-shipping-methods',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-shipping-method',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-shipping-method',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-update-shipping-method',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-list-tax-rates',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-tax-rate',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-tax-rate',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-update-tax-rate',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-list-tax-classes',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-create-tax-class',
				'risk' => 'write',
			),
			array(
				'name' => 'oversio/wc-get-sales-report',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-top-sellers-report',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-count-orders',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-count-products',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-list-payment-gateways',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-get-payment-gateway',
				'risk' => 'read',
			),
			array(
				'name' => 'oversio/wc-update-payment-gateway',
				'risk' => 'write',
			),
		),
	);
}

/**
 * Static per-integration ability DESCRIPTOR.
 *
 * For each integration slug, an ordered list of every ability that integration exposes when its
 * host plugin is active: { name, label, risk, description } in registry order. Integration abilities
 * register only while their host plugin is active, so a live registry walk cannot describe (or count)
 * an integration whose host is inactive. This descriptor holds the full picture independent of host
 * activation: it lets the Integrations tab render every ability — disabled — for an inactive host,
 * and lets oversio_integration_manifest() DERIVE the counts from one source instead of a second
 * hand-kept tally.
 *
 * The `name` order and the `risk` come from oversio_integration_ability_order(); the `label` and
 * `description` are hydrated from the registry's guard-independent full view
 * (oversio_get_abilities_registry_full()), so the registry is the single source of truth for those two
 * strings — no second copy to keep in sync. The render layer still prefers the matching
 * oversio_ability_disclosures() line over `description` at render time, mirroring the active-path hint
 * logic so there is one disclosure source of truth.
 *
 * @return array<string,list<array{name:string,label:string,risk:string,description:string}>>
 */
function oversio_integration_ability_manifest(): array {
	$out = array();
	foreach ( oversio_integration_ability_order() as $slug => $rows ) {
		$out[ $slug ] = array();
		foreach ( $rows as $row ) {
			$name           = (string) $row['name'];
			$out[ $slug ][] = array(
				'name'        => $name,
				'label'       => oversio_ability_label( $name ),
				'risk'        => (string) $row['risk'],
				'description' => oversio_ability_description( $name ),
			);
		}
	}
	return $out;
}

/**
 * Per-integration ability counts, independent of whether the host plugin is active.
 *
 * DERIVED from oversio_integration_ability_manifest(): total is the row count, and read / write /
 * destructive are the per-risk tallies. The return shape is unchanged — each slug maps to
 * {total, read, write, destructive}, total === read + write + destructive — so every caller
 * (oversio_available_ability_count(), the Dashboard and Abilities counts, the Integrations card) is
 * untouched by the source change. The slugs match the integration subjects used in the registry
 * and the Integrations cards (see oversio_integration_cards()).
 *
 * @return array<string,array{total:int,read:int,write:int,destructive:int}>
 */
function oversio_integration_manifest(): array {
	$manifest = array();
	foreach ( oversio_integration_ability_manifest() as $slug => $rows ) {
		$read        = 0;
		$write       = 0;
		$destructive = 0;
		foreach ( $rows as $row ) {
			switch ( (string) ( $row['risk'] ?? 'read' ) ) {
				case 'read':
					++$read;
					break;
				case 'destructive':
					++$destructive;
					break;
				default:
					++$write;
			}
		}
		$manifest[ $slug ] = array(
			'total'       => count( $rows ),
			'read'        => $read,
			'write'       => $write,
			'destructive' => $destructive,
		);
	}
	return $manifest;
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
function oversio_available_ability_count(): int {
	$manifest_total = 0;
	foreach ( oversio_integration_manifest() as $counts ) {
		$manifest_total += (int) $counts['total'];
	}

	return oversio_core_ability_count() + $manifest_total;
}

/**
 * The number of core (non-integration) abilities in the catalog.
 *
 * Core = registry entries whose subject is not an integration slug. The registry always holds
 * every core ability regardless of host activation, so this is stable and host-independent. This
 * is the honest "core abilities" figure the readme advertises, and the readme tripwire asserts
 * against it so the number can never silently drift.
 *
 * @return int
 */
function oversio_core_ability_count(): int {
	$manifest_slugs = array_keys( oversio_integration_manifest() );

	$core     = 0;
	$registry = oversio_get_abilities_registry();
	foreach ( $registry as $meta ) {
		if ( ! in_array( (string) ( $meta['subject'] ?? '' ), $manifest_slugs, true ) ) {
			++$core;
		}
	}

	return $core;
}
