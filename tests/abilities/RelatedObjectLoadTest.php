<?php
/**
 * Related objects are loaded by id before core follows them.
 *
 * Some core functions load a different object from the one they are given and decide from it: a
 * post's parents, a comment's post, a term's parents, a menu item's target. When a `query` filter
 * empties one of those SELECTs, core reads the previous query's row as the related object. The
 * abilities load each related object with aafm_exact_object_chain() first, so core reads it from
 * the cache, and a load that does not come back exact refuses the call.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Comment;
use WP_Post;
use WP_Term;

final class RelatedObjectLoadTest extends TestCase {

	/**
	 * An id no row in the test database uses.
	 */
	private const MISSING = 987654;

	/**
	 * For each faulted query, the functions on the stack when it fired.
	 *
	 * @var array<int,string[]>
	 */
	private array $stages = array();

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
		$this->stages = array();
	}

	/**
	 * Core's own SELECT for one object.
	 *
	 * @param string $type 'post', 'term' or 'comment'.
	 * @param int    $id   Object id.
	 * @return string
	 */
	private function load_sql( string $type, int $id ): string {
		global $wpdb;
		switch ( $type ) {
			case 'term':
				return sprintf( 'SELECT t.*, tt.* FROM %1$s AS t INNER JOIN %2$s AS tt ON t.term_id = tt.term_id WHERE t.term_id = %3$d', $wpdb->terms, $wpdb->term_taxonomy, $id );
			case 'comment':
				return sprintf( 'SELECT * FROM %1$s WHERE comment_ID = %2$d LIMIT 1', $wpdb->comments, $id );
		}
		return sprintf( 'SELECT * FROM %1$s WHERE ID = %2$d LIMIT 1', $wpdb->posts, $id );
	}

	/**
	 * A `query` filter that answers the $occurrence-th query matching $needle with the rows of
	 * $leak_sql (none when $leak_sql selects nothing) and records the call stack it fired in.
	 *
	 * @param string|string[] $needle     Exact query, or substrings that must all be present.
	 * @param string          $leak_sql   The query whose rows are left behind.
	 * @param int             $occurrence Which match to answer; 0 for every one.
	 * @return callable
	 */
	private function recording_leak( $needle, string $leak_sql, int $occurrence = 1 ): callable {
		$inner = QueryFaultInjector::leak_row_filter( $needle, $leak_sql, $occurrence, ! is_array( $needle ) );
		return function ( string $query ) use ( $inner ): string {
			$before = QueryFaultInjector::fired_count();
			$out    = $inner( $query );
			if ( QueryFaultInjector::fired_count() > $before ) {
				$this->stages[] = array_map(
					static function ( array $frame ): string {
						return (string) ( $frame['function'] ?? '' );
					},
					( new \Exception() )->getTrace()
				);
			}
			return $out;
		};
	}

	/**
	 * Fault the next load of object $a: its cache entry is dropped and its SELECT reads object
	 * $b's row, or no row when $b is null.
	 *
	 * @param string   $type       Object type.
	 * @param int      $a          The object asked for.
	 * @param int|null $b          The object whose row is left behind, or null for none.
	 * @param int      $occurrence Which matching load to answer.
	 * @return callable
	 */
	private function fault_load( string $type, int $a, ?int $b = null, int $occurrence = 1 ): callable {
		global $wpdb;
		wp_cache_delete(
			$a,
			array(
				'post'    => 'posts',
				'term'    => 'terms',
				'comment' => 'comment',
			)[ $type ]
		);
		$leak = null === $b ? sprintf( 'SELECT * FROM %s WHERE 1 = 0', $wpdb->posts ) : $this->load_sql( $type, $b );
		return $this->recording_leak( $this->load_sql( $type, $a ), $leak, $occurrence );
	}

	/**
	 * Run $run with $filter on `query`, database errors suppressed and output discarded.
	 *
	 * @param callable|null $filter The `query` filter, or null for none.
	 * @param callable      $run    The call.
	 * @return mixed
	 */
	private function armed( ?callable $filter, callable $run ) {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		if ( null !== $filter ) {
			add_filter( 'query', $filter );
		}
		ob_start();
		try {
			return $run();
		} finally {
			ob_end_clean();
			if ( null !== $filter ) {
				remove_filter( 'query', $filter );
			}
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * The fault fired once, inside $name.
	 *
	 * @param string $name  Function name expected on the stack.
	 * @param string $label Case label.
	 */
	private function assert_fired_in( string $name, string $label ): void {
		$this->assertSame( 1, QueryFaultInjector::fired_count(), "$label: the load was faulted" );
		$this->assertContains( $name, $this->stages[0] ?? array(), "$label: the faulted SELECT fired inside $name" );
	}

	/**
	 * A post.
	 *
	 * @param array<string,mixed> $args Post fields.
	 */
	private function post( array $args = array() ): int {
		return (int) self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
	}

	/**
	 * Store $parent_id as $id's post_parent directly, the way data that dangles or loops gets there.
	 *
	 * @param int $id        Post id.
	 * @param int $parent_id Parent id.
	 */
	private function set_parent( int $id, int $parent_id ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_parent' => $parent_id ), array( 'ID' => $id ) );
		clean_post_cache( $id );
	}

	/**
	 * A revision whose parent row is gone.
	 */
	private function orphan_revision(): int {
		$id = $this->post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
			)
		);
		$this->set_parent( $id, self::MISSING );
		return $id;
	}

	/**
	 * A category under $parent.
	 *
	 * @param int $parent_id Parent term id.
	 */
	private function category( int $parent_id = 0 ): int {
		return (int) self::factory()->category->create( array( 'parent' => $parent_id ) );
	}

	/**
	 * A comment on $post_id.
	 *
	 * @param int $post_id Post id.
	 */
	private function comment( int $post_id ): int {
		return (int) self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
			)
		);
	}

	/**
	 * Store $post_id as a comment's post directly.
	 *
	 * @param int $comment_id Comment id.
	 * @param int $post_id    Post id.
	 */
	private function set_comment_post( int $comment_id, int $post_id ): void {
		global $wpdb;
		$wpdb->update( $wpdb->comments, array( 'comment_post_ID' => $post_id ), array( 'comment_ID' => $comment_id ) );
		clean_comment_cache( $comment_id );
	}

	/**
	 * T9: the helpers exist.
	 */
	public function test_the_helpers_exist(): void {
		$this->assertTrue( function_exists( 'aafm_exact_object_chain' ), 'aafm_exact_object_chain()' );
		$this->assertTrue( function_exists( 'aafm_object_absent' ), 'aafm_object_absent()' );
	}

	public function test_the_chain_returns_the_post_itself_when_its_parents_load(): void {
		$a = $this->post( array( 'post_type' => 'page' ) );
		$b = $this->post(
			array(
				'post_type'   => 'page',
				'post_parent' => $a,
			)
		);
		$c = $this->post(
			array(
				'post_type'   => 'page',
				'post_parent' => $b,
			)
		);

		$got = aafm_exact_object_chain( 'post', $c );

		$this->assertInstanceOf( WP_Post::class, $got );
		$this->assertSame( $c, (int) $got->ID );
	}

	public function test_the_chain_returns_the_root_when_a_parent_row_is_certified_absent(): void {
		$c = $this->post();
		$this->set_parent( $c, self::MISSING );

		$got = aafm_exact_object_chain( 'post', $c );

		$this->assertInstanceOf( WP_Post::class, $got );
		$this->assertSame( $c, (int) $got->ID );
	}

	public function test_the_chain_returns_null_when_a_parent_that_exists_reads_another_row(): void {
		$a       = $this->post( array( 'post_type' => 'page' ) );
		$b       = $this->post(
			array(
				'post_type'   => 'page',
				'post_parent' => $a,
			)
		);
		$c       = $this->post(
			array(
				'post_type'   => 'page',
				'post_parent' => $b,
			)
		);
		$foreign = $this->post();
		get_post( $c );

		$got = $this->armed(
			$this->fault_load( 'post', $a, $foreign ),
			static function () use ( $c ) {
				return aafm_exact_object_chain( 'post', $c );
			}
		);

		$this->assertNull( $got );
		$this->assert_fired_in( 'aafm_exact_object_chain', 'grandparent leak' );
	}

	public function test_the_chain_returns_null_when_a_parent_that_exists_reads_no_row(): void {
		$a = $this->post();
		$c = $this->post( array( 'post_parent' => $a ) );
		get_post( $c );

		$got = $this->armed(
			$this->fault_load( 'post', $a ),
			static function () use ( $c ) {
				return aafm_exact_object_chain( 'post', $c );
			}
		);

		$this->assertNull( $got );
		$this->assert_fired_in( 'aafm_exact_object_chain', 'empty parent read' );
	}

	public function test_the_chain_returns_null_for_a_revision_whose_parent_is_gone(): void {
		$revision = $this->orphan_revision();

		$this->assertNull( aafm_exact_object_chain( 'post', $revision ) );
	}

	public function test_a_revision_higher_up_the_walk_ends_it_like_any_absent_parent(): void {
		$revision = $this->orphan_revision();
		$c        = $this->post();
		$this->set_parent( $c, $revision );

		$got = aafm_exact_object_chain( 'post', $c );

		$this->assertInstanceOf( WP_Post::class, $got );
		$this->assertSame( $c, (int) $got->ID );
	}

	public function test_the_chain_returns_the_root_on_a_stored_cycle(): void {
		$a = $this->post( array( 'post_type' => 'page' ) );
		$b = $this->post( array( 'post_type' => 'page' ) );
		$this->set_parent( $a, $b );
		$this->set_parent( $b, $a );
		$self = $this->post( array( 'post_type' => 'page' ) );
		$this->set_parent( $self, $self );

		$got = aafm_exact_object_chain( 'post', $a );
		$own = aafm_exact_object_chain( 'post', $self );

		$this->assertInstanceOf( WP_Post::class, $got );
		$this->assertSame( $a, (int) $got->ID );
		$this->assertInstanceOf( WP_Post::class, $own );
		$this->assertSame( $self, (int) $own->ID );
	}

	public function test_the_chain_returns_null_when_the_object_itself_does_not_load(): void {
		$this->assertNull( aafm_exact_object_chain( 'post', self::MISSING ) );
		$this->assertNull( aafm_exact_object_chain( 'comment', self::MISSING ) );
		$this->assertNull( aafm_exact_object_chain( 'term', self::MISSING, 'category' ) );
	}

	public function test_the_chain_refuses_a_type_it_does_not_walk(): void {
		$this->assertNull( aafm_exact_object_chain( 'user', 1 ) );
	}

	public function test_a_comment_chain_walks_to_its_post_and_the_posts_parents(): void {
		$a       = $this->post( array( 'post_type' => 'page' ) );
		$b       = $this->post(
			array(
				'post_type'   => 'page',
				'post_parent' => $a,
			)
		);
		$comment = $this->comment( $b );
		get_comment( $comment );
		get_post( $b );

		$healthy = aafm_exact_object_chain( 'comment', $comment );
		$faulted = $this->armed(
			$this->fault_load( 'post', $a, $b ),
			static function () use ( $comment ) {
				return aafm_exact_object_chain( 'comment', $comment );
			}
		);

		$this->assertInstanceOf( WP_Comment::class, $healthy );
		$this->assertSame( $comment, (int) $healthy->comment_ID );
		$this->assertNull( $faulted );
		$this->assert_fired_in( 'aafm_exact_object_chain', "the comment's post's parent" );
	}

	public function test_a_comment_chain_returns_null_when_its_post_reads_another_row(): void {
		$p       = $this->post();
		$other   = $this->post();
		$comment = $this->comment( $p );
		get_comment( $comment );

		$got = $this->armed(
			$this->fault_load( 'post', $p, $other ),
			static function () use ( $comment ) {
				return aafm_exact_object_chain( 'comment', $comment );
			}
		);

		$this->assertNull( $got );
		$this->assert_fired_in( 'aafm_exact_object_chain', "the comment's post" );
	}

	public function test_a_comment_on_a_missing_post_or_on_no_post_returns_the_comment(): void {
		$orphan = $this->comment( $this->post() );
		$this->set_comment_post( $orphan, self::MISSING );
		$none = $this->comment( $this->post() );
		$this->set_comment_post( $none, 0 );

		$got_orphan = aafm_exact_object_chain( 'comment', $orphan );
		$got_none   = aafm_exact_object_chain( 'comment', $none );

		$this->assertInstanceOf( WP_Comment::class, $got_orphan );
		$this->assertSame( $orphan, (int) $got_orphan->comment_ID );
		$this->assertInstanceOf( WP_Comment::class, $got_none );
		$this->assertSame( $none, (int) $got_none->comment_ID );
	}

	public function test_a_comment_whose_post_is_a_revision_with_its_parent_gone_returns_null(): void {
		$comment = $this->comment( $this->post() );
		$this->set_comment_post( $comment, $this->orphan_revision() );

		$this->assertNull( aafm_exact_object_chain( 'comment', $comment ) );
	}

	public function test_a_term_chain_walks_the_parents_in_the_taxonomy(): void {
		$a = $this->category();
		$b = $this->category( $a );
		$c = $this->category( $b );
		get_term( $c, 'category' );
		$foreign = $this->category();

		$healthy = aafm_exact_object_chain( 'term', $c, 'category' );
		$faulted = $this->armed(
			$this->fault_load( 'term', $b, $foreign ),
			static function () use ( $c ) {
				return aafm_exact_object_chain( 'term', $c, 'category' );
			}
		);

		$this->assertInstanceOf( WP_Term::class, $healthy );
		$this->assertSame( $c, (int) $healthy->term_id );
		$this->assertNull( $faulted );
		$this->assert_fired_in( 'aafm_exact_object_chain', 'term parent' );
		$this->assertFalse( wp_cache_get( $b, 'terms' ), "no entry is left under the parent's id" );
	}

	public function test_a_term_chain_ends_at_a_certified_absent_parent_and_at_a_cycle(): void {
		global $wpdb;
		$dangling = $this->category();
		$wpdb->update( $wpdb->term_taxonomy, array( 'parent' => self::MISSING ), array( 'term_id' => $dangling ) );
		$a = $this->category();
		$b = $this->category( $a );
		$wpdb->update( $wpdb->term_taxonomy, array( 'parent' => $b ), array( 'term_id' => $a ) );
		clean_term_cache( array( $dangling, $a, $b ), 'category' );

		$got_dangling = aafm_exact_object_chain( 'term', $dangling, 'category' );
		$got_cycle    = aafm_exact_object_chain( 'term', $b, 'category' );

		$this->assertInstanceOf( WP_Term::class, $got_dangling );
		$this->assertSame( $dangling, (int) $got_dangling->term_id );
		$this->assertInstanceOf( WP_Term::class, $got_cycle );
		$this->assertSame( $b, (int) $got_cycle->term_id );
	}

	public function test_object_absent_is_true_only_for_a_row_a_successful_query_did_not_find(): void {
		$post = $this->post();
		$term = $this->category();

		$this->assertTrue( aafm_object_absent( 'post', self::MISSING ) );
		$this->assertFalse( aafm_object_absent( 'post', $post ) );
		$this->assertTrue( aafm_object_absent( 'term', self::MISSING, 'category' ) );
		$this->assertFalse( aafm_object_absent( 'term', $term, 'category' ) );
		$this->assertTrue( aafm_object_absent( 'term', $term, 'post_tag' ) );
		$this->assertFalse( aafm_object_absent( 'user', self::MISSING ) );
		$this->assertFalse( aafm_object_absent( 'comment', self::MISSING ) );
	}

	public function test_object_absent_is_false_when_its_query_fails(): void {
		$post_query = $this->armed(
			null,
			static function () {
				return QueryFaultInjector::fail_query(
					'SELECT ID FROM',
					static function () {
						return aafm_object_absent( 'post', self::MISSING );
					}
				);
			}
		);
		$fired_post = QueryFaultInjector::fired_count();
		$term_query = $this->armed(
			null,
			static function () {
				return QueryFaultInjector::break_query_with_real_error(
					'SELECT term_id FROM',
					static function () {
						return aafm_object_absent( 'term', self::MISSING, 'category' );
					}
				);
			}
		);

		$this->assertFalse( $post_query );
		$this->assertSame( 1, $fired_post );
		$this->assertFalse( $term_query );
		$this->assertSame( 2, QueryFaultInjector::fired_count() );
	}

	public function test_an_orphaned_term_taxonomy_row_reads_absent(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->term_taxonomy,
			array(
				'term_id'     => self::MISSING,
				'taxonomy'    => 'category',
				'description' => '',
				'parent'      => 0,
				'count'       => 0,
			)
		);

		$this->assertNull( get_term( self::MISSING, 'category' ), 'core finds no term without its terms row' );
		$this->assertTrue( aafm_object_absent( 'term', self::MISSING, 'category' ) );
	}

	public function test_term_absence_with_no_taxonomy_matches_any_taxonomy(): void {
		$term = $this->category();

		$this->assertFalse( aafm_object_absent( 'term', $term, '' ) );
		$this->assertTrue( aafm_object_absent( 'term', self::MISSING, '' ) );
	}

	/**
	 * An attachment inheriting its status from $parent_id.
	 *
	 * @param int $parent_id Parent post id.
	 */
	private function attachment( int $parent_id ): int {
		return $this->post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => $parent_id,
			)
		);
	}

	/**
	 * A user who moderates comments and edits only their own posts.
	 */
	private function own_posts_moderator(): int {
		$id   = $this->acting_as( 'subscriber' );
		$user = wp_get_current_user();
		foreach ( array( 'moderate_comments', 'edit_posts', 'edit_published_posts' ) as $cap ) {
			$user->add_cap( $cap );
		}
		return $id;
	}

	/**
	 * Fault the next postmeta load of $post_id with no rows, in core's SQL and in the checked-read
	 * scope's copy of it.
	 *
	 * @param int $post_id Post id.
	 * @return callable
	 */
	private function fault_post_meta( int $post_id ): callable {
		global $wpdb;
		wp_cache_delete( $post_id, 'post_meta' );
		return $this->recording_leak(
			array( 'SELECT post_id, meta_key, meta_value FROM', $wpdb->postmeta, "WHERE post_id IN ({$post_id})" ),
			sprintf( 'SELECT * FROM %s WHERE 1 = 0', $wpdb->postmeta )
		);
	}

	/**
	 * T1: an attachment's comment read follows the attachment's parent.
	 */
	public function test_a_comment_on_an_attachment_of_a_private_post_is_refused_when_the_parent_load_fails(): void {
		$parent     = $this->post( array( 'post_status' => 'private' ) );
		$attachment = $this->attachment( $parent );
		$comment    = $this->comment( $attachment );
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_comment_post_is_readable( $attachment ), 'healthy: the private parent decides' );
		get_post( $attachment );
		get_comment( $comment );

		$readable = $this->armed(
			$this->fault_load( 'post', $parent ),
			static function () use ( $attachment ) {
				return aafm_comment_post_is_readable( $attachment );
			}
		);
		$this->assertFalse( $readable );
		$this->assert_fired_in( 'aafm_exact_object_chain', 'attachment parent' );

		QueryFaultInjector::reset_fired_count();
		$this->stages = array();
		$permitted    = $this->armed(
			$this->fault_load( 'post', $parent ),
			static function () use ( $comment ) {
				return aafm_perm_get_comment( array( 'comment_id' => $comment ) );
			}
		);
		$this->assertFalse( $permitted, 'get-comment refused' );
		$this->assert_fired_in( 'aafm_exact_object_chain', 'get-comment' );
	}

	/**
	 * T1: a revision as the comment's post.
	 */
	public function test_a_comment_on_a_revision_is_refused_when_the_revisions_parent_reads_another_row(): void {
		$parent   = $this->post( array( 'post_status' => 'private' ) );
		$revision = $this->post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $parent,
			)
		);
		$public   = $this->post();
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_comment_post_is_readable( $revision ), 'healthy: the private parent decides' );
		get_post( $revision );
		get_post( $public );

		$readable = $this->armed(
			$this->fault_load( 'post', $parent, $public ),
			static function () use ( $revision ) {
				return aafm_comment_post_is_readable( $revision );
			}
		);

		$this->assertFalse( $readable );
		$this->assert_fired_in( 'aafm_exact_object_chain', 'revision parent' );
	}

	/**
	 * T1: a parent certified absent reads as core reads it.
	 */
	public function test_a_comment_on_an_attachment_whose_parent_is_gone_stays_readable(): void {
		$attachment = $this->attachment( $this->post() );
		$this->set_parent( $attachment, self::MISSING );
		$this->acting_as( 'subscriber' );

		$this->assertTrue( aafm_comment_post_is_readable( $attachment ) );
	}

	/**
	 * T1 (trashed parent): core reads the parent's status from its `_wp_trash_meta_status` meta.
	 */
	public function test_an_attachment_of_a_trashed_private_post_reads_the_trash_meta_and_refuses_when_that_load_fails(): void {
		$private    = $this->post( array( 'post_status' => 'private' ) );
		$published  = $this->post();
		$attachment = $this->attachment( $private );
		$open       = $this->attachment( $published );
		wp_trash_post( $private );
		wp_trash_post( $published );
		$comment = $this->comment( $attachment );
		$this->acting_as( 'subscriber' );

		$this->assertFalse( aafm_comment_post_is_readable( $attachment ), 'healthy: meta private' );
		$this->assertTrue( aafm_comment_post_is_readable( $open ), 'healthy: meta publish' );

		get_post( $attachment );
		get_post( $private );
		get_comment( $comment );
		$readable = $this->armed(
			$this->fault_post_meta( $private ),
			static function () use ( $attachment ) {
				return aafm_comment_post_is_readable( $attachment );
			}
		);
		$this->assertFalse( $readable );
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'the trash meta load was faulted' );

		QueryFaultInjector::reset_fired_count();
		$permitted = $this->armed(
			$this->fault_post_meta( $private ),
			static function () use ( $comment ) {
				return aafm_perm_get_comment( array( 'comment_id' => $comment ) );
			}
		);
		$this->assertFalse( $permitted, 'get-comment refused' );
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count(), 'the trash meta load was faulted' );
	}

	/**
	 * T2: edit_comment maps through the comment's post, so every edit_comment gate chain-loads it.
	 *
	 * @return iterable<string,array{0:string,1:string}>
	 */
	public function data_edit_comment_gates(): iterable {
		yield 'get-comment, held comment' => array( 'aafm_perm_get_comment', '0' );
		yield 'moderate-comment' => array( 'aafm_perm_moderate_comment_obj', '1' );
		yield 'update-comment' => array( 'aafm_perm_edit_comment_obj', '1' );
	}

	/**
	 * T2.
	 *
	 * @dataProvider data_edit_comment_gates
	 *
	 * @param string $gate     Permission callback.
	 * @param string $approved Comment status.
	 */
	public function test_an_edit_comment_gate_refuses_when_the_comments_post_reads_another_row( string $gate, string $approved ): void {
		$other   = self::factory()->user->create( array( 'role' => 'author' ) );
		$theirs  = $this->post( array( 'post_author' => $other ) );
		$comment = (int) self::factory()->comment->create(
			array(
				'comment_post_ID'  => $theirs,
				'comment_approved' => $approved,
			)
		);
		$me      = $this->own_posts_moderator();
		$mine    = $this->post( array( 'post_author' => $me ) );
		$this->assertFalse( $gate( array( 'comment_id' => $comment ) ), 'healthy: not my post' );
		get_comment( $comment );
		get_post( $mine );

		$permitted = $this->armed(
			$this->fault_load( 'post', $theirs, $mine ),
			static function () use ( $gate, $comment ) {
				return $gate( array( 'comment_id' => $comment ) );
			}
		);

		$this->assertFalse( $permitted );
		$this->assert_fired_in( 'aafm_exact_object_chain', $gate );
	}

	/**
	 * T3: update-term walks the parents the way wp_update_term() and term_is_ancestor_of() do.
	 *
	 * @return iterable<string,array{0:bool,1:string}>
	 */
	public function data_update_term_parent_faults(): iterable {
		yield 'no parent input, the term\'s own parent' => array( false, 'aafm_error' );
		yield 'a parent input whose parent fails' => array( true, 'aafm_invalid_term_parent' );
	}

	/**
	 * T3.
	 *
	 * @dataProvider data_update_term_parent_faults
	 *
	 * @param bool   $with_parent Whether the input names a parent.
	 * @param string $code        Expected error code.
	 */
	public function test_update_term_refuses_before_the_write_when_a_parent_in_the_walk_reads_another_row( bool $with_parent, string $code ): void {
		$a       = $this->category();
		$b       = $this->category( $a );
		$c       = $this->category( $b );
		$x       = $this->category();
		$foreign = $this->category();
		$this->acting_as( 'administrator' );
		$target = $with_parent ? $x : $c;
		$input  = array(
			'taxonomy' => 'category',
			'term_id'  => $target,
			'name'     => 'Renamed',
		);
		if ( $with_parent ) {
			$input['parent'] = $c;
		}
		get_term( $target, 'category' );
		get_term( $c, 'category' );
		get_term( $foreign, 'category' );
		$writes = did_action( 'edit_terms' );

		$out = $this->armed(
			$this->fault_load( 'term', $b, $foreign ),
			static function () use ( $input ) {
				return aafm_exec_update_term( $input );
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( $code, $out->get_error_code() );
		$this->assert_fired_in( 'aafm_exact_object_chain', 'term parent' );
		$this->assertSame( $writes, did_action( 'edit_terms' ), 'wp_update_term() was not reached' );
		$this->assertFalse( wp_cache_get( $b, 'terms' ), "the leaked row is not cached under the parent's id" );
		$this->assertNotSame( 'Renamed', get_term( $target, 'category' )->name );
	}

	/**
	 * T3 healthy: the same calls write when every parent loads.
	 */
	public function test_update_term_under_a_parent_chain_writes_when_healthy(): void {
		$a = $this->category();
		$b = $this->category( $a );
		$c = $this->category( $b );
		$x = $this->category();
		$this->acting_as( 'administrator' );

		$renamed  = aafm_exec_update_term(
			array(
				'taxonomy' => 'category',
				'term_id'  => $c,
				'name'     => 'Renamed',
			)
		);
		$reparent = aafm_exec_update_term(
			array(
				'taxonomy' => 'category',
				'term_id'  => $x,
				'parent'   => $c,
			)
		);

		$this->assertIsArray( $renamed );
		$this->assertIsArray( $reparent );
		$this->assertSame( $c, (int) get_term( $x, 'category' )->parent );
	}

	/**
	 * A nav menu.
	 *
	 * @param string $name Menu name.
	 */
	private function menu( string $name ): int {
		$id = wp_create_nav_menu( $name );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * A menu item made through create-menu-item.
	 *
	 * @param array<string,mixed> $input Create input.
	 */
	private function made_item( array $input ): int {
		$made = aafm_exec_create_menu_item( $input );
		$this->assertIsArray( $made );
		return (int) $made['id'];
	}

	/**
	 * Remove a post row directly, the way a target disappears without core's menu-item cleanup.
	 *
	 * @param int $id Post id.
	 */
	private function drop_post_row( int $id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->posts, array( 'ID' => $id ) );
		clean_post_cache( $id );
	}

	/**
	 * T4: a failed membership read refuses.
	 */
	public function test_menu_item_membership_refuses_when_its_query_fails(): void {
		$this->acting_as( 'administrator' );
		$menu = $this->menu( 'M1' );
		$item = $this->made_item(
			array(
				'menu_id' => $menu,
				'title'   => 'Link',
				'url'     => home_url( '/link' ),
			)
		);
		$this->assertNotNull( aafm_menu_item_by_id( $menu, $item ), 'healthy: a member' );

		$lookup = $this->armed(
			null,
			static function () use ( $menu, $item ) {
				return QueryFaultInjector::fail_query(
					'SELECT tr.object_id FROM',
					static function () use ( $menu, $item ) {
						return array(
							aafm_menu_item_by_id( $menu, $item ),
							aafm_exec_update_menu_item(
								array(
									'menu_id' => $menu,
									'item_id' => $item,
									'title'   => 'Renamed',
								)
							),
						);
					}
				);
			}
		);

		$this->assertNull( $lookup[0] );
		$this->assertInstanceOf( \WP_Error::class, $lookup[1] );
		$this->assertSame( 'aafm_error', $lookup[1]->get_error_code() );
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count() );
		$this->assertSame( 'Link', get_post( $item )->post_title );
	}

	/**
	 * T4: another menu's item is not a member, even when a term load reads the asked menu's row.
	 */
	public function test_an_item_of_another_menu_is_refused_when_that_menus_term_reads_the_asked_menu(): void {
		$this->acting_as( 'administrator' );
		$m1   = $this->menu( 'M1' );
		$m2   = $this->menu( 'M2' );
		$item = $this->made_item(
			array(
				'menu_id' => $m2,
				'title'   => 'Link',
				'url'     => home_url( '/link' ),
			)
		);
		$this->assertNull( aafm_menu_item_by_id( $m1, $item ), 'healthy: not a member' );
		wp_get_object_terms( $item, 'nav_menu' );
		get_object_term_cache( $item, 'nav_menu' );

		$by_id   = $this->fault_load( 'term', $m2, $m1, 0 );
		$by_list = $this->recording_leak( array( 'SELECT t.*, tt.* FROM', "WHERE t.term_id IN ({$m2})" ), $this->load_sql( 'term', $m1 ), 0 );
		$got     = $this->armed(
			static function ( string $query ) use ( $by_id, $by_list ): string {
				return $by_list( $by_id( $query ) );
			},
			static function () use ( $m1, $item ) {
				return aafm_menu_item_by_id( $m1, $item );
			}
		);

		$this->assertNull( $got );
	}

	/**
	 * T5: the writers check the item's target and the post id core will write as its parent.
	 *
	 * @return iterable<string,array{0:string}>
	 */
	public function data_menu_item_target_faults(): iterable {
		yield 'create, post target' => array( 'create_post' );
		yield 'create, post target parent' => array( 'create_post_parent' );
		yield 'create, taxonomy target' => array( 'create_term' );
		yield 'create, taxonomy target parent post' => array( 'create_term_parent' );
		yield 'update, post target parent' => array( 'update_post_parent' );
		yield 'update, taxonomy target parent post' => array( 'update_term_parent' );
		yield 'update, taxonomy target' => array( 'update_term' );
	}

	/**
	 * T5.
	 *
	 * @dataProvider data_menu_item_target_faults
	 *
	 * @param string $shape Which target and which load is faulted.
	 */
	public function test_a_menu_item_writer_refuses_before_the_write_when_its_target_reads_another_row( string $shape ): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		$menu    = $this->menu( 'Main' );
		$foreign = $this->post();
		$grand   = $this->post( array( 'post_type' => 'page' ) );
		$page    = $this->post( array( 'post_type' => 'page' ) );
		$term    = $this->category();
		$is_term = false !== strpos( $shape, 'term' );
		$create  = 0 === strpos( $shape, 'create' );

		$target = $is_term
			? array(
				'type'      => 'taxonomy',
				'object'    => 'category',
				'object_id' => $term,
			)
			: array(
				'type'      => 'post_type',
				'object'    => 'page',
				'object_id' => $page,
			);
		$item   = 0;
		if ( ! $create ) {
			$item = $this->made_item( array( 'menu_id' => $menu ) + $target + array( 'title' => 'Item' ) );
		}
		// The parent core reads from the target now, after any item was made.
		if ( $is_term ) {
			$wpdb->update( $wpdb->term_taxonomy, array( 'parent' => $page ), array( 'term_id' => $term ) );
			clean_term_cache( $term, 'category' );
		}
		$this->set_parent( $page, $grand );

		$faulted = array(
			'create_post'        => $page,
			'create_post_parent' => $grand,
			'create_term'        => $term,
			'create_term_parent' => $page,
			'update_post_parent' => $grand,
			'update_term_parent' => $page,
			'update_term'        => $term,
		)[ $shape ];
		if ( $create && ! $is_term && $faulted === $grand ) {
			get_post( $page );
		}
		get_post( $foreign );
		$writes = did_action( 'save_post_nav_menu_item' );

		$out = $this->armed(
			$is_term && $faulted === $term ? $this->fault_load( 'term', $term, $this->category() ) : $this->fault_load( 'post', $faulted, $foreign ),
			static function () use ( $create, $menu, $item, $target ) {
				if ( $create ) {
					return aafm_exec_create_menu_item( array( 'menu_id' => $menu ) + $target + array( 'title' => 'New' ) );
				}
				return aafm_exec_update_menu_item(
					array(
						'menu_id' => $menu,
						'item_id' => $item,
						'title'   => 'Renamed',
					)
				);
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'the target load was faulted' );
		$this->assertContains( 'aafm_menu_item_target_checked', $this->stages[0] ?? array(), 'the faulted SELECT fired inside the target check' );
		$this->assertSame( $writes, did_action( 'save_post_nav_menu_item' ), 'the writer was not reached' );
	}

	/**
	 * T5: a target whose row is gone writes as in 1.7.5.
	 *
	 * The target check's verdict is asserted on every version. The write round trip runs only
	 * from WordPress 7.0 up. Before 7.0, core's wp_setup_nav_menu_item() hands a missing target
	 * straight to get_post_states() (6.9 nav-menu.php:879; 7.0 guards it at :876), and
	 * aafm_menu_item_by_id() decorates the item on purpose (menus.php:1100-1104), as 1.7.5 did.
	 * This is the same gate as MenuItemVisibilityCorpusTest::core_reader_can_answer().
	 */
	public function test_a_menu_item_whose_target_row_is_gone_still_writes(): void {
		$this->acting_as( 'administrator' );
		$menu   = $this->menu( 'Main' );
		$page   = $this->post( array( 'post_type' => 'page' ) );
		$item   = $this->made_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'Item',
				'type'      => 'post_type',
				'object'    => 'page',
				'object_id' => $page,
			)
		);
		$writes = did_action( 'save_post_nav_menu_item' );
		$this->drop_post_row( $page );

		$this->assertTrue( aafm_object_absent( 'post', $page ), 'the row is really gone' );
		$this->assertTrue( aafm_menu_item_target_checked( 'post_type', 'page', $page ), 'update: a gone target does not refuse' );
		$this->assertTrue( aafm_menu_item_target_checked( 'post_type', 'page', self::MISSING ), 'create: a gone target does not refuse' );

		if ( version_compare( (string) get_bloginfo( 'version' ), '7.0', '<' ) ) {
			return;
		}

		$updated = aafm_exec_update_menu_item(
			array(
				'menu_id' => $menu,
				'item_id' => $item,
				'title'   => 'Renamed',
			)
		);
		$created = aafm_exec_create_menu_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'Gone',
				'type'      => 'post_type',
				'object'    => 'page',
				'object_id' => self::MISSING,
			)
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'Renamed', get_post( $item )->post_title );
		$this->assertInstanceOf( \WP_Error::class, $created );
		$this->assertSame( 'aafm_invalid_menu_item', $created->get_error_code(), 'written, then removed as _invalid, as before' );
		$this->assertSame( $writes + 2, did_action( 'save_post_nav_menu_item' ) );
	}

	/**
	 * T5 (named refusal): a post target that is a revision whose parent is gone.
	 */
	public function test_create_menu_item_refuses_a_revision_target_whose_parent_is_gone(): void {
		$this->acting_as( 'administrator' );
		$menu   = $this->menu( 'Main' );
		$writes = did_action( 'save_post_nav_menu_item' );

		$out = aafm_exec_create_menu_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'Revision',
				'type'      => 'post_type',
				'object_id' => $this->orphan_revision(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertSame( $writes, did_action( 'save_post_nav_menu_item' ) );
	}

	/**
	 * T5 (the narrowing): an item whose stored parent is such a revision still updates.
	 */
	public function test_update_menu_item_writes_when_the_items_stored_parent_is_a_revision_whose_parent_is_gone(): void {
		$this->acting_as( 'administrator' );
		$menu = $this->menu( 'Main' );
		$item = $this->made_item(
			array(
				'menu_id' => $menu,
				'title'   => 'Link',
				'url'     => home_url( '/link' ),
			)
		);
		$this->set_parent( $item, $this->orphan_revision() );

		$out = aafm_exec_update_menu_item(
			array(
				'menu_id' => $menu,
				'item_id' => $item,
				'title'   => 'Renamed',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'Renamed', get_post( $item )->post_title );
	}

	/**
	 * A throwaway taxonomy for menu targets, and one term in it.
	 *
	 * @return array{0:string,1:int} Taxonomy name and term id.
	 */
	private function throwaway_taxonomy_term(): array {
		$taxonomy = 'aafm_menu_tax';
		register_taxonomy( $taxonomy, 'post', array( 'public' => true ) );
		$term = wp_insert_term( 'Target', $taxonomy );
		$this->assertIsArray( $term );
		return array( $taxonomy, (int) $term['term_id'] );
	}

	/**
	 * Update an item's title.
	 *
	 * @param int    $menu  Menu id.
	 * @param int    $item  Item id.
	 * @param string $title New title.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function retitle( int $menu, int $item, string $title = 'Renamed' ) {
		return aafm_exec_update_menu_item(
			array(
				'menu_id' => $menu,
				'item_id' => $item,
				'title'   => $title,
			)
		);
	}

	/**
	 * The update wrote and returned the item as a fresh read shapes it.
	 *
	 * @param mixed $out    The update's result.
	 * @param int   $menu   Menu id.
	 * @param int   $item   Item id.
	 * @param int   $writes save_post_nav_menu_item count before the update (the order restore of a
	 *                      first item writes a second time).
	 */
	private function assert_retitled( $out, int $menu, int $item, int $writes ): void {
		$this->assertIsArray( $out );
		$this->assertSame( aafm_redact_menu_item( aafm_menu_item_by_id( $menu, $item ) ), $out );
		$this->assertSame( 'Renamed', get_post( $item )->post_title );
		$this->assertGreaterThan( $writes, did_action( 'save_post_nav_menu_item' ), 'the item was written' );
	}

	/**
	 * A term target whose taxonomy is not registered in the request: core reads no term, so the
	 * update writes as in 1.7.5.
	 */
	public function test_update_menu_item_writes_a_taxonomy_item_whose_taxonomy_is_not_registered(): void {
		$this->acting_as( 'administrator' );
		$menu                    = $this->menu( 'Main' );
		list( $taxonomy, $term ) = $this->throwaway_taxonomy_term();
		$item                    = $this->made_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'Item',
				'type'      => 'taxonomy',
				'object'    => $taxonomy,
				'object_id' => $term,
			)
		);
		unregister_taxonomy( $taxonomy );
		wp_cache_delete( $term, 'terms' );
		$this->assertFalse( wp_cache_get( $term, 'terms' ), 'precondition: the term is not cached' );
		$writes = did_action( 'save_post_nav_menu_item' );

		$this->assert_retitled( $this->retitle( $menu, $item ), $menu, $item, $writes );
	}

	/**
	 * The same with the term still in core's cache.
	 */
	public function test_update_menu_item_writes_an_unregistered_taxonomy_item_whose_term_is_still_cached(): void {
		$this->acting_as( 'administrator' );
		$menu                    = $this->menu( 'Main' );
		list( $taxonomy, $term ) = $this->throwaway_taxonomy_term();
		$item                    = $this->made_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'Item',
				'type'      => 'taxonomy',
				'object'    => $taxonomy,
				'object_id' => $term,
			)
		);
		get_term( $term, $taxonomy );
		unregister_taxonomy( $taxonomy );
		$this->assertIsObject( wp_cache_get( $term, 'terms' ), 'precondition: the term is cached' );
		$writes = did_action( 'save_post_nav_menu_item' );

		$this->assert_retitled( $this->retitle( $menu, $item ), $menu, $item, $writes );
	}

	public function test_create_menu_item_returns_invalid_menu_item_for_a_taxonomy_that_is_not_registered(): void {
		$this->acting_as( 'administrator' );
		$menu                    = $this->menu( 'Main' );
		list( $taxonomy, $term ) = $this->throwaway_taxonomy_term();
		unregister_taxonomy( $taxonomy );
		wp_cache_delete( $term, 'terms' );
		$this->assertFalse( wp_cache_get( $term, 'terms' ), 'precondition: the term is not cached' );
		$count  = static function (): int {
			return count(
				get_posts(
					array(
						'post_type'   => 'nav_menu_item',
						'post_status' => 'any',
						'numberposts' => -1,
						'fields'      => 'ids',
					)
				)
			);
		};
		$before = $count();

		$named   = aafm_exec_create_menu_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'New',
				'type'      => 'taxonomy',
				'object'    => $taxonomy,
				'object_id' => $term,
			)
		);
		$unnamed = aafm_exec_create_menu_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'New',
				'type'      => 'taxonomy',
				'object_id' => $term,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $named );
		$this->assertSame( 'aafm_invalid_menu_item', $named->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $named->get_error_data() );
		$this->assertInstanceOf( \WP_Error::class, $unnamed );
		$this->assertSame( 'aafm_menu_item_object_required', $unnamed->get_error_code(), 'without object, unchanged' );
		$this->assertSame( $before, $count(), 'no menu item is left behind' );
	}

	/**
	 * AC-4: the target is checked before anything decorates the item.
	 */
	public function test_a_title_only_update_refuses_when_the_target_select_reads_another_row(): void {
		$this->acting_as( 'administrator' );
		$menu    = $this->menu( 'Main' );
		$page    = $this->post( array( 'post_type' => 'page' ) );
		$foreign = $this->post();
		$item    = $this->made_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'Item',
				'type'      => 'post_type',
				'object'    => 'page',
				'object_id' => $page,
			)
		);
		$url     = get_post_meta( $item, '_menu_item_url', true );
		$writes  = did_action( 'save_post_nav_menu_item' );
		get_post( $foreign );

		$out = $this->armed(
			$this->fault_load( 'post', $page, $foreign ),
			function () use ( $menu, $item ) {
				return $this->retitle( $menu, $item );
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assert_fired_in( 'aafm_menu_item_target_checked', 'post target' );
		$this->assertSame( $writes, did_action( 'save_post_nav_menu_item' ), 'the writer was not reached' );
		$this->assertSame( $url, get_post_meta( $item, '_menu_item_url', true ) );
		$this->assertSame( 'Item', get_post( $item )->post_title );
	}

	/**
	 * A url a display filter rewrites is not stored on update.
	 */
	public function test_update_menu_item_keeps_the_stored_url_a_display_filter_rewrites(): void {
		$this->acting_as( 'administrator' );
		$menu   = $this->menu( 'Main' );
		$stored = home_url( '/stored' );
		$item   = $this->made_item(
			array(
				'menu_id' => $menu,
				'title'   => 'Item',
				'url'     => $stored,
			)
		);
		add_filter(
			'wp_setup_nav_menu_item',
			static function ( $menu_item ) use ( $item ) {
				if ( (int) $menu_item->ID === $item ) {
					$menu_item->url = home_url( '/for-this-request' );
				}
				return $menu_item;
			}
		);
		$writes = did_action( 'save_post_nav_menu_item' );

		$this->assert_retitled( $this->retitle( $menu, $item ), $menu, $item, $writes );
		$this->assertSame( $stored, get_post_meta( $item, '_menu_item_url', true ) );
	}

	/**
	 * The stored fields are read failure-aware: a failed load refuses instead of writing blanks.
	 */
	public function test_update_menu_item_refuses_when_the_items_stored_fields_do_not_load(): void {
		$this->acting_as( 'administrator' );
		$menu   = $this->menu( 'Main' );
		$stored = home_url( '/stored' );
		$item   = $this->made_item(
			array(
				'menu_id' => $menu,
				'title'   => 'Item',
				'url'     => $stored,
			)
		);
		$writes = did_action( 'save_post_nav_menu_item' );

		$out = $this->armed(
			$this->fault_post_meta( $item ),
			function () use ( $menu, $item ) {
				return $this->retitle( $menu, $item );
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count() );
		$this->assertSame( $writes, did_action( 'save_post_nav_menu_item' ), 'the writer was not reached' );
		wp_cache_delete( $item, 'post_meta' );
		$this->assertSame( 'custom', get_post_meta( $item, '_menu_item_type', true ) );
		$this->assertSame( $stored, get_post_meta( $item, '_menu_item_url', true ) );
	}

	/**
	 * A category menu item.
	 *
	 * @param int $menu Menu id.
	 * @param int $term Category id.
	 */
	private function category_item( int $menu, int $term ): int {
		return $this->made_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'Item',
				'type'      => 'taxonomy',
				'object'    => 'category',
				'object_id' => $term,
			)
		);
	}

	/**
	 * A `get_term` filter that answers $answer for term $term_id.
	 *
	 * @param int   $term_id Term id.
	 * @param mixed $answer  What the filter returns for it.
	 */
	private function filter_term( int $term_id, $answer ): void {
		add_filter(
			'get_term',
			static function ( $term ) use ( $term_id, $answer ) {
				return $term instanceof WP_Term && (int) $term->term_id === $term_id ? $answer : $term;
			}
		);
	}

	/**
	 * A term a site filter hides after it loaded exactly: core's own get_term() reads nothing.
	 */
	public function test_update_menu_item_writes_when_a_filter_hides_its_target_term(): void {
		$this->acting_as( 'administrator' );
		$menu = $this->menu( 'Main' );
		$term = $this->category();
		$item = $this->category_item( $menu, $term );
		get_term( $term, 'category' );
		$this->filter_term( $term, null );
		$writes = did_action( 'save_post_nav_menu_item' );

		$this->assert_retitled( $this->retitle( $menu, $item ), $menu, $item, $writes );
	}

	/**
	 * The same for an item stored with no taxonomy name.
	 */
	public function test_update_menu_item_writes_when_a_filter_hides_a_term_target_stored_with_no_taxonomy(): void {
		$this->acting_as( 'administrator' );
		$menu = $this->menu( 'Main' );
		$term = $this->category();
		$item = $this->category_item( $menu, $term );
		update_post_meta( $item, '_menu_item_object', '' );
		get_term( $term, '' );
		$this->filter_term( $term, null );
		$writes = did_action( 'save_post_nav_menu_item' );

		$this->assert_retitled( $this->retitle( $menu, $item ), $menu, $item, $writes );
	}

	/**
	 * A filter that swaps the target for another term refuses both writers, a named refusal.
	 */
	public function test_menu_item_writers_refuse_when_a_filter_swaps_the_target_term(): void {
		$this->acting_as( 'administrator' );
		$menu  = $this->menu( 'Main' );
		$term  = $this->category();
		$other = get_term( $this->category(), 'category' );
		$item  = $this->category_item( $menu, $term );
		$this->filter_term( $term, $other );
		$writes = did_action( 'save_post_nav_menu_item' );

		$updated = $this->retitle( $menu, $item );
		$created = aafm_exec_create_menu_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'New',
				'type'      => 'taxonomy',
				'object'    => 'category',
				'object_id' => $term,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $updated );
		$this->assertSame( 'aafm_error', $updated->get_error_code() );
		$this->assertInstanceOf( \WP_Error::class, $created );
		$this->assertSame( 'aafm_error', $created->get_error_code() );
		$this->assertSame( $writes, did_action( 'save_post_nav_menu_item' ) );
	}

	/**
	 * A target term served from core's cache after its rows are gone writes, as in 1.7.5.
	 */
	public function test_update_menu_item_writes_when_its_target_term_is_served_from_the_cache(): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		$menu = $this->menu( 'Main' );
		$term = $this->category();
		$item = $this->category_item( $menu, $term );
		get_term( $term, 'category' );
		$wpdb->delete( $wpdb->terms, array( 'term_id' => $term ) );
		$wpdb->delete( $wpdb->term_taxonomy, array( 'term_id' => $term ) );
		$writes = did_action( 'save_post_nav_menu_item' );

		$this->assert_retitled( $this->retitle( $menu, $item ), $menu, $item, $writes );
	}

	/**
	 * A post target whose post type is not registered in the request behaves as in 1.7.5.
	 */
	public function test_menu_item_writers_on_an_unregistered_post_type_behave_as_before(): void {
		$this->acting_as( 'administrator' );
		register_post_type( 'aafm_menu_type', array( 'public' => true ) );
		$menu   = $this->menu( 'Main' );
		$target = $this->post( array( 'post_type' => 'aafm_menu_type' ) );
		$input  = array(
			'menu_id'   => $menu,
			'title'     => 'Item',
			'type'      => 'post_type',
			'object'    => 'aafm_menu_type',
			'object_id' => $target,
		);
		$item   = $this->made_item( $input );
		unregister_post_type( 'aafm_menu_type' );
		$writes = did_action( 'save_post_nav_menu_item' );

		$this->assert_retitled( $this->retitle( $menu, $item ), $menu, $item, $writes );
		$created = aafm_exec_create_menu_item( $input );
		$this->assertInstanceOf( \WP_Error::class, $created );
		$this->assertSame( 'aafm_invalid_menu_item', $created->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $created->get_error_data() );
	}

	/**
	 * An item stored with no taxonomy name still refuses a target load that reads another row.
	 */
	public function test_update_menu_item_refuses_a_term_target_stored_with_no_taxonomy_when_its_load_reads_another_row(): void {
		$this->acting_as( 'administrator' );
		$menu  = $this->menu( 'Main' );
		$term  = $this->category();
		$other = $this->category();
		$item  = $this->category_item( $menu, $term );
		update_post_meta( $item, '_menu_item_object', '' );
		$writes = did_action( 'save_post_nav_menu_item' );

		$out = $this->armed(
			$this->fault_load( 'term', $term, $other ),
			function () use ( $menu, $item ) {
				return $this->retitle( $menu, $item );
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assert_fired_in( 'aafm_menu_item_target_checked', 'term target stored with no taxonomy' );
		$this->assertSame( $writes, did_action( 'save_post_nav_menu_item' ) );
	}

	/**
	 * An item stored with no taxonomy name, made on a throwaway taxonomy term that is then left
	 * unregistered and uncached. It is the menu's second item: on the first, whose stored position
	 * is 0, core's own wp_update_nav_menu_item() lists the menu and decorates the item, and
	 * get_term_link() then warns on the unregistered taxonomy (nav-menu.php:464, :938), as in 1.7.5.
	 *
	 * @param int $menu Menu id.
	 * @return int Item id.
	 */
	private function unregistered_item_with_no_taxonomy( int $menu ): int {
		$this->made_item(
			array(
				'menu_id' => $menu,
				'title'   => 'First',
				'url'     => 'https://example.org/',
			)
		);
		list( $taxonomy, $term ) = $this->throwaway_taxonomy_term();
		$item                    = $this->made_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'Item',
				'type'      => 'taxonomy',
				'object'    => $taxonomy,
				'object_id' => $term,
			)
		);
		update_post_meta( $item, '_menu_item_object', '' );
		unregister_taxonomy( $taxonomy );
		wp_cache_delete( $term, 'terms' );
		$this->assertInstanceOf( \WP_Error::class, get_term( $term, '' ), 'precondition: core reads no term' );
		$this->assertFalse( wp_cache_get( $term, 'terms' ), 'precondition: the term is not cached' );
		return $item;
	}

	/**
	 * An item stored with no taxonomy name whose term's only taxonomy is not registered: core's
	 * get_term( $id, '' ) returns an invalid-taxonomy error and reads nothing, so the update writes.
	 */
	public function test_update_menu_item_writes_a_term_target_stored_with_no_taxonomy_whose_taxonomy_is_not_registered(): void {
		$this->acting_as( 'administrator' );
		$menu   = $this->menu( 'Main' );
		$item   = $this->unregistered_item_with_no_taxonomy( $menu );
		$writes = did_action( 'save_post_nav_menu_item' );

		$this->assert_retitled( $this->retitle( $menu, $item ), $menu, $item, $writes );
	}

	/**
	 * An item stored with no taxonomy name whose term two registered taxonomies share: core's
	 * get_term( $id, '' ) returns an ambiguous-term error and reads nothing, so the update writes.
	 */
	public function test_update_menu_item_writes_a_term_target_stored_with_no_taxonomy_that_two_registered_taxonomies_share(): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		$menu = $this->menu( 'Main' );
		$term = $this->category();
		$item = $this->category_item( $menu, $term );
		update_post_meta( $item, '_menu_item_object', '' );
		$wpdb->insert(
			$wpdb->term_taxonomy,
			array(
				'term_id'     => $term,
				'taxonomy'    => 'post_tag',
				'description' => '',
				'parent'      => 0,
				'count'       => 0,
			)
		);
		wp_cache_delete( $term, 'terms' );
		$this->assertInstanceOf( \WP_Error::class, get_term( $term, '' ), 'precondition: core reads no term' );
		$this->assertFalse( wp_cache_get( $term, 'terms' ), 'precondition: the term is not cached' );
		$writes = did_action( 'save_post_nav_menu_item' );

		$this->assert_retitled( $this->retitle( $menu, $item ), $menu, $item, $writes );
	}

	/**
	 * The unregistered-taxonomy item refuses when the query that reads its term's taxonomies fails.
	 */
	public function test_update_menu_item_refuses_a_term_target_stored_with_no_taxonomy_when_its_taxonomy_query_fails(): void {
		$this->acting_as( 'administrator' );
		$menu   = $this->menu( 'Main' );
		$item   = $this->unregistered_item_with_no_taxonomy( $menu );
		$writes = did_action( 'save_post_nav_menu_item' );

		$out = $this->armed(
			null,
			function () use ( $menu, $item ) {
				return QueryFaultInjector::fail_query(
					'SELECT tt.taxonomy FROM',
					function () use ( $menu, $item ) {
						return $this->retitle( $menu, $item );
					}
				);
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'the taxonomy query was failed' );
		$this->assertSame( $writes, did_action( 'save_post_nav_menu_item' ) );
	}

	/**
	 * A target term that a filter hides on the check still has its parent post chain-loaded from
	 * the cached row, so a fault in that load refuses.
	 */
	public function test_update_menu_item_refuses_when_the_parent_post_of_a_hidden_cached_target_term_reads_another_row(): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		$menu    = $this->menu( 'Main' );
		$foreign = $this->post();
		$page    = $this->post( array( 'post_type' => 'page' ) );
		$term    = $this->category();
		$item    = $this->category_item( $menu, $term );
		$wpdb->update( $wpdb->term_taxonomy, array( 'parent' => $page ), array( 'term_id' => $term ) );
		clean_term_cache( $term, 'category' );
		get_term( $term, 'category' );
		$this->filter_term( $term, null );
		get_post( $foreign );
		$writes = did_action( 'save_post_nav_menu_item' );

		$out = $this->armed(
			$this->fault_load( 'post', $page, $foreign ),
			function () use ( $menu, $item ) {
				return $this->retitle( $menu, $item );
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assert_fired_in( 'aafm_menu_item_target_checked', 'parent post of a hidden cached term' );
		$this->assertSame( $writes, did_action( 'save_post_nav_menu_item' ) );
	}

	/**
	 * Another term's row cached under the target's id is not taken for the target.
	 */
	public function test_menu_item_target_check_refuses_a_foreign_row_cached_under_the_target_id(): void {
		$term    = $this->category();
		$foreign = $this->category();
		wp_cache_set( $term, get_term( $foreign, 'category' )->data, 'terms' );
		$this->filter_term( $foreign, null );

		$this->assertFalse( aafm_menu_item_target_checked( 'taxonomy', 'category', $term ) );
	}

	/**
	 * T6 (menu order): update-menu-item's own parent walk, which also runs the order restore.
	 */
	public function test_update_menu_item_refuses_before_the_write_when_an_ancestor_of_the_item_reads_another_row(): void {
		$this->acting_as( 'administrator' );
		$menu    = $this->menu( 'Main' );
		$grand   = $this->post( array( 'post_type' => 'page' ) );
		$parent  = $this->post( array( 'post_type' => 'page' ) );
		$foreign = $this->post();
		$item    = $this->made_item(
			array(
				'menu_id' => $menu,
				'title'   => 'Link',
				'url'     => home_url( '/link' ),
			)
		);
		$this->set_parent( $item, $parent );
		$this->set_parent( $parent, $grand );
		$this->assertSame( 0, (int) get_post( $item )->menu_order, 'the first item, so the order restore runs' );
		get_post( $foreign );
		$writes  = did_action( 'save_post_nav_menu_item' );
		$parents = array(
			$item   => $parent,
			$parent => $grand,
		);

		$out = $this->armed(
			$this->fault_load( 'post', $grand, $foreign ),
			static function () use ( $menu, $item ) {
				return aafm_exec_update_menu_item(
					array(
						'menu_id' => $menu,
						'item_id' => $item,
						'title'   => 'Renamed',
					)
				);
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assert_fired_in( 'aafm_exact_object_chain', 'item ancestor' );
		$this->assertSame( $writes, did_action( 'save_post_nav_menu_item' ), 'the writer was not reached' );
		foreach ( $parents as $id => $expected ) {
			clean_post_cache( $id );
			$this->assertSame( $expected, (int) get_post( $id )->post_parent, "post_parent of $id unchanged" );
		}
	}

	/**
	 * T6 writer walks: one row per writer site outside the menus.
	 *
	 * @return iterable<string,array{0:string}>
	 */
	public function data_writer_walks(): iterable {
		$shapes = array( 'update-post', 'replace-in-post', 'replace-sitewide', 'avada', 'update-block', 'geodirectory', 'update-media', 'update-template', 'restore-revision', 'trash-post', 'trash-page', 'delete-block', 'tec-delete-event' );
		foreach ( $shapes as $shape ) {
			yield $shape => array( $shape );
		}
	}

	/**
	 * The object a writer acts on, and the call that writes it.
	 *
	 * @param string $shape Writer shape.
	 * @return array{0:int,1:callable}
	 */
	private function writer_case( string $shape ): array {
		switch ( $shape ) {
			case 'update-post':
				$id = $this->post();
				return array(
					$id,
					static function () use ( $id ) {
						return aafm_exec_update_post(
							array(
								'post_id' => $id,
								'title'   => 'Renamed',
							)
						);
					},
				);
			case 'replace-in-post':
				$id = $this->post( array( 'post_content' => 'Hello world' ) );
				return array(
					$id,
					static function () use ( $id ) {
						return aafm_exec_replace_in_post(
							array(
								'post_id' => $id,
								'search'  => 'Hello',
								'replace' => 'Howdy',
							)
						);
					},
				);
			case 'replace-sitewide':
				$id = $this->post(
					array(
						'post_type'    => 'page',
						'post_content' => 'Hello aafmwalktoken',
					)
				);
				return array(
					$id,
					static function () {
						return aafm_exec_replace_sitewide(
							array(
								'post_type' => 'page',
								'search'    => 'aafmwalktoken',
								'replace'   => 'swapped',
								'dry_run'   => false,
							)
						);
					},
				);
			case 'avada':
				$id = $this->post( array( 'post_content' => '[fusion_builder_container][fusion_text]Hello world[/fusion_text][/fusion_builder_container]' ) );
				update_post_meta( $id, 'fusion_builder_status', 'active' );
				return array(
					$id,
					static function () use ( $id ) {
						return aafm_exec_avada_replace_text(
							array(
								'post_id' => $id,
								'search'  => 'Hello world',
								'replace' => 'Howdy world',
							)
						);
					},
				);
			case 'update-block':
			case 'delete-block':
				$id = $this->post( array( 'post_type' => 'wp_block' ) );
				if ( 'delete-block' === $shape ) {
					return array(
						$id,
						static function () use ( $id ) {
							return aafm_exec_delete_block( array( 'block_id' => $id ) );
						},
					);
				}
				return array(
					$id,
					static function () use ( $id ) {
						return aafm_exec_update_block(
							array(
								'block_id' => $id,
								'title'    => 'Renamed',
							)
						);
					},
				);
			case 'geodirectory':
				aafm_geodir_stub_activate();
				$made = aafm_exec_geodirectory_create_listing( array( 'title' => 'Listing' ) );
				$this->assertIsArray( $made );
				$id = (int) $made['listing_id'];
				return array(
					$id,
					static function () use ( $id ) {
						return aafm_exec_geodirectory_update_listing(
							array(
								'listing_id' => $id,
								'title'      => 'Renamed',
							)
						);
					},
				);
			case 'update-media':
				$id = $this->attachment( 0 );
				return array(
					$id,
					static function () use ( $id ) {
						return aafm_exec_update_media(
							array(
								'attachment_id' => $id,
								'title'         => 'Renamed',
							)
						);
					},
				);
			case 'update-template':
				$id = $this->post(
					array(
						'post_type'    => 'wp_template',
						'post_name'    => 'aafm-walk',
						'post_content' => 'old',
					)
				);
				wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
				return array(
					$id,
					static function () {
						return aafm_exec_update_template(
							array(
								'template_id' => get_stylesheet() . '//aafm-walk',
								'content'     => '<!-- wp:paragraph --><p>new</p><!-- /wp:paragraph -->',
							)
						);
					},
				);
			case 'restore-revision':
				$id = $this->post( array( 'post_content' => 'v1' ) );
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => 'v2',
					)
				);
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => 'v3',
					)
				);
				$revisions = wp_get_post_revisions( $id );
				$oldest    = end( $revisions );
				$this->assertInstanceOf( WP_Post::class, $oldest );
				$revision_id = (int) $oldest->ID;
				return array(
					$id,
					static function () use ( $id, $revision_id ) {
						return aafm_exec_restore_revision(
							array(
								'post_id'     => $id,
								'revision_id' => $revision_id,
							)
						);
					},
				);
			case 'trash-post':
				$id = $this->post();
				return array(
					$id,
					static function () use ( $id ) {
						return aafm_exec_trash_post( array( 'post_id' => $id ) );
					},
				);
			case 'trash-page':
				$id = $this->post( array( 'post_type' => 'page' ) );
				return array(
					$id,
					static function () use ( $id ) {
						return aafm_exec_trash_page( array( 'page_id' => $id ) );
					},
				);
		}
		aafm_tec_stub_define_globals();
		aafm_tec_stub_register_post_types();
		$id = $this->post( array( 'post_type' => \Tribe__Events__Main::POSTTYPE ) );
		return array(
			$id,
			static function () use ( $id ) {
				return aafm_exec_tec_delete_event( array( 'event_id' => $id ) );
			},
		);
	}

	/**
	 * How many times a writer reached core: an update (which a revision restore runs too) or a trash.
	 */
	private function writer_calls(): int {
		return did_action( 'pre_post_update' ) + did_action( 'wp_trash_post' );
	}

	/**
	 * T6: each writer chain-loads its post before core's parent walk, and refuses when an ancestor
	 * reads another row. replace-sitewide skips the post instead of refusing the call.
	 *
	 * @dataProvider data_writer_walks
	 *
	 * @param string $shape Writer shape.
	 */
	public function test_a_writer_refuses_before_its_walk_when_an_ancestor_reads_another_row( string $shape ): void {
		$this->acting_as( 'administrator' );
		list( $child, $run ) = $this->writer_case( $shape );

		$grand   = $this->post( array( 'post_type' => 'page' ) );
		$parent  = $this->post( array( 'post_type' => 'page' ) );
		$foreign = $this->post();
		$this->set_parent( $child, $parent );
		$this->set_parent( $parent, $grand );
		get_post( $foreign );
		$status = get_post( $child )->post_status;
		$writes = $this->writer_calls();

		$out = $this->armed( $this->fault_load( 'post', $grand, $foreign ), $run );

		if ( 'replace-sitewide' === $shape ) {
			$this->assertIsArray( $out );
			$this->assertSame( 0, $out['matched_posts'], 'the post was skipped' );
			$this->assertSame( 0, $out['updated_posts'] );
			$this->assertSame( 1, $out['failed_updates'], 'the unloadable post was counted as failed' );
		} else {
			// restore-revision's missing-post path is its existing irreversible refusal.
			$this->assertInstanceOf( \WP_Error::class, $out, $shape );
			$this->assertSame( 'restore-revision' === $shape ? 'aafm_restore_irreversible' : 'aafm_error', $out->get_error_code(), $shape );
		}
		$this->assert_fired_in( 'aafm_exact_object_chain', $shape );
		$this->assertSame( $writes, $this->writer_calls(), "$shape: the writer was not reached" );
		$parents = array(
			$child  => $parent,
			$parent => $grand,
		);
		foreach ( $parents as $id => $expected ) {
			clean_post_cache( $id );
			$this->assertSame( $expected, (int) get_post( $id )->post_parent, "$shape: post_parent of $id unchanged" );
		}
		$this->assertSame( $status, get_post( $child )->post_status, "$shape: status unchanged" );
	}

	/**
	 * A dry run counts a post it cannot load as failed too: the call could not have updated it.
	 */
	public function test_replace_sitewide_dry_run_counts_an_unloadable_candidate_as_failed(): void {
		$this->acting_as( 'administrator' );
		$child   = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => 'Hello aafmdryruntoken',
			)
		);
		$grand   = $this->post( array( 'post_type' => 'page' ) );
		$parent  = $this->post( array( 'post_type' => 'page' ) );
		$foreign = $this->post();
		$this->set_parent( $child, $parent );
		$this->set_parent( $parent, $grand );
		get_post( $foreign );

		$out = $this->armed(
			$this->fault_load( 'post', $grand, $foreign ),
			static function () {
				return aafm_exec_replace_sitewide(
					array(
						'post_type' => 'page',
						'search'    => 'aafmdryruntoken',
						'replace'   => 'swapped',
						'dry_run'   => true,
					)
				);
			}
		);

		$this->assertIsArray( $out );
		$this->assertSame( 0, $out['matched_posts'] );
		$this->assertSame( 1, $out['failed_updates'] );
		$this->assert_fired_in( 'aafm_exact_object_chain', 'dry run' );
	}

	/**
	 * T6 (replace-sitewide pair): an earlier write in the loop clears its post from the cache, and
	 * that post can be a later candidate's parent, so each post is chain-loaded again right before
	 * its own write. Fault the parent's load inside that second chain, after the parent was written.
	 */
	public function test_replace_sitewide_loads_each_post_again_before_its_write(): void {
		$this->acting_as( 'administrator' );
		$token   = 'aafmpairtoken';
		$page_a  = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => "A $token",
			)
		);
		$page_b  = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => "B $token",
				'post_parent'  => $page_a,
			)
		);
		$foreign = $this->post();
		get_post( $foreign );
		$written = false;
		$mark    = static function ( int $id ) use ( &$written, $page_a ): void {
			if ( $id === $page_a ) {
				$written = true;
			}
		};
		$leak    = $this->recording_leak( $this->load_sql( 'post', $page_a ), $this->load_sql( 'post', $foreign ) );
		$filter  = static function ( string $query ) use ( &$written, $leak ): string {
			if ( ! $written ) {
				return $query;
			}
			$in_chain = in_array( 'aafm_exact_object_chain', array_column( ( new \Exception() )->getTrace(), 'function' ), true );
			return $in_chain ? $leak( $query ) : $query;
		};
		add_action( 'post_updated', $mark );

		try {
			$out = $this->armed(
				$filter,
				static function () use ( $token ) {
					return aafm_exec_replace_sitewide(
						array(
							'post_type' => 'page',
							'search'    => $token,
							'replace'   => 'swapped',
							'dry_run'   => false,
						)
					);
				}
			);
		} finally {
			remove_action( 'post_updated', $mark );
		}

		$this->assertIsArray( $out );
		$this->assertSame( 2, $out['matched_posts'] );
		$this->assertSame( 1, $out['updated_posts'], 'A was written' );
		$this->assertSame( 1, $out['failed_updates'], 'B was counted as failed' );
		$this->assert_fired_in( 'aafm_exact_object_chain', 'A reloaded for B' );
		clean_post_cache( $page_b );
		$this->assertSame( "B $token", get_post( $page_b )->post_content, 'B was not written' );
		$parents = array(
			$page_a  => 0,
			$page_b  => $page_a,
			$foreign => 0,
		);
		foreach ( $parents as $id => $expected ) {
			clean_post_cache( $id );
			$this->assertSame( $expected, (int) get_post( $id )->post_parent, "post_parent of $id unchanged" );
		}
	}

	/**
	 * A revision of $parent_id.
	 *
	 * @param int $parent_id Parent post id.
	 */
	private function revision_of( int $parent_id ): WP_Post {
		$id = $this->post(
			array(
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_parent'  => $parent_id,
				'post_content' => 'secret body',
				'post_excerpt' => 'secret excerpt',
			)
		);
		return get_post( $id );
	}

	/**
	 * T8: the revision payload reads its parent's password from an exact load. A parent load that
	 * reads another row redacts, the same as a protected parent.
	 *
	 * @dataProvider data_revision_parent_leaks
	 *
	 * @param bool $foreign_row Leak another post's row (true) or no row (false).
	 */
	public function test_the_revision_payload_redacts_when_its_parent_load_reads_another_row( bool $foreign_row ): void {
		$parent   = $this->post(
			array(
				'post_password' => 'hunter2',
				'post_content'  => 'current body',
			)
		);
		$other    = $this->post();
		$revision = $this->revision_of( $parent );
		get_post( $other );

		$out = $this->armed(
			$this->fault_load( 'post', $parent, $foreign_row ? $other : null ),
			static function () use ( $revision ) {
				return aafm_get_revision_payload(
					$revision,
					array(
						'content_format' => 'raw',
						'with_diff'      => true,
					)
				);
			}
		);

		$this->assertSame( '', $out['content'] );
		$this->assertSame( '', $out['excerpt'] );
		$this->assertNull( $out['diff'] );
		$this->assert_fired_in( 'aafm_get_revision_payload', 'parent load' );
	}

	/**
	 * The two shapes of a faulted parent load.
	 *
	 * @return iterable<string,array{0:bool}>
	 */
	public function data_revision_parent_leaks(): iterable {
		yield 'another row' => array( true );
		yield 'no row' => array( false );
	}

	/**
	 * T8 healthy: an unprotected parent serves the body, the excerpt and the diff against the
	 * parent's content.
	 */
	public function test_the_revision_payload_serves_the_body_when_its_parent_is_not_protected(): void {
		$parent   = $this->post( array( 'post_content' => 'current body' ) );
		$revision = $this->revision_of( $parent );

		$out = aafm_get_revision_payload(
			$revision,
			array(
				'content_format' => 'raw',
				'with_diff'      => true,
			)
		);

		$this->assertSame( 'secret body', $out['content'] );
		$this->assertSame( 'secret excerpt', $out['excerpt'] );
		$this->assertSame( (string) wp_text_diff( 'secret body', 'current body' ), $out['diff'] );
	}

	/**
	 * Delete-menu-item reports an error when a hook vetoed the delete and the re-read of the
	 * still-present item loads another row: the item is gone only when its row is certainly
	 * absent.
	 */
	public function test_delete_menu_item_reports_an_error_when_a_vetoed_delete_is_reread_under_a_fault(): void {
		$this->acting_as( 'administrator' );
		$menu  = $this->menu( 'Vetoed delete' );
		$item  = $this->made_item(
			array(
				'menu_id' => $menu,
				'title'   => 'Kept',
				'url'     => home_url( '/kept' ),
			)
		);
		$other = $this->post();
		// The first SELECT for the item is the function's own exact load; the second, after the
		// veto drops the cached entry, is the re-read.
		$filter = $this->fault_load( 'post', $item, $other, 2 );
		$veto   = static function ( $check, $post ) use ( $item ) {
			if ( $post instanceof WP_Post && $item === (int) $post->ID ) {
				wp_cache_delete( $item, 'posts' );
				return false;
			}
			return $check;
		};
		add_filter( 'pre_delete_post', $veto, 10, 2 );
		try {
			$out = $this->armed( $filter, static fn() => aafm_exec_delete_menu_item( array( 'item_id' => $item ) ) );
		} finally {
			remove_filter( 'pre_delete_post', $veto, 10 );
		}

		$this->assert_fired_in( 'aafm_exact_object', 'vetoed delete re-read' );
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		clean_post_cache( $item );
		$this->assertInstanceOf( WP_Post::class, get_post( $item ), 'the vetoed item is still there' );
	}

	/**
	 * List-menu-items keeps an item whose live target's gone check loads another row: the target
	 * is gone only when its row is certainly absent.
	 */
	public function test_list_menu_items_keeps_a_live_target_whose_gone_check_is_faulted(): void {
		$this->acting_as( 'administrator' );
		$menu   = $this->menu( 'Gone check' );
		$page   = $this->post( array( 'post_type' => 'page' ) );
		$item   = $this->made_item(
			array(
				'menu_id'   => $menu,
				'title'     => 'Live target',
				'type'      => 'post_type',
				'object'    => 'page',
				'object_id' => $page,
			)
		);
		$other  = $this->post();
		$listed = static fn( array $out ): array => array_map( static fn( array $row ): int => $row['id'], $out['items'] );
		$this->assertSame( array( $item ), $listed( aafm_exec_list_menu_items( array( 'menu_id' => $menu ) ) ), 'healthy' );

		$out = $this->armed( $this->fault_load( 'post', $page, $other ), static fn() => aafm_exec_list_menu_items( array( 'menu_id' => $menu ) ) );

		$this->assert_fired_in( 'aafm_menu_item_target_is_gone', 'gone check' );
		$this->assertSame( array( $item ), $listed( $out ) );
	}
}
