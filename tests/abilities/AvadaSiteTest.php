<?php
/**
 * Avada site tools (plan 326 Session 12): the library item insert (design AV-8), with the trusted fusion_global root of
 * Ruling R-S12-1 in the shared insert path.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\AvadaMapStub;
use AAFM\Tests\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/Support/AvadaMapStub.php';

final class AvadaSiteTest extends TestCase {

	/**
	 * Fusion tags beyond the baseline that Avada registers as shortcodes, which PHPUnit does not load.
	 */
	private const TAGS = array( 'fusion_global', 'fusion_title', 'fusion_button', 'fusion_code' );

	private const PAGE = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_text]Hello[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

	private const SECTION = '[fusion_builder_container hundred_percent="no"][fusion_builder_row][fusion_builder_column type="1_2"][fusion_text]<p>Saved &amp; section</p>[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

	private const ELEMENT = '[fusion_text]<p>Saved element</p>[/fusion_text]';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		AvadaMapStub::install();
		foreach ( self::TAGS as $tag ) {
			add_shortcode( $tag, '__return_empty_string' );
		}
		foreach ( array( 'fusion_element', 'fusion_template' ) as $type ) {
			register_post_type(
				$type,
				array(
					'public'          => false,
					'capability_type' => 'post',
					'map_meta_cap'    => true,
				)
			);
		}
		register_taxonomy( 'element_category', array( 'fusion_element' ), array( 'hierarchical' => true ) );
	}

	public function tear_down(): void {
		AvadaMapStub::remove();
		foreach ( self::TAGS as $tag ) {
			remove_shortcode( $tag );
		}
		unregister_taxonomy( 'element_category' );
		unregister_post_type( 'fusion_element' );
		unregister_post_type( 'fusion_template' );
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		parent::tear_down();
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
		$this->assertSame( $code, $out->get_error_code(), $out->get_error_message() );
		$this->assertSame( $status, $out->get_error_data()['status'] ?? null );
	}

	/**
	 * An administrator who may post unfiltered HTML.
	 *
	 * @return int
	 */
	private function act_as_admin(): int {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		return $admin;
	}

	/**
	 * An editor whose unfiltered_html is set false at user level, as the smoke run's editor is.
	 *
	 * @return int
	 */
	private function act_as_restricted_editor(): int {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new \WP_User( $editor ) )->add_cap( 'unfiltered_html', false );
		wp_set_current_user( 0 );
		wp_set_current_user( $editor );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		return $editor;
	}

	/**
	 * An Avada page.
	 *
	 * @param string   $content Content.
	 * @param int|null $author  Author, or the current user.
	 * @return int
	 */
	private function avada_post( string $content = self::PAGE, ?int $author = null ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_author'  => $author ?? get_current_user_id(),
				'post_content' => $content,
			)
		);
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		return $id;
	}

	/**
	 * A library item.
	 *
	 * @param string   $content Content.
	 * @param string   $term    element_category slug, or '' for a template.
	 * @param bool     $is_global Whether it is a global item.
	 * @param int|null $author  Author, or the current user.
	 * @return int
	 */
	private function item( string $content, string $term = 'sections', bool $is_global = false, ?int $author = null ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => '' === $term ? 'fusion_template' : 'fusion_element',
				'post_status'  => 'publish',
				'post_author'  => $author ?? get_current_user_id(),
				'post_content' => $content,
			)
		);
		if ( '' !== $term ) {
			wp_set_object_terms( $id, $term, 'element_category' );
		}
		if ( $is_global ) {
			add_post_meta( $id, '_fusion_is_global', 'yes' );
		}
		return $id;
	}

	/**
	 * Insert input against the nth element with a tag.
	 *
	 * @param int    $id        Page.
	 * @param int    $item      Item.
	 * @param string $tag       Anchor tag.
	 * @param string $placement Placement.
	 * @return array<string,mixed>
	 */
	private function input( int $id, int $item, string $tag = 'fusion_builder_container', string $placement = 'after' ): array {
		$content = (string) get_post( $id )->post_content;
		foreach ( (array) aafm_builder_elements( $content, aafm_fusion_shortcode_tags() ) as $element ) {
			if ( $tag === $element['tag'] ) {
				return array(
					'post_id'          => $id,
					'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
					'item_id'          => $item,
					'anchor_index'     => $element['index'],
					'anchor_expect'    => array(
						'tag'         => $tag,
						'fingerprint' => aafm_builder_fingerprint( $content, $element ),
					),
					'placement'        => $placement,
				);
			}
		}
		$this->fail( "No $tag." );
	}

	/**
	 * Assert a refusal left the page as it was.
	 *
	 * @param mixed  $out    Result.
	 * @param string $code   Code.
	 * @param int    $status Status.
	 * @param int    $id     Page.
	 * @param string $before Content before.
	 * @return void
	 */
	private function assert_refused( $out, string $code, int $status, int $id, string $before ): void {
		$this->assert_error( $out, $code, $status );
		clean_post_cache( $id );
		$this->assertSame( $before, get_post( $id )->post_content );
	}

	public function test_a_saved_section_is_copied_at_the_top_level(): void {
		$this->act_as_admin();
		$id   = $this->avada_post();
		$item = $this->item( self::SECTION );
		$out  = aafm_exec_avada_insert_library_item( $this->input( $id, $item ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( self::PAGE . self::SECTION, get_post( $id )->post_content );
		$this->assertSame( 4, $out['index'] );
		$this->assertSame( 4, $out['inserted'] );
		$this->assertSame( aafm_builder_page_fingerprint( self::PAGE . self::SECTION ), $out['page_fingerprint'] );
	}

	public function test_the_items_stored_bytes_land_in_the_page_unchanged(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$source = '[fusion_builder_container class="a  b"][fusion_builder_row][fusion_builder_column type="1_1" spacing=\'4%\'][fusion_text]<p>x &#8211; y</p>[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		$item   = $this->item( $source );
		$this->assertIsArray( aafm_exec_avada_insert_library_item( $this->input( $id, $item ) ) );
		$this->assertSame( self::PAGE . $source, get_post( $id )->post_content );
	}

	public function test_a_saved_element_is_copied_into_a_column(): void {
		$this->act_as_admin();
		$id   = $this->avada_post();
		$item = $this->item( self::ELEMENT, 'elements' );
		$out  = aafm_exec_avada_insert_library_item( $this->input( $id, $item, 'fusion_text' ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( str_replace( 'Hello[/fusion_text]', 'Hello[/fusion_text]' . self::ELEMENT, self::PAGE ), get_post( $id )->post_content );
		$this->assertSame( 1, $out['inserted'] );
	}

	public function test_a_section_cannot_be_placed_inside_a_column(): void {
		$this->act_as_admin();
		$id   = $this->avada_post();
		$item = $this->item( self::SECTION );
		$this->assert_refused( aafm_exec_avada_insert_library_item( $this->input( $id, $item, 'fusion_text' ) ), 'aafm_builder_invalid_nesting', 400, $id, self::PAGE );
	}

	public function test_a_global_item_is_inserted_as_a_fusion_global_reference(): void {
		$this->act_as_admin();
		$id   = $this->avada_post();
		$item = $this->item( self::SECTION, 'sections', true );
		$out  = aafm_exec_avada_insert_library_item( $this->input( $id, $item ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$ref = '[fusion_global id="' . $item . '"]';
		$this->assertSame( self::PAGE . $ref, get_post( $id )->post_content );
		$this->assertSame( 1, $out['inserted'] );
		$this->assertSame( substr( hash( 'sha256', $ref ), 0, 16 ), $out['fingerprint'] );

		// A global element goes into a column, as the element it shows.
		$element = $this->item( self::ELEMENT, 'elements', true );
		$this->assertIsArray( aafm_exec_avada_insert_library_item( $this->input( $id, $element, 'fusion_text' ) ) );
		$this->assertStringContainsString( 'Hello[/fusion_text][fusion_global id="' . $element . '"][/fusion_builder_column]', get_post( $id )->post_content );
		// And a global section never goes inside a column.
		$before = (string) get_post( $id )->post_content;
		$this->assert_refused( aafm_exec_avada_insert_library_item( $this->input( $id, $item, 'fusion_text' ) ), 'aafm_builder_invalid_nesting', 400, $id, $before );
	}

	public function test_the_trusted_path_takes_a_fusion_global_root_and_the_untrusted_path_does_not(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$ref    = (string) $this->item( self::SECTION, 'sections', true );
		$anchor = $this->input( $id, 1 );
		$anchor = array(
			'index'     => $anchor['anchor_index'],
			'expect'    => $anchor['anchor_expect'],
			'placement' => 'after',
		);
		$node   = array(
			'tag'      => 'fusion_global',
			'atts'     => array( 'id' => $ref ),
			'children' => array(),
			'source'   => '[fusion_global id="' . $ref . '"]',
			'place_as' => 'fusion_builder_container',
		);
		$this->assert_refused( aafm_avada_insert_tree( get_post( $id ), array( $node ), $anchor, false ), 'aafm_builder_unknown_element', 400, $id, self::PAGE );
		foreach ( array(
			array(
				'atts' => array(
					'id'    => $ref,
					'class' => 'x',
				),
			),
			array( 'atts' => array( 'id' => '0' ) ),
			array( 'place_as' => null ),
			array( 'place_as' => 'fusion_global' ),
			array( 'children' => array( array( 'tag' => 'fusion_text' ) ) ),
		) as $bad ) {
			$this->assert_refused( aafm_avada_insert_tree( get_post( $id ), array( array_merge( $node, $bad ) ), $anchor, true ), 'aafm_builder_invalid_value', 400, $id, self::PAGE );
		}
		$out = aafm_avada_insert_tree( get_post( $id ), array( $node ), $anchor, true );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( self::PAGE . '[fusion_global id="' . $ref . '"]', get_post( $id )->post_content );
	}

	public function test_a_retried_library_insert_is_refused_and_stores_the_item_once(): void {
		$this->act_as_admin();
		$id    = $this->avada_post();
		$item  = $this->item( self::SECTION );
		$input = $this->input( $id, $item );
		$this->assertIsArray( aafm_exec_avada_insert_library_item( $input ) );
		$after = (string) get_post( $id )->post_content;
		$this->assert_refused( aafm_exec_avada_insert_library_item( $input ), 'aafm_builder_page_changed', 409, $id, $after );
		$this->assertSame( 1, substr_count( $after, 'Saved &amp; section' ) );
	}

	public function test_a_stale_anchor_is_refused(): void {
		$this->act_as_admin();
		$id                                    = $this->avada_post();
		$item                                  = $this->item( self::SECTION );
		$input                                 = $this->input( $id, $item );
		$input['anchor_expect']['fingerprint'] = str_repeat( '0', 16 );
		$this->assert_refused( aafm_exec_avada_insert_library_item( $input ), 'aafm_builder_element_mismatch', 409, $id, self::PAGE );
	}

	public function test_a_failed_category_read_is_an_error(): void {
		$this->act_as_admin();
		$id   = $this->avada_post();
		$item = $this->item( self::ELEMENT, 'elements' );
		$fail = static fn( $terms, $post_id, $taxonomy ) => 'element_category' === $taxonomy ? new WP_Error( 'db', 'down' ) : $terms;
		add_filter( 'get_the_terms', $fail, 10, 3 );
		$out  = aafm_exec_avada_insert_library_item( $this->input( $id, $item, 'fusion_text' ) );
		remove_filter( 'get_the_terms', $fail, 10 );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( aafm_generic_error()->get_error_code(), $out->get_error_code() );
		$this->assertSame( self::PAGE, get_post( $id )->post_content );
	}

	public function test_a_post_that_is_not_a_library_item_is_refused(): void {
		$this->act_as_admin();
		$id    = $this->avada_post();
		$other = $this->avada_post( self::SECTION );
		$this->assert_refused( aafm_exec_avada_insert_library_item( $this->input( $id, $other ) ), 'aafm_builder_unknown_template', 404, $id, self::PAGE );
		$this->assert_refused( aafm_exec_avada_insert_library_item( $this->input( $id, 999999 ) ), 'aafm_builder_unknown_template', 404, $id, self::PAGE );
	}

	public function test_an_item_the_user_cannot_edit_is_refused(): void {
		$admin  = $this->act_as_admin();
		$item   = $this->item( self::SECTION, 'sections', false, $admin );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = $this->avada_post( self::PAGE, $author );
		$this->assert_refused( aafm_exec_avada_insert_library_item( $this->input( $id, $item ) ), 'aafm_builder_unknown_template', 404, $id, self::PAGE );
	}

	public function test_an_author_cannot_place_a_global_item_they_wrote(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id     = $this->avada_post( self::PAGE, $author );
		$global = $this->item( self::SECTION, 'sections', true, $author );
		$plain  = $this->item( self::SECTION, 'sections', false, $author );
		$this->assertFalse( current_user_can( 'edit_private_posts' ) );
		$this->assert_refused( aafm_exec_avada_insert_library_item( $this->input( $id, $global ) ), 'aafm_builder_unknown_template', 404, $id, self::PAGE );
		$this->assertIsArray( aafm_exec_avada_insert_library_item( $this->input( $id, $plain ) ), 'Ruling R-S12-2 only adds edit_private_posts for a global item.' );
	}

	/**
	 * Stored item text a restricted editor may not place, as a copy or by reference, each with its refusal.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function refused_text(): array {
		$wrap = static fn( string $inner ): string => '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"]' . $inner . '[/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		return array(
			'code block'      => array( $wrap( '[fusion_code]eA==[/fusion_code]' ), 'aafm_builder_needs_unfiltered_html' ),
			'script element'  => array( $wrap( '[fusion_text]<script>x()</script>[/fusion_text]' ), 'aafm_avada_stored_text_needs_unfiltered_html' ),
			'onclick setting' => array( $wrap( '[fusion_button link_attributes="onclick=x"]B[/fusion_button]' ), 'aafm_builder_needs_unfiltered_html' ),
			'onclick in html' => array( $wrap( '[fusion_text]<p onclick="x">a</p>[/fusion_text]' ), 'aafm_avada_stored_text_needs_unfiltered_html' ),
			'data attribute'  => array( $wrap( '[fusion_text]<p data-x="1">a</p>[/fusion_text]' ), 'aafm_avada_data_attributes_need_unfiltered_html' ),
			'percent bracket' => array( $wrap( '[fusion_text]a %5Bb%5D[/fusion_text]' ), 'aafm_avada_encoded_brackets_need_unfiltered_html' ),
			'entity bracket'  => array( $wrap( '[fusion_text]a &#91;b&#93;[/fusion_text]' ), 'aafm_avada_brackets_need_unfiltered_html' ),
			'unfinished tag'  => array( $wrap( '[fusion_text]a <p class="x[/fusion_text]' ), 'aafm_avada_incomplete_html_needs_unfiltered_html' ),
		);
	}

	/**
	 * SEC-S9b-2 and Rulings B11 and R-S12-3: every refusal class, for a copy and for a global reference.
	 *
	 * @dataProvider refused_text
	 *
	 * @param string $source Item text.
	 * @param string $code   Refusal.
	 */
	public function test_unsafe_item_text_is_refused_for_an_editor_as_a_copy_and_as_a_reference_and_placed_for_an_admin( string $source, string $code ): void {
		$editor = $this->act_as_restricted_editor();
		foreach ( array( false, true ) as $global ) {
			// Stored as written: the item is saved by a user whose text kses leaves alone, then placed by the editor.
			$this->act_as_admin();
			$item = $this->item( $source, 'sections', $global, $editor );
			$id   = $this->avada_post( self::PAGE, $editor );
			$this->assertSame( $source, get_post( $item )->post_content );
			wp_set_current_user( $editor );
			$out = aafm_exec_avada_insert_library_item( $this->input( $id, $item ) );
			$this->assertInstanceOf( WP_Error::class, $out, $global ? 'reference' : 'copy' );
			$this->assertSame( $code, $out->get_error_code(), ( $global ? 'reference: ' : 'copy: ' ) . $out->get_error_message() );
			clean_post_cache( $id );
			$this->assertSame( self::PAGE, get_post( $id )->post_content, 'Page byte-identical.' );
		}
		$this->act_as_admin();
		foreach ( array( false, true ) as $global ) {
			$item = $this->item( $source, 'sections', $global );
			$id   = $this->avada_post();
			$out  = aafm_exec_avada_insert_library_item( $this->input( $id, $item ) );
			$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
			$this->assertStringEndsWith( $global ? '[fusion_global id="' . $item . '"]' : $source, (string) get_post( $id )->post_content );
		}
	}

	/**
	 * Ruling on CX-BR1-1: a global item that shows another global item passes on that item's checks. A restricted
	 * editor's wrapper around an administrator's code block is refused with the page unchanged; an administrator places
	 * it; a wrapper that shows itself is refused for everyone.
	 */
	public function test_a_nested_global_reference_gets_the_checks_of_the_item_it_shows(): void {
		$wrap   = static fn( string $inner ): string => '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"]' . $inner . '[/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		$editor = $this->act_as_restricted_editor();
		$this->act_as_admin();
		$code    = $this->item( $wrap( '[fusion_code]eA==[/fusion_code]' ), 'sections', true );
		$wrapper = $this->item( $wrap( '[fusion_global id="' . $code . '"]' ), 'sections', true, $editor );
		$id      = $this->avada_post( self::PAGE, $editor );
		wp_set_current_user( $editor );
		$this->assert_refused( aafm_exec_avada_insert_library_item( $this->input( $id, $code ) ), 'aafm_builder_needs_unfiltered_html', 403, $id, self::PAGE );
		$this->assert_refused( aafm_exec_avada_insert_library_item( $this->input( $id, $wrapper ) ), 'aafm_builder_needs_unfiltered_html', 403, $id, self::PAGE );

		$this->act_as_admin();
		$out = aafm_exec_avada_insert_library_item( $this->input( $id, $wrapper ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );

		$loop = $this->item( 'x', 'sections', true );
		wp_update_post(
			array(
				'ID'           => $loop,
				'post_content' => $wrap( '[fusion_global id="' . $loop . '"]' ),
			)
		);
		$page = $this->avada_post();
		$this->assert_refused( aafm_exec_avada_insert_library_item( $this->input( $page, $loop ) ), 'aafm_builder_reference_loop', 400, $page, self::PAGE );
	}

	/**
	 * A fusion_global typed through the element tools is never written: insert-element does not know the tag, and
	 * update-element refuses an element that shows a global item.
	 */
	public function test_a_typed_fusion_global_is_refused_by_the_element_tools(): void {
		$this->act_as_admin();
		$item = $this->item( self::SECTION, 'sections', true );
		$id   = $this->avada_post( self::PAGE . '[fusion_global id="' . $item . '"]' );
		$this->assert_refused(
			aafm_exec_avada_insert_element(
				$this->input( $id, 0 ) + array(
					'element' => array(
						'tag'  => 'fusion_global',
						'atts' => array( 'id' => (string) $item ),
					),
				)
			),
			'aafm_builder_unknown_element',
			400,
			$id,
			self::PAGE . '[fusion_global id="' . $item . '"]'
		);
		$content = (string) get_post( $id )->post_content;
		$el      = aafm_builder_elements( $content, aafm_fusion_shortcode_tags() );
		$last    = end( $el );
		$out     = aafm_exec_avada_update_element(
			array(
				'post_id'  => $id,
				'index'    => $last['index'],
				'expect'   => array(
					'tag'         => 'fusion_global',
					'fingerprint' => aafm_builder_fingerprint( $content, $last ),
				),
				'set_atts' => array( 'id' => '1' ),
			)
		);
		$this->assert_refused( $out, 'aafm_avada_global_element', 409, $id, $content );
	}

	public function test_insert_library_item_returns_a_redacted_post(): void {
		$this->act_as_admin();
		$id   = $this->avada_post();
		$item = $this->item( self::SECTION );
		$out  = aafm_exec_avada_insert_library_item( $this->input( $id, $item ) );
		$this->assertIsArray( $out );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'inserted', 'restore_revision_id', 'reversible' ), array_keys( $out ) );
		$this->assertIsArray( $out['post'] );
		$this->assertSame( aafm_redact_post( get_post( $id ) ), $out['post'] );
		$this->assertSame( $id, $out['post']['id'] );
		$this->assertArrayNotHasKey( 'post_content', $out['post'] );
		$this->assertIsInt( $out['restore_revision_id'] );
		$this->assertTrue( $out['reversible'] );
	}

	public function test_a_one_revision_limit_is_reported_with_reversible_reason(): void {
		$this->act_as_admin();
		$id   = $this->avada_post();
		$item = $this->item( self::SECTION );
		$keep = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $keep );
		$out  = aafm_exec_avada_insert_library_item( $this->input( $id, $item ) );
		remove_filter( 'wp_revisions_to_keep', $keep );
		$this->assertIsArray( $out );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertFalse( $out['reversible'] );
		$this->assertStringContainsString( 'keeps 1 revision per post', $out['reversible_reason'] );
	}

	public function test_library_insert_is_refused_on_a_post_avada_does_not_own(): void {
		$this->act_as_admin();
		$content = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
		$id      = self::factory()->post->create( array( 'post_content' => $content ) );
		$item    = $this->item( self::SECTION );
		$input   = array(
			'post_id'          => $id,
			'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
			'item_id'          => $item,
		);
		$this->assert_refused( aafm_exec_avada_insert_library_item( $input ), 'aafm_not_avada_owned', 409, $id, $content );
	}

	public function test_library_insert_permissions_discovery_undo_audit_schema_and_description(): void {
		$name = 'aafm/avada-insert-library-item';
		$args = aafm_args_avada_insert_library_item();
		$this->assertSame( 'aafm_perm_avada_post_object', $args['permission_callback'] );
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'post_id', 'page_fingerprint', 'item_id' ), $args['input_schema']['required'] );
		$this->assertSame( array( 'post_id', 'page_fingerprint', 'anchor_index', 'anchor_expect', 'placement', 'item_id' ), array_keys( $args['input_schema']['properties'] ) );
		$this->assertSame( aafm_args_avada_insert_element()['output_schema'], $args['output_schema'] );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
			),
			$args['meta']['annotations']
		);
		$check = aafm_ability_list_permission( $name );
		$this->assertIsCallable( $check );
		$this->acting_as( 'editor' );
		$this->assertTrue( $check() );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( $check() );
		$this->assertSame( 'not_covered', aafm_undo_refusals()[ $name ] );
		$this->assertStringContainsString( 'Ran a library item insert on Avada page #42', (string) aafm_build_activity_detail( $name, array( 'post_id' => 42 ) ) );
		$row = aafm_get_abilities_registry_full()[ $name ];
		$this->assertSame( 'writes', $row['group'] );
		$this->assertSame( 'write', $row['risk'] );
		$this->assertSame( 'avada', $row['subject'] );
		$this->assertArrayHasKey( $name, aafm_register_avada_site_definitions( array() ) );
		foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'edit access', 'page_fingerprint', 'restore', 'Role Manager', 'unfiltered HTML', '50 element', 'global', 'private posts' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text, $phrase );
			}
		}
		$this->assertStringContainsString( 'Only while Avada is active', $row['description'] );
	}

	// S12-2: avada-update-page-css.

	/**
	 * Run the page CSS tool.
	 *
	 * @param int    $id  Post.
	 * @param string $css CSS.
	 * @return array<string,mixed>|WP_Error
	 */
	private function page_css( int $id, string $css ) {
		return aafm_exec_avada_update_page_css(
			array(
				'post_id'    => $id,
				'custom_css' => $css,
			)
		);
	}

	public function test_css_is_set_and_read_back(): void {
		$this->act_as_admin();
		$id  = $this->avada_post();
		$out = $this->page_css( $id, ".a > .b { color: red; }\n.c{}" );
		$this->assertSame(
			array(
				'post_id'  => $id,
				'changed'  => true,
				'previous' => '',
			),
			$out
		);
		$this->assertSame( ".a > .b { color: red; }\n.c{}", get_post_meta( $id, '_fusion_builder_custom_css', true ) );
		$read = aafm_exec_avada_get_page_content( array( 'post_id' => $id ) );
		$this->assertIsArray( $read );
		$this->assertSame( self::PAGE, $read['content'] );
	}

	/**
	 * CR-3: CSS holding markup is refused, as WordPress refuses it in custom CSS; any other CSS, a range query and a
	 * percent-encoded data URI included, is stored byte for byte.
	 */
	public function test_css_holding_markup_is_refused_and_other_css_is_stored_as_sent(): void {
		$this->act_as_admin();
		$id = $this->avada_post();
		$this->assert_error( $this->page_css( $id, '.a{}</style><script>x()</script>' ), 'aafm_builder_css_markup', 400 );
		$this->assertFalse( metadata_exists( 'post', $id, '_fusion_builder_custom_css' ) );
		$css = '@media (width <= 600px) { .a > .b { background: url("data:image/svg+xml,%3Csvg%3E") } }  ';
		$this->assertTrue( $this->page_css( $id, $css )['changed'] );
		$this->assertSame( $css, get_post_meta( $id, '_fusion_builder_custom_css', true ) );
	}

	public function test_an_empty_value_deletes_the_meta(): void {
		$this->act_as_admin();
		$id = $this->avada_post();
		update_post_meta( $id, '_fusion_builder_custom_css', '.old{}' );
		$out = $this->page_css( $id, '' );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( '.old{}', $out['previous'] );
		$this->assertFalse( metadata_exists( 'post', $id, '_fusion_builder_custom_css' ) );
	}

	public function test_previous_holds_the_old_value(): void {
		$this->act_as_admin();
		$id = $this->avada_post();
		update_post_meta( $id, '_fusion_builder_custom_css', '.old{}' );
		$out = $this->page_css( $id, '.new{}' );
		$this->assertSame( '.old{}', $out['previous'] );
		$this->assertSame( '.new{}', get_post_meta( $id, '_fusion_builder_custom_css', true ) );
	}

	/**
	 * Review Focus 3: writing what is stored changes nothing and writes no meta.
	 */
	public function test_writing_the_stored_css_changes_nothing(): void {
		$this->act_as_admin();
		$id = $this->avada_post();
		update_post_meta( $id, '_fusion_builder_custom_css', '.a{}' );
		$writes = 0;
		$count  = static function ( $check ) use ( &$writes ) {
			++$writes;
			return $check;
		};
		add_filter( 'update_post_metadata', $count );
		add_filter( 'delete_post_metadata', $count );
		add_filter( 'add_post_metadata', $count );
		$out  = $this->page_css( $id, '.a{}' );
		$bare = self::factory()->post->create( array( 'post_type' => 'fusion_element' ) );
		$none = $this->page_css( $bare, '' );
		remove_filter( 'update_post_metadata', $count );
		remove_filter( 'delete_post_metadata', $count );
		remove_filter( 'add_post_metadata', $count );
		$this->assertFalse( $out['changed'] );
		$this->assertFalse( $none['changed'] );
		$this->assertSame( 0, $writes );
	}

	public function test_the_permission_needs_edit_access_and_unfiltered_html(): void {
		$this->act_as_admin();
		$id = $this->avada_post();
		$this->assertSame( 'aafm_perm_avada_page_css', aafm_args_avada_update_page_css()['permission_callback'] );
		$this->assertTrue( aafm_perm_avada_page_css( array( 'post_id' => $id ) ) );
		$this->act_as_restricted_editor();
		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$this->assertFalse( aafm_perm_avada_page_css( array( 'post_id' => $id ) ) );
		$this->acting_as( 'contributor' );
		$this->assertFalse( aafm_perm_avada_page_css( array( 'post_id' => $id ) ) );
	}

	public function test_page_css_is_refused_on_a_classic_page_and_allowed_on_a_library_element(): void {
		$this->act_as_admin();
		$classic = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<p>Classic</p>',
			)
		);
		$this->assert_error( $this->page_css( $classic, '.a{}' ), 'aafm_not_avada_owned', 409 );
		$this->assertFalse( metadata_exists( 'post', $classic, '_fusion_builder_custom_css' ) );
		$item = $this->item( self::ELEMENT, 'elements' );
		$this->assertTrue( $this->page_css( $item, '.a{}' )['changed'] );
		$this->assertSame( '.a{}', get_post_meta( $item, '_fusion_builder_custom_css', true ) );
	}

	public function test_a_failed_css_write_is_an_error(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$refuse = static fn( $check, $object_id, $key ) => '_fusion_builder_custom_css' === $key ? false : $check;
		add_filter( 'update_post_metadata', $refuse, 10, 3 );
		add_filter( 'add_post_metadata', $refuse, 10, 3 );
		$out = $this->page_css( $id, '.a{}' );
		remove_filter( 'update_post_metadata', $refuse, 10 );
		remove_filter( 'add_post_metadata', $refuse, 10 );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertFalse( metadata_exists( 'post', $id, '_fusion_builder_custom_css' ) );
	}

	public function test_page_css_discovery_schema_undo_audit_and_description(): void {
		$name  = 'aafm/avada-update-page-css';
		$check = aafm_ability_list_permission( $name );
		$this->assertIsCallable( $check );
		$this->act_as_admin();
		$this->assertTrue( $check() );
		$this->act_as_restricted_editor();
		$this->assertFalse( $check(), 'Hidden without unfiltered_html.' );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( $check() );
		$args = aafm_args_avada_update_page_css();
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'post_id', 'custom_css' ), $args['input_schema']['required'] );
		$this->assertSame( array( 'post_id', 'custom_css' ), array_keys( $args['input_schema']['properties'] ) );
		$this->assertSame( array( 'post_id', 'changed', 'previous' ), array_keys( $args['output_schema']['properties'] ) );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			$args['meta']['annotations']
		);
		$this->assertSame( 'not_covered', aafm_undo_refusals()[ $name ] );
		$this->assertStringContainsString( 'Ran a page CSS update on Avada page #42', (string) aafm_build_activity_detail( $name, array( 'post_id' => 42 ) ) );
		$row = aafm_get_abilities_registry_full()[ $name ];
		$this->assertSame( 'write', $row['risk'] );
		$this->assertSame( 'avada', $row['subject'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'replaces', 'previous', 'unfiltered HTML', 'edit access' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text, $phrase );
			}
		}
	}

	// S12-2: avada-update-page-options.

	/**
	 * Force the theme seam entry present (a closure standing in for Avada::get_option_name()) or absent, and record every
	 * vendor entry the code asks for.
	 *
	 * @param bool          $present Whether Avada's theme counts as active.
	 * @param array<string> $asked   Entries asked for, added to.
	 * @return void
	 */
	private function force_avada_theme( bool $present, array &$asked = array() ): void {
		add_filter(
			'aafm_builder_vendor',
			static function ( $resolved, string $entry ) use ( $present, &$asked ) {
				$asked[] = $entry;
				if ( 'avada_option_name' !== $entry ) {
					return $resolved;
				}
				return $present ? static fn(): string => 'fusion_options' : null;
			},
			30,
			2
		);
	}

	/**
	 * The S8b fixture's stored _fusion meta.
	 *
	 * @return array<string,mixed>
	 */
	private function s8b_page_options(): array {
		return (array) json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Avada/page-options.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	/**
	 * Run the page options tool.
	 *
	 * @param int                 $id    Post.
	 * @param array<string,mixed> $input Extra input.
	 * @return array<string,mixed>|WP_Error
	 */
	private function page_options( int $id, array $input ) {
		return aafm_exec_avada_update_page_options( array( 'post_id' => $id ) + $input );
	}

	/**
	 * The first allowlisted id of a type.
	 *
	 * @param string $type Theme type.
	 * @return string
	 */
	private function option_of( string $type ): string {
		$id = array_search( $type, aafm_avada_page_option_keys(), true );
		$this->assertIsString( $id, $type );
		return $id;
	}

	public function test_set_changes_only_the_named_keys_and_keeps_the_rest_of_the_array(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		$id    = $this->avada_post();
		$fixed = $this->s8b_page_options();
		update_post_meta( $id, '_fusion', $fixed );
		$out = $this->page_options( $id, array( 'set' => array( 'page_title_bar' => 'yes' ) ) );
		$this->assertSame(
			array(
				'post_id'  => $id,
				'changed'  => array( 'page_title_bar' ),
				'previous' => array( 'page_title_bar' => 'no' ),
			),
			$out
		);
		$this->assertSame( array_merge( $fixed, array( 'page_title_bar' => 'yes' ) ), get_post_meta( $id, '_fusion', true ) );
	}

	public function test_remove_drops_a_key_and_an_empty_or_default_value_drops_it_too(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		$id    = $this->avada_post();
		$fixed = $this->s8b_page_options();
		update_post_meta( $id, '_fusion', $fixed );
		$out = $this->page_options(
			$id,
			array(
				'remove' => array( 'page_title_bar' ),
				'set'    => array(
					'seo_index'      => 'default',
					'displayed_menu' => '',
				),
			)
		);
		$this->assertSame( array( 'seo_index', 'displayed_menu', 'page_title_bar' ), $out['changed'] );
		$this->assertSame(
			array(
				'seo_index'      => 'auto',
				'displayed_menu' => 'default',
				'page_title_bar' => 'no',
			),
			$out['previous']
		);
		$stored = get_post_meta( $id, '_fusion', true );
		foreach ( array( 'page_title_bar', 'seo_index', 'displayed_menu' ) as $gone ) {
			$this->assertArrayNotHasKey( $gone, $stored );
		}
		$this->assertSame( count( $fixed ) - 3, count( $stored ) );
	}

	public function test_removing_the_last_option_deletes_the_meta_and_previous_is_null_where_none_was_stored(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		$id  = $this->avada_post();
		$out = $this->page_options( $id, array( 'set' => array( 'page_title_bar' => 'yes' ) ) );
		$this->assertSame( array( 'page_title_bar' => null ), $out['previous'] );
		$this->assertSame( array( 'page_title_bar' => 'yes' ), get_post_meta( $id, '_fusion', true ) );
		$this->page_options( $id, array( 'remove' => array( 'page_title_bar' ) ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_fusion' ) );
	}

	public function test_an_id_outside_the_allowlist_is_refused(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		$id = $this->avada_post();
		foreach ( array( array( 'set' => array( 'form_notification_email' => 'a@example.com' ) ), array( 'remove' => array( 'tracking_code' ) ) ) as $input ) {
			$this->assert_error( $this->page_options( $id, $input ), 'aafm_builder_attribute_not_writable', 400 );
		}
		$this->assertFalse( metadata_exists( 'post', $id, '_fusion' ) );
	}

	public function test_an_id_of_a_type_that_is_not_writable_is_refused(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		$id = $this->avada_post();
		foreach ( array( 'dimensions', 'typography', 'sortable', 'media', 'layout_conditions' ) as $type ) {
			$this->assertNull( aafm_avada_page_option_kind( $type ) );
			$out = $this->page_options( $id, array( 'set' => array( $this->option_of( $type ) => 'x' ) ) );
			$this->assert_error( $out, 'aafm_builder_attribute_not_writable', 400 );
		}
		$this->assertFalse( metadata_exists( 'post', $id, '_fusion' ) );
	}

	/**
	 * One accepted and one refused value per writable type, with the stored form.
	 *
	 * @return array<string,array{0:string,1:mixed,2:mixed,3:mixed}>
	 */
	public static function typed_values(): array {
		return array(
			'radio-buttonset'    => array( 'radio-buttonset', 'yes', 'yes', 'a"b' ),
			'select'             => array( 'select', 'left top', 'left top', 'x;y' ),
			'iconpicker'         => array( 'iconpicker', 'fa-star fas', 'fa-star fas', '<b>' ),
			'multiple_select'    => array( 'multiple_select', array( 'a', 'b' ), array( 'a', 'b' ), 'a' ),
			'checkbox-buttonset' => array( 'checkbox-buttonset', array( 'small-visibility', 'large-visibility' ), 'small-visibility,large-visibility', array() ),
			'color-alpha'        => array( 'color-alpha', 'rgba(0,0,0,0.5)', 'rgba(0,0,0,0.5)', 'red;}' ),
			'color'              => array( 'color', '#fff', '#fff', 'nope' ),
			'text'               => array( 'text', 'Fish & chips', 'Fish &amp; chips', 'a "quoted" word' ),
			'link'               => array( 'link', 'https://example.com/?a=1&b=2', 'https://example.com/?a=1&b=2', 'javascript:alert(1)' ),
			'slider'             => array( 'slider', 12, '12', 'abc' ),
			'date_time_picker'   => array( 'date_time_picker', '2026-01-02 10:00', '2026-01-02 10:00:00', 'not a date' ),
			'textarea'           => array( 'textarea', '<p>Hi &amp; bye</p>', '<p>Hi &amp; bye</p>', true ),
		);
	}

	/**
	 * Review Focus 1 and design 4.7: each writable type validates its value, and wrong JSON types are refused cleanly
	 * or coerced.
	 *
	 * @dataProvider typed_values
	 *
	 * @param string $type   Theme type.
	 * @param mixed  $good   Accepted input.
	 * @param mixed  $stored Stored form.
	 * @param mixed  $bad    Refused input.
	 */
	public function test_each_writable_type_validates_its_value( string $type, $good, $stored, $bad ): void {
		$this->force_avada_theme( true );
		$this->act_as_restricted_editor();
		$id     = $this->avada_post();
		$option = $this->option_of( $type );
		$out    = $this->page_options( $id, array( 'set' => array( $option => $good ) ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : $type );
		$this->assertSame( array( $option => $stored ), get_post_meta( $id, '_fusion', true ) );
		$refused = $this->page_options( $id, array( 'set' => array( $option => $bad ) ) );
		$this->assert_error( $refused, 'aafm_builder_invalid_value', 400 );
		$this->assertSame( array( $option => $stored ), get_post_meta( $id, '_fusion', true ) );
	}

	public function test_an_upload_takes_an_image_attachment_id(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		$id     = $this->avada_post();
		$image  = self::factory()->attachment->create_object( 'pic.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$option = $this->option_of( 'upload' );
		$this->assertIsArray( $this->page_options( $id, array( 'set' => array( $option => $image ) ) ) );
		$this->assertSame(
			array(
				$option => array(
					'url' => wp_get_attachment_url( $image ),
					'id'  => (string) $image,
				),
			),
			get_post_meta( $id, '_fusion', true )
		);
		$this->assert_error( $this->page_options( $id, array( 'set' => array( $option => 999999 ) ) ), 'aafm_builder_invalid_value', 400 );
	}

	/**
	 * Ruling R-S12-4: a text area value kses would alter is refused for a restricted editor, meta unchanged.
	 */
	public function test_a_textarea_value_kses_would_alter_is_refused_for_an_editor_and_kept_for_an_admin(): void {
		$this->force_avada_theme( true );
		$option = $this->option_of( 'textarea' );
		$this->act_as_restricted_editor();
		$id = $this->avada_post();
		update_post_meta( $id, '_fusion', array( $option => 'old' ) );
		foreach ( array(
			'<iframe src="https://example.com/v"></iframe>' => 'aafm_avada_stored_text_needs_unfiltered_html',
			'<p onclick="x()">a</p>'       => 'aafm_avada_stored_text_needs_unfiltered_html',
			'[fusion_text]x[/fusion_text]' => 'aafm_avada_brackets_need_unfiltered_html',
			'<p data-x="1">a</p>'          => 'aafm_avada_data_attributes_need_unfiltered_html',
		) as $value => $code ) {
			$this->assert_error( $this->page_options( $id, array( 'set' => array( $option => $value ) ) ), $code, 400 );
			$this->assertSame( array( $option => 'old' ), get_post_meta( $id, '_fusion', true ) );
		}
		$this->act_as_admin();
		$embed = '<iframe src="https://example.com/v"></iframe>';
		$this->assertIsArray( $this->page_options( $id, array( 'set' => array( $option => $embed ) ) ) );
		$this->assertSame( array( $option => $embed ), get_post_meta( $id, '_fusion', true ) );
	}

	/**
	 * CR-1: Avada Core prints a text option such as project_url_text without escaping, so for a user who may not post
	 * unfiltered HTML a text value kses would change is refused, never stored raw and never stored filtered; an
	 * administrator's value is stored as sent, as Avada's own save keeps it.
	 */
	public function test_a_text_value_kses_would_change_is_refused_for_a_restricted_user_and_kept_for_an_admin(): void {
		$this->force_avada_theme( true );
		$option = $this->option_of( 'text' );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		( new \WP_User( $author ) )->add_cap( 'unfiltered_html', false );
		$id = $this->avada_post( self::PAGE, $author );
		wp_set_current_user( $author );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		update_post_meta( $id, '_fusion', array( $option => 'old' ) );
		// The unfinished tag of the reproduction is kept as text: kses escapes its < and changes nothing else, so it is
		// stored as the escaped text Avada's own save stores, and Avada prints it as text.
		$this->assertIsArray( $this->page_options( $id, array( 'set' => array( $option => '<img src=x onerror=alert(1)//' ) ) ) );
		$this->assertSame( array( $option => '&lt;img src=x onerror=alert(1)//' ), get_post_meta( $id, '_fusion', true ) );
		update_post_meta( $id, '_fusion', array( $option => 'old' ) );
		foreach ( array( 'Fish & chips <ok>', '<b onclick=x()>a</b>', 'Press <Ctrl> + S', '<img src=x onerror=alert(1)>' ) as $value ) {
			$this->assert_error( $this->page_options( $id, array( 'set' => array( $option => $value ) ) ), 'aafm_avada_stored_text_needs_unfiltered_html', 400 );
			$this->assertSame( array( $option => 'old' ), get_post_meta( $id, '_fusion', true ), $value );
		}
		$this->assertIsArray( $this->page_options( $id, array( 'set' => array( $option => '<strong>Fish</strong> & chips' ) ) ) );
		$this->assertSame( array( $option => '<strong>Fish</strong> &amp; chips' ), get_post_meta( $id, '_fusion', true ) );
		$this->act_as_admin();
		$this->assertIsArray( $this->page_options( $id, array( 'set' => array( $option => 'Press <Ctrl> + S' ) ) ) );
		$this->assertSame( array( $option => 'Press <Ctrl> + S' ), get_post_meta( $id, '_fusion', true ) );
	}

	/**
	 * CR-11: a named entity kses keeps, such as &copy;, is accepted in a text area for a restricted editor.
	 */
	public function test_a_textarea_entity_kses_keeps_is_accepted_for_a_restricted_editor(): void {
		$this->force_avada_theme( true );
		$option = $this->option_of( 'textarea' );
		$this->act_as_restricted_editor();
		$id = $this->avada_post();
		$this->assertIsArray( $this->page_options( $id, array( 'set' => array( $option => '&copy; 2026 Fish & chips' ) ) ) );
		$this->assertSame( array( $option => '&copy; 2026 Fish &amp; chips' ), get_post_meta( $id, '_fusion', true ) );
	}

	public function test_wrong_json_types_are_refused_cleanly_or_coerced(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		$id = $this->avada_post();
		$this->assert_error( $this->page_options( $id, array( 'set' => 'page_title_bar' ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->page_options( $id, array( 'remove' => 'page_title_bar' ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->page_options( $id, array( 'remove' => array( 7 ) ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->page_options( $id, array( 'set' => array( 'page_title_bar' => true ) ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->page_options( $id, array( 'set' => array( 'page_title_bar' => array( 'yes' ) ) ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error(
			$this->page_options(
				$id,
				array(
					'set'    => array( 'page_title_bar' => 'yes' ),
					'remove' => array( 'page_title_bar' ),
				)
			),
			'aafm_builder_invalid_value',
			400
		);
		$this->assert_error( $this->page_options( $id, array() ), 'aafm_avada_page_options_empty', 400 );
		$this->assertFalse( metadata_exists( 'post', $id, '_fusion' ) );
		$slider = $this->option_of( 'slider' );
		$this->assertIsArray( $this->page_options( $id, array( 'set' => (object) array( $slider => 7.5 ) ) ) );
		$this->assertSame( array( $slider => '7.5' ), get_post_meta( $id, '_fusion', true ) );
	}

	public function test_page_options_work_on_a_classic_page_under_the_avada_theme(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<p>Classic</p>',
			)
		);
		$this->assertIsArray( $this->page_options( $id, array( 'set' => array( 'page_title_bar' => 'yes' ) ) ) );
		$this->assertSame( array( 'page_title_bar' => 'yes' ), get_post_meta( $id, '_fusion', true ) );
	}

	public function test_page_options_are_refused_without_the_avada_theme_and_on_an_elementor_page(): void {
		$this->act_as_admin();
		$id = $this->avada_post();
		$this->force_avada_theme( false );
		$this->assert_error( $this->page_options( $id, array( 'set' => array( 'page_title_bar' => 'yes' ) ) ), 'aafm_avada_theme_inactive', 409 );
		$this->assertFalse( metadata_exists( 'post', $id, '_fusion' ) );
		remove_all_filters( 'aafm_builder_vendor', 30 );
		$this->force_avada_theme( true );
		$elementor = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $elementor, '_elementor_data', '[]' );
		$this->assert_error( $this->page_options( $elementor, array( 'set' => array( 'page_title_bar' => 'yes' ) ) ), 'aafm_not_avada_owned', 409 );
		$this->assertFalse( metadata_exists( 'post', $elementor, '_fusion' ) );
	}

	public function test_page_options_are_written_on_a_slide_post_now_s14_allows_the_type(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		register_post_type( 'slide', array( 'public' => true ) );
		$id  = self::factory()->post->create( array( 'post_type' => 'slide' ) );
		$out = $this->page_options( $id, array( 'set' => array( 'caption' => 'x' ) ) );
		unregister_post_type( 'slide' );
		$this->assertIsArray( $out, 'The slide types joined after the S14 slide evidence (R3-PR2-13).' );
		$this->assertSame( 'x', get_post_meta( $id, '_fusion', true )['caption'] );
	}

	public function test_a_section_needs_manage_options(): void {
		register_post_type(
			'fusion_tb_section',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
		$this->act_as_admin();
		$section = self::factory()->post->create( array( 'post_type' => 'fusion_tb_section' ) );
		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $section ) ) );
		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $section ) ) );
		unregister_post_type( 'fusion_tb_section' );
		$this->assertSame( 'aafm_perm_avada_post_object', aafm_args_avada_update_page_options()['permission_callback'] );
	}

	public function test_the_write_never_calls_avadas_setter(): void {
		$asked = array();
		$this->force_avada_theme( true, $asked );
		$this->act_as_admin();
		$id = $this->avada_post();
		$this->assertIsArray( $this->page_options( $id, array( 'set' => array( 'page_title_bar' => 'yes' ) ) ) );
		$this->assertSame( array( 'avada_option_name' ), array_values( array_unique( $asked ) ), 'Only the theme check reaches the vendor seam.' );
		$this->assertSame( array( 'page_title_bar' => 'yes' ), get_post_meta( $id, '_fusion', true ) );
	}

	/**
	 * Review Focus 3: setting the stored values changes nothing and writes no meta.
	 */
	public function test_setting_the_stored_values_changes_nothing(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		$id = $this->avada_post();
		update_post_meta( $id, '_fusion', $this->s8b_page_options() );
		$writes = 0;
		$count  = static function ( $check ) use ( &$writes ) {
			++$writes;
			return $check;
		};
		add_filter( 'update_post_metadata', $count );
		add_filter( 'delete_post_metadata', $count );
		add_filter( 'add_post_metadata', $count );
		$out = $this->page_options(
			$id,
			array(
				'set'    => array(
					'page_title_bar'    => 'no',
					'slider_visibility' => array( 'small-visibility', 'medium-visibility', 'large-visibility' ),
				),
				'remove' => array( 'aspect_ratio' ),
			)
		);
		remove_filter( 'update_post_metadata', $count );
		remove_filter( 'delete_post_metadata', $count );
		remove_filter( 'add_post_metadata', $count );
		$this->assertSame( array(), $out['changed'] );
		$this->assertSame( '{}', wp_json_encode( $out['previous'] ) );
		$this->assertSame( 0, $writes );
	}

	public function test_a_failed_page_options_write_is_an_error(): void {
		$this->force_avada_theme( true );
		$this->act_as_admin();
		$id     = $this->avada_post();
		$refuse = static fn( $check, $object_id, $key ) => '_fusion' === $key ? false : $check;
		add_filter( 'update_post_metadata', $refuse, 10, 3 );
		add_filter( 'add_post_metadata', $refuse, 10, 3 );
		$out = $this->page_options( $id, array( 'set' => array( 'page_title_bar' => 'yes' ) ) );
		remove_filter( 'update_post_metadata', $refuse, 10 );
		remove_filter( 'add_post_metadata', $refuse, 10 );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertFalse( metadata_exists( 'post', $id, '_fusion' ) );
	}

	public function test_page_options_discovery_schema_undo_audit_and_description(): void {
		$name  = 'aafm/avada-update-page-options';
		$check = aafm_ability_list_permission( $name );
		$this->assertIsCallable( $check );
		$this->acting_as( 'editor' );
		$this->assertTrue( $check() );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( $check() );
		$args = aafm_args_avada_update_page_options();
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'post_id' ), $args['input_schema']['required'] );
		$this->assertSame( array( 'post_id', 'set', 'remove' ), array_keys( $args['input_schema']['properties'] ) );
		$this->assertSame( array( 'post_id', 'changed', 'previous' ), array_keys( $args['output_schema']['properties'] ) );
		$this->assertSame( 'not_covered', aafm_undo_refusals()[ $name ] );
		$this->assertStringContainsString( 'Ran a page options update on Avada post #42', (string) aafm_build_activity_detail( $name, array( 'post_id' => 42 ) ) );
		$row = aafm_get_abilities_registry_full()[ $name ];
		$this->assertSame( 'write', $row['risk'] );
		$this->assertSame( 'avada', $row['subject'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'previous', 'unfiltered HTML', 'edit access', 'Avada theme', 'never reachable', 'Role Manager' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text, $phrase );
			}
		}
	}

	/**
	 * Arguments the stub cache reset received, one entry per call.
	 *
	 * @var list<mixed>
	 */
	public static array $resets = array();

	/**
	 * Supply the Avada option name and a recording cache reset through the seam (Ruling B14; the AvadaStandIn class is
	 * not relied on, Ruling R3-PR2-7).
	 *
	 * @param string|null $name Option name, or null for the theme inactive.
	 * @return callable
	 */
	private function avada_options_seam( ?string $name = 'fusion_options' ): callable {
		self::$resets = array();
		$filter       = static function ( $resolved, string $entry ) use ( $name ) {
			if ( 'avada_option_name' === $entry ) {
				return null === $name ? null : static fn(): string => $name;
			}
			if ( 'avada_cache_reset' === $entry ) {
				return static function ( $args = null ): void {
					AvadaSiteTest::$resets[] = $args;
				};
			}
			return $resolved;
		};
		add_filter( 'aafm_builder_vendor', $filter, 20, 2 );
		return $filter;
	}

	/**
	 * Run the Global Options write as an administrator against a stored array.
	 *
	 * @param array<string,mixed>      $set    Set.
	 * @param array<string,mixed>|null $stored Stored option, or null to keep what is stored.
	 * @return array<string,mixed>|WP_Error
	 */
	private function global_write( array $set, ?array $stored = array( 'primary_color' => '#111111' ) ) {
		if ( null !== $stored ) {
			update_option( 'fusion_options', $stored );
		}
		$filter = $this->avada_options_seam();
		$this->acting_as( 'administrator' );
		$out = aafm_exec_avada_update_global_options( array( 'set' => $set ) );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		return $out;
	}

	public function test_the_writable_list_is_the_read_list_minus_maintenance_mode(): void {
		$this->assertSame(
			array( 'site_width', 'layout', 'primary_color', 'color_palette', 'link_color', 'body_typography', 'h1_typography', 'h2_typography', 'h3_typography', 'h4_typography', 'h5_typography', 'h6_typography', 'header_position', 'header_layout', 'logo', 'page_title_bar', 'footer_widgets', 'footer_copyright', 'footer_text', 'responsive' ),
			aafm_avada_global_option_writable()
		);
		$this->assertCount( 20, aafm_avada_global_option_writable() );
		$this->assertSame( aafm_avada_global_option_writable(), array_keys( array_intersect_key( array_flip( aafm_avada_global_option_writable() ), aafm_avada_global_option_rules() ) ) );
	}

	public function test_maintenance_mode_and_an_unlisted_key_are_refused(): void {
		foreach ( array( 'maintenance_mode', 'smtp_password', 'custom_css' ) as $key ) {
			$out = $this->global_write( array( $key => '1' ) );
			$this->assertInstanceOf( WP_Error::class, $out );
			$this->assertSame( 'aafm_builder_attribute_not_writable', $out->get_error_code() );
		}
		$this->assertSame( array( 'primary_color' => '#111111' ), get_option( 'fusion_options' ) );
		$this->assertSame( array(), self::$resets );
	}

	public function test_each_rule_refuses_what_it_should(): void {
		$cases = array(
			array( 'primary_color' => 'red;}body{x' ),
			array( 'primary_color' => '' ),
			array( 'layout' => 'stretched' ),
			array( 'footer_widgets' => 1 ),
			array( 'site_width' => '1200' ),
			array( 'body_typography' => array( 'font-family' => 'Inter{}' ) ),
			array( 'body_typography' => array( 'font-family' => 'url(x)' ) ),
			array( 'body_typography' => array( 'font-size' => '16' ) ),
			array( 'body_typography' => array( 'font-weight' => '450' ) ),
			array( 'body_typography' => array( 'font-face' => 'x' ) ),
			array( 'h1_typography' => 'Inter' ),
			array( 'color_palette' => array( 'color1' => array( 'color' => 'blue;' ) ) ),
			array( 'color_palette' => array( 'colour1' => array( 'color' => '#fff' ) ) ),
			array( 'color_palette' => array( 'color1' => array( 'label' => '<b>x</b>' ) ) ),
			array( 'logo' => 'https://example.org/logo.png' ),
		);
		foreach ( $cases as $set ) {
			$out = $this->global_write( $set );
			$this->assertInstanceOf( WP_Error::class, $out, (string) wp_json_encode( $set ) );
			$this->assertSame( 'aafm_builder_invalid_value', $out->get_error_code(), (string) wp_json_encode( $set ) );
		}
		$this->assertSame( array( 'primary_color' => '#111111' ), get_option( 'fusion_options' ) );
	}

	/**
	 * CR-1 sweep: footer_text is kept as sent for an administrator who may post unfiltered HTML, and for one who may not
	 * (multisite) a value kses would change is refused, never stored filtered.
	 */
	public function test_footer_text_is_kept_for_unfiltered_html_and_refused_when_kses_would_change_it(): void {
		$value = 'Copyright <a href="https://example.org">Us</a><script>alert(1)</script>';
		$out   = $this->global_write( array( 'footer_text' => $value ) );
		$this->assertSame( array( 'footer_text' ), $out['changed'] );
		$this->assertSame( $value, get_option( 'fusion_options' )['footer_text'] );

		$deny = static function ( array $caps, string $cap ): array {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		$out  = $this->global_write( array( 'footer_text' => $value ) );
		$kept = $this->global_write( array( 'footer_text' => 'Fish & <a href="https://example.org">chips</a>' ) );
		remove_filter( 'map_meta_cap', $deny, 10 );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_avada_stored_text_needs_unfiltered_html', $out->get_error_code() );
		$this->assertSame( array( 'footer_text' ), $kept['changed'] );
		$this->assertSame( 'Fish &amp; <a href="https://example.org">chips</a>', get_option( 'fusion_options' )['footer_text'] );
	}

	public function test_logo_writes_url_and_id_together_and_refuses_a_non_image(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$text  = self::factory()->attachment->create( array( 'post_mime_type' => 'text/plain' ) );
		$out   = $this->global_write( array( 'logo' => $text ) );
		$this->assertSame( 'aafm_builder_invalid_value', $out->get_error_code() );
		$out  = $this->global_write( array( 'logo' => $image ) );
		$logo = get_option( 'fusion_options' )['logo'];
		$this->assertSame( array( 'logo' ), $out['changed'] );
		$this->assertSame( (string) $image, $logo['id'] );
		$this->assertSame( wp_get_attachment_url( $image ), $logo['url'] );
		$this->assertSame( array( 'url', 'id', 'height', 'width', 'thumbnail' ), array_keys( $logo ) );
	}

	public function test_typography_and_palette_change_only_the_named_sub_values(): void {
		$stored = array(
			'body_typography' => array(
				'font-family' => 'Inter',
				'font-size'   => '16px',
				'line-height' => 'var(--awb-typography4-line-height)',
			),
			'color_palette'   => array(
				'color1' => array(
					'color' => '#ffffff',
					'label' => 'Color 1',
				),
				'color2' => array(
					'color' => '#000000',
					'label' => 'Color 2',
				),
			),
		);
		$out    = $this->global_write(
			array(
				'body_typography' => array(
					'font-size'   => '18px',
					'line-height' => '1.6',
				),
				'color_palette'   => (object) array( 'color2' => (object) array( 'color' => 'rgba(1,2,3,0.5)' ) ),
			),
			$stored
		);
		$this->assertSame( array( 'body_typography', 'color_palette' ), $out['changed'] );
		$now = get_option( 'fusion_options' );
		$this->assertSame(
			array(
				'font-family' => 'Inter',
				'font-size'   => '18px',
				'line-height' => '1.6',
			),
			$now['body_typography']
		);
		$this->assertSame(
			array(
				'color' => 'rgba(1,2,3,0.5)',
				'label' => 'Color 2',
			),
			$now['color_palette']['color2']
		);
		$this->assertSame( $stored['color_palette']['color1'], $now['color_palette']['color1'] );
		$this->assertSame( $stored, (array) $out['previous'] );
	}

	public function test_only_the_named_keys_change_and_previous_holds_the_old_values(): void {
		$stored = array(
			'primary_color' => '#111111',
			'smtp_password' => 'planted-secret',
			'layout'        => 'wide',
		);
		$out    = $this->global_write(
			array(
				'primary_color'    => 'var(--awb-color5)',
				'footer_copyright' => '0',
			),
			$stored
		);
		$this->assertSame( 'fusion_options', $out['option_name'] );
		$this->assertSame( array( 'primary_color', 'footer_copyright' ), $out['changed'] );
		$this->assertSame(
			array(
				'primary_color'    => '#111111',
				'footer_copyright' => null,
			),
			$out['previous']
		);
		$this->assertSame(
			array(
				'primary_color'    => 'var(--awb-color5)',
				'smtp_password'    => 'planted-secret',
				'layout'           => 'wide',
				'footer_copyright' => '0',
			),
			get_option( 'fusion_options' )
		);
		$this->assertStringNotContainsString( 'planted-secret', (string) wp_json_encode( $out ) );
	}

	public function test_the_cache_call_gets_exactly_the_narrow_argument_and_no_object_cache_flush(): void {
		wp_cache_set( 'aafm-planted', 'still-here', 'aafm-unrelated-group' );
		$this->global_write( array( 'layout' => 'boxed' ) );
		$this->assertSame(
			array(
				array(
					'compiled_assets'  => true,
					'transients'       => true,
					'gfonts'           => false,
					'fa_font'          => false,
					'demo_data'        => false,
					'po_export'        => false,
					'patcher_messages' => false,
					'other_caches'     => false,
				),
			),
			self::$resets
		);
		$this->assertSame( 'still-here', wp_cache_get( 'aafm-planted', 'aafm-unrelated-group' ) );
	}

	public function test_a_failed_verified_write_is_an_error_and_makes_no_cache_call(): void {
		update_option( 'fusion_options', array( 'primary_color' => '#111111' ) );
		$keep = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_fusion_options', $keep, 10, 2 );
		$out  = $this->global_write( array( 'layout' => 'boxed' ), null );
		remove_filter( 'pre_update_option_fusion_options', $keep, 10 );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_avada_options_not_saved', $out->get_error_code() );
		$this->assertSame( array(), self::$resets );
		$this->assertSame( array( 'primary_color' => '#111111' ), get_option( 'fusion_options' ) );
	}

	public function test_the_theme_must_be_active(): void {
		update_option( 'fusion_options', array( 'layout' => 'wide' ) );
		$filter = $this->avada_options_seam( null );
		$this->acting_as( 'administrator' );
		$out = aafm_exec_avada_update_global_options( array( 'set' => array( 'layout' => 'boxed' ) ) );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertSame( 'aafm_avada_theme_inactive', $out->get_error_code() );
		$this->assertSame( 409, $out->get_error_data()['status'] );
		$this->assertSame( array( 'layout' => 'wide' ), get_option( 'fusion_options' ) );
	}

	public function test_global_options_wrong_json_types_are_refused_and_equal_values_write_nothing(): void {
		foreach ( array( array(), 'layout' ) as $set ) {
			update_option( 'fusion_options', array( 'layout' => 'wide' ) );
			$filter = $this->avada_options_seam();
			$this->acting_as( 'administrator' );
			$out = aafm_exec_avada_update_global_options( array( 'set' => $set ) );
			remove_filter( 'aafm_builder_vendor', $filter, 20 );
			$this->assertSame( 'aafm_builder_invalid_value', $out->get_error_code() );
		}
		$writes = 0;
		$count  = static function ( $value ) use ( &$writes ) {
			++$writes;
			return $value;
		};
		update_option(
			'fusion_options',
			array(
				'layout'          => 'wide',
				'body_typography' => array( 'font-size' => '16px' ),
			)
		);
		add_filter( 'pre_update_option_fusion_options', $count );
		$out = $this->global_write(
			array(
				'layout'          => 'wide',
				'body_typography' => array( 'font-size' => '16px' ),
			),
			null
		);
		remove_filter( 'pre_update_option_fusion_options', $count );
		$this->assertSame( 0, $writes );
		$this->assertSame( array(), $out['changed'] );
		$this->assertSame( array(), self::$resets );
	}

	public function test_global_options_schema_permission_audit_and_description(): void {
		$name = 'aafm/avada-update-global-options';
		$args = aafm_args_avada_update_global_options();
		$this->assertSame( 'aafm_perm_manage_options', $args['permission_callback'] );
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertTrue( $args['meta']['annotations']['destructive'] );
		$this->assertNull( aafm_ability_list_permission( $name ) );
		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_perm_manage_options() );
		$this->assertContains( $name, aafm_non_removal_destructive_abilities() );
		$this->assertSame(
			'Ran an Avada Global Options update: layout, primary_color',
			aafm_build_activity_detail(
				$name,
				array(
					'set' => array(
						'primary_color' => 'x',
						'layout'        => 'y',
						'smtp_password' => 'z',
					),
				)
			)
		);
		$row = aafm_get_abilities_registry_full()[ $name ];
		$this->assertSame( 'destructive', $row['risk'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'site-wide', 'previous', 'maintenance', 'manage site options' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text, $phrase );
			}
		}
		$this->assertStringContainsString( 'page cache', $row['description'] );
	}

	/**
	 * Layout fixtures: the two post types and the category, three published sections (header, footer, a second footer),
	 * a draft footer and a layout post stored as layout-blog.txt with its header id swapped in (layout-shop.txt holds
	 * an apostrophe Avada's own decode cannot read, so it is the unparseable case).
	 *
	 * @return array<string,int>
	 */
	private function layout_fixture(): array {
		register_post_type( 'fusion_tb_layout', array( 'public' => false ) );
		register_post_type( 'fusion_tb_section', array( 'public' => false ) );
		register_taxonomy( 'fusion_tb_category', array( 'fusion_tb_section' ) );
		$ids = array();
		foreach ( array(
			'header'  => array( 'header', 'publish' ),
			'footer'  => array( 'footer', 'publish' ),
			'footer2' => array( 'footer', 'publish' ),
			'draft'   => array( 'footer', 'draft' ),
		) as $name => $spec ) {
			$ids[ $name ] = self::factory()->post->create(
				array(
					'post_type'   => 'fusion_tb_section',
					'post_status' => $spec[1],
					'post_title'  => $name,
				)
			);
			wp_set_object_terms( $ids[ $name ], $spec[0], 'fusion_tb_category' );
		}
		$ids['page']   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$ids['layout'] = self::factory()->post->create(
			array(
				'post_type'   => 'fusion_tb_layout',
				'post_status' => 'publish',
			)
		);
		// Stored exactly as Avada stored it: a direct row update, since wp_update_post() would unslash.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		$raw = str_replace( ':13', ':' . $ids['header'], (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Avada/layout-blog.txt' ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $raw ), array( 'ID' => $ids['layout'] ) );
		clean_post_cache( $ids['layout'] );
		return $ids;
	}

	/**
	 * The layout seam: Avada's condition list from a stub get_layout_conditions(), and a recording cache reset.
	 *
	 * @return callable
	 */
	private function layout_seam(): callable {
		self::$resets = array();
		$builder      = new class() {
			/**
			 * The shape Avada's own method returns (class-fusion-template-builder.php:2903).
			 *
			 * @return array<string,mixed>
			 */
			public function get_layout_conditions(): array {
				return array(
					'page'    => array(
						'label'      => 'Pages',
						'conditions' => array(
							array(
								'id'    => 'singular_page',
								'label' => 'All Pages',
								'type'  => 'singular',
							),
							array(
								'id'    => 'front_page',
								'label' => 'Front Page',
								'type'  => 'singular',
							),
							array(
								'id'       => 'specific_page',
								'label'    => 'Specific Page',
								'type'     => 'singular',
								'multiple' => true,
							),
						),
					),
					'archive' => array(
						'conditions' => array(
							array(
								'id'    => 'all_archives',
								'label' => 'All Archives',
								'type'  => 'archives',
							),
						),
					),
				);
			}
		};
		$filter       = static function ( $resolved, string $entry ) use ( $builder ) {
			if ( 'avada_template_builder' === $entry ) {
				return static fn() => $builder;
			}
			if ( 'avada_cache_reset' === $entry ) {
				return static function ( $args = null ): void {
					AvadaSiteTest::$resets[] = $args;
				};
			}
			return $resolved;
		};
		add_filter( 'aafm_builder_vendor', $filter, 20, 2 );
		return $filter;
	}

	/**
	 * Run the layout write as an administrator.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|WP_Error
	 */
	private function layout_write( array $input ) {
		$filter = $this->layout_seam();
		$this->acting_as( 'administrator' );
		$out = aafm_exec_avada_update_layout( $input );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		return $out;
	}

	/**
	 * A layout post decoded the way Avada reads it.
	 *
	 * @param int $id Layout.
	 * @return mixed
	 */
	private static function layout_data( int $id ) {
		return json_decode( str_replace( "'", "\'", wp_unslash( get_post_field( 'post_content', $id, 'raw' ) ) ), true );
	}

	public function test_a_footer_section_is_assigned_and_the_rest_of_the_layout_is_kept(): void {
		$ids    = $this->layout_fixture();
		$before = self::layout_data( $ids['layout'] );
		$out    = $this->layout_write(
			array(
				'layout_id' => $ids['layout'],
				'sections'  => array( 'footer' => $ids['footer2'] ),
			)
		);
		$this->assertIsArray( $out, $out instanceof WP_Error ? $out->get_error_code() . ' ' . $out->get_error_message() : '' );
		$this->assertTrue( $out['changed'] );
		$after = self::layout_data( $ids['layout'] );
		$this->assertSame( $before['conditions'], $after['conditions'] );
		$this->assertSame(
			array(
				'header'  => $ids['header'],
				'content' => 999999,
				'footer'  => $ids['footer2'],
			),
			$after['template_terms']
		);
		$this->assertSame(
			array(
				'sections'   => array(
					'header'  => $ids['header'],
					'content' => 999999,
				),
				'conditions' => array_values( $before['conditions'] ),
			),
			$out['previous']
		);
		$listed = aafm_exec_avada_list_layouts( array() );
		$this->assertSame( $ids['footer2'], $listed['layouts'][0]['sections']->footer['id'] );
	}

	public function test_the_written_json_has_avadas_own_shape(): void {
		$ids = $this->layout_fixture();
		$raw = get_post_field( 'post_content', $ids['layout'], 'raw' );
		// A slot assigned and then cleared again leaves Avada's own stored bytes.
		$this->layout_write(
			array(
				'layout_id' => $ids['layout'],
				'sections'  => array( 'footer' => $ids['footer2'] ),
			)
		);
		$this->assertNotSame( $raw, get_post_field( 'post_content', $ids['layout'], 'raw' ) );
		$this->layout_write(
			array(
				'layout_id' => $ids['layout'],
				'sections'  => array( 'footer' => 0 ),
			)
		);
		$this->assertSame( $raw, get_post_field( 'post_content', $ids['layout'], 'raw' ) );
	}

	public function test_zero_clears_a_slot_and_a_wrong_draft_or_non_section_is_refused(): void {
		$ids = $this->layout_fixture();
		$out = $this->layout_write(
			array(
				'layout_id' => $ids['layout'],
				'sections'  => array( 'header' => 0 ),
			)
		);
		$this->assertTrue( $out['changed'] );
		$this->assertSame( array( 'content' => 999999 ), self::layout_data( $ids['layout'] )['template_terms'] );
		$raw = get_post_field( 'post_content', $ids['layout'], 'raw' );
		foreach ( array(
			array( 'header' => $ids['footer'] ),
			array( 'footer' => $ids['draft'] ),
			array( 'footer' => $ids['page'] ),
		) as $sections ) {
			$err = $this->layout_write(
				array(
					'layout_id' => $ids['layout'],
					'sections'  => $sections,
				)
			);
			$this->assertSame( 'aafm_avada_layout_section_invalid', $err->get_error_code() );
		}
		foreach ( array( array( 'sidebar' => 1 ), array( 'footer' => '5' ), array() ) as $sections ) {
			$err = $this->layout_write(
				array(
					'layout_id' => $ids['layout'],
					'sections'  => $sections,
				)
			);
			$this->assertSame( 'aafm_builder_invalid_value', $err->get_error_code() );
		}
		$this->assertSame( $raw, get_post_field( 'post_content', $ids['layout'], 'raw' ) );
	}

	public function test_conditions_replace_the_stored_list(): void {
		$ids = $this->layout_fixture();
		$out = $this->layout_write(
			array(
				'layout_id'  => $ids['layout'],
				'conditions' => array(
					array(
						'mode'     => 'include',
						'type'     => 'singular',
						'singular' => 'front_page',
					),
					array(
						'mode'     => 'exclude',
						'type'     => 'archives',
						'archives' => 'all_archives',
					),
				),
			)
		);
		$this->assertTrue( $out['changed'] );
		$this->assertSame(
			array(
				'front_page'   => array(
					'label'    => 'Front Page',
					'type'     => 'singular',
					'mode'     => 'include',
					'singular' => 'front_page',
				),
				'all_archives' => array(
					'label'    => 'All Archives',
					'type'     => 'archives',
					'mode'     => 'exclude',
					'archives' => 'all_archives',
				),
			),
			self::layout_data( $ids['layout'] )['conditions']
		);
		$this->assertTrue(
			$this->layout_write(
				array(
					'layout_id'  => $ids['layout'],
					'conditions' => array(),
				)
			)['changed']
		);
		$this->assertSame( array(), self::layout_data( $ids['layout'] )['conditions'] );
	}

	/**
	 * CX-BR1-3: a specific page condition is stored the way Avada's dialog stores it, and the previous a conditions write
	 * returns goes back byte for byte, child rows and their stored labels included.
	 */
	public function test_child_conditions_are_accepted_and_previous_round_trips(): void {
		$ids   = $this->layout_fixture();
		$page  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Landing',
			)
		);
		$child = array(
			'mode'     => 'include',
			'type'     => 'singular',
			'singular' => 'specific_page|' . $page,
			'parent'   => 'specific_page',
		);
		$this->assertTrue(
			$this->layout_write(
				array(
					'layout_id'  => $ids['layout'],
					'conditions' => array( $child ),
				)
			)['changed']
		);
		$stored = self::layout_data( $ids['layout'] )['conditions'];
		$this->assertSame(
			array(
				'specific_page|' . $page => array(
					'label'    => 'Landing',
					'type'     => 'singular',
					'mode'     => 'include',
					'singular' => 'specific_page|' . $page,
					'parent'   => 'specific_page',
				),
			),
			$stored
		);
		// The page is renamed after the dialog stored it; the stored label stays, as Avada keeps it.
		wp_update_post(
			array(
				'ID'         => $page,
				'post_title' => 'Renamed',
			)
		);
		$raw  = get_post_field( 'post_content', $ids['layout'], 'raw' );
		$next = $this->layout_write(
			array(
				'layout_id'  => $ids['layout'],
				'conditions' => array(
					$child,
					array(
						'mode'     => 'include',
						'type'     => 'singular',
						'singular' => 'front_page',
					),
				),
			)
		);
		$this->assertTrue( $next['changed'] );
		$this->assertSame( 'Landing', self::layout_data( $ids['layout'] )['conditions'][ 'specific_page|' . $page ]['label'] );
		$back = $this->layout_write(
			array(
				'layout_id'  => $ids['layout'],
				'conditions' => json_decode( (string) wp_json_encode( $next['previous']['conditions'] ), true ),
			)
		);
		$this->assertTrue( $back['changed'] );
		$this->assertSame( $raw, get_post_field( 'post_content', $ids['layout'], 'raw' ) );

		$post = self::factory()->post->create();
		foreach ( array(
			array( 'singular' => 'specific_page|999999' ),
			array( 'singular' => 'specific_page|' . $post ),
			array( 'singular' => 'specific_post|' . $page ),
			array( 'parent' => 'front_page' ),
			array( 'singular' => 'specific_page|0' ),
		) as $bad ) {
			$err = $this->layout_write(
				array(
					'layout_id'  => $ids['layout'],
					'conditions' => array( array_merge( $child, $bad ) ),
				)
			);
			$this->assertSame( 'aafm_builder_invalid_value', $err->get_error_code(), (string) wp_json_encode( $bad ) );
		}
	}

	public function test_a_condition_with_an_unknown_mode_type_or_value_key_is_refused(): void {
		$ids = $this->layout_fixture();
		$raw = get_post_field( 'post_content', $ids['layout'], 'raw' );
		foreach ( array(
			array(
				'mode'     => 'maybe',
				'type'     => 'singular',
				'singular' => 'front_page',
			),
			array(
				'mode'     => 'include',
				'type'     => 'taxonomy',
				'taxonomy' => 'front_page',
			),
			array(
				'mode'     => 'include',
				'type'     => 'singular',
				'singular' => 'page_99',
			),
			array(
				'mode'     => 'include',
				'type'     => 'singular',
				'archives' => 'all_archives',
			),
			array(
				'mode'     => 'include',
				'type'     => 'singular',
				'singular' => 'front_page',
				'parent'   => 'nowhere',
			),
			array(
				'mode'     => 'include',
				'type'     => 'singular',
				'singular' => 'front_page',
				'onclick'  => 'x',
			),
		) as $condition ) {
			$err = $this->layout_write(
				array(
					'layout_id'  => $ids['layout'],
					'conditions' => array( $condition ),
				)
			);
			$this->assertSame( 'aafm_builder_invalid_value', $err->get_error_code(), (string) wp_json_encode( $condition ) );
		}
		$this->assertSame( $raw, get_post_field( 'post_content', $ids['layout'], 'raw' ) );
		$this->assertSame( array(), self::$resets );
	}

	public function test_the_global_layout_is_written_to_its_option_and_verified(): void {
		$ids = $this->layout_fixture();
		update_option(
			'fusion_tb_layout_default',
			wp_slash(
				(string) wp_json_encode(
					array(
						'conditions'     => array(),
						'template_terms' => array( 'header' => $ids['header'] ),
					)
				)
			)
		);
		$out = $this->layout_write(
			array(
				'layout_id' => 'global',
				'sections'  => array( 'footer' => $ids['footer'] ),
			)
		);
		$this->assertSame( 'global', $out['layout_id'] );
		$this->assertTrue( $out['changed'] );
		$this->assertSame(
			array(
				'conditions'     => array(),
				'template_terms' => array(
					'header' => $ids['header'],
					'footer' => $ids['footer'],
				),
			),
			json_decode( wp_unslash( get_option( 'fusion_tb_layout_default' ) ), true )
		);
		$keep = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_fusion_tb_layout_default', $keep, 10, 2 );
		$err  = $this->layout_write(
			array(
				'layout_id' => 'global',
				'sections'  => array( 'footer' => 0 ),
			)
		);
		remove_filter( 'pre_update_option_fusion_tb_layout_default', $keep, 10 );
		$this->assertSame( 'aafm_avada_layout_not_saved', $err->get_error_code() );
		$this->assertSame( array(), self::$resets );
		delete_option( 'fusion_tb_layout_default' );
	}

	public function test_an_unknown_layout_id_and_an_empty_call_are_refused(): void {
		$ids = $this->layout_fixture();
		foreach ( array( $ids['page'], 999999, 0, 'other' ) as $layout_id ) {
			$err = $this->layout_write(
				array(
					'layout_id' => $layout_id,
					'sections'  => array( 'footer' => 0 ),
				)
			);
			$this->assertSame( 'aafm_avada_unknown_layout', $err->get_error_code() );
		}
		$this->assertSame( 'aafm_avada_layout_empty', $this->layout_write( array( 'layout_id' => $ids['layout'] ) )->get_error_code() );
	}

	public function test_the_cache_call_is_the_narrow_one_and_assigning_the_stored_sections_changes_nothing(): void {
		$ids = $this->layout_fixture();
		$raw = get_post_field( 'post_content', $ids['layout'], 'raw' );
		$out = $this->layout_write(
			array(
				'layout_id' => $ids['layout'],
				'sections'  => array( 'header' => $ids['header'] ),
			)
		);
		$this->assertFalse( $out['changed'] );
		$this->assertSame( array(), self::$resets );
		$this->assertSame( $raw, get_post_field( 'post_content', $ids['layout'], 'raw' ) );
		$this->layout_write(
			array(
				'layout_id' => $ids['layout'],
				'sections'  => array( 'footer' => $ids['footer2'] ),
			)
		);
		$this->assertCount( 1, self::$resets );
		$this->assertSame( array( 'compiled_assets', 'transients', 'gfonts', 'fa_font', 'demo_data', 'po_export', 'patcher_messages', 'other_caches' ), array_keys( self::$resets[0] ) );
		$this->assertSame( array( true, true, false, false, false, false, false, false ), array_values( self::$resets[0] ) );
	}

	public function test_layout_schema_permission_audit_and_description(): void {
		$name = 'aafm/avada-update-layout';
		$args = aafm_args_avada_update_layout();
		$this->assertSame( 'aafm_perm_manage_options', $args['permission_callback'] );
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'layout_id' ), $args['input_schema']['required'] );
		$this->assertTrue( $args['meta']['annotations']['destructive'] );
		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_perm_manage_options() );
		$this->assertContains( $name, aafm_non_removal_destructive_abilities() );
		$this->assertSame( 'Ran an Avada layout update on layout #42', aafm_build_activity_detail( $name, array( 'layout_id' => 42 ) ) );
		$this->assertSame( 'Ran an Avada layout update on the Global Layout', aafm_build_activity_detail( $name, array( 'layout_id' => 'global' ) ) );
		$row = aafm_get_abilities_registry_full()[ $name ];
		$this->assertSame( 'destructive', $row['risk'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'site-wide', 'previous', 'never created or deleted', 'manage site options' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text, $phrase );
			}
		}
	}

	public function test_a_layout_avada_cannot_decode_is_refused(): void {
		$ids = $this->layout_fixture();
		global $wpdb;
		$raw = (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Avada/layout-shop.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		$wpdb->update( $wpdb->posts, array( 'post_content' => $raw ), array( 'ID' => $ids['layout'] ) );
		clean_post_cache( $ids['layout'] );
		$err = $this->layout_write(
			array(
				'layout_id' => $ids['layout'],
				'sections'  => array( 'footer' => $ids['footer'] ),
			)
		);
		$this->assertSame( 'aafm_avada_layout_unparseable', $err->get_error_code() );
		$this->assertSame( $raw, get_post_field( 'post_content', $ids['layout'], 'raw' ) );
	}

	/**
	 * What the stub Studio importer was asked, in order: method => args.
	 *
	 * @var list<array{0:string,1:array<int,mixed>}>
	 */
	public static array $studio_calls = array();

	/**
	 * The Studio seam: the theme's option name, a catalogue, an importer that records its calls and creates the post
	 * Avada would, and the registration answer. Each entry can be forced absent; $catalogue false is a failed fetch.
	 *
	 * @param array<string,mixed>|false $catalogue  What get_data() returns.
	 * @param array<string,bool>        $absent     Entries to force absent.
	 * @param bool                      $registered What should_show( 'studio' ) answers.
	 * @param mixed                     $result     What import_post() returns, or null to create the post.
	 * @return callable
	 */
	private function studio_seam( $catalogue = null, array $absent = array(), bool $registered = true, $result = null ): callable {
		self::$studio_calls = array();
		$catalogue          = null === $catalogue ? array(
			'sections'        => array(
				array(
					'ID'         => 501,
					'post_title' => 'Hero <b>block</b>',
					'post_type'  => 'fusion_element',
					'url'        => 'https://avada.studio/item/501/',
					'tags'       => array( 'heroes', 'dark' ),
				),
				array(
					'ID'         => 502,
					'post_title' => str_repeat( 'x', 300 ),
					'post_type'  => 'fusion_element',
				),
				array(
					'post_title' => 'no id',
					'post_type'  => 'fusion_element',
				),
				array(
					'ID'         => 503,
					'post_title' => 'Not a Studio type',
					'post_type'  => 'page',
				),
			),
			'fusion_template' => array(
				array(
					'ID'         => 601,
					'post_title' => 'Landing',
					'post_type'  => 'fusion_template',
				),
			),
			'studio_tags'     => array( 'sections' => array( 'heroes' ) ),
			'studio_elements' => array( 'count' => 3 ),
		) : $catalogue;
		$studio             = new class( $catalogue ) {
			/**
			 * Catalogue.
			 *
			 * @var mixed
			 */
			private $data;

			/**
			 * Constructor.
			 *
			 * @param mixed $data Catalogue.
			 */
			public function __construct( $data ) {
				$this->data = $data;
			}

			/**
			 * Avada's own method name (A21).
			 *
			 * @return mixed
			 */
			public function get_data() {
				return $this->data;
			}
		};
		$importer           = new class( $result ) {
			/**
			 * Avada's own switch; never set by this plugin.
			 *
			 * @var bool
			 */
			public $update_post = false;

			/**
			 * Forced result.
			 *
			 * @var mixed
			 */
			private $result;

			/**
			 * Constructor.
			 *
			 * @param mixed $result Result.
			 */
			public function __construct( $result ) {
				$this->result = $result;
			}

			/**
			 * Record.
			 *
			 * @param array<string,mixed> $options Options.
			 * @return void
			 */
			public function set_import_options( $options ): void {
				AvadaSiteTest::$studio_calls[] = array( 'set_import_options', array( $options ) );
			}

			/**
			 * Record and create the post Avada's importer would.
			 *
			 * @param array<string,mixed> $studio_post  Studio post.
			 * @param array<string,mixed> $local_post   Local post.
			 * @param bool                $import_media Media.
			 * @return mixed
			 */
			public function import_post( $studio_post = array(), $local_post = array(), $import_media = true ) {
				AvadaSiteTest::$studio_calls[] = array( 'import_post', array( $studio_post, $local_post, $import_media, function_exists( 'media_handle_sideload' ), function_exists( 'wp_generate_attachment_metadata' ), function_exists( 'download_url' ) ) );
				if ( null !== $this->result ) {
					return $this->result;
				}
				$id = wp_insert_post(
					array(
						'post_type'   => $studio_post['post_type'],
						'post_title'  => 'Imported',
						'post_status' => 'publish',
					)
				);
				update_post_meta( $id, '_avada_studio_post', $studio_post['post_id'] );
				return array(
					'post_id'      => $id,
					'was_imported' => false,
				);
			}
		};
		$filter             = static function ( $resolved, string $entry ) use ( $studio, $importer, $absent, $registered ) {
			if ( ! empty( $absent[ $entry ] ) ) {
				return null;
			}
			switch ( $entry ) {
				case 'avada_option_name':
					return static fn(): string => 'fusion_options';
				case 'avada_studio':
					return static fn() => $studio;
				case 'avada_studio_import':
					return static fn() => $importer;
				case 'avada_studio_registered':
					return static fn(): bool => $registered;
			}
			return $resolved;
		};
		add_filter( 'aafm_builder_vendor', $filter, 20, 2 );
		return $filter;
	}

	public function test_studio_types_are_avadas_six(): void {
		$this->assertSame( array( 'fusion_template', 'fusion_tb_section', 'fusion_element', 'fusion_icons', 'fusion_form', 'awb_off_canvas' ), aafm_avada_studio_types() );
	}

	public function test_studio_is_unavailable_without_the_theme_with_studio_off_or_unregistered(): void {
		$filter = $this->studio_seam();
		$this->assertTrue( aafm_avada_studio_ready() );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		foreach ( array(
			array( array( 'avada_option_name' => true ), true ),
			array( array( 'avada_studio' => true ), true ),
			array( array( 'avada_studio_import' => true ), true ),
			array( array( 'avada_studio_registered' => true ), true ),
			array( array(), false ),
		) as $case ) {
			$filter = $this->studio_seam( null, $case[0], $case[1] );
			$out    = aafm_avada_studio_ready();
			remove_filter( 'aafm_builder_vendor', $filter, 20 );
			$this->assertInstanceOf( WP_Error::class, $out );
			$this->assertSame( 'aafm_avada_studio_unavailable', $out->get_error_code() );
			$this->assertSame( 409, $out->get_error_data()['status'] );
		}
	}

	public function test_a_failed_catalogue_fetch_is_an_error_not_an_empty_list(): void {
		$filter = $this->studio_seam( false );
		$out    = aafm_avada_studio_catalogue();
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertSame( 'aafm_avada_studio_fetch_failed', $out->get_error_code() );
	}

	public function test_list_rows_are_trimmed_and_capped(): void {
		$this->assertSame(
			array(
				'id'          => 501,
				'type'        => 'fusion_element',
				'title'       => 'Hero block',
				'category'    => 'sections',
				'preview_url' => 'https://avada.studio/item/501/',
			),
			aafm_avada_studio_row(
				array(
					'ID'         => '501',
					'post_title' => 'Hero <b>block</b>',
					'post_type'  => 'fusion_element',
					'url'        => 'https://avada.studio/item/501/',
					'secret'     => 'x',
				),
				'sections'
			)
		);
		$long = array(
			'ID'         => 2,
			'post_type'  => 'fusion_element',
			'post_title' => str_repeat( 'é', 300 ),
		);
		$this->assertSame( 200, mb_strlen( aafm_avada_studio_row( $long, 'elements' )['title'] ) );
		$no_id = array(
			'post_title' => 'x',
			'post_type'  => 'fusion_element',
		);
		$this->assertNull( aafm_avada_studio_row( $no_id, 'elements' ) );
		$this->assertNull(
			aafm_avada_studio_row(
				array(
					'ID'        => 3,
					'post_type' => 'post',
				),
				'elements'
			)
		);
		$this->assertNull( aafm_avada_studio_row( 'x', 'elements' ) );
	}


	public function test_the_studio_source_never_names_the_registration_data(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/abilities/avada-site.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source scan.
		$start  = strpos( $source, 'function aafm_avada_studio_types' );
		$this->assertNotFalse( $start );
		$studio = substr( $source, (int) $start );
		foreach ( array( 'purchase_code', 'token', 'registration_data' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $studio, $needle );
		}
		$seam  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/builder-shortcodes.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source scan.
		$start = (int) strpos( $seam, "'avada_studio'" );
		$rows  = substr( $seam, $start, (int) strpos( $seam, "'avada_option_name'", $start ) - $start );
		$this->assertStringContainsString( "should_show( 'studio' )", $rows );
		foreach ( array( 'purchase_code', 'token', 'registration_data', 'get_option' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $rows, $needle );
		}
	}

	/**
	 * The owner's approval: the import switched on and the high-risk category unlocked, or either undone.
	 *
	 * @param bool $enabled  Import switched on.
	 * @param bool $unlocked Category unlocked.
	 * @return void
	 */
	private function studio_approval( bool $enabled, bool $unlocked ): void {
		aafm_flush_registry_cache();
		update_option( 'aafm_enabled_abilities', $enabled ? array( 'aafm/avada-import-studio-item', 'aafm/avada-list-studio-items' ) : array() );
		if ( $unlocked ) {
			update_option( 'aafm_high_risk_abilities_unlocked', true );
		} else {
			delete_option( 'aafm_high_risk_abilities_unlocked' );
		}
	}

	/**
	 * Run a Studio executor as an administrator with the seam in place.
	 *
	 * @param string              $tool    list or import.
	 * @param array<string,mixed> $input   Input.
	 * @param array<int,mixed>    $seam    Arguments for studio_seam().
	 * @return array<string,mixed>|WP_Error
	 */
	private function studio_call( string $tool, array $input, array $seam = array() ) {
		$filter = $this->studio_seam( ...$seam );
		$this->acting_as( 'administrator' );
		$out = 'list' === $tool ? aafm_exec_avada_list_studio_items( $input ) : aafm_exec_avada_import_studio_item( $input );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		return $out;
	}

	public function test_both_studio_tools_start_off(): void {
		delete_option( 'aafm_enabled_abilities' );
		delete_option( 'aafm_high_risk_abilities_unlocked' );
		$this->assertNotContains( 'aafm/avada-list-studio-items', aafm_get_enabled_abilities() );
		$this->assertNotContains( 'aafm/avada-import-studio-item', aafm_get_enabled_abilities() );
		$this->assertFalse( aafm_avada_studio_approved() );
	}

	public function test_the_list_and_import_are_refused_while_the_import_is_locked_or_off(): void {
		foreach ( array( array( true, false ), array( false, true ), array( false, false ) ) as $case ) {
			$this->studio_approval( $case[0], $case[1] );
			foreach ( array( 'list', 'import' ) as $tool ) {
				$out = $this->studio_call(
					$tool,
					array(
						'item_id' => 501,
						'type'    => 'fusion_element',
					)
				);
				$this->assertSame( 'aafm_avada_studio_not_approved', $out->get_error_code(), $tool );
				$this->assertSame( 403, $out->get_error_data()['status'] );
			}
		}
		$this->assertSame( array(), self::$studio_calls );
	}

	public function test_both_studio_tools_need_manage_options_and_an_editor_cannot_discover_either(): void {
		foreach ( array( 'aafm_args_avada_list_studio_items', 'aafm_args_avada_import_studio_item' ) as $args ) {
			$this->assertSame( 'aafm_perm_manage_options', $args()['permission_callback'] );
		}
		$this->assertNull( aafm_ability_list_permission( 'aafm/avada-list-studio-items' ) );
		$this->assertNull( aafm_ability_list_permission( 'aafm/avada-import-studio-item' ) );
		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_perm_manage_options() );
	}

	public function test_studio_list_returns_trimmed_rows_by_type_search_and_page(): void {
		$this->studio_approval( true, true );
		$out = $this->studio_call( 'list', array( 'type' => 'fusion_element' ) );
		$this->assertSame( 2, $out['total'] );
		$this->assertFalse( $out['from_cache'] );
		$this->assertSame(
			array(
				'id'          => 501,
				'type'        => 'fusion_element',
				'title'       => 'Hero block',
				'category'    => 'sections',
				'preview_url' => 'https://avada.studio/item/501/',
			),
			$out['items'][0]
		);
		$this->assertSame( 200, mb_strlen( $out['items'][1]['title'] ) );
		$this->assertSame( array( 601 ), array_column( $this->studio_call( 'list', array( 'search' => 'land' ) )['items'], 'id' ) );
		$this->assertSame(
			array( 502 ),
			array_column(
				$this->studio_call(
					'list',
					array(
						'type'     => 'fusion_element',
						'per_page' => 1,
						'page'     => 2,
					)
				)['items'],
				'id'
			)
		);
		set_transient( 'avada_studio', array( 'x' ), 60 );
		$this->assertTrue( $this->studio_call( 'list', array() )['from_cache'] );
		delete_transient( 'avada_studio' );
		$this->assertSame( 'aafm_avada_studio_fetch_failed', $this->studio_call( 'list', array(), array( false ) )->get_error_code() );
		$this->assertSame( 'aafm_avada_studio_unavailable', $this->studio_call( 'list', array(), array( null, array(), false ) )->get_error_code() );
	}

	public function test_an_item_not_in_the_catalogue_or_of_another_type_is_refused_before_avada_is_called(): void {
		$this->studio_approval( true, true );
		foreach ( array( array( 999, 'fusion_element' ), array( 601, 'fusion_element' ), array( 501, 'fusion_template' ) ) as $case ) {
			$out = $this->studio_call(
				'import',
				array(
					'item_id' => $case[0],
					'type'    => $case[1],
				)
			);
			$this->assertSame( 'aafm_builder_unknown_template', $out->get_error_code() );
		}
		$this->assertSame( array(), self::$studio_calls );
	}

	public function test_import_calls_avada_with_the_item_and_never_sets_update_post(): void {
		$this->studio_approval( true, true );
		register_post_type( 'fusion_element', array( 'public' => false ) );
		$out = $this->studio_call(
			'import',
			array(
				'item_id' => 501,
				'type'    => 'fusion_element',
			)
		);
		$this->assertIsArray( $out );
		$this->assertSame(
			array(
				array( 'set_import_options', array( array( 'images' => 'do-import-images' ) ) ),
				array(
					'import_post',
					array(
						array(
							'post_id'   => 501,
							'post_type' => 'fusion_element',
						),
						array(),
						true,
						true,
						true,
						true,
					),
				),
			),
			self::$studio_calls
		);
		$this->assertSame( array( 'post_id', 'post_type', 'title', 'was_imported', 'media', 'note' ), array_keys( $out ) );
		$this->assertSame( 'fusion_element', $out['post_type'] );
		$this->assertFalse( $out['was_imported'] );
		$this->assertSame( 'import', $out['media'] );
		$this->assertSame( '501', get_post_meta( $out['post_id'], '_avada_studio_post', true ) );
		$this->assertStringContainsString( 'avada-insert-library-item', $out['note'] );
	}

	public function test_media_placeholders_sets_the_images_option(): void {
		$this->studio_approval( true, true );
		$out = $this->studio_call(
			'import',
			array(
				'item_id' => 601,
				'type'    => 'fusion_template',
				'media'   => 'placeholders',
			)
		);
		$this->assertSame( 'placeholders', $out['media'] );
		$this->assertSame( array( 'images' => 'dont-import-images' ), self::$studio_calls[0][1][0] );
	}

	public function test_a_false_post_id_and_a_bad_read_back_are_import_failed_and_was_imported_passes_through(): void {
		$this->studio_approval( true, true );
		$args  = array(
			'item_id' => 501,
			'type'    => 'fusion_element',
		);
		$wrong = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$plain = self::factory()->post->create( array( 'post_type' => 'fusion_element' ) );
		foreach ( array( array( 'post_id' => false ), 'x', array( 'post_id' => $wrong ), array( 'post_id' => $plain ) ) as $result ) {
			$this->assertSame( 'aafm_avada_studio_import_failed', $this->studio_call( 'import', $args, array( null, array(), true, $result ) )->get_error_code() );
		}
		update_post_meta( $plain, '_avada_studio_post', '501' );
		$out = $this->studio_call(
			'import',
			$args,
			array(
				null,
				array(),
				true,
				array(
					'post_id'      => $plain,
					'was_imported' => true,
				),
			)
		);
		$this->assertTrue( $out['was_imported'] );
		$this->assertSame( $plain, $out['post_id'] );
	}

	public function test_this_plugins_code_makes_no_http_request_and_no_output_holds_a_token(): void {
		$this->studio_approval( true, true );
		update_option( 'fusion_registration_data', array( 'avada' => array( 'purchase_code' => 'MARKER-TOKEN-55' ) ) );
		$fired = 0;
		$block = static function ( $pre ) use ( &$fired ) {
			++$fired;
			return $pre;
		};
		add_filter( 'pre_http_request', $block );
		$list   = $this->studio_call( 'list', array() );
		$import = $this->studio_call(
			'import',
			array(
				'item_id' => 501,
				'type'    => 'fusion_element',
			)
		);
		remove_filter( 'pre_http_request', $block );
		$this->assertSame( 0, $fired );
		foreach ( array(
			$list,
			$import,
			aafm_build_activity_detail(
				'aafm/avada-import-studio-item',
				array(
					'item_id' => 501,
					'type'    => 'fusion_element',
				)
			),
		) as $out ) {
			$this->assertStringNotContainsString( 'MARKER-TOKEN-55', (string) wp_json_encode( $out ) );
		}
		delete_option( 'fusion_registration_data' );
	}

	public function test_studio_audit_detail_schemas_and_descriptions(): void {
		$this->assertSame(
			'Ran an Avada Studio import of fusion_element item #501',
			aafm_build_activity_detail(
				'aafm/avada-import-studio-item',
				array(
					'item_id' => 501,
					'type'    => 'fusion_element',
					'media'   => 'import',
				)
			)
		);
		$this->assertFalse( aafm_args_avada_list_studio_items()['input_schema']['additionalProperties'] );
		$this->assertSame( 50, aafm_args_avada_list_studio_items()['input_schema']['properties']['per_page']['maximum'] );
		$import = aafm_args_avada_import_studio_item()['input_schema'];
		$this->assertFalse( $import['additionalProperties'] );
		$this->assertSame( array( 'item_id', 'type' ), $import['required'] );
		$this->assertSame( array( 'import', 'placeholders' ), $import['properties']['media']['enum'] );
		$registry = aafm_get_abilities_registry_full();
		$this->assertSame( 'read', $registry['aafm/avada-list-studio-items']['risk'] );
		$this->assertSame( 'write', $registry['aafm/avada-import-studio-item']['risk'] );
		// BR-3: both make Avada call avada.studio, so neither may claim a closed world.
		$this->assertTrue( aafm_args_avada_list_studio_items()['meta']['annotations']['openWorldHint'] );
		$this->assertTrue( aafm_args_avada_import_studio_item()['meta']['annotations']['openWorldHint'] );
		foreach ( array( 'aafm/avada-list-studio-items', 'aafm/avada-import-studio-item' ) as $name ) {
			foreach ( array( $registry[ $name ]['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
				$this->assertStringNotContainsString( ' - ', $text );
				foreach ( array( 'Avada\'s servers', 'this plugin sends nothing', 'manage site options', 'high-risk' ) as $phrase ) {
					$this->assertStringContainsString( $phrase, $text, $name . ': ' . $phrase );
				}
			}
		}
	}
}
