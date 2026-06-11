<?php
/**
 * User read ability: list_users gating + strict redaction (no email/login/pass).
 *
 * This is the most PII-sensitive read in the catalog — competitors leaked user
 * data here. The tests prove a low-privilege caller is denied (and audited) and
 * that the redacted output never carries email, login, or a password hash.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class UsersReadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions, simulated
		// by pushing the action name onto $wp_current_filter — the idiom WP core's own
		// ability test trait uses. wp_register_ability() refuses to run otherwise.
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-users' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * @param string   $action   Action name to simulate.
	 * @param callable $callback Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $callback ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$callback();
		array_pop( $wp_current_filter );
	}

	public function test_get_users_is_in_registry(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/get-users', $registry );
		$this->assertSame( 'reads', $registry['aafm/get-users']['group'] );
		$this->assertSame( 'read', $registry['aafm/get-users']['risk'] );
	}

	/**
	 * Only a caller with the list_users capability (the cap WP itself gates the
	 * user list behind) may enumerate users. An author is denied; an admin allowed.
	 */
	public function test_requires_list_users_cap(): void {
		$this->acting_as( 'author' );
		$this->assertFalse( wp_get_ability( 'aafm/get-users' )->check_permissions( array() ) );

		$this->acting_as( 'administrator' );
		$this->assertTrue( wp_get_ability( 'aafm/get-users' )->check_permissions( array() ) );
	}

	/**
	 * A denied user-enumeration attempt writes a `denied` audit row via the
	 * registration wrapper's permission decorator — proven on the live path.
	 */
	public function test_denied_enumeration_is_audited(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( wp_get_ability( 'aafm/get-users' )->check_permissions( array() ) );

		$rows  = aafm_query_activity( array( 'status' => 'denied' ) );
		$names = wp_list_pluck( $rows, 'ability' );
		$this->assertContains( 'aafm/get-users', $names );
	}

	/**
	 * Security red line: the redacted output must never contain email, login, or a
	 * password hash. Builds a user with a known email/login and asserts both strings
	 * are absent from the serialized output, and that the per-row shape carries only
	 * the safe whitelist (id, display_name, roles, post_count).
	 */
	public function test_output_has_no_email_login_or_password(): void {
		$this->acting_as( 'administrator' );
		$uid  = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_email'   => 'leak@example.com',
				'user_login'   => 'leaklogin',
				'user_pass'    => 'SuperSecretPlainPass',
				'display_name' => 'Visible Display Name',
			)
		);
		$hash = get_userdata( $uid )->user_pass;

		$out  = wp_get_ability( 'aafm/get-users' )->execute( array() );
		$json = (string) wp_json_encode( $out );

		// No email, login, or password hash anywhere in the serialized output.
		$this->assertStringNotContainsString( 'leak@example.com', $json );
		$this->assertStringNotContainsString( 'leaklogin', $json );
		$this->assertStringNotContainsString( $hash, $json );

		// Per-row shape: only the safe whitelist, with PII keys structurally absent.
		foreach ( $out['users'] as $u ) {
			$this->assertSame(
				array( 'id', 'display_name', 'roles', 'post_count' ),
				array_keys( $u )
			);
			$this->assertArrayNotHasKey( 'user_email', $u );
			$this->assertArrayNotHasKey( 'user_login', $u );
			$this->assertArrayNotHasKey( 'user_pass', $u );
			$this->assertArrayHasKey( 'display_name', $u );
		}
	}

	/**
	 * The role filter narrows the result set without ever widening the field shape.
	 */
	public function test_role_filter_narrows_results(): void {
		$this->acting_as( 'administrator' );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$out = wp_get_ability( 'aafm/get-users' )->execute( array( 'role' => 'editor' ) );
		$ids = wp_list_pluck( $out['users'], 'id' );

		$this->assertContains( $editor, $ids );
		foreach ( $out['users'] as $u ) {
			$this->assertContains( 'editor', $u['roles'] );
		}
	}
}
