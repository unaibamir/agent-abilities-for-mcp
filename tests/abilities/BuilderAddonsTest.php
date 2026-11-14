<?php
/**
 * Builder add-ons (design 325 sections 3.3, 3.5, WB-7, WB-8 and AV-15, plan 326 Session 14): Templatera's template
 * posts in the WPBakery tools, and Avada Core's slider settings. Vendor code is reached only through the seam stubs
 * (Ruling B14); the templatera post type and the slide-page taxonomy are registered by the test as the vendors do.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\WpbakeryMapStub;
use AAFM\Tests\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/Support/WpbakeryMapStub.php';

final class BuilderAddonsTest extends TestCase {

	private const SIMPLE = '[vc_row][vc_column][vc_column_text]<p>One</p>[/vc_column_text][/vc_column][/vc_row]';

	private const TEMPLATE = '[vc_row][vc_column][vc_column_text]<p>From Templatera</p>[/vc_column_text][/vc_column][/vc_row]';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		WpbakeryMapStub::install( WpbakeryMapStub::map() + array( 'templatera' => self::templatera_map_entry() ) );
		// Templatera's own registration (lib/vc_template_manager.php createPostType()): not public, capability type post.
		register_post_type(
			'templatera',
			array(
				'public'          => false,
				'show_ui'         => true,
				'capability_type' => 'post',
			)
		);
	}

	public function tear_down(): void {
		WpbakeryMapStub::remove();
		unregister_post_type( 'templatera' );
		remove_filter( 'aafm_integration_active_wpbakery', '__return_true' );
		remove_all_filters( 'aafm_templatera_active' );
		delete_option( 'wpb_js_templates' );
		parent::tear_down();
	}

	/**
	 * Templatera's own map entry (createShortcode()): a content element with id, use_template_scope and el_class.
	 *
	 * @return array<string,mixed>
	 */
	private static function templatera_map_entry(): array {
		return array(
			'name'     => 'Templatera',
			'base'     => 'templatera',
			'category' => 'Content',
			'params'   => array(
				array(
					'type'       => 'dropdown',
					'param_name' => 'id',
					'value'      => array( 'Choose template' => '' ),
				),
				array(
					'type'       => 'checkbox',
					'param_name' => 'use_template_scope',
					'value'      => array( 'Yes' => 'yes' ),
				),
				array(
					'type'       => 'textfield',
					'param_name' => 'el_class',
				),
			),
		);
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
	 * A published templatera post, created by an administrator, with optional Templatera meta lists (T3).
	 *
	 * @param string             $content    Shortcode text.
	 * @param array<string>|null $post_types post_type list, null for no meta.
	 * @param array<string>      $roles      user_role list.
	 * @param string             $title      Title.
	 * @return int
	 */
	private function template( string $content, ?array $post_types = null, array $roles = array(), string $title = 'T' ): int {
		$current = get_current_user_id();
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'templatera',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
		if ( null !== $post_types || array() !== $roles ) {
			update_post_meta(
				$id,
				'templatera',
				array(
					'post_type' => (array) $post_types,
					'user_role' => $roles,
				)
			);
		}
		wp_set_current_user( $current );
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
		return $id;
	}

	public function test_a_template_post_is_readable_and_its_remove_is_refused(): void {
		$this->assertContains( 'templatera', aafm_builder_own_post_types( 'wpbakery' ) );
		$id = $this->template( self::TEMPLATE );
		$this->acting_as( 'editor' );

		$read = aafm_exec_wpbakery_get_page_content( array( 'post_id' => $id ) );
		$this->assertIsArray( $read );
		$this->assertSame( self::TEMPLATE, $read['content'] );

		$replace = aafm_exec_wpbakery_replace_text(
			array(
				'post_id' => $id,
				'search'  => 'From Templatera',
				'replace' => 'Changed in the template',
			)
		);
		$this->assertIsArray( $replace );
		$this->assertSame( 1, $replace['replacements'] );
		$this->assertStringContainsString( 'Changed in the template', (string) get_post_field( 'post_content', $id, 'raw' ) );

		$content  = (string) get_post_field( 'post_content', $id, 'raw' );
		$elements = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) );
		$out      = aafm_exec_wpbakery_remove_element(
			array(
				'post_id'          => $id,
				'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
				'index'            => 2,
				'expect'           => array(
					'tag'         => 'vc_column_text',
					'fingerprint' => aafm_builder_fingerprint( $content, $elements[2] ),
				),
			)
		);
		$this->assert_error( $out, 'aafm_builder_remove_irreversible', 409 );
		$this->assertSame( $content, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertFalse( post_type_supports( 'templatera', 'revisions' ) );
	}

	public function test_a_template_post_takes_an_element_update_with_no_restore_point(): void {
		$id      = $this->template( self::TEMPLATE );
		$content = (string) get_post_field( 'post_content', $id, 'raw' );
		$el      = aafm_builder_elements( $content, aafm_wpbakery_shortcode_tags( $content ) );
		$this->acting_as( 'editor' );
		$out = aafm_exec_wpbakery_update_element(
			array(
				'post_id'          => $id,
				'page_fingerprint' => aafm_builder_page_fingerprint( $content ),
				'index'            => 2,
				'expect'           => array(
					'tag'         => 'vc_column_text',
					'fingerprint' => aafm_builder_fingerprint( $content, $el[2] ),
				),
				'set_atts'         => array( 'el_class' => 'from-test' ),
			)
		);
		$this->assertIsArray( $out );
		$this->assertStringContainsString( 'el_class="from-test"', (string) get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertNull( $out['restore_revision_id'] );
		$this->assertFalse( $out['reversible'] );
	}
}
