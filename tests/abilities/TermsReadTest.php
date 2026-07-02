<?php
/**
 * Term read ability: taxonomy allowlist, redaction, bounded listing.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class TermsReadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions, simulated
		// by pushing the action name onto $wp_current_filter - the idiom WP core's own
		// ability test trait uses. wp_register_ability() refuses to run otherwise.
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-terms' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_get_terms_is_in_registry_as_a_read(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/get-terms', $registry );
		$this->assertSame( 'reads', $registry['aafm/get-terms']['group'] );
		$this->assertSame( 'read', $registry['aafm/get-terms']['risk'] );
	}

	public function test_get_terms_returns_terms_for_public_taxonomy(): void {
		$this->acting_as( 'subscriber' );
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
			)
		);

		$out = wp_get_ability( 'aafm/get-terms' )->execute( array( 'taxonomy' => 'category' ) );

		$this->assertArrayHasKey( 'terms', $out );
		$this->assertContains( 'News', wp_list_pluck( $out['terms'], 'name' ) );
	}

	public function test_get_terms_rejects_non_public_taxonomy(): void {
		$this->acting_as( 'subscriber' );
		$out = wp_get_ability( 'aafm/get-terms' )->execute( array( 'taxonomy' => 'nav_menu' ) );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_get_terms_rejects_unknown_taxonomy(): void {
		$this->acting_as( 'subscriber' );
		$out = wp_get_ability( 'aafm/get-terms' )->execute( array( 'taxonomy' => 'totally_fake' ) );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_get_terms_output_is_redacted_to_safe_fields(): void {
		$this->acting_as( 'subscriber' );
		self::factory()->term->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'Solo',
				'description' => 'A described term.',
			)
		);

		$out = wp_get_ability( 'aafm/get-terms' )->execute( array( 'taxonomy' => 'category' ) );

		$match = null;
		foreach ( $out['terms'] as $term ) {
			if ( 'Solo' === $term['name'] ) {
				$match = $term;
				break;
			}
		}
		$this->assertNotNull( $match );
		$this->assertSame(
			array( 'id', 'name', 'slug', 'taxonomy', 'parent', 'count', 'description' ),
			array_keys( $match )
		);
		$this->assertSame( 'category', $match['taxonomy'] );
		$this->assertArrayNotHasKey( 'term_taxonomy_id', $match );
		$this->assertArrayNotHasKey( 'term_group', $match );
	}

	public function test_get_terms_search_narrows_results(): void {
		$this->acting_as( 'subscriber' );
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Findable',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Unrelated',
			)
		);

		$out    = wp_get_ability( 'aafm/get-terms' )->execute(
			array(
				'taxonomy' => 'category',
				'search'   => 'Findable',
			)
		);
		$titles = wp_list_pluck( $out['terms'], 'name' );

		$this->assertContains( 'Findable', $titles );
		$this->assertNotContains( 'Unrelated', $titles );
	}
}
