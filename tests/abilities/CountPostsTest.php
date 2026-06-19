<?php
/**
 * Count-posts: per-status counts behind the post-type allowlist.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class CountPostsTest extends TestCase {

	public function test_in_registry_as_a_read(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/count-posts', $registry );
		$this->assertSame( 'reads', $registry['aafm/count-posts']['group'] );
		$this->assertSame( 'read', $registry['aafm/count-posts']['risk'] );
		$this->assertSame( 'content', $registry['aafm/count-posts']['subject'] );
	}

	public function test_counts_posts_by_status(): void {
		$this->acting_as( 'editor' );
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
			)
		);

		$out = aafm_exec_count_posts( array( 'post_type' => 'post' ) );

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'total', $out );
		$this->assertArrayHasKey( 'by_status', $out );
		$by_status = (array) $out['by_status'];
		$this->assertSame( 2, $by_status['publish'] );
		$this->assertSame( 1, $by_status['draft'] );
		// total is the sum across every status bucket.
		$this->assertSame( array_sum( array_map( 'intval', $by_status ) ), $out['total'] );
	}

	public function test_defaults_to_post_type_post(): void {
		$this->acting_as( 'editor' );
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$out = aafm_exec_count_posts( array() );
		$this->assertGreaterThanOrEqual( 1, (int) ( (array) $out['by_status'] )['publish'] );
	}

	public function test_rejects_non_allowlisted_type(): void {
		$this->acting_as( 'editor' );
		// 'attachment' is public-but-internal — never eligible, never allowlisted.
		$out = aafm_exec_count_posts( array( 'post_type' => 'attachment' ) );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_permission_is_the_read_floor(): void {
		$this->acting_as( 'subscriber' );
		// Subscriber has 'read'; the read floor admits them (same as get-posts).
		$this->assertTrue( aafm_perm_read() );
	}
}
