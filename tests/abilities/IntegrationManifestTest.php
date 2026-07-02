<?php
/**
 * Static per-integration ability count manifest.
 *
 * The manifest reports per-integration totals independent of whether the host plugin is
 * active, so an Integrations card for an inactive host can show "0 / N". Its per-slug totals,
 * summed with the core (non-integration) count, must equal the catalog-lock total - a drift
 * catcher so a future ability addition that skips the manifest fails loudly here.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class IntegrationManifestTest extends TestCase {

	public function test_manifest_reports_woocommerce_totals_without_the_host_active(): void {
		// WooCommerce is NOT installed on the test site, yet the static manifest still reports
		// its totals (that is the whole point - the card can show "0 / 52" while inactive).
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );

		$manifest = aafm_integration_manifest();
		$this->assertArrayHasKey( 'woocommerce', $manifest );

		$wc = $manifest['woocommerce'];
		$this->assertSame( 52, $wc['total'] );
		$this->assertSame( 27, $wc['read'] );
		$this->assertSame( 23, $wc['write'] );
		$this->assertSame( 2, $wc['destructive'] );
		// read + write + destructive must reconcile to the slug total.
		$this->assertSame( $wc['total'], $wc['read'] + $wc['write'] + $wc['destructive'] );
	}

	public function test_every_manifest_slug_reconciles_internally(): void {
		foreach ( aafm_integration_manifest() as $slug => $counts ) {
			foreach ( array( 'total', 'read', 'write', 'destructive' ) as $key ) {
				$this->assertArrayHasKey( $key, $counts, "{$slug} manifest missing {$key}." );
				$this->assertIsInt( $counts[ $key ] );
			}
			$this->assertSame(
				$counts['total'],
				$counts['read'] + $counts['write'] + $counts['destructive'],
				"{$slug} manifest total does not reconcile with its read/write/destructive split."
			);
		}
	}

	public function test_manifest_plus_core_equals_the_catalog_lock(): void {
		// With every host force-active the registry holds the full catalog. The non-integration
		// (core) abilities plus the manifest's per-slug integration totals must equal the lock.
		add_filter( 'aafm_integration_active_yoast', '__return_true' );
		add_filter( 'aafm_integration_active_rankmath', '__return_true' );
		add_filter( 'aafm_integration_active_aioseo', '__return_true' );
		add_filter( 'aafm_integration_active_acf', '__return_true' );
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );

		$registry      = aafm_get_abilities_registry();
		$manifest      = aafm_integration_manifest();
		$manifest_slug = array_keys( $manifest );

		// Core = registry entries whose subject is not one of the manifest's integration slugs.
		$core = 0;
		foreach ( $registry as $meta ) {
			if ( ! in_array( (string) ( $meta['subject'] ?? '' ), $manifest_slug, true ) ) {
				++$core;
			}
		}

		$manifest_total = 0;
		foreach ( $manifest as $counts ) {
			$manifest_total += $counts['total'];
		}

		$this->assertSame(
			count( $registry ),
			$core + $manifest_total,
			'Manifest integration totals plus the core count must equal the live catalog total - drift detected.'
		);
		$this->assertSame( 153, $core + $manifest_total );

		aafm_registry_cache_should_flush( true );
	}

	public function test_available_ability_count_is_the_single_source_of_truth(): void {
		// The Dashboard and Abilities "available/total" both read this one function, so they
		// can never disagree. It equals core + every integration manifest total.
		$available = aafm_available_ability_count();
		$this->assertSame( 153, $available );
	}

	public function test_descriptor_counts_drive_the_manifest(): void {
		// The manifest is no longer a second hand-kept tally - its per-slug counts derive from the
		// descriptor, so a descriptor row added or removed moves the count automatically.
		$descriptor = aafm_integration_ability_manifest();
		$manifest   = aafm_integration_manifest();

		foreach ( $descriptor as $slug => $rows ) {
			$this->assertArrayHasKey( $slug, $manifest, "{$slug} missing from the derived manifest." );

			$read        = 0;
			$write       = 0;
			$destructive = 0;
			foreach ( $rows as $row ) {
				switch ( (string) $row['risk'] ) {
					case 'read':
						++$read;
						break;
					case 'destructive':
						++$destructive;
						break;
					default:
						++$write;
				}
			}

			$this->assertSame( count( $rows ), $manifest[ $slug ]['total'], "{$slug} total drifts from the descriptor." );
			$this->assertSame( $read, $manifest[ $slug ]['read'], "{$slug} read count drifts from the descriptor." );
			$this->assertSame( $write, $manifest[ $slug ]['write'], "{$slug} write count drifts from the descriptor." );
			$this->assertSame( $destructive, $manifest[ $slug ]['destructive'], "{$slug} destructive count drifts from the descriptor." );
		}
	}

	public function test_descriptor_matches_the_live_registry_when_hosts_are_active(): void {
		// With every host force-active the registry holds the full integration surface. The
		// descriptor must describe exactly that set per slug - same ability names, same risks,
		// same count - so the static descriptor can never silently drift from the real abilities.
		add_filter( 'aafm_integration_active_yoast', '__return_true' );
		add_filter( 'aafm_integration_active_rankmath', '__return_true' );
		add_filter( 'aafm_integration_active_aioseo', '__return_true' );
		add_filter( 'aafm_integration_active_acf', '__return_true' );
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );

		$registry   = aafm_get_abilities_registry();
		$descriptor = aafm_integration_ability_manifest();

		// Live registry rows bucketed by integration subject: name => risk.
		$live = array();
		foreach ( $registry as $name => $meta ) {
			$subject = (string) ( $meta['subject'] ?? '' );
			if ( isset( $descriptor[ $subject ] ) ) {
				$live[ $subject ][ (string) $name ] = (string) ( $meta['risk'] ?? '' );
			}
		}

		foreach ( $descriptor as $slug => $rows ) {
			$this->assertArrayHasKey( $slug, $live, "No live registry rows for {$slug} - descriptor describes a phantom set." );
			$this->assertSame(
				count( $live[ $slug ] ),
				count( $rows ),
				"{$slug} descriptor count differs from the live registry."
			);
			foreach ( $rows as $row ) {
				$name = (string) $row['name'];
				$this->assertArrayHasKey( $name, $live[ $slug ], "{$slug} descriptor lists {$name}, absent from the live registry." );
				$this->assertSame(
					$live[ $slug ][ $name ],
					(string) $row['risk'],
					"{$slug} descriptor risk for {$name} differs from the live registry."
				);
			}
		}

		aafm_registry_cache_should_flush( true );
	}
}
