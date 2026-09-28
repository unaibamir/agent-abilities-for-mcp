<?php
/**
 * The core abilities and helpers load their objects by id, not by class: when a load of object A
 * reads object B's row (a `query` filter emptied A's SELECT, so wpdb returns B's rows from the
 * query before), the permission, precheck or write refuses and nothing of B decides the outcome.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;
use WP_Post;

final class CoreObjectLoadLeakTest extends TestCase {

	use IntegrationStubs;

	private const LEAKED = 'Leaked B';

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
	}

	public function tear_down(): void {
		delete_option( 'aafm_ability_allowlist_overrides' );
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * A `query` filter that answers the next load of object $a with object $b's row. Object
	 * $a's cache entry is dropped first, so the load reaches the database.
	 *
	 * @param string $type       'post', 'term' or 'user'.
	 * @param int    $a          The object asked for.
	 * @param int    $b          The object whose row is left behind.
	 * @param int    $occurrence Which matching load to answer, 0 for every one.
	 * @return callable
	 */
	private function leak( string $type, int $a, int $b, int $occurrence = 1 ): callable {
		global $wpdb;
		$sql = array(
			'post' => "SELECT * FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
			'term' => "SELECT t.*, tt.* FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id WHERE t.term_id = %d",
			'user' => "SELECT * FROM {$wpdb->users} WHERE ID = %s LIMIT 1",
		)[ $type ];
		wp_cache_delete(
			$a,
			array(
				'post' => 'posts',
				'term' => 'terms',
				'user' => 'users',
			)[ $type ]
		);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only table names and a placeholder.
		$needle = $wpdb->prepare( $sql, $a );
		$row    = $wpdb->prepare( $sql, $b );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return QueryFaultInjector::leak_row_filter( $needle, $row, $occurrence, true );
	}

	/**
	 * Run $run with the leak armed from the start.
	 *
	 * @param string   $type       Object type.
	 * @param int      $a          Requested id.
	 * @param int      $b          Leaked id.
	 * @param callable $run        The call.
	 * @param int      $occurrence Which matching load to answer, 0 for every one.
	 * @return mixed
	 */
	private function leaked( string $type, int $a, int $b, callable $run, int $occurrence = 1 ) {
		return $this->armed( $this->leak( $type, $a, $b, $occurrence ), $run );
	}

	/**
	 * Run $run and arm the leak the first time $hook fires and $when accepts its arguments, so
	 * the leaked load is one made after that point (an object cache that dropped the entry).
	 *
	 * @param string        $hook Action or filter name.
	 * @param string        $type Object type.
	 * @param int           $a    Requested id.
	 * @param int           $b    Leaked id.
	 * @param callable      $run  The call.
	 * @param callable|null $when Predicate over the hook's arguments.
	 * @return mixed
	 */
	private function leaked_after( string $hook, string $type, int $a, int $b, callable $run, ?callable $when = null ) {
		$armed = null;
		$arm   = function ( ...$args ) use ( $type, $a, $b, $when, &$armed ) {
			if ( null === $armed && ( null === $when || $when( ...$args ) ) ) {
				$armed = $this->leak( $type, $a, $b );
				add_filter( 'query', $armed );
			}
			return $args[0] ?? null;
		};
		add_filter( $hook, $arm, PHP_INT_MAX, 4 );
		try {
			return $this->armed( null, $run );
		} finally {
			remove_filter( $hook, $arm, PHP_INT_MAX );
			if ( null !== $armed ) {
				remove_filter( 'query', $armed );
			}
		}
	}

	/**
	 * Run $run with $filter on `query`, database errors suppressed and output discarded.
	 *
	 * @param callable|null $filter The `query` filter, or null when it is armed later.
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
	 * The call returned the generic error, the load was the leaked one, and nothing of B is in it.
	 *
	 * @param mixed  $out   The return.
	 * @param string $label Case label.
	 * @param string $code  Expected error code.
	 */
	private function assert_refused( $out, string $label, string $code = 'aafm_error' ): void {
		$this->assertSame( 1, QueryFaultInjector::fired_count(), "$label: the load was faulted" );
		$this->assertInstanceOf( WP_Error::class, $out, $label );
		$this->assertSame( $code, $out->get_error_code(), $label );
		$this->assertStringNotContainsString( self::LEAKED, (string) wp_json_encode( array( $out->get_error_messages(), $out->get_error_data() ) ), $label );
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
	 * A custom-link item in $menu_id.
	 *
	 * @param int    $menu_id Menu.
	 * @param string $title   Item title.
	 */
	private function menu_item( int $menu_id, string $title ): int {
		$made = aafm_exec_create_menu_item(
			array(
				'menu_id' => $menu_id,
				'title'   => $title,
				'url'     => home_url( '/' . sanitize_title( $title ) ),
			)
		);
		$this->assertIsArray( $made );
		return (int) $made['id'];
	}

	/**
	 * Permission callbacks that gate one post: a post the caller may not act on, whose load
	 * returns a post the caller may act on.
	 *
	 * @return iterable<string,array{0:string,1:string,2:string}>
	 */
	public function data_post_gates(): iterable {
		yield 'avada' => array( 'aafm_perm_avada_post_object', 'post_id', 'post' );
		yield 'set featured image' => array( 'aafm_perm_set_featured_image', 'post_id', 'post' );
		yield 'post meta' => array( 'aafm_can_access_post_meta', 'post_id', 'post' );
		yield 'add post terms' => array( 'aafm_perm_add_post_terms', 'post_id', 'post' );
		yield 'trash page' => array( 'aafm_perm_trash_page', 'page_id', 'page' );
		yield 'block' => array( 'aafm_perm_block_object', 'block_id', 'block' );
	}

	/**
	 * Each gate denies A when A's load returns B. The edit gates run as an author asking for an
	 * administrator's post while the author's own post leaks; the page and block gates run as an
	 * administrator asking for a plain post while a page or a block leaks, so only the type pin
	 * can refuse.
	 *
	 * @dataProvider data_post_gates
	 * @param string $gate  Permission callback.
	 * @param string $field Input key.
	 * @param string $kind  'post', 'page' or 'block'.
	 */
	public function test_a_post_gate_denies_a_post_whose_load_returns_another_row( string $gate, string $field, string $kind ): void {
		if ( 'post' === $kind ) {
			$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$a     = $this->post( array( 'post_author' => $admin ) );
			$me    = $this->acting_as( 'author' );
			$b     = $this->post(
				array(
					'post_author' => $me,
					'post_title'  => self::LEAKED,
				)
			);
		} else {
			$this->acting_as( 'administrator' );
			$a = $this->post();
			$b = $this->post(
				array(
					'post_type'  => 'page' === $kind ? 'page' : 'wp_block',
					'post_title' => self::LEAKED,
				)
			);
		}
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$input = array(
			$field     => $a,
			'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- an ability input, not a query argument.
		);
		$this->assertTrue( $gate( array( $field => $b ) + $input ), 'the leaked object alone passes' );

		$out = $this->leaked( 'post', $a, $b, static fn() => $gate( $input ) );
		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertFalse( $out );
	}

	/**
	 * The delete-revision gate checks the parent twice: once for edit, once for delete. A caller who may
	 * edit A but not delete it is denied when the second load returns a draft the caller may
	 * delete.
	 */
	public function test_the_revision_delete_gate_denies_when_its_delete_load_returns_another_row(): void {
		add_role(
			'aafm_leak_editor_no_delete',
			'Edit without delete',
			array(
				'read'                 => true,
				'edit_posts'           => true,
				'edit_others_posts'    => true,
				'edit_published_posts' => true,
				'delete_posts'         => true,
			)
		);
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$a     = $this->post( array( 'post_author' => $other ) );
		$me    = $this->acting_as( 'aafm_leak_editor_no_delete' );
		$b     = $this->post(
			array(
				'post_author' => $me,
				'post_status' => 'draft',
				'post_title'  => self::LEAKED,
			)
		);
		$rev   = $this->post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $a,
				'post_name'   => $a . '-revision-v1',
			)
		);
		$input = array(
			'post_id'     => $a,
			'revision_id' => $rev,
		);
		$this->assertTrue( aafm_revision_parent_editable( $input ), 'A is editable' );
		$this->assertTrue( aafm_can_delete_post_object( get_post( $b ) ), 'B is deletable' );

		$out = $this->leaked_after(
			'map_meta_cap',
			'post',
			$a,
			$b,
			static fn() => aafm_perm_delete_revision( $input ),
			static fn( $caps, $cap = '', $user = 0, $args = array() ) => 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $a
		);
		remove_role( 'aafm_leak_editor_no_delete' );
		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertFalse( $out );
	}

	/**
	 * The replace-in-post ability writes the requested post from the post it loaded: a leaked row must not
	 * become A's new content.
	 */
	public function test_replace_in_post_whose_load_returns_another_row_is_refused_and_writes_nothing(): void {
		$this->acting_as( 'administrator' );
		$a = $this->post( array( 'post_content' => 'alpha only' ) );
		$b = $this->post(
			array(
				'post_title'   => self::LEAKED,
				'post_content' => 'alpha and ' . self::LEAKED,
			)
		);

		$out = $this->leaked(
			'post',
			$a,
			$b,
			static fn() => aafm_exec_replace_in_post(
				array(
					'post_id' => $a,
					'search'  => 'alpha',
					'replace' => 'omega',
				)
			)
		);
		$this->assert_refused( $out, 'replace-in-post' );
		clean_post_cache( $a );
		$this->assertSame( 'alpha only', get_post( $a )->post_content );
	}

	/**
	 * The update-menu-item ability keeps the stored position it read; a stored row that is another item's
	 * must not become A's position.
	 * The leak arms at the item's menu-membership read, the last query before the position read.
	 */
	public function test_update_menu_item_whose_position_read_returns_another_item_is_refused(): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		$menu = $this->menu( 'Leak positions' );
		$this->menu_item( $menu, 'First' );
		$a = $this->menu_item( $menu, 'Second' );
		$b = $this->menu_item( $menu, self::LEAKED );
		$this->assertNotSame( (int) get_post( $a )->menu_order, (int) get_post( $b )->menu_order );
		$order = (int) get_post( $a )->menu_order;

		// Record where the fault fired, so a reordered call fails with that site named instead of a bare count mismatch.
		$sites = array();
		$fired = QueryFaultInjector::fired_count();
		$trace = static function ( $query ) use ( &$sites, &$fired ) {
			if ( QueryFaultInjector::fired_count() !== $fired ) {
				$fired   = QueryFaultInjector::fired_count();
				$sites[] = ( new \Exception() )->getTraceAsString();
			}
			return $query;
		};
		add_filter( 'query', $trace, 11 );
		$out = $this->leaked_after(
			'query',
			'post',
			$a,
			$b,
			static fn() => aafm_exec_update_menu_item(
				array(
					'menu_id' => $menu,
					'item_id' => $a,
					'title'   => 'Second renamed',
				)
			),
			static fn( $q ) => is_string( $q ) && false !== strpos( $q, $wpdb->term_relationships ) && false !== strpos( $q, "'nav_menu'" ) && false !== strpos( $q, 'tr.object_id = ' . $a . ' ' )
		);
		remove_filter( 'query', $trace, 11 );
		$this->assertCount( 1, $sites, 'update-menu-item position: one faulted load' );
		$this->assertStringContainsString( 'aafm_exact_object_chain(', $sites[0], 'update-menu-item position: faulted inside the chain load' );
		$this->assertStringContainsString( 'aafm_exec_update_menu_item(', $sites[0], 'update-menu-item position: faulted inside update-menu-item' );
		$this->assert_refused( $out, 'update-menu-item position' );
		clean_post_cache( $a );
		$this->assertSame( $order, (int) get_post( $a )->menu_order );
		$this->assertSame( 'Second', get_post( $a )->post_title );
	}

	/**
	 * The first item of a menu is put back at its position after the update; when the reload
	 * that checks the position returns another item, the call does not report success.
	 */
	public function test_update_menu_item_whose_position_reload_returns_another_item_is_refused(): void {
		$this->acting_as( 'administrator' );
		$menu = $this->menu( 'Leak reload' );
		$a    = $this->menu_item( $menu, 'First' );
		$b    = $this->menu_item( $menu, self::LEAKED );
		$this->assertSame( 0, (int) get_post( $a )->menu_order );

		$out = $this->leaked_after(
			'wp_update_nav_menu_item',
			'post',
			$a,
			$b,
			static fn() => aafm_exec_update_menu_item(
				array(
					'menu_id' => $menu,
					'item_id' => $a,
					'title'   => 'First renamed',
				)
			)
		);
		$this->assert_refused( $out, 'update-menu-item reload' );
	}

	/**
	 * A post_type menu item takes its object type from the post it points at: a leaked row must
	 * not supply it.
	 */
	public function test_a_menu_item_whose_object_load_returns_another_post_is_refused_and_not_created(): void {
		$this->acting_as( 'administrator' );
		$menu  = $this->menu( 'Leak objects' );
		$a     = $this->post();
		$b     = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => self::LEAKED,
			)
		);
		$items = count( (array) wp_get_nav_menu_items( $menu ) );

		$out = $this->leaked(
			'post',
			$a,
			$b,
			static fn() => aafm_exec_create_menu_item(
				array(
					'menu_id'   => $menu,
					'title'     => 'Linked',
					'type'      => 'post_type',
					'object_id' => $a,
				)
			)
		);
		$this->assert_refused( $out, 'create-menu-item object', 'aafm_menu_item_object_required' );
		$this->assertCount( $items, (array) wp_get_nav_menu_items( $menu ) );
	}

	/**
	 * The menu abilities load the menu by id before core's wrapper runs: a leaked menu row is
	 * refused and caches nothing foreign under A's id.
	 */
	public function test_menu_abilities_whose_menu_load_returns_another_menu_are_refused(): void {
		$this->acting_as( 'administrator' );
		$a = $this->menu( 'Menu A' );
		$b = $this->menu( self::LEAKED );

		$out = $this->leaked( 'term', $a, $b, static fn() => aafm_exec_get_menu( array( 'menu_id' => $a ) ) );
		$this->assert_refused( $out, 'get-menu' );
		$this->assertFalse( wp_cache_get( $a, 'terms' ), 'get-menu' );

		QueryFaultInjector::reset_fired_count();
		$out = $this->leaked(
			'term',
			$a,
			$b,
			static fn() => aafm_exec_update_menu(
				array(
					'menu_id' => $a,
					'name'    => 'Renamed',
				)
			)
		);
		$this->assert_refused( $out, 'update-menu' );
		$this->assertFalse( wp_cache_get( $a, 'terms' ), 'update-menu' );
		$this->assertSame( 'Menu A', get_term( $a, 'nav_menu' )->name );
		$this->assertSame( self::LEAKED, get_term( $b, 'nav_menu' )->name );

		QueryFaultInjector::reset_fired_count();
		$out = $this->leaked( 'term', $a, $b, static fn() => aafm_exec_delete_menu( array( 'menu_id' => $a ) ) );
		$this->assert_refused( $out, 'delete-menu' );
		$this->assertInstanceOf( \WP_Term::class, get_term( $a, 'nav_menu' ) );
		$this->assertInstanceOf( \WP_Term::class, get_term( $b, 'nav_menu' ) );

		QueryFaultInjector::reset_fired_count();
		$out = $this->leaked(
			'term',
			$a,
			$b,
			static fn() => aafm_exec_create_menu_item(
				array(
					'menu_id' => $a,
					'title'   => 'Item',
					'url'     => home_url( '/item' ),
				)
			)
		);
		$this->assert_refused( $out, 'create-menu-item' );
		$this->assertSame( array(), (array) wp_get_nav_menu_items( $a ) );
		$this->assertSame( array(), (array) wp_get_nav_menu_items( $b ) );
	}

	/**
	 * The update-menu ability reloads the menu after the rename; a leaked row there is refused.
	 */
	public function test_update_menu_whose_reload_returns_another_menu_is_refused(): void {
		$this->acting_as( 'administrator' );
		$a = $this->menu( 'Menu A' );
		$b = $this->menu( self::LEAKED );

		$out = $this->leaked_after(
			'wp_update_nav_menu',
			'term',
			$a,
			$b,
			static fn() => aafm_exec_update_menu(
				array(
					'menu_id' => $a,
					'name'    => 'Renamed',
				)
			)
		);
		$this->assert_refused( $out, 'update-menu reload' );
	}

	/**
	 * A menu id that is not a menu is refused, even when another menu's slug or name is those
	 * digits: the menu abilities no longer fall back to a slug or name lookup.
	 */
	public function test_a_menu_id_that_is_not_a_menu_is_refused_rather_than_resolved_by_name(): void {
		$this->acting_as( 'administrator' );
		$n = 987654;
		$this->assertNull( get_term( $n ) );
		$named = $this->menu( (string) $n );
		$item  = $this->menu_item( $named, 'Kept' );
		$this->assertSame( $named, wp_get_nav_menu_object( $n )->term_id, 'core resolves the digits to the named menu' );

		$calls = array(
			'get-menu'         => static fn() => aafm_exec_get_menu( array( 'menu_id' => $n ) ),
			'update-menu'      => static fn() => aafm_exec_update_menu(
				array(
					'menu_id' => $n,
					'name'    => 'Renamed',
				)
			),
			'delete-menu'      => static fn() => aafm_exec_delete_menu( array( 'menu_id' => $n ) ),
			'create-menu-item' => static fn() => aafm_exec_create_menu_item(
				array(
					'menu_id' => $n,
					'title'   => 'Added',
					'url'     => home_url( '/added' ),
				)
			),
			'update-menu-item' => static fn() => aafm_exec_update_menu_item(
				array(
					'menu_id' => $n,
					'item_id' => $item,
					'title'   => 'Changed',
				)
			),
		);
		foreach ( $calls as $label => $call ) {
			$out = $call();
			$this->assertInstanceOf( WP_Error::class, $out, $label );
			$this->assertSame( 'aafm_error', $out->get_error_code(), $label );
			$menu = get_term( $named, 'nav_menu' );
			$this->assertInstanceOf( \WP_Term::class, $menu, $label );
			$this->assertSame( (string) $n, $menu->name, $label );
			$this->assertSame( array( $item ), wp_list_pluck( (array) wp_get_nav_menu_items( $named ), 'ID' ), $label );
			$this->assertSame( 'Kept', get_post( $item )->post_title, $label );
		}
	}

	/**
	 * The update-user ability decides the last-admin lock from the user it loaded: a leaked row is refused
	 * and A's role stays.
	 */
	public function test_update_user_whose_load_returns_another_user_is_refused(): void {
		$this->acting_as( 'administrator' );
		$a = self::factory()->user->create( array( 'role' => 'editor' ) );
		$b = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => self::LEAKED,
			)
		);

		$out = $this->leaked(
			'user',
			$a,
			$b,
			static fn() => aafm_exec_update_user(
				array(
					'user_id' => $a,
					'role'    => 'author',
				)
			)
		);
		$this->assert_refused( $out, 'update-user' );
		clean_user_cache( $a );
		$this->assertSame( array( 'editor' ), get_userdata( $a )->roles );
	}

	/**
	 * The delete-user ability needs a reassign target that exists: a missing id whose load returns another
	 * user is refused and the victim stays.
	 */
	public function test_delete_user_whose_reassign_load_returns_another_user_is_refused(): void {
		$this->acting_as( 'administrator' );
		$victim  = self::factory()->user->create( array( 'role' => 'author' ) );
		$b       = self::factory()->user->create( array( 'display_name' => self::LEAKED ) );
		$missing = $b + 1000;
		$this->assertFalse( get_userdata( $missing ) );

		$out = $this->leaked(
			'user',
			$missing,
			$b,
			static fn() => aafm_exec_delete_user(
				array(
					'user_id'     => $victim,
					'reassign_to' => $missing,
				)
			)
		);
		$this->assert_refused( $out, 'delete-user' );
		clean_user_cache( $victim );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $victim ) );
	}

	/**
	 * The update-user-meta ability refuses a missing user id whose load returns another user, and writes no
	 * row for it.
	 */
	public function test_update_user_meta_for_a_missing_user_whose_load_returns_another_user_is_refused(): void {
		global $wpdb;
		$this->acting_as( 'administrator' );
		$b       = self::factory()->user->create( array( 'display_name' => self::LEAKED ) );
		$missing = $b + 1000;
		update_option( 'aafm_exposed_user_meta_keys', array( 'aafm_note' ) );

		$out = $this->leaked(
			'user',
			$missing,
			$b,
			static fn() => aafm_exec_update_user_meta(
				array(
					'user_id' => $missing,
					'key'     => 'aafm_note',
					'value'   => 'x',
				)
			)
		);
		$this->assert_refused( $out, 'update-user-meta' );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d", $missing ) ) );
	}

	/**
	 * The allowlist restricts a principal by the roles of the user it loaded: a leaked
	 * unrestricted user must not lift the principal's role restriction.
	 */
	public function test_a_role_restricted_principal_whose_load_returns_another_user_is_denied(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$a = self::factory()->user->create( array( 'role' => 'editor' ) );
		$b = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/delete-post', $b, null ), 'B alone is unrestricted' );

		$out = $this->leaked( 'user', $a, $b, static fn() => aafm_ability_allowed_for_principal( 'aafm/delete-post', $a, null ) );
		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertFalse( $out );
	}

	/**
	 * Creating the agent user when the login exists stamps the agent marker only on an
	 * agent-shaped account; a leaked subscriber row must not make an editor look like one.
	 */
	public function test_the_agent_marker_is_not_stamped_when_the_existing_user_load_returns_another_user(): void {
		$this->acting_as( 'administrator' );
		$a = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'aafm-leak-agent',
			)
		);
		$b = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->leaked_after( 'username_exists', 'user', $a, $b, static fn() => aafm_create_agent_user( 'aafm-leak-agent' ) );
		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertSame( '', get_user_meta( $a, aafm_agent_user_marker_meta_key(), true ) );
	}

	/**
	 * A WooCommerce product loaded from the post store always has its post. When that post's
	 * load returns another row, the delete gate refuses instead of falling back to the floor.
	 */
	public function test_the_wc_delete_gates_refuse_when_the_backing_post_load_returns_another_row(): void {
		$this->stub_woocommerce();
		if ( ! class_exists( 'WC_Product_Data_Store_CPT' ) ) {
			eval( 'class WC_Product_Data_Store_CPT {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class-name marker for the store check; tests only.
		}
		if ( ! class_exists( 'WC_Product_Variation_Data_Store_CPT' ) ) {
			eval( 'class WC_Product_Variation_Data_Store_CPT extends WC_Product_Data_Store_CPT {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- as above.
		}
		$this->acting_as( 'administrator' );
		$a = $this->post( array( 'post_type' => 'post' ) );
		$b = $this->post( array( 'post_title' => self::LEAKED ) );
		\AAFM\Tests\WcStubStore::seed(
			$a,
			array(
				'id'         => $a,
				'name'       => 'Product A',
				'type'       => 'simple',
				'status'     => 'publish',
				'data_store' => 'WC_Product_Data_Store_CPT',
			)
		);
		$out = $this->leaked( 'post', $a, $b, static fn() => aafm_perm_wc_delete_product( array( 'product_id' => $a ) ) );
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'product' );
		$this->assertFalse( $out, 'product' );

		QueryFaultInjector::reset_fired_count();
		\AAFM\Tests\WcStubStore::seed(
			$a,
			array(
				'id'         => $a,
				'name'       => 'Variation A',
				'type'       => 'variation',
				'parent_id'  => $b,
				'status'     => 'publish',
				'data_store' => 'WC_Product_Variation_Data_Store_CPT',
			)
		);
		$out = $this->leaked( 'post', $a, $b, static fn() => aafm_perm_wc_delete_product_variation( array( 'variation_id' => $a ) ) );
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'variation' );
		$this->assertFalse( $out, 'variation' );
	}

	/**
	 * The same two gates with WooCommerce's registry reporting its own post stores for products
	 * and variations: a backing post whose load returns another row is refused at the gate's own
	 * load, and the capability floor is never kept for it.
	 */
	public function test_the_wc_delete_gates_refuse_under_the_core_stores_when_the_backing_post_load_returns_another_row(): void {
		$this->stub_woocommerce();
		require_once dirname( __DIR__ ) . '/stubs/WcDataStoreStub.php';
		\WC_Data_Store::reset();
		\WC_Data_Store::$stores['product']           = 'WC_Product_Data_Store_CPT';
		\WC_Data_Store::$stores['product-variation'] = 'WC_Product_Variation_Data_Store_CPT';
		try {
			$this->acting_as( 'administrator' );
			$a = $this->post( array( 'post_type' => 'post' ) );
			$b = $this->post( array( 'post_title' => self::LEAKED ) );
			\AAFM\Tests\WcStubStore::seed(
				$a,
				array(
					'id'     => $a,
					'name'   => 'Product A',
					'type'   => 'simple',
					'status' => 'publish',
				)
			);
			$out = $this->leaked( 'post', $a, $b, static fn() => aafm_perm_wc_delete_product( array( 'product_id' => $a ) ) );
			$this->assertSame( 1, QueryFaultInjector::fired_count(), 'product' );
			$this->assertFalse( $out, 'product' );

			QueryFaultInjector::reset_fired_count();
			\AAFM\Tests\WcStubStore::seed(
				$a,
				array(
					'id'        => $a,
					'name'      => 'Variation A',
					'type'      => 'variation',
					'parent_id' => $b,
					'status'    => 'publish',
				)
			);
			$out = $this->leaked( 'post', $a, $b, static fn() => aafm_perm_wc_delete_product_variation( array( 'variation_id' => $a ) ) );
			$this->assertSame( 1, QueryFaultInjector::fired_count(), 'variation' );
			$this->assertFalse( $out, 'variation' );
		} finally {
			\WC_Data_Store::reset();
		}
	}

	/**
	 * An update-term call with a parent refuses when its load of A returns another term, changes nothing
	 * on A, and leaves no foreign entry cached under A's id.
	 */
	public function test_update_term_whose_load_returns_another_term_is_refused_and_caches_nothing_foreign(): void {
		$this->acting_as( 'administrator' );
		$a      = (int) self::factory()->term->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'Term A',
				'slug'        => 'term-a',
				'description' => 'A description',
			)
		);
		$b      = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => self::LEAKED,
			)
		);
		$parent = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$out = $this->leaked(
			'term',
			$a,
			$b,
			static fn() => aafm_exec_update_term(
				array(
					'taxonomy' => 'category',
					'term_id'  => $a,
					'name'     => 'Renamed',
					'parent'   => $parent,
				)
			)
		);
		$this->assert_refused( $out, 'update-term' );
		$this->assertFalse( wp_cache_get( $a, 'terms' ) );
		$term = get_term( $a, 'category' );
		$this->assertSame( array( 'Term A', 'term-a', 'A description', 0 ), array( $term->name, $term->slug, $term->description, $term->parent ) );
	}

	/**
	 * A numeric WooCommerce attribute option is a term id: a load that returns another term
	 * resolves to no term and caches nothing foreign under the id.
	 */
	public function test_a_numeric_attribute_option_whose_term_load_returns_another_term_resolves_to_nothing(): void {
		$a = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$b = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => self::LEAKED,
			)
		);

		$out = $this->leaked( 'term', $a, $b, static fn() => aafm_wc_find_attribute_term( (string) $a, 'category' ) );
		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertNull( $out );
		$this->assertFalse( wp_cache_get( $a, 'terms' ) );
	}

	/**
	 * The delete-media ability refuses a post that is not an attachment even when every load of it returns an
	 * attachment's row, and deletes neither.
	 */
	public function test_delete_media_whose_type_load_returns_an_attachment_is_refused_and_deletes_nothing(): void {
		$this->acting_as( 'administrator' );
		$a = $this->post();
		$b = (int) self::factory()->attachment->create(
			array(
				'post_title'     => self::LEAKED,
				'post_mime_type' => 'image/png',
			)
		);

		$out = $this->leaked( 'post', $a, $b, static fn() => aafm_exec_delete_media( array( 'attachment_id' => $a ) ), 0 );
		$this->assert_refused( $out, 'delete-media' );
		clean_post_cache( $a );
		clean_post_cache( $b );
		$this->assertInstanceOf( WP_Post::class, get_post( $a ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $b ) );
	}

	/**
	 * The get-revision ability checks that the revision belongs to the named post: a revision of another
	 * post, whose load returns a revision of the named post, is refused.
	 */
	public function test_get_revision_whose_revision_load_returns_another_revision_is_refused(): void {
		$me    = $this->acting_as( 'administrator' );
		$mine  = $this->post( array( 'post_author' => $me ) );
		$other = $this->post();
		$a     = $this->post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $other,
				'post_name'   => $other . '-revision-v1',
			)
		);
		$b     = $this->post(
			array(
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_parent'  => $mine,
				'post_name'    => $mine . '-revision-v1',
				'post_title'   => self::LEAKED,
				'post_content' => self::LEAKED,
			)
		);
		$this->assertIsArray(
			aafm_exec_get_revision(
				array(
					'revision_id' => $b,
					'post_id'     => $mine,
				)
			),
			'B alone is readable'
		);

		$out = $this->leaked(
			'post',
			$a,
			$b,
			static fn() => aafm_exec_get_revision(
				array(
					'revision_id' => $a,
					'post_id'     => $mine,
				)
			)
		);
		$this->assert_refused( $out, 'get-revision' );
	}

	/**
	 * The activity screen's term link loads the term first: a load that returns another term
	 * gives no link and caches nothing foreign under the id.
	 */
	public function test_the_activity_term_link_is_withheld_when_its_term_load_returns_another_term(): void {
		$this->acting_as( 'administrator' );
		$this->assertSame( 'term', aafm_activity_detail_link_type( 'aafm/delete-menu' ) );
		$a = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$b = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => self::LEAKED,
			)
		);
		$this->assertIsArray( aafm_activity_detail_link( 'aafm/delete-menu', 'Deleted #' . $b ), 'B alone links' );

		$out = $this->leaked( 'term', $a, $b, static fn() => aafm_activity_detail_link( 'aafm/delete-menu', 'Deleted #' . $a ) );
		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertNull( $out );
		$this->assertFalse( wp_cache_get( $a, 'terms' ) );
	}

	/**
	 * When a demote leaves the site with no administrator, update-user restores the role on the
	 * user it loads exactly. If that load reads another user's row, the other user is not made an
	 * administrator and the call returns the generic error.
	 */
	public function test_the_last_admin_restore_whose_load_returns_another_user_promotes_nobody(): void {
		$this->acting_as( 'administrator' );
		$a      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$b      = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => self::LEAKED,
			)
		);
		$counts = 0;
		// Once the write has run, the administrator count reads empty, so the restore branch runs.
		$empty = static function ( $results, $query ) use ( &$counts ) {
			if ( 'administrator' === ( $query->query_vars['role'] ?? '' ) ) {
				++$counts;
				return array();
			}
			return $results;
		};
		$arm   = static function () use ( $empty ): void {
			add_filter( 'users_pre_query', $empty, 10, 2 );
		};
		add_action( 'profile_update', $arm, PHP_INT_MAX, 0 );
		try {
			$out = $this->leaked_after(
				'profile_update',
				'user',
				$a,
				$b,
				static fn() => aafm_exec_update_user(
					array(
						'user_id' => $a,
						'role'    => 'editor',
					)
				)
			);
		} finally {
			remove_action( 'profile_update', $arm, PHP_INT_MAX );
			remove_filter( 'users_pre_query', $empty, 10 );
		}

		$this->assertGreaterThan( 0, $counts, 'the restore branch ran' );
		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'the restore load was faulted' );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		clean_user_cache( $b );
		$this->assertSame( array( 'subscriber' ), get_userdata( $b )->roles, 'B was not made an administrator' );
	}
}
