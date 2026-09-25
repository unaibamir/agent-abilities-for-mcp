<?php
/**
 * Yoast SEO abilities (Wave 5): yoast-get-post, yoast-update-post, yoast-get-head.
 *
 * Registers ONLY when Yoast is active (aafm_integration_active('yoast')). Yoast stores post SEO in
 * standard _yoast_wpseo_* post meta, read through core and written through the group writer. SEO
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
// supplies the canned head - production and test wiring never collide.
add_filter( 'aafm_seo_rendered_head', 'aafm_yoast_rendered_head', 10, 3 );

/**
 * Produce Yoast's rendered SEO head markup for a post via its public meta surface.
 *
 * Honors $source: returns the passed-through $head untouched unless $source is 'yoast', so a site
 * running multiple SEO plugins never has Yoast answer for another plugin's head. Guards the real
 * Yoast API (YoastSEO() and the for_post->get_head->html chain) defensively - any missing piece or
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
 * label, description, group, risk, and args builder - consumed by both the host-guarded live
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
			'description'  => __( 'Reads the rendered SEO head markup for a post from Yoast, best-effort: the returned head string is empty when Yoast exposes no head API for that post. Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
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
 * The canonical meaning of Yoast's robots_noindex enum, matching Yoast's own storage.
 *
 * Yoast stores 1 = noindex, 2 = index, 0 = the post-type/site default. This is the reverse of the
 * intuitive ordering, so it is the single source of truth for the schema description and is asserted
 * against Yoast's real semantics in the tests. Verified against wordpress-seo:
 * inc/class-wpseo-meta.php (the meta-robots-noindex options: 1 = No-index, 2 = Index) and
 * src/builders/indexable-post-builder.php::get_robots_noindex (1 -> noindex true, 2 -> index false).
 *
 * PHP casts the numeric string keys to ints; callers stringify them (array_map('strval', ...)) for
 * the string-typed enum.
 *
 * @return array<int,string> Enum value => human meaning.
 */
function aafm_yoast_robots_noindex_meaning(): array {
	return array(
		'0' => 'use the site default',
		'1' => 'noindex',
		'2' => 'index',
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
		$val           = aafm_meta_get( 'post', $id, $key, true );
		$out[ $field ] = is_scalar( $val ) ? (string) $val : '';
	}
	foreach ( aafm_yoast_robots_keys() as $field => $spec ) {
		$val           = aafm_meta_get( 'post', $id, $spec['key'], true );
		$out[ $field ] = is_scalar( $val ) ? (string) $val : '';
	}
	return $out;
}

/**
 * Every post meta key Yoast SEO reads that this plugin writes. Yoast prefixes its keys with
 * WPSEO_Meta::$meta_prefix, a static property rather than a constant, so the keys are listed in
 * full (wordpress-seo 28.5, inc/class-wpseo-meta.php: title, metadesc and focuskw in the general
 * fields, the robots keys and canonical in the advanced fields, and the social fields built from
 * the opengraph and twitter networks).
 *
 * @return string[]
 */
function aafm_yoast_meta_keys(): array {
	return array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		'_yoast_wpseo_canonical',
		'_yoast_wpseo_opengraph-title',
		'_yoast_wpseo_opengraph-description',
		'_yoast_wpseo_opengraph-image',
		'_yoast_wpseo_twitter-title',
		'_yoast_wpseo_twitter-description',
		'_yoast_wpseo_twitter-image',
		'_yoast_wpseo_meta-robots-noindex',
		'_yoast_wpseo_meta-robots-nofollow',
		'_yoast_wpseo_meta-robots-adv',
	);
}

/**
 * Write Yoast SEO post meta as one group.
 *
 * A key outside aafm_yoast_meta_keys() refuses the whole call before anything is read or
 * written, with one refused outcome per requested key. While Yoast's own filter is attached, a
 * requested key that Yoast stores as no row when set to its default is passed with that default,
 * so a clear Yoast turns into a delete counts as written.
 *
 * @param int                 $id              Post id.
 * @param array<string,mixed> $intended_by_key Meta key => unslashed value.
 * @return array<string,mixed>|WP_Error The group result, or the validation error.
 */
function aafm_yoast_write_meta( int $id, array $intended_by_key ) {
	$refused = aafm_seo_refuse_unlisted_keys( $id, $intended_by_key, aafm_yoast_meta_keys() );
	if ( null !== $refused ) {
		return $refused;
	}

	$absent_defaults = array();
	if ( class_exists( 'WPSEO_Meta' ) && false !== has_filter( 'update_post_metadata', array( 'WPSEO_Meta', 'remove_meta_if_default' ) ) ) {
		foreach ( array_keys( $intended_by_key ) as $key ) {
			if ( isset( WPSEO_Meta::$defaults[ $key ] ) ) {
				$absent_defaults[ $key ] = WPSEO_Meta::$defaults[ $key ];
			}
		}
	}

	return aafm_meta_set_group( 'post', $id, $intended_by_key, (string) get_object_subtype( 'post', $id ), array(), $absent_defaults );
}

/**
 * Refuse a vendor meta write that names a key outside the vendor's list: nothing is read or
 * written, and each requested key gets one refused outcome.
 *
 * @param int                 $id              Post id.
 * @param array<string,mixed> $intended_by_key Meta key => value.
 * @param string[]            $listed          The keys the vendor reads.
 * @return array<string,mixed>|null The refusal, or null when every key is listed.
 */
function aafm_seo_refuse_unlisted_keys( int $id, array $intended_by_key, array $listed ): ?array {
	$unlisted = array_diff( array_map( 'strval', array_keys( $intended_by_key ) ), $listed );
	if ( array() === $unlisted ) {
		return null;
	}
	$keys = array();
	foreach ( array_keys( $intended_by_key ) as $key ) {
		$entry                 = array( 'status' => AAFM_WRITE_REFUSED );
		$keys[ (string) $key ] = $entry;
		aafm_emit_write_outcome( $entry, aafm_meta_write_target( 'post', $id, (string) $key ) );
	}
	return array(
		'status' => AAFM_WRITE_REFUSED,
		'keys'   => $keys,
	);
}

/**
 * The error an SEO meta write returns when it did not land: the ability's own code, the message
 * naming the status, and identifiers only in the data.
 *
 * @param string      $code   The ability's error code.
 * @param string      $status The status that occurred.
 * @param int         $id     Post id.
 * @param string|null $key    The storage key the status belongs to, or null.
 * @return WP_Error
 */
function aafm_seo_write_error( string $code, string $status, int $id, ?string $key ): WP_Error {
	$base = aafm_meta_write_error( $status, 'write', 'post', $id, (string) $key );
	$data = $base->get_error_data();
	if ( AAFM_WRITE_PARTIAL === $status ) {
		return new WP_Error(
			$code,
			__( 'Some of the SEO fields were saved and some were not; read the post to see its current state.', 'agent-abilities-for-mcp' ),
			$data
		);
	}
	return new WP_Error( $code, $base->get_error_message(), $data );
}

/**
 * The response of a group SEO write: the ability's read shape, then `status` and `keys`, each key
 * carrying its status, a scalar previous value and modified_by_site when true. Any status other
 * than written or unchanged is the ability's error.
 *
 * @param string                       $code   The ability's error code.
 * @param int                          $id     Post id.
 * @param array<string,mixed>|WP_Error $result The group result.
 * @param callable                     $shape  Builds the read shape for the post.
 * @return array<string,mixed>|WP_Error
 */
function aafm_seo_group_write_response( string $code, int $id, $result, callable $shape ) {
	if ( is_wp_error( $result ) ) {
		// A site sanitize callback turned a value into something that is not text. The group names
		// no member for it.
		return aafm_seo_write_error( $code, AAFM_WRITE_REFUSED, $id, null );
	}
	if ( ! in_array( $result['status'], array( AAFM_WRITE_WRITTEN, AAFM_WRITE_UNCHANGED ), true ) ) {
		$first = null;
		foreach ( $result['keys'] as $key => $entry ) {
			if ( ! in_array( $entry['status'], array( AAFM_WRITE_WRITTEN, AAFM_WRITE_UNCHANGED ), true ) ) {
				$first = (string) $key;
				break;
			}
		}
		return aafm_seo_write_error( $code, $result['status'], $id, $first );
	}

	$keys = array();
	foreach ( $result['keys'] as $key => $entry ) {
		$wire = array( 'status' => $entry['status'] );
		if ( array_key_exists( 'previous', $entry ) && is_scalar( $entry['previous'] ) ) {
			$wire['previous'] = $entry['previous'];
		}
		if ( ! empty( $entry['modified_by_site'] ) ) {
			$wire['modified_by_site'] = true;
		}
		$keys[ (string) $key ] = $wire;
	}

	return aafm_with_checked_reads(
		static function () use ( $id, $result, $keys, $shape ): array {
			$out           = $shape( $id );
			$out['status'] = $result['status'];
			$out['keys']   = (object) $keys;
			return $out;
		},
		// With no key in the result nothing was written, so a failed read claims no write.
		aafm_seo_write_error( $code, array() === $result['keys'] ? AAFM_WRITE_READ_FAILED : AAFM_WRITE_UNCONFIRMED, $id, null )
	);
}

/**
 * Output-schema properties a group SEO write adds to its update ability's response.
 *
 * @return array<string,mixed>
 */
function aafm_seo_group_write_output_properties(): array {
	$meta = aafm_meta_write_output_properties();
	return array(
		'status' => $meta['status'],
		'keys'   => array(
			'type'                 => 'object',
			'additionalProperties' => array(
				'type'       => 'object',
				'properties' => array(
					'status'           => $meta['status'],
					'previous'         => $meta['previous'],
					'modified_by_site' => $meta['modified_by_site'],
				),
			),
		),
	);
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post whose Yoast SEO fields to read. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
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
				'idempotent'  => true,
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
	if ( ! aafm_exact_object( 'post', $id ) instanceof WP_Post ) {
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
	$properties               = array(
		'post_id' => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'ID of the post whose Yoast SEO fields to write. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
		),
	);
	$yoast_field_descriptions = array(
		'title'               => __( 'SEO title override for the post.', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Meta description override for the post.', 'agent-abilities-for-mcp' ),
		'focus_keyword'       => __( "Yoast's target focus keyword, used to score this post's on-page SEO.", 'agent-abilities-for-mcp' ),
		'canonical'           => __( 'Canonical URL override for the post, sanitized as a URL.', 'agent-abilities-for-mcp' ),
		'og_title'            => __( 'Open Graph (Facebook) title override for the post.', 'agent-abilities-for-mcp' ),
		'og_description'      => __( 'Open Graph (Facebook) description override for the post.', 'agent-abilities-for-mcp' ),
		'og_image'            => __( 'Open Graph (Facebook) share image URL, sanitized as a URL. Unlike Rank Math, no media-library attachment lookup is performed; any URL is stored as given.', 'agent-abilities-for-mcp' ),
		'twitter_title'       => __( 'Twitter Card title override. Unlike Rank Math and AIOSEO, Yoast has no separate use-Facebook-data fallback to disable, so this value is used as-is once set.', 'agent-abilities-for-mcp' ),
		'twitter_description' => __( 'Twitter Card description override. Unlike Rank Math and AIOSEO, Yoast has no separate use-Facebook-data fallback to disable, so this value is used as-is once set.', 'agent-abilities-for-mcp' ),
		'twitter_image'       => __( 'Twitter Card share image URL, sanitized as a URL. No media-library attachment lookup is performed; any URL is stored as given.', 'agent-abilities-for-mcp' ),
	);
	foreach ( array_keys( aafm_yoast_fields() ) as $field ) {
		$properties[ $field ] = array(
			'type'        => 'string',
			'description' => $yoast_field_descriptions[ $field ] ?? '',
		);
	}
	$noindex_meaning = aafm_yoast_robots_noindex_meaning();
	$noindex_pairs   = array();
	foreach ( $noindex_meaning as $value => $meaning ) {
		$noindex_pairs[] = $value . ' = ' . $meaning;
	}
	$properties['robots_noindex']  = array(
		'type'        => 'string',
		'enum'        => array_map( 'strval', array_keys( $noindex_meaning ) ),
		'description' => sprintf(
			/* translators: %s: the enum "value = meaning" pairs, e.g. "0 = use the site default, 1 = noindex, 2 = index". */
			__( 'Yoast noindex directive, matching Yoast\'s own storage: %s.', 'agent-abilities-for-mcp' ),
			implode( ', ', $noindex_pairs )
		),
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
			'properties' => array_merge( aafm_yoast_output_properties(), aafm_seo_group_write_output_properties() ),
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
 * Writes the text/URL fields (esc_url_raw for URLs, aafm_sanitize_plain_text otherwise -- a Yoast
 * title or description is rendered into the page head and into feeds, so it gets the same
 * invisible-character strip as a post title) and the three
 * robots keys (noindex/nofollow validated against their enums, adv filtered against the directive
 * allowlist). Returns the refreshed read shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_yoast_update_post( array $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	if ( ! aafm_exact_object( 'post', $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// Keyed by storage meta key, unslashed: the group writer slashes and sanitizes per key.
	$intended   = array();
	$url_fields = aafm_yoast_url_fields();
	foreach ( aafm_yoast_fields() as $field => $key ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$raw              = (string) $input[ $field ];
		$intended[ $key ] = in_array( $field, $url_fields, true ) ? esc_url_raw( $raw ) : aafm_sanitize_plain_text( $raw );
	}

	foreach ( aafm_yoast_robots_keys() as $field => $spec ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$raw = (string) $input[ $field ];
		if ( isset( $spec['enum'] ) ) {
			// An out-of-enum value is dropped (not written), so a bad directive cannot persist.
			if ( in_array( $raw, $spec['enum'], true ) ) {
				$intended[ $spec['key'] ] = $raw;
			}
			continue;
		}
		// adv: filter the CSV against the allowlist, drop unknown tokens, write the clean CSV.
		$tokens                   = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$kept                     = array_values(
			array_filter(
				$tokens,
				static fn( string $t ): bool => in_array( $t, $spec['allow'], true )
			)
		);
		$intended[ $spec['key'] ] = implode( ',', $kept );
	}

	if ( array() === $intended ) {
		return aafm_seo_group_write_response(
			'aafm_yoast_write_unconfirmed',
			$id,
			array(
				'status' => AAFM_WRITE_UNCHANGED,
				'keys'   => array(),
			),
			'aafm_yoast_read_fields'
		);
	}

	return aafm_seo_group_write_response( 'aafm_yoast_write_unconfirmed', $id, aafm_yoast_write_meta( $id, $intended ), 'aafm_yoast_read_fields' );
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
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post to render the Yoast SEO head for.', 'agent-abilities-for-mcp' ),
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
				'idempotent'  => true,
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
	$id   = absint( $input['post_id'] ?? 0 );
	$post = $id > 0 ? aafm_exact_object( 'post', $id ) : null;
	// Use the shared content-edit gate, not a bare edit_post: it enforces the operator's post-type
	// exposure allowlist, so a get-head read is refused on a non-exposed post type exactly as the
	// -get-meta sibling is. A bare edit_post would leak a non-allowlisted CPT's rendered SEO head.
	if ( ! $post instanceof WP_Post || ! aafm_can_edit_post_object( $post ) ) {
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
