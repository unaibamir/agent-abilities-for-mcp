<?php
/**
 * Page parent and template on create-page and update-page (F-11a, 1.11.3).
 *
 * Both writers delegate to the shared aafm_insert_post() / aafm_exec_update_post(), so the fields
 * are page-only there: a post never accepts them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class PageParentTemplateTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/create-page', 'aafm/update-page', 'aafm/update-post' ) );
		add_filter( 'theme_page_templates', array( $this, 'add_template' ) );
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		remove_filter( 'theme_page_templates', array( $this, 'add_template' ) );
		parent::tear_down();
	}

	/**
	 * A page template the active theme offers, for these tests.
	 *
	 * @param array<string,string> $templates Templates.
	 * @return array<string,string>
	 */
	public function add_template( $templates ): array {
		return array_merge( (array) $templates, array( 'tpl-landing.php' => 'Landing' ) );
	}

	private function page( array $args = array() ): int {
		return (int) self::factory()->post->create( array_merge( array( 'post_type' => 'page' ), $args ) );
	}

	private function stored_parent( int $id ): int {
		clean_post_cache( $id );
		return (int) get_post( $id )->post_parent;
	}

	private function update( int $id, array $input ) {
		return wp_get_ability( 'aafm/update-page' )->execute( array_merge( array( 'page_id' => $id ), $input ) );
	}

	public function test_create_page_with_parent_and_template(): void {
		$parent = $this->page();

		$res = wp_get_ability( 'aafm/create-page' )->execute(
			array(
				'title'     => 'Child',
				'parent_id' => $parent,
				'template'  => 'tpl-landing.php',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$id = (int) $res['post']['id'];
		$this->assertSame( $parent, $this->stored_parent( $id ) );
		$this->assertSame( 'tpl-landing.php', get_page_template_slug( $id ) );
	}

	public function test_update_page_changes_parent(): void {
		$parent = $this->page();
		$page   = $this->page();

		$res = $this->update( $page, array( 'parent_id' => $parent ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $parent, $this->stored_parent( $page ) );

		$res = $this->update( $page, array( 'parent_id' => 0 ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 0, $this->stored_parent( $page ), 'parent_id 0 clears the parent.' );
	}

	public function test_parent_that_is_the_page_itself_is_refused(): void {
		$page = $this->page();

		$res = $this->update( $page, array( 'parent_id' => $page ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_page_parent_loop', $res->get_error_code() );
		$this->assertSame( 0, $this->stored_parent( $page ) );
	}

	public function test_parent_that_is_a_descendant_is_refused(): void {
		$top   = $this->page();
		$child = $this->page( array( 'post_parent' => $top ) );
		$grand = $this->page( array( 'post_parent' => $child ) );

		$res = $this->update( $top, array( 'parent_id' => $grand ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_page_parent_loop', $res->get_error_code() );
		$this->assertSame( 0, $this->stored_parent( $top ) );
		$this->assertSame( $child, $this->stored_parent( $grand ), 'No other page was rewritten to break a loop.' );
	}

	public function test_parent_in_trash_is_refused(): void {
		$parent = $this->page( array( 'post_status' => 'trash' ) );
		$page   = $this->page();

		$res = $this->update( $page, array( 'parent_id' => $parent ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_invalid_page_parent', $res->get_error_code() );
		$this->assertSame( 0, $this->stored_parent( $page ) );
	}

	public function test_parent_that_is_not_a_page_is_refused(): void {
		$post = (int) self::factory()->post->create();
		$page = $this->page();

		$res = $this->update( $page, array( 'parent_id' => $post ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_invalid_page_parent', $res->get_error_code() );
		$this->assertSame( 0, $this->stored_parent( $page ) );

		$created = wp_get_ability( 'aafm/create-page' )->execute(
			array(
				'title'     => 'Orphan',
				'parent_id' => 999999,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $created, 'A missing parent refuses a create before anything is written.' );
	}

	public function test_unknown_template_is_refused(): void {
		$page = $this->page( array( 'post_title' => 'Keep' ) );

		$res = $this->update(
			$page,
			array(
				'title'    => 'Changed',
				'template' => 'no-such-template.php',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_invalid_page_template', $res->get_error_code() );
		clean_post_cache( $page );
		$this->assertSame( 'Keep', get_post( $page )->post_title, 'Nothing else was written either.' );
		$this->assertSame( '', get_page_template_slug( $page ) );
	}

	public function test_default_template_is_accepted(): void {
		$page = $this->page();
		update_post_meta( $page, '_wp_page_template', 'tpl-landing.php' );

		$res = $this->update( $page, array( 'template' => 'default' ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( '', get_page_template_slug( $page ) );
		$this->assertSame( 'default', $res['post']['template'] );
	}

	public function test_builder_owned_page_parent_change_refused(): void {
		$parent = $this->page();
		$page   = $this->page();
		update_post_meta( $page, '_elementor_data', '[]' );

		$res = $this->update( $page, array( 'parent_id' => $parent ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_page_builder_owned', $res->get_error_code() );
		$this->assertSame( 0, $this->stored_parent( $page ) );
	}

	public function test_builder_owned_page_template_change_refused(): void {
		$page = $this->page();
		update_post_meta( $page, '_elementor_data', '[]' );

		$res = $this->update( $page, array( 'template' => 'tpl-landing.php' ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_page_builder_owned', $res->get_error_code() );
		$this->assertSame( '', get_page_template_slug( $page ) );
	}

	public function test_read_back_returns_parent_and_template(): void {
		$parent = $this->page();
		$page   = $this->page();

		$res = $this->update(
			$page,
			array(
				'parent_id' => $parent,
				'template'  => 'tpl-landing.php',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $parent, $res['post']['parent_id'] );
		$this->assertSame( 'tpl-landing.php', $res['post']['template'] );
		$this->assertArrayHasKey( 'parent_id', aafm_rich_post_output_properties() );
		$this->assertArrayHasKey( 'template', aafm_rich_post_output_properties() );
	}

	public function test_a_parent_a_site_filter_rewrote_is_not_reported_as_saved(): void {
		$parent = $this->page();
		$page   = $this->page();
		$filter = static fn(): int => 0;
		add_filter( 'wp_insert_post_parent', $filter );
		try {
			$res = $this->update( $page, array( 'parent_id' => $parent ) );
		} finally {
			remove_filter( 'wp_insert_post_parent', $filter );
		}

		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_posts_do_not_accept_parent_or_template(): void {
		$post   = (int) self::factory()->post->create();
		$schema = aafm_args_update_post()['input_schema'];
		$this->assertArrayNotHasKey( 'parent_id', $schema['properties'] );
		$this->assertArrayNotHasKey( 'template', $schema['properties'] );
		$this->assertArrayNotHasKey( 'parent_id', aafm_args_create_draft()['input_schema']['properties'] );

		$res = aafm_exec_update_post(
			array(
				'post_id'   => $post,
				'parent_id' => $this->page(),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_page_only_field', $res->get_error_code() );
		$this->assertSame( 0, $this->stored_parent( $post ) );

		$shape = aafm_redact_post( get_post( $post ) );
		$this->assertArrayNotHasKey( 'parent_id', $shape );
		$this->assertArrayNotHasKey( 'template', $shape );
	}

	public function test_a_parent_the_caller_cannot_read_is_refused_like_a_missing_one(): void {
		$parent = $this->page( array( 'post_status' => 'private' ) );
		$page   = $this->page();
		$deny   = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $parent ): array {
			return 'read_post' === $cap && (int) ( $args[0] ?? 0 ) === $parent ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		try {
			$res     = $this->update( $page, array( 'parent_id' => $parent ) );
			$missing = $this->update( $page, array( 'parent_id' => 999999 ) );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_invalid_page_parent', $res->get_error_code() );
		$this->assertSame( $missing->get_error_message(), $res->get_error_message(), 'The answer does not reveal that the page exists.' );
		$this->assertSame( 0, $this->stored_parent( $page ) );
	}

	public function test_template_description_names_both_theme_kinds(): void {
		$description = aafm_args_update_page()['input_schema']['properties']['template']['description'];
		$this->assertStringContainsString( 'slug', $description );
		$this->assertStringContainsString( 'file name', $description );
	}
}
