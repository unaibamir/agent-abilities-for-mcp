<?php
/**
 * Child process for BridgePluginNameTest: one failing bridged call over the real MCP route.
 *
 * The process-wide MCP server is built once, so the tool has to be enabled before WordPress boots,
 * and the route refuses a tool on any other server. This script boots WordPress from the test config
 * with the fixture in place and without the admin API the WP test library pulls in, the way a REST
 * request runs. Everything it writes stays in a transaction it never commits.
 *
 * @package AgentAbilitiesForMCP
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.DirectDatabaseQuery -- a test process, not plugin code.

declare( strict_types=1 );

$aafm_tests_dir = (string) ( $argv[1] ?? '' );

$_SERVER['HTTP_HOST']       = 'example.org';
$_SERVER['SERVER_NAME']     = 'example.org';
$_SERVER['REQUEST_METHOD']  = 'POST';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

require $aafm_tests_dir . '/wp-tests-config.php';
require $aafm_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		global $wpdb;

		// Everything from here on stays in a transaction that is never committed. Stamping the schema
		// versions keeps the upgraders off: they run dbDelta(), whose DDL would commit the transaction,
		// and upgrade.php loads the admin API, which a request on a settled site never does.
		$wpdb->suppress_errors( true );
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 10' );
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );

		require dirname( __DIR__, 2 ) . '/agent-abilities-for-mcp.php';

		update_option( 'aafm_oauth_schema_version', AAFM_OAUTH_SCHEMA_VERSION );
		update_option( 'aafm_activity_log_schema_version', AAFM_ACTIVITY_LOG_SCHEMA_VERSION );
		update_option( 'aafm_enabled_bridged_abilities', array( 'wpforms/list-entries' ) );
		update_option( 'active_plugins', array( 'wpforms-lite/wpforms.php' ) );

		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				wp_register_ability_category(
					'demo-things',
					array(
						'label'       => 'Demo things',
						'description' => 'Demo fixture category.',
					)
				);
			}
		);
		add_action(
			'wp_abilities_api_init',
			static function (): void {
				wp_register_ability(
					'wpforms/list-entries',
					array(
						'label'               => 'List entries',
						'description'         => 'Fixture.',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'execute_callback'    => static fn() => new WP_Error( 'wpf_boom', 'Quota exceeded.' ),
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
			},
			1
		);
	}
);

require ABSPATH . 'wp-settings.php';

wp_set_current_user( 1 );
wp_cache_set(
	'plugins',
	array(
		'' => array(
			'wpforms-lite/wpforms.php' => array(
				'Name'       => 'WPForms Lite',
				'TextDomain' => 'wpforms-lite',
			),
		),
	),
	'plugins'
);

$aafm_send = static function ( string $body, string $session = '' ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', aafm_mcp_rest_route() );
	$request->set_header( 'Content-Type', 'application/json' );
	if ( '' !== $session ) {
		$request->set_header( 'Mcp-Session-Id', $session );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	}
	$request->set_body( $body );
	return rest_do_request( $request );
};

$aafm_init    = $aafm_send( '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"name-test","version":"1"}}}' );
$aafm_session = (string) ( $aafm_init->get_headers()['Mcp-Session-Id'] ?? '' );

$aafm_call = $aafm_send(
	(string) wp_json_encode(
		array(
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => aafm_mcp_tool_name( 'aafm-bridge/wpforms-list-entries' ),
				'arguments' => (object) array(),
			),
		)
	),
	$aafm_session
);

echo "\nAAFM_CHILD_RESULT:" . wp_json_encode(
	array(
		'session' => '' !== $aafm_session,
		'wire'    => wp_json_encode( $aafm_call->get_data() ),
	)
) . "\n";

$wpdb->query( 'ROLLBACK' );
