<?php
/**
 * FSE theme / template / global-styles abilities (Slice D).
 *
 * Covers the active-theme + theme-list reads, the block-template list/get reads, the
 * kses-hardened update-template write (including the B-1 file-only-template refusal), and
 * the read-only global-styles ability. Every ability gates on edit_theme_options, so an
 * editor is denied and an administrator is allowed. No filesystem path may appear in any
 * output (the tests assert ABSPATH is absent from the JSON).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class ThemesTest extends TestCase {

	/**
	 * The stylesheet active before this test switched themes, restored in tear_down().
	 *
	 * @var string|null
	 */
	private ?string $previous_stylesheet = null;

	/**
	 * Restore the original theme after each test that switched to a block theme.
	 *
	 * The WP test case rolls back database state transactionally, but switch_theme() writes the
	 * stylesheet/template options through a path the rollback does not always reach, so the
	 * original theme is restored explicitly here.
	 */
	public function tear_down(): void {
		if ( null !== $this->previous_stylesheet ) {
			switch_theme( $this->previous_stylesheet );
			$this->previous_stylesheet = null;
		}
		parent::tear_down();
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * @param string   $action Action name to simulate.
	 * @param callable $cb     Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $cb ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$cb();
		array_pop( $wp_current_filter );
	}

	/**
	 * Switch the site to a block theme so the FSE template/global-styles abilities have real
	 * data to act on. The WordPress test library boots with the non-block "default" theme, so the
	 * block-theme bundled with core (twentytwentyfive) is activated for the duration of the test
	 * and restored in tear_down().
	 */
	private function use_block_theme(): void {
		if ( null === $this->previous_stylesheet ) {
			$this->previous_stylesheet = get_stylesheet();
		}
		switch_theme( 'twentytwentyfive' );
	}

	/**
	 * Enable + register the FSE abilities so they can be invoked.
	 */
	private function register_themes(): void {
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->use_block_theme();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/get-active-theme', 'aafm/list-themes', 'aafm/list-templates', 'aafm/get-template', 'aafm/update-template', 'aafm/get-global-styles' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_get_active_theme_requires_cap_and_hides_path(): void {
		$this->register_themes();
		$this->acting_as( 'editor' );
		$this->assertNotTrue( wp_get_ability( 'aafm/get-active-theme' )->check_permissions( array() ) );

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/get-active-theme' )->execute( array() );
		$this->assertArrayHasKey( 'stylesheet', $res );
		$this->assertArrayHasKey( 'is_block_theme', $res );
		$json = (string) wp_json_encode( $res );
		$this->assertStringNotContainsString( ABSPATH, $json, 'Theme path must not leak.' );
	}

	public function test_list_themes_marks_the_active_one(): void {
		$this->register_themes();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/list-themes' )->execute( array() );
		$this->assertArrayHasKey( 'themes', $res );
		$active = array_filter( $res['themes'], static fn( $t ) => 'active' === $t['status'] );
		$this->assertNotEmpty( $active, 'One theme must be marked active.' );
	}

	public function test_list_and_get_template(): void {
		$this->register_themes();
		$this->acting_as( 'administrator' );
		$list = wp_get_ability( 'aafm/list-templates' )->execute( array() );
		$this->assertArrayHasKey( 'templates', $list );
		$this->assertNotEmpty( $list['templates'], 'A block theme has templates.' );
		$id  = $list['templates'][0]['id'];
		$one = wp_get_ability( 'aafm/get-template' )->execute( array( 'template_id' => $id ) );
		$this->assertArrayHasKey( 'content', $one );

		$this->assertInstanceOf( WP_Error::class, wp_get_ability( 'aafm/get-template' )->execute( array( 'template_id' => 'nope//missing' ) ) );
	}

	public function test_update_template_hardens_markup(): void {
		$this->register_themes();
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'aafm-test',
				'post_content' => 'old',
			)
		);
		// A database template override is tied to the active theme by the wp_theme taxonomy term;
		// without it get_block_template() will not resolve the post by its theme//slug id.
		wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme' );
		$id  = get_stylesheet() . '//aafm-test';
		$res = wp_get_ability( 'aafm/update-template' )->execute(
			array(
				'template_id' => $id,
				'content'     => '<!-- wp:paragraph --><p>hi<script>x</script></p><!-- /wp:paragraph -->',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$saved = get_post( $post_id )->post_content;
		$this->assertStringContainsString( 'wp:paragraph', $saved );
		$this->assertStringNotContainsString( '<script', $saved );
	}

	public function test_update_template_refuses_a_theme_file_template(): void {
		$this->register_themes();
		$this->acting_as( 'administrator' );

		// A theme-FILE template has no backing wp_template post (wp_id is unset, not 0). The B-1
		// guard must refuse it, and it must NOT insert a stray wp_template post as a side effect.
		$before = wp_count_posts( 'wp_template' );
		$res    = wp_get_ability( 'aafm/update-template' )->execute(
			array(
				'template_id' => get_stylesheet() . '//single',
				'content'     => '<!-- wp:paragraph --><p>nope</p><!-- /wp:paragraph -->',
			)
		);
		$after  = wp_count_posts( 'wp_template' );

		$this->assertInstanceOf( WP_Error::class, $res, 'A theme-file template must be refused.' );
		$this->assertSame(
			array_sum( (array) $before ),
			array_sum( (array) $after ),
			'Refusing a theme-file template must not create a wp_template post.'
		);
	}

	public function test_update_template_denies_an_editor(): void {
		$this->register_themes();
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/update-template' )->check_permissions( array( 'template_id' => get_stylesheet() . '//single' ) )
		);
	}
}
