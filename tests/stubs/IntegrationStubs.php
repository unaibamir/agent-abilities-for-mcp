<?php
/**
 * Shared host-API stub helpers for the Wave 4 integration tests.
 *
 * The DDEV site ships none of the five host plugins (Yoast / Rank Math / AIOSEO /
 * ACF / WooCommerce) and must stay that way, so every integration slice forces its
 * integration active through the per-slug filter and defines just the slice of the
 * host API its abilities call. This trait centralises that: force_integration()
 * flips the filter (and remembers it so tear-down removes it), and the per-host
 * helpers below define the minimal stubs a slice needs. Every class/function stub is
 * guarded so a second include in the same process never fatals.
 *
 * This file lives under tests/ and never ships; the source-scan security rails only
 * walk includes/, so nothing here is in their scope.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

/**
 * Host-API stub helpers, mixed into an integration slice's test case.
 */
trait IntegrationStubs {

	/**
	 * Slugs forced active during the current test, removed on tear-down.
	 *
	 * @var array<int,string>
	 */
	private array $aafm_forced_integrations = array();

	/**
	 * Force an integration active for the current test via its per-slug filter.
	 *
	 * @param string $slug One of 'seo' | 'acf' | 'woocommerce'.
	 * @return void
	 */
	protected function force_integration( string $slug ): void {
		add_filter( 'aafm_integration_active_' . $slug, '__return_true' );
		$this->aafm_forced_integrations[] = $slug;
	}

	/**
	 * Define the minimal SEO host surface for a given plugin so detection reports it
	 * active and aafm_seo_meta_keys() resolves the right key map.
	 *
	 * The SEO abilities read/write the mapped keys with core get_post_meta /
	 * update_post_meta, so a "stub" only needs the active-plugin signal — once the
	 * detection marker is defined, aafm_seo_active_plugin() returns this plugin and
	 * the production key map applies. No host classes or filter override are required.
	 *
	 * @param string $plugin 'yoast' | 'rankmath' | 'aioseo'.
	 * @return void
	 */
	protected function stub_seo_plugin( string $plugin ): void {
		switch ( $plugin ) {
			case 'yoast':
				if ( ! defined( 'WPSEO_VERSION' ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- mimicking Yoast's own constant so detection sees it; a test stub, never shipped.
					define( 'WPSEO_VERSION', 'stub-test' );
				}
				break;
			case 'rankmath':
				if ( ! class_exists( 'RankMath' ) ) {
					eval( 'class RankMath {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class-only marker stub for tests; never shipped.
				}
				break;
			case 'aioseo':
				if ( ! function_exists( 'aioseo' ) ) {
					eval( 'function aioseo() { return new \stdClass(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a function-only marker stub for tests; never shipped.
				}
				break;
		}
	}

	/**
	 * Remove every filter this trait added. Call from the slice's tear_down().
	 *
	 * @return void
	 */
	protected function reset_integration_stubs(): void {
		foreach ( $this->aafm_forced_integrations as $slug ) {
			remove_filter( 'aafm_integration_active_' . $slug, '__return_true' );
		}
		$this->aafm_forced_integrations = array();
	}
}
