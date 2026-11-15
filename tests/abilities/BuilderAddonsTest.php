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
		if ( null !== $this->slider_seam ) {
			remove_filter( 'aafm_builder_vendor', $this->slider_seam, 10 );
		}
		if ( taxonomy_exists( 'slide-page' ) ) {
			unregister_taxonomy( 'slide-page' );
		}
		if ( post_type_exists( 'slide' ) ) {
			unregister_post_type( 'slide' );
		}
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

	/**
	 * A WPBakery page, created as an administrator.
	 *
	 * @param string $content Content.
	 * @return int
	 */
	private function page( string $content = self::SIMPLE ): int {
		$current = get_current_user_id();
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $content,
			)
		);
		wp_set_current_user( $current );
		return $id;
	}

	/**
	 * Run the template insert after the element at $anchor, with anchor_expect and page_fingerprint filled in.
	 *
	 * @param int                 $id     Post.
	 * @param string              $tid    Template id.
	 * @param array<string,mixed> $extra  Extra input (source, mode).
	 * @param int                 $anchor Anchor index.
	 * @param string              $place  Placement.
	 * @return array<string,mixed>|WP_Error
	 */
	private function insert( int $id, string $tid, array $extra = array(), int $anchor = 0, string $place = 'after' ) {
		$content  = (string) get_post_field( 'post_content', $id, 'raw' );
		$elements = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) );
		return aafm_exec_wpbakery_insert_template(
			$extra + array(
				'post_id'          => $id,
				'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
				'template_id'      => $tid,
				'source'           => 'templatera',
				'anchor_index'     => $anchor,
				'anchor_expect'    => array(
					'tag'         => $elements[ $anchor ]['tag'],
					'fingerprint' => aafm_builder_fingerprint( $content, $elements[ $anchor ] ),
				),
				'placement'        => $place,
			)
		);
	}

	public function test_copy_from_a_templatera_post(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$tid = $this->template( self::TEMPLATE );
		$id  = $this->page();
		$this->acting_as( 'editor' );
		$out = $this->insert( $id, (string) $tid );
		$this->assertIsArray( $out );
		$this->assertSame( self::SIMPLE . self::TEMPLATE, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( 3, $out['inserted'] );
		$this->assertSame( aafm_builder_page_fingerprint( self::SIMPLE . self::TEMPLATE ), $out['page_fingerprint'] );
		$this->assertSame( aafm_redact_post( get_post( $id ) ), $out['post'] );
		$this->assertArrayNotHasKey( 'post_content', $out['post'] );
	}

	public function test_a_template_the_user_may_not_use_or_that_is_not_published_reads_as_unknown(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$admins = $this->template( self::TEMPLATE, null, array( 'administrator' ) );
		$posts  = $this->template( self::TEMPLATE, array( 'post' ) );
		$draft  = $this->template( self::TEMPLATE );
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);
		$id = $this->page();
		$this->acting_as( 'editor' );
		foreach ( array( $admins, $posts, $draft ) as $tid ) {
			$this->assert_error( $this->insert( $id, (string) $tid ), 'aafm_builder_unknown_template', 404 );
			$this->assertSame( self::SIMPLE, get_post_field( 'post_content', $id, 'raw' ) );
		}
	}

	public function test_a_reference_to_a_post_that_is_not_a_template_is_404(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$id    = $this->page();
		$other = $this->page( self::TEMPLATE );
		$this->acting_as( 'editor' );
		foreach ( array( (string) $other, '0', 'abc', '999999' ) as $tid ) {
			$this->assert_error( $this->insert( $id, $tid ), 'aafm_builder_unknown_template', 404 );
		}
		$this->assertSame( self::SIMPLE, get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_without_templatera_the_schema_still_validates_and_the_source_is_refused(): void {
		$schema = aafm_args_wpbakery_insert_template()['input_schema'];
		$this->assertContains( 'templatera', $schema['properties']['source']['enum'] );
		$this->assertFalse( aafm_templatera_active() );
		$tid = $this->template( self::TEMPLATE );
		$id  = $this->page();
		$this->acting_as( 'editor' );
		$this->assert_error( $this->insert( $id, (string) $tid ), 'aafm_templatera_inactive', 409 );
		$this->assertSame( self::SIMPLE, get_post_field( 'post_content', $id, 'raw' ) );
	}

	/**
	 * An editor whose unfiltered_html is set false at user level, as the smoke run's editor is.
	 *
	 * @return void
	 */
	private function act_as_restricted_editor(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new \WP_User( $editor ) )->add_cap( 'unfiltered_html', false );
		wp_set_current_user( 0 );
		wp_set_current_user( $editor );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
	}

	public function test_reference_inserts_the_templatera_element_into_a_column(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$tid = $this->template( self::TEMPLATE );
		$id  = $this->page();
		$this->acting_as( 'editor' );
		$out = $this->insert( $id, (string) $tid, array( 'mode' => 'reference' ), 2, 'after' );
		$this->assertIsArray( $out );
		$element = '[templatera id="' . $tid . '"]';
		$this->assertSame( '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text]' . $element . '[/vc_column][/vc_row]', get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( 1, $out['inserted'] );
		$this->assertSame( 3, $out['index'] );
		$this->assertSame( aafm_redact_post( get_post( $id ) ), $out['post'] );

		$top = $this->page();
		$this->assert_error( $this->insert( $top, (string) $tid, array( 'mode' => 'reference' ), 0, 'after' ), 'aafm_builder_invalid_nesting', 400 );
		$this->assertSame( self::SIMPLE, get_post_field( 'post_content', $top, 'raw' ), 'A content element cannot sit beside a row.' );
	}

	public function test_reference_is_refused_with_the_saved_source(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
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
		$id = $this->page();
		$this->acting_as( 'editor' );
		$out = $this->insert(
			$id,
			't_1',
			array(
				'mode'   => 'reference',
				'source' => 'saved',
			),
			2
		);
		$this->assert_error( $out, 'aafm_templatera_reference_needs_templatera', 400 );
		$this->assertSame( self::SIMPLE, get_post_field( 'post_content', $id, 'raw' ) );
	}

	/**
	 * Section 4 item 4 (SEC-S9b-2, Ruling B11): a template any edit_posts user may write is untrusted text, so for a
	 * user without unfiltered_html neither a copy nor a reference carries a script kind or unsafe body text onto a
	 * page; the same template inserts for an administrator.
	 */
	public function test_a_reference_to_a_template_with_raw_html_needs_unfiltered_html(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$cases = array(
			'[vc_row][vc_column][vc_raw_html]JTNDcCUzRXglM0MlMkZwJTNF[/vc_raw_html][/vc_column][/vc_row]' => array( 'aafm_builder_needs_unfiltered_html', 403 ),
			'[vc_row][vc_column][vc_column_text]<script>alert(1)</script>[/vc_column_text][/vc_column][/vc_row]' => array( 'aafm_wpbakery_text_needs_unfiltered_html', 403 ),
			'[vc_row][vc_column][vc_column_text]<p onclick="alert(1)">x</p>[/vc_column_text][/vc_column][/vc_row]' => array( 'aafm_wpbakery_text_needs_unfiltered_html', 403 ),
			'[vc_row][vc_column][vc_column_text]<p data-x="1">x</p>[/vc_column_text][/vc_column][/vc_row]' => array( 'aafm_wpbakery_data_attributes_need_unfiltered_html', 400 ),
			'[vc_row][vc_column][vc_column_text]<p>%5Bvc_raw_js%5D</p>[/vc_column_text][/vc_column][/vc_row]' => array( 'aafm_wpbakery_encoded_brackets_need_unfiltered_html', 400 ),
			'[vc_row][vc_column][vc_column_text]<p>&#91;vc_raw_js&#93;</p>[/vc_column_text][/vc_column][/vc_row]' => array( 'aafm_wpbakery_brackets_need_unfiltered_html', 400 ),
			'[vc_row][vc_column][vc_column_text]<p>x</p><img src="a" onerror=[/vc_column_text][/vc_column][/vc_row]' => array( 'aafm_wpbakery_incomplete_html_needs_unfiltered_html', 400 ),
		);
		foreach ( $cases as $text => list( $code, $status ) ) {
			$tid   = (string) $this->template( $text );
			$modes = array(
				'copy'      => 0,
				'reference' => 2,
			);
			foreach ( $modes as $mode => $anchor ) {
				$id = $this->page();
				$this->act_as_restricted_editor();
				$this->assert_error( $this->insert( $id, $tid, array( 'mode' => $mode ), $anchor ), $code, $status );
				$this->assertSame( self::SIMPLE, get_post_field( 'post_content', $id, 'raw' ), $mode . ' ' . $text );
				$this->acting_as( 'administrator' );
				$this->assertIsArray( $this->insert( $id, $tid, array( 'mode' => $mode ), $anchor ), $mode . ' ' . $text );
			}
		}
	}

	/**
	 * Ruling on CX-BR1-1: a template that shows another template by reference passes on that template's checks. A
	 * restricted user's wrapper around an administrator's raw HTML template is refused as a copy and as a reference, page
	 * unchanged; an administrator places it.
	 */
	public function test_a_nested_templatera_reference_gets_the_checks_of_the_template_it_shows(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$raw   = $this->template( '[vc_row][vc_column][vc_raw_html]JTNDcCUzRXglM0MlMkZwJTNF[/vc_raw_html][/vc_column][/vc_row]' );
		$inner = $this->template( '[vc_row][vc_column][templatera id="' . $raw . '"][/vc_column][/vc_row]' );
		$wrap  = (string) $this->template( '[vc_row][vc_column][templatera id="' . $inner . '"][/vc_column][/vc_row]' );
		foreach ( array(
			'copy'      => 0,
			'reference' => 2,
		) as $mode => $anchor ) {
			$id = $this->page();
			$this->act_as_restricted_editor();
			$this->assert_error( $this->insert( $id, $wrap, array( 'mode' => $mode ), $anchor ), 'aafm_builder_needs_unfiltered_html', 403 );
			$this->assertSame( self::SIMPLE, get_post_field( 'post_content', $id, 'raw' ), $mode );
			$this->acting_as( 'administrator' );
			$this->assertIsArray( $this->insert( $id, $wrap, array( 'mode' => $mode ), $anchor ), $mode );
		}
	}

	/**
	 * A nested template gets the chosen template's access check too, Templatera's role list included.
	 */
	public function test_a_nested_template_outside_the_users_role_list_refuses_the_insert(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$admins = $this->template( self::TEMPLATE, null, array( 'administrator' ) );
		$wrap   = (string) $this->template( '[vc_row][vc_column][templatera id="' . $admins . '"][/vc_column][/vc_row]' );
		$id     = $this->page();
		$this->acting_as( 'editor' );
		$this->assert_error( $this->insert( $id, (string) $admins, array( 'mode' => 'reference' ), 2 ), 'aafm_builder_unknown_template', 404 );
		$this->assert_error( $this->insert( $id, $wrap ), 'aafm_builder_reference_refused', 403 );
		$this->assertSame( self::SIMPLE, get_post_field( 'post_content', $id, 'raw' ) );
		$this->acting_as( 'administrator' );
		$this->assertIsArray( $this->insert( $id, $wrap ) );
	}

	/**
	 * A template that shows itself, directly or through another, is refused cleanly for everyone.
	 */
	public function test_a_reference_loop_is_refused(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$a = $this->template( self::TEMPLATE );
		$b = $this->template( '[vc_row][vc_column][templatera id="' . $a . '"][/vc_column][/vc_row]' );
		$this->acting_as( 'administrator' );
		wp_update_post(
			array(
				'ID'           => $a,
				'post_content' => '[vc_row][vc_column][templatera id="' . $b . '"][/vc_column][/vc_row]',
			)
		);
		$self = $this->template( 'x' );
		wp_update_post(
			array(
				'ID'           => $self,
				'post_content' => '[vc_row][vc_column][templatera id="' . $self . '"][/vc_column][/vc_row]',
			)
		);
		foreach ( array( $a, $self ) as $tid ) {
			foreach ( array(
				'copy'      => 0,
				'reference' => 2,
			) as $mode => $anchor ) {
				$id = $this->page();
				$this->assert_error( $this->insert( $id, (string) $tid, array( 'mode' => $mode ), $anchor ), 'aafm_builder_reference_loop', 400 );
				$this->assertSame( self::SIMPLE, get_post_field( 'post_content', $id, 'raw' ) );
			}
		}
	}

	/**
	 * A Templatera element typed through insert-element or given a new id through update-element is a reference too.
	 */
	public function test_a_typed_templatera_element_gets_the_checks_of_the_template_it_names(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$raw   = $this->template( '[vc_row][vc_column][vc_raw_html]JTNDcCUzRXglM0MlMkZwJTNF[/vc_raw_html][/vc_column][/vc_row]' );
		$clean = $this->template( self::TEMPLATE );
		// Templatera fills the id dropdown with the templates the user may choose (getTemplateList()).
		$entry                        = self::templatera_map_entry();
		$entry['params'][0]['value'] += array(
			'Raw'   => (string) $raw,
			'Clean' => (string) $clean,
		);
		WpbakeryMapStub::install( WpbakeryMapStub::map() + array( 'templatera' => $entry ) );
		$typed = function ( int $id, int $tid ) {
			$content  = (string) get_post_field( 'post_content', $id, 'raw' );
			$elements = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) );
			return aafm_exec_wpbakery_insert_element(
				array(
					'post_id'          => $id,
					'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
					'anchor_index'     => 2,
					'anchor_expect'    => array(
						'tag'         => 'vc_column_text',
						'fingerprint' => aafm_builder_fingerprint( $content, $elements[2] ),
					),
					'placement'        => 'after',
					'element'          => array(
						'tag'  => 'templatera',
						'atts' => array( 'id' => (string) $tid ),
					),
				)
			);
		};
		$id    = $this->page();
		$this->act_as_restricted_editor();
		$this->assert_error( $typed( $id, $raw ), 'aafm_builder_needs_unfiltered_html', 403 );
		$this->assertSame( self::SIMPLE, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertIsArray( $typed( $id, $clean ) );

		$content = (string) get_post_field( 'post_content', $id, 'raw' );
		$el      = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) );
		$update  = static function ( int $tid ) use ( $id, $content, $el ) {
			return aafm_exec_wpbakery_update_element(
				array(
					'post_id'          => $id,
					'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
					'index'            => 3,
					'expect'           => array(
						'tag'         => 'templatera',
						'fingerprint' => aafm_builder_fingerprint( $content, $el[3] ),
					),
					'set_atts'         => array( 'id' => (string) $tid ),
				)
			);
		};
		$this->assert_error( $update( $raw ), 'aafm_builder_needs_unfiltered_html', 403 );
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
		$this->acting_as( 'administrator' );
		$this->assertIsArray( $update( $raw ) );
		$this->assertStringContainsString( '[templatera id="' . $raw . '"]', (string) get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_a_retried_reference_is_refused_and_placed_once(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$tid   = (string) $this->template( self::TEMPLATE );
		$id    = $this->page();
		$first = aafm_builder_page_fingerprint( self::SIMPLE );
		$this->acting_as( 'editor' );
		$this->assertIsArray( $this->insert( $id, $tid, array( 'mode' => 'reference' ), 2 ) );
		$after    = (string) get_post_field( 'post_content', $id, 'raw' );
		$elements = aafm_builder_elements( $after, aafm_wpbakery_shortcode_tags( $after ) );
		$out      = aafm_exec_wpbakery_insert_template(
			array(
				'post_id'          => $id,
				'page_fingerprint' => $first,
				'template_id'      => $tid,
				'source'           => 'templatera',
				'mode'             => 'reference',
				'anchor_index'     => 2,
				'anchor_expect'    => array(
					'tag'         => 'vc_column_text',
					'fingerprint' => aafm_builder_fingerprint( $after, $elements[2] ),
				),
				'placement'        => 'after',
			)
		);
		$this->assert_error( $out, 'aafm_builder_page_changed', 409 );
		$this->assertSame( 1, substr_count( (string) get_post_field( 'post_content', $id, 'raw' ), '[templatera ' ) );
	}

	public function test_the_insert_schema_has_source_and_mode(): void {
		$schema = aafm_args_wpbakery_insert_template()['input_schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'saved', 'templatera' ), $schema['properties']['source']['enum'] );
		$this->assertSame( array( 'copy', 'reference' ), $schema['properties']['mode']['enum'] );
		$this->assertSame( 'copy', $schema['properties']['mode']['default'] );
		$this->assertSame( array( 'post_id', 'page_fingerprint', 'template_id' ), $schema['required'] );
		$row = aafm_get_abilities_registry_full()['aafm/wpbakery-insert-template'];
		foreach ( array( $row['description'], aafm_ability_disclosures()['aafm/wpbakery-insert-template'] ) as $text ) {
			$this->assertStringContainsString( 'Templatera', $text );
			$this->assertStringContainsString( 'reference', $text );
			$this->assertStringNotContainsString( ' - ', $text );
		}
	}

	public function test_a_templatera_row_validates_against_the_list_output_schema(): void {
		add_filter( 'aafm_templatera_active', '__return_true' );
		$this->template( self::TEMPLATE );
		$this->acting_as( 'editor' );
		$out    = aafm_exec_wpbakery_list_templates( array( 'include_content' => true ) );
		$schema = aafm_args_wpbakery_list_templates()['output_schema'];
		$this->assertSame( array( 'saved', 'templatera' ), $schema['properties']['templates']['items']['properties']['source']['enum'] );
		$this->assertTrue( rest_validate_value_from_schema( $out, $schema, 'output' ) );
	}

	/**
	 * A recording stand-in for Avada's term meta object (Fusion_Data_TermMeta), served through the avada_term_meta
	 * seam row: get_all_meta(), set() (which drops a key set to '' as Avada's does) and reset_data().
	 *
	 * @param array<string,mixed> $stored  Stored settings.
	 * @param bool                $sticky  Whether set() is lost (the read back keeps the old values).
	 * @return object
	 */
	private function slider_meta( array $stored, bool $sticky = false ): object {
		$meta              = new class( $stored, $sticky ) {
			/**
			 * Stored settings.
			 *
			 * @var array<string,mixed>
			 */
			public $db;
			/**
			 * Loaded settings, null until read.
			 *
			 * @var array<string,mixed>|null
			 */
			public $data;
			/**
			 * Calls made, in order.
			 *
			 * @var list<string>
			 */
			public $calls = array();
			/**
			 * Whether set() is lost.
			 *
			 * @var bool
			 */
			private $sticky;
			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $stored Stored.
			 * @param bool                $sticky Sticky.
			 */
			public function __construct( array $stored, bool $sticky ) {
				$this->db     = $stored;
				$this->sticky = $sticky;
			}
			/**
			 * Avada's getter.
			 *
			 * @return array<string,mixed>
			 */
			public function get_all_meta(): array {
				$this->calls[] = 'get_all_meta';
				if ( null === $this->data ) {
					$this->data = $this->db;
				}
				return $this->data;
			}
			/**
			 * Avada's setter.
			 *
			 * @param string $key   Key.
			 * @param mixed  $value Value.
			 * @return bool
			 */
			public function set( string $key, $value ): bool {
				$this->calls[] = 'set:' . $key . '=' . (string) $value;
				// Avada's set() loads the data first, as get_all_meta() does.
				if ( null === $this->data ) {
					$this->data = $this->db;
				}
				$this->data[ $key ] = $value;
				if ( '' === $value ) {
					unset( $this->data[ $key ] );
				}
				if ( ! $this->sticky ) {
					$this->db = (array) $this->data;
				}
				return true;
			}
			/**
			 * Avada's cache reset.
			 *
			 * @return void
			 */
			public function reset_data(): void {
				$this->calls[] = 'reset_data';
				$this->data    = null;
			}
		};
		$this->slider_seam = static function ( $resolved, string $entry ) use ( $meta ) {
			return 'avada_term_meta' === $entry ? static fn(): object => $meta : $resolved;
		};
		add_filter( 'aafm_builder_vendor', $this->slider_seam, 10, 2 );
		return $meta;
	}

	/**
	 * The avada_term_meta seam filter.
	 *
	 * @var callable|null
	 */
	private $slider_seam;

	/**
	 * A slider: a term of a registered slide-page taxonomy, as Avada Core registers it.
	 *
	 * @return int
	 */
	private function slider(): int {
		register_post_type( 'slide', array( 'public' => true ) );
		register_taxonomy( 'slide-page', 'slide' );
		return (int) self::factory()->term->create( array( 'taxonomy' => 'slide-page' ) );
	}

	/**
	 * The stored form of a fresh slider after one save through Avada's slider screen (s14-slider-keys.md).
	 *
	 * @return array<string,string>
	 */
	private static function screen_saved(): array {
		return array(
			'slider_width'           => '100%',
			'slider_height'          => '500px',
			'slider_content_width'   => '',
			'full_screen'            => '0',
			'parallax'               => '0',
			'slider_indicator'       => '',
			'slider_indicator_color' => '',
			'nav_arrows'             => '1',
			'nav_arrow_size'         => '25px',
			'nav_box_width'          => '63px',
			'nav_box_height'         => '63px',
			'slideshow_speed'        => '7000',
			'loop'                   => '1',
			'autoplay'               => '1',
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'animation'              => 'fade',
			'animation_speed'        => '600',
			'typo_sensitivity'       => '0.1',
			'typo_factor'            => '1.5',
		);
	}

	/**
	 * Run the slider settings tool.
	 *
	 * @param int   $term Term.
	 * @param mixed $set  Set.
	 * @return array<string,mixed>|WP_Error
	 */
	private function slider_update( int $term, $set ) {
		return aafm_exec_avada_update_slider_settings(
			array(
				'term_id' => $term,
				'set'     => $set,
			)
		);
	}

	/**
	 * Fix pass 2, ruling 1: slider previous goes back as returned, null where a setting was not stored.
	 */
	public function test_slider_previous_goes_back_byte_for_byte(): void {
		$term   = $this->slider();
		$stored = array_diff_key( self::screen_saved(), array( 'slider_indicator_color' => true ) );
		$meta   = $this->slider_meta( $stored );
		$this->acting_as( 'administrator' );
		$out = $this->slider_update(
			$term,
			array(
				'autoplay'               => false,
				'slider_width'           => '80%',
				'slider_indicator_color' => '#ff0000',
			)
		);
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$back = $this->slider_update( $term, json_decode( (string) wp_json_encode( $out['previous'] ), true ) );
		$this->assertIsArray( $back, is_wp_error( $back ) ? $back->get_error_message() : '' );
		$this->assertSame( $stored, $meta->db );
	}

	public function test_the_slider_keys_allowlist_is_pinned(): void {
		$this->assertSame(
			array( 'slider_indicator', 'slider_indicator_color', 'typo_sensitivity', 'typo_factor', 'nav_box_width', 'nav_box_height', 'nav_arrow_size', 'slider_width', 'slider_height', 'full_screen', 'parallax', 'nav_arrows', 'autoplay', 'loop', 'animation', 'slideshow_speed', 'animation_speed' ),
			array_keys( aafm_avada_slider_setting_keys() )
		);
	}

	public function test_set_writes_only_the_named_keys_through_avadas_object(): void {
		$term = $this->slider();
		$meta = $this->slider_meta( self::screen_saved() );
		$this->acting_as( 'administrator' );
		$out = $this->slider_update(
			$term,
			array(
				'slider_width' => '80%',
				'autoplay'     => false,
				'animation'    => 'slide',
			)
		);
		$this->assertIsArray( $out );
		$this->assertSame( array( 'get_all_meta', 'set:slider_width=80%', 'set:autoplay=0', 'set:animation=slide', 'reset_data', 'get_all_meta' ), $meta->calls );
		$this->assertSame( array( 'slider_width', 'autoplay', 'animation' ), $out['changed'] );
		$this->assertSame(
			array_replace(
				self::screen_saved(),
				array(
					'slider_width' => '80%',
					'autoplay'     => '0',
					'animation'    => 'slide',
				)
			),
			$meta->db
		);
	}

	public function test_previous_holds_the_old_values_from_avadas_getter(): void {
		$term = $this->slider();
		$this->slider_meta( array( 'loop' => '1' ) );
		$this->acting_as( 'administrator' );
		$out = $this->slider_update(
			$term,
			array(
				'loop'         => '0',
				'nav_arrows'   => true,
				'slider_width' => '90%',
			)
		);
		$this->assertSame( $term, $out['term_id'] );
		$this->assertSame(
			array(
				'loop'         => '1',
				'nav_arrows'   => null,
				'slider_width' => null,
			),
			$out['previous']
		);
	}

	public function test_a_key_outside_the_allowlist_is_refused(): void {
		$term = $this->slider();
		$meta = $this->slider_meta( self::screen_saved() );
		$this->acting_as( 'administrator' );
		foreach ( array( 'orderby', 'slider_content_width', 'nope' ) as $key ) {
			$out = $this->slider_update( $term, array( $key => 'x' ) );
			$this->assert_error( $out, 'aafm_builder_attribute_not_writable', 400 );
			$this->assertStringContainsString( $key, $out->get_error_message() );
		}
		$this->assertSame( array(), $meta->calls, 'Refused before anything is read.' );
	}

	/**
	 * Values per kind: key, input, stored form or null when refused.
	 *
	 * @return array<string,array{0:string,1:mixed,2:string|null}>
	 */
	public function slider_value_cases(): array {
		return array(
			'size px'              => array( 'slider_height', '420px', '420px' ),
			'size percent'         => array( 'slider_width', '75%', '75%' ),
			'size no unit'         => array( 'slider_width', '75', null ),
			'size css injection'   => array( 'slider_width', '1px;background:url(x)', null ),
			'size number'          => array( 'nav_box_width', 60, null ),
			'boolean true'         => array( 'parallax', true, '1' ),
			'boolean stored'       => array( 'parallax', '0', '0' ),
			'boolean word'         => array( 'parallax', 'yes', null ),
			'animation'            => array( 'animation', 'slide', 'slide' ),
			'animation other'      => array( 'animation', 'zoom', null ),
			'indicator'            => array( 'slider_indicator', 'pagination_circles', 'pagination_circles' ),
			'indicator none'       => array( 'slider_indicator', '', '' ),
			'indicator other'      => array( 'slider_indicator', 'dots', null ),
			'colour'               => array( 'slider_indicator_color', '#ff0000', '#ff0000' ),
			'colour bad'           => array( 'slider_indicator_color', 'red;}', null ),
			'integer'              => array( 'slideshow_speed', 5000, '5000' ),
			'integer stored'       => array( 'animation_speed', '800', '800' ),
			'integer negative'     => array( 'animation_speed', -1, null ),
			'integer float string' => array( 'animation_speed', '1.5', null ),
			'number'               => array( 'typo_factor', 2.5, '2.5' ),
			'number stored'        => array( 'typo_sensitivity', '0.2', '0.2' ),
			'number word'          => array( 'typo_sensitivity', 'big', null ),
		);
	}

	/**
	 * Each kind's rule, alone and through the tool.
	 *
	 * @dataProvider slider_value_cases
	 *
	 * @param string      $key      Key.
	 * @param mixed       $value    Input.
	 * @param string|null $expected Stored form, null when refused.
	 */
	public function test_each_kind_validates_its_value( string $key, $value, ?string $expected ): void {
		$this->assertSame( $expected, aafm_avada_slider_setting_value( aafm_avada_slider_setting_keys()[ $key ], $value, $key ) );
		$term = $this->slider();
		$this->slider_meta( array( 'animation' => 'fade' ) );
		$this->acting_as( 'administrator' );
		$out = $this->slider_update( $term, array( $key => $value ) );
		if ( null === $expected ) {
			$this->assert_error( $out, 'aafm_builder_invalid_value', 400 );
		} else {
			$this->assertIsArray( $out );
		}
	}

	public function test_a_term_of_another_taxonomy_is_refused(): void {
		$this->slider();
		$meta = $this->slider_meta( array() );
		$cat  = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->acting_as( 'administrator' );
		$out = $this->slider_update( $cat, array( 'loop' => true ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertSame( array(), $meta->calls );
	}

	public function test_an_editor_is_refused_and_cannot_discover_the_tool(): void {
		$args = aafm_args_avada_update_slider_settings();
		$this->assertSame( 'aafm_perm_manage_options', $args['permission_callback'] );
		$this->assertNull( aafm_ability_list_permission( 'aafm/avada-update-slider-settings' ), 'Object independent: discovery runs the real callback.' );
		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_perm_manage_options() );
		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_perm_manage_options() );
	}

	public function test_the_tool_is_unavailable_without_avada_core(): void {
		$term = $this->slider();
		$this->acting_as( 'administrator' );
		$absent = static fn( $resolved, string $entry ) => 'avada_term_meta' === $entry ? null : $resolved;
		add_filter( 'aafm_builder_vendor', $absent, 20, 2 );
		$this->assert_error( $this->slider_update( $term, array( 'loop' => true ) ), 'aafm_avada_slider_unavailable', 409 );
		remove_filter( 'aafm_builder_vendor', $absent, 20 );

		$this->slider_meta( array() );
		unregister_taxonomy( 'slide-page' );
		$this->assert_error( $this->slider_update( $term, array( 'loop' => true ) ), 'aafm_avada_slider_unavailable', 409 );
		$this->assertInstanceOf( \Closure::class, aafm_builder_vendor_rows()['avada_term_meta']['target'] );
		$this->assertSame( 'fusion_data', aafm_builder_vendor_rows()['avada_term_meta']['requires'] );
	}

	public function test_a_write_the_read_back_does_not_show_is_unconfirmed(): void {
		$term = $this->slider();
		$this->slider_meta( self::screen_saved(), true );
		$this->acting_as( 'administrator' );
		$out = $this->slider_update( $term, array( 'slider_height' => '300px' ) );
		$this->assert_error( $out, 'aafm_avada_slider_write_unconfirmed', 500 );
		$this->assertSame( array( 'slider_height' => '500px' ), $out->get_error_data()['previous'] );
	}

	public function test_setting_the_stored_values_writes_nothing(): void {
		$term = $this->slider();
		$meta = $this->slider_meta( self::screen_saved() );
		$this->acting_as( 'administrator' );
		$out = $this->slider_update(
			$term,
			array(
				'slider_width'     => '100%',
				'autoplay'         => true,
				'slideshow_speed'  => 7000,
				'slider_indicator' => '',
			)
		);
		$this->assertSame( array(), $out['changed'] );
		$this->assertEquals( new \stdClass(), $out['previous'] );
		$this->assertSame( array( 'get_all_meta' ), $meta->calls );
	}

	public function test_wrong_json_types_are_refused_cleanly(): void {
		$term = $this->slider();
		$this->slider_meta( array() );
		$this->acting_as( 'administrator' );
		foreach ( array( 'x', array(), array( 'loop' => array( 1 ) ), array( 'slider_width' => false ), array( 'animation' => 1 ) ) as $set ) {
			$out = $this->slider_update( $term, $set );
			$this->assertInstanceOf( WP_Error::class, $out );
			$this->assertSame( 400, $out->get_error_data()['status'] ?? null );
		}
		$schema = aafm_args_avada_update_slider_settings()['input_schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'term_id', 'set' ), $schema['required'] );
		$this->assertSame( array( 'term_id', 'set' ), array_keys( $schema['properties'] ) );
	}

	public function test_the_slider_tool_starts_off_and_is_in_no_preset_and_is_described(): void {
		$name = 'aafm/avada-update-slider-settings';
		delete_option( 'aafm_enabled_abilities' );
		$this->assertNotContains( $name, aafm_get_enabled_abilities() );
		foreach ( array( 'read_only', 'content_editor', 'shop_manager' ) as $preset ) {
			$this->assertFalse( aafm_preset_permits( $preset, $name ), $preset );
		}
		$this->assertSame( 'not_covered', aafm_undo_refusals()[ $name ] );
		$this->assertSame( 'Ran an Avada slider settings update on slider #12', aafm_build_activity_detail( $name, array( 'term_id' => 12 ) ) );
		$args = aafm_args_avada_update_slider_settings();
		$this->assertSame( 'aafm-writes', $args['category'] );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			$args['meta']['annotations']
		);
		$row = aafm_get_abilities_registry_full()[ $name ];
		$this->assertSame( 'writes', $row['group'] );
		$this->assertSame( 'write', $row['risk'] );
		$this->assertSame( 'avada', $row['subject'] );
		$this->assertNotContains( $name, aafm_non_removal_destructive_abilities() );
		foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'Avada', 'every page', 'previous', 'manage site options', 'Avada Core' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text, $phrase );
			}
			$this->assertStringNotContainsString( 'Revolution', $text );
		}
		$this->assertStringContainsString( 'first read', $row['description'] );
	}

	public function test_page_options_work_on_a_slide_post_once_the_type_is_allowed(): void {
		$name = static fn(): string => 'fusion_options';
		$seam = static fn( $resolved, string $entry ) => 'avada_option_name' === $entry ? $name : $resolved;
		add_filter( 'aafm_builder_vendor', $seam, 30, 2 );
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		register_post_type( 'slide', array( 'public' => true ) );
		register_post_type( 'themefusion_elastic', array( 'public' => true ) );
		$this->acting_as( 'administrator' );
		$slide   = self::factory()->post->create( array( 'post_type' => 'slide' ) );
		$elastic = self::factory()->post->create( array( 'post_type' => 'themefusion_elastic' ) );
		$out     = aafm_exec_avada_update_page_options(
			array(
				'post_id' => $slide,
				'set'     => array(
					'heading'   => 'Slide heading',
					'link_type' => 'button',
				),
			)
		);
		$this->assertIsArray( $out );
		$this->assertSame( array( 'heading', 'link_type' ), $out['changed'] );
		$this->assertSame( 'Slide heading', get_post_meta( $slide, '_fusion', true )['heading'] );
		$this->assertIsArray(
			aafm_exec_avada_update_page_options(
				array(
					'post_id' => $elastic,
					'set'     => array( 'caption_1' => 'Elastic caption' ),
				)
			)
		);
		$this->assertSame( 'Elastic caption', get_post_meta( $elastic, '_fusion', true )['caption_1'] );
		remove_filter( 'aafm_builder_vendor', $seam, 30 );
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		unregister_post_type( 'themefusion_elastic' );
	}
}
