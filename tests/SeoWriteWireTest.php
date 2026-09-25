<?php
/**
 * Wire bodies for the Yoast SEO and Rank Math update abilities: the added `status` and `keys`,
 * a clear that Yoast stores as no row, the empty patch, update-schema, and the error each
 * returns when a write does not land.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use AAFM\Tests\Support\QueryFaultInjector;

final class SeoWriteWireTest extends TestCase {

	use IntegrationStubs;

	private const ABILITIES = array(
		'aafm/yoast-update-post',
		'aafm/rankmath-update-post',
		'aafm/rankmath-update-schema',
	);

	/**
	 * Tools handler for the test server.
	 *
	 * @var \WP\MCP\Handlers\Tools\ToolsHandler
	 */
	private $handler;

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
		$this->force_integration( 'yoast' );
		$this->force_integration( 'rankmath' );
		$this->stub_yoast();
		$this->stub_rankmath();
		aafm_registry_cache_should_flush( true );
		$this->acting_as( 'administrator' );
		$this->handler = $this->handler();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		aafm_registry_cache_should_flush( true );
		parent::tear_down();
	}

	private function handler(): \WP\MCP\Handlers\Tools\ToolsHandler {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-seo-write-wire-test-' . $counter;

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( self::ABILITIES );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$tools   = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( self::ABILITIES ) );
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (SEO write wire test)',
					'Test-only server carrying the SEO update abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only SEO write wire server.' );
		}
		return new \WP\MCP\Handlers\Tools\ToolsHandler( $server );
	}

	/**
	 * Call a tool and return its result.
	 *
	 * @param string              $ability   Ability name.
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return \WP\McpSchema\Server\Tools\DTO\CallToolResult
	 */
	private function call( string $ability, array $arguments ) {
		$result = $this->handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( $ability ),
				'arguments' => $arguments,
			),
			'req-seo-write-wire'
		);
		$this->assertInstanceOf( \WP\McpSchema\Server\Tools\DTO\CallToolResult::class, $result );
		return $result;
	}

	/**
	 * The tool's JSON text decoded as arrays, after asserting the call succeeded.
	 *
	 * @param \WP\McpSchema\Server\Tools\DTO\CallToolResult $result Tool result.
	 * @return array<string,mixed>
	 */
	private function body( $result ): array {
		$text = $result->getContent()[0]->getText();
		$this->assertFalse( $result->getIsError(), $text );
		return (array) json_decode( $text, true );
	}

	/**
	 * The Yoast read shape for a post, with the given fields set.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string,string> $fields  Field => value.
	 * @return array<string,mixed>
	 */
	private function yoast_shape( int $post_id, array $fields ): array {
		$shape = array(
			'plugin'  => 'yoast',
			'post_id' => $post_id,
		);
		foreach ( array( 'title', 'description', 'focus_keyword', 'canonical', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image', 'robots_noindex', 'robots_nofollow', 'robots_adv' ) as $field ) {
			$shape[ $field ] = $fields[ $field ] ?? '';
		}
		return $shape;
	}

	public function test_yoast_update_bodies_on_the_wire(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_yoast_wpseo_title', 'Old' );
		$args = array(
			'post_id'     => $post_id,
			'title'       => 'New',
			'description' => 'D',
		);

		$written = $this->body( $this->call( 'aafm/yoast-update-post', $args ) );
		$this->assertSame(
			$this->yoast_shape(
				$post_id,
				array(
					'title'       => 'New',
					'description' => 'D',
				)
			) + array(
				'status' => 'written',
				'keys'   => array(
					'_yoast_wpseo_title'    => array(
						'status'   => 'written',
						'previous' => 'Old',
					),
					'_yoast_wpseo_metadesc' => array( 'status' => 'written' ),
				),
			),
			$written
		);

		$unchanged = $this->body( $this->call( 'aafm/yoast-update-post', $args ) );
		$this->assertSame( 'unchanged', $unchanged['status'] );
		$this->assertSame(
			array(
				'_yoast_wpseo_title'    => array(
					'status'   => 'unchanged',
					'previous' => 'New',
				),
				'_yoast_wpseo_metadesc' => array(
					'status'   => 'unchanged',
					'previous' => 'D',
				),
			),
			$unchanged['keys']
		);
	}

	public function test_a_yoast_clear_the_site_stores_as_no_row_on_the_wire(): void {
		require_once AAFM_PLUGIN_DIR . 'tests/stubs/WpseoMetaDouble.php';
		WpseoMetaDouble::attach();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_yoast_wpseo_title', 'New' );
		update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );

		$cleared = $this->body(
			$this->call(
				'aafm/yoast-update-post',
				array(
					'post_id' => $post_id,
					'title'   => '',
				)
			)
		);
		$again   = $this->body(
			$this->call(
				'aafm/yoast-update-post',
				array(
					'post_id' => $post_id,
					'title'   => '',
				)
			)
		);
		$noindex = $this->body(
			$this->call(
				'aafm/yoast-update-post',
				array(
					'post_id'        => $post_id,
					'robots_noindex' => '0',
				)
			)
		);
		WpseoMetaDouble::detach();

		$this->assertSame( 'written', $cleared['status'] );
		$this->assertSame( '', $cleared['title'] );
		$this->assertSame(
			array(
				'_yoast_wpseo_title' => array(
					'status'   => 'written',
					'previous' => 'New',
				),
			),
			$cleared['keys']
		);
		$this->assertSame( 'unchanged', $again['status'] );
		$this->assertSame( array( '_yoast_wpseo_title' => array( 'status' => 'unchanged' ) ), $again['keys'] );
		$this->assertSame(
			array(
				'_yoast_wpseo_meta-robots-noindex' => array(
					'status'   => 'written',
					'previous' => '1',
				),
			),
			$noindex['keys']
		);
	}

	/**
	 * A clear whose read-back load fails is still confirmed by the failure-aware read, and the
	 * response is built from the stored meta, not from the empty set the failed load left.
	 *
	 * @dataProvider data_fault_shapes
	 * @param string $shape Fault shape.
	 */
	public function test_a_confirmed_yoast_clear_after_a_failed_read_back_answers_with_the_stored_fields( string $shape ): void {
		global $wpdb;
		require_once AAFM_PLUGIN_DIR . 'tests/stubs/WpseoMetaDouble.php';
		WpseoMetaDouble::attach();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_yoast_wpseo_title', 'Old' );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'D' );

		$fault = null;
		$arm   = static function ( $check, $object_id, $meta_key ) use ( &$fault, $shape, $wpdb ) {
			if ( true === $check && '_yoast_wpseo_title' === $meta_key && null === $fault ) {
				$needle = array( 'meta_key, meta_value FROM', $wpdb->postmeta, ' IN (' );
				$fault  = 'no-flush' === $shape ? QueryFaultInjector::no_flush_filter( $needle, 1 ) : QueryFaultInjector::real_error_filter( $needle, 1 );
				add_filter( 'query', $fault );
			}
			return $check;
		};
		add_filter( 'update_post_metadata', $arm, 11, 3 );

		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = wp_get_ability( 'aafm/yoast-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => '',
			)
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'update_post_metadata', $arm, 11 );
		if ( null !== $fault ) {
			remove_filter( 'query', $fault );
		}
		WpseoMetaDouble::detach();

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertIsArray( $result );
		$this->assertSame( 'written', $result['status'] );
		$this->assertSame( 'D', $result['description'] );
		$cached = wp_cache_get( $post_id, 'post_meta' );
		$this->assertIsArray( $cached );
		$this->assertSame( array( 'D' ), $cached['_yoast_wpseo_metadesc'] );
	}

	/**
	 * Both fault shapes.
	 *
	 * @return iterable<string,array{0:string}>
	 */
	public function data_fault_shapes(): iterable {
		yield 'no-flush' => array( 'no-flush' );
		yield 'real-error' => array( 'real-error' );
	}

	public function test_rankmath_update_lists_the_companion_keys_on_the_wire(): void {
		$post_id = self::factory()->post->create();
		$att     = (int) self::factory()->attachment->create_object( 'rm-wire.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );

		$twitter = $this->body(
			$this->call(
				'aafm/rankmath-update-post',
				array(
					'post_id'       => $post_id,
					'twitter_title' => 'T',
				)
			)
		);
		$og      = $this->body(
			$this->call(
				'aafm/rankmath-update-post',
				array(
					'post_id'  => $post_id,
					'og_image' => (string) wp_get_attachment_url( $att ),
				)
			)
		);

		$this->assertSame( 'rankmath', $twitter['plugin'] );
		$this->assertSame( 'T', $twitter['twitter_title'] );
		$this->assertSame( 'written', $twitter['status'] );
		$this->assertSame(
			array(
				'rank_math_twitter_title'        => array( 'status' => 'written' ),
				'rank_math_twitter_use_facebook' => array( 'status' => 'written' ),
			),
			$twitter['keys']
		);
		$this->assertSame( array( 'rank_math_facebook_image', 'rank_math_facebook_image_id' ), array_keys( $og['keys'] ) );
	}

	public function test_a_rankmath_robots_array_baseline_leaves_previous_off_the_wire(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'rank_math_robots', array( 'index' ) );

		$body = $this->body(
			$this->call(
				'aafm/rankmath-update-post',
				array(
					'post_id' => $post_id,
					'robots'  => 'noindex',
				)
			)
		);

		$this->assertSame( array( 'rank_math_robots' => array( 'status' => 'written' ) ), $body['keys'] );
	}

	public function test_an_empty_patch_answers_unchanged_with_an_empty_keys_object(): void {
		$post_id = self::factory()->post->create();
		foreach ( array( 'aafm/yoast-update-post', 'aafm/rankmath-update-post' ) as $ability ) {
			$result = $this->call( $ability, array( 'post_id' => $post_id ) );
			$text   = $result->getContent()[0]->getText();
			$this->assertFalse( $result->getIsError(), $text );
			$this->assertStringContainsString( '"status":"unchanged","keys":{}', $text, $ability );
		}
	}

	public function test_a_partial_rankmath_write_says_so_on_the_wire(): void {
		$post_id = self::factory()->post->create();
		$att     = (int) self::factory()->attachment->create_object( 'rm-partial.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		$veto    = static function ( $check, $object_id, $meta_key ) {
			return 'rank_math_facebook_image_id' === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $veto, 10, 3 );
		$result = $this->call(
			'aafm/rankmath-update-post',
			array(
				'post_id'  => $post_id,
				'og_image' => (string) wp_get_attachment_url( $att ),
			)
		);
		remove_filter( 'update_post_metadata', $veto, 10 );

		$this->assertTrue( $result->getIsError() );
		$this->assertStringContainsString( 'Some of the SEO fields were saved and some were not; read the post to see its current state.', $result->getContent()[0]->getText() );
	}

	public function test_a_yoast_write_the_site_refuses_returns_the_vendor_code_with_the_first_key(): void {
		$post_id = self::factory()->post->create();
		add_filter( 'update_post_metadata', '__return_false' );
		$error = wp_get_ability( 'aafm/yoast-update-post' )->execute(
			array(
				'post_id'     => $post_id,
				'title'       => 'New',
				'description' => 'D',
			)
		);
		remove_filter( 'update_post_metadata', '__return_false' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'aafm_yoast_write_unconfirmed', $error->get_error_code() );
		$this->assertSame( 'The site refused or failed the write; read the key to see its current state.', $error->get_error_message() );
		$this->assertSame(
			array(
				'status'    => 'refused',
				'kind'      => 'post_meta',
				'object_id' => $post_id,
				'key'       => '_yoast_wpseo_title',
			),
			$error->get_error_data()
		);
	}

	public function test_update_schema_bodies_on_the_wire(): void {
		$post_id  = self::factory()->post->create();
		$args     = array(
			'post_id' => $post_id,
			'type'    => 'Article',
			'schema'  => array( 'headline' => 'H' ),
		);
		$expected = array(
			'post_id' => $post_id,
			'type'    => 'Article',
			'schema'  => array( 'headline' => 'H' ),
		);

		$this->assertSame( $expected, $this->body( $this->call( 'aafm/rankmath-update-schema', $args ) ) );
		$this->assertSame( $expected, $this->body( $this->call( 'aafm/rankmath-update-schema', $args ) ) );
	}

	public function test_update_schema_answers_with_what_core_reads(): void {
		$post_id = self::factory()->post->create();
		$args    = array(
			'post_id' => $post_id,
			'type'    => 'Article',
			'schema'  => array( 'headline' => 'H' ),
		);
		$this->body( $this->call( 'aafm/rankmath-update-schema', $args ) );

		// Core takes the first element of a short-circuit value for a single read.
		$read_filter = static function ( $value, $object_id, $meta_key ) {
			return 'rank_math_schema_Article' === $meta_key ? array( array( 'headline' => 'Filtered' ) ) : $value;
		};
		add_filter( 'get_post_metadata', $read_filter, 10, 3 );
		$unchanged = $this->body( $this->call( 'aafm/rankmath-update-schema', $args ) );
		remove_filter( 'get_post_metadata', $read_filter, 10 );

		$this->assertSame( array( 'headline' => 'Filtered' ), $unchanged['schema'] );
	}
}
