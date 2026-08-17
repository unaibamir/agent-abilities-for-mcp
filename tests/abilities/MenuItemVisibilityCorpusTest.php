<?php
/**
 * The menu-item visibility corpus: every reason core hides a menu item, and the verdict
 * aafm/list-menu-items must reach for each.
 *
 * ROWS IN THIS FILE ARE APPEND-ONLY. NEVER DELETE ONE.
 *
 * Same reasoning as the replace-in-post and settings-redaction corpora next door. list-menu-items
 * stopped using wp_get_nav_menu_items() to resolve its items - that reader is language-filtered by
 * WPML, which dropped items that genuinely belonged to the menu - and reapplied core's filters by
 * hand instead. Only one of the two was carried over. The publish-only filter was restored and
 * pinned; the _invalid filter was added to the create and update paths in 1.7.0 and never to the
 * read path, so a menu item whose target had been trashed was still listed while the site rendered
 * nothing for it. That is this project's documented archetype, fixed at one call site and never
 * swept, and it shipped in 1.6.3 and earlier.
 *
 * Every row is asserted against BOTH the ability and wp_get_nav_menu_items(), so the parity claim in
 * the exec's docblock is tested rather than asserted. Both directions are held: the rows that must
 * stay LISTED are what stops the filter being satisfied by hiding everything.
 *
 * The reasons come from wp_setup_nav_menu_item() in wp-includes/nav-menu.php, verified against the
 * WP 6.9 source (this plugin's floor) rather than the 7.0 tree the repo happens to carry.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class MenuItemVisibilityCorpusTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// Executing an ability audits to the activity log; without its table every test here is risky.
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	private function register_menus(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/list-menu-items' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * Every menu-item shape, and whether list-menu-items must return it.
	 *
	 * Each row is [ fixture kind, must_be_listed ]. The kinds are built in build_fixture(), which
	 * runs inside the test because the post/term factories are not available at provider time.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provide_item_shapes(): array {
		return array(
			// -----------------------------------------------------------------
			// Must stay LISTED. Without these the filter could be satisfied by
			// returning nothing at all.
			// -----------------------------------------------------------------
			'custom link'                     => array( 'custom', true ),
			'post_type item, live post'       => array( 'live_post', true ),
			'post_type item, live page'       => array( 'live_page', true ),
			'taxonomy item, live term'        => array( 'live_term', true ),
			'post_type_archive, real type'    => array( 'live_archive', true ),

			// -----------------------------------------------------------------
			// Round-3 traffic simulation, L4-01: the target was trashed after
			// the item was created. Core hides it; the ability listed it, url
			// degraded to a bare ?p= query.
			// -----------------------------------------------------------------
			'post_type item, TRASHED post'    => array( 'trashed_post', false ),
			'post_type item, TRASHED page'    => array( 'trashed_page', false ),

			// -----------------------------------------------------------------
			// The siblings of that finding: every other reason
			// wp_setup_nav_menu_item() sets _invalid, swept rather than fixed
			// one at a time.
			// -----------------------------------------------------------------
			// Note there is deliberately no "permanently deleted target" row:
			// core hooks wp_delete_post/wp_delete_term and removes the menu
			// items pointing at the object, so that state is not reachable. It
			// is pinned as its own test below, so nobody re-adds the row and
			// then loosens the filter to make an impossible fixture pass.
			'post_type item, dangling id'     => array( 'dangling_post_id', false ),
			'post_type item, unknown type'    => array( 'unknown_post_type', false ),
			'taxonomy item, dangling id'      => array( 'dangling_term_id', false ),
			'taxonomy item, unknown taxonomy' => array( 'unknown_taxonomy', false ),
			'post_type_archive, unknown type' => array( 'unknown_archive', false ),

			// -----------------------------------------------------------------
			// The OTHER filter this exec reapplies by hand, kept here so a
			// future rewrite of the loop cannot drop it silently the way the
			// _invalid one was dropped.
			// -----------------------------------------------------------------
			'draft item'                      => array( 'draft', false ),
		);
	}

	/**
	 * Build one menu item of the given kind in the given menu and return its id.
	 *
	 * Items are created through core's wp_update_nav_menu_item() rather than the plugin's
	 * create-menu-item ability on purpose: that ability refuses several of these shapes outright,
	 * which is correct for a write and is exactly why the read path still has to cope with them -
	 * an item can become invalid long after it was legitimately created.
	 *
	 * @param string $kind    Fixture kind from the provider.
	 * @param int    $menu_id Menu id.
	 * @return int Menu item id.
	 */
	private function build_fixture( string $kind, int $menu_id ): int {
		$publish = array( 'menu-item-status' => 'publish' );

		switch ( $kind ) {
			case 'custom':
				return (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					$publish + array(
						'menu-item-title' => 'Custom',
						'menu-item-url'   => home_url( '/somewhere' ),
						'menu-item-type'  => 'custom',
					)
				);

			case 'draft':
				return (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'  => 'Draft',
						'menu-item-url'    => home_url( '/draft' ),
						'menu-item-type'   => 'custom',
						'menu-item-status' => 'draft',
					)
				);

			case 'live_post':
			case 'trashed_post':
				$post_id = (int) self::factory()->post->create(
					array(
						'post_type'   => 'post',
						'post_status' => 'publish',
						'post_title'  => 'Target',
					)
				);
				$item_id = (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					$publish + array(
						'menu-item-title'     => 'Post link',
						'menu-item-type'      => 'post_type',
						'menu-item-object'    => 'post',
						'menu-item-object-id' => $post_id,
					)
				);
				if ( 'trashed_post' === $kind ) {
					wp_trash_post( $post_id );
					$this->assertSame( 'trash', get_post_status( $post_id ), 'the target must really be in the trash.' );
				}
				return $item_id;

			case 'dangling_post_id':
				// An object_id that resolves to nothing. Core's own cleanup never ran for it, which is
				// what a menu looks like after a migration or a direct database delete.
				$missing = 99000001;
				$this->assertNull( get_post( $missing ), 'the fixture needs an id that really does not exist.' );
				return (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					$publish + array(
						'menu-item-title'     => 'Dangling post',
						'menu-item-type'      => 'post_type',
						'menu-item-object'    => 'post',
						'menu-item-object-id' => $missing,
					)
				);

			case 'dangling_term_id':
				$missing_term = 99000002;
				$this->assertNull( term_exists( $missing_term, 'category' ), 'the fixture needs a term id that really does not exist.' );
				return (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					$publish + array(
						'menu-item-title'     => 'Dangling term',
						'menu-item-type'      => 'taxonomy',
						'menu-item-object'    => 'category',
						'menu-item-object-id' => $missing_term,
					)
				);

			case 'live_page':
			case 'trashed_page':
				$page_id = (int) self::factory()->post->create(
					array(
						'post_type'   => 'page',
						'post_status' => 'publish',
						'post_title'  => 'About',
					)
				);
				$item_id = (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					$publish + array(
						'menu-item-title'     => 'About us',
						'menu-item-type'      => 'post_type',
						'menu-item-object'    => 'page',
						'menu-item-object-id' => $page_id,
					)
				);
				if ( 'trashed_page' === $kind ) {
					wp_trash_post( $page_id );
					$this->assertSame( 'trash', get_post_status( $page_id ), 'the target page must really be in the trash.' );
				}
				return $item_id;

			case 'unknown_post_type':
				// A real post, but the item claims a post type that is not registered - the shape a
				// menu takes on after the plugin that registered the type is deactivated.
				$orphan_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
				return (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					$publish + array(
						'menu-item-title'     => 'Orphan type',
						'menu-item-type'      => 'post_type',
						'menu-item-object'    => 'aafm_not_a_registered_type',
						'menu-item-object-id' => $orphan_id,
					)
				);

			case 'live_term':
				$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
				return (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					$publish + array(
						'menu-item-title'     => 'News',
						'menu-item-type'      => 'taxonomy',
						'menu-item-object'    => 'category',
						'menu-item-object-id' => $term_id,
					)
				);

			case 'unknown_taxonomy':
				return (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					$publish + array(
						'menu-item-title'     => 'Ghost taxonomy',
						'menu-item-type'      => 'taxonomy',
						'menu-item-object'    => 'aafm_not_a_registered_taxonomy',
						'menu-item-object-id' => 999999,
					)
				);

			case 'live_archive':
				return (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					$publish + array(
						'menu-item-title'  => 'Posts archive',
						'menu-item-type'   => 'post_type_archive',
						'menu-item-object' => 'post',
					)
				);

			case 'unknown_archive':
				return (int) wp_update_nav_menu_item(
					$menu_id,
					0,
					$publish + array(
						'menu-item-title'  => 'Ghost archive',
						'menu-item-type'   => 'post_type_archive',
						'menu-item-object' => 'aafm_not_a_registered_type',
					)
				);
		}

		$this->fail( sprintf( 'Unknown fixture kind %s.', $kind ) );
	}

	/**
	 * Build one corpus row's item and check both the ability's verdict and its parity with core.
	 *
	 * @dataProvider provide_item_shapes
	 *
	 * @param string $kind           Fixture kind.
	 * @param bool   $must_be_listed Whether list-menu-items must return the item.
	 */
	public function test_item_visibility( string $kind, bool $must_be_listed ): void {
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$menu_id = (int) wp_create_nav_menu( 'Corpus ' . $kind );
		$item_id = $this->build_fixture( $kind, $menu_id );
		$this->assertGreaterThan( 0, $item_id, 'the fixture item must have been created.' );
		$this->assertTrue(
			(bool) is_object_in_term( $item_id, 'nav_menu', $menu_id ),
			'the fixture item must be a real member of the menu, so only a filter can exclude it.'
		);

		$res    = wp_get_ability( 'aafm/list-menu-items' )->execute( array( 'menu_id' => $menu_id ) );
		$listed = array_map( 'intval', array_column( $res['items'], 'id' ) );

		if ( $must_be_listed ) {
			$this->assertContains(
				$item_id,
				$listed,
				sprintf( 'A %s is renderable, so it must stay listed.', $kind )
			);
		} else {
			$this->assertNotContains(
				$item_id,
				$listed,
				sprintf( 'The site will not render a %s, so listing it reports a menu entry that does not exist.', $kind )
			);
		}

		// The exec's docblock claims parity with the reader the front end uses. Test the claim
		// against that reader rather than restating it.
		$rendered = wp_get_nav_menu_items( $menu_id );
		$this->assertSame(
			is_array( $rendered ) ? array_map( 'intval', wp_list_pluck( $rendered, 'ID' ) ) : array(),
			$listed,
			sprintf( 'The listed set must equal what core renders, for a %s.', $kind )
		);
	}

	/**
	 * Why the corpus has no "permanently deleted target" row, pinned rather than assumed.
	 *
	 * Core hooks the permanent delete of a post or a term and removes the menu items that point at it
	 * (_wp_delete_post_menu_item / _wp_delete_tax_menu_item). So an item orphaned by a real delete
	 * does not survive to be listed, and a fixture built that way cannot exist. The reachable
	 * target-is-gone shapes are the trash - which fires no such cleanup - and a dangling id left
	 * behind by something that bypassed core, both of which the corpus does cover.
	 *
	 * Written down because the obvious row to add here is the impossible one, and the obvious way to
	 * make an impossible fixture pass is to loosen the filter under test.
	 */
	public function test_core_removes_the_menu_item_when_its_target_is_permanently_deleted(): void {
		$this->acting_as( 'administrator' );
		$menu_id = (int) wp_create_nav_menu( 'Delete cleanup' );

		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$item_id = (int) wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => 'Post link',
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'post',
				'menu-item-object-id' => $post_id,
				'menu-item-status'    => 'publish',
			)
		);
		$this->assertNotNull( get_post( $item_id ) );

		wp_delete_post( $post_id, true );
		$this->assertNull( get_post( $item_id ), 'core removes the menu item along with the post it pointed at.' );

		$term_id      = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$term_item_id = (int) wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => 'Term link',
				'menu-item-type'      => 'taxonomy',
				'menu-item-object'    => 'category',
				'menu-item-object-id' => $term_id,
				'menu-item-status'    => 'publish',
			)
		);
		$this->assertNotNull( get_post( $term_item_id ) );

		wp_delete_term( $term_id, 'category' );
		$this->assertNull( get_post( $term_item_id ), 'core removes the menu item along with the term it pointed at.' );
	}

	/**
	 * The finding as the simulation drove it, end to end in one menu: a mixed menu loses exactly the
	 * item whose target was trashed, keeps the rest, and renumbers `order` over what is left with no
	 * gap. A per-row test cannot catch a filter that also drops the wrong neighbours.
	 */
	public function test_trashing_a_target_removes_only_that_item_and_order_stays_contiguous(): void {
		$this->register_menus();
		$this->acting_as( 'administrator' );
		$menu_id = (int) wp_create_nav_menu( 'Mixed corpus' );

		$first  = $this->build_fixture( 'custom', $menu_id );
		$second = $this->build_fixture( 'live_post', $menu_id );
		$third  = $this->build_fixture( 'live_page', $menu_id );

		$before = wp_get_ability( 'aafm/list-menu-items' )->execute( array( 'menu_id' => $menu_id ) );
		$this->assertSame(
			array( $first, $second, $third ),
			array_map( 'intval', array_column( $before['items'], 'id' ) ),
			'all three items are renderable to begin with.'
		);
		$this->assertSame( array( 1, 2, 3 ), array_column( $before['items'], 'order' ) );

		// Trash the middle item's target. Nothing about the menu item itself changes.
		$target = (int) get_post_meta( $second, '_menu_item_object_id', true );
		$this->assertGreaterThan( 0, $target, 'the fixture item must point at a real post.' );
		wp_trash_post( $target );
		$this->assertSame( 'trash', get_post_status( $target ) );
		$this->assertNotNull( get_post( $second ), 'the menu item row itself still exists - only its target is gone.' );

		$after = wp_get_ability( 'aafm/list-menu-items' )->execute( array( 'menu_id' => $menu_id ) );
		$this->assertSame(
			array( $first, $third ),
			array_map( 'intval', array_column( $after['items'], 'id' ) ),
			'only the item whose target was trashed is dropped; its neighbours stay.'
		);
		$this->assertSame(
			array( 1, 2 ),
			array_column( $after['items'], 'order' ),
			'order is renumbered over the returned set, so a skipped item leaves no gap.'
		);
	}
}
