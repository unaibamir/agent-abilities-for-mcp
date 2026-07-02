<?php
/**
 * WooCommerce order notes and order refunds abilities (W4-WC2.3).
 *
 * Covers:
 *   Group B - wc-list-order-notes / wc-create-order-note
 *   Group C - wc-list-order-refunds / wc-get-order-refund / wc-create-order-refund
 *
 * WooCommerce is not installed in the DDEV test environment - every WC host function and class is
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
				'aafm/wc-create-order-note',
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
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	// =========================================================================
	// GROUP B - order notes
	// =========================================================================

	// -------------------------------------------------------------------------
	// aafm/wc-list-order-notes
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


	// -------------------------------------------------------------------------
	// aafm/wc-create-order-note
	// -------------------------------------------------------------------------

	/**
	 * Creating a note persists it and returns an id plus the expected response shape.
	 */
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


	/**
	 * Surfaced add-failure: when add_order_note() returns 0, the executor must not report success.
	 */
	public function test_create_order_note_surfaces_failed_add(): void {
		$this->register_group_b();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_notes( 5001, array() );

		WcOrderStubStore::$add_note_should_fail = true;
		$res                                    = wp_get_ability( 'aafm/wc-create-order-note' )->execute(
			array(
				'order_id' => 5001,
				'note'     => 'This note should fail to add.',
			)
		);
		WcOrderStubStore::$add_note_should_fail = false;

		$this->assertInstanceOf( WP_Error::class, $res, 'A failed add_order_note() must not return {id:0,...} as success.' );
	}

	// =========================================================================
	// GROUP C - order refunds
	// =========================================================================

	// -------------------------------------------------------------------------
	// aafm/wc-list-order-refunds
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


	// -------------------------------------------------------------------------
	// aafm/wc-get-order-refund
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


	// -------------------------------------------------------------------------
	// aafm/wc-create-order-refund
	// -------------------------------------------------------------------------

	/**
	 * Creating a refund persists it and returns an id plus the expected response shape.
	 */
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
	 * Refund reason is PII-adjacent and is returned as-is - must not be stripped.
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

	/**
	 * The per-line refund_total/refund_tax are only `type: string` in the schema (no non-negative
	 * pattern), so a malformed amount can reach execute. A negative line amount must be rejected
	 * BEFORE wc_create_refund() is ever called.
	 */
	public function test_create_order_refund_rejects_negative_line_amount(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_refunds( 5001, array() );

		$res = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id'   => 5001,
				'amount'     => '5.00',
				'line_items' => array(
					array(
						'line_item_id' => 1,
						'refund_total' => '-5.00',
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res, 'a negative per-line refund_total must be rejected.' );
		$this->assertSame( array(), WcOrderStubStore::$last_refund_args, 'wc_create_refund() must not run on a bad refund amount.' );
	}

	/**
	 * A non-numeric per-line refund amount must likewise be rejected before wc_create_refund().
	 */
	public function test_create_order_refund_rejects_non_numeric_line_amount(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );
		WcOrderStubStore::seed_refunds( 5001, array() );

		$res = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id'   => 5001,
				'amount'     => '5.00',
				'line_items' => array(
					array(
						'line_item_id' => 1,
						'refund_total' => '0.00',
						'refund_tax'   => 'not-a-number',
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res, 'a non-numeric per-line refund_tax must be rejected.' );
		$this->assertSame( array(), WcOrderStubStore::$last_refund_args, 'wc_create_refund() must not run on a bad refund amount.' );
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
			'line_items[].meta_data smuggle must be rejected - nested sub-schema is closed (MED-4).'
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
			'list-order-notes'    => array( 'b', 'aafm/wc-list-order-notes', array( 'order_id' => 5001 ) ),
			'create-order-note'   => array(
				'b',
				'aafm/wc-create-order-note',
				array(
					'order_id' => 5001,
					'note'     => 'Test.',
				),
			),
			'list-order-refunds'  => array( 'c', 'aafm/wc-list-order-refunds', array( 'order_id' => 5001 ) ),
			'get-order-refund'    => array( 'c', 'aafm/wc-get-order-refund', array( 'refund_id' => 200 ) ),
			'create-order-refund' => array(
				'c',
				'aafm/wc-create-order-refund',
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
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );

		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/wc-list-order-notes', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-order-note', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-list-order-refunds', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-order-refund', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-order-refund', $registry );

		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}

	// =========================================================================
	// Additional coverage - empty lists, round-trips, id-fidelity
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

		$res = wp_get_ability( 'aafm/wc-list-order-notes' )->execute( array( 'order_id' => 5001 ) );

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

		$res = wp_get_ability( 'aafm/wc-list-order-refunds' )->execute( array( 'order_id' => 5001 ) );

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

		$created = wp_get_ability( 'aafm/wc-create-order-note' )->execute(
			array(
				'order_id' => 5001,
				'note'     => 'Round-trip note text.',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $created );
		$this->assertArrayHasKey( 'id', $created );

		$list = wp_get_ability( 'aafm/wc-list-order-notes' )->execute( array( 'order_id' => 5001 ) );

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

		$created = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id' => 5001,
				'amount'   => '7.50',
				'reason'   => 'Round-trip refund.',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $created );
		$this->assertGreaterThan( 0, $created['id'] );

		$list = wp_get_ability( 'aafm/wc-list-order-refunds' )->execute( array( 'order_id' => 5001 ) );

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

		$res = wp_get_ability( 'aafm/wc-get-order-refund' )->execute( array( 'refund_id' => 802 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 802, $res['id'] );
		$this->assertSame( '6.50', $res['amount'] );
		$this->assertSame( 'Second refund delta.', $res['reason'] );
	}

	// =========================================================================
	// Per-line refund_tax distribution across tax rates
	// =========================================================================

	/**
	 * Seed an order whose single line item carries the given tax map, and return the line item id.
	 *
	 * The tax map is keyed by rate id => tax amount string, mirroring what
	 * WC_Order_Item::get_taxes()['total'] returns in real WooCommerce.
	 *
	 * @param int               $order_id     Order id to seed.
	 * @param int               $line_item_id Line item id the refund will target.
	 * @param array<int,string> $rate_totals  Map of tax rate id => line tax amount string.
	 * @return void
	 */
	private function seed_order_with_taxed_line_item( int $order_id, int $line_item_id, array $rate_totals ): void {
		WcOrderStubStore::seed(
			$order_id,
			array(
				'status' => 'processing',
				'items'  => array(
					array(
						'id'         => $line_item_id,
						'name'       => 'Taxed Widget',
						'product_id' => 101,
						'quantity'   => 1,
						'subtotal'   => '40.00',
						'total'      => '40.00',
						'taxes'      => array( 'total' => $rate_totals ),
					),
				),
			)
		);
	}

	/**
	 * A line item taxed under several rates gets its refund_tax split proportionally to each rate's
	 * share of the line tax, with the rounding remainder assigned to the largest-share rate. The
	 * parts must sum back to exactly the requested refund_tax.
	 */
	public function test_create_order_refund_distributes_tax_proportionally_across_rates(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		// Line tax of 8.00 split 6.00 (rate 5) + 2.00 (rate 8); refund 4.00 of tax.
		$this->seed_order_with_taxed_line_item(
			6001,
			10,
			array(
				5 => '6.00',
				8 => '2.00',
			)
		);

		$res = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id'   => 6001,
				'amount'     => '40.00',
				'line_items' => array(
					array(
						'line_item_id' => 10,
						'refund_total' => '40.00',
						'refund_tax'   => '4.00',
					),
				),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );

		$line_items = WcOrderStubStore::$last_refund_args['line_items'] ?? array();
		$this->assertArrayHasKey( 10, $line_items, 'Refund must carry the targeted line item keyed by its id.' );
		$this->assertArrayHasKey( 'refund_tax', $line_items[10], 'A taxed line must emit a refund_tax map.' );

		$refund_tax = $line_items[10]['refund_tax'];
		// rate 8 is 2.00 / 8.00 = 25% of 4.00 = 1.00; rate 5 (largest share) absorbs the rest = 3.00.
		$this->assertSame( '1.00', $refund_tax[8], 'The 25%-share rate gets a proportional 1.00.' );
		$this->assertSame( '3.00', $refund_tax[5], 'The largest-share rate gets the remaining 3.00.' );
		// The distributed parts reconcile exactly to the requested refund_tax.
		$this->assertSame( 4.0, array_sum( array_map( 'floatval', $refund_tax ) ) );
	}

	/**
	 * A single-rate line gets the full requested refund_tax on that one rate.
	 */
	public function test_create_order_refund_single_rate_gets_full_amount(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		$this->seed_order_with_taxed_line_item( 6002, 20, array( 7 => '5.00' ) );

		$res = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id'   => 6002,
				'amount'     => '40.00',
				'line_items' => array(
					array(
						'line_item_id' => 20,
						'refund_total' => '40.00',
						'refund_tax'   => '2.50',
					),
				),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );

		$refund_tax = WcOrderStubStore::$last_refund_args['line_items'][20]['refund_tax'] ?? array();
		$this->assertSame( array( 7 => '2.50' ), $refund_tax, 'A single-rate line takes the whole requested refund_tax.' );
	}

	/**
	 * A line whose total tax is zero emits no refund_tax key (avoids dividing by zero / a bogus map).
	 */
	public function test_create_order_refund_zero_line_tax_emits_no_refund_tax(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		// A rate row exists but the line tax is 0.00 - there is nothing to distribute.
		$this->seed_order_with_taxed_line_item( 6003, 30, array( 9 => '0.00' ) );

		$res = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id'   => 6003,
				'amount'     => '40.00',
				'line_items' => array(
					array(
						'line_item_id' => 30,
						'refund_total' => '40.00',
						'refund_tax'   => '1.00',
					),
				),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );

		$line = WcOrderStubStore::$last_refund_args['line_items'][30] ?? array();
		$this->assertArrayHasKey( 'refund_total', $line );
		$this->assertArrayNotHasKey( 'refund_tax', $line, 'A zero-tax line must not emit a refund_tax map.' );
	}

	/**
	 * Regression: many equal rates with a small refund_tax must never produce a negative part.
	 *
	 * With six EQUAL rates and a 0.04 refund_tax, naive per-rate rounding rounds five shares up to
	 * 0.01 (sum 0.05) and leaves the balancing rate at round(0.04 - 0.05, 2) = -0.01 - a negative
	 * refund_tax WooCommerce can reject. The integer-unit largest-remainder allocation must keep
	 * every part >= 0 and reconcile the parts to exactly the requested refund_tax.
	 */
	public function test_create_order_refund_tax_distribution_never_negative_and_sums_exactly(): void {
		$this->register_group_c();
		$this->acting_as( 'administrator' );

		$this->seed_order_with_taxed_line_item(
			6004,
			40,
			array(
				11 => '1.00',
				12 => '1.00',
				13 => '1.00',
				14 => '1.00',
				15 => '1.00',
				16 => '1.00',
			)
		);

		$res = wp_get_ability( 'aafm/wc-create-order-refund' )->execute(
			array(
				'order_id'   => 6004,
				'amount'     => '40.00',
				'line_items' => array(
					array(
						'line_item_id' => 40,
						'refund_total' => '40.00',
						'refund_tax'   => '0.04',
					),
				),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );

		$refund_tax = WcOrderStubStore::$last_refund_args['line_items'][40]['refund_tax'] ?? array();
		$this->assertNotEmpty( $refund_tax, 'A taxed line must emit a refund_tax map.' );

		// (a) No allocated part is negative.
		foreach ( $refund_tax as $rate_id => $amount ) {
			$this->assertGreaterThanOrEqual( 0.0, (float) $amount, "rate {$rate_id} must not be negative." );
		}

		// (b) The parts reconcile to exactly the requested refund_tax.
		$this->assertSame( 0.04, round( array_sum( array_map( 'floatval', $refund_tax ) ), 2 ) );

		// (c) The map is keyed by the real tax rate ids supplied on the line.
		$this->assertSame(
			array( 11, 12, 13, 14, 15, 16 ),
			array_keys( $refund_tax ),
			'refund_tax must be keyed by the real tax rate ids.'
		);
	}
}
