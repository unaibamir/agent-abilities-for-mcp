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
	$stored = get_post_meta( $id, 'slim_seo', true );
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
			'properties' => aafm_slim_seo_output_properties(),
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

	$stored = get_post_meta( $id, 'slim_seo', true );
	$stored = is_array( $stored ) ? $stored : array();

	$url_fields = aafm_slim_seo_url_fields();
	foreach ( aafm_slim_seo_fields() as $field ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$raw              = (string) $input[ $field ];
		$stored[ $field ] = in_array( $field, $url_fields, true ) ? esc_url_raw( $raw ) : aafm_sanitize_plain_text( $raw );
	}
	if ( array_key_exists( 'noindex', $input ) ) {
		$stored['noindex'] = (bool) $input['noindex'];
	}

	update_post_meta( $id, 'slim_seo', wp_slash( $stored ) );

	// Codex hunt F4: update_post_meta()'s return value was discarded and the response was a
	// fresh read with no comparison to what was requested, so a site-installed
	// update_post_metadata filter vetoing this write would report success while returning the
	// OLD values. Confirm every field the caller actually touched against what landed.
	//
	// Codex round 6 B6-3: the whole slim_seo array is ONE meta value, so sanitize_meta() must
	// see the entire array the way update_post_meta() actually sanitized it, not a per-field
	// scalar reapplication of the same hook (which would misfire against a filter that expects
	// its normal array shape). Sanitize the whole intended array once, then compare each field the
	// caller touched against that canonical form rather than against the plugin's own pre-write
	// intent - a legitimate normalization from a registered sanitize_post_meta_slim_seo callback
	// no longer reads as a false error. This is a deliberate divergence from the scalar
	// aafm_meta_write_confirmed() helper used by every sibling SEO integration: routing through it
	// per field here would run the whole-array hook against a lone scalar and misfire.
	//
	// $stored is slashed the same way update_post_meta() above slashed it, since that is exactly
	// the input core's own sanitize_meta() call saw at write time - comparing against an unslashed
	// recomputation would misjudge a quote/backslash-sensitive registered sanitizer.
	$canonical = wp_unslash( sanitize_meta( 'slim_seo', wp_slash( $stored ), 'post', (string) get_post_type( $id ) ) );
	$canonical = is_array( $canonical ) ? $canonical : array();
	$confirmed = aafm_slim_seo_read_fields( $id );
	foreach ( aafm_slim_seo_fields() as $field ) {
		if ( array_key_exists( $field, $input ) && (string) ( $canonical[ $field ] ?? '' ) !== $confirmed[ $field ] ) {
			return new WP_Error(
				'aafm_slim_seo_write_unconfirmed',
				__( 'The SEO fields could not be confirmed as saved.', 'agent-abilities-for-mcp' )
			);
		}
	}
	if ( array_key_exists( 'noindex', $input ) && ! empty( $canonical['noindex'] ) !== $confirmed['noindex'] ) {
		return new WP_Error(
			'aafm_slim_seo_write_unconfirmed',
			__( 'The SEO fields could not be confirmed as saved.', 'agent-abilities-for-mcp' )
		);
	}

	return $confirmed;
}
