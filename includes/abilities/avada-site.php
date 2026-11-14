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
		'aafm/avada-insert-library-item'   => array(
			'label'        => __( 'Insert an Avada library item', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Places an item from Avada\'s library, named by its id from avada-list-library, on an Avada page, layout section or library item, before or after an element from the outline of avada-get-page-content or as its first or last child. A saved section goes at the top level, a saved column inside a row and a saved element inside a column, by Avada\'s own nesting rules. An ordinary item is copied byte for byte; a global item is placed as a reference to the item, as Avada\'s builder does, so every page that shows it keeps showing the item itself, and placing one needs a user who can edit private posts. An item may hold at most 50 elements, nested at most 6 deep. An item holding a code block, a raw setting or a setting named like an event handler needs a user who may post unfiltered HTML, and for any other user its text may hold no square bracket, percent-encoded bracket, data-* attribute, unfinished tag or HTML that WordPress would remove, for a copy and for a reference alike. Requires page_fingerprint from the last read or write and returns the new one; a changed page is refused and nothing is saved. Other indexes may move, so read the outline again before the next structural change. When a revision is kept, restore_revision_id brings the page back through restore-revision. Not undoable from this plugin\'s change history. Requires edit access to the page and to the item; Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_insert_library_item',
		),
		'aafm/avada-update-page-css'       => array(
			'label'        => __( 'Update Avada page CSS', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Sets the custom CSS of one Avada page, layout section or library item, the CSS field of Avada\'s builder settings for that page. The value given replaces the page\'s existing CSS, and an empty string removes it; previous returns the old CSS, which is the way back. CSS is stored without HTML tags. A value equal to what is stored is left alone and changed is false. No revision is kept, and the change is not undoable from this plugin\'s change history. Requires edit access to the post and a user who may post unfiltered HTML; Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_page_css',
		),
		'aafm/avada-update-page-options'   => array(
			'label'        => __( 'Update Avada page options', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Sets or removes Avada page options on one post, the settings of Avada\'s page options panel: layout, title bar, sidebars, slider, header, footer, off canvas, form appearance, steps and confirmation, SEO and template settings from the fixed list avada-get-page-content returns with include_page_options. set maps option ids to values and remove lists ids to drop, so Avada\'s default applies; give at least one. Every other stored option is kept. A choice is its stored value, a colour a hex, rgba, hsla or Avada colour variable, an image an attachment id, and a multi choice a list. Text may hold no straight double quote or square bracket; for a user who may not post unfiltered HTML, a text area value WordPress would filter is refused, never stored altered. Option groups stored as several sub-values (dimensions, typography, sortable lists, media and display conditions) are not writable, and form notification, submission and privacy settings and code fields are never reachable. An empty value or default removes the option, as Avada\'s own save does. previous returns the old value of every option that changed, null where none was stored, which is the way back. A value equal to what is stored is left alone and not listed in changed. No revision is kept, and the change is not undoable from this plugin\'s change history. Needs the Avada theme active and a post Avada owns, one of Avada\'s own types, or a page, post or portfolio item that no other builder owns. Requires edit access to the post; layout sections need a user who can manage site options. Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_page_options',
		),
		'aafm/avada-update-global-options' => array(
			'label'        => __( 'Update Avada Global Options', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Overwrites site-wide Avada Global Options from the fixed list avada-get-global-options returns, apart from maintenance_mode, which stays read only. set maps option keys to values: a colour as a hex, rgba, hsla or Avada colour variable; site_width as a number with px or %; layout, header_position, header_layout, page_title_bar, footer_widgets, footer_copyright and responsive as one of their stored choices; logo as an image attachment id, whose URL and size are written with it; footer_text as HTML with unsafe tags removed; a typography option as an object of the sub-values to change (font-family, font-size, line-height, letter-spacing, font-weight, font-style, text-transform, color); color_palette as an object of the colour slots to change, each with a color and a label. A value that fits no rule is refused and nothing is written. Every other stored option is kept. previous returns the old value of every option that changed, null where none was stored, which is the way back. The change applies to the whole site at once, and Avada\'s compiled CSS is rebuilt; a page cache plugin keeps serving its cached pages until the owner purges it. Not undoable from this plugin\'s change history. Needs the Avada theme active and a user who can manage site options. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_global_options',
		),
		'aafm/avada-update-layout'         => array(
			'label'        => __( 'Update an Avada layout', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Overwrites the sections and conditions of one Avada layout, named by its id from avada-list-layouts or "global" for the Global Layout. sections assigns a published layout section of the matching type to header, page_title_bar, content or footer, or 0 to clear that slot; the other slots are kept. conditions replaces the whole stored list: each condition takes mode (include or exclude), type, and under the key named by type a condition id that Avada\'s Layouts screen offers, plus an optional parent naming another condition in the list. Give at least one of sections and conditions. Pages pick their layout from these conditions, so the change applies site-wide at once. previous returns the old sections and conditions, which is the way back. A layout is never created or deleted. A change equal to what is stored is left alone and changed is false. Not undoable from this plugin\'s change history. Requires a user who can manage site options. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_layout',
		),
		'aafm/avada-list-studio-items'     => array(
			'label'        => __( 'List Avada Studio items', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the ready-made items in Avada Studio, Avada\'s own library of templates, sections, elements, icons, forms and off canvas panels, from the catalogue Avada itself loads: id, type, title and, where Avada has them, a category and a preview URL, plus total and from_cache. Filter by type and by a word in the title; at most 50 per page. When Avada has no cached catalogue, this call makes Avada download it from Avada\'s servers under the site\'s own Avada registration and cache it for a day; this plugin sends nothing itself and never reads the registration. Refused until the site owner has unlocked the high-risk category and switched on the Avada Studio import, and while Studio is unavailable (the Avada theme off, Studio switched off in Avada, or the product not registered). Requires a user who can manage site options. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_list_studio_items',
		),
		'aafm/avada-import-studio-item'    => array(
			'label'        => __( 'Import an Avada Studio item', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Imports one Avada Studio item, named by its id and type from avada-list-studio-items, into Avada\'s library on this site, through Avada\'s own importer: Avada downloads the item from Avada\'s servers under the site\'s own Avada registration and creates a published post of that type, and with media import (the default) also its images and whatever the item depends on, such as forms, menus and icons; with media placeholders, images become placeholders. Avada makes these requests and this plugin sends nothing itself; it never reads the registration. An item imported before is not imported again; was_imported is then true. Only the item\'s own post is confirmed, by its type and Avada\'s Studio marker; the media and other posts Avada adds are not checked one by one. Nothing is placed on a page: place the item with avada-insert-library-item. There is no undo from this plugin; the owner can delete the item in Avada\'s Library. Locked with the other high-risk tools until the site owner unlocks the category, then off until switched on. Requires a user who can manage site options. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_import_studio_item',
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

/**
 * The Avada Global Options aafm/avada-update-global-options may write: the read allowlist minus maintenance_mode,
 * which stays read only because one wrong value closes the site to visitors (Ruling Q15). 20 keys.
 *
 * @return list<string>
 */
function aafm_avada_global_option_writable(): array {
	return array_values( array_diff( aafm_avada_global_option_keys(), array( 'maintenance_mode' ) ) );
}

/**
 * The rule each writable Global Option gets (design AV-12; themes/Avada/includes/options/*.php, 7.16.1): a fixed
 * list of stored choices, or the name of a value rule. scratchpad/1113/s13-confirm.md records the source of each.
 *
 * @return array<string,string|list<string>>
 */
function aafm_avada_global_option_rules(): array {
	$rules = array(
		'site_width'       => 'width',
		'layout'           => array( 'boxed', 'wide' ),
		'primary_color'    => 'colour',
		'color_palette'    => 'palette',
		'link_color'       => 'colour',
		'header_position'  => array( 'top', 'left', 'right' ),
		'header_layout'    => array( 'v1', 'v2', 'v3', 'v4', 'v5', 'v6', 'v7' ),
		'logo'             => 'image',
		'page_title_bar'   => array( 'bar_and_content', 'content_only', 'hide' ),
		'footer_widgets'   => array( '0', '1' ),
		'footer_copyright' => array( '0', '1' ),
		'footer_text'      => 'html',
		'responsive'       => array( '0', '1' ),
	);
	foreach ( array( 'body', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $level ) {
		$rules[ $level . '_typography' ] = 'typography';
	}
	return $rules;
}

/**
 * Whether a value is an Avada colour: hex, rgb(a), hsl(a) or a global colour variable, by the one colour pattern the
 * element tools use (aafm_avada_encode_value() colour kind).
 *
 * @param mixed  $value Value.
 * @param string $name  Name for the refusal.
 * @return string|null
 */
function aafm_avada_global_colour( $value, string $name ): ?string {
	if ( ! is_string( $value ) || '' === $value ) {
		return null;
	}
	$out = aafm_avada_encode_value( 'colour', $value, array( 'param_name' => $name ) );
	return is_string( $out ) ? $out : null;
}

/**
 * The stored form of one Global Option value, or WP_Error aafm_builder_invalid_value naming the key (design AV-12).
 * Avada's own setter validates nothing (A14), and these values land in Avada's compiled CSS, where an administrator on
 * multisite has manage_options without unfiltered_html, so every rule is explicit. A typography or palette value is the
 * set of sub-values to change; the executor merges it into the stored array.
 *
 * @param string $key   Option key.
 * @param mixed  $value Input.
 * @return mixed|WP_Error
 */
function aafm_avada_validate_global_option( string $key, $value ) {
	$rules = aafm_avada_global_option_rules();
	$rule  = $rules[ $key ] ?? null;
	$bad   = aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $key );
	if ( is_array( $rule ) ) {
		return is_string( $value ) && in_array( $value, $rule, true ) ? $value : $bad;
	}
	switch ( $rule ) {
		case 'width':
			return is_string( $value ) && 1 === preg_match( '/^\d{1,5}(\.\d{1,3})?(px|%)$/', $value ) ? $value : $bad;
		case 'colour':
			return aafm_avada_global_colour( $value, $key ) ?? $bad;
		case 'html':
			return is_string( $value ) ? wp_kses_post( $value ) : $bad;
		case 'image':
			$id         = is_int( $value ) && $value > 0 ? $value : 0;
			$attachment = $id ? aafm_exact_object( 'post', $id ) : null;
			$src        = $attachment instanceof WP_Post && 'attachment' === $attachment->post_type && wp_attachment_is_image( $id ) ? wp_get_attachment_image_src( $id, 'full' ) : false;
			return is_array( $src ) ? array(
				'url'       => (string) $src[0],
				'id'        => (string) $id,
				'height'    => (string) $src[2],
				'width'     => (string) $src[1],
				'thumbnail' => '',
			) : $bad;
		case 'typography':
			return aafm_avada_global_typography( $key, $value ) ?? $bad;
		case 'palette':
			if ( $value instanceof stdClass ) {
				$value = (array) $value;
			}
			if ( ! is_array( $value ) || array() === $value ) {
				return $bad;
			}
			$out = array();
			foreach ( $value as $slot => $entry ) {
				$entry = $entry instanceof stdClass ? (array) $entry : $entry;
				if ( ! is_string( $slot ) || 1 !== preg_match( '/^color\d{1,2}$/', $slot ) || ! is_array( $entry ) || array() === $entry || array() !== array_diff( array_keys( $entry ), array( 'color', 'label' ) ) ) {
					return $bad;
				}
				$clean = array();
				if ( array_key_exists( 'color', $entry ) ) {
					$colour = aafm_avada_global_colour( $entry['color'], $key );
					if ( null === $colour ) {
						return $bad;
					}
					$clean['color'] = $colour;
				}
				if ( array_key_exists( 'label', $entry ) ) {
					if ( ! is_string( $entry['label'] ) || sanitize_text_field( $entry['label'] ) !== $entry['label'] || strlen( $entry['label'] ) > 64 ) {
						return $bad;
					}
					$clean['label'] = $entry['label'];
				}
				$out[ $slot ] = $clean;
			}
			return $out;
	}
	return $bad;
}

/**
 * The sub-values of one typography option, each to its explicit rule, or null when any is refused: a font family from
 * a character class without ; { } < ( or a backslash; a size as a number and a unit; line height and letter spacing as a
 * number with an optional unit (a unitless line height is the CSS default form); weight, style and text transform from
 * fixed lists; colour by the colour pattern. An Avada global typography variable is accepted for any of them, since
 * Avada's own defaults are stored that way.
 *
 * @param string $key   Option key.
 * @param mixed  $value Input.
 * @return array<string,string>|null
 */
function aafm_avada_global_typography( string $key, $value ): ?array {
	if ( $value instanceof stdClass ) {
		$value = (array) $value;
	}
	if ( ! is_array( $value ) || array() === $value ) {
		return null;
	}
	$patterns = array(
		'font-family'    => '/^[A-Za-z0-9 ,\'"_-]{1,100}$/',
		'font-size'      => '/^\d{1,4}(\.\d{1,3})?(px|em|rem|%|vw|vh|pt)$/',
		'line-height'    => '/^\d{1,4}(\.\d{1,3})?(px|em|rem|%)?$/',
		'letter-spacing' => '/^-?\d{1,4}(\.\d{1,3})?(px|em|rem)?$/',
		'font-weight'    => '/^(|[1-9]00|regular|normal|bold)$/',
		'font-style'     => '/^(|normal|italic)$/',
		'text-transform' => '/^(|none|uppercase|lowercase|capitalize)$/',
	);
	$out      = array();
	foreach ( $value as $sub => $one ) {
		if ( ! is_string( $sub ) || ! is_string( $one ) ) {
			return null;
		}
		if ( 'color' === $sub ) {
			$colour = aafm_avada_global_colour( $one, $key );
			if ( null === $colour ) {
				return null;
			}
			$out[ $sub ] = $colour;
			continue;
		}
		if ( ! isset( $patterns[ $sub ] ) ) {
			return null;
		}
		if ( 1 !== preg_match( $patterns[ $sub ], $one ) && 1 !== preg_match( '/^var\(--awb-typography\d{1,2}-[a-z-]{1,32}\)$/', $one ) ) {
			return null;
		}
		$out[ $sub ] = $one;
	}
	return $out;
}

/**
 * Avada's cache reset with the narrow argument array only (Ruling B2, plan 326 S13-2): the compiled assets and Avada's
 * own transients, never fonts, demo data or other caches, and never the bare call. False when the entry is missing or
 * throws. This is not an object cache flush.
 *
 * @return bool
 */
function aafm_avada_reset_caches(): bool {
	$reset = aafm_builder_vendor( 'avada_cache_reset' );
	if ( null === $reset ) {
		return false;
	}
	try {
		$reset(
			array(
				'compiled_assets'  => true,
				'transients'       => true,
				'gfonts'           => false,
				'fa_font'          => false,
				'demo_data'        => false,
				'po_export'        => false,
				'patcher_messages' => false,
				'other_caches'     => false,
			)
		);
	} catch ( Throwable $e ) {
		unset( $e );
		return false;
	}
	return true;
}

/**
 * Args for aafm/avada-update-global-options.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_update_global_options(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-update-global-options' ),
		'description'         => aafm_ability_description( 'aafm/avada-update-global-options' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'set' => array(
					'type'        => 'object',
					'description' => __( 'Option key to its new value, keys from avada-get-global-options.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'set' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'option_name' => array( 'type' => 'string' ),
				'changed'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'previous'    => array(
					'type'        => 'object',
					'description' => __( 'The old value of each changed option, null where none was stored.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_update_global_options',
		'permission_callback' => 'aafm_perm_manage_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/avada-update-global-options (design AV-12). Every key and value is checked before the option is read;
 * the stored array is changed only at the named keys (a typography or palette value only at its named sub-values) and
 * written whole with aafm_update_option_verified() on the per-language name the read uses. Only a certified write is
 * followed by the narrow cache reset.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_update_global_options( array $input ) {
	$name_of = aafm_builder_vendor( 'avada_option_name' );
	$name    = null !== $name_of ? (string) $name_of() : '';
	if ( '' === $name ) {
		return new WP_Error(
			'aafm_avada_theme_inactive',
			__( 'The Avada theme is not active, so there are no Avada Global Options to change. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	$set = $input['set'] ?? null;
	if ( $set instanceof stdClass ) {
		$set = (array) $set;
	}
	if ( ! is_array( $set ) || array() === $set ) {
		return aafm_builder_error( 'aafm_builder_invalid_value' );
	}
	$apply = array();
	foreach ( $set as $key => $value ) {
		$key = (string) $key;
		if ( ! in_array( $key, aafm_avada_global_option_writable(), true ) ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $key );
		}
		$clean = aafm_avada_validate_global_option( $key, $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$apply[ $key ] = $clean;
	}

	$stored = get_option( $name );
	if ( ! is_array( $stored ) ) {
		return new WP_Error(
			'aafm_avada_options_unreadable',
			__( 'The Avada Global Options could not be read. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 500 )
		);
	}
	$rules    = aafm_avada_global_option_rules();
	$new      = $stored;
	$changed  = array();
	$previous = array();
	foreach ( $apply as $key => $value ) {
		$before = $stored[ $key ] ?? null;
		if ( 'typography' === $rules[ $key ] || 'palette' === $rules[ $key ] ) {
			$merged = is_array( $before ) ? $before : array();
			foreach ( $value as $sub => $one ) {
				$merged[ $sub ] = 'palette' === $rules[ $key ] ? array_merge( is_array( $merged[ $sub ] ?? null ) ? $merged[ $sub ] : array(), $one ) : $one;
			}
			$value = $merged;
		}
		if ( $before === $value ) {
			continue;
		}
		$new[ $key ]      = $value;
		$changed[]        = $key;
		$previous[ $key ] = $before;
	}
	$out = array(
		'option_name' => $name,
		'changed'     => $changed,
		// An empty previous goes out as {} so the wire shape matches the object the schema declares.
		'previous'    => array() === $previous ? new stdClass() : $previous,
	);
	if ( array() === $changed ) {
		return $out;
	}
	if ( ! aafm_update_option_verified( $name, $new ) ) {
		return new WP_Error(
			'aafm_avada_options_not_saved',
			__( 'The Avada Global Options could not be saved. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 500 )
		);
	}
	aafm_avada_reset_caches();
	return $out;
}

/**
 * The layout slots Avada's Layouts screen has (Fusion_Template_Builder::set_template_terms()).
 *
 * @return list<string>
 */
function aafm_avada_layout_slots(): array {
	return array( 'header', 'page_title_bar', 'content', 'footer' );
}

/**
 * Args for aafm/avada-update-layout.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_update_layout(): array {
	$slots = array();
	foreach ( aafm_avada_layout_slots() as $slot ) {
		$slots[ $slot ] = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
	}
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-update-layout' ),
		'description'         => aafm_ability_description( 'aafm/avada-update-layout' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'layout_id'  => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'The layout post id from avada-list-layouts, or "global" for the Global Layout.', 'agent-abilities-for-mcp' ),
				),
				'sections'   => array(
					'type'                 => 'object',
					'properties'           => $slots,
					'additionalProperties' => false,
					'description'          => __( 'Slot to a published layout section id of that type, or 0 to clear the slot.', 'agent-abilities-for-mcp' ),
				),
				'conditions' => array(
					'type'        => 'array',
					'maxItems'    => 100,
					'items'       => array( 'type' => 'object' ),
					'description' => __( 'The full new list of conditions, each with mode, type and the condition id under the key named by type.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'layout_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'layout_id' => array( 'type' => array( 'integer', 'string' ) ),
				'changed'   => array( 'type' => 'boolean' ),
				'previous'  => array(
					'type'        => 'object',
					'description' => __( 'The sections and conditions stored before this call.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_update_layout',
		'permission_callback' => 'aafm_perm_manage_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * The conditions Avada's Layouts screen offers, type => id => label, from Avada's own get_layout_conditions() on the
 * Fusion_Template_Builder instance (A15), or null when the entry is missing or answers no list.
 *
 * @return array<string,array<string,string>>|null
 */
function aafm_avada_layout_condition_list(): ?array {
	$builder = aafm_builder_vendor( 'avada_template_builder' );
	try {
		$object   = null !== $builder ? $builder() : null;
		$sections = is_object( $object ) && method_exists( $object, 'get_layout_conditions' ) ? $object->get_layout_conditions() : null;
	} catch ( Throwable $e ) {
		unset( $e );
		return null;
	}
	if ( ! is_array( $sections ) ) {
		return null;
	}
	$out = array();
	foreach ( $sections as $section ) {
		foreach ( is_array( $section ) && is_array( $section['conditions'] ?? null ) ? $section['conditions'] : array() as $condition ) {
			if ( is_array( $condition ) && is_string( $condition['id'] ?? null ) && is_string( $condition['type'] ?? null ) ) {
				$out[ $condition['type'] ][ $condition['id'] ] = is_string( $condition['label'] ?? null ) ? $condition['label'] : $condition['id'];
			}
		}
	}
	return $out;
}

/**
 * The stored conditions for an input list, keyed by condition id in the shape Avada's Layouts screen saves (label,
 * type, mode, the id under the key named by type, parent), or WP_Error aafm_builder_invalid_value. Avada validates
 * none of this (A15), so every condition must name a mode, a type and an id its own list offers.
 *
 * @param mixed $conditions Input.
 * @return array<string,array<string,string>>|WP_Error
 */
function aafm_avada_layout_conditions( $conditions ) {
	$bad = aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', 'conditions' );
	if ( ! is_array( $conditions ) || array_values( $conditions ) !== $conditions ) {
		return $bad;
	}
	$offered = array() === $conditions ? array() : aafm_avada_layout_condition_list();
	if ( null === $offered ) {
		return $bad;
	}
	$out     = array();
	$parents = array();
	foreach ( $conditions as $condition ) {
		$condition = $condition instanceof stdClass ? (array) $condition : $condition;
		$type      = is_array( $condition ) && is_string( $condition['type'] ?? null ) ? $condition['type'] : '';
		$id        = '' !== $type && is_string( $condition[ $type ] ?? null ) ? $condition[ $type ] : '';
		$mode      = $condition['mode'] ?? null;
		if ( '' === $id || ! in_array( $mode, array( 'include', 'exclude' ), true ) || ! isset( $offered[ $type ][ $id ] ) || isset( $out[ $id ] )
			|| array() !== array_diff( array_keys( $condition ), array( 'mode', 'type', $type, 'label', 'parent' ) )
			|| ( isset( $condition['label'] ) && ! is_string( $condition['label'] ) ) ) {
			return $bad;
		}
		$out[ $id ] = array(
			'label' => $offered[ $type ][ $id ],
			'type'  => $type,
			'mode'  => $mode,
			$type   => $id,
		);
		if ( array_key_exists( 'parent', $condition ) ) {
			if ( ! is_string( $condition['parent'] ) ) {
				return $bad;
			}
			$parents[ $id ]       = $condition['parent'];
			$out[ $id ]['parent'] = $condition['parent'];
		}
	}
	foreach ( $parents as $id => $parent ) {
		if ( $parent === $id || ! isset( $out[ $parent ] ) ) {
			return $bad;
		}
	}
	return $out;
}

/**
 * Execute aafm/avada-update-layout (design AV-13). Every input is checked before anything is written. The layout is
 * decoded the way Avada reads it and written back in the exact form Fusion_Template_Builder::update_layout_content()
 * writes (A15): a layout post's content through wp_update_post() and the confirmed-write check, the Global Layout's
 * option through aafm_update_option_verified(). Then the narrow cache reset; Avada's own updater, which calls the bare
 * reset, is never called, and nothing is created or deleted (Ruling Q16).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_update_layout( array $input ) {
	$layout_id = $input['layout_id'] ?? null;
	$global    = 'global' === $layout_id;
	$id        = is_int( $layout_id ) && $layout_id > 0 ? $layout_id : 0;
	$unknown   = new WP_Error(
		'aafm_avada_unknown_layout',
		__( 'No Avada layout has that id. Use an id from avada-list-layouts, or "global". Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 404 )
	);
	if ( ! $global && 0 === $id ) {
		return $unknown;
	}
	$sections   = $input['sections'] ?? null;
	$sections   = $sections instanceof stdClass ? (array) $sections : $sections;
	$conditions = $input['conditions'] ?? null;
	if ( null === $sections && null === $conditions ) {
		return new WP_Error(
			'aafm_avada_layout_empty',
			__( 'Give at least one of sections and conditions. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}

	$post = null;
	if ( ! $global ) {
		$post = aafm_exact_object_chain( 'post', $id );
		if ( ! $post instanceof WP_Post || 'fusion_tb_layout' !== $post->post_type ) {
			return $unknown;
		}
		$guard = aafm_post_has_foreign_builder_ownership( $id );
		if ( false !== $guard && 'avada' !== $guard ) {
			return aafm_avada_not_owned_error();
		}
	}

	$slots = array();
	if ( null !== $sections ) {
		if ( ! is_array( $sections ) || array() === $sections ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', 'sections' );
		}
		foreach ( $sections as $slot => $section_id ) {
			if ( ! in_array( $slot, aafm_avada_layout_slots(), true ) || ! is_int( $section_id ) || $section_id < 0 ) {
				return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', 'sections' );
			}
			if ( $section_id > 0 ) {
				$section = aafm_exact_object( 'post', $section_id );
				$terms   = $section instanceof WP_Post ? get_the_terms( $section, 'fusion_tb_category' ) : false;
				$kinds   = is_array( $terms ) ? array_merge( wp_list_pluck( $terms, 'slug' ), wp_list_pluck( $terms, 'name' ) ) : array();
				if ( ! $section instanceof WP_Post || 'fusion_tb_section' !== $section->post_type || 'publish' !== $section->post_status || ! in_array( $slot, $kinds, true ) ) {
					return new WP_Error(
						'aafm_avada_layout_section_invalid',
						/* translators: %s: the layout slot, such as footer. */
						sprintf( __( 'The %s slot takes a published layout section of that type. Nothing was changed.', 'agent-abilities-for-mcp' ), $slot ),
						array( 'status' => 400 )
					);
				}
			}
			$slots[ $slot ] = $section_id;
		}
	}
	$new_conditions = null;
	if ( null !== $conditions ) {
		$new_conditions = aafm_avada_layout_conditions( $conditions );
		if ( is_wp_error( $new_conditions ) ) {
			return $new_conditions;
		}
	}

	// Decoded as Fusion_Template_Builder::get_default_layout() and set_overrides() decode it.
	$stored  = $global ? get_option( 'fusion_tb_layout_default' ) : (string) $post->post_content;
	$current = $global
		? ( false === $stored ? array() : ( is_string( $stored ) ? json_decode( wp_unslash( $stored ), true ) : null ) )
		: json_decode( str_replace( "'", "\'", wp_unslash( $stored ) ), true );
	if ( ! is_array( $current ) ) {
		return new WP_Error(
			'aafm_avada_layout_unparseable',
			__( 'Avada cannot read this layout\'s stored data, so it was not changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	$terms = is_array( $current['template_terms'] ?? null ) ? $current['template_terms'] : array();
	$old   = array(
		'sections'   => $terms,
		'conditions' => is_array( $current['conditions'] ?? null ) ? $current['conditions'] : array(),
	);
	foreach ( $slots as $slot => $section_id ) {
		if ( 0 === $section_id ) {
			unset( $terms[ $slot ] );
		} else {
			$terms[ $slot ] = $section_id;
		}
	}
	$new = array(
		'conditions'     => null !== $new_conditions ? $new_conditions : $old['conditions'],
		'template_terms' => $terms,
	) + $current;

	$out = array(
		'layout_id' => $global ? 'global' : $id,
		'changed'   => false,
		'previous'  => array(
			// An empty map goes out as {} so a client can send it straight back.
			'sections'   => array() === $old['sections'] ? new stdClass() : $old['sections'],
			'conditions' => array_values( $old['conditions'] ),
		),
	);
	if ( $new['conditions'] === $old['conditions'] && $new['template_terms'] === $old['sections'] ) {
		return $out;
	}

	if ( $global ) {
		if ( ! aafm_update_option_verified( 'fusion_tb_layout_default', wp_slash( (string) wp_json_encode( $new ) ) ) ) {
			return new WP_Error(
				'aafm_avada_layout_not_saved',
				__( 'The Global Layout could not be saved. Nothing was changed.', 'agent-abilities-for-mcp' ),
				array( 'status' => 500 )
			);
		}
	} else {
		$content = wp_slash( str_replace( "\'", "'", (string) wp_json_encode( $new, JSON_UNESCAPED_UNICODE ) ) );
		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => $content,
				)
			),
			true
		);
		if ( is_wp_error( $updated ) || ! aafm_post_field_confirm_logged( $id, 'post_content', $content, $stored ) ) {
			return new WP_Error(
				'aafm_avada_layout_not_saved',
				__( 'The layout could not be confirmed as saved.', 'agent-abilities-for-mcp' ),
				array( 'status' => 500 )
			);
		}
	}
	aafm_avada_reset_caches();
	$out['changed'] = true;
	return $out;
}

/**
 * The post types an Avada Studio item can have (A24).
 *
 * @return list<string>
 */
function aafm_avada_studio_types(): array {
	return array( 'fusion_template', 'fusion_tb_section', 'fusion_element', 'fusion_icons', 'fusion_form', 'awb_off_canvas' );
}

/**
 * Whether Avada Studio can be used here: true, or WP_Error aafm_avada_studio_unavailable (409) when the Avada theme is
 * not active, Studio's catalogue or importer entry is missing (Studio switched off, A21), or Avada does not show Studio
 * (the one registration question this plugin asks, A24). The registration itself is never read.
 *
 * @return true|WP_Error
 */
function aafm_avada_studio_ready() {
	$registered = aafm_builder_vendor( 'avada_studio_registered' );
	if ( null === aafm_builder_vendor( 'avada_option_name' ) || null === aafm_builder_vendor( 'avada_studio' ) || null === aafm_builder_vendor( 'avada_studio_import' )
		|| null === $registered || true !== $registered() ) {
		return new WP_Error(
			'aafm_avada_studio_unavailable',
			__( 'Avada Studio is not available on this site: it needs the Avada theme active, Studio switched on in Avada and the product registered. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	return true;
}

/**
 * Whether the site owner has approved the Studio tools: the import tool is switched on and the high-risk category is
 * unlocked (Rulings Q17, Q19). One approval covers the list too.
 *
 * @return bool
 */
function aafm_avada_studio_approved(): bool {
	return aafm_is_ability_enabled( 'aafm/avada-import-studio-item' ) && ! aafm_ability_is_locked( 'aafm/avada-import-studio-item' );
}

/**
 * The refusal while the Studio tools are not approved.
 *
 * @return WP_Error
 */
function aafm_avada_studio_not_approved_error(): WP_Error {
	return new WP_Error(
		'aafm_avada_studio_not_approved',
		__( 'The Avada Studio tools need the site owner\'s approval first: unlock the high-risk category in this plugin\'s settings and switch on the Avada Studio import. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 403 )
	);
}

/**
 * Avada's Studio catalogue from AWB_Studio()->get_data() (A21): an array keyed by item type, or WP_Error
 * aafm_avada_studio_fetch_failed when Avada returns anything else, never an empty list. When Avada has no cached
 * catalogue, Avada itself downloads it and caches it in its avada_studio transient.
 *
 * @return array<string,mixed>|WP_Error
 */
function aafm_avada_studio_catalogue() {
	$studio = aafm_builder_vendor( 'avada_studio' );
	try {
		$object = null !== $studio ? $studio() : null;
		$data   = is_object( $object ) && method_exists( $object, 'get_data' ) ? $object->get_data() : false;
	} catch ( Throwable $e ) {
		unset( $e );
		$data = false;
	}
	if ( ! is_array( $data ) ) {
		return new WP_Error(
			'aafm_avada_studio_fetch_failed',
			__( 'Avada could not load the Studio catalogue from Avada\'s servers. Try again later. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 502 )
		);
	}
	return $data;
}

/**
 * One catalogue item trimmed to what the list returns, every string capped, or null when it has no positive id.
 *
 * @param mixed  $item Catalogue row.
 * @param string $type Item type.
 * @return array<string,mixed>|null
 */
function aafm_avada_studio_row( $item, string $type ): ?array {
	if ( ! is_array( $item ) || ! is_numeric( $item['ID'] ?? null ) || (int) $item['ID'] < 1 ) {
		return null;
	}
	$row  = array(
		'id'    => (int) $item['ID'],
		'type'  => $type,
		'title' => mb_substr( is_string( $item['post_title'] ?? null ) ? wp_strip_all_tags( $item['post_title'] ) : '', 0, 200 ),
	);
	$tags = is_array( $item['tags'] ?? null ) ? array_values( array_filter( $item['tags'], 'is_string' ) ) : array();
	if ( array() !== $tags ) {
		$row['category'] = mb_substr( sanitize_text_field( $tags[0] ), 0, 100 );
	}
	$url = is_string( $item['url'] ?? null ) ? esc_url_raw( $item['url'] ) : '';
	if ( '' !== $url && strlen( $url ) <= 500 ) {
		$row['preview_url'] = $url;
	}
	return $row;
}

/**
 * Args for aafm/avada-list-studio-items.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_list_studio_items(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-list-studio-items' ),
		'description'         => aafm_ability_description( 'aafm/avada-list-studio-items' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'type'     => array(
					'type' => 'string',
					'enum' => aafm_avada_studio_types(),
				),
				'search'   => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'items'      => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'          => array( 'type' => 'integer' ),
							'type'        => array( 'type' => 'string' ),
							'title'       => array( 'type' => 'string' ),
							'category'    => array( 'type' => 'string' ),
							'preview_url' => array( 'type' => 'string' ),
						),
					),
				),
				'total'      => array( 'type' => 'integer' ),
				'from_cache' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_list_studio_items',
		'permission_callback' => 'aafm_perm_manage_options',
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
 * Execute aafm/avada-list-studio-items (design AV-15 Studio). Approval first, then availability, then Avada's own
 * catalogue call; this plugin makes no request of its own.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_list_studio_items( array $input ) {
	if ( ! aafm_avada_studio_approved() ) {
		return aafm_avada_studio_not_approved_error();
	}
	$ready = aafm_avada_studio_ready();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}
	$cached = false !== get_transient( 'avada_studio' );
	$data   = aafm_avada_studio_catalogue();
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$type   = isset( $input['type'] ) && in_array( $input['type'], aafm_avada_studio_types(), true ) ? array( (string) $input['type'] ) : aafm_avada_studio_types();
	$search = isset( $input['search'] ) && is_string( $input['search'] ) ? trim( $input['search'] ) : '';
	$rows   = array();
	foreach ( $type as $one ) {
		foreach ( is_array( $data[ $one ] ?? null ) ? $data[ $one ] : array() as $item ) {
			$row = aafm_avada_studio_row( $item, $one );
			if ( null !== $row && ( '' === $search || false !== mb_stripos( $row['title'], $search ) ) ) {
				$rows[] = $row;
			}
		}
	}
	$per_page = min( 50, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
	$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
	return array(
		'items'      => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
		'total'      => count( $rows ),
		'from_cache' => $cached,
	);
}

/**
 * Args for aafm/avada-import-studio-item.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_import_studio_item(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-import-studio-item' ),
		'description'         => aafm_ability_description( 'aafm/avada-import-studio-item' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'item_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The item\'s id from avada-list-studio-items.', 'agent-abilities-for-mcp' ),
				),
				'type'    => array(
					'type' => 'string',
					'enum' => aafm_avada_studio_types(),
				),
				'media'   => array(
					'type'    => 'string',
					'enum'    => array( 'import', 'placeholders' ),
					'default' => 'import',
				),
			),
			'required'             => array( 'item_id', 'type' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'      => array( 'type' => 'integer' ),
				'post_type'    => array( 'type' => 'string' ),
				'title'        => array( 'type' => 'string' ),
				'was_imported' => array(
					'type'        => 'boolean',
					'description' => __( 'True when Avada found an earlier import of this item and created nothing.', 'agent-abilities-for-mcp' ),
				),
				'media'        => array( 'type' => 'string' ),
				'note'         => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_import_studio_item',
		'permission_callback' => 'aafm_perm_manage_options',
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
 * Execute aafm/avada-import-studio-item (design AV-15 Studio, Rulings Q17, Q19, X2). The approval and availability
 * checks run first; the item must be in Avada's own catalogue with that type, so Avada is never asked for a path the
 * caller chose. Avada's importer then fetches and writes everything under the site's own registration, the call its
 * setup wizard makes, with update_post never set so an existing post is never overwritten. The new post is read back
 * and its type and _avada_studio_post meta checked.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_import_studio_item( array $input ) {
	if ( ! aafm_avada_studio_approved() ) {
		return aafm_avada_studio_not_approved_error();
	}
	$ready = aafm_avada_studio_ready();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}
	$item_id = absint( $input['item_id'] ?? 0 );
	$type    = is_string( $input['type'] ?? null ) ? $input['type'] : '';
	$media   = 'placeholders' === ( $input['media'] ?? 'import' ) ? 'placeholders' : 'import';
	if ( ! $item_id || ! in_array( $type, aafm_avada_studio_types(), true ) ) {
		return aafm_builder_error( 'aafm_builder_invalid_value' );
	}
	$data = aafm_avada_studio_catalogue();
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$listed = false;
	foreach ( is_array( $data[ $type ] ?? null ) ? $data[ $type ] : array() as $item ) {
		$row = aafm_avada_studio_row( $item, $type );
		if ( null !== $row && $item_id === $row['id'] ) {
			$listed = true;
			break;
		}
	}
	if ( ! $listed ) {
		return aafm_builder_error( 'aafm_builder_unknown_template' );
	}

	// Core files Avada's media step needs and does not load itself (A23).
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$failed = new WP_Error(
		'aafm_avada_studio_import_failed',
		__( 'Avada could not import this Studio item. Nothing was confirmed as imported.', 'agent-abilities-for-mcp' ),
		array( 'status' => 502 )
	);
	$import = aafm_builder_vendor( 'avada_studio_import' );
	try {
		$importer = null !== $import ? $import() : null;
		if ( ! is_object( $importer ) || ! method_exists( $importer, 'set_import_options' ) || ! method_exists( $importer, 'import_post' ) ) {
			return $failed;
		}
		// The accepted values of AWB_Studio::studio_import_options_template() (inc/class-awb-studio.php:124).
		$importer->set_import_options( array( 'images' => 'import' === $media ? 'do-import-images' : 'dont-import-images' ) );
		$result = $importer->import_post(
			array(
				'post_id'   => $item_id,
				'post_type' => $type,
			),
			array(),
			true
		);
	} catch ( Throwable $e ) {
		unset( $e );
		return $failed;
	}
	$post_id = is_array( $result ) && is_numeric( $result['post_id'] ?? null ) ? (int) $result['post_id'] : 0;
	$post    = $post_id > 0 ? aafm_exact_object( 'post', $post_id ) : null;
	if ( ! $post instanceof WP_Post || $type !== $post->post_type ) {
		return $failed;
	}
	$meta = aafm_with_checked_reads(
		static fn(): array => array( 'source' => aafm_meta_get( 'post', $post_id, '_avada_studio_post', true ) ),
		aafm_generic_error()
	);
	if ( is_wp_error( $meta ) || ! is_scalar( $meta['source'] ) || (string) $item_id !== (string) $meta['source'] ) {
		return $failed;
	}
	return array(
		'post_id'      => $post_id,
		'post_type'    => $type,
		'title'        => $post->post_title,
		'was_imported' => true === ( $result['was_imported'] ?? false ),
		'media'        => $media,
		'note'         => __( 'Only the item\'s own post was confirmed; the attachments, forms, menus and icons Avada imports with it are not checked one by one. Place the item on a page with avada-insert-library-item.', 'agent-abilities-for-mcp' ),
	);
}
