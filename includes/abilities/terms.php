<?php
/**
 * Term abilities (reads). Writes are appended in Phase 4.
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
	$registry['aafm/get-terms'] = array(
		'label'        => __( 'Get terms', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List terms (with counts) for a public taxonomy.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'args_builder' => 'aafm_args_get_terms',
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
