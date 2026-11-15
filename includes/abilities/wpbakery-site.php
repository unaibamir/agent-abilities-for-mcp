<?php
/**
 * WPBakery Page Builder site tools: the saved templates (the wpb_js_templates option), a template copied into a
 * page through the shared insert path, and a page's custom CSS and JS.
 *
 * Design 325 WB-7, WB-8 and WB-10, plan 326 Session 11. Vendor code is reached only through aafm_builder_vendor()
 * (Ruling B14). A template is copied through aafm_wpbakery_insert_tree() with trusted_vendor_text, so it gets the
 * same limits, nesting rules, script gate and save replay as an inserted element.
 *
 * Registers ONLY while WPBakery Page Builder is active (aafm_integration_active('wpbakery')).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_wpbakery_site_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wpbakery_site_full_definitions' );

/**
 * Contribute the WPBakery site ability definitions, only while WPBakery is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wpbakery_site_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'wpbakery' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_wpbakery_site_registry_definitions() );
}

/**
 * Contribute the WPBakery site ability definitions to the host-independent full registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wpbakery_site_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wpbakery_site_registry_definitions() );
}

/**
 * The WPBakery site ability rows.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wpbakery_site_registry_definitions(): array {
	return array(
		'aafm/wpbakery-list-templates'   => array(
			'label'        => __( 'List WPBakery templates', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the templates saved in WPBakery Page Builder\'s own template library on this site: id, source (saved) and name, plus total. With include_content each template\'s stored shortcode text comes back byte for byte; for a user who may not post unfiltered HTML, a template holding raw HTML, raw JS, a map, a custom onclick setting or Design Options comes back without its content and with content_withheld true. While the Templatera add-on is active, WPBakery\'s editor shows Templatera\'s own templates instead, and so does this list: its published template posts (source templatera, id the post id) that the user can edit and whose own role list allows the user\'s first role, and with for_post_id only those whose own post type list allows that post; templatera_active says which list this is. Requires the edit_posts capability; WPBakery\'s Role Manager settings are not applied. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_list_templates',
		),
		'aafm/wpbakery-insert-template'  => array(
			'label'        => __( 'Insert a WPBakery template', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Copies a template, named by its id and source from wpbakery-list-templates (saved, from WPBakery\'s own library, or templatera, a published Templatera template post the user can edit and may use on this post by the template\'s own role and post type lists, only while Templatera is active), into a WPBakery page or Grid Builder template, before or after an element from the outline of wpbakery-get-page-content or as its first or last child; on an empty post with no builder, leave out the anchor to start the page, where this one check follows WPBakery\'s own setting for which post types its editor is on for. The template\'s stored text is copied byte for byte; with mode reference (Templatera only) one Templatera element is placed instead, which shows the template\'s current content and gets the same unfiltered HTML checks on that content as a copy. A Templatera template it shows by reference, at any depth, gets the same access check and content checks; a reference loop is refused. WPBakery\'s own nesting rules apply to its top level, and a template may hold at most 50 elements, nested at most 6 deep. A template holding raw HTML, raw JS, a map, a custom onclick setting or Design Options needs a user who may post unfiltered HTML, and for any other user its text may hold no square bracket, percent-encoded bracket, data-* attribute, unfinished tag or HTML that WordPress would remove. Requires page_fingerprint from the last read or write and returns the new one; a changed page is refused and nothing is saved. Other indexes may move, so read the outline again before the next structural change. When a revision is kept, restore_revision_id brings the page back through restore-revision. The page\'s generated CSS is rebuilt. Not undoable from this plugin\'s change history. Requires edit access to the post; WPBakery\'s Role Manager settings are not applied, though its own save filter still runs. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_insert_template',
		),
		'aafm/wpbakery-update-page-code' => array(
			'label'        => __( 'Update WPBakery page code', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Sets the custom CSS, header JS and footer JS of one WPBakery page, the fields of the page settings in WPBakery\'s editor; give at least one of custom_css, custom_js_header and custom_js_footer. Each field given replaces the page\'s existing code for that field, and an empty string removes it; previous returns the old value of every field that changed, which is the way back. CSS that holds markup is refused, as WordPress refuses it in custom CSS, and so is a "<" followed by another character, such as a <= range query, where WPBakery cuts the CSS off when it prints it; any other CSS is stored exactly as sent. JS containing a closing script tag is refused, and the JS fields are refused when WPBakery\'s own Role Manager would not save custom JS for this user. A field equal to what is stored is left alone and not listed in changed. No revision is kept, and the change is not undoable from this plugin\'s change history. Requires edit access to the post and a user who may post unfiltered HTML. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_update_page_code',
		),
		'aafm/wpbakery-get-settings'     => array(
			'label'        => __( 'Get WPBakery settings', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads a fixed list of WPBakery Page Builder\'s site-wide settings from its settings screen: responsive elements, Google Fonts subsets, local Google Fonts, keyboard shortcuts, CSS and JS loading, the Gutenberg switch, auto save, Design Options use, and the global custom CSS; the global header and footer JS only for a user who may post unfiltered HTML. Values come back as stored, and listed settings with no stored value are in not_set. Also returns the post types WPBakery\'s editor is on for and WPBakery\'s version. The license code and every other setting are never returned. Requires a user who can manage site options (manage_options). Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_get_settings',
		),
		'aafm/wpbakery-update-settings'  => array(
			'label'        => __( 'Update WPBakery settings', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Overwrites site-wide WPBakery Page Builder settings from wpbakery-get-settings: not_responsive_css, local_google_fonts and shortcuts (true or false), google_fonts_subsets (a list), css_loading and js_loading (hybrid, optimized or legacy), and the global custom_css, custom_js_header and custom_js_footer, which also need a user who may post unfiltered HTML. Each value goes through WPBakery\'s own sanitize callback for that field, and a value it would change is refused. The global custom_css is the exception: it is refused when it holds markup or a "<" followed by another character, where WPBakery cuts custom.css off, and otherwise stored exactly as sent. WPBakery points each url(../ in the global CSS at its own assets folder in the custom.css file it builds; the setting itself keeps the CSS as sent. A setting whose callback cannot be found, and the Gutenberg switch, auto save and Design Options use, are not writable. The change applies to the whole site at once; previous returns the old value of every setting that changed, null where none was stored, which is the way back. A value equal to what is stored is left alone. Writing custom_css rebuilds WPBakery\'s custom.css file, and the call reports an error when the file does not match afterwards. Not undoable from this plugin\'s change history. Requires a user who can manage site options (manage_options). Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_update_settings',
		),
	);
}

/**
 * Whether the Templatera add-on is active: it defines WPB_TEMPLATERA_VERSION as soon as it loads. A constant check
 * behind its own filter, the pattern of aafm_wpbakery_active(), not a seam row (Ruling B14). S14 calls this.
 *
 * @return bool
 */
function aafm_templatera_active(): bool {
	$active = defined( 'WPB_TEMPLATERA_VERSION' );

	/**
	 * Filters whether the Templatera add-on is reported active.
	 *
	 * @param bool $active Detected active state.
	 */
	return (bool) apply_filters( 'aafm_templatera_active', $active );
}

/**
 * The templates saved in WPBakery's own library (W15): the wpb_js_templates option, an array keyed by a generated
 * id, each row name and template (shortcode text), in stored order. A row that is not an array, or whose name or
 * template is not a string, is skipped. Content is the stored text unchanged. S14 calls this.
 *
 * @return list<array{id:string,name:string,content:string}>
 */
function aafm_wpbakery_saved_templates(): array {
	$stored = get_option( 'wpb_js_templates', array() );
	$rows   = array();
	foreach ( is_array( $stored ) ? $stored : array() as $id => $row ) {
		if ( ! is_array( $row ) || ! is_string( $row['name'] ?? null ) || ! is_string( $row['template'] ?? null ) ) {
			continue;
		}
		$rows[] = array(
			'id'      => (string) $id,
			'name'    => $row['name'],
			'content' => $row['template'],
		);
	}
	return $rows;
}

/**
 * Whether the current user may use a Templatera template (design 3.3 T3, plan S14-2): edit access to the template
 * post, and the template's own templatera meta lists, as Templatera's getTemplateList() applies them: post_type
 * (applied only when $for_post is given) and user_role (the user's first role). An empty list, or a user with no
 * role, means everyone. These lists are the template's data, not Role Manager settings. The meta is read with
 * aafm_meta_get() in its own checked scope, after the capability check; a failed read refuses.
 *
 * @param WP_Post      $template Template post.
 * @param WP_Post|null $for_post Post the template would be used on.
 * @return bool
 */
function aafm_templatera_template_allowed( WP_Post $template, ?WP_Post $for_post = null ): bool {
	$loaded = aafm_exact_object_chain( 'post', $template->ID );
	if ( ! $loaded instanceof WP_Post || 'templatera' !== $loaded->post_type || ! aafm_user_can_checked( 'edit_post', $loaded->ID ) ) {
		return false;
	}
	$lists = aafm_with_checked_reads(
		static function () use ( $loaded ): array {
			$meta = aafm_meta_get( 'post', $loaded->ID, 'templatera', true );
			return is_array( $meta ) ? $meta : array();
		},
		aafm_generic_error()
	);
	if ( is_wp_error( $lists ) ) {
		return false;
	}
	$types = is_array( $lists['post_type'] ?? null ) ? array_map( 'strval', $lists['post_type'] ) : array();
	$roles = is_array( $lists['user_role'] ?? null ) ? array_map( 'strval', $lists['user_role'] ) : array();
	if ( null !== $for_post && array() !== $types && ! in_array( $for_post->post_type, $types, true ) ) {
		return false;
	}
	$user  = wp_get_current_user();
	$first = isset( $user->roles[0] ) ? (string) $user->roles[0] : '';
	return '' === $first || array() === $roles || in_array( $first, $roles, true );
}

/**
 * The published Templatera templates the current user may use, in Templatera's own order (get_posts() defaults:
 * newest first), each id, name and content.
 *
 * @param WP_Post|null $for_post Post the templates would be used on.
 * @return list<array{id:string,name:string,content:string}>
 */
function aafm_templatera_templates( ?WP_Post $for_post ): array {
	$rows  = array();
	$query = new WP_Query(
		array(
			'post_type'              => 'templatera',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);
	foreach ( (array) $query->posts as $template ) {
		if ( $template instanceof WP_Post && aafm_templatera_template_allowed( $template, $for_post ) ) {
			$rows[] = array(
				'id'      => (string) $template->ID,
				'name'    => (string) $template->post_title,
				'content' => (string) $template->post_content,
			);
		}
	}
	return $rows;
}

/**
 * Args for aafm/wpbakery-list-templates.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_list_templates(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-list-templates' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-list-templates' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'include_content' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Also return each template\'s stored shortcode text.', 'agent-abilities-for-mcp' ),
					),
					'for_post_id'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'With Templatera active: list only the templates Templatera offers on this post, by their post type lists. Needs edit access to the post.', 'agent-abilities-for-mcp' ),
					),
				),
				aafm_pagination_schema_props(
					50,
					__( 'Number of templates per page, clamped to the 1-50 range. Defaults to 20 when omitted.', 'agent-abilities-for-mcp' ),
					__( '1-based page number for pagination. Defaults to 1.', 'agent-abilities-for-mcp' )
				)
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'templates'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'               => array( 'type' => 'string' ),
							'source'           => array(
								'type' => 'string',
								'enum' => array( 'saved', 'templatera' ),
							),
							'name'             => array( 'type' => 'string' ),
							'content'          => array(
								'type'        => 'string',
								'description' => __( 'Only with include_content: the stored shortcode text, unchanged.', 'agent-abilities-for-mcp' ),
							),
							'content_withheld' => array(
								'type'        => 'boolean',
								'description' => __( 'Present and true when the template holds raw HTML, raw JS, a map, a custom onclick setting or Design Options and the user may not post unfiltered HTML.', 'agent-abilities-for-mcp' ),
							),
						),
					),
				),
				'total'             => array( 'type' => 'integer' ),
				'templatera_active' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wpbakery_list_templates',
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
 * Execute aafm/wpbakery-list-templates (design WB-7). Script-bearing content goes only to a user with
 * unfiltered_html (Ruling R-S11-4), as the page read withholds page custom JS. While Templatera is active the rows
 * are its template posts the user may use (T3, S14), source templatera; otherwise the saved option rows.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wpbakery_list_templates( array $input ) {
	$active   = aafm_templatera_active();
	$for_post = null;
	if ( isset( $input['for_post_id'] ) ) {
		$for_post = aafm_exact_object_chain( 'post', absint( $input['for_post_id'] ) );
		if ( ! $for_post instanceof WP_Post || ! aafm_user_can_checked( 'edit_post', $for_post->ID ) ) {
			return aafm_generic_error();
		}
	}
	// While Templatera is active its editor lists only its own template posts (T2), so the list does too.
	$all    = $active ? aafm_templatera_templates( $for_post ) : aafm_wpbakery_saved_templates();
	$paging = aafm_paginate_args( $input + array( 'per_page' => 20 ), 50 );
	$rows   = array();
	foreach ( array_slice( $all, ( $paging['page'] - 1 ) * $paging['per_page'], $paging['per_page'] ) as $template ) {
		$row = array(
			'id'     => $template['id'],
			'source' => $active ? 'templatera' : 'saved',
			'name'   => aafm_sanitize_plain_text( $template['name'] ),
		);
		if ( ! empty( $input['include_content'] ) ) {
			if ( ! current_user_can( 'unfiltered_html' ) && aafm_wpbakery_content_has_script( $template['content'] ) ) {
				$row['content_withheld'] = true;
			} else {
				$row['content'] = $template['content'];
			}
		}
		$rows[] = $row;
	}
	return array(
		'templates'         => $rows,
		'total'             => count( $all ),
		'templatera_active' => $active,
	);
}

/**
 * Args for aafm/wpbakery-insert-template.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_insert_template(): array {
	$insert = aafm_args_wpbakery_insert_element();
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-insert-template' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-insert-template' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_wpbakery_insert_post_schema() + array(
				'template_id' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'The template\'s id from wpbakery-list-templates.', 'agent-abilities-for-mcp' ),
				),
				'source'      => array(
					'type'        => 'string',
					'enum'        => array( 'saved', 'templatera' ),
					'default'     => 'saved',
					'description' => __( 'saved, a template in WPBakery\'s own library; templatera, a Templatera template post, by the id wpbakery-list-templates gives while Templatera is active.', 'agent-abilities-for-mcp' ),
				),
				'mode'        => array(
					'type'        => 'string',
					'enum'        => array( 'copy', 'reference' ),
					'default'     => 'copy',
					'description' => __( 'copy (the default) puts the template\'s own elements on the page; reference, only with source templatera, puts one Templatera element that shows the template\'s current content, so later edits to the template show on the page.', 'agent-abilities-for-mcp' ),
				),
			) + aafm_wpbakery_structural_schema( 'anchor_' ),
			'required'             => array( 'post_id', 'page_fingerprint', 'template_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => $insert['output_schema'],
		'execute_callback'    => 'aafm_exec_wpbakery_insert_template',
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
 * The stored shortcode text of the template an insert names: a saved option row, or with source templatera a
 * published Templatera template post the user may use on this post (aafm_templatera_template_allowed()). Anything
 * else is aafm_builder_unknown_template (404), so a template the user may not use reads
 * the same as a missing one; source templatera while Templatera is off is aafm_templatera_inactive (409).
 *
 * @param array<string,mixed> $input Validated input.
 * @param WP_Post             $post  Post the template goes on.
 * @return string|WP_Error
 */
function aafm_wpbakery_template_text( array $input, WP_Post $post ) {
	$id = (string) ( $input['template_id'] ?? '' );
	if ( 'templatera' === ( $input['source'] ?? 'saved' ) ) {
		if ( ! aafm_templatera_active() ) {
			return new WP_Error(
				'aafm_templatera_inactive',
				__( 'The Templatera add-on is not active, so there is no Templatera template to insert. Nothing was changed.', 'agent-abilities-for-mcp' ),
				array( 'status' => 409 )
			);
		}
		$template = 1 === preg_match( '/^[1-9][0-9]*$/', $id ) ? aafm_exact_object_chain( 'post', (int) $id ) : null;
		if ( ! $template instanceof WP_Post || 'templatera' !== $template->post_type || 'publish' !== $template->post_status ) {
			return aafm_builder_error( 'aafm_builder_unknown_template' );
		}
		return aafm_templatera_template_allowed( $template, $post ) ? (string) $template->post_content : aafm_builder_error( 'aafm_builder_unknown_template' );
	}
	foreach ( aafm_wpbakery_saved_templates() as $template ) {
		if ( $template['id'] === $id ) {
			return $template['content'];
		}
	}
	return aafm_builder_error( 'aafm_builder_unknown_template' );
}

/**
 * The copy's gate on a referenced template's stored text, for a user without unfiltered_html (Ruling B11, SEC-S9b-2):
 * a script kind needs unfiltered_html, and every run of body text goes through aafm_wpbakery_stored_text_gate(), as
 * the trusted branch of aafm_wpbakery_insert_tree() does for a copy. A reference writes only [templatera id], which
 * renders the template's text on the page, so without this the reference would be a way round the copy's gate.
 *
 * @param string $text The template's stored shortcode text.
 * @return true|WP_Error
 */
function aafm_templatera_reference_gate( string $text ) {
	if ( current_user_can( 'unfiltered_html' ) ) {
		return true;
	}
	if ( aafm_wpbakery_content_has_script( $text ) ) {
		return aafm_builder_error( 'aafm_builder_needs_unfiltered_html' );
	}
	$tags = aafm_wpbakery_shortcode_tags( $text );
	if ( null === $tags ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	return aafm_wpbakery_stored_text_gate( $text, $tags );
}

/**
 * Execute aafm/wpbakery-insert-template (design WB-8): copies a saved template through aafm_wpbakery_insert_tree()
 * with trusted_vendor_text, which keeps the stored bytes and applies the limits, nesting, the script gate, the
 * stored text checks (SEC-S9b-2) and the save replay. The guard call, ownership rule and page fingerprint check run
 * first, in aafm_wpbakery_structural_post().
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wpbakery_insert_template( array $input ) {
	$post = aafm_wpbakery_structural_post( $input, true );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$reference = 'reference' === ( $input['mode'] ?? 'copy' );
	if ( $reference && 'templatera' !== ( $input['source'] ?? 'saved' ) ) {
		return new WP_Error(
			'aafm_templatera_reference_needs_templatera',
			__( 'mode reference works only with source templatera: a saved WPBakery template has no element that shows it by reference. Use mode copy. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}
	$text = aafm_wpbakery_template_text( $input, $post );
	if ( is_wp_error( $text ) ) {
		return $text;
	}
	if ( $reference ) {
		// Ruling B11: the reference writes only [templatera id], so the template's own text gets the copy's gate here.
		$gate = aafm_templatera_reference_gate( $text );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$text = '[templatera id="' . absint( $input['template_id'] ) . '"]';
	}
	$tags = aafm_wpbakery_shortcode_tags( $text );
	$tree = null === $tags ? null : aafm_builder_tree( $text, $tags );
	if ( null === $tree || array() === $tree ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$out = aafm_wpbakery_insert_tree(
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
 * The page code fields and their metas (W11).
 *
 * @return array<string,string>
 */
function aafm_wpbakery_page_code_keys(): array {
	return array(
		'custom_css'       => '_wpb_post_custom_css',
		'custom_js_header' => '_wpb_post_custom_js_header',
		'custom_js_footer' => '_wpb_post_custom_js_footer',
	);
}

/**
 * Permission for aafm/wpbakery-update-page-code: edit access to the post and unfiltered_html, for all three fields
 * (operator ruling: page CSS and JS together).
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_wpbakery_page_code( array $input ): bool {
	return aafm_perm_avada_post_object( $input ) && current_user_can( 'unfiltered_html' );
}

/**
 * Args for aafm/wpbakery-update-page-code.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_update_page_code(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-update-page-code' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-update-page-code' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the WPBakery page or post.', 'agent-abilities-for-mcp' ),
				),
				'custom_css'       => array(
					'type'        => 'string',
					'description' => __( 'The page\'s custom CSS, replacing what is stored; an empty string removes it.', 'agent-abilities-for-mcp' ),
				),
				'custom_js_header' => array(
					'type'        => 'string',
					'description' => __( 'JS printed in the page head, replacing what is stored; an empty string removes it.', 'agent-abilities-for-mcp' ),
				),
				'custom_js_footer' => array(
					'type'        => 'string',
					'description' => __( 'JS printed before the page\'s closing body tag, replacing what is stored; an empty string removes it.', 'agent-abilities-for-mcp' ),
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
					'description' => __( 'The old value of each changed field, an empty string where there was none.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wpbakery_update_page_code',
		'permission_callback' => 'aafm_perm_wpbakery_page_code',
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
 * Whether WPBakery's own save would keep custom JS for the current user: its unfiltered_html Role Manager part
 * (modules/custom-js/module.php validate_custom_js_meta()). An absent or failing seam answers no.
 *
 * @return bool
 */
function aafm_wpbakery_custom_js_allowed(): bool {
	$access = aafm_builder_vendor( 'wpbakery_user_access' );
	if ( null === $access ) {
		return false;
	}
	try {
		return true === (bool) $access()->part( 'unfiltered_html' )->checkStateAny( true, null )->get();
	} catch ( Throwable $e ) {
		unset( $e );
		return false;
	}
}

/**
 * Execute aafm/wpbakery-update-page-code (design WB-10). Every check runs before any write; each changed field is
 * written with aafm_meta_set() or removed with aafm_meta_delete(), and a write that does not take is an error.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wpbakery_update_page_code( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( ! aafm_builder_ownership_allows( 'wpbakery', aafm_post_has_foreign_builder_ownership( $id ), $post ) ) {
		return new WP_Error(
			'aafm_not_wpbakery_owned',
			__( 'This post is not a WPBakery page. Edit a page built with another builder in that builder. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	$keys  = aafm_wpbakery_page_code_keys();
	$given = array();
	foreach ( $keys as $field => $key ) {
		if ( isset( $input[ $field ] ) && is_string( $input[ $field ] ) ) {
			$given[ $field ] = $input[ $field ];
		}
	}
	$markup = isset( $given['custom_css'] ) ? aafm_builder_css_markup( 'custom_css', $given['custom_css'] ) ?? aafm_builder_css_vendor_alters( 'wpbakery', 'custom_css', $given['custom_css'] ) : null;
	if ( null !== $markup ) {
		return $markup;
	}
	if ( array() === $given ) {
		return new WP_Error(
			'aafm_wpbakery_page_code_empty',
			__( 'Give at least one of custom_css, custom_js_header and custom_js_footer. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}
	$js = array_intersect_key( $given, array_flip( array( 'custom_js_header', 'custom_js_footer' ) ) );
	foreach ( $js as $field => $value ) {
		// WPBakery prints the value inside a script tag, so a closing tag would end it early.
		if ( 1 === preg_match( '#</script#i', $value ) ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $field );
		}
	}
	if ( array() !== $js && ! aafm_wpbakery_custom_js_allowed() ) {
		return new WP_Error(
			'aafm_wpbakery_custom_js_off',
			__( 'WPBakery\'s Role Manager does not let this user save custom JS, so WPBakery\'s own save would skip it. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 403 )
		);
	}

	$old = aafm_with_checked_reads(
		static function () use ( $id, $keys, $given ): array {
			$old = array();
			foreach ( array_keys( $given ) as $field ) {
				$value         = aafm_meta_get( 'post', $id, $keys[ $field ], true );
				$old[ $field ] = is_scalar( $value ) ? (string) $value : '';
			}
			return $old;
		},
		aafm_generic_error()
	);
	if ( is_wp_error( $old ) ) {
		return $old;
	}

	$changed  = array();
	$previous = array();
	foreach ( $given as $field => $value ) {
		if ( $value === $old[ $field ] ) {
			continue;
		}
		$key = $keys[ $field ];
		if ( '' === $value ) {
			$result = aafm_meta_delete( 'post', $id, $key );
			$status = (string) ( $result['status'] ?? AAFM_WRITE_REFUSED );
			$ok     = in_array( $status, array( AAFM_WRITE_DELETED, AAFM_WRITE_ABSENT ), true );
		} else {
			$result = aafm_meta_set( 'post', $id, $key, $value, $post->post_type );
			$status = is_wp_error( $result ) ? AAFM_WRITE_REFUSED : (string) ( $result['status'] ?? AAFM_WRITE_REFUSED );
			$ok     = in_array( $status, array( AAFM_WRITE_WRITTEN, AAFM_WRITE_UNCHANGED ), true );
		}
		if ( ! $ok ) {
			$error = aafm_meta_write_error( $status, '' === $value ? 'delete' : 'write', 'post', $id, $key );
			$error->add_data(
				array_merge(
					(array) $error->get_error_data(),
					array(
						'field'    => $field,
						'changed'  => $changed,
						'previous' => $previous,
					)
				)
			);
			return $error;
		}
		$changed[]          = $field;
		$previous[ $field ] = $old[ $field ];
	}
	return array(
		'post_id'  => $id,
		'changed'  => $changed,
		// An empty previous goes out as {} so the wire shape matches the object the schema declares.
		'previous' => array() === $previous ? new stdClass() : $previous,
	);
}

/**
 * The WPBakery site-wide settings this plugin reads, each stored as the option wpb_js_ plus the key (design WB-14,
 * class-vc-settings.php:430-486). A fixed allowlist, never a prefix scan: the licence code and its token are wpb_js_
 * options too, and are outside it.
 *
 * @return list<string>
 */
function aafm_wpbakery_settings_keys(): array {
	return array( 'not_responsive_css', 'google_fonts_subsets', 'local_google_fonts', 'shortcuts', 'css_loading', 'js_loading', 'gutenberg_disable', 'auto_save', 'use_custom', 'custom_css', 'custom_js_header', 'custom_js_footer' );
}

/**
 * The settings that hold global JS, returned and written only for a user who may post unfiltered HTML.
 *
 * @return list<string>
 */
function aafm_wpbakery_settings_js_keys(): array {
	return array( 'custom_js_header', 'custom_js_footer' );
}

/**
 * Args for aafm/wpbakery-get-settings.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_get_settings(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-get-settings' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-get-settings' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'settings'          => array(
					'type'        => 'object',
					'description' => __( 'Setting key to its stored value, unchanged.', 'agent-abilities-for-mcp' ),
				),
				'not_set'           => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Listed settings with no stored value; WPBakery\'s default applies.', 'agent-abilities-for-mcp' ),
				),
				'editor_post_types' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'version'           => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wpbakery_get_settings',
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
 * The stored rows of the given settings: key => value for each row that exists. One get_option() per key with a
 * sentinel default, so an absent row is told apart from a stored empty value.
 *
 * @param array<int,string> $keys Setting keys.
 * @return array<string,mixed>
 */
function aafm_wpbakery_stored_settings( array $keys ): array {
	$absent = new stdClass();
	$out    = array();
	foreach ( $keys as $key ) {
		$value = get_option( 'wpb_js_' . $key, $absent );
		if ( $value !== $absent ) {
			$out[ $key ] = $value;
		}
	}
	return $out;
}

/**
 * Execute aafm/wpbakery-get-settings (design WB-14). The global JS keys are left out entirely, from settings and
 * from not_set, for a user who may not post unfiltered HTML.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_wpbakery_get_settings( array $input ): array {
	unset( $input );
	$keys = aafm_wpbakery_settings_keys();
	if ( ! current_user_can( 'unfiltered_html' ) ) {
		$keys = array_values( array_diff( $keys, aafm_wpbakery_settings_js_keys() ) );
	}
	$stored = aafm_wpbakery_stored_settings( $keys );
	$types  = aafm_builder_vendor( 'wpbakery_editor_post_types' );
	$types  = null !== $types ? $types() : array();
	return array(
		// An empty map goes out as {} so the wire shape matches the object the schema declares.
		'settings'          => array() === $stored ? new stdClass() : $stored,
		'not_set'           => array_values( array_diff( $keys, array_keys( $stored ) ) ),
		'editor_post_types' => array_values( array_map( 'strval', array_filter( is_array( $types ) ? $types : array(), 'is_string' ) ) ),
		'version'           => defined( 'WPB_VC_VERSION' ) ? (string) constant( 'WPB_VC_VERSION' ) : '',
	);
}

/**
 * The settings aafm/wpbakery-update-settings may write: the read list minus use_custom (Design Options' own switch,
 * WB-14) and minus the keys whose sanitize callback is not a method on a vendor object (Ruling R3-P8): gutenberg_disable
 * and auto_save are registered with plain functions (include/autoload/vendors/gutenberg.php:28,
 * include/autoload/hooks/vc-settings-auto-save.php:21), so scratchpad/1113/s13-wpbakery-callbacks.md records them NOT
 * RESOLVED and they are refused, never validated by a rule made up here.
 *
 * @return list<string>
 */
function aafm_wpbakery_settings_writable(): array {
	return array( 'not_responsive_css', 'google_fonts_subsets', 'local_google_fonts', 'shortcuts', 'css_loading', 'js_loading', 'custom_css', 'custom_js_header', 'custom_js_footer' );
}

/**
 * The vendor sanitize callback of one writable setting, as a method on the object that defines it (Ruling B10), or
 * null when it cannot be resolved: the general tab methods on vc_settings(), the custom CSS and custom JS methods on
 * their modules' settings objects, only while that module is on (otherwise WPBakery neither prints the value nor
 * rebuilds custom.css).
 *
 * @param string $key Setting key.
 * @return callable|null
 */
function aafm_wpbakery_setting_callback( string $key ): ?callable {
	$modules = array(
		'custom_css'       => 'vc-custom-css',
		'custom_js_header' => 'vc-custom-js',
		'custom_js_footer' => 'vc-custom-js',
	);
	try {
		if ( isset( $modules[ $key ] ) ) {
			$manager = aafm_builder_vendor( 'wpbakery_modules' );
			$manager = null !== $manager ? $manager() : null;
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'is_module_on' ) || ! method_exists( $manager, 'get_module' ) || true !== $manager->is_module_on( $modules[ $key ] ) ) {
				return null;
			}
			// ponytail: get_module() builds a second module instance, which adds WPBakery's post-save filters again for the
			// rest of this request; no settings call saves a post, so nothing runs them twice.
			$module = $manager->get_module( $modules[ $key ] );
			$object = is_object( $module ) && isset( $module->settings ) && is_object( $module->settings ) ? $module->settings : null;
		} else {
			$settings = aafm_builder_vendor( 'wpbakery_settings' );
			$object   = null !== $settings ? $settings() : null;
		}
	} catch ( Throwable $e ) {
		unset( $e );
		return null;
	}
	$method   = 'sanitize_' . $key . '_callback';
	$callback = is_object( $object ) && method_exists( $object, $method ) ? array( $object, $method ) : null;
	return is_callable( $callback ) ? $callback : null;
}

/**
 * One setting value as WPBakery's own callback stores it, or a refusal naming the setting. The JSON type is checked
 * first; a choice or a subset list the callback would change is refused, so a value is never stored other than as
 * given. Checkboxes are stored as '1' or '', the form the settings screen leaves in the database.
 *
 * @param string $key   Setting key.
 * @param mixed  $value Input.
 * @return mixed|WP_Error
 */
function aafm_wpbakery_sanitize_setting( string $key, $value ) {
	$bools = array( 'not_responsive_css', 'local_google_fonts', 'shortcuts' );
	if ( in_array( $key, $bools, true ) && in_array( $value, array( '1', '' ), true ) ) {
		// The stored form of a checkbox, as previous returns it.
		$value = '1' === $value;
	}
	$ok = in_array( $key, $bools, true ) ? is_bool( $value )
		: ( 'google_fonts_subsets' === $key ? is_array( $value ) && array_values( $value ) === $value && array() === array_filter( $value, static fn( $one ): bool => ! is_string( $one ) ) : is_string( $value ) );
	if ( ! $ok ) {
		return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $key );
	}
	$markup = 'custom_css' === $key && is_string( $value ) ? aafm_builder_css_markup( $key, $value ) ?? aafm_builder_css_vendor_alters( 'wpbakery', $key, $value ) : null;
	if ( null !== $markup ) {
		return $markup;
	}
	if ( in_array( $key, aafm_wpbakery_settings_js_keys(), true ) && is_string( $value ) && 1 === preg_match( '#</script#i', $value ) ) {
		// WPBakery prints the value inside a script tag, so a closing tag would end it early.
		return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $key );
	}
	$callback = aafm_wpbakery_setting_callback( $key );
	if ( null === $callback ) {
		return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $key );
	}
	try {
		$clean = call_user_func( $callback, $value );
	} catch ( Throwable $e ) {
		unset( $e );
		return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $key );
	}
	if ( in_array( $key, $bools, true ) ) {
		return $clean ? '1' : '';
	}
	if ( 'custom_css' === $key ) {
		// WordPress core's custom CSS rule above, not WPBakery's strip: the CSS is stored as sent (CR-3).
		return $value;
	}
	if ( in_array( $key, array( 'google_fonts_subsets', 'css_loading', 'js_loading' ), true ) && $clean !== $value ) {
		return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $key );
	}
	return is_string( $clean ) || is_array( $clean ) ? $clean : aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $key );
}

/**
 * Args for aafm/wpbakery-update-settings.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_update_settings(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-update-settings' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-update-settings' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'set' => array(
					'type'        => 'object',
					'description' => __( 'Setting key to its new value, keys from wpbakery-get-settings.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'set' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
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
		'execute_callback'    => 'aafm_exec_wpbakery_update_settings',
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
 * Whether WPBakery's custom.css file holds what it writes for $css (custom-css/module.php build_custom_css(): the
 * option with each url(../ pointed at WPBakery's assets folder). Both sides have those references reduced to url( so
 * the comparison does not depend on the assets URL. False when the file cannot be read.
 *
 * @param string $css Stored global CSS.
 * @return bool
 */
function aafm_wpbakery_custom_css_file_matches( string $css ): bool {
	$settings = aafm_builder_vendor( 'wpbakery_settings' );
	try {
		$object = null !== $settings ? $settings() : null;
		if ( ! is_object( $object ) || ! method_exists( $object, 'getFileSystem' ) || ! method_exists( $object, 'uploadDir' ) ) {
			return false;
		}
		// Both are static methods on WPBakery's settings class (class-vc-settings.php:1057, :1092).
		$class = get_class( $object );
		$fs    = call_user_func( array( $class, 'getFileSystem' ) );
		$file  = is_object( $fs ) && method_exists( $fs, 'get_contents' ) ? $fs->get_contents( (string) call_user_func( array( $class, 'uploadDir' ) ) . '/custom.css' ) : false;
	} catch ( Throwable $e ) {
		unset( $e );
		return false;
	}
	if ( ! is_string( $file ) ) {
		return false;
	}
	$want = preg_replace( '#url\(\.\./(?!\.)#', 'url(', wp_strip_all_tags( $css ) );
	$have = preg_replace( '#url\([^)]*?/assets/#', 'url(', $file );
	return $want === $have;
}

/**
 * Execute aafm/wpbakery-update-settings (design WB-14). Every key and value is checked and sanitized before anything
 * is written; each changed setting is written with aafm_update_option_verified(), and a write that does not certify
 * is an error naming what had already landed. A custom_css change loads wp-admin's template functions first, because
 * WPBakery's file rebuild calls add_settings_error() when it fails (W19), and is checked against the file after.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wpbakery_update_settings( array $input ) {
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
		if ( ! in_array( $key, aafm_wpbakery_settings_writable(), true ) ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $key );
		}
		if ( in_array( $key, array( 'custom_css', 'custom_js_header', 'custom_js_footer' ), true ) && ! current_user_can( 'unfiltered_html' ) ) {
			return aafm_builder_error( 'aafm_builder_needs_unfiltered_html' );
		}
		if ( null === $value ) {
			// previous names a setting that was not stored with null; it is removed again.
			$apply[ $key ] = null;
			continue;
		}
		$clean = aafm_wpbakery_sanitize_setting( $key, $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$apply[ $key ] = $clean;
	}

	$old      = aafm_wpbakery_stored_settings( array_keys( $apply ) );
	$changed  = array();
	$previous = array();
	foreach ( $apply as $key => $value ) {
		$before = $old[ $key ] ?? null;
		if ( ( null === $before && null === $value ) || ( null !== $before && ( $before === $value || ( is_bool( $before ) && ( $before ? '1' : '' ) === $value ) ) ) ) {
			continue;
		}
		if ( 'custom_css' === $key ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		if ( null === $value ) {
			// WPBakery rebuilds custom.css only when the option is added or updated, never on a delete, so the CSS is
			// emptied first and the row removed after.
			$saved = ( 'custom_css' !== $key || aafm_update_option_verified( 'wpb_js_' . $key, '' ) ) && aafm_delete_option_cache_safe( 'wpb_js_' . $key );
		} else {
			$saved = aafm_update_option_verified( 'wpb_js_' . $key, $value );
		}
		if ( ! $saved ) {
			return new WP_Error(
				'aafm_wpbakery_setting_not_saved',
				/* translators: %s: the setting key. */
				sprintf( __( 'The WPBakery setting %s could not be saved. Settings listed in changed were saved before it.', 'agent-abilities-for-mcp' ), $key ),
				array(
					'status'   => 500,
					'setting'  => $key,
					'changed'  => $changed,
					'previous' => $previous,
				)
			);
		}
		$changed[]        = $key;
		$previous[ $key ] = $before;
	}
	if ( in_array( 'custom_css', $changed, true ) && ! aafm_wpbakery_custom_css_file_matches( (string) $apply['custom_css'] ) ) {
		return new WP_Error(
			'aafm_wpbakery_css_stale',
			__( 'The global CSS was saved, but WPBakery\'s custom.css file does not match it, so the site may still show the old CSS. Check that the uploads folder is writable, then save the CSS again.', 'agent-abilities-for-mcp' ),
			array(
				'status'   => 500,
				'changed'  => $changed,
				'previous' => $previous,
			)
		);
	}
	return array(
		'changed'  => $changed,
		// An empty previous goes out as {} so the wire shape matches the object the schema declares.
		'previous' => array() === $previous ? new stdClass() : $previous,
	);
}
