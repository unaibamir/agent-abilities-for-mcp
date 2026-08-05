<?php
/**
 * Wire-shape tests: what abilities actually serialize versus what their schemas declare.
 *
 * These assert on wp_json_encode() output rather than the PHP value, because
 * array() and (object) array() are indistinguishable in PHP but encode to
 * "[]" and "{}" respectively, and only the encoded form reaches the client.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcShippingStubStore;

final class OutputSchemaFidelityTest extends TestCase {

	use IntegrationStubs;

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	public function test_a_page_with_no_terms_encodes_terms_as_an_empty_object(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'A page has no taxonomies',
			)
		);

		$shape = aafm_rich_post( get_post( $page_id ) );

		$this->assertSame(
			'{}',
			wp_json_encode( $shape['terms'] ),
			'terms is declared type:object at helpers.php:1123, so an empty map must encode as {} not [].'
		);
	}

	public function test_a_post_with_no_allowlisted_meta_encodes_meta_as_an_empty_object(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$shape = aafm_rich_post( get_post( $post_id ) );

		$this->assertSame(
			'{}',
			wp_json_encode( $shape['meta'] ),
			'meta is declared type:object at helpers.php:1145, so an empty map must encode as {} not [].'
		);
	}

	public function test_a_post_with_terms_still_encodes_terms_as_a_populated_object(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$cat_id  = self::factory()->category->create( array( 'name' => 'Reporting' ) );
		wp_set_object_terms( $post_id, array( (int) $cat_id ), 'category' );

		$shape = aafm_rich_post( get_post( $post_id ) );
		$json  = (string) wp_json_encode( $shape['terms'] );

		$this->assertStringStartsWith( '{', $json, 'A populated terms map must still be an object.' );
		$this->assertStringContainsString( '"category"', $json );
		$this->assertSame( 'Reporting', $shape['terms']['category'][0]['name'], 'Array access must still work.' );
	}

	/**
	 * A zone shipping method that has never been configured has no per-instance option, so
	 * instance_settings comes back empty. settings is declared type:object (shipping.php:597);
	 * an empty PHP array encodes as [], not {}, and the adapter passes the return value verbatim
	 * into structuredContent - the same class of bug as the terms/meta cases above, one bucket
	 * deeper in WooCommerce's shipping method API.
	 */
	public function test_a_shipping_method_with_no_instance_settings_encodes_settings_as_an_empty_object(): void {
		$this->prepare_wc_shipping_stub();

		$method = $this->make_zone_shipping_method( 'free_shipping', array() );

		$row = aafm_rich_wc_shipping_method( $method );

		$this->assertSame(
			'{}',
			wp_json_encode( $row['settings'] ),
			'settings is declared type:object at shipping.php:597; an empty map must encode as {} not [].'
		);
	}

	/**
	 * $method->settings is WooCommerce's legacy global bucket. This project's stub constructor
	 * merges the per-instance option into that legacy bucket for round-tripping convenience,
	 * which papers over a wrong-bucket read for any key the instance option also sets - so this
	 * test seeds a key that exists ONLY in the legacy bucket (never written to the instance
	 * option) to prove the output comes from instance_settings alone. Reading the legacy bucket
	 * would let that stale key leak into a live agent's view of the method's real configuration.
	 */
	public function test_shipping_settings_reports_the_instance_configuration_not_the_legacy_bucket(): void {
		$this->prepare_wc_shipping_stub();

		WcShippingStubStore::seed_method(
			1,
			1,
			array(
				'id'           => 'flat_rate',
				'method_title' => 'Flat Rate',
				'enabled'      => 'yes',
				// The legacy bucket: a stale value under a key the instance option below never
				// sets. Only a read of the wrong bucket can make this key reach the wire.
				'settings'     => array(
					'title'         => 'STALE-LEGACY-TITLE',
					'legacy_marker' => 'must-not-leak',
				),
			)
		);
		update_option(
			'woocommerce_flat_rate_1_settings',
			array(
				'title' => 'Flat rate',
				'cost'  => '9.99',
			)
		);

		$zone    = new \WC_Shipping_Zone( 1 );
		$methods = $zone->get_shipping_methods();
		$method  = $methods[1];

		$row = aafm_rich_wc_shipping_method( $method );

		$this->assertSame( 'Flat rate', $row['settings']['title'], 'settings must expose the real instance title.' );
		$this->assertSame( '9.99', $row['settings']['cost'], 'settings must expose the real configured cost.' );
		$this->assertArrayNotHasKey(
			'legacy_marker',
			$row['settings'],
			'A key that exists ONLY in the legacy $settings bucket must not leak into the instance_settings-based output.'
		);
	}

	/**
	 * WooCommerce is never installed in this test environment - WC_Shipping_Zone,
	 * WC_Shipping_Method, and WC_Shipping_Zones come from the IntegrationStubs trait (see
	 * WooShippingTest's class docblock). Define them and reset the process-wide store so each
	 * test starts from a clean slate.
	 *
	 * @return void
	 */
	private function prepare_wc_shipping_stub(): void {
		$this->stub_woocommerce();
		$this->stub_wc_shipping();
		WcShippingStubStore::reset();
	}

	/**
	 * Build a zone shipping method whose configuration lives only in instance_settings,
	 * matching real WooCommerce. Returns the method object the redactor consumes.
	 *
	 * @param string               $method_id Shipping method id.
	 * @param array<string,string> $instance  Instance settings to store.
	 * @return \WC_Shipping_Method
	 */
	private function make_zone_shipping_method( string $method_id, array $instance ) {
		$zone = new \WC_Shipping_Zone( 0 );
		$zone->set_zone_name( 'Fidelity test zone' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( $method_id );

		update_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings', $instance );

		$methods = $zone->get_shipping_methods();
		return $methods[ $instance_id ];
	}
}
