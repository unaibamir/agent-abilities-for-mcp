<?php
/**
 * WooCommerce vendor-contract tests.
 *
 * THE STANDING RULE FOR THIS SUITE: a stub may only model behaviour that a contract test here has
 * confirmed against the REAL vendor. When a stub and a contract test disagree, the stub is wrong.
 * Each test below pins one contract an ability depends on; a test that FAILS against reality is a
 * finding (the source fix lands in the matching correctness workstream), not a flaky test.
 *
 * Run: vendor/bin/phpunit -c phpunit-contract.xml.dist (after tests/bin/install-vendors.sh).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Contract;

use AAFM\Tests\TestCase;

/**
 * Asserts the real WooCommerce contracts the store abilities rely on.
 *
 * @group contract
 */
final class WooCommerceContractTest extends TestCase {

	/**
	 * Skip the whole class if WooCommerce is not provisioned in the test core.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not provisioned — run tests/bin/install-vendors.sh.' );
		}
	}

	/**
	 * The original exemplar: `wc_get_customers()` never existed. 1.2.1 called it and every store
	 * reported zero customers. The stub eval()'d it into being; reality has no such function.
	 */
	public function test_wc_get_customers_does_not_exist(): void {
		$this->assertFalse(
			function_exists( 'wc_get_customers' ),
			'wc_get_customers() must NOT exist in real WooCommerce — the 1.2.1 silent-zero bug proved it never did.'
		);
	}

	/**
	 * The customer-listing helpers the rewritten ability actually uses DO exist.
	 */
	public function test_customer_query_symbols_exist(): void {
		$this->assertTrue( class_exists( '\WC_Customer' ), 'WC_Customer must exist.' );
		$this->assertTrue( function_exists( 'wc_create_new_customer' ), 'wc_create_new_customer() must exist.' );
	}

	/**
	 * M13: a payment gateway's sort position is NOT a `$gateway->order` property. Production reads
	 * `->order` and gets nothing (every gateway reports order:0). The abstract gateway declares no
	 * such property, and gateways expose no `save()` (the phpstan stub invented one).
	 */
	public function test_payment_gateway_has_no_order_property_or_save_method(): void {
		$this->assertTrue( class_exists( '\WC_Payment_Gateway' ), 'WC_Payment_Gateway must exist.' );
		$ref = new \ReflectionClass( '\WC_Payment_Gateway' );

		$this->assertFalse(
			$ref->hasProperty( 'order' ),
			'WC_Payment_Gateway declares no `order` property — reading $gateway->order (gateways.php:142) is the M13 fabrication.'
		);
		$this->assertFalse(
			method_exists( '\WC_Payment_Gateway', 'save' ),
			'WC_Payment_Gateway has no save() — the phpstan stub invented one.'
		);
	}

	/**
	 * Item-1 contract: `WC_Settings_API::update_option()` mutates the in-memory settings copy BEFORE
	 * the DB write, so a same-instance `get_option()` read reflects the REQUESTED value even when a
	 * sanitize filter diverts what actually persists. Verifying a gateway write against
	 * `$gateway->get_option()` therefore reports a false success; the executor must read the
	 * DB-persisted option row (`get_option_key()`) instead. This pins that divergence against real WC.
	 */
	public function test_gateway_update_option_mutates_in_memory_before_persist(): void {
		// Force WooCommerce to load its bundled gateway classes.
		if ( ! class_exists( '\WC_Gateway_BACS' ) && function_exists( 'WC' ) && WC()->payment_gateways() ) {
			WC()->payment_gateways()->payment_gateways();
		}
		if ( ! class_exists( '\WC_Gateway_BACS' ) ) {
			$this->markTestSkipped( 'BACS gateway class unavailable.' );
		}

		$gateway    = new \WC_Gateway_BACS();
		$option_key = $gateway->get_option_key();
		$filter     = 'woocommerce_settings_api_sanitized_fields_' . $gateway->id;

		// A sanitize filter that diverts what lands in the DB, modelling a normalization or rejection
		// that changes the persisted value away from the copy update_option() cached in memory.
		$diverter = static function ( $settings ) {
			$settings['title'] = 'AAFM_PERSISTED_DIFFERENT';
			return $settings;
		};
		add_filter( $filter, $diverter );
		$gateway->update_option( 'title', 'AAFM_IN_MEMORY' );
		remove_filter( $filter, $diverter );

		// The gateway's in-memory copy holds the value we passed - set BEFORE the DB write.
		$this->assertSame(
			'AAFM_IN_MEMORY',
			$gateway->get_option( 'title' ),
			'get_option() reads $this->settings, set before persist - it does not reflect the diverted DB value.'
		);

		// The DB-persisted row holds the diverted value, not the in-memory copy. Verifying against
		// get_option() would falsely pass; only reading the persisted row catches the divergence.
		$persisted = get_option( $option_key );
		$this->assertIsArray( $persisted, 'The gateway settings option must have been written.' );
		$this->assertSame(
			'AAFM_PERSISTED_DIFFERENT',
			$persisted['title'],
			'The persisted row diverges from the in-memory copy - the in-memory-before-persist false-success vector.'
		);

		delete_option( $option_key );
	}

	/**
	 * Refund crash risk (audit F3): `get_taxes()` lives on the product/fee/shipping order-item
	 * subclasses, NOT on the base WC_Order_Item. The stub fabricated it on the base class, hiding a
	 * fatal when a coupon/tax line id is passed to the refund ability.
	 */
	public function test_get_taxes_is_not_on_base_order_item(): void {
		$this->assertTrue( class_exists( '\WC_Order_Item' ), 'WC_Order_Item must exist.' );
		$this->assertFalse(
			method_exists( '\WC_Order_Item', 'get_taxes' ),
			'Base WC_Order_Item has no get_taxes() — refund executor must guard with method_exists (F3).'
		);
		$this->assertTrue(
			method_exists( '\WC_Order_Item_Product', 'get_taxes' ),
			'WC_Order_Item_Product does define get_taxes().'
		);
		$this->assertFalse(
			method_exists( '\WC_Order_Item_Coupon', 'get_taxes' ),
			'WC_Order_Item_Coupon has no get_taxes() — passing a coupon line id would fatal.'
		);
	}

	/**
	 * C1: `WC_Shipping_Zones::get_zones()` rows carry NO `zone_object` key. 1.2.1 read
	 * `$row['zone_object']` (never set) and returned an empty zone list on every store.
	 */
	public function test_shipping_zone_rows_have_no_zone_object_key(): void {
		if ( ! class_exists( '\WC_Shipping_Zone' ) || ! class_exists( '\WC_Shipping_Zones' ) ) {
			$this->markTestSkipped( 'WC shipping classes unavailable.' );
		}

		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'AAFM Contract Zone' );
		$zone->set_zone_order( 1 );
		$zone_id = $zone->save();
		$this->assertGreaterThan( 0, $zone_id, 'Zone must persist so get_zones() has a row to return.' );

		$rows = \WC_Shipping_Zones::get_zones();
		$this->assertNotEmpty( $rows, 'get_zones() must return the created zone.' );

		$row = reset( $rows );
		$this->assertIsArray( $row );
		$this->assertArrayNotHasKey(
			'zone_object',
			$row,
			'get_zones() rows have NO zone_object key — reading it (shipping.php:300) was the C1 silent-empty bug.'
		);
		$this->assertArrayHasKey( 'zone_id', $row, 'Rows expose the zone id under zone_id.' );

		// Cleanup: remove the throwaway zone so the contract DB is not left dirty.
		( new \WC_Shipping_Zone( (int) $row['zone_id'] ) )->delete();
	}

	/**
	 * M4: WooCommerce registers a dedicated `customer` role on install. A buyer who holds a
	 * different role (subscriber on a membership/LMS store) is invisible to a role=customer query,
	 * which is why hardcoding role=customer reports zero customers on those stores.
	 */
	public function test_customer_role_is_distinct_from_other_roles(): void {
		$this->assertNotNull( get_role( 'customer' ), 'WC install must register the customer role.' );

		$buyer = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$as_customer = get_users(
			array(
				'role'   => 'customer',
				'fields' => 'ID',
			)
		);
		$this->assertNotContains(
			(string) $buyer,
			array_map( 'strval', $as_customer ),
			'A subscriber-role buyer is NOT returned by role=customer — the M4 blind spot.'
		);

		$all = get_users(
			array(
				'include' => array( $buyer ),
				'fields'  => 'ID',
			)
		);
		$this->assertContains(
			(string) $buyer,
			array_map( 'strval', $all ),
			'The same buyer IS reachable without the role filter, so listing must not hardcode role=customer.'
		);
	}

	/**
	 * M12: a programmatic order note is attributed by WooCommerce as `added_by = 'system'`, never
	 * the literal `'user'` the stub emitted and the production `added_by_user` check tested for.
	 */
	public function test_order_note_added_by_is_system_not_user(): void {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			$this->markTestSkipped( 'wc_get_order_notes() unavailable.' );
		}

		$order = new \WC_Order();
		$order->save();
		$order->add_order_note( 'AAFM contract note', false, false );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty( $notes, 'The added note must be returned.' );
		$note = reset( $notes );

		$this->assertObjectHasProperty( 'added_by', $note, 'Notes expose added_by.' );
		$this->assertSame(
			'system',
			$note->added_by,
			"A programmatic note is added_by 'system', not 'user' — the M12 fabrication tested for 'user'."
		);

		$order->delete( true );
	}

	/**
	 * M12 companion: a note a logged-in user with edit_shop_orders adds ($added_by_user = true) is
	 * attributed to that user's DISPLAY NAME, not 'system'. This is the case the production
	 * added_by_user check ('system' !== added_by) must read as true - the inverse of the programmatic
	 * case above, and the half the original stub's hardcoded 'user' never modelled either.
	 */
	public function test_order_note_added_by_is_display_name_for_a_human(): void {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			$this->markTestSkipped( 'wc_get_order_notes() unavailable.' );
		}

		$user_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Aafm Store Manager',
			)
		);
		// WC_Order::add_order_note() attributes the note to the acting user only when that user is
		// logged in AND can edit_shop_orders; administrators get that WooCommerce capability on install.
		$user = new \WP_User( $user_id );
		$user->add_cap( 'edit_shop_orders' );
		wp_set_current_user( $user_id );

		$order = new \WC_Order();
		$order->save();
		$order->add_order_note( 'AAFM human note', false, true );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty( $notes, 'The added note must be returned.' );
		$note = reset( $notes );

		$this->assertObjectHasProperty( 'added_by', $note, 'Notes expose added_by.' );
		$this->assertSame(
			'Aafm Store Manager',
			$note->added_by,
			"A human-authored note is attributed to the user's display name, not 'system'."
		);
		$this->assertNotSame(
			'system',
			$note->added_by,
			"The production added_by_user check ('system' !== added_by) must read true for a human note."
		);

		$order->delete( true );
		wp_set_current_user( 0 );
	}

	/**
	 * M3 / WC 9.1 floor: at 9.1.0 `wc_update_attribute()` backfills the fields a partial update
	 * omits, so a name-only update no longer wipes has_archives/order_by/type. This is the
	 * behavioural cliff the version floor is pinned to; below 9.1 the same call is destructive.
	 */
	public function test_update_attribute_backfills_unsent_fields_at_floor(): void {
		if ( ! function_exists( 'wc_create_attribute' ) || ! function_exists( 'wc_update_attribute' ) ) {
			$this->markTestSkipped( 'WC attribute functions unavailable.' );
		}
		$this->assertTrue(
			version_compare( \WC_VERSION, '9.1', '>=' ),
			'Contract pins WooCommerce at the 9.1 floor; the backfill contract only holds from 9.1.0.'
		);

		$attribute_id = wc_create_attribute(
			array(
				'name'         => 'AAFM Contract Color',
				'slug'         => 'aafm_contract_color',
				'type'         => 'select',
				'order_by'     => 'name',
				'has_archives' => true,
			)
		);
		$this->assertIsInt( $attribute_id );
		$this->assertGreaterThan( 0, $attribute_id );

		// Name-only update: everything else is intentionally omitted.
		wc_update_attribute( $attribute_id, array( 'name' => 'AAFM Contract Colour' ) );

		$updated = wc_get_attribute( $attribute_id );
		$this->assertSame( 'AAFM Contract Colour', $updated->name, 'The sent field updates.' );
		$this->assertSame( 'name', $updated->order_by, 'order_by is backfilled, not reset (9.1 contract).' );
		$this->assertEquals( 1, (int) $updated->has_archives, 'has_archives is backfilled, not reset (9.1 contract).' );

		wc_delete_attribute( $attribute_id );
	}

	/**
	 * M3 version-safety: the ability's update executor no longer trusts wc_update_attribute()'s
	 * own backfill (that only exists from 9.1.0); instead it resolves the CURRENT row from
	 * wc_get_attribute_taxonomies() and backfills every field itself before writing. This pins the
	 * exact stdClass property names that resolve step reads (attributes.php:aafm_wc_get_attribute /
	 * aafm_redact_wc_attribute), so a WooCommerce refactor that renames one is caught here instead
	 * of silently producing an empty or default field on write.
	 */
	public function test_attribute_taxonomy_row_shape(): void {
		if ( ! function_exists( 'wc_create_attribute' ) || ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$this->markTestSkipped( 'WC attribute functions unavailable.' );
		}

		$attribute_id = wc_create_attribute(
			array(
				'name'         => 'AAFM Contract Shape',
				'slug'         => 'aafm_contract_shape',
				'type'         => 'select',
				'order_by'     => 'name',
				'has_archives' => true,
			)
		);
		$this->assertIsInt( $attribute_id );
		$this->assertGreaterThan( 0, $attribute_id );

		$row = null;
		foreach ( wc_get_attribute_taxonomies() as $candidate ) {
			if ( (int) ( $candidate->attribute_id ?? 0 ) === $attribute_id ) {
				$row = $candidate;
				break;
			}
		}
		$this->assertNotNull( $row, 'The created attribute must appear in wc_get_attribute_taxonomies().' );

		// The exact property names aafm_wc_get_attribute()/aafm_redact_wc_attribute() read, and
		// which the version-safe update executor now backfills from.
		foreach ( array( 'attribute_id', 'attribute_name', 'attribute_label', 'attribute_type', 'attribute_orderby', 'attribute_public' ) as $property ) {
			$this->assertObjectHasProperty( $property, $row, "Attribute row must expose {$property}." );
		}
		$this->assertSame( 'aafm_contract_shape', $row->attribute_name, 'attribute_name is the raw, unprefixed slug.' );
		$this->assertSame( 'AAFM Contract Shape', $row->attribute_label, 'attribute_label is the human name.' );

		wc_delete_attribute( $attribute_id );
	}
}
