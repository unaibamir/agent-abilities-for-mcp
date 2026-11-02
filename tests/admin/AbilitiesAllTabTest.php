<?php
/**
 * The Abilities tab's "All" view: first and selected by default, every subject panel visible under
 * its label, each checkbox rendered once, and bulk controls that reach the whole form.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AbilitiesAllTabTest extends TestCase {

	private function render(): string {
		$this->acting_as( 'administrator' );
		ob_start();
		aafm_render_abilities_tab();
		return (string) ob_get_clean();
	}

	public function test_all_is_the_first_tab_and_starts_selected(): void {
		$html = $this->render();
		$this->assertSame( 1, preg_match( '/<button type="button" class="aafm-subject-tab([^"]*)"[^>]*data-subject="([^"]+)">([^<]+)</', $html, $m ) );
		$this->assertSame( ' is-active', $m[1] );
		$this->assertSame( 'all', $m[2] );
		$this->assertSame( 'All ', $m[3] );
		$this->assertSame( 1, substr_count( $html, 'class="aafm-subject-tab is-active"' ) );
		$this->assertStringContainsString( '<div class="aafm-subject-all" role="tabpanel" id="aafm-subject-panel-all" aria-labelledby="aafm-subject-tab-all">', $html );
	}

	public function test_the_all_tab_count_is_every_ability_on_the_tab(): void {
		$html = $this->render();
		preg_match( '/data-subject="all">All <span class="count">(\d+)</', $html, $m );
		preg_match_all( '/data-subject="(?!all")[^"]+">[^<]+<span class="count">(\d+)</', $html, $counts );
		$this->assertSame( array_sum( array_map( 'intval', $counts[1] ) ), (int) $m[1] );
		$this->assertSame( (int) $m[1], substr_count( $html, 'class="aafm-ability-row"' ) );
	}

	public function test_the_all_note_points_only_at_the_integrations_tab(): void {
		$html = $this->render();
		$this->assertSame( 1, preg_match( '/<p class="aafm-card-desc aafm-subject-all-note">([^<]+)</', $html, $m ) );
		$this->assertStringEndsWith( 'on the Integrations tab.', $m[1] );
	}

	public function test_every_panel_shows_with_its_label_and_no_checkbox_is_duplicated(): void {
		$html = $this->render();
		preg_match_all( '/<div class="aafm-subject-panel"[^>]*>/', $html, $panels );
		$this->assertNotEmpty( $panels[0] );
		foreach ( $panels[0] as $open ) {
			$this->assertStringNotContainsString( ' hidden', $open );
		}
		$this->assertSame( count( $panels[0] ), substr_count( $html, '<p class="aafm-subject-search-label">' ) );

		preg_match_all( '/name="aafm_abilities\[\]" value="([^"]+)"/', $html, $boxes );
		$this->assertNotEmpty( $boxes[1] );
		$this->assertSame( count( $boxes[1] ), count( array_unique( $boxes[1] ) ) );
	}

	public function test_the_all_toolbar_carries_the_same_bulk_controls_scoped_to_all(): void {
		$html    = $this->render();
		$start   = strpos( $html, 'id="aafm-subject-panel-all"' );
		$toolbar = substr( $html, (int) $start, (int) strpos( $html, '</div>', (int) $start ) - (int) $start );
		$this->assertStringContainsString( 'class="aafm-btn aafm-btn-secondary aafm-section-toggle-all" data-subject="all" data-has-destructive="1"', $toolbar );
		$this->assertStringContainsString( 'aafm-enable-reads', $toolbar );
		$this->assertStringContainsString( 'aafm-enable-writes', $toolbar );
		$this->assertStringContainsString( 'id="aafm-write-note-all"', $toolbar );
	}

	public function test_the_script_scopes_the_all_controls_to_the_form(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin asset off disk, never a remote URL.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		$this->assertStringContainsString( "'.aafm-subject-panel, .aafm-integration-card, #aafm-abilities-form'", $js );
		$this->assertStringContainsString( "? btn.closest( '#aafm-abilities-form' )", $js );
		$this->assertStringContainsString( 'panel.hidden = ! all && panel.dataset.subject !== subject;', $js );
	}
}
