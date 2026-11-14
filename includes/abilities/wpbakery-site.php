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
			'description'  => __( 'Lists the templates saved in WPBakery Page Builder\'s own template library on this site: id, source (saved) and name, plus total. With include_content each template\'s stored shortcode text comes back byte for byte; for a user who may not post unfiltered HTML, a template holding raw HTML, raw JS, a map, a custom onclick setting or Design Options comes back without its content and with content_withheld true. templatera_active says whether the Templatera add-on is active; while it is, WPBakery\'s editor shows Templatera\'s own template list instead of these. Requires the edit_posts capability; WPBakery\'s Role Manager settings are not applied. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_list_templates',
		),
		'aafm/wpbakery-insert-template'  => array(
			'label'        => __( 'Insert a WPBakery template', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Copies a template saved in WPBakery\'s own library, named by its id from wpbakery-list-templates, into a WPBakery page or Grid Builder template, before or after an element from the outline of wpbakery-get-page-content or as its first or last child. The template\'s stored text is copied byte for byte. WPBakery\'s own nesting rules apply to its top level, and a template may hold at most 50 elements, nested at most 6 deep. A template holding raw HTML, raw JS, a map, a custom onclick setting or Design Options needs a user who may post unfiltered HTML, and for any other user its text may hold no square bracket, percent-encoded bracket, data-* attribute, unfinished tag or HTML that WordPress would remove. Requires page_fingerprint from the last read or write and returns the new one; a changed page is refused and nothing is saved. Other indexes may move, so read the outline again before the next structural change. When a revision is kept, restore_revision_id brings the page back through restore-revision. The page\'s generated CSS is rebuilt. Not undoable from this plugin\'s change history. Requires edit access to the post; WPBakery\'s Role Manager settings are not applied, though its own save filter still runs. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_insert_template',
		),
		'aafm/wpbakery-update-page-code' => array(
			'label'        => __( 'Update WPBakery page code', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Sets the custom CSS, header JS and footer JS of one WPBakery page, the fields of the page settings in WPBakery\'s editor; give at least one of custom_css, custom_js_header and custom_js_footer. Each field given replaces the page\'s existing code for that field, and an empty string removes it; previous returns the old value of every field that changed, which is the way back. CSS is stored without HTML tags, as WPBakery prints it. JS containing a closing script tag is refused, and the JS fields are refused when WPBakery\'s own Role Manager would not save custom JS for this user. A field equal to what is stored is left alone and not listed in changed. No revision is kept, and the change is not undoable from this plugin\'s change history. Requires edit access to the post and a user who may post unfiltered HTML. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_update_page_code',
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
								'enum' => array( 'saved' ),
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
 * unfiltered_html (Ruling R-S11-4), as the page read withholds page custom JS.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_wpbakery_list_templates( array $input ): array {
	$all    = aafm_wpbakery_saved_templates();
	$paging = aafm_paginate_args( $input + array( 'per_page' => 20 ), 50 );
	$rows   = array();
	foreach ( array_slice( $all, ( $paging['page'] - 1 ) * $paging['per_page'], $paging['per_page'] ) as $template ) {
		$row = array(
			'id'     => $template['id'],
			'source' => 'saved',
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
		'templatera_active' => aafm_templatera_active(),
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
			'properties'           => aafm_wpbakery_structural_schema( '' ) + array(
				'template_id' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'The template\'s id from wpbakery-list-templates.', 'agent-abilities-for-mcp' ),
				),
				'source'      => array(
					'type'        => 'string',
					'enum'        => array( 'saved' ),
					'default'     => 'saved',
					'description' => __( 'saved, a template in WPBakery\'s own library.', 'agent-abilities-for-mcp' ),
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
	$id   = (string) ( $input['template_id'] ?? '' );
	$text = null;
	foreach ( aafm_wpbakery_saved_templates() as $template ) {
		if ( $template['id'] === $id ) {
			$text = $template['content'];
			break;
		}
	}
	if ( null === $text || 'saved' !== ( $input['source'] ?? 'saved' ) ) {
		return aafm_builder_error( 'aafm_builder_unknown_template' );
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
			$given[ $field ] = 'custom_css' === $field ? wp_strip_all_tags( $input[ $field ] ) : $input[ $field ];
		}
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
		'previous' => $previous,
	);
}
