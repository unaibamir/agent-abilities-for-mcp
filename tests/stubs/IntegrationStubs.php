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
	 * Define the minimal ACF host surface so detection reports ACF active and the ACF abilities
	 * can read field-group structure + hydrated values and record writes.
	 *
	 * ACF's functions are global and defined once per process, so the actual field/value state
	 * lives in a process-wide store (AcfStubStore) that this helper RESETS and seeds on every
	 * call. The guarded function definitions below read+write that store, so within a test a
	 * value written through update_field() is visible to a following get_field()/get_fields().
	 *
	 * Config shape (per the A1 plan):
	 *   array(
	 *     'groups' => array( array( 'key' => 'group_1', 'title' => 'Hero',
	 *                  'fields' => array( array( 'key' => 'field_1', 'label' => 'Headline', 'type' => 'text' ) ) ) ),
	 *     'values' => array( 'field_1' => 'Hello' ),  // seeds the current object's hydrated values.
	 *   )
	 *
	 * Defining get_field() makes real ACF detection true; the slice still forces the integration
	 * filter explicitly, and the host-inactive test drives the aafm_acf_active seam to false.
	 *
	 * @param array<string,mixed> $config Group + value seed.
	 * @return void
	 */
	protected function stub_acf( array $config ): void {
		AcfStubStore::reset();
		AcfStubStore::$groups = isset( $config['groups'] ) && is_array( $config['groups'] ) ? $config['groups'] : array();
		$values               = isset( $config['values'] ) && is_array( $config['values'] ) ? $config['values'] : array();
		// Seed the seeded values under every object selector the test might read, plus the
		// "current object" bucket (selector '' / 0) ACF uses when no explicit id is given.
		AcfStubStore::$seed_values = $values;

		// Build the field-definition index (key => {key,label,type}) from the group fields so
		// acf_get_field() can resolve a field's type for type-aware sanitize.
		foreach ( AcfStubStore::$groups as $group ) {
			$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array();
			foreach ( $fields as $field ) {
				if ( isset( $field['key'] ) ) {
					AcfStubStore::$field_defs[ (string) $field['key'] ] = $field;
				}
			}
		}

		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function acf_get_field_groups( $args = array() ) { return \AAFM\Tests\AcfStubStore::groups_without_fields(); }' );
		}
		if ( ! function_exists( 'acf_get_fields' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function acf_get_fields( $group ) { return \AAFM\Tests\AcfStubStore::fields_for_group( $group ); }' );
		}
		if ( ! function_exists( 'acf_get_field' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function acf_get_field( $key ) { return \AAFM\Tests\AcfStubStore::field_def( $key ); }' );
		}
		if ( ! function_exists( 'get_fields' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function get_fields( $selector = false ) { return \AAFM\Tests\AcfStubStore::all_values( $selector ); }' );
		}
		if ( ! function_exists( 'get_field' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function get_field( $selector, $post_id = false, $format = true ) { return \AAFM\Tests\AcfStubStore::value( $selector, $post_id ); }' );
		}
		if ( ! function_exists( 'update_field' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- function-only marker stub for tests; never shipped.
			eval( 'function update_field( $selector, $value, $post_id = false ) { return \AAFM\Tests\AcfStubStore::record( $selector, $value, $post_id ); }' );
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
		AcfStubStore::reset();
	}
}
