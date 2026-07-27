<?php
/**
 * Parameter-description coverage ratchet.
 *
 * The catalog is what an agent reads to decide how to call a tool - an input property with no
 * `description` is a blind guess for the agent. A sweep across the catalog is landing in
 * tranches (Tranche 1: posts, pages, terms, media, search - commit 3b7ff24); this test turns
 * that sweep into a structural guarantee instead of a one-off pass: it walks every registered
 * ability's input schema and fails the moment a property in an ALREADY-COVERED ability loses
 * its description, while an explicit, named allowlist carries the abilities tranches 2-4 have
 * not reached yet. The allowlist only ever shrinks - removing an ability from it is the proof a
 * later tranche landed; it must never grow to cover a regression in an ability already swept.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class DescriptionCoverageTest extends TestCase {

	/**
	 * Abilities the parameter-description sweep has not reached yet (tranches 2-4: comments,
	 * media, users, post/user/term meta, revisions, activity log, blocks, menus, templates, and
	 * every yoast-/rankmath-/aioseo-/acf-/wc- integration). Tranche 1 (posts, pages, terms,
	 * media's list/get reads, search - commit 3b7ff24) is NOT here, so this test already
	 * enforces coverage on it structurally.
	 *
	 * Shrinks to empty as tranches 2-4 land - remove an ability the moment its properties all
	 * carry a description; never add one back to paper over a regression.
	 *
	 * @var string[]
	 */
	private const UNCOVERED_ABILITIES = array(
		'aafm/acf-get-post-fields',
		'aafm/acf-get-term-fields',
		'aafm/acf-get-user-fields',
		'aafm/acf-update-post-fields',
		'aafm/acf-update-term-fields',
		'aafm/acf-update-user-fields',
		'aafm/aioseo-get-head',
		'aafm/aioseo-get-post',
		'aafm/aioseo-update-post',
		'aafm/create-block',
		'aafm/create-comment',
		'aafm/create-menu',
		'aafm/create-menu-item',
		'aafm/create-user',
		'aafm/delete-block',
		'aafm/delete-comment',
		'aafm/delete-menu',
		'aafm/delete-menu-item',
		'aafm/delete-post-meta',
		'aafm/delete-revision',
		'aafm/delete-term-meta',
		'aafm/delete-user',
		'aafm/delete-user-meta',
		'aafm/get-activity-log',
		'aafm/get-all-post-meta',
		'aafm/get-block',
		'aafm/get-comment',
		'aafm/get-comments',
		'aafm/get-media-item',
		'aafm/get-menu',
		'aafm/get-pending-comments',
		'aafm/get-post-meta',
		'aafm/get-revision',
		'aafm/get-template',
		'aafm/get-term-meta',
		'aafm/get-user',
		'aafm/get-user-meta',
		'aafm/get-users',
		'aafm/list-blocks',
		'aafm/list-menu-items',
		'aafm/list-revisions',
		'aafm/list-templates',
		'aafm/moderate-comment',
		'aafm/rankmath-get-head',
		'aafm/rankmath-get-post',
		'aafm/rankmath-get-schema',
		'aafm/rankmath-update-post',
		'aafm/rankmath-update-schema',
		'aafm/restore-revision',
		'aafm/update-block',
		'aafm/update-comment',
		'aafm/update-menu',
		'aafm/update-menu-item',
		'aafm/update-post-meta',
		'aafm/update-template',
		'aafm/update-term-meta',
		'aafm/update-user',
		'aafm/update-user-meta',
		'aafm/wc-create-coupon',
		'aafm/wc-create-customer',
		'aafm/wc-create-order',
		'aafm/wc-create-order-note',
		'aafm/wc-create-order-refund',
		'aafm/wc-create-product',
		'aafm/wc-create-product-attribute',
		'aafm/wc-create-product-variation',
		'aafm/wc-create-shipping-method',
		'aafm/wc-create-shipping-zone',
		'aafm/wc-create-tax-class',
		'aafm/wc-create-tax-rate',
		'aafm/wc-delete-product',
		'aafm/wc-delete-product-variation',
		'aafm/wc-get-coupon',
		'aafm/wc-get-customer',
		'aafm/wc-get-order',
		'aafm/wc-get-order-refund',
		'aafm/wc-get-payment-gateway',
		'aafm/wc-get-product',
		'aafm/wc-get-product-variation',
		'aafm/wc-get-shipping-method',
		'aafm/wc-get-shipping-zone',
		'aafm/wc-get-tax-rate',
		'aafm/wc-get-top-sellers-report',
		'aafm/wc-list-coupons',
		'aafm/wc-list-customers',
		'aafm/wc-list-order-notes',
		'aafm/wc-list-order-refunds',
		'aafm/wc-list-orders',
		'aafm/wc-list-product-variations',
		'aafm/wc-list-products',
		'aafm/wc-list-shipping-methods',
		'aafm/wc-update-coupon',
		'aafm/wc-update-customer',
		'aafm/wc-update-order',
		'aafm/wc-update-order-status',
		'aafm/wc-update-payment-gateway',
		'aafm/wc-update-product',
		'aafm/wc-update-product-attribute',
		'aafm/wc-update-product-variation',
		'aafm/wc-update-shipping-method',
		'aafm/wc-update-shipping-zone',
		'aafm/wc-update-tax-rate',
		'aafm/yoast-get-head',
		'aafm/yoast-get-post',
		'aafm/yoast-update-post',
	);

	public function set_up(): void {
		parent::set_up();
		// Force every integration active so its abilities are built and walked too, not just
		// the always-on core abilities - mirrors CatalogTest's convention. The registry is
		// memoized (includes/registry.php static $cache), so the flush is mandatory.
		add_filter( 'aafm_integration_active_yoast', '__return_true' );
		add_filter( 'aafm_integration_active_rankmath', '__return_true' );
		add_filter( 'aafm_integration_active_aioseo', '__return_true' );
		add_filter( 'aafm_integration_active_acf', '__return_true' );
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_yoast', '__return_true' );
		remove_filter( 'aafm_integration_active_rankmath', '__return_true' );
		remove_filter( 'aafm_integration_active_aioseo', '__return_true' );
		remove_filter( 'aafm_integration_active_acf', '__return_true' );
		remove_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );
		parent::tear_down();
	}

	/**
	 * Every input property of every ability NOT on the allowlist must declare a description.
	 */
	public function test_every_covered_ability_describes_all_its_input_properties(): void {
		$registry  = aafm_get_abilities_registry();
		$allowlist = array_flip( self::UNCOVERED_ABILITIES );
		$offenders = array();

		foreach ( $registry as $name => $row ) {
			if ( isset( $allowlist[ $name ] ) ) {
				continue;
			}
			if ( empty( $row['args_builder'] ) || ! is_callable( $row['args_builder'] ) ) {
				continue;
			}
			$args   = call_user_func( $row['args_builder'] );
			$schema = $args['input_schema'] ?? null;
			if ( ! is_array( $schema ) ) {
				continue;
			}
			$paths = self::undescribed_paths( $schema );
			foreach ( $paths as $path ) {
				$offenders[] = $name . ': ' . $path;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"The following input properties have no description:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * The allowlist must name abilities that actually exist in the registry and are genuinely
	 * missing a description - it is a ledger of known-bare abilities, not a wildcard escape
	 * hatch, so a stale or wrong entry (an ability renamed/removed, or one that already got its
	 * descriptions written) is caught rather than silently carried forever.
	 */
	public function test_allowlist_entries_exist_and_are_still_genuinely_bare(): void {
		$registry = aafm_get_abilities_registry();
		$stale    = array();
		$overdue  = array();

		foreach ( self::UNCOVERED_ABILITIES as $name ) {
			if ( ! isset( $registry[ $name ] ) ) {
				$stale[] = $name;
				continue;
			}
			$row = $registry[ $name ];
			if ( empty( $row['args_builder'] ) || ! is_callable( $row['args_builder'] ) ) {
				continue;
			}
			$args   = call_user_func( $row['args_builder'] );
			$schema = $args['input_schema'] ?? null;
			if ( ! is_array( $schema ) ) {
				continue;
			}
			if ( array() === self::undescribed_paths( $schema ) ) {
				$overdue[] = $name;
			}
		}

		$this->assertSame( array(), $stale, "Allowlisted abilities no longer in the registry (renamed/removed):\n" . implode( "\n", $stale ) );
		$this->assertSame( array(), $overdue, "Allowlisted abilities that are now fully described - remove from the allowlist:\n" . implode( "\n", $overdue ) );
	}

	/**
	 * Recursively find every property path in a schema with no non-empty string `description`.
	 * Recurses into a nested object property's own `properties` and into an array property's
	 * `items` schema, so a sub-field of a compound parameter (e.g. billing.city on a WooCommerce
	 * order) is checked too, not just the top-level field that contains it.
	 *
	 * @param array<string,mixed> $schema Schema node (the ability's input_schema, or a nested one).
	 * @param string              $path   Dotted path prefix for nested recursion.
	 * @return array<int,string> Property paths with no description.
	 */
	private static function undescribed_paths( array $schema, string $path = '' ): array {
		$missing = array();
		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return $missing;
		}
		foreach ( $schema['properties'] as $key => $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$here = '' === $path ? (string) $key : $path . '.' . $key;
			if ( empty( $prop['description'] ) || ! is_string( $prop['description'] ) ) {
				$missing[] = $here;
			}
			$missing = array_merge( $missing, self::undescribed_paths( $prop, $here ) );
			if ( isset( $prop['items'] ) && is_array( $prop['items'] ) ) {
				$missing = array_merge( $missing, self::undescribed_paths( $prop['items'], $here . '[]' ) );
			}
		}
		return $missing;
	}
}
