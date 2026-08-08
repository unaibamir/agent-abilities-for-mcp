<?php
/**
 * Pagination bounds sweep (B44).
 *
 * AAFM_LIST_PAGE_MAX's docblock promises the ceiling is "declared on every list tool's
 * `page` arg". That was false: get-pages and the WooCommerce lists declared `minimum`
 * only, and aafm_paginate_args() never clamped the page server-side, so a stray input
 * schema (or a caller reaching the helper outside schema validation) could request an
 * unbounded page offset. This suite makes the docblock structurally true: every
 * registered ability whose input schema declares a `page` property must bound it, and
 * the shared helper must clamp even without the schema.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ListPageBoundsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// Force every integration active so its list abilities are built and swept too -
		// mirrors DescriptionCoverageTest's convention. The registry memo flush is mandatory.
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
	 * Every `page` input property across the whole registry declares the shared bounds.
	 */
	public function test_every_page_property_declares_the_shared_bounds(): void {
		$offenders = array();
		foreach ( aafm_get_abilities_registry() as $name => $row ) {
			if ( empty( $row['args_builder'] ) || ! is_callable( $row['args_builder'] ) ) {
				continue;
			}
			$args       = call_user_func( $row['args_builder'] );
			$properties = $args['input_schema']['properties'] ?? array();
			if ( ! isset( $properties['page'] ) || ! is_array( $properties['page'] ) ) {
				continue;
			}
			$page = $properties['page'];
			if ( 1 !== ( $page['minimum'] ?? null ) || AAFM_LIST_PAGE_MAX !== ( $page['maximum'] ?? null ) ) {
				$offenders[] = $name;
			}
		}
		$this->assertSame(
			array(),
			$offenders,
			"These list abilities do not bound their page arg to 1..AAFM_LIST_PAGE_MAX:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * The shared helper clamps the page server-side, independent of schema validation.
	 */
	public function test_paginate_args_clamps_the_page_to_the_shared_ceiling(): void {
		$args = aafm_paginate_args( array( 'page' => AAFM_LIST_PAGE_MAX + 1 ), 50 );
		$this->assertSame( AAFM_LIST_PAGE_MAX, $args['page'], 'the helper must clamp an over-ceiling page.' );

		$args = aafm_paginate_args( array( 'page' => 7 ), 50 );
		$this->assertSame( 7, $args['page'], 'an in-range page passes through unchanged.' );
	}
}
