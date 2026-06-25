<?php
/**
 * Rank Math abilities (Wave 5): rankmath-get-post, rankmath-update-post, rankmath-get-schema,
 * rankmath-update-schema, rankmath-get-head.
 *
 * Registers ONLY when Rank Math is active (oversio_integration_active('rankmath')). Rank Math stores
 * post SEO in standard rank_math_* post meta, with two serialization traps the unified map got
 * wrong: rank_math_robots is a SERIALIZED ARRAY of directive tokens (not a CSV string), and schema
 * lives under DYNAMIC per-type keys rank_math_schema_{Type} (not a flat rank_math_schema). SEO meta
 * is post content, so every per-object ability gates on edit_post($id) via the shared
 * oversio_perm_seo_post_object(); the head ability uses the edit_posts floor at discovery, refined
 * per-object at execute. The schema writer reuses the relocated oversio_sanitize_schema_array().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'oversio_abilities_registry', 'oversio_register_rankmath_definitions' );
add_filter( 'oversio_abilities_registry_integrations', 'oversio_register_rankmath_full_definitions' );

/**
 * Contribute the Rank Math definitions to the registry, but only when Rank Math is active. Host
 * inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_rankmath_definitions( array $registry ): array {
	if ( ! oversio_integration_active( 'rankmath' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, oversio_rankmath_registry_definitions() );
}

/**
 * Contribute the Rank Math definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view enumerates every Rank Math ability even when the host is
 * inactive, for the Integrations tab and the manifest. The live registration path never reads this
 * filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_rankmath_full_definitions( array $registry ): array {
	return array_merge( $registry, oversio_rankmath_registry_definitions() );
}

/**
 * The Rank Math registry rows, keyed by ability name. The single source of truth for these
 * abilities' label, description, group, risk, and args builder — consumed by both the host-guarded
 * live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function oversio_rankmath_registry_definitions(): array {
	return array(
		'oversio/rankmath-get-post'      => array(
			'label'        => __( 'Get post SEO (Rank Math)', 'oversio-agent-abilities' ),
			'description'  => __( "Reads a post's Rank Math SEO fields (title, description, focus keyword, canonical, social, and robots) from its rank_math_* post meta. Requires edit access to that post.", 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'rankmath',
			'args_builder' => 'oversio_args_rankmath_get_post',
		),
		'oversio/rankmath-update-post'   => array(
			'label'        => __( 'Update post SEO (Rank Math)', 'oversio-agent-abilities' ),
			'description'  => __( "Writes a post's Rank Math SEO fields to its rank_math_* post meta. URL fields are sanitized as URLs and robots is stored as Rank Math's serialized directive array. Requires edit access to that post.", 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'rankmath',
			'args_builder' => 'oversio_args_rankmath_update_post',
		),
		'oversio/rankmath-get-schema'    => array(
			'label'        => __( 'Get post schema (Rank Math)', 'oversio-agent-abilities' ),
			'description'  => __( "Reads a post's structured-data (JSON-LD) schema of a given type from Rank Math's rank_math_schema_{Type} post meta. Requires edit access to that post.", 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'rankmath',
			'args_builder' => 'oversio_args_rankmath_get_schema',
		),
		'oversio/rankmath-update-schema' => array(
			'label'        => __( 'Update post schema (Rank Math)', 'oversio-agent-abilities' ),
			'description'  => __( "Writes a post's structured-data (JSON-LD) schema of a given type to Rank Math's rank_math_schema_{Type} post meta, recursively sanitized. Requires edit access to that post.", 'oversio-agent-abilities' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'rankmath',
			'args_builder' => 'oversio_args_rankmath_update_schema',
		),
		'oversio/rankmath-get-head'      => array(
			'label'        => __( 'Get post SEO head (Rank Math)', 'oversio-agent-abilities' ),
			'description'  => __( 'Reads the rendered SEO head markup for a post from Rank Math, best-effort (empty when no head API is available). Requires the edit-posts capability and edit access to that post.', 'oversio-agent-abilities' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'rankmath',
			'args_builder' => 'oversio_args_rankmath_get_head',
		),
	);
}

/**
 * The Rank Math text-and-URL field set: unified field => meta key. Robots is handled separately
 * because it is stored as a serialized array of tokens.
 *
 * @return array<string,string>
 */
function oversio_rankmath_fields(): array {
	return array(
		'title'               => 'rank_math_title',
		'description'         => 'rank_math_description',
		'focus_keyword'       => 'rank_math_focus_keyword',
		'canonical'           => 'rank_math_canonical_url',
		'og_title'            => 'rank_math_facebook_title',
		'og_description'      => 'rank_math_facebook_description',
		'og_image'            => 'rank_math_facebook_image',
		'twitter_title'       => 'rank_math_twitter_title',
		'twitter_description' => 'rank_math_twitter_description',
		'twitter_image'       => 'rank_math_twitter_image',
	);
}

/**
 * The Rank Math fields holding a URL, sanitized with esc_url_raw on write.
 *
 * @return string[]
 */
function oversio_rankmath_url_fields(): array {
	return array( 'canonical', 'og_image', 'twitter_image' );
}

/**
 * The allowed robots directive tokens. A token outside this set is dropped before the array is
 * written, so a free-text directive can never persist.
 *
 * @return string[]
 */
function oversio_rankmath_robots_tokens(): array {
	return array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );
}

/**
 * Read every Rank Math field for a post into the unified output shape. Robots is read as the stored
 * array and imploded back to the unified comma string.
 *
 * @param int $id Post id.
 * @return array<string,mixed>
 */
function oversio_rankmath_read_fields( int $id ): array {
	$out = array(
		'plugin'  => 'rankmath',
		'post_id' => $id,
	);
	foreach ( oversio_rankmath_fields() as $field => $key ) {
		$val           = get_post_meta( $id, $key, true );
		$out[ $field ] = is_scalar( $val ) ? (string) $val : '';
	}
	$robots = get_post_meta( $id, 'rank_math_robots', true );
	// Current Rank Math stores robots as an array of tokens; a legacy/imported row may hold a raw CSV
	// string. Implode the array, pass a string through as-is, and floor anything else to ''.
	if ( is_array( $robots ) ) {
		$out['robots'] = implode( ',', array_map( 'strval', $robots ) );
	} elseif ( is_string( $robots ) ) {
		$out['robots'] = $robots;
	} else {
		$out['robots'] = '';
	}
	return $out;
}

/**
 * The shared output schema for rankmath-get-post / rankmath-update-post.
 *
 * @return array<string,array<string,mixed>>
 */
function oversio_rankmath_output_properties(): array {
	$props = array(
		'plugin'  => array( 'type' => 'string' ),
		'post_id' => array( 'type' => 'integer' ),
	);
	foreach ( array_keys( oversio_rankmath_fields() ) as $field ) {
		$props[ $field ] = array( 'type' => 'string' );
	}
	$props['robots'] = array( 'type' => 'string' );
	return $props;
}

/**
 * Args for oversio/rankmath-get-post.
 *
 * @return array<string,mixed>
 */
function oversio_args_rankmath_get_post(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/rankmath-get-post' ),
		'description'         => oversio_ability_description( 'oversio/rankmath-get-post' ),
		'category'            => 'oversio-reads',
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
			'properties' => oversio_rankmath_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_rankmath_get_post',
		'permission_callback' => 'oversio_perm_seo_post_object',
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
 * Execute oversio/rankmath-get-post.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_rankmath_get_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return oversio_generic_error();
	}
	return oversio_rankmath_read_fields( $id );
}

/**
 * Args for oversio/rankmath-update-post.
 *
 * @return array<string,mixed>
 */
function oversio_args_rankmath_update_post(): array {
	$properties = array(
		'post_id' => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
	);
	foreach ( array_keys( oversio_rankmath_fields() ) as $field ) {
		$properties[ $field ] = array( 'type' => 'string' );
	}
	$properties['robots'] = array(
		'type'        => 'string',
		'description' => __( 'Robots directives as a comma-separated list. Accepted tokens: index, noindex, nofollow, noarchive, noimageindex, nosnippet. Unknown tokens are dropped, and the value is stored as Rank Math\'s serialized directive array.', 'oversio-agent-abilities' ),
	);

	return array(
		'label'               => oversio_ability_label( 'oversio/rankmath-update-post' ),
		'description'         => __( "Writes a post's Rank Math SEO fields. URL fields are sanitized as URLs and robots is stored as the serialized directive array. Requires edit access to that post.", 'oversio-agent-abilities' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => oversio_rankmath_output_properties(),
		),
		'execute_callback'    => 'oversio_exec_rankmath_update_post',
		'permission_callback' => 'oversio_perm_seo_post_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute oversio/rankmath-update-post.
 *
 * Writes the text/URL fields, then robots: split the CSV, validate each token against the allowlist,
 * and write the ARRAY (update_post_meta serializes it) — never a raw string, which Rank Math would
 * not honor. Returns the refreshed read shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_rankmath_update_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return oversio_generic_error();
	}

	$url_fields = oversio_rankmath_url_fields();
	foreach ( oversio_rankmath_fields() as $field => $key ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$raw   = (string) $input[ $field ];
		$clean = in_array( $field, $url_fields, true ) ? esc_url_raw( $raw ) : sanitize_text_field( $raw );
		update_post_meta( $id, $key, $clean );
	}

	if ( array_key_exists( 'robots', $input ) ) {
		$allowed = oversio_rankmath_robots_tokens();
		$tokens  = array_filter( array_map( 'trim', explode( ',', (string) $input['robots'] ) ) );
		$kept    = array_values(
			array_filter(
				$tokens,
				static fn( string $t ): bool => in_array( $t, $allowed, true )
			)
		);
		update_post_meta( $id, 'rank_math_robots', $kept );
	}

	return oversio_rankmath_read_fields( $id );
}

/**
 * Validate a Rank Math schema type suffix. The type becomes part of a meta key
 * (rank_math_schema_{Type}), so only letters and digits are allowed, preserving case (Rank Math uses
 * PascalCase type names like Article, FAQPage, HowTo).
 *
 * @param string $type Raw type argument.
 * @return string The validated type, or '' when invalid.
 */
function oversio_rankmath_validate_schema_type( string $type ): string {
	return ( '' !== $type && (bool) preg_match( '/^[A-Za-z][A-Za-z0-9]*$/', $type ) ) ? $type : '';
}

/**
 * Args for oversio/rankmath-get-schema.
 *
 * @return array<string,mixed>
 */
function oversio_args_rankmath_get_schema(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/rankmath-get-schema' ),
		'description'         => oversio_ability_description( 'oversio/rankmath-get-schema' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'type'    => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z][A-Za-z0-9]*$',
					'description' => __( 'The schema type suffix, for example Article, FAQPage, or HowTo. Must start with a letter and contain only letters and digits (PascalCase).', 'oversio-agent-abilities' ),
				),
			),
			'required'             => array( 'post_id', 'type' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'type'    => array( 'type' => 'string' ),
				'schema'  => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'oversio_exec_rankmath_get_schema',
		'permission_callback' => 'oversio_perm_seo_post_object',
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
 * Execute oversio/rankmath-get-schema.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_rankmath_get_schema( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return oversio_generic_error();
	}
	$type = oversio_rankmath_validate_schema_type( (string) ( $input['type'] ?? '' ) );
	if ( '' === $type ) {
		return oversio_generic_error();
	}
	$stored = get_post_meta( $id, 'rank_math_schema_' . $type, true );
	return array(
		'post_id' => $id,
		'type'    => $type,
		// (object) so an empty/never-set schema JSON-encodes to "{}" per the output_schema's
		// type:object, never "[]" (mirrors the acf-integration.php / meta.php empty-map convention).
		'schema'  => (object) ( is_array( $stored ) ? $stored : array() ),
	);
}

/**
 * Args for oversio/rankmath-update-schema.
 *
 * @return array<string,mixed>
 */
function oversio_args_rankmath_update_schema(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/rankmath-update-schema' ),
		'description'         => oversio_ability_description( 'oversio/rankmath-update-schema' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'type'    => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z][A-Za-z0-9]*$',
					'description' => __( 'The schema type suffix, for example Article, FAQPage, or HowTo. Must start with a letter and contain only letters and digits (PascalCase).', 'oversio-agent-abilities' ),
				),
				'schema'  => array( 'type' => 'object' ),
			),
			'required'             => array( 'post_id', 'type', 'schema' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'type'    => array( 'type' => 'string' ),
				'schema'  => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'oversio_exec_rankmath_update_schema',
		'permission_callback' => 'oversio_perm_seo_post_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute oversio/rankmath-update-schema.
 *
 * Refuses a bad type or a non-array payload, recursively sanitizes the schema (reusing the shared
 * oversio_sanitize_schema_array), and writes it to the dynamic rank_math_schema_{Type} meta. Returns the
 * refreshed shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_rankmath_update_schema( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return oversio_generic_error();
	}
	$type = oversio_rankmath_validate_schema_type( (string) ( $input['type'] ?? '' ) );
	if ( '' === $type ) {
		return oversio_generic_error();
	}
	$schema = $input['schema'] ?? null;
	if ( ! is_array( $schema ) ) {
		return oversio_generic_error();
	}
	$clean = oversio_sanitize_schema_array( $schema );
	update_post_meta( $id, 'rank_math_schema_' . $type, $clean );
	return array(
		'post_id' => $id,
		'type'    => $type,
		// (object) so an empty sanitized schema JSON-encodes to "{}" per the output_schema's
		// type:object, never "[]" (mirrors the get-schema reader above).
		'schema'  => (object) $clean,
	);
}

/**
 * Args for oversio/rankmath-get-head.
 *
 * @return array<string,mixed>
 */
function oversio_args_rankmath_get_head(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/rankmath-get-head' ),
		'description'         => oversio_ability_description( 'oversio/rankmath-get-head' ),
		'category'            => 'oversio-reads',
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
		'execute_callback'    => 'oversio_exec_rankmath_get_head',
		'permission_callback' => 'oversio_perm_seo_get_head_floor',
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
 * Execute oversio/rankmath-get-head.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_rankmath_get_head( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post || ! current_user_can( 'edit_post', $id ) ) {
		return oversio_generic_error();
	}

	/** This filter is documented in includes/abilities/yoast.php (the rendered-head seam). */
	$head = (string) apply_filters( 'oversio_seo_rendered_head', '', $id, 'rankmath' );

	return array(
		'post_id' => $id,
		'plugin'  => 'rankmath',
		'head'    => $head,
	);
}
