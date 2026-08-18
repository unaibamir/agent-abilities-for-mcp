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
				'menu_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the navigation menu to read. An unknown ID, or a term that is not a nav menu, returns a generic error.', 'agent-abilities-for-mcp' ),
				),
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
				'menu_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the navigation menu whose items to list. An unknown or empty menu returns an empty items list rather than an error.', 'agent-abilities-for-mcp' ),
				),
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
 * Resolving the items ourselves also means core's own two filters have to be reapplied by hand, and
 * both are:
 *
 *   1. get_objects_in_term() applies no post_status filter, whereas wp_get_nav_menu_items() defaults
 *      to post_status => 'publish'. Restored below, so drafts stay excluded.
 *   2. wp_get_nav_menu_items() drops every item wp_setup_nav_menu_item() marked _invalid - an item
 *      whose target no longer resolves, most commonly because the linked post was trashed or deleted
 *      after the item was created (nav-menu.php, _is_valid_nav_menu_item()). Without this the ability
 *      answers with links the site will not render, which is the same lie the create path goes to
 *      real lengths to avoid. Restored below.
 *
 * The exact bound on that second filter: core applies it only when ! is_admin(), keeping invalid
 * items visible on the wp-admin Menus screen so they can be removed there. This ability applies it
 * unconditionally, because a read served over MCP should not return a different set depending on
 * which PHP entry point happened to invoke it. Two consequences worth knowing. The returned set
 * equals what the site renders, which is the point. But an invalid item is no longer DISCOVERABLE
 * here, so an agent cannot learn its id from this ability in order to clean it up - delete-menu-item
 * still removes it by id, and wp-admin still shows it. The contiguous 1..N `order` is numbered over
 * the returned set, so skipped items leave no gap.
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
		// published so drafts stay out of the returned list.
		if ( 'publish' !== $post->post_status ) {
			continue;
		}
		// An item whose target post is gone is one the site will not render, so it is dropped for
		// exactly the reason the _invalid check below drops its siblings. It has to be caught
		// BEFORE decoration because on WordPress 6.9 the decoration is itself what breaks; see
		// aafm_menu_item_target_is_gone(). On 7.0 core reaches the same verdict by setting
		// _invalid, so the returned set is identical on both versions.
		if ( aafm_menu_item_target_is_gone( $post ) ) {
			continue;
		}
		$item = wp_setup_nav_menu_item( $post );
		// Same parity for core's other filter: an item core marked _invalid is one the site will not
		// render, so returning it would report a menu entry that does not exist to a visitor.
		if ( ! empty( $item->_invalid ) ) {
			continue;
		}
		$decorated[] = $item;
	}

	// usort() only became stable in PHP 8.0. Programmatic nav menus commonly carry menu_order 0
	// on every item, and on this plugin's PHP 7.4 floor a tie could reshuffle both the returned
	// sequence and the renumbered `order` field below - the comment on that renumbering step
	// requires byte-identical output to the pre-fix behaviour, so this cannot be allowed to
	// drift. Pair each item with its original position and tie-break on it, so every PHP version
	// reproduces PHP 8's current stable order byte-for-byte.
	$paired = array_map(
		static function ( $item, int $index ): array {
			return array( $item, $index );
		},
		$decorated,
		array_keys( $decorated )
	);
	usort(
		$paired,
		static function ( array $a, array $b ): int {
			$a_order = isset( $a[0]->menu_order ) ? (int) $a[0]->menu_order : 0;
			$b_order = isset( $b[0]->menu_order ) ? (int) $b[0]->menu_order : 0;
			$cmp     = $a_order <=> $b_order;
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			// Ties break toward the earlier item (original position), matching PHP 8's stable
			// usort() so this plugin's PHP 7.4 floor and PHP 8.x produce identical output.
			return $a[1] <=> $b[1];
		}
	);
	$decorated = array_column( $paired, 0 );

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
				'name' => array(
					'type'        => 'string',
					'description' => __( 'Name for the new navigation menu. Sanitized as plain text; a duplicate name is refused.', 'agent-abilities-for-mcp' ),
				),
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
	$name = aafm_sanitize_plain_text( (string) ( $input['name'] ?? '' ) );
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
				'menu_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the navigation menu to rename. An unknown ID returns a generic error.', 'agent-abilities-for-mcp' ),
				),
				'name'    => array(
					'type'        => 'string',
					'description' => __( 'New name for the menu. Sanitized as plain text.', 'agent-abilities-for-mcp' ),
				),
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
	$name   = aafm_sanitize_plain_text( (string) ( $input['name'] ?? '' ) );
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
				'menu_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the navigation menu to permanently delete, along with every item inside it. Navigation menus have no Trash, so this cannot be undone.', 'agent-abilities-for-mcp' ),
				),
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
				'menu_id'   => array(
					'type'        => 'integer',
					'description' => __( 'ID of the navigation menu to add the item to. An unknown ID returns a generic error.', 'agent-abilities-for-mcp' ),
				),
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'The item\'s visible link text. Sanitized as plain text.', 'agent-abilities-for-mcp' ),
				),
				'url'       => array(
					'type'        => 'string',
					'description' => __( 'Destination URL for a custom link item. Sanitized with esc_url_raw. Omit when object_id and type point at an existing post, page, or term instead.', 'agent-abilities-for-mcp' ),
				),
				'parent'    => array(
					'type'        => 'integer',
					'description' => __( 'ID of an existing menu item this one should nest under, or omit for a top-level item. Not verified to exist or to belong to this menu before being applied.', 'agent-abilities-for-mcp' ),
				),
				'object_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the linked post, page, or term when this item points at existing content instead of a custom URL. Required for the post_type and taxonomy types, and it must exist: an id that resolves to nothing is refused rather than saved as a broken link.', 'agent-abilities-for-mcp' ),
				),
				'type'      => array(
					'type'        => 'string',
					'description' => __( 'Menu item type, for example post_type, post_type_archive, taxonomy, or custom. Not validated against a fixed list; an unrecognized value is passed straight through to WordPress.', 'agent-abilities-for-mcp' ),
				),
				'object'    => array(
					'type'        => 'string',
					'description' => __( 'Name of the thing the item points at: a post type slug such as page for the post_type and post_type_archive types, or a taxonomy name such as category for the taxonomy type. Omit it for post_type and taxonomy and it is looked up from object_id. Required for post_type_archive, which has no object_id to look up.', 'agent-abilities-for-mcp' ),
				),
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
		'menu-item-title'  => aafm_sanitize_plain_text( (string) ( $input['title'] ?? '' ) ),
		'menu-item-status' => 'publish',
	);
	if ( isset( $input['url'] ) ) {
		$args['menu-item-url'] = esc_url_raw( (string) $input['url'] );
	}
	if ( isset( $input['parent'] ) ) {
		$args['menu-item-parent-id'] = (int) $input['parent'];
	}
	$object_id = isset( $input['object_id'] ) ? (int) $input['object_id'] : 0;
	if ( isset( $input['object_id'] ) ) {
		$args['menu-item-object-id'] = $object_id;
	}
	$type = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : '';
	if ( '' !== $type ) {
		$args['menu-item-type'] = $type;
	}

	// menu-item-object is not optional for anything but a custom link, and leaving it out is not
	// a cosmetic omission: wp_setup_nav_menu_item() looks the object up by name and flags the item
	// _invalid when the lookup fails, and wp_get_nav_menu_items() then drops every _invalid item.
	// So an item saved without it does not appear in the menu at all, while a direct re-read of the
	// post row still finds it - the plugin ends up reporting a link that the site will never render.
	// Resolve it from object_id where it can be resolved, take it from the caller where it cannot.
	$object = aafm_resolve_menu_item_object( $type, $object_id, isset( $input['object'] ) ? sanitize_key( (string) $input['object'] ) : '' );
	if ( is_wp_error( $object ) ) {
		return $object;
	}
	if ( '' !== $object ) {
		$args['menu-item-object'] = $object;
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
	// Belt and braces over the resolution above: whatever the reason, an item core marks _invalid
	// is one wp_get_nav_menu_items() will hide, so reporting it as created would be a lie. Remove
	// the row we just wrote - it is ours, created in this call, and nothing else can be relying on
	// it yet - and answer with an error instead of a menu item that does not exist to the site.
	if ( ! empty( $saved->_invalid ) ) {
		wp_delete_post( (int) $item_id, true );
		return new WP_Error(
			'aafm_invalid_menu_item',
			__( 'That menu item could not be linked to anything the site can resolve, so it was not created. Check object_id and object against the type you asked for.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}
	return aafm_redact_menu_item( $saved );
}

/**
 * Work out the `menu-item-object` value for a menu item core will accept.
 *
 * Core stores the item's target as a (type, object, object_id) triple and resolves it by NAME:
 * a post_type item carries the post type slug, a taxonomy item the taxonomy name, and a
 * post_type_archive item the post type slug with no id at all. Get the name wrong or leave it
 * blank and wp_setup_nav_menu_item() marks the item _invalid, which makes it invisible in the
 * rendered menu while the underlying post row still exists.
 *
 * Callers may pass the name explicitly; when they do not, it is looked up from object_id, which
 * is what an agent can reasonably be expected to know. A lookup that resolves to nothing is an
 * error rather than a blank, so the caller hears about a bad id instead of getting a dead link.
 *
 * @param string $type      Menu item type, already sanitized ('' when the caller omitted it).
 * @param int    $object_id Linked object id, 0 when the caller omitted it.
 * @param string $requested Explicit object name from the caller, already sanitized ('' when absent).
 * @return string|WP_Error The object name ('' when the type needs none), or an error.
 */
function aafm_resolve_menu_item_object( string $type, int $object_id, string $requested ) {
	if ( '' !== $requested ) {
		return $requested;
	}

	// 'custom' and the empty default are plain URLs: core fills object in itself and there is
	// nothing to look up. Anything else we do not recognise is passed through untouched, the same
	// way the type is - a third party may register its own nav-menu item types.
	if ( 'post_type' !== $type && 'taxonomy' !== $type && 'post_type_archive' !== $type ) {
		return '';
	}

	if ( 'post_type_archive' === $type ) {
		return new WP_Error(
			'aafm_menu_item_object_required',
			__( 'A post_type_archive menu item needs the object parameter set to the post type slug it should link to.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}

	if ( $object_id <= 0 ) {
		return new WP_Error(
			'aafm_menu_item_object_required',
			__( 'That menu item type needs object_id, or object naming the post type or taxonomy it points at.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}

	if ( 'post_type' === $type ) {
		$post_type = get_post_type( $object_id );
		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return new WP_Error(
				'aafm_menu_item_object_required',
				__( 'No post exists with that object_id, so the menu item has nothing to link to.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
		return $post_type;
	}

	$term = get_term( $object_id );
	if ( ! $term instanceof WP_Term ) {
		return new WP_Error(
			'aafm_menu_item_object_required',
			__( 'No term exists with that object_id, so the menu item has nothing to link to.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}
	return (string) $term->taxonomy;
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
				'menu_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the navigation menu the item belongs to. The item must genuinely belong to this menu or the update is refused.', 'agent-abilities-for-mcp' ),
				),
				'item_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the menu item to update. Every field you do not send here (link target, order, CSS classes, and so on) is preserved from its current stored value; only title and url actually change.', 'agent-abilities-for-mcp' ),
				),
				'title'   => array(
					'type'        => 'string',
					'description' => __( 'New link text for the item. Sanitized as plain text. Omit to leave the current title unchanged.', 'agent-abilities-for-mcp' ),
				),
				'url'     => array(
					'type'        => 'string',
					'description' => __( 'New destination URL for the item. Sanitized with esc_url_raw. Omit to leave the current URL unchanged.', 'agent-abilities-for-mcp' ),
				),
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
	$stored_post    = get_post( $item_id );
	$original_order = $stored_post instanceof WP_Post ? (int) $stored_post->menu_order : 0;
	$args           = array(
		'menu-item-object-id'   => isset( $existing->object_id ) ? (int) $existing->object_id : 0,
		'menu-item-object'      => isset( $existing->object ) ? (string) $existing->object : '',
		'menu-item-parent-id'   => isset( $existing->menu_item_parent ) ? (int) $existing->menu_item_parent : 0,
		'menu-item-position'    => $original_order,
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
		$args['menu-item-title'] = wp_slash( aafm_sanitize_plain_text( (string) $input['title'] ) );
	}
	if ( isset( $input['url'] ) ) {
		$args['menu-item-url'] = esc_url_raw( (string) $input['url'] );
	}

	$result = wp_update_nav_menu_item( $menu_id, $item_id, $args );
	if ( is_wp_error( $result ) || 0 === (int) $result ) {
		return aafm_generic_error();
	}

	// Passing the stored position back is not enough to keep it. wp_update_nav_menu_item() treats
	// position 0 as "no position given" (nav-menu.php:460) and reassigns it to the end of the menu,
	// and 0 is exactly what the FIRST item in a menu stores - our own create path leaves it there,
	// because core does. So a title-only edit of the first item silently moved it last, which is
	// the opposite of what this ability promises. Put the saved order back when core changed it.
	if ( 0 === $original_order ) {
		$after = get_post( $item_id );
		if ( $after instanceof WP_Post && $original_order !== (int) $after->menu_order ) {
			wp_update_post(
				array(
					'ID'         => $item_id,
					'menu_order' => $original_order,
				)
			);
		}
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
				'item_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the menu item to permanently remove. Menu items have no Trash, so this cannot be undone.', 'agent-abilities-for-mcp' ),
				),
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
 * Whether a nav menu item points at a post that no longer exists.
 *
 * Guards a WordPress 6.9 defect that 7.0 fixed. wp_setup_nav_menu_item() enriches a post_type
 * item using get_post_states(), and on 6.9 it does so without checking that the target resolved:
 *
 *     $menu_post   = get_post( $menu_item->object_id );
 *     $post_states = get_post_states( $menu_post );   // 6.9 wp-includes/nav-menu.php
 *
 * get_post_states() reads $post->post_status and several other fields, so a missing target
 * produces a PHP warning per field. WordPress 7.0 wrapped that call in
 * `if ( $menu_post instanceof WP_Post )`, which is why the problem is invisible above this
 * plugin's 6.9 floor, and why the suite only caught it once it was run against a 6.9 library.
 * The enrichment sits behind function_exists( 'get_post_states' ), an admin-only function, so a
 * plain REST/MCP request never reaches it; WP-CLI and any plugin that loads
 * wp-admin/includes/template.php do, and a site whose error handler promotes warnings to
 * exceptions turns it into a real failure.
 *
 * Deliberately narrow, because over-skipping would silently drop legitimate menu entries and
 * that is the worse bug. Only post_type items resolve object_id to a post: custom links have no
 * target object at all, while taxonomy and post_type_archive items resolve through different
 * core branches that do not carry this defect. A trashed target still returns a WP_Post, so it
 * is not flagged here and stays with core's own _invalid handling.
 *
 * Reads post meta rather than a decorated item on purpose, since the entire point is to answer
 * before wp_setup_nav_menu_item() has run.
 *
 * Not used by aafm_menu_item_by_id() below, and that is deliberate rather than an oversight.
 * Skipping there would make a dangling item unreadable, and update-menu-item would then refuse
 * to touch it on BOTH 6.9 and 7.0 - which removes the only way to repoint a broken item at a
 * live post, and changes behaviour on 7.0 where nothing is wrong. A read that feeds a write
 * needs the item, not a verdict about it.
 *
 * @param WP_Post $item_post The nav_menu_item post, before decoration.
 * @return bool True when this is a post_type item whose target post is gone.
 */
function aafm_menu_item_target_is_gone( WP_Post $item_post ): bool {
	if ( 'post_type' !== get_post_meta( $item_post->ID, '_menu_item_type', true ) ) {
		return false;
	}
	// A missing or zero object id is deliberately NOT treated as "fine". An item can carry
	// post_type with its object id meta absent, which reads as 0, and get_post( 0 ) returns null
	// exactly like a deleted target - so excusing it here would hand core the very input that
	// breaks it. Calling it gone is also the verdict core reaches by another route: an item
	// pointing at nothing renders nothing.
	$object_id = (int) get_post_meta( $item_post->ID, '_menu_item_object_id', true );
	return ! ( get_post( $object_id ) instanceof WP_Post );
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
