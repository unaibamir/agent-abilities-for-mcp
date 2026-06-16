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
}
