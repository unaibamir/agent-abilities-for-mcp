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
		'aafm/wpbakery-list-templates' => array(
			'label'        => __( 'List WPBakery templates', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the templates saved in WPBakery Page Builder\'s own template library on this site: id, source (saved) and name, plus total. With include_content each template\'s stored shortcode text comes back byte for byte; for a user who may not post unfiltered HTML, a template holding raw HTML, raw JS, a map, a custom onclick setting or Design Options comes back without its content and with content_withheld true. templatera_active says whether the Templatera add-on is active; while it is, WPBakery\'s editor shows Templatera\'s own template list instead of these. Requires the edit_posts capability; WPBakery\'s Role Manager settings are not applied. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_list_templates',
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
