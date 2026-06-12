<?php
/**
 * Term abilities: reads (get-terms) and guarded writes (create-term, update-term).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_terms_definitions' );

/**
 * Contribute term ability definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_terms_definitions( array $registry ): array {
	$registry['aafm/get-terms']   = array(
		'label'        => __( 'Get terms', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List terms (with counts) for a public taxonomy.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'taxonomies',
		'args_builder' => 'aafm_args_get_terms',
	);
	$registry['aafm/create-term'] = array(
		'label'        => __( 'Create term', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Create a term in a public taxonomy (requires manage_categories).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'taxonomies',
		'args_builder' => 'aafm_args_create_term',
	);
	$registry['aafm/update-term'] = array(
		'label'        => __( 'Update term', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Update a term in a public taxonomy, with a circular-hierarchy guard on reparenting.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'taxonomies',
		'args_builder' => 'aafm_args_update_term',
	);
	return $registry;
}

/**
 * Args for aafm/get-terms.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_terms(): array {
	return array(
		'label'               => __( 'Get terms', 'agent-abilities-for-mcp' ),
		'description'         => __( 'List terms (with counts) for a public taxonomy.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
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
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
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
			),
		),
		'execute_callback'    => 'aafm_exec_get_terms',
		'permission_callback' => 'aafm_perm_read',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/get-terms.
 *
 * Validates the requested taxonomy against the public allow-list (default-deny on an
 * unknown or non-public taxonomy), then returns a redacted, bounded list of terms.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_terms( array $input ) {
	$taxonomy = aafm_validate_taxonomy( isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category' );
	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}

	$paging = aafm_paginate_args( $input, 100 );

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
		return aafm_generic_error();
	}

	$objects = array_filter(
		(array) $terms,
		static fn( $term ): bool => $term instanceof WP_Term
	);

	return array( 'terms' => array_values( array_map( 'aafm_redact_term', $objects ) ) );
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
function aafm_perm_manage_terms( array $input ): bool {
	$taxonomy = aafm_validate_taxonomy( isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category' );
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
function aafm_term_write_result( WP_Term $term ): array {
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
 * Args for aafm/create-term.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_term(): array {
	return array(
		'label'               => __( 'Create term', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Create a term in a public taxonomy (requires that taxonomy\'s manage_terms capability).', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
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
		'execute_callback'    => 'aafm_exec_create_term',
		'permission_callback' => 'aafm_perm_manage_terms',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/create-term.
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
function aafm_exec_create_term( array $input ) {
	$taxonomy = aafm_validate_taxonomy( isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category' );
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
		return aafm_generic_error();
	}

	$term = get_term( (int) $result['term_id'], $taxonomy );
	if ( ! $term instanceof WP_Term ) {
		return aafm_generic_error();
	}
	return aafm_term_write_result( $term );
}

/**
 * Args for aafm/update-term.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_term(): array {
	return array(
		'label'               => __( 'Update term', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Update a term in a public taxonomy, with a circular-hierarchy guard on reparenting.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
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
		'execute_callback'    => 'aafm_exec_update_term',
		'permission_callback' => 'aafm_perm_manage_terms',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/update-term.
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
function aafm_exec_update_term( array $input ) {
	$taxonomy = aafm_validate_taxonomy( isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category' );
	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}

	$term_id = absint( $input['term_id'] );
	$term    = get_term( $term_id, $taxonomy );
	if ( ! $term instanceof WP_Term ) {
		return aafm_generic_error();
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
			return new WP_Error( 'aafm_circular_term', __( 'That parent would create a circular hierarchy.', 'agent-abilities-for-mcp' ) );
		}
		$args['parent'] = $parent;
	}

	$result = wp_update_term( $term_id, $taxonomy, $args );
	if ( is_wp_error( $result ) ) {
		return aafm_generic_error();
	}

	$updated = get_term( $term_id, $taxonomy );
	if ( ! $updated instanceof WP_Term ) {
		return aafm_generic_error();
	}
	return aafm_term_write_result( $updated );
}
