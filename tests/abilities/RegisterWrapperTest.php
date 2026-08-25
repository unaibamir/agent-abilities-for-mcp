<?php
/**
 * Registration wrapper: permission enforcement + audit logging on success/error/deny.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class RegisterWrapperTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->ensure_categories();
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

	public function test_missing_permission_callback_is_refused(): void {
		$this->setExpectedIncorrectUsage( 'aafm_register_ability_with_log' );
		$ability = $this->register(
			'aafm/no-perm',
			array(
				'label'            => 'No Perm',
				'description'      => 'Should not register.',
				'category'         => 'aafm-reads',
				'output_schema'    => array( 'type' => 'object' ),
				'execute_callback' => static fn() => array(),
				// permission_callback intentionally omitted.
			)
		);
		$this->assertNull( $ability );
	}

	/**
	 * Same contract as the permission guard above: a registration missing its execute_callback
	 * must be refused with a named _doing_it_wrong and a null return, not read unguarded. Before
	 * the guard, the read at the top of the wrapper emitted an undefined-array-key diagnostic and
	 * the closure fataled later when it tried to call null - a confusing warning-then-fatal
	 * instead of a clear error.
	 */
	public function test_missing_execute_callback_is_refused(): void {
		$this->setExpectedIncorrectUsage( 'aafm_register_ability_with_log' );
		$ability = $this->register(
			'aafm/no-exec',
			array(
				'label'               => 'No Exec',
				'description'         => 'Should not register.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				// execute_callback intentionally omitted.
			)
		);
		$this->assertNull( $ability );
	}

	public function test_successful_call_logs_before_and_after(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/echo-ok',
			array(
				'label'               => 'Echo',
				'description'         => 'Returns ok.',
				'category'            => 'aafm-reads',
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'foo' => array( 'type' => 'string' ) ),
					'additionalProperties' => false,
				),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'permission_callback' => '__return_true',
			)
		);

		$result = wp_get_ability( 'aafm/echo-ok' )->execute( array( 'foo' => 'bar' ) );
		$this->assertSame( array( 'ok' => true ), $result );

		$rows = aafm_query_activity( array() );
		$this->assertCount( 1, $rows ); // started row updated in place - not a second row.
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( 'aafm/echo-ok', $rows[0]['ability'] );
		$this->assertSame( 'foo', $rows[0]['arg_keys'] ); // keys, not values.
	}

	public function test_denied_call_is_logged_as_denied(): void {
		$this->acting_as( 'subscriber' );
		$this->register(
			'aafm/needs-admin',
			array(
				'label'               => 'Needs Admin',
				'description'         => 'Admin only.',
				'category'            => 'aafm-writes',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'done' => true ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);

		$ability = wp_get_ability( 'aafm/needs-admin' );
		$allowed = $ability->check_permissions( array() );
		$this->assertFalse( $allowed );

		$rows = aafm_query_activity( array( 'status' => 'denied' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'aafm/needs-admin', $rows[0]['ability'] );
	}

	/**
	 * T3-4: a permission callback returning a non-true, non-false value (null) is still a
	 * denial - the WP Abilities API admits only true - so it must be audited as denied.
	 */
	public function test_null_permission_return_is_logged_as_denied(): void {
		$this->acting_as( 'subscriber' );
		$this->register(
			'aafm/null-perm',
			array(
				'label'               => 'Null Perm',
				'description'         => 'Permission callback returns null.',
				'category'            => 'aafm-writes',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'done' => true ),
				'permission_callback' => static fn() => null,
			)
		);

		$ability = wp_get_ability( 'aafm/null-perm' );
		$ability->check_permissions( array() );

		$rows      = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $rows, 'ability' );
		$this->assertContains( 'aafm/null-perm', $abilities, 'A null permission return must be audited as denied.' );
	}

	/**
	 * L5: the activity log recorded argument KEYS but never how much data a list/read call
	 * actually returned. A read ability whose result carries a 'total' key (the shape every
	 * list ability in this plugin already returns - see aafm_exec_get_posts()) logs that count.
	 */
	public function test_read_call_with_total_logs_result_count(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/list-things',
			array(
				'label'               => 'List things',
				'description'         => 'Returns a list with a total.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(
					'things' => array( 'a', 'b', 'c' ),
					'total'  => 3,
				),
				'permission_callback' => '__return_true',
			)
		);

		wp_get_ability( 'aafm/list-things' )->execute( null );

		$rows = aafm_query_activity( array() );
		$this->assertSame( 3, (int) $rows[0]['result_count'] );
	}

	/**
	 * Without an explicit 'total', the magnitude falls back to the length of the first
	 * sequential-list value in the result (the shape a simpler read ability might return).
	 */
	public function test_read_call_without_total_falls_back_to_list_length(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/list-no-total',
			array(
				'label'               => 'List, no total',
				'description'         => 'Returns a bare list.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'things' => array( 'a', 'b' ) ),
				'permission_callback' => '__return_true',
			)
		);

		wp_get_ability( 'aafm/list-no-total' )->execute( null );

		$rows = aafm_query_activity( array() );
		$this->assertSame( 2, (int) $rows[0]['result_count'] );
	}

	/**
	 * A write ability's result is never given a magnitude - only list/read calls are scoped in,
	 * so a write's return shape is never guessed at.
	 */
	public function test_write_call_never_logs_a_result_count(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/write-thing',
			array(
				'label'               => 'Write thing',
				'description'         => 'A write ability.',
				'category'            => 'aafm-writes',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'items' => array( 1, 2, 3 ) ),
				'permission_callback' => '__return_true',
			)
		);

		wp_get_ability( 'aafm/write-thing' )->execute( null );

		$rows = aafm_query_activity( array() );
		$this->assertNull( $rows[0]['result_count'] );
	}

	/**
	 * An errored call never logs a magnitude either - there is no result to measure.
	 */
	public function test_errored_read_call_never_logs_a_result_count(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/read-error',
			array(
				'label'               => 'Read error',
				'description'         => 'A read ability that errors.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => new \WP_Error( 'boom', 'boom' ),
				'permission_callback' => '__return_true',
			)
		);

		wp_get_ability( 'aafm/read-error' )->execute( null );

		$rows = aafm_query_activity( array() );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertNull( $rows[0]['result_count'] );
	}

	/**
	 * Every execute_callback is contracted to return array|WP_Error. That contract used to be a
	 * native PHP union return type on the ability functions themselves; removing it for the PHP
	 * 7.4 floor left static analysis as the only compile-time enforcement, so this wrapper must
	 * catch a wrong-shaped return at runtime instead. Before this guard, a non-array, non-WP_Error
	 * result flowed straight through and was logged as a SUCCESS, because status is decided by
	 * is_wp_error() alone - a silent wrong answer, not a loud one.
	 */
	public function test_malformed_result_is_converted_to_a_logged_error(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/read-malformed',
			array(
				'label'               => 'Read malformed',
				'description'         => 'A read ability whose callback returns the wrong shape.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => 'not-an-array-or-wp-error',
				'permission_callback' => '__return_true',
			)
		);

		$result = wp_get_ability( 'aafm/read-malformed' )->execute( null );

		$this->assertInstanceOf( \WP_Error::class, $result, 'a wrong-shaped return must never reach the caller as-is.' );
		$this->assertSame( 'aafm_malformed_ability_result', $result->get_error_code() );

		$rows = aafm_query_activity( array() );
		$this->assertSame( 'error', $rows[0]['status'], 'a wrong-shaped return must be logged as an error, never a success.' );
		$this->assertNull( $rows[0]['result_count'] );
	}

	/**
	 * M16: a plain (non-OAuth) call - no bearer ever resolved this request - logs client_id as ''.
	 */
	public function test_activity_log_has_no_client_id_for_a_non_oauth_call(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/echo-plain',
			array(
				'label'               => 'Echo plain',
				'description'         => 'Returns ok.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'permission_callback' => '__return_true',
			)
		);

		wp_get_ability( 'aafm/echo-plain' )->execute( null );

		$rows = aafm_query_activity( array() );
		$this->assertSame( '', $rows[0]['client_id'] );
	}

	/**
	 * M16: once an OAuth bearer has resolved a client_id for the request (see
	 * aafm_oauth_current_client_id() in includes/oauth/validator.php, populated at the end of
	 * aafm_oauth_resolve_current_user()), a successful ability call's activity row is attributed
	 * to that client. Calling the read-only recorder directly here stands in for a full bearer
	 * resolve - the wiring under test is register.php reading it back, not the OAuth resolve
	 * itself (that is covered end to end in ValidatorTest).
	 */
	public function test_activity_log_attributes_successful_call_to_resolved_oauth_client(): void {
		$this->acting_as( 'administrator' );
		aafm_oauth_current_client_id( 'agent_client_42' );
		$this->register(
			'aafm/echo-oauth',
			array(
				'label'               => 'Echo oauth',
				'description'         => 'Returns ok.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'permission_callback' => '__return_true',
			)
		);

		wp_get_ability( 'aafm/echo-oauth' )->execute( null );

		$rows = aafm_query_activity( array() );
		$this->assertSame( 'agent_client_42', $rows[0]['client_id'] );
	}

	/**
	 * M16: a denied call under a resolved OAuth client is attributed too, not only a success.
	 */
	public function test_activity_log_attributes_denied_call_to_resolved_oauth_client(): void {
		$this->acting_as( 'subscriber' );
		aafm_oauth_current_client_id( 'agent_client_42' );
		$this->register(
			'aafm/needs-admin-oauth',
			array(
				'label'               => 'Needs admin, oauth',
				'description'         => 'Admin only.',
				'category'            => 'aafm-writes',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'done' => true ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);

		wp_get_ability( 'aafm/needs-admin-oauth' )->check_permissions( array() );

		$rows = aafm_query_activity( array( 'status' => 'denied' ) );
		$this->assertSame( 'agent_client_42', $rows[0]['client_id'] );
	}

	public function test_categories_are_registered(): void {
		$this->assertInstanceOf( \WP_Ability_Category::class, wp_get_ability_category( 'aafm-reads' ) );
		$this->assertInstanceOf( \WP_Ability_Category::class, wp_get_ability_category( 'aafm-writes' ) );
	}

	/**
	 * A native aafm/* ability that declares no openWorldHint gets one defaulted to false:
	 * nothing native reaches an external system, so that is a true statement.
	 */
	public function test_native_ability_defaults_open_world_hint_to_false(): void {
		$this->register(
			'aafm/no-hint-declared',
			array(
				'label'               => 'No hint declared',
				'description'         => 'A native ability with no openWorldHint of its own.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		$annotations = wp_get_ability( 'aafm/no-hint-declared' )->get_meta_item( 'annotations' );
		$this->assertFalse( $annotations['openWorldHint'] );
	}

	/**
	 * A native ability that already declares its own openWorldHint keeps that value: the
	 * default only fills a gap, it never overwrites an explicit declaration.
	 */
	public function test_native_ability_with_explicit_open_world_hint_is_not_overwritten(): void {
		$this->register(
			'aafm/hint-declared',
			array(
				'label'               => 'Hint declared',
				'description'         => 'A native ability that already declares openWorldHint.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'annotations' => array(
						'readonly'      => true,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => true,
					),
				),
			)
		);

		$annotations = wp_get_ability( 'aafm/hint-declared' )->get_meta_item( 'annotations' );
		$this->assertTrue( $annotations['openWorldHint'] );
	}

	/**
	 * A bridged aafm-bridge/* wrapper never gets openWorldHint injected, declared or not: a
	 * third-party ability may genuinely reach outward and this plugin cannot vouch for it, so
	 * the key must stay absent rather than defaulted either way.
	 */
	public function test_bridged_wrapper_never_gets_open_world_hint_injected(): void {
		$this->register(
			'aafm-bridge/some-foreign-tool',
			array(
				'label'               => 'Some foreign tool',
				'description'         => 'A bridged wrapper around a third-party ability.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		$annotations = wp_get_ability( 'aafm-bridge/some-foreign-tool' )->get_meta_item( 'annotations' );
		$this->assertArrayNotHasKey( 'openWorldHint', $annotations );
	}

	/**
	 * Every ability registered through the choke point advertises meta.mcp.public = true, so
	 * this plugin's tool visibility never depends on which mcp-adapter discovery path a future
	 * bundled version defaults to (McpAbilityHelperTrait::is_mcp_ability_public() and
	 * DefaultServerFactory both read this key; this plugin bypasses that path today, but the key
	 * costs nothing and removes the implicit dependency).
	 */
	public function test_native_ability_gets_mcp_public_true_by_default(): void {
		$this->register(
			'aafm/no-mcp-meta-declared',
			array(
				'label'               => 'No mcp meta declared',
				'description'         => 'A native ability with no meta.mcp of its own.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(),
				'permission_callback' => '__return_true',
			)
		);

		$this->assertTrue( wp_get_ability( 'aafm/no-mcp-meta-declared' )->get_meta_item( 'mcp' )['public'] );
	}

	/**
	 * A caller that already declared meta.mcp.public keeps that value - the default only fills a
	 * gap, exactly like openWorldHint above.
	 */
	public function test_ability_with_explicit_mcp_public_false_is_not_overwritten(): void {
		$this->register(
			'aafm/mcp-public-declared',
			array(
				'label'               => 'MCP public declared',
				'description'         => 'A native ability that already declares meta.mcp.public.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(),
				'permission_callback' => '__return_true',
				'meta'                => array( 'mcp' => array( 'public' => false ) ),
			)
		);

		$this->assertFalse( wp_get_ability( 'aafm/mcp-public-declared' )->get_meta_item( 'mcp' )['public'] );
	}

	/**
	 * A bridged wrapper gets the same default: it is still a real tool this plugin exposes and
	 * governs, so it should not depend on adapter discovery internals either.
	 */
	public function test_bridged_wrapper_gets_mcp_public_true_by_default(): void {
		$this->register(
			'aafm-bridge/some-other-foreign-tool',
			array(
				'label'               => 'Some other foreign tool',
				'description'         => 'A bridged wrapper with no meta.mcp of its own.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(),
				'permission_callback' => '__return_true',
			)
		);

		$this->assertTrue( wp_get_ability( 'aafm-bridge/some-other-foreign-tool' )->get_meta_item( 'mcp' )['public'] );
	}
}
