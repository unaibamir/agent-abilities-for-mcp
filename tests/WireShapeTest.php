<?php
/**
 * Wire-shape regression: every ability result must be a JSON object, never a bare list.
 *
 * MCP specification 2025-06-18, "Tools" page, section "Structured Content" (fetched via
 * `curl -s https://modelcontextprotocol.io/specification/2025-06-18/server/tools.md`,
 * checked 2026-07-28): "Structured content is returned as a JSON object in the
 * structuredContent field of a result." A tool result that is a top-level JSON array
 * rather than an object is therefore out of spec, and strict MCP clients reject it. A
 * competitor (EMCP) shipped exactly this defect across three releases.
 *
 * Serialization trace: `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:307`
 * passes the ability's raw execute() return value straight through as `structuredContent`
 * with no wrap and no shape check: `'structuredContent' => $result`. Whatever an execute
 * callback returns is what reaches the wire, verbatim.
 *
 * All 154 `output_schema` declarations in includes/abilities/ declare top-level
 * `type => 'object'`, so the schema side was already clean before this test existed. The
 * 154-vs-153 count (153 registered abilities) is `aafm_args_structure_read()` in
 * structure.php: a shared, parametrised builder that `aafm_args_get_taxonomies()` and
 * `aafm_args_get_post_types()` both call to construct their base schema. It is not itself
 * an args_builder for any registered ability, so it is the one extra declaration, not a
 * missing or orphaned ability.
 *
 * This test exercises every core (non-vendor) read's execute callback directly, the same
 * way the per-ability *ReadTest suites do, against real fixture content, and asserts the
 * exact runtime result is never a top-level JSON list. The vendor-backed reads
 * (yoast-*, rankmath-*, aioseo-*, acf-*, wc-*) need their real host plugin loaded to
 * execute meaningfully and are out of reach of the bare unit environment; every one of
 * their exec functions was read and each wraps its result under a string key before
 * returning, so nothing here is asserted about them by inspection alone. The bridge path
 * (a foreign ability's result could be any shape) is not addressed here; it is handled by
 * validating a bridged result against its own declared schema (Wave 3 Task 7).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class WireShapeTest extends TestCase {

	/**
	 * The stylesheet active before this test switched to a block theme, restored in
	 * tear_down() - the same idiom ThemesTest uses for the same reason (get-template needs
	 * a real block template to resolve).
	 *
	 * @var string|null
	 */
	private ?string $previous_stylesheet = null;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->acting_as( 'administrator' );

		// The three meta-read abilities default-deny every key; admit the one probe key each
		// uses so get-post-meta/get-user-meta/get-term-meta return a real result instead of
		// an allowlist rejection, which would make this test blind to their runtime shape.
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_wire_shape_probe' ) );
		add_filter( 'aafm_allowed_user_meta_keys', static fn(): array => array( 'aafm_wire_shape_probe' ) );
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'aafm_wire_shape_probe' ) );

		// The WordPress test library boots with the non-block "default" theme, so get-template
		// has no real block template to resolve without this - see executable_read_results().
		$this->previous_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
	}

	public function tear_down(): void {
		if ( null !== $this->previous_stylesheet ) {
			switch_theme( $this->previous_stylesheet );
		}
		parent::tear_down();
	}

	/**
	 * No native read ability's runtime result is a top-level JSON array.
	 *
	 * Every ability exercised here is expected to reach a real, non-error result: the test
	 * fixture is built specifically so each one has something genuine to read. Reporting what
	 * was skipped and why (rather than silently excluding a WP_Error result from the shape
	 * check) is the same principle Task 2 applied to the annotation gate - a check that can
	 * quietly narrow its own coverage is a hole with the same shape as a missing check.
	 */
	public function test_no_native_ability_returns_a_top_level_array(): void {
		$results = $this->executable_read_results();

		$this->assertGreaterThan(
			30,
			count( $results ),
			'This test enumerated fewer core reads than expected, so it is not exercising the catalog it claims to.'
		);

		$skipped = array();
		$checked = array();
		foreach ( $results as $name => $result ) {
			if ( is_wp_error( $result ) ) {
				$skipped[ $name ] = $result->get_error_message();
				continue;
			}
			$checked[ $name ] = $result;
		}

		$this->assertSame(
			array(),
			$skipped,
			sprintf(
				"Every fixture in this test is built to reach a real result. An ability that errored here never reached the shape assertion below, narrowing this test's actual coverage silently (ability => error):\n%s",
				implode(
					"\n",
					array_map(
						static fn( string $name, string $reason ): string => "  $name: $reason",
						array_keys( $skipped ),
						array_values( $skipped )
					)
				)
			)
		);

		foreach ( $checked as $name => $result ) {
			$this->assertIsArray( $result, "$name returned a non-array result." );
			$this->assertTrue(
				array() === $result || ! array_is_list( $result ),
				"$name returned a top-level JSON list. Per MCP spec 2025-06-18, structuredContent must be a JSON object."
			);
		}
	}

	/**
	 * Build real fixture content, then call every core read's execute callback directly with
	 * it, exactly as the per-ability *ReadTest suites already do. Returns ability name => the
	 * raw execute() result (which may be a WP_Error).
	 *
	 * @return array<string,mixed>
	 */
	private function executable_read_results(): array {
		$post = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post, 'aafm_wire_shape_probe', 'x' );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'revised for the wire-shape probe',
			)
		);
		$revision_ids = array_values( wp_get_post_revisions( $post, array( 'fields' => 'ids' ) ) );
		$revision_id  = array() !== $revision_ids ? (int) $revision_ids[0] : 0;

		$page = (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$term = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term, 'aafm_wire_shape_probe', 'x' );

		$comment = (int) self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => '1',
			)
		);

		$attachment = (int) self::factory()->attachment->create_object(
			array(
				'post_parent'    => $post,
				'post_mime_type' => 'image/png',
				'post_type'      => 'attachment',
			)
		);

		$user = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $user, 'aafm_wire_shape_probe', 'x' );

		$menu_id = (int) wp_create_nav_menu( 'Wire Shape Probe Menu' );
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Home',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
			)
		);

		$block = (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Wire Shape Probe Block',
				'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			)
		);

		// A real database template override, tied to the active theme via the wp_theme
		// taxonomy term, exactly as ThemesTest does - so get-template resolves a genuine
		// template instead of erroring on a deliberately-missing id.
		$template_post = (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'wire-shape-probe',
				'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_object_terms( $template_post, get_stylesheet(), 'wp_theme' );
		$template_id = get_stylesheet() . '//wire-shape-probe';

		return array(
			'aafm/get-posts'            => aafm_exec_get_posts( array() ),
			'aafm/count-posts'          => aafm_exec_count_posts( array() ),
			'aafm/get-post'             => aafm_exec_get_post( array( 'post_id' => $post ) ),
			'aafm/get-post-meta'        => aafm_exec_get_post_meta(
				array(
					'post_id'  => $post,
					'meta_key' => 'aafm_wire_shape_probe', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ability-input array key, not a meta query.
				)
			),
			'aafm/get-all-post-meta'    => aafm_exec_get_all_post_meta( array( 'post_id' => $post ) ),
			'aafm/get-pages'            => aafm_exec_get_pages( array() ),
			'aafm/get-page'             => aafm_exec_get_page( array( 'page_id' => $page ) ),
			'aafm/get-terms'            => aafm_exec_get_terms( array( 'taxonomy' => 'category' ) ),
			'aafm/get-term'             => aafm_exec_get_term(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term,
				)
			),
			'aafm/get-term-meta'        => aafm_exec_get_term_meta(
				array(
					'term_id'  => $term,
					'taxonomy' => 'category',
					'meta_key' => 'aafm_wire_shape_probe', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ability-input array key, not a meta query.
				)
			),
			'aafm/get-taxonomies'       => aafm_exec_get_taxonomies(),
			'aafm/get-post-types'       => aafm_exec_get_post_types(),
			'aafm/get-site-info'        => aafm_exec_get_site_info(),
			'aafm/get-comments'         => aafm_exec_get_comments( array() ),
			'aafm/get-pending-comments' => aafm_exec_get_pending_comments( array() ),
			'aafm/get-comment'          => aafm_exec_get_comment( array( 'comment_id' => $comment ) ),
			'aafm/get-media'            => aafm_exec_get_media( array() ),
			'aafm/get-media-item'       => aafm_exec_get_media_item( array( 'attachment_id' => $attachment ) ),
			'aafm/count-media'          => aafm_exec_count_media( array() ),
			'aafm/get-users'            => aafm_exec_get_users( array() ),
			'aafm/get-user'             => aafm_exec_get_user( array( 'user_id' => $user ) ),
			'aafm/get-user-meta'        => aafm_exec_get_user_meta(
				array(
					'user_id' => $user,
					'key'     => 'aafm_wire_shape_probe',
				)
			),
			'aafm/list-revisions'       => aafm_exec_list_revisions( array( 'post_id' => $post ) ),
			'aafm/get-revision'         => aafm_exec_get_revision(
				array(
					'revision_id' => $revision_id,
					'post_id'     => $post,
				)
			),
			'aafm/search-content'       => aafm_exec_search_content( array( 'search' => 'wire shape probe' ) ),
			'aafm/get-site-settings'    => aafm_exec_get_site_settings(),
			'aafm/list-plugins'         => aafm_exec_list_plugins(),
			'aafm/get-activity-log'     => aafm_exec_get_activity_log( array() ),
			'aafm/list-blocks'          => aafm_exec_list_blocks( array() ),
			'aafm/get-block'            => aafm_exec_get_block( array( 'block_id' => $block ) ),
			'aafm/list-menus'           => aafm_exec_list_menus(),
			'aafm/get-menu'             => aafm_exec_get_menu( array( 'menu_id' => $menu_id ) ),
			'aafm/list-menu-items'      => aafm_exec_list_menu_items( array( 'menu_id' => $menu_id ) ),
			'aafm/get-active-theme'     => aafm_exec_get_active_theme(),
			'aafm/list-themes'          => aafm_exec_list_themes(),
			'aafm/list-templates'       => aafm_exec_list_templates( array() ),
			'aafm/get-template'         => aafm_exec_get_template( array( 'template_id' => $template_id ) ),
			'aafm/get-global-styles'    => aafm_exec_get_global_styles(),
		);
	}
}
