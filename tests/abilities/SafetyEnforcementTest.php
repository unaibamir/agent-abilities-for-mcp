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

	/**
	 * B38: IP-blocked transport denials must be bounded per source IP, the same way the sibling
	 * failed-app-password logger already is (AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW rows per window).
	 * Without the cap, an attacker holding a VALID credential from a blocked address can flood the
	 * 30-day activity-log table without limit, since every request writes its own denied row. The
	 * denial itself is never capped, only its log rows; a different source IP keeps its own cap.
	 */
	public function test_ip_blocked_denial_rows_are_capped_per_ip(): void {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $uid );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		update_option( 'aafm_ip_allowlist', array( '10.0.0.0/8' ) );

		for ( $i = 0; $i < AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW + 3; $i++ ) {
			$result = aafm_transport_permission_callback( null );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'aafm_ip_blocked', $result->get_error_code(), 'The denial itself must never be capped, only its audit rows.' );
		}

		$rows = aafm_query_activity( array( 'per_page' => 50 ) );
		$this->assertCount(
			AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW,
			$rows,
			'A single blocked IP must not be able to grow the log past the shared per-window cap.'
		);

		// A different source IP writes its own row: one attacker's flood must not crowd out a
		// genuine signal from a different address.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.78';
		$this->assertInstanceOf( \WP_Error::class, aafm_transport_permission_callback( null ) );
		$rows = aafm_query_activity( array( 'per_page' => 50 ) );
		$this->assertCount( AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW + 1, $rows );
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

		// B40 sweep: core routes case-insensitively, so an odd-cased MCP route reaches the
		// transport too - the guard must catch it, or the crash it closes comes back through
		// nothing but a capital letter.
		$odd_case = new \WP_REST_Request( 'POST', strtoupper( aafm_mcp_rest_route() ) );
		$odd_case->set_header( 'Content-Type', 'application/json' );
		$odd_case->set_body( '"x"' );
		$this->assertInstanceOf( \WP_Error::class, aafm_reject_scalar_mcp_body( null, null, $odd_case ), 'An odd-cased MCP route must not bypass the guard.' );
	}

	/**
	 * B39: a batch with non-object elements must get the JSON-RPC 2.0 answer, not a 500.
	 *
	 * The vendor's JsonRpcResponseBuilder treats any array with a 0 key as a batch and feeds each
	 * element into process_single_message(array $message); a non-array element ([1,2,3]) is a
	 * TypeError that the transport's blanket Throwable catch turns into a 500 internal_error. The
	 * spec (jsonrpc.org/specification, "rpc call with invalid Batch") says each invalid element
	 * gets its own {"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}
	 * response object. The pre-dispatch guard answers the all-invalid batch exactly that way; a
	 * mixed batch cannot be half-processed at this layer, so it is refused whole with one clean
	 * invalid-request error instead of crashing the vendor.
	 */
	public function test_batch_with_non_object_elements_is_answered_per_spec_not_crashed(): void {
		// The spec's own example: [1,2,3] -> three per-element invalid-request errors, HTTP 200
		// (the same status the vendor uses for every batch response).
		$batch = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$batch->set_header( 'Content-Type', 'application/json' );
		$batch->set_body( '[1,2,3]' );
		$result = aafm_reject_scalar_mcp_body( null, null, $batch );
		$this->assertInstanceOf( \WP_REST_Response::class, $result, 'An all-invalid batch must be answered, not passed to the vendor to crash on.' );
		$this->assertSame( 200, $result->get_status() );
		$element = '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}';
		$this->assertSame(
			'[' . $element . ',' . $element . ',' . $element . ']',
			wp_json_encode( $result->get_data() ),
			'Each invalid batch element must get its own spec-shaped invalid-request response object.'
		);

		// A mixed batch cannot be half-processed from rest_pre_dispatch, so it is refused whole
		// with a single clean invalid-request error (400, the vendor status for -32600) rather
		// than reaching the vendor TypeError.
		$mixed = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$mixed->set_header( 'Content-Type', 'application/json' );
		$mixed->set_body( '[{"jsonrpc":"2.0","method":"ping","id":1},2]' );
		$result = aafm_reject_scalar_mcp_body( null, null, $mixed );
		$this->assertInstanceOf( \WP_REST_Response::class, $result, 'A mixed batch must be refused cleanly, not crash the vendor.' );
		$this->assertSame( 400, $result->get_status() );
		$data = $result->get_data();
		$this->assertSame( -32600, $data['error']['code'] ?? null );

		// A well-formed batch of objects is not ours to answer: it must reach the vendor.
		$valid = new \WP_REST_Request( 'POST', aafm_mcp_rest_route() );
		$valid->set_header( 'Content-Type', 'application/json' );
		$valid->set_body( '[{"jsonrpc":"2.0","method":"ping","id":1},{"jsonrpc":"2.0","method":"ping","id":2}]' );
		$this->assertNull( aafm_reject_scalar_mcp_body( null, null, $valid ), 'A valid batch must pass through untouched.' );
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
				'execute_callback'    => static fn() => array( 'ok' => true ),
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

	/**
	 * B12 residual (Codex, doc 167): a call whose adapter-phase permission fire passes but whose
	 * input then fails core's schema validation dies INSIDE WP_Ability::execute(), before the
	 * decorated execute wrapper that used to be the only in-call release of the per-call rate memo.
	 * The stale allowed memo then let the NEXT tools/call for the same ability in the same request
	 * skip its consume, slipping past a rate limit of 1. AAFM_Rate_Limited_Ability::execute() now
	 * releases the memo however core resolves the call.
	 */
	public function test_schema_invalid_call_does_not_carry_its_rate_memo_into_the_next_call(): void {
		$uid = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_rate_limit_per_min', 1 );

		$executed = 0;
		$this->register(
			'aafm/rl-leak-probe',
			array(
				'label'               => 'RL Leak Probe',
				'description'         => 'Throwaway ability for the B12 stale-memo regression.',
				'category'            => 'aafm-reads',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'ok' ),
					'properties'           => array( 'ok' => array( 'type' => 'boolean' ) ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function () use ( &$executed ) {
					++$executed;
					return array( 'ok' => true );
				},
				'permission_callback' => '__return_true',
			)
		);
		$ability = wp_get_ability( 'aafm/rl-leak-probe' );

		// Call 1, malformed: the adapter-phase permission fire passes and consumes the only token...
		//
		// The malformed value satisfies `required` and fails on TYPE, deliberately. A call that
		// omits a required property is now refused at the permission fire itself and releases its
		// memo inline, which is a different route to the same guarantee and is pinned in its own
		// test below. This row has to keep exercising the ORIGINAL path, where the permission fire
		// passes and core is what rejects the input, or B12's actual regression stops being covered.
		$this->assertTrue( $ability->check_permissions( array( 'ok' => 'not-a-boolean' ) ) );
		// ...then core refuses the input on schema grounds before the execute wrapper ever runs.
		$this->assertInstanceOf( \WP_Error::class, $ability->execute( array( 'ok' => 'not-a-boolean' ) ) );
		$this->assertSame( 0, $executed, 'The malformed call must die at input validation, never reaching the execute callback.' );

		// Call 2, valid, same ability, same request: the only token is already spent, so this must
		// be denied. A stale memo from the dead call must not pay for it.
		$this->assertFalse(
			$ability->check_permissions( array( 'ok' => true ) ),
			'A schema-invalid call must not gift its rate token to the next same-ability call in the batch.'
		);
		$this->assertSame( 0, $executed );
	}

	/**
	 * The same B12 guarantee for the other route: a call that OMITS a required argument.
	 *
	 * This one is refused at the permission fire rather than by core, because the fire now answers
	 * a missing required property as the schema failure it is instead of handing it to the
	 * permission callback. It consumes a token first, exactly as before, so a flood of malformed
	 * calls still costs something, and then releases its memo through the shared non-true branch.
	 * If that release were ever lost the dead call would pay for the next one, which is the leak
	 * B12 was.
	 */
	public function test_a_call_missing_a_required_argument_releases_its_rate_memo(): void {
		$uid = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_rate_limit_per_min', 1 );

		$executed = 0;
		$this->register(
			'aafm/rl-missing-arg-probe',
			array(
				'label'               => 'RL Missing Arg Probe',
				'description'         => 'Throwaway ability for the missing-argument refusal path.',
				'category'            => 'aafm-reads',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'ok' ),
					'properties'           => array( 'ok' => array( 'type' => 'boolean' ) ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function () use ( &$executed ) {
					++$executed;
					return array( 'ok' => true );
				},
				'permission_callback' => '__return_true',
			)
		);
		$ability = wp_get_ability( 'aafm/rl-missing-arg-probe' );

		$refused = $ability->check_permissions( array() );
		$this->assertInstanceOf( \WP_Error::class, $refused, 'A missing required argument is answered as a schema failure.' );
		$this->assertSame( 'ability_invalid_input', $refused->get_error_code() );

		// The token was still spent, and then released: the next valid call is denied by the limit,
		// not gifted the dead call's allow.
		$this->assertFalse(
			$ability->check_permissions( array( 'ok' => true ) ),
			'A malformed call must not gift its rate token to the next same-ability call.'
		);
		$this->assertSame( 0, $executed );
	}

	/**
	 * B12 sweep: mcp_adapter_pre_tool_call may short-circuit a call with a WP_Error AFTER the
	 * adapter's permission fire consumed a token but BEFORE execute() runs - the one dead-call path
	 * core's execute() cannot see. The abort hook must release the stale memo so the next
	 * same-ability call consumes fresh, and a pass-through (non-error) filter result must leave the
	 * in-flight call's memo alone or core's re-check would consume a second token per call.
	 */
	public function test_aborted_pre_tool_call_releases_the_rate_memo(): void {
		$uid = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_rate_limit_per_min', 1 );

		$probe = static function (): array {
			return array(
				'label'               => 'RL Abort Probe',
				'description'         => 'Throwaway ability for the pre_tool_call abort sweep.',
				'category'            => 'aafm-reads',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'permission_callback' => '__return_true',
			);
		};
		// Control first: a pass-through filter result must NOT touch the in-flight memo. The
		// second permission fire (core's re-check) must reuse the memoized allow instead of
		// consuming the empty bucket.
		$this->register( 'aafm/rl-pass-probe', $probe() );
		$pass      = wp_get_ability( 'aafm/rl-pass-probe' );
		$pass_tool = \WP\MCP\Domain\Tools\McpTool::fromAbility( $pass );
		$this->assertTrue( $pass->check_permissions( array() ) );
		$args = aafm_release_rate_memo_on_aborted_tool_call( array( 'a' => 1 ), 'aafm-rl-pass-probe', $pass_tool );
		$this->assertSame( array( 'a' => 1 ), $args, 'A pass-through result must come back unchanged.' );
		$this->assertTrue( $pass->check_permissions( array() ), 'The in-flight call memo must survive a pass-through filter.' );

		// The abort: a fresh principal gets a fresh bucket. The permission fire consumes the only
		// token, the filter short-circuits the call, and the released memo means the next call
		// consumes fresh - and finds the bucket empty.
		$uid2 = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $uid2 );
		$this->register( 'aafm/rl-abort-probe', $probe() );
		$abort      = wp_get_ability( 'aafm/rl-abort-probe' );
		$abort_tool = \WP\MCP\Domain\Tools\McpTool::fromAbility( $abort );
		$this->assertTrue( $abort->check_permissions( array() ) );
		$error = new \WP_Error( 'blocked', 'Blocked by a consumer filter.' );
		$this->assertSame( $error, aafm_release_rate_memo_on_aborted_tool_call( $error, 'aafm-rl-abort-probe', $abort_tool ) );
		$this->assertFalse(
			$abort->check_permissions( array() ),
			'An aborted call must not leave its allowed memo behind for the next same-ability call.'
		);

		// A malformed hook payload (no tool object) must pass through without a fatal.
		$this->assertSame( array(), aafm_release_rate_memo_on_aborted_tool_call( array(), 'aafm-rl-abort-probe', null ) );
	}

	/**
	 * B12 sweep: a permission callback that crashes while the rethrow switch is on escapes the
	 * decorated closure AFTER the consume memoized an allow. The catch must release the memo before
	 * rethrowing, or the next same-ability fire reuses the dead call's allow instead of consuming.
	 */
	public function test_rethrown_permission_crash_releases_the_rate_memo(): void {
		$uid = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $uid );
		update_option( 'aafm_rate_limit_per_min', 1 );
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_true' );

		$throws = 0;
		$this->register(
			'aafm/rl-throw-probe',
			array(
				'label'               => 'RL Throw Probe',
				'description'         => 'Throwaway ability whose permission crashes once.',
				'category'            => 'aafm-reads',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'permission_callback' => static function () use ( &$throws ) {
					if ( 0 === $throws ) {
						++$throws;
						throw new \RuntimeException( 'permission boom' );
					}
					return true;
				},
			)
		);
		$ability = wp_get_ability( 'aafm/rl-throw-probe' );

		// On the 6.9 floor the rethrow escapes check_permissions(); 7.0's invoke_callback() catches
		// it and converts to a WP_Error. Either way the crashed fire must not answer an allow - and
		// either way it skipped the closure's non-true reset, which is the leak under test.
		$first = null;
		try {
			$first = $ability->check_permissions( array() );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'permission boom', $e->getMessage() );
		}
		if ( null !== $first ) {
			$this->assertNotTrue( $first, 'The crashed fire must not answer an allow.' );
		}

		// The crashed fire consumed the only token. With the memo released, this fire consumes
		// fresh and finds the bucket empty; with a stale memo it would wrongly answer true.
		$this->assertFalse(
			$ability->check_permissions( array() ),
			'A rethrown permission crash must not leave its allowed memo behind.'
		);
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
				'execute_callback'    => static fn() => array( 'ok' => true ),
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
				'execute_callback'    => static fn() => array( 'ok' => true ),
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
