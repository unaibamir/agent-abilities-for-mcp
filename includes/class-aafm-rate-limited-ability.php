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
 * A WP_Ability that always releases its per-call rate-limit memo when execute() resolves.
 *
 * The decorated permission callback (aafm_register_ability_with_log()) consumes a rate token on its
 * first fire and memoizes the allow so core's re-check inside execute() does not consume a second
 * token. The memo used to be released only inside the decorated EXECUTE callback - but core's
 * execute() refuses a schema-invalid input (and, on 7.0+, an output-schema violation or a callback
 * throw) BEFORE or INSTEAD OF that callback, leaving the allow memoized. The next tools/call for the
 * same ability in the same request then reused the stale allow and skipped its consume - a real
 * rate-limit bypass inside one JSON-RPC batch (finding B12, doc 167).
 *
 * Releasing in a finally around parent::execute() closes every in-execute path at once: input
 * refusal, the permission re-check, the callback itself, output refusal, and a rethrown crash. The
 * one dead-call path outside execute() - a consumer WP_Error on mcp_adapter_pre_tool_call - is
 * covered by aafm_release_rate_memo_on_aborted_tool_call() instead.
 */
class AAFM_Rate_Limited_Ability extends WP_Ability {

	/**
	 * Execute the ability, releasing the per-call rate memo however the call resolves.
	 *
	 * @param mixed $input Optional. The input data for the ability. Default `null`.
	 * @return mixed|WP_Error The result of the ability execution, or WP_Error on failure.
	 */
	public function execute( $input = null ) {
		try {
			return parent::execute( $input );
		} finally {
			aafm_rate_limit_call_reset( $this->get_name() );
		}
	}
}
