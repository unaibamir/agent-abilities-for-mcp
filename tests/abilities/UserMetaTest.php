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
}
