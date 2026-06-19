<?php
/**
 * Slice B: the reusable-block (wp_block) read + write abilities.
 *
 * Covers the lean/rich block assemblers and block-object resolver, the list-blocks and
 * get-block reads, the kses-hardened create-block/update-block writes with forced type and
 * author plus closed-schema smuggle rejection, the per-object edit_block/delete_block gates,
 * and the trash-only delete-block with its trash-disabled refusal.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class BlocksTest extends TestCase {

	private function make_block( string $title = 'CTA', string $content = '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	public function test_lean_redactor_has_no_content_and_rich_adds_markup(): void {
		$id   = $this->make_block();
		$lean = aafm_redact_block( get_post( $id ) );
		$this->assertSame( array( 'id', 'title', 'slug', 'status', 'modified' ), array_keys( $lean ) );
		$this->assertArrayNotHasKey( 'content', $lean, 'the list redactor must not carry block markup.' );

		$rich = aafm_rich_block( get_post( $id ) );
		$this->assertArrayHasKey( 'content', $rich );
		$this->assertStringContainsString( 'wp:paragraph', $rich['content'], 'rich block must expose the raw block markup.' );
	}

	public function test_block_object_resolver_rejects_a_non_block_post(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertNull( aafm_get_block_object( $post_id ), 'a normal post is not a wp_block.' );
		$block_id = $this->make_block();
		$this->assertInstanceOf( \WP_Post::class, aafm_get_block_object( $block_id ) );
	}

	private function in_action( string $action, callable $cb ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$cb();
		array_pop( $wp_current_filter );
	}

	private function register_blocks(): void {
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/list-blocks', 'aafm/get-block', 'aafm/create-block', 'aafm/update-block', 'aafm/delete-block' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_list_blocks_requires_edit_posts_and_returns_lean_rows(): void {
		$this->register_blocks();
		$this->make_block( 'Alpha' );
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue( wp_get_ability( 'aafm/list-blocks' )->check_permissions( array() ) );

		$this->acting_as( 'editor' );
		$res = wp_get_ability( 'aafm/list-blocks' )->execute( array() );
		$this->assertArrayHasKey( 'blocks', $res );
		$this->assertArrayHasKey( 'total', $res );
		$this->assertArrayNotHasKey( 'content', $res['blocks'][0], 'list rows must be lean (no markup).' );
	}

	/**
	 * Enumeration scope: a contributor who owns block A but not block B (another author's)
	 * must see A and NOT B in list-blocks — the list is scoped to blocks the caller can edit.
	 * A contributor holds edit_posts (clears the floor) but lacks edit_others_posts, so
	 * map_meta_cap denies edit_post on a block they do not own — the same refinement the M-1
	 * gate relies on, applied here as a row filter so enumeration matches per-object access.
	 */
	public function test_list_blocks_scopes_to_blocks_the_caller_can_edit(): void {
		$this->register_blocks();
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$mine        = (int) self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'draft',
				'post_title'  => 'Mine',
				'post_author' => $contributor,
			)
		);
		$theirs      = (int) self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'draft',
				'post_title'  => 'Theirs',
				'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ),
			)
		);
		wp_set_current_user( $contributor );

		$res = wp_get_ability( 'aafm/list-blocks' )->execute( array() );
		$ids = wp_list_pluck( $res['blocks'], 'id' );
		$this->assertContains( $mine, $ids, 'the contributor must see a block they own and can edit.' );
		$this->assertNotContains( $theirs, $ids, "the contributor must NOT enumerate another author's draft block they cannot edit." );
		$this->assertSame( count( $res['blocks'] ), $res['total'], 'total must match the visible (filtered) rows.' );
	}

	public function test_get_block_returns_markup_and_rejects_a_non_block(): void {
		$this->register_blocks();
		$id = $this->make_block( 'Beta', '<!-- wp:heading --><h2>Hi</h2><!-- /wp:heading -->' );
		$this->acting_as( 'editor' );
		$res = wp_get_ability( 'aafm/get-block' )->execute( array( 'block_id' => $id ) );
		$this->assertStringContainsString( 'wp:heading', $res['content'] );

		$post_id = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertInstanceOf( \WP_Error::class, wp_get_ability( 'aafm/get-block' )->execute( array( 'block_id' => $post_id ) ) );
	}

	public function test_create_block_stores_kses_hardened_markup_and_forces_type(): void {
		$this->register_blocks();
		$this->acting_as( 'editor' );
		$res = wp_get_ability( 'aafm/create-block' )->execute(
			array(
				'title'   => 'Hero',
				'content' => '<!-- wp:paragraph --><p>Hi<script>alert(1)</script></p><!-- /wp:paragraph -->',
			)
		);
		$this->assertArrayHasKey( 'id', $res );
		$saved = get_post( $res['id'] );
		$this->assertSame( 'wp_block', $saved->post_type, 'type is forced to wp_block.' );
		$this->assertSame( (string) get_current_user_id(), (string) $saved->post_author, 'author is forced to the agent.' );
		$this->assertStringContainsString( 'wp:paragraph', $saved->post_content, 'block delimiters survive kses.' );
		$this->assertStringNotContainsString( '<script', $saved->post_content, 'script stripped by wp_kses_post.' );
	}

	public function test_create_block_rejects_smuggled_post_type(): void {
		$this->register_blocks();
		$this->acting_as( 'editor' );
		$res = wp_get_ability( 'aafm/create-block' )->execute(
			array(
				'title'     => 'x',
				'content'   => 'y',
				'post_type' => 'page',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $res, 'closed schema rejects a smuggled post_type.' );
	}

	public function test_update_block_edits_markup_with_per_object_gate(): void {
		$this->register_blocks();
		$id = $this->make_block( 'Old' );
		$this->acting_as( 'editor' );
		$res = wp_get_ability( 'aafm/update-block' )->execute(
			array(
				'block_id' => $id,
				'content'  => '<!-- wp:list --><ul><li>a</li></ul><!-- /wp:list -->',
			)
		);
		$this->assertStringContainsString( 'wp:list', get_post( $id )->post_content );

		// A subscriber cannot update (per-object edit_post denies).
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue( wp_get_ability( 'aafm/update-block' )->check_permissions( array( 'block_id' => $id ) ) );
	}

	/**
	 * M-1: prove the per-object gate REFINES the coarse floor — a user who clears the
	 * edit_posts discovery floor but lacks edit_block on a SPECIFIC block is denied at execute.
	 * (The subscriber case above only exercises the floor; this exercises the per-object
	 * refinement.) A contributor holds edit_posts but, lacking edit_others_posts, cannot edit
	 * a block they do not own — so map_meta_cap denies edit_block on this id.
	 */
	public function test_update_block_per_object_gate_denies_without_edit_block_on_the_id(): void {
		$this->register_blocks();
		$id = $this->make_block( 'Locked' );
		$this->acting_as( 'contributor' );
		$this->assertTrue(
			current_user_can( 'edit_posts' ),
			'a contributor must clear the object-independent edit_posts floor.'
		);
		$this->assertNotTrue(
			wp_get_ability( 'aafm/update-block' )->check_permissions( array( 'block_id' => $id ) ),
			'a user who clears the edit_posts floor but lacks edit_block on this id must be denied at execute.'
		);
	}

	public function test_delete_block_trashes_recoverably(): void {
		$this->register_blocks();
		$id = $this->make_block( 'Trashme' );
		$this->acting_as( 'editor' );
		$res = wp_get_ability( 'aafm/delete-block' )->execute( array( 'block_id' => $id ) );
		$this->assertNotInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'trash', get_post( $id )->post_status, 'block goes to trash, not permanent delete.' );
		$this->assertNotFalse( wp_untrash_post( $id ), 'trashed block is recoverable.' );
	}

	public function test_delete_block_refuses_a_non_block(): void {
		$this->register_blocks();
		$post_id = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->acting_as( 'editor' );
		$this->assertInstanceOf( \WP_Error::class, wp_get_ability( 'aafm/delete-block' )->execute( array( 'block_id' => $post_id ) ) );
	}

	public function test_delete_block_refuses_when_trash_is_disabled(): void {
		$this->register_blocks();
		$id = $this->make_block();
		add_filter( 'aafm_trash_is_enabled', '__return_false' );
		$this->acting_as( 'editor' );
		$res = wp_get_ability( 'aafm/delete-block' )->execute( array( 'block_id' => $id ) );
		remove_filter( 'aafm_trash_is_enabled', '__return_false' );
		$this->assertInstanceOf( \WP_Error::class, $res, 'must refuse to "trash" when trash is disabled (it would force-delete).' );
		$this->assertNotSame( 'trash', get_post( $id )->post_status );
	}
}
