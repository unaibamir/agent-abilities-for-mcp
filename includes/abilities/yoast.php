<?php
/**
 * Yoast SEO abilities (Wave 5): yoast-get-post, yoast-update-post, yoast-get-head.
 *
 * Registers ONLY when Yoast is active (aafm_integration_active('yoast')). Yoast stores post SEO in
 * standard _yoast_wpseo_* post meta, so reads/writes use core get_post_meta/update_post_meta. SEO
 * meta is post content, so every per-object ability gates on edit_post($id) via the shared
 * aafm_perm_seo_post_object(); the head ability uses the edit_posts floor at discovery, refined
 * per-object at execute. Yoast splits robots across THREE keys (noindex enum 0/1/2, nofollow enum
 * 0/1, adv a CSV of advanced directives), exposed distinctly here. Yoast persists no JSON-LD schema
 * object in meta, so there is no yoast schema ability.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_yoast_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_yoast_full_definitions' );

// Production rendered-head seam. Registered unconditionally because host plugins may load after us
// on plugins_loaded (so a load-time activity check could miss Yoast); the callback's own
// function_exists('YoastSEO') guard makes it inert until Yoast is genuinely present. Under the
// PHPUnit stubs YoastSEO() is undefined, so this passes through and the test stub's own filter
// supplies the canned head — production and test wiring never collide.
add_filter( 'aafm_seo_rendered_head', 'aafm_yoast_rendered_head', 10, 3 );

/**
 * Produce Yoast's rendered SEO head markup for a post via its public meta surface.
 *
 * Honors $source: returns the passed-through $head untouched unless $source is 'yoast', so a site
 * running multiple SEO plugins never has Yoast answer for another plugin's head. Guards the real
 * Yoast API (YoastSEO() and the for_post->get_head->html chain) defensively — any missing piece or
 * thrown error falls back to the passed-through $head rather than fataling.
 *
 * @param string $head   Head markup accumulated so far (passthrough default).
 * @param int    $post_id Post id.
 * @param string $source Integration slug the caller is asking for.
 * @return string
 */
function aafm_yoast_rendered_head( string $head, int $post_id, string $source ): string {
	if ( 'yoast' !== $source || ! function_exists( 'YoastSEO' ) ) {
		return $head;
	}

	try {
		$meta = YoastSEO()->meta->for_post( $post_id );
		if ( null === $meta || ! is_object( $meta ) || ! method_exists( $meta, 'get_head' ) ) {
			return $head;
		}
		$result = $meta->get_head();
		// for_post()->get_head() returns an object with an ->html string property.
		if ( is_object( $result ) && isset( $result->html ) && is_string( $result->html ) ) {
			return $result->html;
		}
		if ( is_string( $result ) ) {
			return $result;
		}
	} catch ( \Throwable $e ) {
		return $head; // The real API shape changed or threw: stay best-effort, never fatal.
	}

	return $head;
}

/**
 * Contribute the Yoast definitions to the registry, but only when Yoast is active. Host inactive:
 * the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_yoast_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'yoast' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_yoast_registry_definitions() );
}

/**
 * Contribute the Yoast definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view (aafm_get_abilities_registry_full()) enumerates every Yoast
 * ability even when Yoast is inactive, for the Integrations tab and the manifest. The live
 * registration path never reads this filter, so an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_yoast_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_yoast_registry_definitions() );
}

/**
 * The Yoast registry rows, keyed by ability name. The single source of truth for these abilities'
 * label, description, group, risk, and args builder — consumed by both the host-guarded live
 * registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_yoast_registry_definitions(): array {
	return array(
		'aafm/yoast-get-post'    => array(
			'label'        => __( 'Get post SEO (Yoast)', 'agent-abilities-for-mcp' ),
			'description'  => __( "Reads a post's Yoast SEO fields (title, description, focus keyword, canonical, social, and the three robots directives) from its _yoast_wpseo_* post meta. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'yoast',
			'args_builder' => 'aafm_args_yoast_get_post',
		),
		'aafm/yoast-update-post' => array(
			'label'        => __( 'Update post SEO (Yoast)', 'agent-abilities-for-mcp' ),
			'description'  => __( "Writes a post's Yoast SEO fields to its _yoast_wpseo_* post meta. URL fields are sanitized as URLs and the robots directives are validated. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'yoast',
			'args_builder' => 'aafm_args_yoast_update_post',
		),
		'aafm/yoast-get-head'    => array(
			'label'        => __( 'Get post SEO head (Yoast)', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads the rendered SEO head markup for a post from Yoast, best-effort (empty when no head API is available). Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'yoast',
			'args_builder' => 'aafm_args_yoast_get_head',
		),
	);
}

/**
 * The Yoast text-and-URL field set: unified field => meta key. Robots is handled separately because
 * it spans three keys with their own enums/allowlist (see aafm_yoast_robots_keys).
 *
 * @return array<string,string>
 */
function aafm_yoast_fields(): array {
	return array(
		'title'               => '_yoast_wpseo_title',
		'description'         => '_yoast_wpseo_metadesc',
		'focus_keyword'       => '_yoast_wpseo_focuskw',
		'canonical'           => '_yoast_wpseo_canonical',
		'og_title'            => '_yoast_wpseo_opengraph-title',
		'og_description'      => '_yoast_wpseo_opengraph-description',
		'og_image'            => '_yoast_wpseo_opengraph-image',
		'twitter_title'       => '_yoast_wpseo_twitter-title',
		'twitter_description' => '_yoast_wpseo_twitter-description',
		'twitter_image'       => '_yoast_wpseo_twitter-image',
	);
}

/**
 * The Yoast fields holding a URL, sanitized with esc_url_raw on write so a javascript: scheme drops.
 *
 * @return string[]
 */
function aafm_yoast_url_fields(): array {
	return array( 'canonical', 'og_image', 'twitter_image' );
}

/**
 * The three Yoast robots fields: unified field => {key, enum|allow}, where `key` is the post-meta
 * key. noindex/nofollow are enums; adv is a CSV validated against an allowlist of advanced directives.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_yoast_robots_keys(): array {
	return array(
		'robots_noindex'  => array(
			'key'  => '_yoast_wpseo_meta-robots-noindex',
			'enum' => array( '0', '1', '2' ),
		),
		'robots_nofollow' => array(
			'key'  => '_yoast_wpseo_meta-robots-nofollow',
			'enum' => array( '0', '1' ),
		),
		'robots_adv'      => array(
			'key'   => '_yoast_wpseo_meta-robots-adv',
			'allow' => array( 'noarchive', 'nosnippet', 'noimageindex' ),
		),
	);
}

/**
 * Read every Yoast field for a post into the unified output shape.
 *
 * @param int $id Post id.
 * @return array<string,mixed>
 */
function aafm_yoast_read_fields( int $id ): array {
	$out = array(
		'plugin'  => 'yoast',
		'post_id' => $id,
	);
	foreach ( aafm_yoast_fields() as $field => $key ) {
		$val           = get_post_meta( $id, $key, true );
		$out[ $field ] = is_scalar( $val ) ? (string) $val : '';
	}
	foreach ( aafm_yoast_robots_keys() as $field => $spec ) {
		$val           = get_post_meta( $id, $spec['key'], true );
		$out[ $field ] = is_scalar( $val ) ? (string) $val : '';
	}
	return $out;
}

/**
 * The shared output schema for yoast-get-post / yoast-update-post.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_yoast_output_properties(): array {
	$props = array(
		'plugin'  => array( 'type' => 'string' ),
		'post_id' => array( 'type' => 'integer' ),
	);
	foreach ( array_keys( aafm_yoast_fields() ) as $field ) {
		$props[ $field ] = array( 'type' => 'string' );
	}
	foreach ( array_keys( aafm_yoast_robots_keys() ) as $field ) {
		$props[ $field ] = array( 'type' => 'string' );
	}
	return $props;
}

/**
 * Args for aafm/yoast-get-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_yoast_get_post(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/yoast-get-post' ),
		'description'         => aafm_ability_description( 'aafm/yoast-get-post' ),
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
			'properties' => aafm_yoast_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_yoast_get_post',
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
 * Execute aafm/yoast-get-post.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_yoast_get_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	return aafm_yoast_read_fields( $id );
}

/**
 * Args for aafm/yoast-update-post.
 *
 * The closed schema enumerates every writable field explicitly (MEDIUM-3): additionalProperties:false
 * means an unlisted field can never be written.
 *
 * @return array<string,mixed>
 */
function aafm_args_yoast_update_post(): array {
	$properties = array(
		'post_id' => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
	);
	foreach ( array_keys( aafm_yoast_fields() ) as $field ) {
		$properties[ $field ] = array( 'type' => 'string' );
	}
	$properties['robots_noindex']  = array(
		'type'        => 'string',
		'enum'        => array( '0', '1', '2' ),
		'description' => aafm_ability_description( 'aafm/yoast-update-post' ),
	);
	$properties['robots_nofollow'] = array(
		'type'        => 'string',
		'enum'        => array( '0', '1' ),
		'description' => __( 'Yoast nofollow directive: 0 = follow, 1 = nofollow.', 'agent-abilities-for-mcp' ),
	);
	$properties['robots_adv']      = array(
		'type'        => 'string',
		'description' => __( 'Advanced robots directives as a comma-separated list. Accepted tokens: noarchive, nosnippet, noimageindex. Unknown tokens are dropped.', 'agent-abilities-for-mcp' ),
	);

	return array(
		'label'               => aafm_ability_label( 'aafm/yoast-update-post' ),
		'description'         => __( "Writes a post's Yoast SEO fields. URL fields are sanitized as URLs and the robots directives are validated. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_yoast_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_yoast_update_post',
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
 * Execute aafm/yoast-update-post.
 *
 * Writes the text/URL fields (esc_url_raw for URLs, sanitize_text_field otherwise) and the three
 * robots keys (noindex/nofollow validated against their enums, adv filtered against the directive
 * allowlist). Returns the refreshed read shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_yoast_update_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}

	$url_fields = aafm_yoast_url_fields();
	foreach ( aafm_yoast_fields() as $field => $key ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$raw   = (string) $input[ $field ];
		$clean = in_array( $field, $url_fields, true ) ? esc_url_raw( $raw ) : sanitize_text_field( $raw );
		update_post_meta( $id, $key, $clean );
	}

	foreach ( aafm_yoast_robots_keys() as $field => $spec ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$raw = (string) $input[ $field ];
		if ( isset( $spec['enum'] ) ) {
			// An out-of-enum value is dropped (not written), so a bad directive cannot persist.
			if ( in_array( $raw, $spec['enum'], true ) ) {
				update_post_meta( $id, $spec['key'], $raw );
			}
			continue;
		}
		// adv: filter the CSV against the allowlist, drop unknown tokens, write the clean CSV.
		$tokens = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$kept   = array_values(
			array_filter(
				$tokens,
				static fn( string $t ): bool => in_array( $t, $spec['allow'], true )
			)
		);
		update_post_meta( $id, $spec['key'], implode( ',', $kept ) );
	}

	return aafm_yoast_read_fields( $id );
}

/**
 * Args for aafm/yoast-get-head.
 *
 * @return array<string,mixed>
 */
function aafm_args_yoast_get_head(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/yoast-get-head' ),
		'description'         => aafm_ability_description( 'aafm/yoast-get-head' ),
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
		'execute_callback'    => 'aafm_exec_yoast_get_head',
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
 * Execute aafm/yoast-get-head.
 *
 * Best-effort: resolve the post, refine to per-object edit_post, then read Yoast's rendered head
 * through the shared aafm_seo_rendered_head seam (empty when no head API is wired). Never fatal.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_yoast_get_head( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post || ! current_user_can( 'edit_post', $id ) ) {
		return aafm_generic_error();
	}

	/** This filter is documented in includes/abilities/yoast.php (the rendered-head seam). */
	$head = (string) apply_filters( 'aafm_seo_rendered_head', '', $id, 'yoast' );

	return array(
		'post_id' => $id,
		'plugin'  => 'yoast',
		'head'    => $head,
	);
}
