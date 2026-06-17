<?php
/**
 * Navigation-menu READ abilities.
 *
 * Exposes the WordPress nav-menu core API read-only: list every menu, read one menu's
 * metadata by id, and list the items inside a menu. All three gate on edit_theme_options —
 * the capability WordPress puts on the Appearance > Menus screen, so an agent is held to the
 * same bar a human editor is.
 *
 * The permission is object-INDEPENDENT: WordPress has no per-menu capability (a menu is a
 * nav_menu term, and the whole Menus screen sits behind one site-wide cap). So the discovery
 * layer falls through to this callback with no per-object case in server.php — there is
 * nothing to scope per menu id.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_menus_definitions' );

/**
 * Contribute the nav-menu read definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_menus_definitions( array $registry ): array {
	$registry['aafm/list-menus']      = array(
		'label'        => __( 'List menus', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists the navigation menus by id, name, slug, and item count. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_menus',
	);
	$registry['aafm/get-menu']        = array(
		'label'        => __( 'Get menu', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one navigation menu by id: its name, slug, and item count. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_menu',
	);
	$registry['aafm/list-menu-items'] = array(
		'label'        => __( 'List menu items', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists the items in a navigation menu by id: each item id, title, URL, what it links to, and its place in the order. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_menu_items',
	);
	return $registry;
}

/**
 * Shared permission for the whole menus/themes family: edit_theme_options.
 *
 * This is the cap WordPress gates the Appearance screens (Menus, Themes, Customize) behind.
 * It is DEFINED EXACTLY ONCE here — menus.php loads before any later themes ability, which
 * must reuse this callback and never redefine it. The check is object-independent (WordPress
 * has no per-menu capability), so discovery falls through to it with no server.php case.
 *
 * @return bool
 */
function aafm_perm_edit_theme_options(): bool {
	return current_user_can( 'edit_theme_options' );
}

/**
 * Args for aafm/list-menus.
 *
 * @return array<string,mixed>
 */
function aafm_args_list_menus(): array {
	return array(
		'label'               => __( 'List menus', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists the navigation menus by id, name, slug, and item count. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'menus' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_menu_output_properties(),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_list_menus',
		'permission_callback' => 'aafm_perm_edit_theme_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/list-menus.
 *
 * Returns every registered nav menu, redacted to id/name/slug/count.
 *
 * @return array<string,mixed>
 */
function aafm_exec_list_menus(): array {
	$menus = array();
	foreach ( wp_get_nav_menus() as $menu ) {
		$menus[] = aafm_redact_menu( $menu );
	}
	return array( 'menus' => $menus );
}

/**
 * Args for aafm/get-menu.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_menu(): array {
	return array(
		'label'               => __( 'Get menu', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads one navigation menu by id. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu_id' => array( 'type' => 'integer' ),
			),
			'required'             => array( 'menu_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'    => array( 'type' => 'integer' ),
				'name'  => array( 'type' => 'string' ),
				'slug'  => array( 'type' => 'string' ),
				'count' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_menu',
		'permission_callback' => 'aafm_perm_edit_theme_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/get-menu.
 *
 * Resolves the menu by id; an unknown id (or a term that is not a nav menu) returns a
 * generic error rather than leaking which ids exist.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_menu( array $input ) {
	$menu = wp_get_nav_menu_object( (int) $input['menu_id'] );
	if ( ! $menu instanceof WP_Term ) {
		return aafm_generic_error();
	}
	return aafm_redact_menu( $menu );
}

/**
 * Args for aafm/list-menu-items.
 *
 * @return array<string,mixed>
 */
function aafm_args_list_menu_items(): array {
	return array(
		'label'               => __( 'List menu items', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists the items in a navigation menu by id. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu_id' => array( 'type' => 'integer' ),
			),
			'required'             => array( 'menu_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_menu_item_output_properties(),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_list_menu_items',
		'permission_callback' => 'aafm_perm_edit_theme_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/list-menu-items.
 *
 * Returns the items in the menu, each redacted to the menu-relevant fields. An unknown or
 * empty menu yields an empty items list — wp_get_nav_menu_items() returns false for a bad id,
 * which is treated as no items.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_list_menu_items( array $input ): array {
	$raw = wp_get_nav_menu_items( (int) $input['menu_id'] );
	if ( ! is_array( $raw ) ) {
		$raw = array();
	}
	$items = array();
	foreach ( $raw as $item ) {
		$items[] = aafm_redact_menu_item( $item );
	}
	return array( 'items' => $items );
}
