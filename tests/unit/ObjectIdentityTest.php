<?php
/**
 * The exact object loader returns an object only when its own id is the one requested.
 *
 * When a `query` filter empties a loader's SELECT, wpdb returns before it flushes, so core
 * reads the previous query's row and wraps it as the requested type. Each case below leaves
 * object B's row in `last_result`, clears A's cache, then fails A's own load once.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Comment;
use WP_Post;
use WP_Term;
use WP_User;

final class ObjectIdentityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
	}

	public function test_the_helper_exists(): void {
		$this->assertTrue( function_exists( 'aafm_exact_object' ) );
	}

	/**
	 * The four object types the loader handles.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function types(): array {
		return array(
			'post'    => array( 'post' ),
			'term'    => array( 'term' ),
			'user'    => array( 'user' ),
			'comment' => array( 'comment' ),
		);
	}

	/**
	 * A healthy load returns the object itself.
	 *
	 * @dataProvider types
	 *
	 * @param string $type Object type.
	 */
	public function test_a_healthy_load_returns_the_object( string $type ): void {
		list( $a ) = $this->make_pair( $type );

		$object = aafm_exact_object( $type, $a );

		$this->assertInstanceOf( $this->class_of( $type ), $object );
		$this->assertSame( $a, $this->id_of( $object ) );
	}

	/**
	 * A load that reads another object's row returns null.
	 *
	 * @dataProvider types
	 *
	 * @param string $type Object type.
	 */
	public function test_a_load_that_reads_another_objects_row_returns_null( string $type ): void {
		list( $a, $b ) = $this->make_pair( $type );

		$result = $this->load_with_b_left_behind( $type, $a, $b, 'no_flush' );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertNull( $result );
	}

	/**
	 * A load that fails with a real SQL error returns null.
	 *
	 * @dataProvider types
	 *
	 * @param string $type Object type.
	 */
	public function test_a_load_that_fails_with_a_real_error_returns_null( string $type ): void {
		list( $a, $b ) = $this->make_pair( $type );

		$result = $this->load_with_b_left_behind( $type, $a, $b, 'real_error' );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertNull( $result );
	}

	public function test_a_term_mismatch_drops_the_entry_core_cached_under_the_requested_id(): void {
		list( $a, $b ) = $this->make_pair( 'term' );

		$result = $this->load_with_b_left_behind( 'term', $a, $b, 'no_flush' );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertNull( $result );
		$this->assertFalse( wp_cache_get( $a, 'terms' ) );
		$reloaded = get_term( $a );
		$this->assertInstanceOf( WP_Term::class, $reloaded );
		$this->assertSame( $a, (int) $reloaded->term_id );
	}

	public function test_a_term_loads_under_its_own_taxonomy_only(): void {
		$a = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$own = aafm_exact_object( 'term', $a, 'category' );

		$this->assertInstanceOf( WP_Term::class, $own );
		$this->assertSame( $a, (int) $own->term_id );
		$this->assertNull( aafm_exact_object( 'term', $a, 'post_tag' ) );
	}

	public function test_a_term_load_with_a_taxonomy_that_reads_another_row_returns_null_and_caches_nothing_under_the_id(): void {
		list( $a, $b ) = $this->make_pair( 'term' );

		$result = $this->load_with_b_left_behind( 'term', $a, $b, 'no_flush', 'category' );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertNull( $result );
		$this->assertFalse( wp_cache_get( $a, 'terms' ) );
	}

	public function test_an_unknown_type_returns_null(): void {
		$post_id = self::factory()->post->create();

		$this->assertNull( aafm_exact_object( 'attachment', $post_id ) );
	}

	/**
	 * Two objects of one type: A is the one asked for, B the one whose row is left behind.
	 *
	 * @param string $type Object type.
	 * @return int[]
	 */
	private function make_pair( string $type ): array {
		switch ( $type ) {
			case 'post':
				return array( self::factory()->post->create(), self::factory()->post->create() );
			case 'term':
				return array(
					self::factory()->term->create( array( 'taxonomy' => 'category' ) ),
					self::factory()->term->create( array( 'taxonomy' => 'category' ) ),
				);
			case 'user':
				return array( self::factory()->user->create(), self::factory()->user->create() );
			default:
				$post_id = self::factory()->post->create();
				return array(
					self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) ),
					self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) ),
				);
		}
	}

	/**
	 * Read B's row straight from the database, so it is the last result, clear A's cache, then
	 * load A with its own SELECT failed once in the named shape.
	 *
	 * @param string $type     Object type.
	 * @param int    $a        Requested id.
	 * @param int    $b        Id whose row is left in `last_result`.
	 * @param string $shape    'no_flush' or 'real_error'.
	 * @param string $taxonomy Taxonomy for a term load.
	 * @return mixed
	 */
	private function load_with_b_left_behind( string $type, int $a, int $b, string $shape, string $taxonomy = '' ) {
		global $wpdb;
		$sql   = array(
			'post'    => "SELECT * FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
			'term'    => "SELECT t.*, tt.* FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id WHERE t.term_id = %d",
			'user'    => "SELECT * FROM {$wpdb->users} WHERE ID = %s LIMIT 1",
			'comment' => "SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d LIMIT 1",
		)[ $type ];
		$group = array(
			'post'    => 'posts',
			'term'    => 'terms',
			'user'    => 'users',
			'comment' => 'comment',
		)[ $type ];

		wp_cache_delete( $a, $group );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only table names and a placeholder.
		$wpdb->get_results( $wpdb->prepare( $sql, $b ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- as above.
		$needle = $wpdb->prepare( $sql, $a );
		$load   = static function () use ( $type, $a, $taxonomy ) {
			return aafm_exact_object( $type, $a, $taxonomy );
		};

		if ( 'no_flush' === $shape ) {
			return QueryFaultInjector::fail_nth_query( $needle, 1, $load, true );
		}
		return QueryFaultInjector::break_query_with_real_error( $needle, $load, 1, true );
	}

	/**
	 * The class a load of this type returns.
	 *
	 * @param string $type Object type.
	 * @return string
	 */
	private function class_of( string $type ): string {
		return array(
			'post'    => WP_Post::class,
			'term'    => WP_Term::class,
			'user'    => WP_User::class,
			'comment' => WP_Comment::class,
		)[ $type ];
	}

	/**
	 * The loaded object's own id.
	 *
	 * @param object $loaded A loaded object.
	 * @return int
	 */
	private function id_of( $loaded ): int {
		if ( $loaded instanceof WP_Term ) {
			return (int) $loaded->term_id;
		}
		if ( $loaded instanceof WP_Comment ) {
			return (int) $loaded->comment_ID;
		}
		return (int) $loaded->ID;
	}
}
