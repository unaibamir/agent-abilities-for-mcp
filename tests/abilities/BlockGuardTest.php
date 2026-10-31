<?php
/**
 * Block-content guardrail: the heuristic scanner, plus its warn/strict behaviour on the write path.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\AcfStubStore;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\TestCase;
use WP_Block_Type_Registry;
use WP_Error;

final class BlockGuardTest extends TestCase {

	use IntegrationStubs;

	/**
	 * Stylesheet to restore after a test that switched themes, or null when none was switched.
	 *
	 * @var string|null
	 */
	private ?string $previous_stylesheet = null;

	public function set_up(): void {
		parent::set_up();

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/create-post', 'aafm/update-post' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * Restore the original theme after any test that switched to a block theme.
	 *
	 * The switch_theme() call writes the stylesheet option through a path the transactional
	 * rollback does not always reach, so it is restored explicitly (mirrors ThemesTest).
	 */
	public function tear_down(): void {
		if ( null !== $this->previous_stylesheet ) {
			switch_theme( $this->previous_stylesheet );
			$this->previous_stylesheet = null;
		}
		parent::tear_down();
	}

	/**
	 * Switch to the bundled block theme and register the update-template ability alongside the posts.
	 *
	 * The Site Editor abilities need a block theme with real templates, and the update-template
	 * ability must be enabled to be invoked. Re-running the registration is idempotent.
	 */
	private function enable_template_ability(): void {
		$this->previous_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/create-post', 'aafm/update-post', 'aafm/update-template' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * Create a database wp_template fixture tied to the active theme, returning [post_id, template_id].
	 *
	 * @param string $content Initial template markup.
	 * @return array{0:int,1:string}
	 */
	private function make_template( string $content ): array {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'aafm-guard-tpl',
				'post_content' => $content,
			)
		);
		// A database template override resolves by theme//slug only when tied to the theme term.
		wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme' );
		return array( $post_id, get_stylesheet() . '//aafm-guard-tpl' );
	}

	/**
	 * Attribute-driven markup that matches save() output produces no warnings.
	 */
	public function test_clean_attribute_driven_markup_is_silent(): void {
		$markup = '<!-- wp:heading {"style":{"color":{"text":"#ffffff"}}} -->' . "\n"
			. '<h2 class="has-text-color" style="color:#ffffff">Hello</h2>' . "\n"
			. '<!-- /wp:heading -->';

		$this->assertSame( array(), aafm_scan_block_content( $markup ) );
	}

	/**
	 * A presentational colour class with no backing attribute is flagged.
	 */
	public function test_text_color_class_without_attr_is_flagged(): void {
		$markup = '<!-- wp:heading -->' . "\n"
			. '<h2 class="has-text-color">Hello</h2>' . "\n"
			. '<!-- /wp:heading -->';

		$warnings = aafm_scan_block_content( $markup );
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'core/heading', $warnings[0]['block'] );
		$this->assertSame( 'text_color_class_without_attr', $warnings[0]['code'] );
	}

	/**
	 * Real core/button save() puts has-custom-font-size on the inner anchor, not the wrapper div.
	 * With no style.typography.fontSize attribute the button is flagged.
	 */
	public function test_custom_font_size_class_without_attr_is_flagged(): void {
		$markup = '<!-- wp:button -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link has-custom-font-size wp-element-button">Go</a></div>' . "\n"
			. '<!-- /wp:button -->';

		$warnings = aafm_scan_block_content( $markup );
		$codes    = wp_list_pluck( $warnings, 'code' );
		$this->assertContains( 'custom_font_size_class_without_attr', $codes );
	}

	/**
	 * The inverse: the class AND inline font-size sit on the anchor and the attribute is present, so clean.
	 */
	public function test_custom_font_size_class_with_attr_is_clean(): void {
		$markup = '<!-- wp:button {"style":{"typography":{"fontSize":"24px"}}} -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link has-custom-font-size wp-element-button" style="font-size:24px">Go</a></div>' . "\n"
			. '<!-- /wp:button -->';

		$this->assertSame( array(), aafm_scan_block_content( $markup ) );
	}

	/**
	 * A correctly authored button (the real Exam-LMS Download button shape) produces no warnings.
	 */
	public function test_correctly_authored_button_is_clean(): void {
		$markup = '<!-- wp:button {"style":{"typography":{"fontSize":"17px"},"color":{"text":"#b90874","background":"#ffffff"}}} -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background has-custom-font-size wp-element-button" style="color:#b90874;background-color:#ffffff;font-size:17px">Download</a></div>' . "\n"
			. '<!-- /wp:button -->';

		$this->assertSame( array(), aafm_scan_block_content( $markup ) );
	}

	/**
	 * An inline-styled anchor inside paragraph text is legitimate content and must not be flagged.
	 */
	public function test_inline_styled_anchor_in_paragraph_is_not_flagged(): void {
		$markup = '<!-- wp:paragraph -->' . "\n"
			. '<p>See <a href="https://x.test" style="color:#f00">this</a></p>' . "\n"
			. '<!-- /wp:paragraph -->';

		$this->assertSame( array(), aafm_scan_block_content( $markup ) );
	}

	/**
	 * A bare inline style on the block root with no styling attributes is flagged.
	 */
	public function test_inline_style_on_root_is_flagged(): void {
		$markup = '<!-- wp:paragraph -->' . "\n"
			. '<p style="color:#ffffff">Hi</p>' . "\n"
			. '<!-- /wp:paragraph -->';

		$warnings = aafm_scan_block_content( $markup );
		$codes    = wp_list_pluck( $warnings, 'code' );
		$this->assertContains( 'inline_style_without_attrs', $codes );
	}

	/**
	 * The core/cover save() puts a structural min-height on the root (from the top-level minHeight
	 * attribute, not a color/size support). That is legitimate output and must NOT trip Check B -
	 * min-height is not a presentational property the guard backs as a block attribute.
	 */
	public function test_cover_min_height_style_is_not_flagged(): void {
		$markup = '<!-- wp:cover {"minHeight":400} -->' . "\n"
			. '<div class="wp-block-cover" style="min-height:400px"><span aria-hidden="true" class="wp-block-cover__background"></span><div class="wp-block-cover__inner-container"></div></div>' . "\n"
			. '<!-- /wp:cover -->';

		$this->assertSame( array(), aafm_scan_block_content( $markup ) );
	}

	/**
	 * The core/media-text save() writes a structural grid-template-columns on the root from the
	 * mediaWidth attribute. Like min-height it is not a color/size the guard mirrors, so it must
	 * stay silent rather than be rejected in strict mode.
	 */
	public function test_media_text_grid_template_style_is_not_flagged(): void {
		$markup = '<!-- wp:media-text {"mediaWidth":50} -->' . "\n"
			. '<div class="wp-block-media-text is-stacked-on-mobile" style="grid-template-columns:50% auto"><figure class="wp-block-media-text__media"></figure><div class="wp-block-media-text__content"></div></div>' . "\n"
			. '<!-- /wp:media-text -->';

		$this->assertSame( array(), aafm_scan_block_content( $markup ) );
	}

	/**
	 * The narrowed Check B still catches the real case: an inline PRESENTATIONAL style (color) on
	 * the root with no matching block attribute is flagged.
	 */
	public function test_unbacked_inline_color_style_still_flagged(): void {
		$markup = '<!-- wp:group -->' . "\n"
			. '<div class="wp-block-group" style="color:red">X</div>' . "\n"
			. '<!-- /wp:group -->';

		$codes = wp_list_pluck( aafm_scan_block_content( $markup ), 'code' );
		$this->assertContains( 'inline_style_without_attrs', $codes );
	}

	/**
	 * The KSES-dropped case: an attribute declares a colour the sanitizer removed from the HTML.
	 */
	public function test_dropped_color_value_is_flagged(): void {
		// Simulates post-KSES markup: the rgba() colour was stripped from the inline style,
		// leaving the attribute and the has-text-color class with no value behind them.
		$markup = '<!-- wp:heading {"style":{"color":{"text":"rgba(0,0,0,0.5)"}}} -->' . "\n"
			. '<h2 class="has-text-color">Hi</h2>' . "\n"
			. '<!-- /wp:heading -->';

		$warnings = aafm_scan_block_content( $markup );
		$codes    = wp_list_pluck( $warnings, 'code' );
		$this->assertContains( 'color_attr_value_dropped', $codes );
	}

	/**
	 * Third-party (non-core) blocks are out of scope and never flagged.
	 */
	public function test_third_party_block_is_never_flagged(): void {
		$markup = '<!-- wp:acme/fancy -->' . "\n"
			. '<div class="acme-fancy has-text-color" style="color:#fff">X</div>' . "\n"
			. '<!-- /wp:acme/fancy -->';

		// Registered, so the unregistered_block check stays out of this heuristic-only test.
		register_block_type( 'acme/fancy' );
		$warnings = aafm_scan_block_content( $markup );
		unregister_block_type( 'acme/fancy' );

		$this->assertSame( array(), $warnings );
	}

	/**
	 * Nested inner blocks are walked, and clean container markup stays silent.
	 */
	public function test_recurses_into_inner_blocks(): void {
		$markup = '<!-- wp:group -->' . "\n"
			. '<div class="wp-block-group">' . "\n"
			. '<!-- wp:paragraph -->' . "\n"
			. '<p class="has-text-color">Nested</p>' . "\n"
			. '<!-- /wp:paragraph -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->';

		$warnings = aafm_scan_block_content( $markup );
		$blocks   = wp_list_pluck( $warnings, 'block' );
		$this->assertContains( 'core/paragraph', $blocks );
	}

	/**
	 * Warn mode (default): a dirty create still saves, and the response carries content_warnings.
	 */
	public function test_warn_mode_saves_and_returns_warnings(): void {
		$this->acting_as( 'editor' );

		$out = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'   => 'Dirty warn',
				'content' => '<!-- wp:heading --><h2 class="has-text-color">Hi</h2><!-- /wp:heading -->',
			)
		);

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'content_warnings', $out );
		$this->assertNotEmpty( $out['content_warnings'] );
		$this->assertSame( 'publish', get_post_status( $out['post']['id'] ) );
	}

	/**
	 * Warn mode with clean content omits the content_warnings key entirely.
	 */
	public function test_warn_mode_clean_content_has_no_warnings_key(): void {
		$this->acting_as( 'editor' );

		$out = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'   => 'Clean warn',
				'content' => '<!-- wp:paragraph --><p>Plain body</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $out );
		$this->assertArrayNotHasKey( 'content_warnings', $out );
	}

	/**
	 * Strict mode: a dirty create is refused and nothing is written.
	 */
	public function test_strict_mode_rejects_dirty_create_and_writes_nothing(): void {
		update_option( 'aafm_block_guard_strict', true );
		$this->acting_as( 'editor' );

		$before = (int) wp_count_posts( 'post' )->publish;
		$out    = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'   => 'Dirty strict',
				'content' => '<!-- wp:heading --><h2 class="has-text-color">Hi</h2><!-- /wp:heading -->',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_invalid_block_content', $out->get_error_code() );
		$this->assertSame( $before, (int) wp_count_posts( 'post' )->publish );
	}

	/**
	 * Strict mode with clean content saves normally, no error, no warnings key.
	 */
	public function test_strict_mode_allows_clean_create(): void {
		update_option( 'aafm_block_guard_strict', true );
		$this->acting_as( 'editor' );

		$out = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'   => 'Clean strict',
				'content' => '<!-- wp:paragraph --><p>Plain body</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $out );
		$this->assertArrayNotHasKey( 'content_warnings', $out );
		$this->assertSame( 'publish', get_post_status( $out['post']['id'] ) );
	}

	/**
	 * Strict mode on the update path: a dirty update is refused and the post is left unchanged.
	 */
	public function test_strict_mode_rejects_dirty_update_and_leaves_post_unchanged(): void {
		update_option( 'aafm_block_guard_strict', true );
		$this->acting_as( 'editor' );

		$post = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->',
			)
		);

		$out = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id' => $post,
				'content' => '<!-- wp:heading --><h2 class="has-text-color">Broken</h2><!-- /wp:heading -->',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertStringContainsString( 'Original', (string) get_post_field( 'post_content', $post ) );
	}

	/**
	 * The strict-mode getter mirrors the force-draft option shape: default off, option-driven.
	 */
	public function test_strict_getter_defaults_off_and_reads_option(): void {
		delete_option( 'aafm_block_guard_strict' );
		$this->assertFalse( aafm_block_guard_is_strict() );

		update_option( 'aafm_block_guard_strict', true );
		$this->assertTrue( aafm_block_guard_is_strict() );
	}

	/**
	 * The content-parameter contract names the attributes-not-inline rule and the core/html escape hatch.
	 */
	public function test_content_description_states_the_contract(): void {
		$desc = aafm_write_content_description();
		$this->assertStringContainsString( 'block delimiter', $desc );
		$this->assertStringContainsString( 'core/html', $desc );

		// It flows onto the shared write schema's content property.
		$schema = aafm_write_content_schema( true );
		$this->assertSame( $desc, $schema['properties']['content']['description'] );

		// And onto the update-template input schema.
		$tpl_schema = aafm_args_update_template()['input_schema'];
		$this->assertSame( $desc, $tpl_schema['properties']['content']['description'] );
	}

	/**
	 * A template-only whitelisted block (core/navigation) with an unbacked colour class is flagged.
	 */
	public function test_navigation_block_without_color_attr_is_flagged(): void {
		$markup = '<!-- wp:navigation -->' . "\n"
			. '<nav class="wp-block-navigation has-text-color">Menu</nav>' . "\n"
			. '<!-- /wp:navigation -->';

		$warnings = aafm_scan_block_content( $markup );
		$codes    = wp_list_pluck( $warnings, 'code' );
		$this->assertContains( 'text_color_class_without_attr', $codes );
	}

	/**
	 * A correctly authored navigation block (colour class backed by the attribute) stays silent.
	 */
	public function test_navigation_block_with_color_attr_is_clean(): void {
		$markup = '<!-- wp:navigation {"style":{"color":{"text":"#111111"}}} -->' . "\n"
			. '<nav class="wp-block-navigation has-text-color" style="color:#111111">Menu</nav>' . "\n"
			. '<!-- /wp:navigation -->';

		$this->assertSame( array(), aafm_scan_block_content( $markup ) );
	}

	/**
	 * Warn mode: a dirty update-template still saves, and the response carries content_warnings.
	 */
	public function test_update_template_warn_mode_saves_and_returns_warnings(): void {
		$this->enable_template_ability();
		$this->acting_as( 'administrator' );

		list( $post_id, $template_id ) = $this->make_template( '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->' );

		$out = wp_get_ability( 'aafm/update-template' )->execute(
			array(
				'template_id' => $template_id,
				'content'     => '<!-- wp:heading --><h2 class="has-text-color">Broken</h2><!-- /wp:heading -->',
			)
		);

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'content_warnings', $out );
		$this->assertNotEmpty( $out['content_warnings'] );
		$this->assertStringContainsString( 'wp:heading', (string) get_post( $post_id )->post_content );
	}

	/**
	 * Warn mode with clean template markup saves and omits the content_warnings key.
	 */
	public function test_update_template_warn_mode_clean_has_no_warnings_key(): void {
		$this->enable_template_ability();
		$this->acting_as( 'administrator' );

		list( , $template_id ) = $this->make_template( '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->' );

		$out = wp_get_ability( 'aafm/update-template' )->execute(
			array(
				'template_id' => $template_id,
				'content'     => '<!-- wp:paragraph --><p>Clean template body</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $out );
		$this->assertArrayNotHasKey( 'content_warnings', $out );
	}

	/**
	 * Strict mode: a dirty update-template is refused and the stored content is left unchanged.
	 */
	public function test_update_template_strict_mode_rejects_and_leaves_content_unchanged(): void {
		$this->enable_template_ability();
		update_option( 'aafm_block_guard_strict', true );
		$this->acting_as( 'administrator' );

		$original                      = '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->';
		list( $post_id, $template_id ) = $this->make_template( $original );

		$out = wp_get_ability( 'aafm/update-template' )->execute(
			array(
				'template_id' => $template_id,
				'content'     => '<!-- wp:heading --><h2 class="has-text-color">Broken</h2><!-- /wp:heading -->',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_invalid_block_content', $out->get_error_code() );
		$this->assertSame( $original, (string) get_post( $post_id )->post_content );
	}

	/**
	 * Codes of the warnings the guard scan returns for a markup string.
	 *
	 * @param string $markup Markup.
	 * @return list<string>
	 */
	private function codes( string $markup ): array {
		return wp_list_pluck( aafm_scan_block_content( $markup ), 'code' );
	}

	/**
	 * Seed an ACF field group bound to acme/acf-card with a repeater, a group, a flexible field and
	 * two clones (one without prefix_name, one with it), as ACF loads them.
	 */
	private function stub_card_fields(): void {
		$this->stub_acf(
			array(
				'groups' => array(
					array(
						'key'      => 'group_card',
						'title'    => 'Card',
						'location' => array(
							array(
								array(
									'param'    => 'block',
									'operator' => '==',
									'value'    => 'acme/acf-card',
								),
							),
						),
						'fields'   => array(
							array(
								'key'  => 'f_title',
								'name' => 'title',
								'type' => 'text',
							),
							array(
								'key'        => 'f_items',
								'name'       => 'items',
								'type'       => 'repeater',
								'sub_fields' => array(
									array(
										'key'  => 'f_items_title',
										'name' => 'title',
										'type' => 'text',
									),
								),
							),
							array(
								'key'        => 'f_group',
								'name'       => 'group',
								'type'       => 'group',
								'sub_fields' => array(
									array(
										'key'  => 'f_group_sub',
										'name' => 'sub',
										'type' => 'text',
									),
								),
							),
							array(
								'key'     => 'f_flex',
								'name'    => 'layout_field',
								'type'    => 'flexible_content',
								'layouts' => array(
									'l1' => array(
										'name'       => 'text_row',
										'label'      => 'Text row',
										'sub_fields' => array(
											array(
												'key'  => 'f_flex_text',
												'name' => 'text',
												'type' => 'text',
											),
										),
									),
								),
							),
							array(
								'key'        => 'f_cta',
								'name'       => 'cta',
								'type'       => 'clone',
								'sub_fields' => array(
									array(
										'key'  => 'f_cta_label',
										'name' => 'cta_label',
										'type' => 'text',
									),
								),
							),
							array(
								'key'         => 'f_promo',
								'name'        => 'promo',
								'type'        => 'clone',
								'prefix_name' => 1,
								'sub_fields'  => array(
									array(
										'key'  => 'f_promo_text',
										'name' => 'promo_text',
										'type' => 'text',
									),
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * The guard's unknown_acf_field rows for an acme/acf-card block carrying $data.
	 *
	 * @param array<string,mixed> $data ACF block data.
	 * @return list<array{block:string,code:string,message:string}>
	 */
	private function acf_rows( array $data ): array {
		$markup = '<!-- wp:acme/acf-card ' . serialize_block_attributes(
			array(
				'name' => 'acme/acf-card',
				'data' => $data,
				'mode' => 'preview',
			)
		) . ' /-->';
		return array_values(
			array_filter(
				aafm_scan_block_content( $markup ),
				static fn( array $row ): bool => 'unknown_acf_field' === $row['code']
			)
		);
	}

	public function test_unregistered_block_is_flagged(): void {
		$this->assertSame(
			array( 'unregistered_block' ),
			$this->codes( '<!-- wp:acme/nowhere --><div>x</div><!-- /wp:acme/nowhere -->' )
		);
		$this->assertStringContainsString( 'aafm-list-block-types', aafm_scan_block_content( '<!-- wp:acme/nowhere /-->' )[0]['message'] );
	}

	public function test_a_registered_block_is_not_flagged_as_unregistered(): void {
		$this->assertSame( array(), $this->codes( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		$this->assertSame( array(), $this->codes( '<p>freeform only</p>' ) );
	}

	public function test_unknown_acf_key_ignores_references_and_valid_flattened_rows(): void {
		$this->stub_card_fields();

		$this->assertSame(
			array(),
			$this->acf_rows(
				array(
					'title'               => 'T',
					'_title'              => 'f_title',
					'items'               => 1,
					'items_0_title'       => 'Row',
					'_items_0_title'      => 'f_items_title',
					'group_sub'           => 'G',
					'layout_field'        => array( 'text_row' ),
					'layout_field_0_text' => 'Flex',
					'cta'                 => '',
					'promo'               => '',
				)
			)
		);
	}

	public function test_unknown_acf_key_flags_a_misspelled_sub_field(): void {
		$this->stub_card_fields();
		$rows = $this->acf_rows(
			array(
				'title'         => 'T',
				'items_0_tilte' => 'Row',
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'acme/acf-card', $rows[0]['block'] );
		$this->assertStringContainsString( 'items_0_tilte', $rows[0]['message'] );
		$this->assertStringContainsString( 'title, items, group, layout_field, cta, promo', $rows[0]['message'] );
	}

	public function test_unknown_acf_key_is_silent_for_a_block_without_acf_fields(): void {
		$this->stub_card_fields();
		$this->assertSame( array(), wp_list_pluck( $this->acf_rows( array() ), 'code' ) );
		$this->assertNotContains(
			'unknown_acf_field',
			$this->codes( '<!-- wp:acme/other {"data":{"whatever":"x"}} /-->' )
		);
	}

	public function test_a_clone_without_prefix_name_takes_the_bare_sub_field_key(): void {
		$this->stub_card_fields();
		$this->assertSame( array(), $this->acf_rows( array( 'cta_label' => 'Go' ) ) );
	}

	public function test_a_clone_with_prefix_name_takes_the_prefixed_key_and_flags_the_bare_one(): void {
		$this->stub_card_fields();
		$this->assertSame( array(), $this->acf_rows( array( 'promo_text' => 'P' ) ) );
		$this->assertCount( 1, $this->acf_rows( array( 'text' => 'P' ) ) );
	}

	public function test_a_misspelled_clone_sub_field_is_flagged(): void {
		$this->stub_card_fields();
		$this->assertCount( 1, $this->acf_rows( array( 'cta_lable' => 'Go' ) ) );
	}

	/**
	 * ACF's "Block is equal to All" rule binds a group to every ACF block, so its field names are
	 * valid data keys on a block that also has its own group.
	 */
	public function test_a_field_from_an_all_blocks_group_is_not_flagged(): void {
		$this->stub_card_fields();
		AcfStubStore::$block_types = array( 'acme/acf-card' );
		AcfStubStore::$groups[]    = array(
			'key'      => 'group_all',
			'title'    => 'Every block',
			'location' => array(
				array(
					array(
						'param'    => 'block',
						'operator' => '==',
						'value'    => 'all',
					),
				),
			),
			'fields'   => array(
				array(
					'key'  => 'f_anchor',
					'name' => 'anchor_id',
					'type' => 'text',
				),
			),
		);

		$this->assertSame( array(), $this->acf_rows( array( 'anchor_id' => 'top' ) ) );
	}

	/**
	 * ACF converts data keyed by field keys into names on save, so a field key is not ignored.
	 */
	public function test_a_field_key_used_as_a_data_key_is_not_flagged(): void {
		$this->stub_card_fields();
		$this->assertSame(
			array(),
			$this->acf_rows(
				array(
					'f_title'       => 'T',
					'items_0_title' => 'Row',
				)
			)
		);
		$this->assertCount( 1, $this->acf_rows( array( 'f_nope' => 'x' ) ) );
	}

	public function test_a_block_gap_core_drops_is_flagged(): void {
		$scalar = $this->codes( '<!-- wp:group {"style":{"spacing":{"blockGap":"clamp(1rem, 2vw, 2rem)"}}} --><div class="wp-block-group"></div><!-- /wp:group -->' );
		$array  = aafm_scan_block_content( '<!-- wp:group {"style":{"spacing":{"blockGap":{"top":"calc(1rem + 2px)","left":"24px"}}}} --><div class="wp-block-group"></div><!-- /wp:group -->' );

		$this->assertSame( array( 'block_gap_dropped' ), $scalar );
		$this->assertSame( array( 'block_gap_dropped' ), wp_list_pluck( $array, 'code' ) );
		$this->assertStringContainsString( 'calc(1rem + 2px)', $array[0]['message'] );
		$this->assertStringContainsString( 'value calc(1rem + 2px) on', $array[0]['message'], 'Only the dropped value is named.' );
	}

	public function test_a_block_gap_core_keeps_is_not_flagged(): void {
		$this->assertSame( array(), $this->codes( '<!-- wp:group {"style":{"spacing":{"blockGap":"24px"}}} --><div class="wp-block-group"></div><!-- /wp:group -->' ) );
		$this->assertSame( array(), $this->codes( '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} --><div class="wp-block-group"></div><!-- /wp:group -->' ) );
	}

	/**
	 * U35: strict mode refuses exactly what it refused before. The five informational codes,
	 * alone, never refuse a write.
	 */
	public function test_strict_mode_does_not_refuse_on_informational_codes(): void {
		update_option( 'aafm_block_guard_strict', true );
		$this->acting_as( 'administrator' );

		$out = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'   => 'Informational strict',
				'content' => '<!-- wp:acme/nowhere /--><!-- wp:group {"style":{"spacing":{"blockGap":"calc(1rem)"}}} --><div class="wp-block-group"></div><!-- /wp:group --><!-- wp:heading {"level":2,} --><h2 class="wp-block-heading">Hi</h2><!-- /wp:heading --><!-- wp:html --><style>.a{}</style><!-- /wp:html -->',
			)
		);

		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$codes = wp_list_pluck( $out['content_warnings'], 'code' );
		sort( $codes );
		$this->assertSame( array( 'block_gap_dropped', 'invalid_block_attributes', 'removed_element', 'unregistered_block' ), $codes );
	}

	/**
	 * U36: the codes the guard returned at 1.10.0 keep their exact rows.
	 */
	public function test_existing_warning_codes_are_unchanged(): void {
		$this->assertSame(
			array(
				array(
					'block'   => 'core/heading',
					'code'    => 'text_color_class_without_attr',
					'message' => 'The core/heading block has the "has-text-color" class but no matching value in its block attributes. Encode the styling inside the block delimiter so the editor does not flag the block as invalid.',
				),
				array(
					'block'   => 'core/heading',
					'code'    => 'inline_style_without_attrs',
					'message' => 'The core/heading block uses an inline style="" attribute with no styling in its block attributes. Move the styling into the block delimiter, or the editor will flag the block as invalid.',
				),
			),
			aafm_scan_block_content( '<!-- wp:heading --><h2 class="has-text-color" style="color:#fff">Hi</h2><!-- /wp:heading -->' )
		);
		$this->assertSame(
			array(
				array(
					'block'   => 'core/paragraph',
					'code'    => 'color_attr_value_dropped',
					'message' => 'The core/paragraph block declares a color or size in its attributes, but the value is missing from the stored HTML (the sanitizer likely dropped an rgba() or alpha color). Use a plain hex color so the attribute and the markup agree.',
				),
			),
			aafm_scan_block_content( '<!-- wp:paragraph {"style":{"color":{"text":"#fff"}}} --><p class="has-text-color">x</p><!-- /wp:paragraph -->' )
		);
		$this->assertSame(
			array( 'text_color_class_without_attr', 'background_class_without_attr', 'border_color_class_without_attr', 'custom_font_size_class_without_attr', 'font_size_class_without_attr', 'inline_style_without_attrs', 'color_attr_value_dropped', 'background_attr_value_dropped', 'font_size_attr_value_dropped' ),
			aafm_block_guard_strict_codes()
		);
	}
}
