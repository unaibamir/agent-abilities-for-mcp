<?php
/**
 * Capability checks on a post object read that post's metadata failure-aware.
 *
 * Core's map_meta_cap() decides some post capabilities from metadata: a trashed post's own
 * `_wp_trash_meta_status`, and, through get_post_status(), the trash status of an attachment's
 * parent. When that load fails, core reads an empty value and can grant more than the stored
 * status allows. The plugin's capability calls on a post or comment object run through
 * aafm_user_can_checked(), which refuses when the load failed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Post;

final class CapabilityMetaReadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
	}

	/**
	 * Run $run with $post_id's postmeta load answered with no rows, errors suppressed and output
	 * discarded.
	 *
	 * @param int      $post_id    Post whose meta load is faulted.
	 * @param callable $run        The call.
	 * @param bool     $drop_cache Whether to drop the post's meta cache entry first.
	 * @return mixed
	 */
	private function with_meta_load_faulted( int $post_id, callable $run, bool $drop_cache = true ) {
		global $wpdb;
		if ( $drop_cache ) {
			wp_cache_delete( $post_id, 'post_meta' );
		}
		$filter     = QueryFaultInjector::leak_row_filter(
			array( 'SELECT post_id, meta_key, meta_value FROM', $wpdb->postmeta, "WHERE post_id IN ({$post_id})" ),
			sprintf( 'SELECT * FROM %s WHERE 1 = 0', $wpdb->postmeta ),
			0
		);
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		ob_start();
		try {
			return $run();
		} finally {
			ob_end_clean();
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * A contributor's published post, now in the trash, loaded in the cache as the current user.
	 */
	private function contributors_trashed_published_post(): WP_Post {
		$author  = $this->acting_as( 'contributor' );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);
		wp_trash_post( $post_id );
		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'publish', get_post_meta( $post_id, '_wp_trash_meta_status', true ), 'precondition: it was published' );
		return $post;
	}

	public function test_can_edit_post_object_refuses_when_the_trash_status_meta_does_not_load(): void {
		$post = $this->contributors_trashed_published_post();
		$this->assertFalse( aafm_can_edit_post_object( $post ), 'healthy: no edit_published_posts' );

		$faulted = $this->with_meta_load_faulted(
			$post->ID,
			static function () use ( $post ): bool {
				return aafm_can_edit_post_object( $post );
			}
		);

		$this->assertFalse( $faulted );
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count() );
	}

	public function test_can_delete_post_object_refuses_when_the_trash_status_meta_does_not_load(): void {
		$post = $this->contributors_trashed_published_post();
		$this->assertFalse( aafm_can_delete_post_object( $post ), 'healthy: no delete_published_posts' );

		$faulted = $this->with_meta_load_faulted(
			$post->ID,
			static function () use ( $post ): bool {
				return aafm_can_delete_post_object( $post );
			}
		);

		$this->assertFalse( $faulted );
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count() );
	}

	public function test_can_read_post_object_refuses_when_the_trash_status_meta_does_not_load(): void {
		$post = $this->contributors_trashed_published_post();
		$this->assertFalse( aafm_can_read_post_object( $post ), 'healthy: trash is not public, and editing needs edit_published_posts' );

		$faulted = $this->with_meta_load_faulted(
			$post->ID,
			static function () use ( $post ): bool {
				return aafm_can_read_post_object( $post );
			}
		);

		$this->assertFalse( $faulted );
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count() );
	}

	public function test_edit_comment_refuses_when_its_posts_trash_status_does_not_load(): void {
		$post    = $this->contributors_trashed_published_post();
		$comment = (int) self::factory()->comment->create( array( 'comment_post_ID' => $post->ID ) );
		get_comment( $comment );
		$this->assertFalse( aafm_user_can_checked( 'edit_comment', $comment ), 'healthy' );

		$faulted = $this->with_meta_load_faulted(
			$post->ID,
			static function () use ( $comment ): bool {
				return aafm_user_can_checked( 'edit_comment', $comment );
			}
		);

		$this->assertFalse( $faulted );
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count() );
	}

	public function test_an_attachment_read_refuses_when_its_trashed_parents_status_does_not_load(): void {
		$parent     = (int) self::factory()->post->create( array( 'post_status' => 'private' ) );
		$attachment = (int) self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => $parent,
			)
		);
		wp_trash_post( $parent );
		$this->acting_as( 'subscriber' );
		get_post( $attachment );
		get_post( $parent );
		$this->assertFalse( aafm_user_can_checked( 'read_post', $attachment ), 'healthy: the parent was private' );

		$faulted = $this->with_meta_load_faulted(
			$parent,
			static function () use ( $attachment ): bool {
				return aafm_user_can_checked( 'read_post', $attachment );
			}
		);

		$this->assertFalse( $faulted );
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count() );
	}

	/**
	 * Healthy rows: when no load runs, the scope changes nothing.
	 */
	public function test_a_cached_trash_status_runs_no_query_and_answers_as_core(): void {
		$post = $this->contributors_trashed_published_post();
		get_post_meta( $post->ID, '_wp_trash_meta_status', true );

		foreach ( array( 'edit_post', 'delete_post', 'read_post' ) as $cap ) {
			$this->assertSame( current_user_can( $cap, $post->ID ), aafm_user_can_checked( $cap, $post->ID ), $cap );
		}
		$faulted = $this->with_meta_load_faulted(
			$post->ID,
			static function () use ( $post ): bool {
				return aafm_user_can_checked( 'edit_post', $post->ID );
			},
			false
		);
		$this->assertFalse( $faulted );
		$this->assertSame( 0, QueryFaultInjector::fired_count() );
	}

	public function test_a_metadata_short_circuit_runs_no_query_and_answers_as_core(): void {
		$post = $this->contributors_trashed_published_post();
		add_filter(
			'get_post_metadata',
			static function ( $value, $object_id, $meta_key ) use ( $post ) {
				return (int) $object_id === $post->ID && '_wp_trash_meta_status' === $meta_key ? array( 'draft' ) : $value;
			},
			10,
			3
		);
		$unscoped = current_user_can( 'edit_post', $post->ID );
		$this->assertTrue( $unscoped, 'precondition: the filtered status is a draft, which the author edits' );

		$scoped = $this->with_meta_load_faulted(
			$post->ID,
			static function () use ( $post ): bool {
				return aafm_user_can_checked( 'edit_post', $post->ID );
			}
		);

		$this->assertSame( $unscoped, $scoped );
		$this->assertSame( 0, QueryFaultInjector::fired_count() );
	}

	public function test_an_unregistered_post_type_answers_as_core_without_a_metadata_read(): void {
		register_post_type( 'aafm_cap_type', array( 'map_meta_cap' => true ) );
		$post_id = (int) self::factory()->post->create( array( 'post_type' => 'aafm_cap_type' ) );
		unregister_post_type( 'aafm_cap_type' );
		$this->acting_as( 'editor' );
		get_post( $post_id );
		$this->setExpectedIncorrectUsage( 'map_meta_cap' );
		$unscoped = current_user_can( 'edit_post', $post_id );

		$scoped = $this->with_meta_load_faulted(
			$post_id,
			static function () use ( $post_id ): bool {
				return aafm_user_can_checked( 'edit_post', $post_id );
			}
		);

		$this->assertSame( $unscoped, $scoped );
		$this->assertSame( 0, QueryFaultInjector::fired_count() );
	}

	// --- User and term objects ------------------------------------------------

	private const MISSING = 987654;

	public function test_user_absence_is_certain_only_from_a_query_that_ran(): void {
		global $wpdb;
		$user = (int) self::factory()->user->create();

		$this->assertFalse( aafm_object_absent( 'user', $user ) );
		$this->assertTrue( aafm_object_absent( 'user', self::MISSING ) );
		$failed = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT ID FROM', $wpdb->users ),
			static function (): bool {
				return aafm_object_absent( 'user', self::MISSING );
			}
		);
		$this->assertFalse( $failed );
		$this->assertSame( 1, QueryFaultInjector::fired_count() );
	}
}
