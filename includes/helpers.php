<?php
/**
 * Shared validation, redaction, pagination, and error helpers.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Validate a post type against the eligibility floor AND the default-deny allowlist.
 *
 * @param string $type Requested post type.
 * @return string|WP_Error Sanitized type, or error if not allowed.
 */
function aafm_validate_post_type( string $type ) {
	$type = sanitize_key( $type );

	// The hard floor (public, non-internal) runs first and is independent of the allowlist —
	// it rejects attachment/revision/internal types even if one is forced into the option.
	if ( ! aafm_post_type_is_eligible( $type ) ) {
		return new WP_Error( 'aafm_invalid_post_type', __( 'Unsupported post type.', 'agent-abilities-for-mcp' ) );
	}

	// Then default-deny: only types the operator has explicitly exposed (post/page always-on).
	if ( ! in_array( $type, aafm_allowed_post_types(), true ) ) {
		return new WP_Error( 'aafm_post_type_not_allowed', __( 'This content type is not exposed to agents.', 'agent-abilities-for-mcp' ) );
	}

	return $type;
}

/**
 * The hard eligibility floor for an exposed content type, independent of the allowlist.
 *
 * Reads the RESOLVED WP_Post_Type object rather than get_post_types(['public'=>true]),
 * which wrongly includes the built-in `attachment` type. post/page are built-in but are
 * our shipped surface, so they are allowed by explicit special-case. Every internal type
 * (revision, nav_menu_item, wp_block, wp_template, …) is caught by public===false and/or
 * _builtin===true; `attachment` is the lone public-but-internal case, caught by _builtin.
 *
 * @param string $type Post type slug.
 * @return bool True when the type may be exposed at all.
 */
function aafm_post_type_is_eligible( string $type ): bool {
	$type = sanitize_key( $type );
	if ( in_array( $type, array( 'post', 'page' ), true ) ) {
		return true;
	}
	$obj = get_post_type_object( $type );
	return $obj instanceof WP_Post_Type && true === $obj->public && false === $obj->_builtin;
}

/**
 * Every registered post type that clears the eligibility floor.
 *
 * Includes post/page (they are eligible); the admin selector excludes those two because
 * they are always-on. Used to populate the "Exposed content types" UI and to intersect
 * posted allowlist input down to real, eligible types.
 *
 * @return list<string>
 */
function aafm_eligible_post_types(): array {
	$types = get_post_types( array(), 'names' );
	return array_values( array_filter( $types, 'aafm_post_type_is_eligible' ) );
}

/**
 * The default-deny post-type allowlist: post/page always-on, every other eligible type
 * opt-in via the aafm_allowed_post_types option (admin selector) or the matching filter.
 *
 * The eligibility floor is applied AFTER the option read AND after the filter, so neither
 * a junk option value nor a rogue filter can slip an ineligible type (attachment, revision,
 * a private CPT) into the exposed set. post/page are merged in unconditionally to preserve
 * the shipped, reviewed surface.
 *
 * @return list<string>
 */
function aafm_allowed_post_types(): array {
	$stored = get_option( 'aafm_allowed_post_types', array() );
	$stored = is_array( $stored ) ? array_map( 'sanitize_key', $stored ) : array();

	$allowed = array_merge( array( 'post', 'page' ), $stored );
	$allowed = array_values( array_unique( array_filter( $allowed, 'aafm_post_type_is_eligible' ) ) );

	/**
	 * Filters the post types exposed to AI agents.
	 *
	 * Values are re-floored after this filter, so adding an ineligible type is a no-op.
	 *
	 * @param list<string> $allowed Eligible, exposed post-type slugs.
	 */
	$allowed = apply_filters( 'aafm_allowed_post_types', $allowed );

	return array_values( array_filter( array_map( 'sanitize_key', (array) $allowed ), 'aafm_post_type_is_eligible' ) );
}

/**
 * Resolve the post types a cross-type search may touch: the requested set intersected with
 * the exposed allowlist (never wider). An empty/omitted request means the whole allowlist.
 *
 * @param array<int, string> $requested Caller-supplied post types (may be empty).
 * @return list<string>
 */
function aafm_resolve_search_post_types( array $requested ): array {
	$allowed = aafm_allowed_post_types();
	if ( empty( $requested ) ) {
		return $allowed;
	}
	return array_values( array_intersect( array_map( 'sanitize_key', $requested ), $allowed ) );
}

/**
 * Whether a meta key is permanently blocked from agent access (even if allowlisted).
 *
 * Blocks protected (`_`-prefixed) meta, the auth-sensitive denylist stolen from
 * easy-mcp-ai's User_Meta_Auth_Guard, and this install's capability/user-level keys.
 * The aafm_hard_blocked_meta_keys filter may ADD keys; the built-ins are re-merged
 * after it, so a filter can never unblock one.
 *
 * @param string $key Meta key.
 * @return bool
 */
function aafm_hard_blocked_meta_key( string $key ): bool {
	global $wpdb;
	$key = (string) $key;
	if ( '' === trim( $key ) ) {
		return true;
	}
	if ( is_protected_meta( $key, 'post' ) ) {
		return true;
	}
	$builtin = array(
		'session_tokens',
		'_application_passwords',
		'wp_capabilities',
		'wp_user_level',
		'wp_user-settings',
		'wp_user-settings-time',
		'default_password_nonce',
		'_password_reset_key',
		'community-events-location',
		'_new_email',
		$wpdb->prefix . 'capabilities',
		$wpdb->prefix . 'user_level',
	);
	/**
	 * Filters EXTRA meta keys to hard-block. Built-ins are re-merged after, so this
	 * can only add blocks, never remove them.
	 *
	 * @param list<string> $extra Extra keys to block.
	 */
	$extra   = (array) apply_filters( 'aafm_hard_blocked_meta_keys', array() );
	$blocked = array_merge( $builtin, array_map( 'strval', $extra ) );
	if ( in_array( $key, $blocked, true ) ) {
		return true;
	}
	// Any prefix*capabilities form, including multisite per-blog keys (wp_2_capabilities).
	return (bool) preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . '\d*_?capabilities$/', $key );
}

/**
 * Default-deny meta-key allowlist. Default empty; opt-in via the aafm_allowed_meta_keys
 * option (admin textarea) or the matching filter. Hard-blocked keys are stripped AFTER the
 * option read AND after the filter, so neither a junk write nor a rogue filter exposes one.
 *
 * @return list<string>
 */
function aafm_allowed_meta_keys(): array {
	$stored = get_option( 'aafm_allowed_meta_keys', array() );
	$stored = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	$stored = array_values( array_filter( $stored, static fn( $k ) => ! aafm_hard_blocked_meta_key( $k ) ) );

	/**
	 * Filters the meta keys exposed to AI agents. Re-floored against the hard-block
	 * after this filter, so adding a blocked key is a no-op.
	 *
	 * @param list<string> $stored Allowlisted, non-blocked keys.
	 */
	$filtered = (array) apply_filters( 'aafm_allowed_meta_keys', $stored );

	return array_values(
		array_unique(
			array_filter( array_map( 'strval', $filtered ), static fn( $k ) => '' !== $k && ! aafm_hard_blocked_meta_key( $k ) )
		)
	);
}

/**
 * Validate a meta key: must be allowlisted AND not hard-blocked. One generic error code
 * for both failure modes so a caller cannot distinguish "blocked" from "not allowlisted".
 *
 * @param string $key Requested meta key.
 * @return string|WP_Error
 */
function aafm_validate_meta_key( string $key ) {
	$key = trim( (string) $key );
	if ( '' === $key || aafm_hard_blocked_meta_key( $key ) || ! in_array( $key, aafm_allowed_meta_keys(), true ) ) {
		return new WP_Error( 'aafm_meta_key_not_allowed', __( 'This meta key is not available to agents.', 'agent-abilities-for-mcp' ) );
	}
	return $key;
}

/**
 * Coerce + sanitize a meta value for writing. Scalar-only: arrays/objects are refused so
 * the agent can never store a serialized structure. Strings are plain-text sanitized (meta
 * is not rendered as post content); the result is then run through sanitize_meta so any
 * registered sanitize_callback still applies. The object subtype ('post') is passed so the
 * per-key sanitize_post_meta_{$key} callback actually fires — the 3-arg form skips it.
 * Whatever the callback returns is re-asserted as scalar before it can be stored or returned,
 * so a callback that coerces the value into an array/object is refused (defence in depth,
 * symmetric with the scalar-only read path).
 *
 * @param string $key   Meta key (already validated/allowlisted by the caller).
 * @param mixed  $value Raw value from input.
 * @return mixed|WP_Error Sanitized scalar, or error if non-scalar.
 */
function aafm_sanitize_meta_value( string $key, $value ) {
	if ( ! is_scalar( $value ) ) {
		return new WP_Error( 'aafm_meta_value_invalid', __( 'Only text, number, or boolean meta values are supported.', 'agent-abilities-for-mcp' ) );
	}
	if ( is_string( $value ) ) {
		$value = sanitize_text_field( $value );
	}
	$value = sanitize_meta( $key, $value, 'post', 'post' );
	if ( ! is_scalar( $value ) ) {
		return new WP_Error( 'aafm_meta_value_invalid', __( 'Only text, number, or boolean meta values are supported.', 'agent-abilities-for-mcp' ) );
	}
	return $value;
}

/**
 * Resolve a post type's cap object and whether it uses core's meta-cap mapping.
 *
 * The Tier-1 cap keys (edit_post, delete_post, publish_posts, read_private_posts) are
 * guaranteed present on ->cap across every registration style. `mapped` reflects
 * map_meta_cap: when false, per-object edit_post/delete_post checks degrade to a bare
 * singular primitive with no author/status containment — the footgun the write gates refuse.
 *
 * @param string $type Post type slug.
 * @return array{object: ?WP_Post_Type, mapped: bool}
 */
function aafm_type_caps( string $type ): array {
	$obj = get_post_type_object( $type );
	return array(
		'object' => $obj instanceof WP_Post_Type ? $obj : null,
		'mapped' => $obj instanceof WP_Post_Type && (bool) $obj->map_meta_cap,
	);
}

/**
 * Whether the current user may READ a single object of its type through the content abilities.
 *
 * Type must clear the floor AND the allowlist. Public-status objects use the cap-free fast
 * path (matches the list reads). A non-public status is readable only on a map_meta_cap type,
 * via that type's own per-object edit cap; non-mapped non-public reads are denied.
 *
 * @param WP_Post $post Target object.
 * @return bool
 */
function aafm_can_read_post_object( WP_Post $post ): bool {
	if ( is_wp_error( aafm_validate_post_type( $post->post_type ) ) ) {
		return false;
	}
	if ( in_array( $post->post_status, get_post_stati( array( 'public' => true ) ), true ) ) {
		return true;
	}
	$caps = aafm_type_caps( $post->post_type );
	if ( ! $caps['mapped'] || ! $caps['object'] instanceof WP_Post_Type ) {
		return false;
	}
	return current_user_can( (string) $caps['object']->cap->edit_post, $post->ID );
}

/**
 * Whether the current user may EDIT a single object through the content abilities.
 *
 * Type must clear the floor AND the allowlist AND be map_meta_cap===true (Q5 write-safety).
 * For a non-mapped type the write is refused outright rather than trusting a degraded
 * per-object cap that can fail OPEN. For post/page (mapped) this resolves to today's
 * current_user_can( 'edit_post'/'edit_page', $id ) — zero behaviour change.
 *
 * @param WP_Post $post Target object.
 * @return bool
 */
function aafm_can_edit_post_object( WP_Post $post ): bool {
	$caps = aafm_writable_type_caps( $post );
	return null !== $caps && current_user_can( (string) $caps->cap->edit_post, $post->ID );
}

/**
 * Whether the current user may TRASH a single object through the content abilities.
 *
 * Same floor + allowlist + map_meta_cap gate as edit; uses the type's own delete cap.
 *
 * @param WP_Post $post Target object.
 * @return bool
 */
function aafm_can_delete_post_object( WP_Post $post ): bool {
	$caps = aafm_writable_type_caps( $post );
	return null !== $caps && current_user_can( (string) $caps->cap->delete_post, $post->ID );
}

/**
 * Shared write-eligibility resolver: returns the cap object only when the post's type is
 * exposed (floor + allowlist) AND map_meta_cap===true. Null means "refuse the write".
 *
 * @param WP_Post $post Target object.
 * @return WP_Post_Type|null
 */
function aafm_writable_type_caps( WP_Post $post ): ?WP_Post_Type {
	if ( is_wp_error( aafm_validate_post_type( $post->post_type ) ) ) {
		return null;
	}
	$caps = aafm_type_caps( $post->post_type );
	if ( ! $caps['mapped'] || ! $caps['object'] instanceof WP_Post_Type ) {
		return null;
	}
	return $caps['object'];
}

/**
 * Validate a taxonomy against the public allow-list.
 *
 * @param string $taxonomy Requested taxonomy.
 * @return string|WP_Error Sanitized taxonomy, or error if not allowed.
 */
function aafm_validate_taxonomy( string $taxonomy ) {
	$taxonomy = sanitize_key( $taxonomy );
	$allowed  = get_taxonomies( array( 'public' => true ), 'names' );
	if ( ! in_array( $taxonomy, $allowed, true ) ) {
		return new WP_Error( 'aafm_invalid_taxonomy', __( 'Unsupported taxonomy.', 'agent-abilities-for-mcp' ) );
	}
	return $taxonomy;
}

/**
 * Validate a list of term IDs for a single taxonomy, returning the sanitized IDs.
 *
 * Enforces, in order: the taxonomy clears the public allow-list (aafm_validate_taxonomy);
 * the current (agent) user holds that taxonomy's assign_terms cap; every ID is a positive
 * integer; every ID is an EXISTING term that belongs to THIS taxonomy (rejects nonexistent
 * and cross-taxonomy IDs). Pure — performs no writes — so the caller can validate the whole
 * enrichment payload before mutating anything.
 *
 * @param string           $taxonomy Requested taxonomy slug.
 * @param array<int,mixed> $term_ids Candidate term IDs.
 * @return list<int>|WP_Error Sanitized, validated IDs, or a generic error on any failure.
 */
function aafm_validate_term_ids_for_taxonomy( string $taxonomy, array $term_ids ) {
	$tax = aafm_validate_taxonomy( $taxonomy );
	if ( is_wp_error( $tax ) ) {
		return $tax;
	}

	$tax_object = get_taxonomy( $tax );
	if ( ! $tax_object instanceof WP_Taxonomy
		|| ! current_user_can( (string) $tax_object->cap->assign_terms ) ) {
		return aafm_generic_error();
	}

	$clean = array();
	foreach ( $term_ids as $raw ) {
		$id = absint( $raw );
		if ( $id < 1 ) {
			return aafm_generic_error();
		}
		$term = get_term( $id, $tax );
		if ( ! $term instanceof WP_Term ) {
			return aafm_generic_error();
		}
		$clean[] = $id;
	}

	return $clean;
}

/**
 * Validate a featured-media id: it must resolve to a real attachment post.
 *
 * Edit access to the PARENT post is already enforced by the calling ability's
 * permission gate (create holds edit_posts/publish_posts; update runs
 * aafm_can_edit_post_object), and set_post_thumbnail writes _thumbnail_id on that
 * parent — so the only new check here is that the id is genuinely an attachment.
 *
 * @param mixed $attachment_id Candidate attachment id.
 * @return int|WP_Error The sanitized attachment id, or a generic error.
 */
function aafm_validate_featured_attachment_id( $attachment_id ) {
	$id  = absint( $attachment_id );
	$att = $id ? get_post( $id ) : null;
	if ( ! $att instanceof WP_Post || 'attachment' !== $att->post_type ) {
		return aafm_generic_error();
	}
	return $id;
}

/**
 * Validate + pre-sanitize a meta write payload.
 *
 * Each key passes aafm_validate_meta_key() (allow-list AND hard-block floor); each value
 * passes aafm_sanitize_meta_value() (scalar-only). Returns a key => sanitized-value map
 * ready for update_post_meta(), or a generic error on the first bad key/value so the caller
 * can reject the whole write before mutating. Both the key failure and the value failure
 * collapse to the same non-distinguishing generic error (mirrors aafm_exec_update_post_meta
 * and the helpers.php meta-key doctrine: a caller cannot tell "blocked" from "not allowlisted"
 * from "non-scalar value").
 *
 * @param array<string,mixed> $meta Raw meta object from input.
 * @return array<string,mixed>|WP_Error Sanitized key=>value map, or error.
 */
function aafm_validate_meta_payload( array $meta ) {
	$clean = array();
	foreach ( $meta as $raw_key => $raw_value ) {
		$key = aafm_validate_meta_key( (string) $raw_key );
		if ( is_wp_error( $key ) ) {
			return aafm_generic_error();
		}
		$value = aafm_sanitize_meta_value( $key, $raw_value );
		if ( is_wp_error( $value ) ) {
			return aafm_generic_error();
		}
		$clean[ $key ] = $value;
	}
	return $clean;
}

/**
 * Validate the optional write-enrichment fields (terms, featured_media, meta) up front.
 *
 * Returns a normalized bundle of ALREADY-VALIDATED data ready for
 * aafm_apply_write_enrichment(), or the first WP_Error. Absent fields are normalized to
 * empty so the apply step is a no-op for them. Validating everything here — before any row
 * is mutated — is what makes the write reject bad input cleanly: a bad taxonomy/term/
 * attachment/meta key aborts the whole call rather than half-applying. ('slug' is NOT handled
 * here; it is folded into the postarr by the create/update path so it lands in the same atomic
 * row write.)
 *
 * @param array<string,mixed> $input Raw ability input.
 * @return array{terms:array<string,list<int>>,featured_media:int,meta:array<string,mixed>}|WP_Error
 */
function aafm_validate_write_enrichment( array $input ) {
	$bundle = array(
		'terms'          => array(),
		'featured_media' => 0,
		'meta'           => array(),
	);

	if ( isset( $input['terms'] ) && is_array( $input['terms'] ) ) {
		foreach ( $input['terms'] as $taxonomy => $term_ids ) {
			$ids = aafm_validate_term_ids_for_taxonomy( (string) $taxonomy, is_array( $term_ids ) ? $term_ids : array() );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$bundle['terms'][ sanitize_key( (string) $taxonomy ) ] = $ids;
		}
	}

	if ( isset( $input['featured_media'] ) ) {
		$att = aafm_validate_featured_attachment_id( $input['featured_media'] );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		$bundle['featured_media'] = $att;
	}

	if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
		$meta = aafm_validate_meta_payload( $input['meta'] );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$bundle['meta'] = $meta;
	}

	return $bundle;
}

/**
 * Apply a PRE-VALIDATED enrichment bundle to an existing post.
 *
 * This step is NOT transactional and does NOT roll back the post row. Because every value in
 * the bundle was already checked by aafm_validate_write_enrichment(), the apply cannot fail
 * ON BAD INPUT — there is no malformed taxonomy/term/attachment/meta left to reject. It can
 * still encounter a late, environmental failure (a save_post hook, or a TOCTOU race that
 * deletes a just-validated term), and when it does the post row stays written: the create/
 * update has already committed the core fields before this runs.
 *
 * DELIBERATE CHOICE — term-assignment WP_Errors are accepted, not surfaced. wp_set_post_terms()
 * can return a WP_Error (e.g. a concurrent term-insert race). The lean-write contract treats
 * the post as already saved and the enrichment as recoverable by simply re-calling the write,
 * so this function ignores that return value rather than failing the whole call after the row
 * is committed. set_post_thumbnail()/update_post_meta() likewise are not re-checked here.
 *
 * @param int                                                                              $post_id Target post id.
 * @param array{terms:array<string,list<int>>,featured_media:int,meta:array<string,mixed>} $bundle  Validated bundle.
 * @return null Always null — enrichment outcomes are not surfaced (see DELIBERATE CHOICE above).
 */
function aafm_apply_write_enrichment( int $post_id, array $bundle ) {
	foreach ( $bundle['terms'] as $taxonomy => $ids ) {
		// Replace, not append ($append=false): the documented contract is that `terms`
		// REPLACES existing terms for that taxonomy. A WP_Error return (term-insert race)
		// is intentionally not surfaced — see the DELIBERATE CHOICE note above.
		wp_set_post_terms( $post_id, $ids, $taxonomy );
	}

	if ( $bundle['featured_media'] > 0 ) {
		set_post_thumbnail( $post_id, $bundle['featured_media'] );
	}

	foreach ( $bundle['meta'] as $key => $value ) {
		update_post_meta( $post_id, $key, wp_slash( $value ) );
	}

	return null;
}

/**
 * Validate a post status against a strict allow-list.
 *
 * Blocks 'any' and prevents a non-privileged caller from widening visibility to
 * private/draft/etc.
 *
 * @param string $status           Requested status.
 * @param bool   $can_read_private Whether the caller may read non-public statuses.
 * @return string|WP_Error Sanitized status, or error if not allowed.
 */
function aafm_validate_post_status( string $status, bool $can_read_private ) {
	$status = sanitize_key( $status );

	// Public statuses come from core (covers custom public statuses), not a hardcoded list.
	$public_statuses  = array_values( get_post_stati( array( 'public' => true ) ) );
	$private_statuses = array( 'draft', 'pending', 'future', 'private' );

	if ( in_array( $status, $public_statuses, true ) ) {
		return $status;
	}
	if ( in_array( $status, $private_statuses, true ) && $can_read_private ) {
		return $status;
	}
	// 'any', 'trash', 'auto-draft', 'inherit', and unknown values are always rejected —
	// this is the no-`status=any`-widening guard.
	return new WP_Error( 'aafm_invalid_status', __( 'Unsupported or unauthorized post status.', 'agent-abilities-for-mcp' ) );
}

/**
 * Reduce a post to a safe, public-facing shape.
 *
 * @param WP_Post $post Post object.
 * @return array<string,mixed>
 */
function aafm_redact_post( WP_Post $post ): array {
	return array(
		'id'           => (int) $post->ID,
		'title'        => get_the_title( $post ),
		'status'       => $post->post_status,
		'type'         => $post->post_type,
		'slug'         => $post->post_name,
		'excerpt'      => has_excerpt( $post ) ? get_the_excerpt( $post ) : '',
		'link'         => (string) get_permalink( $post ),
		'author_id'    => (int) $post->post_author,
		'date_gmt'     => $post->post_date_gmt,
		'modified_gmt' => $post->post_modified_gmt,
	);
}

/**
 * Collect a post's terms grouped by taxonomy, restricted to public taxonomies
 * registered for the post's type. Empty taxonomies are omitted.
 *
 * @param WP_Post $post Post object.
 * @return array<string,array<int,array<string,mixed>>>
 */
function aafm_post_terms_grouped( WP_Post $post ): array {
	$grouped    = array();
	$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

	foreach ( $taxonomies as $tax_name => $tax ) {
		if ( ! ( $tax instanceof WP_Taxonomy ) || ! $tax->public ) {
			continue;
		}
		$terms = get_the_terms( $post, $tax_name );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			continue;
		}
		$grouped[ $tax_name ] = array_map(
			static function ( WP_Term $term ): array {
				return array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			},
			$terms
		);
	}

	return $grouped;
}

/**
 * JSON-schema property fragment describing the enriched rich-post output shape.
 * Shared by every read getter's output_schema so the contract stays in one place.
 * Defined here in helpers.php (loaded before posts.php/pages.php/search.php) so
 * every ability file can reference it regardless of include order.
 *
 * @return array<string,mixed>
 */
function aafm_rich_post_output_properties(): array {
	return array(
		'id'             => array( 'type' => 'integer' ),
		'title'          => array( 'type' => 'string' ),
		'status'         => array( 'type' => 'string' ),
		'type'           => array( 'type' => 'string' ),
		'slug'           => array( 'type' => 'string' ),
		'link'           => array( 'type' => 'string' ),
		'author_id'      => array( 'type' => 'integer' ),
		'author'         => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'display_name' => array( 'type' => 'string' ),
			),
		),
		'date_gmt'       => array( 'type' => 'string' ),
		'modified_gmt'   => array( 'type' => 'string' ),
		'content'        => array(
			'type'        => 'string',
			'description' => __( 'Present on single-post reads and when include_content=true; omitted for password-protected posts.', 'agent-abilities-for-mcp' ),
		),
		'excerpt'        => array( 'type' => 'string' ),
		'terms'          => array(
			'type'                 => 'object',
			'additionalProperties' => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'   => array( 'type' => 'integer' ),
						'name' => array( 'type' => 'string' ),
						'slug' => array( 'type' => 'string' ),
					),
				),
			),
		),
		'featured_image' => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'id'  => array( 'type' => 'integer' ),
				'url' => array( 'type' => 'string' ),
				'alt' => array( 'type' => 'string' ),
			),
		),
		'meta'           => array(
			'type'                 => 'object',
			'additionalProperties' => true,
		),
	);
}

/**
 * Assemble the enriched, agent-facing post shape: the lean redactor base plus
 * content, excerpt, terms, author, featured image, and allowlisted meta.
 *
 * Read access is the caller's responsibility — every getter that calls this has
 * already cleared its permission_callback. This helper only shapes data.
 *
 * SECURITY: a password-protected post never exposes its body here — no raw or
 * rendered `content`, and no body-derived excerpt (Tasks 2-3). The single-post
 * read gate does not inspect post_password, so this is the chokepoint.
 *
 * @param WP_Post             $post    Post object.
 * @param array<string,mixed> $options {
 *     Optional. Assembly options.
 *     @type string $content_format  'rendered' (default) or 'raw'.
 *     @type bool   $include_content Whether to include the heavy `content` field. Default true.
 * }
 * @return array<string,mixed>
 */
function aafm_rich_post( WP_Post $post, array $options = array() ): array {
	$shape = aafm_redact_post( $post );

	$format          = isset( $options['content_format'] ) && 'raw' === $options['content_format'] ? 'raw' : 'rendered';
	$include_content = ! array_key_exists( 'include_content', $options ) || (bool) $options['include_content'];

	// SECURITY: a password-protected post must never expose its body — not the raw
	// stored markup, not the rendered HTML, and not a body-derived excerpt. The
	// single-post read gate (aafm_can_read_post_object) admits any public-status
	// post without inspecting post_password, so this is the only place the body is
	// withheld. Mirrors the precedent in comments.php (gate on empty password).
	$is_protected = '' !== (string) $post->post_password;

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, applied so blocks/shortcodes render.
	$rendered = $is_protected ? '' : (string) apply_filters( 'the_content', $post->post_content );

	// A manual excerpt is always safe to surface. For a protected post we read the
	// stored excerpt directly: get_the_excerpt() returns core's "no excerpt because
	// this is a protected post" placeholder on a protected post, so it cannot be
	// used here. Without a manual excerpt, fall back to '' — never derive an excerpt
	// from the body ($rendered is already '' when $is_protected, so the auto-excerpt
	// branch can only run for non-protected posts).
	if ( has_excerpt( $post ) ) {
		$shape['excerpt'] = $is_protected
			? sanitize_text_field( (string) $post->post_excerpt )
			: get_the_excerpt( $post );
	} elseif ( $is_protected ) {
		$shape['excerpt'] = '';
	} else {
		$shape['excerpt'] = wp_trim_words( wp_strip_all_tags( $rendered ), 55 );
	}

	if ( $include_content && ! $is_protected ) {
		$shape['content'] = 'raw' === $format ? (string) $post->post_content : $rendered;
	}

	$shape['terms'] = aafm_post_terms_grouped( $post );

	$author          = get_userdata( (int) $post->post_author );
	$shape['author'] = $author instanceof WP_User
		? array(
			'id'           => (int) $author->ID,
			'display_name' => $author->display_name,
		)
		: null;

	$thumb_id                = get_post_thumbnail_id( $post );
	$shape['featured_image'] = $thumb_id
		? array(
			'id'  => (int) $thumb_id,
			'url' => (string) wp_get_attachment_url( $thumb_id ),
			'alt' => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
		)
		: null;

	$meta = array();
	foreach ( aafm_allowed_meta_keys() as $meta_key ) {
		$value = get_post_meta( $post->ID, $meta_key, true );
		// Skip empty strings (absent keys) and never expose non-scalar blobs.
		if ( is_scalar( $value ) && '' !== $value ) {
			$meta[ $meta_key ] = $value;
		}
	}
	$shape['meta'] = $meta;

	return $shape;
}

/**
 * Reduce a user to id, display name, roles, and post count — never PII.
 *
 * Pass $post_count to use a pre-computed count (e.g. batched via
 * count_many_users_posts() over the whole listing) and avoid a per-user COUNT(*).
 * When null, the count is resolved individually — fine for single-user shapes.
 *
 * @param WP_User|false $user       User object.
 * @param int|null      $post_count Pre-computed post count, or null to resolve here.
 * @return array<string,mixed>
 */
function aafm_redact_user( $user, ?int $post_count = null ): array {
	if ( ! $user instanceof WP_User ) {
		return array();
	}
	return array(
		'id'           => (int) $user->ID,
		'display_name' => $user->display_name,
		'roles'        => array_values( $user->roles ),
		'post_count'   => null !== $post_count ? $post_count : (int) count_user_posts( $user->ID ),
	);
}

/**
 * Reduce a comment to a safe shape — no email, no IP.
 *
 * @param WP_Comment|null $comment Comment object.
 * @return array<string,mixed>
 */
function aafm_redact_comment( $comment ): array {
	if ( ! $comment instanceof WP_Comment ) {
		return array();
	}
	return array(
		'id'          => (int) $comment->comment_ID,
		'post_id'     => (int) $comment->comment_post_ID,
		'author_name' => $comment->comment_author,
		'content'     => $comment->comment_content,
		'status'      => wp_get_comment_status( $comment ),
		'date_gmt'    => $comment->comment_date_gmt,
		'parent'      => (int) $comment->comment_parent,
	);
}

/**
 * Reduce a term to a safe, public-facing shape.
 *
 * @param WP_Term $term Term object.
 * @return array<string,mixed>
 */
function aafm_redact_term( WP_Term $term ): array {
	return array(
		'id'          => (int) $term->term_id,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'taxonomy'    => $term->taxonomy,
		'parent'      => (int) $term->parent,
		'count'       => (int) $term->count,
		'description' => $term->description,
	);
}

/**
 * Reduce an attachment to a safe inventory shape (public URL/alt/mime/dims only).
 *
 * @param WP_Post $attachment Attachment post.
 * @return array<string,mixed>
 */
function aafm_redact_media( WP_Post $attachment ): array {
	$meta = wp_get_attachment_metadata( $attachment->ID );
	return array(
		'id'        => (int) $attachment->ID,
		'title'     => get_the_title( $attachment ),
		'mime_type' => $attachment->post_mime_type,
		'url'       => (string) wp_get_attachment_url( $attachment->ID ),
		'alt'       => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
		'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : null,
	);
}

/**
 * Reduce a revision to a safe, metadata-only shape. Deliberately omits post_content,
 * excerpt, and link — the plugin never exposes raw bodies; revision diffs/content are a
 * post-launch change-history concern.
 *
 * @param WP_Post $revision Revision post.
 * @return array<string,mixed>
 */
function aafm_redact_revision( WP_Post $revision ): array {
	return array(
		'id'           => (int) $revision->ID,
		'post_id'      => (int) $revision->post_parent,
		'author_id'    => (int) $revision->post_author,
		'date_gmt'     => $revision->post_date_gmt,
		'modified_gmt' => $revision->post_modified_gmt,
		'title'        => get_the_title( $revision ),
	);
}

/**
 * Resolve a revision id, enforcing that it is a real revision of $post_id.
 *
 * @param int $revision_id Candidate revision id.
 * @param int $post_id     Expected parent post id.
 * @return WP_Post|WP_Error The revision, or a generic error if it is not a revision of $post_id.
 */
function aafm_validate_revision( int $revision_id, int $post_id ) {
	$revision = $revision_id ? wp_get_post_revision( $revision_id ) : null;
	if ( ! $revision instanceof WP_Post || (int) $revision->post_parent !== $post_id ) {
		return aafm_generic_error();
	}
	return $revision;
}

/**
 * Bound page/per_page arguments.
 *
 * @param array<string,mixed> $input Raw input.
 * @param int                 $max   Maximum allowed per_page.
 * @return array{per_page:int,page:int}
 */
function aafm_paginate_args( array $input, int $max = 50 ): array {
	$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 10;
	$page     = isset( $input['page'] ) ? (int) $input['page'] : 1;
	return array(
		'per_page' => min( $max, max( 1, $per_page ) ),
		'page'     => max( 1, $page ),
	);
}

/**
 * A single generic error returned to callers — never leaks internal detail.
 *
 * @return WP_Error
 */
function aafm_generic_error(): WP_Error {
	return new WP_Error( 'aafm_error', __( 'The request could not be completed.', 'agent-abilities-for-mcp' ) );
}

/**
 * Whether WordPress will move trashed content to the Trash instead of deleting it.
 *
 * Core's wp_trash_post()/wp_trash_comment() force a permanent, unrecoverable delete
 * when EMPTY_TRASH_DAYS is 0 or falsy. The trash abilities advertise "recoverable,
 * never permanently deleted", so they consult this before trashing and refuse when
 * the Trash is off rather than silently destroy content.
 *
 * @return bool True when the Trash is enabled (content is recoverable).
 */
function aafm_trash_is_enabled(): bool {
	$enabled = defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS;

	/**
	 * Filters whether the plugin treats the Trash as enabled.
	 *
	 * @param bool $enabled True when EMPTY_TRASH_DAYS is truthy.
	 */
	return (bool) apply_filters( 'aafm_trash_is_enabled', $enabled );
}

/**
 * The error returned when a trash ability is asked to act on a Trash-disabled site.
 *
 * @return WP_Error
 */
function aafm_trash_disabled_error(): WP_Error {
	return new WP_Error(
		'aafm_trash_disabled',
		__( 'Trash is disabled on this site, so this content cannot be moved to the Trash. Refusing to permanently delete it.', 'agent-abilities-for-mcp' )
	);
}
