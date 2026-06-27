<?php
/**
 * Reusable-block (wp_block) read + write abilities.
 *
 * The wp_block post type is behind the Reusable Blocks / synced patterns. It is NOT in the
 * content post-type allowlist, so the shared content-write helpers (which gate on that
 * allowlist) refuse it. These abilities gate per-object directly on the wp_block edit/delete
 * meta caps (edit_block/delete_block) instead, with edit_posts/delete_posts as the
 * object-independent discovery floor. Block markup is hardened with wp_kses_post() before
 * insert — verified to PRESERVE Gutenberg block delimiters and their JSON attributes.
 *
 * delete-block uses the Trash (wp_trash_post), guarded against trash-disabled sites, so no
 * force-delete primitive is added.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_blocks_definitions' );

/**
 * Register the reusable-block ability definitions.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_blocks_definitions( array $registry ): array {
	$registry['aafm/list-blocks']  = array(
		'label'        => __( 'List blocks', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists reusable blocks (synced patterns) by id, title, slug, status, and modified date. No block markup in the list. Response includes total (the query-wide count of matching blocks). Requires the edit-posts capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_blocks',
	);
	$registry['aafm/get-block']    = array(
		'label'        => __( 'Get block', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one reusable block by id, including its raw block markup. Requires edit access to that block.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_block',
	);
	$registry['aafm/create-block'] = array(
		'label'        => __( 'Create block', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a reusable block (published immediately). Its markup is sanitized, and the author is always the agent. Requires the publish-posts capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_create_block',
	);
	$registry['aafm/update-block'] = array(
		'label'        => __( 'Update block', 'agent-abilities-for-mcp' ),
		'description'  => __( "Updates a reusable block's title or markup by id. The markup is sanitized. Requires edit access to that block.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_update_block',
	);
	$registry['aafm/delete-block'] = array(
		'label'        => __( 'Delete block', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Moves a reusable block to the Trash, where you can restore it. Never a permanent delete. Requires delete access to that block.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_delete_block',
	);
	return $registry;
}

/**
 * Object-independent floor for block reads/creates: the Block editor authoring cap.
 *
 * @return bool
 */
function aafm_perm_blocks_floor(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Object-independent floor for CREATING a block: the caller can publish posts.
 *
 * The create-block write forces post_status=publish, and wp_insert_post() does no capability check
 * of its own — so gating only on edit_posts would let a Contributor (edit_posts WITHOUT
 * publish_posts) mint a published synced wp_block they could never create in wp-admin. Require
 * publish_posts so the ability matches the editor capability the published-block write needs.
 *
 * @return bool
 */
function aafm_perm_blocks_create(): bool {
	return current_user_can( 'publish_posts' );
}

/**
 * Per-object permission: the caller may edit THIS wp_block (edit_block meta cap).
 *
 * @param array<string,mixed> $input Input carrying block_id.
 * @return bool
 */
function aafm_perm_block_object( array $input ): bool {
	$id    = absint( $input['block_id'] ?? 0 );
	$block = aafm_get_block_object( $id );
	return null !== $block && current_user_can( 'edit_post', $id );
}

/**
 * Per-object permission: the caller may delete THIS wp_block (delete_block meta cap).
 *
 * @param array<string,mixed> $input Input carrying block_id.
 * @return bool
 */
function aafm_perm_block_delete_object( array $input ): bool {
	$id    = absint( $input['block_id'] ?? 0 );
	$block = aafm_get_block_object( $id );
	return null !== $block && current_user_can( 'delete_post', $id );
}

/**
 * Args for list-blocks.
 *
 * @return array<string,mixed>
 */
function aafm_args_list_blocks(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/list-blocks' ),
		'description'         => aafm_ability_description( 'aafm/list-blocks' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => AAFM_LIST_PAGE_MAX,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
				'search'   => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'blocks' => array( 'type' => 'array' ),
				'total'  => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_list_blocks',
		'permission_callback' => 'aafm_perm_blocks_floor',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute list-blocks: lean rows, paginated, no markup.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>
 */
function aafm_exec_list_blocks( array $input ): array {
	$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;

	$query_args = array(
		'post_type'      => 'wp_block',
		'post_status'    => array( 'publish', 'draft' ),
		'posts_per_page' => $per_page,
		'paged'          => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
		'no_found_rows'  => false,
	);
	// Only pass a search term when one was actually given: an empty 's' makes WP_Query run a
	// pointless LIKE on every row, so omit it entirely when no search is requested (B6).
	$search = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';
	if ( '' !== $search ) {
		$query_args['s'] = $search;
	}

	$query  = new WP_Query( $query_args );
	$blocks = array();
	foreach ( $query->posts as $block ) {
		// Scope to blocks the caller can actually edit: the discovery floor (edit_posts) lets a
		// contributor reach this list, but they must not enumerate id/title/slug of OTHER
		// authors' blocks they lack edit_post on. Filtering here keeps the lean rows aligned
		// with the per-object get/update gates.
		if ( $block instanceof WP_Post && current_user_can( 'edit_post', $block->ID ) ) {
			$blocks[] = aafm_redact_block( $block );
		}
	}
	return array(
		'blocks' => $blocks,
		// total reflects the visible (capability-filtered) rows on this page, not the query-wide
		// found_posts. Because blocks are filtered by per-object edit_post AFTER the query, a
		// query-wide count would over-report blocks the caller can never see (e.g. another
		// author's drafts) and mislead the agent. On a typical block-editing role (editor/admin)
		// the unfiltered and filtered sets are identical anyway.
		'total'  => count( $blocks ),
	);
}

/**
 * Args for get-block.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_block(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-block' ),
		'description'         => aafm_ability_description( 'aafm/get-block' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array( 'block_id' => array( 'type' => 'integer' ) ),
			'required'             => array( 'block_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_rich_block_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_get_block',
		'permission_callback' => 'aafm_perm_block_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute get-block: the rich single-block shape (markup included).
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_block( array $input ) {
	$block = aafm_get_block_object( absint( $input['block_id'] ?? 0 ) );
	if ( null === $block ) {
		return aafm_generic_error();
	}
	return aafm_rich_block( $block );
}

/**
 * Args for create-block.
 *
 * The schema is closed (additionalProperties:false) and declares only title + content, so a
 * smuggled post_type/post_author/post_status is rejected before execute ever runs.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_block(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/create-block' ),
		'description'         => aafm_ability_description( 'aafm/create-block' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'title'   => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
			),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_rich_block_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_create_block',
		'permission_callback' => 'aafm_perm_blocks_create',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute create-block: kses-harden the markup, force the type, let the author default.
 *
 * The type is forced to wp_block from our own args; post_author is omitted so wp_insert_post
 * defaults it to the current (agent) user — neither can be spoofed through input. The markup
 * is sanitized with wp_kses_post() (which preserves Gutenberg block delimiters), then the
 * whole array is wp_slash()'d once because the insert functions expect slashed data.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_create_block( array $input ) {
	$title   = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
	$content = isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '';
	$id      = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		),
		true
	);
	if ( is_wp_error( $id ) || 0 === (int) $id ) {
		return aafm_generic_error();
	}
	return aafm_rich_block( get_post( (int) $id ) );
}

/**
 * Args for update-block.
 *
 * Closed schema: block_id (required) plus optional title/content. No post_type/post_author/
 * post_status field exists, so none can be smuggled in.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_block(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/update-block' ),
		'description'         => aafm_ability_description( 'aafm/update-block' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'block_id' => array( 'type' => 'integer' ),
				'title'    => array( 'type' => 'string' ),
				'content'  => array( 'type' => 'string' ),
			),
			'required'             => array( 'block_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_rich_block_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_update_block',
		'permission_callback' => 'aafm_perm_block_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute update-block: resolve per-object, then apply a kses-hardened markup edit.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_block( array $input ) {
	$id    = absint( $input['block_id'] ?? 0 );
	$block = aafm_get_block_object( $id );
	if ( null === $block ) {
		return aafm_generic_error();
	}
	$update = array( 'ID' => $id );
	if ( isset( $input['title'] ) ) {
		$update['post_title'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( isset( $input['content'] ) ) {
		$update['post_content'] = wp_kses_post( (string) $input['content'] );
	}
	$result = wp_update_post( wp_slash( $update ), true );
	if ( is_wp_error( $result ) || 0 === (int) $result ) {
		return aafm_generic_error();
	}
	return aafm_rich_block( get_post( (int) $result ) );
}

/**
 * Args for delete-block.
 *
 * @return array<string,mixed>
 */
function aafm_args_delete_block(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/delete-block' ),
		'description'         => aafm_ability_description( 'aafm/delete-block' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array( 'block_id' => array( 'type' => 'integer' ) ),
			'required'             => array( 'block_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'     => array( 'type' => 'integer' ),
				'status' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_delete_block',
		'permission_callback' => 'aafm_perm_block_delete_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Execute delete-block: move the block to the Trash (recoverable), never a force-delete.
 *
 * Core's wp_trash_post() short-circuits to a permanent delete when the Trash is disabled
 * (EMPTY_TRASH_DAYS falsy), so refuse there rather than silently destroy the block. No
 * force-delete primitive (the trash-bypassing permanent delete) is added anywhere in this file.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_delete_block( array $input ) {
	$id    = absint( $input['block_id'] ?? 0 );
	$block = aafm_get_block_object( $id );
	if ( null === $block ) {
		return aafm_generic_error();
	}
	if ( ! aafm_trash_is_enabled() ) {
		return aafm_generic_error();
	}
	$result = wp_trash_post( $id );
	if ( ! $result instanceof WP_Post ) {
		return aafm_generic_error();
	}
	return array(
		'id'     => $id,
		'status' => 'trash',
	);
}
