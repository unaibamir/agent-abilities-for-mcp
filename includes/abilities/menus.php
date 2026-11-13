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
 * Block themes keep their menus elsewhere: a core/navigation block shows a wp_navigation post whose
 * content is navigation-link blocks. list-navigations and update-navigation reach those posts. Core
 * maps every wp_navigation capability to edit_theme_options, so the write's per-object edit_post
 * check holds callers to the same bar, and server.php gives it a discovery case.
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
	$registry['aafm/list-menus']        = array(
		'label'        => __( 'List menus', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists the navigation menus by id, name, slug, and item count. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_menus',
	);
	$registry['aafm/get-menu']          = array(
		'label'        => __( 'Get menu', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads one navigation menu by id: its name, slug, and item count. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_menu',
	);
	$registry['aafm/list-menu-items']   = array(
		'label'        => __( 'List menu items', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists the items in a navigation menu by id: each item id, title, URL, what it links to, and its place in the order. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_menu_items',
	);
	$registry['aafm/create-menu']       = array(
		'label'        => __( 'Create menu', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Creates a navigation menu by name. The menu is not attached to any theme location; wiring it in is a manual step. On a block theme it stays invisible on the front end until a human adds it to a template, since the core/navigation block reads from a separate mechanism this ability does not touch. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_create_menu',
	);
	$registry['aafm/update-menu']       = array(
		'label'        => __( 'Update menu', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Renames a navigation menu by id. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_update_menu',
	);
	$registry['aafm/delete-menu']       = array(
		'label'        => __( 'Delete menu', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently deletes a navigation menu and all of its items. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_delete_menu',
	);
	$registry['aafm/create-menu-item']  = array(
		'label'        => __( 'Create menu item', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Adds an item (link) to a navigation menu. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_create_menu_item',
	);
	$registry['aafm/update-menu-item']  = array(
		'label'        => __( 'Update menu item', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Updates a menu item by id: its title, URL, parent item (to nest it, or 0 for the top level) or stored position. A parent must be another item of the same menu and cannot be the item itself or one of its own descendants. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_update_menu_item',
	);
	$registry['aafm/delete-menu-item']  = array(
		'label'        => __( 'Delete menu item', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently removes one item from a navigation menu. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_delete_menu_item',
	);
	$registry['aafm/list-navigations']  = array(
		'label'        => __( 'List navigations', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists the block-theme navigation menus (the wp_navigation posts a core/navigation block shows) by id, title and status, each with its links as a nested items tree of label, URL and children. Blocks the tree cannot show, such as a page list or a search box, are named under other_blocks. A classic menu is not listed here; use list-menus for those. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_navigations',
	);
	$registry['aafm/update-navigation'] = array(
		'label'        => __( 'Update navigation', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Replaces the links of one block-theme navigation menu by id with the list you send: each item a label and a URL, with optional children up to three levels deep and 100 items in all. The whole navigation is replaced, including any blocks list-navigations names under other_blocks. Each link is saved as a plain custom link, so per-link settings such as open in a new tab, rel, CSS classes and a link\'s binding to a page or post are not kept. URLs must be http, https, mailto or tel links, a path starting with /, or an anchor starting with #. A navigation owned by a page builder is refused. Navigation edits are not recorded in change history, so this cannot be undone here; with WordPress revisions enabled, the earlier version is kept as a revision of the navigation. Requires the edit-theme-options capability. Off by default.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_update_navigation',
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
			// This used to list only id/name/slug/count by hand,
			// while the executor below (aafm_redact_menu()) already returns the same
			// attached/registered theme-location fields list-menus/create-menu/update-menu
			// advertise through this shared helper. Use it here too, so all four menu endpoints
			// describe the identical result shape they actually return.
			'properties' => aafm_menu_output_properties(),
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
	if ( ! aafm_exact_object( 'term', (int) $input['menu_id'], 'nav_menu' ) instanceof WP_Term ) {
		return aafm_generic_error();
	}
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
						'properties' => aafm_menu_item_output_properties( true ),
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
 * A menu id that is not a menu returns the same generic error as get-menu, instead of an empty
 * list that reads as "this menu has no items".
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_list_menu_items( array $input ) {
	if ( ! aafm_exact_object( 'term', (int) $input['menu_id'], 'nav_menu' ) instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$object_ids = get_objects_in_term( (int) $input['menu_id'], 'nav_menu' );
	if ( is_wp_error( $object_ids ) || ! is_array( $object_ids ) ) {
		$object_ids = array();
	}

	$decorated = array();
	foreach ( $object_ids as $object_id ) {
		$post = aafm_exact_object( 'post', (int) $object_id );
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

	// Programmatic nav menus commonly carry menu_order 0 on every item, and on this plugin's
	// PHP 7.4 floor a tie could reshuffle both the returned sequence and the renumbered `order`
	// field below - the comment on that renumbering step requires byte-identical output to the
	// pre-fix behaviour, so this needs a stable sort to reproduce PHP 8's output byte-for-byte.
	$decorated = aafm_stable_sort(
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
	if ( ! aafm_exact_object( 'term', (int) $id, 'nav_menu' ) instanceof WP_Term ) {
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
	if ( ! aafm_exact_object( 'term', $menu_id, 'nav_menu' ) instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$menu = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$name   = aafm_sanitize_plain_text( (string) ( $input['name'] ?? '' ) );
	$result = wp_update_nav_menu_object( $menu_id, array( 'menu-name' => $name ) );
	if ( is_wp_error( $result ) || 0 === (int) $result ) {
		return aafm_generic_error();
	}
	if ( ! aafm_exact_object( 'term', $menu_id, 'nav_menu' ) instanceof WP_Term ) {
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
	if ( ! aafm_exact_object( 'term', $menu_id, 'nav_menu' ) instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$menu = wp_get_nav_menu_object( $menu_id );
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
 * aafm_checked_url at execute (an invalid one is refused); nothing else is writable, so no extra menu-item field can be smuggled.
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
					'description' => __( 'Destination URL for a custom link item. An invalid or unsafe URL is refused. Omit when object_id and type point at an existing post, page, or term instead.', 'agent-abilities-for-mcp' ),
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
 * the url through aafm_checked_url; the created item is returned in the redacted item shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_create_menu_item( array $input ) {
	$menu_id = (int) ( $input['menu_id'] ?? 0 );
	if ( ! aafm_exact_object( 'term', $menu_id, 'nav_menu' ) instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$menu = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu instanceof WP_Term ) {
		return aafm_generic_error();
	}

	$args = array(
		'menu-item-title'  => aafm_sanitize_plain_text( (string) ( $input['title'] ?? '' ) ),
		'menu-item-status' => 'publish',
	);
	if ( isset( $input['url'] ) ) {
		$url = aafm_checked_url( (string) $input['url'], 'url' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$args['menu-item-url'] = $url;
	}
	if ( isset( $input['parent'] ) ) {
		$parent = (int) $input['parent'];
		// A parent must be an item of this same menu. An id that is missing, belongs to another menu
		// or is not a menu item at all would save an orphan the menu never renders.
		if ( $parent > 0 && ! aafm_menu_item_post( $menu_id, $parent ) instanceof WP_Post ) {
			return new WP_Error(
				'aafm_invalid_menu_item_parent',
				__( 'The parent must be an existing menu item in this same menu, so nothing was created.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
		$args['menu-item-parent-id'] = $parent;
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
	if ( ! aafm_menu_item_target_checked( $type, $object, $object_id ) ) {
		return aafm_generic_error();
	}

	$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
	if ( is_wp_error( $item_id ) || 0 === (int) $item_id ) {
		return aafm_generic_error();
	}
	// Re-read the saved item to return the canonical redacted shape. If the re-fetch comes back
	// null (a hook deleted it, or a cache race), surface a generic error rather than redacting
	// null into an empty object that would violate the menu-item output schema.
	$saved = aafm_menu_item_by_id( $menu_id, (int) $item_id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	// Belt and braces over the resolution above: whatever the reason, an item core marks _invalid
	// is one wp_get_nav_menu_items() will hide, so reporting it as created would be a lie. Remove
	// the row we just wrote - it is ours, created in this call, and nothing else can be relying on
	// it yet - and answer with an error instead of a menu item that does not exist to the site.
	// A nav_menu_item has no Trash (see aafm_exec_delete_menu_item()'s docblock below), so the
	// second `true` argument is redundant: a plain wp_delete_post() call deletes it directly.
	if ( ! empty( $saved->_invalid ) ) {
		wp_delete_post( (int) $item_id );
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
		$post      = aafm_exact_object( 'post', $object_id );
		$post_type = $post instanceof WP_Post ? $post->post_type : false;
		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return new WP_Error(
				'aafm_menu_item_object_required',
				__( 'No post exists with that object_id, so the menu item has nothing to link to.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
		return $post_type;
	}

	$term = aafm_exact_object( 'term', $object_id );
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
 * Whether a menu item's target, and the post id core will write as the item's parent, load by id.
 *
 * Core's wp_update_nav_menu_item() writes the target's parent as the item's post_parent: a post
 * target's post_parent, or a term target's parent term id read as a post id
 * (wp-includes/nav-menu.php:496, :530). On update, its final wp_update_post() walks the posts
 * above that id. So a post target is chain-loaded, and a term target is loaded by id and the post
 * its parent id names is chain-loaded. A load that does not come back exact passes only when
 * aafm_object_absent() finds no row, which is how core already treats a deleted target. Other
 * item types have no target to load.
 *
 * A term target also passes, with nothing loaded, when its taxonomy is not registered in this
 * request, or when core's terms cache holds this id's own row but a site filter hid the term.
 * Either way core's get_term() inside the write gets no term, so the write reads nothing from the
 * target (wp-includes/nav-menu.php:492-498). The post a cached row's parent id names is still
 * checked, the same as for a loaded term.
 *
 * An item stored with an empty taxonomy name follows core's get_term( $id, '' )
 * (wp-includes/class-wp-term.php:124-174). If the load does not return the term and the cache
 * does not hold its row, one query reads the id's taxonomies. The check refuses when that query
 * fails, or when exactly one of those taxonomies is registered, the only case where core would read
 * a term. No row, only unregistered taxonomies, or a term shared by several registered taxonomies
 * gives core no term, so the check passes.
 *
 * @param string $type        Menu item type.
 * @param string $object_name Post type or taxonomy name.
 * @param int    $object_id   Target id.
 * @return bool
 */
function aafm_menu_item_target_checked( string $type, string $object_name, int $object_id ): bool {
	global $wpdb;

	if ( $object_id <= 0 ) {
		return true;
	}
	if ( 'post_type' === $type ) {
		return aafm_exact_object_chain( 'post', $object_id ) instanceof WP_Post || aafm_object_absent( 'post', $object_id );
	}
	if ( 'taxonomy' !== $type ) {
		return true;
	}
	if ( '' !== $object_name && ! taxonomy_exists( $object_name ) ) {
		return true;
	}

	$term = aafm_exact_object( 'term', $object_id, $object_name );
	if ( $term instanceof WP_Term ) {
		$parent_id = (int) $term->parent;
	} else {
		// Core caches the raw row, whose term_id is a string.
		$cached = wp_cache_get( $object_id, 'terms' );
		if ( is_object( $cached ) && isset( $cached->term_id, $cached->taxonomy ) && (int) $cached->term_id === $object_id && ( '' === $object_name || (string) $cached->taxonomy === $object_name ) ) {
			$parent_id = (int) ( $cached->parent ?? 0 );
		} elseif ( '' !== $object_name ) {
			return aafm_object_absent( 'term', $object_id, $object_name );
		} else {
			$rows = aafm_wpdb_results( $wpdb->prepare( 'SELECT tt.taxonomy FROM %i AS t INNER JOIN %i AS tt ON t.term_id = tt.term_id WHERE t.term_id = %d', $wpdb->terms, $wpdb->term_taxonomy, $object_id ) );
			if ( ! $rows['ok'] ) {
				return false;
			}
			$registered = 0;
			foreach ( (array) $rows['value'] as $row ) {
				if ( taxonomy_exists( (string) $row['taxonomy'] ) ) {
					++$registered;
				}
			}
			return 1 !== $registered;
		}
	}

	if ( $parent_id <= 0 ) {
		return true;
	}
	return aafm_exact_object_chain( 'post', $parent_id ) instanceof WP_Post || aafm_object_absent( 'post', $parent_id );
}

/**
 * Args for aafm/update-menu-item.
 *
 * Closed schema: the menu id and item id (both required) plus optional title, url, parent_id and
 * position to change.
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
				'menu_id'   => array(
					'type'        => 'integer',
					'description' => __( 'ID of the navigation menu the item belongs to. The item must genuinely belong to this menu or the update is refused.', 'agent-abilities-for-mcp' ),
				),
				'item_id'   => array(
					'type'        => 'integer',
					'description' => __( 'ID of the menu item to update. Every field you do not send here (link target, parent, order, CSS classes, and so on) is preserved from its current stored value.', 'agent-abilities-for-mcp' ),
				),
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'New link text for the item. Sanitized as plain text. Omit to leave the current title unchanged.', 'agent-abilities-for-mcp' ),
				),
				'url'       => array(
					'type'        => 'string',
					'description' => __( 'New destination URL for the item. An invalid or unsafe URL is refused and the current URL stays. Omit to leave the current URL unchanged.', 'agent-abilities-for-mcp' ),
				),
				'parent_id' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'ID of another item in the same menu to nest this one under, or 0 to move it to the top level. The item itself or one of its own descendants is refused. Omit to keep the current parent.', 'agent-abilities-for-mcp' ),
				),
				'position'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The item\'s stored position (its menu order). Items sort by this value; other items keep theirs, so two items can share a value. Omit to keep the current position.', 'agent-abilities-for-mcp' ),
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
 * menu, returns a generic error), then applies the title/url/parent/position edit through the core API. The
 * updated item is returned in the redacted shape. The title is sanitized as plain text and the
 * url through aafm_checked_url.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_menu_item( array $input ) {
	$menu_id = (int) ( $input['menu_id'] ?? 0 );
	$item_id = (int) ( $input['item_id'] ?? 0 );

	if ( ! aafm_exact_object( 'term', $menu_id, 'nav_menu' ) instanceof WP_Term ) {
		return aafm_generic_error();
	}
	$menu = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu instanceof WP_Term ) {
		return aafm_generic_error();
	}
	if ( ! aafm_menu_item_post( $menu_id, $item_id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// wp_update_nav_menu_item() is NOT a partial API: any menu-item field not passed is backfilled
	// from core defaults (type -> 'custom', blank url/object/object-id/parent/classes/xfn/target and
	// a reset order/position) and then persisted. Sending only the changed keys therefore corrupts a
	// page/post_type item into a broken custom link. So we seed the full field set from the item's
	// stored values and layer the requested edit on top, leaving every unspecified field exactly as
	// it was. The values come from the item's own post row and meta, read with the same calls and
	// casts wp_setup_nav_menu_item() makes, but nothing decorates the item first: decoration loads
	// the target and runs display filters, and neither may feed the write or run before the target
	// check below. The meta is read inside aafm_with_checked_reads(), so a load that fails refuses
	// instead of writing blank fields. Core blanks the url of a non-custom item itself before
	// it stores anything. The slashed text fields (title/description/attr-title, per the
	// core contract) are re-slashed. Position is the stored menu_order.
	$stored_post = aafm_exact_object_chain( 'post', $item_id );
	if ( ! $stored_post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$original_order = (int) $stored_post->menu_order;
	$args           = aafm_with_checked_reads(
		static function () use ( $item_id, $stored_post, $original_order ): array {
			return array(
				'menu-item-object-id'   => (int) aafm_meta_get( 'post', $item_id, '_menu_item_object_id', true ),
				'menu-item-object'      => (string) aafm_meta_get( 'post', $item_id, '_menu_item_object', true ),
				'menu-item-parent-id'   => (int) aafm_meta_get( 'post', $item_id, '_menu_item_menu_item_parent', true ),
				'menu-item-position'    => $original_order,
				'menu-item-type'        => (string) aafm_meta_get( 'post', $item_id, '_menu_item_type', true ),
				'menu-item-title'       => wp_slash( (string) $stored_post->post_title ),
				'menu-item-url'         => (string) aafm_meta_get( 'post', $item_id, '_menu_item_url', true ),
				'menu-item-description' => wp_slash( (string) $stored_post->post_content ),
				'menu-item-attr-title'  => wp_slash( (string) $stored_post->post_excerpt ),
				'menu-item-target'      => (string) aafm_meta_get( 'post', $item_id, '_menu_item_target', true ),
				'menu-item-classes'     => implode( ' ', (array) aafm_meta_get( 'post', $item_id, '_menu_item_classes', true ) ),
				'menu-item-xfn'         => (string) aafm_meta_get( 'post', $item_id, '_menu_item_xfn', true ),
				'menu-item-status'      => (string) $stored_post->post_status,
			);
		},
		aafm_generic_error()
	);
	if ( is_wp_error( $args ) ) {
		return $args;
	}
	if ( isset( $input['title'] ) ) {
		$args['menu-item-title'] = wp_slash( aafm_sanitize_plain_text( (string) $input['title'] ) );
	}
	if ( isset( $input['url'] ) ) {
		$url = aafm_checked_url( (string) $input['url'], 'url' );
		if ( is_wp_error( $url ) ) {
			return $url; // Refused before the write, so the stored URL stays.
		}
		$args['menu-item-url'] = $url;
	}
	if ( isset( $input['parent_id'] ) ) {
		$parent = aafm_menu_item_parent_checked( $menu_id, $item_id, (int) $input['parent_id'] );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		$args['menu-item-parent-id'] = $parent;
	}
	if ( isset( $input['position'] ) ) {
		$args['menu-item-position'] = max( 1, (int) $input['position'] );
	}
	if ( ! aafm_menu_item_target_checked( $args['menu-item-type'], $args['menu-item-object'], $args['menu-item-object-id'] ) ) {
		return aafm_generic_error();
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
	if ( 0 === $original_order && ! isset( $input['position'] ) ) {
		$after = aafm_exact_object( 'post', $item_id );
		if ( ! $after instanceof WP_Post ) {
			return aafm_generic_error();
		}
		if ( $original_order !== (int) $after->menu_order ) {
			wp_update_post(
				array(
					'ID'         => $item_id,
					'menu_order' => $original_order,
				)
			);
		}
	}

	// Same guard as create-menu-item: a null re-fetch must not be redacted into an empty
	// object that violates the output schema.
	$saved = aafm_menu_item_by_id( $menu_id, $item_id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	// A requested parent or position is read back from the item itself: a hook that rewrote either
	// must not be reported as the change the caller asked for.
	$shape = aafm_redact_menu_item( $saved );
	if ( ( isset( $input['parent_id'] ) && $shape['parent'] !== $args['menu-item-parent-id'] )
		|| ( isset( $input['position'] ) && $shape['order'] !== $args['menu-item-position'] ) ) {
		return aafm_generic_error();
	}
	return $shape;
}

/**
 * Check a requested menu item parent: 0 (top level), or another item of the same menu that is not
 * the item itself and not one of its descendants. A loop would leave items the menu never renders.
 *
 * @param int $menu_id   Menu id.
 * @param int $item_id   The item being moved.
 * @param int $parent_id Requested parent item id.
 * @return int|WP_Error The parent id, or the refusal.
 */
function aafm_menu_item_parent_checked( int $menu_id, int $item_id, int $parent_id ) {
	if ( $parent_id <= 0 ) {
		return 0;
	}
	if ( ! aafm_menu_item_post( $menu_id, $parent_id ) instanceof WP_Post ) {
		return new WP_Error(
			'aafm_invalid_menu_item_parent',
			__( 'The parent must be an existing menu item in this same menu, so nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}
	// Walk up from the requested parent. Reaching the item means the item would sit under itself. The
	// walk is bounded by the menu's own size, so a loop already stored cannot hang it.
	$seen = array();
	for ( $ancestor = $parent_id; $ancestor > 0 && ! isset( $seen[ $ancestor ] ); $ancestor = (int) aafm_meta_get( 'post', $ancestor, '_menu_item_menu_item_parent', true ) ) {
		if ( $ancestor === $item_id ) {
			return new WP_Error(
				'aafm_menu_item_parent_loop',
				__( 'A menu item cannot be nested under itself or under one of its own sub-items, so nothing was changed.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
		$seen[ $ancestor ] = true;
	}
	return $parent_id;
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
	$post    = aafm_exact_object( 'post', $item_id );
	if ( ! $post instanceof WP_Post || 'nav_menu_item' !== $post->post_type ) {
		return aafm_generic_error();
	}
	wp_delete_post( $item_id );
	// A re-read that does not load exactly proves nothing: the item is gone only when a
	// failure-aware query finds no row, so a delete that a hook vetoed still reports an error.
	if ( aafm_exact_object( 'post', $item_id ) instanceof WP_Post || ! aafm_object_absent( 'post', $item_id ) ) {
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
 * The target counts as gone only when a failure-aware query finds no row for it. A target load
 * that fails or comes back as another row keeps the item. On 6.9 that item can then hit the
 * get_post_states() warnings above, but only when the target's load itself failed.
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
	if ( 'post_type' !== aafm_meta_get( 'post', $item_post->ID, '_menu_item_type', true ) ) {
		return false;
	}
	// A missing or zero object id is deliberately NOT treated as "fine". An item can carry
	// post_type with its object id meta absent, which reads as 0, and get_post( 0 ) returns null
	// exactly like a deleted target - so excusing it here would hand core the very input that
	// breaks it. Calling it gone is also the verdict core reaches by another route: an item
	// pointing at nothing renders nothing.
	$object_id = (int) aafm_meta_get( 'post', $item_post->ID, '_menu_item_object_id', true );
	return ! ( aafm_exact_object( 'post', $object_id ) instanceof WP_Post || ! aafm_object_absent( 'post', $object_id ) );
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
	$post = aafm_menu_item_post( $menu_id, $item_id );
	return $post instanceof WP_Post ? wp_setup_nav_menu_item( $post ) : null;
}

/**
 * Load one nav menu item of a given menu by its id, undecorated.
 *
 * The item's post is loaded exactly and its menu membership is read with a failure-aware query,
 * as aafm_menu_item_by_id() describes. Nothing about the item's target is loaded.
 *
 * @param int $menu_id Menu (nav_menu term) id.
 * @param int $item_id Menu item (nav_menu_item post) id.
 * @return WP_Post|null The item's post, or null.
 */
function aafm_menu_item_post( int $menu_id, int $item_id ): ?WP_Post {
	global $wpdb;

	// Deliberately NOT status-filtered: create/update/delete re-read the item they just wrote, which
	// can be a draft (e.g. it points at an unpublished object), so a just-saved draft item must stay
	// resolvable. This is intentionally more capable than the old publish-only reader.
	$post = aafm_exact_object( 'post', $item_id );
	if ( ! $post instanceof WP_Post || 'nav_menu_item' !== $post->post_type ) {
		return null;
	}
	$belongs = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT tr.object_id FROM %i AS tr INNER JOIN %i AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s AND tt.term_id = %d LIMIT 1', $wpdb->term_relationships, $wpdb->term_taxonomy, $item_id, 'nav_menu', $menu_id ) );
	if ( ! $belongs['ok'] || null === $belongs['value'] ) {
		return null;
	}
	return $post;
}

/**
 * The most items one navigation write may hold, counting children.
 */
const AAFM_NAVIGATION_MAX_ITEMS = 100;

/**
 * How deep one navigation write may nest: top-level items are level 1.
 */
const AAFM_NAVIGATION_MAX_DEPTH = 3;

/**
 * The longest label a navigation item may carry, in characters.
 */
const AAFM_NAVIGATION_MAX_LABEL = 200;

/**
 * Load a navigation post by id: an exact wp_navigation post that is not in the Trash, or null.
 *
 * @param int $id Post id.
 * @return WP_Post|null
 */
function aafm_navigation_post( int $id ): ?WP_Post {
	$post = $id > 0 ? aafm_exact_object( 'post', $id ) : null;
	return $post instanceof WP_Post && 'wp_navigation' === $post->post_type && 'trash' !== $post->post_status ? $post : null;
}

/**
 * Per-object permission for aafm/update-navigation: the id is a navigation post the caller may edit.
 * Core maps edit_post on a wp_navigation post to edit_theme_options.
 *
 * @param array<string,mixed> $input Input carrying id.
 * @return bool
 */
function aafm_perm_navigation_object( array $input ): bool {
	$id = absint( $input['id'] ?? 0 );
	return aafm_navigation_post( $id ) instanceof WP_Post
		&& aafm_exact_object_chain( 'post', $id ) instanceof WP_Post
		&& aafm_user_can_checked( 'edit_post', $id );
}

/**
 * The input schema of one navigation item at a given level. The deepest level takes no children.
 *
 * @param int $level 1 for a top-level item.
 * @return array<string,mixed>
 */
function aafm_navigation_item_schema( int $level ): array {
	$children = array(
		'type'        => 'array',
		'description' => __( 'Items nested under this one, in the same shape. Up to three levels in all.', 'agent-abilities-for-mcp' ),
	);
	if ( $level < AAFM_NAVIGATION_MAX_DEPTH ) {
		$children['items'] = aafm_navigation_item_schema( $level + 1 );
	} else {
		$children['maxItems'] = 0;
	}
	return array(
		'type'                 => 'object',
		'properties'           => array(
			'label'    => array(
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => AAFM_NAVIGATION_MAX_LABEL,
				'description' => __( 'The visible link text, 1 to 200 characters. Sanitized as plain text.', 'agent-abilities-for-mcp' ),
			),
			'url'      => array(
				'type'        => 'string',
				'description' => __( 'Where the link goes: an http, https, mailto or tel link, a path starting with /, or an anchor starting with #. Anything else is refused.', 'agent-abilities-for-mcp' ),
			),
			'children' => $children,
		),
		'required'             => array( 'label', 'url' ),
		'additionalProperties' => false,
	);
}

/**
 * Output schema of one navigation: the shape list-navigations returns per post and update-navigation returns.
 *
 * @return array<string,mixed>
 */
function aafm_navigation_output_properties(): array {
	return array(
		'id'           => array( 'type' => 'integer' ),
		'title'        => array( 'type' => 'string' ),
		'status'       => array( 'type' => 'string' ),
		'items'        => array(
			'type'        => 'array',
			'description' => __( 'The links, in order, each with label, url and children (the same shape, nested).', 'agent-abilities-for-mcp' ),
			'items'       => array( 'type' => 'object' ),
		),
		'other_blocks' => array(
			'type'        => 'array',
			'description' => __( 'Names of blocks in this navigation that are not links, such as core/page-list. update-navigation replaces them too.', 'agent-abilities-for-mcp' ),
			'items'       => array( 'type' => 'string' ),
		),
	);
}

/**
 * Args for aafm/list-navigations.
 *
 * @return array<string,mixed>
 */
function aafm_args_list_navigations(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/list-navigations' ),
		'description'         => aafm_ability_description( 'aafm/list-navigations' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'navigations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_navigation_output_properties(),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_list_navigations',
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
 * Execute aafm/list-navigations: every navigation post outside the Trash, oldest first. A site with
 * none (a classic theme, usually) gets an empty list.
 *
 * @return array<string,mixed>
 */
function aafm_exec_list_navigations(): array {
	$posts = get_posts(
		array(
			'post_type'   => 'wp_navigation',
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			// ponytail: no paging; a site keeps a handful of navigations. Page it if one ever holds hundreds.
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);
	$navigations = array();
	foreach ( $posts as $post ) {
		if ( $post instanceof WP_Post ) {
			$navigations[] = aafm_navigation_shape( $post );
		}
	}
	return array( 'navigations' => $navigations );
}

/**
 * One navigation post as the tools return it.
 *
 * @param WP_Post $post A wp_navigation post.
 * @return array<string,mixed>
 */
function aafm_navigation_shape( WP_Post $post ): array {
	$other = array();
	$items = aafm_navigation_items_from_blocks( parse_blocks( (string) $post->post_content ), $other );
	return array(
		'id'           => (int) $post->ID,
		'title'        => (string) $post->post_title,
		'status'       => (string) $post->post_status,
		'items'        => $items,
		'other_blocks' => array_values( array_unique( $other ) ),
	);
}

/**
 * The item tree of parsed navigation blocks. Links and submenus become items; any other block is
 * named in $other, and so is non-blank markup outside a block.
 *
 * @param array<int|string,array<string,mixed>> $blocks Parsed blocks.
 * @param string[]                              $other  Names of the blocks the tree leaves out, appended.
 * @return array<int,array<string,mixed>>
 */
function aafm_navigation_items_from_blocks( array $blocks, array &$other ): array {
	$items = array();
	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;
		if ( null === $name ) {
			if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				$other[] = 'core/freeform';
			}
			continue;
		}
		if ( 'core/navigation-link' !== $name && 'core/navigation-submenu' !== $name ) {
			$other[] = (string) $name;
			continue;
		}
		$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$items[] = array(
			'label'    => is_string( $attrs['label'] ?? null ) ? $attrs['label'] : '',
			'url'      => is_string( $attrs['url'] ?? null ) ? $attrs['url'] : '',
			'children' => aafm_navigation_items_from_blocks( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $other ),
		);
	}
	return $items;
}

/**
 * Validate and clean a navigation item list: every label 1 to 200 characters of plain text, every URL
 * an http, https, mailto or tel link or a relative path, at most three levels and 100 items in all.
 *
 * @param mixed $items  The caller's list.
 * @param int   $level  1 for the top level.
 * @param int   $count  Items seen so far, carried across levels.
 * @return array<int,array<string,mixed>>|WP_Error The cleaned tree, every item with label, url and children.
 */
function aafm_navigation_items_checked( $items, int $level, int &$count ) {
	$clean = array();
	foreach ( is_array( $items ) ? $items : array() as $item ) {
		if ( ++$count > AAFM_NAVIGATION_MAX_ITEMS ) {
			return new WP_Error(
				'aafm_navigation_too_many_items',
				/* translators: %d: the most items one navigation may hold. */
				sprintf( __( 'A navigation can hold at most %d items, counting nested ones, so nothing was changed.', 'agent-abilities-for-mcp' ), AAFM_NAVIGATION_MAX_ITEMS ),
				array( 'status' => 400 )
			);
		}
		if ( ! is_array( $item ) ) {
			return new WP_Error( 'aafm_navigation_bad_item', __( 'Each navigation item must be an object with a label and a url, so nothing was changed.', 'agent-abilities-for-mcp' ), array( 'status' => 400 ) );
		}
		$label = aafm_sanitize_plain_text( is_string( $item['label'] ?? null ) ? $item['label'] : '' );
		if ( '' === $label ) {
			return new WP_Error( 'aafm_navigation_label_empty', __( 'Every navigation item needs a label, so nothing was changed.', 'agent-abilities-for-mcp' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $label ) > AAFM_NAVIGATION_MAX_LABEL ) {
			return new WP_Error(
				'aafm_navigation_label_too_long',
				/* translators: %d: the longest label allowed, in characters. */
				sprintf( __( 'A navigation label can be at most %d characters, so nothing was changed.', 'agent-abilities-for-mcp' ), AAFM_NAVIGATION_MAX_LABEL ),
				array( 'status' => 400 )
			);
		}
		// Read the scheme the way aafm_checked_url() does: wp_parse_url() takes fax:12345 for a host and port.
		$url    = aafm_checked_url( is_string( $item['url'] ?? null ) ? $item['url'] : '', 'url' );
		$scheme = is_string( $url ) && 1 === preg_match( '/^([a-z][a-z0-9+\-]*):/i', $url, $found ) ? strtolower( $found[1] ) : '';
		if ( is_wp_error( $url ) || '' === $url || ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https', 'mailto', 'tel' ), true ) ) ) {
			return new WP_Error(
				'aafm_invalid_url',
				__( 'The url value is not a valid URL, so nothing was changed. Use an https:// or http:// address, a mailto: or tel: link, a path starting with /, or an anchor starting with #.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
		$children = $item['children'] ?? array();
		if ( is_array( $children ) && array() !== $children && $level >= AAFM_NAVIGATION_MAX_DEPTH ) {
			return new WP_Error(
				'aafm_navigation_too_deep',
				/* translators: %d: the deepest nesting allowed. */
				sprintf( __( 'Navigation items can nest at most %d levels deep, so nothing was changed.', 'agent-abilities-for-mcp' ), AAFM_NAVIGATION_MAX_DEPTH ),
				array( 'status' => 400 )
			);
		}
		$children = aafm_navigation_items_checked( $children, $level + 1, $count );
		if ( is_wp_error( $children ) ) {
			return $children;
		}
		$clean[] = array(
			'label'    => $label,
			'url'      => $url,
			'children' => $children,
		);
	}
	return $clean;
}

/**
 * Canonical blocks for a cleaned item tree: a leaf is core/navigation-link and an item with children
 * is core/navigation-submenu, the shape core writes when it converts a classic menu.
 *
 * @param array<int,array<string,mixed>> $items Cleaned items.
 * @return array<int,array{blockName:string,attrs:array<string,string>,innerBlocks:array<int,mixed>,innerHTML:string,innerContent:array<int,null>}> Block arrays for serialize_blocks().
 */
function aafm_navigation_blocks( array $items ): array {
	$blocks = array();
	foreach ( $items as $item ) {
		$inner    = aafm_navigation_blocks( $item['children'] );
		$blocks[] = array(
			'blockName'    => array() === $inner ? 'core/navigation-link' : 'core/navigation-submenu',
			'attrs'        => array(
				'label' => $item['label'],
				'url'   => $item['url'],
				'kind'  => 'custom',
			),
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $inner ), null ),
		);
	}
	return $blocks;
}

/**
 * Whether markup parses back to exactly this item tree and nothing else.
 *
 * @param string                         $markup Navigation markup.
 * @param array<int,array<string,mixed>> $items  The tree it must hold.
 * @return bool
 */
function aafm_navigation_markup_holds( string $markup, array $items ): bool {
	$other = array();
	return aafm_navigation_items_from_blocks( parse_blocks( $markup ), $other ) === $items && array() === $other;
}

/**
 * Args for aafm/update-navigation.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_navigation(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/update-navigation' ),
		'description'         => aafm_ability_description( 'aafm/update-navigation' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the navigation to replace, from list-navigations.', 'agent-abilities-for-mcp' ),
				),
				'items' => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => AAFM_NAVIGATION_MAX_ITEMS,
					'description' => __( 'The complete new list of links, in order. It replaces everything in the navigation, and each link is saved as a plain custom link without per-link settings such as open in a new tab. At least 1 and at most 100 items in all, counting nested ones; an empty list is refused.', 'agent-abilities-for-mcp' ),
					'items'       => aafm_navigation_item_schema( 1 ),
				),
				'title' => array(
					'type'        => 'string',
					'description' => __( 'New name for the navigation. Sanitized as plain text. Omit to keep the current name.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'id', 'items' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_navigation_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_update_navigation',
		'permission_callback' => 'aafm_perm_navigation_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/update-navigation.
 *
 * Order: load the navigation, clean the list, build canonical markup and check that it parses back
 * to the same tree with no block the guard would refuse in strict mode, run the page-builder ownership
 * guard (an unknown owner refuses too), save, then read the stored post back and refuse unless it
 * holds exactly that tree.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_navigation( array $input ) {
	$id   = absint( $input['id'] ?? 0 );
	$post = aafm_navigation_post( $id );
	if ( null === $post || ! aafm_exact_object_chain( 'post', $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}

	$count = 0;
	$items = aafm_navigation_items_checked( $input['items'] ?? array(), 1, $count );
	if ( is_wp_error( $items ) ) {
		return $items;
	}
	if ( array() === $items ) {
		return new WP_Error( 'aafm_navigation_empty', __( 'An empty list would remove every link from this navigation and cannot be undone here, so nothing was changed. Send at least one item.', 'agent-abilities-for-mcp' ), array( 'status' => 400 ) );
	}

	$content = serialize_blocks( aafm_navigation_blocks( $items ) );
	$guard   = aafm_block_guard_evaluate( $content );
	$strict  = array_intersect( array_column( $guard['warnings'], 'code' ), aafm_block_guard_strict_codes() );
	if ( ! aafm_navigation_markup_holds( $content, $items ) || $guard['error'] instanceof WP_Error || array() !== $strict ) {
		return new WP_Error( 'aafm_invalid_block_content', __( 'The navigation markup did not pass block validation, so nothing was changed.', 'agent-abilities-for-mcp' ), array( 'status' => 400 ) );
	}

	// What WordPress will store: for a user without unfiltered_html, kses rewrites & in a block
	// attribute to &amp;. The read-back is held to that, not to the request.
	$expected_other = array();
	$expected       = aafm_navigation_items_from_blocks( parse_blocks( aafm_post_field_canonical_replay( 'post_content', $content, $id ) ), $expected_other );
	if ( array() !== $expected_other || count( $expected ) !== count( $items ) ) {
		// WordPress would store something other than navigation links, so refuse before writing.
		return new WP_Error( 'aafm_invalid_block_content', __( 'The navigation markup did not pass block validation, so nothing was changed.', 'agent-abilities-for-mcp' ), array( 'status' => 400 ) );
	}

	$owning_builder = aafm_post_has_foreign_builder_ownership( $id );
	if ( false !== $owning_builder ) {
		return aafm_page_builder_owned_error( $owning_builder );
	}

	$update = array(
		'ID'           => $id,
		'post_content' => $content,
	);
	if ( isset( $input['title'] ) ) {
		$update['post_title'] = aafm_sanitize_plain_text( (string) $input['title'] );
	}
	$result = wp_update_post( wp_slash( $update ), true );
	if ( is_wp_error( $result ) || 0 === (int) $result ) {
		return aafm_generic_error();
	}

	$saved = aafm_navigation_post( $id );
	if ( null === $saved ) {
		return aafm_generic_error();
	}
	foreach ( array( 'post_title', 'post_content' ) as $field ) {
		if ( isset( $update[ $field ] ) && ! aafm_post_field_confirm_logged( $id, $field, (string) $update[ $field ], (string) $post->$field ) ) {
			return aafm_generic_error();
		}
	}
	if ( ! aafm_navigation_markup_holds( (string) $saved->post_content, $expected ) ) {
		return new WP_Error( 'aafm_navigation_not_stored', __( 'The navigation was saved, but what WordPress stored is not the list that was sent. Read it with list-navigations before trying again.', 'agent-abilities-for-mcp' ), array( 'status' => 500 ) );
	}
	return aafm_navigation_shape( $saved );
}
