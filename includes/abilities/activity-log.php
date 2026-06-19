<?php
/**
 * Read-only activity-log ability.
 *
 * Surfaces the plugin's OWN audit table (every ability execute + denial) to an agent,
 * gated on manage_options — the same bar the admin Dashboard activity panel sits behind.
 * It returns each row's id, ability name, status, acting user id + login, the argument
 * KEYS that were passed (never values — values are never logged), and the timestamp. It
 * deliberately OMITS source_ip: a network address is PII the admin panel does not show, so
 * it is never handed to an agent. Read-only: there is no write/clear ability here.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_activity_log_definitions' );

/**
 * Contribute the activity-log definition to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_activity_log_definitions( array $registry ): array {
	$registry['aafm/get-activity-log'] = array(
		'label'        => __( 'Get activity log', 'agent-abilities-for-mcp' ),
		'description'  => __( "Reads this plugin's own audit log: each row's ability name, status (started, success, error, denied), acting user id and login, the argument keys passed, and the timestamp. Most recent first. Never argument values or network addresses. Requires the manage-options capability.", 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_activity_log',
	);
	return $registry;
}

/**
 * Args for aafm/get-activity-log.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_activity_log(): array {
	return array(
		'label'               => __( 'Get activity log', 'agent-abilities-for-mcp' ),
		'description'         => __( "Reads this plugin's own audit log, most recent first. Requires manage-options. Never argument values or network addresses.", 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'status'   => array(
					'type' => 'string',
					'enum' => array( 'started', 'success', 'error', 'denied' ),
				),
				'ability'  => array( 'type' => 'string' ),
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'entries' => array( 'type' => 'array' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_activity_log',
		'permission_callback' => 'aafm_perm_manage_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/get-activity-log.
 *
 * Passes the (validated) filters straight to aafm_query_activity(), which caps per_page at
 * 200 and orders most-recent-first, then redacts each row to the safe field set — never
 * source_ip.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_get_activity_log( array $input ): array {
	$args = array();
	if ( ! empty( $input['status'] ) ) {
		$args['status'] = (string) $input['status'];
	}
	if ( ! empty( $input['ability'] ) ) {
		$args['ability'] = (string) $input['ability'];
	}
	if ( isset( $input['page'] ) ) {
		$args['page'] = (int) $input['page'];
	}
	if ( isset( $input['per_page'] ) ) {
		$args['per_page'] = (int) $input['per_page'];
	}

	$entries = array();
	foreach ( aafm_query_activity( $args ) as $row ) {
		$entries[] = array(
			'id'                => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'ability'           => isset( $row['ability'] ) ? (string) $row['ability'] : '',
			'status'            => isset( $row['status'] ) ? (string) $row['status'] : '',
			'principal_user_id' => isset( $row['principal_user_id'] ) ? (int) $row['principal_user_id'] : 0,
			'principal_login'   => isset( $row['principal_login'] ) ? (string) $row['principal_login'] : '',
			'arg_keys'          => isset( $row['arg_keys'] ) ? (string) $row['arg_keys'] : '',
			'created_at'        => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			// source_ip is deliberately NOT returned (network PII, not shown in the admin panel).
		);
	}

	return array( 'entries' => $entries );
}
