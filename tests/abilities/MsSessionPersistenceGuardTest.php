<?php
/**
 * Multisite behavior for the session-persistence guard: proves aafm_mcp_guard_unpersisted_session()
 * correctly recognizes a session that WP\MCP\Transport\Infrastructure\SessionManager::create_session()
 * actually persisted - on its own site - and does not cross-recognize a session created on a
 * DIFFERENT site on the same network as valid.
 *
 * The sibling single-site test, tests/abilities/McpSessionPersistenceGuardTest.php, hand-writes to
 * the literal 'mcp_adapter_sessions' user-meta key rather than driving the real adapter write path,
 * so it cannot catch a change to that key's name or shape. This test always calls the real
 * SessionManager::create_session() so a mismatch between what it actually writes and what
 * includes/server.php's guard actually reads is provable rather than assumed.
 *
 * Runs only under tests/multisite.xml.dist; the single-site config skips it.
 *
 * @group ms-required
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Drives aafm_mcp_guard_unpersisted_session() against SessionManager::create_session()'s real
 * output, across two sites on one network.
 *
 * @group ms-required
 */
final class MsSessionPersistenceGuardTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		if ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}
		parent::tear_down();
	}

	/**
	 * A response carrying the given session id on the Mcp-Session-Id header, exactly as the
	 * transport leaves a successful initialize response.
	 *
	 * @param string $session_id The session id to stamp.
	 * @return WP_REST_Response
	 */
	private function session_response( string $session_id ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array( 'protocolVersion' => '2025-06-18' ),
			),
			200
		);
		$response->header( 'Mcp-Session-Id', $session_id );
		return $response;
	}

	/**
	 * A request against the MCP route, matching tests/abilities/McpSessionPersistenceGuardTest.php.
	 *
	 * @return WP_REST_Request
	 */
	private function mcp_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
				)
			)
		);
		return $request;
	}

	/**
	 * A session genuinely created via the real adapter write path, on the CURRENT site, is
	 * recognized as persisted when the guard checks it on that same site.
	 */
	public function test_a_session_created_on_a_site_is_recognized_as_persisted_on_that_same_site(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$blog_id = (int) self::factory()->blog->create();

		switch_to_blog( $blog_id );
		wp_set_current_user( $user_id );

		$session_id = \WP\MCP\Transport\Infrastructure\SessionManager::create_session( $user_id );
		$this->assertIsString( $session_id, 'SessionManager::create_session() must return a real session id to test against.' );

		$out = aafm_mcp_guard_unpersisted_session( $this->session_response( $session_id ), null, $this->mcp_request() );

		$this->assertSame( 200, $out->get_status(), 'A session genuinely persisted via the real adapter write path on this site must be recognized as persisted by the guard on the same site.' );

		restore_current_blog();
	}

	/**
	 * A session created on one site must NOT be recognized as persisted when the guard checks it
	 * from a DIFFERENT site on the same network - proving the guard does not silently fall back to
	 * a stale, network-wide read of a per-site-scoped store.
	 */
	public function test_a_session_created_on_one_site_is_not_recognized_as_persisted_on_another_site(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$blog_a  = (int) self::factory()->blog->create();
		$blog_b  = (int) self::factory()->blog->create();

		switch_to_blog( $blog_a );
		wp_set_current_user( $user_id );
		$session_id = \WP\MCP\Transport\Infrastructure\SessionManager::create_session( $user_id );
		$this->assertIsString( $session_id );
		restore_current_blog();

		switch_to_blog( $blog_b );
		wp_set_current_user( $user_id );

		$out = aafm_mcp_guard_unpersisted_session( $this->session_response( $session_id ), null, $this->mcp_request() );

		$this->assertSame(
			500,
			$out->get_status(),
			'A session created on one site must not be treated as persisted when checked from a different site on the same network - the point of the per-site session storage this adapter bump introduces.'
		);

		restore_current_blog();
	}
}
