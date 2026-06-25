<?php
/**
 * Registry plumbing + enabled-abilities option.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Unit;

use Oversio\Tests\TestCase;

final class RegistryTest extends TestCase {

	public function test_registry_is_filterable(): void {
		$cb = static function ( array $r ): array {
			$r['oversio/demo'] = array(
				'label' => 'Demo',
				'group' => 'reads',
			);
			return $r;
		};
		add_filter( 'oversio_abilities_registry', $cb );

		$registry = oversio_get_abilities_registry();
		$this->assertArrayHasKey( 'oversio/demo', $registry );

		remove_filter( 'oversio_abilities_registry', $cb );
	}

	public function test_nothing_enabled_by_default(): void {
		$this->assertSame( array(), oversio_get_enabled_abilities() );
		$this->assertFalse( oversio_is_ability_enabled( 'oversio/get-posts' ) );
	}

	public function test_enabling_persists_and_reads_back(): void {
		$cb = static function ( array $r ): array {
			$r['oversio/get-posts'] = array(
				'label' => 'Get Posts',
				'group' => 'reads',
			);
			return $r;
		};
		add_filter( 'oversio_abilities_registry', $cb );

		update_option( 'oversio_enabled_abilities', array( 'oversio/get-posts' ) );
		$this->assertTrue( oversio_is_ability_enabled( 'oversio/get-posts' ) );
		$this->assertFalse( oversio_is_ability_enabled( 'oversio/trash-post' ) );

		remove_filter( 'oversio_abilities_registry', $cb );
	}

	public function test_registry_is_memoized_within_a_request(): void {
		$runs = 0;
		$cb   = static function ( array $r ) use ( &$runs ): array {
			++$runs;
			return $r;
		};
		add_filter( 'oversio_abilities_registry', $cb );

		oversio_get_abilities_registry();
		oversio_get_abilities_registry();
		oversio_get_abilities_registry();

		remove_filter( 'oversio_abilities_registry', $cb );

		// The filter set is fixed within a request, so the catalog is built once and
		// the heavy __()/array churn doesn't repeat on every call.
		$this->assertSame( 1, $runs, 'oversio_abilities_registry filter re-ran instead of being memoized.' );
	}

	public function test_registry_cache_flush_rebuilds(): void {
		// Prime the memo with the current (no-demo) catalog.
		$this->assertArrayNotHasKey( 'oversio/flush-demo', oversio_get_abilities_registry() );

		$cb = static function ( array $r ): array {
			$r['oversio/flush-demo'] = array(
				'label' => 'Flush Demo',
				'group' => 'reads',
			);
			return $r;
		};
		add_filter( 'oversio_abilities_registry', $cb );

		// Without a flush the memo still holds the pre-filter catalog.
		$this->assertArrayNotHasKey( 'oversio/flush-demo', oversio_get_abilities_registry() );

		// Flushing rebuilds, so the new filter contribution appears.
		oversio_flush_registry_cache();
		$this->assertArrayHasKey( 'oversio/flush-demo', oversio_get_abilities_registry() );

		remove_filter( 'oversio_abilities_registry', $cb );
		oversio_flush_registry_cache();
		$this->assertArrayNotHasKey( 'oversio/flush-demo', oversio_get_abilities_registry() );
	}

	public function test_enabled_list_is_intersected_with_known_registry(): void {
		$cb = static function ( array $r ): array {
			$r['oversio/get-posts'] = array(
				'label' => 'Get Posts',
				'group' => 'reads',
			);
			return $r;
		};
		add_filter( 'oversio_abilities_registry', $cb );

		// A stale/unknown key in the option must never be treated as enabled.
		update_option( 'oversio_enabled_abilities', array( 'oversio/get-posts', 'oversio/ghost' ) );
		$this->assertSame( array( 'oversio/get-posts' ), oversio_get_enabled_abilities() );

		remove_filter( 'oversio_abilities_registry', $cb );
	}

	public function test_removed_unified_seo_abilities_are_gone_and_a_stale_enabled_key_is_harmless(): void {
		// Wave 5 removed the unified seo-* abilities in favour of the per-plugin sets. Even with every
		// SEO host force-active, the old names must be absent from the registry, and a stale enabled
		// option entry for one of them must resolve cleanly (intersected away) rather than fatal —
		// the migration-safety guarantee, no migration code needed.
		add_filter( 'oversio_integration_active_yoast', '__return_true' );
		add_filter( 'oversio_integration_active_rankmath', '__return_true' );
		add_filter( 'oversio_integration_active_aioseo', '__return_true' );
		oversio_registry_cache_should_flush( true );

		$registry = oversio_get_abilities_registry();
		foreach ( array( 'oversio/seo-get-post', 'oversio/seo-update-post', 'oversio/seo-get-schema', 'oversio/seo-update-schema', 'oversio/seo-get-head' ) as $dead ) {
			$this->assertArrayNotHasKey( $dead, $registry, $dead . ' must be gone from the registry.' );
		}

		update_option( 'oversio_enabled_abilities', array( 'oversio/seo-get-post', 'oversio/yoast-get-post' ) );
		$this->assertSame(
			array( 'oversio/yoast-get-post' ),
			oversio_get_enabled_abilities(),
			'A stale seo-* enabled key must be dropped, leaving the live per-plugin key.'
		);

		remove_filter( 'oversio_integration_active_yoast', '__return_true' );
		remove_filter( 'oversio_integration_active_rankmath', '__return_true' );
		remove_filter( 'oversio_integration_active_aioseo', '__return_true' );
		oversio_registry_cache_should_flush( true );
	}
}
