<?php
/**
 * Wire-shape tests: what abilities actually serialize versus what their schemas declare.
 *
 * These assert on wp_json_encode() output rather than the PHP value, because
 * array() and (object) array() are indistinguishable in PHP but encode to
 * "[]" and "{}" respectively, and only the encoded form reaches the client.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class OutputSchemaFidelityTest extends TestCase {

	public function test_a_page_with_no_terms_encodes_terms_as_an_empty_object(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'A page has no taxonomies',
			)
		);

		$shape = aafm_rich_post( get_post( $page_id ) );

		$this->assertSame(
			'{}',
			wp_json_encode( $shape['terms'] ),
			'terms is declared type:object at helpers.php:1123, so an empty map must encode as {} not [].'
		);
	}

	public function test_a_post_with_no_allowlisted_meta_encodes_meta_as_an_empty_object(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$shape = aafm_rich_post( get_post( $post_id ) );

		$this->assertSame(
			'{}',
			wp_json_encode( $shape['meta'] ),
			'meta is declared type:object at helpers.php:1145, so an empty map must encode as {} not [].'
		);
	}

	public function test_a_post_with_terms_still_encodes_terms_as_a_populated_object(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$cat_id  = self::factory()->category->create( array( 'name' => 'Reporting' ) );
		wp_set_object_terms( $post_id, array( (int) $cat_id ), 'category' );

		$shape = aafm_rich_post( get_post( $post_id ) );
		$json  = (string) wp_json_encode( $shape['terms'] );

		$this->assertStringStartsWith( '{', $json, 'A populated terms map must still be an object.' );
		$this->assertStringContainsString( '"category"', $json );
		$this->assertSame( 'Reporting', $shape['terms']['category'][0]['name'], 'Array access must still work.' );
	}
}
