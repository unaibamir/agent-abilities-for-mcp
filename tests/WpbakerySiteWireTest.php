<?php
/**
 * Wire-level proof that the WPBakery site tools (templates list, template insert, page code) round-trip over a real
 * tools/call, with the exact wire body asserted (absent keys asserted absent). WPBakery is the seam stub
 * (Ruling B14).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use AAFM\Tests\Support\WpbakeryMapStub;

require_once __DIR__ . '/Support/WpbakeryMapStub.php';

final class WpbakerySiteWireTest extends TestCase {

	private const PAGE = '[vc_row][vc_column][vc_column_text]<p>Hello</p>[/vc_column_text][/vc_column][/vc_row]';

	private const TEMPLATE = '[vc_row][vc_column][vc_column_text]<p>From the template</p>[/vc_column_text][/vc_column][/vc_row]';

	private const TOOLS = array( 'aafm/wpbakery-list-templates', 'aafm/wpbakery-insert-template', 'aafm/wpbakery-update-page-code' );

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		WpbakeryMapStub::install();
		aafm_flush_registry_cache();
		update_option(
			'wpb_js_templates',
			array(
				'hero_1' => array(
					'name'     => 'Hero',
					'template' => self::TEMPLATE,
				),
			),
			false
		);
	}

	public function tear_down(): void {
		WpbakeryMapStub::remove();
		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		delete_option( 'wpb_js_templates' );
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * A throwaway MCP server carrying only the WPBakery site abilities.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-wpbakery-site-wire-test-' . $counter;
		$tools     = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( self::TOOLS ) );
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (WPBakery site wire test)',
					'Test-only server carrying only the WPBakery site abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only WPBakery site wire server.' );
		}
		return $server;
	}

	/**
	 * A handler and a page the caller can edit.
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

	public function test_list_templates_round_trips_over_a_real_tools_call(): void {
		list( $handler ) = $this->wired( 'editor' );
		$this->assertSame(
			array(
				'templates'         => array(
					array(
						'id'     => 'hero_1',
						'source' => 'saved',
						'name'   => 'Hero',
					),
				),
				'total'             => 1,
				'templatera_active' => false,
			),
			$this->wire(
				$handler->call_tool(
					array(
						'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-list-templates' ),
						'arguments' => array(),
					),
					'req-wpb-templates-1'
				)
			)
		);
		$this->assertSame(
			array(
				'id'      => 'hero_1',
				'source'  => 'saved',
				'name'    => 'Hero',
				'content' => self::TEMPLATE,
			),
			$this->wire(
				$handler->call_tool(
					array(
						'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-list-templates' ),
						'arguments' => array( 'include_content' => true ),
					),
					'req-wpb-templates-2'
				)
			)['templates'][0]
		);
	}

	/**
	 * Insert-template arguments anchored after the page's only row.
	 *
	 * @param int $id Post.
	 * @return array<string,mixed>
	 */
	private static function insert_args( int $id ): array {
		return array(
			'post_id'          => $id,
			'page_fingerprint' => self::fp( self::PAGE ),
			'template_id'      => 'hero_1',
			'anchor_index'     => 0,
			'anchor_expect'    => array(
				'tag'         => 'vc_row',
				'fingerprint' => self::fp( self::PAGE ),
			),
			'placement'        => 'after',
		);
	}

	public function test_insert_template_round_trips_over_a_real_tools_call(): void {
		list( $handler, $id ) = $this->wired( 'editor' );
		$done                 = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-insert-template' ),
					'arguments' => self::insert_args( $id ),
				),
				'req-wpb-template-insert-1'
			)
		);
		$stored               = self::PAGE . self::TEMPLATE;
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'inserted', 'restore_revision_id', 'reversible', 'undo' ), array_keys( $done ) );
		$this->assertSame( $id, $done['post']['id'] );
		$this->assertArrayNotHasKey( 'post_content', $done['post'] );
		$this->assertSame( 3, $done['index'] );
		$this->assertSame( self::fp( self::TEMPLATE ), $done['fingerprint'] );
		$this->assertSame( self::fp( $stored ), $done['page_fingerprint'] );
		$this->assertSame( 3, $done['inserted'] );
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
				'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-insert-template' ),
				'arguments' => self::insert_args( $id ),
			),
			'req-wpb-template-insert-2'
		);
		$this->assertTrue( $refused->getIsError() );
		$this->assertSame( aafm_builder_error( 'aafm_builder_page_changed' )->get_error_message(), $refused->getContent()[0]->getText() );
		$this->assertSame( $stored, get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_insert_template_reversible_reason_is_on_the_wire_only_when_the_restore_point_is_gone(): void {
		list( $handler, $id ) = $this->wired( 'editor' );
		$one                  = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $one );
		$done                 = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-insert-template' ),
					'arguments' => self::insert_args( $id ),
				),
				'req-wpb-template-insert-limit'
			)
		);
		remove_filter( 'wp_revisions_to_keep', $one );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'inserted', 'restore_revision_id', 'reversible', 'reversible_reason', 'undo' ), array_keys( $done ) );
		$this->assertNull( $done['restore_revision_id'] );
		$this->assertFalse( $done['reversible'] );
		$this->assertStringContainsString( 'no longer there after the save', $done['reversible_reason'] );
	}

	public function test_update_page_code_round_trips_over_a_real_tools_call(): void {
		$access = function ( $resolved, string $entry ) {
			return 'wpbakery_user_access' === $entry ? fn() => new class() {
				/**
				 * Part.
				 *
				 * @return self
				 */
				public function part(): self {
					return $this;
				}

				/**
				 * State check.
				 *
				 * @return self
				 */
				public function checkStateAny(): self { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WPBakery's own method name.
					return $this;
				}

				/**
				 * Result.
				 *
				 * @return bool
				 */
				public function get(): bool {
					return true;
				}
			} : $resolved;
		};
		add_filter( 'aafm_builder_vendor', $access, 20, 2 );
		list( $handler, $id ) = $this->wired();
		update_post_meta( $id, '_wpb_post_custom_css', '.old{}' );
		$args = array(
			'post_id'          => $id,
			'custom_css'       => '.title{display:none}',
			'custom_js_footer' => 'console.info(1);',
		);
		$done = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-update-page-code' ),
					'arguments' => $args,
				),
				'req-wpb-page-code-1'
			)
		);
		$this->assertSame(
			array(
				'post_id'  => $id,
				'changed'  => array( 'custom_css', 'custom_js_footer' ),
				'previous' => array(
					'custom_css'       => '.old{}',
					'custom_js_footer' => '',
				),
				'undo'     => array(
					'undoable'   => false,
					'change_ids' => array(),
					'reason'     => 'not_covered',
				),
			),
			$done
		);
		$this->assertSame( '.title{display:none}', get_post_meta( $id, '_wpb_post_custom_css', true ) );
		$this->assertSame( 'console.info(1);', get_post_meta( $id, '_wpb_post_custom_js_footer', true ) );

		$again = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-update-page-code' ),
					'arguments' => $args,
				),
				'req-wpb-page-code-2'
			)
		);
		$this->assertSame( array(), $again['changed'] );
		$this->assertSame( array(), $again['previous'] );
		remove_filter( 'aafm_builder_vendor', $access, 20 );
	}

	public function test_update_page_code_is_absent_from_tools_list_without_unfiltered_html(): void {
		list( $handler, , $server ) = $this->wired( 'editor' );
		$editor                     = wp_get_current_user();
		$names                      = static fn(): array => array_map( static fn( $tool ) => $tool->getName(), aafm_filter_mcp_tools_list( $handler->list_tools()->getTools(), $server ) );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/wpbakery-update-page-code' ), $names() );
		$editor->add_cap( 'unfiltered_html', false );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$visible = $names();
		$this->assertNotContains( aafm_mcp_tool_name( 'aafm/wpbakery-update-page-code' ), $visible );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/wpbakery-insert-template' ), $visible );
	}
}
