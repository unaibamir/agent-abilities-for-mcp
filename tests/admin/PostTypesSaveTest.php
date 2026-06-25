<?php
/**
 * Exposed-content-types sanitizer + AJAX save coverage.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;
use WP_Privacy_Policy_Content;

final class PostTypesSaveTest extends TestCase {

	public function test_sanitize_keeps_only_eligible_opted_in_types(): void {
		register_post_type(
			'oversio_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);
		$posted = array( 'oversio_post_types' => array( 'oversio_book', 'attachment', 'revision', 'post', '<script>' ) );
		$clean  = oversio_sanitize_allowed_post_types_input( $posted );
		// Only the eligible CPT survives. attachment/revision fail the floor; post/page are
		// always-on and never stored in the option; <script> sanitizes to nothing eligible.
		$this->assertSame( array( 'oversio_book' ), $clean );
	}

	public function test_sanitize_empty_post_stores_nothing(): void {
		$this->assertSame( array(), oversio_sanitize_allowed_post_types_input( array() ) );
	}

	public function test_post_and_page_are_never_persisted_to_the_option(): void {
		$clean = oversio_sanitize_allowed_post_types_input( array( 'oversio_post_types' => array( 'post', 'page' ) ) );
		$this->assertSame( array(), $clean ); // they are forced on by the helper, not stored.
	}

	public function test_content_panel_renders_eligible_cpt_selector(): void {
		$this->acting_as( 'administrator' );
		register_post_type(
			'oversio_book',
			array(
				'public'          => true,
				'show_in_rest'    => true,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
				'label'           => 'Books',
			)
		);
		update_option( 'oversio_allowed_post_types', array( 'oversio_book' ) );

		ob_start();
		oversio_render_abilities_tab();
		$html = (string) ob_get_clean();

		// The selector renders the CPT as a checked checkbox with the post-types field name.
		$this->assertStringContainsString( 'name="oversio_post_types[]"', $html );
		$this->assertStringContainsString( 'value="oversio_book"', $html );
		$this->assertStringContainsString( 'oversio-post-types-form', $html );
		// Direction A presentation: the Expose column uses the toggle switch.
		$this->assertStringContainsString( 'oversio-switch', $html );
		$this->assertStringContainsString( 'oversio-card', $html );
		// post/page are always-on, not offered as toggles in the selector.
		$this->assertStringNotContainsString( 'value="post"', $html );
		// The governance note names the fields the agent can see.
		$this->assertStringContainsString( 'title', $html );
	}

	public function test_abilities_tab_has_no_nested_post_types_form(): void {
		$this->acting_as( 'administrator' );
		register_post_type(
			'oversio_book',
			array(
				'public'          => true,
				'show_in_rest'    => true,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
				'label'           => 'Books',
			)
		);
		update_option( 'oversio_allowed_post_types', array( 'oversio_book' ) );

		ob_start();
		oversio_render_abilities_tab();
		$html = (string) ob_get_clean();

		// HTML forbids nested <form> elements: the browser drops the inner tags, breaking the
		// JS save handler. Only the outer abilities form may open a <form> in this markup.
		$this->assertSame(
			1,
			substr_count( $html, '<form' ),
			'The abilities tab must not contain a nested form (the post-types selector must be a div).'
		);

		// The selector wrapper is a div, not a form.
		$this->assertStringContainsString( '<div id="oversio-post-types-form"', $html );
		$this->assertStringNotContainsString( '<form id="oversio-post-types-form"', $html );

		// The save control is a non-submit button so it can never submit the outer form.
		$this->assertStringContainsString( 'id="oversio-post-types-save"', $html );
		$this->assertStringContainsString( 'type="button"', $html );
	}

	public function test_content_panel_has_no_selector_when_no_eligible_cpts(): void {
		$this->acting_as( 'administrator' );
		// No public non-builtin CPTs registered in this isolated test → selector lists nothing to opt into.
		ob_start();
		oversio_render_abilities_tab();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'name="oversio_post_types[]"', $html );
	}

	public function test_privacy_policy_content_is_hooked_on_admin_init(): void {
		$this->assertNotFalse(
			has_action( 'admin_init', 'oversio_register_privacy_policy_content' ),
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

		oversio_register_privacy_policy_content();

		$entries = WP_Privacy_Policy_Content::get_suggested_policy_text();
		$ours    = wp_list_filter( $entries, array( 'plugin_name' => 'Oversio Agent Abilities' ) );
		$this->assertNotEmpty( $ours, 'Our plugin must contribute a suggested privacy-policy entry.' );

		$entry = reset( $ours );
		$this->assertStringContainsString(
			"read and change those keys' values",
			(string) $entry['policy_text'],
			'Suggested text must disclose that exposed meta keys are readable and writable by agents.'
		);
		$this->assertStringContainsString(
			'meta',
			strtolower( (string) $entry['policy_text'] ),
			'Suggested text must mention meta exposure.'
		);
	}
}
