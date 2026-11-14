<?php
/**
 * Avada site tools: a library item placed on a page through the shared insert path.
 *
 * Design 325 AV-8, plan 326 Session 12. A copy and a global reference both go through aafm_avada_insert_tree() with
 * trusted_vendor_text, so they get the same limits, nesting rules, place checks, stored text gate and save replay as
 * an inserted element. Library items are ordinary capability_type post types, so their stored text is untrusted
 * content (SEC-S9b-2).
 *
 * Registers ONLY while Avada is active (aafm_integration_active('avada')).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_avada_site_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_avada_site_full_definitions' );

/**
 * Contribute the Avada site ability definitions, only while Avada is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_avada_site_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'avada' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_avada_site_registry_definitions() );
}

/**
 * Contribute the Avada site ability definitions to the host-independent full registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_avada_site_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_avada_site_registry_definitions() );
}

/**
 * The Avada site ability rows.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_avada_site_registry_definitions(): array {
	return array(
		'aafm/avada-insert-library-item' => array(
			'label'        => __( 'Insert an Avada library item', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Places an item from Avada\'s library, named by its id from avada-list-library, on an Avada page, layout section or library item, before or after an element from the outline of avada-get-page-content or as its first or last child. A saved section goes at the top level, a saved column inside a row and a saved element inside a column, by Avada\'s own nesting rules. An ordinary item is copied byte for byte; a global item is placed as a reference to the item, as Avada\'s builder does, so every page that shows it keeps showing the item itself, and placing one needs a user who can edit private posts. An item may hold at most 50 elements, nested at most 6 deep. An item holding a code block, a raw setting or a setting named like an event handler needs a user who may post unfiltered HTML, and for any other user its text may hold no square bracket, percent-encoded bracket, data-* attribute, unfinished tag or HTML that WordPress would remove, for a copy and for a reference alike. Requires page_fingerprint from the last read or write and returns the new one; a changed page is refused and nothing is saved. Other indexes may move, so read the outline again before the next structural change. When a revision is kept, restore_revision_id brings the page back through restore-revision. Not undoable from this plugin\'s change history. Requires edit access to the page and to the item; Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_insert_library_item',
		),
	);
}

/**
 * Args for aafm/avada-insert-library-item.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_insert_library_item(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-insert-library-item' ),
		'description'         => aafm_ability_description( 'aafm/avada-insert-library-item' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_avada_structural_schema( false, true ) + array(
				'item_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The library item\'s id from avada-list-library.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id', 'page_fingerprint', 'item_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => aafm_args_avada_insert_element()['output_schema'],
		'execute_callback'    => 'aafm_exec_avada_insert_library_item',
		'permission_callback' => 'aafm_perm_avada_post_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/avada-insert-library-item (design AV-8). The page's guard call, ownership rule and fingerprint run first;
 * then the item must be a library item the user may edit, through the same per-object check as any Avada post
 * (aafm_perm_avada_post_object(), so a global item needs edit_private_posts, Ruling R-S12-2). A global item becomes the
 * one stored element [fusion_global id] (A7), checked against the map and nesting as its own root element (Ruling
 * R-S12-1), and the item's stored text gets the full trusted text gate before the reference is written, because the
 * reference writes none of it (Rulings B11, R-S12-3). Any other item is a byte copy through the trusted branch, which
 * runs the same gate on the copied text.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_insert_library_item( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( ! aafm_builder_ownership_allows( 'avada', aafm_post_has_foreign_builder_ownership( $id ), $post ) ) {
		return aafm_avada_not_owned_error();
	}
	$check = aafm_builder_page_check( (string) $post->post_content, (string) ( $input['page_fingerprint'] ?? '' ) );
	if ( is_wp_error( $check ) ) {
		return $check;
	}

	$item_id = absint( $input['item_id'] ?? 0 );
	$item    = $item_id ? aafm_exact_object_chain( 'post', $item_id ) : null;
	if ( ! $item instanceof WP_Post || ! in_array( $item->post_type, array( 'fusion_element', 'fusion_template' ), true )
		|| ! aafm_perm_avada_post_object( array( 'post_id' => $item_id ) ) ) {
		return aafm_builder_error( 'aafm_builder_unknown_template' );
	}
	if ( 'fusion_element' === $item->post_type && is_wp_error( get_the_terms( $item, 'element_category' ) ) ) {
		return aafm_generic_error();
	}
	$global = aafm_with_checked_reads(
		static fn(): array => array( 'global' => 'yes' === aafm_meta_get( 'post', $item_id, '_fusion_is_global', true ) ),
		aafm_generic_error()
	);
	if ( is_wp_error( $global ) ) {
		return $global;
	}

	$text = (string) $item->post_content;
	$tags = aafm_fusion_shortcode_tags();
	$tree = aafm_builder_tree( $text, $tags );
	if ( null === $tree || array() === $tree ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	if ( $global['global'] ) {
		if ( 1 !== count( $tree ) ) {
			return aafm_builder_error( 'aafm_builder_unparseable' );
		}
		$allow = aafm_avada_trusted_text_allowed( $text, $tags );
		if ( is_wp_error( $allow ) ) {
			return $allow;
		}
		$tree = array(
			array(
				'tag'      => 'fusion_global',
				'atts'     => array( 'id' => (string) $item_id ),
				'children' => array(),
				'source'   => '[fusion_global id="' . $item_id . '"]',
				'place_as' => (string) $tree[0]['tag'],
			),
		);
	}
	$out = aafm_avada_insert_tree(
		$post,
		$tree,
		array(
			'index'     => isset( $input['anchor_index'] ) ? (int) $input['anchor_index'] : null,
			'expect'    => is_array( $input['anchor_expect'] ?? null ) ? $input['anchor_expect'] : null,
			'placement' => isset( $input['placement'] ) ? (string) $input['placement'] : null,
		),
		true
	);
	if ( is_wp_error( $out ) ) {
		return $out;
	}
	$out['post'] = aafm_redact_post( $out['post'] );
	return $out;
}
