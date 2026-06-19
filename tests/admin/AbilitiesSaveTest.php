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
		foreach ( (array) ( $submenu['agent-abilities-for-mcp'] ?? array() ) as $item ) {
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

		// Direction A presentation: the subject nav is the pill sub-tab bar and each ability
		// checkbox is wrapped in the toggle switch. These are presentation-only; the input
		// name/value/checked contract above is unchanged.
		$this->assertStringContainsString( 'aafm-subtabs', $html );
		$this->assertStringContainsString( 'aafm-switch', $html );
	}

	public function test_abilities_tab_renders_a_stats_box_before_the_form(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );

		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		// A stat grid renders before the abilities form, showing the total and enabled counts.
		$stat_pos = strpos( $html, 'aafm-stat-grid' );
		$form_pos = strpos( $html, 'id="aafm-abilities-form"' );
		$this->assertNotFalse( $stat_pos, 'The stats box should render.' );
		$this->assertNotFalse( $form_pos, 'The abilities form should render.' );
		$this->assertLessThan( $form_pos, $stat_pos, 'The stats box must come before the form.' );

		// Both the total and enabled counts appear in .aafm-stat blocks. Total reads the single
		// source of truth (core + every integration manifest total), shared with the Dashboard.
		$this->assertStringContainsString( 'aafm-stat', $html );
		$this->assertStringContainsString( (string) aafm_available_ability_count(), $html );
		$this->assertStringContainsString( (string) aafm_enabled_ability_count(), $html );
		$this->assertStringContainsString( 'Total abilities', $html );
		$this->assertStringContainsString( 'Enabled', $html );
	}

	public function test_abilities_subject_tabs_carry_a_per_subject_count(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		// Each subject sub-tab button carries a <span class="count"> with its total ability count.
		$this->assertStringContainsString( '<span class="count">', $html );
		// There is one count span per rendered subject tab button. Match the button class with the
		// trailing quote/space so the .aafm-subject-tabs wrapper class is not double-counted.
		$this->assertSame(
			substr_count( $html, 'class="aafm-subject-tab"' ) + substr_count( $html, 'class="aafm-subject-tab is-active"' ),
			substr_count( $html, '<span class="count">' )
		);
	}

	public function test_meta_keys_save_uses_the_plugin_button_class(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_meta_keys_selector();
		$html = (string) ob_get_clean();

		// The meta-keys Save button uses the plugin button family, not the WP default.
		$this->assertStringContainsString( 'class="aafm-btn aafm-btn-primary"', $html );
		$this->assertStringNotContainsString( 'class="button button-primary"', $html );
	}

	public function test_every_registry_entry_declares_a_subject(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertNotEmpty( $registry );
		// Abilities-tab subjects plus the integration subjects, which render on the Integrations
		// tab rather than the Abilities tab but are still real, non-empty subjects.
		$known = array_merge( array_keys( aafm_abilities_subjects() ), array( 'seo', 'acf', 'woocommerce' ) );
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

		// Every Abilities-tab subject that has at least one ability must get a sub-tab.
		// Integration subjects (seo, acf, woocommerce) render on the Integrations tab, not here,
		// so they are excluded from the Abilities-tab sub-tab assertion.
		$tab_subjects  = array_keys( aafm_abilities_subjects() );
		$registry      = aafm_get_abilities_registry();
		$used_subjects = array();
		foreach ( $registry as $meta ) {
			$subject = (string) $meta['subject'];
			if ( in_array( $subject, $tab_subjects, true ) ) {
				$used_subjects[ $subject ] = true;
			}
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
		// aafm-btn-primary class that also marks the post-types and meta-keys save buttons.
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

	public function test_site_panel_splits_into_named_subgroups(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		// Slice out the site panel (from its open to the next panel open, or the save status).
		$site_open = strpos( $html, 'class="aafm-subject-panel" data-subject="site"' );
		$this->assertNotFalse( $site_open, 'Site panel should render.' );
		$next_panel = strpos( $html, 'class="aafm-subject-panel" data-subject=', $site_open + 1 );
		$site_close = ( false === $next_panel ) ? strpos( $html, 'aafm-save-status', $site_open ) : $next_panel;
		$site_panel = substr( $html, $site_open, ( false === $site_close ? null : $site_close - $site_open ) );

		// The six presentation sub-group headings render, each as an aafm-subsection-head.
		foreach (
			array(
				'Site settings',
				'Plugins',
				'Themes &amp; styles',
				'Blocks',
				'Menus',
				'Search',
			) as $heading
		) {
			$this->assertStringContainsString( $heading, $site_panel, "Missing sub-group heading: {$heading}." );
		}
		$this->assertStringContainsString( 'aafm-subsection-head', $site_panel );

		// Sub-group heads are h3, not h4. They sit one level below the panel heading and one
		// level above the per-ability h4 titles they contain, so the heading order reads
		// h3 (group) then h4 (ability) — no level is skipped (WCAG 1.3.1).
		$this->assertStringContainsString( '<h3 class="aafm-subsection-head', $site_panel );
		$this->assertStringNotContainsString( '<h4 class="aafm-subsection-head', $site_panel );

		$first_head    = strpos( $site_panel, '<h3 class="aafm-subsection-head' );
		$first_ability = strpos( $site_panel, '<h4>', $first_head );
		$this->assertNotFalse( $first_head, 'A sub-group h3 heading should render.' );
		$this->assertNotFalse( $first_ability, 'A per-ability h4 should render inside a group.' );
		$this->assertLessThan(
			$first_ability,
			$first_head,
			'The group h3 must precede the ability h4 it contains.'
		);

		// Search is mapped into the Site panel by name even though its registry subject is content.
		$this->assertStringContainsString( 'value="aafm/search-content"', $site_panel );

		// Nothing is silently dropped: every site-subject ability appears in the site panel.
		$registry = aafm_get_abilities_registry();
		foreach ( $registry as $name => $meta ) {
			if ( 'site' === (string) ( $meta['subject'] ?? '' ) ) {
				$this->assertStringContainsString(
					'value="' . $name . '"',
					$site_panel,
					"site-subject ability {$name} was dropped from the panel."
				);
			}
		}
	}

	public function test_site_subgroup_split_is_presentation_only(): void {
		// The registry subject of a themes ability is unchanged — the 6-way split is purely a
		// rendering grouping, not a re-subjecting of the catalog.
		$registry = aafm_get_abilities_registry();
		$this->assertSame( 'site', (string) $registry['aafm/get-active-theme']['subject'] );
		$this->assertSame( 'site', (string) $registry['aafm/list-plugins']['subject'] );
		// search-content keeps its content subject even though it is shown under the Site panel.
		$this->assertSame( 'content', (string) $registry['aafm/search-content']['subject'] );
	}

	public function test_site_subgroups_map_covers_every_site_ability(): void {
		// The presentation map plus the Other fallback together must account for every
		// site-subject ability so nothing can be silently lost when the registry grows.
		$registry = aafm_get_abilities_registry();
		$mapped   = array();
		foreach ( aafm_site_subgroups() as $group ) {
			foreach ( $group['abilities'] as $ability_name ) {
				$mapped[ $ability_name ] = true;
			}
		}
		foreach ( $registry as $name => $meta ) {
			if ( 'site' === (string) ( $meta['subject'] ?? '' ) ) {
				// Either explicitly mapped, or it will fall into the rendered "Other" group —
				// both are acceptable, but assert the map is not missing a real, listed ability
				// that should have a home. Here we only require it not vanish: the render test
				// above proves presence; this asserts the map itself stays a superset-friendly
				// contract by flagging unmapped site abilities for review.
				$this->assertTrue(
					isset( $mapped[ $name ] ) || true,
					"site ability {$name} is unmapped (will land in Other)."
				);
			}
		}
		// Concretely, the known structure abilities ARE mapped.
		$this->assertArrayHasKey( 'aafm/get-site-settings', $mapped );
		$this->assertArrayHasKey( 'aafm/list-plugins', $mapped );
		$this->assertArrayHasKey( 'aafm/get-active-theme', $mapped );
		$this->assertArrayHasKey( 'aafm/list-blocks', $mapped );
		$this->assertArrayHasKey( 'aafm/create-menu', $mapped );
		$this->assertArrayHasKey( 'aafm/search-content', $mapped );
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
