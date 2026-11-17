<?php
/**
 * Tool set is built only for requests that need it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ServerToolsRequestGateTest extends TestCase {

	/**
	 * Saved globals, restored in tear_down.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	public function set_up(): void {
		parent::set_up();
		global $wp_actions, $wp, $current_screen;
		$this->saved = array(
			'parse_request' => $wp_actions['parse_request'] ?? null,
			'rest_route'    => $wp->query_vars['rest_route'] ?? null,
			'screen'        => $current_screen ?? null,
		);
		unset( $wp_actions['parse_request'], $wp->query_vars['rest_route'] );
	}

	public function tear_down(): void {
		global $wp_actions, $wp, $current_screen;
		unset( $wp_actions['parse_request'], $wp->query_vars['rest_route'] );
		if ( null !== $this->saved['parse_request'] ) {
			$wp_actions['parse_request'] = $this->saved['parse_request'];
		}
		if ( null !== $this->saved['rest_route'] ) {
			$wp->query_vars['rest_route'] = $this->saved['rest_route'];
		}
		$current_screen = $this->saved['screen'];
		parent::tear_down();
	}

	/**
	 * Pretend core parsed the request and routed it to $route ('' means not a REST request).
	 *
	 * @param string $route The parsed rest_route.
	 */
	private function parsed_as( string $route ): void {
		global $wp_actions, $wp;
		$wp_actions['parse_request'] = 1;
		if ( '' === $route ) {
			unset( $wp->query_vars['rest_route'] );
		} else {
			$wp->query_vars['rest_route'] = $route;
		}
	}

	/**
	 * A recording stand-in for the adapter, as DefaultServerGuardTest uses.
	 */
	private function recorder(): object {
		return new class() {
			/**
			 * Arguments of the last create_server() call.
			 *
			 * @var array<int,mixed>
			 */
			public $args = array();

			public function get_server( string $id ) {
				unset( $id );
				return null;
			}

			public function create_server( ...$args ) {
				$this->args = $args;
				return null;
			}
		};
	}

	public function test_tools_are_always_built_on_a_runtime_that_may_outlive_the_request(): void {
		$this->parsed_as( '/wp/v2/posts' );
		$this->assertTrue( aafm_request_needs_server_tools(), 'PHPUnit runs under cli, like WP-CLI.' );
		$this->assertTrue( aafm_request_needs_server_tools( 'cli' ) );
		$this->assertTrue( aafm_request_needs_server_tools( 'frankenphp' ) );
		$this->assertTrue( aafm_request_needs_server_tools( 'some-future-runtime' ) );
	}

	public function test_tools_are_built_when_core_has_not_parsed_the_request(): void {
		$this->assertTrue( aafm_request_needs_server_tools( 'fpm-fcgi' ) );
	}

	public function test_tools_are_built_for_the_mcp_route(): void {
		foreach ( array( 'fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed', 'cli-server' ) as $sapi ) {
			$this->parsed_as( aafm_mcp_rest_route() );
			$this->assertTrue( aafm_request_needs_server_tools( $sapi ), $sapi );
			$this->parsed_as( aafm_mcp_rest_route() . '/' );
			$this->assertTrue( aafm_request_needs_server_tools( $sapi ), $sapi . ': a trailing slash is the same route.' );
		}

		// A plugin that sets an admin screen during a REST request must not hide the route.
		$this->parsed_as( aafm_mcp_rest_route() );
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		$this->assertTrue( aafm_request_needs_server_tools( 'fpm-fcgi' ) );
	}

	public function test_tools_are_skipped_for_another_rest_route(): void {
		foreach ( array( 'fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed', 'cli-server' ) as $sapi ) {
			$this->parsed_as( '/wp/v2/posts' );
			$this->assertFalse( aafm_request_needs_server_tools( $sapi ), $sapi );
		}
		$this->parsed_as( '/' . AAFM_MCP_NAMESPACE . '/oauth/token' );
		$this->assertFalse( aafm_request_needs_server_tools( 'fpm-fcgi' ), 'Another route in our own namespace is not the MCP route.' );
		$this->parsed_as( '/' );
		$this->assertFalse( aafm_request_needs_server_tools( 'fpm-fcgi' ), 'The REST index is not the MCP route.' );
	}

	public function test_tools_are_skipped_for_a_parsed_request_that_is_not_rest(): void {
		$this->parsed_as( '' );
		$this->assertFalse( aafm_request_needs_server_tools( 'fpm-fcgi' ) );
	}

	public function test_tools_are_skipped_in_wp_admin_and_cron(): void {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		$this->assertFalse( aafm_request_needs_server_tools( 'fpm-fcgi' ) );
		$GLOBALS['current_screen'] = $this->saved['screen'];
		$this->assertFalse( is_admin() );

		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( aafm_request_needs_server_tools( 'fpm-fcgi' ) );
		remove_filter( 'wp_doing_cron', '__return_true' );
	}

	public function test_the_filter_can_force_tools_on_and_off(): void {
		$this->parsed_as( '/wp/v2/posts' );
		add_filter( 'aafm_build_server_tools_for_request', '__return_true' );
		$this->assertTrue( aafm_request_needs_server_tools( 'fpm-fcgi' ) );
		remove_filter( 'aafm_build_server_tools_for_request', '__return_true' );

		$this->parsed_as( aafm_mcp_rest_route() );
		add_filter( 'aafm_build_server_tools_for_request', '__return_false' );
		$this->assertFalse( aafm_request_needs_server_tools( 'fpm-fcgi' ) );
		remove_filter( 'aafm_build_server_tools_for_request', '__return_false' );
	}

	public function test_a_skipped_request_still_creates_the_server_with_no_tools(): void {
		global $aafm_policy_state;
		unset( $aafm_policy_state['server_registered_abilities'] );
		$this->register_enabled( array( 'aafm/get-post' ) );
		$recorder = $this->recorder();

		// The suite runs under cli, where tools are always built, so the skip is driven through the filter.
		add_filter( 'aafm_build_server_tools_for_request', '__return_false' );
		aafm_register_mcp_server( $recorder );
		remove_filter( 'aafm_build_server_tools_for_request', '__return_false' );

		$this->assertSame( 'aafm-server', $recorder->args[0] ?? null, 'The server is still created, so the route still registers.' );
		$this->assertSame( array(), $recorder->args[9] ?? null, 'No tools on a request that does not need them.' );
		$this->assertSame( 'aafm_transport_permission_callback', $recorder->args[12] ?? null );
		$this->assertNull( aafm_server_registered_abilities(), 'Playbooks stay unrestricted when no tool set was built.' );
		$this->assertSame( 5, has_filter( 'mcp_adapter_tools_list', 'aafm_filter_mcp_tools_list' ) );
		$this->assertSame( 10, has_filter( 'rest_pre_dispatch', 'aafm_reject_scalar_mcp_body' ) );
	}

	public function test_a_request_that_needs_tools_gets_them(): void {
		$this->register_enabled( array( 'aafm/get-post' ) );
		$this->acting_as( 'administrator' );
		$recorder = $this->recorder();

		aafm_register_mcp_server( $recorder );

		$this->assertContains( 'aafm/get-post', (array) ( $recorder->args[9] ?? array() ) );
		$this->assertContains( 'aafm/get-post', (array) aafm_server_registered_abilities() );
	}
}
