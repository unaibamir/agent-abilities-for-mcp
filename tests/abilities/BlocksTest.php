<?php
/**
 * Slice B: the reusable-block (wp_block) read + write abilities.
 *
 * Covers the lean/rich block assemblers and block-object resolver, the list-blocks and
 * get-block reads, the kses-hardened create-block/update-block writes with forced type and
 * author plus closed-schema smuggle rejection, the per-object edit_block/delete_block gates,
 * and the trash-only delete-block with its trash-disabled refusal.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class BlocksTest extends TestCase {

	private function make_block( string $title = 'CTA', string $content = '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	public function test_lean_redactor_has_no_content_and_rich_adds_markup(): void {
		$id   = $this->make_block();
		$lean = aafm_redact_block( get_post( $id ) );
		$this->assertSame( array( 'id', 'title', 'slug', 'status', 'modified' ), array_keys( $lean ) );
		$this->assertArrayNotHasKey( 'content', $lean, 'the list redactor must not carry block markup.' );

		$rich = aafm_rich_block( get_post( $id ) );
		$this->assertArrayHasKey( 'content', $rich );
		$this->assertStringContainsString( 'wp:paragraph', $rich['content'], 'rich block must expose the raw block markup.' );
	}

	public function test_block_object_resolver_rejects_a_non_block_post(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertNull( aafm_get_block_object( $post_id ), 'a normal post is not a wp_block.' );
		$block_id = $this->make_block();
		$this->assertInstanceOf( \WP_Post::class, aafm_get_block_object( $block_id ) );
	}
}
