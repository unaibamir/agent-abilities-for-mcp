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

	/**
	 * The code point encoder the entity decoder uses gives the bytes mb_chr() gives, in plain PHP, across the four UTF-8
	 * lengths and their boundaries, and refuses what mb_chr() refuses.
	 */
	public function test_the_code_point_encoder_matches_mb_chr(): void {
		$valid = array( 1, 9, 10, 13, 0x41, 0x7F, 0x80, 0xA0, 0x7FF, 0x800, 0x200B, 0x202E, 0xD7FF, 0xE000, 0xFFFD, 0xFFFF, 0x10000, 0x1F600, 0x10FFFF );
		$bytes = array(
			9        => "\t",
			13       => "\r",
			0xA0     => "\xC2\xA0",
			0x200B   => "\xE2\x80\x8B",
			0x10000  => "\xF0\x90\x80\x80",
			0x10FFFF => "\xF4\x8F\xBF\xBF",
		);
		foreach ( $valid as $code ) {
			$char = aafm_utf8_chr( $code );
			$this->assertIsString( $char, (string) $code );
			if ( isset( $bytes[ $code ] ) ) {
				$this->assertSame( $bytes[ $code ], $char, (string) $code );
			}
			if ( function_exists( 'mb_chr' ) ) {
				$this->assertSame( mb_chr( $code, 'UTF-8' ), $char, (string) $code );
			}
		}
		foreach ( array( 0, -1, 0xD800, 0xDBFF, 0xDC00, 0xDFFF, 0x110000, PHP_INT_MAX ) as $code ) {
			$this->assertNull( aafm_utf8_chr( $code ), (string) $code );
		}
		$this->assertSame( "a\rb", aafm_wpbakery_decode_entities( 'a&#13;b' ) );
		$this->assertSame( "a\xE2\x80\xAEb", aafm_wpbakery_decode_entities( 'a&#x202E;b' ) );
		$this->assertSame( 'a&#xD800;b', aafm_wpbakery_decode_entities( 'a&#xD800;b' ), 'A surrogate stays as written.' );
		$this->assertSame( 'a&#1114112;b', aafm_wpbakery_decode_entities( 'a&#1114112;b' ), 'Above U+10FFFF stays as written.' );
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

	/**
	 * S8a R2-3: an add-on can register a map row whose lookup throws. The type is then unknown, which
	 * every caller already treats as "not writable", so the page still reads.
	 */
	public function test_a_param_type_lookup_that_throws_reads_as_unknown(): void {
		$throwing = static function ( $resolved, string $entry ) {
			if ( 'wpbakery_param_type' !== $entry ) {
				return $resolved;
			}
			return static function ( string $tag, string $name ): ?string {
				unset( $tag, $name );
				throw new \RuntimeException( 'add-on map failed' );
			};
		};
		add_filter( 'aafm_builder_vendor', $throwing, 99, 2 );

		$this->assertNull( aafm_wpbakery_param_type( 'wpb_btn', 'title' ) );
		$this->assertFalse( aafm_wpbakery_param_kind( 'wpb_btn', $this->param( 'wpb_btn', 'title' ) )['writable'] );
		$decoded = aafm_wpbakery_decode_atts( 'wpb_btn', array( 'title' => 'Hello' ) );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'title', $decoded );

		remove_filter( 'aafm_builder_vendor', $throwing, 99 );
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
		$this->assertSame( 'x`}``{`vc_raw_js`}`PHNjcmlwdD4=`{`/vc_raw_js`}``{` `{`a`}` `{`b`}` `{`c`}` `{`d`}`', $this->encode( 'vc_custom_heading', 'text', $value ) );
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

	/**
	 * R2-1: the guards run on decoded text, so an entity cannot bring back a line break or a bidi control.
	 */
	public function test_text_guards_run_after_entities_are_decoded(): void {
		$this->assert_error( $this->encode( 'wpb_btn', 'title', 'two&#10;lines' ), 'aafm_builder_invalid_value', 400 );
		$this->assert_error( $this->encode( 'wpb_btn', 'custom_onclick_code', 'a&#13;b' ), 'aafm_builder_invalid_value', 400 );
		$this->assertSame( 'abc', $this->encode( 'wpb_btn', 'title', 'a&#8238;b&#x2066;c' ) );
		$this->assertSame( "one\r\ntwo", $this->encode( 'vc_custom_heading', 'text', 'one&#10;two' ) );
	}

	/**
	 * R2-2: the safe-text form and the URL check read the decoded value.
	 */
	public function test_safe_text_checks_the_decoded_value(): void {
		foreach ( array( 'javascript&#58;alert(1)', 'jav&#97;script:alert(1)' ) as $link ) {
			$this->assert_error( $this->encode( 'vc_gallery', 'custom_links', array( $link ) ), 'aafm_builder_invalid_value', 400 );
		}
		$this->assertStringStartsWith( '#E-8_', (string) $this->encode( 'vc_gallery', 'custom_links', array( '&#104;ttps://example.com/x' ) ) );
		$this->assertSame( 'https://example.com/x', aafm_wpbakery_decode_value( (string) $this->encode( 'vc_gallery', 'custom_links', array( '&#104;ttps://example.com/x' ) ), 'exploded_textarea_safe' ) );
	}

	/**
	 * R3-2: a control character spelled as an entity is stripped from safe text after the decode.
	 */
	public function test_safe_text_strips_an_entity_spelled_control_character(): void {
		$this->assertSame( 'ab', aafm_wpbakery_decode_value( (string) $this->encode( 'vc_gallery', 'custom_links', array( 'a&#1;b' ) ), 'exploded_textarea_safe' ) );
		$this->assertStringNotContainsString( "\x01", (string) aafm_wpbakery_encode_safe_text( 'a&#x1;"b', false ) );
		$this->assertSame( 'a"b', aafm_wpbakery_decode_value( (string) aafm_wpbakery_encode_safe_text( 'a&#x1;"b', false ), 'textarea_safe' ) );
	}

	/**
	 * SEC-S9a-1-R3a: an href URL is stored bare, so its entities are decoded and its brackets percent-encoded.
	 */
	public function test_href_url_decodes_entities_and_encodes_brackets(): void {
		$this->assertSame( 'https://a/%5D%5Bvc_raw_js%5D', aafm_wpbakery_encode_link( 'https://a/&#93;&#91;vc_raw_js&#93;', true ) );
		$this->assertSame( 'https://a/x%5B1%5D', aafm_wpbakery_encode_link( 'https://a/x[1]', true ) );
		$this->assertNull( aafm_wpbakery_encode_link( 'https://a/&#34;x', true ) );
		$this->assertSame( 'https://a/?x=1&amp;y=2', aafm_wpbakery_encode_link( 'https://a/?x=1&amp;y=2', true ) );
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
		foreach ( array( ';', '{', '}', '<', '(', '\\', '&#59;', '&#x7b;', '&lpar;', '&#59', '&#40' ) as $char ) {
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

	/**
	 * SEC-S9a-1-R3b: for a user without unfiltered_html, body text may hold no square bracket at all once entities are
	 * decoded, whatever WPBakery's editor would later do to the rest of it.
	 */
	public function test_body_text_with_a_bracket_needs_unfiltered_html(): void {
		$id = $this->page( $this->fixture() );
		$this->act_as_restricted_editor();

		foreach ( array( '<p>[<b></b>vc_raw_js]PHNjcmlwdD4=[/<b></b>vc_raw_js]</p>', '<p>[&#118;c_raw_js]x[/&#118;c_raw_js]</p>', '<p>Footnote [1]</p>', '<p>a &#91;b</p>' ) as $text ) {
			$out = $this->update( $id, 8, array( 'text' => $text ) );
			$this->assert_refused( $out, 'aafm_wpbakery_brackets_need_unfiltered_html', 400, $id, $this->fixture() );
			$this->assertStringContainsString( 'unfiltered HTML', $out->get_error_message() );
		}
		$this->assertIsArray( $this->update( $id, 8, array( 'text' => '<p>No brackets here.</p>' ) ) );
	}

	/**
	 * SEC-S9a-1-R4: without unfiltered_html, body text may carry no percent-encoded bracket and no data-* attribute,
	 * since WordPress's TinyMCE plugins turn some data attributes into URL-decoded page text on the editor's save.
	 */
	public function test_body_text_refuses_encoded_brackets_and_data_attributes_without_unfiltered_html(): void {
		$id = $this->page( $this->fixture() );
		$this->act_as_restricted_editor();
		$cases = array(
			'<p><img src="https://x/a.png" data-wp-preserve="%5Bvc_raw_js%5DPHNjcmlwdD4=%5B%2Fvc_raw_js%5D"></p>' => 'aafm_wpbakery_encoded_brackets_need_unfiltered_html',
			'<p>Plain %5b text</p>'                        => 'aafm_wpbakery_encoded_brackets_need_unfiltered_html',
			'<p>Spelled &#37;5D here</p>'                  => 'aafm_wpbakery_encoded_brackets_need_unfiltered_html',
			'<p><img src="https://x/a.png" data-wp-media="x" /></p>' => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<p><img src="https://x/a.png" data-wp-preserve="x" /></p>' => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<div data-wpview-text="x">v</div>'            => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<p data-wpview-marker="x">v</p>'              => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<p><span title="a>b" data-x="1">v</span></p>' => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<p><IMG SRC="https://x/a.png" DATA-WP-PRESERVE="x"></p>' => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
		);
		foreach ( $cases as $text => $code ) {
			$out = $this->update( $id, 8, array( 'text' => $text ) );
			$this->assert_refused( $out, $code, 400, $id, $this->fixture() );
			$this->assertStringContainsString( 'unfiltered HTML', $out->get_error_message() );
		}
		$this->assertIsArray( $this->update( $id, 8, array( 'text' => '<p><img src="https://x/a.png" alt="A view" /> <a href="https://x/?a=1&amp;b=2">Link</a></p>' ) ) );

		$this->acting_as( 'administrator' );
		foreach ( array_keys( $cases ) as $text ) {
			$this->assertIsArray( $this->update( $id, 8, array( 'text' => $text ) ), $text );
		}
	}

	/**
	 * R5-1: an incomplete tag stops the tag parser before it sees every attribute, so the check refuses instead of
	 * passing what it could not read.
	 */
	public function test_body_text_refuses_an_incomplete_tag_without_unfiltered_html(): void {
		$this->act_as_restricted_editor();
		$out = aafm_wpbakery_body_text( '<p>Text</p><img src="https://x/a.png" alt="a' );
		$this->assertWPError( $out );
		$this->assertSame( 'aafm_wpbakery_incomplete_html_needs_unfiltered_html', $out->get_error_code() );
		$this->assertSame( 400, $out->get_error_data()['status'] );

		$this->acting_as( 'administrator' );
		$this->assertIsString( aafm_wpbakery_body_text( '<p>Text</p><img src="https://x/a.png" alt="a' ) );
	}

	/**
	 * R5-1: kses can join a percent-encoded bracket by removing a tag between its parts, so the stored form is checked
	 * as well as the text as sent.
	 */
	public function test_body_text_refuses_an_encoded_bracket_kses_would_join_without_unfiltered_html(): void {
		$this->act_as_restricted_editor();
		$out = aafm_wpbakery_body_text( '<p>Plain %<acme></acme>5b text</p>' );
		$this->assertWPError( $out );
		$this->assertSame( 'aafm_wpbakery_encoded_brackets_need_unfiltered_html', $out->get_error_code() );

		$this->acting_as( 'administrator' );
		$this->assertIsString( aafm_wpbakery_body_text( '<p>Plain %<acme></acme>5b text</p>' ) );
	}

	public function test_body_text_with_a_bracket_keeps_todays_checks_for_unfiltered_html(): void {
		$id = $this->page( $this->fixture() );
		$this->acting_as( 'administrator' );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );

		$this->assertIsArray( $this->update( $id, 8, array( 'text' => '<p>[<b></b>vc_raw_js]PHNjcmlwdD4=[/<b></b>vc_raw_js]</p>' ) ), 'Someone who may post unfiltered HTML may write this in wp-admin too.' );
		$this->assert_refused( $this->update( $id, 8, array( 'text' => '<p>[&#118;c_raw_js]x[/&#118;c_raw_js]</p>' ) ), 'aafm_builder_structure_changed', 400, $id, (string) get_post_field( 'post_content', $id, 'raw' ) );
		$this->assert_refused( $this->update( $id, 8, array( 'text' => '<p>Footnote [1]</p>' ) ), 'aafm_builder_structure_changed', 400, $id, (string) get_post_field( 'post_content', $id, 'raw' ) );
	}

	/**
	 * R3-1: quote entities inside the HTML stay encoded, so an attribute holding one keeps all of its text.
	 */
	public function test_body_text_keeps_quote_entities_inside_attributes(): void {
		$this->assertSame( '<p><img src="x.jpg" alt="the &quot;best&quot; view &quot;x&quot; &apos;y&apos;" /></p>', $this->encode( 'vc_column_text', 'content', '<p><img src="x.jpg" alt="the &quot;best&quot; view &#34;x&#x22; &#39;y&#x27;" /></p>' ) );
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
		$this->assertStringContainsString( 'Only a user who may post unfiltered HTML can put square brackets, percent-encoded brackets or data-* attributes in body text, and even then text that would add an element is refused.', $row['description'] );
		$this->assertStringContainsString( 'Only while WPBakery Page Builder is active', $row['description'] );
	}

	/*
	 * S9b: insert, move and remove (WB-4, WB-5, WB-6).
	 */

	private const SIMPLE = '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text][/vc_column][/vc_row]';

	private const TWO_ROWS = '[vc_row el_id="a"][vc_column][vc_column_text]<p>A</p>[/vc_column_text][/vc_column][/vc_row][vc_row el_id="b"][vc_column][vc_column_text]<p>B</p>[/vc_column_text][/vc_column][/vc_row]';

	private const SECTION = '[vc_section][vc_row][vc_column][vc_column_text]<p>A</p>[/vc_column_text][vc_row_inner][vc_column_inner][vc_column_text]<p>B</p>[/vc_column_text][/vc_column_inner][/vc_row_inner][/vc_column][/vc_row][/vc_section]';

	/**
	 * The page fingerprint of a post as stored now.
	 *
	 * @param int $id Post.
	 * @return string
	 */
	private function page_fp( int $id ): string {
		return aafm_builder_page_fingerprint( (string) get_post_field( 'post_content', $id, 'raw' ) );
	}

	/**
	 * Run the insert with anchor_expect and page_fingerprint filled in.
	 *
	 * @param int                 $id        Post.
	 * @param int|null            $anchor    Anchor index, or null for an empty post.
	 * @param string              $placement Placement.
	 * @param array<string,mixed> $element   Element.
	 * @param string|null         $page      Page fingerprint, or null for the stored one.
	 * @return array<string,mixed>|WP_Error
	 */
	private function insert( int $id, ?int $anchor, string $placement, array $element, ?string $page = null ) {
		$input = array(
			'post_id'          => $id,
			'page_fingerprint' => $page ?? $this->page_fp( $id ),
			'element'          => $element,
		);
		if ( null !== $anchor ) {
			$input += array(
				'anchor_index'  => $anchor,
				'anchor_expect' => $this->expect( $id, $anchor ),
				'placement'     => $placement,
			);
		}
		return aafm_exec_wpbakery_insert_element( $input );
	}

	/**
	 * Run the move with both expects and page_fingerprint filled in.
	 *
	 * @param int         $id        Post.
	 * @param int         $index     Element.
	 * @param int         $anchor    Anchor.
	 * @param string      $placement Placement.
	 * @param string|null $page      Page fingerprint, or null for the stored one.
	 * @return array<string,mixed>|WP_Error
	 */
	private function move( int $id, int $index, int $anchor, string $placement, ?string $page = null ) {
		return aafm_exec_wpbakery_move_element(
			array(
				'post_id'          => $id,
				'page_fingerprint' => $page ?? $this->page_fp( $id ),
				'index'            => $index,
				'expect'           => $this->expect( $id, $index ),
				'anchor_index'     => $anchor,
				'anchor_expect'    => $this->expect( $id, $anchor ),
				'placement'        => $placement,
			)
		);
	}

	/**
	 * Run the remove with expect and page_fingerprint filled in.
	 *
	 * @param int                       $id     Post.
	 * @param int                       $index  Element.
	 * @param string|null               $page   Page fingerprint, or null for the stored one.
	 * @param array<string,string>|null $expect Expect, or null for the stored one.
	 * @return array<string,mixed>|WP_Error
	 */
	private function remove( int $id, int $index, ?string $page = null, ?array $expect = null ) {
		return aafm_exec_wpbakery_remove_element(
			array(
				'post_id'          => $id,
				'page_fingerprint' => $page ?? $this->page_fp( $id ),
				'index'            => $index,
				'expect'           => $expect ?? $this->expect( $id, $index ),
			)
		);
	}

	/**
	 * A text block node.
	 *
	 * @param string $text Text.
	 * @return array<string,mixed>
	 */
	private static function text_block( string $text ): array {
		return array(
			'tag'  => 'vc_column_text',
			'text' => $text,
		);
	}

	/**
	 * The stored outline's tags in order.
	 *
	 * @param int $id Post.
	 * @return array<string>
	 */
	private function tags_of( int $id ): array {
		$content = (string) get_post_field( 'post_content', $id, 'raw' );
		return array_column( (array) aafm_builder_elements( $content, (array) aafm_wpbakery_shortcode_tags( $content ) ), 'tag' );
	}

	/**
	 * Makes the editor seam answer the given post types.
	 *
	 * @param array<string> $types Types.
	 * @return callable The filter, to remove.
	 */
	private function editor_types( array $types ): callable {
		$filter = static function ( $resolved, string $entry ) use ( $types ) {
			return 'wpbakery_editor_post_types' === $entry ? static fn(): array => $types : $resolved;
		};
		add_filter( 'aafm_builder_vendor', $filter, 20, 2 );
		return $filter;
	}

	public function test_nesting_allows_the_fixed_rules_and_reads_the_map_for_other_containers(): void {
		$map = WpbakeryMapStub::map() + array(
			'acme_tabs' => array( 'as_parent' => array( 'only' => 'acme_tab' ) ),
			'acme_tab'  => array( 'as_child' => array( 'only' => 'acme_tabs' ) ),
		);
		foreach ( array( array( '', 'vc_row' ), array( '', 'vc_section' ), array( 'vc_section', 'vc_row' ), array( 'vc_row', 'vc_column' ), array( 'vc_column', 'vc_column_text' ), array( 'vc_column', 'vc_row_inner' ), array( 'vc_row_inner', 'vc_column_inner' ), array( 'vc_column_inner', 'vc_column_text' ), array( 'acme_tabs', 'acme_tab' ) ) as list( $parent, $child ) ) {
			$this->assertTrue( aafm_wpbakery_nesting_allows( $parent, $child, $map ), "$parent > $child" );
		}
		foreach ( array( array( '', 'vc_column' ), array( 'vc_row', 'vc_column_text' ), array( 'vc_column_inner', 'vc_row_inner' ), array( 'vc_column_text', 'vc_column_text' ), array( 'acme_tabs', 'vc_column_text' ), array( 'vc_column', 'acme_tab' ), array( 'acme_unread', 'vc_column_text' ) ) as list( $parent, $child ) ) {
			$out = aafm_wpbakery_nesting_allows( $parent, $child, $map );
			$this->assert_error( $out, 'aafm_builder_invalid_nesting', 400 );
			$this->assertSame( $child, $out->get_error_data()['child'] );
			$this->assertStringContainsString( $child, $out->get_error_message() );
		}
	}

	/**
	 * The containers WPBakery 9.1 maps with is_container and no as_parent, as its own configs give them.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function container_map(): array {
		return WpbakeryMapStub::map() + array(
			'vc_tta_tabs'               => array(
				'is_container' => true,
				'as_parent'    => array( 'only' => 'vc_tta_section' ),
			),
			'vc_tta_section'            => array(
				'is_container'              => true,
				'allowed_container_element' => 'vc_row',
				'as_child'                  => array( 'only' => 'vc_tta_tour,vc_tta_tabs,vc_tta_accordion,vc_tta_pageable' ),
			),
			'vc_flexbox_container'      => array(
				'is_container' => true,
				'as_child'     => array( 'except' => 'vc_column,vc_flexbox_container_item,vc_row_inner,vc_column_inner,vc_grid_container_item' ),
				'as_parent'    => array( 'only' => 'vc_flexbox_container_item' ),
			),
			'vc_flexbox_container_item' => array(
				'is_container'    => true,
				'content_element' => false,
			),
			'vc_grid_container_item'    => array(
				'is_container'    => true,
				'content_element' => false,
			),
		);
	}

	/**
	 * R1-2: a container with no as_parent takes content elements, and an inner row only where allowed_container_element
	 * names vc_row; the child's own as_child still applies.
	 */
	public function test_nesting_lets_containers_without_as_parent_take_content(): void {
		$map = self::container_map();
		foreach ( array( array( 'vc_tta_section', 'vc_column_text' ), array( 'vc_tta_section', 'vc_row_inner' ), array( 'vc_flexbox_container_item', 'vc_column_text' ), array( 'vc_grid_container_item', 'vc_custom_heading' ), array( 'vc_tta_tabs', 'vc_tta_section' ) ) as list( $parent, $child ) ) {
			$this->assertTrue( aafm_wpbakery_nesting_allows( $parent, $child, $map ), "$parent > $child" );
		}
		foreach ( array( array( 'vc_flexbox_container_item', 'vc_flexbox_container' ), array( 'vc_flexbox_container_item', 'vc_row_inner' ), array( 'vc_flexbox_container_item', 'vc_column' ), array( 'vc_tta_section', 'vc_tta_section' ), array( 'vc_column', 'vc_tta_section' ), array( 'vc_flexbox_container', 'vc_column_text' ) ) as list( $parent, $child ) ) {
			$this->assert_error( aafm_wpbakery_nesting_allows( $parent, $child, $map ), 'aafm_builder_invalid_nesting', 400 );
		}
		// A container the site's map does not list: refused, and the message says why. WPBakery 9.1 ships the flexbox
		// and grid tags without mapping them anywhere, its editor included (smoke site, round 2), so the message does
		// not point at the editor.
		$out = aafm_wpbakery_nesting_allows( 'vc_flexbox_container_item', 'vc_column_text', WpbakeryMapStub::map() );
		$this->assert_error( $out, 'aafm_builder_invalid_nesting', 400 );
		$this->assertStringContainsString( 'does not list vc_flexbox_container_item', $out->get_error_message() );
		$this->assertStringNotContainsString( 'outside its editor', $out->get_error_message() );
	}

	public function test_insert_move_and_reorder_inside_tab_sections(): void {
		WpbakeryMapStub::install( self::container_map() );
		$a    = '[vc_column_text]<p>A</p>[/vc_column_text]';
		$b    = '[vc_column_text]<p>B</p>[/vc_column_text]';
		$page = '[vc_row][vc_column][vc_tta_tabs][vc_tta_section title="One"]' . $a . '[/vc_tta_section][vc_tta_section title="Two"]' . $b . '[/vc_tta_section][/vc_tta_tabs][/vc_column][/vc_row]';
		$id   = $this->page( $page );
		$this->acting_as( 'editor' );
		$this->assertIsArray( $this->insert( $id, 3, 'inside_end', self::text_block( '<p>C</p>' ) ) );
		$c = '[vc_column_text]<p>C</p>[/vc_column_text]';
		$this->assertSame( str_replace( $a, $a . $c, $page ), get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertIsArray( $this->move( $id, 5, 4, 'before' ), 'Reorder inside one section.' );
		$this->assertSame( str_replace( $a, $c . $a, $page ), get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertIsArray( $this->move( $id, 4, 6, 'inside_start' ), 'Move into the other section.' );
		$this->assertSame( str_replace( array( $a, $b ), array( $a, $c . $b ), $page ), get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_content_has_script_finds_every_script_kind(): void {
		$this->assertFalse( aafm_wpbakery_content_has_script( self::SIMPLE ) );
		foreach ( array(
			'[vc_raw_html]PGI+[/vc_raw_html]',
			'[vc_raw_js]PGI+[/vc_raw_js]',
			'[vc_gmaps title="Map"]',
			'[wpb_btn title="Go" custom_onclick="true"]',
			'[wpb_btn title="Go" btn_custom_onclick_code="alert(1)"]',
			'[vc_column_text css=".vc_custom_1{margin:0 !important;}"]x[/vc_column_text]',
			'[acme_embed code="x"]',
		) as $source ) {
			$this->assertTrue( aafm_wpbakery_content_has_script( $source ), $source );
		}
	}

	public function test_a_retried_insert_is_refused_and_stores_one_element(): void {
		$id    = $this->page( self::SIMPLE );
		$first = $this->page_fp( $id );
		$this->acting_as( 'editor' );
		$this->assertIsArray( $this->insert( $id, 2, 'before', self::text_block( '<p>New</p>' ), $first ) );
		$after = (string) get_post_field( 'post_content', $id, 'raw' );

		$this->assert_refused( $this->insert( $id, 2, 'before', self::text_block( '<p>New</p>' ), $first ), 'aafm_builder_page_changed', 409, $id, $after );
		$this->assertSame( 1, substr_count( $after, '<p>New</p>' ) );
	}

	public function test_chained_inserts_work_with_the_returned_page_fingerprint(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$one = $this->insert( $id, 2, 'after', self::text_block( '<p>Two</p>' ) );
		$this->assertIsArray( $one );
		$two = $this->insert( $id, 3, 'after', self::text_block( '<p>Three</p>' ), $one['page_fingerprint'] );
		$this->assertIsArray( $two );
		$this->assertSame( '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text][vc_column_text]<p>Two</p>[/vc_column_text][vc_column_text]<p>Three</p>[/vc_column_text][/vc_column][/vc_row]', get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_the_returned_page_fingerprint_is_of_the_stored_content(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$filter = static fn( $content ) => str_replace( 'Two', 'Two!', (string) $content );
		add_filter( 'content_save_pre', $filter );
		$out    = $this->insert( $id, 2, 'after', self::text_block( '<p>Two</p>' ) );
		remove_filter( 'content_save_pre', $filter );

		$this->assertIsArray( $out );
		$stored = (string) get_post_field( 'post_content', $id, 'raw' );
		$this->assertStringContainsString( 'Two!', $stored );
		$this->assertSame( aafm_builder_page_fingerprint( $stored ), $out['page_fingerprint'] );
	}

	public function test_insert_at_each_of_the_four_placements(): void {
		$new   = '[vc_column_text]<p>N</p>[/vc_column_text]';
		$cases = array(
			array( 2, 'before', '[vc_row][vc_column]' . $new . '[vc_column_text]<p>One</p>[/vc_column_text][/vc_column][/vc_row]', 2 ),
			array( 2, 'after', '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text]' . $new . '[/vc_column][/vc_row]', 3 ),
			array( 1, 'inside_start', '[vc_row][vc_column]' . $new . '[vc_column_text]<p>One</p>[/vc_column_text][/vc_column][/vc_row]', 2 ),
			array( 1, 'inside_end', '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text]' . $new . '[/vc_column][/vc_row]', 3 ),
		);
		foreach ( $cases as list( $anchor, $placement, $expected, $index ) ) {
			$id = $this->page( self::SIMPLE );
			$this->acting_as( 'editor' );
			$out = $this->insert( $id, $anchor, $placement, self::text_block( '<p>N</p>' ) );
			$this->assertIsArray( $out, $placement );
			$this->assertSame( $expected, get_post_field( 'post_content', $id, 'raw' ), $placement );
			$this->assertSame( $index, $out['index'] );
			$this->assertSame( substr( hash( 'sha256', $new ), 0, 16 ), $out['fingerprint'] );
			$this->assertSame( 1, $out['inserted'] );
			$this->assertIsInt( $out['restore_revision_id'] );
			$this->assertTrue( $out['reversible'] );
		}
	}

	public function test_insert_a_row_with_two_columns_and_three_children(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$out = $this->insert(
			$id,
			0,
			'after',
			array(
				'tag'      => 'vc_row',
				'atts'     => array( 'gap' => '10px' ),
				'children' => array(
					array(
						'tag'      => 'vc_column',
						'atts'     => array( 'width' => '1/2' ),
						'children' => array(
							array(
								'tag'  => 'vc_custom_heading',
								'atts' => array( 'text' => 'Hi "you"' ),
							),
							self::text_block( '<p>L</p>' ),
						),
					),
					array(
						'tag'      => 'vc_column',
						'children' => array( self::text_block( '<p>R</p>' ) ),
					),
				),
			)
		);
		$this->assertIsArray( $out );
		$this->assertSame( 6, $out['inserted'] );
		$this->assertSame( 3, $out['index'] );
		$this->assertSame( self::SIMPLE . '[vc_row gap="10px"][vc_column width="1/2"][vc_custom_heading text="Hi ``you``"][vc_column_text]<p>L</p>[/vc_column_text][/vc_column][vc_column][vc_column_text]<p>R</p>[/vc_column_text][/vc_column][/vc_row]', get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( array( 'vc_row', 'vc_column', 'vc_column_text', 'vc_row', 'vc_column', 'vc_custom_heading', 'vc_column_text', 'vc_column', 'vc_column_text' ), $this->tags_of( $id ) );
	}

	/**
	 * One case per fixed nesting rule, plus a leaf as a parent.
	 *
	 * @return array<string,array{0:int,1:string,2:array<string,mixed>}>
	 */
	public function wrong_nesting(): array {
		return array(
			'column at the top level'        => array( 0, 'after', array( 'tag' => 'vc_column' ) ),
			'column in a section'            => array( 0, 'inside_end', array( 'tag' => 'vc_column' ) ),
			'text block in a row'            => array( 1, 'inside_end', self::text_block( '<p>x</p>' ) ),
			'inner column in a column'       => array( 2, 'inside_end', array( 'tag' => 'vc_column_inner' ) ),
			'text block in an inner row'     => array( 4, 'inside_end', self::text_block( '<p>x</p>' ) ),
			'inner row in an inner column'   => array( 5, 'inside_end', array( 'tag' => 'vc_row_inner' ) ),
			'text block inside a text block' => array( 3, 'inside_end', self::text_block( '<p>x</p>' ) ),
		);
	}

	/**
	 * Each wrong nesting is refused with the parent and child named.
	 *
	 * @dataProvider wrong_nesting
	 *
	 * @param int                 $anchor    Anchor.
	 * @param string              $placement Placement.
	 * @param array<string,mixed> $element   Element.
	 */
	public function test_insert_refuses_each_wrong_nesting( int $anchor, string $placement, array $element ): void {
		$id = $this->page( self::SECTION );
		$this->acting_as( 'administrator' );
		$out = $this->insert( $id, $anchor, $placement, $element );
		$this->assert_refused( $out, 'aafm_builder_invalid_nesting', 400, $id, self::SECTION );
		$this->assertSame( $element['tag'], $out->get_error_data()['child'] );
	}

	public function test_insert_refuses_a_tag_missing_from_the_map(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->insert( $id, 2, 'after', array( 'tag' => 'acme_missing' ) ), 'aafm_builder_unknown_element', 400, $id, self::SIMPLE );
	}

	public function test_insert_refuses_depth_7_and_51_elements(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$deep = self::text_block( '<p>x</p>' );
		for ( $i = 0; $i < 6; $i++ ) {
			$deep = array(
				'tag'      => 0 === $i % 2 ? 'vc_column' : 'vc_row',
				'children' => array( $deep ),
			);
		}
		$this->assert_refused( $this->insert( $id, 0, 'after', $deep ), 'aafm_builder_insert_limit', 400, $id, self::SIMPLE );

		$wide = array(
			'tag'      => 'vc_row',
			'children' => array(
				array(
					'tag'      => 'vc_column',
					'children' => array_fill( 0, 49, self::text_block( '<p>x</p>' ) ),
				),
			),
		);
		$this->assert_refused( $this->insert( $id, 0, 'after', $wide ), 'aafm_builder_insert_limit', 400, $id, self::SIMPLE );
		array_pop( $wide['children'][0]['children'] );
		$this->assertIsArray( $this->insert( $id, 0, 'after', $wide ), '50 elements are allowed.' );
	}

	public function test_insert_refuses_a_stale_anchor(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_insert_element(
			array(
				'post_id'          => $id,
				'page_fingerprint' => $this->page_fp( $id ),
				'anchor_index'     => 2,
				'anchor_expect'    => array(
					'tag'         => 'vc_column_text',
					'fingerprint' => '0000000000000000',
				),
				'placement'        => 'after',
				'element'          => self::text_block( '<p>x</p>' ),
			)
		);
		$this->assert_refused( $out, 'aafm_builder_element_mismatch', 409, $id, self::SIMPLE );
		$partial = aafm_exec_wpbakery_insert_element(
			array(
				'post_id'          => $id,
				'page_fingerprint' => $this->page_fp( $id ),
				'anchor_index'     => 2,
				'element'          => self::text_block( '<p>x</p>' ),
			)
		);
		$this->assert_refused( $partial, 'aafm_wpbakery_anchor_required', 400, $id, self::SIMPLE );
	}

	public function test_an_editor_cannot_insert_raw_html_or_design_options(): void {
		$id = $this->page( self::SIMPLE );
		$this->act_as_restricted_editor();
		$this->assert_refused(
			$this->insert(
				$id,
				2,
				'after',
				array(
					'tag'  => 'vc_raw_html',
					'text' => '<script>x</script>',
				)
			),
			'aafm_builder_needs_unfiltered_html',
			403,
			$id,
			self::SIMPLE
		);
		$this->assert_refused(
			$this->insert(
				$id,
				2,
				'after',
				array(
					'tag'  => 'vc_column_text',
					'atts' => array( 'css' => array( 'margin_top' => '10px' ) ),
					'text' => '<p>x</p>',
				)
			),
			'aafm_builder_needs_unfiltered_html',
			403,
			$id,
			self::SIMPLE
		);
		$this->assert_refused(
			$this->insert(
				$id,
				2,
				'after',
				array(
					'tag'  => 'wpb_btn',
					'atts' => array( 'custom_onclick_code' => 'alert(1)' ),
				)
			),
			'aafm_builder_needs_unfiltered_html',
			403,
			$id,
			self::SIMPLE
		);
	}

	/**
	 * Item 2: insert's body text goes through the one choke point, and its attribute values through the encoder.
	 */
	public function test_insert_body_text_and_attributes_go_through_the_choke_point_and_the_encoder(): void {
		$id = $this->page( self::SIMPLE );
		$this->act_as_restricted_editor();
		$this->assert_refused( $this->insert( $id, 2, 'after', self::text_block( '<p>Footnote [1]</p>' ) ), 'aafm_wpbakery_brackets_need_unfiltered_html', 400, $id, self::SIMPLE );
		$this->assert_refused( $this->insert( $id, 2, 'after', self::text_block( '<p>A %5B b</p>' ) ), 'aafm_wpbakery_encoded_brackets_need_unfiltered_html', 400, $id, self::SIMPLE );
		$this->assert_refused(
			$this->insert(
				$id,
				2,
				'after',
				array(
					'tag'  => 'vc_custom_heading',
					'atts' => array( 'text' => '#E-8_aGk=' ),
				)
			),
			'aafm_builder_invalid_value',
			400,
			$id,
			self::SIMPLE
		);
		$this->assert_refused(
			$this->insert(
				$id,
				2,
				'after',
				array(
					'tag'  => 'vc_custom_heading',
					'atts' => array( 'nope' => 'x' ),
				)
			),
			'aafm_builder_attribute_not_writable',
			400,
			$id,
			self::SIMPLE
		);
		$this->assert_refused(
			$this->insert(
				$id,
				2,
				'after',
				array(
					'tag'  => 'vc_custom_heading',
					'text' => '<p>x</p>',
				)
			),
			'aafm_builder_attribute_not_writable',
			400,
			$id,
			self::SIMPLE
		);

		$this->acting_as( 'administrator' );
		$this->assert_refused( $this->insert( $id, 2, 'after', self::text_block( '<p>x[/vc_column_text]y</p>' ) ), 'aafm_builder_structure_changed', 400, $id, self::SIMPLE );
	}

	public function test_insert_asks_for_one_css_rebuild(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		WpbakeryMapStub::$calls = array();
		$this->assertIsArray( $this->insert( $id, 2, 'after', self::text_block( '<p>x</p>' ) ) );
		$this->assertSame( array( 'rebuild:default', 'rebuild:custom' ), array_values( array_filter( WpbakeryMapStub::$calls, static fn( string $c ): bool => 0 === strpos( $c, 'rebuild:' ) ) ) );
	}

	public function test_insert_starts_a_page_on_an_empty_post(): void {
		$id     = $this->page( '' );
		$filter = $this->editor_types( array( 'page' ) );
		$this->acting_as( 'editor' );
		$out = $this->insert(
			$id,
			null,
			'',
			array(
				'tag'      => 'vc_row',
				'children' => array(
					array(
						'tag'      => 'vc_column',
						'children' => array( self::text_block( '<p>First</p>' ) ),
					),
				),
			)
		);
		remove_filter( 'aafm_builder_vendor', $filter, 20 );
		$this->assertIsArray( $out );
		$this->assertSame( 0, $out['index'] );
		$this->assertSame( 3, $out['inserted'] );
		$this->assertSame( '[vc_row][vc_column][vc_column_text]<p>First</p>[/vc_column_text][/vc_column][/vc_row]', get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( 'wpbakery', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_empty_post_insert_refuses_non_empty_content_another_builders_marker_and_a_type_the_editor_is_off_for(): void {
		$filter = $this->editor_types( array( 'page' ) );
		$row    = array(
			'tag'      => 'vc_row',
			'children' => array( array( 'tag' => 'vc_column' ) ),
		);
		$plain  = $this->page( '<p>Plain</p>' );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->insert( $plain, null, '', $row ), 'aafm_not_wpbakery_owned', 409, $plain, '<p>Plain</p>' );

		$marked = $this->page( '' );
		update_post_meta( $marked, '_elementor_data', '[]' );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->insert( $marked, null, '', $row ), 'aafm_not_wpbakery_owned', 409, $marked, '' );

		$on_page = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->insert( $on_page, null, '', $row ), 'aafm_wpbakery_anchor_required', 400, $on_page, self::SIMPLE );
		remove_filter( 'aafm_builder_vendor', $filter, 20 );

		$off    = $this->editor_types( array( 'post' ) );
		$closed = $this->page( '' );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->insert( $closed, null, '', $row ), 'aafm_wpbakery_editor_off', 409, $closed, '' );
		remove_filter( 'aafm_builder_vendor', $off, 20 );
		$this->assert_refused( $this->insert( $closed, null, '', $row ), 'aafm_wpbakery_editor_off', 409, $closed, '' );
	}

	public function test_insert_tree_keeps_stored_attribute_text_when_trusted(): void {
		$source = '[vc_row gap="0px"][vc_column width="1/2"][vc_custom_heading text="Say ``hi`` `{`x`}`"  ][vc_column_text]

<p>Kept</p>

[/vc_column_text][/vc_column][/vc_row]';
		$tree   = aafm_builder_tree( $source, (array) aafm_wpbakery_shortcode_tags( $source ) );
		$id     = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$content = (string) get_post_field( 'post_content', $id, 'raw' );
		$out     = aafm_wpbakery_insert_tree(
			get_post( $id ),
			$tree,
			array(
				'index'     => 0,
				'expect'    => $this->expect( $id, 0 ),
				'placement' => 'after',
			),
			true
		);
		$this->assertIsArray( $out );
		$this->assertSame( $content . $source, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( 4, $out['inserted'] );
		$this->assertSame( 3, $out['index'] );
	}

	public function test_insert_tree_still_gates_script_content_when_trusted(): void {
		foreach ( array(
			'[vc_raw_html]PGI+[/vc_raw_html]',
			'[vc_raw_js]PGI+[/vc_raw_js]',
			'[vc_gmaps title="Map"]',
			'[wpb_btn title="Go" custom_onclick="true"]',
			'[wpb_btn title="Go" btn_custom_onclick_code="alert(1)"]',
			'[vc_column_text css=".vc_custom_1{margin:0 !important;}"]x[/vc_column_text]',
			'[acme_embed code="x"]',
		) as $element ) {
			$source = '[vc_row][vc_column]' . $element . '[/vc_column][/vc_row]';
			$id     = $this->page( self::SIMPLE );
			$this->act_as_restricted_editor();
			$out = aafm_wpbakery_insert_tree(
				get_post( $id ),
				(array) aafm_builder_tree( $source, (array) aafm_wpbakery_shortcode_tags( $source ) ),
				array(
					'index'     => 0,
					'expect'    => $this->expect( $id, 0 ),
					'placement' => 'after',
				),
				true
			);
			$this->assert_refused( $out, 'aafm_builder_needs_unfiltered_html', 403, $id, self::SIMPLE );
		}
	}

	public function test_move_a_row_above_another_row(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$out = $this->move( $id, 3, 0, 'before' );
		$this->assertIsArray( $out );
		$this->assertTrue( $out['moved'] );
		$this->assertSame( 0, $out['index'] );
		list( $a, $b ) = array( substr( self::TWO_ROWS, 0, strpos( self::TWO_ROWS, '[vc_row el_id="b"]' ) ), substr( self::TWO_ROWS, strpos( self::TWO_ROWS, '[vc_row el_id="b"]' ) ) );
		$this->assertSame( $b . $a, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( substr( hash( 'sha256', $b ), 0, 16 ), $out['fingerprint'] );
		$this->assertIsInt( $out['restore_revision_id'] );
	}

	public function test_move_a_text_block_into_another_column(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$out = $this->move( $id, 2, 4, 'inside_start' );
		$this->assertIsArray( $out );
		$this->assertSame( '[vc_row el_id="a"][vc_column][/vc_column][/vc_row][vc_row el_id="b"][vc_column][vc_column_text]<p>A</p>[/vc_column_text][vc_column_text]<p>B</p>[/vc_column_text][/vc_column][/vc_row]', get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( 4, $out['index'] );
	}

	public function test_move_into_its_own_child_is_refused(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->move( $id, 0, 1, 'inside_end' ), 'aafm_builder_invalid_move', 400, $id, self::TWO_ROWS );
		$this->assert_refused( $this->move( $id, 1, 1, 'inside_end' ), 'aafm_builder_invalid_move', 400, $id, self::TWO_ROWS );
	}

	public function test_move_to_a_wrong_parent_is_refused(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->move( $id, 2, 3, 'after' ), 'aafm_builder_invalid_nesting', 400, $id, self::TWO_ROWS );
	}

	public function test_move_that_empties_a_row_is_refused(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->move( $id, 1, 3, 'inside_end' ), 'aafm_builder_row_needs_column', 400, $id, self::TWO_ROWS );
	}

	/**
	 * CX-S9b-1: a moved tag the map cannot resolve is refused, and a mapped one keeps its own as_child rule, so neither
	 * a flexbox item nor a flexbox container can be moved into a column.
	 */
	public function test_move_refuses_an_unmapped_tag_and_honours_the_moved_elements_as_child(): void {
		$text = '[vc_column_text]<p>A</p>[/vc_column_text]';
		$page = '[vc_row][vc_column]' . $text . '[/vc_column][/vc_row][vc_flexbox_container][vc_flexbox_container_item][vc_column_text]<p>F</p>[/vc_column_text][/vc_flexbox_container_item][/vc_flexbox_container]';
		$id   = $this->page( $page );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->move( $id, 4, 2, 'after' ), 'aafm_builder_unknown_element', 400, $id, $page );
		$this->assert_refused( $this->move( $id, 3, 2, 'after' ), 'aafm_builder_unknown_element', 400, $id, $page );

		WpbakeryMapStub::install( self::container_map() );
		$this->assert_refused( $this->move( $id, 3, 2, 'after' ), 'aafm_builder_invalid_nesting', 400, $id, $page );
		$this->assert_refused( $this->move( $id, 4, 2, 'after' ), 'aafm_builder_invalid_nesting', 400, $id, $page );
		$this->assert_refused( $this->insert( $id, 2, 'after', array( 'tag' => 'vc_flexbox_container' ) ), 'aafm_builder_invalid_nesting', 400, $id, $page );
		WpbakeryMapStub::install();
		$this->assert_refused( $this->insert( $id, 2, 'after', array( 'tag' => 'vc_flexbox_container_item' ) ), 'aafm_builder_unknown_element', 400, $id, $page );
	}

	/**
	 * Demo #6b (CA-14): two grid items in an unmapped grid container reorder, since a same-parent move needs no map
	 * entry; demo #6a: adding a flexbox item is refused with a message that names the flexbox and grid limitation.
	 */
	public function test_grid_items_reorder_and_the_flexbox_refusal_names_the_limitation(): void {
		$one  = '[vc_grid_container_item][vc_column_text]<p>One</p>[/vc_column_text][/vc_grid_container_item]';
		$two  = '[vc_grid_container_item][vc_column_text]<p>Two</p>[/vc_column_text][/vc_grid_container_item]';
		$page = '[vc_grid_container]' . $one . $two . '[/vc_grid_container]';
		$id   = $this->page( $page );
		$this->acting_as( 'editor' );
		$out = $this->move( $id, 3, 1, 'before' );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertSame( '[vc_grid_container]' . $two . $one . '[/vc_grid_container]', get_post_field( 'post_content', $id, 'raw' ) );
		$refused = aafm_wpbakery_map( 'page', 'vc_flexbox_container_item' );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'aafm_builder_unknown_element', $refused->get_error_code() );
		$this->assertStringContainsString( 'flexbox and grid', $refused->get_error_message() );
		$this->assertStringNotContainsString( 'flexbox', aafm_wpbakery_map( 'page', 'acme_unknown' )->get_error_message() );
	}

	/**
	 * R3-1: a deprecated leaf WPBakery maps with content_element false (vc_gmaps, vc_facebook, vc_cta_button) is still
	 * content, so it moves between columns and reorders in its own column.
	 */
	public function test_move_and_reorder_deprecated_leaf_elements(): void {
		$map = self::container_map();
		foreach ( array( 'vc_gmaps', 'vc_facebook', 'vc_cta_button' ) as $leaf ) {
			$map[ $leaf ] = array( 'content_element' => false ) + ( $map[ $leaf ] ?? array() );
		}
		WpbakeryMapStub::install( $map );
		foreach ( array( 'vc_gmaps', 'vc_facebook', 'vc_cta_button' ) as $leaf ) {
			$el   = '[' . $leaf . ' title="x"]';
			$page = '[vc_row][vc_column]' . $el . '[vc_column_text]<p>A</p>[/vc_column_text][/vc_column][vc_column][vc_column_text]<p>B</p>[/vc_column_text][/vc_column][/vc_row]';
			$id   = $this->page( $page );
			$this->acting_as( 'editor' );
			$this->assertIsArray( $this->move( $id, 2, 3, 'after' ), $leaf . ' reorder' );
			$this->assertIsArray( $this->move( $id, 3, 5, 'after' ), $leaf . ' into the other column' );
			$this->assertStringContainsString( '<p>B</p>[/vc_column_text]' . $el . '[/vc_column]', (string) get_post_field( 'post_content', $id, 'raw' ) );
		}
	}

	/**
	 * R3-1: a Grid Builder column reorders inside its row; a reorder keeps the parent, so it needs no nesting rule.
	 */
	public function test_reorder_grid_builder_columns_inside_their_row(): void {
		register_post_type(
			'vc_grid_item',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'supports'        => array( 'title', 'editor' ),
			)
		);
		WpbakeryMapStub::install(
			self::container_map() + array(
				'vc_gitem'        => array( 'is_container' => true ),
				'vc_gitem_zone_c' => array( 'is_container' => true ),
				'vc_gitem_row'    => array(
					'is_container'    => true,
					'content_element' => false,
				),
				'vc_gitem_col'    => array(
					'is_container'    => true,
					'content_element' => false,
				),
			)
		);
		$one     = '[vc_gitem_col width="1/2"][vc_column_text]<p>a</p>[/vc_column_text][/vc_gitem_col]';
		$two     = '[vc_gitem_col width="1/2"][/vc_gitem_col]';
		$content = '[vc_gitem][vc_gitem_zone_c][vc_gitem_row]' . $one . $two . '[/vc_gitem_row][/vc_gitem_zone_c][/vc_gitem]';
		$this->acting_as( 'editor' );
		$id  = self::factory()->post->create(
			array(
				'post_type'    => 'vc_grid_item',
				'post_content' => $content,
			)
		);
		$out = $this->move( $id, 5, 3, 'before' );
		$this->assertIsArray( $out );
		$this->assertSame( str_replace( $one . $two, $two . $one, $content ), get_post_field( 'post_content', $id, 'raw' ) );
	}

	public function test_move_to_the_same_place_saves_nothing(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$revisions = count( wp_get_post_revisions( $id ) );
		$out       = $this->move( $id, 3, 0, 'after' );
		$this->assertIsArray( $out );
		$this->assertFalse( $out['moved'] );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertSame( self::TWO_ROWS, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $id ) ) );
	}

	public function test_move_and_remove_refuse_a_stale_page_fingerprint(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->move( $id, 3, 0, 'before', '0000000000000000' ), 'aafm_builder_page_changed', 409, $id, self::TWO_ROWS );
		$this->assert_refused( $this->remove( $id, 2, '0000000000000000' ), 'aafm_builder_page_changed', 409, $id, self::TWO_ROWS );
	}

	public function test_remove_a_leaf(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$out = $this->remove( $id, 2 );
		$this->assertIsArray( $out );
		$this->assertSame( array( 'post', 'removed', 'page_fingerprint', 'restore_revision_id' ), array_keys( $out ) );
		$this->assertSame( 1, $out['removed'] );
		$this->assertSame( str_replace( '[vc_column_text]<p>A</p>[/vc_column_text]', '', self::TWO_ROWS ), get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( self::TWO_ROWS, get_post( $out['restore_revision_id'] )->post_content );
	}

	public function test_remove_a_row_with_its_children(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		WpbakeryMapStub::$calls = array();
		$out                    = $this->remove( $id, 3 );
		$this->assertIsArray( $out );
		$this->assertSame( 3, $out['removed'] );
		$this->assertSame( substr( self::TWO_ROWS, 0, strpos( self::TWO_ROWS, '[vc_row el_id="b"]' ) ), get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertContains( 'rebuild:default', WpbakeryMapStub::$calls );
	}

	public function test_remove_the_last_column_is_refused(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->remove( $id, 1 ), 'aafm_builder_row_needs_column', 400, $id, self::TWO_ROWS );
	}

	public function test_remove_the_last_root_row_is_refused(): void {
		$id = $this->page( self::SIMPLE );
		$this->acting_as( 'editor' );
		$this->assert_refused( $this->remove( $id, 0 ), 'aafm_builder_remove_last_root', 409, $id, self::SIMPLE );
	}

	/**
	 * R1-1: every root kind the ownership guard reads counts, so the last one of any kind is refused.
	 */
	public function test_remove_refuses_the_last_root_of_each_kind(): void {
		$item = '[vc_column_text]<p>F</p>[/vc_column_text]';
		foreach ( array(
			'[vc_section][vc_row][vc_column]' . $item . '[/vc_column][/vc_row][/vc_section]',
			'[vc_flexbox_container][vc_flexbox_container_item]' . $item . '[/vc_flexbox_container_item][/vc_flexbox_container]',
			'[vc_grid_container][vc_grid_container_item]' . $item . '[/vc_grid_container_item][/vc_grid_container]',
		) as $content ) {
			$id = $this->page( $content );
			$this->assertSame( 'wpbakery', aafm_post_has_foreign_builder_ownership( $id ) );
			$this->acting_as( 'editor' );
			$this->assert_refused( $this->remove( $id, 0 ), 'aafm_builder_remove_last_root', 409, $id, $content );
		}
		$flex  = '[vc_flexbox_container][vc_flexbox_container_item]' . $item . '[/vc_flexbox_container_item][/vc_flexbox_container]';
		$mixed = $this->page( self::SIMPLE . $flex );
		$this->acting_as( 'editor' );
		$this->assertIsArray( $this->remove( $mixed, 0 ), 'A flexbox root still holds the page.' );
		$this->assertSame( $flex, get_post_field( 'post_content', $mixed, 'raw' ) );
	}

	/**
	 * R2-1: with one revision kept, core deletes the restore point inside the save. Remove refuses before writing; the
	 * other writes save and say there is no way back, naming the limit. Two kept is enough.
	 */
	public function test_a_one_revision_limit_refuses_remove_and_is_reported_by_the_other_writes(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$one      = static fn(): int => 1;
		add_filter( 'wp_revisions_to_keep', $one );
		$removed  = $this->remove( $id, 2 );
		$inserted = $this->insert( $id, 2, 'after', self::text_block( '<p>N</p>' ) );
		$moved    = $this->move( $id, 3, 2, 'before' );
		$updated  = $this->update( $id, 2, array( 'text' => '<p>U</p>' ) );
		remove_filter( 'wp_revisions_to_keep', $one );

		$this->assert_error( $removed, 'aafm_builder_remove_irreversible', 409 );
		$this->assertStringContainsString( 'keeps only 1 revision', $removed->get_error_message() );
		foreach ( array(
			'insert' => $inserted,
			'move'   => $moved,
			'update' => $updated,
		) as $tool => $out ) {
			$this->assertIsArray( $out, $tool );
			$this->assertFalse( $out['reversible'], $tool );
			$this->assertNull( $out['restore_revision_id'], $tool );
			$this->assertStringContainsString( 'no longer there after the save', $out['reversible_reason'], $tool );
		}

		$two    = static fn(): int => 2;
		add_filter( 'wp_revisions_to_keep', $two );
		$before = (string) get_post_field( 'post_content', $id, 'raw' );
		$out    = $this->remove( $id, 2 );
		remove_filter( 'wp_revisions_to_keep', $two );
		$this->assertIsArray( $out );
		$this->assertSame( $before, get_post( $out['restore_revision_id'] )->post_content );
		$this->assertArrayNotHasKey( 'reversible_reason', $out );
	}

	public function test_remove_is_refused_when_revisions_are_off(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		$out = $this->remove( $id, 2 );
		remove_filter( 'wp_revisions_to_keep', '__return_zero' );
		$this->assert_refused( $out, 'aafm_builder_remove_irreversible', 409, $id, self::TWO_ROWS );
	}

	public function test_remove_is_refused_on_a_grid_item(): void {
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
		$id       = self::factory()->post->create(
			array(
				'post_type'    => 'vc_grid_item',
				'post_content' => $content,
			)
		);
		$elements = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) );
		$index    = (int) array_search( 'vc_btn', array_column( $elements, 'tag' ), true );
		$this->assert_refused( $this->remove( $id, $index ), 'aafm_builder_remove_irreversible', 409, $id, $content );
	}

	public function test_remove_refuses_a_stale_fingerprint_after_a_child_changed(): void {
		$id = $this->page( self::TWO_ROWS );
		$this->acting_as( 'editor' );
		$stale = $this->expect( $id, 3 );
		$this->assertIsArray( $this->update( $id, 5, array( 'text' => '<p>Changed</p>' ) ) );
		$content = (string) get_post_field( 'post_content', $id, 'raw' );
		$this->assert_refused( $this->remove( $id, 3, null, $stale ), 'aafm_builder_element_mismatch', 409, $id, $content );
	}

	public function test_structural_tools_permissions_discovery_undo_and_audit(): void {
		$id = $this->page( self::SIMPLE, self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( array(
			'insert' => 'insertion',
			'move'   => 'move',
			'remove' => 'removal',
		) as $tool => $noun ) {
			$name = 'aafm/wpbakery-' . $tool . '-element';
			$args = call_user_func( 'aafm_args_wpbakery_' . $tool . '_element' );
			$this->assertSame( 'aafm_perm_avada_post_object', $args['permission_callback'] );
			$this->acting_as( 'contributor' );
			$this->assertFalse( aafm_perm_avada_post_object( array( 'post_id' => $id ) ) );
			$check = aafm_ability_list_permission( $name );
			$this->assertIsCallable( $check, $name );
			$this->acting_as( 'editor' );
			$this->assertTrue( $check() );
			$this->acting_as( 'subscriber' );
			$this->assertFalse( $check() );
			$this->assertSame( 'not_covered', aafm_undo_refusals()[ $name ] );
			$this->assertStringContainsString( 'Ran an element ' . ( 'insert' === $tool ? 'insert' : $noun ) . ' on WPBakery page #42', (string) aafm_build_activity_detail( $name, array( 'post_id' => 42 ) ) );
		}
	}

	public function test_structural_tool_schemas_are_closed(): void {
		$required = array(
			'insert' => array( 'post_id', 'page_fingerprint', 'element' ),
			'move'   => array( 'post_id', 'page_fingerprint', 'index', 'expect', 'anchor_index', 'anchor_expect', 'placement' ),
			'remove' => array( 'post_id', 'page_fingerprint', 'index', 'expect' ),
		);
		foreach ( $required as $tool => $fields ) {
			$args   = call_user_func( 'aafm_args_wpbakery_' . $tool . '_element' );
			$schema = $args['input_schema'];
			$this->assertFalse( $schema['additionalProperties'], $tool );
			$this->assertSame( $fields, $schema['required'], $tool );
			$this->assertSame( '^[0-9a-f]{16}$', $schema['properties']['page_fingerprint']['pattern'] );
			$this->assertArrayNotHasKey( 'anyOf', $schema );
			$this->assertSame( 'aafm-writes', $args['category'] );
			$this->assertSame(
				array(
					'readonly'    => false,
					'destructive' => 'remove' === $tool,
				),
				$args['meta']['annotations']
			);
		}
		$element = aafm_args_wpbakery_insert_element()['input_schema']['properties']['element'];
		$this->assertFalse( $element['additionalProperties'] );
		$this->assertSame( array( 'tag' ), $element['required'] );
		$this->assertSame( array( 'before', 'after', 'inside_start', 'inside_end' ), aafm_args_wpbakery_move_element()['input_schema']['properties']['placement']['enum'] );
	}

	/**
	 * R1-3: a Grid Builder template and a site with revisions off keep no revision, so the disclosures promise one only
	 * when revisions are on.
	 */
	public function test_insert_and_move_disclosures_promise_a_revision_only_when_revisions_are_on(): void {
		foreach ( array( 'insert', 'move' ) as $tool ) {
			$text = aafm_ability_disclosures()[ 'aafm/wpbakery-' . $tool . '-element' ];
			$this->assertStringContainsString( 'when revisions are on', $text, $tool );
			$this->assertStringNotContainsString( '; a revision is kept so', $text, $tool );
		}
	}

	/**
	 * Insert stored vendor text through the trusted branch, as the template tool does.
	 *
	 * @param int    $id     Post.
	 * @param string $source Stored shortcode text.
	 * @return array<string,mixed>|WP_Error
	 */
	private function insert_trusted( int $id, string $source ) {
		$tree = aafm_builder_tree( $source, (array) aafm_wpbakery_shortcode_tags( $source ) );
		$this->assertIsArray( $tree );
		return aafm_wpbakery_insert_tree(
			get_post( $id ),
			$tree,
			array(
				'index'     => 0,
				'expect'    => $this->expect( $id, 0 ),
				'placement' => 'after',
			),
			true
		);
	}

	/**
	 * SEC-S9b-2 (Ruling R-S11-1): stored template text is untrusted, since a contributor can save a template. For a
	 * user without unfiltered_html, the trusted branch puts each element's own text through the body text checks and
	 * refuses text kses would change; each refusal leaves the page byte-identical.
	 */
	public function test_trusted_text_gets_the_body_text_checks_without_unfiltered_html(): void {
		$cases = array(
			'<p>Hi</p><script>alert(1)</script>'      => 'aafm_wpbakery_text_needs_unfiltered_html',
			'<p onclick="alert(1)">Hi</p>'            => 'aafm_wpbakery_text_needs_unfiltered_html',
			'<p data-x="1">Hi</p>'                    => 'aafm_wpbakery_data_attributes_need_unfiltered_html',
			'<p>%5Bvc_raw_js%5D</p>'                  => 'aafm_wpbakery_encoded_brackets_need_unfiltered_html',
			'<p>&#91;vc_raw_js&#93;</p>'              => 'aafm_wpbakery_brackets_need_unfiltered_html',
			'<p>Hi</p><img src="x" onerror="alert(1)' => 'aafm_wpbakery_incomplete_html_needs_unfiltered_html',
		);
		foreach ( $cases as $text => $code ) {
			$id     = $this->page( self::SIMPLE );
			$source = '[vc_row][vc_column][vc_column_text]' . $text . '[/vc_column_text][/vc_column][/vc_row]';
			$this->act_as_restricted_editor();
			$this->assert_refused( $this->insert_trusted( $id, $source ), $code, 'aafm_wpbakery_text_needs_unfiltered_html' === $code ? 403 : 400, $id, self::SIMPLE );

			$this->acting_as( 'administrator' );
			$this->assertIsArray( $this->insert_trusted( $id, $source ), $text );
			$this->assertSame( self::SIMPLE . $source, get_post_field( 'post_content', $id, 'raw' ), $text );
		}
	}

	public function test_trusted_text_between_children_is_checked_too(): void {
		$id     = $this->page( self::SIMPLE );
		$source = '[vc_row][vc_column]<script>alert(1)</script>[vc_column_text]<p>A</p>[/vc_column_text][/vc_column][/vc_row]';
		$this->act_as_restricted_editor();
		$this->assert_refused( $this->insert_trusted( $id, $source ), 'aafm_wpbakery_text_needs_unfiltered_html', 403, $id, self::SIMPLE );
	}

	public function test_clean_trusted_text_inserts_unchanged_for_a_restricted_editor(): void {
		$id     = $this->page( self::SIMPLE );
		$source = '[vc_row][vc_column][vc_column_text]<p style="text-align: center;">A &amp; B <a href="https://example.com/">link</a></p>[/vc_column_text][/vc_column][/vc_row]';
		$this->act_as_restricted_editor();
		$this->assertIsArray( $this->insert_trusted( $id, $source ) );
		// Core's own save runs kses for this user, which drops the last semicolon of a style rule it keeps.
		$this->assertSame( self::SIMPLE . str_replace( 'center;', 'center', $source ), get_post_field( 'post_content', $id, 'raw' ) );
	}

	/**
	 * CX-S9b-R2-1: every tool that can return reversible_reason declares it as an optional string.
	 */
	public function test_output_schemas_declare_reversible_reason(): void {
		foreach ( array( 'update', 'insert', 'move', 'remove' ) as $tool ) {
			$schema = call_user_func( 'aafm_args_wpbakery_' . $tool . '_element' )['output_schema'];
			$this->assertSame( 'string', $schema['properties']['reversible_reason']['type'] ?? null, $tool );
			$this->assertNotContains( 'reversible_reason', (array) ( $schema['required'] ?? array() ), $tool );
		}
	}

	public function test_structural_tool_descriptions_say_what_the_design_requires(): void {
		$registry = aafm_get_abilities_registry_full();
		foreach ( array( 'insert', 'move', 'remove' ) as $tool ) {
			$name = 'aafm/wpbakery-' . $tool . '-element';
			$row  = $registry[ $name ];
			$this->assertSame( 'writes', $row['group'] );
			$this->assertSame( 'remove' === $tool ? 'destructive' : 'write', $row['risk'] );
			$this->assertSame( 'wpbakery', $row['subject'] );
			foreach ( array( $row['description'], aafm_ability_disclosures()[ $name ] ) as $text ) {
				$this->assertStringNotContainsString( ' - ', $text );
				foreach ( array( 'Visual Composer', 'Avada', 'Elementor' ) as $other ) {
					$this->assertStringNotContainsString( $other, $text );
				}
				foreach ( array( 'edit access', 'page_fingerprint', 'restore', 'Role Manager' ) as $phrase ) {
					$this->assertStringContainsString( $phrase, $text, $name );
				}
			}
			$this->assertStringContainsString( 'Only while WPBakery Page Builder is active', $row['description'] );
		}
		$this->assertStringContainsString( 'WPBakery\'s own setting', $registry['aafm/wpbakery-insert-element']['description'] );
		$this->assertStringContainsString( 'unfiltered HTML', $registry['aafm/wpbakery-insert-element']['description'] );
	}
}
