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

/**
 * Posts and terms an ability wrote meta to during this request. True while the object's meta is
 * newer than its last real save. Returned by reference; internal.
 *
 * @return array{post:array<int,bool>,term:array<int,bool>}
 */
function &aafm_meta_touched(): array {
	static $touched = array(
		'post' => array(),
		'term' => array(),
	);
	return $touched;
}

/**
 * Note a meta write made while an ability executes. A theme or another plugin writing meta is not ours to refresh.
 *
 * @param string     $kind      'post' or 'term'.
 * @param int|string $object_id Object id.
 * @return void
 */
function aafm_collect_meta_touch( string $kind, $object_id ): void {
	if ( null === aafm_executing_ability() ) {
		return;
	}
	$touched                              =& aafm_meta_touched();
	$touched[ $kind ][ (int) $object_id ] = true;
}

/**
 * A real save already did what a save does, so the object is clean again until more meta lands.
 *
 * @param string     $kind      'post' or 'term'.
 * @param int|string $object_id Object id.
 * @return void
 */
function aafm_forget_meta_touch( string $kind, $object_id ): void {
	$touched =& aafm_meta_touched();
	if ( isset( $touched[ $kind ][ (int) $object_id ] ) ) {
		$touched[ $kind ][ (int) $object_id ] = false;
	}
}

foreach ( array( 'post', 'term' ) as $aafm_kind ) {
	foreach ( array( 'added', 'updated', 'deleted' ) as $aafm_verb ) {
		add_action(
			"{$aafm_verb}_{$aafm_kind}_meta",
			static function ( $meta_id, $object_id ) use ( $aafm_kind ): void {
				aafm_collect_meta_touch( $aafm_kind, $object_id );
			},
			10,
			2
		);
	}
}
unset( $aafm_kind, $aafm_verb );
add_action(
	'save_post',
	static function ( $post_id ): void {
		aafm_forget_meta_touch( 'post', $post_id );
	}
);
add_action(
	'edited_term',
	static function ( $term_id ): void {
		aafm_forget_meta_touch( 'term', $term_id );
	}
);

/**
 * After the outermost ability finishes: clear the cache entry of each published, viewable post or
 * term whose meta changed after its last save, and fire aafm_agent_meta_written for each.
 *
 * The default never re-saves, so the modified date does not move and no save_post listener runs.
 * A site whose page cache listens only to save_post opts in with the aafm_meta_write_full_resave
 * filter. A listener that throws cannot turn a successful write into an error.
 *
 * @return void
 */
function aafm_refresh_after_meta_writes(): void {
	$touched =& aafm_meta_touched();
	$posts   = array_keys( array_filter( $touched['post'] ) );
	$terms   = array_keys( array_filter( $touched['term'] ) );
	$touched = array(
		'post' => array(),
		'term' => array(),
	);
	try {
		foreach ( $posts as $post_id ) {
			$post = aafm_exact_object_chain( 'post', $post_id );
			if ( $post instanceof WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
				/**
				 * Whether to re-save a post or term after an agent changed only its custom fields.
				 *
				 * @param bool   $full Default false: clear the cache entry only.
				 * @param string $kind 'post' or 'term'.
				 * @param int    $id   Object id.
				 */
				if ( (bool) apply_filters( 'aafm_meta_write_full_resave', false, 'post', $post_id ) ) {
					wp_update_post( array( 'ID' => $post_id ) );
				} else {
					clean_post_cache( $post_id );
				}
			}
			/**
			 * Fires after an agent call changed only the custom fields of a post or term.
			 *
			 * @param string $kind 'post' or 'term'.
			 * @param int    $id   Object id.
			 */
			do_action( 'aafm_agent_meta_written', 'post', $post_id );
		}
		foreach ( $terms as $term_id ) {
			$term = aafm_exact_object_chain( 'term', $term_id );
			if ( $term instanceof WP_Term && is_taxonomy_viewable( $term->taxonomy ) ) {
				/** This filter is documented above. */
				if ( (bool) apply_filters( 'aafm_meta_write_full_resave', false, 'term', $term_id ) ) {
					wp_update_term( $term_id, $term->taxonomy );
				} else {
					clean_term_cache( $term_id, $term->taxonomy );
				}
			}
			/** This action is documented above. */
			do_action( 'aafm_agent_meta_written', 'term', $term_id );
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}
}
