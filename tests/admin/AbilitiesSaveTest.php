<?php
/**
 * Abilities tab save logic: sanitize, intersect with registry, default-off.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AbilitiesSaveTest extends TestCase {

	public function test_sanitize_keeps_only_known_abilities(): void {
		$posted  = array( 'aafm_abilities' => array( 'aafm/get-posts', 'aafm/ghost', '<script>' ) );
		$enabled = aafm_sanitize_enabled_input( $posted );
		$this->assertSame( array( 'aafm/get-posts' ), $enabled );
	}

	public function test_empty_post_disables_everything(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		$enabled = aafm_sanitize_enabled_input( array() );
		$this->assertSame( array(), $enabled );
	}

	public function test_unknown_keys_alone_disable_everything(): void {
		// A payload of only unknown keys must never enable anything (no fall-through).
		$enabled = aafm_sanitize_enabled_input( array( 'aafm_abilities' => array( 'aafm/ghost', 'option_update' ) ) );
		$this->assertSame( array(), $enabled );
	}

	public function test_menu_is_registered_for_admins(): void {
		$this->acting_as( 'administrator' );
		aafm_register_admin_menu();
		global $submenu;
		$slugs = array();
		foreach ( (array) ( $submenu['options-general.php'] ?? array() ) as $item ) {
			$slugs[] = $item[2];
		}
		$this->assertContains( 'agent-abilities-for-mcp', $slugs );
	}

	public function test_abilities_tab_renders_checkboxes_for_registry_entries(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );

		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		// The enabled ability is present and checked; the value attribute is escaped.
		$this->assertStringContainsString( 'value="aafm/get-posts"', $html );
		$this->assertStringContainsString( 'name="aafm_abilities[]"', $html );
		$this->assertStringContainsString( 'checked', $html );
	}

	public function test_every_registry_entry_declares_a_subject(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertNotEmpty( $registry );
		$known = array_keys( aafm_abilities_subjects() );
		foreach ( $registry as $name => $meta ) {
			$this->assertArrayHasKey( 'subject', $meta, "{$name} is missing a subject." );
			$this->assertNotSame( '', (string) $meta['subject'], "{$name} has an empty subject." );
			$this->assertContains(
				(string) $meta['subject'],
				$known,
				"{$name} declares an unknown subject '{$meta['subject']}'."
			);
		}
	}

	public function test_abilities_tab_renders_a_sub_tab_per_used_subject(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		// Every subject that has at least one ability must get a sub-tab.
		$registry      = aafm_get_abilities_registry();
		$used_subjects = array();
		foreach ( $registry as $meta ) {
			$used_subjects[ (string) $meta['subject'] ] = true;
		}
		foreach ( array_keys( $used_subjects ) as $slug ) {
			$this->assertStringContainsString(
				'aafm-subject-tab',
				$html,
				'The subject sub-tab bar should render.'
			);
			$this->assertStringContainsString( 'data-subject="' . $slug . '"', $html, "Missing sub-tab for {$slug}." );
		}
	}

	public function test_each_ability_appears_under_its_subject_with_reads_before_writes(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		// The Content panel holds posts + pages; its Reads heading must precede its Writes heading,
		// and its checkboxes must sit inside that panel (not in another subject's panel). The panel
		// runs from its own open to the next subject panel's open (or, if content is the last panel,
		// the abilities form's save-status span), so slice on that boundary rather than the first
		// </div> — the notice component and the meta selector both nest <div>s inside the panel,
		// which a naive first-</div> slice would catch. The fallback keys off aafm-save-status,
		// which the form renders exactly once after every panel, rather than the shared
		// button-primary class that also marks the post-types and meta-keys save buttons.
		$content_open = strpos( $html, 'class="aafm-subject-panel" data-subject="content"' );
		$this->assertNotFalse( $content_open, 'Content panel should render.' );
		$next_panel    = strpos( $html, 'class="aafm-subject-panel" data-subject=', $content_open + 1 );
		$content_close = ( false === $next_panel ) ? strpos( $html, 'aafm-save-status', $content_open ) : $next_panel;
		$content_panel = substr( $html, $content_open, ( false === $content_close ? null : $content_close - $content_open ) );

		$reads_pos  = strpos( $content_panel, '>Reads<' );
		$writes_pos = strpos( $content_panel, '>Writes<' );
		$this->assertNotFalse( $reads_pos, 'Content panel should have a Reads heading.' );
		$this->assertNotFalse( $writes_pos, 'Content panel should have a Writes heading.' );
		$this->assertLessThan( $writes_pos, $reads_pos, 'Reads must come before Writes.' );

		// A content read and a content write both live in the content panel.
		$this->assertStringContainsString( 'value="aafm/get-posts"', $content_panel );
		$this->assertStringContainsString( 'value="aafm/trash-post"', $content_panel );

		// A media ability must NOT bleed into the content panel.
		$this->assertStringNotContainsString( 'value="aafm/get-media"', $content_panel );
	}

	public function test_saving_ability_from_a_non_default_subject_persists(): void {
		// 'aafm/get-media' lives under the Media sub-tab, which is never the default (Content is).
		// The save path is the same flat list regardless of which sub-tab was visible.
		$posted  = array( 'aafm_abilities' => array( 'aafm/get-media' ) );
		$enabled = aafm_sanitize_enabled_input( $posted );
		update_option( 'aafm_enabled_abilities', $enabled );

		$this->assertContains( 'aafm/get-media', aafm_get_enabled_abilities() );
		$this->assertTrue( aafm_is_ability_enabled( 'aafm/get-media' ) );
	}
}
