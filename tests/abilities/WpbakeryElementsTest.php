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

	public function test_list_rows_have_the_seven_keys(): void {
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_list_elements( array( 'per_page' => 50 ) );

		$this->assertIsArray( $out );
		$this->assertSame( array( 'elements', 'total', 'skipped', 'map_source' ), array_keys( $out ) );
		$this->assertSame( 'wpbakery', $out['map_source'] );
		$this->assertSame( count( WpbakeryMapStub::map() ), $out['total'] );
		foreach ( $out['elements'] as $row ) {
			$this->assertSame( array( 'tag', 'name', 'category', 'is_container', 'allowed_children', 'allowed_parents', 'deprecated' ), array_keys( $row ) );
		}
		$by_tag = array_column( $out['elements'], null, 'tag' );
		$this->assertTrue( $by_tag['vc_row']['is_container'] );
		$this->assertFalse( $by_tag['wpb_btn']['is_container'] );
		$this->assertSame( array( 'vc_row' ), $by_tag['vc_column']['allowed_parents']['only'] );
		$this->assertTrue( $by_tag['vc_btn']['deprecated'] );
		$this->assertSame( 'Content', $by_tag['vc_row']['category'] );
	}

	public function test_tag_detail_lists_settings_with_kind_writable_and_needs(): void {
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_list_elements( array( 'tag' => 'wpb_btn' ) );

		$this->assertIsArray( $out );
		$this->assertCount( 1, $out['elements'] );
		$settings = array_column( $out['elements'][0]['settings'], null, 'name' );
		foreach ( $settings as $setting ) {
			$this->assertSame( array( 'name', 'kind', 'label', 'default', 'allowed_values', 'writable', 'needs' ), array_keys( $setting ) );
		}
		$this->assertSame( 'text', $settings['title']['kind'] );
		$this->assertSame( 'Text on the button', $settings['title']['default'] );
		$this->assertSame( 'link', $settings['link']['kind'] );
		$this->assertSame( array( 'rounded', 'square', 'round' ), $settings['shape']['allowed_values'] );
		$this->assertSame( array( 'left', 'center' ), $settings['align']['allowed_values'] );
		$this->assertSame( 'raw', $settings['custom_onclick_code']['kind'] );
		$this->assertSame( 'unfiltered_html', $settings['custom_onclick_code']['needs'] );
		$this->assertSame( 'design', $settings['css']['kind'] );
		$this->assertSame( 'token', $settings['el_class']['kind'] );
		$this->assertSame( '', $settings['title']['needs'] );
		$this->assertTrue( $settings['title']['writable'] );
	}

	public function test_a_malformed_param_is_skipped_and_counted(): void {
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_list_elements( array( 'tag' => 'acme_odd' ) );

		$this->assertIsArray( $out );
		$this->assertSame( 2, $out['skipped'] );
		$names = array_column( $out['elements'][0]['settings'], 'name' );
		$this->assertSame( array( 'ids', 'secret', 'query', 'order', 'empty_choice', 'label' ), $names );
		$settings = array_column( $out['elements'][0]['settings'], null, 'name' );
		$this->assertSame( 'Label text', $settings['label']['label'], 'Map strings go through the stored-text sanitizer.' );
	}

	public function test_a_map_entry_that_throws_is_skipped_and_counted(): void {
		WpbakeryMapStub::install( null, array( 'vc_gmaps' ) );
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_list_elements( array( 'per_page' => 50 ) );

		$this->assertIsArray( $out );
		$this->assertSame( 1, $out['skipped'] );
		$this->assertNotContains( 'vc_gmaps', array_column( $out['elements'], 'tag' ) );
	}

	public function test_an_empty_map_is_refused_never_an_empty_list(): void {
		WpbakeryMapStub::install( array() );
		$this->acting_as( 'editor' );

		$this->assert_error( aafm_exec_wpbakery_list_elements( array() ), 'aafm_wpbakery_map_unavailable', 409 );
	}

	public function test_a_missing_vendor_is_refused(): void {
		WpbakeryMapStub::install( null, array(), array( 'wpbakery_map_all', 'wpbakery_map_one' ) );
		$this->acting_as( 'editor' );

		$this->assert_error( aafm_exec_wpbakery_list_elements( array() ), 'aafm_wpbakery_map_unavailable', 409 );
		$this->assert_error( aafm_wpbakery_map( 'page', 'vc_row' ), 'aafm_wpbakery_map_unavailable', 409 );
	}

	public function test_pagination_caps_at_50(): void {
		$map = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$map[ 'acme_el_' . $i ] = array( 'name' => 'Element ' . $i );
		}
		WpbakeryMapStub::install( $map );
		$this->acting_as( 'editor' );

		$out = aafm_exec_wpbakery_list_elements( array( 'per_page' => 500 ) );
		$this->assertIsArray( $out );
		$this->assertCount( 50, $out['elements'] );
		$this->assertSame( 60, $out['total'] );
		$second = aafm_exec_wpbakery_list_elements(
			array(
				'per_page' => 50,
				'page'     => 2,
			)
		);
		$this->assertSame( array( 'acme_el_51', 'acme_el_52' ), array_slice( array_column( $second['elements'], 'tag' ), 0, 2 ) );
		$this->assertSame( 50, aafm_args_wpbakery_list_elements()['input_schema']['properties']['per_page']['maximum'] );
	}

	public function test_search_matches_name_and_tag(): void {
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_list_elements( array( 'search' => 'heading' ) );
		$this->assertSame( array( 'vc_custom_heading' ), array_column( $out['elements'], 'tag' ) );
		$out = aafm_exec_wpbakery_list_elements( array( 'search' => 'raw_' ) );
		$this->assertSame( array( 'vc_raw_html', 'vc_raw_js' ), array_column( $out['elements'], 'tag' ) );
	}

	public function test_grid_context_runs_the_grid_mapper_first(): void {
		$this->acting_as( 'editor' );
		aafm_exec_wpbakery_list_elements( array( 'context' => 'grid' ) );

		$this->assertSame( 'grid_mapper', WpbakeryMapStub::$calls[0] );
		$this->assertSame( 'map_all', WpbakeryMapStub::$calls[1] );

		WpbakeryMapStub::$calls = array();
		aafm_exec_wpbakery_list_elements( array() );
		$this->assertNotContains( 'grid_mapper', WpbakeryMapStub::$calls );
	}

	public function test_grid_context_is_refused_without_the_grid_mapper(): void {
		WpbakeryMapStub::install( null, array(), array( 'wpbakery_grid_mapper' ) );
		$this->acting_as( 'editor' );

		$this->assert_error( aafm_exec_wpbakery_list_elements( array( 'context' => 'grid' ) ), 'aafm_wpbakery_map_unavailable', 409 );
	}

	public function test_an_unknown_tag_is_refused(): void {
		$this->acting_as( 'editor' );
		$this->assert_error( aafm_exec_wpbakery_list_elements( array( 'tag' => 'acme_nothing' ) ), 'aafm_builder_unknown_element', 400 );
	}

	public function test_a_subscriber_is_refused(): void {
		$args = aafm_args_wpbakery_list_elements();
		$this->assertSame( 'aafm_perm_blocks_floor', $args['permission_callback'] );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_perm_blocks_floor() );
		$this->acting_as( 'contributor' );
		$this->assertTrue( aafm_perm_blocks_floor() );
	}

	public function test_allowed_values_are_capped_at_100(): void {
		$values = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$values[ 'Option ' . $i ] = 'opt' . $i;
		}
		WpbakeryMapStub::install(
			array(
				'acme_many' => array(
					'name'   => 'Many',
					'params' => array(
						array(
							'type'       => 'dropdown',
							'param_name' => 'pick',
							'value'      => $values,
						),
					),
				),
			)
		);
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_list_elements( array( 'tag' => 'acme_many' ) );

		$this->assertCount( 100, $out['elements'][0]['settings'][0]['allowed_values'] );
	}

	public function test_list_schema_is_closed_and_read_only(): void {
		$args = aafm_args_wpbakery_list_elements();
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'page', 'grid' ), $args['input_schema']['properties']['context']['enum'] );
		$this->assertSame( 'aafm-reads', $args['category'] );
		$this->assertTrue( $args['meta']['annotations']['readonly'] );
		$row = aafm_get_abilities_registry_full()['aafm/wpbakery-list-elements'];
		$this->assertSame( 'reads', $row['group'] );
		$this->assertSame( 'read', $row['risk'] );
		$this->assertSame( 'wpbakery', $row['subject'] );
	}

	public function test_list_description_names_only_wpbakery_and_has_no_spaced_hyphen(): void {
		$row = aafm_get_abilities_registry_full()['aafm/wpbakery-list-elements'];
		foreach ( array( $row['description'], aafm_ability_disclosures()['aafm/wpbakery-list-elements'] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'Visual Composer', 'Avada', 'Elementor' ) as $other ) {
				$this->assertStringNotContainsString( $other, $text );
			}
			$this->assertStringContainsString( 'Role Manager', $text );
		}
		$this->assertStringContainsString( 'Only while WPBakery Page Builder is active', $row['description'] );
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

	/**
	 * WPBakery 9.1's editor rewrites &quot;, &#034; and &#039; in an attribute value to bare quotes on its next save
	 * (smoke run, scratchpad/1113/s9a-vendor/ev/probe-entities-*), which ends the attribute early. The encoder writes
	 * them as the quote itself, so a double quote takes W2's escape.
	 */
	public function test_text_writes_quote_entities_as_the_quote_itself(): void {
		$this->assertSame( 'A ``q`` \'s\' ``n`` ``x`` C &gt; D &lt; E &amp; F', $this->encode( 'wpb_btn', 'title', 'A &quot;q&quot; &#039;s&#39; &#034;n&#34; &#x22;x&#x22; C &gt; D &lt; E &amp; F' ) );
	}

	/**
	 * SEC-S9a-1: WPBakery's editor reads the page through TinyMCE, which decodes every entity, so a bracket entity in
	 * a value would become a real bracket on the next editor save and end the tag. Bracket entities are written as
	 * brackets, so W2 escapes them.
	 */
	public function test_text_writes_bracket_entities_as_escaped_brackets(): void {
		$value = 'x&#93;&#91;vc_raw_js&#93;PHNjcmlwdD4=&#91;/vc_raw_js&#93;&#91; &#x5B;a&#x5d; &#091;b&#0093; &lsqb;c&rsqb; &lbrack;d&rbrack;';
		// The named forms are not entities kses knows, so normalisation already writes them as &amp;..., which stays text.
		$this->assertSame( 'x`}``{`vc_raw_js`}`PHNjcmlwdD4=`{`/vc_raw_js`}``{` `{`a`}` `{`b`}` &amp;lsqb;c&amp;rsqb; &amp;lbrack;d&amp;rbrack;', $this->encode( 'vc_custom_heading', 'text', $value ) );
		$this->assertSame( '`{`x`}`', $this->encode( 'wpb_btn', 'custom_onclick_code', '&#91;x&#93;' ) );
	}

	/**
	 * WPBakery's editor reads the page through TinyMCE, which decodes every entity and re-encodes only & < and >
	 * (smoke probe, scratchpad/1113/s9a-vendor/f1/probe-13-*). Attribute text is written in that form, so the
	 * editor's next save leaves the attribute as stored.
	 */
	public function test_text_is_written_the_way_the_editor_saves_it(): void {
		$this->assertSame( "C \u{2019} D \u{201C}q\u{201D} E / F = G \$ H \u{00A0} I ` L &amp;#91; M &lt; N", $this->encode( 'wpb_btn', 'title', 'C &#8217; D &#8220;q&#8221; E &#47; F &#61; G &#36; H &nbsp; I &#96; L &amp;#91; M &lt; N' ) );
		$this->assertSame( 'a &gt; b &amp; c', $this->encode( 'wpb_btn', 'title', 'a > b & c' ) );
	}

	/**
	 * R1-1: WPBakery's textarea type keeps a line break inside the attribute, stored as CRLF by its editor's form post,
	 * and its editor saves it back byte for byte (smoke probe, scratchpad/1113/s9a-vendor/f1/twoline-*). A textfield
	 * still refuses one.
	 */
	public function test_a_textarea_setting_keeps_a_line_break(): void {
		$this->assertSame( "Line one\r\nLine two", $this->encode( 'vc_custom_heading', 'text', "Line one\nLine two" ) );
		$this->assertSame( "Line one\r\nLine \"two\"", str_replace( '``', '"', (string) $this->encode( 'vc_custom_heading', 'text', "Line one\r\nLine \"two\"" ) ) );
		$this->assert_error( $this->encode( 'wpb_btn', 'title', "two\nlines" ), 'aafm_builder_invalid_value', 400 );
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

	/**
	 * A page with the given content, created as an administrator so stored markup is kept as written.
	 *
	 * @param string $content Content.
	 * @param int    $author  Author, or 0 for the administrator.
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
	 * Run the update with index and expect filled in.
	 *
	 * @param int                 $id     Post.
	 * @param int                 $index  Outline index.
	 * @param array<string,mixed> $change set_atts, remove_atts and text.
	 * @return array<string,mixed>|WP_Error
	 */
	private function update( int $id, int $index, array $change ) {
		return aafm_exec_wpbakery_update_element(
			array(
				'post_id' => $id,
				'index'   => $index,
				'expect'  => $this->expect( $id, $index ),
			) + $change
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

	/**
	 * The fixture with one opening tag swapped, for byte-for-byte expectations.
	 *
	 * @param string $old      Stored text.
	 * @param string $expected Expected text.
	 * @return string
	 */
	private function fixture_with( string $old, string $expected ): string {
		$this->assertSame( 1, substr_count( $this->fixture(), $old ) );
		return str_replace( $old, $expected, $this->fixture() );
	}

	public function test_update_sets_a_heading_with_quotes_and_brackets(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$out = $this->update( $id, 2, array( 'set_atts' => array( 'text' => 'Ask "why" [twice]' ) ) );

		$this->assertIsArray( $out );
		$this->assertSame( array( 'text' ), $out['changed'] );
		$this->assertSame( $this->fixture_with( '[vc_custom_heading text="Say ``hello`` to `{`S8a`}` heading"]', '[vc_custom_heading text="Ask ``why`` `{`twice`}`"]' ), get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertStringNotContainsString( '[/vc_custom_heading]', get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_update_keeps_a_leaf_without_a_closer_without_one(): void {
		$this->test_update_sets_a_heading_with_quotes_and_brackets();
	}

	public function test_update_writes_a_two_line_heading(): void {
		$content = '[vc_row][vc_column][vc_custom_heading text="One line"][/vc_column][/vc_row]';
		$id      = $this->page( $content );
		$this->act_as_restricted_editor();

		$this->assertIsArray( $this->update( $id, 2, array( 'set_atts' => array( 'text' => "Line one\nLine two" ) ) ) );
		$this->assertSame( "[vc_row][vc_column][vc_custom_heading text=\"Line one\r\nLine two\"][/vc_column][/vc_row]", get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_update_keeps_a_stored_closer(): void {
		$content = '[vc_row][vc_column][vc_custom_heading text="Old"][/vc_custom_heading][/vc_column][/vc_row]';
		$id      = $this->page( $content );
		$this->acting_as( 'editor' );

		$this->assertIsArray( $this->update( $id, 2, array( 'set_atts' => array( 'text' => 'New' ) ) ) );
		$this->assertSame( '[vc_row][vc_column][vc_custom_heading text="New"][/vc_custom_heading][/vc_column][/vc_row]', get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_update_sets_a_button_link_with_every_part(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$out = $this->update(
			$id,
			3,
			array(
				'set_atts' => array(
					'title' => 'Pricing',
					'link'  => array(
						'url'    => '/pricing?plan=pro&x=1',
						'title'  => 'See: plans',
						'target' => '_self',
						'rel'    => 'noopener',
					),
				),
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( array( 'title', 'link' ), $out['changed'] );
		$stored = get_post_field( 'post_content', $id, 'raw' );
		$this->assertStringContainsString( '[wpb_btn title="Pricing" css=".vc_custom_1794659903041{padding-top: 14px !important;padding-right: 20px !important;padding-bottom: 14px !important;padding-left: 20px !important;}" link="url:%2Fpricing%3Fplan%3Dpro%26x%3D1|title:See%3A%20plans|target:_self|rel:noopener"]', $stored );
	}

	public function test_update_sets_an_image_id(): void {
		$image = $this->image();
		$id    = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$this->assertIsArray( $this->update( $id, 4, array( 'set_atts' => array( 'image' => $image ) ) ) );
		$this->assertSame( $this->fixture_with( '[vc_single_image image="13"]', '[vc_single_image image="' . $image . '"]' ), get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_update_sets_a_colour(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$this->assertIsArray( $this->update( $id, 3, array( 'set_atts' => array( 'custom_background' => '#00aa11' ) ) ) );
		$this->assertStringContainsString( 'rel:nofollow" custom_background="#00aa11"]', get_post_field( 'post_content', $id, 'raw' ), 'A new setting is added after the stored ones.' );
	}

	public function test_update_sets_a_choice(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$this->assertIsArray( $this->update( $id, 5, array( 'set_atts' => array( 'onclick' => 'img_link_large' ) ) ) );
		$this->assertStringContainsString( '[vc_gallery onclick="img_link_large" images="13"', get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_update_sets_a_design_options_rule_and_asks_for_a_css_rebuild(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'administrator' );
		WpbakeryMapStub::$calls = array();

		$out = $this->update( $id, 0, array( 'set_atts' => array( 'css' => array( 'margin_top' => '40px' ) ) ) );

		$this->assertIsArray( $out );
		$this->assertSame( array( 'css' ), $out['changed'] );
		$this->assertMatchesRegularExpression( '/^\[vc_row gap="0px" css="\.vc_custom_\d{13}\{margin-top: 40px !important;\}"\]/', get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( array( 'rebuild:default', 'rebuild:custom' ), array_values( array_filter( WpbakeryMapStub::$calls, static fn( string $call ): bool => 0 === strpos( $call, 'rebuild:' ) ) ) );
	}

	public function test_a_text_update_asks_for_no_css_rebuild(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );
		WpbakeryMapStub::$calls = array();

		$this->assertIsArray( $this->update( $id, 2, array( 'set_atts' => array( 'text' => 'Plain' ) ) ) );
		$this->assertSame( array(), array_values( array_filter( WpbakeryMapStub::$calls, static fn( string $call ): bool => 0 === strpos( $call, 'rebuild:' ) ) ) );
	}

	public function test_update_sets_the_text_of_a_leaf(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$out = $this->update( $id, 8, array( 'text' => '<p>New <strong>copy</strong></p><script>x()</script>' ) );

		$this->assertIsArray( $out );
		$this->assertSame( array( 'text' ), $out['changed'] );
		$this->assertSame( $this->fixture_with( "[vc_column_text]\r\n\r\nSeed text block.\r\n\r\n[/vc_column_text]", '[vc_column_text]<p>New <strong>copy</strong></p>x()[/vc_column_text]' ), get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_update_refuses_text_on_an_element_with_no_content_param(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$out = $this->update( $id, 2, array( 'text' => 'Inner' ) );
		$this->assert_refused( $out, 'aafm_builder_attribute_not_writable', 400, $id, $this->fixture() );
		$this->assertStringContainsString( 'text', $out->get_error_message() );
	}

	public function test_remove_atts_drops_the_attribute(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$out = $this->update( $id, 5, array( 'remove_atts' => array( 'interval', 'captions' ) ) );

		$this->assertIsArray( $out );
		$this->assertSame( array( 'interval' ), $out['changed'], 'A setting that was not stored is not reported.' );
		$this->assertSame( $this->fixture_with( ' interval="3"', '' ), get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_untouched_attributes_and_every_other_byte_are_identical(): void {
		$id      = $this->page( $this->fixture() );
		$content = $this->fixture();
		$element = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) )[5];
		$this->acting_as( 'editor' );

		$this->assertIsArray( $this->update( $id, 5, array( 'set_atts' => array( 'interval' => '5' ) ) ) );

		$stored = (string) get_post_field( 'post_content', $id, 'raw' );
		$this->assertSame( substr( $content, 0, $element['start'] ), substr( $stored, 0, $element['start'] ) );
		$this->assertSame( substr( $content, $element['open_end'] ), substr( $stored, $element['open_end'] ) );
		$this->assertSame( str_replace( 'interval="3"', 'interval="5"', substr( $content, $element['start'], $element['open_end'] - $element['start'] ) ), substr( $stored, $element['start'], $element['open_end'] - $element['start'] ) );
	}

	public function test_untouched_attributes_keep_their_quoting(): void {
		$content = "[vc_row][vc_column][wpb_btn title='Single' shape=square]a[/wpb_btn][/vc_column][/vc_row]";
		$id      = $this->page( $content );
		$this->acting_as( 'editor' );

		$this->assertIsArray( $this->update( $id, 2, array( 'set_atts' => array( 'shape' => 'round' ) ) ) );
		$this->assertSame( "[vc_row][vc_column][wpb_btn title='Single' shape=\"round\"]a[/wpb_btn][/vc_column][/vc_row]", get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_update_refuses_a_stale_fingerprint(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_update_element(
			array(
				'post_id'  => $id,
				'index'    => 2,
				'expect'   => array(
					'tag'         => 'vc_custom_heading',
					'fingerprint' => '0000000000000000',
				),
				'set_atts' => array( 'text' => 'x' ),
			)
		);
		$this->assert_refused( $out, 'aafm_builder_element_mismatch', 409, $id, $this->fixture() );
	}

	public function test_update_refuses_a_setting_that_is_not_in_the_map(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );
		foreach ( array( 'onmouseover', 'Title', 'ti-tle', 'content' ) as $name ) {
			$out = $this->update( $id, 3, array( 'set_atts' => array( $name => 'x' ) ) );
			$this->assert_refused( $out, 'aafm_builder_attribute_not_writable', 400, $id, $this->fixture() );
		}
		$this->assert_refused( $this->update( $id, 3, array( 'remove_atts' => array( 'onmouseover' ) ) ), 'aafm_builder_attribute_not_writable', 400, $id, $this->fixture() );
	}

	public function test_update_refuses_a_kind_that_is_not_writable(): void {
		$content = '[vc_row][vc_column width="1/2" offset="vc_col-lg-6"][/vc_column][/vc_row]';
		$id      = $this->page( $content );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->update( $id, 1, array( 'set_atts' => array( 'offset' => 'vc_col-lg-4' ) ) ), 'aafm_builder_attribute_not_writable', 400, $id, $content );
	}

	public function test_update_refuses_without_the_param_type_row(): void {
		$id = $this->page( $this->fixture() );
		WpbakeryMapStub::install( null, array(), array( 'wpbakery_param_type' ) );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->update( $id, 2, array( 'set_atts' => array( 'text' => 'x' ) ) ), 'aafm_builder_attribute_not_writable', 400, $id, $this->fixture() );
	}

	public function test_update_refuses_text_on_an_element_with_children(): void {
		$content = '[vc_row][vc_column][vc_column_text]a[/vc_column_text][/vc_column][/vc_row]';
		$id      = $this->page( $content );
		WpbakeryMapStub::install(
			array_merge(
				WpbakeryMapStub::map(),
				array(
					'vc_column' => array(
						'name'         => 'Column',
						'is_container' => true,
						'params'       => array(
							array(
								'type'       => 'textarea_html',
								'param_name' => 'content',
							),
						),
					),
				)
			)
		);
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->update( $id, 1, array( 'text' => 'flat' ) ), 'aafm_builder_attribute_not_writable', 400, $id, $content );
	}

	public function test_update_refuses_inner_text_with_bracket_entities_that_spell_an_element(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->update( $id, 8, array( 'text' => '<p>a &#91;vc_raw_html&#93;PHA+&#91;/vc_raw_html&#93; b</p>' ) ), 'aafm_builder_structure_changed', 400, $id, $this->fixture() );
		$this->assert_refused( $this->update( $id, 8, array( 'text' => '<p>&#x5b;vc_raw_js&#x5D;x&#X5B;/vc_raw_js&#093;</p>' ) ), 'aafm_builder_structure_changed', 400, $id, $this->fixture() );
	}

	/**
	 * SEC-S9a-1-R2: WPBakery's editor decodes every entity on its next save, including one inside a shortcode name or a
	 * closer, so inner text is decoded to that form first and the structure check sees the element.
	 */
	public function test_update_refuses_inner_text_with_entities_inside_a_shortcode_name(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		foreach ( array( '<p>a [&#118;c_raw_js]PHNjcmlwdD4=[/&#118;c_raw_js] b</p>', '<p>a [&#47;vc_column_text] b</p>', '<p>[vc&#95;raw&#95;js]x[/vc&#95;raw&#95;js]</p>' ) as $text ) {
			$this->assert_refused( $this->update( $id, 8, array( 'text' => $text ) ), 'aafm_builder_structure_changed', 400, $id, $this->fixture() );
		}
	}

	public function test_inner_text_keeps_amp_lt_and_gt_entities(): void {
		$this->assertSame( '<p>a &amp; b &lt;c&gt; &lt;d&gt; ’</p>', $this->encode( 'vc_column_text', 'content', '<p>a &amp; b &lt;c&gt; &#60;d&#62; &#8217;</p>' ) );
	}

	public function test_update_with_the_stored_design_options_saves_nothing(): void {
		$id        = $this->page( $this->fixture() );
		$revisions = count( wp_get_post_revisions( $id ) );
		$this->acting_as( 'administrator' );
		WpbakeryMapStub::$calls = array();

		$out = $this->update( $id, 0, array( 'set_atts' => array( 'css' => array( 'margin_top' => '10px' ) ) ) );

		$this->assertIsArray( $out );
		$this->assertSame( array(), $out['changed'] );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertSame( $this->fixture(), get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $id ) ) );
		$this->assertSame( array(), array_values( array_filter( WpbakeryMapStub::$calls, static fn( string $call ): bool => 0 === strpos( $call, 'rebuild:' ) ) ) );

		$this->assertIsArray( $this->update( $id, 0, array( 'set_atts' => array( 'css' => array( 'margin_top' => '12px' ) ) ) ) );
		$this->assertStringNotContainsString( 'vc_custom_1794659894762', get_post_field( 'post_content', $id, 'raw' ), 'A different rule still gets a new class.' );
	}

	public function test_update_refuses_inner_text_that_adds_an_element(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->update( $id, 8, array( 'text' => 'a [vc_custom_heading text="x"] b' ) ), 'aafm_builder_structure_changed', 400, $id, $this->fixture() );
	}

	public function test_update_refuses_a_value_that_would_end_its_tag(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$this->assert_refused( $this->update( $id, 3, array( 'set_atts' => array( 'el_class' => 'a"] [x' ) ) ), 'aafm_builder_invalid_value', 400, $id, $this->fixture() );
	}

	public function test_update_needs_at_least_one_change(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );
		foreach ( array( array(), array( 'set_atts' => array() ), array( 'remove_atts' => array() ) ) as $change ) {
			$this->assert_refused( $this->update( $id, 2, $change ), 'aafm_builder_nothing_to_change', 400, $id, $this->fixture() );
		}
	}

	/**
	 * Every entry on the script list, as an editor whose unfiltered_html is false: 403 before the value is encoded
	 * (each value is one the encoder would refuse with 400), and the page untouched.
	 */
	public function test_an_editor_without_unfiltered_html_cannot_set_design_options_raw_html_or_an_onclick(): void {
		$id      = $this->page( $this->fixture() );
		$addon   = '[vc_row][vc_column][acme_embed title="t" code="x"][/vc_column][/vc_row]';
		$addon_p = $this->page( $addon );
		$gmaps   = '[vc_row][vc_column][vc_gmaps title="t"][/vc_column][/vc_row]';
		$gmaps_p = $this->page( $gmaps );
		$this->act_as_restricted_editor();

		$cases = array(
			array( $id, 0, array( 'set_atts' => array( 'css' => array( 'margin_top' => '10' ) ) ) ),
			array( $id, 3, array( 'set_atts' => array( 'css' => array( 'selector' => 'x' ) ) ) ),
			array( $id, 3, array( 'remove_atts' => array( 'css' ) ) ),
			array( $id, 11, array( 'text' => array( 'not a string' ) ) ),
			array( $id, 12, array( 'text' => array( 'not a string' ) ) ),
			array( $id, 11, array( 'set_atts' => array( 'el_class' => 'a"b' ) ) ),
			array( $id, 3, array( 'set_atts' => array( 'custom_onclick_code' => array( 'x' ) ) ) ),
			array( $id, 3, array( 'set_atts' => array( 'btn_custom_onclick_code' => array( 'x' ) ) ) ),
			array( $id, 3, array( 'set_atts' => array( 'custom_onclick' => 'nope' ) ) ),
			array( $addon_p, 2, array( 'set_atts' => array( 'code' => array( 'x' ) ) ) ),
			array( $gmaps_p, 2, array( 'set_atts' => array( 'title' => array( 'x' ) ) ) ),
		);
		foreach ( $cases as list( $post, $index, $change ) ) {
			$before = (string) get_post_field( 'post_content', $post, 'raw' );
			$this->assert_refused( $this->update( $post, $index, $change ), 'aafm_builder_needs_unfiltered_html', 403, $post, $before );
		}
		$this->assertStringContainsString( '[vc_raw_html]', get_post_field( 'post_content', $id, 'raw' ) );

		$add = static fn( $tags ) => array_merge( (array) $tags, array( 'acme_embed' ) );
		add_filter( 'wpb_custom_html_elements', $add );
		try {
			$this->assert_refused( $this->update( $addon_p, 2, array( 'set_atts' => array( 'title' => array( 'x' ) ) ) ), 'aafm_builder_needs_unfiltered_html', 403, $addon_p, $addon );
		} finally {
			remove_filter( 'wpb_custom_html_elements', $add );
		}
		add_filter( 'wpb_custom_html_elements', '__return_empty_array' );
		try {
			$this->assert_refused( $this->update( $gmaps_p, 2, array( 'set_atts' => array( 'title' => array( 'x' ) ) ) ), 'aafm_builder_needs_unfiltered_html', 403, $gmaps_p, $gmaps );
		} finally {
			remove_filter( 'wpb_custom_html_elements', '__return_empty_array' );
		}
	}

	public function test_an_administrator_can(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'administrator' );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );

		$this->assertIsArray( $this->update( $id, 11, array( 'text' => '<b>new</b>' ) ) );
		$this->assertSame( '<b>new</b>', aafm_wpbakery_decode_raw( (string) $this->between( $id, '[vc_raw_html]', '[/vc_raw_html]' ) ) );
		$this->assertIsArray( $this->update( $id, 3, array( 'set_atts' => array( 'custom_onclick_code' => 'go("x")' ) ) ) );
		$this->assertStringContainsString( 'custom_onclick_code="go(``x``)"', get_post_field( 'post_content', $id, 'raw' ) );
	}

	/**
	 * The stored text between two markers.
	 *
	 * @param int    $id    Post.
	 * @param string $open  Opening marker.
	 * @param string $close Closing marker.
	 * @return string|null
	 */
	private function between( int $id, string $open, string $close ): ?string {
		$content = (string) get_post_field( 'post_content', $id, 'raw' );
		$start   = strpos( $content, $open );
		if ( false === $start ) {
			return null;
		}
		$start += strlen( $open );
		return substr( $content, $start, (int) strpos( $content, $close, $start ) - $start );
	}

	public function test_update_refuses_when_the_save_would_strip_untouched_content(): void {
		$content = '[vc_row][vc_column][vc_custom_heading text="Old"][vc_column_text]<iframe></iframe>[/vc_column_text][/vc_column][/vc_row]';
		$id      = $this->page( $content );
		$this->acting_as( 'editor' );
		$strip = static fn( $value ) => str_replace( '<iframe></iframe>', '', (string) $value );
		add_filter( 'content_save_pre', $strip );
		try {
			$out = $this->update( $id, 2, array( 'set_atts' => array( 'text' => 'New' ) ) );
		} finally {
			remove_filter( 'content_save_pre', $strip );
		}
		$this->assert_refused( $out, 'aafm_wpbakery_save_would_change_page', 409, $id, $content );
	}

	public function test_update_refuses_when_a_save_filter_deletes_an_element_once_the_new_value_is_present(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );
		$react = static function ( $value ) {
			$value = (string) $value;
			return false === strpos( $value, 'Trigger' ) ? $value : (string) preg_replace( '/\[vc_single_image[^\]]*\]/', '', $value );
		};
		add_filter( 'content_save_pre', $react );
		try {
			$out = $this->update( $id, 2, array( 'set_atts' => array( 'text' => 'Trigger' ) ) );
		} finally {
			remove_filter( 'content_save_pre', $react );
		}
		$this->assert_refused( $out, 'aafm_wpbakery_save_would_change_layout', 409, $id, $this->fixture() );
	}

	public function test_update_reports_unconfirmed_when_a_filter_alters_the_stored_content(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );
		$alter = static function ( $data ) {
			$data['post_content'] .= ' altered';
			return $data;
		};
		add_filter( 'wp_insert_post_data', $alter );
		try {
			$out = $this->update( $id, 2, array( 'set_atts' => array( 'text' => 'New' ) ) );
		} finally {
			remove_filter( 'wp_insert_post_data', $alter );
		}
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_wpbakery_write_unconfirmed', $out->get_error_code() );
	}

	public function test_update_of_the_second_identical_twin_changes_only_the_second(): void {
		$twin    = '[wpb_btn title="Learn more"]';
		$content = '[vc_row][vc_column]' . $twin . $twin . '[/vc_column][/vc_row]';
		$id      = $this->page( $content );
		$this->acting_as( 'editor' );
		$expect = $this->expect( $id, 3 );
		$this->assertSame( $this->expect( $id, 2 )['fingerprint'], $expect['fingerprint'] );

		$this->assertIsArray( $this->update( $id, 3, array( 'set_atts' => array( 'title' => 'Second' ) ) ) );
		$stored = (string) get_post_field( 'post_content', $id, 'raw' );
		$this->assertSame( $twin, substr( $stored, strlen( '[vc_row][vc_column]' ), strlen( $twin ) ) );
		$this->assertSame( '[vc_row][vc_column]' . $twin . '[wpb_btn title="Second"][/vc_column][/vc_row]', $stored );
	}

	public function test_update_with_the_stored_value_saves_nothing(): void {
		$id        = $this->page( $this->fixture() );
		$modified  = get_post_field( 'post_modified', $id );
		$revisions = count( wp_get_post_revisions( $id ) );
		$this->acting_as( 'editor' );

		$out = $this->update(
			$id,
			2,
			array(
				'set_atts'    => array( 'text' => 'Say "hello" to [S8a] heading' ),
				'remove_atts' => array( 'link' ),
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( array(), $out['changed'] );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertSame( aafm_builder_page_fingerprint( $this->fixture() ), $out['page_fingerprint'] );
		$this->assertSame( $modified, get_post_field( 'post_modified', $id ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $id ) ) );
		$this->assertSame( $this->fixture(), get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_update_after_multibyte_text_by_a_restricted_editor_passes_the_replay(): void {
		$content = '[vc_row][vc_column][vc_custom_heading text="مرحبا بالعالم 👋"][vc_custom_heading text="Old"][/vc_column][/vc_row]';
		$id      = $this->page( $content );
		$this->act_as_restricted_editor();

		$out = $this->update( $id, 3, array( 'set_atts' => array( 'text' => 'Café & Crème <b>"x"' ) ) );

		$this->assertIsArray( $out );
		$this->assertSame( '[vc_row][vc_column][vc_custom_heading text="مرحبا بالعالم 👋"][vc_custom_heading text="Café &amp; Crème ``x``"][/vc_column][/vc_row]', get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_a_restricted_editor_can_write_a_value_with_an_ampersand_a_less_than_and_a_quote(): void {
		$id = $this->page( $this->fixture() );
		$this->act_as_restricted_editor();

		$out = $this->update( $id, 2, array( 'set_atts' => array( 'text' => 'A & B < C "D"' ) ) );

		$this->assertIsArray( $out );
		// Core's sanitize_text_field() escapes the run after a lone < (wp_pre_kses_less_than()), quotes included;
		// WPBakery's editor turns a stored quote entity back into a bare quote on save, so it is written as W2's escape.
		$this->assertStringContainsString( '[vc_custom_heading text="A &amp; B &lt; C ``D``"]', get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertStringContainsString( '[vc_custom_heading text="Say ``D``"]', $this->stored_after( $id, 'Say "D"' ) );
	}

	/**
	 * Write a heading text as the current user and return the stored content.
	 *
	 * @param int    $id   Post.
	 * @param string $text Heading text.
	 * @return string
	 */
	private function stored_after( int $id, string $text ): string {
		$this->assertIsArray( $this->update( $id, 2, array( 'set_atts' => array( 'text' => $text ) ) ) );
		return (string) get_post_field( 'post_content', $id, 'raw' );
	}

	public function test_update_refuses_an_index_past_the_outline(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );
		foreach ( array( 13, 2000, 5000 ) as $index ) {
			$out = aafm_exec_wpbakery_update_element(
				array(
					'post_id'  => $id,
					'index'    => $index,
					'expect'   => $this->expect( $id, 2 ),
					'set_atts' => array( 'text' => 'x' ),
				)
			);
			$this->assert_refused( $out, 'aafm_builder_element_mismatch', 409, $id, $this->fixture() );
		}
	}

	public function test_update_refuses_a_revision_id_and_an_attachment_id(): void {
		$id = $this->page( $this->fixture() );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $this->fixture() . ' ',
			)
		);
		$revision   = (int) array_key_first( wp_get_post_revisions( $id ) );
		$attachment = self::factory()->attachment->create( array( 'post_parent' => $id ) );
		$this->acting_as( 'administrator' );
		foreach ( array( $revision, $attachment ) as $target ) {
			$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $target ) ) );
		}
	}

	public function test_update_refuses_a_post_wpbakery_does_not_own(): void {
		$content = '<p>No builder here [vc_custom_heading text="x"]</p>';
		$id      = $this->page( $content );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->update( $id, 0, array( 'set_atts' => array( 'text' => 'y' ) ) ), 'aafm_not_wpbakery_owned', 409, $id, $content );
	}

	public function test_update_on_a_grid_template_runs_the_grid_mapper_first(): void {
		register_post_type(
			'vc_grid_item',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'supports'        => array( 'title', 'editor' ),
			)
		);
		$content = (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Wpbakery/grid-item.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local fixture file, not a remote URL.
		$this->acting_as( 'editor' );
		$id                     = self::factory()->post->create(
			array(
				'post_type'    => 'vc_grid_item',
				'post_content' => $content,
			)
		);
		$elements               = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) );
		$index                  = (int) array_search( 'vc_btn', array_column( $elements, 'tag' ), true );
		WpbakeryMapStub::$calls = array();

		$out = $this->update( $id, $index, array( 'set_atts' => array( 'title' => 'Read on' ) ) );

		$this->assertIsArray( $out );
		$this->assertFalse( $out['reversible'], 'Grid templates keep no revisions.' );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertSame( array( 'grid_mapper', 'map_one:vc_btn' ), array_slice( WpbakeryMapStub::$calls, 0, 2 ) );
		$this->assertStringContainsString( 'title="Read on"', get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_the_response_carries_the_new_fingerprints_and_a_way_back(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'editor' );

		$out = $this->update( $id, 2, array( 'set_atts' => array( 'text' => 'New' ) ) );

		$this->assertSame( array( 'post', 'index', 'fingerprint', 'page_fingerprint', 'changed', 'restore_revision_id', 'reversible' ), array_keys( $out ) );
		$stored = (string) get_post_field( 'post_content', $id, 'raw' );
		$this->assertSame( 2, $out['index'] );
		$this->assertSame( $this->expect( $id, 2 )['fingerprint'], $out['fingerprint'] );
		$this->assertSame( aafm_builder_page_fingerprint( $stored ), $out['page_fingerprint'] );
		$this->assertTrue( $out['reversible'] );
		$this->assertIsInt( $out['restore_revision_id'] );
		$this->assertSame( $this->fixture(), get_post_field( 'post_content', $out['restore_revision_id'], 'raw' ) );
		$this->assertSame( $id, $out['post']['ID'] ?? $out['post']['id'] ?? null );
	}

	public function test_update_contributor_is_refused_on_another_users_page_and_editor_allowed(): void {
		$id = $this->page( $this->fixture(), self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->acting_as( 'contributor' );
		$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$this->acting_as( 'editor' );
		$this->assertTrue( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
		$this->assertSame( 'aafm_perm_avada_post_object', aafm_args_wpbakery_update_element()['permission_callback'] );
	}

	public function test_discovery_shows_the_update_to_an_editor_and_hides_it_from_a_subscriber(): void {
		$check = aafm_ability_list_permission( 'aafm/wpbakery-update-element' );
		$this->assertIsCallable( $check );
		$this->acting_as( 'editor' );
		$this->assertTrue( $check() );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( $check() );
	}

	public function test_update_is_reported_as_not_covered_by_undo(): void {
		$this->assertSame( 'not_covered', aafm_undo_refusals()['aafm/wpbakery-update-element'] );
	}

	public function test_audit_detail_reads_ran_an_element_update(): void {
		$this->assertStringContainsString( 'Ran an element update on WPBakery page #42', (string) aafm_build_activity_detail( 'aafm/wpbakery-update-element', array( 'post_id' => 42 ) ) );
	}

	public function test_update_schema_is_closed(): void {
		$args   = aafm_args_wpbakery_update_element();
		$schema = $args['input_schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'post_id', 'index', 'expect' ), $schema['required'] );
		$this->assertSame( 0, $schema['properties']['index']['minimum'] );
		$this->assertFalse( $schema['properties']['expect']['additionalProperties'] );
		$this->assertSame( array( 'tag', 'fingerprint' ), $schema['properties']['expect']['required'] );
		$this->assertArrayNotHasKey( 'page_fingerprint', $schema['properties'] );
		$this->assertArrayNotHasKey( 'anyOf', $schema );
		$this->assertSame( 'aafm-writes', $args['category'] );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
			),
			$args['meta']['annotations']
		);
	}

	public function test_update_description_says_what_the_design_requires(): void {
		$row = aafm_get_abilities_registry_full()['aafm/wpbakery-update-element'];
		$this->assertSame( 'writes', $row['group'] );
		$this->assertSame( 'write', $row['risk'] );
		$this->assertSame( 'wpbakery', $row['subject'] );
		foreach ( array( $row['description'], aafm_ability_disclosures()['aafm/wpbakery-update-element'] ) as $text ) {
			$this->assertStringNotContainsString( ' - ', $text );
			foreach ( array( 'Visual Composer', 'Avada', 'Elementor' ) as $other ) {
				$this->assertStringNotContainsString( $other, $text );
			}
			foreach ( array( 'edit access', 'unfiltered', 'Role Manager', 'restore', '2,000' ) as $phrase ) {
				$this->assertStringContainsString( $phrase, $text );
			}
		}
		$this->assertStringContainsString( 'Only while WPBakery Page Builder is active', $row['description'] );
	}
}
