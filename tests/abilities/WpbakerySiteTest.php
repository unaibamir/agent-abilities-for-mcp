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
}
