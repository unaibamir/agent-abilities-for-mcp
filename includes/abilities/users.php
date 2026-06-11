<?php
/**
 * User read ability (redacted, read-only). This is the most PII-sensitive read
 * in the catalog: enumeration is gated behind list_users (the cap WP itself
 * requires to view the user list) and the output is reduced to a safe whitelist
 * with no email, login, or password hash. No user writes exist in v1.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_users_definitions' );

/**
 * Contribute the user read definition to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_users_definitions( array $registry ): array {
	$registry['aafm/get-users'] = array(
		'label'        => __( 'Get users', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List users (id, display name, roles, post count only — no email or login).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'args_builder' => 'aafm_args_get_users',
	);
	return $registry;
}

/**
 * Args for aafm/get-users.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_users(): array {
	return array(
		'label'               => __( 'Get users', 'agent-abilities-for-mcp' ),
		'description'         => __( 'List users (id, display name, roles, post count only — no email or login).', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'role'     => array(
					'type' => 'string',
				),
				'search'   => array(
					'type' => 'string',
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'users' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_get_users',
		'permission_callback' => 'aafm_perm_list_users',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for user enumeration: list_users.
 *
 * This is the capability WordPress itself gates the user-list screen behind. A
 * caller without it (subscriber, author, etc.) is denied, and the denial is
 * audited by the registration wrapper before any user record is read.
 *
 * @return bool
 */
function aafm_perm_list_users(): bool {
	return current_user_can( 'list_users' );
}

/**
 * Execute aafm/get-users.
 *
 * Lists users redacted to id, display name, roles, and post count. Email, login,
 * password hash, registration date, IP, capabilities, and meta are never
 * returned — only the safe whitelist produced by aafm_redact_user().
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_get_users( array $input ): array {
	$paging = aafm_paginate_args( $input, 50 );
	$args   = array(
		'number' => $paging['per_page'],
		'paged'  => $paging['page'],
		'fields' => 'all',
	);

	if ( ! empty( $input['role'] ) ) {
		$args['role'] = sanitize_key( (string) $input['role'] );
	}
	if ( ! empty( $input['search'] ) ) {
		$args['search'] = '*' . sanitize_text_field( (string) $input['search'] ) . '*';
	}

	$users = get_users( $args );

	return array( 'users' => array_values( array_map( 'aafm_redact_user', $users ) ) );
}
