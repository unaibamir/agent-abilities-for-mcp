<?php
/**
 * Wire-level proof that aafm-wpbakery-list-elements and aafm-wpbakery-update-element round-trip over a real
 * tools/call, with the exact wire body asserted (absent keys asserted absent). WPBakery's element map is the
 * seam stub (Ruling B14).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use AAFM\Tests\Support\WpbakeryMapStub;

require_once __DIR__ . '/Support/WpbakeryMapStub.php';

final class WpbakeryElementsWireTest extends TestCase {

	private const PAGE = '[vc_row][vc_column][vc_custom_heading text="Heading bravo"][vc_column_text]<p>Hello</p>[/vc_column_text][/vc_column][/vc_row]';

	private const HEADING = '[vc_custom_heading text="Heading bravo"]';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		WpbakeryMapStub::install();
		aafm_flush_registry_cache();
	}

	public function tear_down(): void {
		WpbakeryMapStub::remove();
		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * A throwaway MCP server carrying only the two element abilities.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-wpbakery-elements-wire-test-' . $counter;
		$tools     = aafm_build_server_tools(
			aafm_preflight_bound_server_tools_cached( array( 'aafm/wpbakery-list-elements', 'aafm/wpbakery-update-element', 'aafm/wpbakery-insert-element', 'aafm/wpbakery-move-element', 'aafm/wpbakery-remove-element' ) )
		);
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (WPBakery elements wire test)',
					'Test-only server carrying only the WPBakery element abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only WPBakery elements wire server.' );
		}
		return $server;
	}

	/**
	 * A handler and a page the editor can edit.
	 *
	 * @return array{0:\AAFM\Tests\Support\McpToolsHandlerShim,1:int}
	 */
	private function wired(): array {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => self::PAGE,
			)
		);
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/wpbakery-list-elements', 'aafm/wpbakery-update-element', 'aafm/wpbakery-insert-element', 'aafm/wpbakery-move-element', 'aafm/wpbakery-remove-element' ) );
		$this->acting_as( 'editor' );
		return array( new \AAFM\Tests\Support\McpToolsHandlerShim( $this->build_server( \WP\MCP\Core\McpAdapter::instance() ) ), $id );
	}

	/**
	 * The structured content of a tool result, as a client decodes it.
	 *
	 * @param mixed $result Tool result.
	 * @return array<string,mixed>
	 */
	private function wire( $result ): array {
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $result );
		$this->assertFalse( (bool) $result->getIsError() );
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

	public function test_list_round_trips_over_a_real_tools_call(): void {
		list( $handler ) = $this->wired();

		$out = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-list-elements' ),
					'arguments' => array( 'search' => 'raw_js' ),
				),
				'req-wpb-list-1'
			)
		);
		$this->assertSame(
			array(
				'elements'   => array(
					array(
						'tag'              => 'vc_raw_js',
						'name'             => 'Raw JS',
						'category'         => '',
						'is_container'     => false,
						'allowed_children' => array(
							'only'   => array(),
							'except' => array(),
						),
						'allowed_parents'  => array(
							'only'   => array(),
							'except' => array(),
						),
						'deprecated'       => false,
					),
				),
				'total'      => 1,
				'skipped'    => 0,
				'map_source' => 'wpbakery',
			),
			$out
		);
	}

	public function test_update_round_trips_over_a_real_tools_call(): void {
		list( $handler, $id ) = $this->wired();

		$done   = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-update-element' ),
					'arguments' => array(
						'post_id'  => $id,
						'index'    => 2,
						'expect'   => array(
							'tag'         => 'vc_custom_heading',
							'fingerprint' => self::fp( self::HEADING ),
						),
						'set_atts' => array( 'text' => 'Heading "new"' ),
					),
				),
				'req-wpb-update-1'
			)
		);
		$stored = str_replace( self::HEADING, '[vc_custom_heading text="Heading ``new``"]', self::PAGE );
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'changed', 'restore_revision_id', 'reversible', 'undo' ), array_keys( $done ) );
		$this->assertSame( $id, $done['post']['id'] ?? $done['post']['ID'] ?? null );
		$this->assertSame( 2, $done['index'] );
		$this->assertSame( self::fp( '[vc_custom_heading text="Heading ``new``"]' ), $done['fingerprint'] );
		$this->assertSame( self::fp( $stored ), $done['page_fingerprint'] );
		$this->assertSame( array( 'text' ), $done['changed'] );
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

		$args    = array(
			'post_id'  => $id,
			'index'    => 2,
			'expect'   => array(
				'tag'         => 'vc_custom_heading',
				'fingerprint' => self::fp( self::HEADING ),
			),
			'set_atts' => array( 'text' => 'Stale' ),
		);
		$refused = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-update-element' ),
				'arguments' => $args,
			),
			'req-wpb-update-2'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $refused );
		$this->assertTrue( $refused->getIsError() );
		$this->assertCount( 1, $refused->getContent() );
		$this->assertSame( aafm_builder_error( 'aafm_builder_element_mismatch' )->get_error_message(), $refused->getContent()[0]->getText() );
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_insert_round_trips_over_a_real_tools_call(): void {
		list( $handler, $id ) = $this->wired();
		$text                 = '[vc_column_text]<p>Hello</p>[/vc_column_text]';
		$args                 = array(
			'post_id'          => $id,
			'page_fingerprint' => self::fp( self::PAGE ),
			'anchor_index'     => 3,
			'anchor_expect'    => array(
				'tag'         => 'vc_column_text',
				'fingerprint' => self::fp( $text ),
			),
			'placement'        => 'after',
			'element'          => array(
				'tag'  => 'vc_custom_heading',
				'atts' => array( 'text' => 'Added "one"' ),
			),
		);
		$done                 = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-insert-element' ),
					'arguments' => $args,
				),
				'req-wpb-insert-1'
			)
		);
		$added                = '[vc_custom_heading text="Added ``one``"]';
		$stored               = str_replace( $text, $text . $added, self::PAGE );
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'inserted', 'restore_revision_id', 'reversible', 'undo' ), array_keys( $done ) );
		$this->assertSame( 4, $done['index'] );
		$this->assertSame( self::fp( $added ), $done['fingerprint'] );
		$this->assertSame( self::fp( $stored ), $done['page_fingerprint'] );
		$this->assertSame( 1, $done['inserted'] );
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
				'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-insert-element' ),
				'arguments' => $args,
			),
			'req-wpb-insert-2'
		);
		$this->assertTrue( $refused->getIsError() );
		$this->assertSame( aafm_builder_error( 'aafm_builder_page_changed' )->get_error_message(), $refused->getContent()[0]->getText() );
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_move_round_trips_over_a_real_tools_call(): void {
		list( $handler, $id ) = $this->wired();
		$text                 = '[vc_column_text]<p>Hello</p>[/vc_column_text]';
		$done                 = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-move-element' ),
					'arguments' => array(
						'post_id'          => $id,
						'page_fingerprint' => self::fp( self::PAGE ),
						'index'            => 3,
						'expect'           => array(
							'tag'         => 'vc_column_text',
							'fingerprint' => self::fp( $text ),
						),
						'anchor_index'     => 2,
						'anchor_expect'    => array(
							'tag'         => 'vc_custom_heading',
							'fingerprint' => self::fp( self::HEADING ),
						),
						'placement'        => 'before',
					),
				),
				'req-wpb-move-1'
			)
		);
		$stored               = '[vc_row][vc_column]' . $text . self::HEADING . '[/vc_column][/vc_row]';
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'moved', 'restore_revision_id', 'reversible', 'undo' ), array_keys( $done ) );
		$this->assertSame( 2, $done['index'] );
		$this->assertSame( self::fp( $text ), $done['fingerprint'] );
		$this->assertSame( self::fp( $stored ), $done['page_fingerprint'] );
		$this->assertTrue( $done['moved'] );
		$this->assertIsInt( $done['restore_revision_id'] );
		$this->assertTrue( $done['reversible'] );
		$this->assertSame( 'not_covered', $done['undo']['reason'] );
	}

	public function test_remove_round_trips_over_a_real_tools_call(): void {
		list( $handler, $id ) = $this->wired();
		$done                 = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-remove-element' ),
					'arguments' => array(
						'post_id'          => $id,
						'page_fingerprint' => self::fp( self::PAGE ),
						'index'            => 2,
						'expect'           => array(
							'tag'         => 'vc_custom_heading',
							'fingerprint' => self::fp( self::HEADING ),
						),
					),
				),
				'req-wpb-remove-1'
			)
		);
		$stored               = str_replace( self::HEADING, '', self::PAGE );
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( array( 'post', 'removed', 'page_fingerprint', 'restore_revision_id', 'undo' ), array_keys( $done ) );
		$this->assertSame( 1, $done['removed'] );
		$this->assertSame( self::fp( $stored ), $done['page_fingerprint'] );
		$this->assertSame( self::PAGE, get_post( $done['restore_revision_id'] )->post_content );
		$this->assertSame( 'not_covered', $done['undo']['reason'] );
	}

	public function test_reversible_reason_is_on_the_wire_only_when_the_restore_point_is_gone(): void {
		list( $handler, $id ) = $this->wired();
		$text                 = '[vc_column_text]<p>Hello</p>[/vc_column_text]';
		$one                  = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $one );
		$done                 = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-insert-element' ),
					'arguments' => array(
						'post_id'          => $id,
						'page_fingerprint' => self::fp( self::PAGE ),
						'anchor_index'     => 3,
						'anchor_expect'    => array(
							'tag'         => 'vc_column_text',
							'fingerprint' => self::fp( $text ),
						),
						'placement'        => 'after',
						'element'          => array(
							'tag'  => 'vc_custom_heading',
							'atts' => array( 'text' => 'Limited' ),
						),
					),
				),
				'req-wpb-insert-limit'
			)
		);
		remove_filter( 'wp_revisions_to_keep', $one );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'inserted', 'restore_revision_id', 'reversible', 'reversible_reason', 'undo' ), array_keys( $done ) );
		$this->assertNull( $done['restore_revision_id'] );
		$this->assertFalse( $done['reversible'] );
		$this->assertStringContainsString( 'no longer there after the save', $done['reversible_reason'] );
		$this->assertStringContainsString( 'revision limit', $done['reversible_reason'] );
	}
}
