<?php
/**
 * Governed user-meta surface: the auth-key deny-list (CVE-class), the default-deny
 * allowlist, the user-scoped value sanitizer, and the get/update/delete-user-meta
 * abilities. The deny-list is the headline guarantee — session tokens, application
 * passwords, capability/user-level keys, and 2FA/reset keys can never be read,
 * written, or deleted, even when a filter tries to allowlist them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class UserMetaTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function test_auth_keys_are_hard_blocked_for_everyone(): void {
		global $wpdb;
		$keys = array(
			'session_tokens',
			'_application_passwords',
			'wp_capabilities',
			'wp_user_level',
			'default_password_nonce',
			'_password_reset_key',
			'two_factor_enabled',
			'webauthn_credentials',
			$wpdb->prefix . 'capabilities',
			$wpdb->prefix . 'user_level',
		);
		foreach ( $keys as $key ) {
			$this->assertTrue( aafm_hard_blocked_user_meta_key( $key ), "$key must be hard-blocked." );
		}
		// Multisite per-blog forms (wp_2_capabilities / wp_2_user_level) must also block.
		$this->assertTrue( aafm_hard_blocked_user_meta_key( $wpdb->prefix . '2_capabilities' ) );
		$this->assertTrue( aafm_hard_blocked_user_meta_key( $wpdb->prefix . '2_user_level' ) );
		// An empty key is refused.
		$this->assertTrue( aafm_hard_blocked_user_meta_key( '' ) );
		// A protected-meta (underscore) key is refused.
		$this->assertTrue( aafm_hard_blocked_user_meta_key( '_hidden' ) );
		// A plain custom key is NOT hard-blocked.
		$this->assertFalse( aafm_hard_blocked_user_meta_key( 'twitter' ) );
	}

	public function test_allowlist_defaults_empty_and_filter_adds(): void {
		$this->assertSame( array(), aafm_allowed_user_meta_keys() );
		add_filter( 'aafm_allowed_user_meta_keys', static fn() => array( 'twitter', 'session_tokens' ) );
		$keys = aafm_allowed_user_meta_keys();
		$this->assertContains( 'twitter', $keys );
		// A blocked key cannot be re-admitted through the filter.
		$this->assertNotContains( 'session_tokens', $keys );
	}

	public function test_capability_keys_need_manage_options_even_when_allowlisted(): void {
		add_filter( 'aafm_allowed_user_meta_keys', static fn() => array( 'wp_capabilities' ) );
		// wp_capabilities is hard-blocked outright in v1 (manage_options is a future refinement
		// per 47-); assert it is refused regardless of the allowlist.
		$this->assertTrue( aafm_hard_blocked_user_meta_key( 'wp_capabilities' ) );
		$this->assertNotContains( 'wp_capabilities', aafm_allowed_user_meta_keys() );
		$this->assertWPError( aafm_validate_user_meta_key( 'wp_capabilities' ) );
	}

	public function test_validate_user_meta_key_requires_allowlist_and_not_blocked(): void {
		// Not allowlisted → refused even though it is not blocked.
		$this->assertWPError( aafm_validate_user_meta_key( 'twitter' ) );
		add_filter( 'aafm_allowed_user_meta_keys', static fn() => array( 'twitter' ) );
		$this->assertSame( 'twitter', aafm_validate_user_meta_key( 'twitter' ) );
		// Blocked key stays refused even after allowlisting.
		add_filter( 'aafm_allowed_user_meta_keys', static fn( $k ) => array_merge( $k, array( 'session_tokens' ) ) );
		$this->assertWPError( aafm_validate_user_meta_key( 'session_tokens' ) );
	}

	public function test_user_meta_value_sanitizer_is_scalar_only(): void {
		$this->assertSame( 'plain text', aafm_sanitize_user_meta_value( 'twitter', 'plain text' ) );
		$this->assertSame( 7, aafm_sanitize_user_meta_value( 'twitter', 7 ) );
		$this->assertWPError( aafm_sanitize_user_meta_value( 'twitter', array( 'a' => 'b' ) ) );
		$this->assertWPError( aafm_sanitize_user_meta_value( 'twitter', new \stdClass() ) );
	}

	/**
	 * Enable + register the whole catalog so wp_get_ability() resolves the user-meta tools.
	 */
	private function register_all(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		aafm_register_categories();
		array_pop( $wp_current_filter );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry() ) );
		$wp_current_filter[] = 'wp_abilities_api_init';
		aafm_register_enabled_abilities();
		array_pop( $wp_current_filter );
	}

	public function test_user_meta_roundtrip_on_allowlisted_key(): void {
		add_filter( 'aafm_allowed_user_meta_keys', static fn() => array( 'twitter' ) );
		$this->register_all();
		$this->acting_as( 'administrator' );
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );

		$set = wp_get_ability( 'aafm/update-user-meta' )->execute(
			array(
				'user_id' => $uid,
				'key'     => 'twitter',
				'value'   => '@handle',
			)
		);
		$this->assertIsArray( $set );
		$get = wp_get_ability( 'aafm/get-user-meta' )->execute(
			array(
				'user_id' => $uid,
				'key'     => 'twitter',
			)
		);
		$this->assertSame( '@handle', $get['value'] ?? null );
		$del = wp_get_ability( 'aafm/delete-user-meta' )->execute(
			array(
				'user_id' => $uid,
				'key'     => 'twitter',
			)
		);
		$this->assertIsArray( $del );
		$this->assertSame( '', (string) get_user_meta( $uid, 'twitter', true ) );
	}

	public function test_user_meta_refuses_blocked_key_even_if_targeted(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		$res = wp_get_ability( 'aafm/get-user-meta' )->execute(
			array(
				'user_id' => $uid,
				'key'     => 'session_tokens',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $res );
	}

	public function test_user_meta_gate_is_edit_user(): void {
		add_filter( 'aafm_allowed_user_meta_keys', static fn() => array( 'twitter' ) );
		$this->register_all();
		$uid = self::factory()->user->create( array( 'role' => 'editor' ) );
		// A subscriber cannot edit_user another user.
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue( wp_get_ability( 'aafm/update-user-meta' )->check_permissions( array( 'user_id' => $uid ) ) );
		$this->assertNotTrue( wp_get_ability( 'aafm/get-user-meta' )->check_permissions( array( 'user_id' => $uid ) ) );
		$this->assertNotTrue( wp_get_ability( 'aafm/delete-user-meta' )->check_permissions( array( 'user_id' => $uid ) ) );
	}

	/**
	 * The CVE-class guarantee: a deny-listed auth/capability key can be neither READ, WRITTEN,
	 * nor DELETED — even by an administrator, and even when a filter tries to allowlist it.
	 * A leak here is an account-takeover primitive, so prove all three verbs on every key.
	 */
	public function test_denylist_keys_cannot_be_read_written_or_deleted_even_when_allowlisted(): void {
		global $wpdb;
		$denied = array(
			'session_tokens',
			'_application_passwords',
			'wp_capabilities',
			'wp_user_level',
			'_password_reset_key',
			$wpdb->prefix . 'capabilities',
		);
		// Try to smuggle every one of them onto the allowlist.
		add_filter( 'aafm_allowed_user_meta_keys', static fn() => $denied );
		$this->register_all();
		$this->acting_as( 'administrator' );
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );

		foreach ( $denied as $key ) {
			$before = get_user_meta( $uid, $key, true );

			$read = wp_get_ability( 'aafm/get-user-meta' )->execute(
				array(
					'user_id' => $uid,
					'key'     => $key,
				)
			);
			$this->assertInstanceOf( \WP_Error::class, $read, "READ of $key must be refused." );

			$write = wp_get_ability( 'aafm/update-user-meta' )->execute(
				array(
					'user_id' => $uid,
					'key'     => $key,
					'value'   => 'pwn',
				)
			);
			$this->assertInstanceOf( \WP_Error::class, $write, "WRITE of $key must be refused." );

			$delete = wp_get_ability( 'aafm/delete-user-meta' )->execute(
				array(
					'user_id' => $uid,
					'key'     => $key,
				)
			);
			$this->assertInstanceOf( \WP_Error::class, $delete, "DELETE of $key must be refused." );

			// The stored value is untouched by all three refused calls.
			$this->assertSame( $before, get_user_meta( $uid, $key, true ), "$key must be unchanged after the refused calls." );
		}

		// And the deny-listed keys never reached the allowlist in the first place.
		$this->assertSame( array(), aafm_allowed_user_meta_keys() );
	}

	/**
	 * M3 scalar-guard: an allowlisted key whose stored value is a serialized ARRAY must not
	 * leak its structure through the read — the reader refuses rather than dumping the array.
	 */
	public function test_get_user_meta_scalar_guards_a_serialized_array_value(): void {
		add_filter( 'aafm_allowed_user_meta_keys', static fn() => array( 'twitter' ) );
		$this->register_all();
		$this->acting_as( 'administrator' );
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $uid, 'twitter', array( 'secret' => 'structure' ) );

		$res = wp_get_ability( 'aafm/get-user-meta' )->execute(
			array(
				'user_id' => $uid,
				'key'     => 'twitter',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $res );
	}

	public function test_user_meta_discoverable_by_capable_admin_only(): void {
		$this->register_all();

		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-user-meta' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-user-meta' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-user-meta' ) );

		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/get-user-meta' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/update-user-meta' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/delete-user-meta' ) );
	}
}
