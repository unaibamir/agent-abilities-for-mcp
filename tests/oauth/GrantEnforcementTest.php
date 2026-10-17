<?php
/**
 * Tests for per-connection expiry on the bearer, refresh and validate paths.
 *
 * The authorize-page and code-redemption cases live beside their existing harnesses, in
 * AuthorizeTest and HandshakeTest.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * An expired or uncertifiable grant resolves no user and cannot refresh; everything else is unchanged.
 */
class GrantEnforcementTest extends TestCase {

	private const CLIENT = 'client_enforce';

	/**
	 * Approving user.
	 *
	 * @var int
	 */
	private int $user = 0;

	/**
	 * $_SERVER as set_up() found it.
	 *
	 * @var array<string,mixed>
	 */
	private array $server = array();

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['REQUEST_URI'] = '/' . trim( rest_get_url_prefix(), '/' ) . '/agent-abilities-for-mcp/mcp';
		$this->route_as_rest_request();
		$_SERVER['HTTPS'] = 'on';
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		update_option( 'aafm_oauth_enabled', '1' );
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => self::CLIENT,
				'client_name' => 'Enforce',
				'is_active'   => 1,
			)
		);
		$this->user = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'query' );
		delete_transient( 'aafm_oauth_upgrade_lock' );
		SchemaV9Test::restore_schema();
		$this->set_permalink_structure( '' );
		$_SERVER = $this->server;
		parent::tear_down();
	}

	/**
	 * Record the consent row, with optional v9 columns.
	 *
	 * @param array<string,mixed> $extra Extra columns.
	 * @return void
	 */
	private function consent( array $extra = array() ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array_merge(
				array(
					'wp_user_id' => $this->user,
					'client_id'  => self::CLIENT,
				),
				$extra
			)
		);
	}

	/**
	 * Mint a token pair for the fixture grant.
	 *
	 * @return array{access_token:string,refresh_token:string,expires_in:int}
	 */
	private function mint(): array {
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $this->user,
				'client_id'  => self::CLIENT,
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->assertIsArray( $tokens );
		return $tokens;
	}

	/**
	 * Resolve a bearer the way determine_current_user does.
	 *
	 * @param string $access Raw access token.
	 * @return int|false
	 */
	private function resolve( string $access ) {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;
		return aafm_oauth_resolve_current_user( false );
	}

	/**
	 * Denied oauth:bearer rows in the activity log.
	 *
	 * @return int
	 */
	private function bearer_denials(): int {
		return count(
			aafm_query_activity(
				array(
					'ability' => 'oauth:bearer',
					'status'  => 'denied',
				)
			)
		);
	}

	public function test_an_active_grant_resolves_exactly_as_before(): void {
		$this->consent();
		$tokens = $this->mint();
		$this->assertSame( $this->user, $this->resolve( $tokens['access_token'] ) );
		$this->assertSame( self::CLIENT, aafm_oauth_current_client_id() );
		$this->assertSame( self::CLIENT, aafm_oauth_current_grant()['client_id'] );
		$this->assertSame( $this->user, aafm_oauth_current_grant()['user_id'] );
	}

	public function test_an_expired_grant_resolves_no_user_and_logs_one_bounded_denied_row(): void {
		$this->consent( array( 'expires_at' => '2020-01-01 00:00:00' ) );
		$tokens = $this->mint();
		$this->assertFalse( $this->resolve( $tokens['access_token'] ) );
		$this->assertSame( 1, $this->bearer_denials() );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->resolve( $tokens['access_token'] );
		}
		$this->assertLessThanOrEqual( AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW, $this->bearer_denials() );
	}

	public function test_a_grant_with_no_end_date_never_expires(): void {
		$this->consent( array( 'expires_at' => null ) );
		$tokens = $this->mint();
		$this->assertSame( $this->user, $this->resolve( $tokens['access_token'] ) );
		$this->assertSame( 'ok', aafm_oauth_grant_gate( $this->user, self::CLIENT ) );
	}

	public function test_bearer_resolves_before_the_v9_columns_exist(): void {
		SchemaV9Test::downgrade_consents_to_v8();
		update_option( 'aafm_oauth_schema_version', '8' );
		$this->consent();
		$tokens = $this->mint();
		ob_start();
		$resolved = $this->resolve( $tokens['access_token'] );
		$out      = (string) ob_get_clean();
		$this->assertSame( $this->user, $resolved );
		$this->assertSame( '', $out );
	}

	public function test_a_failed_grant_read_on_a_current_schema_denies(): void {
		global $wpdb;
		$this->consent();
		$tokens = $this->mint();
		$this->assertSame( '9', get_option( 'aafm_oauth_schema_version' ) );
		$resolved = QueryFaultInjector::fail_query(
			'FROM `' . $wpdb->prefix . 'aafm_oauth_consents`',
			fn() => $this->resolve( $tokens['access_token'] )
		);
		$this->assertFalse( $resolved );
	}

	public function test_columns_present_with_an_old_version_stamp_and_a_failed_read_denies(): void {
		global $wpdb;
		$this->consent( array( 'paused_at' => '2026-10-01 00:00:00' ) );
		$tokens = $this->mint();
		update_option( 'aafm_oauth_schema_version', '8' );
		$resolved = QueryFaultInjector::fail_query(
			'FROM `' . $wpdb->prefix . 'aafm_oauth_consents`',
			fn() => $this->resolve( $tokens['access_token'] )
		);
		$this->assertFalse( $resolved );
	}

	public function test_refresh_is_refused_when_the_grant_expired_and_allowed_when_paused(): void {
		global $wpdb;
		$this->consent( array( 'paused_at' => '2026-10-01 00:00:00' ) );
		$tokens  = $this->mint();
		$rotated = aafm_oauth_rotate_refresh( $tokens['refresh_token'], self::CLIENT );
		$this->assertIsArray( $rotated, 'A paused grant keeps refreshing so Resume needs no new sign-in.' );

		$wpdb->update( $wpdb->prefix . 'aafm_oauth_consents', array( 'expires_at' => '2020-01-01 00:00:00' ), array( 'client_id' => self::CLIENT ) );
		$refused = aafm_oauth_rotate_refresh( $rotated['refresh_token'], self::CLIENT );
		$this->assertWPError( $refused );
		$this->assertSame( 'invalid_grant', $refused->get_error_code() );
	}

	public function test_validate_access_token_agrees_with_the_resolver_on_expiry(): void {
		$this->consent( array( 'expires_at' => '2020-01-01 00:00:00' ) );
		$tokens = $this->mint();
		$this->assertFalse( aafm_oauth_validate_access_token( $tokens['access_token'] ) );
		$this->assertFalse( $this->resolve( $tokens['access_token'] ) );
	}

	public function test_the_two_grant_gates_never_widen_anything_for_a_token_without_a_consent_row(): void {
		$tokens = $this->mint();
		$this->assertSame( $this->user, $this->resolve( $tokens['access_token'] ) );
		$this->assertSame( $this->user, aafm_oauth_validate_access_token( $tokens['access_token'] ) );
		$this->assertFalse( aafm_oauth_current_grant()['paused'] );
	}

	public function test_a_refresh_token_from_before_the_update_still_rotates(): void {
		SchemaV9Test::downgrade_consents_to_v8();
		update_option( 'aafm_oauth_schema_version', '8' );
		$this->consent();
		$tokens = $this->mint();

		delete_transient( 'aafm_oauth_upgrade_lock' );
		aafm_maybe_upgrade_oauth_tables();
		$this->assertSame( '9', get_option( 'aafm_oauth_schema_version' ) );

		$rotated = aafm_oauth_rotate_refresh( $tokens['refresh_token'], self::CLIENT );
		$this->assertIsArray( $rotated );
		$this->assertSame( $this->user, $this->resolve( $rotated['access_token'] ) );
	}
}
