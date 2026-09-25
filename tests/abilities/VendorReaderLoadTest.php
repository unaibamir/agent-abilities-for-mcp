<?php
/**
 * Vendor readers that load an object by id inside the vendor are preceded by an exact load.
 *
 * WooCommerce, GeoDirectory, The Events Calendar and Event Tickets each load the requested id's
 * own post or user row with core's loaders. When a `query` filter empties that SELECT, core reads
 * the previous query's row as the requested object. The plugin loads the same id with
 * aafm_exact_object() first, so the vendor reads the exact object from core's cache, and a load
 * that does not come back exact takes the reader's missing-object path. A WooCommerce reader is
 * preceded only while WooCommerce keeps that type in its own core data store.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use AAFM\Tests\WcCouponStubStore;
use AAFM\Tests\WcCustomerStubStore;
use AAFM\Tests\WcOrderStubStore;
use AAFM\Tests\WcStubStore;
use WP_Error;
use WP_Post;

final class VendorReaderLoadTest extends TestCase {

	use IntegrationStubs;

	/**
	 * For each faulted query, the functions on the stack when it fired.
	 *
	 * @var array<int,string[]>
	 */
	private array $stages = array();

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
		$this->stages = array();
	}

	public function tear_down(): void {
		if ( class_exists( 'WC_Data_Store', false ) && method_exists( 'WC_Data_Store', 'reset' ) ) {
			\WC_Data_Store::reset();
		}
		WcOrderStubStore::$throw_on_get = false;
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Load the data store stub and report the given store keys as WooCommerce's core stores.
	 *
	 * @param string[] $core Store keys ('product', 'order', 'coupon', 'customer').
	 */
	private function core_stores( array $core ): void {
		require_once dirname( __DIR__ ) . '/stubs/WcDataStoreStub.php';
		$names = array(
			'product'  => 'WC_Product_Data_Store_CPT',
			'order'    => 'WC_Order_Data_Store_CPT',
			'coupon'   => 'WC_Coupon_Data_Store_CPT',
			'customer' => 'WC_Customer_Data_Store',
		);
		\WC_Data_Store::reset();
		foreach ( $core as $key ) {
			\WC_Data_Store::$stores[ $key ] = $names[ $key ];
		}
	}

	/**
	 * A `query` filter that answers the next load of object $a with object $b's row and records
	 * the call stack it fired in. Object $a's cache entry is dropped first, so the load reaches
	 * the database.
	 *
	 * @param string $type 'post' or 'user'.
	 * @param int    $a    The object asked for.
	 * @param int    $b    The object whose row is left behind.
	 * @return callable
	 */
	private function fault_load( string $type, int $a, int $b ): callable {
		global $wpdb;
		if ( 'user' === $type ) {
			wp_cache_delete( $a, 'users' );
			$sql = "SELECT * FROM {$wpdb->users} WHERE ID = %s LIMIT 1";
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only a table name and a placeholder.
			$needle = $wpdb->prepare( $sql, (string) $a );
			$leak   = $wpdb->prepare( $sql, (string) $b );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		} else {
			wp_cache_delete( $a, 'posts' );
			$needle = sprintf( 'SELECT * FROM %1$s WHERE ID = %2$d LIMIT 1', $wpdb->posts, $a );
			$leak   = sprintf( 'SELECT * FROM %1$s WHERE ID = %2$d LIMIT 1', $wpdb->posts, $b );
		}
		$inner = QueryFaultInjector::leak_row_filter( $needle, $leak, 1, true );
		return function ( string $query ) use ( $inner ): string {
			$before = QueryFaultInjector::fired_count();
			$out    = $inner( $query );
			if ( QueryFaultInjector::fired_count() > $before ) {
				$this->stages[] = array_map(
					static function ( array $frame ): string {
						return (string) ( $frame['function'] ?? '' );
					},
					( new \Exception() )->getTrace()
				);
			}
			return $out;
		};
	}

	/**
	 * Run $run with $filter on `query`, database errors suppressed and output discarded.
	 *
	 * @param callable $filter The `query` filter.
	 * @param callable $run    The call.
	 * @return mixed
	 */
	private function armed( callable $filter, callable $run ) {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		ob_start();
		try {
			return $run();
		} finally {
			ob_end_clean();
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * The fault fired, and the first time it fired was inside aafm_exact_object().
	 *
	 * @param string $label Case label.
	 */
	private function assert_fired_in_exact_load( string $label ): void {
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count(), "$label: the load was faulted" );
		$this->assertContains( 'aafm_exact_object', $this->stages[0] ?? array(), "$label: the faulted SELECT fired inside aafm_exact_object" );
	}

	/**
	 * A published post.
	 *
	 * @param array<string,mixed> $args Post fields.
	 */
	private function post( array $args = array() ): int {
		return (int) self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
	}

	/**
	 * A WooCommerce product row in the stub store, keyed by a real post's id.
	 *
	 * @param string $type 'simple' or 'variation'.
	 * @return int The id.
	 */
	private function stub_product( string $type = 'simple' ): int {
		$id = $this->post( array( 'post_type' => 'product' ) );
		WcStubStore::seed(
			$id,
			array(
				'id'     => $id,
				'name'   => 'Product ' . $id,
				'type'   => $type,
				'status' => 'publish',
			)
		);
		return $id;
	}

	/**
	 * A WooCommerce order in the stub store, keyed by a real post's id.
	 *
	 * @return int The id.
	 */
	private function stub_order(): int {
		$id = $this->post( array( 'post_type' => 'shop_order' ) );
		WcOrderStubStore::seed( $id, array( 'status' => 'processing' ) );
		return $id;
	}

	/**
	 * The product reader refuses a product whose post load reads another row.
	 */
	public function test_product_reader_refuses_a_product_whose_post_loads_a_foreign_row(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'product' ) );
		$a = $this->stub_product();
		$b = $this->post();

		$this->assertNull( $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_wc_get_product( $a ) ) );
		$this->assert_fired_in_exact_load( 'product' );
		$this->assertSame( $a, aafm_wc_get_product( $a )->get_id(), 'unfaulted control' );
	}

	/**
	 * The variation reader refuses a variation whose post load reads another row.
	 */
	public function test_variation_reader_refuses_a_variation_whose_post_loads_a_foreign_row(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'product' ) );
		$a = $this->stub_product( 'variation' );
		$b = $this->post();

		$this->assertNull( $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_wc_get_variation( $a ) ) );
		$this->assert_fired_in_exact_load( 'variation' );
		$this->assertSame( $a, aafm_wc_get_variation( $a )->get_id(), 'unfaulted control' );
	}

	/**
	 * The order reader refuses an order whose post load reads another row.
	 */
	public function test_order_reader_refuses_an_order_whose_post_loads_a_foreign_row(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'order' ) );
		$a = $this->stub_order();
		$b = $this->post();

		$this->assertNull( $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_wc_get_order_object( $a ) ) );
		$this->assert_fired_in_exact_load( 'order' );
		$this->assertSame( $a, aafm_wc_get_order_object( $a )->get_id(), 'unfaulted control' );
	}

	/**
	 * The rollback's order loader refuses an order whose post load reads another row.
	 */
	public function test_rollback_order_loader_refuses_an_order_whose_post_loads_a_foreign_row(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'order' ) );
		$a = $this->stub_order();
		$b = $this->post();

		$this->assertNull( $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_wc_load_order_or_null( $a ) ) );
		$this->assert_fired_in_exact_load( 'rollback order' );
		$this->assertSame( $a, aafm_wc_load_order_or_null( $a )->get_id(), 'unfaulted control' );
	}

	/**
	 * The refund reader refuses a refund whose post load reads another row.
	 */
	public function test_refund_reader_refuses_a_refund_whose_post_loads_a_foreign_row(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'order' ) );
		$order  = $this->stub_order();
		$refund = $this->post( array( 'post_type' => 'shop_order_refund' ) );
		WcOrderStubStore::seed_refunds(
			$order,
			array(
				array(
					'id'     => $refund,
					'amount' => '5.00',
				),
			)
		);
		$b = $this->post();

		$this->assertNull( $this->armed( $this->fault_load( 'post', $refund, $b ), static fn() => aafm_wc_get_refund_object( $refund ) ) );
		$this->assert_fired_in_exact_load( 'refund' );
		$this->assertSame( $refund, aafm_wc_get_refund_object( $refund )->get_id(), 'unfaulted control' );
	}

	/**
	 * The coupon reader refuses a coupon whose post load reads another row.
	 */
	public function test_coupon_reader_refuses_a_coupon_whose_post_loads_a_foreign_row(): void {
		$this->stub_woocommerce();
		$this->stub_wc_coupons();
		$this->core_stores( array( 'coupon' ) );
		$b = $this->post();
		$a = $b + 50;
		WcCouponStubStore::seed(
			$a,
			array(
				'code'   => 'leakcheck',
				'amount' => '10',
			)
		);
		$this->assertInstanceOf( WP_Post::class, get_post( $a ), 'the coupon has its post' );

		$this->assertNull( $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_wc_get_coupon_object( $a ) ) );
		$this->assert_fired_in_exact_load( 'coupon' );
		$this->assertSame( $a, aafm_wc_get_coupon_object( $a )->get_id(), 'unfaulted control' );
	}

	/**
	 * The customer reader refuses a customer whose user load reads another user's row.
	 */
	public function test_customer_reader_refuses_a_customer_whose_user_loads_a_foreign_row(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'customer' ) );
		$a = (int) self::factory()->user->create( array( 'role' => 'customer' ) );
		$b = (int) self::factory()->user->create();
		WcCustomerStubStore::seed( $a, array( 'email' => 'a@example.com' ) );

		$this->assertNull( $this->armed( $this->fault_load( 'user', $a, $b ), static fn() => aafm_wc_get_customer_object( $a ) ) );
		$this->assert_fired_in_exact_load( 'customer' );
		$this->assertSame( $a, aafm_wc_get_customer_object( $a )->get_id(), 'unfaulted control' );
	}

	/**
	 * Id 0 is no customer: the user loader returns null for it without a query.
	 */
	public function test_customer_reader_returns_null_for_id_zero(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'customer' ) );

		$this->assertNull( aafm_wc_get_customer_object( 0 ) );
	}

	/**
	 * A create-order line item whose product post load reads another row is unresolved, the same
	 * refusal as an unknown product.
	 */
	public function test_create_order_leaves_a_line_item_unresolved_when_its_product_post_loads_a_foreign_row(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'product' ) );
		$a     = $this->stub_product();
		$b     = $this->post();
		$input = array(
			'line_items' => array(
				array(
					'product_id' => $a,
					'quantity'   => 1,
				),
			),
		);

		$out = $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_exec_wc_create_order( $input ) );
		$this->assert_fired_in_exact_load( 'line item product' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_unresolved_line_items', $out->get_error_code() );

		$this->assertIsArray( aafm_exec_wc_create_order( $input ), 'unfaulted control' );
	}

	/**
	 * The rollback check reports an order whose post load reads another row as still there, and
	 * an order whose post row is gone as gone.
	 */
	public function test_order_still_exists_is_true_under_a_faulted_load_and_false_once_the_post_is_gone(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'order' ) );
		$a = $this->stub_order();
		$b = $this->post();

		$this->assertTrue( $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_wc_order_still_exists( $a ) ) );
		$this->assert_fired_in_exact_load( 'still exists' );

		wp_delete_post( $a, true );
		$this->assertFalse( aafm_wc_order_still_exists( $a ), 'the post row is gone' );
	}

	/**
	 * GeoDirectory's fields for a listing whose post load reads another row are the empty row.
	 */
	public function test_geodirectory_fields_are_empty_when_the_listing_post_loads_a_foreign_row(): void {
		global $wpdb;
		aafm_geodir_stub_activate();
		$a = $this->post( array( 'post_type' => 'gd_place' ) );
		$b = $this->post();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test fixture row in the stub's own table.
			$wpdb->prefix . 'geodir_gd_place_detail',
			array(
				'post_id' => $a,
				'street'  => '1 Main St',
				'city'    => 'Springfield',
			)
		);
		$empty = aafm_geodirectory_shape_row( null );

		$this->assertSame( $empty, $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_geodirectory_read_fields( $a ) ) );
		$this->assert_fired_in_exact_load( 'geodirectory' );

		$control = aafm_geodirectory_read_fields( $a );
		$this->assertSame( '1 Main St', $control['street'], 'unfaulted control' );
		$this->assertNotSame( $empty, $control );
	}

	/**
	 * An event whose post load reads another row gets the empty dates, venue and organizers.
	 */
	public function test_event_shape_is_empty_when_the_event_post_loads_a_foreign_row(): void {
		$this->stub_tec();
		$a = $this->post( array( 'post_type' => 'tribe_events' ) );
		$b = $this->post();
		update_post_meta( $a, '_EventStartDate', '2026-10-01 09:00:00' );
		update_post_meta( $a, '_EventEndDate', '2026-10-01 17:00:00' );
		update_post_meta( $a, '_EventAllDay', 'yes' );
		update_post_meta( $a, '_EventVenueID', 77 );
		add_post_meta( $a, '_EventOrganizerID', 88 );

		$out = $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_tec_event_shape( $a ) );
		$this->assert_fired_in_exact_load( 'event' );
		$this->assertSame(
			array(
				'id'            => $a,
				'title'         => '',
				'status'        => '',
				'link'          => '',
				'start_date'    => '',
				'end_date'      => '',
				'all_day'       => false,
				'venue_id'      => 0,
				'organizer_ids' => array(),
			),
			$out
		);

		$control = aafm_tec_event_shape( $a );
		$this->assertSame(
			array( '2026-10-01 09:00:00', '2026-10-01 17:00:00', true, 77, array( 88 ) ),
			array( $control['start_date'], $control['end_date'], $control['all_day'], $control['venue_id'], $control['organizer_ids'] ),
			'unfaulted control'
		);
	}

	/**
	 * The activity log gives no order link when the order's post load reads another row, and
	 * never asks WooCommerce for the order (the stub factory throws if it is reached).
	 */
	public function test_activity_order_link_is_absent_when_the_order_post_loads_a_foreign_row(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'order' ) );
		$a = $this->stub_order();
		$b = $this->post();

		WcOrderStubStore::$throw_on_get = true;

		$out = $this->armed(
			$this->fault_load( 'post', $a, $b ),
			static fn() => aafm_activity_detail_link( 'aafm/wc-update-order-status', 'Set order #' . $a . ' to status `completed`' )
		);
		$this->assert_fired_in_exact_load( 'activity order link' );
		$this->assertNull( $out );
	}

	/**
	 * Event Tickets' ticket loader refuses a ticket whose post load reads another row.
	 */
	public function test_ticket_load_refuses_a_ticket_whose_post_loads_a_foreign_row(): void {
		$this->stub_tec();
		$event  = $this->post( array( 'post_type' => 'tribe_events' ) );
		$ticket = $this->stub_add_ticket( $event, 'GA', 25.0, 100 );
		$b      = $this->post();

		$this->assertNull( $this->armed( $this->fault_load( 'post', $ticket, $b ), static fn() => aafm_tec_load_ticket( $ticket ) ) );
		$this->assert_fired_in_exact_load( 'ticket' );
		$this->assertSame( $ticket, aafm_tec_load_ticket( $ticket )->ID, 'unfaulted control' );
	}

	/**
	 * A ticket post whose row is gone but whose cache entry stays loads exactly, so the ticket
	 * still loads.
	 */
	public function test_ticket_load_passes_a_cache_only_ticket_post(): void {
		global $wpdb;
		$this->stub_tec();
		$event  = $this->post( array( 'post_type' => 'tribe_events' ) );
		$ticket = $this->stub_add_ticket( $event );
		$this->assertInstanceOf( WP_Post::class, get_post( $ticket ) );
		$wpdb->delete( $wpdb->posts, array( 'ID' => $ticket ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removes the row and keeps the cache on purpose.

		$this->assertSame( $ticket, aafm_tec_load_ticket( $ticket )->ID );
	}

	/**
	 * With a store that is not WooCommerce's core store (the orders table under HPOS, say), no
	 * post is loaded first, and the vendor's answer is unchanged.
	 */
	public function test_readers_load_no_post_first_when_the_store_is_not_core(): void {
		$this->stub_woocommerce();
		$this->stub_wc_coupons();
		$this->core_stores( array() );
		$product = $this->stub_product();
		$b       = $this->post();

		$out = $this->armed( $this->fault_load( 'post', $product, $b ), static fn() => aafm_wc_get_product( $product ) );
		$this->assertSame( $product, null === $out ? null : $out->get_id(), 'product' );
		$this->assertSame( 0, QueryFaultInjector::fired_count(), 'product: no post load' );

		$coupon = $b + 50;
		WcCouponStubStore::seed( $coupon, array( 'code' => 'noncore' ) );
		$out = $this->armed( $this->fault_load( 'post', $coupon, $b ), static fn() => aafm_wc_get_coupon_object( $coupon ) );
		$this->assertSame( $coupon, null === $out ? null : $out->get_id(), 'coupon' );
		$this->assertSame( 0, QueryFaultInjector::fired_count(), 'coupon: no post load' );

		$customer = (int) self::factory()->user->create();
		$other    = (int) self::factory()->user->create();
		WcCustomerStubStore::seed( $customer, array( 'email' => 'c@example.com' ) );
		$out = $this->armed( $this->fault_load( 'user', $customer, $other ), static fn() => aafm_wc_get_customer_object( $customer ) );
		$this->assertSame( $customer, null === $out ? null : $out->get_id(), 'customer' );
		$this->assertSame( 0, QueryFaultInjector::fired_count(), 'customer: no user load' );
	}

	/**
	 * A product post whose row is gone but whose cache entry stays loads exactly, so the reader
	 * proceeds as before.
	 */
	public function test_product_reader_passes_a_cache_only_product_post(): void {
		global $wpdb;
		$this->stub_woocommerce();
		$this->core_stores( array( 'product' ) );
		$a = $this->stub_product();
		$this->assertInstanceOf( WP_Post::class, get_post( $a ) );
		$wpdb->delete( $wpdb->posts, array( 'ID' => $a ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removes the row and keeps the cache on purpose.

		$this->assertSame( $a, aafm_wc_get_product( $a )->get_id() );
	}

	/**
	 * Refund id 0 is no refund, with the core store on.
	 */
	public function test_refund_reader_returns_null_for_id_zero(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'order' ) );

		$this->assertNull( aafm_wc_get_refund_object( 0 ) );
	}

	/**
	 * A registry that throws for the store is not a core store.
	 */
	public function test_the_store_check_is_false_when_the_registry_throws(): void {
		$this->core_stores( array( 'product' ) );
		\WC_Data_Store::$throw = true;

		$this->assertFalse( aafm_wc_store_is_core( 'product' ) );
	}

	/**
	 * A store that extends the core store is not a core store, since it may read another table.
	 */
	public function test_the_store_check_is_false_for_a_subclass_of_the_core_store(): void {
		$this->core_stores( array() );
		\WC_Data_Store::$stores['product'] = 'AAFM_Test_Product_Data_Store_Subclass';

		$this->assertFalse( aafm_wc_store_is_core( 'product' ) );
	}

	/**
	 * A key outside the four the check knows is false, whatever the registry reports for it.
	 */
	public function test_the_store_check_is_false_for_an_unknown_key(): void {
		$this->core_stores( array() );
		\WC_Data_Store::$stores['shipping-zone'] = 'WC_Product_Data_Store_CPT';

		$this->assertFalse( aafm_wc_store_is_core( 'shipping-zone' ) );
	}

	/**
	 * Without WooCommerce's registry class the check is false and throws nothing. Runs in its own
	 * process so no other test has loaded the data store stub.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_store_check_is_false_without_woocommerce(): void {
		$this->assertFalse( class_exists( 'WC_Data_Store', false ) );

		$this->assertFalse( aafm_wc_store_is_core( 'product' ) );
	}
}
