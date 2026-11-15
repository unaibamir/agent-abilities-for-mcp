<?php
/**
 * WPBakery site tools (design 325 WB-7, WB-8, WB-10): saved templates listed from wpb_js_templates, a template
 * copied into a page through the shared insert path, and the page's custom CSS and JS. WPBakery is reached only
 * through the vendor seam stub (Ruling B14).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\WpbakeryMapStub;
use AAFM\Tests\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/Support/WpbakeryMapStub.php';

final class WpbakerySiteTest extends TestCase {

	private const SIMPLE = '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text][/vc_column][/vc_row]';

	private const TEMPLATE = '[vc_row][vc_column][vc_column_text]<p>From the template</p>[/vc_column_text][/vc_column][/vc_row]';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		WpbakeryMapStub::install();
	}

	public function tear_down(): void {
		WpbakeryMapStub::remove();
		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		remove_all_filters( 'aafm_templatera_active' );
		delete_option( 'wpb_js_templates' );
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
		$this->assertSame( $code, $out->get_error_code() );
		$this->assertSame( $status, $out->get_error_data()['status'] ?? null );
	}

	/**
	 * Store templates the way WPBakery's own save does: an array keyed by a generated id, each name and template.
	 *
	 * @param array<string,mixed> $rows Rows.
	 * @return void
	 */
	private function templates( array $rows ): void {
		update_option( 'wpb_js_templates', $rows, false );
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

	public function test_list_returns_the_option_rows(): void {
		$this->templates(
			array(
				'hero_1'  => array(
					'name'     => 'Hero',
					'template' => self::TEMPLATE,
				),
				'promo_2' => array(
					'name'     => 'Promo',
					'template' => self::SIMPLE,
				),
			)
		);
		$this->acting_as( 'editor' );
		$this->assertSame(
			array(
				'templates'         => array(
					array(
						'id'     => 'hero_1',
						'source' => 'saved',
						'name'   => 'Hero',
					),
					array(
						'id'     => 'promo_2',
						'source' => 'saved',
						'name'   => 'Promo',
					),
				),
				'total'             => 2,
				'templatera_active' => false,
			),
			aafm_exec_wpbakery_list_templates( array() )
		);
		$this->assertSame(
			array(
				array(
					'id'      => 'hero_1',
					'name'    => 'Hero',
					'content' => self::TEMPLATE,
				),
				array(
					'id'      => 'promo_2',
					'name'    => 'Promo',
					'content' => self::SIMPLE,
				),
			),
			aafm_wpbakery_saved_templates()
		);
	}

	public function test_list_skips_a_malformed_row(): void {
		$this->templates(
			array(
				'ok_1'      => array(
					'name'     => 'Fine',
					'template' => self::TEMPLATE,
				),
				'string_2'  => 'not a row',
				'no_name_3' => array( 'template' => self::TEMPLATE ),
				'no_text_4' => array( 'name' => 'Empty' ),
				'array_5'   => array(
					'name'     => array( 'x' ),
					'template' => self::TEMPLATE,
				),
			)
		);
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_list_templates( array() );
		$this->assertSame( array( 'ok_1' ), array_column( $out['templates'], 'id' ) );
		$this->assertSame( 1, $out['total'] );
	}

	public function test_list_of_an_absent_option_is_empty_with_total_0(): void {
		delete_option( 'wpb_js_templates' );
		$this->acting_as( 'editor' );
		$this->assertSame(
			array(
				'templates'         => array(),
				'total'             => 0,
				'templatera_active' => false,
			),
			aafm_exec_wpbakery_list_templates( array() )
		);
		update_option( 'wpb_js_templates', 'garbage', false );
		$this->assertSame( 0, aafm_exec_wpbakery_list_templates( array() )['total'] );
	}

	public function test_content_is_returned_only_on_request(): void {
		$this->templates(
			array(
				'hero_1' => array(
					'name'     => 'Hero',
					'template' => self::TEMPLATE,
				),
			)
		);
		$this->acting_as( 'editor' );
		$this->assertArrayNotHasKey( 'content', aafm_exec_wpbakery_list_templates( array() )['templates'][0] );
		$this->assertSame( self::TEMPLATE, aafm_exec_wpbakery_list_templates( array( 'include_content' => true ) )['templates'][0]['content'] );
	}

	/**
	 * Ruling R-S11-4: a template that holds a script kind keeps its content from a caller without unfiltered_html.
	 */
	public function test_script_bearing_content_is_withheld_without_unfiltered_html(): void {
		$design = '[vc_row css=".vc_custom_1{margin-top:10px !important;}"][vc_column][vc_column_text]<p>D</p>[/vc_column_text][/vc_column][/vc_row]';
		$this->templates(
			array(
				'plain_1'  => array(
					'name'     => 'Plain',
					'template' => self::TEMPLATE,
				),
				'design_2' => array(
					'name'     => 'Design',
					'template' => $design,
				),
			)
		);
		$this->act_as_restricted_editor();
		$rows = aafm_exec_wpbakery_list_templates( array( 'include_content' => true ) )['templates'];
		$this->assertSame( self::TEMPLATE, $rows[0]['content'] );
		$this->assertArrayNotHasKey( 'content_withheld', $rows[0] );
		$this->assertSame(
			array(
				'id'               => 'design_2',
				'source'           => 'saved',
				'name'             => 'Design',
				'content_withheld' => true,
			),
			$rows[1]
		);

		$this->acting_as( 'administrator' );
		$rows = aafm_exec_wpbakery_list_templates( array( 'include_content' => true ) )['templates'];
		$this->assertSame( $design, $rows[1]['content'] );
		$this->assertArrayNotHasKey( 'content_withheld', $rows[1] );
	}

	public function test_list_paginates(): void {
		$rows = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$rows[ 't_' . $i ] = array(
				'name'     => 'T' . $i,
				'template' => self::TEMPLATE,
			);
		}
		$this->templates( $rows );
		$this->acting_as( 'editor' );
		$first = aafm_exec_wpbakery_list_templates( array() );
		$this->assertCount( 20, $first['templates'] );
		$this->assertSame( 25, $first['total'] );
		$this->assertSame(
			array( 't_21', 't_22', 't_23', 't_24', 't_25' ),
			array_column(
				aafm_exec_wpbakery_list_templates(
					array(
						'page'     => 2,
						'per_page' => 20,
					)
				)['templates'],
				'id'
			)
		);
	}

	public function test_a_template_name_comes_back_as_plain_text(): void {
		$this->templates(
			array(
				'x_1' => array(
					'name'     => '<b>Bold</b> name',
					'template' => self::TEMPLATE,
				),
			)
		);
		$this->acting_as( 'editor' );
		$this->assertSame( 'Bold name', aafm_exec_wpbakery_list_templates( array() )['templates'][0]['name'] );
	}

	public function test_templatera_active_is_reported_both_ways(): void {
		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_templatera_active() );
		$this->assertFalse( aafm_exec_wpbakery_list_templates( array() )['templatera_active'] );
		add_filter( 'aafm_templatera_active', '__return_true' );
		$this->assertTrue( aafm_templatera_active() );
		$this->assertTrue( aafm_exec_wpbakery_list_templates( array() )['templatera_active'] );
	}

	public function test_a_subscriber_is_refused(): void {
		$args = aafm_args_wpbakery_list_templates();
		$this->assertSame( 'aafm_perm_blocks_floor', $args['permission_callback'] );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_perm_blocks_floor() );
		$this->acting_as( 'contributor' );
		$this->assertTrue( aafm_perm_blocks_floor() );
		$this->assertNull( aafm_ability_list_permission( 'aafm/wpbakery-list-templates' ), 'Object independent: discovery runs the real callback.' );
	}

	public function test_list_schema_is_closed_and_read_only(): void {
		$args = aafm_args_wpbakery_list_templates();
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'include_content', 'for_post_id', 'page', 'per_page' ), array_keys( $args['input_schema']['properties'] ) );
		$this->assertSame( 'aafm-reads', $args['category'] );
		$this->assertTrue( $args['meta']['annotations']['readonly'] );
		$this->assertFalse( $args['meta']['annotations']['destructive'] );
	}

	public function test_list_description_says_what_the_design_requires(): void {
		$row = aafm_get_abilities_registry_full()['aafm/wpbakery-list-templates'];
		$this->assertSame( 'reads', $row['group'] );
		$this->assertSame( 'read', $row['risk'] );
		$this->assertSame( 'wpbakery', $row['subject'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()['aafm/wpbakery-list-templates'] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			$this->assertStringContainsString( 'Templatera', $text );
			$this->assertStringContainsString( 'unfiltered HTML', $text );
		}
		$this->assertStringContainsString( 'edit_posts', $row['description'] );
		$this->assertStringContainsString( 'Only while WPBakery Page Builder is active', $row['description'] );
	}

	/**
	 * A page with the given content, created as an administrator.
	 *
	 * @param string $content Content.
	 * @param int    $author  Author, 0 for the administrator.
	 * @return int
	 */
	private function page( string $content, int $author = 0 ): int {
		$current = get_current_user_id();
		$this->acting_as( 'administrator' );
		$args = array(
			'post_type'    => 'page',
			'post_content' => $content,
		);
		if ( $author ) {
			$args['post_author'] = $author;
		}
		$id = self::factory()->post->create( $args );
		wp_set_current_user( $current );
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
		return $id;
	}

	/**
	 * The expect object for an element of a post as stored now.
	 *
	 * @param int $id    Post.
	 * @param int $index Outline index.
	 * @return array{tag:string,fingerprint:string}
	 */
	private function expect( int $id, int $index ): array {
		$content  = (string) get_post_field( 'post_content', $id, 'raw' );
		$elements = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) );
		return array(
			'tag'         => $elements[ $index ]['tag'],
			'fingerprint' => aafm_builder_fingerprint( $content, $elements[ $index ] ),
		);
	}

	/**
	 * Run the template insert with anchor_expect and page_fingerprint filled in.
	 *
	 * @param int         $id        Post.
	 * @param string      $template  Template id.
	 * @param int         $anchor    Anchor index.
	 * @param string      $placement Placement.
	 * @param string|null $page      Page fingerprint, or null for the stored one.
	 * @return array<string,mixed>|WP_Error
	 */
	private function insert( int $id, string $template, int $anchor = 0, string $placement = 'after', ?string $page = null ) {
		return aafm_exec_wpbakery_insert_template(
			array(
				'post_id'          => $id,
				'page_fingerprint' => $page ?? aafm_builder_page_fingerprint( (string) get_post_field( 'post_content', $id, 'raw' ) ),
				'template_id'      => $template,
				'source'           => 'saved',
				'anchor_index'     => $anchor,
				'anchor_expect'    => $this->expect( $id, $anchor ),
				'placement'        => $placement,
			)
		);
	}

	/**
	 * Store one template under id t_1.
	 *
	 * @param string $text Shortcode text.
	 * @return void
	 */
	private function one_template( string $text ): void {
		$this->templates(
			array(
				't_1' => array(
					'name'     => 'One',
					'template' => $text,
				),
			)
		);
	}

	/**
	 * Assert a refusal left the page byte-identical.
	 *
	 * @param mixed  $out     Result.
	 * @param string $code    Code.
	 * @param int    $status  Status.
	 * @param int    $id      Post.
	 * @param string $content Content before.
	 * @return void
	 */
	private function assert_refused( $out, string $code, int $status, int $id, string $content ): void {
		$this->assert_error( $out, $code, $status );
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_copy_at_each_of_the_four_placements(): void {
		$block = '[vc_column_text]<p>T</p>[/vc_column_text]';
		$this->one_template( $block );
		$cases = array(
			array( 2, 'before', '[vc_row][vc_column]' . $block . '[vc_column_text]<p>One</p>[/vc_column_text][/vc_column][/vc_row]', 2 ),
			array( 2, 'after', '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text]' . $block . '[/vc_column][/vc_row]', 3 ),
			array( 1, 'inside_start', '[vc_row][vc_column]' . $block . '[vc_column_text]<p>One</p>[/vc_column_text][/vc_column][/vc_row]', 2 ),
			array( 1, 'inside_end', '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text]' . $block . '[/vc_column][/vc_row]', 3 ),
		);
		foreach ( $cases as list( $anchor, $placement, $expected, $index ) ) {
			$id = $this->page( self::SIMPLE );
			$this->acting_as( 'editor' );
			$out = $this->insert( $id, 't_1', $anchor, $placement );
			$this->assertIsArray( $out, $placement );
			$this->assertSame( $expected, get_post_field( 'post_content', $id, 'raw' ), $placement );
			$this->assertSame( $index, $out['index'] );
			$this->assertSame( substr( hash( 'sha256', $block ), 0, 16 ), $out['fingerprint'] );
			$this->assertSame( 1, $out['inserted'] );
			$this->assertIsInt( $out['restore_revision_id'] );
			$this->assertTrue( $out['reversible'] );
		}
	}

	public function test_the_templates_stored_bytes_land_in_the_page_unchanged(): void {
		$template = '[vc_row full_width="stretch_row" css=".vc_custom_1700000000000{padding-top: 20px !important;}"][vc_column width="1/2"][vc_custom_heading text="A ``quote``" font_container="tag:h2|text_align:left"][/vc_column][vc_column width="1/2"][vc_column_text]<p>Two &amp; three</p>[/vc_column_text][/vc_column][/vc_row]';
		$this->one_template( $template );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'administrator' );
		$out = $this->insert( $id, 't_1' );
		$this->assertIsArray( $out );
		$this->assertSame( self::SIMPLE . $template, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( 5, $out['inserted'] );
		$this->assertSame( aafm_builder_page_fingerprint( self::SIMPLE . $template ), $out['page_fingerprint'] );
	}

	public function test_insert_template_returns_a_redacted_post(): void {
		$this->one_template( self::TEMPLATE );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$out = $this->insert( $id, 't_1' );
		$this->assertIsArray( $out );
		$this->assertSame( aafm_redact_post( get_post( $id ) ), $out['post'] );
		$this->assertArrayNotHasKey( 'post_content', $out['post'] );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'inserted', 'restore_revision_id', 'reversible' ), array_keys( $out ) );
	}

	/**
	 * Review Focus 6: the second send of the same call carries a stale page fingerprint, so the template lands once.
	 */
	public function test_a_retried_template_insert_is_refused_and_stores_the_template_once(): void {
		$this->one_template( self::TEMPLATE );
		$id    = $this->page( self::SIMPLE );
		$first = aafm_builder_page_fingerprint( self::SIMPLE );
		$this->acting_as( 'editor' );
		$this->assertIsArray( $this->insert( $id, 't_1', 0, 'after', $first ) );
		$after = (string) get_post_field( 'post_content', $id, 'raw' );
		$out   = aafm_exec_wpbakery_insert_template(
			array(
				'post_id'          => $id,
				'page_fingerprint' => $first,
				'template_id'      => 't_1',
				'anchor_index'     => 0,
				'anchor_expect'    => $this->expect( $id, 0 ),
				'placement'        => 'after',
			)
		);
		$this->assert_refused( $out, 'aafm_builder_page_changed', 409, $id, $after );
		$this->assertSame( 1, substr_count( $after, 'From the template' ) );
	}

	public function test_a_template_with_design_options_is_refused_for_an_editor_without_unfiltered_html(): void {
		$template = '[vc_row css=".vc_custom_1{margin-top: 10px !important;}"][vc_column][vc_column_text]<p>D</p>[/vc_column_text][/vc_column][/vc_row]';
		$this->one_template( $template );
		$id = $this->page( self::SIMPLE );
		$this->act_as_restricted_editor();
		$this->assert_refused( $this->insert( $id, 't_1' ), 'aafm_builder_needs_unfiltered_html', 403, $id, self::SIMPLE );

		$this->acting_as( 'administrator' );
		$this->assertIsArray( $this->insert( $id, 't_1' ) );
		$this->assertSame( self::SIMPLE . $template, get_post_field( 'post_content', $id, 'raw' ) );
	}

	/**
	 * SEC-S9b-2 through the tool: template text an editor saved cannot carry a script, an event attribute, a data-*
	 * attribute, an encoded bracket or an unfinished tag onto a page for a user without unfiltered_html.
	 */
	public function test_unsafe_template_text_is_refused_for_an_editor_and_inserted_for_an_administrator(): void {
		$cases = array(
			'<script>alert(1)</script>'     => array( 'aafm_wpbakery_text_needs_unfiltered_html', 403 ),
			'<p onclick="alert(1)">x</p>'   => array( 'aafm_wpbakery_text_needs_unfiltered_html', 403 ),
			'<p data-x="1">x</p>'           => array( 'aafm_wpbakery_data_attributes_need_unfiltered_html', 400 ),
			'<p>%5Bvc_raw_js%5D</p>'        => array( 'aafm_wpbakery_encoded_brackets_need_unfiltered_html', 400 ),
			'<p>&#91;vc_raw_js&#93;</p>'    => array( 'aafm_wpbakery_brackets_need_unfiltered_html', 400 ),
			'<p>x</p><img src="a" onerror=' => array( 'aafm_wpbakery_incomplete_html_needs_unfiltered_html', 400 ),
		);
		foreach ( $cases as $text => list( $code, $status ) ) {
			$template = '[vc_row][vc_column][vc_column_text]' . $text . '[/vc_column_text][/vc_column][/vc_row]';
			$this->one_template( $template );
			$id = $this->page( self::SIMPLE );
			$this->act_as_restricted_editor();
			$this->assert_refused( $this->insert( $id, 't_1' ), $code, $status, $id, self::SIMPLE );
			$this->acting_as( 'administrator' );
			$this->assertIsArray( $this->insert( $id, 't_1' ), $text );
			$this->assertSame( self::SIMPLE . $template, get_post_field( 'post_content', $id, 'raw' ), $text );
		}
	}

	public function test_an_unknown_template_id_is_404(): void {
		$this->one_template( self::TEMPLATE );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->insert( $id, 'nope' ), 'aafm_builder_unknown_template', 404, $id, self::SIMPLE );
	}

	public function test_a_template_that_does_not_parse_is_refused(): void {
		$names = '';
		for ( $i = 0; $i < 301; $i++ ) {
			$names .= '[x' . $i . ']';
		}
		foreach ( array( 'just text, no element', '[vc_row]' . $names . '[/vc_row]' ) as $text ) {
			$this->one_template( $text );
			$id = $this->page( self::SIMPLE );
			$this->acting_as( 'administrator' );
			$this->assert_refused( $this->insert( $id, 't_1' ), 'aafm_builder_unparseable', 409, $id, self::SIMPLE );
		}
	}

	public function test_a_template_whose_top_level_cannot_sit_at_the_anchor_is_refused(): void {
		$this->one_template( self::TEMPLATE );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->insert( $id, 't_1', 2, 'after' ), 'aafm_builder_invalid_nesting', 400, $id, self::SIMPLE );
	}

	/**
	 * Ruling R-S11-5: a template keeps the shared insert limit.
	 */
	public function test_a_template_over_the_insert_limit_is_refused(): void {
		$this->one_template( '[vc_row][vc_column]' . str_repeat( '[vc_column_text]<p>x</p>[/vc_column_text]', 49 ) . '[/vc_column][/vc_row]' );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->insert( $id, 't_1' ), 'aafm_builder_insert_limit', 400, $id, self::SIMPLE );
	}

	public function test_insert_template_asks_for_one_css_rebuild(): void {
		$this->one_template( self::TEMPLATE );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		WpbakeryMapStub::$calls = array();
		$this->assertIsArray( $this->insert( $id, 't_1' ) );
		$this->assertSame( array( 'rebuild:default', 'rebuild:custom' ), array_values( array_filter( WpbakeryMapStub::$calls, static fn( string $c ): bool => 0 === strpos( $c, 'rebuild:' ) ) ) );
	}

	public function test_a_one_revision_limit_is_reported_with_reversible_reason(): void {
		$this->one_template( self::TEMPLATE );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$one = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $one );
		$out = $this->insert( $id, 't_1' );
		remove_filter( 'wp_revisions_to_keep', $one );
		$this->assertIsArray( $out );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertFalse( $out['reversible'] );
		$this->assertStringContainsString( 'no longer there after the save', $out['reversible_reason'] );
	}

	public function test_insert_template_is_refused_on_a_post_wpbakery_does_not_own(): void {
		$this->one_template( self::TEMPLATE );
		$content = '<!-- wp:paragraph --><p>Block page</p><!-- /wp:paragraph -->';
		$id      = $this->page( $content );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		$this->acting_as( 'administrator' );
		$out = aafm_exec_wpbakery_insert_template(
			array(
				'post_id'          => $id,
				'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
				'template_id'      => 't_1',
			)
		);
		$this->assert_refused( $out, 'aafm_not_wpbakery_owned', 409, $id, $content );
	}

	public function test_the_schema_has_two_sources_and_two_modes(): void {
		$args   = aafm_args_wpbakery_insert_template();
		$schema = $args['input_schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'copy', 'reference' ), $schema['properties']['mode']['enum'] );
		$this->assertSame( array( 'saved', 'templatera' ), $schema['properties']['source']['enum'] );
		$this->assertSame( array( 'post_id', 'page_fingerprint', 'template_id' ), $schema['required'] );
		$this->assertSame( array( 'before', 'after', 'inside_start', 'inside_end' ), $schema['properties']['placement']['enum'] );
		// BR-4: the schema lists only what the tool reads; the place is the anchor_ trio.
		foreach ( array( $schema, aafm_args_wpbakery_insert_element()['input_schema'] ) as $insert ) {
			$this->assertArrayNotHasKey( 'index', $insert['properties'] );
			$this->assertArrayNotHasKey( 'expect', $insert['properties'] );
			$this->assertArrayHasKey( 'anchor_index', $insert['properties'] );
		}
		$this->assertSame( 'aafm-writes', $args['category'] );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
			),
			$args['meta']['annotations']
		);
		$this->assertSame( 'string', $args['output_schema']['properties']['reversible_reason']['type'] ?? null );
		$this->assertNotContains( 'reversible_reason', (array) ( $args['output_schema']['required'] ?? array() ) );
	}

	public function test_insert_template_permissions_discovery_undo_audit_and_description(): void {
		$name = 'aafm/wpbakery-insert-template';
		$id   = $this->page( self::SIMPLE, self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'aafm_perm_avada_post_object', aafm_args_wpbakery_insert_template()['permission_callback'] );
		$this->acting_as( 'contributor' );
		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$check = aafm_ability_list_permission( $name );
		$this->assertIsCallable( $check );
		$this->acting_as( 'editor' );
		$this->assertTrue( $check() );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( $check() );
		$this->assertSame( 'not_covered', aafm_undo_refusals()[ $name ] );
		$this->assertStringContainsString( 'Ran a template insert on WPBakery page #42', (string) aafm_build_activity_detail( $name, array( 'post_id' => 42 ) ) );

		$row = aafm_get_abilities_registry_full()[ $name ];
		$this->assertSame( 'writes', $row['group'] );
		$this->assertSame( 'write', $row['risk'] );
		$this->assertSame( 'wpbakery', $row['subject'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'edit access', 'page_fingerprint', 'restore', 'Role Manager', 'unfiltered HTML', '50 elements' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text, $phrase );
			}
		}
		$this->assertStringContainsString( 'Only while WPBakery Page Builder is active', $row['description'] );
	}

	/**
	 * Make the wpbakery_user_access seam answer WPBakery's own unfiltered_html part with $on.
	 *
	 * @param bool $on Access.
	 * @return callable The filter, to remove.
	 */
	private function wpbakery_access( bool $on ): callable {
		$filter = function ( $resolved, string $entry ) use ( $on ) {
			if ( 'wpbakery_user_access' !== $entry ) {
				return $resolved;
			}
			return function () use ( $on ) {
				return new class( $on ) {
					/**
					 * Access.
					 *
					 * @var bool
					 */
					private $on;

					/**
					 * Seam stub of WPBakery's access chain.
					 *
					 * @param bool $on Access.
					 */
					public function __construct( bool $on ) {
						$this->on = $on;
					}

					/**
					 * Part.
					 *
					 * @param string $part Part.
					 * @return self
					 */
					public function part( string $part ): self {
						unset( $part );
						return $this;
					}

					/**
					 * State check.
					 *
					 * @param mixed ...$states States.
					 * @return self
					 */
					public function checkStateAny( ...$states ): self { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WPBakery's own method name.
						unset( $states );
						return $this;
					}

					/**
					 * Result.
					 *
					 * @return bool
					 */
					public function get(): bool {
						return $this->on;
					}
				};
			};
		};
		add_filter( 'aafm_builder_vendor', $filter, 20, 2 );
		return $filter;
	}

	/**
	 * Run the page code update as the current user.
	 *
	 * @param int                 $id     Post.
	 * @param array<string,mixed> $fields Fields.
	 * @return array<string,mixed>|WP_Error
	 */
	private function page_code( int $id, array $fields ) {
		return aafm_exec_wpbakery_update_page_code( array( 'post_id' => $id ) + $fields );
	}

	public function test_each_field_is_set_and_read_back_from_the_meta(): void {
		$this->wpbakery_access( true );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'administrator' );
		$out = $this->page_code(
			$id,
			array(
				'custom_css'       => '.a{color:red}',
				'custom_js_header' => 'var a = 1;',
				'custom_js_footer' => 'console.info("b");',
			)
		);
		$this->assertSame(
			array(
				'post_id'  => $id,
				'changed'  => array( 'custom_css', 'custom_js_header', 'custom_js_footer' ),
				'previous' => array(
					'custom_css'       => '',
					'custom_js_header' => '',
					'custom_js_footer' => '',
				),
			),
			$out
		);
		$this->assertSame( '.a{color:red}', get_post_meta( $id, '_wpb_post_custom_css', true ) );
		$this->assertSame( 'var a = 1;', get_post_meta( $id, '_wpb_post_custom_js_header', true ) );
		$this->assertSame( 'console.info("b");', get_post_meta( $id, '_wpb_post_custom_js_footer', true ) );
	}

	public function test_an_empty_string_deletes_the_meta(): void {
		$this->wpbakery_access( true );
		$id = $this->page( self::SIMPLE );
		update_post_meta( $id, '_wpb_post_custom_css', '.a{}' );
		update_post_meta( $id, '_wpb_post_custom_js_footer', 'x();' );
		$this->acting_as( 'administrator' );
		$out = $this->page_code(
			$id,
			array(
				'custom_css'       => '',
				'custom_js_footer' => '',
			)
		);
		$this->assertSame( array( 'custom_css', 'custom_js_footer' ), $out['changed'] );
		$this->assertFalse( metadata_exists( 'post', $id, '_wpb_post_custom_css' ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_wpb_post_custom_js_footer' ) );
		$this->assertSame(
			array(
				'custom_css'       => '.a{}',
				'custom_js_footer' => 'x();',
			),
			$out['previous']
		);
	}

	/**
	 * CR-3: page CSS holding markup is refused; any other CSS is stored byte for byte.
	 */
	public function test_css_holding_markup_is_refused_and_other_css_is_stored_as_sent(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'administrator' );
		$this->assert_error( $this->page_code( $id, array( 'custom_css' => '.a{color:red}</style><script>alert(1)</script>' ) ), 'aafm_builder_css_markup', 400 );
		$this->assertFalse( metadata_exists( 'post', $id, '_wpb_post_custom_css' ) );
		// BR-6: a < that WPBakery's print cuts at is refused by name; a < before whitespace and %XX print as stored.
		$this->assert_error( $this->page_code( $id, array( 'custom_css' => '@media (width <= 600px) { .a{} }' ) ), 'aafm_builder_css_vendor_alters', 400 );
		$this->assertFalse( metadata_exists( 'post', $id, '_wpb_post_custom_css' ) );
		$css = '@media (max-width: 600px) { .a > .b { content: "a < b"; background: url("data:image/svg+xml,%3Csvg%3E") } }  ';
		$this->assertIsArray( $this->page_code( $id, array( 'custom_css' => $css ) ) );
		$this->assertSame( $css, get_post_meta( $id, '_wpb_post_custom_css', true ) );
	}

	public function test_js_containing_a_closing_script_tag_is_refused(): void {
		$this->wpbakery_access( true );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'administrator' );
		foreach ( array( 'custom_js_header', 'custom_js_footer' ) as $field ) {
			foreach ( array( 'a();</script><b>', 'a();</ScRiPt >' ) as $js ) {
				$this->assert_error( $this->page_code( $id, array( $field => $js ) ), 'aafm_builder_invalid_value', 400 );
			}
			$this->assertFalse( metadata_exists( 'post', $id, '_wpb_post_' . $field ) );
		}
	}

	public function test_the_permission_needs_edit_access_and_unfiltered_html(): void {
		$author = self::factory()->user->create( array( 'role' => 'editor' ) );
		$id     = $this->page( self::SIMPLE, $author );
		$this->assertSame( 'aafm_perm_wpbakery_page_code', aafm_args_wpbakery_update_page_code()['permission_callback'] );
		$this->act_as_restricted_editor();
		$this->assertFalse( aafm_perm_wpbakery_page_code( array( 'post_id' => $id ) ) );
		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_perm_wpbakery_page_code( array( 'post_id' => $id ) ) );
		$this->acting_as( 'contributor' );
		$this->assertFalse( aafm_perm_wpbakery_page_code( array( 'post_id' => $id ) ) );
	}

	public function test_js_fields_are_refused_when_wpbakerys_own_access_is_off(): void {
		$this->wpbakery_access( false );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'administrator' );
		foreach ( array( 'custom_js_header', 'custom_js_footer' ) as $field ) {
			$this->assert_error( $this->page_code( $id, array( $field => 'a();' ) ), 'aafm_wpbakery_custom_js_off', 403 );
			$this->assertFalse( metadata_exists( 'post', $id, '_wpb_post_' . $field ) );
		}
		$this->assert_error(
			$this->page_code(
				$id,
				array(
					'custom_css'       => '.a{}',
					'custom_js_footer' => 'a();',
				)
			),
			'aafm_wpbakery_custom_js_off',
			403
		);
		$this->assertFalse( metadata_exists( 'post', $id, '_wpb_post_custom_css' ), 'Nothing is written when one field is refused.' );
		$this->assertIsArray( $this->page_code( $id, array( 'custom_css' => '.a{}' ) ), 'CSS alone does not ask WPBakery.' );
	}

	/**
	 * Fix pass 2, ruling 1: page code previous goes back as returned and restores the stored metas.
	 */
	public function test_page_code_previous_goes_back_byte_for_byte(): void {
		$this->wpbakery_access( true );
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'administrator' );
		update_post_meta( $id, '_wpb_post_custom_css', '.old{}' );
		$out  = $this->page_code(
			$id,
			array(
				'custom_css'       => '.new{}',
				'custom_js_footer' => 'x();',
			)
		);
		$back = $this->page_code( $id, (array) json_decode( (string) wp_json_encode( $out['previous'] ), true ) );
		$this->assertIsArray( $back, is_wp_error( $back ) ? $back->get_error_message() : '' );
		$this->assertSame( '.old{}', get_post_meta( $id, '_wpb_post_custom_css', true ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_wpb_post_custom_js_footer' ) );
	}

	/**
	 * Fix pass 2, ruling 1: settings previous goes back as returned: a checkbox in the stored '1' or '' form, and null
	 * for a setting that was not stored, which removes it again.
	 */
	public function test_settings_previous_goes_back_byte_for_byte(): void {
		$this->clear_settings();
		$dir = get_temp_dir() . 'aafm-wpb-css-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		$filter = $this->vendor_settings( $dir );
		// WPBakery rebuilds custom.css when the option is added or updated, and never when it is deleted.
		$build = static function () use ( $dir ): void {
			file_put_contents( $dir . '/custom.css', wp_strip_all_tags( (string) get_option( 'wpb_js_custom_css' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test stand-in for the vendor rebuild.
		};
		add_action( 'add_option_wpb_js_custom_css', $build );
		add_action( 'update_option_wpb_js_custom_css', $build );
		update_option( 'wpb_js_shortcuts', '' );
		update_option( 'wpb_js_css_loading', 'legacy' );
		$this->acting_as( 'administrator' );
		$out = aafm_exec_wpbakery_update_settings(
			array(
				'set' => array(
					'shortcuts'          => true,
					'css_loading'        => 'hybrid',
					'not_responsive_css' => true,
					'custom_css'         => '.vc_btn3{letter-spacing:2px}',
				),
			)
		);
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertNull( $out['previous']['custom_css'], 'No global CSS was stored before.' );
		$this->assertSame( '.vc_btn3{letter-spacing:2px}', file_get_contents( $dir . '/custom.css' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test file.
		$back = aafm_exec_wpbakery_update_settings( array( 'set' => json_decode( (string) wp_json_encode( $out['previous'] ), true ) ) );
		remove_action( 'add_option_wpb_js_custom_css', $build );
		remove_action( 'update_option_wpb_js_custom_css', $build );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$file = (string) file_get_contents( $dir . '/custom.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test file.
		wp_delete_file( $dir . '/custom.css' );
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
		$this->assertIsArray( $back, is_wp_error( $back ) ? $back->get_error_message() : '' );
		$this->assertSame( '', get_option( 'wpb_js_shortcuts' ) );
		$this->assertSame( 'legacy', get_option( 'wpb_js_css_loading' ) );
		$this->assertFalse( get_option( 'wpb_js_not_responsive_css' ) );
		$this->assertFalse( get_option( 'wpb_js_custom_css' ), 'The option row is gone again.' );
		$this->assertSame( '', $file, 'custom.css no longer carries the CSS.' );
		$this->clear_settings();
	}

	public function test_previous_holds_the_old_values(): void {
		$id = $this->page( self::SIMPLE );
		update_post_meta( $id, '_wpb_post_custom_css', '.old{}' );
		$this->acting_as( 'administrator' );
		$out = $this->page_code( $id, array( 'custom_css' => '.new{}' ) );
		$this->assertSame( array( 'custom_css' => '.old{}' ), $out['previous'] );
		$this->assertSame( '.new{}', get_post_meta( $id, '_wpb_post_custom_css', true ) );
	}

	/**
	 * Review Focus 3: writing what is stored changes nothing and writes no meta.
	 */
	public function test_writing_the_stored_values_changes_nothing(): void {
		$this->wpbakery_access( true );
		$id = $this->page( self::SIMPLE );
		update_post_meta( $id, '_wpb_post_custom_css', '.a{}' );
		update_post_meta( $id, '_wpb_post_custom_js_header', 'h();' );
		$this->acting_as( 'administrator' );
		$writes = 0;
		$count  = static function ( $check ) use ( &$writes ) {
			++$writes;
			return $check;
		};
		add_filter( 'update_post_metadata', $count );
		add_filter( 'delete_post_metadata', $count );
		$out = $this->page_code(
			$id,
			array(
				'custom_css'       => '.a{}',
				'custom_js_header' => 'h();',
				'custom_js_footer' => '',
			)
		);
		remove_filter( 'update_post_metadata', $count );
		remove_filter( 'delete_post_metadata', $count );
		$this->assertSame( $id, $out['post_id'] );
		$this->assertSame( array(), $out['changed'] );
		$this->assertEquals( new \stdClass(), $out['previous'] );
		$this->assertSame( '{}', wp_json_encode( $out['previous'] ) );
		$this->assertSame( 0, $writes );
	}

	public function test_page_code_is_refused_on_a_page_wpbakery_does_not_own(): void {
		$content = '<!-- wp:paragraph --><p>Block page</p><!-- /wp:paragraph -->';
		$id      = $this->page( $content );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		$this->acting_as( 'administrator' );
		$this->assert_error( $this->page_code( $id, array( 'custom_css' => '.a{}' ) ), 'aafm_not_wpbakery_owned', 409 );
		$this->assertFalse( metadata_exists( 'post', $id, '_wpb_post_custom_css' ) );
	}

	public function test_at_least_one_field_is_needed(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'administrator' );
		$this->assert_error( $this->page_code( $id, array() ), 'aafm_wpbakery_page_code_empty', 400 );
	}

	public function test_a_failed_meta_write_is_an_error(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'administrator' );
		$refuse = static fn( $check, $object_id, $key ) => '_wpb_post_custom_css' === $key ? false : $check;
		add_filter( 'update_post_metadata', $refuse, 10, 3 );
		add_filter( 'add_post_metadata', $refuse, 10, 3 );
		$out = $this->page_code( $id, array( 'custom_css' => '.a{}' ) );
		remove_filter( 'update_post_metadata', $refuse, 10 );
		remove_filter( 'add_post_metadata', $refuse, 10 );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertFalse( metadata_exists( 'post', $id, '_wpb_post_custom_css' ) );
	}

	public function test_discovery_hides_the_tool_from_a_user_without_unfiltered_html(): void {
		$check = aafm_ability_list_permission( 'aafm/wpbakery-update-page-code' );
		$this->assertIsCallable( $check );
		$this->acting_as( 'administrator' );
		$this->assertTrue( $check() );
		$this->act_as_restricted_editor();
		$this->assertFalse( $check() );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( $check() );
	}

	public function test_page_code_schema_undo_audit_and_description(): void {
		$name   = 'aafm/wpbakery-update-page-code';
		$args   = aafm_args_wpbakery_update_page_code();
		$schema = $args['input_schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'post_id' ), $schema['required'] );
		$this->assertSame( array( 'post_id', 'custom_css', 'custom_js_header', 'custom_js_footer' ), array_keys( $schema['properties'] ) );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			$args['meta']['annotations']
		);
		$this->assertSame( 'not_covered', aafm_undo_refusals()[ $name ] );
		$this->assertStringContainsString( 'Ran a page code update on WPBakery page #42', (string) aafm_build_activity_detail( $name, array( 'post_id' => 42 ) ) );
		$row = aafm_get_abilities_registry_full()[ $name ];
		$this->assertSame( 'writes', $row['group'] );
		$this->assertSame( 'write', $row['risk'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'replace', 'previous', 'unfiltered HTML', 'edit access' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text, $phrase );
			}
		}
		$this->assertStringContainsString( 'Only while WPBakery Page Builder is active', $row['description'] );
	}

	public function test_a_template_can_start_an_empty_page_where_wpbakerys_editor_is_on(): void {
		$this->one_template( self::TEMPLATE );
		$id = $this->page( '' );
		$this->acting_as( 'editor' );
		$input = array(
			'post_id'          => $id,
			'page_fingerprint' => aafm_builder_page_fingerprint( '' ),
			'template_id'      => 't_1',
		);
		$types = static fn( $resolved, string $entry ) => 'wpbakery_editor_post_types' === $entry ? static fn(): array => array( 'page' ) : $resolved;
		$this->assert_refused( aafm_exec_wpbakery_insert_template( $input ), 'aafm_wpbakery_editor_off', 409, $id, '' );
		add_filter( 'aafm_builder_vendor', $types, 20, 2 );
		$out = aafm_exec_wpbakery_insert_template( $input );
		remove_filter( 'aafm_builder_vendor', $types, 20 );
		$this->assertIsArray( $out );
		$this->assertSame( self::TEMPLATE, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertStringContainsString( 'empty post', aafm_get_abilities_registry_full()['aafm/wpbakery-insert-template']['description'] );
	}

	/**
	 * Delete every listed WPBakery setting row and the planted licence rows.
	 *
	 * @return void
	 */
	private function clear_settings(): void {
		foreach ( array_merge( aafm_wpbakery_settings_keys(), array( 'js_composer_purchase_code', 'license_key_token' ) ) as $key ) {
			delete_option( 'wpb_js_' . $key );
		}
	}

	public function test_the_settings_allowlist_is_pinned(): void {
		$this->assertSame(
			array( 'not_responsive_css', 'google_fonts_subsets', 'local_google_fonts', 'shortcuts', 'css_loading', 'js_loading', 'gutenberg_disable', 'auto_save', 'use_custom', 'custom_css', 'custom_js_header', 'custom_js_footer' ),
			aafm_wpbakery_settings_keys()
		);
	}

	public function test_the_licence_code_and_its_token_are_not_in_the_list(): void {
		foreach ( array( 'js_composer_purchase_code', 'license_key_token' ) as $secret ) {
			$this->assertNotContains( $secret, aafm_wpbakery_settings_keys() );
		}
	}

	public function test_get_returns_only_listed_options_and_lists_the_rest_as_not_set(): void {
		$this->clear_settings();
		update_option( 'wpb_js_js_composer_purchase_code', 'MARKER-PURCHASE-CODE-7731' );
		update_option( 'wpb_js_license_key_token', 'MARKER-TOKEN-7731' );
		update_option( 'wpb_js_css_loading', 'optimized' );
		update_option( 'wpb_js_not_responsive_css', '' );
		$this->acting_as( 'administrator' );
		$out = aafm_exec_wpbakery_get_settings( array() );
		$this->assertSame(
			array(
				'not_responsive_css' => '',
				'css_loading'        => 'optimized',
			),
			$out['settings']
		);
		$this->assertSame( array( 'google_fonts_subsets', 'local_google_fonts', 'shortcuts', 'js_loading', 'gutenberg_disable', 'auto_save', 'use_custom', 'custom_css', 'custom_js_header', 'custom_js_footer' ), $out['not_set'] );
		$this->assertSame( array( 'settings', 'not_set', 'editor_post_types', 'version' ), array_keys( $out ) );
		$encoded = (string) wp_json_encode( $out );
		$this->assertStringNotContainsString( 'MARKER', $encoded );
		$this->assertStringNotContainsString( 'purchase', $encoded );
		$this->clear_settings();
	}

	public function test_global_js_is_returned_only_with_unfiltered_html(): void {
		$this->clear_settings();
		update_option( 'wpb_js_custom_js_header', 'console.info(1);' );
		$this->acting_as( 'administrator' );
		$this->assertSame( 'console.info(1);', aafm_exec_wpbakery_get_settings( array() )['settings']['custom_js_header'] );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new \WP_User( $admin ) )->add_cap( 'unfiltered_html', false );
		wp_set_current_user( $admin );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$out = aafm_exec_wpbakery_get_settings( array() );
		$this->assertInstanceOf( \stdClass::class, $out['settings'] );
		$this->assertNotContains( 'custom_js_header', $out['not_set'] );
		$this->assertNotContains( 'custom_js_footer', $out['not_set'] );
		$this->assertStringNotContainsString( 'console.info', (string) wp_json_encode( $out ) );
		$this->clear_settings();
	}

	public function test_get_settings_lists_wpbakerys_editor_post_types(): void {
		$types = static fn( $resolved, string $entry ) => 'wpbakery_editor_post_types' === $entry ? static fn(): array => array( 'page', 'post' ) : $resolved;
		add_filter( 'aafm_builder_vendor', $types, 20, 2 );
		$this->acting_as( 'administrator' );
		$this->assertSame( array( 'page', 'post' ), aafm_exec_wpbakery_get_settings( array() )['editor_post_types'] );
		remove_filter( 'aafm_builder_vendor', $types, 20 );
	}

	public function test_get_settings_schema_permission_and_description(): void {
		$name = 'aafm/wpbakery-get-settings';
		$args = aafm_args_wpbakery_get_settings();
		$this->assertSame( 'aafm_perm_manage_options', $args['permission_callback'] );
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array(), $args['input_schema']['properties'] );
		$this->assertTrue( $args['meta']['annotations']['readonly'] );
		// No server.php case: discovery falls through to aafm_perm_manage_options() itself.
		$this->assertNull( aafm_ability_list_permission( $name ) );
		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_perm_manage_options() );
		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_perm_manage_options() );
		$row = aafm_get_abilities_registry_full()[ $name ];
		$this->assertSame( 'reads', $row['group'] );
		$this->assertSame( 'read', $row['risk'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'license code', 'unfiltered HTML', 'manage site options' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text, $phrase );
			}
		}
		$this->assertStringContainsString( 'Only while WPBakery Page Builder is active', $row['description'] );
	}

	/**
	 * Calls the stub vendor callbacks recorded, method => list of values.
	 *
	 * @var array<string,list<mixed>>
	 */
	public static array $calls = array();

	/**
	 * Supply WPBakery's settings object and module manager through the seam: stubs whose callbacks behave like
	 * WPBakery's own (class-vc-settings.php:848-957, the custom CSS and JS module settings) and record each call.
	 * $missing names a method the settings stub lacks, by returning a stub without it.
	 *
	 * @param string|null $css_dir  Folder the custom.css stub reads, or null for none.
	 * @param bool        $missing  Whether the settings stub lacks sanitize_css_loading_callback.
	 * @return callable The filter, to remove.
	 */
	private function vendor_settings( ?string $css_dir = null, bool $missing = false ): callable {
		self::$calls = array();
		$general     = $missing ? new class() {
		} : new class() {
			/**
			 * Folder custom.css is read from.
			 *
			 * @var string
			 */
			public static string $dir = '';

			/**
			 * Record and answer.
			 *
			 * @param string       $name Method.
			 * @param array<mixed> $args Args.
			 * @return mixed
			 */
			public function __call( $name, $args ) {
				WpbakerySiteTest::$calls[ $name ][] = $args[0];
				$value                              = $args[0];
				switch ( $name ) {
					case 'sanitize_google_fonts_subsets_callback':
						return array_values( array_intersect( (array) $value, array( 'latin', 'cyrillic', 'greek' ) ) );
					case 'sanitize_css_loading_callback':
					case 'sanitize_js_loading_callback':
						return in_array( $value, array( 'hybrid', 'optimized', 'legacy' ), true ) ? $value : 'hybrid';
				}
				return (bool) $value;
			}

			/**
			 * The four general methods __call answers for, so method_exists() sees them.
			 *
			 * @param mixed $v Value.
			 * @return mixed
			 */
			public function sanitize_not_responsive_css_callback( $v ) {
				return $this->__call( __FUNCTION__, array( $v ) );
			}

			/**
			 * See above.
			 *
			 * @param mixed $v Value.
			 * @return mixed
			 */
			public function sanitize_local_google_fonts_callback( $v ) {
				return $this->__call( __FUNCTION__, array( $v ) );
			}

			/**
			 * See above.
			 *
			 * @param mixed $v Value.
			 * @return mixed
			 */
			public function sanitize_shortcuts_callback( $v ) {
				return $this->__call( __FUNCTION__, array( $v ) );
			}

			/**
			 * See above.
			 *
			 * @param mixed $v Value.
			 * @return mixed
			 */
			public function sanitize_google_fonts_subsets_callback( $v ) {
				return $this->__call( __FUNCTION__, array( $v ) );
			}

			/**
			 * See above.
			 *
			 * @param mixed $v Value.
			 * @return mixed
			 */
			public function sanitize_css_loading_callback( $v ) {
				return $this->__call( __FUNCTION__, array( $v ) );
			}

			/**
			 * See above.
			 *
			 * @param mixed $v Value.
			 * @return mixed
			 */
			public function sanitize_js_loading_callback( $v ) {
				return $this->__call( __FUNCTION__, array( $v ) );
			}

			/**
			 * A filesystem whose get_contents() reads the real file.
			 *
			 * @return object
			 */
			public static function getFileSystem(): object { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WPBakery's own method name.
				return new class() {
					/**
					 * Read.
					 *
					 * @param string $file File.
					 * @return string|false
					 */
					public function get_contents( string $file ) {
						return is_readable( $file ) ? (string) file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test stub.
					}
				};
			}

			/**
			 * The folder.
			 *
			 * @return string
			 */
			public static function uploadDir(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WPBakery's own method name.
				return self::$dir;
			}
		};
		if ( ! $missing ) {
			$general::$dir = (string) $css_dir;
		}
		$module  = new class() {
			/**
			 * The module's settings object.
			 *
			 * @var object
			 */
			public $settings;

			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->settings = new class() {
					/**
					 * Record and strip tags, as WPBakery's does.
					 *
					 * @param string $css CSS.
					 * @return string
					 */
					public function sanitize_custom_css_callback( $css ) {
						WpbakerySiteTest::$calls[ __FUNCTION__ ][] = $css;
						return wp_strip_all_tags( $css );
					}

					/**
					 * Record and pass through.
					 *
					 * @param string $js JS.
					 * @return string
					 */
					public function sanitize_custom_js_header_callback( $js ) {
						WpbakerySiteTest::$calls[ __FUNCTION__ ][] = $js;
						return $js;
					}

					/**
					 * Record and pass through.
					 *
					 * @param string $js JS.
					 * @return string
					 */
					public function sanitize_custom_js_footer_callback( $js ) {
						WpbakerySiteTest::$calls[ __FUNCTION__ ][] = $js;
						return $js;
					}
				};
			}
		};
		$manager = new class( $module ) {
			/**
			 * Module.
			 *
			 * @var object
			 */
			private $module;

			/**
			 * Constructor.
			 *
			 * @param object $module Module.
			 */
			public function __construct( $module ) {
				$this->module = $module;
			}

			/**
			 * On.
			 *
			 * @param string $slug Slug.
			 * @return bool
			 */
			public function is_module_on( $slug ): bool {
				return in_array( $slug, array( 'vc-custom-css', 'vc-custom-js' ), true );
			}

			/**
			 * Module.
			 *
			 * @param string $slug Slug.
			 * @return object
			 */
			public function get_module( $slug ) {
				unset( $slug );
				return $this->module;
			}
		};
		$filter  = static function ( $resolved, string $entry ) use ( $general, $manager ) {
			if ( 'wpbakery_settings' === $entry ) {
				return static fn() => $general;
			}
			if ( 'wpbakery_modules' === $entry ) {
				return static fn() => $manager;
			}
			return $resolved;
		};
		add_filter( 'aafm_builder_vendor', $filter, 20, 2 );
		return $filter;
	}

	public function test_the_writable_list_is_the_read_list_minus_the_unresolved_and_use_custom(): void {
		$this->assertSame( array_values( array_diff( aafm_wpbakery_settings_keys(), array( 'gutenberg_disable', 'auto_save', 'use_custom' ) ) ), aafm_wpbakery_settings_writable() );
	}

	public function test_each_writable_field_goes_through_its_vendor_callback(): void {
		$this->clear_settings();
		$filter = $this->vendor_settings();
		$this->acting_as( 'administrator' );
		$out = aafm_exec_wpbakery_update_settings(
			array(
				'set' => array(
					'not_responsive_css'   => true,
					'google_fonts_subsets' => array( 'latin', 'greek' ),
					'local_google_fonts'   => false,
					'shortcuts'            => true,
					'css_loading'          => 'optimized',
					'js_loading'           => 'legacy',
					'custom_js_header'     => 'console.info(1);',
					'custom_js_footer'     => 'console.info(2);',
				),
			)
		);
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertIsArray( $out );
		$this->assertSame( array( 'not_responsive_css', 'google_fonts_subsets', 'local_google_fonts', 'shortcuts', 'css_loading', 'js_loading', 'custom_js_header', 'custom_js_footer' ), $out['changed'] );
		foreach ( array( 'not_responsive_css', 'google_fonts_subsets', 'local_google_fonts', 'shortcuts', 'css_loading', 'js_loading', 'custom_js_header', 'custom_js_footer' ) as $key ) {
			$this->assertCount( 1, self::$calls[ 'sanitize_' . $key . '_callback' ] ?? array(), $key );
		}
		$this->assertSame( '1', get_option( 'wpb_js_not_responsive_css' ) );
		$this->assertSame( '', get_option( 'wpb_js_local_google_fonts' ) );
		$this->assertSame( array( 'latin', 'greek' ), get_option( 'wpb_js_google_fonts_subsets' ) );
		$this->assertSame( 'optimized', get_option( 'wpb_js_css_loading' ) );
		$this->assertSame( 'console.info(2);', get_option( 'wpb_js_custom_js_footer' ) );
		$this->clear_settings();
	}

	public function test_a_value_the_vendor_callback_would_change_is_refused(): void {
		$this->clear_settings();
		$filter = $this->vendor_settings();
		$this->acting_as( 'administrator' );
		$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => array( 'css_loading' => 'fast' ) ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => array( 'google_fonts_subsets' => array( 'klingon' ) ) ) ), 'aafm_builder_invalid_value', 400 );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertFalse( get_option( 'wpb_js_css_loading' ) );
		$this->assertFalse( get_option( 'wpb_js_google_fonts_subsets' ) );
	}

	public function test_a_field_whose_callback_cannot_be_resolved_is_refused_not_guessed(): void {
		$this->clear_settings();
		$filter = $this->vendor_settings( null, true );
		$this->acting_as( 'administrator' );
		$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => array( 'css_loading' => 'legacy' ) ) ), 'aafm_builder_attribute_not_writable', 400 );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertFalse( get_option( 'wpb_js_css_loading' ) );
		$this->acting_as( 'administrator' );
		$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => array( 'shortcuts' => true ) ) ), 'aafm_builder_attribute_not_writable', 400 );
		$this->assertFalse( get_option( 'wpb_js_shortcuts' ) );
	}

	public function test_use_custom_is_readable_and_not_writable_nor_the_unresolved_two(): void {
		$filter = $this->vendor_settings();
		$this->acting_as( 'administrator' );
		foreach ( array( 'use_custom', 'gutenberg_disable', 'auto_save', 'js_composer_purchase_code', 'nope' ) as $key ) {
			$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => array( $key => true ) ) ), 'aafm_builder_attribute_not_writable', 400 );
			$this->assertFalse( get_option( 'wpb_js_' . $key ), $key );
		}
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertContains( 'use_custom', aafm_wpbakery_settings_keys() );
	}

	public function test_global_css_and_js_need_unfiltered_html(): void {
		$this->clear_settings();
		$filter = $this->vendor_settings();
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new \WP_User( $admin ) )->add_cap( 'unfiltered_html', false );
		wp_set_current_user( $admin );
		foreach ( array( 'custom_css', 'custom_js_header', 'custom_js_footer' ) as $key ) {
			$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => array( $key => 'x' ) ) ), 'aafm_builder_needs_unfiltered_html', 403 );
			$this->assertFalse( get_option( 'wpb_js_' . $key ) );
		}
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
	}

	public function test_global_js_with_a_closing_script_tag_is_refused(): void {
		$filter = $this->vendor_settings();
		$this->acting_as( 'administrator' );
		$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => array( 'custom_js_footer' => 'a</script><b>' ) ) ), 'aafm_builder_invalid_value', 400 );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertFalse( get_option( 'wpb_js_custom_js_footer' ) );
	}

	public function test_a_failed_verified_write_is_an_error_and_reports_no_change(): void {
		$this->clear_settings();
		update_option( 'wpb_js_css_loading', 'hybrid' );
		$filter = $this->vendor_settings();
		$keep   = static fn( $value, $old ) => $old;
		add_filter( 'pre_update_option_wpb_js_css_loading', $keep, 10, 2 );
		$this->acting_as( 'administrator' );
		$out = aafm_exec_wpbakery_update_settings( array( 'set' => array( 'css_loading' => 'legacy' ) ) );
		remove_filter( 'pre_update_option_wpb_js_css_loading', $keep, 10 );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assert_error( $out, 'aafm_wpbakery_setting_not_saved', 500 );
		$this->assertSame( array(), $out->get_error_data()['changed'] );
		$this->assertSame( 'hybrid', get_option( 'wpb_js_css_loading' ) );
		$this->clear_settings();
	}

	public function test_settings_previous_holds_the_old_values(): void {
		$this->clear_settings();
		update_option( 'wpb_js_css_loading', 'hybrid' );
		update_option( 'wpb_js_shortcuts', '' );
		$filter = $this->vendor_settings();
		$this->acting_as( 'administrator' );
		$out = aafm_exec_wpbakery_update_settings(
			array(
				'set' => (object) array(
					'css_loading' => 'legacy',
					'shortcuts'   => true,
					'js_loading'  => 'optimized',
				),
			)
		);
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertSame(
			array(
				'css_loading' => 'hybrid',
				'shortcuts'   => '',
				'js_loading'  => null,
			),
			$out['previous']
		);
		$this->clear_settings();
	}

	public function test_wrong_json_types_are_refused_cleanly(): void {
		$filter = $this->vendor_settings();
		$this->acting_as( 'administrator' );
		$cases = array(
			array( 'shortcuts' => 'yes' ),
			array( 'not_responsive_css' => 1 ),
			array( 'google_fonts_subsets' => 'latin' ),
			array( 'google_fonts_subsets' => array( 'latin' => 'latin' ) ),
			array( 'google_fonts_subsets' => array( 1 ) ),
			array( 'css_loading' => array( 'legacy' ) ),
			array( 'custom_js_header' => 5 ),
		);
		foreach ( $cases as $set ) {
			$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => $set ) ), 'aafm_builder_invalid_value', 400 );
		}
		$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => array() ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => 'x' ) ), 'aafm_builder_invalid_value', 400 );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertSame( array(), self::$calls );
	}

	public function test_setting_the_stored_values_writes_no_option(): void {
		$this->clear_settings();
		update_option( 'wpb_js_css_loading', 'legacy' );
		update_option( 'wpb_js_shortcuts', '1' );
		update_option( 'wpb_js_google_fonts_subsets', array( 'latin' ) );
		$filter = $this->vendor_settings();
		$writes = 0;
		$count  = static function ( $value ) use ( &$writes ) {
			++$writes;
			return $value;
		};
		foreach ( array( 'css_loading', 'shortcuts', 'google_fonts_subsets' ) as $key ) {
			add_filter( 'pre_update_option_wpb_js_' . $key, $count );
		}
		$this->acting_as( 'administrator' );
		$out = aafm_exec_wpbakery_update_settings(
			array(
				'set' => array(
					'css_loading'          => 'legacy',
					'shortcuts'            => true,
					'google_fonts_subsets' => array( 'latin' ),
				),
			)
		);
		foreach ( array( 'css_loading', 'shortcuts', 'google_fonts_subsets' ) as $key ) {
			remove_filter( 'pre_update_option_wpb_js_' . $key, $count );
		}
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertSame( 0, $writes );
		$this->assertSame( array(), $out['changed'] );
		$this->assertInstanceOf( \stdClass::class, $out['previous'] );
		$this->clear_settings();
	}

	public function test_custom_css_is_checked_against_the_rebuilt_file(): void {
		$this->clear_settings();
		$dir = get_temp_dir() . 'aafm-wpb-css-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		$filter = $this->vendor_settings( $dir );
		$build  = static function () use ( $dir ): void {
			// What WPBakery's build_custom_css() writes, with its assets folder in place of url(../.
			file_put_contents( $dir . '/custom.css', (string) preg_replace( '#url\(\.\./#', 'url(https://example.org/wp-content/plugins/js_composer/assets/', wp_strip_all_tags( (string) get_option( 'wpb_js_custom_css' ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test stand-in for the vendor rebuild.
		};
		add_action( 'add_option_wpb_js_custom_css', $build );
		add_action( 'update_option_wpb_js_custom_css', $build );
		$this->acting_as( 'administrator' );
		// CR-3: markup is refused, as WordPress refuses it in custom CSS; anything else is stored as sent.
		$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => array( 'custom_css' => '<b>.a{}</b>' ) ) ), 'aafm_builder_css_markup', 400 );
		$this->assertFalse( get_option( 'wpb_js_custom_css' ) );
		$out = aafm_exec_wpbakery_update_settings( array( 'set' => array( 'custom_css' => '.a{background:url(../img/x.png)}' ) ) );
		$this->assertSame( array( 'custom_css' ), $out['changed'] );
		$this->assertSame( '.a{background:url(../img/x.png)}', get_option( 'wpb_js_custom_css' ) );
		$this->assertCount( 1, self::$calls['sanitize_custom_css_callback'] );
		$this->assertTrue( function_exists( 'add_settings_error' ), 'wp-admin template functions are loaded for the vendor failure path.' );
		$this->assert_error( aafm_exec_wpbakery_update_settings( array( 'set' => array( 'custom_css' => '@media (width <= 600px) { .a{} }' ) ) ), 'aafm_builder_css_vendor_alters', 400 );
		$range = '@media (max-width: 600px) { .a > .b { content: "a < b" } }';
		$this->assertSame( array( 'custom_css' ), aafm_exec_wpbakery_update_settings( array( 'set' => array( 'custom_css' => $range ) ) )['changed'] );
		$this->assertSame( $range, get_option( 'wpb_js_custom_css' ) );
		update_option( 'wpb_js_custom_css', '.a{background:url(../img/x.png)}' );

		remove_action( 'add_option_wpb_js_custom_css', $build );
		remove_action( 'update_option_wpb_js_custom_css', $build );
		$stale = aafm_exec_wpbakery_update_settings( array( 'set' => array( 'custom_css' => '.b{}' ) ) );
		$this->assert_error( $stale, 'aafm_wpbakery_css_stale', 500 );
		$this->assertSame( array( 'custom_css' ), $stale->get_error_data()['changed'] );
		$this->assertSame( '.a{background:url(../img/x.png)}', $stale->get_error_data()['previous']['custom_css'] );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		wp_delete_file( $dir . '/custom.css' );
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
		$this->clear_settings();
	}

	/**
	 * R3-4 note 5: the description says WPBakery rewrites url(../ in the global CSS when it builds its file.
	 */
	public function test_update_settings_description_names_the_url_rewrite(): void {
		$text = aafm_get_abilities_registry_full()['aafm/wpbakery-update-settings']['description'];
		$this->assertStringContainsString( 'url(../', $text );
		// Fix round 1: WPBakery rewrites the url in the custom.css file only; the setting keeps the CSS as sent.
		$this->assertStringNotContainsString( 'stored rewritten', $text );
		$this->assertStringContainsString( 'the setting itself keeps the CSS as sent', $text );
	}

	public function test_update_settings_schema_permission_audit_and_description(): void {
		$name = 'aafm/wpbakery-update-settings';
		$args = aafm_args_wpbakery_update_settings();
		$this->assertSame( 'aafm_perm_manage_options', $args['permission_callback'] );
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'set' ), $args['input_schema']['required'] );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			$args['meta']['annotations']
		);
		$this->assertNull( aafm_ability_list_permission( $name ) );
		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_perm_manage_options() );
		$this->assertContains( $name, aafm_non_removal_destructive_abilities() );
		$this->assertSame( 'not_covered', aafm_undo_refusals()[ $name ] );
		$this->assertSame(
			'Ran a WPBakery settings update: css_loading, shortcuts',
			aafm_build_activity_detail(
				$name,
				array(
					'set' => array(
						'shortcuts'                 => true,
						'css_loading'               => 'x',
						'js_composer_purchase_code' => 'y',
					),
				)
			)
		);
		$row = aafm_get_abilities_registry_full()[ $name ];
		$this->assertSame( 'writes', $row['group'] );
		$this->assertSame( 'destructive', $row['risk'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'site-wide', 'previous', 'unfiltered HTML', 'manage site options', 'sanitize callback' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text, $phrase );
			}
		}
		$this->assertStringContainsString( 'Only while WPBakery Page Builder is active', $row['description'] );
	}
}
