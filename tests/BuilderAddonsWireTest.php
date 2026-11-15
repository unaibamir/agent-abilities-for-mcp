<?php
/**
 * Wire-level proof for the builder add-on tools (plan 326 Session 14): Avada Slider settings and the Templatera list
 * and reference over a real tools/call, with the exact wire body asserted. Vendors are seam stubs (Ruling B14).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use AAFM\Tests\Support\WpbakeryMapStub;

require_once __DIR__ . '/Support/WpbakeryMapStub.php';

final class BuilderAddonsWireTest extends TestCase {

	private const TOOLS = array( 'aafm/avada-update-slider-settings', 'aafm/wpbakery-list-templates', 'aafm/wpbakery-insert-template' );

	private const PAGE = '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text][/vc_column][/vc_row]';

	/**
	 * The avada_term_meta seam filter.
	 *
	 * @var callable|null
	 */
	private $seam;

	/**
	 * The slider's stored settings, as the stand-in term meta object keeps them.
	 *
	 * @var array<string,mixed>
	 */
	private $db = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		add_filter( 'aafm_templatera_active', '__return_true' );
		WpbakeryMapStub::install(
			WpbakeryMapStub::map() + array(
				'templatera' => array(
					'name'     => 'Templatera',
					'base'     => 'templatera',
					'category' => 'Content',
					'params'   => array(
						array(
							'type'       => 'dropdown',
							'param_name' => 'id',
						),
					),
				),
			)
		);
		register_post_type(
			'templatera',
			array(
				'public'          => false,
				'capability_type' => 'post',
			)
		);
		register_post_type( 'slide', array( 'public' => true ) );
		register_taxonomy( 'slide-page', 'slide' );
		$this->db   = array(
			'slider_width' => '100%',
			'autoplay'     => '1',
		);
		$db         = &$this->db;
		$this->seam = function ( $resolved, string $entry ) use ( &$db ) {
			if ( 'avada_term_meta' !== $entry ) {
				return $resolved;
			}
			return function ( int $id ) use ( &$db ) {
				unset( $id );
				return new class( $db ) {
					/**
					 * Stored settings.
					 *
					 * @var array<string,mixed>
					 */
					private $db;
					/**
					 * Constructor.
					 *
					 * @param array<string,mixed> $db Stored settings, by reference.
					 */
					public function __construct( array &$db ) {
						$this->db = &$db;
					}
					/**
					 * Avada's getter.
					 *
					 * @return array<string,mixed>
					 */
					public function get_all_meta(): array {
						return $this->db;
					}
					/**
					 * Avada's setter.
					 *
					 * @param string $key   Key.
					 * @param mixed  $value Value.
					 * @return bool
					 */
					public function set( string $key, $value ): bool {
						$this->db[ $key ] = $value;
						return true;
					}
				};
			};
		};
		add_filter( 'aafm_builder_vendor', $this->seam, 20, 2 );
		aafm_flush_registry_cache();
	}

	public function tear_down(): void {
		remove_filter( 'aafm_builder_vendor', $this->seam, 20 );
		WpbakeryMapStub::remove();
		unregister_taxonomy( 'slide-page' );
		unregister_post_type( 'slide' );
		unregister_post_type( 'templatera' );
		remove_filter( 'aafm_templatera_active', '__return_true' );
		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * A throwaway MCP server carrying only the add-on tools, and a handler for it.
	 *
	 * @param string $role Role to act as.
	 * @return \AAFM\Tests\Support\McpToolsHandlerShim
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function handler( string $role = 'administrator' ): \AAFM\Tests\Support\McpToolsHandlerShim {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-builder-addons-wire-test-' . $counter;
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( self::TOOLS );
		$this->acting_as( $role );
		$tools   = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( self::TOOLS ) );
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (builder add-ons wire test)',
					'Test-only server carrying only the builder add-on abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only builder add-ons wire server.' );
		}
		return new \AAFM\Tests\Support\McpToolsHandlerShim( $server );
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

	public function test_slider_settings_round_trip_over_a_real_tools_call(): void {
		$term    = (int) self::factory()->term->create( array( 'taxonomy' => 'slide-page' ) );
		$handler = $this->handler();
		$raw     = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/avada-update-slider-settings' ),
				'arguments' => array(
					'term_id' => $term,
					'set'     => array(
						'slider_width' => '80%',
						'autoplay'     => false,
						'animation'    => 'slide',
					),
				),
			),
			'req-slider-1'
		);
		$this->assertSame(
			array(
				'term_id'  => $term,
				'changed'  => array( 'slider_width', 'autoplay', 'animation' ),
				'previous' => array(
					'slider_width' => '100%',
					'autoplay'     => '1',
					'animation'    => null,
				),
				'undo'     => array(
					'undoable'   => false,
					'change_ids' => array(),
					'reason'     => 'not_covered',
				),
			),
			$this->wire( $raw )
		);
		$this->assertSame(
			array(
				'slider_width' => '80%',
				'autoplay'     => '0',
				'animation'    => 'slide',
			),
			$this->db
		);
	}

	public function test_a_slider_key_outside_the_list_is_refused_on_the_wire(): void {
		$term    = (int) self::factory()->term->create( array( 'taxonomy' => 'slide-page' ) );
		$handler = $this->handler();
		$refused = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/avada-update-slider-settings' ),
				'arguments' => array(
					'term_id' => $term,
					'set'     => array( 'orderby' => 'date' ),
				),
			),
			'req-slider-2'
		);
		$this->assertTrue( $refused->getIsError() );
		$this->assertSame( aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', 'orderby' )->get_error_message(), $refused->getContent()[0]->getText() );
		$this->assertArrayNotHasKey( 'orderby', $this->db );
	}

	public function test_a_templatera_reference_round_trips_over_a_real_tools_call(): void {
		$this->acting_as( 'administrator' );
		$template = self::factory()->post->create(
			array(
				'post_type'    => 'templatera',
				'post_status'  => 'publish',
				'post_title'   => 'Wire template',
				'post_content' => '[vc_row][vc_column][vc_column_text]<p>T</p>[/vc_column_text][/vc_column][/vc_row]',
			)
		);
		$page     = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => self::PAGE,
			)
		);
		$handler  = $this->handler( 'editor' );
		$list     = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-list-templates' ),
					'arguments' => array( 'for_post_id' => $page ),
				),
				'req-templatera-1'
			)
		);
		$this->assertSame(
			array(
				'templates'         => array(
					array(
						'id'     => (string) $template,
						'source' => 'templatera',
						'name'   => 'Wire template',
					),
				),
				'total'             => 1,
				'templatera_active' => true,
			),
			$list
		);
		$elements = aafm_builder_elements( self::PAGE, aafm_wpbakery_shortcode_tags( self::PAGE ) );
		$out      = $this->wire(
			$handler->call_tool(
				array(
					'name'      => aafm_mcp_tool_name( 'aafm/wpbakery-insert-template' ),
					'arguments' => array(
						'post_id'          => $page,
						'page_fingerprint' => aafm_builder_page_fingerprint( self::PAGE ),
						'template_id'      => (string) $template,
						'source'           => 'templatera',
						'mode'             => 'reference',
						'anchor_index'     => 2,
						'anchor_expect'    => array(
							'tag'         => 'vc_column_text',
							'fingerprint' => aafm_builder_fingerprint( self::PAGE, $elements[2] ),
						),
						'placement'        => 'after',
					),
				),
				'req-templatera-2'
			)
		);
		$stored   = '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text][templatera id="' . $template . '"][/vc_column][/vc_row]';
		$this->assertSame( $stored, get_post_field( 'post_content', $page, 'raw' ) );
		$this->assertSame( 3, $out['index'] );
		$this->assertSame( 1, $out['inserted'] );
		$this->assertSame( aafm_builder_page_fingerprint( $stored ), $out['page_fingerprint'] );
		$this->assertArrayNotHasKey( 'post_content', $out['post'] );
	}
}
