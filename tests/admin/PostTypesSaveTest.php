<?php
/**
 * Exposed-content-types sanitizer + AJAX save coverage.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class PostTypesSaveTest extends TestCase {

	public function test_sanitize_keeps_only_eligible_opted_in_types(): void {
		register_post_type(
			'aafm_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);
		$posted = array( 'aafm_post_types' => array( 'aafm_book', 'attachment', 'revision', 'post', '<script>' ) );
		$clean  = aafm_sanitize_allowed_post_types_input( $posted );
		// Only the eligible CPT survives. attachment/revision fail the floor; post/page are
		// always-on and never stored in the option; <script> sanitizes to nothing eligible.
		$this->assertSame( array( 'aafm_book' ), $clean );
	}

	public function test_sanitize_empty_post_stores_nothing(): void {
		$this->assertSame( array(), aafm_sanitize_allowed_post_types_input( array() ) );
	}

	public function test_post_and_page_are_never_persisted_to_the_option(): void {
		$clean = aafm_sanitize_allowed_post_types_input( array( 'aafm_post_types' => array( 'post', 'page' ) ) );
		$this->assertSame( array(), $clean ); // they are forced on by the helper, not stored.
	}

	public function test_content_panel_renders_eligible_cpt_selector(): void {
		$this->acting_as( 'administrator' );
		register_post_type(
			'aafm_book',
			array(
				'public'          => true,
				'show_in_rest'    => true,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
				'label'           => 'Books',
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) );

		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		// The selector renders the CPT as a checked checkbox with the post-types field name.
		$this->assertStringContainsString( 'name="aafm_post_types[]"', $html );
		$this->assertStringContainsString( 'value="aafm_book"', $html );
		$this->assertStringContainsString( 'aafm-post-types-form', $html );
		// post/page are always-on, not offered as toggles in the selector.
		$this->assertStringNotContainsString( 'value="post"', $html );
		// The governance note names the fields the agent can see.
		$this->assertStringContainsString( 'title', $html );
	}

	public function test_content_panel_has_no_selector_when_no_eligible_cpts(): void {
		$this->acting_as( 'administrator' );
		// No public non-builtin CPTs registered in this isolated test → selector lists nothing to opt into.
		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'name="aafm_post_types[]"', $html );
	}

	public function test_privacy_policy_content_is_hooked_on_admin_init(): void {
		$this->assertNotFalse(
			has_action( 'admin_init', 'aafm_register_privacy_policy_content' ),
			'Privacy-policy content must be registered on admin_init.'
		);
	}
}
