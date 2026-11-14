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
		$this->assertSame( array( 'include_content', 'page', 'per_page' ), array_keys( $args['input_schema']['properties'] ) );
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

	public function test_the_schema_has_no_mode_and_one_source(): void {
		$args   = aafm_args_wpbakery_insert_template();
		$schema = $args['input_schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertArrayNotHasKey( 'mode', $schema['properties'] );
		$this->assertSame( array( 'saved' ), $schema['properties']['source']['enum'] );
		$this->assertSame( array( 'post_id', 'page_fingerprint', 'template_id' ), $schema['required'] );
		$this->assertSame( array( 'before', 'after', 'inside_start', 'inside_end' ), $schema['properties']['placement']['enum'] );
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
}
