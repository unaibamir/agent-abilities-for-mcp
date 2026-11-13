<?php
/**
 * Block-theme navigation tools: aafm/list-navigations and aafm/update-navigation (U-4, 1.11.3).
 *
 * The classic menu tools reach nav_menu terms only; these two reach the wp_navigation posts a block
 * theme's core/navigation block reads. The write takes a plain item list, builds canonical
 * core/navigation-link markup, validates it, runs the page-builder ownership guard before saving and
 * reads the stored result back.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class NavigationTest extends TestCase {

	private function register_navigation(): void {
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/list-navigations', 'aafm/update-navigation' ) );
	}

	private function make_navigation( string $content = '', string $title = 'Header' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	/**
	 * A three-level list: a top link with a child that has its own child.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function nested_items(): array {
		return array(
			array(
				'label' => 'Home',
				'url'   => '/',
			),
			array(
				'label'    => 'Shop',
				'url'      => 'https://example.com/shop',
				'children' => array(
					array(
						'label'    => 'Shirts',
						'url'      => '/shop/shirts',
						'children' => array(
							array(
								'label' => 'Call us',
								'url'   => 'tel:+15550100',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The item list with empty children filled in, the shape the tools return.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function nested_items_read_shape(): array {
		return array(
			array(
				'label'    => 'Home',
				'url'      => '/',
				'children' => array(),
			),
			array(
				'label'    => 'Shop',
				'url'      => 'https://example.com/shop',
				'children' => array(
					array(
						'label'    => 'Shirts',
						'url'      => '/shop/shirts',
						'children' => array(
							array(
								'label'    => 'Call us',
								'url'      => 'tel:+15550100',
								'children' => array(),
							),
						),
					),
				),
			),
		);
	}

	private function assert_refused_and_unchanged( $result, string $code, int $id, string $before ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		clean_post_cache( $id );
		$this->assertSame( $before, get_post( $id )->post_content, 'A refused call changes nothing.' );
	}

	public function test_list_returns_nested_items_for_a_navigation_post(): void {
		$this->register_navigation();
		$this->acting_as( 'administrator' );
		$content = '<!-- wp:navigation-link {"label":"About","url":"/about","kind":"custom"} /-->'
			. '<!-- wp:navigation-submenu {"label":"More","url":"/more","kind":"custom"} -->'
			. '<!-- wp:navigation-link {"label":"Team","url":"/team","kind":"custom"} /-->'
			. '<!-- /wp:navigation-submenu -->'
			. '<!-- wp:page-list /-->';
		$id      = $this->make_navigation( $content );

		$res = wp_get_ability( 'aafm/list-navigations' )->execute( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertCount( 1, $res['navigations'] );
		$nav = $res['navigations'][0];
		$this->assertSame( $id, $nav['id'] );
		$this->assertSame( 'Header', $nav['title'] );
		$this->assertSame( 'publish', $nav['status'] );
		$this->assertSame(
			array(
				array(
					'label'    => 'About',
					'url'      => '/about',
					'children' => array(),
				),
				array(
					'label'    => 'More',
					'url'      => '/more',
					'children' => array(
						array(
							'label'    => 'Team',
							'url'      => '/team',
							'children' => array(),
						),
					),
				),
			),
			$nav['items']
		);
		$this->assertSame( array( 'core/page-list' ), $nav['other_blocks'], 'Blocks the item list cannot show are named.' );
	}

	public function test_site_without_block_theme_lists_empty_not_error(): void {
		$this->register_navigation();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/list-navigations' )->execute( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( array(), $res['navigations'] );
	}

	public function test_update_writes_canonical_blocks_that_pass_block_validation(): void {
		$this->register_navigation();
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );

		$res = wp_get_ability( 'aafm/update-navigation' )->execute(
			array(
				'id'    => $id,
				'items' => $this->nested_items(),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		clean_post_cache( $id );
		$stored = (string) get_post( $id )->post_content;
		$this->assertSame( $stored, serialize_blocks( parse_blocks( $stored ) ), 'The stored markup is canonical.' );
		$this->assertStringNotContainsString( 'page-list', $stored, 'The list replaces the whole navigation.' );
		$blocks = array_values( array_filter( parse_blocks( $stored ), static fn( array $b ): bool => null !== $b['blockName'] ) );
		$this->assertSame( array( 'core/navigation-link', 'core/navigation-submenu' ), array_column( $blocks, 'blockName' ), 'An item with children is a submenu, as core writes it.' );
		$this->assertSame( 'core/navigation-submenu', $blocks[1]['innerBlocks'][0]['blockName'], 'A nested item with children is a submenu too.' );
		$this->assertSame( 'core/navigation-link', $blocks[1]['innerBlocks'][0]['innerBlocks'][0]['blockName'], 'A leaf is a navigation-link.' );
		$this->assertSame( 'custom', $blocks[1]['attrs']['kind'] );
		$this->assertSame( 'custom', $blocks[0]['attrs']['kind'] );
		$guard = aafm_block_guard_evaluate( $stored );
		$this->assertNull( $guard['error'] );
		$this->assertSame( array(), array_intersect( array_column( $guard['warnings'], 'code' ), aafm_block_guard_strict_codes() ) );
	}

	public function test_update_reads_back_the_stored_items(): void {
		$this->register_navigation();
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation();

		$res = wp_get_ability( 'aafm/update-navigation' )->execute(
			array(
				'id'    => $id,
				'title' => 'Main menu',
				'items' => $this->nested_items(),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $id, $res['id'] );
		$this->assertSame( 'Main menu', $res['title'] );
		$this->assertSame( $this->nested_items_read_shape(), $res['items'] );
		$listed = wp_get_ability( 'aafm/list-navigations' )->execute( array() );
		$this->assertSame( $this->nested_items_read_shape(), $listed['navigations'][0]['items'], 'The read tool sees what the write stored.' );
	}

	public function test_update_refuses_when_the_stored_markup_reads_back_differently(): void {
		$this->register_navigation();
		$this->acting_as( 'administrator' );
		$id     = $this->make_navigation();
		$filter = static function ( array $data ): array {
			$data['post_content'] = '';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $filter );
		try {
			$res = aafm_exec_update_navigation(
				array(
					'id'    => $id,
					'items' => $this->nested_items(),
				)
			);
		} finally {
			remove_filter( 'wp_insert_post_data', $filter );
		}

		$this->assertInstanceOf( WP_Error::class, $res, 'A save that did not store the list is not reported as a success.' );
	}

	public function test_javascript_url_is_refused(): void {
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => array(
					array(
						'label' => 'Bad',
						'url'   => 'javascript:alert(1)',
					),
				),
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_invalid_url', $id, '<!-- wp:page-list /-->' );
	}

	public function test_a_url_scheme_outside_the_four_is_refused(): void {
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => array(
					array(
						'label' => 'Files',
						'url'   => 'ftp://example.com/file',
					),
				),
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_invalid_url', $id, '<!-- wp:page-list /-->' );
	}

	/**
	 * A scheme with no slashes and a numeric tail parses as host and port in wp_parse_url().
	 *
	 * @dataProvider provide_schemes_that_parse_as_a_port
	 *
	 * @param string $url A link whose scheme is outside http, https, mailto and tel.
	 */
	public function test_a_scheme_that_parses_as_a_port_is_refused( string $url ): void {
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => array(
					array(
						'label' => 'Contact',
						'url'   => $url,
					),
				),
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_invalid_url', $id, '<!-- wp:page-list /-->' );
	}

	/**
	 * Links with a scheme wp_parse_url() misreads as a host and port.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_schemes_that_parse_as_a_port(): array {
		return array(
			'sms' => array( 'sms:123' ),
			'fax' => array( 'fax:12345' ),
		);
	}

	public function test_label_over_200_characters_is_refused(): void {
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => array(
					array(
						'label' => str_repeat( 'a', 10000 ),
						'url'   => '/',
					),
				),
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_navigation_label_too_long', $id, '<!-- wp:page-list /-->' );
	}

	public function test_depth_over_3_is_refused(): void {
		$this->acting_as( 'administrator' );
		$id    = $this->make_navigation( '<!-- wp:page-list /-->' );
		$items = $this->nested_items();
		// Give the third-level item a child of its own: four levels.
		$items[1]['children'][0]['children'][0]['children'] = array(
			array(
				'label' => 'Too deep',
				'url'   => '/deep',
			),
		);

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => $items,
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_navigation_too_deep', $id, '<!-- wp:page-list /-->' );
	}

	public function test_more_than_100_items_is_refused(): void {
		$this->acting_as( 'administrator' );
		$id    = $this->make_navigation( '<!-- wp:page-list /-->' );
		$items = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$items[] = array(
				'label' => 'Item ' . $i,
				'url'   => '/item-' . $i,
			);
		}

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => $items,
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_navigation_too_many_items', $id, '<!-- wp:page-list /-->' );
	}

	public function test_nested_items_count_toward_the_100_limit(): void {
		$this->acting_as( 'administrator' );
		$id       = $this->make_navigation( '<!-- wp:page-list /-->' );
		$children = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$children[] = array(
				'label' => 'Child ' . $i,
				'url'   => '/child-' . $i,
			);
		}

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => array(
					array(
						'label'    => 'Parent',
						'url'      => '/parent',
						'children' => $children,
					),
				),
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_navigation_too_many_items', $id, '<!-- wp:page-list /-->' );
	}

	public function test_empty_label_is_refused(): void {
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => array(
					array(
						'label' => '   ',
						'url'   => '/',
					),
				),
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_navigation_label_empty', $id, '<!-- wp:page-list /-->' );
	}

	public function test_foreign_page_builder_owned_navigation_is_refused(): void {
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );
		update_post_meta( $id, '_elementor_data', '[]' );
		$this->assertSame( 'elementor', aafm_post_has_foreign_builder_ownership( $id ), 'precondition: the guard reads the post as owned.' );

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => $this->nested_items(),
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_page_builder_owned', $id, '<!-- wp:page-list /-->' );
	}

	public function test_navigation_whose_ownership_cannot_be_established_is_refused(): void {
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );
		// Two different builders' markers: the guard cannot tell which owns it and answers unknown.
		update_post_meta( $id, '_elementor_data', '[]' );
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		$this->assertSame( AAFM_BUILDER_OWNERSHIP_UNKNOWN, aafm_post_has_foreign_builder_ownership( $id ), 'precondition: ownership is unknown.' );

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => $this->nested_items(),
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_page_builder_owned', $id, '<!-- wp:page-list /-->' );
	}

	public function test_user_without_edit_cap_is_denied(): void {
		$this->register_navigation();
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );
		$this->acting_as( 'editor' ); // Has edit_posts, lacks edit_theme_options.

		$this->assertNotTrue(
			wp_get_ability( 'aafm/update-navigation' )->check_permissions(
				array(
					'id'    => $id,
					'items' => array(),
				)
			)
		);
		$this->assertNotTrue( wp_get_ability( 'aafm/list-navigations' )->check_permissions( array() ) );
		$res = wp_get_ability( 'aafm/update-navigation' )->execute(
			array(
				'id'    => $id,
				'items' => $this->nested_items(),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
		clean_post_cache( $id );
		$this->assertSame( '<!-- wp:page-list /-->', get_post( $id )->post_content );
	}

	public function test_update_refuses_an_id_that_is_not_a_navigation_post(): void {
		$this->register_navigation();
		$this->acting_as( 'administrator' );
		$page = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'Keep me',
			)
		);

		$this->assertNotTrue(
			wp_get_ability( 'aafm/update-navigation' )->check_permissions(
				array(
					'id'    => $page,
					'items' => array(),
				)
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_update_navigation(
				array(
					'id'    => $page,
					'items' => $this->nested_items(),
				)
			)
		);
		clean_post_cache( $page );
		$this->assertSame( 'Keep me', get_post( $page )->post_content );
	}

	public function test_navigation_write_is_listed_for_a_capable_user_and_hidden_otherwise(): void {
		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-navigation' ), 'Discovery with empty input shows the write to an admin.' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/list-navigations' ) );

		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/update-navigation' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/list-navigations' ) );
	}

	public function test_discovery_and_execute_permission_agree(): void {
		$this->register_navigation();
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );

		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-navigation' ) );
		$this->assertTrue(
			wp_get_ability( 'aafm/update-navigation' )->check_permissions(
				array(
					'id'    => $id,
					'items' => array(),
				)
			)
		);

		$this->acting_as( 'editor' );
		$this->assertTrue( current_user_can( 'edit_posts' ), 'precondition: the editor can edit posts.' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/update-navigation' ) );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/update-navigation' )->check_permissions(
				array(
					'id'    => $id,
					'items' => array(),
				)
			)
		);
	}

	public function test_update_is_reported_as_not_undoable_with_reason(): void {
		$this->register_navigation();
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation();

		$res = wp_get_ability( 'aafm/update-navigation' )->execute(
			array(
				'id'    => $id,
				'items' => $this->nested_items(),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertFalse( $res['undo']['undoable'] );
		$this->assertSame( 'not_covered', $res['undo']['reason'] );
		$this->assertSame( 'not_covered', aafm_undo_refusals()['aafm/update-navigation'] );
		$this->assertStringContainsString( 'cannot be undone', aafm_ability_description( 'aafm/update-navigation' ) );
		$this->assertStringContainsString( 'cannot be undone', aafm_ability_disclosures()['aafm/update-navigation'] );
	}

	public function test_tools_absent_from_every_preset(): void {
		foreach ( array( 'content_editor', 'shop_manager' ) as $preset ) {
			$this->assertFalse( aafm_preset_permits( $preset, 'aafm/update-navigation' ), $preset );
			$this->assertFalse( aafm_preset_permits( $preset, 'aafm/list-navigations' ), $preset );
		}
		$this->assertFalse( aafm_preset_permits( 'read_only', 'aafm/update-navigation' ) );
		// Read only admits every read by rule (includes/presets.php), the same as aafm/list-menus.
		$this->assertSame( aafm_preset_permits( 'read_only', 'aafm/list-menus' ), aafm_preset_permits( 'read_only', 'aafm/list-navigations' ) );
		$this->assertNotContains( 'aafm/update-navigation', aafm_get_enabled_abilities(), 'The write starts off.' );
	}

	public function test_an_ampersand_saves_for_a_user_without_unfiltered_html(): void {
		$this->register_navigation();
		$this->acting_as( 'administrator' );
		$deny = static function ( array $caps, string $cap ): array {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		kses_init();
		$id = $this->make_navigation();
		try {
			$this->assertFalse( current_user_can( 'unfiltered_html' ), 'precondition: kses filters this user.' );
			$res = wp_get_ability( 'aafm/update-navigation' )->execute(
				array(
					'id'    => $id,
					'items' => array(
						array(
							'label' => 'Terms & Conditions',
							'url'   => 'https://shop.example/?utm_source=nav&utm_medium=menu',
						),
					),
				)
			);
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
			kses_remove_filters();
		}

		$this->assertNotInstanceOf( WP_Error::class, $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$listed = aafm_exec_list_navigations()['navigations'][0]['items'];
		$this->assertSame( $listed, $res['items'], 'The response is what was stored.' );
		$this->assertCount( 1, $res['items'] );
	}

	public function test_an_empty_list_is_refused(): void {
		$this->acting_as( 'administrator' );
		$id = $this->make_navigation( '<!-- wp:page-list /-->' );

		$res = aafm_exec_update_navigation(
			array(
				'id'    => $id,
				'items' => array(),
			)
		);

		$this->assert_refused_and_unchanged( $res, 'aafm_navigation_empty', $id, '<!-- wp:page-list /-->' );
	}

	public function test_schema_declares_the_limits(): void {
		$schema = aafm_args_update_navigation()['input_schema']['properties']['items'];
		$this->assertSame( 1, $schema['minItems'] );
		$this->assertSame( 100, $schema['maxItems'] );
		$this->assertSame( 1, $schema['items']['properties']['label']['minLength'] );
		$this->assertSame( 200, $schema['items']['properties']['label']['maxLength'] );
	}

	public function test_description_says_link_settings_are_not_kept(): void {
		foreach ( array( aafm_ability_description( 'aafm/update-navigation' ), aafm_ability_disclosures()['aafm/update-navigation'] ) as $text ) {
			$this->assertStringContainsString( 'new tab', $text );
		}
	}

	public function test_markup_wordpress_would_store_differently_is_refused_before_the_save(): void {
		$this->acting_as( 'administrator' );
		$id     = $this->make_navigation( '<!-- wp:page-list /-->' );
		$filter = static fn( $content ) => $content . '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
		add_filter( 'content_save_pre', $filter );
		try {
			$res = aafm_exec_update_navigation(
				array(
					'id'    => $id,
					'items' => $this->nested_items(),
				)
			);
		} finally {
			remove_filter( 'content_save_pre', $filter );
		}

		$this->assert_refused_and_unchanged( $res, 'aafm_invalid_block_content', $id, '<!-- wp:page-list /-->' );
	}
}
