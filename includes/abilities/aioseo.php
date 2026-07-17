<?php
/**
 * AIOSEO / All in One SEO abilities (Wave 5): aioseo-get-post, aioseo-update-post, aioseo-get-head.
 *
 * Registers ONLY when AIOSEO is active (aafm_integration_active('aioseo')). AIOSEO v4+ keeps post
 * SEO in a CUSTOM TABLE (wp_aioseo_posts), NOT post meta - the _aioseo_* meta keys are WPML-compat
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
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_aioseo_full_definitions' );

// Production rendered-head seam. Registered unconditionally because host plugins may load after us
// on plugins_loaded (so a load-time activity check could miss AIOSEO); the callback's own
// function_exists('aioseo') + aioseo()->head guards make it inert until AIOSEO is genuinely
// present. Under the PHPUnit stubs aioseo() returns a bare stdClass with no ->head, so this passes
// through and the test stub's own filter supplies the canned head - production and test wiring
// never collide.
add_filter( 'aafm_seo_rendered_head', 'aafm_aioseo_rendered_head', 10, 3 );

/**
 * Produce AIOSEO's rendered SEO head markup for a post.
 *
 * AIOSEO exposes no string-returning per-post head API: its head is emitted on wp_head via
 * aioseo()->head->output(), which echoes against the queried object. So this renders inside a
 * controlled, fully restored singular query for the post - snapshot the main-query globals, build a
 * throwaway singular WP_Query for the post, buffer output(), then restore the originals (including
 * the global $post) exactly. Honors $source (passthrough unless 'aioseo') and guards the API
 * defensively: a missing aioseo()->head, an error, or empty output all fall back to the passed
 * head rather than fataling.
 *
 * @param string $head   Head markup accumulated so far (passthrough default).
 * @param int    $post_id Post id.
 * @param string $source Integration slug the caller is asking for.
 * @return string
 */
function aafm_aioseo_rendered_head( string $head, int $post_id, string $source ): string {
	if ( 'aioseo' !== $source || ! function_exists( 'aioseo' ) ) {
		return $head;
	}

	$aioseo = aioseo();
	if ( ! is_object( $aioseo ) || ! isset( $aioseo->head ) || ! is_object( $aioseo->head ) || ! method_exists( $aioseo->head, 'output' ) ) {
		return $head; // AIOSEO present but no head renderer (e.g. older/newer shape): best-effort.
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return $head;
	}

	// Snapshot the query globals AIOSEO reads, so the throwaway query never leaks out of this call.
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
		// resolve to this post while AIOSEO builds the head. Both originals are snapshotted above and
		// restored in the finally block, so this swap never leaks past this call.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- temporary, restored in finally.
		$GLOBALS['wp_query'] = $temp_query;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- temporary, restored in finally.
		$GLOBALS['wp_the_query'] = $temp_query;
		if ( $temp_query->have_posts() ) {
			$temp_query->the_post();
		}

		ob_start();
		$aioseo->head->output();
		$rendered = (string) ob_get_clean();
	} catch ( \Throwable $e ) {
		// Make sure a half-open buffer from a throw inside output() is closed before we bail.
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

	return array_merge( $registry, aafm_aioseo_registry_definitions() );
}

/**
 * Contribute the All in One SEO definitions to the guard-independent full registry view.
 *
 * Unguarded by design: the full view enumerates every AIOSEO ability even when the host is inactive,
 * for the Integrations tab and the manifest. The live registration path never reads this filter, so
 * an inactive host still exposes zero tools.
 *
 * @param array<string,array<string,mixed>> $registry Integration rows accumulator.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_aioseo_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_aioseo_registry_definitions() );
}

/**
 * The All in One SEO registry rows, keyed by ability name. The single source of truth for these
 * abilities' label, description, group, risk, and args builder - consumed by both the host-guarded
 * live registration callback and the unguarded full-view callback.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_aioseo_registry_definitions(): array {
	return array(
		'aafm/aioseo-get-post'    => array(
			'label'        => __( 'Get post SEO (All in One SEO)', 'agent-abilities-for-mcp' ),
			'description'  => __( "Reads a post's SEO fields (title, description, canonical, social, and robots) from All in One SEO's own data store, not post meta. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'aioseo',
			'args_builder' => 'aafm_args_aioseo_get_post',
		),
		'aafm/aioseo-update-post' => array(
			'label'        => __( 'Update post SEO (All in One SEO)', 'agent-abilities-for-mcp' ),
			'description'  => __( "Writes a post's SEO fields through All in One SEO's own data store (not post meta). URL fields are sanitized as URLs. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'aioseo',
			'args_builder' => 'aafm_args_aioseo_update_post',
		),
		'aafm/aioseo-get-head'    => array(
			'label'        => __( 'Get post SEO head (All in One SEO)', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads the rendered SEO head markup for a post from All in One SEO, best-effort: the returned head string is empty when All in One SEO renders no head for that post. Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'aioseo',
			'args_builder' => 'aafm_args_aioseo_get_head',
		),
	);
}

/**
 * The AIOSEO text-and-URL field map: unified field => model property. The *_custom_url props hold the
 * social image URLs (sanitized with esc_url_raw on write); canonical_url is the canonical. An image
 * field also carries type_prop: the *_image_type column that must read 'custom_image' before AIOSEO
 * renders the custom URL at all (see aafm_exec_aioseo_update_post).
 *
 * @return array<string,array{prop:string,url:bool,type_prop?:string}>
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
			'prop'      => 'og_image_custom_url',
			'url'       => true,
			'type_prop' => 'og_image_type',
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
			'prop'      => 'twitter_image_custom_url',
			'url'       => true,
			'type_prop' => 'twitter_image_type',
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
	// The PHPStan stub guarantees these methods, but the real AIOSEO model documents its props and
	// methods as "subject to change," so the runtime guard is intentional defensive code.
	// @phpstan-ignore-next-line function.alreadyNarrowedType (the stub narrows, the real model may not).
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
		'label'               => aafm_ability_label( 'aafm/aioseo-get-post' ),
		'description'         => aafm_ability_description( 'aafm/aioseo-get-post' ),
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
				'idempotent'  => true,
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
		'label'               => aafm_ability_label( 'aafm/aioseo-update-post' ),
		'description'         => aafm_ability_description( 'aafm/aioseo-update-post' ),
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
 * sanitize_text_field on text, bool on robots), then ->save() - AIOSEO's own ORM writes the
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

		// A custom social image renders ONLY when its *_image_type column reads 'custom_image'.
		// That column defaults to 'default' (AIOSEO\Plugin\Common\Models\Post::getDefaults), which
		// makes AIOSEO\Plugin\Common\Social\Facebook/Twitter::getImage ignore the custom URL and fall
		// back to the site default image source. So writing og_image_custom_url/twitter_image_custom_url
		// alone persists a URL that never appears in og:image/twitter:image. Flip the type to
		// 'custom_image' whenever a non-empty image URL is written (mirroring the AIOSEO editor), and
		// back to 'default' when the caller clears it, so the read-back and the live tag agree.
		if ( isset( $spec['type_prop'] ) && property_exists( $model, $spec['type_prop'] ) ) {
			$model->{$spec['type_prop']} = '' !== $model->$prop ? 'custom_image' : 'default';
		}
	}
	$robots_touched = false;
	foreach ( aafm_aioseo_robots_fields() as $field => $prop ) {
		if ( ! array_key_exists( $field, $input ) || ! property_exists( $model, $prop ) ) {
			continue;
		}
		$model->$prop   = (bool) $input[ $field ];
		$robots_touched = true;
	}

	// AIOSEO honors the per-post robots_noindex/robots_nofollow ONLY when robots_default is falsy
	// (see AIOSEO\Plugin\Common\Meta\Robots: it reads the custom flags behind `! $metaData->robots_default`,
	// and the sitemap queries treat robots_default = 1 as "use site default, ignore noindex"). A fresh
	// row defaults robots_default to true, so writing noindex/nofollow alone is a silent no-op. Flip it
	// off whenever the caller sets an explicit robots flag, mirroring what the AIOSEO editor does.
	if ( $robots_touched && property_exists( $model, 'robots_default' ) ) {
		$model->robots_default = false;
	}

	// AIOSEO's model save() returns false on a custom-table write failure. Surface it rather
	// than reporting a stale read as a success.
	if ( false === $model->save() ) {
		return aafm_generic_error();
	}

	return aafm_aioseo_read_fields( $id );
}

/**
 * Args for aafm/aioseo-get-head.
 *
 * @return array<string,mixed>
 */
function aafm_args_aioseo_get_head(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/aioseo-get-head' ),
		'description'         => aafm_ability_description( 'aafm/aioseo-get-head' ),
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
				'idempotent'  => true,
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
