<?php
/**
 * Slim SEO abilities: slim-seo-get-post, slim-seo-update-post.
 *
 * Registers ONLY when Slim SEO is active (aafm_integration_active('slim_seo')). Unlike
 * Rank Math/Yoast/AIOSEO, Slim SEO stores every field in ONE serialized post meta key,
 * `slim_seo` (a PHP array), matching its own declared REST schema
 * (src/RestApi.php:22-46, verified 2026-09 against the wordpress.org SVN trunk): title,
 * description, facebook_image, twitter_image, canonical (all strings), noindex (boolean).
 * SEO meta is post content, so both abilities gate on edit_post($id) via the shared
 * aafm_perm_seo_post_object() (includes/integrations.php) - Slim SEO's own admin metabox
 * uses the same current_user_can('edit_post', $id) shape, so no separate gate is needed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_slim_seo_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_slim_seo_full_definitions' );

/**
 * Contribute Slim SEO ability definitions to the registry, only while Slim SEO is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_slim_seo_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'slim_seo' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_slim_seo_registry_definitions() );
}

/**
 * Contribute Slim SEO ability definitions to the host-independent full registry (used by the
 * Integrations tab and the manifest, regardless of whether Slim SEO is actually active here).
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_slim_seo_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_slim_seo_registry_definitions() );
}

/**
 * The two Slim SEO ability rows, shared by the guarded and full registries.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_slim_seo_registry_definitions(): array {
	return array(
		'aafm/slim-seo-get-post'    => array(
			'label'        => __( 'Get post SEO (Slim SEO)', 'agent-abilities-for-mcp' ),
			'description'  => __( "Reads a post's Slim SEO fields (title, description, canonical, social images, and the noindex flag) from its single slim_seo post meta array. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'slim_seo',
			'args_builder' => 'aafm_args_slim_seo_get_post',
		),
		'aafm/slim-seo-update-post' => array(
			'label'        => __( 'Update post SEO (Slim SEO)', 'agent-abilities-for-mcp' ),
			'description'  => __( "Writes a post's Slim SEO fields into its single slim_seo post meta array. A field omitted from this call is left untouched; the write only replaces fields it was actually passed. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'slim_seo',
			'args_builder' => 'aafm_args_slim_seo_update_post',
		),
	);
}

/**
 * The Slim SEO string fields (excludes the boolean `noindex`, handled separately).
 *
 * @return string[]
 */
function aafm_slim_seo_fields(): array {
	return array( 'title', 'description', 'facebook_image', 'twitter_image', 'canonical' );
}

/**
 * The subset of aafm_slim_seo_fields() that hold URLs (sanitized with esc_url_raw() rather
 * than plain text).
 *
 * @return string[]
 */
function aafm_slim_seo_url_fields(): array {
	return array( 'facebook_image', 'twitter_image', 'canonical' );
}

/**
 * Read a post's slim_seo meta array into the ability's flat output shape.
 *
 * @param int $id Post id, already confirmed to exist by the caller.
 * @return array<string,mixed>
 */
function aafm_slim_seo_read_fields( int $id ): array {
	$stored = aafm_meta_get( 'post', $id, 'slim_seo', true );
	$stored = is_array( $stored ) ? $stored : array();
	$out    = array(
		'plugin'  => 'slim_seo',
		'post_id' => $id,
	);
	foreach ( aafm_slim_seo_fields() as $field ) {
		$out[ $field ] = isset( $stored[ $field ] ) && is_scalar( $stored[ $field ] ) ? (string) $stored[ $field ] : '';
	}
	$out['noindex'] = ! empty( $stored['noindex'] );
	return $out;
}

/**
 * The sub-fields of the slim_seo array that Slim SEO declares (slim-seo 4.10.1, src/RestApi.php,
 * the registered meta's REST schema). Slim SEO defines no constant for them.
 *
 * @return string[]
 */
function aafm_slim_seo_subfields(): array {
	return array( 'title', 'description', 'facebook_image', 'twitter_image', 'canonical', 'noindex' );
}

/**
 * Write changed sub-fields into a post's slim_seo array.
 *
 * A sub-field outside aafm_slim_seo_subfields() refuses the call before anything is read or
 * written. The stored array is read with a failure-aware query and the changes are merged onto
 * it, so a sub-field the call does not name keeps its stored value; a failed read writes nothing.
 * The merged array is written as one array-valued key.
 *
 * @param int                 $id      Post id.
 * @param array<string,mixed> $changes Sub-field => new value.
 * @return array<string,mixed>|WP_Error The writer's result, or the validation error.
 */
function aafm_slim_seo_write_meta( int $id, array $changes ) {
	$target = aafm_meta_write_target( 'post', $id, 'slim_seo' );
	if ( array() !== array_diff( array_map( 'strval', array_keys( $changes ) ), aafm_slim_seo_subfields() ) ) {
		$result = array( 'status' => AAFM_WRITE_REFUSED );
		aafm_emit_write_outcome( $result, $target );
		return $result;
	}

	$baseline = aafm_meta_row( 'post', $id, 'slim_seo' );
	if ( ! $baseline['ok'] ) {
		$result = array( 'status' => AAFM_WRITE_READ_FAILED );
		aafm_emit_write_outcome( $result, $target );
		return $result;
	}

	$merged = is_array( $baseline['value'] ) ? $baseline['value'] : array();
	foreach ( $changes as $field => $value ) {
		$merged[ $field ] = $value;
	}

	return aafm_meta_set( 'post', $id, 'slim_seo', $merged, (string) get_object_subtype( 'post', $id ), false );
}

/**
 * Output schema properties shared by both Slim SEO abilities.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_slim_seo_output_properties(): array {
	$props = array(
		'plugin'  => array( 'type' => 'string' ),
		'post_id' => array( 'type' => 'integer' ),
	);
	foreach ( aafm_slim_seo_fields() as $field ) {
		$props[ $field ] = array( 'type' => 'string' );
	}
	$props['noindex'] = array( 'type' => 'boolean' );
	return $props;
}

/**
 * Args for aafm/slim-seo-get-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_slim_seo_get_post(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/slim-seo-get-post' ),
		'description'         => aafm_ability_description( 'aafm/slim-seo-get-post' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post whose Slim SEO fields to read. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_slim_seo_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_slim_seo_get_post',
		'permission_callback' => 'aafm_perm_seo_post_object',
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
 * Execute aafm/slim-seo-get-post.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_slim_seo_get_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	return aafm_slim_seo_read_fields( $id );
}

/**
 * Args for aafm/slim-seo-update-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_slim_seo_update_post(): array {
	$properties = array(
		'post_id' => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'ID of the post whose Slim SEO fields to write. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
		),
	);
	foreach ( aafm_slim_seo_fields() as $field ) {
		$properties[ $field ] = array(
			'type'        => 'string',
			/* translators: %s: Slim SEO field name (title, description, etc). */
			'description' => sprintf( __( 'Slim SEO %s override. Omit to leave the stored value untouched.', 'agent-abilities-for-mcp' ), $field ),
		);
	}
	$properties['noindex'] = array(
		'type'        => 'boolean',
		'description' => __( 'Whether to mark this post noindex. Omit to leave the stored value untouched.', 'agent-abilities-for-mcp' ),
	);

	return array(
		'label'               => aafm_ability_label( 'aafm/slim-seo-update-post' ),
		'description'         => aafm_ability_description( 'aafm/slim-seo-update-post' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array_merge(
				aafm_slim_seo_output_properties(),
				array( 'status' => aafm_seo_group_write_output_properties()['status'] )
			),
		),
		'execute_callback'    => 'aafm_exec_slim_seo_update_post',
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
 * Execute aafm/slim-seo-update-post.
 *
 * A field omitted from $input is left untouched in the stored slim_seo array, matching Slim
 * SEO's own array-merge write pattern and every sibling SEO integration's partial-update
 * contract.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_slim_seo_update_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}

	$changes    = array();
	$url_fields = aafm_slim_seo_url_fields();
	foreach ( aafm_slim_seo_fields() as $field ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$raw               = (string) $input[ $field ];
		$changes[ $field ] = in_array( $field, $url_fields, true ) ? esc_url_raw( $raw ) : aafm_sanitize_plain_text( $raw );
	}
	if ( array_key_exists( 'noindex', $input ) ) {
		$changes['noindex'] = (bool) $input['noindex'];
	}

	$result = aafm_slim_seo_write_meta( $id, $changes );
	if ( is_wp_error( $result ) ) {
		return aafm_seo_write_error( 'aafm_slim_seo_write_unconfirmed', AAFM_WRITE_REFUSED, $id, null );
	}
	if ( ! in_array( $result['status'], array( AAFM_WRITE_WRITTEN, AAFM_WRITE_UNCHANGED ), true ) ) {
		return aafm_seo_write_error( 'aafm_slim_seo_write_unconfirmed', $result['status'], $id, 'slim_seo' );
	}

	$status = $result['status'];
	return aafm_with_checked_reads(
		static function () use ( $id, $status ): array {
			$out           = aafm_slim_seo_read_fields( $id );
			$out['status'] = $status;
			return $out;
		},
		aafm_seo_write_error( 'aafm_slim_seo_write_unconfirmed', AAFM_WRITE_UNCONFIRMED, $id, 'slim_seo' )
	);
}
