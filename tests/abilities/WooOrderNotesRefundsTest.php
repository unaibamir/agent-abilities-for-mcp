<?php
/**
 * WooCommerce order delete, order notes, and order refunds abilities (W4-WC2.3).
 *
 * Covers:
 *   Group A — wc-delete-order (1 destructive)
 *   Group B — wc-list-order-notes / wc-get-order-note / wc-create-order-note / wc-delete-order-note
 *   Group C — wc-list-order-refunds / wc-get-order-refund / wc-create-order-refund / wc-delete-order-refund
 *
 * WooCommerce is not installed in the DDEV test environment — every WC host function and class is
 * provided by the IntegrationStubs trait backed by WcOrderStubStore and the per-test note/refund stores
 * added by this slice.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcOrderStubStore;
use WP_Error;

final class WooOrderNotesRefundsTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce();
		$this->seed_wc_orders();
		aafm_registry_cache_should_flush( true );
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		WcOrderStubStore::reset();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Push an Abilities API init action onto the filter stack for the duration of the callback.
	 *
	 * @param string   $action Action name to simulate.
	 * @param callable $cb     Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $cb ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$cb();
		array_pop( $wp_current_filter );
	}

	/**
	 * Enable + register the Group A (delete order) ability set.
	 */
	private function register_group_a(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-orders',
				'aafm/wc-get-order',
				'aafm/wc-delete-order',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * Enable + register the Group B (order notes) ability set.
	 */
	private function register_group_b(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-orders',
				'aafm/wc-get-order',
				'aafm/wc-list-order-notes',
				'aafm/wc-get-order-note',
				'aafm/wc-create-order-note',
				'aafm/wc-delete-order-note',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * Enable + register the Group C (order refunds) ability set.
	 */
	private function register_group_c(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-orders',
				'aafm/wc-get-order',
				'aafm/wc-list-order-refunds',
				'aafm/wc-get-order-refund',
				'aafm/wc-create-order-refund',
				'aafm/wc-delete-order-refund',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	// =========================================================================
	// GROUP A — aafm/wc-delete-order
	// =========================================================================

	/**
	 * Successful delete removes the order from the store and returns {id, deleted:true}.
	 */
	public function test_delete_order_removes_the_order(): void {
		$this->register_group_a();
		$this->acting_as( 'administrator' );

		$this->assertTrue( WcOrderStubStore::exists( 5001 ), 'Order 5001 must exist before delete.' );

		$res = wp_get_ability( 'aafm/wc-delete-order' )->execute( array( 'order_id' => 5001 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 5001, $res['id'] );
		$this->assertTrue( $res['deleted'], 'deleted must be true on success.' );
		$this->assertFalse( WcOrderStubStore::exists( 5001 ), 'Order must be gone from the store after delete.' );
	}

	/**
	 * Delete on a non-existent order must return a generic error, not lie success.
	 */
	public function test_delete_order_unknown_id_returns_error(): void {
		$this->register_group_a();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-delete-order' )->execute( array( 'order_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Surfaced delete-failure: when the stub signals a failed delete, we must not report deleted:true.
	 */
	public function test_delete_order_surfaces_failed_delete(): void {
		$this->register_group_a();
		$this->acting_as( 'administrator' );

		// Flag that the stub's delete() should return false.
		WcOrderStubStore::$delete_should_fail = true;

		$res = wp_get_ability( 'aafm/wc-delete-order' )->execute( array( 'order_id' => 5001 ) );

		WcOrderStubStore::$delete_should_fail = false;

		$this->assertInstanceOf( WP_Error::class, $res, 'A failed delete must not return deleted:true.' );
	}

	/**
	 * Editor (no manage_woocommerce) must be denied.
	 */
	public function test_delete_order_requires_manage_woocommerce(): void {
		$this->register_group_a();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-delete-order' )->check_permissions( array( 'order_id' => 5001 ) )
		);
	}

	public function test_delete_order_success_is_audited(): void {
		$this->register_group_a();
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-delete-order' )->execute( array( 'order_id' => 5001 ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-delete-order', $abilities );
	}

	public function test_delete_order_denied_is_audited(): void {
		$this->register_group_a();
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-delete-order' )->check_permissions( array( 'order_id' => 5001 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-delete-order', $abilities );
	}

	public function test_delete_order_is_destructive_annotated(): void {
		$this->register_group_a();
		$annotations = wp_get_ability( 'aafm/wc-delete-order' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['destructive'] ?? false, 'wc-delete-order must be annotated destructive.' );
	}

	public function test_delete_order_closed_schema_rejects_unknown_field(): void {
		$this->register_group_a();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-order' )->execute(
			array(
				'order_id'   => 5001,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Closed schema must reject unknown field.' );
	}

	// =========================================================================
	// GROUP B — order notes
	// =========================================================================

	// -------------------------------------------------------------------------
	// aafm/wc-list-order-notes
	// -------------------------------------------------------------------------

	public function test_list_order_notes_returns_lean_rows(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );

		// Seed some notes onto order 5001.
		WcOrderStubStore::seed_notes(
			5001,
			array(
				array(
					'id'              => 1,
					'note'            => 'Payment received.',
					'added_by_user'   => false,
					'date_created'    => '2024-06-01T10:01:00',
					'customer_note'   => false,
				),
				array(
					'id'              => 2,
					'note'            => 'Shipped via FedEx.',
					'added_by_user'   => true,
					'date_created'    => '2024-06-02T09:00:00',
					'customer_note'   => true,
				),
			)
		);

		$res = wp_get_ability( 'aafm/wc-list-order-notes' )->execute( array( 'order_id' => 5001 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'notes', $res );
		$this->assertCount( 2, $res['notes'] );

		$note = $res['notes'][0];
		$this->assertArrayHasKey( 'id', $note );
		$this->assertArrayHasKey( 'note', $note );
		$this->assertArrayHasKey( 'added_by_user', $note );
		$this->assertArrayHasKey( 'date_created', $note );
		$this->assertArrayHasKey( 'customer_note', $note );
	}

	public function test_list_order_notes_unknown_order_returns_error(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-list-order-notes' )->execute( array( 'order_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_list_order_notes_requires_manage_woocommerce(): void {
		$this->register_group_b();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-order-notes' )->check_permissions( array( 'order_id' => 5001 ) )
		);
	}

	public function test_list_order_notes_success_is_audited(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_notes( 5001, array() );
		wp_get_ability( 'aafm/wc-list-order-notes' )->execute( array( 'order_id' => 5001 ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-list-order-notes', $abilities );
	}

	public function test_list_order_notes_denied_is_audited(): void {
		$this->register_group_b();
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-list-order-notes' )->check_permissions( array( 'order_id' => 5001 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-list-order-notes', $abilities );
	}

	public function test_list_order_notes_closed_schema_rejects_unknown_field(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-order-notes' )->execute(
			array(
				'order_id'   => 5001,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// -------------------------------------------------------------------------
	// aafm/wc-get-order-note
	// -------------------------------------------------------------------------

	public function test_get_order_note_returns_single_note(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed_notes(
			5001,
			array(
				array(
					'id'            => 10,
					'note'          => 'Order confirmed by staff.',
					'added_by_user' => true,
					'date_created'  => '2024-06-01T11:00:00',
					'customer_note' => false,
				),
			)
		);

		$res = wp_get_ability( 'aafm/wc-get-order-note' )->execute(
			array(
				'order_id' => 5001,
				'note_id'  => 10,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 10, $res['id'] );
		$this->assertSame( 'Order confirmed by staff.', $res['note'] );
		$this->assertArrayHasKey( 'added_by_user', $res );
		$this->assertArrayHasKey( 'date_created', $res );
		$this->assertArrayHasKey( 'customer_note', $res );
	}

	public function test_get_order_note_unknown_note_returns_error(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_notes( 5001, array() );

		$res = wp_get_ability( 'aafm/wc-get-order-note' )->execute(
			array(
				'order_id' => 5001,
				'note_id'  => 9999,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_get_order_note_unknown_order_returns_error(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-get-order-note' )->execute(
			array(
				'order_id' => 999999,
				'note_id'  => 1,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_get_order_note_requires_manage_woocommerce(): void {
		$this->register_group_b();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-get-order-note' )->check_permissions(
				array(
					'order_id' => 5001,
					'note_id'  => 1,
				)
			)
		);
	}

	public function test_get_order_note_closed_schema_rejects_unknown_field(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order-note' )->execute(
			array(
				'order_id'   => 5001,
				'note_id'    => 1,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// -------------------------------------------------------------------------
	// aafm/wc-create-order-note
	// -------------------------------------------------------------------------

	public function test_create_order_note_persists_and_returns_shape(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_notes( 5001, array() );

		$res = wp_get_ability( 'aafm/wc-create-order-note' )->execute(
			array(
				'order_id'      => 5001,
				'note'          => 'This is a test note.',
				'customer_note' => true,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'id', $res );
		$this->assertArrayHasKey( 'note', $res );
		$this->assertArrayHasKey( 'customer_note', $res );
		$this->assertArrayHasKey( 'date_created', $res );
		$this->assertGreaterThan( 0, $res['id'], 'Created note must have a non-zero id.' );
		$this->assertSame( 'This is a test note.', $res['note'] );
	}

	public function test_create_order_note_unknown_order_returns_error(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-create-order-note' )->execute(
			array(
				'order_id' => 999999,
				'note'     => 'Should fail.',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_create_order_note_requires_manage_woocommerce(): void {
		$this->register_group_b();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-order-note' )->check_permissions(
				array(
					'order_id' => 5001,
					'note'     => 'Test.',
				)
			)
		);
	}

	public function test_create_order_note_success_is_audited(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_notes( 5001, array() );
		wp_get_ability( 'aafm/wc-create-order-note' )->execute(
			array(
				'order_id' => 5001,
				'note'     => 'Audit test note.',
			)
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-order-note', $abilities );
	}

	public function test_create_order_note_denied_is_audited(): void {
		$this->register_group_b();
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-create-order-note' )->check_permissions(
			array(
				'order_id' => 5001,
				'note'     => 'Test.',
			)
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-create-order-note', $abilities );
	}

	public function test_create_order_note_closed_schema_rejects_unknown_field(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-order-note' )->execute(
			array(
				'order_id'   => 5001,
				'note'       => 'Test.',
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// -------------------------------------------------------------------------
	// aafm/wc-delete-order-note
	// -------------------------------------------------------------------------

	public function test_delete_order_note_removes_the_note(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed_notes(
			5001,
			array(
				array(
					'id'            => 20,
					'note'          => 'Note to delete.',
					'added_by_user' => false,
					'date_created'  => '2024-06-01T12:00:00',
					'customer_note' => false,
				),
			)
		);

		$res = wp_get_ability( 'aafm/wc-delete-order-note' )->execute( array( 'note_id' => 20 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 20, $res['id'] );
		$this->assertTrue( $res['deleted'] );
	}

	public function test_delete_order_note_unknown_note_returns_error(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-delete-order-note' )->execute( array( 'note_id' => 99999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Surfaced delete-note failure: when the stub signals failure we must not report deleted:true.
	 */
	public function test_delete_order_note_surfaces_failed_delete(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed_notes(
			5001,
			array(
				array(
					'id'            => 21,
					'note'          => 'Note whose delete fails.',
					'added_by_user' => false,
					'date_created'  => '2024-06-01T12:00:00',
					'customer_note' => false,
				),
			)
		);
		WcOrderStubStore::$delete_note_should_fail = true;

		$res = wp_get_ability( 'aafm/wc-delete-order-note' )->execute( array( 'note_id' => 21 ) );

		WcOrderStubStore::$delete_note_should_fail = false;

		$this->assertInstanceOf( WP_Error::class, $res, 'A failed note delete must not report deleted:true.' );
	}

	public function test_delete_order_note_requires_manage_woocommerce(): void {
		$this->register_group_b();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-delete-order-note' )->check_permissions( array( 'note_id' => 20 ) )
		);
	}

	public function test_delete_order_note_success_is_audited(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed_notes(
			5001,
			array(
				array(
					'id'            => 30,
					'note'          => 'Audit note.',
					'added_by_user' => false,
					'date_created'  => '2024-06-01T12:00:00',
					'customer_note' => false,
				),
			)
		);
		wp_get_ability( 'aafm/wc-delete-order-note' )->execute( array( 'note_id' => 30 ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-delete-order-note', $abilities );
	}

	public function test_delete_order_note_denied_is_audited(): void {
		$this->register_group_b();
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-delete-order-note' )->check_permissions( array( 'note_id' => 20 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-delete-order-note', $abilities );
	}

	public function test_delete_order_note_is_destructive_annotated(): void {
		$this->register_group_b();
		$annotations = wp_get_ability( 'aafm/wc-delete-order-note' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['destructive'] ?? false, 'wc-delete-order-note must be annotated destructive.' );
	}

	public function test_delete_order_note_closed_schema_rejects_unknown_field(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-order-note' )->execute(
			array(
				'note_id'    => 1,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// GROUP C — order refunds
	// =========================================================================

	// -------------------------------------------------------------------------
	// aafm/wc-list-order-refunds
	// -------------------------------------------------------------------------

	public function test_list_order_refunds_returns_lean_rows(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed_refunds(
			5001,
			array(
				array(
					'id'           => 100,
					'amount'       => '9.99',
					'reason'       => 'Customer request',
					'date_created' => '2024-06-05T10:00:00',
				),
			)
		);

		$res = wp_get_ability( 'aafm/wc-list-order-refunds' )->execute( array( 'order_id' => 5001 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'refunds', $res );
		$this->assertCount( 1, $res['refunds'] );

		$row = $res['refunds'][0];
		$this->assertArrayHasKey( 'id', $row );
		$this->assertArrayHasKey( 'amount', $row );
		$this->assertArrayHasKey( 'reason', $row );
		$this->assertArrayHasKey( 'date_created', $row );
	}

	public function test_list_order_refunds_unknown_order_returns_error(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-list-order-refunds' )->execute( array( 'order_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_list_order_refunds_requires_manage_woocommerce(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-order-refunds' )->check_permissions( array( 'order_id' => 5001 ) )
		);
	}

	public function test_list_order_refunds_success_is_audited(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_refunds( 5001, array() );
		wp_get_ability( 'aafm/wc-list-order-refunds' )->execute( array( 'order_id' => 5001 ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-list-order-refunds', $abilities );
	}

	public function test_list_order_refunds_denied_is_audited(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-list-order-refunds' )->check_permissions( array( 'order_id' => 5001 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-list-order-refunds', $abilities );
	}

	public function test_list_order_refunds_closed_schema_rejects_unknown_field(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-order-refunds' )->execute(
			array(
				'order_id'   => 5001,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// -------------------------------------------------------------------------
	// aafm/wc-get-order-refund
	// -------------------------------------------------------------------------

	public function test_get_order_refund_returns_single_refund(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed_refunds(
			5001,
			array(
				array(
					'id'           => 200,
					'amount'       => '14.99',
					'reason'       => 'Damaged item',
					'date_created' => '2024-06-10T08:00:00',
				),
			)
		);

		$res = wp_get_ability( 'aafm/wc-get-order-refund' )->execute( array( 'refund_id' => 200 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 200, $res['id'] );
		$this->assertSame( '14.99', $res['amount'] );
		$this->assertSame( 'Damaged item', $res['reason'] );
		$this->assertArrayHasKey( 'date_created', $res );
	}

	public function test_get_order_refund_unknown_refund_returns_error(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-get-order-refund' )->execute( array( 'refund_id' => 99999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_get_order_refund_requires_manage_woocommerce(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-get-order-refund' )->check_permissions( array( 'refund_id' => 200 ) )
		);
	}

	public function test_get_order_refund_closed_schema_rejects_unknown_field(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-order-refund' )->execute(
			array(
				'refund_id'  => 200,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// -------------------------------------------------------------------------
	// aafm/wc-create-order-refund
	// -------------------------------------------------------------------------

	public function test_create_order_refund_persists_and_returns_shape(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_refunds( 5001, array() );

		$res = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id' => 5001,
				'amount'   => '5.00',
				'reason'   => 'Item returned.',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'id', $res );
		$this->assertArrayHasKey( 'amount', $res );
		$this->assertArrayHasKey( 'reason', $res );
		$this->assertArrayHasKey( 'date_created', $res );
		$this->assertGreaterThan( 0, $res['id'] );
		$this->assertSame( '5.00', $res['amount'] );
		$this->assertSame( 'Item returned.', $res['reason'] );
	}

	/**
	 * Refund reason is PII-adjacent and is returned as-is — must not be stripped.
	 * It is returned under the Integrations security disclaimer.
	 */
	public function test_create_order_refund_reason_is_returned_verbatim(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_refunds( 5001, array() );

		$reason = 'Customer requested refund: wrong color delivered.';
		$res    = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id' => 5001,
				'amount'   => '9.99',
				'reason'   => $reason,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $reason, $res['reason'], 'Refund reason must be returned verbatim (PII-adjacent, under disclaimer).' );
	}

	public function test_create_order_refund_unknown_order_returns_error(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id' => 999999,
				'amount'   => '5.00',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_create_order_refund_requires_manage_woocommerce(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-order-refund' )->check_permissions(
				array(
					'order_id' => 5001,
					'amount'   => '5.00',
				)
			)
		);
	}

	public function test_create_order_refund_success_is_audited(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_refunds( 5001, array() );
		wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id' => 5001,
				'amount'   => '1.00',
			)
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-order-refund', $abilities );
	}

	public function test_create_order_refund_denied_is_audited(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-create-order-refund' )->check_permissions(
			array(
				'order_id' => 5001,
				'amount'   => '5.00',
			)
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-create-order-refund', $abilities );
	}

	public function test_create_order_refund_closed_schema_rejects_unknown_field(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id'   => 5001,
				'amount'     => '5.00',
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * MED-4 nested-smuggle on line_items[]: a key inside a line_items item must be rejected.
	 *
	 * Each line_items[] item has additionalProperties:false, so smuggled keys inside the sub-schema
	 * must produce WP_Error before execute is ever called.
	 */
	public function test_create_order_refund_line_items_nested_smuggle_rejected(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id'   => 5001,
				'amount'     => '5.00',
				'line_items' => array(
					array(
						'line_item_id' => 1,
						'refund_total' => '5.00',
						'meta_data'    => 'injected', // Smuggled key in nested line_items item.
					),
				),
			)
		);

		$this->assertInstanceOf(
			WP_Error::class,
			$res,
			'line_items[].meta_data smuggle must be rejected — nested sub-schema is closed (MED-4).'
		);
	}

	// -------------------------------------------------------------------------
	// aafm/wc-delete-order-refund
	// -------------------------------------------------------------------------

	public function test_delete_order_refund_removes_the_refund(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed_refunds(
			5001,
			array(
				array(
					'id'           => 300,
					'amount'       => '4.99',
					'reason'       => 'Test refund to delete.',
					'date_created' => '2024-06-01T13:00:00',
				),
			)
		);

		$res = wp_get_ability( 'aafm/wc-delete-order-refund' )->execute( array( 'refund_id' => 300 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 300, $res['id'] );
		$this->assertTrue( $res['deleted'] );
	}

	public function test_delete_order_refund_unknown_refund_returns_error(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-delete-order-refund' )->execute( array( 'refund_id' => 99999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * Surfaced refund-delete failure: when the stub signals failure we must not report deleted:true.
	 */
	public function test_delete_order_refund_surfaces_failed_delete(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed_refunds(
			5001,
			array(
				array(
					'id'           => 301,
					'amount'       => '2.00',
					'reason'       => 'Will fail to delete.',
					'date_created' => '2024-06-01T13:00:00',
				),
			)
		);
		WcOrderStubStore::$delete_refund_should_fail = true;

		$res = wp_get_ability( 'aafm/wc-delete-order-refund' )->execute( array( 'refund_id' => 301 ) );

		WcOrderStubStore::$delete_refund_should_fail = false;

		$this->assertInstanceOf( WP_Error::class, $res, 'A failed refund delete must not report deleted:true.' );
	}

	public function test_delete_order_refund_requires_manage_woocommerce(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-delete-order-refund' )->check_permissions( array( 'refund_id' => 300 ) )
		);
	}

	public function test_delete_order_refund_success_is_audited(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed_refunds(
			5001,
			array(
				array(
					'id'           => 310,
					'amount'       => '3.00',
					'reason'       => 'Audit refund.',
					'date_created' => '2024-06-01T13:00:00',
				),
			)
		);
		wp_get_ability( 'aafm/wc-delete-order-refund' )->execute( array( 'refund_id' => 310 ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-delete-order-refund', $abilities );
	}

	public function test_delete_order_refund_denied_is_audited(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-delete-order-refund' )->check_permissions( array( 'refund_id' => 300 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-delete-order-refund', $abilities );
	}

	public function test_delete_order_refund_is_destructive_annotated(): void {
		$this->register_group_c();
		$annotations = wp_get_ability( 'aafm/wc-delete-order-refund' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['destructive'] ?? false, 'wc-delete-order-refund must be annotated destructive.' );
	}

	public function test_delete_order_refund_closed_schema_rejects_unknown_field(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-delete-order-refund' )->execute(
			array(
				'refund_id'  => 300,
				'evil_field' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// Host-inactive gate (registry-level proof, HIGH-2 pattern)
	// =========================================================================

	/**
	 * All W4-WC2.3 abilities must be absent from the registry when WooCommerce is not active.
	 */
	public function test_wc23_abilities_absent_when_host_inactive(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );

		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/wc-delete-order', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-list-order-notes', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-order-note', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-order-note', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-delete-order-note', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-list-order-refunds', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-order-refund', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-order-refund', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-delete-order-refund', $registry );

		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}
}
