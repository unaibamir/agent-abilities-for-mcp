<?php
/**
 * Pagination bounds sweep.
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
		aafm_flush_registry_cache();
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_yoast', '__return_true' );
		remove_filter( 'aafm_integration_active_rankmath', '__return_true' );
		remove_filter( 'aafm_integration_active_aioseo', '__return_true' );
		remove_filter( 'aafm_integration_active_acf', '__return_true' );
		remove_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_flush_registry_cache();
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

	/**
	 * The seven wide lists that take up to 100 a page, each with the default it shipped with.
	 *
	 * @return array<string,array{0:string,1:string,2:int}>
	 */
	private function wide_lists(): array {
		return array(
			'list-blocks'           => array( 'includes/abilities/blocks.php', 'aafm_exec_list_blocks', 20 ),
			'geodirectory-listings' => array( 'includes/abilities/geodirectory.php', 'aafm_exec_geodirectory_get_listings', 20 ),
			'wc-list-variations'    => array( 'includes/abilities/woocommerce/variations.php', 'aafm_exec_wc_list_product_variations', 20 ),
			'wc-list-tax-rates'     => array( 'includes/abilities/woocommerce/tax.php', 'aafm_exec_wc_list_tax_rates', 20 ),
			'wc-list-orders'        => array( 'includes/abilities/woocommerce/orders.php', 'aafm_exec_wc_list_orders', 20 ),
			'wc-list-customers'     => array( 'includes/abilities/woocommerce/customers.php', 'aafm_exec_wc_list_customers', 10 ),
			'wc-list-products'      => array( 'includes/abilities/woocommerce/products.php', 'aafm_exec_wc_list_products', 20 ),
		);
	}

	/**
	 * The body of one function in a plugin source file.
	 *
	 * @param string $file     Path under the plugin root.
	 * @param string $name     Function name.
	 * @return string
	 */
	private function function_body( string $file, string $name ): string {
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- this plugin's own local source.
		$start  = strpos( $source, "function {$name}(" );
		$this->assertNotFalse( $start, "{$name}() not found in {$file}." );
		$end = strpos( $source, "\n}\n", (int) $start );
		return substr( $source, (int) $start, (int) $end - (int) $start );
	}

	/**
	 * Each wide list sizes its page through aafm_paginate_args() with the 100 ceiling and its own
	 * default, rather than a clamp of its own. A source check: these executors need WooCommerce,
	 * GeoDirectory or block fixtures to run, and the helper's clamping is pinned above.
	 */
	public function test_each_wide_list_clamps_per_page_to_100_and_keeps_its_default(): void {
		$this->assertSame( 100, AAFM_TERMS_PER_PAGE_MAX );
		$this->assertSame( 100, aafm_paginate_args( array( 'per_page' => 5000 ), AAFM_TERMS_PER_PAGE_MAX )['per_page'] );
		foreach ( $this->wide_lists() as $label => list( $file, $function, $default ) ) {
			$body = $this->function_body( $file, $function );
			$this->assertStringNotContainsString( 'min( 100, max( 1', $body, "{$label} still clamps per_page by hand." );
			$this->assertMatchesRegularExpression(
				'/aafm_paginate_args\\(\\s*\\$input \\+ array\\( \'per_page\' => ' . $default . ' \\),\\s*AAFM_TERMS_PER_PAGE_MAX\\s*\\)/',
				$body,
				"{$label} must page through aafm_paginate_args() with a default of {$default} and the 100 ceiling."
			);
		}
	}

	/**
	 * Each wide list takes its page from the helper's answer, so a huge page is clamped to
	 * AAFM_LIST_PAGE_MAX there too.
	 */
	public function test_each_wide_list_clamps_a_huge_page(): void {
		$this->assertSame( AAFM_LIST_PAGE_MAX, aafm_paginate_args( array( 'page' => PHP_INT_MAX ) + array( 'per_page' => 20 ), AAFM_TERMS_PER_PAGE_MAX )['page'] );
		foreach ( $this->wide_lists() as $label => list( $file, $function ) ) {
			$body = $this->function_body( $file, $function );
			$this->assertDoesNotMatchRegularExpression( '/isset\\( \\$input\\[\'page\'\\] \\)/', $body, "{$label} still reads page outside the helper." );
		}
	}
}
