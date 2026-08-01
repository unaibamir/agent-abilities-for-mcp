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
		// An unrecognized namespace still falls back to the generic Title Case transform.
		$this->assertSame( 'Events Manager', aafm_bridge_display_label( 'events-manager' ) );
		$this->assertSame( 'Core', aafm_bridge_display_label( 'core' ) );
		$this->assertSame( 'My Cool Plugin', aafm_bridge_display_label( 'my_cool-plugin' ) );
	}

	/**
	 * Regression guard: a bridged namespace matching a real, known plugin must render that
	 * plugin's real display name, not a raw ucwords() of its slug ("Woocommerce" instead of
	 * "WooCommerce", "Wp Mail Smtp" instead of "WP Mail SMTP"). Five of these resolve through the
	 * Integrations tab's own labels (aafm_integration_cards()); WP Mail SMTP is not one of our
	 * native integrations, so it resolves through the bridge's own known-plugin map instead.
	 */
	public function test_known_plugin_namespaces_render_their_real_name(): void {
		$this->assertSame( 'WooCommerce', aafm_bridge_display_label( 'woocommerce' ) );
		$this->assertSame( 'Yoast SEO', aafm_bridge_display_label( 'yoast' ) );
		$this->assertSame( 'Yoast SEO', aafm_bridge_display_label( 'wordpress-seo' ) );
		$this->assertSame( 'Rank Math', aafm_bridge_display_label( 'rankmath' ) );
		$this->assertSame( 'Rank Math', aafm_bridge_display_label( 'rank-math' ) );
		$this->assertSame( 'All in One SEO', aafm_bridge_display_label( 'aioseo' ) );
		$this->assertSame( 'All in One SEO', aafm_bridge_display_label( 'all-in-one-seo-pack' ) );
		$this->assertSame( 'ACF', aafm_bridge_display_label( 'acf' ) );
		$this->assertSame( 'ACF', aafm_bridge_display_label( 'advanced-custom-fields' ) );
		$this->assertSame( 'WP Mail SMTP', aafm_bridge_display_label( 'wp-mail-smtp' ) );
	}

	/**
	 * Build a minimal synthetic bridge ability row for aafm_render_bridge_group(), bypassing real
	 * Abilities API registration so the count-header tests below can control the exact risk mix.
	 *
	 * @param string $slug Ability slug.
	 * @param string $risk One of 'read' | 'write' | 'destructive'.
	 * @return array<string,mixed>
	 */
	private function synthetic_bridge_row( string $slug, string $risk ): array {
		return array(
			'slug'        => $slug,
			'label'       => $slug,
			'description' => '',
			'risk'        => $risk,
			'readonly'    => 'read' === $risk,
			'destructive' => 'destructive' === $risk,
			'tool_name'   => aafm_mcp_tool_name( aafm_bridge_tool_name( $slug ) ),
		);
	}

	/**
	 * Render a synthetic group with $disabled = true.
	 *
	 * The header count logic under test does not depend on the disabled state, but a live (not
	 * disabled) row tries to resolve its real permission callback via wp_get_ability(), which
	 * triggers a WP "ability not found" doing-it-wrong notice for a slug that was never actually
	 * registered through the Abilities API - these rows are synthetic count fixtures, not real
	 * abilities. $disabled = true skips that lookup (see aafm_render_bridge_permission_line())
	 * without affecting anything the count-header assertions check.
	 *
	 * @param array<string,mixed> $group Group data for aafm_render_bridge_group().
	 * @return string Rendered HTML.
	 */
	private function render_synthetic_group( array $group ): string {
		ob_start();
		aafm_render_bridge_group( 'counttest', $group, array(), true );
		return (string) ob_get_clean();
	}

	/**
	 * Regression guard for the bridge equivalent of the Integrations-tab count bug fixed in
	 * b1e18ba: the header tallied only read/write and folded destructive rows into write, so a
	 * group with three destructive abilities (WooCommerce delete-product, update-order-status,
	 * update-product, in the reported case) showed "7 abilities · 2 read, 5 write" - destructive
	 * reach silently absent from the one screen (bridged abilities) the high-risk floor does not
	 * cover. Asserts all three segments render with the real counts.
	 */
	public function test_group_header_shows_destructive_segment_with_correct_counts(): void {
		$group = array(
			'label'     => 'counttest',
			'abilities' => array(
				$this->synthetic_bridge_row( 'counttest/read-a', 'read' ),
				$this->synthetic_bridge_row( 'counttest/read-b', 'read' ),
				$this->synthetic_bridge_row( 'counttest/write-a', 'write' ),
				$this->synthetic_bridge_row( 'counttest/write-b', 'write' ),
				$this->synthetic_bridge_row( 'counttest/write-c', 'write' ),
				$this->synthetic_bridge_row( 'counttest/destroy-a', 'destructive' ),
			),
		);

		$html = $this->render_synthetic_group( $group );

		$summary_open  = strpos( $html, '<summary class="aafm-card-head' );
		$summary_close = strpos( $html, '</summary>', (int) $summary_open );
		$summary       = substr( $html, (int) $summary_open, (int) $summary_close - (int) $summary_open );

		$this->assertStringContainsString(
			'6 abilities · 2 read, 3 write, 1 destructive',
			$summary,
			'The destructive count must be visible in the header, not folded into write.'
		);
	}

	/**
	 * The other half of the same regression guard: a group with no destructive abilities must keep
	 * the familiar two-part string rather than always showing "0 destructive".
	 */
	public function test_group_header_stays_two_part_when_no_destructive_abilities(): void {
		$group = array(
			'label'     => 'counttest',
			'abilities' => array(
				$this->synthetic_bridge_row( 'counttest/read-a', 'read' ),
				$this->synthetic_bridge_row( 'counttest/read-b', 'read' ),
				$this->synthetic_bridge_row( 'counttest/write-a', 'write' ),
			),
		);

		$html = $this->render_synthetic_group( $group );

		$summary_open  = strpos( $html, '<summary class="aafm-card-head' );
		$summary_close = strpos( $html, '</summary>', (int) $summary_open );
		$summary       = substr( $html, (int) $summary_open, (int) $summary_close - (int) $summary_open );

		$this->assertStringContainsString( '3 abilities · 2 read, 1 write', $summary );
		$this->assertStringNotContainsString( 'destructive', $summary );
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

	public function test_directory_surfaces_effective_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// A second foreign ability gated by a closure. We cannot read a closure, so its row must
		// fall back to the honest "determined by the plugin" note rather than a callback label.
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				wp_register_ability(
					'demo/closed',
					array(
						'label'               => 'Closed',
						'description'         => 'c',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'execute_callback'    => static fn() => array(),
						'permission_callback' => static fn() => true,
					)
				);
			}
		);

		ob_start();
		aafm_render_bridge_directory();
		$html = (string) ob_get_clean();

		// demo/echo gates on a named __return_true - a readable, and notably wide-open, callback
		// the operator should be able to see before enabling.
		$this->assertStringContainsString( 'Permission check:', $html );
		$this->assertStringContainsString( '__return_true', $html );

		// demo/closed gates on an unreadable closure, so its row shows the honest fallback.
		$this->assertStringContainsString( 'Permission determined by Demo', $html );
	}
}
