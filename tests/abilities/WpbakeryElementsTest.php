<?php
/**
 * WPBakery element tools: the element map through the vendor seam, the kinds, the encoder (the inverse of the
 * S8a decoder, checked against the bytes WPBakery 9.1's own editor stored in
 * tests/Fixtures/Wpbakery/page-elements.txt), wpbakery-list-elements and wpbakery-update-element.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\WpbakeryMapStub;
use AAFM\Tests\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/Support/WpbakeryMapStub.php';

final class WpbakeryElementsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		WpbakeryMapStub::install();
	}

	public function tear_down(): void {
		WpbakeryMapStub::remove();
		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		parent::tear_down();
	}

	/**
	 * The S8a fixture, a page WPBakery 9.1's own editor saved.
	 *
	 * @return string
	 */
	private function fixture(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Wpbakery/page-elements.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local fixture file, not a remote URL.
	}

	/**
	 * One stored attribute of the first element with a tag in the fixture.
	 *
	 * @param string $tag  Tag.
	 * @param string $name Attribute.
	 * @return string
	 */
	private function stored( string $tag, string $name ): string {
		$content = $this->fixture();
		foreach ( aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) ) as $element ) {
			if ( $tag === $element['tag'] && isset( $element['atts'][ $name ] ) ) {
				return (string) $element['atts'][ $name ];
			}
		}
		$this->fail( "No $tag $name in the fixture." );
	}

	/**
	 * A param from the stub map, with its type.
	 *
	 * @param string $tag  Tag.
	 * @param string $name Param name.
	 * @return array<string,mixed>
	 */
	private function param( string $tag, string $name ): array {
		foreach ( WpbakeryMapStub::map()[ $tag ]['params'] as $param ) {
			if ( is_array( $param ) && ( $param['param_name'] ?? null ) === $name ) {
				return $param;
			}
		}
		$this->fail( "No $tag $name in the stub map." );
	}

	/**
	 * Encode one setting the way the update tool does: kind from the map, then the encoder.
	 *
	 * @param string $tag   Tag.
	 * @param string $name  Setting.
	 * @param mixed  $value Input value.
	 * @return string|WP_Error
	 */
	private function encode( string $tag, string $name, $value ) {
		$param = $this->param( $tag, $name );
		return aafm_wpbakery_encode_value( aafm_wpbakery_param_kind( $tag, $param )['kind'], $value, $param );
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
	 * A real image attachment.
	 *
	 * @return int
	 */
	private function image(): int {
		return self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => 'aafm-s9a.jpg',
				'post_title'     => 'S9a image',
			)
		);
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

	public function test_raw_html_content_and_custom_onclick_settings_are_the_raw_kind(): void {
		$this->assertSame( 'raw', aafm_wpbakery_param_kind( 'vc_gmaps', $this->param( 'vc_gmaps', 'link' ) )['kind'], 'A textarea_safe setting on a custom HTML element is raw.' );
		$this->assertSame( 'raw', aafm_wpbakery_param_kind( 'vc_gmaps', $this->param( 'vc_gmaps', 'title' ) )['kind'] );
		$this->assertSame( 'raw', aafm_wpbakery_param_kind( 'vc_raw_html', $this->param( 'vc_raw_html', 'content' ) )['kind'] );
		$this->assertSame( 'raw', aafm_wpbakery_param_kind( 'wpb_btn', $this->param( 'wpb_btn', 'custom_onclick_code' ) )['kind'] );
		$this->assertSame( 'raw', aafm_wpbakery_param_kind( 'wpb_btn', $this->param( 'wpb_btn', 'btn_custom_onclick_code' ) )['kind'] );
		$this->assertSame( 'raw', aafm_wpbakery_param_kind( 'wpb_btn', $this->param( 'wpb_btn', 'custom_onclick' ) )['kind'] );
		$this->assertSame( 'raw', aafm_wpbakery_param_kind( 'acme_embed', $this->param( 'acme_embed', 'code' ) )['kind'], 'textarea_raw_html on any element.' );
		$this->assertSame( 'text', aafm_wpbakery_param_kind( 'acme_embed', $this->param( 'acme_embed', 'title' ) )['kind'] );
		foreach ( array( array( 'vc_gmaps', 'link' ), array( 'wpb_btn', 'custom_onclick_code' ) ) as list( $tag, $name ) ) {
			$this->assertSame( 'unfiltered_html', aafm_wpbakery_param_kind( $tag, $this->param( $tag, $name ) )['needs'] );
		}
	}

	public function test_an_addon_element_on_the_custom_html_filter_is_raw(): void {
		$add = static fn( $tags ) => array_merge( (array) $tags, array( 'acme_embed' ) );
		add_filter( 'wpb_custom_html_elements', $add );
		try {
			$this->assertSame( 'raw', aafm_wpbakery_param_kind( 'acme_embed', $this->param( 'acme_embed', 'title' ) )['kind'] );
		} finally {
			remove_filter( 'wpb_custom_html_elements', $add );
		}
	}

	public function test_an_empty_custom_html_filter_still_gates_the_baseline(): void {
		add_filter( 'wpb_custom_html_elements', '__return_empty_array' );
		try {
			foreach ( array( 'vc_raw_html', 'vc_raw_js', 'vc_gmaps' ) as $tag ) {
				$this->assertContains( $tag, aafm_wpbakery_custom_html_elements() );
			}
			$this->assertSame( 'raw', aafm_wpbakery_param_kind( 'vc_gmaps', $this->param( 'vc_gmaps', 'title' ) )['kind'] );
			$this->assertSame( 'raw', aafm_wpbakery_param_kind( 'vc_raw_html', $this->param( 'vc_raw_html', 'el_class' ) )['kind'] );
		} finally {
			remove_filter( 'wpb_custom_html_elements', '__return_empty_array' );
		}
	}

	public function test_css_editor_is_the_design_kind_and_needs_unfiltered_html(): void {
		$kind = aafm_wpbakery_param_kind( 'vc_row', $this->param( 'vc_row', 'css' ) );
		$this->assertSame(
			array(
				'kind'     => 'design',
				'writable' => true,
				'needs'    => 'unfiltered_html',
			),
			$kind
		);
	}

	public function test_unknown_types_and_empty_choice_lists_are_not_writable(): void {
		foreach ( array( 'ids', 'secret', 'query', 'order', 'empty_choice' ) as $name ) {
			$this->assertFalse( aafm_wpbakery_param_kind( 'acme_odd', $this->param( 'acme_odd', $name ) )['writable'], $name );
		}
		$this->assertFalse( aafm_wpbakery_param_kind( 'vc_column', $this->param( 'vc_column', 'offset' ) )['writable'] );
	}

	public function test_the_kind_comes_from_the_param_type_row_not_from_the_map_entry(): void {
		$param         = $this->param( 'wpb_btn', 'title' );
		$param['type'] = 'css_editor';
		$this->assertSame( 'text', aafm_wpbakery_param_kind( 'wpb_btn', $param )['kind'], 'The map entry\'s own type is not read.' );

		WpbakeryMapStub::install( null, array(), array( 'wpbakery_param_type' ) );
		$kind = aafm_wpbakery_param_kind( 'wpb_btn', $this->param( 'wpb_btn', 'title' ) );
		$this->assertFalse( $kind['writable'], 'Without the param type row the setting is not writable.' );
	}

	public function test_text_escapes_quotes_and_brackets_the_way_the_editor_stores_them(): void {
		$stored  = $this->stored( 'vc_custom_heading', 'text' );
		$decoded = aafm_wpbakery_decode_atts( 'vc_custom_heading', array( 'text' => $stored ) )['text'];
		$this->assertSame( 'Say "hello" to [S8a] heading', $decoded );

		$this->assertSame( $stored, $this->encode( 'vc_custom_heading', 'text', $decoded ) );
		$this->assertSame( $decoded, aafm_wpbakery_decode_atts( 'vc_custom_heading', array( 'text' => $this->encode( 'vc_custom_heading', 'text', $decoded ) ) )['text'] );
	}

	public function test_text_normalises_a_bare_ampersand(): void {
		$this->assertSame( 'Tom &amp; Jerry', $this->encode( 'wpb_btn', 'title', 'Tom & Jerry' ) );
		$this->assertSame( 'Tom &amp; Jerry', $this->encode( 'wpb_btn', 'title', 'Tom &amp; Jerry' ) );
	}

	public function test_text_refuses_a_line_break_and_a_pre_encoded_value(): void {
		$this->assert_error( $this->encode( 'wpb_btn', 'title', "two\nlines" ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'wpb_btn', 'title', '#E-8_PHNjcmlwdD4=' ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_link_round_trips_and_refuses_javascript_and_data_urls(): void {
		$stored  = $this->stored( 'wpb_btn', 'link' );
		$decoded = aafm_wpbakery_decode_atts( 'wpb_btn', array( 'link' => $stored ) )['link'];
		$this->assertSame(
			array(
				'url'    => 'https://example.com/docs?a=1&b=2',
				'title'  => 'Docs: start here',
				'target' => '_blank',
				'rel'    => 'nofollow',
			),
			$decoded
		);

		$this->assertSame( $stored, $this->encode( 'wpb_btn', 'link', $decoded ) );
		$this->assertSame( 'url:%2Fpricing', $this->encode( 'wpb_btn', 'link', array( 'url' => '/pricing' ) ), 'Only non-empty parts are written.' );

		foreach ( array( 'javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=', 'JaVaScRiPt:alert(1)', 'example.com' ) as $url ) {
			$this->assert_error( $this->encode( 'wpb_btn', 'link', array( 'url' => $url ) ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_link_accepts_a_site_relative_path_and_an_anchor(): void {
		foreach ( array( '/pricing', '#contact', 'mailto:hi@example.com', 'tel:+15551234', 'https://example.com/a?b=c' ) as $url ) {
			$encoded = $this->encode( 'vc_custom_heading', 'link', array( 'url' => $url ) );
			$this->assertIsString( $encoded, $url );
			$this->assertSame( $url, aafm_wpbakery_decode_atts( 'vc_custom_heading', array( 'link' => $encoded ) )['link']['url'] );
		}
	}

	public function test_link_refuses_a_target_or_rel_outside_the_fixed_lists(): void {
		$this->assert_error(
			$this->encode(
				'wpb_btn',
				'link',
				array(
					'url'    => '/a',
					'target' => '_top',
				)
			),
			'aafm_builder_invalid_value',
			400
		);
		$this->assert_error(
			$this->encode(
				'wpb_btn',
				'link',
				array(
					'url' => '/a',
					'rel' => 'nofollow onmouseover',
				)
			),
			'aafm_builder_invalid_value',
			400
		);
		$this->assert_error( $this->encode( 'wpb_btn', 'link', array( 'href' => '/a' ) ), 'aafm_builder_invalid_value', 400 );
		$this->assertSame(
			'url:%2Fa|rel:nofollow%20noopener',
			$this->encode(
				'wpb_btn',
				'link',
				array(
					'url' => '/a',
					'rel' => 'nofollow noopener',
				)
			)
		);
	}

	public function test_safe_text_uses_the_e8_form_only_with_a_quote_or_http(): void {
		$stored  = $this->stored( 'vc_gallery', 'custom_links' );
		$decoded = aafm_wpbakery_decode_atts( 'vc_gallery', array( 'custom_links' => $stored ) )['custom_links'];
		$this->assertSame( 'https://example.com/one,https://example.com/two', $decoded );

		$this->assertSame( $stored, $this->encode( 'vc_gallery', 'custom_links', $decoded ) );
		$this->assertSame( $stored, $this->encode( 'vc_gallery', 'custom_links', array( 'https://example.com/one', 'https://example.com/two' ) ) );
		$this->assertSame( $stored, $this->encode( 'vc_gallery', 'custom_links', "https://example.com/one\nhttps://example.com/two" ) );
		$this->assertSame( '/one,/two', $this->encode( 'vc_gallery', 'custom_links', array( '/one', '/two' ) ), 'No quote and no http: stored as typed.' );
		$this->assert_error( $this->encode( 'vc_gallery', 'custom_links', array( 'javascript:alert(1)' ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'vc_gallery', 'custom_links', '#E-8_aHR0cA==' ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_list_kind_joins_with_commas(): void {
		$this->assertSame( 'One,Two &amp; three', $this->encode( 'vc_gallery', 'captions', array( 'One', 'Two & three' ) ) );
		$this->assert_error( $this->encode( 'vc_gallery', 'captions', 'One' ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_param_group_rows_round_trip(): void {
		$stored  = $this->stored( 'vc_progress_bar', 'values' );
		$decoded = aafm_wpbakery_decode_atts( 'vc_progress_bar', array( 'values' => $stored ) )['values'];
		$this->assertSame(
			array(
				'label' => 'Skill "A" [one]',
				'value' => '90',
			),
			$decoded[0]
		);

		$this->assertSame( $stored, $this->encode( 'vc_progress_bar', 'values', $decoded ) );
		$this->assertSame( '%5B%7B%7D%5D', $this->encode( 'vc_progress_bar', 'values', array( array() ) ), 'An empty row is {}.' );
		$this->assert_error( $this->encode( 'vc_progress_bar', 'values', array( array( 'nope' => 'x' ) ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'vc_progress_bar', 'values', array( array( 'value' => '150' ) ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'vc_progress_bar', 'values', 'rows' ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_image_must_be_an_existing_image_attachment(): void {
		$image = $this->image();
		$this->assertSame( (string) $image, $this->encode( 'vc_single_image', 'image', $image ) );
		$this->assertSame( $image . ',' . $image, $this->encode( 'vc_gallery', 'images', array( $image, $image ) ) );

		$doc  = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'application/pdf',
				'file'           => 'doc.pdf',
			)
		);
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		foreach ( array( $doc, $page, 999999 ) as $id ) {
			$this->assert_error( $this->encode( 'vc_single_image', 'image', $id ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_choice_must_be_in_the_maps_list(): void {
		$this->assertSame( 'custom_link', $this->encode( 'vc_gallery', 'onclick', $this->stored( 'vc_gallery', 'onclick' ) ) );
		$this->assertSame( '3', $this->encode( 'vc_gallery', 'interval', 3 ) );
		$this->assertSame( $this->stored( 'vc_gallery', 'interval' ), $this->encode( 'vc_gallery', 'interval', '3' ) );
		$this->assertSame( 'center', $this->encode( 'wpb_btn', 'align', 'center' ) );
		$this->assert_error( $this->encode( 'wpb_btn', 'shape', 'oval' ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'wpb_btn', 'align', 'vc-c-alignment-left' ), 'aafm_builder_invalid_value', 400 );
		$this->assertSame( 'true', $this->encode( 'wpb_btn', 'button_block', 'true' ) );
	}

	public function test_colour_accepts_hex_rgb_and_rgba_and_refuses_anything_else(): void {
		foreach ( array( '#fff', '#00aa11', '#00aa1180', 'rgb(1, 2, 3)', 'rgba(0,0,0,0.5)' ) as $colour ) {
			$this->assertSame( $colour, $this->encode( 'wpb_btn', 'custom_background', $colour ) );
		}
		foreach ( array( 'red;background:url(x)', 'red', '#ggg', 'rgb(1,2)', 'expression(1)', 3 ) as $colour ) {
			$this->assert_error( $this->encode( 'wpb_btn', 'custom_background', $colour ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_number_respects_the_maps_minimum_and_maximum(): void {
		$this->assertSame( '4', $this->encode( 'vc_separator', 'border_width', 4 ) );
		$this->assertSame( '2.5', $this->encode( 'vc_separator', 'border_width', 2.5 ) );
		foreach ( array( 0, 11, '1;x', true ) as $bad ) {
			$this->assert_error( $this->encode( 'vc_separator', 'border_width', $bad ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_token_accepts_class_tokens_only(): void {
		$this->assertSame( 'hero big-1 x_y', $this->encode( 'wpb_btn', 'el_class', 'hero big-1 x_y' ) );
		$this->assertSame( 'fa fa-adjust', $this->encode( 'wpb_btn', 'i_icon_fontawesome', 'fa fa-adjust' ) );
		foreach ( array( 'a"b', 'a]b', 'a;b', 'a<b' ) as $bad ) {
			$this->assert_error( $this->encode( 'wpb_btn', 'el_class', $bad ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_font_container_writes_the_pair_form(): void {
		$this->assertSame(
			'tag:h4|text_align:left',
			$this->encode(
				'vc_custom_heading',
				'font_container',
				array(
					'text_align' => 'left',
					'tag'        => 'h4',
				)
			)
		);
		$this->assertSame( 'tag:h2|font_size:24px|color:%23333333', $this->encode( 'vc_custom_heading', 'font_container', array( 'tag' => 'h2', 'font_size' => '24px', 'color' => '#333333' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- one short row.
		$this->assert_error( $this->encode( 'vc_custom_heading', 'font_container', array( 'tag' => 'script' ) ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_font_family_refuses_the_six_forbidden_characters(): void {
		$stored = 'font_family:Abril%20Fatface%3Aregular|font_style:400%20regular%3A400%3Anormal';
		$this->assertSame(
			$stored,
			$this->encode(
				'vc_custom_heading',
				'google_fonts',
				array(
					'font_family' => 'Abril Fatface:regular',
					'font_style'  => '400 regular:400:normal',
				)
			)
		);
		foreach ( array( ';', '{', '}', '<', '(', '\\' ) as $char ) {
			$this->assert_error( $this->encode( 'vc_custom_heading', 'google_fonts', array( 'font_family' => 'Abril' . $char ) ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_design_builds_one_vc_custom_rule_from_fields(): void {
		$row = $this->encode( 'vc_row', 'css', array( 'margin_top' => '10px' ) );
		$this->assertIsString( $row );
		$this->assertMatchesRegularExpression( '/^\.vc_custom_\d{13}\{[^{}]*\}$/', $row );
		$declarations = static fn( string $rule ): string => (string) preg_replace( '/^\.vc_custom_\d+/', '', $rule );
		$this->assertSame( $declarations( $this->stored( 'vc_row', 'css' ) ), $declarations( $row ) );

		$btn = $this->encode(
			'wpb_btn',
			'css',
			array(
				'padding_left'   => '20px',
				'padding_top'    => '14px',
				'padding_right'  => '20px',
				'padding_bottom' => '14px',
			)
		);
		$this->assertSame( $declarations( $this->stored( 'wpb_btn', 'css' ) ), $declarations( $btn ), 'Declarations in the editor\'s order, whatever the input order.' );

		$full = $this->encode(
			'vc_row',
			'css',
			array(
				'margin_bottom'     => '2em',
				'border_left_width' => '1px',
				'border_style'      => 'solid',
				'border_color'      => '#000',
				'border_radius'     => '4px',
				'background_color'  => '#eeeeee',
			)
		);
		$this->assertSame( '{margin-bottom: 2em !important;border-left-width: 1px !important;background-color: #eeeeee !important;border-left-style: solid !important;border-color: #000 !important;border-radius: 4px !important;}', $declarations( $full ) );
	}

	public function test_design_refuses_a_length_without_a_unit_and_a_colour_with_a_brace(): void {
		foreach ( array( array( 'margin_top' => '10' ), array( 'background_color' => '#fff}' ), array( 'margin_top' => '10px;}body{x:1' ), array( 'selector' => '.x' ), array( 'background_image' => 'url(x)' ), array( 'border_style' => 'url(x)' ) ) as $fields ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- one field each.
			$this->assert_error( $this->encode( 'vc_row', 'css', $fields ), 'aafm_builder_invalid_value', 400 );
		}
		$this->assert_error( $this->encode( 'vc_row', 'css', '.vc_custom_1{color:red}' ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_design_background_image_is_an_attachment_resolved_by_the_plugin(): void {
		$image = $this->image();
		$rule  = $this->encode(
			'vc_row',
			'css',
			array(
				'background_image' => $image,
				'background_style' => 'cover',
			)
		);
		$this->assertIsString( $rule );
		$this->assertStringContainsString( 'background-image: url(' . wp_get_attachment_image_url( $image, 'full' ) . '?id=' . $image . ') !important;', $rule );
		$this->assertStringContainsString( 'background-size: cover !important;', $rule );
	}

	public function test_design_and_raw_need_unfiltered_html_before_anything_is_encoded(): void {
		$this->act_as_restricted_editor();
		foreach ( array( 'design', 'raw' ) as $kind ) {
			$this->assert_error( aafm_wpbakery_script_gate( $kind ), 'aafm_builder_needs_unfiltered_html', 403 );
		}
		foreach ( array( 'text', 'link', 'image', 'choice', 'colour', 'number', 'token', 'group', 'safe_text', 'list', 'content' ) as $kind ) {
			$this->assertTrue( aafm_wpbakery_script_gate( $kind ), $kind );
		}

		$this->acting_as( 'administrator' );
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			grant_super_admin( get_current_user_id() );
		}
		$this->assertTrue( aafm_wpbakery_script_gate( 'design' ) );
		$this->assertTrue( aafm_wpbakery_script_gate( 'raw' ) );
	}

	public function test_raw_values_use_the_vendor_forms(): void {
		$code = '<div class="s8a-raw">Raw <strong>HTML</strong> &amp; "quotes"</div>';
		$this->assertSame( 'JTNDZGl2JTIwY2xhc3MlM0QlMjJzOGEtcmF3JTIyJTNFUmF3JTIwJTNDc3Ryb25nJTNFSFRNTCUzQyUyRnN0cm9uZyUzRSUyMCUyNmFtcCUzQiUyMCUyMnF1b3RlcyUyMiUzQyUyRmRpdiUzRQ==', $this->encode( 'vc_raw_html', 'content', $code ), 'The fixture\'s stored raw HTML.' );
		$this->assertSame( $code, aafm_wpbakery_decode_raw( (string) $this->encode( 'vc_raw_html', 'content', $code ) ) );
		$this->assertSame( 'alert(``hi``)', $this->encode( 'wpb_btn', 'custom_onclick_code', 'alert("hi")' ) );
		$iframe = '<iframe src="https://maps.example.com/x"></iframe>';
		$e8     = $this->encode( 'vc_gmaps', 'link', $iframe );
		$this->assertStringStartsWith( '#E-8_', (string) $e8 );
		$this->assertSame( $iframe, aafm_wpbakery_decode_value( (string) $e8, 'textarea_safe' ) );
	}

	public function test_wrong_json_types_are_refused_cleanly_or_coerced(): void {
		$this->assertSame( '2026', $this->encode( 'wpb_btn', 'title', 2026 ) );
		foreach ( array( true, null, array( 'a' => 1 ), new \stdClass() ) as $bad ) {
			$this->assert_error( $this->encode( 'wpb_btn', 'title', $bad ), 'aafm_builder_invalid_value', 400 );
		}
		$this->assert_error( $this->encode( 'vc_single_image', 'image', '13' ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'wpb_btn', 'link', 'https://example.com' ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'vc_row', 'css', null ), 'aafm_builder_invalid_value', 400 );
		$error = $this->encode( 'wpb_btn', 'title', true );
		$this->assertStringContainsString( 'title', $error->get_error_message(), 'The refusal names the setting.' );
	}
}
