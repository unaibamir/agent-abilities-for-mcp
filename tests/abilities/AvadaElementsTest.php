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

	/**
	 * CX-BR1-2: a text value is stored as sent or refused, never stripped of a tag-like run, a percent-encoded octet or
	 * repeated spaces while the tool reports success.
	 */
	public function test_text_keeps_every_character_it_does_not_refuse(): void {
		foreach ( array( 'Press <Ctrl> + S', 'Save 100%25 now', ' padded ', 'Use <strong> here' ) as $text ) {
			$encoded = $this->encode( 'fusion_button', 'title', $text );
			$this->assertIsString( $encoded, $text );
			$this->assertSame( $text, aafm_avada_decode_atts( 'fusion_button', array( 'title' => $encoded ) )['title'], $text );
		}
		$this->assertSame( 'Press &lt;Ctrl&gt; + S', $this->encode( 'fusion_button', 'title', 'Press <Ctrl> + S' ) );
		$this->assert_error( $this->encode( 'fusion_button', 'title', "tab\there" ), 'aafm_builder_invalid_value', 400 );
		// Avada's editor collapses a run of spaces in an attribute on its next save (smoke site, fix round 1), so it is
		// refused with a message that names it rather than stored to be changed later.
		// BR-5: a non-breaking or zero-width space, as the character or an entity, does not survive Avada's editor.
		foreach ( array( "a\u{00A0}b", 'a&nbsp;b', 'a&#160;b', "a\u{200B}b" ) as $text ) {
			$this->assert_error( $this->encode( 'fusion_button', 'title', $text ), 'aafm_avada_nonbreaking_space', 400 );
		}
		foreach ( array( 'two  spaces', 'two&#32;&#32;spaces' ) as $text ) {
			$out = $this->encode( 'fusion_button', 'title', $text );
			$this->assert_error( $out, 'aafm_avada_repeated_spaces', 400 );
			$this->assertStringContainsString( 'two or more spaces in a row', $out->get_error_message() );
		}
	}

	/**
	 * A1: a double-encoded non-breaking space or space run is decoded as far as the attribute rule decodes it, so it is
	 * refused rather than stored and collapsed by Avada's editor on its next save.
	 */
	public function test_double_encoded_nbsp_and_spaces_are_refused(): void {
		$this->assert_error( $this->encode( 'fusion_button', 'title', 'a&amp;nbsp;b' ), 'aafm_avada_nonbreaking_space', 400 );
		$this->assert_error( $this->encode( 'fusion_button', 'title', 'two&amp;#32;&amp;#32;spaces' ), 'aafm_avada_repeated_spaces', 400 );
	}

	/**
	 * A1: a textarea stored as an attribute (not the element's body) goes through the same attribute rule, so it gets
	 * the same two refusals.
	 */
	public function test_textarea_attribute_gets_the_same_two_refusals(): void {
		$param = array(
			'type'       => 'textarea',
			'param_name' => 'description',
		);
		foreach ( array( "a\u{00A0}b", 'a&nbsp;b', 'a&amp;nbsp;b' ) as $text ) {
			$this->assert_error( aafm_avada_encode_value( 'content', $text, $param ), 'aafm_avada_nonbreaking_space', 400 );
		}
		foreach ( array( 'two  spaces', 'two&amp;#32;&amp;#32;spaces' ) as $text ) {
			$this->assert_error( aafm_avada_encode_value( 'content', $text, $param ), 'aafm_avada_repeated_spaces', 400 );
		}
		$this->assertIsString( aafm_avada_encode_value( 'content', 'one space', $param ) );
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

	public function test_dynamic_data_on_the_inner_image_blocks_an_image_change(): void {
		$this->act_as_admin();
		$dyn    = base64_encode( (string) wp_json_encode( array( 'element_content' => array( 'data' => 'featured_image' ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Avada's stored form.
		$id     = $this->avada_post( '[fusion_builder_container][fusion_builder_row][fusion_builder_column][fusion_imageframe image_id="" dynamic_params="' . $dyn . '"][/fusion_imageframe][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' );
		$before = (string) get_post( $id )->post_content;
		$image  = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => '2026/11/s10-dyn.jpg',
			)
		);
		$this->assert_refused( aafm_exec_avada_update_element( $this->addr( $id, 'fusion_imageframe', array( 'set_atts' => array( 'image_id' => $image ) ) ) ), 'aafm_avada_dynamic_setting', 400, $id, $before );
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

	// S10b: nesting, the insert path and avada-insert-element.

	/**
	 * The stub map with the extra entries the insert tests need: a dynamic data element, which the editor always writes
	 * in the void form.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function insert_map(): array {
		$map           = AvadaMapStub::map();
		$map['awb_dd'] = array(
			'name'      => 'Dynamic Data',
			'shortcode' => 'awb_dd',
			'params'    => array(
				array(
					'type'       => 'textfield',
					'param_name' => 'class',
					'value'      => '',
				),
			),
		);
		return $map;
	}

	/**
	 * Insert input against the nth element with a tag, with the page fingerprint of the stored page.
	 *
	 * @param int                 $id        Post.
	 * @param array<string,mixed> $element   Element node.
	 * @param string|null         $tag       Anchor tag, or null for no anchor.
	 * @param string              $placement Placement.
	 * @param int                 $nth       Which anchor.
	 * @return array<string,mixed>
	 */
	private function insert_input( int $id, array $element, ?string $tag = 'fusion_builder_container', string $placement = 'after', int $nth = 0 ): array {
		$content = (string) get_post( $id )->post_content;
		$input   = array(
			'post_id'          => $id,
			'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
			'element'          => $element,
		);
		if ( null === $tag ) {
			return $input;
		}
		$addr = $this->addr( $id, $tag, array(), $nth );
		return $input + array(
			'anchor_index'  => $addr['index'],
			'anchor_expect' => $addr['expect'],
			'placement'     => $placement,
		);
	}

	/**
	 * A container with one 1_1 column holding a title and a button.
	 *
	 * @return array<string,mixed>
	 */
	private function container_node(): array {
		return array(
			'tag'      => 'fusion_builder_container',
			'children' => array(
				array(
					'tag'      => 'fusion_builder_row',
					'children' => array(
						array(
							'tag'      => 'fusion_builder_column',
							'atts'     => array( 'type' => '1_1' ),
							'children' => array(
								array(
									'tag'  => 'fusion_title',
									'atts' => array( 'size' => '2' ),
									'text' => 'New title',
								),
								array(
									'tag'  => 'fusion_button',
									'atts' => array( 'link' => 'https://example.com/new' ),
									'text' => 'Go',
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Parent, child and whether Avada allows it.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public function nesting_cases(): array {
		return array(
			'top level takes a container'       => array( '', 'fusion_builder_container', true ),
			'top level refuses a row'           => array( '', 'fusion_builder_row', false ),
			'top level refuses an element'      => array( '', 'fusion_title', false ),
			'container takes a row'             => array( 'fusion_builder_container', 'fusion_builder_row', true ),
			'container refuses a column'        => array( 'fusion_builder_container', 'fusion_builder_column', false ),
			'row takes a column'                => array( 'fusion_builder_row', 'fusion_builder_column', true ),
			'row refuses an inner column'       => array( 'fusion_builder_row', 'fusion_builder_column_inner', false ),
			'row refuses an element'            => array( 'fusion_builder_row', 'fusion_title', false ),
			'column takes an element'           => array( 'fusion_builder_column', 'fusion_title', true ),
			'column takes an inner row'         => array( 'fusion_builder_column', 'fusion_builder_row_inner', true ),
			'column refuses a container'        => array( 'fusion_builder_column', 'fusion_builder_container', false ),
			'column refuses a child element'    => array( 'fusion_builder_column', 'fusion_tab', false ),
			'inner row takes an inner column'   => array( 'fusion_builder_row_inner', 'fusion_builder_column_inner', true ),
			'inner row refuses a column'        => array( 'fusion_builder_row_inner', 'fusion_builder_column', false ),
			'inner column takes an element'     => array( 'fusion_builder_column_inner', 'fusion_button', true ),
			'inner column refuses an inner row' => array( 'fusion_builder_column_inner', 'fusion_builder_row_inner', false ),
			'parent element takes its child'    => array( 'fusion_tabs', 'fusion_tab', true ),
			'parent element refuses another'    => array( 'fusion_tabs', 'fusion_title', false ),
			'leaf element takes nothing'        => array( 'fusion_title', 'fusion_text', false ),
			'unknown parent takes nothing'      => array( 'fusion_unknown_box', 'fusion_title', false ),
		);
	}

	/**
	 * Each nesting rule.
	 *
	 * @dataProvider nesting_cases
	 *
	 * @param string $outer Parent tag.
	 * @param string $child Child tag.
	 * @param bool   $ok    Whether allowed.
	 */
	public function test_nesting_follows_avadas_six_rules( string $outer, string $child, bool $ok ): void {
		$out = aafm_avada_nesting_allows( $outer, $child, AvadaMapStub::map() );
		if ( $ok ) {
			$this->assertTrue( $out );
			return;
		}
		$this->assert_error( $out, 'aafm_builder_invalid_nesting', 400 );
		$this->assertSame( $outer, $out->get_error_data()['parent'] );
		$this->assertSame( $child, $out->get_error_data()['child'] );
	}

	public function test_a_child_element_is_refused_from_its_own_side(): void {
		$map                         = AvadaMapStub::map();
		$map['fusion_tab']['multi']  = 'multi_element_child';
		$map['fusion_tabs']['multi'] = 'none';
		$this->assert_error( aafm_avada_nesting_allows( 'fusion_builder_column', 'fusion_tab', $map ), 'aafm_builder_invalid_nesting', 400 );
	}

	public function test_a_parent_element_takes_only_its_child_tag(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		$out    = aafm_exec_avada_insert_element(
			$this->insert_input(
				$id,
				array(
					'tag'  => 'fusion_title',
					'text' => 'Not a tab',
				),
				'fusion_tabs',
				'inside_end'
			)
		);
		$this->assert_refused( $out, 'aafm_builder_invalid_nesting', 400, $id, $before );
		$ok = aafm_exec_avada_insert_element(
			$this->insert_input(
				$id,
				array(
					'tag'  => 'fusion_tab',
					'atts' => array( 'title' => 'Third' ),
					'text' => 'Third tab',
				),
				'fusion_tabs',
				'inside_end'
			)
		);
		$this->assertIsArray( $ok, is_wp_error( $ok ) ? $ok->get_error_message() : '' );
		$this->assertStringContainsString( '[fusion_tab title="Third"]Third tab[/fusion_tab][/fusion_tabs]', get_post( $id )->post_content );
	}

	public function test_a_column_needs_a_type_from_the_maps_list(): void {
		$this->act_as_admin();
		foreach ( array( null, '0', '3_2', 'auto', '150%', 'wide' ) as $width ) {
			$id     = $this->avada_post();
			$before = (string) get_post( $id )->post_content;
			$node   = array(
				'tag'  => 'fusion_builder_column',
				'atts' => null === $width ? array() : array( 'type' => $width ),
			);
			$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $node, 'fusion_builder_column', 'after' ) ), 'aafm_avada_column_needs_width', 400, $id, $before );
		}
		foreach ( array( '1_2', '25%', '33.33%' ) as $width ) {
			$this->assertSame( $width, aafm_avada_column_width( $width ) );
		}
	}

	public function test_inserted_elements_carry_every_map_setting_by_default(): void {
		$this->act_as_admin();
		$id  = $this->avada_post();
		$out = aafm_exec_avada_insert_element( $this->insert_input( $id, $this->container_node() ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( 5, $out['inserted'] );
		$stored = (string) get_post( $id )->post_content;
		$this->assertStringContainsString(
			'[fusion_builder_container type="flex" padding_top="" padding_right="" padding_bottom="" padding_left="" background_color="" publish_date=""][fusion_builder_row]'
			. '[fusion_builder_column type="1_1" layout="1_1" link="" first="true" last="true" border_position="all"]'
			. '[fusion_title size="2" fusion_font_family_title_font="" text_color=""]New title[/fusion_title]',
			$stored
		);
		$this->assertMatchesRegularExpression( '/\[fusion_button link="https:\/\/example\.com\/new" title="" target="_self" [^\]]*hide_on_mobile="small-visibility,medium-visibility,large-visibility"[^\]]*\]Go\[\/fusion_button\]/', $stored );
		$elements = $this->elements( $stored );
		$this->assertSame( 'fusion_builder_container', $elements[ $out['index'] ]['tag'] );
		$this->assertSame( aafm_builder_fingerprint( $stored, $elements[ $out['index'] ] ), $out['fingerprint'] );
		$this->assertSame( aafm_builder_page_fingerprint( $stored ), $out['page_fingerprint'] );
		$this->assertArrayNotHasKey( 'post_content', $out['post'] );
	}

	public function test_inserted_elements_drop_empty_settings_when_the_site_removes_them(): void {
		$this->act_as_admin();
		update_option( 'fusion_builder_settings', array( 'remove_empty_attributes' => 'on' ) );
		$id  = $this->avada_post();
		$out = aafm_exec_avada_insert_element( $this->insert_input( $id, $this->container_node() ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$stored = (string) get_post( $id )->post_content;
		$this->assertStringContainsString( '[fusion_builder_container type="flex"][fusion_builder_row][fusion_builder_column type="1_1" layout="1_1" first="true" last="true" border_position="all"][fusion_title size="2"]New title[/fusion_title]', $stored );
	}

	public function test_column_flags_follow_the_editors_running_width(): void {
		$this->assertSame(
			array(
				array(
					'first' => 'true',
					'last'  => 'false',
				),
				array(
					'first' => 'false',
					'last'  => 'true',
				),
				array(
					'first' => 'true',
					'last'  => 'true',
				),
			),
			aafm_avada_column_flags(
				array(
					array(
						'tag'   => 'fusion_builder_row',
						'width' => '',
					),
					array(
						'tag'   => 'fusion_builder_column',
						'width' => '1_2',
					),
					array(
						'tag'   => 'fusion_builder_column',
						'width' => '50%',
					),
					array(
						'tag'   => 'fusion_builder_row',
						'width' => '',
					),
					array(
						'tag'   => 'fusion_builder_column',
						'width' => '1_1',
					),
				)
			)
		);
	}

	public function test_the_void_form_is_used_only_where_the_editor_uses_it(): void {
		$this->act_as_admin();
		AvadaMapStub::install( $this->insert_map() );
		add_shortcode( 'awb_dd', '__return_empty_string' );
		$id    = $this->avada_post();
		$nodes = array(
			array( 'tag' => 'fusion_button' ),
			array( 'tag' => 'fusion_text' ),
			array( 'tag' => 'fusion_code' ),
			array( 'tag' => 'awb_dd' ),
			array(
				'tag'  => 'fusion_title',
				'text' => 'T',
			),
		);
		foreach ( $nodes as $node ) {
			$out = aafm_exec_avada_insert_element( $this->insert_input( $id, $node, 'fusion_title', 'after' ) );
			$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : $node['tag'] );
		}
		remove_shortcode( 'awb_dd' );
		$stored = (string) get_post( $id )->post_content;
		$this->assertMatchesRegularExpression( '/\[fusion_button [^\]]*\/\]/', $stored );
		$this->assertStringContainsString( '[fusion_text columns="1"][/fusion_text]', $stored );
		$this->assertStringContainsString( '[fusion_code][/fusion_code]', $stored );
		$this->assertStringContainsString( '[awb_dd class="" /]', $stored );
		$this->assertStringContainsString( 'fusion_title size="1" fusion_font_family_title_font="" text_color=""]T[/fusion_title]', $stored );
	}

	public function test_a_row_and_an_inner_row_carry_only_what_the_editor_writes(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		$node   = array(
			'tag'      => 'fusion_builder_row_inner',
			'children' => array(
				array(
					'tag'      => 'fusion_builder_column_inner',
					'atts'     => array( 'type' => '1_2' ),
					'children' => array(
						array(
							'tag'  => 'fusion_title',
							'text' => 'Nested',
						),
					),
				),
			),
		);
		$out    = aafm_exec_avada_insert_element( $this->insert_input( $id, $node, 'fusion_title', 'after' ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertStringContainsString( '[fusion_builder_row_inner][fusion_builder_column_inner type="1_2" layout="1_2" first="true" last="true" border_position="all"][fusion_title', get_post( $id )->post_content );
		$id2 = $this->avada_post();
		$row = array(
			'tag'  => 'fusion_builder_row',
			'atts' => array( 'class' => 'x' ),
		);
		$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id2, $row, 'fusion_builder_row', 'after' ) ), 'aafm_builder_attribute_not_writable', 400, $id2, $before );
	}

	public function test_an_unmapped_tag_is_refused_before_nesting(): void {
		$this->act_as_admin();
		foreach ( array( 'fusion_global', 'fusion_unknown_box' ) as $tag ) {
			$id     = $this->avada_post();
			$before = (string) get_post( $id )->post_content;
			$node   = array(
				'tag'  => $tag,
				'atts' => array( 'id' => '14' ),
			);
			// At the top level, where nesting would refuse anything but a container, the map refusal comes first.
			$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $node ) ), 'aafm_builder_unknown_element', 400, $id, $before );
		}
	}

	public function test_a_mapped_tag_no_shortcode_renders_is_refused(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		// fusion_google_map is in the stub map, but no shortcode is registered for it in this request.
		$node = array( 'tag' => 'fusion_google_map' );
		$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $node, 'fusion_title', 'after' ) ), 'aafm_builder_unknown_element', 400, $id, $before );
	}

	public function test_an_insert_inside_a_global_element_is_refused(): void {
		$this->act_as_admin();
		$content = '[fusion_builder_container fusion_global="14"][fusion_builder_row][fusion_builder_column type="1_1" layout="1_1"][fusion_title size="2"]G[/fusion_title][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		$id      = $this->avada_post( $content );
		$node    = array(
			'tag'  => 'fusion_title',
			'text' => 'X',
		);
		$out     = aafm_exec_avada_insert_element( $this->insert_input( $id, $node, 'fusion_title', 'after' ) );
		$this->assert_refused( $out, 'aafm_avada_global_element', 409, $id, $content );
		$this->assertSame( 14, $out->get_error_data()['item_id'] );
		// Beside the global container is fine.
		$this->assertIsArray( aafm_exec_avada_insert_element( $this->insert_input( $id, $this->container_node() ) ) );
	}

	public function test_insert_limits(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		$leaf   = array(
			'tag'  => 'fusion_title',
			'text' => 'x',
		);
		$many   = array(
			'tag'      => 'fusion_builder_column',
			'atts'     => array( 'type' => '1_1' ),
			'children' => array_fill( 0, 50, $leaf ),
		);
		$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $many, 'fusion_builder_column', 'after' ) ), 'aafm_builder_insert_limit', 400, $id, $before );
		$deep = $leaf;
		for ( $i = 0; $i < 6; $i++ ) {
			$deep = array(
				'tag'      => 'fusion_tabs',
				'children' => array( $deep ),
			);
		}
		$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $deep, 'fusion_title', 'after' ) ), 'aafm_builder_insert_limit', 400, $id, $before );
	}

	public function test_an_editor_cannot_insert_a_code_block_or_an_onclick(): void {
		$this->act_as_restricted_editor();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		$code   = array(
			'tag'  => 'fusion_code',
			'text' => '<script>alert(1)</script>',
		);
		$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $code, 'fusion_title', 'after' ) ), 'aafm_builder_needs_unfiltered_html', 403, $id, $before );
		$button = array(
			'tag'  => 'fusion_button',
			'atts' => array( 'link_attributes' => "onclick='x()'" ),
		);
		$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $button, 'fusion_title', 'after' ) ), 'aafm_builder_needs_unfiltered_html', 403, $id, $before );
	}

	/**
	 * Body text and the refusal it gets.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function body_text_classes(): array {
		return array(
			'bracket'         => array( 'a [b] c', 'aafm_avada_brackets_need_unfiltered_html' ),
			'bracket entity'  => array( 'a &#91;b&#93; c', 'aafm_avada_brackets_need_unfiltered_html' ),
			'encoded bracket' => array( 'a %5Bb%5D c', 'aafm_avada_encoded_brackets_need_unfiltered_html' ),
			'data attribute'  => array( '<span data-x="1">a</span>', 'aafm_avada_data_attributes_need_unfiltered_html' ),
			'unfinished tag'  => array( 'a <span class="x', 'aafm_avada_incomplete_html_needs_unfiltered_html' ),
		);
	}

	/**
	 * Each body-text class through insert.
	 *
	 * @dataProvider body_text_classes
	 *
	 * @param string $text Text.
	 * @param string $code Refusal.
	 */
	public function test_body_text_classes_are_refused_through_insert_for_a_restricted_editor( string $text, string $code ): void {
		$this->act_as_restricted_editor();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		$node   = array(
			'tag'  => 'fusion_text',
			'text' => $text,
		);
		$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $node, 'fusion_title', 'after' ) ), $code, 400, $id, $before );
	}

	public function test_an_administrator_inserts_brackets_in_body_text_and_a_raw_setting(): void {
		$this->act_as_admin();
		$id   = $this->avada_post();
		$node = array(
			'tag'  => 'fusion_button',
			'atts' => array( 'link_attributes' => "data-x='1'" ),
			'text' => 'a %5Bb%5D c',
		);
		$out  = aafm_exec_avada_insert_element( $this->insert_input( $id, $node, 'fusion_title', 'after' ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertStringContainsString( "link_attributes=\"data-x='1'\"", get_post( $id )->post_content );
	}

	public function test_a_double_quote_in_an_inserted_attribute_is_refused(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		foreach ( array( 'Say "hi"', 'Say &quot;hi&quot;', 'a [b]' ) as $title ) {
			$node = array(
				'tag'  => 'fusion_button',
				'atts' => array( 'title' => $title ),
			);
			$out  = aafm_exec_avada_insert_element( $this->insert_input( $id, $node, 'fusion_title', 'after' ) );
			$this->assert_refused( $out, 'aafm_builder_invalid_value', 400, $id, $before );
			$this->assertSame( 'title', $out->get_error_data()['setting'] );
		}
	}

	public function test_dynamic_params_and_unknown_settings_are_refused_on_insert(): void {
		$this->act_as_admin();
		$id     = $this->avada_post();
		$before = (string) get_post( $id )->post_content;
		foreach ( array( 'dynamic_params', 'no_such_setting', 'title_typography' ) as $name ) {
			$node = array(
				'tag'  => 'fusion_title',
				'atts' => array( $name => 'x' ),
			);
			$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $node, 'fusion_title', 'after' ) ), 'aafm_builder_attribute_not_writable', 400, $id, $before );
		}
	}

	public function test_empty_post_insert_sets_the_builder_status_meta_after_the_content_is_saved(): void {
		$this->act_as_admin();
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '',
			)
		);
		$this->assertSame( '', get_post_meta( $id, 'fusion_builder_status', true ) );
		$order = array();
		$watch = static function ( $check, $object_id, $key ) use ( &$order, $id ) {
			if ( $object_id === $id && 'fusion_builder_status' === $key ) {
				$order[] = '' === (string) get_post( $id )->post_content ? 'marker before content' : 'marker after content';
			}
			return $check;
		};
		add_filter( 'update_post_metadata', $watch, 10, 3 );
		add_filter( 'add_post_metadata', $watch, 10, 3 );
		$out = aafm_exec_avada_insert_element( $this->insert_input( $id, $this->container_node(), null ) );
		remove_filter( 'update_post_metadata', $watch, 10 );
		remove_filter( 'add_post_metadata', $watch, 10 );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( 0, $out['index'] );
		$this->assertSame( 'active', get_post_meta( $id, 'fusion_builder_status', true ) );
		$this->assertSame( array( 'marker after content' ), array_values( array_unique( $order ) ) );
		$this->assertSame( 'avada', aafm_post_has_foreign_builder_ownership( $id ) );

		// An anchor is needed once the post has content.
		$before = (string) get_post( $id )->post_content;
		$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $this->container_node(), null ) ), 'aafm_avada_anchor_required', 400, $id, $before );
	}

	public function test_a_failed_marker_write_is_reported_with_the_saved_content_and_revision(): void {
		$this->act_as_admin();
		$id   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '',
			)
		);
		$veto = static fn( $check, $object_id, $key ) => 'fusion_builder_status' === $key ? false : $check;
		add_filter( 'update_post_metadata', $veto, 10, 3 );
		add_filter( 'add_post_metadata', $veto, 10, 3 );
		$out = aafm_exec_avada_insert_element( $this->insert_input( $id, $this->container_node(), null ) );
		remove_filter( 'update_post_metadata', $veto, 10 );
		remove_filter( 'add_post_metadata', $veto, 10 );
		$this->assert_error( $out, 'aafm_avada_marker_unconfirmed', 500 );
		$stored = (string) get_post( $id )->post_content;
		$this->assertStringStartsWith( '[fusion_builder_container', $stored );
		$this->assertSame( aafm_builder_page_fingerprint( $stored ), $out->get_error_data()['page_fingerprint'] );
		$this->assertArrayHasKey( 'restore_revision_id', $out->get_error_data() );
		$this->assertSame( $id, $out->get_error_data()['post_id'] );
	}

	public function test_a_retried_insert_is_refused_and_stores_one_element(): void {
		$this->act_as_admin();
		$id    = $this->avada_post();
		$input = $this->insert_input( $id, $this->container_node() );
		$this->assertIsArray( aafm_exec_avada_insert_element( $input ) );
		$after = (string) get_post( $id )->post_content;
		$this->assert_refused( aafm_exec_avada_insert_element( $input ), 'aafm_builder_page_changed', 409, $id, $after );
		$this->assertSame( 1, substr_count( $after, 'New title' ) );
	}

	public function test_chained_inserts_work_with_the_returned_page_fingerprint(): void {
		$this->act_as_admin();
		$id    = $this->avada_post();
		$first = aafm_exec_avada_insert_element( $this->insert_input( $id, $this->container_node() ) );
		$this->assertIsArray( $first );
		$input  = array(
			'post_id'          => $id,
			'page_fingerprint' => $first['page_fingerprint'],
			'anchor_index'     => $first['index'],
			'anchor_expect'    => array(
				'tag'         => 'fusion_builder_container',
				'fingerprint' => $first['fingerprint'],
			),
			'placement'        => 'after',
			'element'          => $this->container_node(),
		);
		$second = aafm_exec_avada_insert_element( $input );
		$this->assertIsArray( $second, is_wp_error( $second ) ? $second->get_error_message() : '' );
		$this->assertSame( $first['index'] + 5, $second['index'] );
		$this->assertSame( 2, substr_count( (string) get_post( $id )->post_content, 'New title' ) );
	}

	public function test_the_trusted_path_gates_script_and_body_text_for_a_restricted_editor(): void {
		$cases = array(
			'[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_code]eA==[/fusion_code][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' => 'aafm_builder_needs_unfiltered_html',
			'[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_button link_attributes="onclick=x"]B[/fusion_button][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' => 'aafm_builder_needs_unfiltered_html',
			'[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_text]a %5Bb%5D[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' => 'aafm_avada_encoded_brackets_need_unfiltered_html',
			'[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_text]<p onmouseover="x">a</p>[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' => 'aafm_avada_stored_text_needs_unfiltered_html',
		);
		foreach ( $cases as $source => $code ) {
			$this->act_as_admin();
			$id     = $this->avada_post();
			$before = (string) get_post( $id )->post_content;
			$tree   = aafm_builder_tree( $source, aafm_fusion_shortcode_tags() );
			$addr   = $this->addr( $id, 'fusion_builder_container' );
			$anchor = array(
				'index'     => $addr['index'],
				'expect'    => $addr['expect'],
				'placement' => 'after',
			);
			$this->act_as_restricted_editor();
			$out = aafm_avada_insert_tree( get_post( $id ), (array) $tree, $anchor, true );
			$this->assertInstanceOf( WP_Error::class, $out, $code );
			$this->assertSame( $code, $out->get_error_code() );
			$this->assertSame( $before, get_post( $id )->post_content );
			$this->act_as_admin();
			$ok = aafm_avada_insert_tree( get_post( $id ), (array) $tree, $anchor, true );
			$this->assertIsArray( $ok, is_wp_error( $ok ) ? $ok->get_error_message() : $code );
			$this->assertStringContainsString( $source, get_post( $id )->post_content, 'Spliced byte for byte.' );
			$this->assertInstanceOf( \WP_Post::class, $ok['post'], 'The path returns the post; every executor redacts it.' );
		}
	}

	public function test_a_lost_restore_point_on_insert_is_reported_with_reversible_reason(): void {
		$this->act_as_admin();
		$id   = $this->avada_post();
		$keep = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $keep );
		$out  = aafm_exec_avada_insert_element( $this->insert_input( $id, $this->container_node() ) );
		remove_filter( 'wp_revisions_to_keep', $keep );
		$this->assertIsArray( $out );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertFalse( $out['reversible'] );
		$this->assertStringContainsString( 'keeps 1 revision per post', $out['reversible_reason'] );
	}

	public function test_insert_permissions_discovery_audit_and_schema(): void {
		$args = aafm_args_avada_insert_element();
		$this->assertSame( 'aafm_perm_avada_post_object', $args['permission_callback'] );
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'post_id', 'page_fingerprint', 'element' ), $args['input_schema']['required'] );
		$this->assertSame( 'string', $args['output_schema']['properties']['reversible_reason']['type'] );
		$this->assertFalse( $args['meta']['annotations']['destructive'] );
		$this->assertSame( 'Ran an element insert on Avada page #%s', aafm_activity_detail_map()['aafm/avada-insert-element']['template'] );
		$this->assertArrayHasKey( 'aafm/avada-insert-element', aafm_register_avada_elements_definitions( array() ) );
		$this->act_as_admin();
		$id = self::factory()->post->create( array( 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		$this->assert_refused( aafm_exec_avada_insert_element( $this->insert_input( $id, $this->container_node(), null ) ), 'aafm_not_avada_owned', 409, $id, '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
	}

	// S10b: avada-move-element.

	/**
	 * Move input for the nth element with a tag against the nth anchor with a tag.
	 *
	 * @param int    $id         Post.
	 * @param string $tag        Moved tag.
	 * @param int    $nth        Which one.
	 * @param string $anchor_tag Anchor tag.
	 * @param int    $anchor_nth Which anchor.
	 * @param string $placement  Placement.
	 * @return array<string,mixed>
	 */
	private function move_input( int $id, string $tag, int $nth, string $anchor_tag, int $anchor_nth, string $placement ): array {
		$addr   = $this->addr( $id, $tag, array(), $nth );
		$anchor = $this->addr( $id, $anchor_tag, array(), $anchor_nth );
		return $addr + array(
			'page_fingerprint' => aafm_builder_page_fingerprint( (string) get_post( $id )->post_content ),
			'anchor_index'     => $anchor['index'],
			'anchor_expect'    => $anchor['expect'],
			'placement'        => $placement,
		);
	}

	/**
	 * Two containers, each with one 1_1 column holding a title.
	 *
	 * @return string
	 */
	private function two_containers(): string {
		$one = static fn( string $t ) => '[fusion_builder_container type="flex"][fusion_builder_row][fusion_builder_column type="1_1" layout="1_1"][fusion_title size="2"]' . $t . '[/fusion_title][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		return $one( 'A' ) . $one( 'B' );
	}

	public function test_move_reorders_containers_byte_for_byte(): void {
		$this->act_as_admin();
		$content = $this->two_containers();
		$id      = $this->avada_post( $content );
		$out     = aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_builder_container', 1, 'fusion_builder_container', 0, 'before' ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertTrue( $out['moved'] );
		$this->assertSame( 0, $out['index'] );
		$stored = (string) get_post( $id )->post_content;
		$half   = strlen( $content ) / 2;
		$this->assertSame( substr( $content, $half ) . substr( $content, 0, $half ), $stored );
		$this->assertSame( aafm_builder_page_fingerprint( $stored ), $out['page_fingerprint'] );
		$this->assertSame( aafm_builder_fingerprint( $stored, $this->elements( $stored )[0] ), $out['fingerprint'] );
		$this->assertArrayNotHasKey( 'post_content', $out['post'] );
	}

	public function test_move_onto_its_own_place_saves_nothing(): void {
		$this->act_as_admin();
		$content = $this->two_containers();
		$id      = $this->avada_post( $content );
		$out     = aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_builder_container', 0, 'fusion_builder_container', 1, 'before' ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertFalse( $out['moved'] );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertSame( $content, get_post( $id )->post_content );
	}

	public function test_move_into_itself_and_onto_itself_is_refused(): void {
		$this->act_as_admin();
		$content = $this->two_containers();
		$id      = $this->avada_post( $content );
		$this->assert_refused( aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_builder_container', 0, 'fusion_builder_column', 0, 'inside_end' ) ), 'aafm_builder_invalid_move', 400, $id, $content );
		$this->assert_refused( aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_builder_container', 0, 'fusion_builder_container', 0, 'inside_end' ) ), 'aafm_builder_invalid_move', 400, $id, $content );
	}

	public function test_move_between_columns_keeps_siblings_and_follows_nesting(): void {
		$this->act_as_admin();
		$content = $this->two_containers();
		$id      = $this->avada_post( $content );
		// The title of the first column into the second column, at its start: first and last child cases.
		$out = aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_title', 0, 'fusion_builder_column', 1, 'inside_start' ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$stored = (string) get_post( $id )->post_content;
		$this->assertSame( 1, substr_count( $stored, ']A[' ) );
		$this->assertSame( 1, substr_count( $stored, ']B[' ) );
		$this->assertStringContainsString( '[fusion_title size="2"]A[/fusion_title][fusion_title size="2"]B[/fusion_title]', $stored );
		$this->assertStringContainsString( '[fusion_builder_column type="1_1" layout="1_1"][/fusion_builder_column]', $stored, 'The emptied column stays.' );
		// A title cannot go into a row, and a row cannot go to the top level.
		$this->assert_refused( aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_title', 0, 'fusion_builder_row', 0, 'inside_end' ) ), 'aafm_builder_invalid_nesting', 400, $id, $stored );
		$this->assert_refused( aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_builder_row', 0, 'fusion_builder_container', 1, 'after' ) ), 'aafm_builder_invalid_nesting', 400, $id, $stored );
	}

	public function test_move_that_empties_a_row_or_a_container_is_refused(): void {
		$this->act_as_admin();
		$content = $this->two_containers();
		$id      = $this->avada_post( $content );
		$this->assert_refused( aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_builder_column', 0, 'fusion_builder_column', 1, 'after' ) ), 'aafm_avada_layout_needs_child', 400, $id, $content );
		$this->assert_refused( aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_builder_row', 0, 'fusion_builder_row', 1, 'after' ) ), 'aafm_avada_layout_needs_child', 400, $id, $content );
	}

	public function test_same_parent_reorder_needs_no_nesting_but_an_unmapped_tag_is_refused(): void {
		$this->act_as_admin();
		$content = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_title size="2"]A[/fusion_title][fusion_unknown_box]x[/fusion_unknown_box][fusion_title size="2"]C[/fusion_title][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		// A registered fusion_ shortcode the map does not list: the outline reads it, the move refuses it.
		add_shortcode( 'fusion_unknown_box', '__return_empty_string' );
		$id = $this->avada_post( $content );
		$this->assert_refused( aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_unknown_box', 0, 'fusion_title', 1, 'after' ) ), 'aafm_builder_unknown_element', 400, $id, $content );
		remove_shortcode( 'fusion_unknown_box' );
		// The last title to the front of its own column: same parent, so no nesting check runs.
		$out = aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_title', 1, 'fusion_title', 0, 'before' ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertStringStartsWith( '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_title size="2"]C[/fusion_title][fusion_title size="2"]A', get_post( $id )->post_content );
	}

	public function test_move_out_of_or_into_a_global_element_is_refused(): void {
		$this->act_as_admin();
		$content = str_replace( '[fusion_builder_container type="flex"]', '[fusion_builder_container type="flex" fusion_global="14"]', substr( $this->two_containers(), 0, strlen( $this->two_containers() ) / 2 ) ) . substr( $this->two_containers(), strlen( $this->two_containers() ) / 2 );
		$id      = $this->avada_post( $content );
		$this->assert_refused( aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_title', 0, 'fusion_title', 1, 'after' ) ), 'aafm_avada_global_element', 409, $id, $content );
		$this->assert_refused( aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_title', 1, 'fusion_title', 0, 'after' ) ), 'aafm_avada_global_element', 409, $id, $content );
		// The global container itself moves.
		$this->assertIsArray( aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_builder_container', 0, 'fusion_builder_container', 1, 'after' ) ) );
	}

	public function test_move_and_remove_refuse_a_stale_page_fingerprint(): void {
		$this->act_as_admin();
		$content                   = $this->two_containers();
		$id                        = $this->avada_post( $content );
		$input                     = $this->move_input( $id, 'fusion_builder_container', 1, 'fusion_builder_container', 0, 'before' );
		$input['page_fingerprint'] = str_repeat( '0', 16 );
		$this->assert_refused( aafm_exec_avada_move_element( $input ), 'aafm_builder_page_changed', 409, $id, $content );
		if ( function_exists( 'aafm_exec_avada_remove_element' ) ) {
			$remove = $this->addr( $id, 'fusion_builder_container', array( 'page_fingerprint' => str_repeat( '0', 16 ) ), 1 );
			$this->assert_refused( aafm_exec_avada_remove_element( $remove ), 'aafm_builder_page_changed', 409, $id, $content );
		}
	}

	public function test_chained_moves_keep_addressing_valid(): void {
		$this->act_as_admin();
		$id    = $this->avada_post( $this->two_containers() );
		$first = aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_builder_container', 1, 'fusion_builder_container', 0, 'before' ) );
		$this->assertIsArray( $first );
		$anchor = $this->addr( $id, 'fusion_builder_container', array(), 1 );
		$back   = aafm_exec_avada_move_element(
			array(
				'post_id'          => $id,
				'page_fingerprint' => $first['page_fingerprint'],
				'index'            => $first['index'],
				'expect'           => array(
					'tag'         => 'fusion_builder_container',
					'fingerprint' => $first['fingerprint'],
				),
				'anchor_index'     => $anchor['index'],
				'anchor_expect'    => $anchor['expect'],
				'placement'        => 'after',
			)
		);
		$this->assertIsArray( $back, is_wp_error( $back ) ? $back->get_error_message() : '' );
		$this->assertSame( $this->two_containers(), get_post( $id )->post_content );
	}

	public function test_move_permissions_discovery_audit_and_schema(): void {
		$args = aafm_args_avada_move_element();
		$this->assertSame( 'aafm_perm_avada_post_object', $args['permission_callback'] );
		$this->assertSame( array( 'post_id', 'page_fingerprint', 'index', 'expect', 'anchor_index', 'anchor_expect', 'placement' ), $args['input_schema']['required'] );
		$this->assertSame( 'string', $args['output_schema']['properties']['reversible_reason']['type'] );
		$this->assertArrayHasKey( 'moved', $args['output_schema']['properties'] );
		$this->assertSame( 'Ran an element move on Avada page #%s', aafm_activity_detail_map()['aafm/avada-move-element']['template'] );
		$this->assertArrayHasKey( 'aafm/avada-move-element', aafm_register_avada_elements_definitions( array() ) );
		$keep = static fn(): int => 1;
		$this->act_as_admin();
		$id   = $this->avada_post( $this->two_containers() );
		add_filter( 'wp_revisions_to_keep', $keep );
		$out = aafm_exec_avada_move_element( $this->move_input( $id, 'fusion_builder_container', 1, 'fusion_builder_container', 0, 'before' ) );
		remove_filter( 'wp_revisions_to_keep', $keep );
		$this->assertIsArray( $out );
		$this->assertFalse( $out['reversible'] );
		$this->assertStringContainsString( 'keeps 1 revision per post', $out['reversible_reason'] );
	}

	// S10b: avada-remove-element.

	/**
	 * Remove input for the nth element with a tag.
	 *
	 * @param int    $id  Post.
	 * @param string $tag Tag.
	 * @param int    $nth Which one.
	 * @return array<string,mixed>
	 */
	private function remove_input( int $id, string $tag, int $nth = 0 ): array {
		return $this->addr( $id, $tag, array( 'page_fingerprint' => aafm_builder_page_fingerprint( (string) get_post( $id )->post_content ) ), $nth );
	}

	public function test_remove_works_on_a_section_with_revisions(): void {
		$this->act_as_admin();
		$content = $this->two_containers();
		$id      = $this->avada_post( $content );
		wp_save_post_revision( $id );
		$out = aafm_exec_avada_remove_element( $this->remove_input( $id, 'fusion_builder_container', 0 ) );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( 4, $out['removed'] );
		$stored = (string) get_post( $id )->post_content;
		$this->assertSame( substr( $content, strlen( $content ) / 2 ), $stored );
		$this->assertSame( aafm_builder_page_fingerprint( $stored ), $out['page_fingerprint'] );
		$this->assertTrue( $out['reversible'] );
		$this->assertSame( $content, get_post( $out['restore_revision_id'] )->post_content, 'The restore point holds the page before the removal.' );
		$this->assertArrayNotHasKey( 'post_content', $out['post'] );
	}

	public function test_remove_keeps_siblings_for_first_and_last_children(): void {
		$this->act_as_admin();
		$content = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_title size="2"]A[/fusion_title][fusion_title size="2"]B[/fusion_title][fusion_title size="2"]C[/fusion_title][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';
		$id      = $this->avada_post( $content );
		$this->assertIsArray( aafm_exec_avada_remove_element( $this->remove_input( $id, 'fusion_title', 2 ) ) );
		$this->assertIsArray( aafm_exec_avada_remove_element( $this->remove_input( $id, 'fusion_title', 0 ) ) );
		$this->assertSame( '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_title size="2"]B[/fusion_title][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]', get_post( $id )->post_content );
	}

	public function test_remove_the_last_root_container_is_refused(): void {
		$this->act_as_admin();
		$content = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_title size="2"]A[/fusion_title][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]<p>after</p>';
		$id      = $this->avada_post( $content );
		$this->assert_refused( aafm_exec_avada_remove_element( $this->remove_input( $id, 'fusion_builder_container' ) ), 'aafm_builder_remove_last_root', 409, $id, $content );
	}

	public function test_remove_that_empties_a_column_row_is_refused(): void {
		$this->act_as_admin();
		$content = $this->two_containers();
		$id      = $this->avada_post( $content );
		$this->assert_refused( aafm_exec_avada_remove_element( $this->remove_input( $id, 'fusion_builder_column', 0 ) ), 'aafm_avada_layout_needs_child', 400, $id, $content );
		$this->assert_refused( aafm_exec_avada_remove_element( $this->remove_input( $id, 'fusion_builder_row', 0 ) ), 'aafm_avada_layout_needs_child', 400, $id, $content );
	}

	public function test_remove_is_refused_on_a_form_and_an_off_canvas_panel(): void {
		$this->act_as_admin();
		foreach ( array( 'fusion_form', 'awb_off_canvas' ) as $type ) {
			// A8: neither type keeps revisions.
			register_post_type(
				$type,
				array(
					'public'          => false,
					'capability_type' => 'post',
					'map_meta_cap'    => true,
					'supports'        => array( 'title', 'editor' ),
				)
			);
			$content = $this->two_containers();
			$id      = $this->avada_post( $content, $type );
			delete_post_meta( $id, 'fusion_builder_status' );
			$this->assert_refused( aafm_exec_avada_remove_element( $this->remove_input( $id, 'fusion_title', 0 ) ), 'aafm_builder_remove_irreversible', 409, $id, $content );
			unregister_post_type( $type );
		}
	}

	public function test_remove_is_refused_when_the_site_keeps_one_revision_or_none(): void {
		$this->act_as_admin();
		$content = $this->two_containers();
		$id      = $this->avada_post( $content );
		foreach ( array( 1, 0 ) as $limit ) {
			$keep = static fn(): int => $limit;
			add_filter( 'wp_revisions_to_keep', $keep );
			$out  = aafm_exec_avada_remove_element( $this->remove_input( $id, 'fusion_title', 0 ) );
			remove_filter( 'wp_revisions_to_keep', $keep );
			$this->assert_refused( $out, 'aafm_builder_remove_irreversible', 409, $id, $content );
			if ( 1 === $limit ) {
				$this->assertStringContainsString( 'keeps only 1 revision', $out->get_error_message() );
			}
		}
	}

	public function test_remove_inside_a_global_element_is_refused_but_the_global_itself_goes(): void {
		$this->act_as_admin();
		$half    = strlen( $this->two_containers() ) / 2;
		$content = str_replace( '[fusion_builder_container type="flex"]', '[fusion_builder_container type="flex" fusion_global="14"]', substr( $this->two_containers(), 0, $half ) ) . substr( $this->two_containers(), $half );
		$id      = $this->avada_post( $content );
		$this->assert_refused( aafm_exec_avada_remove_element( $this->remove_input( $id, 'fusion_title', 0 ) ), 'aafm_avada_global_element', 409, $id, $content );
		$this->assertIsArray( aafm_exec_avada_remove_element( $this->remove_input( $id, 'fusion_builder_container', 0 ) ) );
	}

	public function test_a_retried_remove_is_refused(): void {
		$this->act_as_admin();
		$id    = $this->avada_post( $this->two_containers() );
		$input = $this->remove_input( $id, 'fusion_builder_container', 1 );
		$this->assertIsArray( aafm_exec_avada_remove_element( $input ) );
		$after = (string) get_post( $id )->post_content;
		$this->assert_refused( aafm_exec_avada_remove_element( $input ), 'aafm_builder_page_changed', 409, $id, $after );
	}

	public function test_remove_permissions_discovery_audit_and_schema(): void {
		$args = aafm_args_avada_remove_element();
		$this->assertSame( 'aafm_perm_avada_post_object', $args['permission_callback'] );
		$this->assertSame( array( 'post_id', 'page_fingerprint', 'index', 'expect' ), $args['input_schema']['required'] );
		$this->assertTrue( $args['meta']['annotations']['destructive'] );
		$this->assertSame( 'string', $args['output_schema']['properties']['reversible_reason']['type'] );
		$this->assertSame( 'Ran an element removal on Avada page #%s', aafm_activity_detail_map()['aafm/avada-remove-element']['template'] );
		$this->assertContains( 'aafm/avada-remove-element', aafm_recoverable_delete_abilities() );
		$defs = aafm_register_avada_elements_definitions( array() );
		$this->assertSame( 'destructive', $defs['aafm/avada-remove-element']['risk'] );
	}

	public function test_output_schemas_of_the_three_structural_writes_declare_reversible_reason(): void {
		foreach ( array( 'aafm_args_avada_insert_element', 'aafm_args_avada_move_element', 'aafm_args_avada_remove_element' ) as $builder ) {
			$schema = $builder()['output_schema'];
			$this->assertSame( 'string', $schema['properties']['reversible_reason']['type'], $builder );
			$this->assertSame( array( 'integer', 'null' ), $schema['properties']['restore_revision_id']['type'], $builder );
			$this->assertSame( 'boolean', $schema['properties']['reversible']['type'], $builder );
		}
	}
}
