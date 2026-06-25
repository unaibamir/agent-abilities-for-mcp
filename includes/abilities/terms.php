<?php
/**
 * Term abilities: reads (get-terms) and guarded writes (create-term, update-term).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'oversio_abilities_registry', 'oversio_register_terms_definitions' );

/**
 * Contribute term ability definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_terms_definitions( array $registry ): array {
	$registry['oversio/get-terms']        = array(
		'label'        => __( 'Get terms', 'oversio-agent-abilities' ),
		'description'  => __( 'List terms (with counts) for a public taxonomy. Response includes total (the full match count for the taxonomy and search).', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'taxonomies',
		'args_builder' => 'oversio_args_get_terms',
	);
	$registry['oversio/create-term']      = array(
		'label'        => __( 'Create term', 'oversio-agent-abilities' ),
		'description'  => __( 'Create a term in a public taxonomy (requires manage_categories).', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'taxonomies',
		'args_builder' => 'oversio_args_create_term',
	);
	$registry['oversio/update-term']      = array(
		'label'        => __( 'Update term', 'oversio-agent-abilities' ),
		'description'  => __( 'Update a term in a public taxonomy, with a circular-hierarchy guard on reparenting.', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'taxonomies',
		'args_builder' => 'oversio_args_update_term',
	);
	$registry['oversio/get-term']         = array(
		'label'        => __( 'Get term', 'oversio-agent-abilities' ),
		'description'  => __( 'Read a single term (by id) from a public taxonomy.', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'taxonomies',
		'args_builder' => 'oversio_args_get_term',
	);
	$registry['oversio/add-post-terms']   = array(
		'label'        => __( 'Add post terms', 'oversio-agent-abilities' ),
		'description'  => __( 'Append terms to a post (does not replace existing terms). Requires edit access to the post and the taxonomy\'s assign_terms capability.', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'content',
		'args_builder' => 'oversio_args_add_post_terms',
	);
	$registry['oversio/get-term-meta']    = array(
		'label'        => __( 'Get term meta', 'oversio-agent-abilities' ),
		'description'  => __( 'Read a single allowlisted scalar meta value from a term in a public taxonomy.', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'taxonomies',
		'args_builder' => 'oversio_args_get_term_meta',
	);
	$registry['oversio/update-term-meta'] = array(
		'label'        => __( 'Update term meta', 'oversio-agent-abilities' ),
		'description'  => __( 'Write a single allowlisted scalar meta value to a term you can edit.', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'taxonomies',
		'args_builder' => 'oversio_args_update_term_meta',
	);
	$registry['oversio/delete-term-meta'] = array(
		'label'        => __( 'Delete term meta', 'oversio-agent-abilities' ),
		'description'  => __( 'Delete an allowlisted meta key from a term you can edit. Removes all values of that key.', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'taxonomies',
		'args_builder' => 'oversio_args_delete_term_meta',
	);
	return $registry;
}

/**
 * Args for oversio/get-terms.
 *
 * @return array<string,mixed>
 */
function oversio_args_get_terms(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/get-terms' ),
		'description'         => oversio_ability_description( 'oversio/get-terms' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array(
					'type'    => 'string',
					'default' => 'category',
				),
				'search'   => array( 'type' => 'string' ),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => OVERSIO_LIST_PAGE_MAX,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => OVERSIO_TERMS_PER_PAGE_MAX,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'terms' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'oversio_exec_get_terms',
		'permission_callback' => 'oversio_perm_read',
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
 * Execute oversio/get-terms.
 *
 * Validates the requested taxonomy against the public allow-list (default-deny on an
 * unknown or non-public taxonomy), then returns a redacted, bounded list of terms.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_get_terms( array $input ) {
	$taxonomy = oversio_validate_taxonomy( isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category' );
	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}

	$paging = oversio_paginate_args( $input, OVERSIO_TERMS_PER_PAGE_MAX );

	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'search'     => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
			'number'     => $paging['per_page'],
			'offset'     => ( $paging['page'] - 1 ) * $paging['per_page'],
		)
	);
	if ( is_wp_error( $terms ) ) {
		return oversio_generic_error();
	}

	$objects = array_filter(
		(array) $terms,
		static fn( $term ): bool => $term instanceof WP_Term
	);

	// total is the full match count for the same taxonomy + search filter (not paged), so an
	// agent can page through the whole set. wp_count_terms() honors hide_empty/search the same
	// way the listing query does.
	$total = wp_count_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'search'     => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
		)
	);

	return array(
		'terms' => array_values( array_map( 'oversio_redact_term', $objects ) ),
		'total' => is_wp_error( $total ) ? count( $objects ) : (int) $total,
	);
}

/**
 * Args for oversio/get-term.
 *
 * @return array<string,mixed>
 */
function oversio_args_get_term(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/get-term' ),
		'description'         => oversio_ability_description( 'oversio/get-term' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array(
					'type'    => 'string',
					'default' => 'category',
				),
				'term_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'term_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'term' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'oversio_exec_get_term',
		'permission_callback' => 'oversio_perm_read',
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
 * Execute oversio/get-term.
 *
 * Confines the read to the public-allowlisted taxonomy AND to a term that actually
 * belongs to it: get_term( $id, $taxonomy ) returns null for a nonexistent id or one
 * in a different taxonomy, so a tag id claimed as a category is rejected. Returns the
 * same redacted shape as get-terms; nothing beyond oversio_redact_term() is exposed.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_get_term( array $input ) {
	$taxonomy = oversio_validate_taxonomy( isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category' );
	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}

	$term = get_term( absint( $input['term_id'] ), $taxonomy );
	if ( ! $term instanceof WP_Term ) {
		return oversio_generic_error();
	}

	return array( 'term' => oversio_redact_term( $term ) );
}

/**
 * Args for oversio/add-post-terms.
 *
 * @return array<string,mixed>
 */
function oversio_args_add_post_terms(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/add-post-terms' ),
		'description'         => oversio_ability_description( 'oversio/add-post-terms' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'taxonomy' => array(
					'type'    => 'string',
					'default' => 'category',
				),
				'term_ids' => array(
					'type'     => 'array',
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'minItems' => 1,
				),
			),
			'required'             => array( 'post_id', 'taxonomy', 'term_ids' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'terms'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			),
		),
		'execute_callback'    => 'oversio_exec_add_post_terms',
		'permission_callback' => 'oversio_perm_add_post_terms',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for oversio/add-post-terms: per-object edit_post on the target post.
 *
 * The taxonomy's assign_terms cap + term-existence are enforced at execute time by the
 * reused oversio_validate_term_ids_for_taxonomy() (C2). This callback is the post-edit gate:
 * a caller who cannot edit the post is denied before any term is touched, so an APPEND
 * can never attach terms to a post the agent is not authorized to edit.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function oversio_perm_add_post_terms( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? get_post( $id ) : null;
	return $post instanceof WP_Post && oversio_can_edit_post_object( $post );
}

/**
 * Execute oversio/add-post-terms.
 *
 * APPEND semantics (distinct from the REPLACE-on-update path in the post writes): the
 * fourth arg to wp_set_post_terms() is true, so existing terms are preserved. Term ids are
 * validated through the SAME reusable validator the enrichment path uses — taxonomy public
 * allow-list + assign_terms cap + term-exists-in-this-taxonomy — so a cross-taxonomy or
 * nonexistent id, or a missing assign_terms cap, is rejected before the write.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_add_post_terms( array $input ) {
	$post_id = absint( $input['post_id'] );
	$post    = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return oversio_generic_error();
	}

	$taxonomy = oversio_validate_taxonomy( isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category' );
	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}

	$term_ids = oversio_validate_term_ids_for_taxonomy(
		$taxonomy,
		isset( $input['term_ids'] ) && is_array( $input['term_ids'] ) ? $input['term_ids'] : array()
	);
	if ( is_wp_error( $term_ids ) ) {
		return $term_ids;
	}

	$result = wp_set_post_terms( $post_id, $term_ids, $taxonomy, true );
	if ( is_wp_error( $result ) || false === $result ) {
		return oversio_generic_error();
	}

	$current = get_the_terms( $post_id, $taxonomy );
	$objects = is_array( $current ) ? array_filter( $current, static fn( $t ): bool => $t instanceof WP_Term ) : array();

	return array(
		'post_id' => $post_id,
		'terms'   => array_values( array_map( 'oversio_redact_term', $objects ) ),
	);
}

/**
 * Per-object term-edit gate shared by get/update/delete term-meta: the term must be readable
 * + key-allowlisted (oversio_validate_term_meta_request) AND the current user must hold
 * edit_term on that specific term. Term meta can hold private data, so even the read gates on
 * edit_term here — mirroring how the post-meta family gates get/update/delete on edit_post.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function oversio_perm_can_edit_term_meta( array $input ): bool {
	if ( is_wp_error( oversio_validate_term_meta_request( $input ) ) ) {
		return false;
	}
	$term_id = absint( $input['term_id'] );
	return current_user_can( 'edit_term', $term_id );
}

/**
 * Args for oversio/get-term-meta.
 *
 * @return array<string,mixed>
 */
function oversio_args_get_term_meta(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/get-term-meta' ),
		'description'         => oversio_ability_description( 'oversio/get-term-meta' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array(
					'type'    => 'string',
					'default' => 'category',
				),
				'term_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'meta_key' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- schema property key, not a meta query.
					'type'      => 'string',
					'minLength' => 1,
				),
			),
			'required'             => array( 'term_id', 'meta_key' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'term_id'  => array( 'type' => 'integer' ),
				'meta_key' => array( 'type' => 'string' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- schema property key, not a meta query.
				'value'    => array(
					'type' => array( 'string', 'number', 'boolean', 'null' ),
				),
			),
		),
		'execute_callback'    => 'oversio_exec_get_term_meta',
		'permission_callback' => 'oversio_perm_get_term_meta',
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
 * Permission for oversio/get-term-meta: per-object edit_term + key allowlist (EDIT 2).
 *
 * Term meta can hold private data, so reads require edit_term on the term, mirroring
 * get-post-meta's edit_post gate. Reuses the shared per-object gate.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function oversio_perm_get_term_meta( array $input ): bool {
	return oversio_perm_can_edit_term_meta( $input );
}

/**
 * Execute oversio/get-term-meta.
 *
 * Re-validates taxonomy/term/key (defence in depth), then reads a single value. Non-scalar
 * values are refused so a serialized array/object can never be dumped to the agent.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_get_term_meta( array $input ) {
	$taxonomy = oversio_validate_term_meta_request( $input );
	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}
	$term_id = absint( $input['term_id'] );
	$key     = (string) $input['meta_key'];
	$value   = get_term_meta( $term_id, $key, true );
	if ( '' !== $value && ! is_scalar( $value ) ) {
		return oversio_generic_error();
	}
	return array(
		'term_id'  => $term_id,
		'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response array key, not a meta query.
		'value'    => $value,
	);
}

/**
 * Args for oversio/update-term-meta.
 *
 * @return array<string,mixed>
 */
function oversio_args_update_term_meta(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/update-term-meta' ),
		'description'         => oversio_ability_description( 'oversio/update-term-meta' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array(
					'type'    => 'string',
					'default' => 'category',
				),
				'term_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'meta_key' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- schema property key, not a meta query.
					'type'      => 'string',
					'minLength' => 1,
				),
				'value'    => array(
					'type' => array( 'string', 'number', 'boolean', 'integer' ),
				),
			),
			'required'             => array( 'term_id', 'meta_key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'term_id'  => array( 'type' => 'integer' ),
				'meta_key' => array( 'type' => 'string' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- schema property key, not a meta query.
				'value'    => array(
					'type' => array( 'string', 'number', 'boolean', 'integer' ),
				),
			),
		),
		'execute_callback'    => 'oversio_exec_update_term_meta',
		'permission_callback' => 'oversio_perm_update_term_meta',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for oversio/update-term-meta: per-object edit_term + key allowlist.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function oversio_perm_update_term_meta( array $input ): bool {
	return oversio_perm_can_edit_term_meta( $input );
}

/**
 * Execute oversio/update-term-meta.
 *
 * Re-validates taxonomy/term/key, refuses non-scalar values, then writes a single value.
 * wp_slash() guards update_term_meta()'s internal unslash (matches the post-meta convention).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_update_term_meta( array $input ) {
	$taxonomy = oversio_validate_term_meta_request( $input );
	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}
	$term_id = absint( $input['term_id'] );
	$key     = (string) $input['meta_key'];
	$value   = oversio_sanitize_term_meta_value( $key, $input['value'] ?? '' );
	if ( is_wp_error( $value ) ) {
		return $value;
	}
	if ( false === update_term_meta( $term_id, $key, wp_slash( $value ) ) ) {
		// update_term_meta returns false on a same-value no-op too. Meta round-trips through a
		// longtext column, so the stored value reads back as a string; compare stringified forms
		// to avoid a false failure on a genuine no-op (e.g. re-sending an int or bool).
		if ( (string) get_term_meta( $term_id, $key, true ) !== (string) $value ) {
			return oversio_generic_error();
		}
	}
	return array(
		'term_id'  => $term_id,
		'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response array key, not a meta query.
		'value'    => get_term_meta( $term_id, $key, true ),
	);
}

/**
 * Args for oversio/delete-term-meta.
 *
 * @return array<string,mixed>
 */
function oversio_args_delete_term_meta(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/delete-term-meta' ),
		'description'         => oversio_ability_description( 'oversio/delete-term-meta' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array(
					'type'    => 'string',
					'default' => 'category',
				),
				'term_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'meta_key' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- schema property key, not a meta query.
					'type'      => 'string',
					'minLength' => 1,
				),
			),
			'required'             => array( 'term_id', 'meta_key' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'oversio_exec_delete_term_meta',
		'permission_callback' => 'oversio_perm_delete_term_meta',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for oversio/delete-term-meta: per-object edit_term + key allowlist.
 *
 * @param array<string,mixed> $input Ability input.
 * @return bool
 */
function oversio_perm_delete_term_meta( array $input ): bool {
	return oversio_perm_can_edit_term_meta( $input );
}

/**
 * Execute oversio/delete-term-meta.
 *
 * Re-validates taxonomy/term/key (defence in depth), then removes every value of that key.
 * delete_term_meta() with no value arg deletes all values of the key — the intended
 * destructive behaviour.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_delete_term_meta( array $input ) {
	$taxonomy = oversio_validate_term_meta_request( $input );
	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}
	delete_term_meta( absint( $input['term_id'] ), (string) $input['meta_key'] );
	return array( 'deleted' => true );
}

/**
 * Permission for term writes: the target taxonomy's own manage_terms cap.
 *
 * WordPress maps each taxonomy to its own primitive (category -> manage_categories,
 * post_tag -> manage_post_tags, and likewise for custom public taxonomies), and
 * wp_insert_term / wp_update_term do no internal capability check. So the gate has
 * to resolve the taxonomy named in the input and check its real manage_terms cap —
 * a hardcoded manage_categories would let someone who can only manage categories
 * write tags on a config that decouples those caps. The taxonomy is validated
 * against the public allow-list first; an unknown/internal one is denied outright.
 * A low-privilege caller is denied and the denial is audited by the wrapper.
 *
 * @param array<string,mixed> $input Ability input (taxonomy defaults to category).
 * @return bool
 */
function oversio_perm_manage_terms( array $input ): bool {
	$taxonomy = oversio_validate_taxonomy( isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category' );
	if ( is_wp_error( $taxonomy ) ) {
		return false;
	}
	$tax_object = get_taxonomy( $taxonomy );
	if ( ! $tax_object instanceof WP_Taxonomy ) {
		return false;
	}
	return current_user_can( $tax_object->cap->manage_terms );
}

/**
 * Build a redacted descriptor for a written term.
 *
 * @param WP_Term $term Term object.
 * @return array<string,mixed>
 */
function oversio_term_write_result( WP_Term $term ): array {
	return array(
		'term' => array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => (int) $term->parent,
		),
	);
}

/**
 * Args for oversio/create-term.
 *
 * @return array<string,mixed>
 */
function oversio_args_create_term(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/create-term' ),
		'description'         => oversio_ability_description( 'oversio/create-term' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'    => array(
					'type'    => 'string',
					'default' => 'category',
				),
				'name'        => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'description' => array( 'type' => 'string' ),
				'parent'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'term' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'oversio_exec_create_term',
		'permission_callback' => 'oversio_perm_manage_terms',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute oversio/create-term.
 *
 * Default-deny: the taxonomy is validated against the public allow-list, so an
 * unknown or internal taxonomy (nav_menu, link_category, etc.) is rejected before
 * any write. The name is sanitized with sanitize_text_field and the description
 * with wp_kses_post, so script can never be stored. The closed input schema rejects
 * any undeclared field before this runs.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_create_term( array $input ) {
	$taxonomy = oversio_validate_taxonomy( isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category' );
	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}

	$result = wp_insert_term(
		sanitize_text_field( (string) $input['name'] ),
		$taxonomy,
		array(
			'description' => isset( $input['description'] ) ? wp_kses_post( (string) $input['description'] ) : '',
			'parent'      => isset( $input['parent'] ) ? absint( $input['parent'] ) : 0,
		)
	);
	if ( is_wp_error( $result ) ) {
		return oversio_generic_error();
	}

	$term = get_term( (int) $result['term_id'], $taxonomy );
	if ( ! $term instanceof WP_Term ) {
		return oversio_generic_error();
	}
	return oversio_term_write_result( $term );
}

/**
 * Args for oversio/update-term.
 *
 * @return array<string,mixed>
 */
function oversio_args_update_term(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/update-term' ),
		'description'         => oversio_ability_description( 'oversio/update-term' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'    => array(
					'type'    => 'string',
					'default' => 'category',
				),
				'term_id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'        => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'description' => array( 'type' => 'string' ),
				'parent'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			'required'             => array( 'term_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'term' => array( 'type' => 'object' ) ),
		),
		'execute_callback'    => 'oversio_exec_update_term',
		'permission_callback' => 'oversio_perm_manage_terms',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute oversio/update-term.
 *
 * Confines the write to the allow-listed taxonomy AND to a term that actually
 * belongs to it: get_term( $id, $taxonomy ) returns null when the term is in a
 * different taxonomy, so a tag ID claimed as a category is rejected (the term/
 * taxonomy-confusion guard). Reparenting is guarded against cycles with
 * term_is_ancestor_of before the update runs.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_update_term( array $input ) {
	$taxonomy = oversio_validate_taxonomy( isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category' );
	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}

	$term_id = absint( $input['term_id'] );
	$term    = get_term( $term_id, $taxonomy );
	if ( ! $term instanceof WP_Term ) {
		return oversio_generic_error();
	}

	$args = array();
	if ( isset( $input['name'] ) ) {
		$args['name'] = sanitize_text_field( (string) $input['name'] );
	}
	if ( isset( $input['description'] ) ) {
		$args['description'] = wp_kses_post( (string) $input['description'] );
	}
	if ( isset( $input['parent'] ) ) {
		$parent = absint( $input['parent'] );
		// Circular-hierarchy guard: the requested parent must not be a descendant
		// of the term being edited (which would make the term its own ancestor).
		if ( $parent && term_is_ancestor_of( $term_id, $parent, $taxonomy ) ) {
			return new WP_Error( 'oversio_circular_term', __( 'That parent would create a circular hierarchy.', 'oversio-agent-abilities' ) );
		}
		$args['parent'] = $parent;
	}

	$result = wp_update_term( $term_id, $taxonomy, $args );
	if ( is_wp_error( $result ) ) {
		return oversio_generic_error();
	}

	$updated = get_term( $term_id, $taxonomy );
	if ( ! $updated instanceof WP_Term ) {
		return oversio_generic_error();
	}
	return oversio_term_write_result( $updated );
}
