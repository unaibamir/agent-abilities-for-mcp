<?php
/**
 * WooCommerce global product-attribute abilities (sub-slice W4-WC1c).
 *
 * The DDEV site ships no WooCommerce host plugin, so each test forces the integration active through
 * its per-slug filter and defines the minimal WooCommerce host surface via stub_woocommerce() (the
 * IntegrationStubs trait). Global attributes are stored in the WcAttributeStubStore (not WcStubStore)
 * and reached through wc_get_attribute_taxonomies() / wc_create_attribute() / wc_update_attribute() /
 * wc_delete_attribute().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcAttributeStubStore;
use WP_Error;

final class WooAttributesTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'woocommerce' );
		$this->stub_woocommerce(); // also resets + caps admin.
		$this->seed_wc_attributes(); // seeds id 1 = Color, id 2 = Size.
		aafm_registry_cache_should_flush( true );
		$this->register_wc_attributes();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}
	/**
	 * Enable + register the WooCommerce attribute ability set.
	 */
	private function register_wc_attributes(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-product-attributes',
				'aafm/wc-create-product-attribute',
				'aafm/wc-update-product-attribute',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}
	public function test_list_attributes_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-product-attributes' )->check_permissions( array() )
		);

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-product-attributes' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'attributes', $res );
		$this->assertArrayHasKey( 'total', $res );
		$this->assertSame( 2, $res['total'] );
	}

	public function test_list_attributes_returns_lean_rows(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-product-attributes' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertCount( 2, $res['attributes'] );

		$row = $res['attributes'][0];
		$this->assertArrayHasKey( 'id', $row );
		$this->assertArrayHasKey( 'name', $row );
		$this->assertArrayHasKey( 'slug', $row );
		$this->assertArrayHasKey( 'type', $row );
		$this->assertArrayHasKey( 'order_by', $row );
		$this->assertArrayHasKey( 'has_archives', $row );
	}

	public function test_list_attributes_includes_both_seeded_attributes(): void {
		$this->acting_as( 'administrator' );
		$res   = wp_get_ability( 'aafm/wc-list-product-attributes' )->execute( array() );
		$ids   = wp_list_pluck( $res['attributes'], 'id' );
		$names = wp_list_pluck( $res['attributes'], 'name' );
		sort( $ids );
		$this->assertSame( array( 1, 2 ), $ids );
		$this->assertContains( 'Color', $names );
		$this->assertContains( 'Size', $names );
	}

	public function test_list_attributes_denies_a_subscriber(): void {
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-product-attributes' )->check_permissions( array() )
		);
	}
	public function test_create_attribute_returns_rich_shape_and_is_recorded(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-attribute' )->execute(
			array(
				'name'         => 'Material',
				'slug'         => 'material',
				'type'         => 'select',
				'order_by'     => 'name',
				'has_archives' => true,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Material', $res['name'] );
		$this->assertSame( 'pa_material', $res['slug'] );
		$this->assertSame( 'select', $res['type'] );
		$this->assertSame( 'name', $res['order_by'] );
		$this->assertTrue( $res['has_archives'] );
		$this->assertArrayHasKey( 'id', $res );
		$this->assertGreaterThan( 0, $res['id'] );

		// Appears in the re-list.
		$list = wp_get_ability( 'aafm/wc-list-product-attributes' )->execute( array() );
		$ids  = wp_list_pluck( $list['attributes'], 'id' );
		$this->assertContains( $res['id'], $ids );
	}

	public function test_create_attribute_requires_name(): void {
		$this->acting_as( 'administrator' );
		// No 'name' key - schema requires it.
		$res = wp_get_ability( 'aafm/wc-create-product-attribute' )->execute( array( 'type' => 'select' ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'name is required on create.' );
	}

	public function test_create_attribute_closed_schema_rejects_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-attribute' )->execute(
			array(
				'name'       => 'Color',
				'evil_field' => 'injected',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema must reject an unknown top-level field.' );
	}

	public function test_create_attribute_denies_an_editor(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-create-product-attribute' )->check_permissions( array( 'name' => 'Color' ) )
		);
	}

	public function test_create_attribute_write_is_audited(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-attribute' )->execute( array( 'name' => 'Texture' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-product-attribute', $abilities );
	}

	public function test_create_and_update_share_the_output_schema(): void {
		$create = aafm_args_wc_create_product_attribute()['output_schema']['properties'];
		$update = aafm_args_wc_update_product_attribute()['output_schema']['properties'];

		$this->assertSame( $create, $update, 'create-attribute and update-attribute share the same rich output schema.' );
	}
	public function test_update_attribute_patches_by_id(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-attribute' )->execute(
			array(
				'attribute_id' => 1,
				'name'         => 'Colour', // British spelling.
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Colour', $res['name'] );

		// Untouched fields survive.
		$this->assertSame( 'select', $res['type'] );
		$this->assertSame( 1, $res['id'] );
	}

	public function test_update_attribute_field_isolation(): void {
		// Changing 'type' must leave 'name' intact.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-attribute' )->execute(
			array(
				'attribute_id' => 2,
				'type'         => 'text',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'text', $res['type'] );
		$this->assertSame( 'Size', $res['name'], 'name is untouched.' );
	}

	public function test_update_attribute_field_isolation_preserves_archives_and_order(): void {
		// M3: below WC 9.1, wc_update_attribute() resets any field the caller omits back to its
		// default, so a name-only PATCH silently wiped has_archives and order_by. The version-safe
		// fix always resends the CURRENT value for every untouched field, so this must hold
		// regardless of the WooCommerce version installed.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-attribute' )->execute(
			array(
				'attribute_id' => 1,
				'name'         => 'Colour', // British spelling.
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Colour', $res['name'] );
		$this->assertFalse( $res['has_archives'], 'has_archives is untouched (the M3 destructive field).' );
		$this->assertSame( 'menu_order', $res['order_by'], 'order_by is untouched (the M3 destructive field).' );
	}

	public function test_update_attribute_unknown_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-attribute' )->execute(
			array(
				'attribute_id' => 999999,
				'name'         => 'Ghost',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_update_attribute_empty_patch_is_a_noop(): void {
		// An update carrying only attribute_id (no other fields) is a valid no-op.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-attribute' )->execute( array( 'attribute_id' => 1 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'An empty PATCH is a no-op, not an error.' );
		$this->assertSame( 'Color', $res['name'], 'The seeded name survives an empty PATCH.' );
	}

	public function test_update_attribute_closed_schema_rejects_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-attribute' )->execute(
			array(
				'attribute_id' => 1,
				'evil_field'   => 'injected',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema must reject an unknown top-level field on update.' );
	}

	public function test_update_attribute_denies_an_editor(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-update-product-attribute' )->check_permissions( array( 'attribute_id' => 1 ) )
		);
	}

	public function test_update_attribute_write_is_audited(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-product-attribute' )->execute(
			array(
				'attribute_id' => 1,
				'name'         => 'Colors',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-update-product-attribute', $abilities );
	}
	public function test_wc_attribute_abilities_absent_when_host_inactive(): void {
		// Mirror WooProductsTest / WooVariationsTest: pin detection OFF through the seam so the
		// class WooCommerce marker (defined process-wide) does not falsely report WC active.
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );
		$this->assertArrayNotHasKey( 'aafm/wc-list-product-attributes', aafm_get_abilities_registry() );
		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}
	public function test_list_attributes_discovery_admin_yes_editor_no(): void {
		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/wc-list-product-attributes' ) );

		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/wc-list-product-attributes' ) );
	}

	public function test_update_attribute_surfaces_error_when_wc_update_fails(): void {
		$this->acting_as( 'administrator' );
		// Force the underlying store update to return false on the next call.
		WcAttributeStubStore::set_update_should_fail( true );

		$res = wp_get_ability( 'aafm/wc-update-product-attribute' )->execute(
			array(
				'attribute_id' => 1,
				'name'         => 'Will Fail',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A failed wc_update_attribute must surface as WP_Error.' );
	}

	/**
	 * Audit: a denied permission check is recorded, and the gate actually denies.
	 *
	 * @dataProvider provide_denied_audit_cases
	 *
	 * @param string               $ability  Ability name.
	 * @param array<string, mixed> $args     check_permissions args.
	 * @param string               $low_role Role that must be denied.
	 */
	public function test_denied_is_audited( string $ability, array $args, string $low_role ): void {
		$this->acting_as( $low_role );
		$this->assertNotTrue( wp_get_ability( $ability )->check_permissions( $args ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( $ability, $abilities );
	}

	/**
	 * Cases: each attribute write and the args its original denied audit test used.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public function provide_denied_audit_cases(): array {
		return array(
			'create-product-attribute' => array( 'aafm/wc-create-product-attribute', array( 'name' => 'Color' ), 'editor' ),
			'update-product-attribute' => array( 'aafm/wc-update-product-attribute', array( 'attribute_id' => 1 ), 'editor' ),
		);
	}

	public function test_create_attribute_surfaces_error_when_wc_create_fails(): void {
		$this->acting_as( 'administrator' );
		WcAttributeStubStore::set_create_should_fail( true );

		$res = wp_get_ability( 'aafm/wc-create-product-attribute' )->execute( array( 'name' => 'Doomed' ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'A failed wc_create_attribute must surface as WP_Error.' );
	}

	public function test_list_attributes_returns_empty_result_when_no_attributes_exist(): void {
		$this->acting_as( 'administrator' );
		// Clear the seeded attributes so the store is empty.
		WcAttributeStubStore::reset();

		$res = wp_get_ability( 'aafm/wc-list-product-attributes' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( array(), $res['attributes'] );
		$this->assertSame( 0, $res['total'] );
	}

	public function test_create_attribute_derives_slug_from_name_when_omitted(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-product-attribute' )->execute( array( 'name' => 'My Fabric' ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		// sanitize_title( 'My Fabric' ) yields 'my-fabric'; wc_attribute_taxonomy_name adds 'pa_' prefix.
		$this->assertSame( 'pa_my-fabric', $res['slug'] );
	}
}
