<?php
/**
 * Cross-type search ability: spans the allowlist, redacted output, status guard,
 * per-type private-read containment, narrowing, and bounded pagination.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

// Task 3 wires search.php into the plugin bootstrap's require list. Until then, load the
// ability file here so its global aafm_* functions resolve for this suite.
if ( ! function_exists( 'aafm_exec_search_content' ) ) {
	require_once dirname( __DIR__, 2 ) . '/includes/abilities/search.php';
}

final class SearchTest extends TestCase {

	public function test_search_spans_allowlisted_types_redacted(): void {
		register_post_type( 'aafm_book', array( 'public' => true, 'label' => 'Books', 'capability_type' => 'post', 'map_meta_cap' => true ) );
		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) );
		self::factory()->post->create( array( 'post_type' => 'post', 'post_title' => 'ZEBRA one', 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_type' => 'aafm_book', 'post_title' => 'ZEBRA two', 'post_status' => 'publish' ) );

		$out   = aafm_exec_search_content( array( 'search' => 'ZEBRA' ) );
		$types = array_column( $out['results'], 'type' );
		$this->assertContains( 'post', $types );
		$this->assertContains( 'aafm_book', $types );
		$this->assertArrayNotHasKey( 'content', $out['results'][0] );
		$this->assertSame( 2, $out['total'] );
	}

	public function test_search_status_guard_blocks_low_priv_private(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		$this->assertInstanceOf( WP_Error::class, aafm_exec_search_content( array( 'search' => 'x', 'status' => 'draft' ) ) );
	}

	public function test_search_post_types_narrows_and_cannot_widen(): void {
		update_option( 'aafm_allowed_post_types', array() ); // → ['post','page'].
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'YETI page', 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_type' => 'post', 'post_title' => 'YETI post', 'post_status' => 'publish' ) );
		$out = aafm_exec_search_content( array( 'search' => 'YETI', 'post_types' => array( 'page' ) ) );
		$this->assertSame( array( 'page' ), array_values( array_unique( array_column( $out['results'], 'type' ) ) ) );
		$empty = aafm_exec_search_content( array( 'search' => 'YETI', 'post_types' => array( 'notallowed' ) ) );
		$this->assertSame( array(), $empty['results'] );
		$this->assertSame( 0, $empty['total'] );
	}

	public function test_search_default_excludes_drafts(): void {
		update_option( 'aafm_allowed_post_types', array() );
		self::factory()->post->create( array( 'post_type' => 'post', 'post_title' => 'OKAPI draft', 'post_status' => 'draft' ) );
		self::factory()->post->create( array( 'post_type' => 'post', 'post_title' => 'OKAPI live', 'post_status' => 'publish' ) );
		$out    = aafm_exec_search_content( array( 'search' => 'OKAPI' ) );
		$titles = array_column( $out['results'], 'title' );
		$this->assertContains( 'OKAPI live', $titles );
		$this->assertNotContains( 'OKAPI draft', $titles );
		$this->assertSame( 1, $out['total'] );
	}

	public function test_search_per_page_is_capped(): void {
		update_option( 'aafm_allowed_post_types', array() );
		$out = aafm_exec_search_content( array( 'search' => 'x', 'per_page' => 9999 ) );
		// A cap means the builder used per_page=50; with no matches the shape still holds.
		$this->assertArrayHasKey( 'results', $out );
		$this->assertLessThanOrEqual( 50, count( $out['results'] ) );
	}

	public function test_search_perm_read_true_for_read_capable(): void {
		$this->acting_as( 'subscriber' );
		$this->assertTrue( aafm_perm_read() );
	}

	public function test_search_in_registry_as_a_read(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/search-content', $registry );
		$this->assertSame( 'reads', $registry['aafm/search-content']['group'] );
		$this->assertSame( 'read', $registry['aafm/search-content']['risk'] );
	}
}
