<?php
/**
 * Playbook read abilities: list the owner's Active playbooks and load one by slug.
 *
 * Both are reads. No ability writes a playbook; the owner writes them on the Playbooks tab.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_playbook_definitions' );

/**
 * Contribute the playbook ability definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_playbook_definitions( array $registry ): array {
	$registry['aafm/list-playbooks'] = array(
		'label'        => __( 'List playbooks', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List the step-by-step playbooks the site owner wrote for agents, with each one\'s slug, title and description. Call this before starting a task on this site, and load a matching playbook with get-playbook.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_playbooks',
	);
	$registry['aafm/get-playbook']   = array(
		'label'        => __( 'Get playbook', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Load one of the site owner\'s playbooks by slug: the steps to follow, the abilities it uses and which of those you cannot use on this connection.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_playbook',
	);
	return $registry;
}

/**
 * The output properties shared by a listed and a loaded playbook.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_playbook_output_properties(): array {
	return array(
		'slug'                  => array( 'type' => 'string' ),
		'title'                 => array( 'type' => 'string' ),
		'description'           => array( 'type' => 'string' ),
		'uses_abilities'        => array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		),
		'unavailable_abilities' => array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		),
	);
}

/**
 * The annotations both abilities carry.
 *
 * @return array<string,mixed>
 */
function aafm_playbook_ability_meta(): array {
	return array(
		'annotations' => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
	);
}

/**
 * Args for aafm/list-playbooks.
 *
 * @return array<string,mixed>
 */
function aafm_args_list_playbooks(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/list-playbooks' ),
		'description'         => aafm_ability_description( 'aafm/list-playbooks' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'playbooks' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => aafm_playbook_output_properties(),
					),
				),
				'count'     => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_list_playbooks',
		'permission_callback' => 'aafm_perm_read',
		'meta'                => aafm_playbook_ability_meta(),
	);
}

/**
 * Args for aafm/get-playbook.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_playbook(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-playbook' ),
		'description'         => aafm_ability_description( 'aafm/get-playbook' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'slug' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => __( 'The playbook slug from list-playbooks.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'slug' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array_merge(
				aafm_playbook_output_properties(),
				array(
					'body'        => array( 'type' => 'string' ),
					'updated_gmt' => array( 'type' => 'string' ),
				)
			),
		),
		'execute_callback'    => 'aafm_exec_get_playbook',
		'permission_callback' => 'aafm_perm_read',
		'meta'                => aafm_playbook_ability_meta(),
	);
}

/**
 * The abilities a playbook body names, as ability names, in order of first appearance.
 *
 * Matches aafm/... and aafm-bridge/... names and the MCP tool form (aafm-...). A tool form maps
 * back to its ability name over the full registry and the enabled bridged names, so a disabled
 * ability written as a tool is still recognized; an unknown tool form is kept as written.
 *
 * @param string $body Playbook body.
 * @return list<string>
 */
function aafm_playbook_named_abilities( string $body ): array {
	if ( ! preg_match_all( '#\baafm(?:-bridge)?/[a-z0-9-]+|\baafm-[a-z0-9-]+#', $body, $matches ) ) {
		return array();
	}
	$by_tool = array();
	$names   = array_merge( array_keys( aafm_get_abilities_registry_full() ), aafm_get_enabled_bridged_abilities() );
	foreach ( $names as $name ) {
		$by_tool[ aafm_mcp_tool_name( (string) $name ) ] = (string) $name;
	}
	$out = array();
	foreach ( $matches[0] as $found ) {
		$found = rtrim( $found, '-' );
		$name  = false === strpos( $found, '/' ) ? ( $by_tool[ $found ] ?? $found ) : $found;
		if ( ! in_array( $name, $out, true ) ) {
			$out[] = $name;
		}
	}
	return $out;
}

/**
 * The abilities the MCP server registered in this request, set once by aafm_register_mcp_server().
 *
 * @param list<string>|null $set Names to record; null to read.
 * @return list<string>|null Null when no server was built in this request.
 */
function aafm_server_registered_abilities( ?array $set = null ): ?array {
	global $aafm_policy_state;
	if ( null !== $set ) {
		$aafm_policy_state['server_registered_abilities'] = array_values( $set );
	}
	return $aafm_policy_state['server_registered_abilities'] ?? null;
}

/**
 * The subset of named abilities the current caller cannot use right now: not enabled, not
 * registered, left out of this request's server, or hidden from this principal.
 *
 * @param string[] $names Ability names.
 * @return list<string>
 */
function aafm_playbook_unavailable_abilities( array $names ): array {
	$on_server = aafm_server_registered_abilities();
	$enabled   = array_merge( aafm_get_enabled_abilities(), aafm_get_enabled_bridged_abilities() );
	$out       = array();
	foreach ( $names as $name ) {
		if ( ! in_array( $name, $enabled, true )
			|| ! wp_has_ability( $name )
			|| ( null !== $on_server && ! in_array( $name, $on_server, true ) )
			|| ! aafm_user_can_discover_ability( $name ) ) {
			$out[] = $name;
		}
	}
	return $out;
}

/**
 * The fields an agent sees for one playbook.
 *
 * @param array<string,mixed> $playbook Stored playbook.
 * @return array<string,mixed>
 */
function aafm_playbook_public_fields( array $playbook ): array {
	$uses = aafm_playbook_named_abilities( (string) $playbook['body'] );
	return array(
		'slug'                  => $playbook['slug'],
		'title'                 => $playbook['title'],
		'description'           => $playbook['description'],
		'uses_abilities'        => $uses,
		'unavailable_abilities' => aafm_playbook_unavailable_abilities( $uses ),
	);
}

/**
 * The refusal for a library the site could not read: never reported as an empty library.
 *
 * @return WP_Error
 */
function aafm_playbooks_unreadable_error(): WP_Error {
	return new WP_Error( 'aafm_playbooks_unreadable', __( 'The site could not read its playbooks right now. Try again shortly.', 'agent-abilities-for-mcp' ) );
}

/**
 * Execute aafm/list-playbooks: Active playbooks only, without their bodies.
 *
 * @param array<string,mixed> $input Unused.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_list_playbooks( array $input = array() ) {
	unset( $input );
	$read = aafm_playbooks_read();
	if ( ! $read['ok'] ) {
		return aafm_playbooks_unreadable_error();
	}
	$out = array();
	foreach ( $read['list'] as $playbook ) {
		if ( $playbook['active'] ) {
			$out[] = aafm_playbook_public_fields( $playbook );
		}
	}
	return array(
		'playbooks' => $out,
		'count'     => count( $out ),
	);
}

/**
 * Whether the caller can use aafm/list-playbooks, so a refusal may point at it.
 *
 * @return bool
 */
function aafm_playbook_list_tool_usable(): bool {
	return in_array( 'aafm/list-playbooks', aafm_get_enabled_abilities(), true )
		&& aafm_user_can_discover_ability( 'aafm/list-playbooks' );
}

/**
 * Execute aafm/get-playbook.
 *
 * @param array<string,mixed> $input Input with slug.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_playbook( array $input ) {
	$slug = is_string( $input['slug'] ?? null ) ? $input['slug'] : '';
	$read = aafm_playbooks_read();
	if ( ! $read['ok'] ) {
		return aafm_playbooks_unreadable_error();
	}
	$playbook = null;
	foreach ( $read['list'] as $entry ) {
		if ( $entry['slug'] === $slug ) {
			$playbook = $entry;
			break;
		}
	}
	if ( null === $playbook || ! $playbook['active'] ) {
		$error = aafm_playbook_not_found_error( $slug );
		if ( aafm_playbook_list_tool_usable() ) {
			$error = new WP_Error( 'aafm_playbook_not_found', $error->get_error_message() . ' ' . __( 'Call list-playbooks to see the playbooks on this site.', 'agent-abilities-for-mcp' ) );
		}
		return $error;
	}
	$fields = aafm_playbook_public_fields( $playbook );
	return array(
		'slug'                  => $fields['slug'],
		'title'                 => $fields['title'],
		'description'           => $fields['description'],
		'body'                  => $playbook['body'],
		'uses_abilities'        => $fields['uses_abilities'],
		'unavailable_abilities' => $fields['unavailable_abilities'],
		'updated_gmt'           => $playbook['updated_gmt'],
	);
}
