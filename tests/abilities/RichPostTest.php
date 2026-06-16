<?php
/**
 * Unit tests for aafm_rich_post() — the enriched post-assembly helper.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Post;
use WP_User;

final class RichPostTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
	}

	public function test_rich_post_includes_all_base_redactor_keys(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Base Keys Survive',
				'post_content' => 'Hello body.',
			)
		);
		$shape   = aafm_rich_post( get_post( $post_id ) );

		foreach ( array( 'id', 'title', 'status', 'type', 'slug', 'link', 'author_id', 'date_gmt', 'modified_gmt' ) as $key ) {
			$this->assertArrayHasKey( $key, $shape, "Missing base key {$key}" );
		}
		$this->assertSame( $post_id, $shape['id'] );
		$this->assertSame( 'Base Keys Survive', $shape['title'] );
	}

	public function test_rich_post_content_rendered_by_default(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => "First para.\n\nSecond para.",
			)
		);
		$shape = aafm_rich_post( get_post( $post_id ) );

		$this->assertArrayHasKey( 'content', $shape );
		// the_content wraps paragraphs in <p> tags via wpautop.
		$this->assertStringContainsString( '<p>', $shape['content'] );
	}

	public function test_rich_post_content_raw_returns_stored_markup(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Raw [shortcode] body no wpautop',
			)
		);
		$shape = aafm_rich_post( get_post( $post_id ), array( 'content_format' => 'raw' ) );

		$this->assertSame( 'Raw [shortcode] body no wpautop', $shape['content'] );
	}

	public function test_rich_post_unknown_content_format_falls_back_to_rendered(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => "Para one.\n\nPara two.",
			)
		);
		$shape = aafm_rich_post( get_post( $post_id ), array( 'content_format' => 'bogus' ) );

		$this->assertStringContainsString( '<p>', $shape['content'] );
	}
}
