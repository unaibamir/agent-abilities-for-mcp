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

	private const USER_META_KEY = 'cap_probe_note';

	private const TERM_META_KEY = 'cap_probe_note';

	private const TERM_FLAG = 'cap_probe_locked';

	private const MISSING = 987654;

	/**
	 * For each faulted query, in order: whether the plugin's checked-read scope was open on the
	 * metadata type it loads.
	 *
	 * @var bool[]
	 */
	private array $fired_in_scope = array();

	/**
	 * Run $run with every query matching $needle replaced by $leak_query's rows, errors suppressed
	 * and output discarded. Records in $fired_in_scope, at each match, whether a callback sits on
	 * `update_{$meta_type}_metadata_cache` at PHP_INT_MAX, where aafm_with_checked_reads() hooks.
	 *
	 * @param string[] $needle     Substrings the query must hold.
	 * @param string   $leak_query The query whose rows the faulted one reads.
	 * @param callable $run        The call.
	 * @param string   $meta_type  Metadata type whose scope hook is recorded.
	 * @return mixed
	 */
	private function with_query_faulted( array $needle, string $leak_query, callable $run, string $meta_type = 'user' ) {
		global $wpdb, $wp_filter;
		$this->fired_in_scope = array();
		$hook                 = "update_{$meta_type}_metadata_cache";
		$recorder             = function ( string $query ) use ( $needle, $hook, &$wp_filter ): string {
			foreach ( $needle as $part ) {
				if ( false === strpos( $query, $part ) ) {
					return $query;
				}
			}
			$this->fired_in_scope[] = isset( $wp_filter[ $hook ] ) && isset( $wp_filter[ $hook ]->callbacks[ PHP_INT_MAX ] );
			return $query;
		};
		$filter               = QueryFaultInjector::leak_row_filter( $needle, $leak_query, 0 );
		$suppressed           = $wpdb->suppress_errors( true );
		add_filter( 'query', $recorder, 1 );
		add_filter( 'query', $filter );
		ob_start();
		try {
			return $run();
		} finally {
			ob_end_clean();
			remove_filter( 'query', $filter );
			remove_filter( 'query', $recorder, 1 );
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Run $run with $user_id's usermeta load answered with no rows, from the start of the call.
	 *
	 * @param int      $user_id User whose meta load is faulted.
	 * @param callable $run     The call.
	 * @return mixed
	 */
	private function with_user_meta_load_faulted( int $user_id, callable $run ) {
		global $wpdb;
		wp_cache_delete( $user_id, 'user_meta' );
		return $this->with_query_faulted(
			array( 'SELECT user_id, meta_key, meta_value FROM', $wpdb->usermeta, "WHERE user_id IN ({$user_id})" ),
			sprintf( 'SELECT * FROM %s WHERE 1 = 0', $wpdb->usermeta ),
			$run
		);
	}

	/**
	 * Act as a user who manages users and the store but is not an administrator, like
	 * WooCommerce's shop manager.
	 */
	private function acting_as_user_manager(): int {
		$user_id = $this->acting_as( 'editor' );
		foreach ( array( 'list_users', 'edit_users', 'promote_users', 'delete_users', 'manage_woocommerce' ) as $cap ) {
			wp_get_current_user()->add_cap( $cap );
		}
		return $user_id;
	}

	/**
	 * Deny edit_user, remove_user, promote_user and delete_user on an administrator, deciding from
	 * the target's roles the way WooCommerce's wc_modify_map_meta_cap() does.
	 */
	private function deny_user_edits_on_administrators(): void {
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ): array {
				if ( ! in_array( $cap, array( 'edit_user', 'remove_user', 'promote_user', 'delete_user' ), true ) || ! isset( $args[0] ) || (int) $args[0] === $user_id ) {
					return $caps;
				}
				$target = get_userdata( (int) $args[0] );
				if ( $target instanceof \WP_User && in_array( 'administrator', (array) $target->roles, true ) ) {
					$caps[] = 'do_not_allow';
				}
				return $caps;
			},
			10,
			4
		);
	}

	private function allow_user_meta_key(): void {
		add_filter( 'aafm_allowed_user_meta_keys', static fn(): array => array( self::USER_META_KEY ) );
	}

	/**
	 * The stored roles of $user_id, read from the database.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	private function stored_roles( int $user_id ): array {
		clean_user_cache( $user_id );
		$user = get_userdata( $user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		return array_values( (array) $user->roles );
	}

	/**
	 * Each permission path that checks a capability on a target user, as a callable that says
	 * whether the call was allowed. The role change asks for 'editor'.
	 *
	 * @return iterable<string,array{0:callable}>
	 */
	public function data_user_capability_sites(): iterable {
		yield 'update-user' => array( static fn( int $id ): bool => aafm_perm_update_user( array( 'user_id' => $id ) ) );
		yield 'update-user role change' => array(
			static fn( int $id ): bool => ! is_wp_error(
				aafm_exec_update_user(
					array(
						'user_id' => $id,
						'role'    => 'editor',
					)
				)
			),
		);
		yield 'delete-user' => array( static fn( int $id ): bool => aafm_perm_delete_user( array( 'user_id' => $id ) ) );
		yield 'user meta' => array(
			static fn( int $id ): bool => aafm_can_access_user_meta(
				array(
					'user_id' => $id,
					'key'     => self::USER_META_KEY,
				)
			),
		);
		yield 'wc-update-customer' => array( static fn( int $id ): bool => aafm_perm_wc_update_customer( array( 'customer_id' => $id ) ) );
		yield 'acf user fields' => array( static fn( int $id ): bool => aafm_perm_acf_user( array( 'user_id' => $id ) ) );
	}

	/**
	 * The answer each permission path gave before its capability call was checked: the same
	 * expression with raw current_user_can() calls.
	 *
	 * @return array<string,callable>
	 */
	private function unchecked_user_answers(): array {
		$floor = static fn( string $cap, int $id ): bool => $id > 0 && current_user_can( $cap . 's' ) && current_user_can( $cap, $id );
		return array(
			'update-user'        => static fn( int $id ): bool => $floor( 'edit_user', $id ),
			'delete-user'        => static fn( int $id ): bool => $floor( 'delete_user', $id ),
			'user meta'          => static fn( int $id ): bool => $floor( 'edit_user', $id ) && ! is_wp_error( aafm_validate_user_meta_key( self::USER_META_KEY ) ),
			'wc-update-customer' => static fn( int $id ): bool => aafm_wc_perm() && $floor( 'edit_user', $id ),
			'acf user fields'    => static fn( int $id ): bool => aafm_exact_object( 'user', $id ) instanceof \WP_User && $floor( 'edit_user', $id ),
		);
	}

	/**
	 * A stock shop manager holds edit_users, and WooCommerce refuses edits on an administrator
	 * only after reading the target's roles. When that read fails, the roles read as empty, so
	 * every user capability check loads the target inside its checked scope and refuses.
	 *
	 * @dataProvider data_user_capability_sites
	 *
	 * @param callable $site The permission path.
	 */
	public function test_a_user_capability_check_refuses_when_the_targets_metadata_does_not_load( callable $site ): void {
		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->acting_as_user_manager();
		$this->deny_user_edits_on_administrators();
		$this->allow_user_meta_key();
		$this->assertFalse( $site( $admin ), 'healthy: the target is an administrator' );

		$faulted = $this->with_user_meta_load_faulted(
			$admin,
			static function () use ( $site, $admin ): bool {
				return $site( $admin );
			}
		);

		$this->assertFalse( $faulted );
		$this->assertNotSame( array(), $this->fired_in_scope, 'the fault fired' );
		$this->assertTrue( $this->fired_in_scope[0], 'the first load of the target ran inside a checked scope' );
		$this->assertSame( array( 'administrator' ), $this->stored_roles( $admin ) );
	}

	/**
	 * The whole update-user ability stops at its permission check when the target's metadata
	 * does not load, and nothing is written.
	 */
	public function test_update_user_is_refused_when_the_targets_metadata_does_not_load(): void {
		$this->register_enabled( array( 'aafm/update-user' ) );

		$admin = (int) self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'owner@example.com',
			)
		);
		$this->acting_as_user_manager();
		$this->deny_user_edits_on_administrators();
		$ability = wp_get_ability( 'aafm/update-user' );
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$input = array(
			'user_id' => $admin,
			'email'   => 'taken@example.com',
		);

		$result = $this->with_user_meta_load_faulted(
			$admin,
			static function () use ( $ability, $input ) {
				return $ability->execute( $input );
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		clean_user_cache( $admin );
		$this->assertSame( 'owner@example.com', get_userdata( $admin )->user_email );
	}

	/**
	 * A target whose users row reads as another user's row is refused, not checked as that user.
	 *
	 * @dataProvider data_user_capability_sites
	 *
	 * @param callable $site The permission path.
	 */
	public function test_a_user_capability_check_refuses_when_the_target_loads_as_another_user( callable $site ): void {
		global $wpdb;
		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->acting_as_user_manager();
		$this->deny_user_edits_on_administrators();
		$this->allow_user_meta_key();
		wp_cache_delete( $admin, 'users' );
		wp_cache_delete( $admin, 'user_meta' );

		$faulted = $this->with_query_faulted(
			array( 'SELECT * FROM', $wpdb->users, "WHERE ID = '{$admin}' LIMIT" ),
			$wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->users, $other ),
			static function () use ( $site, $admin ): bool {
				return $site( $admin );
			}
		);

		$this->assertFalse( $faulted );
		$this->assertNotSame( array(), $this->fired_in_scope, 'the fault fired' );
		$this->assertSame( array( 'administrator' ), $this->stored_roles( $admin ) );
	}

	/**
	 * On a healthy database every user permission path answers as it did with raw capability
	 * calls: a missing id, id 0, the caller's own id, a target the filter denies or allows, and a
	 * user held only in the cache.
	 */
	public function test_user_capability_sites_answer_as_before_on_a_healthy_database(): void {
		global $wpdb;
		$this->allow_user_meta_key();
		$sites     = array_map( 'current', iterator_to_array( $this->data_user_capability_sites() ) );
		$unchecked = $this->unchecked_user_answers();

		$caller = $this->acting_as( 'administrator' );
		foreach ( array( self::MISSING, 0, $caller ) as $id ) {
			foreach ( $unchecked as $name => $before ) {
				$this->assertSame( $before( $id ), $sites[ $name ]( $id ), "$name, administrator caller, id $id" );
			}
		}
		$this->assertTrue( $sites['update-user']( self::MISSING ), 'a missing id keeps the capability floor' );
		$this->assertFalse( $sites['acf user fields']( self::MISSING ) );
		$this->assertFalse( $sites['update-user role change']( self::MISSING ) );
		$this->assertFalse( $sites['update-user role change']( 0 ) );

		$admin  = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$cached = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$caller = $this->acting_as_user_manager();
		$this->deny_user_edits_on_administrators();
		get_userdata( $cached );
		$wpdb->delete( $wpdb->users, array( 'ID' => $cached ) );
		foreach ( array( $admin, $author, $cached, $caller, self::MISSING, 0 ) as $id ) {
			foreach ( $unchecked as $name => $before ) {
				$this->assertSame( $before( $id ), $sites[ $name ]( $id ), "$name, user manager caller, id $id" );
			}
		}
		foreach ( $sites as $name => $site ) {
			$this->assertFalse( $site( $admin ), "$name: the filter denies an administrator target" );
		}
		$this->assertSame( array( 'administrator' ), $this->stored_roles( $admin ) );
		$this->assertTrue( $sites['update-user']( $author ) );
	}

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

	/**
	 * Each permission path that checks edit_term, as a callable that says whether it allowed.
	 *
	 * @return iterable<string,array{0:callable}>
	 */
	public function data_term_capability_sites(): iterable {
		yield 'term meta' => array(
			static fn( int $id ): bool => aafm_perm_can_edit_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $id,
					'meta_key' => self::TERM_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ability input, not a meta query.
				)
			),
		);
		yield 'acf term fields' => array( static fn( int $id ): bool => aafm_perm_acf_term( array( 'term_id' => $id ) ) );
	}

	/**
	 * Act as an editor, with the probe key allowed and edit_term denied on a term that carries the
	 * lock flag in its metadata.
	 */
	private function term_capability_setup(): void {
		$this->acting_as( 'editor' );
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( self::TERM_META_KEY ) );
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ): array {
				if ( 'edit_term' === $cap && isset( $args[0] ) && '' !== (string) get_term_meta( (int) $args[0], self::TERM_FLAG, true ) ) {
					$caps[] = 'do_not_allow';
				}
				return $caps;
			},
			10,
			4
		);
	}

	/**
	 * A map_meta_cap filter may decide edit_term from the term's metadata, so a term capability
	 * check refuses when that metadata does not load.
	 *
	 * @dataProvider data_term_capability_sites
	 *
	 * @param callable $site The permission path.
	 */
	public function test_a_term_capability_check_refuses_when_the_terms_metadata_does_not_load( callable $site ): void {
		global $wpdb;
		$this->term_capability_setup();
		$term = (int) self::factory()->category->create();
		add_term_meta( $term, self::TERM_FLAG, '1' );
		$this->assertFalse( $site( $term ), 'healthy: the term is locked' );
		wp_cache_delete( $term, 'term_meta' );

		$faulted = $this->with_query_faulted(
			array( 'SELECT term_id, meta_key, meta_value FROM', $wpdb->termmeta, "WHERE term_id IN ({$term})" ),
			sprintf( 'SELECT * FROM %s WHERE 1 = 0', $wpdb->termmeta ),
			static function () use ( $site, $term ): bool {
				return $site( $term );
			},
			'term'
		);

		$this->assertFalse( $faulted );
		$this->assertNotSame( array(), $this->fired_in_scope, 'the fault fired' );
		$this->assertTrue( $this->fired_in_scope[0], 'the first load of the term metadata ran inside a checked scope' );
	}

	/**
	 * On a healthy database: an unlocked term is allowed, a locked one refused, a missing id
	 * refused.
	 *
	 * @dataProvider data_term_capability_sites
	 *
	 * @param callable $site The permission path.
	 */
	public function test_term_capability_sites_answer_as_before_on_a_healthy_database( callable $site ): void {
		$this->term_capability_setup();
		$open   = (int) self::factory()->category->create();
		$locked = (int) self::factory()->category->create();
		add_term_meta( $locked, self::TERM_FLAG, '1' );

		$this->assertTrue( $site( $open ) );
		$this->assertFalse( $site( $locked ) );
		$this->assertFalse( $site( self::MISSING ) );
	}

	private const POST_FLAG = 'cap_probe_locked';

	private const SEARCH = 'cap-probe-needle';

	/**
	 * Act as an editor, with edit_post denied on a post that carries the lock flag in its
	 * metadata.
	 */
	private function post_lock_setup(): void {
		$this->acting_as( 'editor' );
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ): array {
				if ( 'edit_post' === $cap && isset( $args[0] ) && '' !== (string) get_post_meta( (int) $args[0], self::POST_FLAG, true ) ) {
					$caps[] = 'do_not_allow';
				}
				return $caps;
			},
			10,
			4
		);
	}

	/**
	 * A replace-sitewide dry run over published posts: its matched, failed and permission counts.
	 *
	 * @return array{matched:int,failed:int,no_permission:int}
	 */
	private function replace_sitewide_counts(): array {
		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => self::SEARCH,
				'replace' => 'replaced',
				'dry_run' => true,
			)
		);
		$this->assertIsArray( $out );
		return array(
			'matched'       => $out['matched_posts'],
			'failed'        => $out['failed_updates'],
			'no_permission' => $out['skipped_no_permission'],
		);
	}

	/**
	 * The capability check on this candidate could not load the post's metadata, so it decided
	 * nothing. The candidate counts as a failed update rather than a permission skip.
	 */
	public function test_replace_sitewide_counts_a_candidate_whose_capability_check_did_not_load_as_failed(): void {
		$this->post_lock_setup();
		$post = (int) self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'before ' . self::SEARCH . ' after',
			)
		);
		add_post_meta( $post, self::POST_FLAG, '1' );

		$faulted = $this->with_meta_load_faulted(
			$post,
			function (): array {
				return $this->replace_sitewide_counts();
			}
		);

		$this->assertSame(
			array(
				'matched'       => 0,
				'failed'        => 1,
				'no_permission' => 0,
			),
			$faulted
		);
		$this->assertGreaterThanOrEqual( 1, QueryFaultInjector::fired_count() );
	}

	/**
	 * On a healthy database a denied candidate is a permission skip and an allowed one matches.
	 */
	public function test_replace_sitewide_counts_a_healthy_denial_as_a_permission_skip(): void {
		$this->post_lock_setup();
		$locked = (int) self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'before ' . self::SEARCH . ' after',
			)
		);
		add_post_meta( $locked, self::POST_FLAG, '1' );
		$this->assertSame(
			array(
				'matched'       => 0,
				'failed'        => 0,
				'no_permission' => 1,
			),
			$this->replace_sitewide_counts()
		);

		delete_post_meta( $locked, self::POST_FLAG );
		$this->assertSame(
			array(
				'matched'       => 1,
				'failed'        => 0,
				'no_permission' => 0,
			),
			$this->replace_sitewide_counts()
		);
	}

	private const POST_NOTE = 'cap_probe_note';

	private const LEAKED = 'value-of-another-post';

	/**
	 * Grant edit_post on a post that carries the flag in its metadata and deny it otherwise, with
	 * the note key allowed in the meta block.
	 */
	private function grant_edit_post_from_flag(): void {
		add_filter( 'aafm_allowed_meta_keys', static fn(): array => array( self::POST_NOTE ) );
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ): array {
				if ( 'edit_post' !== $cap || ! isset( $args[0] ) ) {
					return $caps;
				}
				return '' !== (string) get_post_meta( (int) $args[0], self::POST_FLAG, true ) ? array( 'exist' ) : array_merge( $caps, array( 'do_not_allow' ) );
			},
			10,
			4
		);
	}

	/**
	 * A published post by another author carrying the flag and a note, whose metadata rows a
	 * faulted load of $target's metadata hands back as $target's.
	 */
	private function flagged_donor_post(): int {
		$donor = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_post_meta( $donor, self::POST_FLAG, '1' );
		add_post_meta( $donor, self::POST_NOTE, self::LEAKED );
		return $donor;
	}

	/**
	 * Run $run with every metadata load of $target answered with $donor's rows under $target's id.
	 *
	 * @param int      $target Post whose metadata load is faulted.
	 * @param int      $donor  Post whose rows the load reads.
	 * @param callable $run    The call.
	 * @return mixed
	 */
	private function with_post_meta_leaked( int $target, int $donor, callable $run ) {
		global $wpdb;
		wp_cache_delete( $target, 'post_meta' );
		return $this->with_query_faulted(
			array( 'SELECT post_id, meta_key, meta_value FROM', $wpdb->postmeta, "WHERE post_id IN ({$target})" ),
			$wpdb->prepare( 'SELECT %d AS post_id, meta_key, meta_value FROM %i WHERE post_id = %d', $target, $wpdb->postmeta, $donor ),
			$run,
			'post'
		);
	}

	/**
	 * The post metadata loads $run makes, counted by the load query's shape.
	 *
	 * @param callable $run The call.
	 */
	private function post_meta_loads( callable $run ): int {
		global $wpdb;
		$count  = 0;
		$filter = static function ( string $query ) use ( &$count, $wpdb ): string {
			if ( false !== strpos( $query, 'SELECT post_id, meta_key, meta_value FROM' ) && false !== strpos( $query, $wpdb->postmeta ) ) {
				++$count;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$run();
		} finally {
			remove_filter( 'query', $filter );
		}
		return $count;
	}

	/**
	 * A post's meta block is shown only to a caller who can edit it. The rich shape checks that
	 * before it reads any of the post's metadata, so a first load that hands back another post's
	 * rows is refused instead of trusted.
	 */
	public function test_get_post_meta_block_is_not_granted_from_another_posts_leaked_rows(): void {
		$this->acting_as( 'editor' );
		$this->grant_edit_post_from_flag();
		$post  = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$donor = $this->flagged_donor_post();

		$out = $this->with_post_meta_leaked(
			$post,
			$donor,
			static function () use ( $post ) {
				return aafm_exec_get_post( array( 'post_id' => $post ) );
			}
		);

		$this->assertIsArray( $out );
		$this->assertEquals( (object) array(), $out['post']['meta'] );
		$this->assertStringNotContainsString( self::LEAKED, (string) wp_json_encode( $out ) );
		$this->assertTrue( $this->fired_in_scope[0] ?? false, 'the first load of the post metadata ran inside a checked scope' );
	}

	/**
	 * Each list read that checks the meta-block capability per post, as a callable returning the
	 * rows it shaped.
	 *
	 * @return iterable<string,array{0:callable}>
	 */
	public function data_rich_post_list_reads(): iterable {
		yield 'get-posts' => array( static fn(): array => aafm_exec_get_posts( array() )['posts'] );
		yield 'search-content' => array( static fn(): array => aafm_exec_search_content( array( 'search' => self::SEARCH ) )['results'] );
	}

	/**
	 * A list read primes the page's post metadata inside a checked scope, so a priming load that
	 * hands back another post's rows is not trusted by the meta-block check.
	 *
	 * @dataProvider data_rich_post_list_reads
	 *
	 * @param callable $read The list read.
	 */
	public function test_a_list_reads_meta_block_is_not_granted_from_a_leaked_priming_load( callable $read ): void {
		$this->acting_as( 'editor' );
		$this->grant_edit_post_from_flag();
		$post  = (int) self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => self::SEARCH,
			)
		);
		$donor = $this->flagged_donor_post();
		wp_update_post(
			array(
				'ID'          => $donor,
				'post_status' => 'draft',
			)
		);

		$rows = $this->with_post_meta_leaked( $post, $donor, $read );

		$this->assertCount( 1, $rows );
		$this->assertSame( $post, $rows[0]['id'] );
		$this->assertEquals( (object) array(), $rows[0]['meta'] );
		$this->assertStringNotContainsString( self::LEAKED, (string) wp_json_encode( $rows ) );
		$this->assertTrue( $this->fired_in_scope[0] ?? false, 'the priming load ran inside a checked scope' );
	}

	/**
	 * On a healthy database a list read shows the meta block exactly where the caller can edit,
	 * with one metadata load for the page.
	 *
	 * @dataProvider data_rich_post_list_reads
	 *
	 * @param callable $read The list read.
	 */
	public function test_a_list_read_answers_as_before_on_a_healthy_database( callable $read ): void {
		$this->acting_as( 'editor' );
		$this->grant_edit_post_from_flag();
		$open   = (int) self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => self::SEARCH,
				'post_date'    => '2020-01-01 00:00:00',
			)
		);
		$editor = (int) self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => self::SEARCH,
				'post_date'    => '2020-01-02 00:00:00',
			)
		);
		add_post_meta( $editor, self::POST_FLAG, '1' );
		add_post_meta( $editor, self::POST_NOTE, 'editable note' );
		add_post_meta( $open, self::POST_NOTE, 'hidden note' );
		wp_cache_delete( $open, 'post_meta' );
		wp_cache_delete( $editor, 'post_meta' );

		$rows  = array();
		$loads = $this->post_meta_loads(
			static function () use ( $read, &$rows ): void {
				$rows = $read();
			}
		);

		$this->assertSame( array( $editor, $open ), array_column( $rows, 'id' ) );
		$this->assertSame( array( self::POST_NOTE => 'editable note' ), $rows[0]['meta'] );
		$this->assertEquals( (object) array(), $rows[1]['meta'] );
		$this->assertSame( 1, $loads, 'one metadata load for the page' );
	}

	/**
	 * The list-blocks read shows only blocks the caller can edit. It primes the page's metadata
	 * inside a checked scope, so a priming load that hands back another post's rows grants nothing.
	 */
	public function test_list_blocks_does_not_list_a_block_from_a_leaked_priming_load(): void {
		$this->acting_as( 'contributor' );
		$this->grant_edit_post_from_flag();
		$other = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$block = (int) self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
				'post_author' => $other,
			)
		);
		$donor = $this->flagged_donor_post();
		$this->assertSame( array(), aafm_exec_list_blocks( array() )['blocks'], 'healthy: another author\'s block is not editable' );

		$out = $this->with_post_meta_leaked(
			$block,
			$donor,
			static fn(): array => aafm_exec_list_blocks( array() )
		);

		$this->assertSame( array(), $out['blocks'] );
		$this->assertTrue( $this->fired_in_scope[0] ?? false, 'the priming load ran inside a checked scope' );
	}

	/**
	 * On a healthy database list-blocks lists the editable block, with one metadata load.
	 */
	public function test_list_blocks_answers_as_before_on_a_healthy_database(): void {
		$this->acting_as( 'contributor' );
		$this->grant_edit_post_from_flag();
		$other  = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$locked = (int) self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
				'post_author' => $other,
			)
		);
		$open   = (int) self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
				'post_author' => $other,
			)
		);
		add_post_meta( $open, self::POST_FLAG, '1' );
		wp_cache_delete( $locked, 'post_meta' );
		wp_cache_delete( $open, 'post_meta' );

		$out   = array();
		$loads = $this->post_meta_loads(
			static function () use ( &$out ): void {
				$out = aafm_exec_list_blocks( array() );
			}
		);

		$this->assertSame( array( $open ), array_column( $out['blocks'], 'id' ) );
		$this->assertSame( 2, $out['total'] );
		$this->assertSame( 1, $loads, 'one metadata load for the page' );
	}
}
