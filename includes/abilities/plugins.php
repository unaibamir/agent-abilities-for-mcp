<?php
/**
 * Read-only plugin inventory ability.
 *
 * The list-plugins ability is read-only by design: there is NO activate/deactivate ability in the
 * catalog. It gates on activate_plugins (the capability WordPress puts on the Plugins
 * screen) and returns, per plugin, the relative basename (e.g. akismet/akismet.php), the
 * name, the version, and whether it is active. It never returns an absolute filesystem
 * path — get_plugins() keys are already relative to the plugins directory, so no part of
 * the server's directory layout is disclosed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_plugins_definitions' );

/**
 * Contribute the plugins-list definition to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_plugins_definitions( array $registry ): array {
	$registry['aafm/list-plugins'] = array(
		'label'        => __( 'List plugins', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Lists installed plugins with their name, version, and active state. Read-only — it can never activate, deactivate, or change a plugin. Requires the activate-plugins capability.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_list_plugins',
	);
	return $registry;
}

/**
 * Permission for aafm/list-plugins: activate_plugins.
 *
 * The same capability WordPress gates the Plugins screen behind. The check is object-
 * independent, so discovery can fall through to this callback with no server.php case.
 *
 * @return bool
 */
function aafm_perm_list_plugins(): bool {
	return current_user_can( 'activate_plugins' );
}

/**
 * Args for aafm/list-plugins.
 *
 * @return array<string,mixed>
 */
function aafm_args_list_plugins(): array {
	return array(
		'label'               => __( 'List plugins', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Lists installed plugins with their name, version, and active state. Read-only. Requires the activate-plugins capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'plugins' => array( 'type' => 'array' ),
			),
		),
		'execute_callback'    => 'aafm_exec_list_plugins',
		'permission_callback' => 'aafm_perm_list_plugins',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/list-plugins.
 *
 * Returns the installed-plugin inventory. Each entry exposes the RELATIVE basename
 * (get_plugins() keys are relative to the plugins directory), the name, the version, and
 * the active state — never an absolute filesystem path.
 *
 * @return array<string,mixed>
 */
function aafm_exec_list_plugins(): array {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$out[] = array(
			'plugin'  => $file, // Relative basename (e.g. akismet/akismet.php) — never an absolute path.
			'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : '',
			'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			'active'  => is_plugin_active( $file ),
		);
	}

	return array( 'plugins' => $out );
}
