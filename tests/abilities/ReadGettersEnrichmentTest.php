<?php
/**
 * Integration tests: the five read getters return the enriched rich-post shape.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ReadGettersEnrichmentTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_get_post_returns_enriched_shape_with_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => "Para A.\n\nPara B.",
			)
		);
		$out = aafm_exec_get_post( array( 'post_id' => $post_id ) );

		$this->assertArrayHasKey( 'post', $out );
		foreach ( array( 'content', 'excerpt', 'terms', 'author', 'featured_image', 'meta' ) as $key ) {
			$this->assertArrayHasKey( $key, $out['post'], "get-post missing {$key}" );
		}
		$this->assertStringContainsString( '<p>', $out['post']['content'] );
	}

	public function test_get_post_raw_content_format(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Raw body [sc] here',
			)
		);
		$out = aafm_exec_get_post(
			array(
				'post_id'        => $post_id,
				'content_format' => 'raw',
			)
		);

		$this->assertSame( 'Raw body [sc] here', $out['post']['content'] );
	}

	public function test_get_posts_default_omits_content_keeps_light_fields(): void {
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		$out = aafm_exec_get_posts( array() );

		$this->assertArrayHasKey( 'posts', $out );
		$this->assertArrayHasKey( 'total', $out );
		$this->assertNotEmpty( $out['posts'] );
		$first = $out['posts'][0];
		$this->assertArrayNotHasKey( 'content', $first );
		$this->assertArrayHasKey( 'excerpt', $first );
		$this->assertArrayHasKey( 'terms', $first );
		$this->assertArrayHasKey( 'author', $first );
	}

	public function test_get_posts_include_content_true_adds_content(): void {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Listed body.',
			)
		);
		$out = aafm_exec_get_posts( array( 'include_content' => true ) );

		$this->assertArrayHasKey( 'content', $out['posts'][0] );
	}
}
