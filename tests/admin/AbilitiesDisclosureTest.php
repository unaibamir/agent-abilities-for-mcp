<?php
/**
 * Per-ability disclosure map and Abilities-tab badge rendering.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class AbilitiesDisclosureTest extends TestCase {

	public function test_every_ability_has_a_disclosure(): void {
		$disclosures = aafm_ability_disclosures();
		foreach ( array_keys( aafm_get_abilities_registry() ) as $name ) {
			$this->assertArrayHasKey( $name, $disclosures, "Missing disclosure for $name" );
			$this->assertNotEmpty( $disclosures[ $name ] );
		}
	}

	public function test_abilities_tab_shows_hint_and_badges(): void {
		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'aafm-ability-hint', $html );
		$this->assertStringContainsString( 'aafm-badge', $html );
		$this->assertStringContainsString( 'aafm-count-badge', $html );
	}

	public function test_read_only_rows_carry_a_read_only_badge_and_writes_do_not(): void {
		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		// A read-only ability row must carry the read-only badge class.
		$this->assertStringContainsString( 'aafm-readonly-badge', $html );

		// The read-only badge count must equal the number of read-risk abilities, proving
		// it lands on reads and never leaks onto write/destructive rows.
		$read_only = 0;
		foreach ( aafm_get_abilities_registry() as $meta ) {
			if ( 'read' === ( $meta['risk'] ?? '' ) ) {
				++$read_only;
			}
		}
		$this->assertSame(
			$read_only,
			substr_count( $html, 'aafm-readonly-badge' ),
			'Read-only badge count must equal the number of read-risk abilities.'
		);
	}

	public function test_per_subject_count_badge_is_enabled_over_total(): void {
		// Enable one read in the content subject; the content count badge must read 1 / total.
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );

		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		$content_total = 0;
		foreach ( aafm_get_abilities_registry() as $meta ) {
			if ( 'content' === ( $meta['subject'] ?? '' ) ) {
				++$content_total;
			}
		}

		$this->assertMatchesRegularExpression(
			'~aafm-count-badge[^>]*>\s*1\s*/\s*' . $content_total . '~',
			$html,
			'Content subject count badge must show enabled/total (1 / ' . $content_total . ').'
		);
	}
}
