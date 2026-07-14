<?php
/**
 * Block-content guardrail: the heuristic scanner, plus its warn/strict behaviour on the write path.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class BlockGuardTest extends TestCase {

	/**
	 * Stylesheet to restore after a test that switched themes, or null when none was switched.
	 *
	 * @var string|null
	 */
	private ?string $previous_stylesheet = null;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();

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

		$this->assertSame( array(), aafm_scan_block_content( $markup ) );
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
}
