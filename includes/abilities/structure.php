<?php
/**
 * Site-structure read abilities: public taxonomies, public post types, redacted site info.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_structure_definitions' );

/**
 * Contribute structure ability definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_structure_definitions( array $registry ): array {
	$registry['aafm/get-taxonomies'] = array(
		'label'        => __( 'Get taxonomies', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List public taxonomies registered on the site.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_taxonomies',
	);
	$registry['aafm/get-post-types'] = array(
		'label'        => __( 'Get post types', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List public post types registered on the site. Each type includes a writable flag indicating whether agents may create/update items of that type.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_post_types',
	);
	$registry['aafm/get-site-info']  = array(
		'label'        => __( 'Get site info', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Retrieve the site name, tagline, URL, and language.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_site_info',
	);
	return $registry;
}

/**
 * Build the shared no-input read schema used by the list-returning structure reads.
 *
 * @param string $label   Ability label.
 * @param string $desc    Ability description.
 * @param string $execute Execute callback name.
 * @param string $out_key Output property key (the array of objects returned).
 * @return array<string,mixed>
 */
function aafm_args_structure_read( string $label, string $desc, string $execute, string $out_key ): array {
	return array(
		'label'               => $label,
		'description'         => $desc,
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				$out_key => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			),
		),
		'execute_callback'    => $execute,
		'permission_callback' => 'aafm_perm_read',
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
 * Args for aafm/get-taxonomies.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_taxonomies(): array {
	$args = aafm_args_structure_read(
		aafm_ability_label( 'aafm/get-taxonomies' ),
		aafm_ability_description( 'aafm/get-taxonomies' ),
		'aafm_exec_get_taxonomies',
		'taxonomies'
	);
	// Declare the per-taxonomy item shape so the published schema documents each field (A3).
	$args['output_schema']['properties']['taxonomies']['items'] = array(
		'type'       => 'object',
		'properties' => array(
			'slug'         => array( 'type' => 'string' ),
			'label'        => array( 'type' => 'string' ),
			'hierarchical' => array( 'type' => 'boolean' ),
			'public'       => array( 'type' => 'boolean' ),
			'object_types' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
		),
	);
	return $args;
}

/**
 * Execute aafm/get-taxonomies.
 *
 * Returns PUBLIC taxonomies only - private/internal object types (e.g. nav_menu,
 * link_category) are never exposed.
 *
 * @return array<string,mixed>
 */
function aafm_exec_get_taxonomies(): array {
	$out = array();
	foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
		$out[] = array(
			'slug'         => $tax->name,
			'label'        => $tax->label,
			'hierarchical' => (bool) $tax->hierarchical,
			'public'       => (bool) $tax->public,
			'object_types' => array_values( (array) $tax->object_type ),
		);
	}
	return array( 'taxonomies' => $out );
}

/**
 * Args for aafm/get-post-types.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_post_types(): array {
	$args = aafm_args_structure_read(
		aafm_ability_label( 'aafm/get-post-types' ),
		aafm_ability_description( 'aafm/get-post-types' ),
		'aafm_exec_get_post_types',
		'post_types'
	);
	// Declare the per-type item shape so the writable boolean is part of the published schema.
	$args['output_schema']['properties']['post_types']['items'] = array(
		'type'       => 'object',
		'properties' => array(
			'slug'         => array( 'type' => 'string' ),
			'label'        => array( 'type' => 'string' ),
			'hierarchical' => array( 'type' => 'boolean' ),
			'public'       => array( 'type' => 'boolean' ),
			'writable'     => array( 'type' => 'boolean' ),
		),
	);
	return $args;
}

/**
 * Execute aafm/get-post-types.
 *
 * Returns PUBLIC post types only - internal types (revision, nav_menu_item, etc.)
 * are never exposed.
 *
 * @return array<string,mixed>
 */
function aafm_exec_get_post_types(): array {
	$writable = aafm_allowed_post_types();
	$out      = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
		$out[] = array(
			'slug'         => $type->name,
			'label'        => $type->label,
			'hierarchical' => (bool) $type->hierarchical,
			'public'       => (bool) $type->public,
			// Agent-actionable: true only when the operator has exposed this type to the CPT
			// write abilities. A public-but-not-writable type would pass schema validation on a
			// create/update CPT call and then be rejected at execute, so the agent needs this
			// flag to pick a valid post_type up front.
			'writable'     => in_array( $type->name, $writable, true ),
		);
	}
	return array( 'post_types' => $out );
}

/**
 * Args for aafm/get-site-info.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_site_info(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-site-info' ),
		'description'         => aafm_ability_description( 'aafm/get-site-info' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'site' => array(
					'type'       => 'object',
					'properties' => array(
						'name'     => array( 'type' => 'string' ),
						'tagline'  => array( 'type' => 'string' ),
						'url'      => array( 'type' => 'string' ),
						'language' => array( 'type' => 'string' ),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_get_site_info',
		'permission_callback' => 'aafm_perm_read',
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
 * Execute aafm/get-site-info.
 *
 * SECURITY-CRITICAL redaction. Returns ONLY the whitelisted, non-sensitive
 * descriptor below. It deliberately NEVER discloses: the WordPress version, PHP
 * version, server software, any filesystem path, the admin email, debug status,
 * or the installed plugin/theme list - the exact fields competitor plugins leaked.
 *
 * @return array<string,mixed>
 */
function aafm_exec_get_site_info(): array {
	return array(
		'site' => array(
			'name'     => get_bloginfo( 'name' ),
			'tagline'  => get_bloginfo( 'description' ),
			'url'      => home_url(),
			'language' => get_bloginfo( 'language' ),
		),
	);
}
