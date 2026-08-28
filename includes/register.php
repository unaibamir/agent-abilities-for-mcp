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
 * The single storage behind per-invocation audit-row correlation: a per-ability-name stack of
 * {token, row_id} frames. Returned BY REFERENCE so every function below shares the same
 * request-scoped state without each keeping its own copy. Never call this directly outside this
 * file - use the named functions that follow.
 *
 * Final-gate fix (Codex finding 1, its first review's proposal, not taken until now): a plain
 * per-name stack (no token) cannot tell whose frame is on top when the SAME ability is invoked
 * recursively - a wp_pre_execute_ability filter that itself calls the same ability is the
 * concrete case, but the Abilities API places no restriction on same-name nesting. Under the
 * name-only version, a nested call's own cleanup could discard the OUTER call's still-open frame:
 * the outer row was left stuck at 'started' forever while the outer callback, finding nothing
 * pending, opened and resolved a DUPLICATE row for the same call. A token turns every discard
 * into "is this specific frame mine," not merely "is there a frame for this name," which closes
 * the whole class of nesting bugs rather than the three exit paths (short-circuit,
 * normalize_input() failure, validate_input() failure) a name-only stack happened to be tested
 * against in fix round 1.
 *
 * @return array<string,array<int,array{token:string,row_id:int|null}>>
 */
function &aafm_invocation_stacks(): array {
	static $stacks = array();
	return $stacks;
}

/**
 * Open a new invocation frame for $name, before core does any work.
 *
 * Called ONLY by AAFM_Rate_Limited_Ability::execute(), once per execute() invocation, before
 * calling parent::execute(). The returned token is this specific invocation's identity: pass it
 * back to aafm_discard_invocation_if_mine() in that same method's finally, and nowhere else. A
 * nested same-ability call pushes its OWN frame on top of this one and is responsible for its own
 * token, never this one.
 *
 * @param string $name Ability name.
 * @return string A token unique to this invocation.
 */
function aafm_begin_invocation( string $name ): string {
	static $counter = 0;

	++$counter;
	$token             = $name . ':' . $counter;
	$stacks            =& aafm_invocation_stacks();
	$stacks[ $name ][] = array(
		'token'  => $token,
		'row_id' => null,
	);
	return $token;
}

/**
 * Attach the just-opened audit row id to the CURRENT invocation's frame.
 *
 * Called only from aafm_log_ability_invocation(), which core fires as the very first action
 * inside execute() - synchronously, immediately after aafm_begin_invocation() pushed this
 * invocation's own frame and before anything else for the same ability name could possibly run.
 * The top frame at this exact moment is therefore always this invocation's own by construction;
 * no token check is needed here; the check matters only later, in
 * aafm_discard_invocation_if_mine(), once a nested call has had the chance to run in between.
 *
 * @param string $name   Ability name.
 * @param int    $row_id The row id just opened for this invocation.
 * @return void
 */
function aafm_tag_current_invocation_row( string $name, int $row_id ): void {
	$stacks =& aafm_invocation_stacks();
	if ( empty( $stacks[ $name ] ) ) {
		return; // Defensive: no frame means execute() ran without the subclass, which never happens for our abilities.
	}
	$top           =& $stacks[ $name ][ count( $stacks[ $name ] ) - 1 ];
	$top['row_id'] = $row_id;
}

/**
 * Resolve (pop) the CURRENT invocation's own frame and return its row id.
 *
 * Called by the decorated permission callback's two denial-write sites and by the decorated
 * execute_callback, each exactly once, at the point where THIS invocation's own permission or
 * execute phase is running. By the time any of these run, any nested same-ability call that could
 * have happened earlier in this same invocation (during wp_pre_execute_ability, for example) has
 * already been fully pushed, resolved or discarded - so the top frame here is always this
 * invocation's own, and no token check is needed. That guarantee is exactly what
 * aafm_discard_invocation_if_mine()'s token check exists to protect: nesting only stays correctly
 * ordered because the nested call's own finally never touches a frame that is not its own.
 *
 * @param string $name Ability name.
 * @return int|null The row id, or null if nothing was pending (pre-7.1 core, where
 *                   wp_ability_invoked never fires and the frame's row_id was never tagged).
 */
function aafm_resolve_current_invocation_row( string $name ): ?int {
	$stacks =& aafm_invocation_stacks();
	if ( empty( $stacks[ $name ] ) ) {
		return null;
	}
	$frame = array_pop( $stacks[ $name ] );
	return $frame['row_id'];
}

/**
 * Discard the invocation frame for $name, but ONLY if it is still the one $token opened.
 *
 * Called from AAFM_Rate_Limited_Ability::execute()'s finally, once per execute() invocation,
 * after parent::execute() returns however it returns, passing the exact token
 * aafm_begin_invocation() returned for this same invocation. This is the ONE call site in this
 * file that must check whose frame it is looking at: by the time it runs, a nested same-ability
 * call may have already pushed and popped its own frame on top of this one and left THIS
 * invocation's frame exposed again, or this invocation's own frame may already have been resolved
 * by aafm_resolve_current_invocation_row() above. Either way, if the top frame's token is not
 * this one, it belongs to an outer invocation that has not finished yet, and touching it would be
 * exactly the corruption this token exists to prevent - so this leaves it strictly alone.
 *
 * When the token DOES match, the frame is discarded without touching the database row: if it
 * still carries a row id, that row stays exactly where the short-circuit/normalize-failure/
 * validate-failure path (or a rethrown permission crash) left it - stuck at 'started', the same
 * forensic signal every other unresolved-row path in this file already relies on. Only the
 * in-memory correlation is cleared, so it can never be inherited by a later, unrelated call for
 * the same ability name.
 *
 * @param string $name  Ability name.
 * @param string $token This invocation's own token, from aafm_begin_invocation().
 * @return void
 */
function aafm_discard_invocation_if_mine( string $name, string $token ): void {
	$stacks =& aafm_invocation_stacks();
	if ( empty( $stacks[ $name ] ) ) {
		return;
	}
	$top =& $stacks[ $name ][ count( $stacks[ $name ] ) - 1 ];
	if ( $top['token'] === $token ) {
		array_pop( $stacks[ $name ] );
	}
}

/**
 * Open a 'started' audit row for an invocation and tag it onto the frame
 * aafm_begin_invocation() already pushed, so the decorated permission/execute callbacks below
 * (or a later denial write) find and reuse it instead of opening a second row for the same call.
 *
 * Two callers, same row shape, covering every WP version this plugin supports:
 *
 * - aafm_log_ability_invocation() (below), hooked on wp_ability_invoked - WP 7.1+ only, fires
 *   before core does any work, so it also catches a wp_pre_execute_ability short-circuit.
 * - AAFM_Rate_Limited_Ability::execute() (includes/class-aafm-rate-limited-ability.php), for the
 *   WP 6.9 floor, where wp_ability_invoked never fires at all: without this fallback, a call core
 *   itself rejects before EITHER decorated callback ever runs - malformed input failing core's own
 *   validate_input() - left zero audit trace whatsoever on that floor, not even the stuck 'started'
 *   row 7.1+ leaves via the hook. See that class for the version gate.
 *
 * $input here is RAW and UNNORMALISED (core's own docblock on wp_ability_invoked says so
 * explicitly, and AAFM_Rate_Limited_Ability::execute() receives the same raw parameter core will
 * pass to normalize_input() next) - cast defensively before it reaches anything, the same way
 * every other call site in this file treats caller input. aafm_build_activity_detail()'s own
 * field-level checks (includes/audit/detail.php) already reject non-scalar values per key, so this
 * is safe once cast.
 *
 * Scoped to abilities THIS PLUGIN registered: aafm_remember_raw_permission() (read mode) already
 * remembers one for every ability aafm_register_ability_with_log() processes, so its presence is a
 * free "is this ours" test with no new bookkeeping. A foreign ability (native core, or a third
 * party) never reaches AAFM_Rate_Limited_Ability::execute() at all - that subclass is only ever
 * assigned inside aafm_register_ability_with_log() - so this scoping check matters only for the
 * wp_ability_invoked path, which fires for every registered ability's execute() call.
 *
 * A call that short-circuits or fails validation here never reaches the decorated execute_callback,
 * but that does not leak the per-call rate-limit token: AAFM_Rate_Limited_Ability::execute() already
 * wraps every exit from parent::execute() in a finally that releases it regardless.
 *
 * @param string $ability_name The ability name.
 * @param mixed  $input        Raw, unnormalized input.
 * @return void
 */
function aafm_open_pending_invocation_row( string $ability_name, $input ): void {
	if ( null === aafm_remember_raw_permission( $ability_name ) ) {
		return; // Not an ability this plugin registered through the choke point.
	}

	$call_args = is_array( $input ) ? $input : array();
	$user      = wp_get_current_user();

	$row_id = aafm_log_activity(
		array(
			'ability'           => $ability_name,
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
			'status'            => 'started',
			'arg_keys'          => array_keys( $call_args ),
			'client_id'         => aafm_oauth_current_client_id(),
			'detail'            => aafm_build_activity_detail( $ability_name, $call_args ),
		)
	);

	aafm_tag_current_invocation_row( $ability_name, $row_id );
}

/**
 * The wp_ability_invoked listener (WP 7.1+ only - see aafm_open_pending_invocation_row() above for
 * the pre-7.1 floor's equivalent). Fires as the very first action inside core's execute(), before
 * wp_pre_execute_ability gets a chance to short-circuit the rest of execute() and skip our
 * decorated execute_callback, which would otherwise be the ONLY place this plugin writes an audit
 * row. Closes a real visibility gap: a third party hooking wp_pre_execute_ability can otherwise
 * return a result to an MCP client with zero audit rows logged, even though our own permission
 * check already ran via the adapter's separate check_permission() call.
 *
 * @param string     $ability_name The ability name.
 * @param mixed      $input        Raw, unnormalized input.
 * @param WP_Ability $ability      The ability instance (unused; required by the hook signature).
 * @return void
 */
function aafm_log_ability_invocation( string $ability_name, $input, $ability ): void {
	unset( $ability );
	aafm_open_pending_invocation_row( $ability_name, $input );
}
add_action( 'wp_ability_invoked', 'aafm_log_ability_invocation', 10, 3 );

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
 * The per-call rate-limit memo. The MCP adapter calls check_permission() and then execute(), and
 * core's WP_Ability::execute() re-runs check_permissions(), so the decorated permission closure
 * fires TWICE for a single tools/call. Consuming a token on each fire halved every configured limit
 * (a limit of 60 delivered 30). The memo records the consume decision per ability for the span of
 * one call so the second fire reuses it; aafm_rate_limit_call_reset() clears it when the call
 * resolves or is denied, so the next call consumes fresh. The release sites, covering every way a
 * call can end (B12): a rate denial and a non-true permission result reset inline below; every
 * resolution of core's execute() - including the input-schema refusal that returns before the
 * decorated execute callback - resets via AAFM_Rate_Limited_Ability::execute()'s finally; a
 * consumer WP_Error on mcp_adapter_pre_tool_call resets via
 * aafm_release_rate_memo_on_aborted_tool_call(); and a rethrown permission crash resets before it
 * throws. Known residual, accepted: a third-party pre_tool_call filter that THROWS (rather than
 * returning WP_Error) propagates out of apply_filters() before the release hook runs, leaving the
 * memo stale for the rest of that request. That needs another server-side plugin hooking the
 * adapter's filter and crashing; an MCP client cannot reach it alone, and filter-land offers no
 * clean way to catch someone else's throw.
 *
 * @return array<string,bool> Reference to the request-scoped memo, keyed by ability name.
 */
function &aafm_rate_limit_call_memo(): array {
	static $memo = array();
	return $memo;
}

/**
 * Consume a rate-limit token at most once per tools/call (see aafm_rate_limit_call_memo()).
 *
 * @param int    $user_id Authenticated principal id.
 * @param string $name    Ability name (the memo key: a distinct tool consumes independently).
 * @return bool True when the call is within the limit.
 */
function aafm_rate_limit_consume_once( int $user_id, string $name ): bool {
	$memo = &aafm_rate_limit_call_memo();
	if ( array_key_exists( $name, $memo ) ) {
		return $memo[ $name ];
	}
	$allowed       = aafm_rate_limit_consume( $user_id );
	$memo[ $name ] = $allowed;
	return $allowed;
}

/**
 * Forget the per-call rate memo for one ability, so its next tools/call consumes a fresh token.
 *
 * @param string $name Ability name.
 * @return void
 */
function aafm_rate_limit_call_reset( string $name ): void {
	$memo = &aafm_rate_limit_call_memo();
	unset( $memo[ $name ] );
}

/**
 * Release a tool's rate memo, and audit the call, when a consumer filter aborts it.
 *
 * The adapter fires mcp_adapter_pre_tool_call AFTER its permission fire (which consumed a token and
 * memoized the allow) and BEFORE execute(); a WP_Error short-circuit there kills the call without
 * ever entering execute(), so it is the one dead-call path AAFM_Rate_Limited_Ability's finally
 * cannot see, and also the one path that never opens a 'started' row: wp_ability_invoked (which
 * aafm_log_ability_invocation() listens for) only fires from inside execute(), which this
 * short-circuit prevents from ever running. Without this handler a call our own permission check
 * ALLOWED, then a third party's pre_tool_call filter killed, left no trace anywhere: no rate-memo
 * release (finding B12, doc 167) and no audit row (the round-14 panel's finding 2, doc 214) - the
 * client sees an error, and the activity log shows nothing happened.
 *
 * Wired at PHP_INT_MAX in aafm_register_mcp_server() so it runs after the adapter's own permission
 * fire and sees a WP_Error short-circuit from any consumer whose filter registered before this one;
 * a same-priority consumer that registers later runs after this hook (core runs equal-priority
 * filters in registration order), so its short-circuit is not visible here. A pass-through (array)
 * result leaves the in-flight call's memo alone, since core's re-check inside execute() still needs
 * it, and writes no row - only an actual short-circuit is unaudited.
 *
 * The audit write is scoped to abilities THIS PLUGIN registered, the same "is this ours" test
 * aafm_log_ability_invocation() uses (aafm_remember_raw_permission() in its read-mode), because
 * get_observability_context()['ability_name'] is populated for ANY ability-backed MCP tool, not
 * only ours - a native WordPress ability or another plugin's could be wrapped by the same adapter.
 * The rate-memo reset above stays unscoped, unchanged from before this fix: it is a harmless no-op
 * against an ability name this plugin never memoized.
 *
 * @param mixed       $args      The filtered tool arguments, or a WP_Error short-circuit.
 * @param string      $tool_name The MCP tool name (unused: the memo is keyed by ability name, which
 *                               the adapter records in the tool's observability context).
 * @param object|null $mcp_tool  The adapter's tool instance for this call.
 * @return mixed The $args value, untouched.
 */
function aafm_release_rate_memo_on_aborted_tool_call( $args, $tool_name = '', $mcp_tool = null ) {
	unset( $tool_name );
	if ( is_wp_error( $args ) && $mcp_tool instanceof \WP\MCP\Domain\Tools\McpTool ) {
		$context = $mcp_tool->get_observability_context();
		$ability = isset( $context['ability_name'] ) ? (string) $context['ability_name'] : '';
		if ( '' !== $ability ) {
			aafm_rate_limit_call_reset( $ability );
			if ( null !== aafm_remember_raw_permission( $ability ) ) {
				aafm_log_aborted_tool_call( $ability, $args );
			}
		}
	}
	return $args;
}

/**
 * Write the audit row a call would otherwise never get when a third-party
 * mcp_adapter_pre_tool_call filter short-circuits it after this plugin's own permission check
 * already allowed it. See aafm_release_rate_memo_on_aborted_tool_call() for the seam this closes.
 *
 * Logged as 'error', not 'denied': this plugin's own permission check never refused the call, so
 * a 'denied' row would misattribute the outcome to a capability decision that never happened. It
 * is not resolving a pending 'started' row either - none exists yet at this seam, since
 * wp_ability_invoked (which opens one) only fires from inside execute(), which this short-circuit
 * prevented from ever running - so this always inserts a fresh row, the same shape
 * aafm_log_ability_invocation() would have opened had the call reached execute().
 *
 * The short-circuiting filter is third-party code (mcp_adapter_pre_tool_call is a globally
 * unscoped hook name any co-installed plugin bundling the same adapter can hook), so its WP_Error
 * is treated with the same caution aafm_build_activity_detail_from_result() applies to a bridged
 * ability's error: only the code is read, filtered through the same identifier allowlist
 * (aafm_activity_detail_field('key', ...)), and get_error_message() is never touched - a vendor or
 * third-party message routinely interpolates argument values, which this log never stores.
 *
 * @param string   $ability The ability this short-circuited call targeted.
 * @param WP_Error $error   The short-circuit result the consumer filter returned.
 * @return void
 */
function aafm_log_aborted_tool_call( string $ability, WP_Error $error ): void {
	$user   = wp_get_current_user();
	$code   = aafm_activity_detail_field( 'key', $error->get_error_code() );
	$detail = null !== $code
		? "aborted by mcp_adapter_pre_tool_call: {$code}"
		: 'aborted by mcp_adapter_pre_tool_call';

	$row_id = aafm_log_activity(
		array(
			'ability'           => $ability,
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
			'status'            => 'error',
			'client_id'         => aafm_oauth_current_client_id(),
			'detail'            => $detail,
		)
	);

	aafm_announce_ability_resolved( $row_id > 0 ? $row_id : null, 'error', null, $detail );
}

/**
 * The property names an ability's own input schema marks required.
 *
 * Read off the ability's declared schema, never from a list kept here, so this cannot drift from
 * what the ability actually asks for and cannot be wrong for one ability while right for the rest.
 * Only the JSON Schema draft-4 form is read - a `required` array on the object - which is the form
 * every ability in this plugin uses and the same one core reads first at rest-api.php:2387. The
 * older per-property `required => true` spelling is deliberately not read: no ability here uses it,
 * and a bridged wrapper carrying a foreign schema that does simply gets today's behaviour rather
 * than a rule nobody has measured against that vendor.
 *
 * @param array<string,mixed> $args Ability args as registered.
 * @return array<int,string> Required property names, possibly empty.
 */
function aafm_required_input_properties( array $args ): array {
	$schema = isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ? $args['input_schema'] : array();
	if ( ! isset( $schema['required'] ) || ! is_array( $schema['required'] ) ) {
		return array();
	}

	$required = array();
	foreach ( $schema['required'] as $property ) {
		$property = is_string( $property ) ? $property : '';
		if ( '' !== $property ) {
			$required[] = $property;
		}
	}
	return $required;
}

/**
 * The refusal for a call that omitted an argument the ability's schema requires.
 *
 * A WP_Error rather than false, which is the whole point and is worth being precise about, because
 * one line down the crash path deliberately returns false for the opposite reason.
 *
 * WHAT THIS FIXES. Core runs the two checks in the right order already: WP_Ability::execute()
 * validates input and only then checks permissions (class-wp-ability.php), and
 * rest_validate_object_value_from_schema() does enforce `required` (rest-api.php:2387-2396). The
 * MCP adapter does not. ToolsHandler calls the tool's check_permission() BEFORE execute() and turns
 * any non-true into the literal string "Permission denied", so a call that simply forgot an
 * argument never reaches validation and is answered as though it were a capability decision. Fifty
 * two of this plugin's abilities did that, measured by calling each raw permission callback with an
 * empty input while acting as an administrator, so that a denial could only come from the missing
 * argument. They are the ones whose callback resolves the object it is asked about: no post_id
 * means get_post( 0 ), which is null, which is false.
 *
 * WHY THAT IS WORTH FIXING AT ALL, given it moves no data. This plugin's whole proposition is that
 * an agent reads the error and corrects itself. "Permission denied" tells an agent to stop and
 * fetch a human. "post_id is required" tells it to fix the call and retry. One of those burns a
 * loop that would otherwise heal.
 *
 * FAIL-CLOSED. A WP_Error is a denial on every path that can see it: the Abilities API admits only
 * a strict true, the adapter treats anything else as a refusal, and this returns before the
 * permission callback is consulted at all, so it can never turn into an allow. It also cannot reach
 * core's execute(), which refuses to leak a permission error and would _doing_it_wrong() over one -
 * core validates first, so a call missing a required property is already rejected there before
 * permissions are consulted.
 *
 * WHY THE MESSAGE IS SAFE, unlike the crashed-callback message a few lines below. That one is
 * vendor text of unknown provenance and is deliberately swallowed so the adapter substitutes its
 * own wording. This one is built here out of property NAMES taken from the ability's own declared
 * schema. Those are plugin constants: identical on every install, published in the plugin source
 * and in the tool's advertised inputSchema, and independent of anything about the site. Nothing
 * about the caller's data, the site's configuration, or the reason they lack a capability can reach
 * it. That is the same distinction the ACF floors draw between a protected meta key, which says
 * something about the site and is refused generically, and a caller's own address, which does not.
 *
 * The error CODE matches the one core uses for the same condition, so a client keying on codes sees
 * one answer whichever path answered it.
 *
 * @param string            $name    Ability name.
 * @param array<int,string> $missing The required properties the call omitted.
 * @return \WP_Error
 */
function aafm_missing_input_error( string $name, array $missing ): WP_Error {
	return new WP_Error(
		'ability_invalid_input',
		sprintf(
			/* translators: 1: ability name, 2: comma-separated list of required property names. */
			__( 'Ability "%1$s" has invalid input. These required properties are missing: %2$s', 'agent-abilities-for-mcp' ),
			$name,
			implode( ', ', $missing )
		)
	);
}

/**
 * The WordPress-reserved post-meta keys an agent reaches for by name, and the ability that
 * actually does the job for each operation.
 *
 * Every key here is protected meta in stock WordPress, so aafm_hard_blocked_meta_key() refuses
 * it for an administrator exactly as it refuses it for a subscriber. That is what makes naming
 * the alternative safe: the answer depends on the key alone, never on who asked or on how the
 * site is configured.
 *
 * A route is only listed once the named ability has been read and confirmed to write the same
 * storage. `_wp_attachment_image_alt` is aafm/update-media's `alt` parameter
 * (includes/abilities/media.php:1129) and is a plain `alt` field on aafm/get-media-item's payload
 * (includes/helpers.php:1693). `_thumbnail_id` is what aafm/set-featured-image's
 * set_post_thumbnail() writes (includes/abilities/media.php:550) and what the post/page write
 * bundle's `featured_media` writes (includes/helpers.php:1229); it is read back as
 * `featured_image` (includes/helpers.php:1500).
 *
 * The empty delete route for `_thumbnail_id` is deliberate and load-bearing: nothing in this
 * plugin calls delete_post_thumbnail(), and set_post_thumbnail() with the bundle's 0 is a no-op,
 * so there is no ability that removes a featured image. An empty string means the refusal stops
 * after saying the key is unreachable rather than inventing a route, which is the same rule the
 * tool descriptions follow.
 *
 * @return array<string, array<string, string>> Canonical key => operation => sentence naming the route.
 */
function aafm_reserved_post_meta_routes(): array {
	return array(
		'_wp_attachment_image_alt' => array(
			'read'   => __( 'Call aafm-get-media-item, which returns the alt text as a plain "alt" field.', 'agent-abilities-for-mcp' ),
			'write'  => __( 'Call aafm-update-media and pass its "alt" parameter.', 'agent-abilities-for-mcp' ),
			'delete' => __( 'Call aafm-update-media with an empty "alt" to clear it.', 'agent-abilities-for-mcp' ),
		),
		'_thumbnail_id'            => array(
			'read'   => __( 'Call aafm-get-post or aafm-get-page, which return the "featured_image" object.', 'agent-abilities-for-mcp' ),
			'write'  => __( 'Call aafm-set-featured-image, or pass "featured_media" to aafm-update-post or aafm-update-page.', 'agent-abilities-for-mcp' ),
			'delete' => '',
		),
		'_regular_price'           => array(
			'read'   => __( 'Call aafm-wc-get-product, which returns it as a "regular_price" field.', 'agent-abilities-for-mcp' ),
			'write'  => __( 'Call aafm-wc-update-product and pass its "regular_price" parameter.', 'agent-abilities-for-mcp' ),
			'delete' => __( 'Call aafm-wc-update-product with an empty "regular_price" to clear it.', 'agent-abilities-for-mcp' ),
		),
		'_price'                   => array(
			'read'   => __( 'Call aafm-wc-get-product, which returns the active price as a "price" field.', 'agent-abilities-for-mcp' ),
			'write'  => __( 'This is WooCommerce\'s own computed cache, not directly settable; call aafm-wc-update-product with "regular_price" and/or "sale_price" instead, and WooCommerce recalculates it.', 'agent-abilities-for-mcp' ),
			'delete' => '',
		),
		'_yoast_wpseo_metadesc'    => array(
			'read'   => __( 'Call aafm-yoast-get-post, which returns it as a "description" field.', 'agent-abilities-for-mcp' ),
			'write'  => __( 'Call aafm-yoast-update-post and pass its "description" parameter.', 'agent-abilities-for-mcp' ),
			'delete' => __( 'Call aafm-yoast-update-post with an empty "description" to clear it.', 'agent-abilities-for-mcp' ),
		),
	);
}

/**
 * Answer a post-meta call that names a WordPress-reserved key with the route that does the job,
 * rather than letting it fall through to the adapter's bare "Permission denied".
 *
 * THE DEFECT. `_wp_attachment_image_alt` is where WordPress stores image alt text, so an agent
 * asked to fix alt text calls aafm-update-post-meta on it. It is protected meta, so
 * aafm_can_access_post_meta() returns a bare false, and the adapter turns any non-true into the
 * literal string "Permission denied" (ToolsHandler.php:150) before this plugin's own error
 * handling is ever reached. The agent concludes it lacks privileges, and stops. It does not lack
 * privileges; the key is not reachable through that tool at all, by anyone, and there is a
 * different tool that does exactly what it was asked to do.
 *
 * WHY THIS MESSAGE IS SAFE WHERE A CAPABILITY MESSAGE WOULD NOT BE. There are two reasons a meta
 * call gets refused, and only one of them may be explained. A hard-blocked key is refused
 * identically for every caller and tells the agent nothing it could not read in the tool's own
 * published inputSchema. A missing capability, or a key the operator simply has not allowlisted,
 * depends on who is asking and on this site's configuration; those keep the bare refusal they
 * have today and are not touched here. This function can only ever fire on the first kind: the
 * ability must be one of the three named below, the key must be one of the two literals in the
 * map, and aafm_hard_blocked_meta_key() must ALSO say so at call time. Nothing it reads varies by
 * caller, so the message cannot vary by caller either - pinned as byte equality across roles in
 * ReservedMetaKeyRouteTest rather than argued here.
 *
 * WHY THE HARD-BLOCK IS RE-CHECKED rather than trusted from the map. is_protected_meta() is a
 * filter, so a site can genuinely unprotect one of these keys and allowlist it, and on that site
 * the write works. Re-checking keeps the message truthful there instead of refusing a call that
 * would otherwise have succeeded: when the key is not blocked, this returns null and the path is
 * byte-identical to today's.
 *
 * FAIL-CLOSED, for the same reason aafm_missing_input_error() is. A WP_Error is a denial
 * everywhere it can be seen - the Abilities API admits only a strict true - so the worst this can
 * do is refuse something, never allow it. It returns BEFORE the permission callback for the same
 * reason R6-2 does: an answer that never consults the callback provably cannot depend on it, and
 * for a hard-blocked key the callback's answer is false anyway.
 *
 * WHERE THE MESSAGE ACTUALLY LANDS, and where it does not. The MCP path stops at the permission
 * verdict and renders it (ToolsHandler.php:149-166), which is the path the report came from and the
 * one this fixes. The direct and REST paths go through WP_Ability::execute(), which deliberately
 * refuses to relay a permission WP_Error - it _doing_it_wrong()s over one and returns its own
 * generic 'ability_invalid_permissions' instead. So a REST caller's answer is byte-for-byte what it
 * was before this existed, and the only difference on that path is a WP_DEBUG-only notice. That is
 * unlike R6-2, which core never reaches because core validates input before permissions; a reserved
 * key is valid input, so it does reach the gate. Accepted rather than dodged: giving the REST path
 * the same message would mean a second choke point inside execute(), which is more surface on this
 * plugin's most regression-sensitive code for a path nobody reported a problem with.
 *
 * @param string              $name  Ability name.
 * @param array<string,mixed> $input Call arguments.
 * @return \WP_Error|null The refusal, or null to leave the call on its normal path.
 */
function aafm_unreachable_meta_key_error( string $name, array $input ) {
	$operations = array(
		'aafm/get-post-meta'    => 'read',
		'aafm/update-post-meta' => 'write',
		'aafm/delete-post-meta' => 'delete',
	);
	if ( ! isset( $operations[ $name ] ) ) {
		return null;
	}

	// Guarded against a non-scalar, because this runs on raw caller input and a meta_key sent as
	// an array would fatal on the cast. Trimmed to match aafm_hard_blocked_meta_key(), which
	// compares on a trimmed copy for the PAD SPACE reason documented there.
	$raw = $input['meta_key'] ?? null; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- reading a call argument, not a meta query.
	$key = is_scalar( $raw ) ? trim( (string) $raw ) : '';
	if ( '' === $key ) {
		return null;
	}

	// Matched case-insensitively so a mixed-case spelling gets the same help, but the CANONICAL
	// spelling from the map is what goes into the message. Caller input never reaches the wire.
	$routes    = aafm_reserved_post_meta_routes();
	$canonical = '';
	foreach ( array_keys( $routes ) as $candidate ) {
		if ( 0 === strcasecmp( $candidate, $key ) ) {
			$canonical = $candidate;
			break;
		}
	}
	if ( '' === $canonical || ! aafm_hard_blocked_meta_key( $key ) ) {
		return null;
	}

	$route = (string) ( $routes[ $canonical ][ $operations[ $name ] ] ?? '' );
	$lead  = sprintf(
		/* translators: %s: the WordPress-reserved meta key the call named. */
		__( 'The meta key "%s" is protected by WordPress, so no user can reach it through this tool.', 'agent-abilities-for-mcp' ),
		$canonical
	);

	return new WP_Error( 'aafm_meta_key_unreachable', '' === $route ? $lead : $lead . ' ' . $route );
}

/**
 * The WordPress-reserved user-meta keys an agent reaches for by name, and the ability that
 * actually does the job. Mirrors aafm_reserved_post_meta_routes() exactly, but for user meta:
 * `wp_capabilities` is already hard-blocked outright (aafm_hard_blocked_user_meta_key()), and
 * this is additive UX on top of that existing block, not a new security boundary. Deliberately
 * scoped to this one key for now per planning doc 200 - a SHOULD-level wording improvement, not
 * a gap to close urgently.
 *
 * @return array<string, array<string, string>> Canonical key => operation => sentence naming the route.
 */
function aafm_reserved_user_meta_routes(): array {
	return array(
		'wp_capabilities' => array(
			'read'   => __( 'Call aafm-get-user, which returns the user\'s roles as a "roles" field.', 'agent-abilities-for-mcp' ),
			'write'  => __( 'Call aafm-update-user and pass its "role" parameter.', 'agent-abilities-for-mcp' ),
			'delete' => '',
		),
	);
}

/**
 * User-meta counterpart to aafm_unreachable_meta_key_error() - same shape, same fail-closed
 * re-check against the live hard-block, same operation-per-ability scoping, but reading the
 * `key` input the three user-meta abilities actually use (not `meta_key`) and checking
 * aafm_hard_blocked_user_meta_key() (not the post-scoped aafm_hard_blocked_meta_key()).
 *
 * @param string              $name  Ability name.
 * @param array<string,mixed> $input Call arguments.
 * @return \WP_Error|null The refusal, or null to leave the call on its normal path.
 */
function aafm_unreachable_user_meta_key_error( string $name, array $input ) {
	$operations = array(
		'aafm/get-user-meta'    => 'read',
		'aafm/update-user-meta' => 'write',
		'aafm/delete-user-meta' => 'delete',
	);
	if ( ! isset( $operations[ $name ] ) ) {
		return null;
	}

	$raw = $input['key'] ?? null;
	$key = is_scalar( $raw ) ? trim( (string) $raw ) : '';
	if ( '' === $key ) {
		return null;
	}

	$routes    = aafm_reserved_user_meta_routes();
	$canonical = '';
	foreach ( array_keys( $routes ) as $candidate ) {
		if ( 0 === strcasecmp( $candidate, $key ) ) {
			$canonical = $candidate;
			break;
		}
	}
	if ( '' === $canonical || ! aafm_hard_blocked_user_meta_key( $key ) ) {
		return null;
	}

	$route = (string) ( $routes[ $canonical ][ $operations[ $name ] ] ?? '' );
	$lead  = sprintf(
		/* translators: %s: the WordPress-reserved user-meta key the call named. */
		__( 'The user meta key "%s" is protected by WordPress, so no user can reach it through this tool.', 'agent-abilities-for-mcp' ),
		$canonical
	);

	return new WP_Error( 'aafm_user_meta_key_unreachable', '' === $route ? $lead : $lead . ' ' . $route );
}

/**
 * Register one ability through the audited, guarded, rate-limited choke point.
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

	if ( empty( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			esc_html(
				sprintf(
					/* translators: %s: ability name */
					__( 'Ability "%s" was not registered: an execute_callback is required.', 'agent-abilities-for-mcp' ),
					$name
				)
			),
			'1.6.2'
		);
		return null;
	}

	if ( str_starts_with( $name, 'aafm/' ) && ! isset( $args['meta']['annotations']['openWorldHint'] ) ) {
		$args['meta']['annotations']['openWorldHint'] = false;
	}

	// Cheap insurance (WP 7.1 findings, 2026-08-21): the bundled mcp-adapter only ever reads
	// meta.mcp.public from its DefaultServerFactory discovery path, which this plugin disables
	// entirely (mcp_adapter_create_default_server returns false, includes/bootstrap.php) in favor
	// of registering explicitly through create_server(). So this key is inert today - but stamping
	// it removes an implicit dependency on which discovery path a future bundled adapter version
	// defaults to. Applies to every ability this choke point registers, native and bridged alike,
	// and never overwrites a caller's own explicit value.
	if ( ! isset( $args['meta']['mcp']['public'] ) ) {
		$args['meta']['mcp']['public'] = true;
	}

	$original_permission = $args['permission_callback'];
	$original_execute    = $args['execute_callback'];
	// L5: only a list/read ability's result gets a logged magnitude - a write ability's return
	// shape is never guessed at. Read before the closures below capture it, so a plain scalar
	// is what gets captured rather than the whole (still-mutating) $args array.
	$is_read_ability = 'aafm-reads' === (string) ( $args['category'] ?? '' );
	// Read here, for the same reason and at the same moment as the line above: the closure captures
	// a plain array taken from the ability's OWN schema rather than the still-mutating $args.
	$required_input = aafm_required_input_properties( $args );

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

	// WP 7.1 note (delegation audit, not a code change): core's WP_Ability::check_permissions()
	// fires apply_filters('wp_ability_permission_result', ...) AFTER whatever this closure returns,
	// and a filter attached there can flip our denial into a grant - for both the adapter's
	// check_permission() call before execute() and core's own re-check inside execute(). This is
	// not exploitable by this plugin's own code (nothing here hooks that filter) and there is no
	// code fix available: a filter that runs after this closure returns can always override it, by
	// design of the hook, the same way current_user_can() has always been overridable via
	// map_meta_cap/user_has_cap. Decision (2026-08-22, delegation audit): ACCEPT and DOCUMENT rather
	// than attempt a third permission check that would only race the filter's own intent. See
	// .claude/planning/ROADMAP.md's decision log for the full reasoning.
	$args['permission_callback'] = static function ( $input = null ) use ( $original_permission, $name, $principal, $required_input ) {
		// Per-principal rate gate. Discovery (tools/list) is exempt automatically: it
		// uses the raw permission stored above, which never enters this decorated closure
		// and so never consumes a token. With the limit off (default) aafm_rate_limit_consume()
		// returns true, making this block a no-op - the path stays identical to today.
		$p         = $principal();
		$call_args = is_array( $input ) ? $input : array();
		if ( $p['principal_user_id'] > 0 && ! aafm_rate_limit_consume_once( $p['principal_user_id'], $name ) ) {
			// The call is refused, so start the next one on a fresh token rather than leaving this
			// denial memoized for the rest of the request.
			aafm_rate_limit_call_reset( $name );
			$rate_detail = aafm_build_activity_detail( $name, $call_args );
			// WP 7.1+: when this fire is core's internal re-check inside execute(), a 'started' row
			// is already pending from aafm_log_ability_invocation() (wp_ability_invoked fires before
			// ANY permission check). Resolve that same row instead of inserting a second one for the
			// same call. On the adapter's earlier check_permission() fire (pre-execute()) nothing is
			// pending yet, so this falls back to the original insert, unchanged. A popped row id that
			// no longer resolves to a real row (the log was cleared, or the pending stack outlived
			// its row for any other reason) also falls back to a fresh insert, rather than silently
			// dropping the denial - a stray extra row is a far cheaper mistake than an unaudited one.
			$rate_pending_row_id = aafm_resolve_current_invocation_row( $name );
			$rate_row_id         = null !== $rate_pending_row_id && aafm_update_activity_status( $rate_pending_row_id, 'denied', null, $rate_detail )
				? $rate_pending_row_id
				: aafm_log_activity(
					array_merge(
						$p,
						array(
							'ability'   => $name,
							'status'    => 'denied',
							'arg_keys'  => array_keys( $call_args ),
							'client_id' => aafm_oauth_current_client_id(),
							'detail'    => $rate_detail,
						)
					)
				);
			// A refused call is a call that finished, so it announces like any other resolve. A
			// landed row (inserted or resolved above) announces its real id, and a failed insert
			// announces null: same treatment as the execute tail, because a denial a monitor cannot
			// see while the audit table is failing is the same blind spot.
			if ( $rate_row_id > 0 ) {
				aafm_announce_ability_resolved( $rate_row_id, 'denied', null, $rate_detail );
			} else {
				aafm_announce_ability_resolved( null, 'denied', null, $rate_detail );
			}
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

		// A call that omitted a required argument is a schema failure, and it is answered as one
		// here rather than handed to the permission callback that would answer it as a capability
		// decision. Ordered AFTER the rate gate on purpose, so a flood of malformed calls still
		// costs a token, and BEFORE the callback so a malformed call is never asked "may you?" at
		// all. See aafm_missing_input_error() for the mechanism, the fail-closed argument, and why
		// this message is safe where the crashed-callback message below is not.
		//
		// The check is array_key_exists against the ability's own required list, and nothing wider.
		// Running the full schema validator here would be tidier and is the trap: the adapter and
		// core normalize input differently, so a wider check could refuse a call core would accept,
		// which is a real regression bought for a cosmetic gain. A required key that is absent is
		// absent under either normalization - AbilityArgumentNormalizer injects nothing and core's
		// normalize_input() applies no per-property defaults - so this is the one part that is safe
		// to answer early. Nothing that would have been executed is refused: core rejects every
		// input this refuses, at validate_input(), before permissions are consulted.
		$missing = array();
		foreach ( $required_input as $property ) {
			if ( ! array_key_exists( $property, $call_args ) ) {
				$missing[] = $property;
			}
		}
		// A call naming a WordPress-reserved meta key is answered the same way and for the same
		// reason: the refusal is structural, identical for every caller, and there is a different
		// tool that does the job. Ordered AFTER the missing-argument branch so R6-2's answer is
		// unchanged when a call is both malformed and reserved, and evaluated only on that branch
		// so a malformed call is never asked about its key. See aafm_unreachable_meta_key_error()
		// for why this discloses nothing a capability message would.
		// Two independent routing maps - post-meta and user-meta - each scoped to its own three
		// abilities by name, so checking both here is always exactly one real lookup and one
		// no-op, never both firing for the same call.
		$unreachable = array() === $missing
			? ( aafm_unreachable_meta_key_error( $name, $call_args ) ?? aafm_unreachable_user_meta_key_error( $name, $call_args ) )
			: null;

		if ( array() !== $missing ) {
			// No try/catch around this one: building the refusal cannot crash, and it falls through
			// to the shared non-true branch below, so the denial is audited, announced and has its
			// rate memo released exactly as every other refusal does rather than growing a second
			// copy of that bookkeeping.
			$allowed = aafm_missing_input_error( $name, $missing );
		} elseif ( null !== $unreachable ) {
			// Same bookkeeping route as the branch above, and for the same reason.
			$allowed = $unreachable;
		} else {
			try {
				$allowed = $original_permission( $input );
			} catch ( \Throwable $e ) {
				// Same operator switch as the execute-side catch, so one setting governs both phases.
				// Re-throwing here deliberately writes NO row: on the pre-7.1 floor there never was a
				// 'started' row on this path, so the absent row is this phase's version of the stuck
				// row the execute side leaves. On WP 7.1+, when this fire is core's internal re-check
				// inside execute(), a 'started' row MAY already be pending from
				// aafm_log_ability_invocation() - that row is deliberately left stuck rather than
				// resolved here (same reasoning as the execute-side rethrow). No explicit pop is
				// needed for the correlation stack: AAFM_Rate_Limited_Ability::execute()'s finally
				// discards any entry this call left dangling once parent::execute() returns, however
				// this throw resolves (core's own invoke_callback() converts it to a WP_Error before
				// it ever reaches that finally, but the finally runs on that normal return too).
				/** This filter is documented in includes/register.php, at the execute-side catch. */
				if ( apply_filters( 'aafm_rethrow_ability_exceptions', defined( 'WP_DEBUG' ) && WP_DEBUG, $e ) ) {
					// The consume above may have memoized an allow before the callback crashed, and this
					// throw skips the non-true reset below - release the memo here or the dead call's
					// allow pays for the next same-ability fire (the B12 leak, on its crash path).
					aafm_rate_limit_call_reset( $name );
					throw $e;
				}

				// Deny. The Abilities API admits only a strict true, so false is a denial on both the
				// 6.9 floor and 7.0+; and because it is NOT a WP_Error the adapter substitutes its own
				// generic 'Permission denied' (ToolsHandler.php:149) instead of echoing any string of
				// ours - the leak is closed by construction, not by careful wording.
				$crash_detail = aafm_build_activity_detail_from_exception( $e );
				$allowed      = false;
			}
		}

		// The WP Abilities API admits ONLY a strict true; every other return (false, WP_Error,
		// null, 0, '') is a denial. Audit any non-true result so a malformed or future permission
		// callback's denial is never silently unlogged.
		if ( true !== $allowed ) {
			// The call is refused at the capability check, so release the per-call rate memo: this
			// call consumed a token but will not reach execute (which is where a proceeding call's
			// memo is cleared), so clear it here or the ability would stop consuming for the request.
			aafm_rate_limit_call_reset( $name );
			// A crashed check records the throw site instead of the ability's mapped argument
			// detail: the defect is what matters on this row, and the mapped detail is already on
			// the ordinary-denial rows.
			$denied_detail = null !== $crash_detail ? $crash_detail : aafm_build_activity_detail( $name, $call_args );
			// WP 7.1+: when this fire is core's internal re-check inside execute(), a 'started' row
			// is already pending from aafm_log_ability_invocation() (wp_ability_invoked fires before
			// ANY permission check, including this one). Resolve that same row instead of inserting
			// a second one for the same call - otherwise the pending row is left stuck at 'started'
			// forever (execute_callback never runs on a denied call) while a separate 'denied' row
			// also exists, doubling the row count for one call. On the adapter's earlier
			// check_permission() fire (pre-execute()) nothing is pending yet, so this falls back to
			// the original insert, unchanged. A popped row id that no longer resolves to a real row
			// (the log was cleared, or the pending stack outlived its row for any other reason) also
			// falls back to a fresh insert, rather than silently dropping the denial - a stray extra
			// row is a far cheaper mistake than an unaudited one.
			$denied_pending_row_id = aafm_resolve_current_invocation_row( $name );
			$denied_row_id         = null !== $denied_pending_row_id && aafm_update_activity_status( $denied_pending_row_id, 'denied', null, $denied_detail )
				? $denied_pending_row_id
				: aafm_log_activity(
					array_merge(
						$p,
						array(
							'ability'   => $name,
							'status'    => 'denied',
							'arg_keys'  => array_keys( $call_args ),
							'client_id' => aafm_oauth_current_client_id(),
							'detail'    => $denied_detail,
						)
					)
				);
			// Announce, for the same reason the execute side does: a refused call has finished, and
			// a permission callback that exploded is the failure an operator most wants paged
			// about - it is the very class of crash the discovery guard in includes/server.php
			// exists to survive. Exactly one fire per call, because a denial returns false here and
			// the execute closure never runs. A failed insert announces with a null row_id rather
			// than staying silent - $denied_detail carries the throw site on a crashed check, so
			// the payload is the whole record when there is no row to join to.
			if ( $denied_row_id > 0 ) {
				aafm_announce_ability_resolved( $denied_row_id, 'denied', null, $denied_detail );
			} else {
				aafm_announce_ability_resolved( null, 'denied', null, $denied_detail );
			}
		}
		return $allowed;
	};

	$args['execute_callback'] = static function ( $input = null ) use ( $original_execute, $name, $principal, $is_read_ability ) {
		$call_args = is_array( $input ) ? $input : array();
		$arg_keys  = array_keys( $call_args );

		// This call passed both permission fires (the adapter's check_permission and core's re-check
		// inside execute) and is now proceeding, so release its per-call rate memo. The next
		// tools/call for this ability then consumes a fresh token instead of reusing this one's.
		aafm_rate_limit_call_reset( $name );

		// WP 7.1+: aafm_log_ability_invocation() (hooked on wp_ability_invoked) already opened a
		// 'started' row for this exact call before wp_pre_execute_ability could short-circuit past
		// this closure entirely. Reuse that row rather than open a second one for the same call.
		// Pre-7.1 core never fires that action, so the pop always returns null there and this falls
		// back to opening the row exactly as before.
		$row_id = aafm_resolve_current_invocation_row( $name );
		if ( null === $row_id ) {
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
		}

		// Null on a clean call; the crash's class and throw site once the catch below has written it.
		$crash_detail = null;
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
			$crash_detail = aafm_log_ability_exception( $row_id, $e );

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
		$status          = is_wp_error( $result ) ? 'error' : 'success';
		$resolved_detail = null !== $crash_detail ? $crash_detail : aafm_build_activity_detail_from_result( $name, $result );

		// This is deliberately a SECOND update on a crashed call - aafm_log_ability_exception()
		// above already resolved the row - and passing null for the detail is what keeps that
		// harmless. Collapsing the two writes would buy one query and put both the one-row-per-call
		// invariant and the crash detail's survival at risk, so it is not worth doing.
		//
		// A crash already wrote the exception's class and throw site. Passing null here leaves it in
		// place: the throw site names the actual defect, while 'aafm_ability_exception' only names
		// our own wrapper. Without this, the WP_Error branch of
		// aafm_build_activity_detail_from_result() would overwrite the throw site with that wrapper
		// code on every crashed call.
		$written = aafm_update_activity_status(
			$row_id,
			$status,
			$result_count,
			null !== $crash_detail ? null : $resolved_detail
		);

		// Announced from here rather than from inside the writer, and exactly once: this is the only
		// scope that knows both the final status and the detail the row ended up carrying. The
		// re-throw branch above never reaches this line, so a re-thrown crash announces nothing,
		// which matches the row it deliberately leaves at 'started'.
		//
		// The one silent case left is a POSITIVE row_id whose tail write failed: the row opened
		// but was pruned or cleared between the two writes (or the query itself failed). There is
		// no row left for a consumer to join to, so announcing would hand out a dangling id, and
		// the two sub-cases are not distinguishable here. That silence is deliberate and stays.
		if ( $written ) {
			aafm_announce_ability_resolved( $row_id, $status, $result_count, $resolved_detail );
		} elseif ( $row_id <= 0 ) {
			// The opening insert never landed, so there is no row to resolve and nothing to join to -
			// but the call still finished, and silence here is a crash a monitor cannot see, at
			// exactly the moment the audit table itself is failing. null, not 0: a consumer must be
			// able to tell "no row" from every real id. On a crash, $resolved_detail is already the
			// crash detail, so the announcement carries it. This reverses 1.6.1's announce-nothing
			// decision; the history lives on ResolveHookTest's null-row-id test.
			aafm_announce_ability_resolved( null, $status, $result_count, $resolved_detail );
		}

		return $result;
	};

	// The registry instantiates this subclass instead of WP_Ability so the per-call rate memo is
	// released however core's execute() resolves - including the input-schema refusal that returns
	// BEFORE the decorated execute callback ever runs (the B12 batch leak; see the subclass for the
	// full path list). A caller's own ability_class is honored; nothing in this plugin passes one.
	if ( ! isset( $args['ability_class'] ) && class_exists( 'AAFM_Rate_Limited_Ability' ) ) {
		$args['ability_class'] = AAFM_Rate_Limited_Ability::class;
	}

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
