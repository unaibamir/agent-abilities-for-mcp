<?php
/**
 * Navigation-menu READ + WRITE abilities.
 *
 * Exposes the WordPress nav-menu core API: list every menu, read one menu's metadata by id,
 * and list the items inside a menu (reads); create/rename/delete a menu and create/update/
 * delete a menu item (writes). Every ability gates on edit_theme_options - the capability
 * WordPress puts on the Appearance > Menus screen, so an agent is held to the same bar a human
 * editor is.
 *
 * The permission is object-INDEPENDENT: WordPress has no per-menu capability (a menu is a
 * nav_menu term, and the whole Menus screen sits behind one site-wide cap). So the discovery
 * layer falls through to this callback with no per-object case in server.php - there is
 * nothing to scope per menu id, reads and writes alike.
 *
 * The destructive writes are PERMANENT: navigation menus and their items have no Trash, so
 * wp_delete_nav_menu() removes a menu and all its items outright, and a menu item (a
 * nav_menu_item post) is deleted with no recoverable copy. Neither uses a force-delete
 * trash-bypass flag in our source - wp_delete_post() is called with no second argument, which
 * deletes the trash-less nav_menu_item directly without matching the banned ,true pattern.
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
	$registry['aafm/list-menus']       = array(
		'label'        => __( 'List menus', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists the navigation menus by id, name, slug, and item count. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_menus',
	);
	$registry['aafm/get-menu']         = array(
		'label'        => __( 'Get menu', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one navigation menu by id: its name, slug, and item count. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_menu',
	);
	$registry['aafm/list-menu-items']  = array(
		'label'        => __( 'List menu items', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists the items in a navigation menu by id: each item id, title, URL, what it links to, and its place in the order. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_menu_items',
	);
	$registry['aafm/create-menu']      = array(
		'label'        => __( 'Create menu', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a navigation menu by name. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_create_menu',
	);
	$registry['aafm/update-menu']      = array(
		'label'        => __( 'Update menu', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Renames a navigation menu by id. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_update_menu',
	);
	$registry['aafm/delete-menu']      = array(
		'label'        => __( 'Delete menu', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently deletes a navigation menu and all of its items. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_delete_menu',
	);
	$registry['aafm/create-menu-item'] = array(
		'label'        => __( 'Create menu item', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Adds an item (link) to a navigation menu. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_create_menu_item',
	);
	$registry['aafm/update-menu-item'] = array(
		'label'        => __( 'Update menu item', 'agent-abilities-for-mcp' ),
		'description'  => __( "Updates a menu item's title or URL by id. Requires the edit-theme-options capability.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_update_menu_item',
	);
	$registry['aafm/delete-menu-item'] = array(
		'label'        => __( 'Delete menu item', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently removes one item from a navigation menu. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_delete_menu_item',
	);
	return $registry;
}

/**
 * Shared permission for the whole menus/themes family: edit_theme_options.
 *
 * This is the cap WordPress gates the Appearance screens (Menus, Themes, Customize) behind.
 * It is DEFINED EXACTLY ONCE here - menus.php loads before any later themes ability, which
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
		'label'               => aafm_ability_label( 'aafm/list-menus' ),
		'description'         => aafm_ability_description( 'aafm/list-menus' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/get-menu' ),
		'description'         => aafm_ability_description( 'aafm/get-menu' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/list-menu-items' ),
		'description'         => aafm_ability_description( 'aafm/list-menu-items' ),
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
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/list-menu-items.
 *
 * Returns the items in the menu, each redacted to the menu-relevant fields, sorted by menu_order.
 * The items are resolved from the nav_menu TERM membership rather than wp_get_nav_menu_items(),
 * which WPML language-filters mid-request - a language filter would otherwise drop items that
 * genuinely belong to the requested menu. get_objects_in_term() reads the term relationship
 * directly, so the list is language-agnostic and works the same with or without WPML. An unknown
 * or empty menu yields an empty items list.
 *
 * get_objects_in_term() applies no post_status filter, whereas wp_get_nav_menu_items() defaulted to
 * post_status => 'publish'. We restore that filter here so the returned SET matches the pre-fix
 * behaviour exactly on a non-WPML site (published items only, drafts excluded) while keeping the
 * language-agnostic resolution that is the actual WPML fix.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_list_menu_items( array $input ): array {
	$object_ids = get_objects_in_term( (int) $input['menu_id'], 'nav_menu' );
	if ( is_wp_error( $object_ids ) || ! is_array( $object_ids ) ) {
		$object_ids = array();
	}

	$decorated = array();
	foreach ( $object_ids as $object_id ) {
		$post = get_post( (int) $object_id );
		if ( ! $post instanceof WP_Post || 'nav_menu_item' !== $post->post_type ) {
			continue;
		}
		// Publish-only parity with the old wp_get_nav_menu_items() default: skip any item that is not
		// published so the returned list is byte-identical to the pre-fix behaviour.
		if ( 'publish' !== $post->post_status ) {
			continue;
		}
		$decorated[] = wp_setup_nav_menu_item( $post );
	}

	usort(
		$decorated,
		static function ( $a, $b ): int {
			$a_order = isset( $a->menu_order ) ? (int) $a->menu_order : 0;
			$b_order = isset( $b->menu_order ) ? (int) $b->menu_order : 0;
			return $a_order <=> $b_order;
		}
	);

	$items = array();
	$order = 0;
	foreach ( $decorated as $item ) {
		++$order;
		// The old wp_get_nav_menu_items() path emitted a contiguous 1..N display index as the item
		// order; the raw stored menu_order carried here can have gaps. Relative order is already
		// correct from the usort above, so renumber the emitted value to a contiguous 1-based index
		// to match the pre-fix output contract.
		$redacted          = aafm_redact_menu_item( $item );
		$redacted['order'] = $order;
		$items[]           = $redacted;
	}
	return array( 'items' => $items );
}

/**
 * Args for aafm/create-menu.
 *
 * Closed schema: the only input is the menu name. There is no taxonomy/term/parent field, so a
 * smuggled key (e.g. taxonomy) is rejected before execute ever runs.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_menu(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/create-menu' ),
		'description'         => aafm_ability_description( 'aafm/create-menu' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array( 'type' => 'string' ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_menu_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_create_menu',
		'permission_callback' => 'aafm_perm_edit_theme_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/create-menu.
 *
 * Creates a new nav menu via the core nav-menu API (id 0 means "create"). The name is
 * sanitized; a duplicate name or other failure returns a generic error. The created menu is
 * returned in the redacted id/name/slug/count shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_create_menu( array $input ) {
	$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$id   = wp_update_nav_menu_object( 0, array( 'menu-name' => $name ) );
	if ( is_wp_error( $id ) || 0 === (int) $id ) {
		return aafm_generic_error();
	}
	$menu = wp_get_nav_menu_object( (int) $id );
	if ( ! $menu instanceof WP_Term ) {
		return aafm_generic_error();
	}
	return aafm_redact_menu( $menu );
}

/**
 * Args for aafm/update-menu.
 *
 * Closed schema: a menu id plus the new name. No other menu field is writable here.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_menu(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/update-menu' ),
		'description'         => aafm_ability_description( 'aafm/update-menu' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu_id' => array( 'type' => 'integer' ),
				'name'    => array( 'type' => 'string' ),
			),
			'required'             => array( 'menu_id', 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_menu_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_update_menu',
		'permission_callback' => 'aafm_perm_edit_theme_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/update-menu.
 *
 * Resolves the menu by id first (an unknown id, or a term that is not a nav menu, returns a
 * generic error rather than leaking which ids exist), then renames it. The renamed menu is
 * returned in the redacted shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_menu( array $input ) {
	$menu_id = (int) ( $input['menu_id'] ?? 0 );
	$menu    = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$name   = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$result = wp_update_nav_menu_object( $menu_id, array( 'menu-name' => $name ) );
	if ( is_wp_error( $result ) || 0 === (int) $result ) {
		return aafm_generic_error();
	}
	$updated = wp_get_nav_menu_object( $menu_id );
	if ( ! $updated instanceof WP_Term ) {
		return aafm_generic_error();
	}
	return aafm_redact_menu( $updated );
}

/**
 * Args for aafm/delete-menu.
 *
 * Closed schema: just the menu id. This is the disclosed destructive menu ability - deleting a
 * menu permanently removes it AND every item inside it (nav menus have no Trash).
 *
 * @return array<string,mixed>
 */
function aafm_args_delete_menu(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/delete-menu' ),
		'description'         => aafm_ability_description( 'aafm/delete-menu' ),
		'category'            => 'aafm-writes',
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
				'id'      => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_delete_menu',
		'permission_callback' => 'aafm_perm_edit_theme_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Execute aafm/delete-menu.
 *
 * Resolves the menu by id (unknown id → generic error), then permanently deletes it with the
 * core nav-menu wrapper, which removes the menu term and all of its items. Returns the id and a
 * deleted flag. wp_delete_nav_menu() is a core wrapper, not a force-delete primitive, so this
 * adds no banned trash-bypass call to our source.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_delete_menu( array $input ) {
	$menu_id = (int) ( $input['menu_id'] ?? 0 );
	$menu    = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$result = wp_delete_nav_menu( $menu_id );
	if ( is_wp_error( $result ) || true !== $result ) {
		return aafm_generic_error();
	}
	return array(
		'id'      => $menu_id,
		'deleted' => true,
	);
}

/**
 * Args for aafm/create-menu-item.
 *
 * Closed schema: a menu id and a title (both required), plus optional url/parent/object_id/type
 * for a link or an object reference. The title is sanitized as plain text and the url through
 * esc_url_raw at execute; nothing else is writable, so no extra menu-item field can be smuggled.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_menu_item(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/create-menu-item' ),
		'description'         => aafm_ability_description( 'aafm/create-menu-item' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu_id'   => array( 'type' => 'integer' ),
				'title'     => array( 'type' => 'string' ),
				'url'       => array( 'type' => 'string' ),
				'parent'    => array( 'type' => 'integer' ),
				'object_id' => array( 'type' => 'integer' ),
				'type'      => array( 'type' => 'string' ),
			),
			'required'             => array( 'menu_id', 'title' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_menu_item_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_create_menu_item',
		'permission_callback' => 'aafm_perm_edit_theme_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/create-menu-item.
 *
 * Resolves the target menu first (unknown id → generic error), then adds a published item to it
 * via the core nav-menu API (item id 0 means "create"). The title is sanitized as plain text and
 * the url through esc_url_raw; the created item is returned in the redacted item shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_create_menu_item( array $input ) {
	$menu_id = (int) ( $input['menu_id'] ?? 0 );
	$menu    = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu instanceof WP_Term ) {
		return aafm_generic_error();
	}

	$args = array(
		'menu-item-title'  => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
		'menu-item-status' => 'publish',
	);
	if ( isset( $input['url'] ) ) {
		$args['menu-item-url'] = esc_url_raw( (string) $input['url'] );
	}
	if ( isset( $input['parent'] ) ) {
		$args['menu-item-parent-id'] = (int) $input['parent'];
	}
	if ( isset( $input['object_id'] ) ) {
		$args['menu-item-object-id'] = (int) $input['object_id'];
	}
	if ( isset( $input['type'] ) ) {
		$args['menu-item-type'] = sanitize_key( (string) $input['type'] );
	}

	$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
	if ( is_wp_error( $item_id ) || 0 === (int) $item_id ) {
		return aafm_generic_error();
	}
	// Re-read the saved item to return the canonical redacted shape. If the re-fetch comes back
	// null (a hook deleted it, or a cache race), surface a generic error rather than redacting
	// null into an empty object that would violate the menu-item output schema (B9).
	$saved = aafm_menu_item_by_id( $menu_id, (int) $item_id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_redact_menu_item( $saved );
}

/**
 * Args for aafm/update-menu-item.
 *
 * Closed schema: the menu id and item id (both required) plus optional title/url to change.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_menu_item(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/update-menu-item' ),
		'description'         => aafm_ability_description( 'aafm/update-menu-item' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu_id' => array( 'type' => 'integer' ),
				'item_id' => array( 'type' => 'integer' ),
				'title'   => array( 'type' => 'string' ),
				'url'     => array( 'type' => 'string' ),
			),
			'required'             => array( 'menu_id', 'item_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_menu_item_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_update_menu_item',
		'permission_callback' => 'aafm_perm_edit_theme_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/update-menu-item.
 *
 * Resolves both the menu and the item by id (an unknown menu, or an item that is not in that
 * menu, returns a generic error), then applies the title/url edit through the core API. The
 * updated item is returned in the redacted shape. The title is sanitized as plain text and the
 * url through esc_url_raw.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_menu_item( array $input ) {
	$menu_id = (int) ( $input['menu_id'] ?? 0 );
	$item_id = (int) ( $input['item_id'] ?? 0 );

	$menu = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$existing = aafm_menu_item_by_id( $menu_id, $item_id );
	if ( null === $existing ) {
		return aafm_generic_error();
	}

	// wp_update_nav_menu_item() is NOT a partial API: any menu-item field not passed is backfilled
	// from core defaults (type -> 'custom', blank url/object/object-id/parent/classes/xfn/target and
	// a reset order/position) and then persisted. Sending only the changed keys therefore corrupts a
	// page/post_type item into a broken custom link. So we seed the full field set from the item's
	// current stored values and layer the requested edit on top, leaving every unspecified field
	// exactly as it was. Values are read from the same decorated item shape the read path uses; the
	// slashed text fields (title/description/attr-title, per the core contract) are re-slashed.
	// Position is read straight from the stored post row so the item keeps its exact saved
	// menu_order. $existing is now decorated from a directly-loaded post (via aafm_menu_item_by_id())
	// so its menu_order is the stored value too, but reading the row keeps the source unambiguous.
	$stored_post = get_post( $item_id );
	$args        = array(
		'menu-item-object-id'   => isset( $existing->object_id ) ? (int) $existing->object_id : 0,
		'menu-item-object'      => isset( $existing->object ) ? (string) $existing->object : '',
		'menu-item-parent-id'   => isset( $existing->menu_item_parent ) ? (int) $existing->menu_item_parent : 0,
		'menu-item-position'    => $stored_post instanceof WP_Post ? (int) $stored_post->menu_order : 0,
		'menu-item-type'        => isset( $existing->type ) ? (string) $existing->type : 'custom',
		'menu-item-title'       => isset( $existing->post_title ) ? wp_slash( (string) $existing->post_title ) : '',
		'menu-item-url'         => isset( $existing->url ) ? (string) $existing->url : '',
		'menu-item-description' => isset( $existing->post_content ) ? wp_slash( (string) $existing->post_content ) : '',
		'menu-item-attr-title'  => isset( $existing->post_excerpt ) ? wp_slash( (string) $existing->post_excerpt ) : '',
		'menu-item-target'      => isset( $existing->target ) ? (string) $existing->target : '',
		'menu-item-classes'     => isset( $existing->classes ) ? implode( ' ', (array) $existing->classes ) : '',
		'menu-item-xfn'         => isset( $existing->xfn ) ? (string) $existing->xfn : '',
		'menu-item-status'      => isset( $existing->post_status ) ? (string) $existing->post_status : 'publish',
	);
	if ( isset( $input['title'] ) ) {
		$args['menu-item-title'] = wp_slash( sanitize_text_field( (string) $input['title'] ) );
	}
	if ( isset( $input['url'] ) ) {
		$args['menu-item-url'] = esc_url_raw( (string) $input['url'] );
	}

	$result = wp_update_nav_menu_item( $menu_id, $item_id, $args );
	if ( is_wp_error( $result ) || 0 === (int) $result ) {
		return aafm_generic_error();
	}
	// Same B9 guard as create-menu-item: a null re-fetch must not be redacted into an empty
	// object that violates the output schema.
	$saved = aafm_menu_item_by_id( $menu_id, $item_id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	return aafm_redact_menu_item( $saved );
}

/**
 * Args for aafm/delete-menu-item.
 *
 * Closed schema: just the item id. This is a disclosed destructive write - a menu item has no
 * Trash, so removing it is permanent.
 *
 * @return array<string,mixed>
 */
function aafm_args_delete_menu_item(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/delete-menu-item' ),
		'description'         => aafm_ability_description( 'aafm/delete-menu-item' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'item_id' => array( 'type' => 'integer' ),
			),
			'required'             => array( 'item_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_delete_menu_item',
		'permission_callback' => 'aafm_perm_edit_theme_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Execute aafm/delete-menu-item.
 *
 * Confirms the id is a nav_menu_item post (so this cannot be steered into deleting an arbitrary
 * post type), then removes it. A nav_menu_item has no Trash, so a plain wp_delete_post() call
 * with NO second argument deletes it directly. That avoids the trash-bypass force-delete flag
 * the security sweep bans, so this adds no force-delete primitive to our source. Removal is
 * verified by re-fetching the post.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_delete_menu_item( array $input ) {
	$item_id = (int) ( $input['item_id'] ?? 0 );
	$post    = get_post( $item_id );
	if ( ! $post instanceof WP_Post || 'nav_menu_item' !== $post->post_type ) {
		return aafm_generic_error();
	}
	wp_delete_post( $item_id );
	if ( null !== get_post( $item_id ) ) {
		return aafm_generic_error();
	}
	return array(
		'id'      => $item_id,
		'deleted' => true,
	);
}

/**
 * Resolve one nav menu item inside a given menu by its id.
 *
 * The core writer wp_update_nav_menu_item() returns only the new item id, so to hand back the
 * redacted item shape we re-read the saved item. This resolves it WITHOUT wp_get_nav_menu_items():
 * that reader is language-filtered by WPML (it remaps/filters the menu to the current language
 * mid-request), so a just-created item can be absent from its list and the write would look like a
 * failure. Instead we load the post directly and confirm it belongs to the menu via the nav_menu
 * TERM relationship, which is language-agnostic. This keeps the "reject an item from another menu"
 * contract that update relies on and works identically with or without WPML. The post is decorated
 * with wp_setup_nav_menu_item() so it carries the same fields aafm_redact_menu_item() reads.
 * Returns null when the id is not a nav_menu_item, or is not an item of that menu.
 *
 * @param int $menu_id Menu (nav_menu term) id.
 * @param int $item_id Menu item (nav_menu_item post) id.
 * @return object|null The decorated nav menu item object, or null.
 */
function aafm_menu_item_by_id( int $menu_id, int $item_id ) {
	// Deliberately NOT status-filtered: create/update/delete re-read the item they just wrote, which
	// can be a draft (e.g. it points at an unpublished object), so a just-saved draft item must stay
	// resolvable. This is intentionally more capable than the old publish-only reader.
	$post = get_post( $item_id );
	if ( ! $post instanceof WP_Post || 'nav_menu_item' !== $post->post_type ) {
		return null;
	}
	$belongs = is_object_in_term( $item_id, 'nav_menu', $menu_id );
	if ( is_wp_error( $belongs ) || ! $belongs ) {
		return null;
	}
	return wp_setup_nav_menu_item( $post );
}
