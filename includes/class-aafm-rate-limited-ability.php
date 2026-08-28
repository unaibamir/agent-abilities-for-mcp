<?php
/**
 * The ability subclass whose execute() releases the per-call rate memo however a call resolves.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Ability' ) ) {
	return; // Abilities API absent (WP below the 6.9 floor): nothing registers, so nothing needs the subclass.
}

/**
 * A WP_Ability that always releases its per-call rate-limit memo and any dangling audit-log
 * correlation when execute() resolves, however it resolves.
 *
 * The decorated permission callback (aafm_register_ability_with_log()) consumes a rate token on its
 * first fire and memoizes the allow so core's re-check inside execute() does not consume a second
 * token. The memo used to be released only inside the decorated EXECUTE callback - but core's
 * execute() refuses a schema-invalid input (and, on 7.0+, an output-schema violation or a callback
 * throw) BEFORE or INSTEAD OF that callback, leaving the allow memoized. The next tools/call for the
 * same ability in the same request then reused the stale allow and skipped its consume - a real
 * rate-limit bypass inside one JSON-RPC batch (finding B12, doc 167).
 *
 * Fix round 1 (Codex finding 1) added the second responsibility: on WP 7.1+, wp_ability_invoked
 * (includes/register.php, aafm_log_ability_invocation()) pushes a pending audit row onto a per-name
 * correlation stack the instant execute() begins, before core does any work. An intentional
 * wp_pre_execute_ability short-circuit, a normalize_input() failure, or a validate_input() failure
 * all return from execute() without ever reaching a denial write or the decorated execute_callback,
 * so that entry would otherwise sit on the stack until a LATER, unrelated call for the same ability
 * name - denied at a preliminary permission check that never goes through execute() at all - popped
 * and resolved it instead of writing its own row, misattributing the second call's outcome onto the
 * first and leaving the second with no row of its own.
 *
 * Final-gate fix (Codex finding 1, round 2): the fix round 1 version discarded whatever frame was
 * on top of the per-name stack, with no way to tell whose frame that was. That is exactly wrong
 * when the SAME ability is invoked recursively - a wp_pre_execute_ability filter that itself calls
 * the same ability, which the Abilities API does not forbid. The nested call's own cleanup would
 * discard the OUTER call's still-open frame: the outer row was left stuck at 'started' forever
 * while the outer callback, finding nothing pending, opened and resolved a duplicate row.
 * aafm_begin_invocation() now hands this specific execute() call a unique token before
 * parent::execute() runs, and the finally passes that SAME token to
 * aafm_discard_invocation_if_mine(), which discards the top frame only when it is still the one
 * this token opened - never a nested or outer invocation's frame. See that function's own docblock
 * in includes/register.php for the full mechanism and why the other consumers of this stack
 * (the decorated permission and execute callbacks) do not need the same check.
 *
 * Releasing both in a finally around parent::execute() closes every in-execute path at once, for
 * both concerns: input refusal, the permission re-check, the callback itself, output refusal, and a
 * rethrown crash. The one dead-call path outside execute() - a consumer WP_Error on
 * mcp_adapter_pre_tool_call - is covered by aafm_release_rate_memo_on_aborted_tool_call() instead;
 * that path never reaches wp_ability_invoked either, so it never has a pending row to discard.
 *
 * WP 6.9 floor gap (found in the 1.7.1 CI review, 2026-08-27): wp_ability_invoked and
 * wp_pre_execute_ability are both `@since 7.1.0` in core - absent entirely below that floor, not
 * merely quieter. Without a fallback, a call core itself rejects before EITHER decorated callback
 * ever runs - malformed input failing core's own validate_input() - left ZERO audit trace at all on
 * 6.9, where 7.1+ at least leaves the stuck 'started' row wp_ability_invoked opens. This class
 * already runs unconditionally, on every WP version this plugin supports, before parent::execute()
 * does any work - exactly the choke point wp_ability_invoked exists to approximate for cores that
 * have it. So execute() below opens that same row directly, via the function
 * aafm_log_ability_invocation() itself calls, whenever the running core lacks the 7.1 surface.
 * class_exists( 'WP_Filter_Sentinel' ) is the capability probe: that class is core's own default
 * sentinel for the wp_pre_execute_ability filter, introduced in the exact same 7.1.0 release as
 * both hooks, so it is a reliable stand-in for "does this core fire wp_ability_invoked" - reliable
 * across a future core bump the way a hardcoded version-number comparison would not be. On any core
 * that has it (this plugin's 7.1 ceiling and beyond), the branch below is a no-op and
 * wp_ability_invoked keeps doing this exact job exactly as before - zero behavior change there.
 */
class AAFM_Rate_Limited_Ability extends WP_Ability {

	/**
	 * Execute the ability, releasing the per-call rate memo and any dangling audit-log correlation
	 * however the call resolves.
	 *
	 * @param mixed $input Optional. The input data for the ability. Default `null`.
	 * @return mixed|WP_Error The result of the ability execution, or WP_Error on failure.
	 */
	public function execute( $input = null ) {
		$invocation_token = aafm_begin_invocation( $this->get_name() );
		if ( ! class_exists( 'WP_Filter_Sentinel' ) ) {
			// Pre-7.1 core: wp_ability_invoked will never fire for this call, so open the same
			// pending row here ourselves, before parent::execute() does any work. See the class
			// docblock above for why this check gates cleanly on every core this plugin supports.
			aafm_open_pending_invocation_row( $this->get_name(), $input );
		}
		try {
			return parent::execute( $input );
		} finally {
			aafm_rate_limit_call_reset( $this->get_name() );
			aafm_discard_invocation_if_mine( $this->get_name(), $invocation_token );
		}
	}
}
