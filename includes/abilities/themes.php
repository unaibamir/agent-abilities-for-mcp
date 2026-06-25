<?php
/**
 * Full Site Editing (FSE) theme / template / global-styles abilities.
 *
 * Exposes the WordPress block-theme surface: read the active theme and the installed theme
 * list, list and read block templates, update a database block template's markup, and read the
 * resolved global styles and settings (theme.json). Every ability gates on edit_theme_options —
 * the capability WordPress puts on the Appearance screens — reusing aafm_perm_edit_theme_options()
 * from menus.php (loaded first); this file never redefines it, which would be a fatal redeclare.
 *
 * The permission is object-INDEPENDENT (there is no per-template/per-theme capability), so the
 * discovery layer falls through to that callback with no per-object case in server.php for the
 * whole FSE family, reads and the single write alike.
 *
 * No filesystem path is ever returned: the reads use get_stylesheet()/get_template() and the
 * theme header fields, never get_stylesheet_directory(). update-template refuses theme-FILE
 * templates (which have no backing post) and only ever edits a wp_template post's content, which
 * is kses-hardened first — block delimiters survive, scripts are stripped.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_themes_definitions' );

/**
 * Contribute the FSE theme/template/global-styles definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_themes_definitions( array $registry ): array {
	$registry['aafm/get-active-theme']  = array(
		'label'        => __( 'Get active theme', 'oversio-agent-abilities' ),
		'description'  => __( 'Reads the active theme: name, version, stylesheet, parent, and whether it is a block theme. Requires the edit-theme-options capability.', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_active_theme',
	);
	$registry['aafm/list-themes']       = array(
		'label'        => __( 'List themes', 'oversio-agent-abilities' ),
		'description'  => __( 'Lists installed themes by name, version, stylesheet, and active state. Requires the edit-theme-options capability.', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_themes',
	);
	$registry['aafm/list-templates']    = array(
		'label'        => __( 'List templates', 'oversio-agent-abilities' ),
		'description'  => __( 'Lists block templates (or template parts) by id, slug, title, type, and source. Requires the edit-theme-options capability.', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_templates',
	);
	$registry['aafm/get-template']      = array(
		'label'        => __( 'Get template', 'oversio-agent-abilities' ),
		'description'  => __( 'Reads one block template by id, including its markup. Requires the edit-theme-options capability.', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_template',
	);
	$registry['aafm/get-global-styles'] = array(
		'label'        => __( 'Get global styles', 'oversio-agent-abilities' ),
		'description'  => __( "Reads the active theme's resolved global styles and settings (theme.json). Requires the edit-theme-options capability.", 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_global_styles',
	);
	$registry['aafm/update-template']   = array(
		'label'        => __( 'Update template', 'oversio-agent-abilities' ),
		'description'  => __( 'Updates a database block template by id. Its markup is sanitized, and theme-file templates cannot be edited. Requires the edit-theme-options capability.', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_update_template',
	);
	return $registry;
}

/**
 * Args for aafm/get-active-theme.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_active_theme(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-active-theme' ),
		'description'         => aafm_ability_description( 'aafm/get-active-theme' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'name'           => array( 'type' => 'string' ),
				'version'        => array( 'type' => 'string' ),
				'stylesheet'     => array( 'type' => 'string' ),
				'template'       => array( 'type' => 'string' ),
				'is_block_theme' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_active_theme',
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
 * Execute aafm/get-active-theme.
 *
 * Returns the active theme's header fields and slugs only — never a filesystem directory.
 * stylesheet is the active (child) slug; template is the parent slug (same as stylesheet for a
 * non-child theme).
 *
 * @return array<string,mixed>
 */
function aafm_exec_get_active_theme(): array {
	$theme = wp_get_theme();
	return array(
		'name'           => (string) $theme->get( 'Name' ),
		'version'        => (string) $theme->get( 'Version' ),
		'stylesheet'     => $theme->get_stylesheet(),
		'template'       => $theme->get_template(),
		'is_block_theme' => wp_is_block_theme(),
	);
}

/**
 * Args for aafm/list-themes.
 *
 * @return array<string,mixed>
 */
function aafm_args_list_themes(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/list-themes' ),
		'description'         => aafm_ability_description( 'aafm/list-themes' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'themes' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'       => array( 'type' => 'string' ),
							'version'    => array( 'type' => 'string' ),
							'stylesheet' => array( 'type' => 'string' ),
							'status'     => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_list_themes',
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
 * Execute aafm/list-themes.
 *
 * Lists every installed theme with its header fields and stylesheet slug, marking the active one.
 * No filesystem path is returned (the slug is the stylesheet directory NAME, not its full path).
 *
 * @return array<string,mixed>
 */
function aafm_exec_list_themes(): array {
	$active = wp_get_theme()->get_stylesheet();
	$themes = array();
	foreach ( wp_get_themes() as $theme ) {
		$stylesheet = $theme->get_stylesheet();
		$themes[]   = array(
			'name'       => (string) $theme->get( 'Name' ),
			'version'    => (string) $theme->get( 'Version' ),
			'stylesheet' => $stylesheet,
			'status'     => $stylesheet === $active ? 'active' : 'inactive',
		);
	}
	return array( 'themes' => $themes );
}

/**
 * Resolve the template type from input, constrained to the two valid block-template types.
 *
 * The schema enum already rejects anything else, but coercing here keeps the core API calls
 * fed a known value (wp_template by default) rather than trusting raw input. The narrowed
 * phpstan-return lets the core get_block_template(s) calls see the exact literal union they expect.
 *
 * @param array<string,mixed> $input Validated input.
 * @return string Either 'wp_template' or 'wp_template_part'.
 * @phpstan-return 'wp_template'|'wp_template_part'
 */
function aafm_template_type( array $input ): string {
	$type = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : 'wp_template';
	return 'wp_template_part' === $type ? 'wp_template_part' : 'wp_template';
}

/**
 * Safe shape for one block template — id, slug, title, type, and source only.
 *
 * No markup here (the list stays lean); get-template adds content. source is theme|custom: a
 * theme-FILE template ('theme') has no backing post, a database-overridden one is 'custom'.
 *
 * @param WP_Block_Template $template Block template object.
 * @return array<string,mixed>
 */
function aafm_redact_template( WP_Block_Template $template ): array {
	return array(
		'id'     => (string) $template->id,
		'slug'   => (string) $template->slug,
		'title'  => (string) $template->title,
		'type'   => (string) $template->type,
		'source' => (string) $template->source,
	);
}

/**
 * Args for aafm/list-templates.
 *
 * @return array<string,mixed>
 */
function aafm_args_list_templates(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/list-templates' ),
		'description'         => aafm_ability_description( 'aafm/list-templates' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'type' => array(
					'type' => 'string',
					'enum' => array( 'wp_template', 'wp_template_part' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'templates' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_template_output_properties(),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_list_templates',
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
 * Execute aafm/list-templates.
 *
 * Returns every block template (or template part) for the active theme, redacted to the lean
 * shape (no markup). The type defaults to wp_template.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_list_templates( array $input ): array {
	$type      = aafm_template_type( $input );
	$templates = array();
	foreach ( get_block_templates( array(), $type ) as $template ) {
		if ( $template instanceof WP_Block_Template ) {
			$templates[] = aafm_redact_template( $template );
		}
	}
	return array( 'templates' => $templates );
}

/**
 * Args for aafm/get-template.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_template(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-template' ),
		'description'         => aafm_ability_description( 'aafm/get-template' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'template_id' => array( 'type' => 'string' ),
				'type'        => array(
					'type' => 'string',
					'enum' => array( 'wp_template', 'wp_template_part' ),
				),
			),
			'required'             => array( 'template_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_rich_template_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_get_template',
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
 * Execute aafm/get-template.
 *
 * Resolves the template by its theme//slug id; an unknown id returns a generic error rather than
 * leaking which ids exist. The rich shape adds the template markup to the lean fields.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_template( array $input ) {
	$id       = sanitize_text_field( (string) ( $input['template_id'] ?? '' ) );
	$type     = aafm_template_type( $input );
	$template = get_block_template( $id, $type );
	if ( ! $template instanceof WP_Block_Template ) {
		return aafm_generic_error();
	}
	$out            = aafm_redact_template( $template );
	$out['content'] = (string) $template->content;
	return $out;
}

/**
 * Args for aafm/update-template.
 *
 * Closed schema: the template id plus the new markup (both required), with an optional type. No
 * other field is writable, so nothing extra can be smuggled into the backing post.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_template(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/update-template' ),
		'description'         => aafm_ability_description( 'aafm/update-template' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'template_id' => array( 'type' => 'string' ),
				'content'     => array( 'type' => 'string' ),
				'type'        => array(
					'type' => 'string',
					'enum' => array( 'wp_template', 'wp_template_part' ),
				),
			),
			'required'             => array( 'template_id', 'content' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_rich_template_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_update_template',
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
 * Execute aafm/update-template.
 *
 * Resolves the template by id (unknown id → generic error), then refuses any template that lives
 * only as a THEME FILE — those have no backing wp_template post, so editing them is not allowed
 * (we never write theme files).
 *
 * B-1 (load-bearing): a theme-file template's wp_id is UNSET (effectively null), not 0, and
 * 0 === null is false in PHP — so a naive `0 === $wp_id` guard would let a file template fall
 * through to wp_update_post(['ID'=>null,...]), which inserts a stray post. The cast-then-compare
 * below ((int)($wp_id ?? 0) <= 0) catches both the unset/null and the literal 0 case.
 *
 * For a real database template the markup is kses-hardened (block delimiters survive, scripts are
 * stripped), the whole array is wp_slash()'d once for the insert layer, and the refreshed
 * get-template shape is returned.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_template( array $input ) {
	$id       = sanitize_text_field( (string) ( $input['template_id'] ?? '' ) );
	$type     = aafm_template_type( $input );
	$template = get_block_template( $id, $type );
	if ( ! $template instanceof WP_Block_Template ) {
		return aafm_generic_error();
	}

	$wp_id = (int) ( $template->wp_id ?? 0 );
	if ( $wp_id <= 0 ) {
		return aafm_generic_error();
	}

	$content = wp_kses_post( (string) ( $input['content'] ?? '' ) );
	$result  = wp_update_post(
		wp_slash(
			array(
				'ID'           => $wp_id,
				'post_content' => $content,
			)
		),
		true
	);
	if ( is_wp_error( $result ) || 0 === (int) $result ) {
		return aafm_generic_error();
	}

	$refreshed = get_block_template( $id, $type );
	if ( ! $refreshed instanceof WP_Block_Template ) {
		return aafm_generic_error();
	}
	$out            = aafm_redact_template( $refreshed );
	$out['content'] = (string) $refreshed->content;
	return $out;
}

/**
 * Args for aafm/get-global-styles.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_global_styles(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-global-styles' ),
		'description'         => aafm_ability_description( 'aafm/get-global-styles' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'settings' => array( 'type' => 'object' ),
				'styles'   => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_global_styles',
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
 * Execute aafm/get-global-styles.
 *
 * Returns the active theme's resolved theme.json settings and styles arrays. Both are theme.json
 * data structures (no filesystem path), read-only.
 *
 * @return array<string,mixed>
 */
function aafm_exec_get_global_styles(): array {
	return array(
		'settings' => wp_get_global_settings(),
		'styles'   => wp_get_global_styles(),
	);
}
