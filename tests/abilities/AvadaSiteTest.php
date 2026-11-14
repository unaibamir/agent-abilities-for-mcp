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
		$anchor = $this->input( $id, 1 );
		$anchor = array(
			'index'     => $anchor['anchor_index'],
			'expect'    => $anchor['anchor_expect'],
			'placement' => 'after',
		);
		$node   = array(
			'tag'      => 'fusion_global',
			'atts'     => array( 'id' => '77' ),
			'children' => array(),
			'source'   => '[fusion_global id="77"]',
			'place_as' => 'fusion_builder_container',
		);
		$this->assert_refused( aafm_avada_insert_tree( get_post( $id ), array( $node ), $anchor, false ), 'aafm_builder_unknown_element', 400, $id, self::PAGE );
		foreach ( array(
			array(
				'atts' => array(
					'id'    => '77',
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
		$this->assertSame( self::PAGE . '[fusion_global id="77"]', get_post( $id )->post_content );
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

	public function test_css_is_stored_without_tags(): void {
		$this->act_as_admin();
		$id = $this->avada_post();
		$this->assertTrue( $this->page_css( $id, '.a{}</style><script>x()</script>' )['changed'] );
		$this->assertStringNotContainsString( '<', (string) get_post_meta( $id, '_fusion_builder_custom_css', true ) );
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
}
