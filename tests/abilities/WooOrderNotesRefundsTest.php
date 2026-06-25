<?php
/**
 * WooCommerce order notes and order refunds abilities (W4-WC2.3).
 *
 * Covers:
 *   Group B — wc-list-order-notes / wc-create-order-note
 *   Group C — wc-list-order-refunds / wc-get-order-refund / wc-create-order-refund
 *
 * WooCommerce is not installed in the DDEV test environment — every WC host function and class is
 * provided by the IntegrationStubs trait backed by WcOrderStubStore and the per-test note/refund stores
 * added by this slice.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use Oversio\Tests\IntegrationStubs;
use Oversio\Tests\WcOrderStubStore;
use WP_Error;

final class WooOrderNotesRefundsTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
		oversio_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce();
		$this->seed_wc_orders();
		oversio_registry_cache_should_flush( true );
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
	 * Enable + register the Group B (order notes) ability set.
	 */
	private function register_group_b(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array(
				'oversio/wc-list-orders',
				'oversio/wc-get-order',
				'oversio/wc-list-order-notes',
				'oversio/wc-create-order-note',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	/**
	 * Enable + register the Group C (order refunds) ability set.
	 */
	private function register_group_c(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array(
				'oversio/wc-list-orders',
				'oversio/wc-get-order',
				'oversio/wc-list-order-refunds',
				'oversio/wc-get-order-refund',
				'oversio/wc-create-order-refund',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	// =========================================================================
	// GROUP B — order notes
	// =========================================================================

	// -------------------------------------------------------------------------
	// oversio/wc-list-order-notes
	// -------------------------------------------------------------------------

	/**
	 * The list returns lean note rows with the expected fields for an order.
	 */
	public function test_list_order_notes_returns_lean_rows(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );

		// Seed some notes onto order 5001.
		WcOrderStubStore::seed_notes(
			5001,
			array(
				array(
					'id'            => 1,
					'note'          => 'Payment received.',
					'added_by_user' => false,
					'date_created'  => '2024-06-01T10:01:00',
					'customer_note' => false,
				),
				array(
					'id'            => 2,
					'note'          => 'Shipped via FedEx.',
					'added_by_user' => true,
					'date_created'  => '2024-06-02T09:00:00',
					'customer_note' => true,
				),
			)
		);

		$res = wp_get_ability( 'oversio/wc-list-order-notes' )->execute( array( 'order_id' => 5001 ) );

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

		$res = wp_get_ability( 'oversio/wc-list-order-notes' )->execute( array( 'order_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_list_order_notes_requires_manage_woocommerce(): void {
		$this->register_group_b();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'oversio/wc-list-order-notes' )->check_permissions( array( 'order_id' => 5001 ) )
		);
	}

	public function test_list_order_notes_success_is_audited(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_notes( 5001, array() );
		wp_get_ability( 'oversio/wc-list-order-notes' )->execute( array( 'order_id' => 5001 ) );

		$success   = oversio_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'oversio/wc-list-order-notes', $abilities );
	}

	public function test_list_order_notes_denied_is_audited(): void {
		$this->register_group_b();
		$this->acting_as( 'editor' );
		wp_get_ability( 'oversio/wc-list-order-notes' )->check_permissions( array( 'order_id' => 5001 ) );

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/wc-list-order-notes', $abilities );
	}


	// -------------------------------------------------------------------------
	// oversio/wc-create-order-note
	// -------------------------------------------------------------------------

	/**
	 * Creating a note persists it and returns an id plus the expected response shape.
	 */
	public function test_create_order_note_persists_and_returns_shape(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_notes( 5001, array() );

		$res = wp_get_ability( 'oversio/wc-create-order-note' )->execute(
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

		$res = wp_get_ability( 'oversio/wc-create-order-note' )->execute(
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
			wp_get_ability( 'oversio/wc-create-order-note' )->check_permissions(
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
		wp_get_ability( 'oversio/wc-create-order-note' )->execute(
			array(
				'order_id' => 5001,
				'note'     => 'Audit test note.',
			)
		);

		$success   = oversio_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'oversio/wc-create-order-note', $abilities );
	}

	public function test_create_order_note_denied_is_audited(): void {
		$this->register_group_b();
		$this->acting_as( 'editor' );
		wp_get_ability( 'oversio/wc-create-order-note' )->check_permissions(
			array(
				'order_id' => 5001,
				'note'     => 'Test.',
			)
		);

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/wc-create-order-note', $abilities );
	}


	/**
	 * Surfaced add-failure: when add_order_note() returns 0, the executor must not report success.
	 */
	public function test_create_order_note_surfaces_failed_add(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_notes( 5001, array() );

		WcOrderStubStore::$add_note_should_fail = true;
		$res                                    = wp_get_ability( 'oversio/wc-create-order-note' )->execute(
			array(
				'order_id' => 5001,
				'note'     => 'This note should fail to add.',
			)
		);
		WcOrderStubStore::$add_note_should_fail = false;

		$this->assertInstanceOf( WP_Error::class, $res, 'A failed add_order_note() must not return {id:0,...} as success.' );
	}

	// =========================================================================
	// GROUP C — order refunds
	// =========================================================================

	// -------------------------------------------------------------------------
	// oversio/wc-list-order-refunds
	// -------------------------------------------------------------------------

	/**
	 * The list returns lean refund rows with the expected fields for an order.
	 */
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

		$res = wp_get_ability( 'oversio/wc-list-order-refunds' )->execute( array( 'order_id' => 5001 ) );

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

		$res = wp_get_ability( 'oversio/wc-list-order-refunds' )->execute( array( 'order_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_list_order_refunds_requires_manage_woocommerce(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'oversio/wc-list-order-refunds' )->check_permissions( array( 'order_id' => 5001 ) )
		);
	}

	public function test_list_order_refunds_success_is_audited(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_refunds( 5001, array() );
		wp_get_ability( 'oversio/wc-list-order-refunds' )->execute( array( 'order_id' => 5001 ) );

		$success   = oversio_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'oversio/wc-list-order-refunds', $abilities );
	}

	public function test_list_order_refunds_denied_is_audited(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		wp_get_ability( 'oversio/wc-list-order-refunds' )->check_permissions( array( 'order_id' => 5001 ) );

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/wc-list-order-refunds', $abilities );
	}


	// -------------------------------------------------------------------------
	// oversio/wc-get-order-refund
	// -------------------------------------------------------------------------

	/**
	 * Fetching a refund by id returns the correct refund with amount, reason, and date.
	 */
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

		$res = wp_get_ability( 'oversio/wc-get-order-refund' )->execute( array( 'refund_id' => 200 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 200, $res['id'] );
		$this->assertSame( '14.99', $res['amount'] );
		$this->assertSame( 'Damaged item', $res['reason'] );
		$this->assertArrayHasKey( 'date_created', $res );
	}

	public function test_get_order_refund_unknown_refund_returns_error(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'oversio/wc-get-order-refund' )->execute( array( 'refund_id' => 99999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_get_order_refund_requires_manage_woocommerce(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'oversio/wc-get-order-refund' )->check_permissions( array( 'refund_id' => 200 ) )
		);
	}


	// -------------------------------------------------------------------------
	// oversio/wc-create-order-refund
	// -------------------------------------------------------------------------

	/**
	 * Creating a refund persists it and returns an id plus the expected response shape.
	 */
	public function test_create_order_refund_persists_and_returns_shape(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_refunds( 5001, array() );

		$res = wp_get_ability( 'oversio/wc-create-order-refund' )->execute(
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
		$res    = wp_get_ability( 'oversio/wc-create-order-refund' )->execute(
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

		$res = wp_get_ability( 'oversio/wc-create-order-refund' )->execute(
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
			wp_get_ability( 'oversio/wc-create-order-refund' )->check_permissions(
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
		wp_get_ability( 'oversio/wc-create-order-refund' )->execute(
			array(
				'order_id' => 5001,
				'amount'   => '1.00',
			)
		);

		$success   = oversio_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'oversio/wc-create-order-refund', $abilities );
	}

	public function test_create_order_refund_denied_is_audited(): void {
		$this->register_group_c();
		$this->acting_as( 'editor' );
		wp_get_ability( 'oversio/wc-create-order-refund' )->check_permissions(
			array(
				'order_id' => 5001,
				'amount'   => '5.00',
			)
		);

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/wc-create-order-refund', $abilities );
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

		$res = wp_get_ability( 'oversio/wc-create-order-refund' )->execute(
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

	/**
	 * Closed schema: an unknown field injected on top of valid args is rejected by execute().
	 *
	 * Each case names the ability group it belongs to so the right abilities are
	 * registered before the call, matching what the per-ability tests did.
	 *
	 * @dataProvider provide_closed_schema_cases
	 *
	 * @param string               $group          Registration group: 'b' or 'c'.
	 * @param string               $ability        Ability name.
	 * @param array<string, mixed> $valid_min_args Minimal valid args for the ability.
	 */
	public function test_closed_schema_rejects_unknown_field( string $group, string $ability, array $valid_min_args ): void {
		switch ( $group ) {
			case 'b':
				$this->register_group_b();
				break;
			case 'c':
				$this->register_group_c();
				break;
		}

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( $ability )->execute(
			array_merge( $valid_min_args, array( 'evil_field' => 'x' ) )
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'Closed schema must reject an unknown field.' );
	}

	/**
	 * Cases: each ability, its registration group, and the minimal valid args its original test used.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public function provide_closed_schema_cases(): array {
		return array(
			'list-order-notes'    => array( 'b', 'oversio/wc-list-order-notes', array( 'order_id' => 5001 ) ),
			'create-order-note'   => array(
				'b',
				'oversio/wc-create-order-note',
				array(
					'order_id' => 5001,
					'note'     => 'Test.',
				),
			),
			'list-order-refunds'  => array( 'c', 'oversio/wc-list-order-refunds', array( 'order_id' => 5001 ) ),
			'get-order-refund'    => array( 'c', 'oversio/wc-get-order-refund', array( 'refund_id' => 200 ) ),
			'create-order-refund' => array(
				'c',
				'oversio/wc-create-order-refund',
				array(
					'order_id' => 5001,
					'amount'   => '5.00',
				),
			),
		);
	}

	// =========================================================================
	// Host-inactive gate (registry-level proof, HIGH-2 pattern)
	// =========================================================================

	/**
	 * All W4-WC2.3 abilities must be absent from the registry when WooCommerce is not active.
	 */
	public function test_wc23_abilities_absent_when_host_inactive(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'oversio_integration_active_woocommerce' );
		add_filter( 'oversio_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( oversio_integration_active( 'woocommerce' ) );
		oversio_registry_cache_should_flush( true );

		$registry = oversio_get_abilities_registry();
		$this->assertArrayNotHasKey( 'oversio/wc-list-order-notes', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-create-order-note', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-list-order-refunds', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-get-order-refund', $registry );
		$this->assertArrayNotHasKey( 'oversio/wc-create-order-refund', $registry );

		remove_filter( 'oversio_woocommerce_active', '__return_false', 99 );
	}

	// =========================================================================
	// Additional coverage — empty lists, round-trips, id-fidelity
	// =========================================================================

	// -------------------------------------------------------------------------
	// Empty-list invariant
	// -------------------------------------------------------------------------

	/**
	 * An order with no notes returns an empty array, not an empty object.
	 */
	public function test_list_order_notes_empty_order_returns_empty_array(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_notes( 5001, array() );

		$res = wp_get_ability( 'oversio/wc-list-order-notes' )->execute( array( 'order_id' => 5001 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'notes', $res );
		$this->assertIsArray( $res['notes'] );
		$this->assertCount( 0, $res['notes'] );
	}

	/**
	 * An order with no refunds returns an empty array, not an empty object.
	 */
	public function test_list_order_refunds_empty_order_returns_empty_array(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_refunds( 5001, array() );

		$res = wp_get_ability( 'oversio/wc-list-order-refunds' )->execute( array( 'order_id' => 5001 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'refunds', $res );
		$this->assertIsArray( $res['refunds'] );
		$this->assertCount( 0, $res['refunds'] );
	}

	// -------------------------------------------------------------------------
	// Round-trip: create then retrieve
	// -------------------------------------------------------------------------

	/**
	 * A note created via wc-create-order-note shows up in wc-list-order-notes.
	 */
	public function test_create_order_note_round_trip(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_notes( 5001, array() );

		$created = wp_get_ability( 'oversio/wc-create-order-note' )->execute(
			array(
				'order_id' => 5001,
				'note'     => 'Round-trip note text.',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $created );
		$this->assertArrayHasKey( 'id', $created );

		$list = wp_get_ability( 'oversio/wc-list-order-notes' )->execute( array( 'order_id' => 5001 ) );

		$this->assertNotInstanceOf( WP_Error::class, $list );
		$ids = array_column( $list['notes'], 'id' );
		$this->assertContains( $created['id'], $ids );
	}

	/**
	 * A refund created via wc-create-order-refund shows up in wc-list-order-refunds.
	 */
	public function test_create_order_refund_round_trip(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_refunds( 5001, array() );

		$created = wp_get_ability( 'oversio/wc-create-order-refund' )->execute(
			array(
				'order_id' => 5001,
				'amount'   => '7.50',
				'reason'   => 'Round-trip refund.',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $created );
		$this->assertGreaterThan( 0, $created['id'] );

		$list = wp_get_ability( 'oversio/wc-list-order-refunds' )->execute( array( 'order_id' => 5001 ) );

		$this->assertNotInstanceOf( WP_Error::class, $list );
		$ids = array_column( $list['refunds'], 'id' );
		$this->assertContains( $created['id'], $ids );
	}

	// -------------------------------------------------------------------------
	// Id-fidelity: second of two items is correctly distinguishable
	// -------------------------------------------------------------------------

	/**
	 * Fetching the second of two refunds by id returns that refund's amount and reason.
	 */
	public function test_get_order_refund_id_fidelity(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		WcOrderStubStore::seed_refunds(
			5001,
			array(
				array(
					'id'           => 801,
					'amount'       => '3.00',
					'reason'       => 'First refund gamma.',
					'date_created' => '2024-07-02T10:00:00',
				),
				array(
					'id'           => 802,
					'amount'       => '6.50',
					'reason'       => 'Second refund delta.',
					'date_created' => '2024-07-02T11:00:00',
				),
			)
		);

		$res = wp_get_ability( 'oversio/wc-get-order-refund' )->execute( array( 'refund_id' => 802 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 802, $res['id'] );
		$this->assertSame( '6.50', $res['amount'] );
		$this->assertSame( 'Second refund delta.', $res['reason'] );
	}
}
