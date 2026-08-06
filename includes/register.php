<?php
/**
 * Ability category registration + the audited registration wrapper.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the two ability categories.
 *
 * @return void
 */
function aafm_register_categories(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}
	if ( ! wp_has_ability_category( 'aafm-reads' ) ) {
		wp_register_ability_category(
			'aafm-reads',
			array(
				'label'       => __( 'Agent reads', 'agent-abilities-for-mcp' ),
				'description' => __( 'Read-only abilities exposed to AI agents.', 'agent-abilities-for-mcp' ),
			)
		);
	}
	if ( ! wp_has_ability_category( 'aafm-writes' ) ) {
		wp_register_ability_category(
			'aafm-writes',
			array(
				'label'       => __( 'Agent writes', 'agent-abilities-for-mcp' ),
				'description' => __( 'Guarded write abilities exposed to AI agents.', 'agent-abilities-for-mcp' ),
			)
		);
	}
}

/**
 * Remember (or read) an ability's undecorated permission callback.
 *
 * Acts as a tiny static store keyed by ability name. Passing a callable records it;
 * passing null returns the stored callback for that name (or null if unknown). This
 * lets the MCP tools/list filter test a connection's visibility without going through
 * the audited permission decorator, which would log a denial on every hidden tool.
 *
 * @param string        $name     Ability name.
 * @param callable|null $callback Callback to store, or null to read.
 * @return callable|null Stored callback when reading; null otherwise.
 */
function aafm_remember_raw_permission( string $name, ?callable $callback = null ): ?callable {
	static $store = array();

	if ( null !== $callback ) {
		$store[ $name ] = $callback;
		return null;
	}

	return $store[ $name ] ?? null;
}

/**
 * Best-effort magnitude of a list/read ability's result, for activity-log observability only.
 *
 * Every list ability in this plugin returns an integer 'total' key alongside its items (see
 * aafm_exec_get_posts() and its siblings) - that is authoritative and preferred, since it is the
 * full matched count, not just the page slice returned. Failing that, the length of the first
 * sequential-list value in the result is used as a fallback for a simpler shape. A single-object
 * "get" result (no list value at all) yields no magnitude - always logging 1 for those would add
 * noise, not information.
 *
 * @param mixed $result The ability's execute_callback return value (never a WP_Error - the
 *                       caller only invokes this on a successful result).
 * @return int|null The magnitude, or null when the result shape does not offer one.
 */
function aafm_result_magnitude( $result ): ?int {
	if ( ! is_array( $result ) ) {
		return null;
	}

	if ( isset( $result['total'] ) && is_int( $result['total'] ) ) {
		return $result['total'];
	}

	foreach ( $result as $value ) {
		if ( is_array( $value ) && array_values( $value ) === $value ) {
			return count( $value );
		}
	}

	return null;
}

/**
 * Register an ability with a guaranteed permission callback and full audit logging.
 *
 * Refuses to register without a callable permission_callback. Decorates the permission
 * callback to log denials and the execute callback to log before + after with real status.
 *
 * Also defaults the MCP `openWorldHint` annotation to false for our own `aafm/*` abilities,
 * unless the ability already declared a value. Nothing native makes an outbound network call
 * (verified: the plugin's one remote HTTP request is an admin-side loopback in
 * includes/admin/connection.php, not an ability), so false is a true statement about the whole
 * native set. A bridged `aafm-bridge/*` wrapper fronts a third-party ability we cannot vouch
 * for, so this never touches those; an absent key there still reads as "unknown, assume open"
 * per the MCP schema default, which is the honest state for a foreign ability.
 *
 * @param string              $name Ability name.
 * @param array<string,mixed> $args Ability args (per the Abilities API).
 * @return WP_Ability|null
 */
function aafm_register_ability_with_log( string $name, array $args ) {
	if ( empty( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			esc_html(
				sprintf(
					/* translators: %s: ability name */
					__( 'Ability "%s" was not registered: a permission_callback is required.', 'agent-abilities-for-mcp' ),
					$name
				)
			),
			'1.0.0'
		);
		return null;
	}

	if ( str_starts_with( $name, 'aafm/' ) && ! isset( $args['meta']['annotations']['openWorldHint'] ) ) {
		$args['meta']['annotations']['openWorldHint'] = false;
	}

	$original_permission = $args['permission_callback'];
	$original_execute    = $args['execute_callback'];
	// L5: only a list/read ability's result gets a logged magnitude - a write ability's return
	// shape is never guessed at. Read before the closures below capture it, so a plain scalar
	// is what gets captured rather than the whole (still-mutating) $args array.
	$is_read_ability = 'aafm-reads' === (string) ( $args['category'] ?? '' );

	// Stash the undecorated permission callback so list-time capability checks
	// (e.g. the MCP tools/list filter) can test visibility WITHOUT writing a
	// denied audit row for every tool a connection happens not to be able to call.
	aafm_remember_raw_permission( $name, $original_permission );

	$principal = static function (): array {
		$user = wp_get_current_user();
		return array(
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
		);
	};

	$args['permission_callback'] = static function ( $input = null ) use ( $original_permission, $name, $principal ) {
		// Per-principal rate gate. Discovery (tools/list) is exempt automatically: it
		// uses the raw permission stored above, which never enters this decorated closure
		// and so never consumes a token. With the limit off (default) aafm_rate_limit_consume()
		// returns true, making this block a no-op - the path stays identical to today.
		$p         = $principal();
		$call_args = is_array( $input ) ? $input : array();
		if ( $p['principal_user_id'] > 0 && ! aafm_rate_limit_consume( $p['principal_user_id'] ) ) {
			aafm_log_activity(
				array_merge(
					$p,
					array(
						'ability'   => $name,
						'status'    => 'denied',
						'arg_keys'  => array_keys( $call_args ),
						'client_id' => aafm_oauth_current_client_id(),
						'detail'    => aafm_build_activity_detail( $name, $call_args ),
					)
				)
			);
			return false;
		}

		// The permission phase gets the same floor as the execute phase below, and for the same
		// reason: $original_permission is third-party code on any bridged wrapper (bridge.php
		// delegates straight into a foreign ability's own callback), and even a native one runs
		// current_user_can(), whose map_meta_cap/user_has_cap filters any plugin can hook. Without
		// this the throw escapes into the adapter, which builds its error from
		// $throwable->getMessage() (McpTool.php:358-366) and sends that raw vendor text to the
		// client - the exact thing includes/audit/log.php forbids. Core's own execute() refuses to
		// leak a permission error (the permission branch of WP_Ability::execute()); the MCP path
		// never reaches that gate.
		$crash_detail = null;
		try {
			$allowed = $original_permission( $input );
		} catch ( \Throwable $e ) {
			// Same operator switch as the execute-side catch, so one setting governs both phases.
			// Re-throwing here deliberately writes NO row: there is no 'started' row on this path,
			// so the absent row is this phase's version of the stuck row the execute side leaves.
			/** This filter is documented in includes/register.php, at the execute-side catch. */
			if ( apply_filters( 'aafm_rethrow_ability_exceptions', defined( 'WP_DEBUG' ) && WP_DEBUG, $e ) ) {
				throw $e;
			}

			// Deny. The Abilities API admits only a strict true, so false is a denial on both the
			// 6.9 floor and 7.0+; and because it is NOT a WP_Error the adapter substitutes its own
			// generic 'Permission denied' (ToolsHandler.php:149) instead of echoing any string of
			// ours - the leak is closed by construction, not by careful wording.
			$crash_detail = aafm_build_activity_detail_from_exception( $e );
			$allowed      = false;
		}

		// The WP Abilities API admits ONLY a strict true; every other return (false, WP_Error,
		// null, 0, '') is a denial. Audit any non-true result so a malformed or future permission
		// callback's denial is never silently unlogged.
		if ( true !== $allowed ) {
			aafm_log_activity(
				array_merge(
					$p,
					array(
						'ability'   => $name,
						'status'    => 'denied',
						'arg_keys'  => array_keys( $call_args ),
						'client_id' => aafm_oauth_current_client_id(),
						// A crashed check records the throw site instead of the ability's mapped
						// argument detail: the defect is what matters on this row, and the mapped
						// detail is already on the ordinary-denial rows.
						'detail'    => null !== $crash_detail ? $crash_detail : aafm_build_activity_detail( $name, $call_args ),
					)
				)
			);
		}
		return $allowed;
	};

	$args['execute_callback'] = static function ( $input = null ) use ( $original_execute, $name, $principal, $is_read_ability ) {
		$call_args = is_array( $input ) ? $input : array();
		$arg_keys  = array_keys( $call_args );

		// One row at 'started' (intent), then updated in place with the real outcome -
		// one row per call, not two. A crash mid-execute leaves a visible 'started' row.
		$row_id = aafm_log_activity(
			array_merge(
				$principal(),
				array(
					'ability'   => $name,
					'status'    => 'started',
					'arg_keys'  => $arg_keys,
					'client_id' => aafm_oauth_current_client_id(),
					// Identifier-only, and only for an ability the detail allowlist names. Null for
					// everything else, which leaves the column exactly as it was before v5.
					'detail'    => aafm_build_activity_detail( $name, $call_args ),
				)
			)
		);

		$crash_detail_written = false;
		try {
			$result = $original_execute( $input );
		} catch ( \Throwable $e ) {
			// One catch for the whole catalog, not per-vendor-call-site. WooCommerce (and other
			// vendors) routinely use exceptions as ordinary input validation - WC_Data::error()
			// throws - and this codebase has 103 WooCommerce setter call sites against 2
			// individual try/catch blocks (Tasks 3 and 4). A future vendor release can add a
			// throw anywhere; this closure wraps every native AND bridged ability's
			// execute_callback, so it is the one place a new throw cannot slip past. On this
			// plugin's WP 6.9 floor WP_Ability::execute() has no Throwable guard of its own, so
			// without this an uncaught exception here is a raw 500 with no audit row at all.
			// Individual abilities still catch specific exceptions where a precise error code and
			// message help the caller (aafm_wc_duplicate_sku, aafm_wc_invalid_coupon_amount); this
			// is the floor beneath them, not a replacement for them.
			/**
			 * Whether a Throwable escaping an ability should be re-thrown instead of converted to
			 * a WP_Error.
			 *
			 * Defaults to WP_DEBUG, so a development site keeps programming errors loud while a
			 * production site converts them to an audited error. Since 1.6.1 this governs an
			 * ability's PERMISSION callback as well as its EXECUTE callback, so one switch covers
			 * both phases.
			 *
			 * Re-throwing deliberately leaves no resolved audit row: on the execute path the row
			 * stays at 'started', on the permission path no row is written at all. That absence is
			 * the forensic signal, and it is intentional - do not "fix" it.
			 *
			 * @since 1.6.0
			 * @param bool       $rethrow Whether to re-throw. Default WP_DEBUG.
			 * @param \Throwable $e       The caught throwable. @since 1.6.1
			 */
			$rethrow = apply_filters( 'aafm_rethrow_ability_exceptions', defined( 'WP_DEBUG' ) && WP_DEBUG, $e );
			if ( $rethrow ) {
				// Deliberately skips both the WP_Error below AND aafm_log_ability_exception(): the
				// row is left stuck at 'started', same as before this catch existed. That stuck row
				// is itself development's forensic signal something crashed uncaught; resolving it
				// here would erase the one thing this branch exists to keep loud.
				throw $e; // Keep programming errors loud in development.
			}

			// Record the exception's class and throw site onto the row this call already opened, so
			// a crash stays distinguishable from an ordinary validation error in the activity log -
			// the same signal the stuck 'started' row used to carry before this catch existed.
			aafm_log_ability_exception( $row_id, $e );
			$crash_detail_written = true;

			$result = new \WP_Error(
				'aafm_ability_exception',
				__( 'This ability could not complete because of an unexpected error. The site administrator can find the details in the activity log.', 'agent-abilities-for-mcp' )
			);
		}

		// Every NATIVE ability's execute_callback is contracted to return array|WP_Error. That
		// contract used to be a native PHP union return type on the ability functions
		// themselves, so a violation was an uncaught TypeError - a 500 with no audit row.
		// Removing those types for the PHP 7.4 floor (static analysis still enforces the
		// contract) moved the last line of defense here, to runtime. Without this check a
		// wrong-shaped result would flow straight through to the
		// `is_wp_error( $result ) ? 'error' : 'success'` line below and get logged as a
		// SUCCESS, since status is decided by is_wp_error() alone - a silent wrong answer is
		// strictly worse than the crash it replaces. Catch it here and turn it into a real,
		// logged, visible error instead.
		//
		// A bridged aafm-bridge/* wrapper is deliberately exempt: it fronts someone else's
		// ability, and core documents WP_Ability::execute() as returning mixed|WP_Error, a
		// wider contract than our own. Converting a foreign ability's legal scalar (e.g. `true`
		// after a successful write) into a WP_Error here would misreport a real success as an
		// error - the exact defect this guard exists to prevent, just aimed at code we do not
		// control. $name here is the ABILITY slug ('aafm-bridge/vendor-thing', built by
		// aafm_bridge_tool_name() in bridge.php with a slash separator) - a different string
		// from the MCP TOOL name aafm_filter_bridged_tool_call_result() checks in that same
		// file (dash-separated, after the adapter's own slug-to-tool-name transform), so the
		// separator here is intentionally '/' rather than that function's '-'.
		$is_bridged = str_starts_with( $name, AAFM_BRIDGE_NAMESPACE . '/' );
		if ( ! $is_bridged && ! is_array( $result ) && ! is_wp_error( $result ) ) {
			$result = new \WP_Error(
				'aafm_malformed_ability_result',
				__( 'This ability returned an unexpected result. Please try again or contact the site administrator.', 'agent-abilities-for-mcp' )
			);
		}

		// L5: a list/read call's magnitude is observability only - it is never used to alter
		// $result or the logged status, so a mis-shaped result simply logs no count.
		$result_count = ( $is_read_ability && ! is_wp_error( $result ) ) ? aafm_result_magnitude( $result ) : null;

		// A create's identifier only exists once the call returns, so the resolve carries it. Null
		// for every other ability, and null leaves whatever the insert wrote in place.
		//
		// This is deliberately a SECOND update on a crashed call - aafm_log_ability_exception()
		// above already resolved the row - and the flag is what keeps that harmless. Collapsing the
		// two writes would buy one query and put both the one-row-per-call invariant and the crash
		// detail's survival at risk, so it is not worth doing.
		//
		// A crash already wrote the exception's class and throw site. Passing null here leaves it in
		// place: the throw site names the actual defect, while 'aafm_ability_exception' only names
		// our own wrapper. Without the flag, the WP_Error branch of
		// aafm_build_activity_detail_from_result() would overwrite the throw site with that wrapper
		// code on every crashed call.
		aafm_update_activity_status(
			$row_id,
			is_wp_error( $result ) ? 'error' : 'success',
			$result_count,
			$crash_detail_written ? null : aafm_build_activity_detail_from_result( $name, $result )
		);

		return $result;
	};

	return wp_register_ability( $name, $args );
}

/**
 * Register every enabled ability from the registry on the Abilities API init pass.
 *
 * @return void
 */
function aafm_register_enabled_abilities(): void {
	$registry = aafm_get_abilities_registry();
	foreach ( aafm_get_enabled_abilities() as $name ) {
		// Idempotent: re-firing wp_abilities_api_init (in tests, or if another consumer
		// triggers the action) must not re-register and emit "already registered".
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			continue;
		}
		if ( empty( $registry[ $name ]['args_builder'] ) || ! is_callable( $registry[ $name ]['args_builder'] ) ) {
			continue;
		}
		$args = call_user_func( $registry[ $name ]['args_builder'] );
		aafm_register_ability_with_log( $name, $args );
	}
}
