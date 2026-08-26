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

	/**
	 * MCP defect-fix premise: `WC_Order::add_product()` always creates a NEW order item -- it never
	 * merges into an existing line item that already carries the same product id. This is the
	 * vendor fact the wc-update-order additive fix (add_line_items / the deprecated line_items
	 * alias) depends on: adding the same product twice results in two line items, never one line
	 * with a combined quantity, so "additive" genuinely means "adds a new line", not "increments
	 * an existing one".
	 */
	public function test_add_product_always_creates_a_new_line_item(): void {
		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order unavailable.' );
		}

		$product = new \WC_Product();
		$product->set_name( 'AAFM Contract Widget' );
		$product->set_regular_price( '9.99' );
		$product_id = $product->save();
		$this->assertGreaterThan( 0, $product_id );

		$order = new \WC_Order();
		$order->add_product( wc_get_product( $product_id ), 2 );
		$order->add_product( wc_get_product( $product_id ), 3 );
		$order->save();

		$items = $order->get_items();
		$this->assertCount(
			2,
			$items,
			'add_product() called twice for the SAME product must produce two line items, not merge into one.'
		);

		$quantities = array();
		foreach ( $items as $item ) {
			$quantities[] = (int) $item->get_quantity();
		}
		sort( $quantities );
		$this->assertSame(
			array( 2, 3 ),
			$quantities,
			'Each add_product() call keeps its own requested quantity -- none are summed into an existing line.'
		);

		$order->delete( true );
		$product->delete( true );
	}

	/**
	 * THE regression test for the wc-update-order partial-write defect. `WC_Order::add_product()`
	 * calls `$item->save()` immediately -- it does not wait for `$order->save()`. Before the fix,
	 * `aafm_wc_apply_order_input()` looped over the combined line_items/add_line_items list and
	 * called add_product() as each id resolved, only detecting an unresolvable id (and returning
	 * WP_Error) after any earlier valid item had already been persisted. This is invisible to the
	 * stub suite: the stub's add_product() only mutates the in-memory PHP object and is never
	 * flushed to WcOrderStubStore until $order->save() runs, so a stub test cannot distinguish the
	 * buggy single-pass loop from the fixed two-pass one. Only a real WC_Order, whose add_product()
	 * genuinely persists ahead of save(), can prove the write never partially lands.
	 */
	public function test_update_order_unresolved_item_leaves_no_partial_write(): void {
		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order unavailable.' );
		}

		$existing_product = new \WC_Product();
		$existing_product->set_name( 'AAFM Contract Existing Widget' );
		$existing_product->set_regular_price( '9.00' );
		$existing_product_id = $existing_product->save();
		$this->assertGreaterThan( 0, $existing_product_id );

		$new_product = new \WC_Product();
		$new_product->set_name( 'AAFM Contract New Widget' );
		$new_product->set_regular_price( '12.50' );
		$new_product_id = $new_product->save();
		$this->assertGreaterThan( 0, $new_product_id );

		// Seed a baseline order that already carries one line item, mirroring the finding's exact
		// scenario -- an order that exists and must come out of the failed update EXACTLY as it went
		// in, not just "no items were added".
		$order = new \WC_Order();
		$order->add_product( wc_get_product( $existing_product_id ), 1 );
		$order->calculate_totals();
		$order->save();
		$order_id = $order->get_id();
		$this->assertGreaterThan( 0, $order_id );

		// Snapshot from a fresh DB read (not the in-memory $order object) so the baseline and the
		// post-failure read are captured identically.
		$baseline     = new \WC_Order( $order_id );
		$before_count = count( $baseline->get_items() );
		$before_total = $baseline->get_total();
		$this->assertSame( 1, $before_count, 'Baseline order must seed exactly one line item.' );

		$bogus_product_id = 999999999;
		$result           = aafm_exec_wc_update_order(
			array(
				'order_id'       => $order_id,
				'add_line_items' => array(
					array(
						'product_id' => $new_product_id,
						'quantity'   => 2,
					),
					array(
						'product_id' => $bogus_product_id,
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'An unresolvable product id in the combined list must fail the whole request.'
		);

		// Re-read the order fresh from the DB -- not the in-memory $order object -- so a premature
		// add_product() write for the valid new item would show up even though this PHP request
		// never called $order->save() again itself.
		$reloaded = new \WC_Order( $order_id );
		$this->assertCount(
			$before_count,
			$reloaded->get_items(),
			'The valid item that precedes the unresolvable one in the list must NOT have been persisted.'
		);
		$this->assertSame(
			$before_total,
			$reloaded->get_total(),
			'The order total must be exactly what it was before the failed update -- no partial write.'
		);

		foreach ( $reloaded->get_items() as $item ) {
			$this->assertSame(
				$existing_product_id,
				(int) $item->get_product_id(),
				'The only surviving line item must be the original one -- the rejected new item is absent.'
			);
		}

		$order->delete( true );
		$existing_product->delete( true );
		$new_product->delete( true );
	}

	/**
	 * The all-valid path: every item in the combined line_items/add_line_items list must still be
	 * added, in the documented order (line_items first, then add_line_items), once every id
	 * resolves. Pins that the two-pass fix does not drop, duplicate, or reorder items when there is
	 * nothing to reject.
	 */
	public function test_update_order_all_valid_items_are_added_in_order(): void {
		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order unavailable.' );
		}

		$product_a = new \WC_Product();
		$product_a->set_name( 'AAFM Contract Order Item A' );
		$product_a->set_regular_price( '5.00' );
		$product_a_id = $product_a->save();

		$product_b = new \WC_Product();
		$product_b->set_name( 'AAFM Contract Order Item B' );
		$product_b->set_regular_price( '7.00' );
		$product_b_id = $product_b->save();

		$order = new \WC_Order();
		$order->save();
		$order_id = $order->get_id();

		$result = aafm_exec_wc_update_order(
			array(
				'order_id'       => $order_id,
				'line_items'     => array(
					array(
						'product_id' => $product_a_id,
						'quantity'   => 1,
					),
				),
				'add_line_items' => array(
					array(
						'product_id' => $product_b_id,
						'quantity'   => 3,
					),
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$reloaded = new \WC_Order( $order_id );
		$items    = array_values( $reloaded->get_items() );
		$this->assertCount( 2, $items, 'Both the line_items and add_line_items entries must be added.' );
		$this->assertSame( $product_a_id, (int) $items[0]->get_product_id(), 'line_items entries are added first.' );
		$this->assertSame( 1, (int) $items[0]->get_quantity() );
		$this->assertSame( $product_b_id, (int) $items[1]->get_product_id(), 'add_line_items entries follow.' );
		$this->assertSame( 3, (int) $items[1]->get_quantity() );

		$order->delete( true );
		$product_a->delete( true );
		$product_b->delete( true );
	}

	/**
	 * Wc-create-order companion to the update regression above. On create, the order passed into
	 * aafm_wc_apply_order_input() is UNSAVED (get_id() === 0): add_product() still calls
	 * $item->save() immediately, which persists an order_item row against order_id 0 -- an orphaned
	 * row, since wp_insert_post() for the order itself never runs when the executor returns before
	 * calling $order->save(). The two-pass fix avoids this too, because it never calls add_product()
	 * at all until every id in the list has resolved. Pins that no order_item row leaks into
	 * wp_woocommerce_order_items when wc-create-order fails on an unresolvable product id.
	 */
	public function test_create_order_unresolved_item_leaves_no_orphaned_order_item_rows(): void {
		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order unavailable.' );
		}

		global $wpdb;

		$product = new \WC_Product();
		$product->set_name( 'AAFM Contract Orphan-Row Widget' );
		$product->set_regular_price( '3.25' );
		$product_id = $product->save();

		$items_table  = $wpdb->prefix . 'woocommerce_order_items';
		$before_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $items_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed table name, test-only.

		$result = aafm_exec_wc_create_order(
			array(
				'line_items' => array(
					array(
						'product_id' => $product_id,
						'quantity'   => 1,
					),
					array(
						'product_id' => 999999998,
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result, 'An unresolvable product id must fail the whole create.' );

		$after_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $items_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed table name, test-only.
		$this->assertSame(
			$before_count,
			$after_count,
			'The valid item preceding the unresolvable one must never have been persisted -- not even against an orphaned order_id 0 row.'
		);

		$product->delete( true );
	}

	/**
	 * Wc-create-order's all-valid path is unchanged by the atomicity fix: every requested item is
	 * still added and the order still saves with a calculated total.
	 */
	public function test_create_order_behavior_unchanged_for_valid_items(): void {
		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order unavailable.' );
		}

		$product = new \WC_Product();
		$product->set_name( 'AAFM Contract Create-Order Widget' );
		$product->set_regular_price( '10.00' );
		$product_id = $product->save();

		$result = aafm_exec_wc_create_order(
			array(
				'line_items' => array(
					array(
						'product_id' => $product_id,
						'quantity'   => 2,
					),
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertGreaterThan( 0, $result['id'] );

		$order = new \WC_Order( (int) $result['id'] );
		$items = array_values( $order->get_items() );
		$this->assertCount( 1, $items );
		$this->assertSame( $product_id, (int) $items[0]->get_product_id() );
		$this->assertSame( 2, (int) $items[0]->get_quantity() );
		$this->assertSame( '20.00', $order->get_total(), 'calculate_totals() must still run after all items resolve.' );

		$order->delete( true );
		$product->delete( true );
	}

	/**
	 * The B-05 recalculation added calculate_totals() to the UPDATE path, which 1.6.3 never called
	 * there. That put a second, unguarded failure point AFTER add_product() has already persisted
	 * its item rows: WooCommerce fires woocommerce_order_before_calculate_totals from inside
	 * calculate_totals(), and any extension listening on it can throw. B27's rollback wraps the add
	 * loop only, so a throw at that later point left the new item row written, the order total
	 * stale, and a raw Throwable escaping the ability -- goods on the order that the total never
	 * bills for, which is the exact harm B-05 exists to stop. Pins that a recalculation failure
	 * returns a WP_Error and leaves the order exactly as it was found.
	 */
	public function test_update_order_recalculation_failure_leaves_no_partial_line_item_write(): void {
		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order unavailable.' );
		}

		$product = new \WC_Product();
		$product->set_name( 'AAFM Contract Recalc-Failure Widget' );
		$product->set_regular_price( '14.99' );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();

		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order_id = (int) $order->save();

		$total_before = $order->get_total();
		$items_before = count( $order->get_items() );

		// An extension that refuses to let the order recalculate.
		$boom = static function (): void {
			throw new \RuntimeException( 'Extension refused the recalculation.' );
		};
		add_action( 'woocommerce_order_before_calculate_totals', $boom, 10, 0 );
		$result = aafm_exec_wc_update_order(
			array(
				'order_id'       => $order_id,
				'add_line_items' => array(
					array(
						'product_id' => $product_id,
						'quantity'   => 1,
					),
				),
			)
		);
		remove_action( 'woocommerce_order_before_calculate_totals', $boom, 10 );

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'A recalculation failure must be reported as a WP_Error, not raised as an uncaught Throwable.'
		);

		$reloaded = new \WC_Order( $order_id );
		$this->assertCount(
			$items_before,
			$reloaded->get_items(),
			'The item added before the recalculation failed must be rolled back, not left on the order.'
		);
		$this->assertSame(
			$total_before,
			$reloaded->get_total(),
			'The order total must be exactly what it was before the failed request.'
		);

		$reloaded->delete( true );
		$product->delete( true );
	}

	/**
	 * R3-2: the same failure, but from the hook that fires AFTER WooCommerce has already saved.
	 *
	 * The sibling test above throws from woocommerce_order_before_calculate_totals, which runs
	 * before any mutation, so it proves only the easy half. calculate_totals() is not atomic: it
	 * calls calculate_taxes() first, whose own WooCommerce comment reads "Note; this also triggers
	 * save()", and fires woocommerce_order_after_calculate_totals only afterwards. An extension
	 * throwing from that later hook therefore leaves recalculated tax ON DISK.
	 *
	 * Measured against real WooCommerce before the fix: an order holding one 100 line at 20% went
	 * from a persisted tax of 20 to a persisted tax of 40 while the ability returned
	 * "the order is unchanged" and the added item had been correctly rolled back. The order was
	 * left claiming more tax than its own line items justified.
	 *
	 * Taxes are switched on through FILTERS rather than options, so this test changes no store
	 * setting and has nothing to restore.
	 */
	public function test_update_order_recalculation_failure_after_taxes_are_saved_restores_the_money(): void {
		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order unavailable.' );
		}

		$tax_on   = '__return_true';
		$tax_rate = static function (): array {
			return array(
				999 => array(
					'rate'     => 20.0,
					'label'    => 'AAFM Contract Tax',
					'shipping' => 'no',
					'compound' => 'no',
				),
			);
		};
		add_filter( 'wc_tax_enabled', $tax_on );
		add_filter( 'woocommerce_matched_tax_rates', $tax_rate );

		$product = new \WC_Product();
		$product->set_name( 'AAFM Contract Late-Recalc Widget' );
		$product->set_regular_price( '100' );
		$product->set_tax_status( 'taxable' );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();

		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );
		$order_id = (int) $order->save();

		$before = $this->money_state( $order_id );
		$this->assertNotSame( '', $before['tax_rows'], 'The fixture must actually carry tax, or this test proves nothing.' );

		$boom = static function (): void {
			throw new \RuntimeException( 'Extension exploded after totals were calculated.' );
		};
		add_action( 'woocommerce_order_after_calculate_totals', $boom, 10, 0 );
		$result = aafm_exec_wc_update_order(
			array(
				'order_id'       => $order_id,
				'add_line_items' => array(
					array(
						'product_id' => $product_id,
						'quantity'   => 1,
					),
				),
			)
		);
		remove_action( 'woocommerce_order_after_calculate_totals', $boom, 10 );

		$this->assertInstanceOf( \WP_Error::class, $result, 'A late recalculation failure must be a WP_Error.' );
		$this->assertSame(
			'aafm_wc_line_items_not_applied',
			$result->get_error_code(),
			'The strong error code is only earned when the money was actually put back; the weak path has its own code.'
		);

		$after = $this->money_state( $order_id );
		$this->assertSame(
			$before['tax_rows'],
			$after['tax_rows'],
			'The persisted tax rows must be exactly what they were. This is the field that broke: it doubled and stayed doubled.'
		);
		$this->assertSame( $before['total'], $after['total'], 'The order total must be exactly what it was.' );
		$this->assertSame( $before['total_tax'], $after['total_tax'], 'The order tax must be exactly what it was.' );
		$this->assertSame( $before['items'], $after['items'], 'Every surviving line item must carry its original net and tax.' );

		$reloaded = new \WC_Order( $order_id );
		$reloaded->delete( true );
		$product->delete( true );
		remove_filter( 'wc_tax_enabled', $tax_on );
		remove_filter( 'woocommerce_matched_tax_rates', $tax_rate );
	}

	/**
	 * The same late-throwing extension, but on CREATE, where the failure is worse.
	 *
	 * Before this fix, aafm_exec_wc_create_order() called calculate_totals() with no catch, so the exception
	 * left the ability as a raw Throwable. That is not a wrong answer, it is an unhandled crash: the
	 * agent gets no structured error and nothing to act on.
	 *
	 * The part that surprises is that there is an order to clean up. `new WC_Order()` persists
	 * nothing, but calculate_totals() runs calculate_taxes() and WooCommerce saves inside it, so the
	 * order exists by the time the after hook throws. Measured before the fix: the order table
	 * gained a row AND the Throwable escaped.
	 *
	 * Asserts the outcome on both counts, because either alone would pass while the other was
	 * broken: a structured error is worthless if it leaves a half-built order behind, and a clean
	 * table is worthless if the ability still crashes.
	 */
	public function test_create_order_recalculation_failure_leaves_no_order_behind(): void {
		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order unavailable.' );
		}

		global $wpdb;

		$product = new \WC_Product();
		$product->set_name( 'AAFM Contract Create-Crash Widget' );
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();

		$orders_before = $this->count_orders();

		$boom = static function (): void {
			throw new \RuntimeException( 'Extension exploded during create.' );
		};
		add_action( 'woocommerce_order_after_calculate_totals', $boom, 10, 0 );

		$threw  = null;
		$result = null;
		try {
			$result = aafm_exec_wc_create_order(
				array(
					'status'     => 'pending',
					'line_items' => array(
						array(
							'product_id' => $product_id,
							'quantity'   => 1,
						),
					),
				)
			);
		} catch ( \Throwable $e ) {
			$threw = $e;
		}
		remove_action( 'woocommerce_order_after_calculate_totals', $boom, 10 );

		$this->assertNull(
			$threw,
			'A late create failure must not escape as a raw Throwable. On this plugin\'s WP floor nothing above catches it, so the agent gets a crash instead of an error it can act on.'
		);
		$this->assertInstanceOf( \WP_Error::class, $result, 'The failure must come back as a structured WP_Error.' );
		$this->assertSame(
			'aafm_wc_order_not_created',
			$result->get_error_code(),
			'The clean code is only earned when nothing was left behind; the partial case has its own.'
		);

		$this->assertSame(
			$orders_before,
			$this->count_orders(),
			'A failed create must leave no order behind. calculate_taxes() saves, so the order really does exist by the time the throw is caught.'
		);

		$product->delete( true );
		unset( $wpdb );
	}

	/**
	 * R4-2: the rollback must survive an extension that throws while the rollback is cleaning up.
	 *
	 * WooCommerce's wc_delete_order_item() fires woocommerce_before_delete_order_item before removing the row, so
	 * a callback listening there throws straight out of the deletion helper. Uncaught, that skipped
	 * the order-level delete entirely and let a raw Throwable leave the ability with the part-built
	 * order still in place, which is both halves of the defect the create fix was written to close,
	 * reappearing exactly when an extension misbehaves during cleanup.
	 *
	 * Two extensions throw here, one from each hook, which is the realistic shape: whatever is
	 * broken enough to fail during totals is broken enough to fail during cleanup.
	 *
	 * Measured before the fix: `RuntimeException: extension exploded during item cleanup` escaped
	 * the ability and the order table gained a row.
	 */
	public function test_create_rollback_survives_an_extension_that_throws_during_item_cleanup(): void {
		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order unavailable.' );
		}

		$product = new \WC_Product();
		$product->set_name( 'AAFM Contract Cleanup-Crash Widget' );
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();

		$orders_before = $this->count_orders();

		$boom_totals  = static function (): void {
			throw new \RuntimeException( 'Extension exploded after totals.' );
		};
		$boom_cleanup = static function (): void {
			throw new \RuntimeException( 'Extension exploded during item cleanup.' );
		};
		add_action( 'woocommerce_order_after_calculate_totals', $boom_totals, 10, 0 );
		add_action( 'woocommerce_before_delete_order_item', $boom_cleanup, 10, 0 );

		$threw  = null;
		$result = null;
		try {
			$result = aafm_exec_wc_create_order(
				array(
					'status'     => 'pending',
					'line_items' => array(
						array(
							'product_id' => $product_id,
							'quantity'   => 1,
						),
					),
				)
			);
		} catch ( \Throwable $e ) {
			$threw = $e;
		}
		remove_action( 'woocommerce_order_after_calculate_totals', $boom_totals, 10 );
		remove_action( 'woocommerce_before_delete_order_item', $boom_cleanup, 10 );

		$this->assertNull(
			$threw,
			'A rollback that can itself crash is not a rollback. The cleanup exception must not escape the ability.'
		);
		$this->assertInstanceOf( \WP_Error::class, $result, 'The caller must still get a structured error.' );
		$this->assertSame(
			$orders_before,
			$this->count_orders(),
			'The order-level delete must still run even though the item delete threw, so no part-built order survives.'
		);

		$product->delete( true );
	}

	/**
	 * R4-1: the strong "totals and taxes were put back" message must not be returned over a tax row
	 * whose rate identity is still the new rate's.
	 *
	 * WooCommerce's update_taxes() rewrites a tax row's rate code, label, compound flag AND rate percent from the
	 * current rate before the late hook fires, not just its amounts. Restoring the amounts alone
	 * left the old money sitting under the new rate's identity, and because the verification
	 * compared only amounts, that inconsistent row still earned the strong message.
	 *
	 * The rate really is edited mid-test, because WC_Tax::get_rate_percent_value() reads the rate
	 * table directly and has no filter, so this cannot be faked. The rate is created, edited and
	 * deleted by this test; no pre-existing rate is touched.
	 *
	 * Measured before the fix: tax_total restored to 20, but rate_percent 5 and label AAFMREPROB,
	 * with the strong error code returned.
	 */
	public function test_late_failure_restores_tax_rate_identity_not_just_the_amounts(): void {
		global $wpdb;

		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) || ! class_exists( '\WC_Tax' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order/WC_Tax unavailable.' );
		}

		$rate_percent = 20.0;
		$tax_on       = '__return_true';
		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'AAFMCONTRACTA',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 0,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
		$rate_id = (int) $wpdb->insert_id;
		$matched = static function () use ( $rate_id, &$rate_percent ): array {
			return array(
				$rate_id => array(
					'rate'     => (float) $rate_percent,
					'label'    => 'AAFM Contract Tax',
					'shipping' => 'no',
					'compound' => 'no',
				),
			);
		};
		add_filter( 'wc_tax_enabled', $tax_on );
		add_filter( 'woocommerce_matched_tax_rates', $matched );

		$product = new \WC_Product();
		$product->set_name( 'AAFM Contract Rate-Identity Widget' );
		$product->set_regular_price( '100' );
		$product->set_tax_status( 'taxable' );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();

		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );
		$order_id = (int) $order->save();

		$before = $this->tax_row_identity( $order_id );
		$this->assertSame( '20', $before['rate_percent'], 'The fixture must record the ORIGINAL rate, or this test proves nothing.' );

		// The shop edits that same rate, which is what makes the metadata diverge.
		\WC_Tax::_update_tax_rate(
			$rate_id,
			array(
				'tax_rate'      => '5.0000',
				'tax_rate_name' => 'AAFMCONTRACTB',
			)
		);
		$rate_percent = 5.0;
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );

		$boom = static function (): void {
			throw new \RuntimeException( 'Extension exploded after totals were calculated.' );
		};
		add_action( 'woocommerce_order_after_calculate_totals', $boom, 10, 0 );
		$result = aafm_exec_wc_update_order(
			array(
				'order_id'       => $order_id,
				'add_line_items' => array(
					array(
						'product_id' => $product_id,
						'quantity'   => 1,
					),
				),
			)
		);
		remove_action( 'woocommerce_order_after_calculate_totals', $boom, 10 );

		$after = $this->tax_row_identity( $order_id );

		$this->assertSame(
			$before['rate_percent'],
			$after['rate_percent'],
			'The tax row\'s rate percent must be restored. This is the field that survived as the NEW rate while the strong message was still returned.'
		);
		$this->assertSame( $before['label'], $after['label'], 'The tax row\'s label must be restored too.' );
		$this->assertSame( $before['tax_total'], $after['tax_total'], 'The amount must still be restored.' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'aafm_wc_line_items_not_applied',
			$result->get_error_code(),
			'With the identity genuinely restored the strong message is earned; it must not be earned any other way.'
		);

		$reloaded = new \WC_Order( $order_id );
		$reloaded->delete( true );
		$product->delete( true );
		\WC_Tax::_delete_tax_rate( $rate_id );
		remove_filter( 'wc_tax_enabled', $tax_on );
		remove_filter( 'woocommerce_matched_tax_rates', $matched );
	}

	/**
	 * R5-2: a DISPLAY value must never be written back as stored order history.
	 *
	 * WooCommerce getters default to 'view' context, and view context runs the property's display
	 * filter (WC_Data::get_prop). A snapshot built from default getters therefore records what an
	 * extension wants shown, not what is stored, and the restore writes that presentation value into
	 * the order. The verification, re-reading through the same filter, agrees with itself and hands
	 * back the strong message over a corrupted row.
	 *
	 * The rate is deliberately NOT changed here. Recalculation rewrites the correct raw values; only
	 * the restore corrupts them. That isolates the defect to the snapshot's context.
	 *
	 * Measured before the fix, with a filter that only prettifies the label on screen: the stored raw
	 * label went from "AAFMRAW" to "Display Tax" and the strong message was still returned.
	 *
	 * Every assertion reads with an explicit 'edit', which is the whole point: reading back through
	 * the view getter would return the filtered value in both versions and prove nothing.
	 */
	public function test_a_display_filter_is_never_written_into_stored_order_history(): void {
		global $wpdb;

		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) || ! class_exists( '\WC_Tax' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order/WC_Tax unavailable.' );
		}

		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'AAFMVIEWCTX',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 0,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
		$rate_id = (int) $wpdb->insert_id;

		$tax_on  = '__return_true';
		$matched = static function () use ( $rate_id ): array {
			return array(
				$rate_id => array(
					'rate'     => 20.0,
					'label'    => 'AAFM Contract Tax',
					'shipping' => 'no',
					'compound' => 'no',
				),
			);
		};
		add_filter( 'wc_tax_enabled', $tax_on );
		add_filter( 'woocommerce_matched_tax_rates', $matched );

		$product = new \WC_Product();
		$product->set_name( 'AAFM Contract View-Context Widget' );
		$product->set_regular_price( '100' );
		$product->set_tax_status( 'taxable' );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();

		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );
		$order_id = (int) $order->save();

		$before = $this->raw_tax_identity( $order_id );
		$this->assertNotSame( '', $before['label'], 'The fixture must carry a real stored label, or this test proves nothing.' );

		// An extension that only prettifies the tax row ON SCREEN. It stores nothing.
		$display = static function () {
			return 'Display Tax';
		};
		add_filter( 'woocommerce_order_item_get_rate_code', $display );
		add_filter( 'woocommerce_order_item_get_label', $display );

		$boom = static function (): void {
			throw new \RuntimeException( 'Extension exploded after totals were calculated.' );
		};
		add_action( 'woocommerce_order_after_calculate_totals', $boom, 10, 0 );
		$result = aafm_exec_wc_update_order(
			array(
				'order_id'       => $order_id,
				'add_line_items' => array(
					array(
						'product_id' => $product_id,
						'quantity'   => 1,
					),
				),
			)
		);
		remove_action( 'woocommerce_order_after_calculate_totals', $boom, 10 );
		remove_filter( 'woocommerce_order_item_get_rate_code', $display );
		remove_filter( 'woocommerce_order_item_get_label', $display );

		$after = $this->raw_tax_identity( $order_id );

		$this->assertSame(
			$before['label'],
			$after['label'],
			'The RAW stored label must be untouched. This is the field that took the display value and kept it.'
		);
		$this->assertSame( $before['rate_code'], $after['rate_code'], 'The RAW stored rate code must be untouched too.' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'aafm_wc_line_items_not_applied',
			$result->get_error_code(),
			'The strong message is only correct when what was put back is what was actually stored.'
		);

		$reloaded = new \WC_Order( $order_id );
		$reloaded->delete( true );
		$product->delete( true );
		\WC_Tax::_delete_tax_rate( $rate_id );
		remove_filter( 'wc_tax_enabled', $tax_on );
		remove_filter( 'woocommerce_matched_tax_rates', $matched );
	}

	/**
	 * B2-01 / B2-03: reading a product and writing it straight back must change nothing.
	 *
	 * The most ordinary turn an agent takes, and it used to silently demote a global attribute to a
	 * local one. The read reported a taxonomy attribute's options as term IDS; echoing those back
	 * rebuilt the attribute with set_id( 0 ), which dropped the taxonomy binding, so
	 * `_product_attributes` flipped is_taxonomy 1 to 0 and every variation keyed on it was left
	 * holding a value the product no longer declared. The response was isError:false throughout.
	 *
	 * EVERY assertion here is on STORED state or on a downstream operation, never on the response
	 * body. The response was already a full, plausible product shape in the broken version, and a
	 * second read came back byte-identical, so a test that read the reply would pass against the bug.
	 *
	 * The term-count assertion is the one that matters most. It fails against the tempting one-line
	 * fix (carry the attribute id, leave the read emitting ids), because persisting a taxonomy
	 * attribute routes through WC_Product_Attribute::get_terms(), which resolves an unmatched option
	 * by NAME and calls wp_insert_term() on a miss: echoing ids back would create terms literally
	 * named "1768" and "1769".
	 *
	 * The fixture builds its OWN global attribute and taxonomy rather than borrowing one, so that
	 * any term pollution a regression causes lands somewhere this test owns and deletes.
	 */
	public function test_a_product_attribute_round_trip_changes_nothing(): void {
		if ( ! class_exists( '\WC_Product_Variable' ) || ! function_exists( 'wc_create_attribute' ) ) {
			$this->markTestSkipped( 'WooCommerce product/attribute API unavailable.' );
		}

		$fixture  = $this->seed_global_attribute_product( 'aafmroundtrip', 'AAFM Round Trip' );
		$taxonomy = $fixture['taxonomy'];

		$before_meta  = get_post_meta( $fixture['product_id'], '_product_attributes', true );
		$before_terms = $this->attribute_term_count( $taxonomy );

		$read = aafm_exec_wc_get_product( array( 'product_id' => $fixture['product_id'] ) );
		$this->assertIsArray( $read );
		$row = (array) ( (array) $read['attributes'] )[ $taxonomy ];

		// B2-03: the read must speak the vocabulary the write paths accept, and say which kind it is.
		$this->assertTrue( (bool) $row['taxonomy'], 'A global attribute must be flagged as taxonomy-backed.' );
		$this->assertSame(
			array( 'blue', 'green' ),
			array_values( (array) $row['options'] ),
			'A global attribute\'s options must be reported as term SLUGS, not the term ids the write path rejects.'
		);

		// B2-01: echo exactly what the read gave back.
		$result = aafm_exec_wc_update_product(
			array(
				'product_id' => $fixture['product_id'],
				'attributes' => array(
					array(
						'name'    => (string) $row['name'],
						'options' => array_values( (array) $row['options'] ),
					),
				),
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result, 'An unchanged echo is the ordinary turn and must stay lossless.' );

		clean_post_cache( $fixture['product_id'] );
		$after_meta = get_post_meta( $fixture['product_id'], '_product_attributes', true );

		$this->assertSame(
			1,
			(int) $after_meta[ $taxonomy ]['is_taxonomy'],
			'The attribute must still be global. This is the field that flipped to 0 and took the taxonomy binding with it.'
		);
		$this->assertSame(
			'',
			(string) $after_meta[ $taxonomy ]['value'],
			'A global attribute stores no inline value; a non-empty one means it was rewritten as a local attribute.'
		);
		$this->assertSame( $before_meta, $after_meta, 'The whole stored attribute row must be byte-identical after a round trip.' );
		$this->assertSame(
			$before_terms,
			$this->attribute_term_count( $taxonomy ),
			'No term may be created. This is what the one-line fix would have got wrong, via get_terms() -> wp_insert_term().'
		);

		$this->cleanup_global_attribute_product( $fixture );
	}

	/**
	 * B2-01: a genuine CHANGE to a global attribute is refused, and both custom-attribute controls
	 * still work.
	 *
	 * Refusing is right because the `attributes` field models a custom attribute and nothing else:
	 * a name and literal option strings. A global attribute's options are terms in a shared
	 * taxonomy, so the field cannot express the change, and the old behaviour of accepting it
	 * anyway is what demoted the attribute. The positive controls are in the same test on purpose:
	 * a refusal that also broke ordinary custom attributes would be a worse bug than the one fixed.
	 */
	public function test_changing_a_global_attribute_is_refused_but_custom_ones_still_work(): void {
		if ( ! class_exists( '\WC_Product_Variable' ) || ! function_exists( 'wc_create_attribute' ) ) {
			$this->markTestSkipped( 'WooCommerce product/attribute API unavailable.' );
		}

		$fixture  = $this->seed_global_attribute_product( 'aafmrefuse', 'AAFM Refuse', true );
		$taxonomy = $fixture['taxonomy'];

		$refused = aafm_exec_wc_update_product(
			array(
				'product_id' => $fixture['product_id'],
				'attributes' => array(
					array(
						'name'    => $taxonomy,
						'options' => array( 'blue', 'red' ),
					),
				),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $refused, 'A genuine change to a global attribute must be refused, not silently demoted.' );
		$this->assertSame( 'aafm_wc_global_attribute_not_editable', $refused->get_error_code() );
		// Pinned to the CURRENT guidance (commit 77e9e6b): wc-update-product-attribute cannot
		// change a global attribute's options either, so the refusal was rewritten to point at
		// what actually can - the WooCommerce admin for the option itself, then the variation
		// abilities to build variations with it. This test previously asserted the stale,
		// pre-77e9e6b wording (doc 214, item B).
		$message = $refused->get_error_message();
		$this->assertStringContainsString( 'Products > Attributes', $message, "The error must name where a global attribute's own options can actually be changed." );
		$this->assertStringContainsString( 'wc-update-product-variation', $message, 'The error must name a tool that CAN act on this attribute.' );

		clean_post_cache( $fixture['product_id'] );
		$meta = get_post_meta( $fixture['product_id'], '_product_attributes', true );
		$this->assertSame( 1, (int) $meta[ $taxonomy ]['is_taxonomy'], 'A refused write must leave the attribute global.' );

		// Positive control 1: a genuinely new custom attribute still upserts.
		$added = aafm_exec_wc_update_product(
			array(
				'product_id' => $fixture['product_id'],
				'attributes' => array(
					array(
						'name'    => 'Finish',
						'options' => array( 'Matte', 'Gloss' ),
					),
				),
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $added );

		// Positive control 2: an existing custom attribute's options are still editable.
		$edited = aafm_exec_wc_update_product(
			array(
				'product_id' => $fixture['product_id'],
				'attributes' => array(
					array(
						'name'    => 'Material',
						'options' => array( 'Cotton', 'Linen' ),
					),
				),
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $edited );

		clean_post_cache( $fixture['product_id'] );
		$meta = get_post_meta( $fixture['product_id'], '_product_attributes', true );
		$this->assertSame( 'Matte | Gloss', (string) $meta['finish']['value'], 'A new custom attribute must still be written.' );
		$this->assertSame( 'Cotton | Linen', (string) $meta['material']['value'], 'An existing custom attribute must still be editable.' );
		$this->assertSame( 1, (int) $meta[ $taxonomy ]['is_taxonomy'], 'And the global attribute must survive all of it.' );

		$this->cleanup_global_attribute_product( $fixture );
	}

	/**
	 * R6-1: a DISPLAY filter on the attributes must never reach storage, and must never create a term.
	 *
	 * WC_Product::get_attributes() defaults to view context, which runs
	 * woocommerce_product_get_attributes. Preserving that object on the unchanged-echo path carried
	 * a display-only option into set_attributes(), and saving a taxonomy attribute routes through
	 * WC_Product_Attribute::get_terms(), which resolves each option by NAME and calls
	 * wp_insert_term() on a miss. Measured before the fix: an unchanged echo created a
	 * "display-swatch" term out of a string no extension ever stored.
	 *
	 * The filter is what makes the term-count assertion able to go red. Without a phantom option in
	 * the collection the count is trivially unchanged and the test proves nothing, which is the
	 * shape a count assertion fails in. This one appends exactly one unresolvable string.
	 *
	 * Note the sibling round-trip test cannot catch this: its fixture has no filter, so its own
	 * term-count assertion passes either way. That is why this is a separate test rather than an
	 * extra assertion there.
	 */
	public function test_a_display_filter_on_attributes_never_reaches_storage(): void {
		if ( ! class_exists( '\WC_Product_Variable' ) || ! function_exists( 'wc_create_attribute' ) ) {
			$this->markTestSkipped( 'WooCommerce product/attribute API unavailable.' );
		}

		$fixture  = $this->seed_global_attribute_product( 'aafmdisplay', 'AAFM Display' );
		$taxonomy = $fixture['taxonomy'];

		$before_terms = $this->attribute_term_count( $taxonomy );
		$before_meta  = get_post_meta( $fixture['product_id'], '_product_attributes', true );

		// An extension that appends a display-only option corresponding to no term.
		$display = static function ( $attributes ) use ( $taxonomy ) {
			if ( isset( $attributes[ $taxonomy ] ) && $attributes[ $taxonomy ] instanceof \WC_Product_Attribute ) {
				$clone = clone $attributes[ $taxonomy ];
				$clone->set_options( array_merge( (array) $clone->get_options(), array( 'Display swatch' ) ) );
				$attributes[ $taxonomy ] = $clone;
			}
			return $attributes;
		};
		add_filter( 'woocommerce_product_get_attributes', $display );

		$read = aafm_exec_wc_get_product( array( 'product_id' => $fixture['product_id'] ) );
		$row  = (array) ( (array) $read['attributes'] )[ $taxonomy ];

		$result = aafm_exec_wc_update_product(
			array(
				'product_id' => $fixture['product_id'],
				'attributes' => array(
					array(
						'name'    => (string) $row['name'],
						'options' => array_values( (array) $row['options'] ),
					),
				),
			)
		);
		remove_filter( 'woocommerce_product_get_attributes', $display );

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'The echo matches what was shown, so it must still be accepted.' );

		clean_post_cache( $fixture['product_id'] );
		$this->assertSame(
			$before_terms,
			$this->attribute_term_count( $taxonomy ),
			'No term may be created. A display-only option reached get_terms() and was inserted as a real term.'
		);
		$this->assertSame(
			$before_meta,
			get_post_meta( $fixture['product_id'], '_product_attributes', true ),
			'The stored attribute row must be byte-identical; a display filter is not state.'
		);

		$this->cleanup_global_attribute_product( $fixture );
	}

	/**
	 * R7B-1: a display filter that HIDES a stored term must not turn a real edit into a false no-op.
	 *
	 * The sibling test above covers a filter that INVENTS an option, which the read path drops
	 * because it resolves to no term, so shown and stored agree by the time they are compared. This
	 * is the other direction and it is the dangerous one: hide one of two real terms and the caller
	 * is shown a set that is a legitimate edit request in its own right. Sending it back used to be
	 * classified as an unchanged echo, allowed, and applied as a no-op against stored state.
	 *
	 * Measured before the fix, through this exact path: no error, the response reported the single
	 * displayed term, and `wp_get_object_terms()` still held BOTH. The caller asked for a removal,
	 * was told it worked, and nothing changed. Note which assertion catches that -- the object-term
	 * relationship. `_product_attributes` is byte-identical either way, because a taxonomy
	 * attribute's terms do not live there, so a test that only diffed the meta row would pass
	 * against the bug.
	 *
	 * The positive control in the same test is what stops this from being fixed by refusing every
	 * filtered attribute: the caller who sends the STORED set is still accepted, filter and all,
	 * because that request is a no-op whichever way it was meant.
	 */
	public function test_a_filter_hiding_a_stored_term_cannot_make_an_edit_a_silent_no_op(): void {
		if ( ! class_exists( '\WC_Product_Variable' ) || ! function_exists( 'wc_create_attribute' ) ) {
			$this->markTestSkipped( 'WooCommerce product/attribute API unavailable.' );
		}

		$fixture  = $this->seed_global_attribute_product( 'aafmmasked', 'AAFM Masked' );
		$taxonomy = $fixture['taxonomy'];
		$blue     = get_term_by( 'slug', 'blue', $taxonomy );
		$this->assertInstanceOf( \WP_Term::class, $blue, 'The fixture must have created a blue term.' );

		$before_terms = $this->attribute_term_count( $taxonomy );
		$before_meta  = get_post_meta( $fixture['product_id'], '_product_attributes', true );

		// An extension that shows only one of the two stored terms. Every option it does show is a
		// real, resolvable term, which is what makes the displayed set a plausible edit request.
		$hide = static function ( $attributes ) use ( $taxonomy, $blue ) {
			if ( isset( $attributes[ $taxonomy ] ) && $attributes[ $taxonomy ] instanceof \WC_Product_Attribute ) {
				$clone = clone $attributes[ $taxonomy ];
				$clone->set_options( array( (int) $blue->term_id ) );
				$attributes[ $taxonomy ] = $clone;
			}
			return $attributes;
		};
		add_filter( 'woocommerce_product_get_attributes', $hide );

		$read = aafm_exec_wc_get_product( array( 'product_id' => $fixture['product_id'] ) );
		$this->assertIsArray( $read );
		$shown = array_values( (array) ( (array) ( (array) $read['attributes'] )[ $taxonomy ] )['options'] );
		$this->assertSame(
			array( 'blue' ),
			$shown,
			'The filter must actually hide a term, or this test proves nothing.'
		);

		$masked = aafm_exec_wc_update_product(
			array(
				'product_id' => $fixture['product_id'],
				'attributes' => array(
					array(
						'name'    => $taxonomy,
						'options' => $shown,
					),
				),
			)
		);

		// Positive control, with the filter still active: the stored set is still accepted.
		$echo = aafm_exec_wc_update_product(
			array(
				'product_id' => $fixture['product_id'],
				'attributes' => array(
					array(
						'name'    => $taxonomy,
						'options' => array( 'blue', 'green' ),
					),
				),
			)
		);
		remove_filter( 'woocommerce_product_get_attributes', $hide );

		$this->assertInstanceOf(
			\WP_Error::class,
			$masked,
			'A set that differs from stored state must be refused, not accepted and quietly dropped.'
		);
		$this->assertSame( 'aafm_wc_global_attribute_display_masked', $masked->get_error_code() );
		$this->assertStringNotContainsString(
			'unchanged',
			$masked->get_error_message(),
			'Telling this caller to resend the current options is the advice that just failed them.'
		);
		$this->assertNotInstanceOf(
			\WP_Error::class,
			$echo,
			'A caller who sends the STORED set is asking for nothing and must still be accepted.'
		);

		clean_post_cache( $fixture['product_id'] );
		$this->assertSame(
			array( 'blue', 'green' ),
			array_values( (array) wp_get_object_terms( $fixture['product_id'], $taxonomy, array( 'fields' => 'slugs' ) ) ),
			'Both terms must still be related to the product. This is the assertion that goes red on the false no-op.'
		);
		$this->assertSame(
			$before_terms,
			$this->attribute_term_count( $taxonomy ),
			'No term may be created on either request.'
		);
		$this->assertSame(
			$before_meta,
			get_post_meta( $fixture['product_id'], '_product_attributes', true ),
			'The stored attribute row must be byte-identical; a refusal writes nothing and a no-op changes nothing.'
		);

		$this->cleanup_global_attribute_product( $fixture );
	}

	/**
	 * Create a global attribute, its taxonomy, two terms, and a variable product declaring it.
	 *
	 * @param string $slug          Attribute slug (without the pa_ prefix).
	 * @param string $label         Attribute label.
	 * @param bool   $with_custom   Also give the product a custom attribute, for the positive controls.
	 * @return array<string,mixed>
	 */
	private function seed_global_attribute_product( string $slug, string $label, bool $with_custom = false ): array {
		$attribute_id = wc_create_attribute(
			array(
				'name' => $label,
				'slug' => $slug,
				'type' => 'select',
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $attribute_id, 'The fixture attribute must be creatable.' );
		$attribute_id = (int) $attribute_id;
		$taxonomy     = wc_attribute_taxonomy_name( $slug );
		register_taxonomy( $taxonomy, 'product', array( 'public' => false ) );

		$term_ids = array();
		foreach ( array(
			'blue'  => 'Blue',
			'green' => 'Green',
		) as $term_slug => $term_name ) {
			$term       = wp_insert_term( $term_name, $taxonomy, array( 'slug' => $term_slug ) );
			$term_ids[] = (int) $term['term_id'];
		}

		$global = new \WC_Product_Attribute();
		$global->set_id( $attribute_id );
		$global->set_name( $taxonomy );
		$global->set_options( $term_ids );
		$global->set_visible( true );
		$global->set_variation( true );

		$attributes = array( $global );
		if ( $with_custom ) {
			$custom = new \WC_Product_Attribute();
			$custom->set_id( 0 );
			$custom->set_name( 'Material' );
			$custom->set_options( array( 'Cotton', 'Wool' ) );
			$custom->set_visible( true );
			$attributes[] = $custom;
		}

		$product = new \WC_Product_Variable();
		$product->set_name( 'AAFM Contract ' . $label . ' Product' );
		$product->set_status( 'publish' );
		$product->set_attributes( $attributes );

		return array(
			'attribute_id' => $attribute_id,
			'taxonomy'     => $taxonomy,
			'product_id'   => (int) $product->save(),
		);
	}

	/**
	 * Remove everything seed_global_attribute_product() created.
	 *
	 * @param array<string,mixed> $fixture From seed_global_attribute_product().
	 * @return void
	 */
	private function cleanup_global_attribute_product( array $fixture ): void {
		$product = wc_get_product( (int) $fixture['product_id'] );
		if ( $product ) {
			$product->delete( true );
		}
		foreach ( (array) get_terms(
			array(
				'taxonomy'   => (string) $fixture['taxonomy'],
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		) as $term_id ) {
			wp_delete_term( (int) $term_id, (string) $fixture['taxonomy'] );
		}
		wc_delete_attribute( (int) $fixture['attribute_id'] );
	}

	/**
	 * Count the terms in an attribute taxonomy, so term creation by a regression is visible.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 * @return int
	 */
	private function attribute_term_count( string $taxonomy ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		return is_array( $terms ) ? count( $terms ) : 0;
	}

	/**
	 * The first tax row's identity as STORED, read with an explicit edit context so no display
	 * filter can touch it.
	 *
	 * @param int $order_id Order id.
	 * @return array<string,string>
	 */
	private function raw_tax_identity( int $order_id ): array {
		wp_cache_flush();
		$order = new \WC_Order( $order_id );
		foreach ( $order->get_taxes() as $tax_item ) {
			return array(
				'rate_code' => (string) $tax_item->get_rate_code( 'edit' ),
				'label'     => (string) $tax_item->get_label( 'edit' ),
			);
		}
		return array(
			'rate_code' => '',
			'label'     => '',
		);
	}

	/**
	 * R5-1: a throw from the hook that fires AFTER deletion must not become a phantom survivor.
	 *
	 * The sibling cleanup test throws from woocommerce_before_delete_order_item, which fires while
	 * the row still exists, so it cannot reach this path at all. WooCommerce fires
	 * woocommerce_delete_order_item AFTER the row is gone, and the catch added for R4-2 reports that
	 * id as unconfirmed. Unconfirmed is correct; treating it as a survivor is not.
	 *
	 * Measured before the fix: the order held zero item rows and the error said
	 * "Order item ids still persisted: 77" for an id that did not exist. A caller acting on that id
	 * operates on nothing.
	 *
	 * The discriminating assertion is the ERROR CODE, not the row count: the row count is zero in
	 * both versions, because the deletion genuinely succeeded either way. Only the message was wrong.
	 */
	public function test_a_post_delete_throw_is_not_reported_as_a_surviving_item(): void {
		global $wpdb;

		if ( ! class_exists( '\WC_Product' ) || ! class_exists( '\WC_Order' ) ) {
			$this->markTestSkipped( 'WC_Product/WC_Order unavailable.' );
		}

		$product_a = new \WC_Product();
		$product_a->set_name( 'AAFM Contract Post-Delete A' );
		$product_a->set_regular_price( '10' );
		$product_a->set_status( 'publish' );
		$id_a = (int) $product_a->save();

		$product_b = new \WC_Product();
		$product_b->set_name( 'AAFM Contract Post-Delete B' );
		$product_b->set_regular_price( '10' );
		$product_b->set_status( 'publish' );
		$id_b = (int) $product_b->save();

		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order_id = (int) $order->save();

		// Explodes while B's price is read, so B's add_product() fails AFTER A's row was written.
		$boom_add = static function ( $price, $product ) use ( $id_b ) {
			if ( (int) $product->get_id() === $id_b ) {
				throw new \RuntimeException( 'Extension exploded reading product B price.' );
			}
			return $price;
		};
		// Explodes from the hook that fires AFTER the row has been deleted.
		$boom_post_delete = static function (): void {
			throw new \RuntimeException( 'Extension exploded after the item row was deleted.' );
		};
		add_filter( 'woocommerce_product_get_price', $boom_add, 10, 2 );
		add_action( 'woocommerce_delete_order_item', $boom_post_delete, 10, 0 );

		$result = aafm_exec_wc_update_order(
			array(
				'order_id'       => $order_id,
				'add_line_items' => array(
					array(
						'product_id' => $id_a,
						'quantity'   => 1,
					),
					array(
						'product_id' => $id_b,
						'quantity'   => 1,
					),
				),
			)
		);

		remove_filter( 'woocommerce_product_get_price', $boom_add, 10 );
		remove_action( 'woocommerce_delete_order_item', $boom_post_delete, 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'aafm_wc_line_items_not_applied',
			$result->get_error_code(),
			'The row really was deleted, so the error must say the order is unchanged rather than claim an item survived.'
		);

		$rows = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d", $order_id )
		);
		$this->assertSame( 0, $rows, 'No item row from this request may remain on the order.' );

		$reloaded = new \WC_Order( $order_id );
		$reloaded->delete( true );
		$product_a->delete( true );
		$product_b->delete( true );
	}

	/**
	 * The first tax row's amount and rate identity, read back from storage.
	 *
	 * @param int $order_id Order id.
	 * @return array<string,string>
	 */
	private function tax_row_identity( int $order_id ): array {
		wp_cache_flush();
		$order = new \WC_Order( $order_id );
		foreach ( $order->get_taxes() as $tax_item ) {
			return array(
				'tax_total'    => (string) $tax_item->get_tax_total(),
				'rate_percent' => (string) $tax_item->get_rate_percent(),
				'label'        => (string) $tax_item->get_label(),
				'rate_code'    => (string) $tax_item->get_rate_code(),
			);
		}
		return array(
			'tax_total'    => '',
			'rate_percent' => '',
			'label'        => '',
			'rate_code'    => '',
		);
	}

	/**
	 * Count real orders, so a failed create leaving a row behind is visible.
	 *
	 * @return int
	 */
	private function count_orders(): int {
		$orders = wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'ids',
				'status' => array_keys( wc_get_order_statuses() ),
			)
		);
		return is_array( $orders ) ? count( $orders ) : 0;
	}

	/**
	 * Read an order's money straight back out of storage, as strings, for exact comparison.
	 *
	 * @param int $order_id Order id.
	 * @return array<string,string>
	 */
	private function money_state( int $order_id ): array {
		wp_cache_flush();
		$order = new \WC_Order( $order_id );

		$tax_rows = array();
		foreach ( $order->get_taxes() as $tax_item ) {
			$tax_rows[] = $tax_item->get_rate_id() . '=>' . $tax_item->get_tax_total();
		}
		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$items[] = $item_id . ':' . $item->get_total() . '/' . $item->get_total_tax();
		}

		return array(
			'total'     => (string) $order->get_total(),
			'total_tax' => (string) $order->get_total_tax(),
			'tax_rows'  => implode( '|', $tax_rows ),
			'items'     => implode( '|', $items ),
		);
	}

	/**
	 * WC1.3 per-object ownership: a caller who clears the manage_woocommerce floor but does
	 * not own the specific product and lacks the others-level capability must be refused,
	 * the same per-object pattern the post abilities already use for delete (see
	 * aafm_can_delete_post_object() / aafm_perm_delete_post() in includes/helpers.php and
	 * includes/abilities/posts.php). Needs a REAL WC_Product: WooCommerce registers 'product'
	 * with map_meta_cap => true and its own delete_product(s)/delete_others_products
	 * capability_type, which the unit suite's WcStubStore never backs with a real WP_Post, so
	 * this is not exercisable there.
	 */
	public function test_delete_product_denies_a_caller_who_does_not_own_it_and_lacks_the_others_capability(): void {
		if ( ! class_exists( '\WC_Product' ) ) {
			$this->markTestSkipped( 'WC_Product unavailable.' );
		}

		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $owner );
		$product = new \WC_Product();
		$product->set_name( 'AAFM Contract Ownership Widget' );
		$product->set_regular_price( '5.00' );
		$product->set_status( 'publish' );
		$product_id = $product->save();
		$this->assertGreaterThan( 0, $product_id );
		$this->assertSame( $owner, (int) get_post( $product_id )->post_author, 'Fixture sanity: the product is authored by $owner.' );

		// A caller who clears manage_woocommerce and can delete THEIR OWN products, but was
		// never granted the others-level capability - a scoped role an operator could actually
		// configure, distinct from the stock shop_manager/administrator roles that WooCommerce
		// grants the full capability set to on install.
		$scoped = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user   = new \WP_User( $scoped );
		$user->add_cap( 'manage_woocommerce' );
		$user->add_cap( 'edit_products' );
		$user->add_cap( 'delete_products' );
		$user->add_cap( 'delete_published_products' );
		wp_set_current_user( $scoped );

		$this->assertFalse(
			aafm_perm_wc_delete_product( array( 'product_id' => $product_id ) ),
			'A caller who does not own this product and lacks delete_others_products must be refused.'
		);

		// The owner, with the exact same scoped grant, is authorized for their own product.
		$owner_user = new \WP_User( $owner );
		$owner_user->add_cap( 'manage_woocommerce' );
		$owner_user->add_cap( 'edit_products' );
		$owner_user->add_cap( 'delete_products' );
		$owner_user->add_cap( 'delete_published_products' );
		wp_set_current_user( $owner );

		$this->assertTrue(
			aafm_perm_wc_delete_product( array( 'product_id' => $product_id ) ),
			'The owner must still be authorized for their own product.'
		);

		$product->delete( true );
	}

	/**
	 * WC_Product::set_sku() throws WC_Data_Exception on a duplicate SKU
	 * (abstract-wc-product.php: $this->error('product_invalid_sku', ...) when
	 * !wc_product_has_unique_sku()). Unguarded, that surfaces as an uncaught
	 * exception rather than a clean WP_Error. Needs a real WC_Product: the unit
	 * suite's stub set_sku() never throws.
	 */
	public function test_create_product_with_a_duplicate_sku_is_a_clean_error(): void {
		if ( ! class_exists( '\WC_Product' ) ) {
			$this->markTestSkipped( 'WC_Product unavailable.' );
		}

		$first = new \WC_Product();
		$first->set_name( 'AAFM Contract SKU Widget One' );
		$first->set_sku( 'AAFM-CONTRACT-DUPLICATE-SKU' );
		$first_id = $first->save();
		$this->assertGreaterThan( 0, $first_id );

		$result = aafm_exec_wc_create_product(
			array(
				'name' => 'AAFM Contract SKU Widget Two',
				'sku'  => 'AAFM-CONTRACT-DUPLICATE-SKU',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result, 'A duplicate SKU must be a clean error, not an uncaught exception.' );
		$this->assertStringContainsString( 'AAFM-CONTRACT-DUPLICATE-SKU', $result->get_error_message() );

		$first->delete( true );
	}

	/**
	 * A real WC_Coupon does NOT throw on a duplicate code. Verified directly against
	 * this WooCommerce version: WC_Coupon::set_code() only calls set_prop(), and
	 * wc_get_coupon_id_by_code() duplicate detection lives solely in
	 * WC_REST_Coupons_V1/V2_Controller (rest-api/Controllers/.../class-wc-rest-coupons-v*-controller.php),
	 * never in the WC_Coupon object our ability calls directly. Two coupon posts with
	 * the identical code both save without error today; a WC_Data_Exception guard
	 * around set_code() would be dead code; there is nothing to catch.
	 *
	 * The fix that actually closes the gap is the one the REST controller itself uses:
	 * check wc_get_coupon_id_by_code() before saving and refuse a real collision.
	 */
	public function test_create_coupon_with_a_duplicate_code_is_a_clean_error(): void {
		if ( ! class_exists( '\WC_Coupon' ) ) {
			$this->markTestSkipped( 'WC_Coupon unavailable.' );
		}

		$first = new \WC_Coupon();
		$first->set_code( 'AAFM-CONTRACT-DUPLICATE-CODE' );
		$first->set_discount_type( 'percent' );
		$first->set_amount( 10 );
		$first_id = $first->save();
		$this->assertGreaterThan( 0, $first_id );

		$result = aafm_exec_wc_create_coupon(
			array(
				'code'          => 'AAFM-CONTRACT-DUPLICATE-CODE',
				'discount_type' => 'percent',
				'amount'        => '15',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result, 'A duplicate coupon code must be a clean error.' );
		// wc_format_coupon_code() lowercases the code, matching how WC itself stores and
		// compares coupon codes, so the message carries the lowercased form.
		$this->assertStringContainsString( 'aafm-contract-duplicate-code', $result->get_error_message() );

		$first->delete( true );
	}

	/**
	 * The duplicate-code check must exclude the coupon's OWN row: re-saving a coupon
	 * with the code it already has is not a collision.
	 */
	public function test_update_coupon_keeping_its_own_code_is_not_a_false_collision(): void {
		if ( ! class_exists( '\WC_Coupon' ) ) {
			$this->markTestSkipped( 'WC_Coupon unavailable.' );
		}

		$coupon = new \WC_Coupon();
		$coupon->set_code( 'AAFM-CONTRACT-SELF-CODE' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$id = $coupon->save();
		$this->assertGreaterThan( 0, $id );

		$result = aafm_exec_wc_update_coupon(
			array(
				'coupon_id' => $id,
				'code'      => 'AAFM-CONTRACT-SELF-CODE',
				'amount'    => '12',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, "Keeping a coupon's own code must not be treated as a collision." );

		$coupon->delete( true );
	}
}
