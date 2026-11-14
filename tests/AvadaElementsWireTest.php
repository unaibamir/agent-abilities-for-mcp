<?php
/**
 * Wire-level proof that the Avada element tools (list, update, insert, move and remove) round-trip over a real
 * tools/call, with the exact wire body asserted (absent keys asserted absent). Avada Builder's element map is the
 * seam stub (Ruling B14).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use AAFM\Tests\Support\AvadaMapStub;

require_once __DIR__ . '/Support/AvadaMapStub.php';

final class AvadaElementsWireTest extends TestCase {

	private const PAGE = '[fusion_builder_container][fusion_builder_row][fusion_builder_column][fusion_button link="" title="Bravo"]Go[/fusion_button][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

	private const BUTTON = '[fusion_button link="" title="Bravo"]Go[/fusion_button]';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		AvadaMapStub::install();
		add_shortcode( 'fusion_title', '__return_empty_string' );
		aafm_flush_registry_cache();
	}

	public function tear_down(): void {
		AvadaMapStub::remove();
		remove_shortcode( 'fusion_button' );
		remove_shortcode( 'fusion_title' );
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * A throwaway MCP server carrying only the Avada element abilities.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-avada-elements-wire-test-' . $counter;
		$tools     = aafm_build_server_tools(
			aafm_preflight_bound_server_tools_cached( array( 'aafm/avada-list-elements', 'aafm/avada-update-element', 'aafm/avada-insert-element', 'aafm/avada-move-element', 'aafm/avada-remove-element' ) )
		);
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (Avada elements wire test)',
					'Test-only server carrying only the Avada element abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only Avada elements wire server.' );
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
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/avada-list-elements', 'aafm/avada-update-element', 'aafm/avada-insert-element', 'aafm/avada-move-element', 'aafm/avada-remove-element' ) );
		$this->acting_as( 'editor' );
		// Avada registers its element shortcodes itself; PHPUnit does not load it, and the registration above resets them.
		add_shortcode( 'fusion_button', '__return_empty_string' );
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
		$this->assertFalse( (bool) $result->getIsError(), (string) $result->getContent()[0]->getText() );
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
					'name'      => aafm_mcp_tool_name( 'aafm/avada-list-elements' ),
					'arguments' => array( 'search' => 'tabs' ),
				),
				'req-avada-list-1'
			)
		);
		$this->assertSame(
			array(
				'elements'   => array(
					array(
						'tag'          => 'fusion_tabs',
						'name'         => 'Tabs',
						'is_container' => true,
						'child_tag'    => 'fusion_tab',
					),
				),
				'total'      => 1,
				'skipped'    => 0,
				'map_source' => 'avada',
			),
			$out
		);
	}

	public function test_update_round_trips_over_a_real_tools_call(): void {
		list( $handler, $id ) = $this->wired();
		$expect               = array(
			'tag'         => 'fusion_button',
			'fingerprint' => self::fp( self::BUTTON ),
		);

		$done   = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/avada-update-element' ),
					'arguments' => array(
						'post_id'  => $id,
						'index'    => 3,
						'expect'   => $expect,
						'set_atts' => array( 'title' => "It's & a < b" ),
					),
				),
				'req-avada-update-1'
			)
		);
		$button = '[fusion_button link="" title="It\'s &amp; a &lt; b"]Go[/fusion_button]';
		$stored = str_replace( self::BUTTON, $button, self::PAGE );
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'changed', 'restore_revision_id', 'reversible', 'undo' ), array_keys( $done ), 'reversible_reason is absent while the restore point is kept.' );
		$this->assertSame( $id, $done['post']['id'] ?? $done['post']['ID'] ?? null );
		$this->assertSame( 3, $done['index'] );
		$this->assertSame( self::fp( $button ), $done['fingerprint'] );
		$this->assertSame( self::fp( $stored ), $done['page_fingerprint'] );
		$this->assertSame( array( 'title' ), $done['changed'] );
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
				'name'      => aafm_mcp_tool_name( 'aafm/avada-update-element' ),
				'arguments' => array(
					'post_id'  => $id,
					'index'    => 3,
					'expect'   => $expect,
					'set_atts' => array( 'title' => 'Stale' ),
				),
			),
			'req-avada-update-2'
		);
		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $refused );
		$this->assertTrue( $refused->getIsError() );
		$this->assertCount( 1, $refused->getContent() );
		$this->assertSame( aafm_builder_error( 'aafm_builder_element_mismatch' )->get_error_message(), $refused->getContent()[0]->getText() );
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_a_lost_restore_point_adds_reversible_reason_on_the_wire(): void {
		list( $handler, $id ) = $this->wired();
		$keep                 = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $keep );
		$done                 = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/avada-update-element' ),
					'arguments' => array(
						'post_id'  => $id,
						'index'    => 3,
						'expect'   => array(
							'tag'         => 'fusion_button',
							'fingerprint' => self::fp( self::BUTTON ),
						),
						'set_atts' => array( 'title' => 'One kept' ),
					),
				),
				'req-avada-update-3'
			)
		);
		remove_filter( 'wp_revisions_to_keep', $keep );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'changed', 'restore_revision_id', 'reversible', 'reversible_reason', 'undo' ), array_keys( $done ) );
		$this->assertNull( $done['restore_revision_id'] );
		$this->assertFalse( $done['reversible'] );
	}

	/**
	 * Call one tool and return its wire body.
	 *
	 * @param object              $handler Handler.
	 * @param string              $ability Ability.
	 * @param array<string,mixed> $args    Arguments.
	 * @param string              $req     Request id.
	 * @return array<string,mixed>
	 */
	private function call( $handler, string $ability, array $args, string $req ): array {
		return $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( $ability ),
					'arguments' => $args,
				),
				$req
			)
		);
	}

	public function test_insert_move_and_remove_round_trip_with_exact_keys(): void {
		list( $handler, $id ) = $this->wired();
		$container            = array(
			'tag'      => 'fusion_builder_container',
			'children' => array(
				array(
					'tag'      => 'fusion_builder_row',
					'children' => array(
						array(
							'tag'      => 'fusion_builder_column',
							'atts'     => array( 'type' => '1_1' ),
							'children' => array(
								array(
									'tag'  => 'fusion_title',
									'text' => 'Wire',
								),
							),
						),
					),
				),
			),
		);
		$ins                  = $this->call(
			$handler,
			'aafm/avada-insert-element',
			array(
				'post_id'          => $id,
				'page_fingerprint' => self::fp( self::PAGE ),
				'anchor_index'     => 0,
				'anchor_expect'    => array(
					'tag'         => 'fusion_builder_container',
					'fingerprint' => self::fp( self::PAGE ),
				),
				'placement'        => 'after',
				'element'          => $container,
			),
			'req-avada-insert-1'
		);
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'inserted', 'restore_revision_id', 'reversible', 'undo' ), array_keys( $ins ), 'reversible_reason is absent while the restore point is kept.' );
		$this->assertSame( 4, $ins['index'] );
		$this->assertSame( 4, $ins['inserted'] );
		$this->assertTrue( $ins['reversible'] );
		$stored = (string) get_post_field( 'post_content', $id, 'raw' );
		$this->assertSame( self::fp( $stored ), $ins['page_fingerprint'] );

		$mov = $this->call(
			$handler,
			'aafm/avada-move-element',
			array(
				'post_id'          => $id,
				'page_fingerprint' => $ins['page_fingerprint'],
				'index'            => 4,
				'expect'           => array(
					'tag'         => 'fusion_builder_container',
					'fingerprint' => $ins['fingerprint'],
				),
				'anchor_index'     => 0,
				'anchor_expect'    => array(
					'tag'         => 'fusion_builder_container',
					'fingerprint' => self::fp( self::PAGE ),
				),
				'placement'        => 'before',
			),
			'req-avada-move-1'
		);
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'moved', 'restore_revision_id', 'reversible', 'undo' ), array_keys( $mov ) );
		$this->assertSame( 0, $mov['index'] );
		$this->assertTrue( $mov['moved'] );
		$this->assertStringEndsWith( self::PAGE, (string) get_post_field( 'post_content', $id, 'raw' ) );

		$rem = $this->call(
			$handler,
			'aafm/avada-remove-element',
			array(
				'post_id'          => $id,
				'page_fingerprint' => $mov['page_fingerprint'],
				'index'            => 0,
				'expect'           => array(
					'tag'         => 'fusion_builder_container',
					'fingerprint' => $mov['fingerprint'],
				),
			),
			'req-avada-remove-1'
		);
		$this->assertSame( array( 'post', 'removed', 'page_fingerprint', 'restore_revision_id', 'reversible', 'undo' ), array_keys( $rem ) );
		$this->assertSame( 4, $rem['removed'] );
		$this->assertSame( self::PAGE, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertIsInt( $rem['restore_revision_id'] );
	}

	public function test_a_lost_restore_point_adds_reversible_reason_to_insert_and_move(): void {
		list( $handler, $id ) = $this->wired();
		$keep                 = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $keep );
		$ins                  = $this->call(
			$handler,
			'aafm/avada-insert-element',
			array(
				'post_id'          => $id,
				'page_fingerprint' => self::fp( self::PAGE ),
				'anchor_index'     => 3,
				'anchor_expect'    => array(
					'tag'         => 'fusion_button',
					'fingerprint' => self::fp( self::BUTTON ),
				),
				'placement'        => 'after',
				'element'          => array(
					'tag'  => 'fusion_title',
					'text' => 'Kept one',
				),
			),
			'req-avada-insert-2'
		);
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'inserted', 'restore_revision_id', 'reversible', 'reversible_reason', 'undo' ), array_keys( $ins ) );
		$this->assertNull( $ins['restore_revision_id'] );
		$this->assertFalse( $ins['reversible'] );
		$mov = $this->call(
			$handler,
			'aafm/avada-move-element',
			array(
				'post_id'          => $id,
				'page_fingerprint' => $ins['page_fingerprint'],
				'index'            => 4,
				'expect'           => array(
					'tag'         => 'fusion_title',
					'fingerprint' => $ins['fingerprint'],
				),
				'anchor_index'     => 3,
				'anchor_expect'    => array(
					'tag'         => 'fusion_button',
					'fingerprint' => self::fp( self::BUTTON ),
				),
				'placement'        => 'before',
			),
			'req-avada-move-2'
		);
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'moved', 'restore_revision_id', 'reversible', 'reversible_reason', 'undo' ), array_keys( $mov ) );
		$refused = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/avada-remove-element' ),
				'arguments' => array(
					'post_id'          => $id,
					'page_fingerprint' => $mov['page_fingerprint'],
					'index'            => 3,
					'expect'           => array(
						'tag'         => 'fusion_title',
						'fingerprint' => $mov['fingerprint'],
					),
				),
			),
			'req-avada-remove-2'
		);
		remove_filter( 'wp_revisions_to_keep', $keep );
		$this->assertTrue( $refused->getIsError() );
		$this->assertStringContainsString( 'keeps only 1 revision', $refused->getContent()[0]->getText() );
	}
}
