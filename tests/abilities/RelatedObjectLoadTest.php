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
}
