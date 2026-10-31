<?php
/**
 * Block type discovery through aafm/list-block-types and aafm/get-block-type, ACF block fields included.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\TestCase;
use WP_Block_Type_Registry;
use WP_Error;

final class BlockTypesTest extends TestCase {

	use IntegrationStubs;

	/**
	 * Block types this test registered, unregistered again in tear_down.
	 *
	 * @var string[]
	 */
	private array $registered = array();

	private function register( string $name, array $args = array() ): void {
		register_block_type( $name, $args );
		$this->registered[] = $name;
	}

	public function tear_down(): void {
		foreach ( $this->registered as $name ) {
			if ( WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				unregister_block_type( $name );
			}
		}
		$this->registered = array();
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Seed an ACF field group bound to acf/hero through a block location rule.
	 */
	private function stub_hero_group(): void {
		$this->register( 'acf/hero', array( 'title' => 'Hero' ) );
		$this->stub_acf(
			array(
				'groups' => array(
					array(
						'key'      => 'group_hero',
						'title'    => 'Hero',
						'location' => array(
							array(
								array(
									'param'    => 'block',
									'operator' => '==',
									'value'    => 'acf/hero',
								),
							),
						),
						'fields'   => array(
							array(
								'key'   => 'field_title',
								'name'  => 'title',
								'label' => 'Title',
								'type'  => 'text',
							),
							array(
								'key'     => 'field_style',
								'name'    => 'style',
								'label'   => 'Style',
								'type'    => 'select',
								'choices' => array(
									'dark'  => 'Dark',
									'light' => 'Light',
								),
							),
							array(
								'key'        => 'field_items',
								'name'       => 'items',
								'label'      => 'Items',
								'type'       => 'repeater',
								'sub_fields' => array(
									array(
										'key'   => 'field_item_title',
										'name'  => 'item_title',
										'label' => 'Item title',
										'type'  => 'text',
									),
								),
							),
							array(
								'key'           => 'field_art',
								'name'          => 'art',
								'label'         => 'Art',
								'type'          => 'image',
								'return_format' => 'id',
							),
						),
					),
				),
			)
		);
	}

	public function test_a_registered_core_block_appears_in_the_list_with_the_row_shape(): void {
		$this->acting_as( 'contributor' );
		$rows = aafm_exec_list_block_types(
			array(
				'search'   => 'core/paragraph',
				'per_page' => 100,
			)
		)['block_types'];
		$row  = array_values( array_filter( $rows, static fn( array $r ): bool => 'core/paragraph' === $r['name'] ) )[0] ?? null;

		$this->assertIsArray( $row );
		$this->assertSame( array( 'name', 'title', 'category', 'description', 'parent', 'is_acf', 'is_dynamic' ), array_keys( $row ) );
		$this->assertSame( 'Paragraph', $row['title'] );
		$this->assertNull( $row['parent'] );
		$this->assertFalse( $row['is_acf'] );
		$this->assertFalse( $row['is_dynamic'] );
	}

	public function test_is_dynamic_reports_a_server_rendered_block(): void {
		$this->acting_as( 'contributor' );
		$rows = aafm_exec_list_block_types( array( 'search' => 'core/latest-posts' ) )['block_types'];

		$this->assertTrue( $rows[0]['is_dynamic'] );
	}

	public function test_the_namespace_filter_keeps_only_that_namespace(): void {
		$this->acting_as( 'contributor' );
		$this->register( 'aafm-test/one', array( 'title' => 'One' ) );
		$out = aafm_exec_list_block_types(
			array(
				'namespace' => 'core',
				'per_page'  => 100,
			)
		);

		$this->assertGreaterThan( 20, $out['total'] );
		foreach ( $out['block_types'] as $row ) {
			$this->assertStringStartsWith( 'core/', $row['name'] );
		}
	}

	public function test_pagination_clamps_page_and_per_page(): void {
		$this->acting_as( 'contributor' );
		$wide = aafm_exec_list_block_types(
			array(
				'per_page' => 500,
				'page'     => 0,
			)
		);
		$this->assertCount( 100, $wide['block_types'] );

		$default = aafm_exec_list_block_types( array() );
		$this->assertCount( 50, $default['block_types'] );
		$this->assertSame( $wide['block_types'][0], $default['block_types'][0] );
	}

	public function test_search_is_case_insensitive_on_name_and_title(): void {
		$this->acting_as( 'contributor' );
		$this->register( 'aafm-test/promo-strip', array( 'title' => 'Hero Banner' ) );

		$by_title = wp_list_pluck( aafm_exec_list_block_types( array( 'search' => 'hero BANNER' ) )['block_types'], 'name' );
		$by_name  = wp_list_pluck( aafm_exec_list_block_types( array( 'search' => 'MO-STR' ) )['block_types'], 'name' );

		$this->assertSame( array( 'aafm-test/promo-strip' ), $by_title );
		$this->assertSame( array( 'aafm-test/promo-strip' ), $by_name );
	}

	public function test_results_are_sorted_by_name_and_total_is_query_wide(): void {
		$this->acting_as( 'contributor' );
		foreach ( array( 'aafm-test/c', 'aafm-test/a', 'aafm-test/b' ) as $name ) {
			$this->register( $name, array( 'title' => $name ) );
		}
		$page = static fn( int $n ): array => aafm_exec_list_block_types(
			array(
				'namespace' => 'aafm-test',
				'per_page'  => 2,
				'page'      => $n,
			)
		);

		$this->assertSame( array( 'aafm-test/a', 'aafm-test/b' ), wp_list_pluck( $page( 1 )['block_types'], 'name' ) );
		$this->assertSame( array( 'aafm-test/c' ), wp_list_pluck( $page( 2 )['block_types'], 'name' ) );
		$this->assertSame( 3, $page( 1 )['total'] );
		$this->assertSame( 3, $page( 2 )['total'] );
	}

	public function test_get_core_group_returns_supports_and_attributes(): void {
		$this->acting_as( 'contributor' );
		$out = aafm_exec_get_block_type( array( 'name' => 'core/group' ) );

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'blockGap', $out['supports']['spacing'] );
		$this->assertArrayHasKey( 'layout', $out['attributes'] );
		$this->assertNull( $out['acf'] );
		$this->assertSame(
			array( 'name', 'title', 'description', 'category', 'parent', 'ancestor', 'allowed_blocks', 'attributes', 'supports', 'styles', 'uses_context', 'provides_context', 'example', 'is_dynamic', 'acf' ),
			array_keys( $out )
		);
	}

	public function test_styles_are_name_and_label_only(): void {
		$this->acting_as( 'contributor' );
		register_block_style(
			'core/quote',
			array(
				'name'         => 'aafm-fancy',
				'label'        => 'Fancy',
				'inline_style' => '.is-style-aafm-fancy{color:red}',
			)
		);
		$styles = aafm_exec_get_block_type( array( 'name' => 'core/quote' ) )['styles'];
		unregister_block_style( 'core/quote', 'aafm-fancy' );

		$this->assertContains(
			array(
				'name'  => 'aafm-fancy',
				'label' => 'Fancy',
			),
			$styles
		);
		foreach ( $styles as $style ) {
			$this->assertSame( array( 'name', 'label' ), array_keys( $style ) );
		}
	}

	public function test_an_acf_block_returns_its_fields_and_an_example_payload(): void {
		$this->acting_as( 'contributor' );
		$this->stub_hero_group();
		$out = aafm_exec_get_block_type( array( 'name' => 'acf/hero' ) );

		$fields = $out['acf']['fields'];
		$this->assertSame( array( 'title', 'style', 'items', 'art' ), wp_list_pluck( $fields, 'name' ) );
		$this->assertSame(
			array(
				'dark'  => 'Dark',
				'light' => 'Light',
			),
			$fields[1]['choices']
		);
		$this->assertSame( 'item_title', $fields[2]['sub_fields'][0]['name'] );
		$this->assertSame( 'id', $fields[3]['return_format'] );
		$this->assertArrayNotHasKey( 'choices', $fields[0] );

		$example = parse_blocks( $out['acf']['envelope_example'] )[0];
		$this->assertSame( 'acf/hero', $example['blockName'] );
		$this->assertSame( 'acf/hero', $example['attrs']['name'] );
		$this->assertSame( 'preview', $example['attrs']['mode'] );
		$this->assertSame(
			array(
				'title' => '',
				'style' => 'dark',
				'items' => '',
				'art'   => 0,
			),
			$example['attrs']['data']
		);

		$listed = aafm_exec_list_block_types( array( 'search' => 'acf/hero' ) )['block_types'];
		$this->assertTrue( $listed[0]['is_acf'] );
	}

	public function test_a_group_without_a_block_rule_is_not_an_acf_block(): void {
		$this->acting_as( 'contributor' );
		$this->register( 'acf/plain', array( 'title' => 'Plain' ) );
		$this->stub_acf(
			array(
				'groups' => array(
					array(
						'key'      => 'group_post',
						'title'    => 'Post fields',
						'location' => array(
							array(
								array(
									'param'    => 'post_type',
									'operator' => '==',
									'value'    => 'post',
								),
							),
						),
						'fields'   => array(
							array(
								'key'  => 'field_x',
								'name' => 'x',
								'type' => 'text',
							),
						),
					),
				),
			)
		);

		$this->assertNull( aafm_acf_block_fields( 'acf/plain' ) );
		$this->assertNull( aafm_exec_get_block_type( array( 'name' => 'acf/plain' ) )['acf'] );
	}

	public function test_an_unknown_block_type_is_an_error_naming_the_list_tool(): void {
		$this->acting_as( 'contributor' );
		$out = aafm_exec_get_block_type( array( 'name' => 'nope/missing' ) );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_unknown_block_type', $out->get_error_code() );
		$this->assertStringContainsString( 'aafm-list-block-types', $out->get_error_message() );
	}

	public function test_get_block_type_output_has_no_paths_or_callables(): void {
		$this->acting_as( 'contributor' );
		$this->stub_hero_group();
		$this->register(
			'aafm-test/rendered',
			array(
				'title'           => 'Rendered',
				'render_callback' => static fn(): string => 'x',
				'attributes'      => array(
					'cb' => array(
						'type'    => 'object',
						'default' => static fn(): string => 'x',
					),
				),
			)
		);

		foreach ( array( 'core/group', 'core/image', 'core/navigation', 'acf/hero', 'aafm-test/rendered' ) as $name ) {
			$json = (string) wp_json_encode( aafm_exec_get_block_type( array( 'name' => $name ) ) );
			$this->assertStringNotContainsString( 'render_callback', $json, $name );
			$this->assertStringNotContainsString( '.php', $json, $name );
			$this->assertStringNotContainsString( '/wp-content/', $json, $name );
			$this->assertStringNotContainsString( 'editor_script', $json, $name );
			$this->assertStringNotContainsString( 'view_script', $json, $name );
		}
		$this->assertTrue( aafm_exec_get_block_type( array( 'name' => 'aafm-test/rendered' ) )['is_dynamic'] );
	}

	public function test_a_subscriber_is_refused_and_a_contributor_is_allowed(): void {
		foreach ( array( 'aafm_args_list_block_types', 'aafm_args_get_block_type' ) as $builder ) {
			$permission = $builder()['permission_callback'];

			$this->acting_as( 'subscriber' );
			$this->assertFalse( $permission( array( 'name' => 'core/group' ) ), $builder );

			$this->acting_as( 'contributor' );
			$this->assertTrue( $permission( array( 'name' => 'core/group' ) ), $builder );
		}
	}

	public function test_both_reads_are_registered_as_content_reads(): void {
		$registry = aafm_get_abilities_registry();
		foreach ( array( 'aafm/list-block-types', 'aafm/get-block-type' ) as $name ) {
			$this->assertSame( 'reads', $registry[ $name ]['group'], $name );
			$this->assertSame( 'read', $registry[ $name ]['risk'], $name );
			$this->assertSame( 'content', $registry[ $name ]['subject'], $name );
		}
		$this->assertSame( '^[a-z0-9-]+/[a-z0-9-]+$', aafm_args_get_block_type()['input_schema']['properties']['name']['pattern'] );
		$this->assertFalse( aafm_args_list_block_types()['input_schema']['additionalProperties'] );
		$this->assertTrue( aafm_args_get_block_type()['meta']['annotations']['readonly'] );
	}
}
