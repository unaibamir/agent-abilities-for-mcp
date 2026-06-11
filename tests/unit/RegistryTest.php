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
