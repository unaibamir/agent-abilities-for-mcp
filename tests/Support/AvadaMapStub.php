<?php
/**
 * A stand-in for Avada Builder's element map, supplied through the aafm_builder_vendor filter (Ruling B14): no
 * vendor class or function is defined. The entries copy the shape Avada Builder 3.16.1's fusion_builder_map() leaves
 * outside wp-admin (params a plain list, each with param_name and type, A25) and the param types of its own element
 * files for the elements on tests/Fixtures/Avada/page-elements.txt, plus rows for the kinds the fixture does not carry.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

final class AvadaMapStub {

	/**
	 * Seam calls in order.
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
	 * The element map: tag => entry, as $all_fusion_builder_elements holds it after the loader ran.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function map(): array {
		$text     = static fn( string $name, string $heading = '' ): array => array(
			'type'       => 'textfield',
			'param_name' => $name,
			'heading'    => '' === $heading ? $name : $heading,
			'value'      => '',
		);
		$class_id = array(
			array(
				'type'       => 'textfield',
				'param_name' => 'class',
				'heading'    => 'CSS Class',
				'value'      => '',
			),
			array(
				'type'       => 'textfield',
				'param_name' => 'id',
				'heading'    => 'CSS ID',
				'value'      => '',
			),
		);
		return array(
			'fusion_builder_container'    => array(
				'name'      => 'Container',
				'shortcode' => 'fusion_builder_container',
				'params'    => array(
					array(
						'type'       => 'radio_button_set',
						'param_name' => 'type',
						'heading'    => 'Container Layout',
						'default'    => 'flex',
						'value'      => array(
							'legacy' => 'Legacy',
							'flex'   => 'Flex',
						),
					),
					array(
						'type'       => 'dimension',
						'param_name' => 'spacing_padding',
						'heading'    => 'Padding',
						'value'      => array(
							'padding_top'    => '',
							'padding_right'  => '',
							'padding_bottom' => '',
							'padding_left'   => '',
						),
					),
					array(
						'type'       => 'colorpickeralpha',
						'param_name' => 'background_color',
						'heading'    => 'Background Color',
						'value'      => '',
					),
					array(
						'type'       => 'date_time_picker',
						'param_name' => 'publish_date',
						'heading'    => 'Publish Date',
						'value'      => '',
					),
				),
			),
			'fusion_builder_row'          => array(
				'name'      => 'Row',
				'shortcode' => 'fusion_builder_row',
				'params'    => array(),
			),
			'fusion_builder_column'       => array(
				'name'      => 'Column',
				'shortcode' => 'fusion_builder_column',
				'params'    => array(
					array(
						'type'       => 'column_width',
						'param_name' => 'type',
						'heading'    => 'Width',
						'default'    => '0',
					),
					$text( 'layout' ),
					array(
						'type'       => 'link_selector',
						'param_name' => 'link',
						'heading'    => 'Link URL',
						'value'      => '',
					),
				),
			),
			'fusion_title'                => array(
				'name'         => 'Title',
				'shortcode'    => 'fusion_title',
				'subparam_map' => array( 'fusion_font_family_title_font' => 'title_typography' ),
				'params'       => array(
					array(
						'type'       => 'tinymce',
						'param_name' => 'element_content',
						'heading'    => 'Title',
						'value'      => 'Your Content Goes Here',
					),
					array(
						'type'       => 'radio_button_set',
						'param_name' => 'size',
						'heading'    => 'HTML Heading Size',
						'default'    => '1',
						'value'      => array(
							'1' => 'H1',
							'2' => 'H2',
							'3' => 'H3',
						),
					),
					array(
						'type'       => 'typography',
						'param_name' => 'title_typography',
						'heading'    => 'Typography',
					),
					array(
						'type'       => 'colorpickeralpha',
						'param_name' => 'text_color',
						'heading'    => 'Font Color',
						'value'      => '',
					),
				),
			),
			'fusion_text'                 => array(
				'name'      => 'Text Block',
				'shortcode' => 'fusion_text',
				'params'    => array(
					array(
						'type'       => 'tinymce',
						'param_name' => 'element_content',
						'heading'    => 'Content',
						'value'      => 'Your Content Goes Here',
					),
					array(
						'type'       => 'range',
						'param_name' => 'columns',
						'heading'    => 'Number of Inline Columns',
						'default'    => '1',
						'min'        => '1',
						'max'        => '6',
						'value'      => '',
					),
				),
			),
			'fusion_button'               => array(
				'name'         => 'Button',
				'shortcode'    => 'fusion_button',
				'subparam_map' => array(
					'fusion_font_family_button_font'  => 'main_typography',
					'fusion_font_variant_button_font' => 'main_typography',
					'font_size'                       => 'main_typography',
				),
				'params'       => array_merge(
					array(
						array(
							'type'       => 'link_selector',
							'param_name' => 'link',
							'heading'    => 'Button URL',
							'value'      => '',
						),
						array(
							'type'       => 'textfield',
							'param_name' => 'element_content',
							'heading'    => 'Button Text',
							'value'      => 'Button Text',
						),
						$text( 'title', 'Button Title Attribute' ),
						array(
							'type'       => 'radio_button_set',
							'param_name' => 'target',
							'heading'    => 'Button Target',
							'default'    => '_self',
							'value'      => array(
								'_self'    => '_self',
								'_blank'   => '_blank',
								'lightbox' => 'Lightbox',
							),
						),
						$text( 'link_attributes', 'Button Additional Attributes' ),
						array(
							'type'       => 'colorpickeralpha',
							'param_name' => 'accent_color',
							'heading'    => 'Text Color',
							'value'      => '',
						),
						array(
							'type'       => 'range',
							'param_name' => 'gradient_start_position',
							'heading'    => 'Gradient Start Position',
							'default'    => '0',
							'value'      => '',
							'min'        => '0',
							'max'        => '100',
						),
						array(
							'type'       => 'dimension',
							'param_name' => 'padding',
							'heading'    => 'Padding',
							'value'      => array(
								'padding_top'    => '',
								'padding_right'  => '',
								'padding_bottom' => '',
								'padding_left'   => '',
							),
						),
						array(
							'type'       => 'typography',
							'param_name' => 'main_typography',
							'heading'    => 'Button Typography',
						),
						array(
							'type'       => 'iconpicker',
							'param_name' => 'icon',
							'heading'    => 'Icon',
							'value'      => '',
						),
						array(
							'type'       => 'checkbox_button_set',
							'param_name' => 'hide_on_mobile',
							'heading'    => 'Element Visibility',
							'default'    => 'small-visibility,medium-visibility,large-visibility',
							'value'      => array(
								'small-visibility'  => 'Small Screen',
								'medium-visibility' => 'Medium Screen',
								'large-visibility'  => 'Large Screen',
							),
						),
						array(
							'type'       => 'repeater',
							'param_name' => 'html_attributes',
							'heading'    => 'Attributes',
						),
					),
					$class_id
				),
			),
			'fusion_imageframe'           => array(
				'name'      => 'Image',
				'shortcode' => 'fusion_imageframe',
				'params'    => array(
					array(
						'type'       => 'upload',
						'param_name' => 'element_content',
						'heading'    => 'Image',
						'value'      => '',
					),
					array(
						'type'       => 'textfield',
						'param_name' => 'image_id',
						'heading'    => 'Image ID',
						'value'      => '',
						'hidden'     => true,
					),
					$text( 'alt', 'Image Alt Text' ),
				),
			),
			'fusion_code'                 => array(
				'name'      => 'Code Block',
				'shortcode' => 'fusion_code',
				'params'    => array(
					array(
						'type'       => 'code',
						'param_name' => 'element_content',
						'heading'    => 'Code Block',
						'value'      => '',
					),
				),
			),
			'fusion_tabs'                 => array(
				'name'          => 'Tabs',
				'shortcode'     => 'fusion_tabs',
				'multi'         => 'multi_element_parent',
				'element_child' => 'fusion_tab',
				'params'        => array(
					array(
						'type'       => 'tinymce',
						'param_name' => 'element_content',
						'heading'    => 'Content',
						'value'      => '[fusion_tab title="Your Content Goes Here"]Your Content Goes Here[/fusion_tab]',
					),
				),
			),
			'fusion_tab'                  => array(
				'name'      => 'Tab',
				'shortcode' => 'fusion_tab',
				'params'    => array(
					$text( 'title', 'Tab Title' ),
					array(
						'type'       => 'tinymce',
						'param_name' => 'element_content',
						'heading'    => 'Tab Content',
						'value'      => '',
					),
				),
			),
			'fusion_google_map'           => array(
				'name'      => 'Google Map',
				'shortcode' => 'fusion_google_map',
				'defaults'  => array( 'api_key' => 'AIzaSECRET' ),
				'extras'    => array( 'gmap_api_key' => 'AIzaSECRET' ),
				'params'    => array(
					array(
						'type'       => 'raw_textarea',
						'param_name' => 'infobox_content',
						'heading'    => 'Info Box Content',
						'value'      => '',
					),
					array(
						'type'       => 'multiple_select',
						'param_name' => 'attributes',
						'heading'    => 'Attributes',
						'value'      => array(
							'a' => 'A',
							'b' => 'B',
						),
					),
					array(
						'type'       => 'raw_text',
						'param_name' => 'pattern',
						'heading'    => 'Pattern',
						'value'      => '',
					),
					array(
						'type'       => 'custom',
						'param_name' => 'onclick',
						'heading'    => 'Handler',
					),
					array( 'heading' => 'A group with no setting name' ),
				),
			),
			'fusion_placeholder_empty'    => array(),
			'fusion_builder_row_inner'    => array(
				'name'      => 'Nested Columns',
				'shortcode' => 'fusion_builder_row_inner',
				'params'    => array(),
			),
			'fusion_builder_column_inner' => array(
				'name'      => 'Nested Column',
				'shortcode' => 'fusion_builder_column_inner',
				'params'    => array(
					array(
						'type'       => 'column_width',
						'param_name' => 'type',
						'heading'    => 'Width',
						'default'    => '0',
					),
				),
			),
		);
	}

	/**
	 * Install the map through the seam: avada_map_loader is a no-op that records the call, avada_map returns the map.
	 *
	 * @param array<string,mixed>|null $map Map to serve, or null for map().
	 * @return void
	 */
	public static function install( ?array $map = null ): void {
		self::remove();
		self::$calls  = array();
		$served       = null === $map ? self::map() : $map;
		self::$filter = static function ( $cb, string $entry ) use ( $served ) {
			if ( 'avada_map_loader' === $entry ) {
				return static function (): void {
					self::$calls[] = 'loader';
				};
			}
			if ( 'avada_map' === $entry ) {
				return static function () use ( $served ): array {
					self::$calls[] = 'map';
					return $served;
				};
			}
			return $cb;
		};
		add_filter( 'aafm_builder_vendor', self::$filter, 10, 2 );
	}

	/**
	 * Force one entry absent.
	 *
	 * @param string $entry Entry name.
	 * @return void
	 */
	public static function absent( string $entry ): void {
		add_filter(
			'aafm_builder_vendor',
			static fn( $cb, string $name ) => $name === $entry ? null : $cb,
			20,
			2
		);
	}

	/**
	 * Remove the installed filter and any absent() forcing.
	 *
	 * @return void
	 */
	public static function remove(): void {
		if ( null !== self::$filter ) {
			remove_filter( 'aafm_builder_vendor', self::$filter, 10 );
			self::$filter = null;
		}
		remove_all_filters( 'aafm_builder_vendor', 20 );
	}
}
