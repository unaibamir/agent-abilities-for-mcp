<?php
/**
 * AIOSEO / All in One SEO abilities (Wave 5): aioseo-get-post, aioseo-update-post, aioseo-get-head.
 *
 * Registers ONLY when AIOSEO is active (aafm_integration_active('aioseo')). AIOSEO v4+ keeps post
 * SEO in a CUSTOM TABLE (wp_aioseo_posts), NOT post meta. So reads go through AIOSEO's own Post
 * model: AIOSEO\Plugin\Common\Models\Post::getPost($id) returns the row. Writes go through the
 * model's own AIOSEO\Plugin\Common\Models\Post::savePost($id, $data) (fix round 1, delegation
 * audit sweep, 210-sweep-B5-report.md), the same method AIOSEO's own REST controller and every
 * other real caller in its codebase use - NOT a bare property-set-then-save(), which skips
 * savePost()'s own aioseo_save_post/aioseo_insert_post hooks, its default-title/description
 * tracking, and its _aioseo_* traditional-post-meta sync.
 *
 * Correcting a previous misreading of that meta here: the _aioseo_* keys are WPML-compat SHADOW
 * COPIES AIOSEO writes on save so that WPML/Polylang can carry them across when they duplicate a
 * post into another language (those plugins copy post meta, not AIOSEO's own custom-table row) -
 * they are not meant for AIOSEO itself to read back, and this plugin does not read them either.
 * Going through savePost() keeps that shadow meta in sync, which matters for this plugin's own
 * documented, tested WPML support.
 *
 * This NEVER runs raw SQL. The model is guarded with class_exists/method_exists; on absence the
 * ability returns a generic error rather than fataling. Schema is OMITTED (AIOSEO's schema column
 * is internal, undocumented JSON). SEO data is post content, so every per-object ability gates on
 * edit_post($id).
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
			'description'  => __( "Writes a post's SEO fields through All in One SEO's own data store (not post meta). URL fields are sanitized as URLs. Setting a Twitter field turns off the use-OpenGraph fallback so the Twitter title, description, and image render on their own. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
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
 * The AIOSEO boolean robots fields: unified field => Post::savePost()'s own PATCH-DATA key.
 *
 * Fix round 1, delegation audit sweep: Post::savePost()'s field map (Model::getSanitizeFieldMap())
 * uses BARE names for the robots flags - 'noindex', 'nofollow', 'default' - not the model COLUMN
 * names ('robots_noindex', 'robots_nofollow', 'robots_default') aafm_aioseo_robots_fields() above
 * returns for reading. Sending the column name as a $data key to savePost() would be silently
 * ignored (patch semantics: only keys savePost() recognizes are applied), so the write path needs
 * its own map. Verified against the real vendor source, not assumed from the naming pattern the
 * other fields happen to follow.
 *
 * @return array<string,string>
 */
function aafm_aioseo_robots_save_data_keys(): array {
	return array(
		'robots_noindex'  => 'noindex',
		'robots_nofollow' => 'nofollow',
	);
}

/**
 * Whether the write carries a non-empty Twitter-specific field. AIOSEO's Twitter renderer returns
 * the Facebook/OpenGraph value whenever twitter_use_og is truthy (its default), so a written
 * twitter title/description/image only renders once that fallback is turned off - and only when a
 * Twitter field is actually provided, so an og-only or unrelated write never touches it.
 *
 * @param array<string,mixed> $input Validated input.
 * @return bool
 */
function aafm_aioseo_twitter_fields_provided( array $input ): bool {
	foreach ( array( 'twitter_title', 'twitter_description', 'twitter_image' ) as $field ) {
		if ( array_key_exists( $field, $input ) && '' !== trim( (string) $input[ $field ] ) ) {
			return true;
		}
	}
	return false;
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
 * Whether an AIOSEO string write actually persisted, tolerating the benign normalization AIOSEO may
 * apply on save. An exact match passes immediately; otherwise both the persisted and the expected
 * value are canonicalized the same way (trimmed, and for URL fields re-encoded with a trailing slash
 * dropped) before comparison, so a write that landed but was reformatted is not read back as a
 * failure. A genuine non-persist still fails: the old or default value left in the row is not a
 * normalized form of what we asked to write.
 *
 * @param string $persisted Value read back from AIOSEO after save().
 * @param string $expected  Sanitized value we asked AIOSEO to store.
 * @param bool   $is_url    Whether the field is a URL (adds URL canonicalization on top of trim).
 * @return bool
 */
function aafm_aioseo_value_persisted( string $persisted, string $expected, bool $is_url ): bool {
	if ( $persisted === $expected ) {
		return true;
	}

	$persisted_trimmed = trim( $persisted );
	$expected_trimmed  = trim( $expected );
	if ( $persisted_trimmed === $expected_trimmed ) {
		return true;
	}
	if ( $is_url ) {
		return untrailingslashit( esc_url_raw( $persisted_trimmed ) ) === untrailingslashit( esc_url_raw( $expected_trimmed ) );
	}
	return false;
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post whose All in One SEO fields to read. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
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
	$properties                = array(
		'post_id' => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'ID of the post whose All in One SEO fields to write. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
		),
	);
	$aioseo_field_descriptions = array(
		'title'               => __( 'SEO title override for the post.', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Meta description override for the post.', 'agent-abilities-for-mcp' ),
		'canonical'           => __( 'Canonical URL override for the post, sanitized as a URL.', 'agent-abilities-for-mcp' ),
		'og_title'            => __( 'Open Graph (Facebook) title override for the post.', 'agent-abilities-for-mcp' ),
		'og_description'      => __( 'Open Graph (Facebook) description override for the post.', 'agent-abilities-for-mcp' ),
		'og_image'            => __( "Open Graph (Facebook) share image URL. A non-empty value switches AIOSEO's image source to custom so this URL actually renders instead of its default source; clearing the value resets that source only when it was already set to custom.", 'agent-abilities-for-mcp' ),
		'twitter_title'       => __( "Twitter Card title override. Setting any twitter_* field turns off AIOSEO's use-OpenGraph fallback so this value renders instead of the Facebook/OG title.", 'agent-abilities-for-mcp' ),
		'twitter_description' => __( "Twitter Card description override. Setting any twitter_* field turns off AIOSEO's use-OpenGraph fallback so this value renders instead of the Facebook/OG description.", 'agent-abilities-for-mcp' ),
		'twitter_image'       => __( 'Twitter Card share image URL (same custom-source switch as og_image). Setting any twitter_* field also turns off the use-OpenGraph fallback.', 'agent-abilities-for-mcp' ),
	);
	foreach ( array_keys( aafm_aioseo_fields() ) as $field ) {
		$properties[ $field ] = array(
			'type'        => 'string',
			'description' => $aioseo_field_descriptions[ $field ] ?? '',
		);
	}
	$aioseo_robots_descriptions = array(
		'robots_noindex'  => __( "Whether to noindex this post. Setting either robots flag turns off AIOSEO's 'use site default' robots setting so the explicit flags actually take effect.", 'agent-abilities-for-mcp' ),
		'robots_nofollow' => __( 'Whether to nofollow links on this post (see robots_noindex for the site-default interaction this also turns off).', 'agent-abilities-for-mcp' ),
	);
	foreach ( array_keys( aafm_aioseo_robots_fields() ) as $field ) {
		$properties[ $field ] = array(
			'type'        => 'boolean',
			'description' => $aioseo_robots_descriptions[ $field ] ?? '',
		);
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
 * Fix round 1 (delegation audit sweep, 210-sweep-B5-report.md): builds a $data array (keyed by
 * AIOSEO's OWN savePost() patch-data keys, sanitized: esc_url_raw on URL fields, aafm_sanitize_
 * plain_text on text, bool on robots) and calls Post::savePost($id, $data) - the same method
 * AIOSEO's own REST controller and every other real caller in its codebase use - instead of
 * setting props on a fetched model and calling the low-level ORM ->save() directly. That gets this
 * write the vendor's own aioseo_save_post/aioseo_insert_post hooks, its default-title/description
 * tracking, and its _aioseo_* shadow-meta sync for WPML/Polylang, none of which a bare ->save()
 * exercises. The image-type-flip and Twitter-fallback business logic below is this plugin's own,
 * with no equivalent inside savePost() (confirmed against the real vendor source), so it still
 * reads the CURRENT model state via getPost() to decide what to flip; it now writes its decisions
 * into $data instead of onto the model object. A field absent on the installed model version is
 * skipped rather than sent (so the write never invents a property). Returns the refreshed read
 * shape.
 *
 * Known, deliberately unhandled edge case: savePost()'s own checkForDefaultFormat() nulls a
 * caller-provided title/description that is byte-identical to the site's CURRENT default title/
 * description FORMAT TEMPLATE (e.g. the literal tag string, not a rendered title), so that future
 * template edits keep propagating to that post. Adopting that is the whole point of delegating to
 * savePost() and is not treated as a defect - but it means a write whose literal value happens to
 * match that template reads back as the vendor's dynamic default rather than the literal string, so
 * the read-back verification below could report a false failure for that single, narrow case. Not
 * modelled here: it requires an agent to write the exact configured template tag text verbatim,
 * which is not a value an agent-driven title/description write would plausibly produce.
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
	// Fix round 2 (test-quality sweep finding): this branch is untested in either direction, on
	// purpose, not by oversight - it is the same shape as the sibling aafm_aioseo_model_available()
	// guard just above and every other vendor-absence guard in this codebase (e.g. class_exists()
	// checks for WC_Product_Simple). The test-environment stub (tests/stubs/IntegrationStubs.php)
	// always defines the full model class with every method this file calls, and PHP cannot
	// undefine a class or a method on it once the process has loaded it, so there is no clean way
	// to drive method_exists() false here without a dedicated filterable seam over AAFM_AIOSEO_MODEL
	// itself - a real code change purely to make this one guard testable, which would be
	// contorting the stub for a defensive branch this codebase already accepts leaving unexercised
	// elsewhere. Left as a documented gap rather than a fragile test.
	if ( ! method_exists( $class, 'savePost' ) ) {
		return aafm_generic_error();
	}
	$model = $class::getPost( $id );
	if ( ! is_object( $model ) ) {
		return aafm_generic_error();
	}

	// Desired values keyed by unified field name (not the savePost() data key), so they can be
	// diffed straight against aafm_aioseo_read_fields()'s output after save() - see the read-back
	// comment below (L11). $data is keyed by AIOSEO's OWN savePost() patch keys instead.
	$desired = array();
	$data    = array();

	foreach ( aafm_aioseo_fields() as $field => $spec ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$prop = $spec['prop'];
		if ( ! property_exists( $model, $prop ) ) {
			continue; // The installed model version does not expose this prop; do not invent it.
		}
		$raw               = (string) $input[ $field ];
		$clean             = $spec['url'] ? esc_url_raw( $raw ) : aafm_sanitize_plain_text( $raw );
		$data[ $prop ]     = $clean;
		$desired[ $field ] = $clean;

		// A custom social image renders ONLY when its *_image_type column reads 'custom_image'.
		// That column defaults to 'default' (AIOSEO\Plugin\Common\Models\Post::getDefaults), which
		// makes AIOSEO\Plugin\Common\Social\Image::getImage ignore the custom URL and fall back to the
		// site default image source. So writing og_image_custom_url/twitter_image_custom_url alone
		// persists a URL that never appears in og:image/twitter:image. Flip the type to 'custom_image'
		// whenever a non-empty image URL is written (mirroring the AIOSEO editor). When the caller
		// clears the URL, ONLY reset a type that currently reads 'custom_image' (it would otherwise
		// point at the now-empty custom URL) - a type naming any other AIOSEO source (featured, attach,
		// content, author, auto; see Image::getImage) is left untouched, so clearing a custom URL never
		// silently swaps a rendered featured/attachment image for the site default. Reads the model's
		// CURRENT (pre-write) type, since $model is not mutated by this rewrite.
		if ( isset( $spec['type_prop'] ) && property_exists( $model, $spec['type_prop'] ) ) {
			$type_prop = $spec['type_prop'];
			if ( '' !== $clean ) {
				$data[ $type_prop ] = 'custom_image';
			} elseif ( 'custom_image' === $model->$type_prop ) {
				$data[ $type_prop ] = 'default';
			}
		}
	}
	// AIOSEO's Twitter renderer short-circuits to the Facebook/OpenGraph image, title, and description
	// whenever twitter_use_og is truthy: AIOSEO\Plugin\Common\Social\Twitter::getImage/getTitle/
	// getDescription each `return aioseo()->social->facebook->get*()` behind `! empty( $metaData->
	// twitter_use_og )`, BEFORE they ever read the twitter-specific column. That flag defaults truthy
	// (Post::setDynamicDefaults copies the site social->twitter->general->useOgData option, whose
	// default is true), so writing a twitter_image/twitter_title/twitter_description alone persists a
	// value the Twitter card never shows - even with twitter_image_type flipped to 'custom_image'
	// above. Turn it off whenever a non-empty Twitter field is written so the provided values render,
	// mirroring the AIOSEO editor. Leaving twitter_title/twitter_description empty stays safe: with
	// twitter_use_og false and the field empty, getTitle/getDescription still fall back to the
	// Facebook/OG value (Twitter.php: `return $title ? $title : ...->facebook->getTitle()`), the same
	// output as the truthy branch - so this never blanks a card that was rendering an OG title/desc.
	if ( aafm_aioseo_twitter_fields_provided( $input ) && property_exists( $model, 'twitter_use_og' ) ) {
		$data['twitter_use_og'] = false;
	}

	$robots_touched   = false;
	$robots_data_keys = aafm_aioseo_robots_save_data_keys();
	foreach ( aafm_aioseo_robots_fields() as $field => $prop ) {
		if ( ! array_key_exists( $field, $input ) || ! property_exists( $model, $prop ) ) {
			continue;
		}
		$value                               = (bool) $input[ $field ];
		$data[ $robots_data_keys[ $field ] ] = $value;
		$desired[ $field ]                   = $value;
		$robots_touched                      = true;
	}

	// AIOSEO honors the per-post robots_noindex/robots_nofollow ONLY when robots_default is falsy
	// (see AIOSEO\Plugin\Common\Meta\Robots: it reads the custom flags behind `! $metaData->robots_default`,
	// and the sitemap queries treat robots_default = 1 as "use site default, ignore noindex"). A fresh
	// row defaults robots_default to true, so writing noindex/nofollow alone is a silent no-op. Flip it
	// off whenever the caller sets an explicit robots flag, mirroring what the AIOSEO editor does.
	// savePost()'s own patch-data key for this column is the bare 'default', not 'robots_default'.
	if ( $robots_touched && property_exists( $model, 'robots_default' ) ) {
		$data['default'] = false;
	}

	// savePost() itself early-returns false on an empty $data without touching the row (real vendor
	// source: `if ( empty( $data ) ) { return false; }`), so an empty PATCH (post_id only, or every
	// field already skipped above) is correctly a no-op either way - skip the call entirely rather
	// than rely on that early return, so the test-stub model does not need to replicate it too.
	if ( array() !== $data ) {
		// AIOSEO's savePost() reports failure as a DB error string or void, never a bool worth
		// branching on directly (mirrors the prior ->save() shape, L11: SeoContractTest::
		// test_aioseo_model_save_returns_void_not_bool()). Verify persistence a different way: force
		// a fresh read of the model and diff it against what we just asked to be written, field by
		// field. A real write failure still surfaces as a read-back mismatch.
		$class::savePost( $id, $data );
	}

	$after     = aafm_aioseo_read_fields( $id );
	$url_field = array();
	foreach ( aafm_aioseo_fields() as $field => $spec ) {
		$url_field[ $field ] = (bool) $spec['url'];
	}
	foreach ( $desired as $field => $value ) {
		if ( is_bool( $value ) ) {
			// Robots flags are stored verbatim; an exact bool check is right, and a genuine failure
			// (the old flag still in the row) still trips it.
			if ( (bool) $after[ $field ] !== $value ) {
				return aafm_generic_error();
			}
			continue;
		}
		// String fields: tolerate the benign normalization AIOSEO may apply on save (trimming, or a
		// URL re-encode / trailing-slash change) so a write that genuinely persisted is not reported as
		// a failure just because the stored form differs cosmetically. A real non-persist - the old or
		// default value still sitting in the row - is not a normalized form of what we wrote, so it
		// still fails.
		if ( ! aafm_aioseo_value_persisted( (string) $after[ $field ], (string) $value, $url_field[ $field ] ?? false ) ) {
			return aafm_generic_error();
		}
	}

	return $after;
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post to render the All in One SEO head for.', 'agent-abilities-for-mcp' ),
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
	$id   = absint( $input['post_id'] ?? 0 );
	$post = $id > 0 ? get_post( $id ) : null;
	// Use the shared content-edit gate, not a bare edit_post: it enforces the operator's post-type
	// exposure allowlist, so a get-head read is refused on a non-exposed post type exactly as the
	// -get-meta sibling is. A bare edit_post would leak a non-allowlisted CPT's rendered SEO head.
	if ( ! $post instanceof WP_Post || ! aafm_can_edit_post_object( $post ) ) {
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
