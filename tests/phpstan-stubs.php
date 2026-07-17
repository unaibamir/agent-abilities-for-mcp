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

if ( ! class_exists( 'WC_Product_Attribute' ) ) {
	/**
	 * Minimal WooCommerce product-attribute signature for static analysis only.
	 */
	class WC_Product_Attribute {
		public function get_id(): int {
			return 0;
		}
		public function get_name(): string {
			return '';
		}
		/** @return array<int,mixed> */
		public function get_options(): array {
			return array();
		}
		public function get_position(): int {
			return 0;
		}
		public function get_visible(): bool {
			return false;
		}
		public function get_variation(): bool {
			return false;
		}
		/** @param mixed $value @return void */
		public function set_id( $value ) {}
		/** @param mixed $value @return void */
		public function set_name( $value ) {}
		/** @param mixed $value @return void */
		public function set_options( $value ) {}
		/** @param mixed $value @return void */
		public function set_position( $value ) {}
		/** @param mixed $value @return void */
		public function set_visible( $value ) {}
		/** @param mixed $value @return void */
		public function set_variation( $value ) {}
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
	 * Stub WC_Order for PHPStan - mirrors the getters the order abilities call.
	 */
	class WC_Order {
		/**
		 * @param bool $and_taxes
		 * @return string
		 */
		public function calculate_totals( $and_taxes = true ) { return '0.00'; }
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
		/**
		 * @param string $note
		 * @param bool   $customer_note
		 * @param bool   $added_by_user
		 * @return int
		 */
		public function add_order_note( $note, $customer_note = false, $added_by_user = false ) { return 0; }
		/** @return array<int,\WC_Order_Refund> */
		public function get_refunds() { return array(); }
		/**
		 * Return a single order line item by id.
		 *
		 * @param int  $item_id      Order item id.
		 * @param bool $load_from_db Whether to re-read the item from the DB.
		 * @return \WC_Order_Item|false
		 */
		public function get_item( $item_id, $load_from_db = true ) { return false; }
		/** @return float */
		public function get_total_refunded() { return 0.0; }
		/** @return float */
		public function get_total_tax_refunded() { return 0.0; }
		/** @return float */
		public function get_total_shipping_refunded() { return 0.0; }
		/**
		 * @param bool $force
		 * @return bool
		 */
		public function delete( $force = false ) { return false; }
		/** @return int */
		public function save() { return 0; }
	}
}

if ( ! class_exists( 'WC_Order_Item' ) ) {
	/**
	 * Stub WC_Order_Item for PHPStan - mirrors the getters the order abilities call.
	 */
	class WC_Order_Item {
		/** @return int */
		public function get_id() { return 0; }
		/**
		 * Return the item's tax data, keyed by 'total' (and 'subtotal') => [ rate_id => amount ].
		 *
		 * @return array<string,array<int,string>>
		 */
		public function get_taxes() { return array(); }
	}
}

if ( ! class_exists( 'WC_Order_Refund' ) ) {
	class WC_Order_Refund {
		/** @return int */
		public function get_id() { return 0; }
		/** @return string */
		public function get_amount() { return '0.00'; }
		/** @return string */
		public function get_reason() { return ''; }
		/** @return string|null */
		public function get_date_created() { return null; }
		/**
		 * @param bool $force
		 * @return bool
		 */
		public function delete( $force = false ) { return false; }
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
	 * Real WooCommerce returns a WC_Order_Refund when given a refund post id, so the
	 * stub return type includes it - the refund resolver's instanceof check needs it.
	 *
	 * @param int|false $id
	 * @return \WC_Order|\WC_Order_Refund|false
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
if ( ! function_exists( 'wc_get_order_notes' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return array<int,object>
	 */
	function wc_get_order_notes( $args = array() ) {
		return array();
	}
}
if ( ! function_exists( 'wc_delete_order_note' ) ) {
	/**
	 * @param int $note_id
	 * @return bool
	 */
	function wc_delete_order_note( $note_id ) {
		return false;
	}
}
if ( ! function_exists( 'wc_create_refund' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return \WC_Order_Refund|\WP_Error
	 */
	function wc_create_refund( $args = array() ) {
		return new \WP_Error();
	}
}
if ( ! function_exists( 'wc_format_decimal' ) ) {
	/**
	 * @param string|float|int $number
	 * @param int|false        $dp
	 * @param bool             $trim_zeros
	 * @return string
	 */
	function wc_format_decimal( $number, $dp = false, $trim_zeros = false ) {
		return (string) $number;
	}
}
if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	/**
	 * @return int
	 */
	function wc_get_price_decimals() {
		return 2;
	}
}
if ( ! class_exists( 'WC_Customer' ) ) {
	/**
	 * PHPStan stub for WC_Customer (W4-WC3). Never loaded in production; test-only.
	 */
	class WC_Customer {
		/** @param int $id */
		public function __construct( $id = 0 ) {}
		/** @return int */
		public function get_id() { return 0; }
		/** @return string */
		public function get_email() { return ''; }
		/** @return string */
		public function get_first_name() { return ''; }
		/** @return string */
		public function get_last_name() { return ''; }
		/** @return string */
		public function get_username() { return ''; }
		/** @return int */
		public function get_order_count() { return 0; }
		/** @return string */
		public function get_total_spent() { return '0.00'; }
		/** @return string|null */
		public function get_date_created() { return null; }
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
		public function set_email( $v ) {}
		/** @param string $v @return void */
		public function set_first_name( $v ) {}
		/** @param string $v @return void */
		public function set_last_name( $v ) {}
		/** @param string $v @return void */
		public function set_username( $v ) {}
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
		/** @return int */
		public function save() { return 0; }
	}
}
if ( ! class_exists( 'WC_Coupon' ) ) {
	class WC_Coupon {
		/** @param int|string $code_or_id */
		public function __construct( $code_or_id = 0 ) {}
		/** @return int */
		public function get_id() { return 0; }
		/** @return string */
		public function get_code() { return ''; }
		/** @return string */
		public function get_amount() { return '0.00'; }
		/** @return string */
		public function get_discount_type() { return 'fixed_cart'; }
		/** @return string */
		public function get_description() { return ''; }
		/** @return string|null */
		public function get_date_expires() { return null; }
		/** @return int */
		public function get_usage_count() { return 0; }
		/** @return int|null */
		public function get_usage_limit() { return null; }
		/** @return int|null */
		public function get_usage_limit_per_user() { return null; }
		/** @return string */
		public function get_minimum_amount() { return ''; }
		/** @return string */
		public function get_maximum_amount() { return ''; }
		/** @return bool */
		public function get_individual_use() { return false; }
		/** @return bool */
		public function get_exclude_sale_items() { return false; }
		/** @return array<int,int> */
		public function get_product_ids() { return array(); }
		/** @return array<int,int> */
		public function get_excluded_product_ids() { return array(); }
		/** @return array<int,string> */
		public function get_email_restrictions() { return array(); }
		/** @param string $v @return void */
		public function set_code( $v ) {}
		/** @param string $v @return void */
		public function set_amount( $v ) {}
		/** @param string $v @return void */
		public function set_discount_type( $v ) {}
		/** @param string $v @return void */
		public function set_description( $v ) {}
		/** @param string|null $v @return void */
		public function set_date_expires( $v ) {}
		/** @param int|null $v @return void */
		public function set_usage_limit( $v ) {}
		/** @param int|null $v @return void */
		public function set_usage_limit_per_user( $v ) {}
		/** @param string $v @return void */
		public function set_minimum_amount( $v ) {}
		/** @param string $v @return void */
		public function set_maximum_amount( $v ) {}
		/** @param bool $v @return void */
		public function set_individual_use( $v ) {}
		/** @param bool $v @return void */
		public function set_exclude_sale_items( $v ) {}
		/** @param array<int,int> $v @return void */
		public function set_product_ids( $v ) {}
		/** @param array<int,int> $v @return void */
		public function set_excluded_product_ids( $v ) {}
		/** @param array<int,string> $v @return void */
		public function set_email_restrictions( $v ) {}
		/** @return int */
		public function save() { return 0; }
		/** @param bool $force @return bool */
		public function delete( $force = false ) { return false; }
	}
}
if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
	/**
	 * @param string $code
	 * @return int
	 */
	function wc_get_coupon_id_by_code( $code ) {
		return 0;
	}
}
if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
	/**
	 * PHPStan stub for WC_Shipping_Zone (W4-WC5). Never loaded in production; test-only.
	 */
	class WC_Shipping_Zone {
		/** @param int $zone_id */
		public function __construct( $zone_id = 0 ) {}
		/** @return int */
		public function get_id() { return 0; }
		/** @return array<string,mixed> */
		public function get_data() { return array(); }
		/** @return string */
		public function get_zone_name() { return ''; }
		/** @return int */
		public function get_zone_order() { return 0; }
		/** @param string $v @return void */
		public function set_zone_name( $v ) {}
		/** @param int $v @return void */
		public function set_zone_order( $v ) {}
		/** @return int */
		public function save() { return 0; }
		/** @param bool $force @return bool */
		public function delete( $force = false ) { return false; }
		/**
		 * @param bool $enabled_only
		 * @return array<int,\WC_Shipping_Method>
		 */
		public function get_shipping_methods( $enabled_only = false ) { return array(); }
		/** @param string $type @return int */
		public function add_shipping_method( $type ) { return 0; }
		/** @param int $instance_id @return bool */
		public function delete_shipping_method( $instance_id ) { return false; }
	}
}
if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	/**
	 * PHPStan stub for WC_Shipping_Method (W4-WC5). Never loaded in production; test-only.
	 */
	class WC_Shipping_Method {
		/** @var int */
		public $instance_id = 0;
		/** @var string */
		public $id = '';
		/** @var string */
		public $method_title = '';
		/** @var string */
		public $title = '';
		/** @var string */
		public $enabled = 'yes';
		/** @var array<string,mixed> */
		public $settings = array();
		/** @var array<string,mixed> */
		public $instance_settings = array();
		/**
		 * @param int $instance_id
		 * @param int $zone_id
		 */
		public function __construct( $instance_id = 0, $zone_id = 0 ) {}
		/** @return int */
		public function get_instance_id() { return 0; }
		/** @return void */
		public function init_instance_settings() {}
		/** @return string */
		public function get_instance_option_key() { return ''; }
	}
}
if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
	/**
	 * PHPStan stub for WC_Shipping_Zones (W4-WC5). Never loaded in production; test-only.
	 */
	class WC_Shipping_Zones {
		/**
		 * @param array<string,mixed> $args
		 * @return array<int,array<string,mixed>>
		 */
		public static function get_zones( $args = array() ) { return array(); }
	}
}
if ( ! class_exists( 'WC_Tax' ) ) {
	/**
	 * PHPStan stub for WC_Tax (W4-WC6). Never loaded in production; test-only.
	 */
	class WC_Tax {
		/**
		 * Return all custom tax class slugs (standard is NOT included).
		 *
		 * @return string[]
		 */
		public static function get_tax_classes(): array { return array(); }

		/**
		 * Return all custom tax class slugs, parallel to get_tax_classes() names.
		 *
		 * @return string[]
		 */
		public static function get_tax_class_slugs(): array { return array(); }

		/**
		 * Create a tax class.
		 *
		 * @param string $name Class name.
		 * @param string $slug Optional slug.
		 * @return array<string,string>|\WP_Error
		 */
		public static function create_tax_class( string $name, string $slug = '' ): array|\WP_Error { return array(); }

		/**
		 * Delete a tax class by field/value.
		 *
		 * @param string $field Field name.
		 * @param string $value Field value.
		 * @return bool|\WP_Error
		 */
		public static function delete_tax_class_by( string $field, string $value ): bool|\WP_Error { return false; }

		/**
		 * Insert a single tax rate row and return its new id.
		 *
		 * @param array<string,mixed> $tax_rate Tax rate row fields.
		 * @return int
		 */
		public static function _insert_tax_rate( array $tax_rate ): int { return 0; }

		/**
		 * Update an existing tax rate row by id.
		 *
		 * @param int                 $tax_rate_id Tax rate id.
		 * @param array<string,mixed> $tax_rate    Tax rate row fields.
		 * @return void
		 */
		public static function _update_tax_rate( int $tax_rate_id, array $tax_rate ): void {}
	}
}
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	/**
	 * PHPStan stub for WC_Payment_Gateway (W4-WC7). Never loaded in production; test-only.
	 */
	class WC_Payment_Gateway {
		/** @var string */
		public $id = '';
		/** @var string */
		public $title = '';
		/** @var string */
		public $description = '';
		/** @var string */
		public $enabled = 'yes';
		/** @var int|null */
		public ?int $order = null;
		/** @var array<string,mixed> */
		public $settings = array();
		/**
		 * @param array<string,mixed> $data
		 */
		public function __construct( array $data = array() ) {}
		/**
		 * @param string $key
		 * @param mixed  $value
		 * @return bool
		 */
		public function update_option( $key, $value ) { return false; }
		/**
		 * @param string $key
		 * @param mixed  $empty_value
		 * @return mixed
		 */
		public function get_option( $key, $empty_value = null ) { return $empty_value; }
		/** @return bool */
		public function save() { return false; }
	}
}
if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
	/**
	 * PHPStan stub for WC_Payment_Gateways (W4-WC7). Never loaded in production; test-only.
	 */
	class WC_Payment_Gateways {
		/** @return static */
		public static function instance() { return new static(); }
		/** @return array<string,\WC_Payment_Gateway> */
		public function payment_gateways() { return array(); }
	}
}
if ( ! class_exists( 'AIOSEO\\Plugin\\Common\\Models\\Post' ) ) {
	/**
	 * PHPStan stub for the AIOSEO Post model (Wave 5 Slice B). Never loaded in production; the real
	 * plugin (or the test stub) supplies it at runtime. Mirrors the static getPost() factory, the
	 * save() persister, and the public props the aioseo ability touches.
	 */
	class Aafm_Phpstan_Aioseo_Post_Model {
		/** @var int */
		public $post_id = 0;
		/** @var string */
		public $title = '';
		/** @var string */
		public $description = '';
		/** @var string */
		public $canonical_url = '';
		/** @var string */
		public $og_title = '';
		/** @var string */
		public $og_description = '';
		/** @var string */
		public $og_image_custom_url = '';
		/** @var string */
		public $og_image_type = 'default';
		/** @var string */
		public $twitter_title = '';
		/** @var string */
		public $twitter_description = '';
		/** @var string */
		public $twitter_image_custom_url = '';
		/** @var string */
		public $twitter_image_type = 'default';
		/** @var bool */
		public $robots_noindex = false;
		/** @var bool */
		public $robots_nofollow = false;
		/** @var bool */
		public $robots_default = true;
		/**
		 * @param int $post_id
		 * @return self
		 */
		public static function getPost( $post_id ) {
			return new self();
		}
		/** @return bool */
		public function save() {
			return true;
		}
	}
	class_alias( 'Aafm_Phpstan_Aioseo_Post_Model', 'AIOSEO\\Plugin\\Common\\Models\\Post' );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP-CLI signature for static analysis only. Real WP_CLI is provided by the
	 * WP-CLI runtime, and the command is only registered behind defined( 'WP_CLI' ).
	 */
	class WP_CLI {
		/**
		 * @param string   $name     Command name.
		 * @param callable $callable Command callback.
		 * @param array<string,mixed> $args Registration args.
		 * @return void
		 */
		public static function add_command( $name, $callable, $args = array() ) {}
		/**
		 * @param string $message Line to print.
		 * @return void
		 */
		public static function line( $message = '' ) {}
	}
}
// phpcs:enable
