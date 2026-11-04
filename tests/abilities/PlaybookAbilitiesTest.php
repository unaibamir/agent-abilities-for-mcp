<?php
/**
 * The two playbook read abilities: shape, gating, availability report and named refusals.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\McpProtocolError;
use AAFM\Tests\Support\McpToolsHandlerShim;
use AAFM\Tests\TestCase;

final class PlaybookAbilitiesTest extends TestCase {

	private const BOTH = array( 'aafm/list-playbooks', 'aafm/get-playbook' );

	public function tear_down(): void {
		delete_option( 'aafm_playbooks' );
		delete_option( 'aafm_ability_allowlist_overrides' );
		parent::tear_down();
	}

	/**
	 * Store a playbook straight in the row.
	 *
	 * @param string $slug   Slug.
	 * @param bool   $active Active switch.
	 * @param string $body   Body.
	 * @return void
	 */
	private function seed( string $slug, bool $active = true, string $body = 'Read the draft.' ): void {
		$list   = get_option( 'aafm_playbooks', array() );
		$list[] = array(
			'slug'        => $slug,
			'title'       => ucfirst( str_replace( '-', ' ', $slug ) ),
			'description' => 'About ' . $slug,
			'body'        => $body,
			'active'      => $active,
			'updated_gmt' => '2026-11-04 10:00:00',
		);
		update_option( 'aafm_playbooks', $list, false );
	}

	/**
	 * A test server carrying the given enabled abilities, wrapped in the tools handler shim.
	 *
	 * @param string[] $names Ability names.
	 * @return McpToolsHandlerShim
	 */
	private function handler( array $names ): McpToolsHandlerShim {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-playbooks-test-' . $counter;
		$tools     = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( $names ) );
		$adapter   = \WP\MCP\Core\McpAdapter::instance();
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Playbooks test',
					'Test-only server.',
					AAFM_VERSION,
					array( \WP\MCP\Transport\HttpTransport::class ),
					\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
					\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
					$tools,
					array(),
					array(),
					'aafm_transport_permission_callback'
				);
			}
		);
		return new McpToolsHandlerShim( $adapter->get_server( $server_id ) );
	}

	/**
	 * The Activity Log rows for one ability.
	 *
	 * @param string $ability Ability name.
	 * @return list<array<string,mixed>>
	 */
	private function rows( string $ability ): array {
		global $wpdb;
		$table = aafm_activity_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ability = %s ORDER BY id ASC", $ability ), ARRAY_A );
	}

	public function test_both_abilities_are_registered_reads_and_off_by_default(): void {
		$registry = aafm_get_abilities_registry_full();
		foreach ( self::BOTH as $name ) {
			$this->assertSame( 'read', $registry[ $name ]['risk'] );
			$this->assertSame( 'reads', $registry[ $name ]['group'] );
			$this->assertSame( 'site', $registry[ $name ]['subject'] );
			$this->assertNotContains( $name, aafm_get_enabled_abilities() );
		}
	}

	public function test_list_returns_active_only_with_exact_keys(): void {
		$this->seed( 'on-one' );
		$this->seed( 'off-one', false );
		$this->acting_as( 'administrator' );
		$result = aafm_exec_list_playbooks();
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( array( 'slug', 'title', 'description', 'uses_abilities', 'unavailable_abilities' ), array_keys( $result['playbooks'][0] ) );
		$this->assertSame( 'on-one', $result['playbooks'][0]['slug'] );
	}

	public function test_get_returns_the_body_byte_identical(): void {
		$this->seed( 'on-one', true, "Line one\nLine \"two\" & more" );
		$this->acting_as( 'administrator' );
		$result = aafm_exec_get_playbook( array( 'slug' => 'on-one' ) );
		$this->assertSame( "Line one\nLine \"two\" & more", $result['body'] );
		$this->assertSame( array( 'slug', 'title', 'description', 'body', 'uses_abilities', 'unavailable_abilities', 'updated_gmt' ), array_keys( $result ) );
	}

	public function test_get_playbook_for_a_removed_slug_is_a_named_error(): void {
		$this->seed( 'gone-soon' );
		$this->register_enabled( self::BOTH );
		$this->acting_as( 'administrator' );
		delete_option( 'aafm_playbooks' );

		$result = $this->handler( self::BOTH )->call_tool(
			array(
				'name'      => 'aafm-get-playbook',
				'arguments' => array( 'slug' => 'gone-soon' ),
			)
		);
		$this->assertNotInstanceOf( McpProtocolError::class, $result );
		$this->assertTrue( $result->getIsError() );
		$text = $result->getContent()[0]->getText();
		$this->assertStringContainsString( 'gone-soon', $text );
		$this->assertStringContainsString( 'list-playbooks', $text );

		$this->register_enabled( array( 'aafm/get-playbook' ) );
		$result = $this->handler( array( 'aafm/get-playbook' ) )->call_tool(
			array(
				'name'      => 'aafm-get-playbook',
				'arguments' => array( 'slug' => 'gone-soon' ),
			)
		);
		$text   = $result->getContent()[0]->getText();
		$this->assertStringContainsString( 'gone-soon', $text );
		$this->assertStringNotContainsString( 'list-playbooks', $text );
	}

	public function test_an_omitted_ability_is_unavailable(): void {
		$this->register_enabled( array( 'aafm/get-posts', 'aafm/get-playbook' ) );
		$this->acting_as( 'administrator' );
		update_option( 'aafm_omitted_abilities', array() );
		aafm_server_registered_abilities( array( 'aafm/get-playbook' ) );
		$this->assertSame( array( 'aafm/get-posts' ), aafm_playbook_unavailable_abilities( array( 'aafm/get-posts' ) ) );
		aafm_server_registered_abilities( array( 'aafm/get-playbook', 'aafm/get-posts' ) );
		$this->assertSame( array(), aafm_playbook_unavailable_abilities( array( 'aafm/get-posts' ) ) );
		delete_option( 'aafm_omitted_abilities' );
	}

	public function test_get_without_slug_is_a_missing_input_error(): void {
		$this->register_enabled( self::BOTH );
		$this->acting_as( 'administrator' );
		$result = $this->handler( self::BOTH )->call_tool(
			array(
				'name'      => 'aafm-get-playbook',
				'arguments' => array(),
			)
		);
		$this->assertNotInstanceOf( McpProtocolError::class, $result );
		$this->assertTrue( $result->getIsError() );
		$this->assertSame( aafm_missing_input_error( 'aafm/get-playbook', array( 'slug' ) )->get_error_message(), $result->getContent()[0]->getText() );
	}

	public function test_named_abilities_and_unavailable_ones(): void {
		$this->register_enabled( array( 'aafm/get-posts' ) );
		$this->acting_as( 'administrator' );
		$names = aafm_playbook_named_abilities( 'Use aafm/create-draft, then aafm-get-posts and aafm-trash-post; aafm/not-real.' );
		$this->assertSame( array( 'aafm/create-draft', 'aafm/get-posts', 'aafm/trash-post', 'aafm/not-real' ), $names );
		$this->assertSame( array( 'aafm/create-draft', 'aafm/trash-post', 'aafm/not-real' ), aafm_playbook_unavailable_abilities( $names ) );
	}

	public function test_thirty_one_names_are_all_reported(): void {
		$picked = array_slice( array_keys( aafm_get_abilities_registry() ), 0, 31 );
		$this->register_enabled( array_slice( $picked, 0, 30 ) );
		$this->acting_as( 'administrator' );
		$names = aafm_playbook_named_abilities( implode( "\n", array_map( 'aafm_mcp_tool_name', $picked ) ) );
		$this->assertCount( 31, $names );
		$this->assertContains( $picked[30], aafm_playbook_unavailable_abilities( $names ) );
	}

	public function test_a_subscriber_agent_with_the_ability_enabled_can_read(): void {
		$this->register_enabled( self::BOTH );
		$this->acting_as( 'subscriber' );
		$this->assertTrue( aafm_perm_read() );
	}

	public function test_role_allowlist_narrows_it_like_any_ability(): void {
		$this->seed( 'on-one' );
		$this->register_enabled( self::BOTH );
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/list-playbooks' ),
				),
			)
		);
		$this->acting_as( 'administrator' );
		$handler = $this->handler( self::BOTH );
		$this->acting_as( 'editor' );
		$names = array_map( static fn( $tool ) => $tool->getName(), aafm_filter_mcp_tools_list( $handler->list_tools()->getTools(), null ) );
		$this->assertNotContains( 'aafm-get-playbook', $names );

		$result = $handler->call_tool(
			array(
				'name'      => 'aafm-get-playbook',
				'arguments' => array( 'slug' => 'on-one' ),
			)
		);
		$this->assertNotInstanceOf( McpProtocolError::class, $result );
		$this->assertTrue( $result->getIsError() );
		$this->assertContains( 'denied', array_column( $this->rows( 'aafm/get-playbook' ), 'status' ) );
	}

	public function test_get_call_row_carries_the_slug(): void {
		$this->seed( 'publish-checklist', true, 'secret body words' );
		$this->register_enabled( self::BOTH );
		$this->acting_as( 'administrator' );
		$result = $this->handler( self::BOTH )->call_tool(
			array(
				'name'      => 'aafm-get-playbook',
				'arguments' => array( 'slug' => 'publish-checklist' ),
			)
		);
		$this->assertFalse( $result->getIsError() );
		$rows = array_values( array_filter( $this->rows( 'aafm/get-playbook' ), static fn( $r ) => 'success' === $r['status'] ) );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'slug:publish-checklist', (string) $rows[0]['detail'] );
		$this->assertStringNotContainsString( 'secret body words', (string) $rows[0]['detail'] );
	}

	public function test_quick_connect_does_not_enable_them(): void {
		$bundle = aafm_quickconnect_read_abilities();
		foreach ( self::BOTH as $name ) {
			$this->assertNotContains( $name, $bundle );
		}
		$this->assertContains( 'aafm/get-site-info', $bundle );
	}

	public function test_read_only_preset_includes_both(): void {
		foreach ( self::BOTH as $name ) {
			$this->assertTrue( aafm_preset_permits( 'read_only', $name ) );
		}
	}

	public function test_a_failed_read_is_an_error_not_an_empty_library(): void {
		$this->seed( 'on-one' );
		$this->acting_as( 'administrator' );
		add_filter(
			'query',
			static function ( string $query ): string {
				return false !== strpos( $query, 'SELECT' ) && false !== strpos( $query, "'aafm_playbooks'" ) ? 'SELECT * FROM aafm_missing_table_for_test' : $query;
			}
		);
		ob_start();
		$list = aafm_exec_list_playbooks();
		$get  = aafm_exec_get_playbook( array( 'slug' => 'on-one' ) );
		ob_end_clean();
		remove_all_filters( 'query' );
		$this->assertWPError( $list );
		$this->assertSame( 'aafm_playbooks_unreadable', $list->get_error_code() );
		$this->assertWPError( $get );
		$this->assertSame( 'aafm_playbooks_unreadable', $get->get_error_code() );
	}
}
