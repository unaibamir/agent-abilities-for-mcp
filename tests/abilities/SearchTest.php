<?php
/**
 * Cross-type search ability: spans the allowlist, redacted output, status guard,
 * per-type private-read containment, narrowing, and bounded pagination.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use WP_Error;

final class SearchTest extends TestCase {

	public function test_search_spans_allowlisted_types_redacted(): void {
		register_post_type(
			'oversio_book',
			array(
				'public'          => true,
				'label'           => 'Books',
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
		update_option( 'oversio_allowed_post_types', array( 'oversio_book' ) );
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'ZEBRA one',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'oversio_book',
				'post_title'  => 'ZEBRA two',
				'post_status' => 'publish',
			)
		);

		$out   = oversio_exec_search_content( array( 'search' => 'ZEBRA' ) );
		$types = array_column( $out['results'], 'type' );
		$this->assertContains( 'post', $types );
		$this->assertContains( 'oversio_book', $types );
		$this->assertArrayNotHasKey( 'content', $out['results'][0] );
		$this->assertSame( 2, $out['total'] );
	}

	public function test_search_status_guard_blocks_low_priv_private(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		$this->assertInstanceOf(
			WP_Error::class,
			oversio_exec_search_content(
				array(
					'search' => 'x',
					'status' => 'draft',
				)
			)
		);
	}

	public function test_search_private_status_contains_per_type_read_cap(): void {
		// A CPT with its own capability set: editors get read_private_posts but NOT
		// read_private_books, so a private search must drop the book hit it can't read.
		register_post_type(
			'oversio_book',
			array(
				'public'          => true,
				'label'           => 'Books',
				'capability_type' => array( 'book', 'books' ),
				'map_meta_cap'    => true,
			)
		);
		// Allowlist both so the containment filter has a type to KEEP (post) and one to DROP (book).
		update_option( 'oversio_allowed_post_types', array( 'post', 'oversio_book' ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertTrue( current_user_can( 'read_private_posts' ), 'Editor must hold the post-level FLOOR cap.' );
		$this->assertFalse( current_user_can( 'read_private_books' ), 'Editor must NOT hold the per-type book cap.' );

		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'QUOKKA secret post',
				'post_status' => 'private',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'oversio_book',
				'post_title'  => 'QUOKKA secret book',
				'post_status' => 'private',
			)
		);

		$out   = oversio_exec_search_content(
			array(
				'search' => 'QUOKKA',
				'status' => 'private',
			)
		);
		$types = array_column( $out['results'], 'type' );
		$this->assertContains( 'post', $types );
		$this->assertNotContains( 'oversio_book', $types );
		// total comes from found_posts AFTER the containment narrowing — no private-count leak.
		$this->assertSame( 1, $out['total'] );
	}

	public function test_search_post_types_narrows_and_cannot_widen(): void {
		update_option( 'oversio_allowed_post_types', array() ); // Allowlist resolves to post + page.
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'YETI page',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'YETI post',
				'post_status' => 'publish',
			)
		);
		$out = oversio_exec_search_content(
			array(
				'search'     => 'YETI',
				'post_types' => array( 'page' ),
			)
		);
		$this->assertSame( array( 'page' ), array_values( array_unique( array_column( $out['results'], 'type' ) ) ) );
		$empty = oversio_exec_search_content(
			array(
				'search'     => 'YETI',
				'post_types' => array( 'notallowed' ),
			)
		);
		$this->assertSame( array(), $empty['results'] );
		$this->assertSame( 0, $empty['total'] );
	}

	public function test_search_default_excludes_drafts(): void {
		update_option( 'oversio_allowed_post_types', array() );
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'OKAPI draft',
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'OKAPI live',
				'post_status' => 'publish',
			)
		);
		$out    = oversio_exec_search_content( array( 'search' => 'OKAPI' ) );
		$titles = array_column( $out['results'], 'title' );
		$this->assertContains( 'OKAPI live', $titles );
		$this->assertNotContains( 'OKAPI draft', $titles );
		$this->assertSame( 1, $out['total'] );
	}

	public function test_search_per_page_is_capped(): void {
		update_option( 'oversio_allowed_post_types', array() );
		$out = oversio_exec_search_content(
			array(
				'search'   => 'x',
				'per_page' => 9999,
			)
		);
		// A cap means the builder used per_page=50; with no matches the shape still holds.
		$this->assertArrayHasKey( 'results', $out );
		$this->assertLessThanOrEqual( 50, count( $out['results'] ) );
	}

	public function test_search_perm_read_true_for_read_capable(): void {
		$this->acting_as( 'subscriber' );
		$this->assertTrue( oversio_perm_read() );
	}

	public function test_search_in_registry_as_a_read(): void {
		$registry = oversio_get_abilities_registry();
		$this->assertArrayHasKey( 'oversio/search-content', $registry );
		$this->assertSame( 'reads', $registry['oversio/search-content']['group'] );
		$this->assertSame( 'read', $registry['oversio/search-content']['risk'] );
	}

	public function test_search_ability_registered_as_read(): void {
		$reg = oversio_get_abilities_registry();
		$this->assertArrayHasKey( 'oversio/search-content', $reg );
		$this->assertSame( 'reads', $reg['oversio/search-content']['group'] );
	}
}
