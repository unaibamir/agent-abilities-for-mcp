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

	/**
	 * Report the given store keys as the given store class names, the way WooCommerce's registry
	 * reports them.
	 *
	 * @param array<string,string> $names Store key => class name.
	 */
	private function stores_named( array $names ): void {
		require_once dirname( __DIR__ ) . '/stubs/WcDataStoreStub.php';
		\WC_Data_Store::reset();
		foreach ( $names as $key => $name ) {
			\WC_Data_Store::$stores[ $key ] = $name;
		}
	}

	/**
	 * Every store key the store check knows, each named as WooCommerce's own core class.
	 *
	 * @return array<string,string>
	 */
	private function all_core_stores(): array {
		return array(
			'product'           => 'WC_Product_Data_Store_CPT',
			'product-variation' => 'WC_Product_Variation_Data_Store_CPT',
			'order'             => 'WC_Order_Data_Store_CPT',
			'order-refund'      => 'WC_Order_Refund_Data_Store_CPT',
			'coupon'            => 'WC_Coupon_Data_Store_CPT',
			'customer'          => 'WC_Customer_Data_Store',
		);
	}

	/**
	 * Wrap a `query` filter so each time it fires, the call stack it fired in is recorded.
	 *
	 * @param callable $inner The fault filter.
	 * @return callable
	 */
	private function recorded( callable $inner ): callable {
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
	 * A `query` filter that answers the $occurrence-th load of post $a with post $b's row.
	 * Post $a's cache entry is dropped first.
	 *
	 * @param int $a          The post asked for.
	 * @param int $b          The post whose row is left behind.
	 * @param int $occurrence Which load of $a to answer.
	 * @return callable
	 */
	private function fault_post_load_at( int $a, int $b, int $occurrence ): callable {
		global $wpdb;
		wp_cache_delete( $a, 'posts' );
		return $this->recorded(
			QueryFaultInjector::leak_row_filter(
				sprintf( 'SELECT * FROM %1$s WHERE ID = %2$d LIMIT 1', $wpdb->posts, $a ),
				sprintf( 'SELECT * FROM %1$s WHERE ID = %2$d LIMIT 1', $wpdb->posts, $b ),
				$occurrence,
				true
			)
		);
	}

	/**
	 * A `query` filter that answers every postmeta load of $post_id with no rows, core's own load
	 * and a checked-read scope's copy of it alike. The post's meta cache entry is dropped first.
	 *
	 * @param int $post_id Post whose metadata load is faulted.
	 * @return callable
	 */
	private function fault_meta_load( int $post_id ): callable {
		global $wpdb;
		wp_cache_delete( $post_id, 'post_meta' );
		return $this->recorded(
			QueryFaultInjector::leak_row_filter(
				array( 'SELECT post_id, meta_key, meta_value FROM', $wpdb->postmeta, "WHERE post_id IN ({$post_id})" ),
				sprintf( 'SELECT * FROM %s WHERE 1 = 0', $wpdb->postmeta ),
				0
			)
		);
	}

	/**
	 * A caller who holds manage_woocommerce and nothing that lets it delete another user's post.
	 *
	 * @return int The user id.
	 */
	private function store_manager_without_delete_rights(): int {
		$id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $id )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Create WooCommerce's HPOS orders table with the given order ids. The test suite turns the
	 * CREATE into a temporary table.
	 *
	 * @param int ...$ids Order ids to insert.
	 */
	private function hpos_orders_table( int ...$ids ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_orders';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL on an internal table name.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		$wpdb->query( "CREATE TABLE `{$table}` ( id BIGINT UNSIGNED NOT NULL, PRIMARY KEY ( id ) )" );
		foreach ( $ids as $id ) {
			$wpdb->insert( $table, array( 'id' => $id ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drop the HPOS orders table a test created.
	 */
	private function drop_hpos_orders_table(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wc_orders`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture teardown.
	}

	/**
	 * The product delete gate, under the core product store, refuses a product whose own post
	 * load reads another row, instead of keeping the capability floor.
	 */
	public function test_the_product_delete_gate_refuses_when_its_post_load_reads_another_row(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'product' ) );
		$a = $this->stub_product();
		$b = $this->post();
		$this->store_manager_without_delete_rights();

		$out = $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_perm_wc_delete_product( array( 'product_id' => $a ) ) );
		$this->assertFalse( $out );
		$this->assert_fired_in_exact_load( 'product gate' );
	}

	/**
	 * The variation delete gate refuses the same way when only the variation store is core.
	 */
	public function test_the_variation_delete_gate_refuses_under_the_core_variation_store_when_its_post_load_reads_another_row(): void {
		$this->stub_woocommerce();
		$this->stores_named( array( 'product-variation' => 'WC_Product_Variation_Data_Store_CPT' ) );
		$a = $this->stub_product( 'variation' );
		$b = $this->post();
		$this->store_manager_without_delete_rights();

		$out = $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_perm_wc_delete_product_variation( array( 'variation_id' => $a ) ) );
		$this->assertFalse( $out );
		$this->assert_fired_in_exact_load( 'variation gate, variation store' );
	}

	/**
	 * The variation delete gate refuses the same way when only the product store is core, since
	 * that store resolves a variation's type.
	 */
	public function test_the_variation_delete_gate_refuses_under_the_core_product_store_when_its_post_load_reads_another_row(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'product' ) );
		$a = $this->stub_product( 'variation' );
		$b = $this->post();
		$this->store_manager_without_delete_rights();

		$out = $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_perm_wc_delete_product_variation( array( 'variation_id' => $a ) ) );
		$this->assertFalse( $out );
		$this->assert_fired_in_exact_load( 'variation gate, product store' );
	}

	/**
	 * Under the core product store, id 0, a nonexistent id and the id of a post that is not a
	 * product keep the capability floor in both delete gates, and execute answers them with its
	 * generic error. A product post whose row is gone but whose cache entry stays still loads, so
	 * the capability check decides.
	 */
	public function test_the_delete_gates_keep_the_floor_where_no_post_load_failed(): void {
		global $wpdb;
		$this->stub_woocommerce();
		$this->core_stores( array( 'product' ) );
		$this->acting_as( 'administrator' );
		$page    = $this->post( array( 'post_type' => 'page' ) );
		$missing = $page + 1000;
		$generic = aafm_generic_error();

		foreach ( array( 0, $missing, $page ) as $id ) {
			$this->assertTrue( aafm_perm_wc_delete_product( array( 'product_id' => $id ) ), "product gate, id $id" );
			$this->assertTrue( aafm_perm_wc_delete_product_variation( array( 'variation_id' => $id ) ), "variation gate, id $id" );
		}
		foreach ( array( $missing, $page ) as $id ) {
			$out = aafm_exec_wc_delete_product( array( 'product_id' => $id ) );
			$this->assertInstanceOf( WP_Error::class, $out );
			$this->assertSame( array( $generic->get_error_code(), $generic->get_error_message(), $generic->get_error_data() ), array( $out->get_error_code(), $out->get_error_message(), $out->get_error_data() ), "product exec, id $id" );
			$out = aafm_exec_wc_delete_product_variation( array( 'variation_id' => $id ) );
			$this->assertInstanceOf( WP_Error::class, $out );
			$this->assertSame( array( $generic->get_error_code(), $generic->get_error_message(), $generic->get_error_data() ), array( $out->get_error_code(), $out->get_error_message(), $out->get_error_data() ), "variation exec, id $id" );
		}

		foreach ( array( 'simple', 'variation' ) as $type ) {
			$id = $this->post();
			WcStubStore::seed(
				$id,
				array(
					'id'     => $id,
					'name'   => 'Cached ' . $id,
					'type'   => $type,
					'status' => 'publish',
				)
			);
			$this->assertInstanceOf( WP_Post::class, get_post( $id ) );
			$wpdb->delete( $wpdb->posts, array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removes the row and keeps the cache on purpose.
			$this->assertTrue(
				'simple' === $type ? aafm_perm_wc_delete_product( array( 'product_id' => $id ) ) : aafm_perm_wc_delete_product_variation( array( 'variation_id' => $id ) ),
				"cache-only $type post: the capability check decides"
			);
		}
	}

	/**
	 * Delete-product under the core product store errors when the product's row is still there
	 * after the delete and the re-read of its post is faulted. Cache additions are suspended so the
	 * re-read reaches the database, as it does after wp_delete_post() clears the post's cache.
	 */
	public function test_delete_product_errors_when_the_row_survives_and_its_re_read_is_faulted(): void {
		global $wpdb;
		$this->stub_woocommerce();
		$this->core_stores( array( 'product' ) );
		$a = $this->stub_product();
		$b = $this->post();

		$suspended = wp_suspend_cache_addition();
		wp_suspend_cache_addition( true );
		try {
			$out = $this->armed( $this->fault_post_load_at( $a, $b, 2 ), static fn() => aafm_exec_wc_delete_product( array( 'product_id' => $a ) ) );
		} finally {
			wp_suspend_cache_addition( $suspended );
		}

		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'the re-read was faulted' );
		$this->assertContains( 'aafm_exact_object', $this->stages[0] ?? array() );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertSame( (string) $a, $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d', $wpdb->posts, $a ) ), 'the post row is still there' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checks the row itself.
	}

	/**
	 * Under WooCommerce's HPOS store the rollback check asks the orders table: a row it finds is
	 * still there, a row it does not find is gone, and a query that fails reads as still there.
	 */
	public function test_order_still_exists_asks_the_orders_table_under_hpos(): void {
		$this->stub_woocommerce();
		$this->stores_named( array( 'order' => 'Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' ) );
		$present = 987651;
		$absent  = 987652;
		$this->hpos_orders_table( $present );
		try {
			$this->assertTrue( aafm_wc_order_still_exists( $present ), 'row present' );
			$this->assertFalse( aafm_wc_order_still_exists( $absent ), 'row absent' );

			$out = QueryFaultInjector::break_query_with_real_error(
				array( 'SELECT id FROM', 'wc_orders', "WHERE id = {$present}" ),
				static fn() => aafm_wc_order_still_exists( $present )
			);
			$this->assertSame( 1, QueryFaultInjector::fired_count(), 'the orders-table query was broken' );
			$this->assertTrue( $out, 'a failed query reads as still there' );
		} finally {
			$this->drop_hpos_orders_table();
		}
	}

	/**
	 * The variation reader loads the variation's post first when only the variation store is
	 * WooCommerce's core store.
	 */
	public function test_variation_reader_precedes_when_only_the_variation_store_is_core(): void {
		$this->stub_woocommerce();
		$this->stores_named( array( 'product-variation' => 'WC_Product_Variation_Data_Store_CPT' ) );
		$a = $this->stub_product( 'variation' );
		$b = $this->post();

		$this->assertNull( $this->armed( $this->fault_load( 'post', $a, $b ), static fn() => aafm_wc_get_variation( $a ) ) );
		$this->assert_fired_in_exact_load( 'variation store' );
	}

	/**
	 * The refund reader loads the refund's post first when only the refund store is WooCommerce's
	 * core store.
	 */
	public function test_refund_reader_precedes_when_only_the_refund_store_is_core(): void {
		$this->stub_woocommerce();
		$this->stores_named( array( 'order-refund' => 'WC_Order_Refund_Data_Store_CPT' ) );
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
		$this->assert_fired_in_exact_load( 'refund store' );
	}

	/**
	 * The order-keyed sites, one per row.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function order_keyed_sites(): array {
		return array(
			'order reader'    => array( 'order' ),
			'rollback loader' => array( 'rollback' ),
			'still exists'    => array( 'still_exists' ),
			'refund reader'   => array( 'refund' ),
			'activity link'   => array( 'link' ),
		);
	}

	/**
	 * With only the product store core, an order-keyed site loads no post first, even for a
	 * post-backed order, and answers as it does with no core store at all.
	 *
	 * @dataProvider order_keyed_sites
	 *
	 * @param string $site Which site.
	 */
	public function test_order_keyed_sites_load_no_post_when_only_the_product_store_is_core( string $site ): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'product' ) );
		$order = $this->stub_order();
		$id    = $order;
		if ( 'refund' === $site ) {
			$id = $this->post( array( 'post_type' => 'shop_order_refund' ) );
			WcOrderStubStore::seed_refunds(
				$order,
				array(
					array(
						'id'     => $id,
						'amount' => '5.00',
					),
				)
			);
		}
		$b        = $this->post();
		$call     = static function () use ( $site, $id ) {
			switch ( $site ) {
				case 'order':
					$out = aafm_wc_get_order_object( $id );
					return null === $out ? null : $out->get_id();
				case 'rollback':
					$out = aafm_wc_load_order_or_null( $id );
					return null === $out ? null : $out->get_id();
				case 'still_exists':
					return aafm_wc_order_still_exists( $id );
				case 'refund':
					$out = aafm_wc_get_refund_object( $id );
					return null === $out ? null : $out->get_id();
			}
			return aafm_activity_detail_link( 'aafm/wc-update-order-status', 'Set order #' . $id . ' to status `completed`' );
		};
		$expected = $call();

		$this->assertSame( $expected, $this->armed( $this->fault_load( 'post', $id, $b ), $call ), $site );
		$this->assertSame( 0, QueryFaultInjector::fired_count(), "$site: no post load" );
		if ( 'link' !== $site ) {
			$this->assertNotNull( $expected, "$site: the stub order resolves" );
		}
	}

	/**
	 * The store check matches the registry's class name case-insensitively and with a leading
	 * backslash ignored, the two ways a filter-supplied name can differ from the class's own.
	 */
	public function test_the_store_check_ignores_case_and_a_leading_backslash(): void {
		$this->stores_named( array( 'product' => '\WC_Product_Data_Store_CPT' ) );
		$this->assertTrue( aafm_wc_store_is_core( 'product' ), 'leading backslash' );

		$this->stores_named( array( 'product' => 'wc_product_data_store_cpt' ) );
		$this->assertTrue( aafm_wc_store_is_core( 'product' ), 'lower case' );
	}

	/**
	 * A registry that throws an Error, not only an Exception, reads as a store that is not core,
	 * and the order helpers take their non-core path without letting it escape.
	 */
	public function test_a_registry_error_reads_as_a_store_that_is_not_core(): void {
		$this->stub_woocommerce();
		$this->core_stores( array( 'product', 'order' ) );
		$order = $this->stub_order();

		\WC_Data_Store::$throw_error = true;

		$this->assertFalse( aafm_wc_store_is_core( 'product' ) );
		$this->assertNull( aafm_wc_store_class( 'order' ) );
		$this->assertTrue( aafm_wc_order_still_exists( $order ) );
		$loaded = aafm_wc_load_order_or_null( $order );
		$this->assertSame( $order, null === $loaded ? null : $loaded->get_id() );
	}

	/**
	 * With every store core and real posts behind the stub ids, the product, order, coupon and
	 * customer read and write bodies equal the bodies the same calls give with no core store.
	 */
	public function test_core_store_bodies_equal_the_non_core_bodies(): void {
		$this->stub_woocommerce();
		$this->stub_wc_coupons();
		$product = $this->stub_product();
		$order   = $this->stub_order();
		$coupon  = $this->post() + 50;
		WcCouponStubStore::seed(
			$coupon,
			array(
				'code'   => 'pinned',
				'amount' => '10',
			)
		);
		$customer = (int) self::factory()->user->create( array( 'role' => 'customer' ) );
		WcCustomerStubStore::seed( $customer, array( 'email' => 'pin@example.com' ) );
		$runs = array(
			'get product'     => static fn() => aafm_exec_wc_get_product( array( 'product_id' => $product ) ),
			'update product'  => static fn() => aafm_exec_wc_update_product(
				array(
					'product_id' => $product,
					'name'       => 'Pinned name',
				)
			),
			'get order'       => static fn() => aafm_exec_wc_get_order( array( 'order_id' => $order ) ),
			'update coupon'   => static fn() => aafm_exec_wc_update_coupon(
				array(
					'coupon_id' => $coupon,
					'amount'    => '12',
				)
			),
			'update customer' => static fn() => aafm_exec_wc_update_customer(
				array(
					'customer_id' => $customer,
					'first_name'  => 'Pin',
				)
			),
		);

		foreach ( $runs as $label => $run ) {
			$this->core_stores( array() );
			$plain = $run();
			$this->assertIsArray( $plain, "$label: healthy" );
			$this->stores_named( $this->all_core_stores() );
			QueryFaultInjector::reset_fired_count();
			$this->assertSame( wp_json_encode( $plain ), wp_json_encode( $run() ), $label );
		}
	}

	/**
	 * With every store core, a product delete whose post row goes and a variation delete whose
	 * post row goes both answer as they do with no core store. The product's row is removed at its
	 * re-read, standing in for the wp_delete_post() inside WooCommerce's own delete; cache
	 * additions are suspended so that re-read reaches the database. The variation's row is removed
	 * beforehand with its cache entry kept, and the ability clears the cache itself.
	 */
	public function test_core_store_deletes_answer_as_the_non_core_deletes(): void {
		global $wpdb;
		$this->stub_woocommerce();
		foreach ( array( false, true ) as $core ) {
			$this->stores_named( $core ? $this->all_core_stores() : array() );

			$product = $this->stub_product();
			$seen    = 0;
			$remove  = static function ( string $query ) use ( $product, &$seen ): string {
				global $wpdb;
				if ( sprintf( 'SELECT * FROM %1$s WHERE ID = %2$d LIMIT 1', $wpdb->posts, $product ) === $query && 2 === ++$seen ) {
					$wpdb->delete( $wpdb->posts, array( 'ID' => $product ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- stands in for WooCommerce's own post delete.
				}
				return $query;
			};
			wp_cache_delete( $product, 'posts' );
			$suspended = wp_suspend_cache_addition();
			wp_suspend_cache_addition( true );
			add_filter( 'query', $remove );
			try {
				$out = aafm_exec_wc_delete_product( array( 'product_id' => $product ) );
			} finally {
				remove_filter( 'query', $remove );
				wp_suspend_cache_addition( $suspended );
			}
			$this->assertSame(
				array(
					'id'      => $product,
					'deleted' => true,
				),
				$out,
				$core ? 'product, core stores' : 'product, no core store'
			);

			$variation = $this->stub_product( 'variation' );
			$this->assertInstanceOf( WP_Post::class, get_post( $variation ) );
			$wpdb->delete( $wpdb->posts, array( 'ID' => $variation ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removes the row and keeps the cache on purpose.
			$this->assertSame(
				array(
					'id'      => $variation,
					'deleted' => true,
				),
				aafm_exec_wc_delete_product_variation( array( 'variation_id' => $variation ) ),
				$core ? 'variation, core stores' : 'variation, no core store'
			);
		}
	}

	/**
	 * The product delete gate reads the product through WooCommerce inside a checked-read scope.
	 * WooCommerce's read loads the product's post meta, and core decides whether a trashed product
	 * may be deleted from its `_wp_trash_meta_status` row, so a failed load must refuse rather
	 * than let an empty status skip delete_published_products.
	 *
	 * Runs in its own process so wc_get_product() can be defined with the meta read WooCommerce's
	 * data store makes (class-wc-product-data-store-cpt.php:449) before the stubs load.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_product_delete_gate_refuses_when_the_products_meta_load_fails(): void {
		$this->assert_delete_gate_refuses_when_the_vendor_meta_load_fails( 'product' );
	}

	/**
	 * The variation twin of the product gate test, on a site that registers variations with
	 * map_meta_cap (WooCommerce's woocommerce_register_post_type_product_variation filter), so core
	 * reads a trashed variation's status too (class-wc-product-variation-data-store-cpt.php:365).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_variation_delete_gate_refuses_when_the_variations_meta_load_fails(): void {
		$this->assert_delete_gate_refuses_when_the_vendor_meta_load_fails( 'variation' );
	}

	/**
	 * The shared body of the two delete gate meta-load tests.
	 *
	 * @param string $kind 'product' or 'variation'.
	 */
	private function assert_delete_gate_refuses_when_the_vendor_meta_load_fails( string $kind ): void {
		$this->assertFalse( function_exists( 'wc_get_product' ), 'a process with no stubs yet' );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a vendor function stub for tests; never shipped.
		eval( 'function wc_get_product( $id = false ) { $id = (int) $id; get_post_meta( $id ); if ( ! \AAFM\Tests\WcStubStore::exists( $id ) ) { return false; } $row = \AAFM\Tests\WcStubStore::get( $id ); return ( "variation" === ( $row["type"] ?? "" ) ) ? new \WC_Product_Variation( $id ) : new \WC_Product( $id ); }' );
		$this->stub_woocommerce();
		$this->core_stores( array( 'product' ) );
		$post_type = 'product' === $kind ? 'product' : 'product_variation';
		register_post_type(
			$post_type,
			array(
				'public'          => false,
				'capability_type' => 'product',
				'map_meta_cap'    => true,
			)
		);
		add_role(
			'aafm_test_own_product_deleter',
			'AAFM Test Own Product Deleter',
			array(
				'read'                   => true,
				'manage_woocommerce'     => true,
				'delete_products'        => true,
				'delete_others_products' => true,
			)
		);
		$caller = $this->acting_as( 'aafm_test_own_product_deleter' );
		$id     = (int) self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_author' => $caller,
			)
		);
		wp_trash_post( $id );
		$this->assertSame( 'publish', get_post_meta( $id, '_wp_trash_meta_status', true ), 'precondition: it was published' );
		WcStubStore::seed(
			$id,
			array(
				'id'     => $id,
				'name'   => 'Trashed ' . $kind,
				'type'   => 'product' === $kind ? 'simple' : 'variation',
				'status' => 'trash',
			)
		);
		$gate   = 'product' === $kind ? 'aafm_perm_wc_delete_product' : 'aafm_perm_wc_delete_product_variation';
		$input  = 'product' === $kind ? array( 'product_id' => $id ) : array( 'variation_id' => $id );
		$reader = 'product' === $kind ? 'aafm_wc_get_product' : 'aafm_wc_get_variation';

		$this->assertFalse( $gate( $input ), 'healthy: no delete_published_products' );

		$this->assertFalse( $this->armed( $this->fault_meta_load( $id ), static fn() => $gate( $input ) ) );
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count(), 'the meta load was faulted' );
		$this->assertContains( $reader, $this->stages[0] ?? array(), 'the first faulted load is the vendor read' );
		$this->assertContains( 'aafm_with_checked_reads', $this->stages[0] ?? array(), 'and it ran inside a checked-read scope' );

		get_user_by( 'id', $caller )->add_cap( 'delete_published_products' );
		wp_set_current_user( 0 );
		wp_set_current_user( $caller );
		$this->assertTrue( $gate( $input ), 'healthy: with delete_published_products' );
	}
}
