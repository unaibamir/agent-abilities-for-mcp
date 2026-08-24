<?php
/**
 * The tools/list DISCOVERY check is decoupled from per-object EXECUTE authorization.
 *
 * Several abilities gate execution on a per-object capability that needs an id from the
 * input (e.g. edit_post( $post_id )). The tools/list visibility check runs with EMPTY
 * input, so a naive "can call with empty input" test hid those tools from fully capable
 * users - they became undiscoverable. The discovery layer uses an object-independent
 * predicate so a capable user SEES the tool, while the real permission_callback still
 * runs at execute time and still denies on objects the user can't act on.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ServerDiscoveryTest extends TestCase {

	/**
	 * The per-object abilities that were hidden from capable users before the fix.
	 *
	 * @var string[]
	 */
	private const PER_OBJECT_ABILITIES = array(
		'aafm/get-post',
		'aafm/get-page',
		'aafm/update-post',
		'aafm/trash-post',
		'aafm/update-page',
		'aafm/trash-page',
		'aafm/set-featured-image',
		'aafm/moderate-comment',
		'aafm/get-post-meta',
		'aafm/update-post-meta',
		'aafm/delete-post-meta',
	);

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check + execute to the
		// custom table, so it must exist before any ability registers or runs.
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->register_whole_catalog();
	}

	/**
	 * Enable and register the real 24-ability catalog (the same way CatalogTest does).
	 */
	private function register_whole_catalog(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry() ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * Minimal Tool DTO stub exposing getName(), matching the adapter's DTO contract.
	 *
	 * @param string $name Sanitized MCP tool name.
	 * @return object
	 */
	private function tool_dto( string $name ): object {
		return new class( $name ) {
			/**
			 * Tool name.
			 *
			 * @var string
			 */
			private string $name;

			/**
			 * Stub Tool DTO.
			 *
			 * @param string $name Tool name.
			 */
			public function __construct( string $name ) {
				$this->name = $name;
			}

			public function getName(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter DTO accessor.
				return $this->name;
			}
		};
	}

	/**
	 * Build a Tool DTO list for every enabled ability, then run the real tools/list filter.
	 *
	 * @return array<int,string> Sanitized MCP tool names that survive the filter.
	 */
	private function visible_tool_names(): array {
		$tools = array();
		foreach ( aafm_get_enabled_abilities() as $ability_name ) {
			$tools[] = $this->tool_dto( aafm_mcp_tool_name( $ability_name ) );
		}
		$visible = aafm_filter_mcp_tools_list( $tools );

		$names = array();
		foreach ( (array) $visible as $tool ) {
			$names[] = $tool->getName();
		}
		return $names;
	}

	public function test_capable_user_discovers_per_object_post_tools(): void {
		// An editor holds edit_posts / delete_posts / moderate_comments and edit_pages /
		// delete_pages - the floor caps the per-object branches refine - so they must SEE
		// every per-object tool in tools/list even though no object id is supplied.
		$this->acting_as( 'editor' );

		$names = $this->visible_tool_names();

		foreach ( self::PER_OBJECT_ABILITIES as $ability ) {
			$this->assertContains(
				aafm_mcp_tool_name( $ability ),
				$names,
				$ability . ' should be discoverable for a capable user (editor)'
			);
		}
	}

	public function test_administrator_discovers_governed_post_meta_tools(): void {
		// Governed post-meta gates on per-object edit_post (reads included). An administrator
		// holds edit_posts, so the coarse discovery floor passes and all three meta tools must
		// appear in the real tools/list the adapter filter produces - the ship-blocker check.
		$this->acting_as( 'administrator' );

		$names = $this->visible_tool_names();

		$this->assertContains( aafm_mcp_tool_name( 'aafm/get-post-meta' ), $names );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/update-post-meta' ), $names );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/delete-post-meta' ), $names );
	}

	/**
	 * B15: a site that renames a tool via mcp_adapter_tool_name must not thereby leak an admin-only
	 * tool into a subscriber's tools/list.
	 *
	 * The adapter names the tool DTO by running the sanitized name through mcp_adapter_tool_name. If
	 * the visibility map is keyed only by our sanitized name, a renamed tool misses the map and is
	 * shown ungated. The map now applies the same filter, so the renamed admin-only aafm/update-user
	 * is still filtered out for a subscriber (execution was always denied; this closes the catalog
	 * leak).
	 */
	public function test_renamed_admin_tool_stays_gated_in_tools_list(): void {
		add_filter(
			'mcp_adapter_tool_name',
			static function ( $name, $ability ) {
				return ( $ability instanceof \WP_Ability && 'aafm/update-user' === $ability->get_name() )
					? 'site_renamed_update_user'
					: $name;
			},
			10,
			2
		);

		$this->acting_as( 'subscriber' );

		// Build the tools list the way the adapter would: the renamed DTO name for update-user.
		$tools = array();
		foreach ( aafm_get_enabled_abilities() as $ability_name ) {
			$sanitized = aafm_mcp_tool_name( $ability_name );
			$ability   = wp_get_ability( $ability_name );
			$dto_name  = ( $ability instanceof \WP_Ability )
				? (string) apply_filters( 'mcp_adapter_tool_name', $sanitized, $ability ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook; mirrored here to reproduce the DTO name.
				: $sanitized;
			$tools[]   = $this->tool_dto( $dto_name );
		}

		$names = array();
		foreach ( (array) aafm_filter_mcp_tools_list( $tools ) as $tool ) {
			$names[] = $tool->getName();
		}

		$this->assertNotContains(
			'site_renamed_update_user',
			$names,
			'A renamed admin-only tool must still be gated out of a subscriber tools/list.'
		);
	}

	public function test_author_discovers_update_and_trash_and_get_post(): void {
		// An author has edit_posts + delete_posts + read, so the post-side per-object tools
		// surface for them too. Authors lack moderate_comments and the page caps, which are
		// asserted separately.
		$this->acting_as( 'author' );

		$names = $this->visible_tool_names();

		$this->assertContains( aafm_mcp_tool_name( 'aafm/update-post' ), $names );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/trash-post' ), $names );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/get-post' ), $names );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/get-page' ), $names );
		$this->assertContains( aafm_mcp_tool_name( 'aafm/set-featured-image' ), $names );
	}

	public function test_discovery_is_not_execute_authorization_for_contributor(): void {
		// A contributor HAS edit_posts, so they now DISCOVER update-post (discovery cap passes).
		$author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_post_id = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);

		$contributor_id = $this->acting_as( 'contributor' );

		// Discovery: the contributor SEES update-post.
		$this->assertContains(
			aafm_mcp_tool_name( 'aafm/update-post' ),
			$this->visible_tool_names(),
			'A contributor with edit_posts should discover update-post'
		);

		// Execute gate: the per-object permission_callback STILL denies editing a post the
		// contributor does not own. Discovery did not grant authorization.
		$this->assertFalse(
			current_user_can( 'edit_post', $other_post_id ),
			'sanity: a contributor cannot edit another author\'s post'
		);
		$this->assertFalse(
			aafm_perm_update_post( array( 'post_id' => $other_post_id ) ),
			'the EXECUTE-time per-object permission must still deny on a post the user cannot edit'
		);

		unset( $contributor_id );
	}

	public function test_subscriber_does_not_discover_write_tools(): void {
		// A subscriber lacks edit_posts / delete_posts / moderate_comments and the page caps,
		// so the discovery predicate denies - none of the per-object WRITE tools appear.
		$this->acting_as( 'subscriber' );

		$names = $this->visible_tool_names();

		$this->assertNotContains( aafm_mcp_tool_name( 'aafm/update-post' ), $names );
		$this->assertNotContains( aafm_mcp_tool_name( 'aafm/trash-post' ), $names );
		$this->assertNotContains( aafm_mcp_tool_name( 'aafm/update-page' ), $names );
		$this->assertNotContains( aafm_mcp_tool_name( 'aafm/trash-page' ), $names );
		$this->assertNotContains( aafm_mcp_tool_name( 'aafm/set-featured-image' ), $names );
		$this->assertNotContains( aafm_mcp_tool_name( 'aafm/moderate-comment' ), $names );
	}

	public function test_discover_helper_falls_back_for_general_cap_abilities(): void {
		// For abilities with no per-object branch, discovery is the plain empty-input check -
		// behavior is unchanged. create-post gates on publish_posts: editor yes, subscriber no.
		$this->acting_as( 'editor' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/create-post' ) );

		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/create-post' ) );
		// A subscriber can still discover the generic read (get-posts gates on 'read').
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-posts' ) );
	}

	public function test_unknown_ability_is_not_discoverable(): void {
		// No stashed callback and no list-permission override → fail closed.
		$this->acting_as( 'administrator' );
		$this->assertNull( aafm_ability_list_permission( 'aafm/does-not-exist' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/does-not-exist' ) );
	}

	public function test_discovery_check_does_not_log_denials(): void {
		// The discovery predicate must not write denied audit rows while building tools/list.
		$this->acting_as( 'subscriber' );
		$this->visible_tool_names();

		$denied = aafm_query_activity( array( 'status' => 'denied' ) );
		$this->assertCount( 0, (array) $denied, 'tools/list discovery must not audit denials' );
	}

	public function test_replace_in_post_discoverable_at_edit_posts_floor(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/replace-in-post' ) );

		$this->acting_as( 'author' ); // has edit_posts.
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/replace-in-post' ) );
	}

	public function test_get_all_post_meta_discoverable_at_edit_posts_floor(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/get-all-post-meta' ) );

		$this->acting_as( 'author' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-all-post-meta' ) );
	}

	public function test_count_posts_discoverable_at_read_floor(): void {
		$this->acting_as( 'subscriber' ); // subscriber has 'read'.
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/count-posts' ) );
	}

	/**
	 * B-update-page-discovery: a role holding edit_others_pages + edit_published_pages, but NOT
	 * edit_pages, can genuinely pass aafm_perm_update_page() on someone else's published page
	 * (WP's own map_meta_cap('edit_post', ...) resolves the "editing someone else's post"
	 * branch through edit_others_pages + edit_published_pages, never through edit_pages at
	 * all). The coarse discovery predicate must not hide the tool from that role.
	 */
	public function test_discovery_reconciles_update_page_for_a_role_missing_edit_pages(): void {
		add_role(
			'aafm_page_editor_no_own',
			'AAFM Page Editor (no own)',
			array(
				'read'                 => true,
				'edit_others_pages'    => true,
				'edit_published_pages' => true,
			)
		);

		$author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);

		$this->acting_as( 'aafm_page_editor_no_own' );

		$this->assertFalse(
			current_user_can( 'edit_pages' ),
			'sanity: this role does not hold the generic edit_pages capability.'
		);
		$this->assertTrue(
			aafm_perm_update_page( array( 'page_id' => $other_page_id ) ),
			'sanity: the EXECUTE-time permission genuinely passes for this role on this page.'
		);

		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/update-page' ),
			'discovery must not hide a tool this role can genuinely use, just because it lacks edit_pages specifically.'
		);
	}

	/**
	 * A post authored by someone else, deliberately DRAFT rather than published:
	 * map_meta_cap's "editing/deleting someone else's post" branch only adds
	 * edit_others_posts/delete_others_posts by itself for a draft/pending object - a PUBLISHED
	 * post by another author additionally requires edit_published_posts/delete_published_posts
	 * (both ANDed together), so a published fixture would test a different, two-cap combination.
	 *
	 * @param string $post_type Post type to create.
	 * @return int Post id.
	 */
	private function foreign_authored_post( string $post_type = 'post' ): int {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		return (int) self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * FINDING 1 (Codex MEDIUM): the standalone edit_private_pages arm never resolves anything on
	 * its own (core only ever pairs it with edit_others_pages), so a role holding ONLY
	 * edit_private_pages was shown update-page despite being unable to execute it on any object.
	 * Removing the arm must make discovery agree with that reality.
	 */
	public function test_update_page_no_longer_discoverable_on_private_pages_cap_alone(): void {
		add_role(
			'aafm_private_pages_only',
			'AAFM Private Pages Only',
			array(
				'read'               => true,
				'edit_private_pages' => true,
			)
		);

		$other_page_id = $this->foreign_authored_post( 'page' );
		wp_update_post(
			array(
				'ID'          => $other_page_id,
				'post_status' => 'private',
			)
		);

		$this->acting_as( 'aafm_private_pages_only' );

		$this->assertFalse(
			current_user_can( 'edit_others_pages' ),
			'sanity: this role does not hold edit_others_pages.'
		);
		$this->assertFalse(
			aafm_perm_update_page( array( 'page_id' => $other_page_id ) ),
			'sanity: edit_private_pages alone never satisfies the EXECUTE-time permission, even on a private page.'
		);

		$this->assertFalse(
			aafm_user_can_discover_ability( 'aafm/update-page' ),
			'discovery must not show a tool that edit_private_pages alone can never execute.'
		);
	}

	/**
	 * Fix round 2: Codex MEDIUM x2 (update-page's remaining private-cap arm, and the same
	 * discovery/execute mismatch on every sibling per-object write/delete). Every test below
	 * follows the same shape as test_discovery_reconciles_update_page_for_a_role_missing_edit_pages
	 * above: a custom role holding ONLY the new OR arm, a sanity check that the real execute-time
	 * permission_callback genuinely passes for that role on a real object, then the discovery
	 * assertion the fix is actually proving.
	 */

	/**
	 * FINDING 2: aafm/update-post, aafm/replace-in-post, and aafm/set-featured-image share the
	 * identical discovery case in server.php - testing update-post proves the shared closure for
	 * all three. aafm_perm_update_post delegates to aafm_can_edit_post_object, which resolves the
	 * same edit_posts/edit_others_posts/edit_published_posts OR that update-page's per-object
	 * check does; edit_others_posts was the missing arm.
	 */
	public function test_update_post_discoverable_with_edit_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_editor',
			'AAFM Others-Posts Editor',
			array(
				'read'              => true,
				'edit_others_posts' => true,
			)
		);

		$other_post_id = $this->foreign_authored_post();

		$this->acting_as( 'aafm_others_posts_editor' );

		$this->assertFalse( current_user_can( 'edit_posts' ), 'sanity: this role lacks the bare edit_posts cap.' );
		$this->assertTrue(
			aafm_perm_update_post( array( 'post_id' => $other_post_id ) ),
			'sanity: edit_others_posts alone genuinely passes the EXECUTE-time check on someone else\'s post.'
		);

		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/update-post' ),
			'discovery must not hide update-post from a role that can genuinely edit someone else\'s post.'
		);
		// replace-in-post and set-featured-image share the exact same case/closure.
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/replace-in-post' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/set-featured-image' ) );
	}

	/**
	 * FINDING 2: aafm/trash-post and aafm/delete-post's delete floor needed the same widening as
	 * the edit floor above, on the delete side (delete_others_posts).
	 */
	public function test_trash_post_discoverable_with_delete_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_deleter',
			'AAFM Others-Posts Deleter',
			array(
				'read'                => true,
				'delete_others_posts' => true,
			)
		);

		$other_post_id = $this->foreign_authored_post();

		$this->acting_as( 'aafm_others_posts_deleter' );

		$this->assertFalse( current_user_can( 'delete_posts' ) );
		$this->assertTrue( aafm_can_delete_post_object( get_post( $other_post_id ) ) );

		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/trash-post' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-post' ) );
	}

	/**
	 * FINDING 2 (Codex-named example): aafm/trash-page and aafm/delete-page checked only
	 * delete_pages (i.e. the page type's delete_posts cap), missing delete_others_posts and
	 * delete_published_posts - the exact mismatch Task 8 had already fixed on update-page but
	 * never carried to this sibling.
	 */
	public function test_trash_page_discoverable_with_delete_others_pages_alone(): void {
		add_role(
			'aafm_others_pages_deleter',
			'AAFM Others-Pages Deleter',
			array(
				'read'                => true,
				'delete_others_pages' => true,
			)
		);

		$other_page_id = $this->foreign_authored_post( 'page' );

		$this->acting_as( 'aafm_others_pages_deleter' );

		$this->assertFalse( current_user_can( 'delete_pages' ), 'sanity: this role lacks the bare delete_pages cap.' );
		$this->assertTrue(
			aafm_can_delete_post_object( get_post( $other_page_id ) ),
			'sanity: delete_others_pages alone genuinely passes the EXECUTE-time check on someone else\'s page.'
		);

		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/trash-page' ),
			'discovery must not hide trash-page from a role that can genuinely delete someone else\'s page.'
		);
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-page' ) );
	}

	/**
	 * FINDING 2: the governed post-meta family (get/update/delete + bulk read) shares update-post's
	 * edit floor, reads included - a meta read is gated the same as the write (meta can hold
	 * private data).
	 */
	public function test_get_post_meta_discoverable_with_edit_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_meta',
			'AAFM Others-Posts Meta',
			array(
				'read'              => true,
				'edit_others_posts' => true,
			)
		);

		$other_post_id = $this->foreign_authored_post();

		$this->acting_as( 'aafm_others_posts_meta' );

		$this->assertTrue(
			aafm_can_edit_post_object( get_post( $other_post_id ) ),
			'sanity: edit_others_posts alone genuinely clears the per-object gate the meta family shares with update-post.'
		);

		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-post-meta' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-all-post-meta' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-post-meta' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-post-meta' ) );
	}

	/**
	 * FINDING 2: revisions (list/get/restore/delete) gate per-object on the SAME edit floor as
	 * update-post, on the parent post.
	 */
	public function test_list_revisions_discoverable_with_edit_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_revisions',
			'AAFM Others-Posts Revisions',
			array(
				'read'              => true,
				'edit_others_posts' => true,
			)
		);

		$other_post_id = $this->foreign_authored_post();

		$this->acting_as( 'aafm_others_posts_revisions' );

		$this->assertTrue(
			aafm_perm_list_revisions( array( 'post_id' => $other_post_id ) ),
			'sanity: edit_others_posts alone genuinely passes the parent-editable gate revisions share with update-post.'
		);

		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/list-revisions' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-revision' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/restore-revision' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-revision' ) );
	}

	/**
	 * FINDING 2: add-post-terms shares the same per-object edit floor as update-post.
	 */
	public function test_add_post_terms_discoverable_with_edit_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_terms',
			'AAFM Others-Posts Terms',
			array(
				'read'              => true,
				'edit_others_posts' => true,
			)
		);

		$other_post_id = $this->foreign_authored_post();

		$this->acting_as( 'aafm_others_posts_terms' );

		$this->assertTrue( aafm_perm_add_post_terms( array( 'post_id' => $other_post_id ) ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/add-post-terms' ) );
	}

	/**
	 * FINDING 2: reusable blocks (get-block/update-block/delete-block) reuse the SAME literal
	 * edit_posts/edit_others_posts/edit_published_posts and delete_ equivalents, since wp_block is
	 * registered with those exact primitive names (confirmed against core's
	 * create_initial_post_types()).
	 */
	public function test_update_block_discoverable_with_edit_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_blocks',
			'AAFM Others-Posts Blocks',
			array(
				'read'              => true,
				'edit_others_posts' => true,
			)
		);

		$other_block_id = $this->foreign_authored_post( 'wp_block' );

		$this->acting_as( 'aafm_others_posts_blocks' );

		$this->assertTrue( aafm_perm_block_object( array( 'block_id' => $other_block_id ) ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-block' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-block' ) );
	}

	public function test_delete_block_discoverable_with_delete_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_blocks_del',
			'AAFM Others-Posts Blocks Delete',
			array(
				'read'                => true,
				'delete_others_posts' => true,
			)
		);

		$other_block_id = $this->foreign_authored_post( 'wp_block' );

		$this->acting_as( 'aafm_others_posts_blocks_del' );

		$this->assertTrue( aafm_perm_block_delete_object( array( 'block_id' => $other_block_id ) ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-block' ) );
	}

	/**
	 * FINDING 2: every per-plugin SEO integration (Yoast / Rank Math / AIOSEO) shares the same
	 * aafm_perm_seo_post_object -> aafm_can_edit_post_object gate as update-post. Testing one
	 * representative ability per vendor proves the shared closure; no vendor plugin needs to be
	 * active, since the permission_callback itself carries no vendor-active check.
	 */
	public function test_yoast_update_post_discoverable_with_edit_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_seo',
			'AAFM Others-Posts SEO',
			array(
				'read'              => true,
				'edit_others_posts' => true,
			)
		);

		$other_post_id = $this->foreign_authored_post();

		$this->acting_as( 'aafm_others_posts_seo' );

		$this->assertTrue( aafm_perm_seo_post_object( array( 'post_id' => $other_post_id ) ) );

		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/yoast-get-post' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/yoast-update-post' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/rankmath-get-post' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/rankmath-update-post' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/rankmath-get-schema' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/rankmath-update-schema' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/aioseo-get-post' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/aioseo-update-post' ) );
	}

	/**
	 * FINDING 2: ACF post fields share the same shared content-edit gate as the SEO family.
	 */
	public function test_acf_update_post_fields_discoverable_with_edit_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_acf',
			'AAFM Others-Posts ACF',
			array(
				'read'              => true,
				'edit_others_posts' => true,
			)
		);

		$other_post_id = $this->foreign_authored_post();

		$this->acting_as( 'aafm_others_posts_acf' );

		$this->assertTrue( aafm_perm_acf_post( array( 'post_id' => $other_post_id ) ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/acf-get-post-fields' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/acf-update-post-fields' ) );
	}

	/**
	 * FINDING 2, the severe mismatch: ACF term fields and the term-meta family gate per-object on
	 * edit_term, which resolves through map_meta_cap to the TARGET TAXONOMY's own edit_terms
	 * capability - not edit_posts at all. A role holding a custom taxonomy's decoupled edit_terms
	 * cap, but no edit_posts, was hidden from a tool it could genuinely execute; a Contributor
	 * (edit_posts, no manage_categories) was shown a tool it could never execute on any term. The
	 * fix mirrors the create-term/update-term taxonomy loop already established for manage_terms.
	 */
	public function test_term_meta_discoverable_with_custom_taxonomy_edit_terms_cap(): void {
		register_taxonomy(
			'aafm_genre_meta',
			'post',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'capabilities' => array( 'edit_terms' => 'edit_aafm_genre_meta' ),
			)
		);
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user_id )->add_cap( 'edit_aafm_genre_meta' );
		wp_set_current_user( $user_id );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'aafm_genre_meta' ) );

		$this->assertFalse( current_user_can( 'edit_posts' ), 'sanity: this role holds no edit_posts, only the custom taxonomy cap.' );
		$this->assertTrue(
			aafm_perm_get_term_meta(
				array(
					'taxonomy' => 'aafm_genre_meta',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test input, not a meta query.
				)
			),
			'sanity: the decoupled edit_terms cap genuinely clears the EXECUTE-time gate on this taxonomy\'s term.'
		);

		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/get-term-meta' ),
			'discovery must not hide term-meta from a role holding a custom taxonomy\'s own edit_terms cap.'
		);
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-term-meta' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-term-meta' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/acf-get-term-fields' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/acf-update-term-fields' ) );

		// A Contributor (edit_posts, no manage_categories anywhere) must NOT discover term-meta
		// any more - the old edit_posts-based floor was neither necessary nor sufficient.
		$this->acting_as( 'contributor' );
		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/get-term-meta' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/acf-get-term-fields' ) );

		remove_all_filters( 'aafm_allowed_term_meta_keys' );
		unregister_taxonomy( 'aafm_genre_meta' );
	}

	/**
	 * FINDING 2, the non-viable-arm variant (same class as FINDING 1, different family): media
	 * writes previously OR'd in upload_files, which never appears anywhere in map_meta_cap's
	 * resolution of edit_post/delete_post for an attachment. A role holding upload_files WITHOUT
	 * any of edit_posts/edit_others_posts/edit_published_posts was shown update-media/delete-media
	 * despite being unable to execute either on any attachment.
	 */
	public function test_update_media_no_longer_discoverable_on_upload_files_alone(): void {
		add_role(
			'aafm_upload_only',
			'AAFM Upload Only',
			array(
				'read'         => true,
				'upload_files' => true,
			)
		);

		$this->acting_as( 'aafm_upload_only' );

		$att_id = (int) self::factory()->attachment->create_object(
			'upload-only-probe.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_author'    => get_current_user_id(),
			)
		);

		$this->assertFalse(
			current_user_can( 'edit_post', $att_id ),
			'sanity: upload_files alone never satisfies the EXECUTE-time per-object gate, even on the caller\'s own upload.'
		);

		$this->assertFalse(
			aafm_user_can_discover_ability( 'aafm/update-media' ),
			'discovery must not show a tool that upload_files alone can never execute.'
		);
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/delete-media' ) );
	}

	/**
	 * FINDING 2: with the non-viable upload_files arm removed, update-media/delete-media still
	 * need the SAME widened edit/delete floor as update-post/trash-post, since attachments reuse
	 * the identical literal primitive cap names.
	 */
	public function test_update_media_discoverable_with_edit_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_media',
			'AAFM Others-Posts Media',
			array(
				'read'              => true,
				'edit_others_posts' => true,
			)
		);

		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$att_id       = (int) self::factory()->attachment->create_object(
			'others-post-probe.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_author'    => $other_author,
			)
		);

		$this->acting_as( 'aafm_others_posts_media' );

		$this->assertTrue( current_user_can( 'edit_post', $att_id ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-media' ) );
	}

	public function test_delete_media_discoverable_with_delete_others_posts_alone(): void {
		add_role(
			'aafm_others_posts_media_del',
			'AAFM Others-Posts Media Delete',
			array(
				'read'                => true,
				'delete_others_posts' => true,
			)
		);

		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$att_id       = (int) self::factory()->attachment->create_object(
			'others-post-probe-del.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_author'    => $other_author,
			)
		);

		$this->acting_as( 'aafm_others_posts_media_del' );

		$this->assertTrue( current_user_can( 'delete_post', $att_id ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-media' ) );
	}

	/**
	 * Fix round 3, ITEM 1 (MEDIUM): create-cpt-item previously checked the literal core
	 * 'edit_posts' string only, which is wrong for a CPT registered with its own
	 * capability_type - a role holding that type's own edit_posts-equivalent cap (e.g.
	 * edit_aafm_widgets) but not literal edit_posts could genuinely create a draft item of that
	 * type, yet was hidden from the tool.
	 */
	public function test_create_cpt_item_discoverable_with_custom_capability_type_alone(): void {
		register_post_type(
			'aafm_widget',
			array(
				'public'          => true,
				'map_meta_cap'    => true,
				'capability_type' => array( 'aafm_widget', 'aafm_widgets' ),
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'aafm_widget' ) );

		add_role(
			'aafm_widget_author',
			'AAFM Widget Author',
			array(
				'read'              => true,
				'edit_aafm_widgets' => true,
			)
		);
		$this->acting_as( 'aafm_widget_author' );

		$this->assertFalse( current_user_can( 'edit_posts' ), 'sanity: this role holds no literal edit_posts, only the CPT\'s own cap.' );
		$this->assertTrue(
			aafm_perm_create_cpt_item( array( 'post_type' => 'aafm_widget' ) ),
			'sanity: the CPT\'s own edit_posts-equivalent cap genuinely clears the EXECUTE-time gate.'
		);

		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/create-cpt-item' ),
			'discovery must not hide create-cpt-item from a role that can genuinely create an item of an allowlisted CPT.'
		);

		unregister_post_type( 'aafm_widget' );
		delete_option( 'aafm_allowed_post_types' );
	}

	/**
	 * Fix round 3, ITEM 1 (MEDIUM): update-cpt-item has the identical per-object edit_post shape
	 * as update-post (aafm_can_edit_post_object), but was sharing create-cpt-item's bare literal
	 * edit_posts check - wrong for the same custom-capability_type reason, and additionally
	 * missing the edit_others/edit_published widening every sibling per-object case already got
	 * in round 2.
	 */
	public function test_update_cpt_item_discoverable_with_custom_capability_type_others_cap_alone(): void {
		register_post_type(
			'aafm_widget',
			array(
				'public'          => true,
				'map_meta_cap'    => true,
				'capability_type' => array( 'aafm_widget', 'aafm_widgets' ),
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'aafm_widget' ) );

		add_role(
			'aafm_widget_others_editor',
			'AAFM Widget Others Editor',
			array(
				'read'                     => true,
				'edit_others_aafm_widgets' => true,
			)
		);

		$other_widget_id = $this->foreign_authored_post( 'aafm_widget' );

		$this->acting_as( 'aafm_widget_others_editor' );

		$this->assertFalse( current_user_can( 'edit_aafm_widgets' ), 'sanity: this role lacks the CPT\'s bare edit cap.' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- test-only custom cap, registered above via capability_type, not a WP core capability.
		$this->assertTrue(
			aafm_can_edit_post_object( get_post( $other_widget_id ) ),
			'sanity: the CPT\'s own edit_others-equivalent cap genuinely passes the EXECUTE-time per-object gate.'
		);

		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/update-cpt-item' ),
			'discovery must not hide update-cpt-item from a role that can genuinely edit someone else\'s item of an allowlisted CPT.'
		);

		unregister_post_type( 'aafm_widget' );
		delete_option( 'aafm_allowed_post_types' );
	}

	/**
	 * Fix round 3, ITEM 3 (LOW): aafm_perm_acf_term() accepts any EXISTING term and checks
	 * edit_term($id) directly, with no aafm_validate_taxonomy()-style public-taxonomy
	 * restriction (unlike term-meta, which routes through aafm_validate_term_meta_request() and
	 * DOES enforce that restriction). So the taxonomy loop ACF term-fields previously shared with
	 * term-meta was too narrow for it: a user whose only usable taxonomy is non-public can
	 * genuinely execute ACF term-fields but was hidden from discovering it.
	 */
	public function test_acf_term_fields_discoverable_on_non_public_taxonomy(): void {
		register_taxonomy(
			'aafm_private_genre',
			'post',
			array(
				'public'       => false,
				'capabilities' => array( 'edit_terms' => 'edit_aafm_private_genre' ),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user_id )->add_cap( 'edit_aafm_private_genre' );
		wp_set_current_user( $user_id );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'aafm_private_genre' ) );

		$this->assertTrue(
			aafm_perm_acf_term( array( 'term_id' => $term_id ) ),
			'sanity: aafm_perm_acf_term has no public-taxonomy restriction, so this genuinely passes at execute time.'
		);

		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/acf-get-term-fields' ),
			'discovery must not hide ACF term-fields from a role that can genuinely edit a term in a non-public taxonomy.'
		);
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/acf-update-term-fields' ) );

		// term-meta stays hidden for the SAME non-public taxonomy: its own execute-time gate
		// (aafm_validate_term_meta_request -> aafm_validate_taxonomy) genuinely denies a
		// non-public taxonomy, so discovery correctly disagrees with ACF term-fields here.
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/get-term-meta' ) );

		unregister_taxonomy( 'aafm_private_genre' );
	}
}
