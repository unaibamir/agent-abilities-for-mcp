<?php
/**
 * A stand-in for WPBakery's element map, supplied through the aafm_builder_vendor filter (Ruling B14): no
 * vendor class or function is defined. The entries copy the shape and the param types of WPBakery 9.1's own
 * configs for the elements on tests/Fixtures/Wpbakery/page-elements.txt, plus a few rows that exercise the
 * kinds the fixture does not carry. The same map answers wpbakery_param_type, so a decode or a kind lookup
 * goes through the element map and never through the decoder's shape fallback.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

final class WpbakeryMapStub {

	/**
	 * Seam calls in order, for tests that check order (the grid mapper first) or a rebuild.
	 *
	 * @var list<string>
	 */
	public static $calls = array();

	/**
	 * The filter currently installed.
	 *
	 * @var callable|null
	 */
	private static $filter = null;

	/**
	 * The element map: tag => settings, as WPBMap::getShortCode() returns them.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function map(): array {
		$css      = array(
			'type'       => 'css_editor',
			'param_name' => 'css',
			'heading'    => 'CSS box',
		);
		$el_class = array(
			'type'       => 'textfield',
			'param_name' => 'el_class',
			'heading'    => 'Extra class name',
		);
		$button   = array(
			array(
				'type'       => 'textfield',
				'param_name' => 'title',
				'heading'    => 'Text',
				'value'      => 'Text on the button',
			),
			array(
				'type'       => 'link',
				'param_name' => 'link',
				'heading'    => 'URL',
			),
			array(
				'type'       => 'dropdown',
				'param_name' => 'shape',
				'heading'    => 'Shape',
				'value'      => array(
					'Rounded' => 'rounded',
					'Square'  => 'square',
					'Round'   => 'round',
				),
			),
			array(
				'type'       => 'button_group',
				'param_name' => 'align',
				'heading'    => 'Alignment',
				'value'      => array(
					'left'   => array( 'label' => 'vc-c-alignment-left' ),
					'center' => array( 'label' => 'vc-c-alignment-center' ),
				),
			),
			array(
				'type'       => 'colorpicker',
				'param_name' => 'custom_background',
				'heading'    => 'Background',
			),
			array(
				'type'       => 'toggle',
				'param_name' => 'button_block',
				'heading'    => 'Full width',
			),
			array(
				'type'       => 'toggle',
				'param_name' => 'custom_onclick',
				'heading'    => 'Onclick action',
			),
			array(
				'type'       => 'textfield',
				'param_name' => 'custom_onclick_code',
				'heading'    => 'On click code',
			),
			array(
				'type'       => 'textfield',
				'param_name' => 'btn_custom_onclick_code',
				'heading'    => 'Prefixed on click code',
			),
			array(
				'type'       => 'iconpicker',
				'param_name' => 'i_icon_fontawesome',
				'heading'    => 'Icon',
			),
			$css,
			$el_class,
		);
		return array(
			'vc_row'            => array(
				'name'         => 'Row',
				'category'     => 'Content',
				'is_container' => true,
				'params'       => array(
					array(
						'type'       => 'dropdown',
						'param_name' => 'gap',
						'heading'    => 'Columns gap',
						'value'      => array(
							'0px'  => '0px',
							'1px'  => '1px',
							'10px' => '10px',
						),
					),
					$el_class,
					$css,
				),
			),
			'vc_column'         => array(
				'name'         => 'Column',
				'is_container' => true,
				'as_child'     => array( 'only' => 'vc_row' ),
				'params'       => array(
					array(
						'type'       => 'dropdown',
						'param_name' => 'width',
						'heading'    => 'Width',
						'value'      => array(
							'1 column - 1/1'  => '1/1',
							'2 columns - 1/2' => '1/2',
						),
					),
					array(
						'type'       => 'column_offset',
						'param_name' => 'offset',
						'heading'    => 'Responsiveness',
					),
					$css,
				),
			),
			'vc_row_inner'      => array(
				'name'         => 'Inner Row',
				'is_container' => true,
				'params'       => array( $css ),
			),
			'vc_column_inner'   => array(
				'name'         => 'Inner Column',
				'is_container' => true,
				'as_child'     => array( 'only' => 'vc_row_inner' ),
				'params'       => array( $css ),
			),
			'vc_column_text'    => array(
				'name'     => 'Text Block',
				'category' => 'Content',
				'params'   => array(
					array(
						'type'       => 'textarea_html',
						'param_name' => 'content',
						'heading'    => 'Text',
						'value'      => '<p>I am text block.</p>',
					),
					$el_class,
					$css,
				),
			),
			'vc_custom_heading' => array(
				'name'     => 'Custom Heading',
				'category' => 'Content',
				'params'   => array(
					array(
						'type'       => 'textarea',
						'param_name' => 'text',
						'heading'    => 'Text',
						'value'      => 'This is custom heading element',
					),
					array(
						'type'       => 'link',
						'param_name' => 'link',
						'heading'    => 'URL (Link)',
					),
					array(
						'type'       => 'font_container',
						'param_name' => 'font_container',
						'settings'   => array(
							'fields' => array(
								'tag'         => 'h2',
								'text_align'  => '',
								'font_size'   => '',
								'line_height' => '',
								'color'       => '',
							),
						),
					),
					array(
						'type'       => 'google_fonts',
						'param_name' => 'google_fonts',
					),
					$el_class,
					$css,
				),
			),
			'wpb_btn'           => array(
				'name'     => 'Button',
				'category' => 'Content',
				'params'   => $button,
			),
			'vc_btn'            => array(
				'name'       => 'Button (old)',
				'category'   => 'Deprecated',
				'deprecated' => '9.0',
				'params'     => $button,
			),
			'vc_single_image'   => array(
				'name'   => 'Single Image',
				'params' => array(
					array(
						'type'       => 'attach_image',
						'param_name' => 'image',
						'heading'    => 'Image',
					),
					$css,
				),
			),
			'vc_gallery'        => array(
				'name'   => 'Image Gallery',
				'params' => array(
					array(
						'type'       => 'attach_images',
						'param_name' => 'images',
						'heading'    => 'Images',
					),
					array(
						'type'       => 'dropdown',
						'param_name' => 'onclick',
						'heading'    => 'On click action',
						'value'      => array(
							'None'                => '',
							'Link to large image' => 'img_link_large',
							'Open custom link'    => 'custom_link',
						),
					),
					array(
						'type'       => 'dropdown',
						'param_name' => 'interval',
						'heading'    => 'Auto rotate',
						'value'      => array(
							3,
							5,
							10,
							'Disable' => 0,
						),
					),
					array(
						'type'       => 'exploded_textarea_safe',
						'param_name' => 'custom_links',
						'heading'    => 'Custom links',
					),
					array(
						'type'       => 'exploded_textarea',
						'param_name' => 'captions',
						'heading'    => 'Captions',
					),
				),
			),
			'vc_progress_bar'   => array(
				'name'   => 'Progress Bar',
				'params' => array(
					array(
						'type'       => 'param_group',
						'param_name' => 'values',
						'heading'    => 'Values',
						'params'     => array(
							array(
								'type'       => 'textfield',
								'param_name' => 'label',
							),
							array(
								'type'       => 'number',
								'param_name' => 'value',
								'settings'   => array(
									'min' => 0,
									'max' => 100,
								),
							),
						),
					),
					$css,
				),
			),
			'vc_raw_html'       => array(
				'name'   => 'Raw HTML',
				'params' => array(
					array(
						'type'       => 'textarea_ace',
						'param_name' => 'content',
						'heading'    => 'Raw HTML',
					),
					$el_class,
					$css,
				),
			),
			'vc_raw_js'         => array(
				'name'   => 'Raw JS',
				'params' => array(
					array(
						'type'       => 'textarea_ace',
						'param_name' => 'content',
						'heading'    => 'JavaScript Code',
					),
					$el_class,
				),
			),
			'vc_gmaps'          => array(
				'name'   => 'Google Maps',
				'params' => array(
					array(
						'type'       => 'textfield',
						'param_name' => 'title',
						'heading'    => 'Widget title',
					),
					array(
						'type'       => 'textarea_safe',
						'param_name' => 'link',
						'heading'    => 'Map embed iframe',
					),
				),
			),
			'vc_separator'      => array(
				'name'   => 'Separator',
				'params' => array(
					array(
						'type'       => 'number',
						'param_name' => 'border_width',
						'heading'    => 'Border width',
						'value'      => '1',
						'settings'   => array(
							'min' => 1,
							'max' => 10,
						),
					),
				),
			),
			'acme_embed'        => array(
				'name'   => 'Acme Embed',
				'params' => array(
					array(
						'type'       => 'textfield',
						'param_name' => 'title',
						'heading'    => 'Title',
					),
					array(
						'type'       => 'textarea_raw_html',
						'param_name' => 'code',
						'heading'    => 'Embed code',
					),
				),
			),
			'acme_odd'          => array(
				'name'   => 'Acme Odd',
				'params' => array(
					'not a param',
					array( 'type' => 'textfield' ),
					array(
						'type'       => 'autocomplete',
						'param_name' => 'ids',
					),
					array(
						'type'       => 'hidden',
						'param_name' => 'secret',
					),
					array(
						'type'       => 'loop',
						'param_name' => 'query',
					),
					array(
						'type'       => 'sorted_list',
						'param_name' => 'order',
					),
					array(
						'type'       => 'dropdown',
						'param_name' => 'empty_choice',
						'value'      => array(),
					),
					array(
						'type'       => 'textfield',
						'param_name' => 'label',
						'heading'    => '<b>Label</b> text',
					),
				),
			),
		);
	}

	/**
	 * Installs the stub: the map, one element, the param type and the grid mapper, plus a recording CSS rebuild.
	 *
	 * @param array<string,array<string,mixed>>|null $map         Map to serve, default map().
	 * @param array<string>                          $throws_for  Tags whose resolution throws.
	 * @param array<string>                          $absent      Entries to report absent.
	 * @return void
	 */
	public static function install( ?array $map = null, array $throws_for = array(), array $absent = array() ): void {
		self::remove();
		self::$calls  = array();
		$map          = $map ?? self::map();
		self::$filter = static function ( $resolved, string $entry ) use ( $map, $throws_for, $absent ) {
			if ( in_array( $entry, $absent, true ) ) {
				return null;
			}
			switch ( $entry ) {
				case 'wpbakery_map_all':
					return static function () use ( $map ): array {
						self::$calls[] = 'map_all';
						return $map;
					};
				case 'wpbakery_map_one':
					return static function ( string $tag ) use ( $map, $throws_for ): ?array {
						self::$calls[] = 'map_one:' . $tag;
						if ( in_array( $tag, $throws_for, true ) ) {
							throw new \RuntimeException( 'map entry failed' );
						}
						return $map[ $tag ] ?? null;
					};
				case 'wpbakery_param_type':
					return static function ( string $tag, string $name ) use ( $map ): ?string {
						foreach ( (array) ( $map[ $tag ]['params'] ?? array() ) as $param ) {
							if ( is_array( $param ) && ( $param['param_name'] ?? null ) === $name ) {
								return (string) $param['type'];
							}
						}
						return null;
					};
				case 'wpbakery_grid_mapper':
					return static function (): void {
						self::$calls[] = 'grid_mapper';
					};
				case 'wpbakery_css_rebuild':
					return static function ( int $id, string $type ): void {
						self::$calls[] = 'rebuild:' . $type;
					};
			}
			return $resolved;
		};
		add_filter( 'aafm_builder_vendor', self::$filter, 10, 2 );
	}

	/**
	 * Removes the stub.
	 *
	 * @return void
	 */
	public static function remove(): void {
		if ( null !== self::$filter ) {
			remove_filter( 'aafm_builder_vendor', self::$filter, 10 );
			self::$filter = null;
		}
	}
}
