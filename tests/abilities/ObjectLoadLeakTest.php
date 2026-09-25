<?php
/**
 * The integration abilities load their objects by id, not by class: when a load of object A
 * reads object B's row (a `query` filter emptied A's SELECT, so wpdb returns B's rows from the
 * query before), the ability refuses and nothing of B reaches the caller.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use Tribe__Events__Main;
use WP_Error;

final class ObjectLoadLeakTest extends TestCase {

	use IntegrationStubs;

	private const LEAKED_TITLE = 'Leaked private B';

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
		$this->stub_tec();
		add_filter( 'aafm_integration_active_tec', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_tec', '__return_true' );
		remove_filter( 'aafm_integration_active_slim_seo', '__return_true' );
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Both fault shapes.
	 *
	 * @return iterable<string,array{0:string}>
	 */
	public function data_fault_shapes(): iterable {
		yield 'no-flush' => array( 'no-flush' );
		yield 'real-error' => array( 'real-error' );
	}

	/**
	 * The `query` filter that fails the next load of object $a: in the no-flush shape it leaves
	 * object $b's row behind for that load to read, in the real-error shape it breaks the load.
	 * Object $a's cache entry is dropped first, so the load reaches the database.
	 *
	 * @param string $type  'post', 'term' or 'comment'.
	 * @param int    $a     The object the ability asks for.
	 * @param int    $b     The object whose row is left behind.
	 * @param string $shape 'no-flush' or 'real-error'.
	 * @return callable
	 */
	private function fault_for( string $type, int $a, int $b, string $shape ): callable {
		global $wpdb;
		$sql = array(
			'post'    => "SELECT * FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
			'term'    => "SELECT t.*, tt.* FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id WHERE t.term_id = %d",
			'comment' => "SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d LIMIT 1",
		)[ $type ];
		wp_cache_delete(
			$a,
			array(
				'post'    => 'posts',
				'term'    => 'terms',
				'comment' => 'comment',
			)[ $type ]
		);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only table names and a placeholder.
		$needle = $wpdb->prepare( $sql, $a );
		$leak   = $wpdb->prepare( $sql, $b );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return 'no-flush' === $shape
			? QueryFaultInjector::leak_row_filter( $needle, $leak, 1, true )
			: QueryFaultInjector::real_error_filter( $needle, 1, true );
	}

	/**
	 * Run $run with the next load of object $a failed now.
	 *
	 * @param string   $type  Object type.
	 * @param int      $a     Requested id.
	 * @param int      $b     Leaked id.
	 * @param string   $shape Fault shape.
	 * @param callable $run   The call.
	 * @return mixed
	 */
	private function with_leak( string $type, int $a, int $b, string $shape, callable $run ) {
		global $wpdb;
		$filter     = $this->fault_for( $type, $a, $b, $shape );
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
	 * Run $run and fail the next post load of the object a write of $kind just finished, so the
	 * failed load is the ability's own read after its write.
	 *
	 * @param string   $kind  The write_outcome kind to wait for.
	 * @param int      $b     Leaked id.
	 * @param string   $shape Fault shape.
	 * @param callable $run   The call.
	 * @return mixed
	 */
	private function with_leak_after_write( string $kind, int $b, string $shape, callable $run ) {
		global $wpdb;
		$armed = null;
		$arm   = function ( $result, $target ) use ( $kind, $b, $shape, &$armed ): void {
			if ( null !== $armed || ! isset( $target['kind'] ) || $target['kind'] !== $kind || empty( $target['object_id'] ) ) {
				return;
			}
			$armed = $this->fault_for( 'post', (int) $target['object_id'], $b, $shape );
			add_filter( 'query', $armed );
		};
		add_action( 'aafm_write_completed', $arm, 10, 2 );
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		try {
			return $run();
		} finally {
			ob_end_clean();
			remove_action( 'aafm_write_completed', $arm, 10 );
			if ( null !== $armed ) {
				remove_filter( 'query', $armed );
			}
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * The call returned the generic error and carries nothing of the leaked object.
	 *
	 * @param mixed  $out   The ability's return.
	 * @param string $label Case label.
	 */
	private function assert_refused_without_leak( $out, string $label ): void {
		$this->assertSame( 1, QueryFaultInjector::fired_count(), "$label: the load was faulted" );
		$this->assertInstanceOf( WP_Error::class, $out, $label );
		$this->assertSame( 'aafm_error', $out->get_error_code(), $label );
		$this->assertStringNotContainsString( self::LEAKED_TITLE, (string) wp_json_encode( array( $out->get_error_messages(), $out->get_error_data() ) ), $label );
	}

	/**
	 * A private post of the given type whose row is the one left behind.
	 *
	 * @param string $post_type Post type.
	 * @param string $status    Post status.
	 */
	private function leaked_post( string $post_type, string $status = 'private' ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => $post_type,
				'post_status'  => $status,
				'post_title'   => self::LEAKED_TITLE,
				'post_content' => 'Leaked B content',
			)
		);
	}

	/**
	 * Event create, update and a no-change update each refuse when their read of the event
	 * returns another event's row.
	 *
	 * @dataProvider data_fault_shapes
	 * @param string $shape Fault shape.
	 */
	public function test_an_event_write_whose_event_read_returns_another_row_is_refused( string $shape ): void {
		$this->acting_as( 'administrator' );
		$b     = $this->leaked_post( Tribe__Events__Main::POSTTYPE );
		$event = aafm_exec_tec_create_event( array( 'title' => 'Event A' ) );
		$this->assertIsArray( $event );
		$a = (int) $event['event']['id'];

		$out = $this->with_leak_after_write( 'tec', $b, $shape, static fn() => aafm_exec_tec_create_event( array( 'title' => 'Event C' ) ) );
		$this->assert_refused_without_leak( $out, 'create' );

		QueryFaultInjector::reset_fired_count();
		$out = $this->with_leak_after_write(
			'tec',
			$b,
			$shape,
			static fn() => aafm_exec_tec_update_event(
				array(
					'event_id' => $a,
					'title'    => 'Event A renamed',
				)
			)
		);
		$this->assert_refused_without_leak( $out, 'update' );

		QueryFaultInjector::reset_fired_count();
		$out = $this->with_leak( 'post', $a, $b, $shape, static fn() => aafm_exec_tec_update_event( array( 'event_id' => $a ) ) );
		$this->assert_refused_without_leak( $out, 'no change' );
	}

	public function test_get_event_whose_read_returns_another_events_row_is_refused(): void {
		$this->acting_as( 'administrator' );
		$b = $this->leaked_post( Tribe__Events__Main::POSTTYPE );
		$a = (int) aafm_exec_tec_create_event( array( 'title' => 'Event A' ) )['event']['id'];

		$out = $this->with_leak( 'post', $a, $b, 'no-flush', static fn() => aafm_exec_tec_get_event( array( 'event_id' => $a ) ) );

		$this->assert_refused_without_leak( $out, 'get' );
	}

	/**
	 * Venue and organizer create, update and a no-change update each refuse when their read of
	 * the object returns another row.
	 *
	 * @dataProvider data_fault_shapes
	 * @param string $shape Fault shape.
	 */
	public function test_venue_and_organizer_writes_whose_read_returns_another_row_are_refused( string $shape ): void {
		$this->acting_as( 'administrator' );
		$entities = array(
			'venue'     => array( 'tribe_venue', 'aafm_exec_tec_create_venue', 'aafm_exec_tec_update_venue', 'venue_id' ),
			'organizer' => array( 'tribe_organizer', 'aafm_exec_tec_create_organizer', 'aafm_exec_tec_update_organizer', 'organizer_id' ),
		);
		foreach ( $entities as $entity => list( $post_type, $create, $update, $id_key ) ) {
			$b       = $this->leaked_post( $post_type );
			$created = $create( array( 'title' => "A $entity" ) );
			$this->assertIsArray( $created, $entity );
			$a = (int) $created[ $entity ]['id'];

			QueryFaultInjector::reset_fired_count();
			$out = $this->with_leak_after_write( 'tec', $b, $shape, static fn() => $create( array( 'title' => "C $entity" ) ) );
			$this->assert_refused_without_leak( $out, "create $entity" );

			QueryFaultInjector::reset_fired_count();
			$out = $this->with_leak_after_write(
				'tec',
				$b,
				$shape,
				static fn() => $update(
					array(
						$id_key => $a,
						'title' => "A $entity renamed",
					)
				)
			);
			$this->assert_refused_without_leak( $out, "update $entity" );

			QueryFaultInjector::reset_fired_count();
			$out = $this->with_leak( 'post', $a, $b, $shape, static fn() => $update( array( $id_key => $a ) ) );
			$this->assert_refused_without_leak( $out, "no change $entity" );
		}
	}

	public function test_a_venue_id_whose_read_returns_a_venue_row_is_refused_and_links_nothing(): void {
		$this->acting_as( 'administrator' );
		$b      = $this->leaked_post( 'tribe_venue' );
		$a      = self::factory()->post->create();
		$before = count(
			get_posts(
				array(
					'post_type'   => Tribe__Events__Main::POSTTYPE,
					'post_status' => 'any',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			)
		);

		$out = $this->with_leak(
			'post',
			$a,
			$b,
			'no-flush',
			static fn() => aafm_exec_tec_create_event(
				array(
					'title'    => 'Linked event',
					'venue_id' => $a,
				)
			)
		);

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_tec_invalid_venue', $out->get_error_code() );
		$this->assertCount(
			$before,
			get_posts(
				array(
					'post_type'   => Tribe__Events__Main::POSTTYPE,
					'post_status' => 'any',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			)
		);
	}

	public function test_reading_a_draft_event_is_denied_when_its_read_returns_a_published_event(): void {
		$b = $this->leaked_post( Tribe__Events__Main::POSTTYPE, 'publish' );
		$a = self::factory()->post->create(
			array(
				'post_type'   => Tribe__Events__Main::POSTTYPE,
				'post_status' => 'draft',
			)
		);
		$this->acting_as( 'subscriber' );

		$allowed = $this->with_leak( 'post', $a, $b, 'no-flush', static fn() => aafm_tec_perm_read_event( array( 'event_id' => $a ) ) );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertFalse( $allowed );
	}

	public function test_the_seo_edit_gate_denies_a_post_whose_read_returns_an_editable_post(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$a      = self::factory()->post->create( array( 'post_author' => $other ) );
		$b      = self::factory()->post->create( array( 'post_author' => $author ) );
		wp_set_current_user( $author );

		$allowed = $this->with_leak( 'post', $a, $b, 'no-flush', static fn() => aafm_perm_seo_post_object( array( 'post_id' => $a ) ) );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertFalse( $allowed );
	}

	public function test_seo_updates_whose_post_read_returns_another_row_are_refused_and_write_nothing(): void {
		$this->acting_as( 'administrator' );
		$this->force_integration( 'yoast' );
		$this->force_integration( 'rankmath' );
		$this->force_integration( 'aioseo' );
		$this->stub_yoast();
		$this->stub_rankmath();
		$this->stub_aioseo();
		add_filter( 'aafm_integration_active_slim_seo', '__return_true' );
		$b = $this->leaked_post( 'post' );

		$cases = array(
			'yoast'    => array( 'aafm_exec_yoast_update_post', '_yoast_wpseo_title' ),
			'rankmath' => array( 'aafm_exec_rankmath_update_post', 'rank_math_title' ),
			'slim-seo' => array( 'aafm_exec_slim_seo_update_post', 'slim_seo' ),
			'aioseo'   => array( 'aafm_exec_aioseo_update_post', null ),
		);
		foreach ( $cases as $label => list( $update, $key ) ) {
			$a = self::factory()->post->create();
			QueryFaultInjector::reset_fired_count();
			$out = $this->with_leak(
				'post',
				$a,
				$b,
				'no-flush',
				static fn() => $update(
					array(
						'post_id' => $a,
						'title'   => 'New title',
					)
				)
			);
			$this->assert_refused_without_leak( $out, $label );
			if ( null !== $key ) {
				$this->assertSame( array(), get_post_meta( $a, $key, false ), "$label: nothing written" );
			}
		}
	}

	public function test_acf_term_update_whose_term_read_returns_another_term_is_refused_and_caches_nothing_foreign(): void {
		$this->acting_as( 'administrator' );
		$a = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$b = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$out = $this->with_leak(
			'term',
			$a,
			$b,
			'no-flush',
			static fn() => aafm_exec_acf_update_term_fields(
				array(
					'term_id' => $a,
					'fields'  => array( 'field_1' => 'x' ),
				)
			)
		);

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertFalse( wp_cache_get( $a, 'terms' ) );
	}

	public function test_a_reply_whose_parent_read_returns_another_comment_is_refused(): void {
		$this->acting_as( 'administrator' );
		$post_id = self::factory()->post->create();
		$a       = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$b       = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => self::LEAKED_TITLE,
			)
		);
		$before  = (int) get_comments(
			array(
				'post_id' => $post_id,
				'count'   => true,
				'status'  => 'all',
			)
		);

		$out = $this->with_leak(
			'comment',
			$a,
			$b,
			'no-flush',
			static fn() => aafm_exec_create_comment(
				array(
					'post_id' => $post_id,
					'parent'  => $a,
					'content' => 'A reply',
				)
			)
		);

		$this->assert_refused_without_leak( $out, 'reply' );
		$this->assertSame(
			$before,
			(int) get_comments(
				array(
					'post_id' => $post_id,
					'count'   => true,
					'status'  => 'all',
				)
			)
		);
	}

	public function test_geodirectory_create_and_update_whose_final_read_returns_another_row_are_refused(): void {
		aafm_geodir_stub_activate();
		add_filter( 'aafm_integration_active_geodirectory', '__return_true' );
		$this->acting_as( 'administrator' );
		$b = $this->leaked_post( 'gd_place' );

		$out = $this->with_leak_after_write(
			'geodirectory',
			$b,
			'no-flush',
			static fn() => aafm_exec_geodirectory_create_listing(
				array(
					'title'   => 'Cafe',
					'content' => 'A place.',
					'street'  => '1 Main St',
				)
			)
		);
		$this->assert_refused_without_leak( $out, 'create' );
		$this->assertStringNotContainsString( 'Leaked B content', (string) wp_json_encode( $out->get_error_data() ) );

		$created = aafm_exec_geodirectory_create_listing(
			array(
				'title'   => 'Bakery',
				'content' => 'Bread.',
				'street'  => '2 Main St',
			)
		);
		$this->assertIsArray( $created );

		QueryFaultInjector::reset_fired_count();
		$out = $this->with_leak_after_write(
			'geodirectory',
			$b,
			'no-flush',
			static fn() => aafm_exec_geodirectory_update_listing(
				array(
					'listing_id' => (int) $created['listing_id'],
					'street'     => '3 Main St',
				)
			)
		);
		$this->assert_refused_without_leak( $out, 'update' );
		remove_filter( 'aafm_integration_active_geodirectory', '__return_true' );
	}
}
