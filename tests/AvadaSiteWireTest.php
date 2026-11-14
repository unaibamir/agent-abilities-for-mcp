<?php
/**
 * Wire-level proof that the Avada site tools round-trip over a real tools/call, with the exact wire body asserted
 * (absent keys asserted absent). Avada's map is the seam stub (Ruling B14).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use AAFM\Tests\Support\AvadaMapStub;

require_once __DIR__ . '/Support/AvadaMapStub.php';

final class AvadaSiteWireTest extends TestCase {

	private const PAGE = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_text]Hello[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

	private const SECTION = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_text]<p>Saved section</p>[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

	private const TOOLS = array( 'aafm/avada-insert-library-item', 'aafm/avada-update-page-css' );

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		AvadaMapStub::install();
		add_shortcode( 'fusion_global', '__return_empty_string' );
		foreach ( array( 'fusion_element', 'fusion_template' ) as $type ) {
			register_post_type(
				$type,
				array(
					'public'          => false,
					'capability_type' => 'post',
					'map_meta_cap'    => true,
				)
			);
		}
		register_taxonomy( 'element_category', array( 'fusion_element' ), array( 'hierarchical' => true ) );
		aafm_flush_registry_cache();
	}

	public function tear_down(): void {
		AvadaMapStub::remove();
		remove_shortcode( 'fusion_global' );
		unregister_taxonomy( 'element_category' );
		unregister_post_type( 'fusion_element' );
		unregister_post_type( 'fusion_template' );
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * A throwaway MCP server carrying only the Avada site abilities.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-avada-site-wire-test-' . $counter;
		$tools     = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( self::TOOLS ) );
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (Avada site wire test)',
					'Test-only server carrying only the Avada site abilities.',
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
		$server = $adapter->get_server( $server_id );
		if ( ! $server instanceof \WP\MCP\Core\McpServer ) {
			throw new \RuntimeException( 'Failed to build the test-only Avada site wire server.' );
		}
		return $server;
	}

	/**
	 * A handler and an Avada page the caller can edit.
	 *
	 * @param string $role Role to act as.
	 * @return array{0:\AAFM\Tests\Support\McpToolsHandlerShim,1:int,2:\WP\MCP\Core\McpServer}
	 */
	private function wired( string $role = 'administrator' ): array {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => self::PAGE,
			)
		);
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( self::TOOLS );
		$this->acting_as( $role );
		$server = $this->build_server( \WP\MCP\Core\McpAdapter::instance() );
		return array( new \AAFM\Tests\Support\McpToolsHandlerShim( $server ), $id, $server );
	}

	/**
	 * The structured content of a tool result, as a client decodes it.
	 *
	 * @param mixed $result Tool result.
	 * @return array<string,mixed>
	 */
	private function wire( $result ): array {
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $result );
		$this->assertFalse( (bool) $result->getIsError(), (string) ( $result->getContent()[0]->getText() ?? '' ) );
		return json_decode( (string) $result->getContent()[0]->getText(), true );
	}

	/**
	 * First 16 hex characters of SHA-256, worked out here independently of the engine.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	private static function fp( string $source ): string {
		return substr( hash( 'sha256', $source ), 0, 16 );
	}

	/**
	 * A library item.
	 *
	 * @param bool $is_global Whether it is global.
	 * @return int
	 */
	private function item( bool $is_global = false ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'fusion_element',
				'post_content' => self::SECTION,
			)
		);
		wp_set_object_terms( $id, 'sections', 'element_category' );
		if ( $is_global ) {
			add_post_meta( $id, '_fusion_is_global', 'yes' );
		}
		return $id;
	}

	/**
	 * Library insert arguments anchored after the page's only container.
	 *
	 * @param int $id   Page.
	 * @param int $item Item.
	 * @return array<string,mixed>
	 */
	private static function insert_args( int $id, int $item ): array {
		return array(
			'post_id'          => $id,
			'page_fingerprint' => self::fp( self::PAGE ),
			'item_id'          => $item,
			'anchor_index'     => 0,
			'anchor_expect'    => array(
				'tag'         => 'fusion_builder_container',
				'fingerprint' => self::fp( self::PAGE ),
			),
			'placement'        => 'after',
		);
	}

	public function test_insert_library_item_round_trips_over_a_real_tools_call(): void {
		list( $handler, $id ) = $this->wired( 'editor' );
		$item                 = $this->item();
		$done                 = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/avada-insert-library-item' ),
					'arguments' => self::insert_args( $id, $item ),
				),
				'req-avada-library-1'
			)
		);
		$stored               = self::PAGE . self::SECTION;
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'inserted', 'restore_revision_id', 'reversible', 'undo' ), array_keys( $done ) );
		$this->assertSame( $id, $done['post']['id'] );
		$this->assertArrayNotHasKey( 'post_content', $done['post'] );
		$this->assertSame( 4, $done['index'] );
		$this->assertSame( self::fp( self::SECTION ), $done['fingerprint'] );
		$this->assertSame( self::fp( $stored ), $done['page_fingerprint'] );
		$this->assertSame( 4, $done['inserted'] );
		$this->assertIsInt( $done['restore_revision_id'] );
		$this->assertTrue( $done['reversible'] );
		$this->assertSame(
			array(
				'undoable'   => false,
				'change_ids' => array(),
				'reason'     => 'not_covered',
			),
			$done['undo']
		);

		$refused = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/avada-insert-library-item' ),
				'arguments' => self::insert_args( $id, $item ),
			),
			'req-avada-library-2'
		);
		$this->assertTrue( $refused->getIsError() );
		$this->assertSame( aafm_builder_error( 'aafm_builder_page_changed' )->get_error_message(), $refused->getContent()[0]->getText() );
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_a_global_item_goes_on_the_wire_as_a_reference_with_reversible_reason_when_the_restore_point_is_gone(): void {
		list( $handler, $id ) = $this->wired( 'editor' );
		$item                 = $this->item( true );
		$one                  = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $one );
		$done                 = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/avada-insert-library-item' ),
					'arguments' => self::insert_args( $id, $item ),
				),
				'req-avada-library-limit'
			)
		);
		remove_filter( 'wp_revisions_to_keep', $one );
		$ref = '[fusion_global id="' . $item . '"]';
		$this->assertSame( self::PAGE . $ref, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'inserted', 'restore_revision_id', 'reversible', 'reversible_reason', 'undo' ), array_keys( $done ) );
		$this->assertSame( self::fp( $ref ), $done['fingerprint'] );
		$this->assertSame( 1, $done['inserted'] );
		$this->assertNull( $done['restore_revision_id'] );
		$this->assertFalse( $done['reversible'] );
		$this->assertStringContainsString( 'no longer there after the save', $done['reversible_reason'] );
	}

	public function test_update_page_css_round_trips_over_a_real_tools_call(): void {
		list( $handler, $id ) = $this->wired();
		update_post_meta( $id, '_fusion_builder_custom_css', '.old{}' );
		$call = fn( string $req ) => $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/avada-update-page-css' ),
				'arguments' => array(
					'post_id'    => $id,
					'custom_css' => '.title{display:none}',
				),
			),
			$req
		);
		$this->assertSame(
			array(
				'post_id'  => $id,
				'changed'  => true,
				'previous' => '.old{}',
				'undo'     => array(
					'undoable'   => false,
					'change_ids' => array(),
					'reason'     => 'not_covered',
				),
			),
			$this->wire( $call( 'req-avada-css-1' ) )
		);
		$this->assertSame( '.title{display:none}', get_post_meta( $id, '_fusion_builder_custom_css', true ) );
		$again = $this->wire( $call( 'req-avada-css-2' ) );
		$this->assertFalse( $again['changed'] );
		$this->assertSame( '.title{display:none}', $again['previous'] );
	}

	public function test_update_page_css_is_absent_from_tools_list_without_unfiltered_html(): void {
		list( $handler, , $server ) = $this->wired( 'editor' );
		$editor                     = wp_get_current_user();
		$names                      = static fn(): array => array_map( static fn( $tool ) => $tool->getName(), aafm_filter_mcp_tools_list( $handler->list_tools()->getTools(), $server ) );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/avada-update-page-css' ), $names() );
		$editor->add_cap( 'unfiltered_html', false );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$visible = $names();
		$this->assertNotContains( aafm_mcp_tool_name( 'aafm/avada-update-page-css' ), $visible );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/avada-insert-library-item' ), $visible );
	}
}
