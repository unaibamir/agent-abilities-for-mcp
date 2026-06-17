<?php
/**
 * SEO integration abilities — one unified set routed across Yoast / Rank Math / AIOSEO.
 *
 * Registers ONLY when an SEO plugin is active (aafm_integration_active('seo')); a host-inactive
 * site contributes zero entries to the registry. Each read/write resolves the unified field names
 * to the active plugin's post-meta keys via aafm_seo_meta_keys(), so the same abilities serve
 * whichever plugin the site runs. SEO meta is post content, so every per-object ability gates on
 * edit_post($id) — the caller must be able to edit THAT post. No PII is exposed here.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_seo_definitions' );

/**
 * Contribute the unified SEO definitions to the registry, but only when an SEO host plugin is
 * active. Host inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_seo_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'seo' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	$registry['aafm/seo-get-post']      = array(
		'label'        => __( 'Get post SEO', 'agent-abilities-for-mcp' ),
		'description'  => __( "Reads a post's SEO fields (title, description, focus keyword, canonical, robots, and social) from the active SEO plugin. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'seo',
		'args_builder' => 'aafm_args_seo_get_post',
	);
	$registry['aafm/seo-update-post']   = array(
		'label'        => __( 'Update post SEO', 'agent-abilities-for-mcp' ),
		'description'  => __( "Writes a post's SEO fields (title, description, focus keyword, canonical, robots, and social) to the active SEO plugin's meta keys. URL fields are sanitized as URLs. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'seo',
		'args_builder' => 'aafm_args_seo_update_post',
	);
	$registry['aafm/seo-get-schema']    = array(
		'label'        => __( 'Get post schema', 'agent-abilities-for-mcp' ),
		'description'  => __( "Reads a post's structured-data (JSON-LD) schema from Rank Math. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'seo',
		'args_builder' => 'aafm_args_seo_get_schema',
	);
	$registry['aafm/seo-update-schema'] = array(
		'label'        => __( 'Update post schema', 'agent-abilities-for-mcp' ),
		'description'  => __( "Writes a post's structured-data (JSON-LD) schema to Rank Math. The object is recursively sanitized. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'seo',
		'args_builder' => 'aafm_args_seo_update_schema',
	);
	$registry['aafm/seo-get-head']      = array(
		'label'        => __( 'Get post SEO head', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reads the rendered SEO head markup for a post from the active SEO plugin, best-effort (empty when the plugin offers no head API). Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'seo',
		'args_builder' => 'aafm_args_seo_get_head',
	);

	return $registry;
}

/**
 * Per-object permission: the caller may edit THIS post (SEO meta is post content).
 *
 * @param array<string,mixed> $input Validated input.
 * @return bool
 */
function aafm_perm_seo_post( array $input ): bool {
	$id = absint( $input['post_id'] ?? 0 );
	return $id > 0 && get_post( $id ) instanceof WP_Post && current_user_can( 'edit_post', $id );
}

/**
 * The unified SEO field set, in the canonical order. The read returns each (empty when the active
 * plugin does not map it); the write accepts each. Keeping this list the single source of truth
 * keeps the read output, the write schema, and the key map in lockstep.
 *
 * @return string[]
 */
function aafm_seo_fields(): array {
	return array(
		'title',
		'description',
		'focus_keyword',
		'canonical',
		'robots',
		'og_title',
		'og_description',
		'og_image',
		'twitter_title',
		'twitter_description',
		'twitter_image',
	);
}

/**
 * Read the unified SEO fields for a post from the active plugin's mapped meta keys.
 *
 * Every unified field appears in the result; one with no mapped key (or no stored value) reads as
 * an empty string, so the shape is stable regardless of which plugin is active.
 *
 * @param int $id Post id.
 * @return array<string,mixed>
 */
function aafm_seo_read_fields( int $id ): array {
	$plugin = aafm_seo_active_plugin();
	$map    = aafm_seo_meta_keys( $plugin );
	$out    = array(
		'plugin'  => $plugin,
		'post_id' => $id,
	);
	foreach ( aafm_seo_fields() as $field ) {
		$meta_key      = $map[ $field ] ?? '';
		$out[ $field ] = '' === $meta_key ? '' : (string) get_post_meta( $id, $meta_key, true );
	}
	return $out;
}

/**
 * Output schema shared by seo-get-post and seo-update-post: the unified field shape.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_seo_post_output_properties(): array {
	$props = array(
		'plugin'  => array( 'type' => 'string' ),
		'post_id' => array( 'type' => 'integer' ),
	);
	foreach ( aafm_seo_fields() as $field ) {
		$props[ $field ] = array( 'type' => 'string' );
	}
	return $props;
}

/**
 * Args for aafm/seo-get-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_seo_get_post(): array {
	return array(
		'label'               => __( 'Get post SEO', 'agent-abilities-for-mcp' ),
		'description'         => __( "Reads a post's SEO fields from the active SEO plugin. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
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
			'properties' => aafm_seo_post_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_seo_get_post',
		'permission_callback' => 'aafm_perm_seo_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/seo-get-post.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_seo_get_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	return aafm_seo_read_fields( $id );
}

/**
 * The unified SEO fields that hold a URL and so must be sanitized with esc_url_raw rather than
 * sanitize_text_field (which would let a javascript: scheme through).
 *
 * @return string[]
 */
function aafm_seo_url_fields(): array {
	return array( 'canonical', 'og_image', 'twitter_image' );
}

/**
 * Args for aafm/seo-update-post.
 *
 * The closed schema enumerates EVERY writable unified field explicitly (MEDIUM-3):
 * additionalProperties:false means a field not listed here could never be written, silently
 * breaking parity, so the property list is kept in lockstep with aafm_seo_fields() and the read.
 *
 * @return array<string,mixed>
 */
function aafm_args_seo_update_post(): array {
	$properties = array(
		'post_id' => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
	);
	foreach ( aafm_seo_fields() as $field ) {
		$properties[ $field ] = array( 'type' => 'string' );
	}

	return array(
		'label'               => __( 'Update post SEO', 'agent-abilities-for-mcp' ),
		'description'         => __( "Writes a post's SEO fields to the active SEO plugin. URL fields are sanitized as URLs. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_seo_post_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_seo_update_post',
		'permission_callback' => 'aafm_perm_seo_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/seo-update-post.
 *
 * For each unified field present in the input that the active plugin maps to a meta key, sanitize
 * per type (esc_url_raw for the URL fields, sanitize_text_field otherwise) and write it. Returns
 * the refreshed read shape so the agent sees ground truth after the write.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_seo_update_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}

	$map        = aafm_seo_meta_keys( aafm_seo_active_plugin() );
	$url_fields = aafm_seo_url_fields();
	foreach ( aafm_seo_fields() as $field ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$meta_key = $map[ $field ] ?? '';
		if ( '' === $meta_key ) {
			continue; // The active plugin does not map this field.
		}
		$raw   = (string) $input[ $field ];
		$clean = in_array( $field, $url_fields, true ) ? esc_url_raw( $raw ) : sanitize_text_field( $raw );
		update_post_meta( $id, $meta_key, $clean );
	}

	return aafm_seo_read_fields( $id );
}

/**
 * The Rank Math post-meta key that holds the structured-data schema object.
 */
const AAFM_SEO_SCHEMA_META_KEY = 'rank_math_schema';

/**
 * Recursively sanitize a JSON-LD schema array.
 *
 * At every depth: arrays recurse; a value under a url-ish key (url / image / logo / sameAs / @id,
 * case-insensitive) is run through esc_url_raw so a javascript: scheme is dropped; every other
 * scalar leaf is run through sanitize_text_field, which strips <script> tags and control noise;
 * anything that is neither scalar nor array (objects, resources) is dropped. So script payloads
 * cannot survive at any level.
 *
 * @param array<int|string,mixed> $schema Schema array.
 * @return array<int|string,mixed>
 */
function aafm_sanitize_schema_array( array $schema ): array {
	$url_keys = array( 'url', 'image', 'logo', 'sameas', '@id', 'contenturl', 'thumbnailurl' );
	$clean    = array();
	foreach ( $schema as $key => $value ) {
		$safe_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;
		if ( is_array( $value ) ) {
			$clean[ $safe_key ] = aafm_sanitize_schema_array( $value );
			continue;
		}
		if ( ! is_scalar( $value ) ) {
			continue; // Drop objects / resources / null.
		}
		$as_string = is_bool( $value ) ? $value : (string) $value;
		if ( is_string( $safe_key ) && in_array( strtolower( $safe_key ), $url_keys, true ) ) {
			$clean[ $safe_key ] = esc_url_raw( (string) $as_string );
		} else {
			$clean[ $safe_key ] = is_bool( $as_string ) ? $as_string : sanitize_text_field( (string) $as_string );
		}
	}
	return $clean;
}

/**
 * Args for aafm/seo-get-schema.
 *
 * @return array<string,mixed>
 */
function aafm_args_seo_get_schema(): array {
	return array(
		'label'               => __( 'Get post schema', 'agent-abilities-for-mcp' ),
		'description'         => __( "Reads a post's structured-data (JSON-LD) schema from Rank Math. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
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
				'schema'  => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_seo_get_schema',
		'permission_callback' => 'aafm_perm_seo_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/seo-get-schema.
 *
 * Schema is Rank Math-primary. On any other active SEO plugin this degrades to a generic error
 * rather than guessing at that plugin's (different) schema storage.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_seo_get_schema( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( 'rankmath' !== aafm_seo_active_plugin() ) {
		return aafm_generic_error();
	}
	$stored = get_post_meta( $id, AAFM_SEO_SCHEMA_META_KEY, true );
	return array(
		'post_id' => $id,
		'schema'  => is_array( $stored ) ? $stored : array(),
	);
}

/**
 * Args for aafm/seo-update-schema.
 *
 * @return array<string,mixed>
 */
function aafm_args_seo_update_schema(): array {
	return array(
		'label'               => __( 'Update post schema', 'agent-abilities-for-mcp' ),
		'description'         => __( "Writes a post's structured-data (JSON-LD) schema to Rank Math. The object is recursively sanitized. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'schema'  => array( 'type' => 'object' ),
			),
			'required'             => array( 'post_id', 'schema' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'schema'  => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_seo_update_schema',
		'permission_callback' => 'aafm_perm_seo_post',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/seo-update-schema.
 *
 * Refuses a non-array payload, recursively sanitizes the schema, and writes it to the Rank Math
 * schema meta. Schema is Rank Math-primary, so on any other active plugin this degrades to a
 * generic error. Returns the refreshed read shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_seo_update_schema( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( 'rankmath' !== aafm_seo_active_plugin() ) {
		return aafm_generic_error();
	}
	$schema = $input['schema'] ?? null;
	if ( ! is_array( $schema ) ) {
		return aafm_generic_error();
	}
	$clean = aafm_sanitize_schema_array( $schema );
	update_post_meta( $id, AAFM_SEO_SCHEMA_META_KEY, $clean );
	return array(
		'post_id' => $id,
		'schema'  => $clean,
	);
}

/**
 * Object-independent floor for seo-get-head: the caller can author posts at all. The per-object
 * edit_post($id) refinement runs inside execute. This floor lets discovery (empty input) advertise
 * the tool to a capable user, mirroring the documented FSE/floor pattern.
 *
 * @return bool
 */
function aafm_perm_seo_get_head(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Args for aafm/seo-get-head.
 *
 * @return array<string,mixed>
 */
function aafm_args_seo_get_head(): array {
	return array(
		'label'               => __( 'Get post SEO head', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Reads the rendered SEO <head> markup for a post, best-effort. Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
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
		'execute_callback'    => 'aafm_exec_seo_get_head',
		'permission_callback' => 'aafm_perm_seo_get_head',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/seo-get-head.
 *
 * Best-effort: resolve the post, refine to per-object edit_post, then attempt the active plugin's
 * rendered-head API only if it is genuinely present (guarded function_exists/method_exists). When
 * no such API is available the head is an empty string — never fatal.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_seo_get_head( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post || ! current_user_can( 'edit_post', $id ) ) {
		return aafm_generic_error();
	}

	$plugin = aafm_seo_active_plugin();

	/**
	 * Filters the rendered SEO <head> markup for a post. The default is an empty string; the
	 * active plugin's frontend head API (when one exists) is wired in through this seam so the
	 * ability never fatals if that API is missing or changes shape. Returns a plain string.
	 *
	 * @param string $head   Rendered head markup (default '').
	 * @param int    $id     Post id.
	 * @param string $plugin Active SEO plugin slug.
	 */
	$head = (string) apply_filters( 'aafm_seo_rendered_head', '', $id, $plugin );

	return array(
		'post_id' => $id,
		'plugin'  => $plugin,
		'head'    => $head,
	);
}
