<?php
/**
 * Avada element tools: the element map through the vendor seam, the kinds, the encoder (the inverse of the S8b
 * decoder, checked against the bytes Avada Builder 3.16.1's own editor stored in tests/Fixtures/Avada/page-elements.txt
 * and in the S10 editor probe, scratchpad/1113/s10-editor-rewrites.md), the body-text choke point,
 * avada-list-elements and avada-update-element.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\AvadaMapStub;
use AAFM\Tests\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/Support/AvadaMapStub.php';

final class AvadaElementsTest extends TestCase {

	/**
	 * Fusion tags the fixture uses beyond the baseline aafm_fusion_shortcode_tags() always reads; Avada registers them
	 * as shortcodes, which PHPUnit does not load.
	 */
	private const TAGS = array( 'fusion_global', 'fusion_title', 'fusion_button', 'fusion_imageframe', 'fusion_code', 'fusion_tabs', 'fusion_tab' );

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		AvadaMapStub::install();
		foreach ( self::TAGS as $tag ) {
			add_shortcode( $tag, '__return_empty_string' );
		}
	}

	public function tear_down(): void {
		AvadaMapStub::remove();
		foreach ( self::TAGS as $tag ) {
			remove_shortcode( $tag );
		}
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		delete_option( 'avada_disable_encoding' );
		delete_option( 'fusion_builder_settings' );
		parent::tear_down();
	}

	/**
	 * The S8b fixture.
	 *
	 * @return string
	 */
	private function fixture(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Avada/page-elements.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local fixture file, not a remote URL.
	}

	/**
	 * The fixture's elements.
	 *
	 * @param string|null $content Content, or null for the fixture.
	 * @return list<array<string,mixed>>
	 */
	private function elements( ?string $content = null ): array {
		$content = $content ?? $this->fixture();
		return (array) aafm_builder_elements( $content, aafm_fusion_shortcode_tags() );
	}

	/**
	 * The first element with a tag.
	 *
	 * @param string      $tag     Tag.
	 * @param string|null $content Content, or null for the fixture.
	 * @return array<string,mixed>
	 */
	private function element( string $tag, ?string $content = null ): array {
		foreach ( $this->elements( $content ) as $element ) {
			if ( $tag === $element['tag'] ) {
				return $element;
			}
		}
		$this->fail( "No $tag." );
	}

	/**
	 * A param from the stub map.
	 *
	 * @param string $tag  Tag.
	 * @param string $name Param name.
	 * @return array<string,mixed>
	 */
	private function param( string $tag, string $name ): array {
		foreach ( AvadaMapStub::map()[ $tag ]['params'] as $param ) {
			if ( is_array( $param ) && ( $param['param_name'] ?? null ) === $name ) {
				return $param;
			}
		}
		$this->fail( "No $tag $name in the stub map." );
	}

	/**
	 * Encode one setting the way the update tool does.
	 *
	 * @param string $tag   Tag.
	 * @param string $name  Param name.
	 * @param mixed  $value Input.
	 * @return string|array<string,string>|WP_Error
	 */
	private function encode( string $tag, string $name, $value ) {
		$param = $this->param( $tag, $name );
		return aafm_avada_encode_value( aafm_avada_param_kind( $tag, $param )['kind'], $value, $param );
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

	public function test_list_rows_have_the_four_keys(): void {
		$this->acting_as( 'editor' );
		$out = aafm_exec_avada_list_elements( array( 'per_page' => 50 ) );

		$this->assertIsArray( $out );
		$this->assertSame( array( 'elements', 'total', 'skipped', 'map_source' ), array_keys( $out ) );
		$this->assertSame( 'avada', $out['map_source'] );
		$this->assertSame( count( AvadaMapStub::map() ) - 1, $out['total'] );
		foreach ( $out['elements'] as $row ) {
			$this->assertSame( array( 'tag', 'name', 'is_container', 'child_tag' ), array_keys( $row ) );
		}
		$rows = array_column( $out['elements'], null, 'tag' );
		$this->assertTrue( $rows['fusion_builder_container']['is_container'] );
		$this->assertTrue( $rows['fusion_tabs']['is_container'] );
		$this->assertSame( 'fusion_tab', $rows['fusion_tabs']['child_tag'] );
		$this->assertFalse( $rows['fusion_button']['is_container'] );
		$this->assertNull( $rows['fusion_button']['child_tag'] );
		$this->assertSame( array( 'loader', 'map' ), AvadaMapStub::$calls );
	}

	public function test_search_and_pagination(): void {
		$this->acting_as( 'editor' );
		$out = aafm_exec_avada_list_elements( array( 'search' => 'butt' ) );
		$this->assertSame( array( 'fusion_button' ), array_column( $out['elements'], 'tag' ) );

		$page = aafm_exec_avada_list_elements(
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertCount( 2, $page['elements'] );
		$this->assertSame( 'fusion_builder_column', $page['elements'][0]['tag'] );
	}

	public function test_tag_detail_lists_settings(): void {
		$this->acting_as( 'editor' );
		$out = aafm_exec_avada_list_elements( array( 'tag' => 'fusion_button' ) );

		$this->assertSame( 1, $out['total'] );
		$row      = $out['elements'][0];
		$settings = array_column( $row['settings'], null, 'name' );
		$this->assertSame( array( 'tag', 'name', 'is_container', 'child_tag', 'settings' ), array_keys( $row ) );
		$this->assertSame( array( 'name', 'kind', 'label', 'default', 'allowed_values', 'writable', 'needs' ), array_keys( $settings['link'] ) );
		$this->assertSame( 'link', $settings['link']['kind'] );
		$this->assertSame( 'choice', $settings['target']['kind'] );
		$this->assertSame( '_self', $settings['target']['default'] );
		$this->assertSame( array( '_self', '_blank', 'lightbox' ), $settings['target']['allowed_values'] );
		$this->assertSame( 'raw', $settings['link_attributes']['kind'] );
		$this->assertSame( 'unfiltered_html', $settings['link_attributes']['needs'] );
		// A dimension is one setting per side, named as Avada stores it.
		$this->assertSame( 'size', $settings['padding_top']['kind'] );
		$this->assertArrayNotHasKey( 'padding', $settings );
		// Typography sub-values are listed under their stored names and are not writable.
		$this->assertSame( 'typography', $settings['fusion_font_family_button_font']['kind'] );
		$this->assertFalse( $settings['fusion_font_family_button_font']['writable'] );
		// The inner content is text, not a setting.
		$this->assertArrayNotHasKey( 'element_content', $settings );
		$this->assertSame( '', $settings['title']['default'] );

		$this->assert_error( aafm_exec_avada_list_elements( array( 'tag' => 'no_such_tag' ) ), 'aafm_builder_unknown_element', 400 );
	}

	public function test_empty_placeholders_are_skipped(): void {
		$this->acting_as( 'editor' );
		$out = aafm_exec_avada_list_elements( array( 'per_page' => 50 ) );
		$this->assertNotContains( 'fusion_placeholder_empty', array_column( $out['elements'], 'tag' ) );

		$map = aafm_exec_avada_list_elements( array( 'tag' => 'fusion_google_map' ) );
		$this->assertSame( 1, $map['skipped'], 'The group with no setting name is skipped and counted.' );
	}

	public function test_a_missing_loader_is_refused(): void {
		$this->acting_as( 'editor' );
		AvadaMapStub::absent( 'avada_map_loader' );
		$this->assert_error( aafm_exec_avada_list_elements( array() ), 'aafm_avada_map_unavailable', 409 );
		$this->assert_error( aafm_avada_map(), 'aafm_avada_map_unavailable', 409 );
	}

	public function test_an_empty_map_after_the_loader_is_refused(): void {
		$this->acting_as( 'editor' );
		AvadaMapStub::install( array( 'fusion_placeholder_empty' => array() ) );
		$this->assert_error( aafm_exec_avada_list_elements( array() ), 'aafm_avada_map_unavailable', 409 );
		$this->assertSame( array( 'loader', 'map' ), AvadaMapStub::$calls );
	}

	public function test_a_throwing_loader_is_refused(): void {
		$this->acting_as( 'editor' );
		$throw = static fn( $cb, string $entry ) => 'avada_map_loader' === $entry ? static function (): void {
			throw new \RuntimeException( 'vendor failure' );
		} : $cb;
		add_filter( 'aafm_builder_vendor', $throw, 20, 2 );
		$this->assert_error( aafm_avada_map(), 'aafm_avada_map_unavailable', 409 );
	}

	public function test_a_secret_in_extras_never_leaves(): void {
		$this->acting_as( 'editor' );
		$result = array(
			aafm_exec_avada_list_elements( array( 'per_page' => 50 ) ),
			aafm_exec_avada_list_elements( array( 'tag' => 'fusion_google_map' ) ),
		);
		$this->assertStringNotContainsString( 'AIzaSECRET', (string) wp_json_encode( $result ) );
	}

	public function test_a_subscriber_is_refused(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_perm_blocks_floor( array() ) );
		$this->assertSame( 'aafm_perm_blocks_floor', aafm_args_avada_list_elements()['permission_callback'] );
	}

	public function test_link_attributes_and_any_attributes_setting_are_the_raw_kind(): void {
		foreach ( array(
			array( 'fusion_button', 'link_attributes' ),
			array( 'fusion_google_map', 'attributes' ),
		) as list( $tag, $name ) ) {
			$kind = aafm_avada_param_kind( $tag, $this->param( $tag, $name ) );
			$this->assertSame( 'raw', $kind['kind'], $name );
			$this->assertSame( 'unfiltered_html', $kind['needs'], $name );
			$this->assertTrue( $kind['writable'], $name );
		}
		$custom = aafm_avada_param_kind(
			'fusion_button',
			array(
				'type'       => 'colorpickeralpha',
				'param_name' => 'hover_attributes',
			)
		);
		$this->assertSame( 'raw', $custom['kind'], 'The name rule wins over the type.' );
	}

	public function test_repeater_raw_textarea_and_code_are_the_raw_kind(): void {
		$this->assertSame( 'raw', aafm_avada_param_kind( 'fusion_button', $this->param( 'fusion_button', 'html_attributes' ) )['kind'] );
		$this->assertSame( 'raw', aafm_avada_param_kind( 'fusion_google_map', $this->param( 'fusion_google_map', 'infobox_content' ) )['kind'] );
		$this->assertSame( 'raw', aafm_avada_param_kind( 'fusion_google_map', $this->param( 'fusion_google_map', 'pattern' ) )['kind'] );
		$this->assertSame( 'raw', aafm_avada_param_kind( 'fusion_code', $this->param( 'fusion_code', 'element_content' ) )['kind'] );
	}

	public function test_typography_and_dynamic_params_are_not_writable(): void {
		$typo = aafm_avada_param_kind( 'fusion_button', $this->param( 'fusion_button', 'main_typography' ) );
		$this->assertSame( array( 'typography', false, '' ), array( $typo['kind'], $typo['writable'], $typo['needs'] ) );
		$dynamic = aafm_avada_param_kind(
			'fusion_button',
			array(
				'type'       => 'textfield',
				'param_name' => 'dynamic_params',
			)
		);
		$this->assertFalse( $dynamic['writable'] );
		$handler = aafm_avada_param_kind( 'fusion_google_map', $this->param( 'fusion_google_map', 'onclick' ) );
		$this->assertFalse( $handler['writable'], 'An event handler name, or a type the encoder does not know, is refused for everyone.' );
		$hidden = aafm_avada_param_kind( 'fusion_imageframe', $this->param( 'fusion_imageframe', 'image_id' ) );
		$this->assertFalse( $hidden['writable'], 'image_id is written only by the image kind, with the URL.' );
	}

	public function test_text_escapes_the_six_characters_and_refuses_brackets(): void {
		// The form the editor keeps after its own save (s10-editor-rewrites.md, page 17): & < > as entities, the
		// apostrophe and the backtick as characters.
		$encoded = $this->encode( 'fusion_button', 'title', "H 'a' and `t` < b & c" );
		$this->assertSame( "H 'a' and `t` &lt; b &amp; c", $encoded );
		$this->assertSame( "H 'a' and `t` < b & c", aafm_avada_decode_atts( 'fusion_button', array( 'title' => $encoded ) )['title'] );
		foreach ( array( 'a [b]', 'a &#91;b', 'say "hi"', 'say &quot;hi&quot;', 'say &amp;quot;hi' ) as $bad ) {
			$this->assert_error( $this->encode( 'fusion_button', 'title', $bad ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_text_normalises_a_bare_ampersand(): void {
		$this->assertSame( 'Caf&amp;e &amp; Cr&amp;me', $this->encode( 'fusion_button', 'title', 'Caf&e & Cr&amp;me' ) );
		$this->assertSame( 'Docs &amp; guides', $this->encode( 'fusion_button', 'title', 'Docs &amp; guides' ) );
	}

	public function test_link_refuses_javascript_and_data_urls(): void {
		$this->assertSame( 'https://example.com/docs', $this->encode( 'fusion_button', 'link', 'https://example.com/docs' ) );
		$this->assertSame( $this->element( 'fusion_button' )['atts']['link'], $this->encode( 'fusion_button', 'link', 'https://example.com/docs' ) );
		$this->assertSame( 'https://example.com/?a=1&amp;b=2', $this->encode( 'fusion_button', 'link', 'https://example.com/?a=1&b=2' ) );
		foreach ( array( 'javascript:alert(1)', 'data:text/html,x', 'https://example.com/[x]', 'https://example.com/"x' ) as $bad ) {
			$this->assert_error( $this->encode( 'fusion_button', 'link', $bad ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_image_writes_image_id_and_url_together(): void {
		$id  = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/png',
				'file'           => '2026/11/s8b-image.png',
				'post_title'     => 'S10 image',
			)
		);
		$out = $this->encode( 'fusion_imageframe', 'element_content', $id );
		$this->assertSame(
			array(
				'image_id' => $id . '|full',
				''         => wp_get_attachment_url( $id ),
			),
			$out
		);
		// The fixture's stored pair has the same shape: the id and size joined by a pipe, the URL between the tags.
		$this->assertMatchesRegularExpression( '/^\d+\|full$/', $this->element( 'fusion_imageframe' )['atts']['image_id'] );
		$this->assert_error( $this->encode( 'fusion_imageframe', 'element_content', self::factory()->post->create() ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'fusion_imageframe', 'element_content', 'https://example.com/x.png' ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_choice_must_be_in_the_maps_list(): void {
		$this->assertSame( '_blank', $this->encode( 'fusion_button', 'target', '_blank' ) );
		$this->assertSame( $this->element( 'fusion_button' )['atts']['target'], $this->encode( 'fusion_button', 'target', '_blank' ) );
		$this->assert_error( $this->encode( 'fusion_button', 'target', '_top' ), 'aafm_builder_invalid_value', 400 );
		$this->assertSame( 'small-visibility,medium-visibility,large-visibility', $this->encode( 'fusion_button', 'hide_on_mobile', array( 'small-visibility', 'medium-visibility', 'large-visibility' ) ) );
		$this->assertSame( $this->element( 'fusion_button' )['atts']['hide_on_mobile'], $this->encode( 'fusion_button', 'hide_on_mobile', array( 'small-visibility', 'medium-visibility', 'large-visibility' ) ) );
		$this->assert_error( $this->encode( 'fusion_button', 'hide_on_mobile', array( 'tiny-visibility' ) ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_colour_accepts_hex_rgb_rgba_hsla_and_a_palette_variable(): void {
		foreach ( array( '#fff', '#a1b2c3', '#a1b2c3d4', 'rgb(1,2,3)', 'rgba(1, 2, 3, 0.5)', 'hsla(120, 50%, 40%, 0.3)', 'var(--awb-color1)', 'var(--awb-color3-l)' ) as $ok ) {
			$this->assertSame( $ok, $this->encode( 'fusion_button', 'accent_color', $ok ), $ok );
		}
		foreach ( array( 'red;}', 'var(--other)', 'url(x)', '#ggg' ) as $bad ) {
			$this->assert_error( $this->encode( 'fusion_button', 'accent_color', $bad ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_number_respects_the_range(): void {
		$this->assertSame( '40', $this->encode( 'fusion_button', 'gradient_start_position', 40 ) );
		$this->assertSame( '0', $this->encode( 'fusion_button', 'gradient_start_position', '0' ) );
		$this->assertSame( '2.5', $this->encode( 'fusion_button', 'gradient_start_position', 2.5 ) );
		foreach ( array( 101, -1, '1e2', 'x' ) as $bad ) {
			$this->assert_error( $this->encode( 'fusion_button', 'gradient_start_position', $bad ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_size_needs_a_number_and_a_unit_from_the_fixed_list(): void {
		foreach ( array( '10px', '1.5em', '2rem', '50%', '10vw', '5vh', '-4px' ) as $ok ) {
			$this->assertSame( $ok, $this->encode( 'fusion_button', 'padding', $ok ), $ok );
		}
		foreach ( array( '10', '10pt', 'calc(1px)', '10px;color:red' ) as $bad ) {
			$this->assert_error( $this->encode( 'fusion_button', 'padding', $bad ), 'aafm_builder_invalid_value', 400 );
		}
	}

	public function test_token_and_id_patterns(): void {
		$this->assertSame( 'fa-check fas', $this->encode( 'fusion_button', 'icon', 'fa-check fas' ) );
		$this->assertSame( 'my-class two_2', $this->encode( 'fusion_button', 'class', 'my-class two_2' ) );
		$this->assertSame( 'cta-1', $this->encode( 'fusion_button', 'id', 'cta-1' ) );
		$this->assert_error( $this->encode( 'fusion_button', 'id', 'two words' ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'fusion_button', 'class', 'a" onclick="x' ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_date_is_written_in_avadas_stored_format(): void {
		$this->assertSame( '2026-05-10 12:30:00', $this->encode( 'fusion_builder_container', 'publish_date', '2026-05-10 12:30' ) );
		$this->assertSame( '2026-05-10 00:00:00', $this->encode( 'fusion_builder_container', 'publish_date', '2026-05-10' ) );
		$this->assert_error( $this->encode( 'fusion_builder_container', 'publish_date', 'not a date' ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_code_block_is_base64_only_when_avada_disable_encoding_is_1(): void {
		$this->act_as_admin();
		$code   = '<div class="s8b-code">Code block output</div>';
		$stored = substr( $this->fixture(), (int) $this->element( 'fusion_code' )['open_end'], (int) $this->element( 'fusion_code' )['inner_end'] - (int) $this->element( 'fusion_code' )['open_end'] );

		update_option( 'avada_disable_encoding', '1' );
		$this->assertSame( $stored, $this->encode( 'fusion_code', 'element_content', $code ), 'The fixture\'s stored base64.' );
		$this->assertSame( $code, aafm_avada_decode_if_needed( (string) $this->encode( 'fusion_code', 'element_content', $code ) ) );

		update_option( 'avada_disable_encoding', '0' );
		$this->assertSame( $code, $this->encode( 'fusion_code', 'element_content', $code ) );
		delete_option( 'avada_disable_encoding' );
		$this->assertSame( $code, $this->encode( 'fusion_code', 'element_content', $code ) );
	}

	public function test_raw_fields_and_repeaters_are_base64(): void {
		$this->act_as_admin();
		$this->assertSame( 'W10=', $this->encode( 'fusion_button', 'html_attributes', array() ), 'The fixture\'s empty repeater.' );
		$rows    = array(
			array(
				'name'  => 'data-x',
				'value' => '1',
			),
		);
		$encoded = (string) $this->encode( 'fusion_button', 'html_attributes', $rows );
		$this->assertSame( $rows, json_decode( (string) aafm_avada_decode_atts( 'fusion_button', array( 'html_attributes' => $encoded ) )['html_attributes'], true ) );

		$info = (string) $this->encode( 'fusion_google_map', 'infobox_content', '<b>Info</b>' );
		$this->assertSame( '<b>Info</b>', aafm_avada_decode_atts( 'fusion_map', array( 'infobox_content' => $info ) )['infobox_content'] );

		$pattern = (string) $this->encode( 'fusion_google_map', 'pattern', "[a-z]'+" );
		$this->assertSame( "[a-z]'+", aafm_avada_decode_atts( 'fusion_form_email', array( 'pattern' => $pattern ) )['pattern'] );

		$this->assertSame( "data-track='cta'", $this->encode( 'fusion_button', 'link_attributes', "data-track='cta'" ), 'link_attributes is plain text.' );
		$this->assert_error( $this->encode( 'fusion_button', 'link_attributes', 'onclick="x()"' ), 'aafm_builder_invalid_value', 400 );
	}

	public function test_raw_kinds_need_unfiltered_html_before_anything_is_encoded(): void {
		$this->act_as_restricted_editor();
		foreach ( array( 'link_attributes', 'html_attributes' ) as $name ) {
			$this->assert_error( aafm_avada_script_gate( aafm_avada_param_kind( 'fusion_button', $this->param( 'fusion_button', $name ) )['kind'] ), 'aafm_builder_needs_unfiltered_html', 403 );
		}
		$this->assert_error( aafm_avada_script_gate( 'raw' ), 'aafm_builder_needs_unfiltered_html', 403 );
		$this->assertTrue( aafm_avada_script_gate( 'text' ) );
		$this->act_as_admin();
		$this->assertTrue( aafm_avada_script_gate( 'raw' ) );
	}

	public function test_content_has_script(): void {
		$this->assertTrue( aafm_avada_content_has_script( '[fusion_code]x[/fusion_code]' ) );
		$this->assertTrue( aafm_avada_content_has_script( '[fusion_button link_attributes="data-x=\'1\'"]a[/fusion_button]' ) );
		$this->assertTrue( aafm_avada_content_has_script( '[fusion_button html_attributes="W3sibmFtZSI6Im9uY2xpY2siLCJ2YWx1ZSI6IngoKSJ9XQ=="]a[/fusion_button]' ) );
		$this->assertTrue( aafm_avada_content_has_script( '[fusion_tagline_box description="eA=="]a[/fusion_tagline_box]' ) );
		$this->assertTrue( aafm_avada_content_has_script( '[fusion_button onmouseover="x()"]a[/fusion_button]' ) );
		$this->assertFalse( aafm_avada_content_has_script( '[fusion_button link_attributes="" html_attributes="W10="]a[/fusion_button]' ) );
		$this->assertFalse( aafm_avada_content_has_script( '[fusion_title size="2"]Hi[/fusion_title]' ) );
		$this->assertTrue( aafm_avada_content_has_script( $this->fixture() ), 'The fixture holds a code block and link_attributes.' );
	}

	public function test_body_text_refuses_each_class_for_a_restricted_editor(): void {
		$this->act_as_restricted_editor();
		$cases = array(
			'aafm_avada_brackets_need_unfiltered_html' => array( '<p>a [x]</p>', '<p>a &#91;x&#93;</p>', '<p>a &lbrack;x</p>' ),
			'aafm_avada_encoded_brackets_need_unfiltered_html' => array( '<p>a %5Bx%5D</p>', '<a href="/?q=%5b">l</a>' ),
			'aafm_avada_data_attributes_need_unfiltered_html' => array( '<span data-x="1">d</span>', '<span data&#45;x="1">d</span>' ),
			'aafm_avada_incomplete_html_needs_unfiltered_html' => array( '<p>a <span title="x' ),
		);
		foreach ( $cases as $code => $texts ) {
			foreach ( $texts as $text ) {
				$this->assert_error( aafm_avada_body_text( $text ), $code, 400 );
			}
		}
		$this->assertSame( '<p>Caf&amp;e &amp; &lt;b&gt; &quot;q&quot;</p>', aafm_avada_body_text( '<p>Caf&e &amp; &lt;b&gt; &quot;q&quot;</p>' ) );
	}

	public function test_body_text_for_an_administrator_is_kept(): void {
		$this->act_as_admin();
		$this->assertSame( '<p>a [x] %5B <span data-x="1">d</span></p>', aafm_avada_body_text( '<p>a &#91;x] %5B <span data-x="1">d</span></p>' ) );
	}

	public function test_wrong_json_types_are_refused_cleanly_or_coerced(): void {
		$this->act_as_admin();
		$this->assertSame( '7', $this->encode( 'fusion_button', 'title', 7 ) );
		foreach ( array( array( 'a' ), true, null, (object) array() ) as $bad ) {
			$this->assert_error( $this->encode( 'fusion_button', 'title', $bad ), 'aafm_builder_invalid_value', 400 );
			$this->assert_error( $this->encode( 'fusion_button', 'link', $bad ), 'aafm_builder_invalid_value', 400 );
			$this->assert_error( $this->encode( 'fusion_button', 'accent_color', $bad ), 'aafm_builder_invalid_value', 400 );
		}
		$this->assert_error( $this->encode( 'fusion_button', 'target', array( '_blank' ) ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'fusion_button', 'html_attributes', 'not rows' ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'fusion_button', 'html_attributes', array( array( 'name' => array( 'x' ) ) ) ), 'aafm_builder_invalid_value', 400 );
	}

	/**
	 * An Avada page holding the fixture (or other content), as the current user's.
	 *
	 * @param string|null $content Content, or null for the fixture.
	 * @param string      $type    Post type.
	 * @return int
	 */
	private function avada_post( ?string $content = null, string $type = 'page' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
				'post_content' => $content ?? $this->fixture(),
			)
		);
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		return $id;
	}

	/**
	 * Update input addressing the nth element with a tag on a post.
	 *
	 * @param int                 $id    Post.
	 * @param string              $tag   Tag.
	 * @param array<string,mixed> $extra Extra input.
	 * @param int                 $nth   Which one.
	 * @return array<string,mixed>
	 */
	private function addr( int $id, string $tag, array $extra = array(), int $nth = 0 ): array {
		$content = (string) get_post( $id )->post_content;
		foreach ( $this->elements( $content ) as $element ) {
			if ( $tag === $element['tag'] && 0 === $nth-- ) {
				return array_merge(
					array(
						'post_id' => $id,
						'index'   => $element['index'],
						'expect'  => array(
							'tag'         => $tag,
							'fingerprint' => aafm_builder_fingerprint( $content, $element ),
						),
					),
					$extra
				);
			}
		}
		$this->fail( "No $tag." );
	}

	/**
	 * Assert a refusal left the post as it was.
	 *
	 * @param mixed  $out    Result.
	 * @param string $code   Code.
	 * @param int    $status Status.
	 * @param int    $id     Post.
	 * @param string $before Content before.
	 * @return void
	 */
	private function assert_refused( $out, string $code, int $status, int $id, string $before ): void {
		$this->assert_error( $out, $code, $status );
		clean_post_cache( $id );
		$this->assertSame( $before, get_post( $id )->post_content );
	}

	public function test_update_sets_a_button_link_and_target(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		$out    = aafm_exec_avada_update_element(
			$this->addr(
				$id,
				'fusion_button',
				array(
					'set_atts' => array(
						'link'   => 'https://example.com/new?a=1&b=2',
						'target' => '_self',
					),
				)
			)
		);
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( array( 'link', 'target' ), $out['changed'] );
		$after = (string) get_post( $id )->post_content;
		$this->assertSame( str_replace( array( 'link="https://example.com/docs"', 'target="_blank"' ), array( 'link="https://example.com/new?a=1&amp;b=2"', 'target="_self"' ), $before ), $after, 'Only those two attributes changed.' );
		$this->assertSame( aafm_builder_page_fingerprint( $after ), $out['page_fingerprint'] );
		$this->assertTrue( $out['reversible'] );
		$this->assertSame( $before, get_post( (int) $out['restore_revision_id'] )->post_content );
		$this->assertArrayNotHasKey( 'reversible_reason', $out );
		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'changed', 'restore_revision_id', 'reversible' ), array_keys( $out ) );
	}

	public function test_update_sets_an_image_and_its_alt_text(): void {
		$this->act_as_admin();
		$id    = $this->avada_post();
		$image = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => '2026/11/s10.jpg',
			)
		);
		$out   = aafm_exec_avada_update_element(
			$this->addr(
				$id,
				'fusion_imageframe',
				array(
					'set_atts' => array(
						'image_id' => $image,
						'alt'      => 'A new picture',
					),
				)
			)
		);
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( array( 'image_id', 'alt' ), $out['changed'] );
		$this->assertStringContainsString( '[fusion_imageframe image_id="' . $image . '|full" alt="A new picture"]' . wp_get_attachment_url( $image ) . '[/fusion_imageframe]', (string) get_post( $id )->post_content );
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_imageframe', array( 'text' => 'x' ) ) ), 'aafm_builder_attribute_not_writable', 400, $id, (string) get_post( $id )->post_content );
	}

	public function test_update_sets_a_title_a_colour_and_a_container_padding(): void {
		$this->act_as_restricted_editor();
		$id  = $this->avada_post();
		$out = aafm_exec_avada_update_element( $this->addr( $id, 'fusion_title', array( 'text' => 'New <em>title</em> & more' ) ) );
		$this->assertSame( array( 'text' ), $out['changed'] );
		$this->assertStringContainsString( '[fusion_title size="2"]New <em>title</em> &amp; more[/fusion_title]', (string) get_post( $id )->post_content );

		$out = aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'accent_color' => 'var(--awb-color1)' ) ) ) );
		$this->assertSame( array( 'accent_color' ), $out['changed'] );
		$this->assertStringContainsString( 'accent_color="var(--awb-color1)"', (string) get_post( $id )->post_content );

		$out = aafm_exec_avada_update_element( $this->addr( $id, 'fusion_builder_container', array( 'set_atts' => array( 'padding_top' => '40px' ) ) ) );
		$this->assertSame( array( 'padding_top' ), $out['changed'] );
		$this->assertStringStartsWith( '[fusion_builder_container padding_top="40px"][fusion_builder_row]', (string) get_post( $id )->post_content );
	}

	public function test_update_sets_a_code_block_as_an_administrator(): void {
		$this->act_as_admin();
		update_option( 'avada_disable_encoding', '1' );
		$id  = $this->avada_post();
		$out = aafm_exec_avada_update_element( $this->addr( $id, 'fusion_code', array( 'text' => '<script>s10()</script>' ) ) );
		$this->assertSame( array( 'text' ), $out['changed'] );
		$this->assertStringContainsString( ']' . base64_encode( '<script>s10()</script>' ) . '[/fusion_code]', (string) get_post( $id )->post_content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the expected stored form.
	}

	public function test_untouched_attributes_and_every_other_byte_are_identical(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		$out    = aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'gradient_start_position' => 30 ) ) ) );
		$after  = (string) get_post( $id )->post_content;
		$this->assertSame( str_replace( 'gradient_start_position=""', 'gradient_start_position="30"', $before ), $after );
		$this->assertSame( array( 'gradient_start_position' ), $out['changed'] );

		$same = aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'gradient_start_position' => '30' ) ) ) );
		$this->assertSame( array(), $same['changed'], 'The same value saves nothing (commit step 0).' );
		$this->assertNull( $same['restore_revision_id'] );
	}

	public function test_update_refuses_an_element_that_shows_a_global_item(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		$out    = aafm_exec_avada_update_element( $this->addr( $id, 'fusion_global', array( 'set_atts' => array( 'id' => 'x' ) ) ) );
		$this->assert_refused( $out, 'aafm_avada_global_element', 409, $id, $before );
		$this->assertStringContainsString( '14', $out->get_error_message() );
		$marked = $this->avada_post( '[fusion_builder_container][fusion_builder_row][fusion_builder_column][fusion_button fusion_global="14" title="a"]x[/fusion_button][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' );
		$this->assert_error( aafm_exec_avada_update_element( $this->addr( $marked, 'fusion_button', array( 'set_atts' => array( 'title' => 'b' ) ) ) ), 'aafm_avada_global_element', 409 );
	}

	public function test_update_refuses_a_setting_driven_by_dynamic_params(): void {
		$this->act_as_admin();
		$dyn    = base64_encode( (string) wp_json_encode( array( 'link' => array( 'data' => 'post_permalink' ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Avada's stored form.
		$id     = $this->avada_post( '[fusion_builder_container][fusion_builder_row][fusion_builder_column][fusion_button link="" title="a" dynamic_params="' . $dyn . '"]x[/fusion_button][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' );
		$before = (string) get_post( $id )->post_content;
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'link' => 'https://example.com' ) ) ) ), 'aafm_avada_dynamic_setting', 400, $id, $before );
		$this->assertIsArray( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'title' => 'b' ) ) ) ), 'A setting dynamic data does not drive stays writable.' );
		$this->assert_error( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'dynamic_params' => '' ) ) ) ), 'aafm_builder_attribute_not_writable', 400 );
	}

	public function test_dynamic_params_is_refused_for_an_administrator_too(): void {
		$this->act_as_admin();
		$id = $this->avada_post();
		$this->assert_error( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'main_typography' => 'x' ) ) ) ), 'aafm_builder_attribute_not_writable', 400 );
		$this->assert_error( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'fusion_font_family_button_font' => 'Arial' ) ) ) ), 'aafm_builder_attribute_not_writable', 400 );
	}

	public function test_update_refuses_a_bracket_or_a_quote_in_a_text_setting(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		foreach ( array( 'a [b]', 'a &quot;b&quot;' ) as $bad ) {
			$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'title' => $bad ) ) ) ), 'aafm_builder_invalid_value', 400, $id, $before );
		}
	}

	public function test_an_editor_without_unfiltered_html_cannot_set_a_code_block_link_attributes_or_html_attributes(): void {
		$this->act_as_restricted_editor();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_code', array( 'text' => 'x' ) ) ), 'aafm_builder_needs_unfiltered_html', 403, $id, $before );
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'link_attributes' => "onclick='x()'" ) ) ) ), 'aafm_builder_needs_unfiltered_html', 403, $id, $before );
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'html_attributes' => array() ) ) ) ), 'aafm_builder_needs_unfiltered_html', 403, $id, $before );
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'remove_atts' => array( 'link_attributes' ) ) ) ), 'aafm_builder_needs_unfiltered_html', 403, $id, $before );
	}

	public function test_body_text_classes_are_refused_through_update_for_a_restricted_editor(): void {
		$this->act_as_restricted_editor();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		foreach ( array(
			'aafm_avada_brackets_need_unfiltered_html' => '<p>a &#91;x&#93;</p>',
			'aafm_avada_encoded_brackets_need_unfiltered_html' => '<p>a %5Bx</p>',
			'aafm_avada_data_attributes_need_unfiltered_html' => '<span data-x="1">d</span>',
			'aafm_avada_incomplete_html_needs_unfiltered_html' => '<p>a <b title="x',
		) as $code => $text ) {
			$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_text', array( 'text' => $text ) ) ), $code, 400, $id, $before );
			$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'text' => $text ) ) ), $code, 400, $id, $before );
		}
	}

	public function test_an_administrator_keeps_brackets_but_not_a_shortcode(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_text', array( 'text' => 'a [fusion_title]x[/fusion_title]' ) ) ), 'aafm_builder_structure_changed', 400, $id, $before );
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_text', array( 'text' => 'a [/fusion_builder_column] b' ) ) ), 'aafm_builder_structure_changed', 400, $id, $before );
		$out = aafm_exec_avada_update_element( $this->addr( $id, 'fusion_text', array( 'text' => '<p>Price [sic] %5B</p>' ) ) );
		$this->assertSame( array( 'text' ), $out['changed'] );
	}

	public function test_stale_unknown_not_writable_and_children_refusals(): void {
		$this->act_as_admin();
		$id                             = $this->avada_post();
		$before                         = (string) get_post( $id )->post_content;
		$stale                          = $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'title' => 'x' ) ) );
		$stale['expect']['fingerprint'] = '0000000000000000';
		$this->assert_refused( aafm_exec_avada_update_element( $stale ), 'aafm_builder_element_mismatch', 409, $id, $before );
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'no_such' => 'x' ) ) ) ), 'aafm_builder_attribute_not_writable', 400, $id, $before );
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'element_content' => 'x' ) ) ) ), 'aafm_builder_attribute_not_writable', 400, $id, $before );
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_tabs', array( 'text' => 'x' ) ) ), 'aafm_builder_attribute_not_writable', 400, $id, $before );
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array() ) ), 'aafm_builder_nothing_to_change', 400, $id, $before );
		$plain = self::factory()->post->create( array( 'post_content' => '<p>plain</p>' ) );
		$this->assert_error(
			aafm_exec_avada_update_element(
				array(
					'post_id' => $plain,
					'index'   => 0,
					'expect'  => array(
						'tag'         => 'x',
						'fingerprint' => '0000000000000000',
					),
				)
			),
			'aafm_not_avada_owned',
			409
		);
	}

	public function test_a_lost_restore_point_is_reported_with_reversible_reason(): void {
		$this->act_as_admin();
		$id   = $this->avada_post();
		$keep = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $keep );
		$out  = aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'title' => 'Kept one' ) ) ) );
		remove_filter( 'wp_revisions_to_keep', $keep );
		$this->assertIsArray( $out );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertFalse( $out['reversible'] );
		$this->assertStringContainsString( 'keeps 1 revision per post', $out['reversible_reason'] );
	}

	public function test_output_schemas_declare_reversible_reason(): void {
		$schema = aafm_args_avada_update_element()['output_schema'];
		$this->assertSame( 'string', $schema['properties']['reversible_reason']['type'] );
		$this->assertSame( array( 'integer', 'null' ), $schema['properties']['restore_revision_id']['type'] );
	}

	public function test_update_works_on_a_library_element_and_a_form(): void {
		$this->act_as_admin();
		foreach ( array( 'fusion_element', 'fusion_form' ) as $type ) {
			// A8: library elements keep revisions, forms do not.
			register_post_type(
				$type,
				array(
					'public'          => false,
					'capability_type' => 'post',
					'map_meta_cap'    => true,
					'supports'        => 'fusion_element' === $type ? array( 'title', 'editor', 'revisions' ) : array( 'title', 'editor' ),
				)
			);
			$id = $this->avada_post( null, $type );
			delete_post_meta( $id, 'fusion_builder_status' );
			$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ), $type );
			$out = aafm_exec_avada_update_element( $this->addr( $id, 'fusion_button', array( 'set_atts' => array( 'title' => 'In ' . $type ) ) ) );
			$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : $type );
			$this->assertSame( 'fusion_form' !== $type, $out['reversible'], $type );
			unregister_post_type( $type );
		}
	}

	public function test_update_on_a_section_needs_manage_options(): void {
		register_post_type(
			'fusion_tb_section',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
		$this->act_as_admin();
		$id = $this->avada_post( null, 'fusion_tb_section' );
		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$this->act_as_restricted_editor();
		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		unregister_post_type( 'fusion_tb_section' );
	}

	public function test_permissions_discovery_audit_and_schema(): void {
		$args = aafm_args_avada_update_element();
		$this->assertSame( 'aafm_perm_avada_post_object', $args['permission_callback'] );
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'post_id', 'index', 'expect' ), $args['input_schema']['required'] );
		$this->assertArrayNotHasKey( 'page_fingerprint', $args['input_schema']['properties'] );
		$this->assertSame( 'Ran an element update on Avada page #%s', aafm_activity_detail_map()['aafm/avada-update-element']['template'] );
		$this->acting_as( 'subscriber' );
		$id = self::factory()->post->create();
		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$this->assertArrayHasKey( 'aafm/avada-update-element', aafm_register_avada_elements_definitions( array() ) );
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		$this->assertSame( array(), aafm_register_avada_elements_definitions( array() ) );
		add_filter( 'aafm_integration_active_avada', '__return_true' );
	}
}
