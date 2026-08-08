<?php
/**
 * Transport-gate safety enforcement: the IP allowlist denies blocked addresses
 * (audited) while leaving the logged-in and unauthenticated paths intact.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class SafetyEnforcementTest extends TestCase {

	/**
	 * Saved REMOTE_ADDR so each test restores the fixture's request environment.
	 *
	 * @var string|null
	 */
	private $original_remote_addr;

	public function set_up(): void {
		parent::set_up();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$this->original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;

		// The transport denial path writes a 'denied' row to the custom log.
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->ensure_categories();
	}

	public function tear_down(): void {
		if ( null === $this->original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}
		parent::tear_down();
	}

	public function test_transport_blocks_disallowed_ip_and_audits(): void {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $uid );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		update_option( 'aafm_ip_allowlist', array( '10.0.0.0/8' ) );

		$result = aafm_transport_permission_callback( null );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );

		$denied = aafm_query_activity(
			array(
				'status'   => 'denied',
				'per_page' => 1,
			)
		);
		$this->assertNotEmpty( $denied );
		$this->assertSame( 'denied', $denied[0]['status'] );
		$this->assertSame( '(transport)', $denied[0]['ability'] );
	}

	public function test_transport_allows_listed_ip(): void {
		$uid = self::factory()->user->create();
		wp_set_current_user( $uid );
		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
		update_option( 'aafm_ip_allowlist', array( '10.0.0.0/8' ) );
		$this->assertTrue( aafm_transport_permission_callback( null ) );
	}

	public function test_transport_empty_allowlist_allows_any_ip(): void {
		$uid = self::factory()->user->create();
		wp_set_current_user( $uid );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		update_option( 'aafm_ip_allowlist', array() );
		$this->assertTrue( aafm_transport_permission_callback( null ) );
	}

	public function test_transport_unauthenticated_still_401_regardless_of_ip(): void {
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '10.1.2.3'; // Would be allowed if it mattered.
		update_option( 'aafm_ip_allowlist', array( '10.0.0.0/8' ) );
		$result = aafm_transport_permission_callback( null );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * Register the plugin categories inside a simulated categories-init action.
	 *
	 * The Abilities API only permits category registration while the
	 * 'wp_abilities_api_categories_init' action is running; aafm_register_categories()
	 * is idempotent, so this is safe to call before every test.
	 */
	private function ensure_categories(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		aafm_register_categories();
		array_pop( $wp_current_filter );
	}

	/**
	 * Register an ability through the wrapper inside a simulated abilities-init action.
	 *
	 * @param string              $name Ability name.
	 * @param array<string,mixed> $args Ability args.
	 * @return mixed The wrapper return value (WP_Ability or null).
	 */
	private function register( string $name, array $args ) {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';
		$result              = aafm_register_ability_with_log( $name, $args );
		array_pop( $wp_current_filter );
		return $result;
	}

	/**
	 * B35: a top-level scalar JSON body on the MCP route must be rejected cleanly, not crash.
	 *
	 * The bundled transport builds an HttpRequestContext whose $body is typed ?array from
	 * get_json_params(); a scalar body ("x", true) makes that assignment throw an uncaught TypeError
	 * BEFORE the auth check, so an unauthenticated scalar body was a 500 plus a PHP fatal. The
	 * pre-dispatch guard turns it into a clean 400. A null body (the corrected note: 0 decodes to
	 * null, not a crash) and an object body are left alone.
	 */
	public function test_scalar_json_body_on_mcp_route_is_rejected_not_crashed(): void {
		$scalar = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$scalar->set_header( 'Content-Type', 'application/json' );
		$scalar->set_body( '"x"' );
		$result = aafm_reject_scalar_mcp_body( null, null, $scalar );
		$this->assertInstanceOf( \WP_Error::class, $result, 'A scalar string body must be rejected before the transport sees it.' );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );

		$boolean = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$boolean->set_header( 'Content-Type', 'application/json' );
		$boolean->set_body( 'true' );
		$this->assertInstanceOf( \WP_Error::class, aafm_reject_scalar_mcp_body( null, null, $boolean ), 'A boolean body is the same crash class and must be rejected.' );

		// A JSON object body is a legitimate MCP request and must pass through (null = continue).
		$object = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$object->set_header( 'Content-Type', 'application/json' );
		$object->set_body( '{"jsonrpc":"2.0","method":"initialize","id":1}' );
		$this->assertNull( aafm_reject_scalar_mcp_body( null, null, $object ), 'A JSON object body must pass through untouched.' );

		// A scalar body on a different REST route is not ours to guard.
		$other = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$other->set_header( 'Content-Type', 'application/json' );
		$other->set_body( '"x"' );
		$this->assertNull( aafm_reject_scalar_mcp_body( null, null, $other ), 'A scalar body on another route must not be touched.' );
	}

	public function test_decorated_permission_rate_limits_and_audits(): void {
		$uid = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_rate_limit_per_min', 1 );

		$this->register(
			'aafm/rl-probe',
			array(
				'label'               => 'RL Probe',
				'description'         => 'Throwaway ability for rate-limit testing.',
				'category'            => 'aafm-reads',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn( $i = null ) => array( 'ok' => true ),
				'permission_callback' => '__return_true',
			)
		);
		$ability = wp_get_ability( 'aafm/rl-probe' );

		// B12: one tools/call fires the decorated permission TWICE (the adapter's check_permission,
		// then core's re-check inside execute), but it must consume ONE token, not two. Model a full
		// call: check_permissions then execute (which releases the per-call memo). A limit of 1 must
		// therefore allow the whole first call and only deny the second call.
		$this->assertTrue( $ability->check_permissions( array() ) );                 // call 1, phase A.
		$this->assertNotInstanceOf( \WP_Error::class, $ability->execute( array() ) ); // call 1 proceeds.
		$this->assertFalse( $ability->check_permissions( array() ) );                // call 2: over limit.

		$denied = aafm_query_activity(
			array(
				'status'   => 'denied',
				'per_page' => 1,
			)
		);
		$this->assertNotEmpty( $denied );
		$this->assertSame( 'denied', $denied[0]['status'] );
		$this->assertSame( 'aafm/rl-probe', $denied[0]['ability'] );
	}

	public function test_rate_limit_off_decorator_is_no_op(): void {
		$uid = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_rate_limit_per_min', 0 ); // Zero is the default and disables the limit.

		$this->register(
			'aafm/rl-off-probe',
			array(
				'label'               => 'RL Off Probe',
				'description'         => 'Throwaway.',
				'category'            => 'aafm-reads',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn( $i = null ) => array( 'ok' => true ),
				'permission_callback' => '__return_true',
			)
		);
		$ability = wp_get_ability( 'aafm/rl-off-probe' );

		// Many calls, all allowed - off means no token consumption, identical to today.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $ability->check_permissions( array() ) );
		}
	}

	public function test_discovery_does_not_consume_a_rate_token(): void {
		// The RAW permission (used by tools/list) must NOT consume a token, so a real
		// ability call afterwards still gets its full allowance.
		$uid = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_rate_limit_per_min', 1 );

		$this->register(
			'aafm/rl-disc-probe',
			array(
				'label'               => 'RL Disc Probe',
				'description'         => 'Throwaway.',
				'category'            => 'aafm-reads',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn( $i = null ) => array( 'ok' => true ),
				'permission_callback' => '__return_true',
			)
		);

		// Simulate discovery: call the RAW permission the way the tools/list filter does.
		$raw = aafm_remember_raw_permission( 'aafm/rl-disc-probe' );
		$this->assertTrue( (bool) $raw( array() ) ); // discovery visibility check - must NOT consume a token.
		$this->assertTrue( (bool) $raw( array() ) ); // again - still no token.

		// Now the FIRST real (decorated) call still has its full allowance of 1. Model a full call
		// (check_permissions then execute, which releases the per-call rate memo) so the second real
		// call is the one that trips the limit.
		$ability = wp_get_ability( 'aafm/rl-disc-probe' );
		$this->assertTrue( $ability->check_permissions( array() ) );                 // 1st real call allowed.
		$this->assertNotInstanceOf( \WP_Error::class, $ability->execute( array() ) ); // 1st real call proceeds.
		$this->assertFalse( $ability->check_permissions( array() ) );                // 2nd real call over limit.
	}

	public function test_force_draft_overrides_create_post(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_force_draft', '1' );
		$out = aafm_exec_create_post( array( 'title' => 'Hello' ) );
		$this->assertSame( 'draft', $out['post']['status'] );
	}

	public function test_force_draft_overrides_create_page(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_force_draft', '1' );
		$out = aafm_exec_create_page( array( 'title' => 'Hello Page' ) );
		$this->assertSame( 'draft', $out['post']['status'] );
	}

	/**
	 * Force-draft must still coerce to draft even when the request explicitly asks for a
	 * public status (not just when status is omitted) - now that create-post/create-draft/
	 * create-page honour a requested status, this is the guard against force-draft being
	 * silently bypassed by an explicit status field.
	 */
	public function test_force_draft_overrides_explicit_publish_status_on_create_post(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_force_draft', '1' );
		$out = aafm_exec_create_post(
			array(
				'title'  => 'Hello',
				'status' => 'publish',
			)
		);
		$this->assertSame( 'draft', $out['post']['status'] );
	}

	public function test_force_draft_overrides_explicit_publish_status_on_create_draft(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_force_draft', '1' );
		$out = aafm_exec_create_draft(
			array(
				'title'  => 'Hello',
				'status' => 'publish',
			)
		);
		$this->assertSame( 'draft', $out['post']['status'] );
	}

	public function test_force_draft_overrides_explicit_publish_status_on_create_page(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_force_draft', '1' );
		$out = aafm_exec_create_page(
			array(
				'title'  => 'Hello Page',
				'status' => 'publish',
			)
		);
		$this->assertSame( 'draft', $out['post']['status'] );
	}

	public function test_force_draft_off_create_post_still_publishes(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_force_draft', '0' ); // OFF (default) - no behavior change.
		$out = aafm_exec_create_post( array( 'title' => 'Published Hello' ) );
		$this->assertSame( 'publish', $out['post']['status'] );
	}

	public function test_force_draft_overrides_update_post_to_publish(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_force_draft', '1' );
		$id  = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $uid,
			)
		);
		$out = aafm_exec_update_post(
			array(
				'post_id' => $id,
				'status'  => 'publish',
			)
		);
		$this->assertSame( 'draft', $out['post']['status'] );
	}

	public function test_force_draft_overrides_update_page_to_publish(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_force_draft', '1' );
		$pid = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_author' => $uid,
			)
		);
		$out = aafm_exec_update_page(
			array(
				'page_id' => $pid,
				'status'  => 'publish',
			)
		);
		$this->assertSame( 'draft', $out['post']['status'] );
	}

	public function test_force_draft_off_update_to_publish_still_publishes(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_force_draft', '0' ); // OFF (default) - update may publish.
		$id  = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $uid,
			)
		);
		$out = aafm_exec_update_post(
			array(
				'post_id' => $id,
				'status'  => 'publish',
			)
		);
		$this->assertSame( 'publish', $out['post']['status'] );
	}

	public function test_force_draft_on_update_without_status_does_not_unpublish(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_force_draft', '1' );
		$id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $uid,
			)
		);
		// No 'status' in the input - force-draft must not retro-unpublish an edit-only update.
		$out = aafm_exec_update_post(
			array(
				'post_id' => $id,
				'content' => 'Edited body only.',
			)
		);
		$this->assertSame( 'publish', $out['post']['status'] );
	}

	public function test_max_title_blocks_create_and_update(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_max_title_len', 5 );

		// Create over limit -> WP_Error.
		$this->assertInstanceOf( \WP_Error::class, aafm_exec_create_post( array( 'title' => 'TooLongTitle' ) ) );
		// Update over limit -> WP_Error.
		$id = self::factory()->post->create( array( 'post_author' => $uid ) );
		$this->assertInstanceOf(
			\WP_Error::class,
			aafm_exec_update_post(
				array(
					'post_id' => $id,
					'title'   => 'AlsoTooLong',
				)
			)
		);
		// Under limit create -> ok (has a 'post' key).
		$this->assertArrayHasKey( 'post', (array) aafm_exec_create_post( array( 'title' => 'Hi' ) ) );
	}

	public function test_max_title_boundary_is_inclusive(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_max_title_len', 5 );
		// Exactly 5 chars -> allowed (inclusive boundary).
		$this->assertArrayHasKey( 'post', (array) aafm_exec_create_post( array( 'title' => 'Hello' ) ) );
		// 6 chars -> rejected.
		$this->assertInstanceOf( \WP_Error::class, aafm_exec_create_post( array( 'title' => 'Hello!' ) ) );
	}

	public function test_max_title_blocks_create_page_and_update_page(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_max_title_len', 5 );

		$this->assertInstanceOf( \WP_Error::class, aafm_exec_create_page( array( 'title' => 'LongPageTitle' ) ) );
		$pid = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $uid,
			)
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			aafm_exec_update_page(
				array(
					'page_id' => $pid,
					'title'   => 'AlsoTooLong',
				)
			)
		);
	}

	public function test_max_title_off_allows_long_titles(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		// Zero is the default and disables the cap.
		update_option( 'aafm_max_title_len', 0 );
		$out = aafm_exec_create_post( array( 'title' => 'A Very Long Title That Would Otherwise Be Rejected' ) );
		$this->assertArrayHasKey( 'post', (array) $out );
	}

	public function test_max_title_update_without_title_is_unaffected(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_max_title_len', 5 );
		$id = self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_title'  => 'Existing Long Title',
			)
		);
		// Update only the content, no title field -> must NOT be rejected by max-title.
		$out = aafm_exec_update_post(
			array(
				'post_id' => $id,
				'content' => 'new body',
			)
		);
		$this->assertArrayHasKey( 'post', (array) $out );
	}

	public function test_max_title_counts_multibyte_correctly(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_max_title_len', 3 );
		// 3 multibyte chars = within a 3-char limit (mb_strlen=3), even though byte length > 3.
		$out = aafm_exec_create_post( array( 'title' => '今日は' ) ); // 3 CJK chars.
		$this->assertArrayHasKey( 'post', (array) $out );
		// 4 multibyte chars -> over a 3-char limit.
		$this->assertInstanceOf( \WP_Error::class, aafm_exec_create_post( array( 'title' => '今日はね' ) ) );
	}
}
