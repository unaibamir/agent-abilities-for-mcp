<?php
/**
 * PHPStan-only host-symbol stubs for the third-party integrations.
 *
 * The integration abilities are written against host plugins (WooCommerce, …) that are NOT installed
 * in this repo, so PHPStan cannot see their classes/functions. The abilities guard every call with
 * is-active / function_exists / instanceof checks at runtime, but PHPStan still needs the symbol
 * SIGNATURES to type-check the type hints and method calls. These minimal declarations provide them.
 * This file is loaded ONLY by PHPStan (a bootstrapFile) and is excluded from analysis; WordPress
 * never loads it, and at runtime the real host plugin (or the test stub) supplies these symbols.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

// phpcs:disable
if ( ! class_exists( 'WC_Product' ) ) {
	/**
	 * Minimal WooCommerce product signature for static analysis only.
	 */
	class WC_Product {
		public function get_id(): int {
			return 0;
		}
		public function get_name(): string {
			return '';
		}
		public function get_type(): string {
			return '';
		}
		public function get_status(): string {
			return '';
		}
		public function get_sku(): string {
			return '';
		}
		public function get_description(): string {
			return '';
		}
		public function get_short_description(): string {
			return '';
		}
		public function get_price(): string {
			return '';
		}
		public function get_regular_price(): string {
			return '';
		}
		public function get_sale_price(): string {
			return '';
		}
		public function get_stock_status(): string {
			return '';
		}
		/** @return int|null */
		public function get_stock_quantity() {
			return null;
		}
		public function get_manage_stock(): bool {
			return false;
		}
		public function get_featured(): bool {
			return false;
		}
		/** @return int[] */
		public function get_category_ids(): array {
			return array();
		}
		/** @return int[] */
		public function get_tag_ids(): array {
			return array();
		}
		/** @return int[] */
		public function get_gallery_image_ids(): array {
			return array();
		}
		public function get_image_id(): int {
			return 0;
		}
		/** @return array<int|string,mixed> */
		public function get_attributes(): array {
			return array();
		}
		/** @return int[] */
		public function get_children(): array {
			return array();
		}
		/** @param mixed $value @return void */
		public function set_name( $value ) {}
		/** @param mixed $value @return void */
		public function set_status( $value ) {}
		/** @param mixed $value @return void */
		public function set_sku( $value ) {}
		/** @param mixed $value @return void */
		public function set_description( $value ) {}
		/** @param mixed $value @return void */
		public function set_short_description( $value ) {}
		/** @param mixed $value @return void */
		public function set_regular_price( $value ) {}
		/** @param mixed $value @return void */
		public function set_sale_price( $value ) {}
		/** @param mixed $value @return void */
		public function set_price( $value ) {}
		/** @param mixed $value @return void */
		public function set_stock_status( $value ) {}
		/** @param mixed $value @return void */
		public function set_stock_quantity( $value ) {}
		/** @param mixed $value @return void */
		public function set_manage_stock( $value ) {}
		/** @param mixed $value @return void */
		public function set_featured( $value ) {}
		/** @param mixed $value @return void */
		public function set_category_ids( $value ) {}
		/** @param mixed $value @return void */
		public function set_tag_ids( $value ) {}
		/** @param mixed $value @return void */
		public function set_gallery_image_ids( $value ) {}
		/** @param mixed $value @return void */
		public function set_image_id( $value ) {}
		/** @param mixed $value @return void */
		public function set_attributes( $value ) {}
		public function save(): int {
			return 0;
		}
		/** @param bool $force_delete @return bool */
		public function delete( $force_delete = false ): bool {
			return true;
		}
	}
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	/**
	 * Minimal WooCommerce product-variation signature for static analysis only.
	 */
	class WC_Product_Variation {
		public function __construct( $variation = 0 ) {}
		public function get_id(): int {
			return 0;
		}
		public function get_parent_id(): int {
			return 0;
		}
		public function get_type(): string {
			return 'variation';
		}
		public function get_status(): string {
			return '';
		}
		public function get_sku(): string {
			return '';
		}
		public function get_description(): string {
			return '';
		}
		public function get_price(): string {
			return '';
		}
		public function get_regular_price(): string {
			return '';
		}
		public function get_sale_price(): string {
			return '';
		}
		public function get_stock_status(): string {
			return '';
		}
		/** @return int|null */
		public function get_stock_quantity() {
			return null;
		}
		public function get_manage_stock(): bool {
			return false;
		}
		public function get_image_id(): int {
			return 0;
		}
		/** @return array<int|string,mixed> */
		public function get_attributes(): array {
			return array();
		}
		/** @param mixed $value @return void */
		public function set_parent_id( $value ) {}
		/** @param mixed $value @return void */
		public function set_status( $value ) {}
		/** @param mixed $value @return void */
		public function set_sku( $value ) {}
		/** @param mixed $value @return void */
		public function set_description( $value ) {}
		/** @param mixed $value @return void */
		public function set_regular_price( $value ) {}
		/** @param mixed $value @return void */
		public function set_sale_price( $value ) {}
		/** @param mixed $value @return void */
		public function set_price( $value ) {}
		/** @param mixed $value @return void */
		public function set_stock_status( $value ) {}
		/** @param mixed $value @return void */
		public function set_stock_quantity( $value ) {}
		/** @param mixed $value @return void */
		public function set_manage_stock( $value ) {}
		/** @param mixed $value @return void */
		public function set_image_id( $value ) {}
		/** @param mixed $value @return void */
		public function set_attributes( $value ) {}
		public function save(): int {
			return 0;
		}
		/** @param bool $force_delete @return bool */
		public function delete( $force_delete = false ): bool {
			return true;
		}
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * @param int|false $the_product
	 * @return WC_Product|false
	 */
	function wc_get_product( $the_product = false ) {
		return false;
	}
}

if ( ! function_exists( 'wc_get_products' ) ) {
	/**
	 * Mirrors real WooCommerce: an array of products by default, or a stdClass carrying ->products and
	 * ->total when called with 'paginate' => true.
	 *
	 * @param array<string,mixed> $args
	 * @return WC_Product[]|\stdClass
	 */
	function wc_get_products( $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
	/**
	 * @return \stdClass[]
	 */
	function wc_get_attribute_taxonomies() {
		return array();
	}
}

if ( ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
	/**
	 * @param string $attribute_name
	 * @return string
	 */
	function wc_attribute_taxonomy_name( $attribute_name ) {
		return '';
	}
}

if ( ! function_exists( 'wc_sanitize_taxonomy_name' ) ) {
	/**
	 * @param string $taxonomy
	 * @return string
	 */
	function wc_sanitize_taxonomy_name( $taxonomy ) {
		return '';
	}
}

if ( ! function_exists( 'wc_create_attribute' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return int|\WP_Error
	 */
	function wc_create_attribute( $args ) {
		return 0;
	}
}

if ( ! function_exists( 'wc_update_attribute' ) ) {
	/**
	 * @param int                 $id
	 * @param array<string,mixed> $args
	 * @return int|\WP_Error
	 */
	function wc_update_attribute( $id, $args ) {
		return 0;
	}
}

if ( ! function_exists( 'wc_delete_attribute' ) ) {
	/**
	 * @param int $id
	 * @return bool|\WP_Error
	 */
	function wc_delete_attribute( $id ) {
		return false;
	}
}
// phpcs:enable
