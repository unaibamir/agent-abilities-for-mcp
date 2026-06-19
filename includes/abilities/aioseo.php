<?php
/**
 * AIOSEO / All in One SEO abilities (Wave 5): aioseo-get-post, aioseo-update-post, aioseo-get-head.
 *
 * Registers ONLY when AIOSEO is active (aafm_integration_active('aioseo')). AIOSEO v4+ keeps post
 * SEO in a CUSTOM TABLE (wp_aioseo_posts), NOT post meta — the _aioseo_* meta keys are WPML-compat
 * shadow copies that AIOSEO does not honor on write. So reads and writes go through AIOSEO's own
 * Post model: AIOSEO\Plugin\Common\Models\Post::getPost($id) returns the row, set the public props,
 * ->save() writes the row through AIOSEO's ORM. This NEVER runs raw SQL and NEVER writes the
 * shadow meta. The model is guarded with class_exists/method_exists; on absence the ability returns
 * a generic error rather than fataling. Schema is OMITTED (AIOSEO's schema column is internal,
 * undocumented JSON). SEO data is post content, so every per-object ability gates on edit_post($id).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const AAFM_AIOSEO_MODEL = 'AIOSEO\\Plugin\\Common\\Models\\Post';

add_filter( 'aafm_abilities_registry', 'aafm_register_aioseo_definitions' );

/**
 * Contribute the AIOSEO definitions to the registry, but only when AIOSEO is active. Host inactive:
 * the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_aioseo_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'aioseo' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	$registry['aafm/aioseo-get-post']    = array(
		'label'        => __( 'Get post SEO (All in One SEO)', 'agent-abilities-for-mcp' ),
		'description'  => __( "Reads a post's SEO fields (title, description, canonical, social, and robots) from All in One SEO's own data store, not post meta. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'aioseo',
		'args_builder' => 'aafm_args_aioseo_get_post',
	);
	$registry['aafm/aioseo-update-post'] = array(
		'label'        => __( 'Update post SEO (All in One SEO)', 'agent-abilities-for-mcp' ),
		'description'  => __( "Writes a post's SEO fields through All in One SEO's own data store (not post meta). URL fields are sanitized as URLs. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'aioseo',
		'args_builder' => 'aafm_args_aioseo_update_post',
	);
	$registry['aafm/aioseo-get-head']    = array(
		'label'        => __( 'Get post SEO head (All in One SEO)', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads the rendered SEO head markup for a post from All in One SEO, best-effort (empty when no head API is available). Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'aioseo',
		'args_builder' => 'aafm_args_aioseo_get_head',
	);

	return $registry;
}

/**
 * The AIOSEO text-and-URL field map: unified field => model property. The *_custom_url props hold the
 * social image URLs (sanitized with esc_url_raw on write); canonical_url is the canonical.
 *
 * @return array<string,array{prop:string,url:bool}>
 */
function aafm_aioseo_fields(): array {
	return array(
		'title'               => array(
			'prop' => 'title',
			'url'  => false,
		),
		'description'         => array(
			'prop' => 'description',
			'url'  => false,
		),
		'canonical'           => array(
			'prop' => 'canonical_url',
			'url'  => true,
		),
		'og_title'            => array(
			'prop' => 'og_title',
			'url'  => false,
		),
		'og_description'      => array(
			'prop' => 'og_description',
			'url'  => false,
		),
		'og_image'            => array(
			'prop' => 'og_image_custom_url',
			'url'  => true,
		),
		'twitter_title'       => array(
			'prop' => 'twitter_title',
			'url'  => false,
		),
		'twitter_description' => array(
			'prop' => 'twitter_description',
			'url'  => false,
		),
		'twitter_image'       => array(
			'prop' => 'twitter_image_custom_url',
			'url'  => true,
		),
	);
}

/**
 * The AIOSEO boolean robots fields: unified field => model property.
 *
 * @return array<string,string>
 */
function aafm_aioseo_robots_fields(): array {
	return array(
		'robots_noindex'  => 'robots_noindex',
		'robots_nofollow' => 'robots_nofollow',
	);
}

/**
 * Whether the AIOSEO Post model is genuinely available (class loaded with a save method). The props
 * are documented as "subject to change," so guard before touching them.
 *
 * @return bool
 */
function aafm_aioseo_model_available(): bool {
	return class_exists( AAFM_AIOSEO_MODEL ) && method_exists( AAFM_AIOSEO_MODEL, 'save' ) && method_exists( AAFM_AIOSEO_MODEL, 'getPost' );
}

/**
 * Read a post's AIOSEO fields from the model into the unified output shape. Only props that exist on
 * the model are read (partial-support honesty); an absent prop reads as empty/false.
 *
 * @param int $id Post id.
 * @return array<string,mixed>
 */
function aafm_aioseo_read_fields( int $id ): array {
	$class = AAFM_AIOSEO_MODEL;
	$model = $class::getPost( $id );
	$out   = array(
		'plugin'  => 'aioseo',
		'post_id' => $id,
	);
	foreach ( aafm_aioseo_fields() as $field => $spec ) {
		$prop          = $spec['prop'];
		$val           = ( is_object( $model ) && isset( $model->$prop ) && is_scalar( $model->$prop ) ) ? $model->$prop : '';
		$out[ $field ] = (string) $val;
	}
	foreach ( aafm_aioseo_robots_fields() as $field => $prop ) {
		$out[ $field ] = ( is_object( $model ) && isset( $model->$prop ) ) ? (bool) $model->$prop : false;
	}
	return $out;
}

/**
 * The shared output schema for aioseo-get-post / aioseo-update-post.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_aioseo_output_properties(): array {
	$props = array(
		'plugin'  => array( 'type' => 'string' ),
		'post_id' => array( 'type' => 'integer' ),
	);
	foreach ( array_keys( aafm_aioseo_fields() ) as $field ) {
		$props[ $field ] = array( 'type' => 'string' );
	}
	foreach ( array_keys( aafm_aioseo_robots_fields() ) as $field ) {
		$props[ $field ] = array( 'type' => 'boolean' );
	}
	return $props;
}

/**
 * Args for aafm/aioseo-get-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_aioseo_get_post(): array {
	return array(
		'label'               => __( 'Get post SEO (All in One SEO)', 'agent-abilities-for-mcp' ),
		'description'         => __( "Reads a post's All in One SEO fields from the plugin's own data store. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_aioseo_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_aioseo_get_post',
		'permission_callback' => 'aafm_perm_seo_post_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/aioseo-get-post.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_aioseo_get_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post || ! aafm_aioseo_model_available() ) {
		return aafm_generic_error();
	}
	return aafm_aioseo_read_fields( $id );
}

/**
 * Args for aafm/aioseo-update-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_aioseo_update_post(): array {
	$properties = array(
		'post_id' => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
	);
	foreach ( array_keys( aafm_aioseo_fields() ) as $field ) {
		$properties[ $field ] = array( 'type' => 'string' );
	}
	foreach ( array_keys( aafm_aioseo_robots_fields() ) as $field ) {
		$properties[ $field ] = array( 'type' => 'boolean' );
	}

	return array(
		'label'               => __( 'Update post SEO (All in One SEO)', 'agent-abilities-for-mcp' ),
		'description'         => __( "Writes a post's All in One SEO fields through the plugin's own data store. URL fields are sanitized as URLs. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_aioseo_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_aioseo_update_post',
		'permission_callback' => 'aafm_perm_seo_post_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/aioseo-update-post.
 *
 * Loads the model for the post, sets the allowlisted props (esc_url_raw on URL props,
 * sanitize_text_field on text, bool on robots), then ->save() — AIOSEO's own ORM writes the
 * custom-table row. A prop absent on the installed model version is skipped rather than set (so the
 * write never invents a property). Returns the refreshed read shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_aioseo_update_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post || ! aafm_aioseo_model_available() ) {
		return aafm_generic_error();
	}

	$class = AAFM_AIOSEO_MODEL;
	$model = $class::getPost( $id );
	if ( ! is_object( $model ) ) {
		return aafm_generic_error();
	}

	foreach ( aafm_aioseo_fields() as $field => $spec ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$prop = $spec['prop'];
		if ( ! property_exists( $model, $prop ) ) {
			continue; // The installed model version does not expose this prop; do not invent it.
		}
		$raw          = (string) $input[ $field ];
		$model->$prop = $spec['url'] ? esc_url_raw( $raw ) : sanitize_text_field( $raw );
	}
	foreach ( aafm_aioseo_robots_fields() as $field => $prop ) {
		if ( ! array_key_exists( $field, $input ) || ! property_exists( $model, $prop ) ) {
			continue;
		}
		$model->$prop = (bool) $input[ $field ];
	}

	$model->save();

	return aafm_aioseo_read_fields( $id );
}

/**
 * Args for aafm/aioseo-get-head.
 *
 * @return array<string,mixed>
 */
function aafm_args_aioseo_get_head(): array {
	return array(
		'label'               => __( 'Get post SEO head (All in One SEO)', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads the rendered All in One SEO <head> markup for a post, best-effort. Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'plugin'  => array( 'type' => 'string' ),
				'head'    => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_aioseo_get_head',
		'permission_callback' => 'aafm_perm_seo_get_head_floor',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/aioseo-get-head.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_aioseo_get_head( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post || ! current_user_can( 'edit_post', $id ) ) {
		return aafm_generic_error();
	}

	/** This filter is documented in includes/abilities/yoast.php (the rendered-head seam). */
	$head = (string) apply_filters( 'aafm_seo_rendered_head', '', $id, 'aioseo' );

	return array(
		'post_id' => $id,
		'plugin'  => 'aioseo',
		'head'    => $head,
	);
}
