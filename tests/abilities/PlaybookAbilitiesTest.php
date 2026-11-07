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
	 * @param string   $slug   Slug.
	 * @param bool     $active Active switch.
	 * @param string   $body   Body.
	 * @param string[] $abilities Picked abilities.
	 * @return void
	 */
	private function seed( string $slug, bool $active = true, string $body = 'Read the draft.', array $abilities = array() ): void {
		$list   = get_option( 'aafm_playbooks', array() );
		$list[] = array(
			'slug'        => $slug,
			'title'       => ucfirst( str_replace( '-', ' ', $slug ) ),
			'description' => 'About ' . $slug,
			'body'        => $body,
			'active'      => $active,
			'abilities'   => $abilities,
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
		$this->assertSame( array( 'slug', 'title', 'description', 'body', 'uses_abilities', 'unavailable_abilities', 'abilities', 'updated_gmt' ), array_keys( $result ) );
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

	public function test_content_editor_and_shop_manager_reach_both_playbook_reads(): void {
		foreach ( array( 'content_editor', 'shop_manager' ) as $preset ) {
			foreach ( self::BOTH as $name ) {
				$this->assertTrue( aafm_preset_permits( $preset, $name ), "$preset: $name" );
			}
		}
	}

	public function test_content_editor_grant_sees_the_playbook_index(): void {
		$this->seed( 'on-one' );
		$this->register_enabled( self::BOTH );
		$this->acting_as( 'editor' );
		aafm_oauth_current_client_id( 'preset-playbook-client' );
		aafm_oauth_current_grant(
			array_merge(
				aafm_oauth_grant_defaults(),
				array(
					'preset'    => 'content_editor',
					'client_id' => 'preset-playbook-client',
					'user_id'   => get_current_user_id(),
				)
			)
		);
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/list-playbooks' ) );
		$this->assertStringContainsString( 'on-one', aafm_playbook_index() );
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

	public function test_abilities_are_stored_and_read_back(): void {
		$this->acting_as( 'administrator' );
		$saved = aafm_save_playbook(
			array(
				'title'     => 'Picks',
				'body'      => 'Steps.',
				'abilities' => array( 'aafm/get-post', 'aafm/get-post', 'aafm/not-real' ),
			)
		);
		$this->assertSame( array( 'aafm/get-post' ), aafm_playbook( $saved['slug'] )['abilities'] );
	}

	public function test_abilities_accept_a_json_string(): void {
		$this->assertSame( array( 'aafm/get-terms' ), aafm_clean_playbook_abilities( '["aafm/get-terms"]' ) );
		$this->assertSame( array(), aafm_clean_playbook_abilities( 'nonsense' ) );
	}

	public function test_more_than_fifty_abilities_names_the_range(): void {
		$names  = array_slice( array_keys( aafm_get_abilities_registry_full() ), 0, 51 );
		$result = aafm_clean_playbook_abilities( $names );
		$this->assertWPError( $result );
		$this->assertSame( 'aafm_playbook_invalid', $result->get_error_code() );
		$this->assertStringContainsString( '50', $result->get_error_message() );
	}

	public function test_entry_without_abilities_reads_as_empty(): void {
		update_option(
			'aafm_playbooks',
			array(
				array(
					'slug'   => 'old',
					'title'  => 'Old',
					'body'   => 'Read it with aafm/get-post.',
					'active' => true,
				),
			),
			false
		);
		$this->acting_as( 'administrator' );
		$this->assertSame( array(), aafm_playbook( 'old' )['abilities'] );
		$this->assertSame( array(), aafm_exec_list_playbooks()['playbooks'][0]['uses_abilities'] );
	}

	public function test_stored_list_is_the_only_source(): void {
		$this->seed( 'one', true, 'Read it with aafm/get-post.', array( 'aafm/get-terms' ) );
		$this->acting_as( 'administrator' );
		$this->assertSame( array( 'aafm/get-terms' ), aafm_exec_list_playbooks()['playbooks'][0]['uses_abilities'] );
	}

	public function test_named_abilities_function_is_gone(): void {
		$this->assertFalse( function_exists( 'aafm_playbook_named_abilities' ) );
	}

	public function test_stored_pick_for_an_inactive_integration_survives_read(): void {
		$this->seed( 'shop', true, 'Steps.', array( 'aafm/wc-get-product' ) );
		$this->acting_as( 'administrator' );
		$item = aafm_exec_list_playbooks()['playbooks'][0];
		$this->assertSame( array( 'aafm/wc-get-product' ), $item['uses_abilities'] );
		$this->assertSame( array( 'aafm/wc-get-product' ), $item['unavailable_abilities'] );
	}

	public function test_get_returns_ability_detail(): void {
		$this->register_enabled( array( 'aafm/get-post', 'aafm/get-playbook' ) );
		$this->seed( 'one', true, 'Steps.', array( 'aafm/get-post', 'aafm/get-terms' ) );
		$this->acting_as( 'administrator' );
		$result = aafm_exec_get_playbook( array( 'slug' => 'one' ) );
		$this->assertSame( array( 'slug', 'title', 'description', 'body', 'uses_abilities', 'unavailable_abilities', 'abilities', 'updated_gmt' ), array_keys( $result ) );
		$registry = aafm_get_abilities_registry_full();
		foreach ( $result['abilities'] as $item ) {
			$this->assertSame( array( 'name', 'tool', 'label', 'available' ), array_keys( $item ) );
			$this->assertSame( aafm_mcp_tool_name( $item['name'] ), $item['tool'] );
			$this->assertSame( $registry[ $item['name'] ]['label'], $item['label'] );
		}
		$this->assertTrue( $result['abilities'][0]['available'] );
		$this->assertFalse( $result['abilities'][1]['available'], 'get-terms is not enabled.' );
	}

	public function test_list_keys_unchanged(): void {
		$this->seed( 'one', true, 'Steps.', array( 'aafm/get-post' ) );
		$this->acting_as( 'administrator' );
		$this->assertSame( array( 'slug', 'title', 'description', 'uses_abilities', 'unavailable_abilities' ), array_keys( aafm_exec_list_playbooks()['playbooks'][0] ) );
	}

	/**
	 * The floored bridged reader leaves a held tool out while locked, so building the playbook's
	 * known list from it would drop the tool on the next save for good.
	 */
	public function test_saving_a_playbook_while_locked_keeps_a_held_tool(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'demo/charge-card' ) );
		$hold = static fn( array $e ): array => array_merge( $e, array( 'demo/charge-card' ) );
		add_filter( 'aafm_high_risk_abilities', $hold );

		$cleaned = aafm_clean_playbook_abilities( array( 'aafm/get-post', 'demo/charge-card' ) );

		remove_filter( 'aafm_high_risk_abilities', $hold );
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->assertSame( array( 'aafm/get-post', 'demo/charge-card' ), $cleaned );
	}
}
