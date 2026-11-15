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
		'aafm/avada-insert-library-item'    => array(
			'label'        => __( 'Insert an Avada library item', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Places an item from Avada\'s library, named by its id from avada-list-library, on an Avada page, layout section or library item, before or after an element from the outline of avada-get-page-content or as its first or last child. A saved section goes at the top level, a saved column inside a row, or directly in a flex container as Avada Studio sections hold it, and a saved element inside a column, by Avada\'s own nesting rules. An ordinary item is copied byte for byte; a global item is placed as a reference to the item, as Avada\'s builder does, so every page that shows it keeps showing the item itself, and placing one needs a user who can edit private posts. An item may hold at most 50 elements, nested at most 6 deep. An item holding a code block, a raw setting or a setting named like an event handler needs a user who may post unfiltered HTML, and for any other user its text may hold no square bracket, percent-encoded bracket, data-* attribute, unfinished tag or HTML that WordPress would remove, for a copy and for a reference alike. A global item it shows by reference, at any depth, gets the same access check and content checks; a reference loop is refused. Requires page_fingerprint from the last read or write and returns the new one; a changed page is refused and nothing is saved. Other indexes may move, so read the outline again before the next structural change. When a revision is kept, restore_revision_id brings the page back through restore-revision. Not undoable from this plugin\'s change history. Requires edit access to the page and to the item; Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_insert_library_item',
		),
		'aafm/avada-update-page-css'        => array(
			'label'        => __( 'Update Avada page CSS', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Sets the custom CSS of one Avada page, layout section or library item, the CSS field of Avada\'s builder settings for that page. The value given replaces the page\'s existing CSS, and an empty string removes it; previous returns the old CSS, which is the way back; previous_restorable is false when the earlier CSS holds a form this tool refuses, so it cannot be sent back. CSS that holds markup is refused, as WordPress refuses it in custom CSS, and so is a "<" or a percent-encoded sequence such as %20, which Avada rewrites or removes when it prints page CSS; any other CSS is stored exactly as sent. A value equal to what is stored is left alone and changed is false. No revision is kept, and the change is not undoable from this plugin\'s change history. Requires edit access to the post and a user who may post unfiltered HTML; Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_page_css',
		),
		'aafm/avada-update-page-options'    => array(
			'label'        => __( 'Update Avada page options', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Sets or removes Avada page options on one post, the settings of Avada\'s page options panel: layout, title bar, sidebars, slider, header, footer, off canvas, form appearance, steps and confirmation, SEO and template settings from the fixed list avada-get-page-content returns with include_page_options. set maps option ids to values and remove lists ids to drop, so Avada\'s default applies; give at least one. Every other stored option is kept. A choice is its stored value, a color a hex, rgba, hsla or Avada color variable, an image an attachment id, and a multi choice a list. Text may hold no straight double quote or square bracket; for a user who may not post unfiltered HTML, a text or text area value WordPress would filter is refused, never stored altered. Option groups stored as several sub-values (dimensions, typography, sortable lists, media and display conditions) are not writable, and form notification, submission and privacy settings and code fields are never reachable. An empty value or default removes the option, as Avada\'s own save does. The old value of every option that changed comes back in previous, null where none was stored; send it back as set to undo the change. That brings back every value as Avada reads it, but not always the same stored data: an option stored as default comes back as no stored option, which Avada treats the same, and options restored after a remove may be stored in another order. A value equal to what is stored is left alone and not listed in changed. No revision is kept, and the change is not undoable from this plugin\'s change history. Needs the Avada theme active and a post Avada owns, one of Avada\'s own types, or a page, post, portfolio item, Avada Slider slide or Elastic Slider slide that no other builder owns; a portfolio item or slide also needs its content type exposed in the Content sub-tab of the Abilities tab first. Requires edit access to the post; layout sections need a user who can manage site options. Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_page_options',
		),
		'aafm/avada-update-global-options'  => array(
			'label'        => __( 'Update Avada Global Options', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Overwrites site-wide Avada Global Options from the fixed list avada-get-global-options returns, apart from maintenance_mode, which stays read only. set maps option keys to values: a color as a hex, rgba, hsla or Avada color variable; site_width as a number with px or %; layout, header_position, header_layout, page_title_bar, footer_widgets, footer_copyright and responsive as one of their stored choices; logo as an image attachment id, whose URL and size are written with it; footer_text as HTML, refused for a user who may not post unfiltered HTML when WordPress would filter it; a typography option as an object of the sub-values to change (font-family, font-size, line-height, letter-spacing, font-weight, font-style, text-transform, color); color_palette as an object of the color slots to change, each with a color and a label. A value that fits no rule is refused and nothing is written. Every other stored option is kept. The old value of every option that changed comes back in previous (for a typography or palette option, of each sub-value that changed), null where none was stored; send it back as set to undo the change. A typography option stored as an empty array, which Avada\'s own save never writes, can be removed, and that previous cannot be written back, so the response lists such options in not_restorable. The change applies to the whole site at once, and Avada\'s compiled CSS is rebuilt; a page cache plugin keeps serving its cached pages until the owner purges it. Not undoable from this plugin\'s change history. Needs the Avada theme active and a user who can manage site options (manage_options). Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_global_options',
		),
		'aafm/avada-update-layout'          => array(
			'label'        => __( 'Update an Avada layout', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Overwrites the sections and conditions of one Avada layout, named by its id from avada-list-layouts or "global" for the Global Layout. sections assigns a published layout section of the matching type to header, page_title_bar, content or footer, or 0 to clear that slot; the other slots are kept. conditions replaces the whole stored list: each condition takes mode (include or exclude), type, and under the key named by type a condition id that Avada\'s Layouts screen offers, or, for one specific page, post, term or author, the id Avada stores for it, such as specific_page|42, with parent set to the part of that id before the |: {"mode": "include", "type": "singular", "singular": "specific_page|42", "parent": "specific_page"}. Give at least one of sections and conditions. Pages pick their layout from these conditions, so the change applies site-wide at once. The old sections and conditions come back in previous; send them back to undo the change. A layout is never created or deleted. A change equal to what is stored is left alone and changed is false. Not undoable from this plugin\'s change history. Requires a user who can manage site options (manage_options). Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_layout',
		),
		'aafm/avada-list-studio-items'      => array(
			'label'        => __( 'List Avada Studio items', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the ready-made items in Avada Studio, Avada\'s own library of templates, sections, elements, icons, forms and off canvas panels, from the catalog Avada itself loads: id, type, title and, where Avada has them, a category and a preview URL, plus total and from_cache. Filter by type and by a word in the title; at most 50 per page. When Avada has no cached catalog, this call makes Avada download it from Avada\'s servers under the site\'s own Avada registration and cache it for a day; this plugin sends nothing itself and never reads the registration. Refused until the site owner has switched on the Avada Studio import, and while Studio is unavailable (the Avada theme off, Studio switched off in Avada, or the product not registered). Requires a user who can manage site options (manage_options). Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_list_studio_items',
		),
		'aafm/avada-import-studio-item'     => array(
			'label'        => __( 'Import an Avada Studio item', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Imports one Avada Studio item, named by its id and type from avada-list-studio-items, into Avada\'s library on this site, through Avada\'s own importer: Avada downloads the item from Avada\'s servers under the site\'s own Avada registration and creates a published post of that type, and with media import (the default) also its images and whatever the item depends on, such as forms, menus and icons; with media placeholders, images become placeholders. Avada makes these requests and this plugin sends nothing itself; it never reads the registration. An item imported before is not imported again; was_imported is then true. Only the item\'s own post is confirmed, by its type and Avada\'s Studio marker; the media and other posts Avada adds are not checked one by one. Nothing is placed on a page: place the item with avada-insert-library-item. There is no undo from this plugin; the owner can delete the item in Avada\'s Library. Off until the site owner switches it on. Requires a user who can manage site options (manage_options). Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_import_studio_item',
		),
		'aafm/avada-update-slider-settings' => array(
			'label'        => __( 'Update Avada Slider settings', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Changes the settings of one Avada Slider, the slider as a whole and not its slides, for every page that shows it. A slider is a term of Avada\'s slide-page taxonomy, named by term_id. set maps setting keys to values: slider_width, slider_height, nav_box_width, nav_box_height and nav_arrow_size as a number with px, %, em, rem, vh or vw; full_screen, parallax, nav_arrows, autoplay and loop as true or false; animation as fade or slide; slideshow_speed and animation_speed as whole milliseconds; typo_sensitivity and typo_factor as numbers; slider_indicator as an empty string (none), scroll_down_indicator or pagination_circles; slider_indicator_color as a color or an empty string. Values are stored as Avada\'s slider screen stores them, written through Avada\'s own slider settings code and read back. A value equal to what is stored is left alone and not listed in changed. The old value of every setting that changed comes back in previous, null where none was stored; send it back as set to undo the change. When a slider still holds settings from an old Avada version, Avada itself copies them into its current store on the first read. Not undoable from this plugin\'s change history. Requires a user who can manage site options (manage_options). Only while Avada is active, and the slider needs Avada Core active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_slider_settings',
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
		'output_schema'       => array_merge_recursive(
			aafm_avada_meta_write_output_schema(
				array(
					'type'        => 'string',
					'description' => __( 'The CSS stored before this call, an empty string where there was none.', 'agent-abilities-for-mcp' ),
				)
			),
			array(
				'properties' => array(
					'previous_restorable' => array(
						'type'        => 'boolean',
						'description' => __( 'False when previous holds a form this tool refuses, so it cannot be sent back.', 'agent-abilities-for-mcp' ),
					),
				),
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
	$css    = $input['custom_css'];
	$markup = aafm_builder_css_markup( 'custom_css', $css ) ?? aafm_builder_css_vendor_alters( 'avada', 'custom_css', $css );
	if ( null !== $markup ) {
		return $markup;
	}
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
		'post_id'             => $id,
		'changed'             => false,
		'previous'            => $old['css'],
		// R3-4: CSS stored by another route (a Studio import keeps %41) may be a form this tool refuses.
		'previous_restorable' => null === aafm_builder_css_markup( 'custom_css', $old['css'] ) && null === aafm_builder_css_vendor_alters( 'avada', 'custom_css', $old['css'] ),
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
 * entities that rule adds for an attribute read back, since the panel stores plain text in the meta. Avada Core prints
 * text options without escaping (fusion-core templates/single-avada_portfolio.php), and Avada's own save runs
 * wp_kses_post() on every page option string for a user who may not post unfiltered HTML, so text and a text area both
 * end in aafm_avada_filtered_text() (CR-1); a text area goes through the body-text choke point first (Ruling R-S12-4).
 *
 * @param string $id    Option id.
 * @param string $kind  Kind from aafm_avada_page_option_kind().
 * @param mixed  $value Input.
 * @return string|array<int|string,string>|WP_Error
 */
function aafm_avada_page_option_value( string $id, string $kind, $value ) {
	$param = array( 'param_name' => $id );
	if ( 'csv' === $kind && is_string( $value ) && '' !== $value ) {
		// The stored form, as previous returns it (fix pass 2, ruling 1).
		$value = explode( ',', $value );
	}
	if ( 'image' === $kind && is_array( $value ) && isset( $value['id'] ) && is_scalar( $value['id'] ) && 1 === preg_match( '/^[1-9][0-9]*$/', (string) $value['id'] ) ) {
		// The stored url and id pair, as previous returns it: the attachment id names it again.
		$value = (int) $value['id'];
	}
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
		return is_wp_error( $clean ) ? $clean : aafm_avada_filtered_text( $id, $text );
	}
	// Text skips the element-only refusals in aafm_avada_encode_value() (R2-1): no editor collapses a page option.
	$out = 'text' === $kind ? aafm_avada_encode_clean( 'text', $value, $param ) : aafm_avada_encode_value( $kind, $value, $param );
	if ( null === $out ) {
		return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $id );
	}
	if ( is_wp_error( $out ) ) {
		return $out;
	}
	if ( 'image' === $kind ) {
		return is_array( $out ) ? array(
			'url' => htmlspecialchars_decode( (string) $out[ $id ], ENT_NOQUOTES ),
			'id'  => (string) strtok( (string) $out[ $id . '_id' ], '|' ),
		) : aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $id );
	}
	if ( ! is_string( $out ) ) {
		return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $id );
	}
	$out = htmlspecialchars_decode( $out, ENT_NOQUOTES );
	if ( 'text' === $kind && is_string( $value ) && $value !== $out && str_replace( '&amp;', '&', $value ) === $out && current_user_can( 'unfiltered_html' ) ) {
		// R3-4: a text Avada stored with & as &amp; (its save for a restricted user) comes back in previous that way, so
		// it is stored as sent; it prints the same.
		return $value;
	}
	return 'text' === $kind ? aafm_avada_filtered_text( $id, $out ) : $out;
}

/**
 * A text value Avada prints without escaping, in the form Avada's own save stores it (CR-1): as sent for a user who may
 * post unfiltered HTML; for any other user wp_kses_post()'s form when kses changes nothing but how entities are spelled
 * (so "Fish & chips" is stored "Fish &amp; chips"), otherwise refused naming the setting, never stored filtered.
 *
 * @param string $id   Option id, named in the refusal.
 * @param string $text Value.
 * @return string|WP_Error
 */
function aafm_avada_filtered_text( string $id, string $text ) {
	if ( current_user_can( 'unfiltered_html' ) ) {
		return $text;
	}
	$clean = wp_kses_post( $text );
	if ( html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) {
		return new WP_Error(
			'aafm_avada_stored_text_needs_unfiltered_html',
			/* translators: %s: the option id. */
			sprintf( __( 'The value of %s holds HTML that would be filtered out for this user, so it needs a user who may post unfiltered HTML. Nothing was changed.', 'agent-abilities-for-mcp' ), $id ),
			array(
				'status'  => 400,
				'setting' => $id,
			)
		);
	}
	return $clean;
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
		// An empty value or default is no stored option at all, as Avada's own save treats it; null is how previous
		// names an option that was not stored.
		if ( null === $value || '' === $value || 'default' === $value ) {
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
			return is_string( $value ) ? aafm_avada_filtered_text( $key, $value ) : $bad;
		case 'image':
			// The stored url and id array, as previous returns it, names the attachment by its id.
			$value      = is_array( $value ) && isset( $value['id'] ) && is_scalar( $value['id'] ) && 1 === preg_match( '/^[1-9][0-9]*$/', (string) $value['id'] ) ? (int) $value['id'] : $value;
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
				if ( null === $entry && is_string( $slot ) && 1 === preg_match( '/^color\d{1,2}$/', $slot ) ) {
					$out[ $slot ] = null;
					continue;
				}
				if ( ! is_string( $slot ) || 1 !== preg_match( '/^color\d{1,2}$/', $slot ) || ! is_array( $entry ) || array() === $entry || array() !== array_diff( array_keys( $entry ), array( 'color', 'label' ) ) ) {
					return $bad;
				}
				$clean = array();
				foreach ( array( 'color', 'label' ) as $field ) {
					if ( array_key_exists( $field, $entry ) && null === $entry[ $field ] ) {
						$clean[ $field ] = null;
						unset( $entry[ $field ] );
					}
				}
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
 * @return array<string,string|null>|null Null sub-values remove what previous names as not stored.
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
		if ( is_string( $sub ) && null === $one && ( 'color' === $sub || isset( $patterns[ $sub ] ) ) ) {
			// null removes a sub-value that was not stored, the form previous names it in.
			$out[ $sub ] = null;
			continue;
		}
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
				'option_name'    => array( 'type' => 'string' ),
				'changed'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'previous'       => array(
					'type'        => 'object',
					'description' => __( 'The old value of each changed option, null where none was stored.', 'agent-abilities-for-mcp' ),
				),
				'not_restorable' => array(
					'type'        => 'array',
					'description' => __( 'Present only when needed: options whose previous value this tool cannot write back, such as a typography option that was stored as an empty array.', 'agent-abilities-for-mcp' ),
					'items'       => array( 'type' => 'string' ),
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
		if ( null === $value ) {
			// previous names an option that was not stored with null; it is removed again.
			$apply[ $key ] = null;
			continue;
		}
		$clean = aafm_avada_validate_global_option( $key, $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$apply[ $key ] = $clean;
	}

	$stored = get_option( $name );
	if ( false === $stored ) {
		// BR-1: Avada writes the row the first time its options panel is opened.
		return new WP_Error(
			'aafm_avada_options_not_stored',
			__( 'Avada has not stored its Global Options yet. Open Avada > Options in wp-admin once, then try again. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	if ( ! is_array( $stored ) ) {
		return new WP_Error(
			'aafm_avada_options_unreadable',
			__( 'The Avada Global Options could not be read. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 500 )
		);
	}
	$rules = aafm_avada_global_option_rules();
	foreach ( $apply as $key => $value ) {
		// R3-4: removing a typography option whose previous the write validator would refuse (a sub-value it cannot
		// write, or a value such as font-weight 450) would leave no way back.
		if ( null === $value && 'typography' === $rules[ $key ] && isset( $stored[ $key ] ) && array() !== $stored[ $key ] && is_wp_error( aafm_avada_validate_global_option( $key, $stored[ $key ] ) ) ) {
			return new WP_Error(
				'aafm_avada_typography_not_removable',
				__( 'This typography option holds values this tool cannot write back, so it cannot be removed here. Change its sub-values instead. Nothing was changed.', 'agent-abilities-for-mcp' ),
				array(
					'status'  => 400,
					'setting' => $key,
				)
			);
		}
	}
	$new      = $stored;
	$changed  = array();
	$previous = array();
	foreach ( $apply as $key => $value ) {
		$before = $stored[ $key ] ?? null;
		// previous holds only what this call changed (a typography or palette option: its changed sub-values, null
		// where none was stored), so sending it back restores the stored bytes (fix pass 2, ruling 1).
		$old = $before;
		if ( null !== $value && ( 'typography' === $rules[ $key ] || 'palette' === $rules[ $key ] ) ) {
			$merged = is_array( $before ) ? $before : array();
			$old    = array();
			foreach ( $value as $sub => $one ) {
				$was = $merged[ $sub ] ?? null;
				if ( 'palette' === $rules[ $key ] && is_array( $one ) ) {
					$slot = is_array( $was ) ? $was : array();
					$prev = array();
					foreach ( $one as $field => $part ) {
						if ( ( $slot[ $field ] ?? null ) !== $part ) {
							$prev[ $field ] = $slot[ $field ] ?? null;
						}
						if ( null === $part ) {
							unset( $slot[ $field ] );
						} else {
							$slot[ $field ] = $part;
						}
					}
					$one = $slot;
					if ( array() !== $prev ) {
						$old[ $sub ] = null === $was ? null : $prev;
					}
				} elseif ( $was !== $one ) {
					$old[ $sub ] = $was;
				}
				if ( null === $one ) {
					unset( $merged[ $sub ] );
				} else {
					$merged[ $sub ] = $one;
				}
			}
			// Demo #10: a merge that leaves nothing is no stored option, as Avada's own save treats it; an empty
			// array reads differently from an absent key in Avada.
			$value = array() === $merged ? null : $merged;
		}
		if ( $before === $value ) {
			continue;
		}
		if ( null === $value ) {
			unset( $new[ $key ] );
		} else {
			$new[ $key ] = $value;
		}
		$changed[]        = $key;
		$previous[ $key ] = $old;
	}
	$out = array(
		'option_name' => $name,
		'changed'     => $changed,
		// An empty previous goes out as {} so the wire shape matches the object the schema declares.
		'previous'    => array() === $previous ? new stdClass() : $previous,
	);
	// Codex demo pass: a previous the tool's own validation refuses cannot be sent back (an empty typography array, a
	// stored font-weight 450), so it is listed. null (none was stored) always goes back as a removal.
	$not_restorable = array();
	foreach ( $previous as $key => $old ) {
		if ( null !== $old && is_wp_error( aafm_avada_validate_global_option( (string) $key, $old ) ) ) {
			$not_restorable[] = (string) $key;
		}
	}
	if ( array() !== $not_restorable ) {
		$out['not_restorable'] = $not_restorable;
	}
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
		// Avada's Layouts screen stores the id as a string, which previous returns as stored (BR-7).
		$slots[ $slot ] = array(
			'type'    => array( 'integer', 'string' ),
			'minimum' => 0,
			'pattern' => '^(0|[1-9][0-9]*)$',
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
 * none of this (A15), so every condition must name a mode, a type and an id its own list offers. A row with a parent is
 * one specific item under that condition, stored as Avada's dialog stores it (get_layout_child_conditions(),
 * layout-conditions-option.js): id "<parent>|<object id>", where the object is a post of the parent's type, a user or a
 * term that exists now (CX-BR1-3). Its label is the one already stored for that id on this layout, so previous goes back
 * byte for byte, or else the object's own name, as the dialog shows it.
 *
 * @param mixed                             $conditions Input.
 * @param array<string,array<string,mixed>> $stored     The layout's stored conditions.
 * @return array<string,array<string,string>>|WP_Error
 */
function aafm_avada_layout_conditions( $conditions, array $stored = array() ) {
	$bad = aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', 'conditions' );
	if ( ! is_array( $conditions ) || array_values( $conditions ) !== $conditions ) {
		return $bad;
	}
	$offered = array() === $conditions ? array() : aafm_avada_layout_condition_list();
	if ( null === $offered ) {
		return $bad;
	}
	$out = array();
	foreach ( $conditions as $n => $condition ) {
		$condition = $condition instanceof stdClass ? (array) $condition : $condition;
		$type      = is_array( $condition ) && is_string( $condition['type'] ?? null ) ? $condition['type'] : '';
		$id        = '' !== $type && is_string( $condition[ $type ] ?? null ) ? $condition[ $type ] : '';
		$mode      = $condition['mode'] ?? null;
		$parent    = is_array( $condition ) && array_key_exists( 'parent', $condition ) ? $condition['parent'] : null;
		$label     = null === $parent ? ( $offered[ $type ][ $id ] ?? null ) : aafm_avada_layout_child_label( $type, $id, $parent, $offered );
		$prefix    = (string) strtok( $id, '|' );
		$reason    = null;
		if ( ! is_array( $condition ) || '' === $id ) {
			$reason = __( 'it needs type and, under the key type names, a condition id', 'agent-abilities-for-mcp' );
		} elseif ( ! in_array( $mode, array( 'include', 'exclude' ), true ) ) {
			$reason = __( 'mode must be include or exclude', 'agent-abilities-for-mcp' );
		} elseif ( array() !== array_diff( array_keys( $condition ), array( 'mode', 'type', $type, 'label', 'parent' ) ) || ( isset( $condition['label'] ) && ! is_string( $condition['label'] ) ) ) {
			$reason = __( 'only mode, type, the key type names, label and parent are accepted, and label must be text', 'agent-abilities-for-mcp' );
		} elseif ( null === $label && null !== $parent && false !== strpos( $id, '|' ) && $parent !== $prefix ) {
			/* translators: %s: the part of the condition id before the |. */
			$reason = sprintf( __( 'parent must be %s, the part of the id before |', 'agent-abilities-for-mcp' ), $prefix );
		} elseif ( null === $label ) {
			$reason = null === $parent
				? __( 'the condition id is not one Avada\'s Layouts screen offers for that type', 'agent-abilities-for-mcp' )
				: __( 'the id does not name an item of that kind on this site', 'agent-abilities-for-mcp' );
		} elseif ( isset( $out[ $id ] ) ) {
			$reason = __( 'it repeats a condition id already in the list', 'agent-abilities-for-mcp' );
		}
		if ( null !== $reason ) {
			// Demo #12: name the row and what failed, not only the field.
			return new WP_Error(
				'aafm_builder_invalid_value',
				/* translators: 1: the condition's position, starting at 1, 2: what failed. */
				sprintf( __( 'Condition %1$d: %2$s. Nothing was changed.', 'agent-abilities-for-mcp' ), (int) $n + 1, $reason ),
				array(
					'status'  => 400,
					'setting' => 'conditions',
					'index'   => (int) $n,
				)
			);
		}
		if ( null !== $parent && is_string( $stored[ $id ]['label'] ?? null ) ) {
			$label = $stored[ $id ]['label'];
		}
		$out[ $id ] = array(
			'label' => $label,
			'type'  => $type,
			'mode'  => $mode,
			$type   => $id,
		);
		if ( null !== $parent ) {
			$out[ $id ]['parent'] = $parent;
		}
	}
	return $out;
}

/**
 * The name of the one item a child layout condition names, or null when the row is not one Avada's dialog stores:
 * the id must be "<parent>|<positive integer>", the parent an id Avada offers, the type singular or archives, and the
 * integer a post of the parent's post type (specific_ and children_of_), a user (author_archive_) or a term of the
 * parent's taxonomy (taxonomy_of_ and archive_of_), the split get_layout_child_conditions() makes.
 *
 * @param string                             $type    Condition type.
 * @param string                             $id      Condition id.
 * @param mixed                              $under   Parent from the input.
 * @param array<string,array<string,string>> $offered Offered ids by type.
 * @return string|null
 */
function aafm_avada_layout_child_label( string $type, string $id, $under, array $offered ): ?string {
	if ( ! is_string( $under ) || ! in_array( $type, array( 'singular', 'archives' ), true ) || 1 !== preg_match( '/^(.+)\|([1-9][0-9]*)$/', $id, $m ) || $m[1] !== $under ) {
		return null;
	}
	$known = false;
	foreach ( $offered as $ids ) {
		$known = $known || isset( $ids[ $under ] );
	}
	if ( ! $known ) {
		return null;
	}
	$object = (int) $m[2];
	if ( false !== strpos( $under, 'specific_' ) || false !== strpos( $under, 'children_of_' ) ) {
		$post = aafm_exact_object( 'post', $object );
		return $post instanceof WP_Post && preg_replace( '/specific_|children_of_/', '', $under ) === $post->post_type ? (string) $post->post_title : null;
	}
	if ( false !== strpos( $under, 'author_archive_' ) ) {
		$user = aafm_exact_object( 'user', $object );
		return $user instanceof WP_User ? (string) $user->display_name : null;
	}
	$term = aafm_exact_object( 'term', $object, (string) preg_replace( '/taxonomy_of_|archive_of_/', '', $under ) );
	return $term instanceof WP_Term ? (string) $term->name : null;
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
			$digits = is_string( $section_id ) && 1 === preg_match( '/^(0|[1-9][0-9]*)$/', $section_id );
			if ( ! in_array( $slot, aafm_avada_layout_slots(), true ) || ( ! is_int( $section_id ) && ! $digits ) || (int) $section_id < 0 ) {
				return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', 'sections' );
			}
			$slots[ $slot ] = $section_id;
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
		// A slot sent back as it is stored is no change, so it needs no check (previous names it so, BR-7).
		$stored_id = (string) ( $terms[ $slot ] ?? '' );
		if ( $stored_id === (string) $section_id ) {
			continue;
		}
		if ( (int) $section_id > 0 ) {
			$section = aafm_exact_object( 'post', (int) $section_id );
			$cats    = $section instanceof WP_Post ? get_the_terms( $section, 'fusion_tb_category' ) : false;
			$kinds   = is_array( $cats ) ? array_merge( wp_list_pluck( $cats, 'slug' ), wp_list_pluck( $cats, 'name' ) ) : array();
			if ( ! $section instanceof WP_Post || 'fusion_tb_section' !== $section->post_type || 'publish' !== $section->post_status || ! in_array( $slot, $kinds, true ) ) {
				return new WP_Error(
					'aafm_avada_layout_section_invalid',
					/* translators: %s: the layout slot, such as footer. */
					sprintf( __( 'The %s slot takes a published layout section of that type. Nothing was changed.', 'agent-abilities-for-mcp' ), $slot ),
					array( 'status' => 400 )
				);
			}
		}
	}
	$new_conditions = null;
	if ( null !== $conditions ) {
		$new_conditions = aafm_avada_layout_conditions( $conditions, $old['conditions'] );
		if ( is_wp_error( $new_conditions ) ) {
			return $new_conditions;
		}
	}
	// A write that names every stored slot, as previous does, also restores their order, so the bytes come back.
	$full = array() === array_diff_key( $terms, $slots );
	foreach ( $slots as $slot => $section_id ) {
		if ( $full ) {
			unset( $terms[ $slot ] );
		}
		if ( 0 === (int) $section_id ) {
			unset( $terms[ $slot ] );
		} else {
			$terms[ $slot ] = $section_id;
		}
	}
	$new = array(
		'conditions'     => null !== $new_conditions ? $new_conditions : $old['conditions'],
		'template_terms' => $terms,
	) + $current;

	// A slot this call names that was empty before goes back as 0, which clears it, so previous restores exactly.
	$previous_sections = $old['sections'] + array_fill_keys( array_keys( array_diff_key( $slots, $old['sections'] ) ), 0 );
	$out               = array(
		'layout_id' => $global ? 'global' : $id,
		'changed'   => false,
		'previous'  => array(
			// An empty map goes out as {} so a client can send it straight back.
			'sections'   => array() === $previous_sections ? new stdClass() : $previous_sections,
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
 * Whether the site owner has approved the Studio tools: the import tool is switched on (operator decision B,
 * 2026-11-15; the high-risk lock no longer applies). One approval covers the list too.
 *
 * @return bool
 */
function aafm_avada_studio_approved(): bool {
	return aafm_is_ability_enabled( 'aafm/avada-import-studio-item' );
}

/**
 * Whether an ability is left out of every bulk enable control, so the owner ticks it on its own: the Studio import,
 * whose switch is also the approval for the Studio list (fix round 1 ruling).
 *
 * @param string $name Ability name.
 * @return bool
 */
function aafm_ability_needs_own_switch( string $name ): bool {
	return 'aafm/avada-import-studio-item' === $name;
}

/**
 * The refusal while the Studio tools are not approved.
 *
 * @return WP_Error
 */
function aafm_avada_studio_not_approved_error(): WP_Error {
	if ( aafm_read_only_mode() && in_array( 'aafm/avada-import-studio-item', aafm_get_stored_enabled_abilities_raw(), true ) ) {
		// The import is ticked but read-only mode holds it (a write), so read-only mode is what to turn off (review F5).
		// With the import not ticked, the switch is what is missing and the message below names it.
		return new WP_Error(
			'aafm_avada_studio_not_approved',
			__( 'Read-only mode is on, so the Avada Studio tools are held until the site owner turns it off. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 403 )
		);
	}
	return new WP_Error(
		'aafm_avada_studio_not_approved',
		__( 'The Avada Studio tools are switched off. The site owner can switch on the Avada Studio import on this plugin\'s Abilities tab. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 403 )
	);
}

/**
 * Avada's Studio catalog from AWB_Studio()->get_data() (A21): an array keyed by item type, or WP_Error
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
			__( 'Avada could not load the Studio catalog from Avada\'s servers. Try again later. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 502 )
		);
	}
	return $data;
}

/**
 * One catalogue item trimmed to what the list returns, every string capped, or null when it has no positive id or its
 * post_type is not one of Avada's Studio types. The catalogue is grouped by Studio section (elements, sections, columns,
 * post_cards, header, footer, content, forms, icons and so on, seen on the smoke site 2026-11-15), and each item names
 * its own post_type; the group is returned as category.
 *
 * @param mixed  $item  Catalogue row.
 * @param string $group Catalogue group.
 * @return array<string,mixed>|null
 */
function aafm_avada_studio_row( $item, string $group ): ?array {
	if ( ! is_array( $item ) || ! is_numeric( $item['ID'] ?? null ) || (int) $item['ID'] < 1 || ! in_array( $item['post_type'] ?? null, aafm_avada_studio_types(), true ) ) {
		return null;
	}
	$row = array(
		'id'       => (int) $item['ID'],
		'type'     => (string) $item['post_type'],
		'title'    => mb_substr( is_string( $item['post_title'] ?? null ) ? wp_strip_all_tags( $item['post_title'] ) : '', 0, 200 ),
		'category' => mb_substr( sanitize_key( $group ), 0, 100 ),
	);
	$url = is_string( $item['url'] ?? null ) ? esc_url_raw( $item['url'] ) : '';
	if ( '' !== $url && strlen( $url ) <= 500 ) {
		$row['preview_url'] = $url;
	}
	return $row;
}

/**
 * Every item of a catalogue as list rows, in catalogue order, each type and id once.
 *
 * @param array<string,mixed> $data Catalogue from aafm_avada_studio_catalogue().
 * @return list<array<string,mixed>>
 */
function aafm_avada_studio_items( array $data ): array {
	$rows = array();
	foreach ( $data as $group => $items ) {
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$row = aafm_avada_studio_row( $item, (string) $group );
			if ( null !== $row && ! isset( $rows[ $row['type'] . ':' . $row['id'] ] ) ) {
				$rows[ $row['type'] . ':' . $row['id'] ] = $row;
			}
		}
	}
	return array_values( $rows );
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
				'readonly'      => true,
				'destructive'   => false,
				'idempotent'    => true,
				// Avada fetches from avada.studio for this call (BR-3).
				'openWorldHint' => true,
			),
		),
	);
}

/**
 * Whether text holds a search string, ignoring case across Unicode letters. PCRE only, so no mbstring is needed
 * (WordPress never polyfills mb_stripos()); the search is quoted, so it is matched as text, never as a pattern. Text that
 * is not valid UTF-8 never matches.
 *
 * @param string $text   Text to search.
 * @param string $search Search string.
 * @return bool
 */
function aafm_text_contains_ci( string $text, string $search ): bool {
	return 1 === preg_match( '/' . preg_quote( $search, '/' ) . '/iu', $text );
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
	$type   = isset( $input['type'] ) && is_string( $input['type'] ) ? $input['type'] : '';
	$search = isset( $input['search'] ) && is_string( $input['search'] ) ? trim( $input['search'] ) : '';
	$rows   = array();
	foreach ( aafm_avada_studio_items( $data ) as $row ) {
		if ( ( '' === $type || $type === $row['type'] ) && ( '' === $search || aafm_text_contains_ci( $row['title'], $search ) ) ) {
			$rows[] = $row;
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
				'readonly'      => false,
				'destructive'   => false,
				'idempotent'    => true,
				// Avada fetches from avada.studio for this call (BR-3).
				'openWorldHint' => true,
			),
		),
	);
}

/**
 * Execute aafm/avada-import-studio-item (design AV-15 Studio, Ruling X2, operator decision B). The approval and availability
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
	foreach ( aafm_avada_studio_items( $data ) as $row ) {
		if ( $type === $row['type'] && $item_id === $row['id'] ) {
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

/**
 * The slider-wide settings of an Avada Slider (Ruling R3-P8, scratchpad/1113/s14-slider-keys.md): the 17 keys of the
 * defaults in Avada Core's slider edit screen (fusion-slider/class-fusion-slider.php:540-557), each with its kind.
 *
 * @return array<string,string> Key to kind.
 */
function aafm_avada_slider_setting_keys(): array {
	return array(
		'slider_indicator'       => 'indicator',
		'slider_indicator_color' => 'colour',
		'typo_sensitivity'       => 'number',
		'typo_factor'            => 'number',
		'nav_box_width'          => 'size',
		'nav_box_height'         => 'size',
		'nav_arrow_size'         => 'size',
		'slider_width'           => 'size',
		'slider_height'          => 'size',
		'full_screen'            => 'boolean',
		'parallax'               => 'boolean',
		'nav_arrows'             => 'boolean',
		'autoplay'               => 'boolean',
		'loop'                   => 'boolean',
		'animation'              => 'animation',
		'slideshow_speed'        => 'integer',
		'animation_speed'        => 'integer',
	);
}

/**
 * One slider setting in the form Avada's slider screen stores it (every value a string; a checkbox "1" or "0"), or
 * null when the value fits no rule. Each kind also takes its own stored form, so a previous value goes back as it is.
 *
 * @param string $kind  Kind from aafm_avada_slider_setting_keys().
 * @param mixed  $value Input.
 * @param string $key   Key, for the colour check.
 * @return string|null
 */
function aafm_avada_slider_setting_value( string $kind, $value, string $key ): ?string {
	switch ( $kind ) {
		case 'boolean':
			if ( is_bool( $value ) ) {
				return $value ? '1' : '0';
			}
			return in_array( $value, array( '1', '0' ), true ) ? $value : null;
		case 'indicator':
			return in_array( $value, array( '', 'scroll_down_indicator', 'pagination_circles' ), true ) ? $value : null;
		case 'animation':
			return in_array( $value, array( 'fade', 'slide' ), true ) ? $value : null;
		case 'colour':
			return '' === $value ? '' : aafm_avada_global_colour( $value, $key );
		case 'integer':
			if ( is_int( $value ) && $value >= 0 ) {
				return (string) $value;
			}
			return is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]{0,6})$/', $value ) ? $value : null;
		case 'number':
			if ( ( is_int( $value ) || is_float( $value ) ) && $value >= 0 && $value < 1000 ) {
				return (string) $value;
			}
			return is_string( $value ) && 1 === preg_match( '/^[0-9]{1,3}(\.[0-9]{1,3})?$/', $value ) ? $value : null;
		case 'size':
			return is_string( $value ) && 1 === preg_match( '/^[0-9]{1,5}(\.[0-9]{1,3})?(px|%|em|rem|vh|vw)$/', $value ) ? $value : null;
	}
	return null;
}

/**
 * Args for aafm/avada-update-slider-settings.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_update_slider_settings(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-update-slider-settings' ),
		'description'         => aafm_ability_description( 'aafm/avada-update-slider-settings' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'term_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The slider: a term ID of Avada\'s slide-page taxonomy.', 'agent-abilities-for-mcp' ),
				),
				'set'     => array(
					'type'        => 'object',
					'description' => __( 'Setting key to its new value, keys from the fixed slider settings list in the description.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'term_id', 'set' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'term_id'  => array( 'type' => 'integer' ),
				'changed'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'previous' => array(
					'type'        => 'object',
					'description' => __( 'The old value of each changed setting, null where none was stored.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_update_slider_settings',
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
 * The refusal when Avada Slider is not there: Avada Core off (no slide-page taxonomy) or Avada's term meta class
 * missing.
 *
 * @return WP_Error
 */
function aafm_avada_slider_unavailable(): WP_Error {
	return new WP_Error(
		'aafm_avada_slider_unavailable',
		__( 'Avada Slider is not available on this site: it needs the Avada theme and the Avada Core plugin active. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 409 )
	);
}

/**
 * Execute aafm/avada-update-slider-settings (design AV-15, Ruling R3-P7). Every key and value is checked before
 * anything is read. The settings are read and written only through the term meta object Avada's own slider screen
 * uses (the avada_term_meta seam row): get_all_meta(), then set() per changed key, then a fresh get_all_meta() after
 * the object's cache is reset, compared key by key.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_update_slider_settings( array $input ) {
	$factory = aafm_builder_vendor( 'avada_term_meta' );
	if ( null === $factory || ! taxonomy_exists( 'slide-page' ) ) {
		return aafm_avada_slider_unavailable();
	}
	$term = aafm_exact_object_chain( 'term', absint( $input['term_id'] ?? 0 ), 'slide-page' );
	if ( ! $term instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$set = $input['set'] ?? null;
	if ( $set instanceof stdClass ) {
		$set = (array) $set;
	}
	if ( ! is_array( $set ) || array() === $set ) {
		return aafm_builder_error( 'aafm_builder_invalid_value' );
	}
	$keys  = aafm_avada_slider_setting_keys();
	$apply = array();
	foreach ( $set as $key => $value ) {
		$key = (string) $key;
		if ( ! isset( $keys[ $key ] ) ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $key );
		}
		if ( null === $value ) {
			// previous names a setting that was not stored with null; Avada's set() drops a key set to ''.
			$apply[ $key ] = '';
			continue;
		}
		$clean = aafm_avada_slider_setting_value( $keys[ $key ], $value, $key );
		if ( null === $clean ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $key );
		}
		$apply[ $key ] = $clean;
	}

	try {
		$meta = $factory( (int) $term->term_id );
		if ( ! is_object( $meta ) || ! method_exists( $meta, 'get_all_meta' ) || ! method_exists( $meta, 'set' ) ) {
			return aafm_avada_slider_unavailable();
		}
		$stored   = (array) $meta->get_all_meta();
		$changed  = array();
		$previous = array();
		foreach ( $apply as $key => $value ) {
			$before = $stored[ $key ] ?? null;
			// Avada's set() drops a key set to '', so an absent key already holds ''.
			if ( ( is_scalar( $before ) ? (string) $before : null ) === $value || ( null === $before && '' === $value ) ) {
				continue;
			}
			$changed[]        = $key;
			$previous[ $key ] = $before;
		}
		$out = array(
			'term_id'  => (int) $term->term_id,
			'changed'  => $changed,
			// An empty previous goes out as {} so the wire shape matches the object the schema declares.
			'previous' => array() === $previous ? new stdClass() : $previous,
		);
		if ( array() === $changed ) {
			return $out;
		}
		foreach ( $changed as $key ) {
			$meta->set( $key, $apply[ $key ] );
		}
		if ( method_exists( $meta, 'reset_data' ) ) {
			$meta->reset_data();
		}
		$after = (array) $meta->get_all_meta();
	} catch ( Throwable $e ) {
		unset( $e );
		return aafm_avada_slider_unavailable();
	}
	foreach ( $changed as $key ) {
		$got = $after[ $key ] ?? '';
		if ( ! is_scalar( $got ) || (string) $got !== $apply[ $key ] ) {
			return new WP_Error(
				'aafm_avada_slider_write_unconfirmed',
				/* translators: %s: the slider setting key. */
				sprintf( __( 'The slider settings were written, but reading them back does not show the new value of %s. Check the slider in Avada\'s Sliders screen; previous in this error holds the old values.', 'agent-abilities-for-mcp' ), $key ),
				array(
					'status'   => 500,
					'previous' => $previous,
				)
			);
		}
	}
	return $out;
}
