<?php
/**
 * Single-term read ability: returns the aafm_redact_term shape, default-denies a
 * nonexistent or cross-taxonomy id, and reads as a public read (read floor).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class GetTermTest extends TestCase {

	public function test_get_term_returns_redacted_shape(): void {
		$this->acting_as( 'subscriber' );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Travel',
				'slug'     => 'travel',
			)
		);

		$result = aafm_exec_get_term(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
			)
		);

		$this->assertSame( (int) $term_id, $result['term']['id'] );
		$this->assertSame( 'Travel', $result['term']['name'] );
		$this->assertSame( 'travel', $result['term']['slug'] );
		$this->assertSame( 'category', $result['term']['taxonomy'] );
		// Redactor never exposes anything beyond its sanctioned keys.
		$this->assertSame(
			array( 'id', 'name', 'slug', 'taxonomy', 'parent', 'count', 'description' ),
			array_keys( $result['term'] )
		);
	}

	public function test_get_term_rejects_nonexistent_id(): void {
		$this->acting_as( 'subscriber' );
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_get_term( array( 'taxonomy' => 'category', 'term_id' => 999999 ) )
		);
	}

	public function test_get_term_rejects_cross_taxonomy_id(): void {
		$this->acting_as( 'subscriber' );
		$tag_id = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		// A tag id claimed as a category must be rejected (term/taxonomy confusion guard).
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_get_term( array( 'taxonomy' => 'category', 'term_id' => $tag_id ) )
		);
	}

	public function test_get_term_rejects_nonpublic_taxonomy(): void {
		$this->acting_as( 'subscriber' );
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_get_term( array( 'taxonomy' => 'nav_menu', 'term_id' => 1 ) )
		);
	}
}
