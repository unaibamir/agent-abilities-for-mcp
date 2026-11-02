<?php
/**
 * The aafm/replace-block ability: swap one block, addressed by its path over named blocks, and leave the rest
 * of the page as it was.
 *
 * The page is rebuilt with serialize_blocks() from its parsed tree, so every other block keeps its
 * parsed attributes and HTML, and on a page already in canonical serialized form every byte outside
 * the replaced block is unchanged. A page whose tree does not survive that round trip is refused.
 * The write is confirmed exactly the way replace-in-post confirms its own.
 *
 * Fixtures are written straight to the posts table so the stored bytes are exactly the fixture's,
 * whatever the creating user's kses filters would have done.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ReplaceBlockTest extends TestCase {

	/**
	 * A heading, a paragraph and a group holding two paragraphs, with the blank lines the editor
	 * writes between blocks (freeform chunks the path does not count). Named paths: [0] heading,
	 * [1] paragraph, [2] group, [2,0] and [2,1] its inner paragraphs.
	 */
	private const PAGE = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Title</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>First</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Inner one</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Inner two</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";

	private const NEW_PARAGRAPH = "<!-- wp:paragraph -->\n<p>Replaced</p>\n<!-- /wp:paragraph -->";

	/**
	 * Create a post and store $content in it byte for byte.
	 *
	 * @param string $content Stored post_content.
	 * @param int    $author  Author id (0 keeps the factory default).
	 * @return int Post id.
	 */
	private function post_with( string $content, int $author = 0 ): int {
		global $wpdb;
		$args = array( 'post_status' => 'draft' );
		if ( $author > 0 ) {
			$args['post_author'] = $author;
		}
		$id = self::factory()->post->create( $args );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: exact stored bytes.
		clean_post_cache( $id );
		$this->assertSame( $content, get_post( $id )->post_content, 'Fixture stored byte for byte.' );
		return $id;
	}

	private function stored( int $id ): string {
		clean_post_cache( $id );
		return (string) get_post( $id )->post_content;
	}

	/**
	 * The named blocks of a block list, freeform chunks dropped.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return list<array<string,mixed>>
	 */
	private function named( array $blocks ): array {
		return array_values( array_filter( $blocks, static fn( array $b ): bool => null !== $b['blockName'] ) );
	}

	private function admin(): int {
		$id = $this->acting_as( 'administrator' );
		$this->assertTrue( current_user_can( 'unfiltered_html' ), 'Fixture: an administrator posts unfiltered HTML.' );
		return $id;
	}

	public function test_replaces_a_top_level_block(): void {
		$this->admin();
		$id = $this->post_with( serialize_blocks( parse_blocks( self::PAGE ) ) );

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 1 ),
				'markup'  => self::NEW_PARAGRAPH,
			)
		);

		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertSame( $id, $res['post']['id'] );
		$this->assertSame(
			array(
				'path'  => array( 1 ),
				'block' => 'core/paragraph',
			),
			$res['replaced']
		);
		$named = $this->named( parse_blocks( $this->stored( $id ) ) );
		$this->assertCount( 3, $named );
		$this->assertStringContainsString( 'Replaced', $named[1]['innerHTML'] );
		$this->assertStringNotContainsString( 'First', $this->stored( $id ) );
	}

	public function test_bytes_outside_the_block_are_unchanged(): void {
		$this->admin();
		// Canonical by construction: exactly what the block editor stores.
		$page = serialize_blocks( parse_blocks( self::PAGE ) );
		$id   = $this->post_with( $page );

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 2, 1 ),
				'markup'  => self::NEW_PARAGRAPH,
			)
		);
		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );

		$old_block = serialize_blocks( parse_blocks( "<!-- wp:paragraph -->\n<p>Inner two</p>\n<!-- /wp:paragraph -->" ) );
		$new_block = serialize_blocks( parse_blocks( self::NEW_PARAGRAPH ) );
		$this->assertSame( 1, substr_count( $page, $old_block ), 'Fixture: the old block occurs once.' );
		$this->assertSame( str_replace( $old_block, $new_block, $page ), $this->stored( $id ) );
	}

	public function test_a_nested_path_replaces_the_inner_block(): void {
		$this->admin();
		$id = $this->post_with( self::PAGE );

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 2, 0 ),
				'markup'  => self::NEW_PARAGRAPH,
			)
		);
		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );

		$group = $this->named( parse_blocks( $this->stored( $id ) ) )[2];
		$this->assertSame( 'core/group', $group['blockName'] );
		$inner = $this->named( $group['innerBlocks'] );
		$this->assertCount( 2, $inner );
		$this->assertStringContainsString( 'Replaced', $inner[0]['innerHTML'] );
		$this->assertStringContainsString( 'Inner two', $inner[1]['innerHTML'] );
		$this->assertSame( array( 2, 0 ), $res['replaced']['path'] );
	}

	public function test_freeform_whitespace_is_not_counted_in_the_path(): void {
		$this->admin();
		$id = $this->post_with( self::PAGE );

		// Raw index 2 is the blank line between the heading and the paragraph; named index 2 is the group.
		$this->assertNull( parse_blocks( self::PAGE )[1]['blockName'], 'Fixture: freeform whitespace between blocks.' );
		$res = aafm_exec_replace_block(
			array(
				'post_id'      => $id,
				'path'         => array( 2 ),
				'markup'       => self::NEW_PARAGRAPH,
				'expect_block' => 'core/group',
			)
		);
		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );

		$named = $this->named( parse_blocks( $this->stored( $id ) ) );
		$this->assertCount( 3, $named );
		$this->assertSame( 'core/paragraph', $named[2]['blockName'] );
		$this->assertStringNotContainsString( 'Inner one', $this->stored( $id ) );
	}

	public function test_a_path_out_of_range_is_refused_and_names_the_count(): void {
		$this->admin();
		$id = $this->post_with( self::PAGE );

		$top = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 3 ),
				'markup'  => self::NEW_PARAGRAPH,
			)
		);
		$this->assertWPError( $top );
		$this->assertSame( 'aafm_block_path_not_found', $top->get_error_code() );
		$this->assertStringContainsString( '3', $top->get_error_message() );

		$inner = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 2, 5 ),
				'markup'  => self::NEW_PARAGRAPH,
			)
		);
		$this->assertWPError( $inner );
		$this->assertSame( 'aafm_block_path_not_found', $inner->get_error_code() );
		$this->assertStringContainsString( '2', $inner->get_error_message() );

		$this->assertSame( self::PAGE, $this->stored( $id ) );
	}

	public function test_a_post_with_no_blocks_points_at_update_post(): void {
		$this->admin();
		$id = $this->post_with( '<p>Classic content only.</p>' );

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 0 ),
				'markup'  => self::NEW_PARAGRAPH,
			)
		);
		$this->assertWPError( $res );
		$this->assertSame( 'aafm_block_path_not_found', $res->get_error_code() );
		$this->assertStringContainsString( 'aafm-update-post', $res->get_error_message() );
		$this->assertSame( '<p>Classic content only.</p>', $this->stored( $id ) );
	}

	public function test_an_expect_block_mismatch_is_refused_and_names_the_block_found(): void {
		$this->admin();
		$id = $this->post_with( self::PAGE );

		$res = aafm_exec_replace_block(
			array(
				'post_id'      => $id,
				'path'         => array( 0 ),
				'markup'       => self::NEW_PARAGRAPH,
				'expect_block' => 'core/paragraph',
			)
		);

		$this->assertWPError( $res );
		$this->assertSame( 'aafm_block_mismatch', $res->get_error_code() );
		$this->assertStringContainsString( 'core/heading', $res->get_error_message() );
		$this->assertSame( self::PAGE, $this->stored( $id ) );
	}

	public function test_a_replacement_that_is_not_exactly_one_block_is_refused(): void {
		$this->admin();
		$id = $this->post_with( self::PAGE );

		foreach ( array( self::NEW_PARAGRAPH . "\n\n" . self::NEW_PARAGRAPH, 'just some text', "\n\n" ) as $markup ) {
			$res = aafm_exec_replace_block(
				array(
					'post_id' => $id,
					'path'    => array( 1 ),
					'markup'  => $markup,
				)
			);
			$this->assertWPError( $res, $markup );
			$this->assertSame( 'aafm_replacement_not_one_block', $res->get_error_code(), $markup );
		}
		$this->assertSame( self::PAGE, $this->stored( $id ) );
	}

	public function test_a_script_in_the_replacement_is_cleaned(): void {
		$this->admin();
		$id = $this->post_with( self::PAGE );

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 1 ),
				'markup'  => '<!-- wp:html --><script>alert(1)</script><p>ok</p><!-- /wp:html -->',
			)
		);

		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertStringNotContainsString( 'alert(1)', $this->stored( $id ) );
		$this->assertStringNotContainsString( '<script', $this->stored( $id ) );
		$this->assertContains( 'removed_element', wp_list_pluck( $res['content_warnings'], 'code' ) );
	}

	public function test_a_page_written_by_1_9_0_with_an_ampersand_is_accepted(): void {
		$this->admin();
		// 1.9.0 stored attribute text kses-encoded, raw in the delimiter JSON.
		$page = "<!-- wp:acme/card {\"lead\":\"Saturday &amp; Sunday\"} /-->\n\n" . self::NEW_PARAGRAPH;
		$id   = $this->post_with( $page );

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 1 ),
				'markup'  => "<!-- wp:paragraph -->\n<p>Second</p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );

		$before = $this->named( parse_blocks( $page ) )[0];
		$after  = $this->named( parse_blocks( $this->stored( $id ) ) )[0];
		$this->assertSame( $before, $after, 'The other block parses identically.' );
		$this->assertSame( 'Saturday &amp; Sunday', $after['attrs']['lead'] );
	}

	public function test_a_page_whose_tree_does_not_round_trip_is_refused(): void {
		$this->admin();
		$page = "<!-- wp:heading {\"level\":2,} -->\n<h2 class=\"wp-block-heading\">Bad JSON</h2>\n<!-- /wp:heading -->\n\n" . self::NEW_PARAGRAPH;
		$id   = $this->post_with( $page );

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 1 ),
				'markup'  => "<!-- wp:paragraph -->\n<p>Second</p>\n<!-- /wp:paragraph -->",
			)
		);

		$this->assertWPError( $res );
		$this->assertSame( 'aafm_not_round_trippable', $res->get_error_code() );
		$this->assertStringContainsString( 'aafm-update-post', $res->get_error_message() );
		$this->assertSame( $page, $this->stored( $id ) );
	}

	public function test_unfiltered_html_markup_elsewhere_survives(): void {
		$this->admin();
		$iframe = '<iframe src="https://example.com/embed" title="Embed"></iframe>';
		$page   = serialize_blocks( parse_blocks( '<!-- wp:html -->' . $iframe . '<!-- /wp:html -->' . "\n\n" . self::NEW_PARAGRAPH ) );
		$id     = $this->post_with( $page );

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 1 ),
				'markup'  => "<!-- wp:paragraph -->\n<p>Second</p>\n<!-- /wp:paragraph -->",
			)
		);

		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertStringContainsString( $iframe, $this->stored( $id ) );
		$this->assertStringContainsString( 'Second', $this->stored( $id ) );
	}

	public function test_a_limited_user_gets_core_kses_on_the_whole_page(): void {
		$author = $this->acting_as( 'author' );
		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'Fixture: an author does not post unfiltered HTML.' );
		$page = serialize_blocks( parse_blocks( '<!-- wp:html --><iframe src="https://example.com/embed"></iframe><!-- /wp:html -->' . "\n\n" . self::NEW_PARAGRAPH ) );
		$id   = $this->post_with( $page, $author );

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 1 ),
				'markup'  => "<!-- wp:paragraph -->\n<p>Second</p>\n<!-- /wp:paragraph -->",
			)
		);

		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : 'confirmed write expected' );
		$this->assertStringNotContainsString( '<iframe', $this->stored( $id ), 'Core kses re-sanitizes the whole page for this user, as their own editor does.' );
		$this->assertStringContainsString( 'Second', $this->stored( $id ) );
	}

	public function test_a_vetoed_write_is_reported_unconfirmed(): void {
		$this->admin();
		$id = $this->post_with( self::PAGE );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ) use ( $id ): array {
				if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === $id ) {
					$data['post_content'] = wp_slash( self::PAGE );
				}
				return $data;
			},
			10,
			2
		);

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 1 ),
				'markup'  => self::NEW_PARAGRAPH,
			)
		);

		$this->assertWPError( $res );
		$this->assertSame( 'aafm_replace_block_write_unconfirmed', $res->get_error_code() );
		$this->assertSame( self::PAGE, $this->stored( $id ) );
	}

	public function test_replace_block_creates_a_revision(): void {
		$this->admin();
		$id     = $this->post_with( self::PAGE );
		$before = count( wp_get_post_revisions( $id ) );

		$res = aafm_exec_replace_block(
			array(
				'post_id' => $id,
				'path'    => array( 1 ),
				'markup'  => self::NEW_PARAGRAPH,
			)
		);

		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertGreaterThan( $before, count( wp_get_post_revisions( $id ) ) );
	}

	public function test_a_caller_without_edit_access_is_refused(): void {
		$this->register_enabled( array( 'aafm/replace-block' ) );
		$id = $this->post_with( self::PAGE );

		$this->acting_as( 'subscriber' );
		$out = wp_get_ability( 'aafm/replace-block' )->execute(
			array(
				'post_id' => $id,
				'path'    => array( 1 ),
				'markup'  => self::NEW_PARAGRAPH,
			)
		);

		$this->assertWPError( $out );
		$this->assertSame( self::PAGE, $this->stored( $id ) );
	}

	public function test_an_editor_on_a_post_they_cannot_edit_is_refused(): void {
		$this->register_enabled( array( 'aafm/replace-block' ) );
		$id = $this->post_with( self::PAGE );
		$this->acting_as( 'editor' );
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $id ): array {
				if ( 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $id ) {
					$caps[] = 'do_not_allow';
				}
				return $caps;
			},
			10,
			4
		);
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/replace-block' ), 'Fixture: the editor still discovers the tool.' );

		$this->assertFalse( aafm_perm_update_post( array( 'post_id' => $id ) ) );
		$out = wp_get_ability( 'aafm/replace-block' )->execute(
			array(
				'post_id' => $id,
				'path'    => array( 1 ),
				'markup'  => self::NEW_PARAGRAPH,
			)
		);
		$this->assertWPError( $out );
		$this->assertSame( self::PAGE, $this->stored( $id ) );
	}

	/**
	 * Builds a throwaway single-ability MCP server carrying only aafm/replace-block.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return \WP\MCP\Core\McpServer
	 * @throws \RuntimeException When the adapter refuses to build the test-only server.
	 */
	private function build_single_ability_server( \WP\MCP\Core\McpAdapter $adapter ): \WP\MCP\Core\McpServer {
		static $counter = 0;
		++$counter;
		$server_id = 'aafm-server-replace-block-wire-test-' . $counter;

		$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/replace-block' ) ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (replace-block wire test)',
					'Test-only server carrying only aafm/replace-block.',
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
			throw new \RuntimeException( 'Failed to build the test-only replace-block wire server.' );
		}
		return $server;
	}

	public function test_wire_response_keys_are_exact_with_no_content_warnings_when_there_are_none(): void {
		$id = $this->post_with( self::PAGE );
		$this->register_enabled( array( 'aafm/replace-block' ) );
		$this->acting_as( 'editor' );

		$handler = new \AAFM\Tests\Support\McpToolsHandlerShim( $this->build_single_ability_server( \WP\MCP\Core\McpAdapter::instance() ) );
		$result  = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( 'aafm/replace-block' ),
				'arguments' => array(
					'post_id' => $id,
					'path'    => array( 2, 1 ),
					'markup'  => self::NEW_PARAGRAPH,
				),
			),
			'req-replace-block-wire-1'
		);

		$this->assertNotInstanceOf( \AAFM\Tests\Support\McpProtocolError::class, $result );
		$shape = $result->getStructuredContent();
		$this->assertSame( array( 'post', 'replaced' ), array_keys( $shape ) );
		$this->assertSame(
			array(
				'path'  => array( 2, 1 ),
				'block' => 'core/paragraph',
			),
			$shape['replaced']
		);
		$this->assertSame( $id, $shape['post']['id'] );
		$this->assertStringContainsString( 'Replaced', $this->stored( $id ) );
	}
}
