<?php
/**
 * Exposed-content-types sanitizer + AJAX save coverage.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;
use WP_Privacy_Policy_Content;

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

	public function test_privacy_policy_content_registers_our_suggested_text(): void {
		// Prove the callback actually contributes suggested privacy text — not just that
		// it is wired. wp_add_privacy_policy_content guards on is_admin() + admin_init, so
		// stand up that context the canonical way (set_current_screen) before invoking.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		set_current_screen( 'admin_init' );
		global $wp_actions;
		$wp_actions['admin_init'] = ( $wp_actions['admin_init'] ?? 0 ) + 1;

		aafm_register_privacy_policy_content();

		$entries = WP_Privacy_Policy_Content::get_suggested_policy_text();
		$ours    = wp_list_filter( $entries, array( 'plugin_name' => 'Agent Abilities for MCP' ) );
		$this->assertNotEmpty( $ours, 'Our plugin must contribute a suggested privacy-policy entry.' );

		$entry = reset( $ours );
		$this->assertStringContainsString(
			'No custom field',
			(string) $entry['policy_text'],
			'Suggested text must state that post meta is never exposed.'
		);
	}
}
