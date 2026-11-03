<?php
/**
 * A real initialize over the plugin's MCP route carries the playbook index in its instructions.
 *
 * Lives in the oauth suite because it builds the real aafm-server, which the abilities suite's
 * registration pins expect to build themselves.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class PlaybookInitializeWireTest extends TestCase {

	public function tear_down(): void {
		delete_option( 'aafm_playbooks' );
		unset( $GLOBALS['HTTP_RAW_POST_DATA'], $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_ACCEPT'] );
		parent::tear_down();
	}

	public function test_initialize_instructions_carry_the_index(): void {
		update_option(
			'aafm_playbooks',
			array(
				array(
					'slug'        => 'playbook-1',
					'title'       => 'Playbook 1',
					'description' => 'The first.',
					'body'        => 'Steps.',
					'active'      => true,
				),
			),
			false
		);
		$this->register_enabled( array( 'aafm/list-playbooks', 'aafm/get-playbook' ) );
		$this->acting_as( 'administrator' );
		$this->route_as_rest_request();
		$server                        = $this->mcp_spy_server();
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_SERVER['CONTENT_TYPE']       = 'application/json';
		$_SERVER['HTTP_ACCEPT']        = 'application/json, text/event-stream';
		$GLOBALS['HTTP_RAW_POST_DATA'] = wp_json_encode( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the body core's get_raw_data() reads.
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-06-18',
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'index-test',
						'version' => '1.0',
					),
				),
			)
		);
		// An earlier test may have built aafm-server already, and the hooks it added went with that test.
		if ( false === has_filter( 'mcp_adapter_initialize_response', 'aafm_filter_initialize_capabilities' ) ) {
			add_filter( 'mcp_adapter_initialize_response', 'aafm_filter_initialize_capabilities', 10, 3 );
		}
		$server->serve_request( aafm_mcp_rest_route() );
		$body = json_decode( (string) $server->sent_body, true );
		$this->assertSame( 200, $server->status, (string) $server->sent_body );
		$this->assertStringContainsString( 'playbook-1', (string) ( $body['result']['instructions'] ?? '' ) );
	}
}
