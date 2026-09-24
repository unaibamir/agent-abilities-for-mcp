<?php
/**
 * Wire-level bodies of the meta write abilities: the exact structured content a real tools/call
 * returns for each outcome, with the keys that must be absent, and the exact error text.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class MetaWriteWireTest extends TestCase {

	private const ABILITIES = array(
		'aafm/get-post-meta',
		'aafm/update-post-meta',
		'aafm/delete-post-meta',
	);

	/**
	 * Build a throwaway MCP server over the given abilities and return its tools handler.
	 *
	 * @param string[] $abilities Ability names.
	 * @return \WP\MCP\Handlers\Tools\ToolsHandler
	 * @throws \RuntimeException When the adapter refuses to build the server.
	 */
	private function handler( array $abilities ): \WP\MCP\Handlers\Tools\ToolsHandler {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-meta-write-wire-test-' . $counter;

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( $abilities );

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$tools   = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( $abilities ) );
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (meta write wire test)',
					'Test-only server carrying the meta write abilities.',
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
			throw new \RuntimeException( 'Failed to build the test-only meta write wire server.' );
		}
		return new \WP\MCP\Handlers\Tools\ToolsHandler( $server );
	}

	/**
	 * Call one tool and return the result DTO.
	 *
	 * @param \WP\MCP\Handlers\Tools\ToolsHandler $handler   Handler.
	 * @param string                              $ability   Ability name.
	 * @param array<string,mixed>                 $arguments Arguments.
	 * @return \WP\McpSchema\Server\Tools\DTO\CallToolResult
	 */
	private function call( \WP\MCP\Handlers\Tools\ToolsHandler $handler, string $ability, array $arguments ) {
		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( $ability ),
				'arguments' => $arguments,
			),
			'req-meta-write-wire'
		);
		$this->assertInstanceOf( \WP\McpSchema\Server\Tools\DTO\CallToolResult::class, $result );
		return $result;
	}

	/**
	 * An editor's post with `aafm_note` allowlisted.
	 *
	 * @return int Post id.
	 */
	private function note_post(): int {
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_note' ) );
		$this->acting_as( 'editor' );
		return self::factory()->post->create();
	}

	public function test_post_meta_bodies_on_the_wire(): void {
		$id      = $this->note_post();
		$handler = $this->handler( self::ABILITIES );
		update_post_meta( $id, 'aafm_note', 'old' );

		$args = array(
			'post_id'  => $id,
			'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument, not a meta query.
		);

		$written = $this->call( $handler, 'aafm/update-post-meta', $args + array( 'value' => 'new' ) );
		$this->assertFalse( $written->getIsError(), $written->getContent()[0]->getText() );
		$this->assertSame(
			array(
				'post_id'      => $id,
				'meta_key'     => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response key.
				'value'        => 'new',
				'status'       => 'written',
				'previous'     => 'old',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => true,
					'count'  => 1,
				),
			),
			$written->getStructuredContent()
		);

		$unchanged = $this->call( $handler, 'aafm/update-post-meta', $args + array( 'value' => 'new' ) );
		$this->assertSame(
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response key.
				'value'    => 'new',
				'status'   => 'unchanged',
				'previous' => 'new',
			),
			$unchanged->getStructuredContent()
		);

		$read = $this->call( $handler, 'aafm/get-post-meta', $args );
		$this->assertSame(
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response key.
				'value'    => 'new',
			),
			$read->getStructuredContent()
		);

		$deleted = $this->call( $handler, 'aafm/delete-post-meta', $args );
		$this->assertSame(
			array(
				'deleted'      => true,
				'status'       => 'deleted',
				'previous'     => 'new',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => false,
					'count'  => 0,
				),
			),
			$deleted->getStructuredContent()
		);

		$absent = $this->call( $handler, 'aafm/delete-post-meta', $args );
		$this->assertSame(
			array(
				'deleted' => true,
				'status'  => 'absent',
			),
			$absent->getStructuredContent()
		);
	}

	public function test_a_refused_post_meta_write_is_an_error_with_the_status_message_on_the_wire(): void {
		$id      = $this->note_post();
		$handler = $this->handler( self::ABILITIES );
		update_post_meta( $id, 'aafm_note', 'old' );

		add_filter( 'update_post_metadata', '__return_false' );
		$refused = $this->call(
			$handler,
			'aafm/update-post-meta',
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'value'    => 'new',
			)
		);
		remove_filter( 'update_post_metadata', '__return_false' );

		$this->assertTrue( $refused->getIsError() );
		$this->assertNull( $refused->getStructuredContent() );
		$this->assertSame( 'The site refused or failed the write; read the key to see its current state.', $refused->getContent()[0]->getText() );
	}

	public function test_a_meta_write_error_carries_identifiers_only(): void {
		$id = $this->note_post();
		update_post_meta( $id, 'aafm_note', 'secret-old-value' );

		add_filter( 'update_post_metadata', '__return_false' );
		$error = aafm_exec_update_post_meta(
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ability input.
				'value'    => 'secret-new-value',
			)
		);
		remove_filter( 'update_post_metadata', '__return_false' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$data = $error->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( array( 'status', 'kind', 'object_id', 'key' ), array_keys( $data ) );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $error->get_error_data() ) );
	}

	/**
	 * Call create-post over the wire as an editor with the given extra arguments.
	 *
	 * @param array<string,mixed> $extra Extra arguments.
	 * @return \WP\McpSchema\Server\Tools\DTO\CallToolResult
	 */
	private function create_post( array $extra ) {
		$this->acting_as( 'editor' );
		$handler = $this->handler( array( 'aafm/create-post' ) );
		return $this->call( $handler, 'aafm/create-post', array( 'title' => 'Enrichment wire test' ) + $extra );
	}

	public function test_a_create_with_no_enrichment_input_has_no_enrichment_key(): void {
		$result = $this->create_post( array() );

		$this->assertFalse( $result->getIsError() );
		$this->assertArrayNotHasKey( 'enrichment', $result->getStructuredContent() );
	}

	public function test_a_vetoed_enrichment_meta_key_reports_refused_while_the_post_saves(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_note', 'aafm_other' ) );
		$veto = static function ( $check, $object_id, $meta_key ) {
			return 'aafm_other' === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $veto, 10, 3 );
		$result = $this->create_post(
			array(
				'meta' => array(
					'aafm_note'  => 'kept',
					'aafm_other' => 'vetoed',
				),
			)
		);
		remove_filter( 'update_post_metadata', $veto, 10 );

		$this->assertFalse( $result->getIsError() );
		$body = $result->getStructuredContent();
		$this->assertArrayHasKey( 'enrichment', $body );
		$this->assertSame(
			'{"meta":{"aafm_note":"written","aafm_other":"refused"}}',
			wp_json_encode( $body['enrichment'] )
		);
		$post_id = (int) $body['post']['id'];
		$this->assertSame( 'Enrichment wire test', get_post( $post_id )->post_title );
		$this->assertSame( 'kept', get_post_meta( $post_id, 'aafm_note', true ) );
		$this->assertSame( '', get_post_meta( $post_id, 'aafm_other', true ) );
	}

	public function test_a_meta_value_coerced_to_an_array_refuses_the_whole_create_before_the_post_exists(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_note' ) );
		$coerce = static function () {
			return array( 'evil' => 1 );
		};
		add_filter( 'sanitize_post_meta_aafm_note', $coerce );
		$before = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'post' )->draft;
		$result = $this->create_post( array( 'meta' => array( 'aafm_note' => 'x' ) ) );
		remove_filter( 'sanitize_post_meta_aafm_note', $coerce );
		wp_cache_flush_group( 'counts' );
		$after = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'post' )->draft;

		$this->assertTrue( $result->getIsError() );
		$this->assertSame( $before, $after );
	}

	/**
	 * Core's update_metadata() refuses a meta key of '0' outright (it tests `! $meta_key`), so
	 * the key reports refused. What this pins is the shape: a map keyed '0' encodes as a JSON
	 * object, never a list.
	 */
	public function test_an_enrichment_meta_key_of_zero_comes_back_as_a_json_object(): void {
		update_option( 'aafm_allowed_meta_keys', array( '0' ) );
		$result = $this->create_post( array( 'meta' => array( '0' => 'v' ) ) );

		$this->assertFalse( $result->getIsError() );
		$this->assertArrayHasKey( 'enrichment', $result->getStructuredContent() );
		$this->assertSame( '{"meta":{"0":"refused"}}', wp_json_encode( $result->getStructuredContent()['enrichment'] ) );
	}

	public function test_enrichment_reports_terms_and_featured_image(): void {
		$category = self::factory()->category->create();
		$image    = self::factory()->attachment->create_object(
			'image.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		$result   = $this->create_post(
			array(
				'terms'          => array( 'category' => array( $category ) ),
				'featured_media' => $image,
			)
		);

		$this->assertFalse( $result->getIsError(), $result->getContent()[0]->getText() );
		$this->assertArrayHasKey( 'enrichment', $result->getStructuredContent() );
		$this->assertSame(
			'{"terms":{"category":"written"},"featured_media":"written"}',
			wp_json_encode( $result->getStructuredContent()['enrichment'] )
		);
	}

	public function test_a_create_page_with_enrichment_input_returns_enrichment(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_note' ) );
		$this->acting_as( 'editor' );
		$handler = $this->handler( array( 'aafm/create-page' ) );
		$result  = $this->call(
			$handler,
			'aafm/create-page',
			array(
				'title' => 'Enriched page',
				'meta'  => array( 'aafm_note' => 'v' ),
			)
		);

		$this->assertFalse( $result->getIsError(), $result->getContent()[0]->getText() );
		$this->assertArrayHasKey( 'enrichment', $result->getStructuredContent() );
		$this->assertSame( '{"meta":{"aafm_note":"written"}}', wp_json_encode( $result->getStructuredContent()['enrichment'] ) );
	}

	public function test_term_meta_bodies_on_the_wire(): void {
		update_option( 'aafm_exposed_term_meta_keys', array( 'aafm_note' ) );
		$this->acting_as( 'editor' );
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term_id, 'aafm_note', 'old' );
		$handler = $this->handler( array( 'aafm/update-term-meta', 'aafm/delete-term-meta' ) );
		$args    = array(
			'taxonomy' => 'category',
			'term_id'  => $term_id,
			'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
		);

		$written = $this->call( $handler, 'aafm/update-term-meta', $args + array( 'value' => 'new' ) );
		$this->assertSame(
			array(
				'term_id'      => $term_id,
				'meta_key'     => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response key.
				'value'        => 'new',
				'status'       => 'written',
				'previous'     => 'old',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => true,
					'count'  => 1,
				),
			),
			$written->getStructuredContent()
		);

		$deleted = $this->call( $handler, 'aafm/delete-term-meta', $args );
		$this->assertSame(
			array(
				'deleted'      => true,
				'status'       => 'deleted',
				'previous'     => 'new',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => false,
					'count'  => 0,
				),
			),
			$deleted->getStructuredContent()
		);

		add_filter( 'update_term_metadata', '__return_false' );
		$refused = $this->call( $handler, 'aafm/update-term-meta', $args + array( 'value' => 'again' ) );
		remove_filter( 'update_term_metadata', '__return_false' );
		$this->assertTrue( $refused->getIsError() );
		$this->assertSame( 'The site refused or failed the write; read the key to see its current state.', $refused->getContent()[0]->getText() );
	}

	public function test_user_meta_bodies_on_the_wire(): void {
		update_option( 'aafm_exposed_user_meta_keys', array( 'aafm_note' ) );
		$this->acting_as( 'administrator' );
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'aafm_note', 'old' );
		$handler = $this->handler( array( 'aafm/update-user-meta', 'aafm/delete-user-meta' ) );
		$args    = array(
			'user_id' => $user_id,
			'key'     => 'aafm_note',
		);

		$written = $this->call( $handler, 'aafm/update-user-meta', $args + array( 'value' => 'new' ) );
		$this->assertSame(
			array(
				'user_id'      => $user_id,
				'key'          => 'aafm_note',
				'value'        => 'new',
				'status'       => 'written',
				'previous'     => 'old',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => true,
					'count'  => 1,
				),
			),
			$written->getStructuredContent()
		);

		$deleted = $this->call( $handler, 'aafm/delete-user-meta', $args );
		$this->assertSame(
			array(
				'deleted'      => true,
				'status'       => 'deleted',
				'previous'     => 'new',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => false,
					'count'  => 0,
				),
			),
			$deleted->getStructuredContent()
		);

		$absent = $this->call( $handler, 'aafm/delete-user-meta', $args );
		$this->assertSame(
			array(
				'deleted' => true,
				'status'  => 'absent',
			),
			$absent->getStructuredContent()
		);
	}

	/**
	 * The three meta families: object creation, the update/delete tools and their arguments.
	 *
	 * @return iterable<string,array{0:string}>
	 */
	public function data_meta_families(): iterable {
		yield 'post' => array( 'post' );
		yield 'term' => array( 'term' );
		yield 'user' => array( 'user' );
	}

	/**
	 * An object of the family with `aafm_note` allowlisted, the handler, and the base arguments.
	 *
	 * @param string $type 'post', 'term' or 'user'.
	 * @return array{0:int,1:\WP\MCP\Handlers\Tools\ToolsHandler,2:array<string,mixed>,3:array<string,mixed>}
	 */
	private function family( string $type ): array {
		if ( 'post' === $type ) {
			update_option( 'aafm_allowed_meta_keys', array( 'aafm_note' ) );
			$this->acting_as( 'editor' );
			$id       = self::factory()->post->create();
			$args     = array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
			);
			$identity = array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response key.
			);
		} elseif ( 'term' === $type ) {
			update_option( 'aafm_exposed_term_meta_keys', array( 'aafm_note' ) );
			$this->acting_as( 'editor' );
			$id       = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
			$args     = array(
				'taxonomy' => 'category',
				'term_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
			);
			$identity = array(
				'term_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- response key.
			);
		} else {
			update_option( 'aafm_exposed_user_meta_keys', array( 'aafm_note' ) );
			$this->acting_as( 'administrator' );
			$id       = self::factory()->user->create();
			$args     = array(
				'user_id' => $id,
				'key'     => 'aafm_note',
			);
			$identity = array(
				'user_id' => $id,
				'key'     => 'aafm_note',
			);
		}
		$handler = $this->handler( array( "aafm/update-$type-meta", "aafm/delete-$type-meta" ) );
		return array( $id, $handler, $args, $identity );
	}

	/**
	 * A two-row baseline reports rows: 2 on written and on deleted, in the exact body.
	 *
	 * @dataProvider data_meta_families
	 * @param string $type Family.
	 */
	public function test_a_two_row_baseline_reports_rows_on_the_wire( string $type ): void {
		list( $id, $handler, $args, $identity ) = $this->family( $type );
		add_metadata( $type, $id, 'aafm_note', 'a' );
		add_metadata( $type, $id, 'aafm_note', 'b' );

		$written = $this->call( $handler, "aafm/update-$type-meta", $args + array( 'value' => 'c' ) );
		$this->assertSame(
			$identity + array(
				'value'        => 'c',
				'status'       => 'written',
				'previous'     => 'a',
				'rows'         => 2,
				'acknowledged' => true,
				'observed'     => array(
					'exists' => true,
					'count'  => 2,
				),
			),
			$written->getStructuredContent()
		);

		$deleted = $this->call( $handler, "aafm/delete-$type-meta", $args );
		$this->assertSame(
			array(
				'deleted'      => true,
				'status'       => 'deleted',
				'previous'     => 'c',
				'rows'         => 2,
				'acknowledged' => true,
				'observed'     => array(
					'exists' => false,
					'count'  => 0,
				),
			),
			$deleted->getStructuredContent()
		);
	}

	/**
	 * A sanitizer that gives a different result on every call stores a value that is neither the
	 * canonical form nor the baseline, reported as modified_by_site: true in the exact body.
	 *
	 * @dataProvider data_meta_families
	 * @param string $type Family.
	 */
	public function test_a_value_the_site_changed_reports_modified_by_site_on_the_wire( string $type ): void {
		list( $id, $handler, $args, $identity ) = $this->family( $type );
		add_metadata( $type, $id, 'aafm_note', 'old' );
		$calls   = 0;
		$counter = static function ( $value ) use ( &$calls ) {
			++$calls;
			return is_string( $value ) ? $value . '-' . $calls : $value;
		};
		add_filter( "sanitize_{$type}_meta_aafm_note", $counter );
		$written = $this->call( $handler, "aafm/update-$type-meta", $args + array( 'value' => 'new' ) );
		remove_filter( "sanitize_{$type}_meta_aafm_note", $counter );

		$stored = get_metadata( $type, $id, 'aafm_note', true );
		$this->assertSame(
			$identity + array(
				'value'            => $stored,
				'status'           => 'written',
				'previous'         => 'old',
				'acknowledged'     => true,
				'observed'         => array(
					'exists' => true,
					'count'  => 1,
				),
				'modified_by_site' => true,
			),
			$written->getStructuredContent()
		);
	}
}
