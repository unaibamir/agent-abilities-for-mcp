<?php
/**
 * Registry plumbing + enabled-abilities option.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class RegistryTest extends TestCase {

	public function test_registry_is_filterable(): void {
		$cb = static function ( array $r ): array {
			$r['aafm/demo'] = array(
				'label' => 'Demo',
				'group' => 'reads',
			);
			return $r;
		};
		add_filter( 'aafm_abilities_registry', $cb );

		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/demo', $registry );

		remove_filter( 'aafm_abilities_registry', $cb );
	}

	public function test_nothing_enabled_by_default(): void {
		$this->assertSame( array(), aafm_get_enabled_abilities() );
		$this->assertFalse( aafm_is_ability_enabled( 'aafm/get-posts' ) );
	}

	public function test_enabling_persists_and_reads_back(): void {
		$cb = static function ( array $r ): array {
			$r['aafm/get-posts'] = array(
				'label' => 'Get Posts',
				'group' => 'reads',
			);
			return $r;
		};
		add_filter( 'aafm_abilities_registry', $cb );

		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		$this->assertTrue( aafm_is_ability_enabled( 'aafm/get-posts' ) );
		$this->assertFalse( aafm_is_ability_enabled( 'aafm/trash-post' ) );

		remove_filter( 'aafm_abilities_registry', $cb );
	}

	public function test_registry_is_memoized_within_a_request(): void {
		$runs = 0;
		$cb   = static function ( array $r ) use ( &$runs ): array {
			++$runs;
			return $r;
		};
		add_filter( 'aafm_abilities_registry', $cb );

		aafm_get_abilities_registry();
		aafm_get_abilities_registry();
		aafm_get_abilities_registry();

		remove_filter( 'aafm_abilities_registry', $cb );

		// The filter set is fixed within a request, so the catalog is built once and
		// the heavy __()/array churn doesn't repeat on every call.
		$this->assertSame( 1, $runs, 'aafm_abilities_registry filter re-ran instead of being memoized.' );
	}

	public function test_registry_cache_flush_rebuilds(): void {
		// Prime the memo with the current (no-demo) catalog.
		$this->assertArrayNotHasKey( 'aafm/flush-demo', aafm_get_abilities_registry() );

		$cb = static function ( array $r ): array {
			$r['aafm/flush-demo'] = array(
				'label' => 'Flush Demo',
				'group' => 'reads',
			);
			return $r;
		};
		add_filter( 'aafm_abilities_registry', $cb );

		// Without a flush the memo still holds the pre-filter catalog.
		$this->assertArrayNotHasKey( 'aafm/flush-demo', aafm_get_abilities_registry() );

		// Flushing rebuilds, so the new filter contribution appears.
		aafm_flush_registry_cache();
		$this->assertArrayHasKey( 'aafm/flush-demo', aafm_get_abilities_registry() );

		remove_filter( 'aafm_abilities_registry', $cb );
		aafm_flush_registry_cache();
		$this->assertArrayNotHasKey( 'aafm/flush-demo', aafm_get_abilities_registry() );
	}

	public function test_enabled_list_is_intersected_with_known_registry(): void {
		$cb = static function ( array $r ): array {
			$r['aafm/get-posts'] = array(
				'label' => 'Get Posts',
				'group' => 'reads',
			);
			return $r;
		};
		add_filter( 'aafm_abilities_registry', $cb );

		// A stale/unknown key in the option must never be treated as enabled.
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/ghost' ) );
		$this->assertSame( array( 'aafm/get-posts' ), aafm_get_enabled_abilities() );

		remove_filter( 'aafm_abilities_registry', $cb );
	}
}
