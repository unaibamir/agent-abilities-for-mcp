<?php
/**
 * Rank Math abilities (Wave 5): rankmath-get-post, rankmath-update-post, rankmath-get-schema,
 * rankmath-update-schema, rankmath-get-head.
 *
 * Registers ONLY when Rank Math is active (aafm_integration_active('rankmath')). Rank Math stores
 * post SEO in standard rank_math_* post meta, with two serialization traps the unified map got
 * wrong: rank_math_robots is a SERIALIZED ARRAY of directive tokens (not a CSV string), and schema
 * lives under DYNAMIC per-type keys rank_math_schema_{Type} (not a flat rank_math_schema). SEO meta
 * is post content, so every per-object ability gates on edit_post($id) via the shared
 * aafm_perm_seo_post_object(); the head ability uses the edit_posts floor at discovery, refined
 * per-object at execute. The schema writer reuses the relocated aafm_sanitize_schema_array().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_rankmath_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_rankmath_full_definitions' );

// Production rendered-head seam. Registered unconditionally because host plugins may load after us
// on plugins_loaded (so a load-time activity check could miss Rank Math); the callback's own
// function_exists('rank_math') + rank_math()->head guards make it inert until Rank Math is genuinely
// present. Under the PHPUnit stubs rank_math() is undefined, so this passes through and the test
// stub's own filter supplies the canned head - production and test wiring never collide (M1: until
// this was added, no production callback ever ran, so rankmath-get-head returned head:'' on every
// real store; the unit test only ever exercised the test stub's canned filter, never this path).
add_filter( 'aafm_seo_rendered_head', 'aafm_rankmath_rendered_head', 10, 3 );

/**
 * Produce Rank Math's rendered SEO head markup for a post.
 *
 * Rank Math exposes no string-returning per-post head API: its head is emitted on wp_head via
 * rank_math()->head->head(), which echoes against the queried object (title, description, canonical,
 * robots, OG/Twitter, JSON-LD schema). As a side effect it permanently removes a handful of core
 * wp_head actions it replaces (rel_canonical, index_rel_link, ...) - the same removal that already
 * happens on any real front-end request once Rank Math is active, not a new side effect introduced
 * here. So this renders inside a controlled, fully restored singular query for the post: snapshot
 * the main-query globals, build a throwaway singular WP_Query, buffer head(), then restore the
 * originals exactly (the same shape as aafm_aioseo_rendered_head()). Honors $source (passthrough
 * unless 'rankmath') and guards the real API defensively: a missing head object, a thrown error, or
 * empty output all fall back to the passed head rather than fataling.
 *
 * Rank Math only builds rank_math()->head (and the OG/Twitter/schema generators that feed it) inside
 * Frontend::integrations(), which Rank Math itself hooks to the 'wp' action - an action that never
 * fires while WordPress is dispatching a REST request (core's REST bootstrap short-circuits
 * parse_request() and exits before 'wp' runs), which is how every ability call reaches this code. So
 * ->head would otherwise never exist here even on a fully-registered site. When rank_math()->frontend
 * exists (set by Rank Math's own plugins_loaded handler once registration is valid or skipped) but
 * ->head does not yet, this triggers frontend->integrations() once - the exact real-vendor call a
 * normal front-end pageview would have made - before checking for ->head again.
 *
 * @param string $head    Head markup accumulated so far (passthrough default).
 * @param int    $post_id Post id.
 * @param string $source  Integration slug the caller is asking for.
 * @return string
 */
function aafm_rankmath_rendered_head( string $head, int $post_id, string $source ): string {
	if ( 'rankmath' !== $source || ! function_exists( 'rank_math' ) ) {
		return $head;
	}

	$plugin = rank_math();
	if ( ! is_object( $plugin ) ) {
		return $head;
	}

	if ( ! isset( $plugin->head ) && isset( $plugin->frontend ) && is_object( $plugin->frontend ) && method_exists( $plugin->frontend, 'integrations' ) ) {
		try {
			$plugin->frontend->integrations();
		} catch ( \Throwable $e ) {
			return $head; // The real API shape changed or threw: stay best-effort, never fatal.
		}
	}

	if ( ! isset( $plugin->head ) || ! is_object( $plugin->head ) || ! method_exists( $plugin->head, 'head' ) ) {
		return $head; // Rank Math present but no head renderer (e.g. unregistered, or a differently-shaped build): best-effort.
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return $head;
	}

	// Snapshot the query globals Rank Math reads, so the throwaway query never leaks out of this call.
	$saved_wp_query     = $GLOBALS['wp_query'] ?? null;
	$saved_wp_the_query = $GLOBALS['wp_the_query'] ?? null;
	$saved_post         = $GLOBALS['post'] ?? null;

	$rendered = '';
	try {
		$temp_query = new WP_Query(
			array(
				'p'                      => $post_id,
				'post_type'              => $post->post_type,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		// Point the main-query globals at our singular query so is_singular()/get_queried_object()
		// resolve to this post while Rank Math builds the head. Both originals are snapshotted above
		// and restored in the finally block, so this swap never leaks past this call.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- temporary, restored in finally.
		$GLOBALS['wp_query'] = $temp_query;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- temporary, restored in finally.
		$GLOBALS['wp_the_query'] = $temp_query;
		if ( $temp_query->have_posts() ) {
			$temp_query->the_post();
		}

		ob_start();
		$plugin->head->head();
		$rendered = (string) ob_get_clean();
	} catch ( \Throwable $e ) {
		// Make sure a half-open buffer from a throw inside head() is closed before we bail.
		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$rendered = '';
	} finally {
		// Restore the originals exactly (order matters: globals first, then reset postdata).
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the snapshotted original.
		$GLOBALS['wp_query'] = $saved_wp_query;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the snapshotted original.
		$GLOBALS['wp_the_query'] = $saved_wp_the_query;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the snapshotted original.
		$GLOBALS['post'] = $saved_post;
		wp_reset_postdata();
	}

	$rendered = trim( $rendered );
	return '' !== $rendered ? $rendered : $head;
}

/**
 * Contribute the Rank Math definitions to the registry, but only when Rank Math is active. Host
 * inactive: the registry is returned unchanged.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_rankmath_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'rankmath' ) ) {
		return $registry; // Host inactive: contribute nothing.
	}

	return array_merge( $registry, aafm_rankmath_registry_definitions() );
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
function aafm_register_rankmath_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_rankmath_registry_definitions() );
}

/**
 * The Rank Math registry rows, keyed by ability name. The single source of truth for these
 * abilities' label, description, group, risk, and args builder - consumed by both the host-guarded
 * live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_rankmath_registry_definitions(): array {
	return array(
		'aafm/rankmath-get-post'      => array(
			'label'        => __( 'Get post SEO (Rank Math)', 'agent-abilities-for-mcp' ),
			'description'  => __( "Reads a post's Rank Math SEO fields (title, description, focus keyword, canonical, social, and robots) from its rank_math_* post meta. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'rankmath',
			'args_builder' => 'aafm_args_rankmath_get_post',
		),
		'aafm/rankmath-update-post'   => array(
			'label'        => __( 'Update post SEO (Rank Math)', 'agent-abilities-for-mcp' ),
			'description'  => __( "Writes a post's Rank Math SEO fields to its rank_math_* post meta. URL fields are sanitized as URLs and robots is stored as Rank Math's serialized directive array. Social images (og_image, twitter_image) must be URLs of existing media-library attachments so Rank Math can render them; a URL with no matching attachment is refused. Setting a Twitter field turns off the Facebook fallback so the Twitter values render. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'rankmath',
			'args_builder' => 'aafm_args_rankmath_update_post',
		),
		'aafm/rankmath-get-schema'    => array(
			'label'        => __( 'Get post schema (Rank Math)', 'agent-abilities-for-mcp' ),
			'description'  => __( "Reads a post's structured-data (JSON-LD) schema of a given type from Rank Math's rank_math_schema_{Type} post meta. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'rankmath',
			'args_builder' => 'aafm_args_rankmath_get_schema',
		),
		'aafm/rankmath-update-schema' => array(
			'label'        => __( 'Update post schema (Rank Math)', 'agent-abilities-for-mcp' ),
			'description'  => __( "Writes a post's structured-data (JSON-LD) schema of a given type to Rank Math's rank_math_schema_{Type} post meta, recursively sanitized. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'rankmath',
			'args_builder' => 'aafm_args_rankmath_update_schema',
		),
		'aafm/rankmath-get-head'      => array(
			'label'        => __( 'Get post SEO head (Rank Math)', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads the rendered SEO head markup for a post from Rank Math, best-effort (empty when no head API is available). Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'rankmath',
			'args_builder' => 'aafm_args_rankmath_get_head',
		),
	);
}

/**
 * The Rank Math text-and-URL field set: unified field => meta key. Robots is handled separately
 * because it is stored as a serialized array of tokens.
 *
 * @return array<string,string>
 */
function aafm_rankmath_fields(): array {
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
function aafm_rankmath_url_fields(): array {
	return array( 'canonical', 'og_image', 'twitter_image' );
}

/**
 * The social-image fields whose write must ALSO persist an attachment-id companion meta, mapped to
 * that companion key. Rank Math's frontend OpenGraph resolver renders the image from
 * rank_math_{facebook,twitter}_image_id (an attachment id), never the URL meta - so writing only the
 * URL persists a value the frontend ignores. Verified against Rank Math 1.0.274.1:
 * includes/opengraph/class-image.php:405-410 reads Helper::get_post_meta("{$prefix}_image_id") and
 * feeds it to add_image_by_id(), and includes/class-metadata.php:124-125 prepends the rank_math_
 * prefix.
 *
 * @return array<string,string>
 */
function aafm_rankmath_image_id_fields(): array {
	return array(
		'og_image'      => 'rank_math_facebook_image_id',
		'twitter_image' => 'rank_math_twitter_image_id',
	);
}

/**
 * Whether the write carries a non-empty Twitter-specific field, in which case the Twitter->Facebook
 * fallback must be turned off so the Twitter values actually render.
 *
 * @param array<string,mixed> $input Validated input.
 * @return bool
 */
function aafm_rankmath_twitter_fields_provided( array $input ): bool {
	foreach ( array( 'twitter_title', 'twitter_description', 'twitter_image' ) as $field ) {
		if ( array_key_exists( $field, $input ) && '' !== trim( (string) $input[ $field ] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The allowed robots directive tokens. A token outside this set is dropped before the array is
 * written, so a free-text directive can never persist.
 *
 * @return string[]
 */
function aafm_rankmath_robots_tokens(): array {
	return array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );
}

/**
 * Read every Rank Math field for a post into the unified output shape. Robots is read as the stored
 * array and imploded back to the unified comma string.
 *
 * @param int $id Post id.
 * @return array<string,mixed>
 */
function aafm_rankmath_read_fields( int $id ): array {
	$out = array(
		'plugin'  => 'rankmath',
		'post_id' => $id,
	);
	foreach ( aafm_rankmath_fields() as $field => $key ) {
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
function aafm_rankmath_output_properties(): array {
	$props = array(
		'plugin'  => array( 'type' => 'string' ),
		'post_id' => array( 'type' => 'integer' ),
	);
	foreach ( array_keys( aafm_rankmath_fields() ) as $field ) {
		$props[ $field ] = array( 'type' => 'string' );
	}
	$props['robots'] = array( 'type' => 'string' );
	return $props;
}

/**
 * Args for aafm/rankmath-get-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_rankmath_get_post(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/rankmath-get-post' ),
		'description'         => aafm_ability_description( 'aafm/rankmath-get-post' ),
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
			'properties' => aafm_rankmath_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_rankmath_get_post',
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
 * Execute aafm/rankmath-get-post.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_rankmath_get_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	return aafm_rankmath_read_fields( $id );
}

/**
 * Args for aafm/rankmath-update-post.
 *
 * @return array<string,mixed>
 */
function aafm_args_rankmath_update_post(): array {
	$properties = array(
		'post_id' => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
	);
	foreach ( array_keys( aafm_rankmath_fields() ) as $field ) {
		$properties[ $field ] = array( 'type' => 'string' );
	}
	$properties['robots'] = array(
		'type'        => 'string',
		'description' => __( 'Robots directives as a comma-separated list. Accepted tokens: index, noindex, nofollow, noarchive, noimageindex, nosnippet. Unknown tokens are dropped, and the value is stored as Rank Math\'s serialized directive array.', 'agent-abilities-for-mcp' ),
	);

	return array(
		'label'               => aafm_ability_label( 'aafm/rankmath-update-post' ),
		'description'         => __( "Writes a post's Rank Math SEO fields. URL fields are sanitized as URLs and robots is stored as the serialized directive array. Social images (og_image, twitter_image) must be URLs of existing media-library attachments so Rank Math can render them; a URL with no matching attachment is refused. Setting a Twitter field turns off the Facebook fallback so the Twitter values render. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => aafm_rankmath_output_properties(),
		),
		'execute_callback'    => 'aafm_exec_rankmath_update_post',
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
 * Execute aafm/rankmath-update-post.
 *
 * Writes the text/URL fields, then robots: split the CSV, validate each token against the allowlist,
 * and write the ARRAY (update_post_meta serializes it) - never a raw string, which Rank Math would
 * not honor. Returns the refreshed read shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_rankmath_update_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// Resolve every provided social-image URL to a media-library attachment id BEFORE any write. The
	// frontend renders the image from the id meta, not the URL, so a URL that maps to no attachment can
	// never render. Fail the whole write - resolving up front keeps a bad image from leaving a partial
	// write behind - rather than silently persisting a confident-empty URL.
	$image_id_fields = aafm_rankmath_image_id_fields();
	$resolved_ids    = array();
	foreach ( $image_id_fields as $field => $id_key ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$url = esc_url_raw( trim( (string) $input[ $field ] ) );
		if ( '' === $url ) {
			$resolved_ids[ $field ] = 0; // Cleared: blank the URL and the id companion below.
			continue;
		}
		$attachment_id = attachment_url_to_postid( $url );
		if ( 0 === $attachment_id ) {
			return new WP_Error(
				'aafm_rankmath_image_not_in_library',
				sprintf(
					/* translators: %s: the social image field name, for example og_image. */
					__( 'The %s URL does not resolve to a media-library attachment, so Rank Math cannot render it. Add the image to the media library and pass its attachment URL.', 'agent-abilities-for-mcp' ),
					$field
				),
				array( 'status' => 400 )
			);
		}
		$resolved_ids[ $field ] = $attachment_id;
	}

	$url_fields = aafm_rankmath_url_fields();
	foreach ( aafm_rankmath_fields() as $field => $key ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$raw   = (string) $input[ $field ];
		$clean = in_array( $field, $url_fields, true ) ? esc_url_raw( $raw ) : sanitize_text_field( $raw );
		update_post_meta( $id, $key, $clean );
	}

	// Persist the attachment-id companion meta the frontend actually renders from. A cleared image (0)
	// blanks the id so the resolver falls through to the featured image, never a stale id.
	foreach ( $resolved_ids as $field => $attachment_id ) {
		update_post_meta( $id, $image_id_fields[ $field ], $attachment_id > 0 ? $attachment_id : '' );
	}

	// Turn off the Twitter->Facebook fallback when Twitter-specific fields are provided; otherwise the
	// frontend reads the Facebook prefix and the Twitter title/description/image never render (verified:
	// includes/opengraph/class-twitter.php:93-106 and includes/frontend/paper/class-singular.php:160).
	// Rank Math's normalize_data() (includes/helpers/class-options.php:51-62) reads only the exact
	// string 'off' as false; an empty string, '0', or boolean false falls back to the truthy default.
	if ( aafm_rankmath_twitter_fields_provided( $input ) ) {
		update_post_meta( $id, 'rank_math_twitter_use_facebook', 'off' );
	}

	if ( array_key_exists( 'robots', $input ) ) {
		$allowed = aafm_rankmath_robots_tokens();
		$tokens  = array_filter( array_map( 'trim', explode( ',', (string) $input['robots'] ) ) );
		$kept    = array_values(
			array_filter(
				$tokens,
				static fn( string $t ): bool => in_array( $t, $allowed, true )
			)
		);
		update_post_meta( $id, 'rank_math_robots', $kept );
	}

	return aafm_rankmath_read_fields( $id );
}

/**
 * Validate a Rank Math schema type suffix. The type becomes part of a meta key
 * (rank_math_schema_{Type}), so only letters and digits are allowed, preserving case (Rank Math uses
 * PascalCase type names like Article, FAQPage, HowTo).
 *
 * @param string $type Raw type argument.
 * @return string The validated type, or '' when invalid.
 */
function aafm_rankmath_validate_schema_type( string $type ): string {
	return ( '' !== $type && (bool) preg_match( '/^[A-Za-z][A-Za-z0-9]*$/', $type ) ) ? $type : '';
}

/**
 * Args for aafm/rankmath-get-schema.
 *
 * @return array<string,mixed>
 */
function aafm_args_rankmath_get_schema(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/rankmath-get-schema' ),
		'description'         => aafm_ability_description( 'aafm/rankmath-get-schema' ),
		'category'            => 'aafm-reads',
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
					'description' => __( 'The schema type suffix, for example Article, FAQPage, or HowTo. Must start with a letter and contain only letters and digits (PascalCase).', 'agent-abilities-for-mcp' ),
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
		'execute_callback'    => 'aafm_exec_rankmath_get_schema',
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
 * Execute aafm/rankmath-get-schema.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_rankmath_get_schema( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$type = aafm_rankmath_validate_schema_type( (string) ( $input['type'] ?? '' ) );
	if ( '' === $type ) {
		return aafm_generic_error();
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
 * Args for aafm/rankmath-update-schema.
 *
 * @return array<string,mixed>
 */
function aafm_args_rankmath_update_schema(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/rankmath-update-schema' ),
		'description'         => aafm_ability_description( 'aafm/rankmath-update-schema' ),
		'category'            => 'aafm-writes',
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
					'description' => __( 'The schema type suffix, for example Article, FAQPage, or HowTo. Must start with a letter and contain only letters and digits (PascalCase).', 'agent-abilities-for-mcp' ),
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
		'execute_callback'    => 'aafm_exec_rankmath_update_schema',
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
 * Execute aafm/rankmath-update-schema.
 *
 * Refuses a bad type or a non-array payload, recursively sanitizes the schema (reusing the shared
 * aafm_sanitize_schema_array), and writes it to the dynamic rank_math_schema_{Type} meta. Returns the
 * refreshed shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_rankmath_update_schema( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}
	$type = aafm_rankmath_validate_schema_type( (string) ( $input['type'] ?? '' ) );
	if ( '' === $type ) {
		return aafm_generic_error();
	}
	$schema = $input['schema'] ?? null;
	if ( ! is_array( $schema ) ) {
		return aafm_generic_error();
	}
	$clean = aafm_sanitize_schema_array( $schema );
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
 * Args for aafm/rankmath-get-head.
 *
 * @return array<string,mixed>
 */
function aafm_args_rankmath_get_head(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/rankmath-get-head' ),
		'description'         => aafm_ability_description( 'aafm/rankmath-get-head' ),
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
		'execute_callback'    => 'aafm_exec_rankmath_get_head',
		'permission_callback' => 'aafm_perm_seo_get_head_floor',
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
 * Execute aafm/rankmath-get-head.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_rankmath_get_head( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! get_post( $id ) instanceof WP_Post || ! current_user_can( 'edit_post', $id ) ) {
		return aafm_generic_error();
	}

	/** This filter is documented in includes/abilities/yoast.php (the rendered-head seam). */
	$head = (string) apply_filters( 'aafm_seo_rendered_head', '', $id, 'rankmath' );

	if ( '' === trim( $head ) ) {
		// Unlike Yoast/AIOSEO, Rank Math emits a head (title, robots, canonical, OG) for every public
		// post, so an empty result never means "this post has no SEO head" - it means the renderer
		// could not build one. In practice that is a Rank Math install whose setup wizard was never
		// completed or skipped: rank_math()->frontend (and therefore ->head) is never initialised, so
		// the renderer returns '' on every request. Report that honestly instead of handing back an
		// empty head with success, which reads as a successfully-rendered-but-empty head. We do not
		// force the frontend to initialise here: that would mutate Rank Math's registration state as a
		// side effect of a read.
		return new WP_Error(
			'aafm_rankmath_head_unavailable',
			__( 'Rank Math could not render the SEO head for this post. This usually means the Rank Math setup wizard has not been completed or skipped, so its front-end head renderer is not initialised.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}

	return array(
		'post_id' => $id,
		'plugin'  => 'rankmath',
		'head'    => $head,
	);
}
