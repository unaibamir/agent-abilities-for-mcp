<?php
/**
 * The tools/list DISCOVERY check is decoupled from per-object EXECUTE authorization.
 *
 * Several abilities gate execution on a per-object capability that needs an id from the
 * input (e.g. edit_post( $post_id )). The tools/list visibility check runs with EMPTY
 * input, so a naive "can call with empty input" test hid those tools from fully capable
 * users — they became undiscoverable. The discovery layer uses an object-independent
 * predicate so a capable user SEES the tool, while the real permission_callback still
 * runs at execute time and still denies on objects the user can't act on.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;

final class ServerDiscoveryTest extends TestCase {

	/**
	 * The per-object abilities that were hidden from capable users before the fix.
	 *
	 * @var string[]
	 */
	private const PER_OBJECT_ABILITIES = array(
		'oversio/get-post',
		'oversio/get-page',
		'oversio/update-post',
		'oversio/trash-post',
		'oversio/update-page',
		'oversio/trash-page',
		'oversio/set-featured-image',
		'oversio/moderate-comment',
		'oversio/get-post-meta',
		'oversio/update-post-meta',
		'oversio/delete-post-meta',
	);

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check + execute to the
		// custom table, so it must exist before any ability registers or runs.
		oversio_install_activity_log();
		oversio_clear_activity_log();
		$this->register_whole_catalog();
	}

	/**
	 * Enable and register the real 24-ability catalog (the same way CatalogTest does).
	 */
	private function register_whole_catalog(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option( 'oversio_enabled_abilities', array_keys( oversio_get_abilities_registry() ) );
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
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
			 * Stub Tool DTO.
			 *
			 * @param string $name Tool name.
			 */
			public function __construct( private string $name ) {}

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
		foreach ( oversio_get_enabled_abilities() as $ability_name ) {
			$tools[] = $this->tool_dto( oversio_mcp_tool_name( $ability_name ) );
		}
		$visible = oversio_filter_mcp_tools_list( $tools );

		$names = array();
		foreach ( (array) $visible as $tool ) {
			$names[] = $tool->getName();
		}
		return $names;
	}

	public function test_capable_user_discovers_per_object_post_tools(): void {
		// An editor holds edit_posts / delete_posts / moderate_comments and edit_pages /
		// delete_pages — the floor caps the per-object branches refine — so they must SEE
		// every per-object tool in tools/list even though no object id is supplied.
		$this->acting_as( 'editor' );

		$names = $this->visible_tool_names();

		foreach ( self::PER_OBJECT_ABILITIES as $ability ) {
			$this->assertContains(
				oversio_mcp_tool_name( $ability ),
				$names,
				$ability . ' should be discoverable for a capable user (editor)'
			);
		}
	}

	public function test_administrator_discovers_governed_post_meta_tools(): void {
		// Governed post-meta gates on per-object edit_post (reads included). An administrator
		// holds edit_posts, so the coarse discovery floor passes and all three meta tools must
		// appear in the real tools/list the adapter filter produces — the ship-blocker check.
		$this->acting_as( 'administrator' );

		$names = $this->visible_tool_names();

		$this->assertContains( oversio_mcp_tool_name( 'oversio/get-post-meta' ), $names );
		$this->assertContains( oversio_mcp_tool_name( 'oversio/update-post-meta' ), $names );
		$this->assertContains( oversio_mcp_tool_name( 'oversio/delete-post-meta' ), $names );
	}

	public function test_author_discovers_update_and_trash_and_get_post(): void {
		// An author has edit_posts + delete_posts + read, so the post-side per-object tools
		// surface for them too. Authors lack moderate_comments and the page caps, which are
		// asserted separately.
		$this->acting_as( 'author' );

		$names = $this->visible_tool_names();

		$this->assertContains( oversio_mcp_tool_name( 'oversio/update-post' ), $names );
		$this->assertContains( oversio_mcp_tool_name( 'oversio/trash-post' ), $names );
		$this->assertContains( oversio_mcp_tool_name( 'oversio/get-post' ), $names );
		$this->assertContains( oversio_mcp_tool_name( 'oversio/get-page' ), $names );
		$this->assertContains( oversio_mcp_tool_name( 'oversio/set-featured-image' ), $names );
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
			oversio_mcp_tool_name( 'oversio/update-post' ),
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
			oversio_perm_update_post( array( 'post_id' => $other_post_id ) ),
			'the EXECUTE-time per-object permission must still deny on a post the user cannot edit'
		);

		unset( $contributor_id );
	}

	public function test_subscriber_does_not_discover_write_tools(): void {
		// A subscriber lacks edit_posts / delete_posts / moderate_comments and the page caps,
		// so the discovery predicate denies — none of the per-object WRITE tools appear.
		$this->acting_as( 'subscriber' );

		$names = $this->visible_tool_names();

		$this->assertNotContains( oversio_mcp_tool_name( 'oversio/update-post' ), $names );
		$this->assertNotContains( oversio_mcp_tool_name( 'oversio/trash-post' ), $names );
		$this->assertNotContains( oversio_mcp_tool_name( 'oversio/update-page' ), $names );
		$this->assertNotContains( oversio_mcp_tool_name( 'oversio/trash-page' ), $names );
		$this->assertNotContains( oversio_mcp_tool_name( 'oversio/set-featured-image' ), $names );
		$this->assertNotContains( oversio_mcp_tool_name( 'oversio/moderate-comment' ), $names );
	}

	public function test_discover_helper_falls_back_for_general_cap_abilities(): void {
		// For abilities with no per-object branch, discovery is the plain empty-input check —
		// behavior is unchanged. create-post gates on publish_posts: editor yes, subscriber no.
		$this->acting_as( 'editor' );
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/create-post' ) );

		$this->acting_as( 'subscriber' );
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/create-post' ) );
		// A subscriber can still discover the generic read (get-posts gates on 'read').
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/get-posts' ) );
	}

	public function test_unknown_ability_is_not_discoverable(): void {
		// No stashed callback and no list-permission override → fail closed.
		$this->acting_as( 'administrator' );
		$this->assertNull( oversio_ability_list_permission( 'oversio/does-not-exist' ) );
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/does-not-exist' ) );
	}

	public function test_discovery_check_does_not_log_denials(): void {
		// The discovery predicate must not write denied audit rows while building tools/list.
		$this->acting_as( 'subscriber' );
		$this->visible_tool_names();

		$denied = oversio_query_activity( array( 'status' => 'denied' ) );
		$this->assertCount( 0, (array) $denied, 'tools/list discovery must not audit denials' );
	}

	public function test_replace_in_post_discoverable_at_edit_posts_floor(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/replace-in-post' ) );

		$this->acting_as( 'author' ); // has edit_posts.
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/replace-in-post' ) );
	}

	public function test_get_all_post_meta_discoverable_at_edit_posts_floor(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( oversio_user_can_discover_ability( 'oversio/get-all-post-meta' ) );

		$this->acting_as( 'author' );
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/get-all-post-meta' ) );
	}

	public function test_count_posts_discoverable_at_read_floor(): void {
		$this->acting_as( 'subscriber' ); // subscriber has 'read'.
		$this->assertTrue( oversio_user_can_discover_ability( 'oversio/count-posts' ) );
	}
}
