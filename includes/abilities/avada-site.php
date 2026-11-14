<?php
/**
 * Avada site tools: a library item placed on a page through the shared insert path, a page's custom CSS and its page
 * options.
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
		'aafm/avada-update-page-css'     => array(
			'label'        => __( 'Update Avada page CSS', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Sets the custom CSS of one Avada page, layout section or library item, the CSS field of Avada\'s builder settings for that page. The value given replaces the page\'s existing CSS, and an empty string removes it; previous returns the old CSS, which is the way back. CSS is stored without HTML tags. A value equal to what is stored is left alone and changed is false. No revision is kept, and the change is not undoable from this plugin\'s change history. Requires edit access to the post and a user who may post unfiltered HTML; Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_page_css',
		),
		'aafm/avada-update-page-options' => array(
			'label'        => __( 'Update Avada page options', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Sets or removes Avada page options on one post, the settings of Avada\'s page options panel: layout, title bar, sidebars, slider, header, footer, off canvas, form appearance, steps and confirmation, SEO and template settings from the fixed list avada-get-page-content returns with include_page_options. set maps option ids to values and remove lists ids to drop, so Avada\'s default applies; give at least one. Every other stored option is kept. A choice is its stored value, a colour a hex, rgba, hsla or Avada colour variable, an image an attachment id, and a multi choice a list. Text may hold no straight double quote or square bracket; for a user who may not post unfiltered HTML, a text area value WordPress would filter is refused, never stored altered. Option groups stored as several sub-values (dimensions, typography, sortable lists, media and display conditions) are not writable, and form notification, submission and privacy settings and code fields are never reachable. An empty value or default removes the option, as Avada\'s own save does. previous returns the old value of every option that changed, null where none was stored, which is the way back. A value equal to what is stored is left alone and not listed in changed. No revision is kept, and the change is not undoable from this plugin\'s change history. Needs the Avada theme active and a post Avada owns, one of Avada\'s own types, or a page, post or portfolio item that no other builder owns. Requires edit access to the post; layout sections need a user who can manage site options. Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_page_options',
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

/**
 * Permission for aafm/avada-update-page-css: the per-object Avada check and unfiltered_html (operator decision: raw CSS
 * needs the caller's unfiltered_html).
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_avada_page_css( array $input ): bool {
	return aafm_perm_avada_post_object( $input ) && current_user_can( 'unfiltered_html' );
}

/**
 * The output schema of a single meta write: post_id, changed and previous.
 *
 * @param array<string,mixed> $previous Schema of previous.
 * @return array<string,mixed>
 */
function aafm_avada_meta_write_output_schema( array $previous ): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'post_id'  => array( 'type' => 'integer' ),
			'changed'  => array( 'type' => 'boolean' ),
			'previous' => $previous,
		),
	);
}

/**
 * Args for aafm/avada-update-page-css.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_update_page_css(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-update-page-css' ),
		'description'         => aafm_ability_description( 'aafm/avada-update-page-css' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the Avada page, layout section or library item.', 'agent-abilities-for-mcp' ),
				),
				'custom_css' => array(
					'type'        => 'string',
					'description' => __( 'The page\'s custom CSS, replacing what is stored; an empty string removes it.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id', 'custom_css' ),
			'additionalProperties' => false,
		),
		'output_schema'       => aafm_avada_meta_write_output_schema(
			array(
				'type'        => 'string',
				'description' => __( 'The CSS stored before this call, an empty string where there was none.', 'agent-abilities-for-mcp' ),
			)
		),
		'execute_callback'    => 'aafm_exec_avada_update_page_css',
		'permission_callback' => 'aafm_perm_avada_page_css',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/avada-update-page-css (design AV-10). Page CSS stays on Avada-owned posts and Avada's own types (Ruling
 * B7, design 4.3). The meta (A13) is written with aafm_meta_set(), or removed for an empty value; a value equal to the
 * stored one writes nothing (Review Focus 3), and a write that does not take is an error.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_update_page_css( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post || ! is_string( $input['custom_css'] ?? null ) ) {
		return aafm_generic_error();
	}
	if ( ! aafm_builder_ownership_allows( 'avada', aafm_post_has_foreign_builder_ownership( $id ), $post ) ) {
		return aafm_avada_not_owned_error();
	}
	$css = sanitize_textarea_field( $input['custom_css'] );
	$old = aafm_with_checked_reads(
		static function () use ( $id ): array {
			$value = aafm_meta_get( 'post', $id, '_fusion_builder_custom_css', true );
			return array( 'css' => is_scalar( $value ) ? (string) $value : '' );
		},
		aafm_generic_error()
	);
	if ( is_wp_error( $old ) ) {
		return $old;
	}
	$out = array(
		'post_id'  => $id,
		'changed'  => false,
		'previous' => $old['css'],
	);
	if ( $css === $old['css'] ) {
		return $out;
	}
	if ( '' === $css ) {
		$result = aafm_meta_delete( 'post', $id, '_fusion_builder_custom_css' );
		$status = (string) ( $result['status'] ?? AAFM_WRITE_REFUSED );
		$ok     = in_array( $status, array( AAFM_WRITE_DELETED, AAFM_WRITE_ABSENT ), true );
	} else {
		$result = aafm_meta_set( 'post', $id, '_fusion_builder_custom_css', $css, $post->post_type );
		$status = is_wp_error( $result ) ? AAFM_WRITE_REFUSED : (string) ( $result['status'] ?? AAFM_WRITE_REFUSED );
		$ok     = in_array( $status, array( AAFM_WRITE_WRITTEN, AAFM_WRITE_UNCHANGED ), true );
	}
	if ( ! $ok ) {
		return aafm_meta_write_error( $status, '' === $css ? 'delete' : 'write', 'post', $id, '_fusion_builder_custom_css' );
	}
	$out['changed'] = true;
	return $out;
}

/**
 * The encoder kind of a page option type from aafm_avada_page_option_keys(), or null when the type is not writable
 * (design 4.7, s8b-page-option-ids.md): the choice types are validated as tokens, because the allowlist carries no
 * choice lists (plan 326 Assumptions); a multiple select is a list of tokens and a checkbox set a comma joined list, as
 * the panel stores them; an upload is an attachment id stored as Avada's url and id pair; a text area is body text
 * (Ruling R-S12-4). The array types (dimensions, typography, sortable, media, layout_conditions) are not writable.
 *
 * @param string $type Theme metabox type.
 * @return string|null
 */
function aafm_avada_page_option_kind( string $type ): ?string {
	$kinds = array(
		'radio-buttonset'    => 'token',
		'select'             => 'token',
		'iconpicker'         => 'token',
		'multiple_select'    => 'tokens',
		'checkbox-buttonset' => 'csv',
		'color-alpha'        => 'colour',
		'color'              => 'colour',
		'text'               => 'text',
		'link'               => 'link',
		'slider'             => 'number',
		'date_time_picker'   => 'date',
		'textarea'           => 'content',
		'upload'             => 'image',
	);
	return $kinds[ $type ] ?? null;
}

/**
 * The stored form of one page option value, or a refusal naming the option. Every scalar kind goes through the one
 * Avada encoder (aafm_avada_encode_value()); text keeps its attribute rule (Ruling R-S10b-1) and is stored with the
 * entities that rule adds for an attribute read back, since the panel stores plain text in the meta. A text area goes
 * through the body-text choke point and is refused when kses would change it for a user who may not post unfiltered
 * HTML (Ruling R-S12-4).
 *
 * @param string $id    Option id.
 * @param string $kind  Kind from aafm_avada_page_option_kind().
 * @param mixed  $value Input.
 * @return string|array<int|string,string>|WP_Error
 */
function aafm_avada_page_option_value( string $id, string $kind, $value ) {
	$param = array( 'param_name' => $id );
	if ( 'tokens' === $kind || 'csv' === $kind ) {
		if ( ! is_array( $value ) || array_values( $value ) !== $value || array() === $value ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $id );
		}
		$out = array();
		foreach ( $value as $one ) {
			$token = is_string( $one ) && '' !== $one ? aafm_avada_encode_value( 'token', $one, $param ) : null;
			if ( ! is_string( $token ) ) {
				return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $id );
			}
			$out[] = $token;
		}
		return 'csv' === $kind ? implode( ',', $out ) : $out;
	}
	if ( is_array( $value ) || is_bool( $value ) || null === $value ) {
		return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $id );
	}
	if ( 'content' === $kind ) {
		$text  = (string) $value;
		$clean = aafm_avada_body_text( $text );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $text;
		}
		if ( $clean !== $text ) {
			return new WP_Error(
				'aafm_avada_stored_text_needs_unfiltered_html',
				/* translators: %s: the page option id. */
				sprintf( __( 'The value of %s holds HTML that would be filtered out for this user, so it needs a user who may post unfiltered HTML. Nothing was changed.', 'agent-abilities-for-mcp' ), $id ),
				array(
					'status'  => 400,
					'setting' => $id,
				)
			);
		}
		return $text;
	}
	$out = aafm_avada_encode_value( $kind, $value, $param );
	if ( is_wp_error( $out ) ) {
		return $out;
	}
	if ( 'image' === $kind ) {
		return is_array( $out ) ? array(
			'url' => htmlspecialchars_decode( (string) $out[ $id ], ENT_NOQUOTES ),
			'id'  => (string) strtok( (string) $out[ $id . '_id' ], '|' ),
		) : aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $id );
	}
	return is_string( $out ) ? htmlspecialchars_decode( $out, ENT_NOQUOTES ) : aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $id );
}

/**
 * Args for aafm/avada-update-page-options.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_update_page_options(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-update-page-options' ),
		'description'         => aafm_ability_description( 'aafm/avada-update-page-options' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post whose Avada page options change.', 'agent-abilities-for-mcp' ),
				),
				'set'     => array(
					'type'        => 'object',
					'description' => __( 'Option id to value, ids from page_options of avada-get-page-content. A multi choice takes a list of values, an image an attachment id.', 'agent-abilities-for-mcp' ),
				),
				'remove'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Option ids to drop, so Avada\'s default applies.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'  => array( 'type' => 'integer' ),
				'changed'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'previous' => array(
					'type'        => 'object',
					'description' => __( 'The old value of each changed option, null where none was stored.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_update_page_options',
		'permission_callback' => 'aafm_perm_avada_post_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/avada-update-page-options (design AV-9 write side, Ruling Q8). Needs the theme, then a post the page
 * options rule admits (Ruling B7). Every id and value is checked before anything is read or written. The stored
 * _fusion array is read with aafm_meta_get() and written back whole with aafm_meta_set() in its array form, never
 * through Avada's own setter, which can write while it reads (A12); an equal result writes nothing (Review Focus 3).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_update_page_options( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( ! aafm_avada_theme_active() ) {
		return new WP_Error(
			'aafm_avada_theme_inactive',
			__( 'Page options can be changed only while the Avada theme is active. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	if ( ! aafm_avada_page_options_post_allowed( $post, aafm_post_has_foreign_builder_ownership( $id ) ) ) {
		return aafm_avada_not_owned_error();
	}
	$set    = $input['set'] ?? array();
	$remove = $input['remove'] ?? array();
	if ( ( ! is_array( $set ) && ! $set instanceof stdClass ) || ! is_array( $remove ) ) {
		return aafm_builder_error( 'aafm_builder_invalid_value' );
	}
	$set = (array) $set;
	if ( array() === $set && array() === $remove ) {
		return new WP_Error(
			'aafm_avada_page_options_empty',
			__( 'Give at least one option in set or remove. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}

	$keys  = aafm_avada_page_option_keys();
	$apply = array();
	foreach ( $set as $key => $value ) {
		$key  = (string) $key;
		$kind = isset( $keys[ $key ] ) ? aafm_avada_page_option_kind( $keys[ $key ] ) : null;
		if ( null === $kind ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $key );
		}
		// An empty value or default is no stored option at all, as Avada's own save treats it.
		if ( '' === $value || 'default' === $value ) {
			$apply[ $key ] = null;
			continue;
		}
		$stored = aafm_avada_page_option_value( $key, $kind, $value );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$apply[ $key ] = $stored;
	}
	foreach ( $remove as $key ) {
		if ( ! is_string( $key ) || array_key_exists( $key, $apply ) ) {
			return aafm_builder_error( 'aafm_builder_invalid_value' );
		}
		if ( ! isset( $keys[ $key ] ) || null === aafm_avada_page_option_kind( $keys[ $key ] ) ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $key );
		}
		$apply[ $key ] = null;
	}

	$old = aafm_with_checked_reads(
		static fn(): array => array( 'fusion' => aafm_meta_get( 'post', $id, '_fusion', true ) ),
		aafm_generic_error()
	);
	if ( is_wp_error( $old ) ) {
		return $old;
	}
	$old = $old['fusion'];
	if ( '' === $old ) {
		$old = array();
	}
	if ( ! is_array( $old ) ) {
		return aafm_generic_error();
	}
	$new      = $old;
	$changed  = array();
	$previous = array();
	foreach ( $apply as $key => $value ) {
		$before = $old[ $key ] ?? null;
		if ( $before === $value ) {
			continue;
		}
		if ( null === $value ) {
			unset( $new[ $key ] );
		} else {
			$new[ $key ] = $value;
		}
		$changed[]        = $key;
		$previous[ $key ] = $before;
	}
	$out = array(
		'post_id'  => $id,
		'changed'  => $changed,
		// An empty previous goes out as {} so the wire shape matches the object the schema declares.
		'previous' => array() === $previous ? new stdClass() : $previous,
	);
	if ( array() === $changed ) {
		return $out;
	}
	if ( array() === $new ) {
		$result = aafm_meta_delete( 'post', $id, '_fusion' );
		$status = (string) ( $result['status'] ?? AAFM_WRITE_REFUSED );
		$ok     = in_array( $status, array( AAFM_WRITE_DELETED, AAFM_WRITE_ABSENT ), true );
	} else {
		$result = aafm_meta_set( 'post', $id, '_fusion', $new, $post->post_type, false );
		$status = (string) ( $result['status'] ?? AAFM_WRITE_REFUSED );
		$ok     = in_array( $status, array( AAFM_WRITE_WRITTEN, AAFM_WRITE_UNCHANGED ), true );
	}
	if ( ! $ok ) {
		return aafm_meta_write_error( $status, array() === $new ? 'delete' : 'write', 'post', $id, '_fusion' );
	}
	return $out;
}
