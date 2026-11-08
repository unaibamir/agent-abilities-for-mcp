<?php
/**
 * The high-risk lock holds other plugins' tools too: a held foreign slug is not bridged while the
 * category is locked, its stored choice survives a save on the Other plugins tab, and unlocking
 * gives it back unchanged.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\ForeignAbilities;
use AAFM\Tests\TestCase;

final class BridgeHighRiskFloorTest extends TestCase {

	use ForeignAbilities;

	private const HELD = 'demo/charge-card';

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_foreign_abilities(
			'demo',
			array(
				'charge-card' => array( 'readonly' => false ),
				'echo'        => array( 'readonly' => false ),
			)
		);
		add_filter( 'aafm_high_risk_abilities', array( $this, 'hold_demo' ) );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_high_risk_abilities', array( $this, 'hold_demo' ) );
		remove_all_filters( 'aafm_force_block_high_risk_abilities' );
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_POST['nonce'], $_POST['bridged_abilities'], $_REQUEST['nonce'] );
		delete_option( 'aafm_enabled_bridged_abilities' );
		delete_option( 'aafm_high_risk_abilities_unlocked' );
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			if ( 0 === strncmp( (string) $slug, 'aafm-bridge/demo-', 17 ) ) {
				wp_unregister_ability( (string) $slug );
			}
		}
		$this->unregister_foreign_abilities();
		parent::tear_down();
	}

	/**
	 * Filter callback: hold the demo fixture slug.
	 *
	 * @param array<int,string> $extra Extra names.
	 * @return array<int,string>
	 */
	public function hold_demo( array $extra ): array {
		$extra[] = self::HELD;
		return $extra;
	}

	private function register_wrappers(): void {
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities' );
	}

	/**
	 * Post the bridge save as an administrator and return the JSON body.
	 *
	 * @param array<int,string> $slugs Posted slugs.
	 * @return array<string,mixed>
	 */
	private function save_tab( array $slugs ): array {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
		$nonce                      = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']             = $nonce;
		$_REQUEST['nonce']          = $nonce;
		$_POST['bridged_abilities'] = $slugs;
		ob_start();
		try {
			aafm_ajax_save_bridged_abilities();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$json = json_decode( (string) ob_get_clean(), true );
		return is_array( $json ) ? $json : array();
	}

	public function test_a_held_tool_that_is_switched_on_does_not_register_while_locked(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( self::HELD, 'demo/echo' ) );

		$this->assertSame( array( 'demo/echo' ), aafm_get_enabled_bridged_abilities() );
		$this->register_wrappers();
		$this->assertFalse( wp_has_ability( 'aafm-bridge/demo-charge-card' ) );
		$this->assertTrue( wp_has_ability( 'aafm-bridge/demo-echo' ) );
	}

	public function test_unlocking_restores_it_without_rewriting_the_option(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( self::HELD, 'demo/echo' ) );
		aafm_get_enabled_bridged_abilities();
		$this->assertSame( array( self::HELD, 'demo/echo' ), get_option( 'aafm_enabled_bridged_abilities' ), 'The lock never rewrites the stored choice.' );

		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->assertSame( array( self::HELD, 'demo/echo' ), aafm_get_enabled_bridged_abilities() );
	}

	public function test_a_save_on_the_tab_keeps_a_stored_held_tool(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( self::HELD ) );

		// The locked row renders no switch, so the save posts only the open tool.
		$json = $this->save_tab( array( 'demo/echo' ) );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$stored = (array) get_option( 'aafm_enabled_bridged_abilities', array() );
		$this->assertContains( self::HELD, $stored );
		$this->assertContains( 'demo/echo', $stored );
	}

	public function test_a_posted_held_tool_not_already_stored_is_refused_and_logged_as_high_risk(): void {
		aafm_clear_activity_log();

		$json = $this->save_tab( array( self::HELD ) );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertNotContains( self::HELD, (array) get_option( 'aafm_enabled_bridged_abilities', array() ) );
		$rows    = aafm_query_activity( array( 'per_page' => 20 ) );
		$blocked = array_values( array_filter( $rows, static fn( array $r ): bool => 'ability_enable_blocked' === $r['event_type'] ) );
		$this->assertCount( 1, $blocked );
		$this->assertSame( self::HELD, $blocked[0]['ability'] );
		$this->assertStringContainsString( 'high-risk', (string) $blocked[0]['detail'] );
	}

	public function test_a_forced_lock_holds_it_even_when_unlocked(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( self::HELD ) );
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		add_filter( 'aafm_force_block_high_risk_abilities', '__return_true' );

		$this->assertSame( array(), aafm_get_enabled_bridged_abilities() );
	}

	public function test_a_call_to_an_unlocked_held_tool_alerts_as_high_risk(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( self::HELD ) );
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->register_wrappers();

		$this->assertTrue( wp_has_ability( 'aafm-bridge/demo-charge-card' ) );
		$this->assertSame( 'high_risk', aafm_notify_ability_risk( 'aafm-bridge/demo-charge-card' ) );
	}
}
