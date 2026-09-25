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
}
