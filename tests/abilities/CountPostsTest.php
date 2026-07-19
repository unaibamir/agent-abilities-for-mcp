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
		update_option( 'aafm_enabled_abilities', array( 'aafm/count-posts' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

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

	public function test_hides_non_public_status_counts_from_non_editors(): void {
		// Seed a mixed-status set: 2 publish + 1 draft + 1 pending.
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
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'pending',
			)
		);

		// An editor of the post type sees the full per-status breakdown, including non-public counts.
		$as_editor = aafm_exec_count_posts( array( 'post_type' => 'post' ) );
		$this->assertIsArray( $as_editor );
		$editor_status = (array) $as_editor['by_status'];
		$this->assertSame( 2, $editor_status['publish'] );
		$this->assertSame( 1, $editor_status['draft'], 'Editor must see the real draft count.' );
		$this->assertSame( 1, $editor_status['pending'], 'Editor must see the real pending count.' );
		// total excludes trash + auto-draft; here that is publish + draft + pending.
		$this->assertSame( 4, $as_editor['total'] );

		// A read-only subscriber sees public-status counts only; non-public counts are zeroed.
		$this->acting_as( 'subscriber' );
		$as_subscriber = aafm_exec_count_posts( array( 'post_type' => 'post' ) );
		$this->assertIsArray( $as_subscriber );
		$sub_status = (array) $as_subscriber['by_status'];

		// The public status is unaffected.
		$this->assertSame( 2, $sub_status['publish'], 'A subscriber still sees the public publish count.' );

		// Non-public status counts are zeroed even though draft/pending posts exist.
		$this->assertSame( 0, $sub_status['draft'], 'Draft count must be hidden from a non-editor.' );
		$this->assertSame( 0, $sub_status['pending'], 'Pending count must be hidden from a non-editor.' );
		$this->assertSame( 0, $sub_status['future'], 'Future count must be hidden from a non-editor.' );
		$this->assertSame( 0, $sub_status['trash'], 'Trash count must be hidden from a non-editor.' );

		// total now excludes the hidden non-public items: only the 2 published remain.
		$this->assertSame( 2, $as_subscriber['total'], 'Subscriber total must exclude the hidden non-public items.' );
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
		// 'attachment' is public-but-internal - never eligible, never allowlisted.
		$out = aafm_exec_count_posts( array( 'post_type' => 'attachment' ) );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_permission_is_the_read_floor(): void {
		$this->acting_as( 'subscriber' );
		// Subscriber has 'read'; the read floor admits them (same as get-posts).
		$this->assertTrue( aafm_perm_read() );
	}

	public function test_count_posts_reports_language_null_without_wpml(): void {
		$this->acting_as( 'subscriber' );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$out = wp_get_ability( 'aafm/count-posts' )->execute( array() );
		$this->assertArrayHasKey( 'language', $out );
		$this->assertNull( $out['language'] );
	}
}
