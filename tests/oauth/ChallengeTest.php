<?php
/**
 * Tests for the OAuth WWW-Authenticate challenge attached to the MCP 401.
 *
 * The transport's existing 401 (aafm_unauthenticated) gains an additive
 * resource_metadata challenge — and only when OAuth is enabled. The 403 IP-block
 * branch and the authenticated path stay byte-for-byte as they were.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Verifies the challenge header builder and its additive attachment to the
 * unauthenticated transport response, plus the rest_post_dispatch promotion of
 * the data key to a real HTTP header.
 */
final class ChallengeTest extends TestCase {

	/**
	 * Saved REMOTE_ADDR so the 403 test restores the fixture's request environment.
	 *
	 * @var string|null
	 */
	private $original_remote_addr;

	public function set_up(): void {
		parent::set_up();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$this->original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;

		// The 403 IP-block path writes a 'denied' row to the custom activity log.
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function tear_down(): void {
		if ( null === $this->original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}
		parent::tear_down();
	}

	/**
	 * The challenge header is a Bearer scheme pointing at the protected-resource
	 * metadata document under .well-known on this site.
	 */
	public function test_challenge_header_value(): void {
		$header = aafm_oauth_challenge_header();

		$this->assertStringStartsWith( 'Bearer ', $header );
		$this->assertStringContainsString( 'resource_metadata=', $header );
		$this->assertStringEndsWith( '/.well-known/oauth-protected-resource"', $header );
		$this->assertSame(
			'Bearer resource_metadata="' . home_url( '/.well-known/oauth-protected-resource' ) . '"',
			$header
		);
	}

	/**
	 * An unauthenticated request returns the 401 and, with OAuth enabled, carries
	 * the challenge under the www_authenticate data key.
	 */
	public function test_unauthenticated_401_carries_challenge_when_oauth_enabled(): void {
		wp_set_current_user( 0 );

		$result = aafm_transport_permission_callback( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? 0 );
		$this->assertSame( aafm_oauth_challenge_header(), $result->get_error_data()['www_authenticate'] ?? '' );
	}

	/**
	 * With OAuth explicitly disabled, the 401 is unchanged: no challenge is added.
	 */
	public function test_unauthenticated_401_omits_challenge_when_oauth_disabled(): void {
		update_option( 'aafm_oauth_enabled', '0' );
		wp_set_current_user( 0 );

		$result = aafm_transport_permission_callback( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? 0 );
		$this->assertArrayNotHasKey( 'www_authenticate', $result->get_error_data() );
	}

	/**
	 * The 403 IP-block branch is untouched: it carries no challenge regardless of
	 * the OAuth toggle. Locks the frozen invariant.
	 */
	public function test_ip_blocked_403_carries_no_challenge(): void {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $uid );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		update_option( 'aafm_ip_allowlist', array( '10.0.0.0/8' ) );

		$result = aafm_transport_permission_callback( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
		$this->assertArrayNotHasKey( 'www_authenticate', $result->get_error_data() );
	}

	/**
	 * The rest_post_dispatch filter promotes the www_authenticate data key on a 401
	 * error response into a real WWW-Authenticate HTTP header.
	 */
	public function test_filter_promotes_data_key_to_header(): void {
		$challenge = aafm_oauth_challenge_header();
		$response  = new \WP_REST_Response(
			array(
				'code'    => 'aafm_unauthenticated',
				'message' => 'Authentication required.',
				'data'    => array(
					'status'           => 401,
					'www_authenticate' => $challenge,
				),
			),
			401
		);

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), new \WP_REST_Request() );

		$this->assertSame( $challenge, $out->get_headers()['WWW-Authenticate'] ?? '' );
	}

	/**
	 * The filter leaves a non-401 response alone — no header is invented.
	 */
	public function test_filter_ignores_non_401_responses(): void {
		$response = new \WP_REST_Response( array( 'ok' => true ), 200 );

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), new \WP_REST_Request() );

		$this->assertArrayNotHasKey( 'WWW-Authenticate', $out->get_headers() );
	}

	/**
	 * A 401 with no www_authenticate data key (e.g. an unrelated auth failure) is
	 * passed through untouched.
	 */
	public function test_filter_ignores_401_without_data_key(): void {
		$response = new \WP_REST_Response(
			array(
				'code' => 'rest_not_logged_in',
				'data' => array( 'status' => 401 ),
			),
			401
		);

		$out = aafm_oauth_filter_rest_challenge( $response, rest_get_server(), new \WP_REST_Request() );

		$this->assertArrayNotHasKey( 'WWW-Authenticate', $out->get_headers() );
	}
}
