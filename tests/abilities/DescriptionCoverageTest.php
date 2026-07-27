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
	 * Abilities the parameter-description sweep has not reached yet.
	 *
	 * Empty since the wc-update-product `type` fix (release/1.4.2): `type` used to be silently
	 * discarded by aafm_exec_wc_update_product() (a mismatched value now returns a WP_Error
	 * instead, and a matching value is a no-op), so it now has an honest, ability-specific
	 * description and the last remaining entry was removed. Keep this const and comment as the
	 * ratchet's home for the next ability found undescribed -- add an entry only for a genuinely
	 * bare ability, never to paper over a regression in one already covered.
	 *
	 * @var string[]
	 */
	private const UNCOVERED_ABILITIES = array();

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
