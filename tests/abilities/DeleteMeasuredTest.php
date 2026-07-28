<?php
/**
 * The four delete abilities the 1.4.2-era delete-capability audit reasoned about rather
 * than measured: delete-comment, delete-revision, delete-menu (and delete-menu-item),
 * and delete-user. Reasoning is not evidence; each gets a real test exercising the
 * actual permission gate.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class DeleteMeasuredTest extends TestCase {

	/**
	 * The delete-comment ability (aafm_perm_edit_comment_obj): moderate_comments is a floor, but the
	 * caller must ALSO be able to edit the comment's parent post (edit_comment maps
	 * through the parent's edit_post meta cap). Measured rather than assumed: a role
	 * granted moderate_comments plus only its OWN edit_posts (not edit_others_posts) can
	 * moderate a comment on its own post but not on another user's post.
	 */
	public function test_delete_comment_requires_moderate_comments_and_editable_parent(): void {
		// Contributor already holds edit_posts natively (its default role definition);
		// only moderate_comments is added here, and only that is removed afterward - a
		// stray remove_cap( 'edit_posts' ) would strip a capability the role is
		// SUPPOSED to have, corrupting it for every later test in the same process.
		$moderator = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$role      = get_role( 'contributor' );
		$role->add_cap( 'moderate_comments' );

		// Draft, not the factory's published default: a contributor-level role has no
		// publish_posts, so its OWN posts are realistically drafts, and edit_post on an
		// owned draft needs only edit_posts - an owned PUBLISHED post needs
		// edit_published_posts too, which this role deliberately does not hold.
		$own_post      = self::factory()->post->create(
			array(
				'post_author' => $moderator,
				'post_status' => 'draft',
			)
		);
		$own_comment   = self::factory()->comment->create( array( 'comment_post_ID' => $own_post ) );
		$other_author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_post    = self::factory()->post->create( array( 'post_author' => $other_author ) );
		$other_comment = self::factory()->comment->create( array( 'comment_post_ID' => $other_post ) );

		wp_set_current_user( $moderator );

		$this->assertTrue(
			aafm_perm_edit_comment_obj( array( 'comment_id' => $own_comment ) ),
			'moderate_comments plus an editable parent (the caller\'s own post) must be allowed.'
		);
		$this->assertFalse(
			aafm_perm_edit_comment_obj( array( 'comment_id' => $other_comment ) ),
			'moderate_comments alone, without edit_others_posts, must not authorize moderating a comment on another user\'s post.'
		);

		$role->remove_cap( 'moderate_comments' );
	}

	/**
	 * A bare floor without moderate_comments is denied outright, regardless of whether
	 * the caller could otherwise edit the parent post.
	 */
	public function test_delete_comment_denies_without_moderate_comments(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post    = self::factory()->post->create( array( 'post_author' => $author ) );
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		wp_set_current_user( $author );

		$this->assertFalse(
			aafm_perm_edit_comment_obj( array( 'comment_id' => $comment ) ),
			'edit_posts on the parent is not enough without moderate_comments.'
		);
	}

	/**
	 * The delete-revision ability (aafm_perm_delete_revision): the SAME gate as restore - the
	 * parent post must be editable by the caller, AND the revision must genuinely be a
	 * child of that exact post. An author can delete a revision of their own post; an
	 * author cannot delete a revision of another author's post; a revision that belongs
	 * to a DIFFERENT post than the one named is rejected even for an otherwise-eligible
	 * caller (rules out a cross-post revision-id mixup).
	 */
	public function test_delete_revision_requires_an_editable_parent_and_a_genuine_child_revision(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$own_post = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'v1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $own_post,
				'post_content' => 'v2',
			)
		);
		$own_revisions = array_values( wp_get_post_revisions( $own_post, array( 'fields' => 'ids' ) ) );
		$own_revision  = (int) $own_revisions[0];

		$this->assertTrue(
			aafm_perm_delete_revision(
				array(
					'post_id'     => $own_post,
					'revision_id' => $own_revision,
				)
			),
			'An author must be able to delete a revision of their own post.'
		);

		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_post   = self::factory()->post->create(
			array(
				'post_author'  => $other_author,
				'post_content' => 'v1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $other_post,
				'post_content' => 'v2',
			)
		);
		$other_revisions = array_values( wp_get_post_revisions( $other_post, array( 'fields' => 'ids' ) ) );
		$other_revision  = (int) $other_revisions[0];

		$this->assertFalse(
			aafm_perm_delete_revision(
				array(
					'post_id'     => $other_post,
					'revision_id' => $other_revision,
				)
			),
			"An author must not be able to delete a revision of another author's post."
		);

		// Cross-post mixup: the caller's OWN editable post_id, but a revision_id that
		// actually belongs to someone else's post. The parent-editable check alone would
		// wrongly pass this; aafm_validate_revision()'s parent-match must refuse it.
		$this->assertFalse(
			aafm_perm_delete_revision(
				array(
					'post_id'     => $own_post,
					'revision_id' => $other_revision,
				)
			),
			'A revision that does not belong to the named post must be rejected even when the named post is editable.'
		);
	}

	/**
	 * The delete-menu / delete-menu-item abilities (aafm_perm_edit_theme_options): a static
	 * capability floor with no per-object check at all, "correct by design" because a
	 * nav menu is a site-wide resource with no per-user ownership concept in WordPress's
	 * model - measured here rather than left as an assumption. A caller without
	 * edit_theme_options is denied; a caller who holds it can act on ANY menu regardless
	 * of who created it, because there is no author field to gate on.
	 */
	public function test_delete_menu_is_gated_on_the_site_wide_floor_with_no_per_object_check(): void {
		$without = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $without );
		$this->assertFalse(
			aafm_perm_edit_theme_options(),
			'editor lacks edit_theme_options by default and must be denied.'
		);

		$with = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $with );
		$this->assertTrue(
			aafm_perm_edit_theme_options(),
			'administrator holds edit_theme_options and must be allowed.'
		);

		// No object-awareness to measure against: menus carry no author, so there is no
		// "someone else's menu" case the gate could fail to check. Confirmed by reading
		// aafm_perm_edit_theme_options()'s signature (menus.php) - it takes no $input and
		// therefore cannot resolve a specific menu id even if it wanted to.
		$this->assertSame(
			0,
			( new \ReflectionFunction( 'aafm_perm_edit_theme_options' ) )->getNumberOfParameters(),
			'aafm_perm_edit_theme_options() takes no input, confirming the floor-only design is structural, not an oversight.'
		);
	}

	/**
	 * The delete-user ability (aafm_perm_delete_user): delete_users AND the delete_user meta cap on
	 * the target id. Measured finding: on a single-site install, WordPress's own
	 * map_meta_cap() maps 'delete_user' straight to 'delete_users' with no per-object
	 * distinction (capabilities.php: "delete_user maps to delete_users" outside
	 * multisite) - so the per-object half of this gate adds no extra restriction beyond
	 * the floor here. The real protections against deleting an inappropriate account
	 * (the last-admin lock, the can't-delete-self guard) live in the EXECUTE callback,
	 * not in this permission gate. Confirmed directly rather than assumed: an
	 * administrator with the floor can target another user of any role.
	 */
	public function test_delete_user_floor_is_the_real_gate_on_single_site(): void {
		$non_admin = self::factory()->user->create( array( 'role' => 'editor' ) );
		$target    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $non_admin );
		$this->assertFalse(
			aafm_perm_delete_user( array( 'user_id' => $target ) ),
			'A caller without delete_users must be denied regardless of the target.'
		);

		$admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue(
			aafm_perm_delete_user( array( 'user_id' => $target ) ),
			'A caller with delete_users is authorized against a lower-role target.'
		);
		$this->assertTrue(
			aafm_perm_delete_user( array( 'user_id' => $other_admin ) ),
			"On single-site, delete_user carries no per-object restriction beyond the floor: WordPress's own map_meta_cap() maps it straight to delete_users, so an administrator is authorized here even against ANOTHER administrator. The last-admin lock that actually prevents this lives in the execute callback, not this permission gate."
		);
	}
}
