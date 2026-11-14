<?php
/**
 * Builder add-ons (design 325 sections 3.3, 3.5, WB-7, WB-8 and AV-15, plan 326 Session 14): Templatera's template
 * posts in the WPBakery tools, and Avada Core's slider settings. Vendor code is reached only through the seam stubs
 * (Ruling B14); the templatera post type and the slide-page taxonomy are registered by the test as the vendors do.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\WpbakeryMapStub;
use AAFM\Tests\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/Support/WpbakeryMapStub.php';

final class BuilderAddonsTest extends TestCase {

	private const SIMPLE = '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text][/vc_column][/vc_row]';

	private const TEMPLATE = '[vc_row][vc_column][vc_column_text]<p>From Templatera</p>[/vc_column_text][/vc_column][/vc_row]';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		WpbakeryMapStub::install( WpbakeryMapStub::map() + array( 'templatera' => self::templatera_map_entry() ) );
		// Templatera's own registration (lib/vc_template_manager.php createPostType()): not public, capability type post.
		register_post_type(
			'templatera',
			array(
				'public'          => false,
				'show_ui'         => true,
				'capability_type' => 'post',
			)
		);
	}

	public function tear_down(): void {
		WpbakeryMapStub::remove();
		unregister_post_type( 'templatera' );
		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		remove_all_filters( 'aafm_templatera_active' );
		delete_option( 'wpb_js_templates' );
		parent::tear_down();
	}

	/**
	 * Templatera's own map entry (createShortcode()): a content element with id, use_template_scope and el_class.
	 *
	 * @return array<string,mixed>
	 */
	private static function templatera_map_entry(): array {
		return array(
			'name'     => 'Templatera',
			'base'     => 'templatera',
			'category' => 'Content',
			'params'   => array(
				array(
					'type'       => 'dropdown',
					'param_name' => 'id',
					'value'      => array( 'Choose template' => '' ),
				),
				array(
					'type'       => 'checkbox',
					'param_name' => 'use_template_scope',
					'value'      => array( 'Yes' => 'yes' ),
				),
				array(
					'type'       => 'textfield',
					'param_name' => 'el_class',
				),
			),
		);
	}

	/**
	 * Assert a WP_Error code and status.
	 *
	 * @param mixed  $out    Result.
	 * @param string $code   Code.
	 * @param int    $status Status.
	 * @return void
	 */
	private function assert_error( $out, string $code, int $status ): void {
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( $code, $out->get_error_code() );
		$this->assertSame( $status, $out->get_error_data()['status'] ?? null );
	}

	/**
	 * A published templatera post, created by an administrator, with optional Templatera meta lists (T3).
	 *
	 * @param string             $content    Shortcode text.
	 * @param array<string>|null $post_types post_type list, null for no meta.
	 * @param array<string>      $roles      user_role list.
	 * @param string             $title      Title.
	 * @return int
	 */
	private function template( string $content, ?array $post_types = null, array $roles = array(), string $title = 'T' ): int {
		$current = get_current_user_id();
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'templatera',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
		if ( null !== $post_types || array() !== $roles ) {
			update_post_meta(
				$id,
				'templatera',
				array(
					'post_type' => (array) $post_types,
					'user_role' => $roles,
				)
			);
		}
		wp_set_current_user( $current );
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
		return $id;
	}

	public function test_a_template_post_is_readable_and_its_remove_is_refused(): void {
		$this->assertContains( 'templatera', aafm_builder_own_post_types( 'wpbakery' ) );
		$id = $this->template( self::TEMPLATE );
		$this->acting_as( 'editor' );

		$read = aafm_exec_wpbakery_get_page_content( array( 'post_id' => $id ) );
		$this->assertIsArray( $read );
		$this->assertSame( self::TEMPLATE, $read['content'] );

		$replace = aafm_exec_wpbakery_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'From Templatera',
				'replace' => 'Changed in the template',
			)
		);
		$this->assertIsArray( $replace );
		$this->assertSame( 1, $replace['replacements'] );
		$this->assertStringContainsString( 'Changed in the template', (string) get_post_field( 'post_content', $id, 'raw' ) );

		$content  = (string) get_post_field( 'post_content', $id, 'raw' );
		$elements = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) );
		$out      = aafm_exec_wpbakery_remove_element(
			array(
				'post_id'          => $id,
				'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
				'index'            => 2,
				'expect'           => array(
					'tag'         => 'vc_column_text',
					'fingerprint' => aafm_builder_fingerprint( $content, $elements[2] ),
				),
			)
		);
		$this->assert_error( $out, 'aafm_builder_remove_irreversible', 409 );
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertFalse( post_type_supports( 'templatera', 'revisions' ) );
	}

	public function test_a_template_post_takes_an_element_update_with_no_restore_point(): void {
		$id      = $this->template( self::TEMPLATE );
		$content = (string) get_post_field( 'post_content', $id, 'raw' );
		$el      = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) );
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_update_element(
			array(
				'post_id'          => $id,
				'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
				'index'            => 2,
				'expect'           => array(
					'tag'         => 'vc_column_text',
					'fingerprint' => aafm_builder_fingerprint( $content, $el[2] ),
				),
				'set_atts'         => array( 'el_class' => 'from-test' ),
			)
		);
		$this->assertIsArray( $out );
		$this->assertStringContainsString( 'el_class="from-test"', (string) get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertFalse( $out['reversible'] );
	}

	/**
	 * The list's rows as id to source.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,string>
	 */
	private function listed( array $input = array() ): array {
		$out = aafm_exec_wpbakery_list_templates( $input );
		$this->assertIsArray( $out );
		return array_column( $out['templates'], 'source', 'id' );
	}

	public function test_templatera_posts_are_listed_only_while_templatera_is_active(): void {
		update_option(
			'wpb_js_templates',
			array(
				't_1' => array(
					'name'     => 'Saved',
					'template' => self::TEMPLATE,
				),
			),
			false
		);
		$id    = $this->template( self::TEMPLATE, null, array(), 'Posted' );
		$draft = $this->template( self::TEMPLATE );
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);
		$this->acting_as( 'editor' );
		$this->assertSame( array( 't_1' => 'saved' ), $this->listed() );

		add_filter( 'aafm_templatera_active', '__return_true' );
		$out = aafm_exec_wpbakery_list_templates( array( 'include_content' => true ) );
		$this->assertSame(
			array(
				array(
					'id'      => (string) $id,
					'source'  => 'templatera',
					'name'    => 'Posted',
					'content' => self::TEMPLATE,
				),
			),
			$out['templates'],
			'Only published template posts, as Templatera\'s getTemplateList(); the option rows are gone.'
		);
		$this->assertSame( 1, $out['total'] );
		$this->assertTrue( $out['templatera_active'] );
	}

	public function test_a_template_is_hidden_from_a_role_outside_its_user_role_list(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$admins = $this->template( self::TEMPLATE, null, array( 'administrator' ) );
		$both   = $this->template( self::TEMPLATE, null, array( 'administrator', 'editor' ) );
		$this->acting_as( 'editor' );
		$this->assertSame( array( (string) $both => 'templatera' ), $this->listed() );
		$this->acting_as( 'administrator' );
		$this->assertEqualsCanonicalizing( array( (string) $admins, (string) $both ), array_keys( $this->listed() ) );
		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_templatera_template_allowed( get_post( $admins ) ) );
	}

	public function test_for_post_id_applies_the_templates_post_type_list(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$pages = $this->template( self::TEMPLATE, array( 'page' ) );
		$posts = $this->template( self::TEMPLATE, array( 'post' ) );
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->acting_as( 'editor' );
		$this->assertSame( array( (string) $pages => 'templatera' ), $this->listed( array( 'for_post_id' => $page ) ) );
		$this->assertEqualsCanonicalizing( array( (string) $pages, (string) $posts ), array_keys( $this->listed() ), 'Without for_post_id the post type list is not applied.' );
		$this->assertTrue( aafm_templatera_template_allowed( get_post( $pages ), get_post( $page ) ) );
		$this->assertFalse( aafm_templatera_template_allowed( get_post( $posts ), get_post( $page ) ) );

		$other = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ),
				'post_status' => 'private',
			)
		);
		$this->acting_as( 'author' );
		$out = aafm_exec_wpbakery_list_templates( array( 'for_post_id' => $other ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
	}

	public function test_empty_lists_mean_everyone(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$open  = $this->template( self::TEMPLATE, array(), array() );
		$plain = $this->template( self::TEMPLATE );
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		foreach ( array( 'editor', 'administrator' ) as $role ) {
			$this->acting_as( $role );
			$this->assertEqualsCanonicalizing( array( (string) $open, (string) $plain ), array_keys( $this->listed( array( 'for_post_id' => $page ) ) ), $role );
		}
		$this->acting_as( 'author' );
		$this->assertSame( array(), $this->listed(), 'Each row still needs edit access to the template post.' );
		$this->assertFalse( aafm_templatera_template_allowed( get_post( $page ) ), 'Only a templatera post is a template.' );
	}
}
