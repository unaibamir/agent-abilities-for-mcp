<?php
/**
 * Change history for agents: list the connection's own recent changes and undo one.
 *
 * Both tools are off by default like every ability. An agent sees only changes its own connection made in
 * the last 24 hours, never a value, and undoes one per call. The owner reverts anything else from the
 * Changes tab.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_changes_definitions' );

/**
 * Contribute the two change history tools to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_changes_definitions( array $registry ): array {
	$registry['aafm/list-changes'] = array(
		'label'        => __( 'List changes', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists the changes this connection made to one post or term in the last 24 hours, newest first: the tool, the field names touched, the time, and whether undo-change can revert it now. Only this connection\'s own changes appear, and never a value, a title or a length. A change that cannot be undone from here says why; the site owner can revert anything else from the Changes tab.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_list_changes',
	);
	$registry['aafm/undo-change']  = array(
		'label'        => __( 'Undo change', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Reverts one change this connection made in the last 24 hours, by the id list-changes gave. It puts back the earlier value of the fields that change touched, only if nothing else has edited them since, and it checks again that this connection may still make that edit. Undoing a create moves the item to the Trash. An undo cannot itself be undone from here, and notifications, webhooks and anything already read are not recalled. One change per call, 30 calls an hour. Off by default.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'content',
		'args_builder' => 'aafm_args_undo_change',
	);
	return $registry;
}

/**
 * Args for aafm/list-changes.
 *
 * @return array<string,mixed>
 */
function aafm_args_list_changes(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/list-changes' ),
		'description'         => aafm_ability_description( 'aafm/list-changes' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'object_type' => array(
					'type'        => 'string',
					'enum'        => array( 'post', 'term' ),
					'description' => __( 'Whether object_id is a post (any post type) or a term.', 'agent-abilities-for-mcp' ),
				),
				'object_id'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The id of the post or term to list this connection\'s recent changes for.', 'agent-abilities-for-mcp' ),
				),
				'per_page'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 20,
					'description' => __( 'Changes per page, 1 to 20. Defaults to 20.', 'agent-abilities-for-mcp' ),
				),
				'page'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => AAFM_LIST_PAGE_MAX,
					'description' => __( '1-based page number. Defaults to 1.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'object_type', 'object_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'changes'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'                    => array( 'type' => 'integer' ),
							'ability'               => array( 'type' => 'string' ),
							'object_type'           => array( 'type' => 'string' ),
							'object_id'             => array( 'type' => 'integer' ),
							'fields'                => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'created_gmt'           => array( 'type' => 'string' ),
							'source'                => array( 'type' => 'string' ),
							'undoable'              => array( 'type' => 'boolean' ),
							'reason'                => array( 'type' => 'string' ),
							'reverts_change_id'     => array( 'type' => 'integer' ),
							'reverted_by_change_id' => array( 'type' => 'integer' ),
						),
					),
				),
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
				'has_more' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_list_changes',
		'permission_callback' => 'aafm_perm_changes_tool',
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
 * Args for aafm/undo-change.
 *
 * @return array<string,mixed>
 */
function aafm_args_undo_change(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/undo-change' ),
		'description'         => aafm_ability_description( 'aafm/undo-change' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'change_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The id of one of this connection\'s own changes from the last 24 hours, as list-changes returned it.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'change_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'change_id'     => array( 'type' => 'integer' ),
				'outcome'       => array(
					'type' => 'string',
					'enum' => array( 'reverted', 'reverted_with_differences' ),
				),
				'new_change_id' => array( 'type' => 'integer' ),
				'fields'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'differences'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'note'          => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_undo_change',
		'permission_callback' => 'aafm_perm_changes_tool',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
		),
	);
}

/**
 * Permission for both tools. Every real check runs in execute, so "not found" and "not yours" are one answer.
 *
 * The floor is `read` on purpose, so tools/list shows both tools to any signed-in user, a subscriber
 * included. They can use neither: a connection only ever sees its own changes, a subscriber's connection
 * has none, and undo-change checks again that the connection may still make the edit. Hiding the tools
 * by capability would need a guess at which write tools a role holds, and a wrong guess hides the undo
 * from a custom role that can write.
 *
 * @return bool
 */
function aafm_perm_changes_tool(): bool {
	return current_user_can( 'read' );
}

/**
 * Whether this connection may call undo-change at all, the way discovery decides.
 *
 * @return bool
 */
function aafm_changes_undo_reachable(): bool {
	return aafm_user_can_call_ability( 'aafm/undo-change' )
		&& aafm_is_ability_enabled( 'aafm/undo-change' )
		&& aafm_ability_allowed_for_principal( 'aafm/undo-change', get_current_user_id(), aafm_oauth_current_client_id() );
}

/**
 * Execute aafm/list-changes.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_list_changes( array $input ) {
	$type      = 'term' === ( $input['object_type'] ?? '' ) ? 'term' : 'post';
	$object_id = absint( $input['object_id'] ?? 0 );
	$per_page  = min( 20, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
	$page      = min( AAFM_LIST_PAGE_MAX, max( 1, (int) ( $input['page'] ?? 1 ) ) );
	$empty     = array(
		'changes'  => array(),
		'page'     => $page,
		'per_page' => $per_page,
		'has_more' => false,
	);

	$connection = aafm_undo_connection_id();
	if ( '' === $connection || $object_id < 1 ) {
		return $empty;
	}
	$listing = aafm_undo_list_for_connection( get_current_user_id(), $connection, $type, $object_id, $page, $per_page );
	if ( ! $listing['ok'] ) {
		return aafm_generic_error();
	}
	$reachable = aafm_changes_undo_reachable();
	$changes   = array();
	foreach ( array_slice( $listing['rows'], 0, $per_page ) as $row ) {
		$state = $reachable ? aafm_undo_row_state( $row, 'agent' ) : array(
			'undoable' => false,
			'reason'   => 'needs_owner',
		);
		$entry = array(
			'id'                    => (int) $row['id'],
			'ability'               => (string) $row['ability'],
			'object_type'           => (string) $row['object_type'],
			'object_id'             => (int) $row['object_id'],
			'fields'                => aafm_undo_field_names( $row ),
			'created_gmt'           => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( (string) $row['created_at'] . ' UTC' ) ),
			'source'                => (string) $row['source'],
			'undoable'              => ! empty( $state['undoable'] ),
			'reverts_change_id'     => (int) $row['reverts_change_id'],
			'reverted_by_change_id' => (int) $row['reverted_by_change_id'],
		);
		if ( empty( $state['undoable'] ) ) {
			$entry['reason'] = (string) ( $state['reason'] ?? 'not_allowed' );
		}
		$changes[] = $entry;
	}
	return array(
		'changes'  => $changes,
		'page'     => $page,
		'per_page' => $per_page,
		'has_more' => count( $listing['rows'] ) > $per_page,
	);
}

/**
 * Whether this connection has used up its hourly undo-change budget, counting the call now running.
 *
 * An OAuth client is counted from the activity log's own rows, which carry its client id. An application
 * password has no client id in the log, so counting there put every password of one user in a single
 * budget; it gets a counter of its own, keyed on the same connection identity the own-connection rule uses.
 * The counter is an hour-bucket transient, the same fixed-window shape as the per-minute limit: a burst
 * across the hour boundary can reach twice the limit. A counter that cannot be read counts as over.
 *
 * @return bool
 */
function aafm_undo_over_limit(): bool {
	$connection = aafm_undo_connection_id();
	if ( 0 === strpos( $connection, 'app:' ) ) {
		$key   = 'aafm_undo_rl_' . get_current_user_id() . '_' . substr( md5( $connection ), 0, 12 ) . '_' . gmdate( 'YmdH' );
		$count = aafm_transient_count( $key );
		if ( null === $count ) {
			return true;
		}
		set_transient( $key, $count + 1, 2 * HOUR_IN_SECONDS );
		return $count + 1 > AAFM_UNDO_AGENT_LIMIT;
	}
	global $wpdb;
	$count = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ability = %s AND principal_user_id = %d AND client_id = %s AND created_at >= %s', aafm_activity_log_table(), 'aafm/undo-change', get_current_user_id(), aafm_oauth_current_client_id(), aafm_undo_cutoff( HOUR_IN_SECONDS ) ) );
	return ! $count['ok'] || (int) $count['value'] > AAFM_UNDO_AGENT_LIMIT;
}

/**
 * Execute aafm/undo-change.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_undo_change( array $input ) {
	// The decorator opens one activity row per call. Without it nothing is written.
	if ( aafm_undo_current_row_id() <= 0 ) {
		return aafm_undo_refusal( 'not_logged' );
	}

	// Counted before the id is resolved, so a missing, foreign and own id are counted alike and still answer
	// identically. The call's own opening row is included.
	if ( aafm_undo_over_limit() ) {
		return aafm_undo_refusal( 'rate_limited' );
	}

	$change_id = absint( $input['change_id'] ?? 0 );
	$result    = aafm_undo_apply( $change_id, 'agent' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$response = array(
		'change_id'     => (int) $result['change_id'],
		'outcome'       => (string) $result['outcome'],
		'new_change_id' => (int) $result['new_change_id'],
		'fields'        => array_map(
			static fn( string $field ): string => 0 === strpos( $field, 'post:' ) || 0 === strpos( $field, 'term:' ) || 0 === strpos( $field, 'aioseo:' ) ? (string) substr( $field, (int) strpos( $field, ':' ) + 1 ) : $field,
			array_map( 'strval', (array) $result['fields'] )
		),
	);
	if ( isset( $result['differences'] ) ) {
		$response['differences'] = array_map( 'strval', (array) $result['differences'] );
	}
	$response['note'] = __( 'Notifications, webhooks and anything already read are not recalled.', 'agent-abilities-for-mcp' );
	$response['undo'] = array(
		'undoable'   => false,
		'change_ids' => array( (int) $result['new_change_id'] ),
		'reason'     => 'needs_owner',
	);
	return $response;
}
