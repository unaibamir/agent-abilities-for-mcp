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

	public function set_up(): void {
		parent::set_up();

		// Wave 4: the no-orphan check would fail if a disclosed integration ability were
		// absent from the registry (host inactive). Force all three integrations active
		// (+ the mandatory registry-memo flush) so disclosure ↔ registry stays 1:1 once the
		// SEO/ACF/WC slices add both ends. No integration ability exists yet in this slice.
		add_filter( 'aafm_integration_active_yoast', '__return_true' );
		add_filter( 'aafm_integration_active_rankmath', '__return_true' );
		add_filter( 'aafm_integration_active_aioseo', '__return_true' );
		add_filter( 'aafm_integration_active_acf', '__return_true' );
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );
	}

	public function test_every_ability_has_a_disclosure(): void {
		$disclosures = aafm_ability_disclosures();
		foreach ( array_keys( aafm_get_abilities_registry() ) as $name ) {
			$this->assertArrayHasKey( $name, $disclosures, "Missing disclosure for $name" );
			$this->assertNotEmpty( $disclosures[ $name ] );
		}
	}

	/**
	 * L6: aafm/get-post and aafm/get-page (aafm_exec_get_post()/aafm_exec_get_page() in
	 * includes/abilities/) both call aafm_rich_post() with include_content left at its
	 * default of true, so a single-object read DOES return the full body - the disclosure
	 * previously claimed the opposite.
	 */
	public function test_get_post_and_get_page_disclosures_do_not_deny_the_body(): void {
		$disclosures = aafm_ability_disclosures();
		$this->assertStringNotContainsString( 'Not the full body', $disclosures['aafm/get-post'] );
		$this->assertStringContainsString( 'body', $disclosures['aafm/get-post'] );
		$this->assertStringContainsString( 'body', $disclosures['aafm/get-page'] );
	}

	public function test_no_orphan_disclosure_keys(): void {
		$disclosures = array_keys( aafm_ability_disclosures() );
		$registry    = array_keys( aafm_get_abilities_registry() );
		$orphans     = array_diff( $disclosures, $registry );
		$this->assertSame( array(), array_values( $orphans ), 'Disclosure map has keys for abilities not in the registry: ' . implode( ', ', $orphans ) );
	}

	public function test_abilities_tab_shows_hint_and_badges(): void {
		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'aafm-ability-hint', $html );
		$this->assertStringContainsString( 'aafm-badge', $html );
		$this->assertStringContainsString( 'aafm-count-badge', $html );

		// Direction A presentation: abilities render as toggle rows inside grouped cards.
		$this->assertStringContainsString( 'aafm-switch', $html );
		$this->assertStringContainsString( 'aafm-ability-row', $html );
	}

	public function test_read_only_rows_carry_a_read_only_badge_and_writes_do_not(): void {
		ob_start();
		aafm_render_abilities_tab();
		$html = (string) ob_get_clean();

		// A read-only ability row must carry the read-only badge class.
		$this->assertStringContainsString( 'aafm-readonly-badge', $html );

		// The read-only badge count must equal the number of read-risk abilities the Abilities
		// tab actually renders, proving it lands on reads and never leaks onto write/destructive
		// rows. Integration abilities (subject 'seo', etc.) live on the Integrations tab, not
		// here, so only abilities whose subject is an Abilities-tab subject are counted.
		$tab_subjects = array_keys( aafm_abilities_subjects() );
		$read_only    = 0;
		foreach ( aafm_get_abilities_registry() as $meta ) {
			if ( 'read' === ( $meta['risk'] ?? '' ) && in_array( (string) ( $meta['subject'] ?? '' ), $tab_subjects, true ) ) {
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

		// The Content tab's badge counts what it actually renders: content-subject abilities minus
		// any relocated into a site display tab by name (search-content shows under the Search tab,
		// not Content). So the total is content-subject count less those relocated abilities.
		$relocated = array();
		$registry  = aafm_get_abilities_registry();
		foreach ( aafm_site_subgroups() as $group ) {
			foreach ( $group['abilities'] as $ability_name ) {
				if ( isset( $registry[ $ability_name ] ) && 'content' === (string) ( $registry[ $ability_name ]['subject'] ?? '' ) ) {
					$relocated[ $ability_name ] = true;
				}
			}
		}

		$content_total = 0;
		foreach ( $registry as $name => $meta ) {
			if ( 'content' === ( $meta['subject'] ?? '' ) && ! isset( $relocated[ (string) $name ] ) ) {
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
