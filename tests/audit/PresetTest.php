<?php
/**
 * Tests for the access presets engine. Registry driven, so a new ability is classified without a test edit.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Read only, Content editor, Shop manager and Custom as rules over the registry.
 */
final class PresetTest extends TestCase {

	public function tear_down(): void {
		delete_option( 'aafm_enabled_bridged_abilities' );
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			if ( 0 === strncmp( (string) $slug, 'preset-demo/', 12 ) ) {
				wp_unregister_ability( (string) $slug );
			}
		}
		parent::tear_down();
	}

	/**
	 * Register a foreign read (annotated readonly) and a foreign ability with no annotations.
	 *
	 * @return void
	 */
	private function register_foreign(): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'preset-demo-things' ) ) {
					wp_register_ability_category(
						'preset-demo-things',
						array(
							'label'       => 'Preset demo',
							'description' => 'Fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				$base = array(
					'category'            => 'preset-demo-things',
					'description'         => 'Fixture.',
					'execute_callback'    => '__return_empty_array',
					'permission_callback' => '__return_true',
				);
				wp_register_ability(
					'preset-demo/reader',
					$base + array(
						'label' => 'Reader',
						'meta'  => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
				wp_register_ability( 'preset-demo/silent', $base + array( 'label' => 'Silent' ) );
			}
		);
	}

	public function test_custom_permits_every_ability_the_allowlist_permits(): void {
		foreach ( array_keys( aafm_get_abilities_registry_full() ) as $name ) {
			$this->assertTrue( aafm_preset_permits( '', $name ), $name );
		}
		$this->assertTrue( aafm_preset_permits( '', 'aafm-bridge/anything' ) );
	}

	public function test_read_only_permits_exactly_the_reads(): void {
		foreach ( aafm_get_abilities_registry_full() as $name => $def ) {
			$this->assertSame( 'read' === $def['risk'], aafm_preset_permits( 'read_only', $name ), $name );
		}
		$this->register_foreign();
		$this->assertTrue( aafm_preset_permits( 'read_only', 'preset-demo/reader' ) );
		$this->assertFalse( aafm_preset_permits( 'read_only', 'preset-demo/silent' ) );
	}

	public function test_content_editor_membership(): void {
		foreach ( array( 'aafm/create-post', 'aafm/update-page', 'aafm/upload-media', 'aafm/create-term', 'aafm/get-posts', 'aafm/update-post-meta', 'aafm/get-post-types', 'aafm/get-taxonomies' ) as $name ) {
			$this->assertTrue( aafm_preset_permits( 'content_editor', $name ), $name );
		}
		foreach ( array( 'aafm/delete-post', 'aafm/trash-post', 'aafm/delete-media', 'aafm/delete-term-meta', 'aafm/create-user', 'aafm/update-user', 'aafm/moderate-comment', 'aafm/create-comment', 'aafm/update-site-settings', 'aafm/replace-sitewide', 'aafm/get-site-settings', 'aafm/get-site-info', 'aafm/wc-list-products', 'aafm-bridge/demo-writer', 'aafm-bridge/demo-reader' ) as $name ) {
			$this->assertFalse( aafm_preset_permits( 'content_editor', $name ), $name );
		}
	}

	public function test_shop_manager_membership(): void {
		foreach ( array( 'aafm/wc-update-product', 'aafm/wc-create-product', 'aafm/wc-list-products' ) as $name ) {
			$this->assertTrue( aafm_preset_permits( 'shop_manager', $name ), $name );
		}
		foreach ( array_keys( aafm_get_abilities_registry_full() ) as $name ) {
			if ( aafm_preset_permits( 'content_editor', $name ) ) {
				$this->assertTrue( aafm_preset_permits( 'shop_manager', $name ), "Shop manager covers Content editor: $name" );
			}
		}
		foreach ( array_merge( aafm_high_risk_abilities_builtin(), array( 'aafm/wc-delete-product', 'aafm/wc-delete-product-variation', 'aafm/update-user' ) ) as $name ) {
			$this->assertFalse( aafm_preset_permits( 'shop_manager', $name ), $name );
		}
		foreach ( aafm_get_abilities_registry_full() as $name => $def ) {
			if ( 'woocommerce' === ( $def['subject'] ?? '' ) && 'destructive' === $def['risk'] ) {
				$this->assertFalse( aafm_preset_permits( 'shop_manager', $name ), $name );
			}
		}
	}

	public function test_an_unknown_slug_permits_nothing(): void {
		$this->assertFalse( aafm_preset_permits( 'bogus', 'aafm/get-posts' ) );
	}

	public function test_normalize_maps_custom_and_garbage(): void {
		$this->assertSame( '', aafm_preset_normalize( 'custom', 'x' ) );
		$this->assertSame( '', aafm_preset_normalize( '', 'x' ) );
		$this->assertSame( 'read_only', aafm_preset_normalize( 'read_only', 'x' ) );
		foreach ( array( array(), new \stdClass(), 'READ_ONLY', "read_only\0" ) as $bad ) {
			$this->assertSame( 'x', aafm_preset_normalize( $bad, 'x' ) );
		}
	}

	public function test_rank_and_clamp(): void {
		$this->assertSame( array( 1, 2, 3, 4, 4, 0 ), array_map( 'aafm_preset_rank', array( 'read_only', 'content_editor', 'shop_manager', '', 'custom', 'bogus' ) ) );

		foreach ( array( '', 'read_only', 'content_editor', 'shop_manager' ) as $preset ) {
			$this->assertSame( $preset, aafm_preset_clamp( $preset, false ), 'With the default at Custom every preset passes.' );
		}

		update_option( 'aafm_oauth_default_preset', 'content_editor' );
		aafm_policy_reset_request_state();
		$this->assertSame( 'content_editor', aafm_preset_clamp( '', false ) );
		$this->assertSame( 'content_editor', aafm_preset_clamp( 'shop_manager', false ) );
		$this->assertSame( 'read_only', aafm_preset_clamp( 'read_only', false ) );
		$this->assertSame( '', aafm_preset_clamp( '', true ) );
		$this->assertSame( 'shop_manager', aafm_preset_clamp( 'shop_manager', true ) );
	}

	public function test_default_preset_reads_through_the_policy_row_and_fails_narrow(): void {
		$this->assertSame( '', aafm_default_preset() );

		update_option( 'aafm_oauth_default_preset', 'read_only' );
		aafm_policy_reset_request_state();
		$this->assertSame( 'read_only', aafm_default_preset() );

		// A stale cache copy over a different row: the row decides.
		update_option( 'aafm_oauth_default_preset', 'shop_manager' );
		$all                              = wp_load_alloptions( true );
		$all['aafm_oauth_default_preset'] = 'custom';
		wp_cache_set( 'alloptions', $all, 'options' );
		wp_cache_set( 'aafm_oauth_default_preset', 'custom', 'options' );
		aafm_policy_reset_request_state();
		$this->assertSame( 'shop_manager', aafm_default_preset() );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'aafm_oauth_default_preset', 'options' );

		aafm_policy_reset_request_state();
		$this->assertSame( 'read_only', QueryFaultInjector::fail_query( 'aafm_oauth_default_preset', static fn() => aafm_default_preset() ) );

		foreach ( array( array( 'shop_manager' ), 'bogus' ) as $bad ) {
			update_option( 'aafm_oauth_default_preset', $bad );
			aafm_policy_reset_request_state();
			$this->assertSame( 'read_only', aafm_default_preset() );
		}
	}

	public function test_count_includes_enabled_bridged_reads(): void {
		$this->register_foreign();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/create-post', 'aafm/get-site-info' ) );
		update_option( 'aafm_enabled_bridged_abilities', array( 'preset-demo/reader' ) );

		$this->assertSame(
			array(
				'allowed' => 3,
				'enabled' => 4,
			),
			aafm_preset_count( 'read_only' )
		);
		$this->assertSame(
			array(
				'allowed' => 2, // get-posts and create-post; get-site-info and the bridged read are outside it.
				'enabled' => 4,
			),
			aafm_preset_count( 'content_editor' )
		);
		$this->assertSame( aafm_preset_count( 'content_editor' ), aafm_preset_count( 'shop_manager' ), 'No WooCommerce tool enabled: Shop manager reaches the Content editor subset.' );
		$this->assertSame( 4, aafm_preset_count( '' )['allowed'] );
	}
}
