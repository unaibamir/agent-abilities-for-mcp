<?php
/**
 * Bridge directory save: nonce + capability gated, allowlisted against discoverable slugs.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class BridgeDirectorySaveTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->register_foreign();
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_POST['bridged_abilities'], $_REQUEST['nonce'] );
		delete_option( 'aafm_enabled_bridged_abilities' );
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'demo/', 5 ) ) {
				wp_unregister_ability( $slug );
			}
		}
		parent::tear_down();
	}

	/**
	 * Register a demo category + a discoverable foreign ability.
	 *
	 * @return void
	 */
	private function register_foreign(): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'demo-things' ) ) {
					wp_register_ability_category(
						'demo-things',
						array(
							'label'       => 'Demo things',
							'description' => 'Demo fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				wp_register_ability(
					'demo/echo',
					array(
						'label'               => 'Echo',
						'description'         => 'e',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'execute_callback'    => static fn() => array(),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * Route wp_send_json through a throwing wp_die so the handler is observable in-process.
	 *
	 * @return void
	 */
	private function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}

	/**
	 * Run an AJAX handler and return its captured JSON payload.
	 *
	 * @param callable $handler The AJAX callback to invoke.
	 * @return array<string,mixed>
	 */
	private function run_handler( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	public function test_save_persists_only_allowlisted_slugs(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->intercept_die();
		$nonce                      = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']             = $nonce;
		$_REQUEST['nonce']          = $nonce;
		$_POST['bridged_abilities'] = array( 'demo/echo', 'evil/not-real' );

		$json = $this->run_handler( 'aafm_ajax_save_bridged_abilities' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$saved = get_option( 'aafm_enabled_bridged_abilities' );
		$this->assertContains( 'demo/echo', $saved );
		$this->assertNotContains( 'evil/not-real', $saved ); // Allowlist rejects unknown.
	}

	public function test_save_preserves_enabled_but_unavailable_slugs(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// An orphan slug: enabled earlier, but its host plugin is now inactive so it is not
		// among the currently-discoverable abilities and its (disabled) checkbox is not posted.
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/echo', 'ghost/gone' ) );
		$this->intercept_die();
		$nonce                      = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']             = $nonce;
		$_REQUEST['nonce']          = $nonce;
		$_POST['bridged_abilities'] = array( 'demo/echo', 'evil/not-real' );

		$json = $this->run_handler( 'aafm_ajax_save_bridged_abilities' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$saved = get_option( 'aafm_enabled_bridged_abilities' );
		$this->assertContains( 'demo/echo', $saved );
		$this->assertContains( 'ghost/gone', $saved, 'An enabled-but-unavailable slug must survive an unrelated save.' );
		$this->assertNotContains( 'evil/not-real', $saved, 'An unknown submitted slug is still rejected.' );
	}

	public function test_save_requires_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->intercept_die();
		$nonce                      = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']             = $nonce;
		$_REQUEST['nonce']          = $nonce;
		$_POST['bridged_abilities'] = array( 'demo/echo' );

		$json = $this->run_handler( 'aafm_ajax_save_bridged_abilities' );

		$this->assertFalse( (bool) ( $json['success'] ?? false ) );
		$this->assertFalse( get_option( 'aafm_enabled_bridged_abilities', false ) );
	}

	public function test_group_label_is_title_cased(): void {
		$this->assertSame( 'Events Manager', aafm_bridge_display_label( 'events-manager' ) );
		$this->assertSame( 'Woocommerce', aafm_bridge_display_label( 'woocommerce' ) );
		$this->assertSame( 'Core', aafm_bridge_display_label( 'core' ) );
		$this->assertSame( 'My Cool Plugin', aafm_bridge_display_label( 'my_cool-plugin' ) );
	}

	public function test_directory_renders_title_case_header_and_bulk_controls(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_render_bridge_directory();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h2>Demo</h2>', $html );          // Title-cased namespace.
		$this->assertStringContainsString( 'data-bridge-bulk="enable"', $html );
		$this->assertStringContainsString( 'data-bridge-bulk="disable"', $html );
	}
}
