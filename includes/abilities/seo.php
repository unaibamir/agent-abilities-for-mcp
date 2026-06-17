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

	$registry['aafm/seo-get-post']    = array(
		'label'        => __( 'Get post SEO', 'agent-abilities-for-mcp' ),
		'description'  => __( "Reads a post's SEO fields (title, description, focus keyword, canonical, robots, and social) from the active SEO plugin. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'seo',
		'args_builder' => 'aafm_args_seo_get_post',
	);
	$registry['aafm/seo-update-post'] = array(
		'label'        => __( 'Update post SEO', 'agent-abilities-for-mcp' ),
		'description'  => __( "Writes a post's SEO fields (title, description, focus keyword, canonical, robots, and social) to the active SEO plugin's meta keys. URL fields are sanitized as URLs. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'seo',
		'args_builder' => 'aafm_args_seo_update_post',
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
