<?php
/**
 * Starter playbooks: three built in, a filter that can add but not break them, and adding one
 * saves it switched Off.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class PlaybookStartersTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'aafm_playbook_starters' );
		delete_option( 'aafm_playbooks' );
		parent::tear_down();
	}

	public function test_three_starters_ship(): void {
		$this->assertSame( array( 'publish-checklist', 'weekly-content-report', 'shop-tidy-up' ), array_keys( aafm_playbook_starters() ) );
	}

	public function test_every_named_ability_exists(): void {
		$registry = aafm_get_abilities_registry_full();
		foreach ( aafm_playbook_starters() as $id => $starter ) {
			$names = aafm_playbook_named_abilities( $starter['body'] );
			$this->assertNotEmpty( $names, $id );
			foreach ( $names as $name ) {
				$this->assertArrayHasKey( $name, $registry, "{$id} names {$name}" );
			}
		}
	}

	public function test_filter_can_add_but_not_break(): void {
		add_filter(
			'aafm_playbook_starters',
			static function ( array $starters ): array {
				$starters['extra']  = array(
					'title'       => 'Extra',
					'description' => 'An added starter.',
					'body'        => 'Do the thing.',
				);
				$starters['broken'] = array( 'title' => '' );
				return $starters;
			}
		);
		$this->assertSame( array( 'publish-checklist', 'weekly-content-report', 'shop-tidy-up', 'extra' ), array_keys( aafm_playbook_starters() ) );
	}

	public function test_filter_cannot_remove_or_replace_built_ins(): void {
		$original = aafm_playbook_starters();
		add_filter(
			'aafm_playbook_starters',
			static function ( array $starters ): array {
				unset( $starters['publish-checklist'] );
				$starters['shop-tidy-up']['title'] = 'Replaced';
				return $starters;
			}
		);
		$this->assertSame( $original, aafm_playbook_starters() );
	}

	public function test_requires_marker_survives_filtering(): void {
		add_filter(
			'aafm_playbook_starters',
			static function ( array $starters ): array {
				$starters['shop-tidy-up']['requires'] = array();
				$starters['extra']                    = array(
					'title'    => 'Extra',
					'body'     => 'Steps.',
					'requires' => array( 'woocommerce', 'made-up' ),
				);
				return $starters;
			}
		);
		$starters = aafm_playbook_starters();
		$this->assertSame( array( 'woocommerce' ), $starters['shop-tidy-up']['requires'] );
		$this->assertSame( array( 'woocommerce' ), $starters['extra']['requires'] );
	}

	public function test_add_starter_saves_an_inactive_playbook_and_logs(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->register_enabled( array( 'aafm/list-playbooks', 'aafm/get-playbook' ) );
		$entry = aafm_add_starter_playbook( 'publish-checklist' );
		$this->assertSame( 'publish-checklist', $entry['slug'] );
		$this->assertFalse( $entry['active'] );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/playbooks' ) );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'Starter playbook added', (string) $rows[0]['detail'] );
		$this->assertSame( 0, aafm_exec_list_playbooks()['count'] );
		$this->assertSame( '', aafm_playbook_index() );

		$saved = aafm_playbook( 'publish-checklist' );
		aafm_save_playbook( array_merge( $saved, array( 'active' => true ) ), 'publish-checklist' );
		$this->assertSame( 1, aafm_exec_list_playbooks()['count'] );
		$this->assertStringContainsString( 'publish-checklist', aafm_playbook_index() );
		$this->assertWPError( aafm_add_starter_playbook( 'nope' ) );
	}

	public function test_starters_respect_the_limits(): void {
		foreach ( aafm_playbook_starters() as $starter ) {
			$fields = array(
				'title'       => $starter['title'],
				'description' => $starter['description'],
				'body'        => $starter['body'],
				'active'      => true,
			);
			$this->assertSame( $fields, aafm_clean_playbook_input( $fields ) );
		}
	}
}
