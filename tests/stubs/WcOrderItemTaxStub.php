<?php
/**
 * A minimal WC_Order_Item_Tax for the WooCommerce order tests, never shipped.
 *
 * Declared once for the whole test process, so every test sees the same class state: the money
 * restore in includes/abilities/woocommerce/orders.php branches on class_exists() for this class.
 * It holds only the methods that code calls, each named after WooCommerce 11.1.2's own
 * (includes/class-wc-order-item-tax.php: set_rate_code :53, set_label :62, set_rate_id :71,
 * set_tax_total :80, set_shipping_tax_total :89, set_compound :98, set_rate_percent :107,
 * get_rate_id :182; get_id and save from abstract-wc-data.php :245 and :284, where save() returns
 * the object's id). The public $id and $rate_id let a test seed a stored row. Guarded, so a run with
 * real WooCommerce loaded keeps the real class.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

if ( ! class_exists( 'WC_Order_Item_Tax' ) ) {
	/**
	 * Tax row stub.
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- stands in for the WooCommerce class of this name.
	class WC_Order_Item_Tax {

		/**
		 * Item id.
		 *
		 * @var int
		 */
		public $id = 0;

		/**
		 * Tax rate id.
		 *
		 * @var int
		 */
		public $rate_id = 0;

		/**
		 * Item id.
		 *
		 * @return int
		 */
		public function get_id() {
			return $this->id;
		}

		/**
		 * Tax rate id.
		 *
		 * @param string $context Read context.
		 * @return int
		 */
		public function get_rate_id( $context = 'view' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WooCommerce's signature.
			return $this->rate_id;
		}

		/**
		 * Set the tax rate id.
		 *
		 * @param mixed $value Rate id.
		 * @return void
		 */
		public function set_rate_id( $value ) {
			$this->rate_id = (int) $value;
		}

		/**
		 * Set the rate code.
		 *
		 * @param mixed $value Rate code.
		 * @return void
		 */
		public function set_rate_code( $value ) {}

		/**
		 * Set the label.
		 *
		 * @param mixed $value Label.
		 * @return void
		 */
		public function set_label( $value ) {}

		/**
		 * Set the compound flag.
		 *
		 * @param mixed $value Compound flag.
		 * @return void
		 */
		public function set_compound( $value ) {}

		/**
		 * Set the rate percent.
		 *
		 * @param mixed $value Rate percent.
		 * @return void
		 */
		public function set_rate_percent( $value ) {}

		/**
		 * Set the tax total.
		 *
		 * @param mixed $value Tax total.
		 * @return void
		 */
		public function set_tax_total( $value ) {}

		/**
		 * Set the shipping tax total.
		 *
		 * @param mixed $value Shipping tax total.
		 * @return void
		 */
		public function set_shipping_tax_total( $value ) {}

		/**
		 * Save the row.
		 *
		 * @return int The item id.
		 */
		public function save() {
			return $this->id;
		}
	}
}
