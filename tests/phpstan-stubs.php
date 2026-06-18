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

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Stub WC_Order for PHPStan — mirrors the getters the order abilities call.
	 */
	class WC_Order {
		/** @return int */
		public function get_id() { return 0; }
		/** @return string */
		public function get_order_number() { return ''; }
		/** @return string */
		public function get_status() { return ''; }
		/** @return string */
		public function get_total() { return '0.00'; }
		/** @return string */
		public function get_currency() { return 'USD'; }
		/** @return string|object|null */
		public function get_date_created() { return null; }
		/** @return string|object|null */
		public function get_date_paid() { return null; }
		/** @return int */
		public function get_customer_id() { return 0; }
		/** @return string */
		public function get_customer_note() { return ''; }
		/**
		 * @param string $types
		 * @return array<mixed>
		 */
		public function get_items( $types = 'line_item' ) { return array(); }
		/** @return string */
		public function get_total_tax() { return '0.00'; }
		/** @return string */
		public function get_subtotal() { return '0.00'; }
		/** @return string */
		public function get_shipping_total() { return '0.00'; }
		/** @return string */
		public function get_billing_first_name() { return ''; }
		/** @return string */
		public function get_billing_last_name() { return ''; }
		/** @return string */
		public function get_billing_company() { return ''; }
		/** @return string */
		public function get_billing_address_1() { return ''; }
		/** @return string */
		public function get_billing_address_2() { return ''; }
		/** @return string */
		public function get_billing_city() { return ''; }
		/** @return string */
		public function get_billing_state() { return ''; }
		/** @return string */
		public function get_billing_postcode() { return ''; }
		/** @return string */
		public function get_billing_country() { return ''; }
		/** @return string */
		public function get_billing_email() { return ''; }
		/** @return string */
		public function get_billing_phone() { return ''; }
		/** @return string */
		public function get_shipping_first_name() { return ''; }
		/** @return string */
		public function get_shipping_last_name() { return ''; }
		/** @return string */
		public function get_shipping_company() { return ''; }
		/** @return string */
		public function get_shipping_address_1() { return ''; }
		/** @return string */
		public function get_shipping_address_2() { return ''; }
		/** @return string */
		public function get_shipping_city() { return ''; }
		/** @return string */
		public function get_shipping_state() { return ''; }
		/** @return string */
		public function get_shipping_postcode() { return ''; }
		/** @return string */
		public function get_shipping_country() { return ''; }
		/** @param string $v @return void */
		public function set_status( $v ) {}
		/** @param string $v @return bool */
		public function update_status( $v ) { return true; }
		/** @param int $v @return void */
		public function set_customer_id( $v ) {}
		/** @param string $v @return void */
		public function set_customer_note( $v ) {}
		/** @param string $v @return void */
		public function set_billing_first_name( $v ) {}
		/** @param string $v @return void */
		public function set_billing_last_name( $v ) {}
		/** @param string $v @return void */
		public function set_billing_company( $v ) {}
		/** @param string $v @return void */
		public function set_billing_address_1( $v ) {}
		/** @param string $v @return void */
		public function set_billing_address_2( $v ) {}
		/** @param string $v @return void */
		public function set_billing_city( $v ) {}
		/** @param string $v @return void */
		public function set_billing_state( $v ) {}
		/** @param string $v @return void */
		public function set_billing_postcode( $v ) {}
		/** @param string $v @return void */
		public function set_billing_country( $v ) {}
		/** @param string $v @return void */
		public function set_billing_email( $v ) {}
		/** @param string $v @return void */
		public function set_billing_phone( $v ) {}
		/** @param string $v @return void */
		public function set_shipping_first_name( $v ) {}
		/** @param string $v @return void */
		public function set_shipping_last_name( $v ) {}
		/** @param string $v @return void */
		public function set_shipping_company( $v ) {}
		/** @param string $v @return void */
		public function set_shipping_address_1( $v ) {}
		/** @param string $v @return void */
		public function set_shipping_address_2( $v ) {}
		/** @param string $v @return void */
		public function set_shipping_city( $v ) {}
		/** @param string $v @return void */
		public function set_shipping_state( $v ) {}
		/** @param string $v @return void */
		public function set_shipping_postcode( $v ) {}
		/** @param string $v @return void */
		public function set_shipping_country( $v ) {}
		/**
		 * @param \WC_Product|false $product
		 * @param int $qty
		 * @return int
		 */
		public function add_product( $product, $qty = 1 ) { return 0; }
		/** @return int */
		public function save() { return 0; }
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return array<int,\WC_Order>|object
	 */
	function wc_get_orders( $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int|false $id
	 * @return \WC_Order|false
	 */
	function wc_get_order( $id = false ) {
		return false;
	}
}
if ( ! function_exists( 'wc_get_order_statuses' ) ) {
	/**
	 * @return array<string,string>
	 */
	function wc_get_order_statuses() {
		return array();
	}
}
// phpcs:enable
