<?php
/**
 * Origin stamp for posts an agent starts.
 *
 * Agents write as ordinary WordPress users (an OAuth approver or an Application Password
 * account), so authorship cannot tell an agent draft from a human one. While an aafm/* or
 * aafm-bridge/* ability executes, a post inserted as a draft or pending item gets three protected
 * meta rows: which tool, which user, which OAuth client. The Needs review inbox reads them.
 * Underscore keys are protected (is_protected_meta(), the meta abilities' hard block, core's
 * edit_post_meta check), so no agent tool or REST meta write can set or clear them. Only creations are
 * stamped; an agent editing a human's draft does not turn it into an agent draft.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const AAFM_ORIGIN_TOOL_META   = '_aafm_agent_tool';
const AAFM_ORIGIN_USER_META   = '_aafm_agent_user';
const AAFM_ORIGIN_CLIENT_META = '_aafm_agent_client';

/**
 * Request-scoped stack of the ability names currently executing. Returned by reference so the
 * named helpers below share one copy; never call it directly.
 *
 * @return array<int,string>
 */
function &aafm_executing_abilities(): array {
	static $stack = array();
	return $stack;
}

/**
 * Mark an ability as executing. Called only by AAFM_Rate_Limited_Ability::execute().
 *
 * @param string $name Ability name.
 * @return void
 */
function aafm_executing_ability_push( string $name ): void {
	$stack   =& aafm_executing_abilities();
	$stack[] = $name;
}

/**
 * Mark the innermost executing ability as finished.
 *
 * @return void
 */
function aafm_executing_ability_pop(): void {
	$stack =& aafm_executing_abilities();
	array_pop( $stack );
}

/**
 * The innermost ability executing right now.
 *
 * @return string|null Null when none is.
 */
function aafm_executing_ability(): ?string {
	$stack = aafm_executing_abilities();
	if ( array() === $stack ) {
		return null;
	}

	return (string) end( $stack );
}

/**
 * Stamp a post an agent just created as a draft or pending item.
 *
 * @param int|string $post_id Post id.
 * @param mixed      $post    The post.
 * @param bool       $update  Whether this was an update of an existing post.
 * @return void
 */
function aafm_stamp_agent_origin( $post_id, $post, $update ): void {
	if ( $update || ! $post instanceof WP_Post || ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
		return;
	}
	$tool = aafm_executing_ability();
	if ( null === $tool ) {
		return;
	}
	$post_id = (int) $post_id;
	// Through the write contract (includes/write-contract.php), like every other meta write here.
	// Each key emits one write_outcome activity row, identifiers only. A failed stamp leaves the
	// post out of the inbox; it never blocks the post's creation.
	aafm_meta_set( 'post', $post_id, AAFM_ORIGIN_TOOL_META, $tool, $post->post_type );
	aafm_meta_set( 'post', $post_id, AAFM_ORIGIN_USER_META, (string) get_current_user_id(), $post->post_type );
	aafm_meta_set( 'post', $post_id, AAFM_ORIGIN_CLIENT_META, aafm_oauth_current_client_id(), $post->post_type );
}
add_action( 'wp_after_insert_post', 'aafm_stamp_agent_origin', 10, 3 );
