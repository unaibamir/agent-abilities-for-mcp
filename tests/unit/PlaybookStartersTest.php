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

	public function test_five_starters_ship(): void {
		$this->assertSame( array( 'publish-checklist', 'weekly-content-report', 'content-health-check', 'draft-a-post', 'shop-tidy-up' ), array_keys( aafm_playbook_starters() ) );
	}

	public function test_every_starter_ability_exists(): void {
		$registry = aafm_get_abilities_registry_full();
		foreach ( aafm_playbook_starters() as $id => $starter ) {
			$this->assertNotEmpty( $starter['abilities'], $id );
			$this->assertDoesNotMatchRegularExpression( '#aafm(-bridge)?/#', $starter['body'], $id );
			$this->assertDoesNotMatchRegularExpression( '#\\baafm-[a-z]#', $starter['body'], $id );
			foreach ( $starter['abilities'] as $name ) {
				$this->assertArrayHasKey( $name, $registry, "{$id} lists {$name}" );
				$this->assertStringContainsString( 'the ' . $registry[ $name ]['label'] . ' tool', $starter['body'], "{$id} names {$name} by its label" );
			}
		}
	}

	public function test_woocommerce_abilities_are_in_the_full_registry_with_woocommerce_off(): void {
		$this->assertFalse( class_exists( 'WooCommerce' ), 'The test site runs without WooCommerce.' );
		foreach ( aafm_playbook_starters()['shop-tidy-up']['abilities'] as $name ) {
			$this->assertArrayHasKey( $name, aafm_get_abilities_registry_full() );
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
		$this->assertSame( array( 'publish-checklist', 'weekly-content-report', 'content-health-check', 'draft-a-post', 'shop-tidy-up', 'extra' ), array_keys( aafm_playbook_starters() ) );
	}

	public function test_filter_cannot_remove_or_replace_built_ins(): void {
		$original = aafm_playbook_starters();
		add_filter(
			'aafm_playbook_starters',
			static function ( array $starters ): array {
				unset( $starters['publish-checklist'], $starters['draft-a-post'] );
				$starters['shop-tidy-up']['title']              = 'Replaced';
				$starters['content-health-check']['body']       = 'Replaced';
				$starters['weekly-content-report']['abilities'] = array();
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

	public function test_an_unknown_starter_is_refused_with_one_failed_log_row(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = aafm_add_starter_playbook( 'nope' );
		$this->assertSame( 'aafm_playbook_starter_unknown', $result->get_error_code() );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/playbooks' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertStringContainsString( 'Starter playbook added', (string) $rows[0]['detail'] );
		$this->assertSame( array(), aafm_playbooks() );
	}

	public function test_adding_the_same_starter_twice_is_refused_and_logged(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertIsArray( aafm_add_starter_playbook( 'publish-checklist' ) );
		$second = aafm_add_starter_playbook( 'publish-checklist' );
		$this->assertSame( 'aafm_playbook_starter_added', $second->get_error_code() );
		$this->assertCount( 1, aafm_playbooks() );
		$rows = aafm_query_activity(
			array(
				'ability' => 'aafm/playbooks',
				'status'  => 'error',
			)
		);
		$this->assertCount( 1, $rows );
	}

	public function test_starters_respect_the_limits(): void {
		foreach ( aafm_playbook_starters() as $starter ) {
			$fields = array(
				'title'       => $starter['title'],
				'description' => $starter['description'],
				'body'        => $starter['body'],
				'active'      => true,
			);
			$this->assertSame( $fields, array_diff_key( aafm_clean_playbook_input( $fields ), array( 'abilities' => true ) ) );
		}
	}

	public function test_add_starter_stores_its_abilities(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$entry = aafm_add_starter_playbook( 'draft-a-post' );
		$this->assertSame( array( 'aafm/get-site-info', 'aafm/search-content', 'aafm/get-terms', 'aafm/create-draft' ), aafm_playbook( $entry['slug'] )['abilities'] );
		$this->assertFalse( $entry['active'] );
		$this->assertSame( 'draft-a-post', aafm_playbook( $entry['slug'] )['starter'] );
		$this->assertSame( 'draft-a-new-post', $entry['slug'] );
	}

	public function test_starter_id_is_kept_on_edit(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$entry = aafm_add_starter_playbook( 'publish-checklist' );
		aafm_save_playbook(
			array_merge(
				$entry,
				array(
					'title'   => 'Renamed',
					'starter' => 'something-else',
				)
			),
			$entry['slug']
		);
		$this->assertSame( 'publish-checklist', aafm_playbook( $entry['slug'] )['starter'] );
	}

	public function test_shop_starter_adds_with_woocommerce_inactive(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$entry = aafm_add_starter_playbook( 'shop-tidy-up' );
		$this->assertNotWPError( $entry );
		$want = array( 'aafm/wc-count-products', 'aafm/wc-list-products', 'aafm/wc-get-product' );
		$this->assertSame( $want, aafm_playbook( $entry['slug'] )['abilities'] );
		aafm_save_playbook( array_merge( aafm_playbook( $entry['slug'] ), array( 'active' => true ) ), $entry['slug'] );
		$item = aafm_exec_list_playbooks()['playbooks'][0];
		$this->assertSame( $want, $item['uses_abilities'] );
		$this->assertSame( $want, $item['unavailable_abilities'] );
	}

	public function test_filter_added_starter_abilities_are_cleaned_alone(): void {
		add_filter(
			'aafm_playbook_starters',
			static function ( array $starters ): array {
				$starters['bad-list'] = array(
					'title'     => 'Bad list',
					'body'      => 'Steps.',
					'abilities' => array_slice( array_keys( aafm_get_abilities_registry_full() ), 0, 60 ),
				);
				$starters['mixed']    = array(
					'title'     => 'Mixed',
					'body'      => 'Steps.',
					'abilities' => array( 'aafm/get-post', 'aafm/gone-forever' ),
				);
				return $starters;
			}
		);
		$starters = aafm_playbook_starters();
		$this->assertSame( array(), $starters['bad-list']['abilities'] );
		$this->assertSame( 'Bad list', $starters['bad-list']['title'] );
		$this->assertSame( array( 'aafm/get-post' ), $starters['mixed']['abilities'] );
	}
}
