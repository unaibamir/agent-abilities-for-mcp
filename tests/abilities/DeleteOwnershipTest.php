<?php
/**
 * Delete/trash per-object ownership: the proofs from the 1.4.2-era delete-capability
 * audit, ported into the permanent suite.
 *
 * That audit ran six PHPUnit assertions in a temporary probe file, confirmed every one
 * green, then deleted the file per its "no source edits, no artifacts left behind"
 * brief - so the proof existed only for the duration of that one session. This file is
 * the permanent replacement: the same six assertions, kept in the suite instead of a
 * one-off.
 *
 * The audit's verdict: every destructive ability gates through
 * aafm_can_delete_post_object() (includes/helpers.php), which resolves the OBJECT's own
 * post type capability names and calls current_user_can( $type->cap->delete_post, $id ),
 * routing through core's map_meta_cap() so ownership (delete_others_X vs delete_X) and
 * status (delete_published_X) are resolved correctly rather than assumed. No hole found;
 * this file is the standing proof so a future change that weakens the gate fails here.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class DeleteOwnershipTest extends TestCase {

	/**
	 * Proof 1 (positive control): an author trashing/deleting their OWN published post is
	 * allowed. Proves the gate isn't a blanket denial - it must pass a real owner.
	 */
	public function test_author_may_trash_and_delete_their_own_published_post(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post   = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $author );

		$this->assertTrue( aafm_perm_trash_post( array( 'post_id' => $post ) ) );
		$this->assertTrue( aafm_perm_delete_post( array( 'post_id' => $post ) ) );
	}

	/**
	 * Proof 2: an author trashing/deleting an EDITOR's published post is denied. This is
	 * the exact "low-priv user destroys another user's content" scenario the audit set
	 * out to test, on both the recoverable and permanent paths.
	 */
	public function test_author_cannot_trash_or_delete_an_editors_published_post(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post   = self::factory()->post->create(
			array(
				'post_author' => $editor,
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $author );

		$this->assertFalse( aafm_perm_trash_post( array( 'post_id' => $post ) ) );
		$this->assertFalse( aafm_perm_delete_post( array( 'post_id' => $post ) ) );
	}

	/**
	 * Proof 3: a contributor trashing/deleting another author's DRAFT is denied. Rules
	 * out delete_posts alone (which a contributor holds) being sufficient - the gate
	 * must also fail on the draft/ownership combination, not only on publish status.
	 */
	public function test_contributor_cannot_trash_or_delete_another_authors_draft(): void {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );
		$draft       = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( $contributor );

		$this->assertFalse( aafm_perm_trash_post( array( 'post_id' => $draft ) ) );
		$this->assertFalse( aafm_perm_delete_post( array( 'post_id' => $draft ) ) );
	}

	/**
	 * Proof 4: media ownership. A contributor cannot delete another author's attachment;
	 * an author can delete their own.
	 */
	public function test_media_delete_is_gated_on_attachment_ownership(): void {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );

		$theirs = (int) self::factory()->attachment->create_object(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_author'    => $author,
			)
		);
		$mine   = (int) self::factory()->attachment->create_object(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_author'    => $author,
			)
		);

		wp_set_current_user( $contributor );
		$this->assertFalse( aafm_perm_delete_media( array( 'attachment_id' => $theirs ) ) );

		wp_set_current_user( $author );
		$this->assertTrue( aafm_perm_delete_media( array( 'attachment_id' => $mine ) ) );
	}

	/**
	 * Proof 5: a custom capability_type CPT, distinct from the literal '_posts' names,
	 * proves aafm_can_delete_post_object() resolves the TYPE'S OWN capability names via
	 * $post_type->cap->delete_post (which core's map_meta_cap() then aliases to the
	 * type's own delete_others_X / delete_published_X) rather than a hardcoded
	 * 'delete_others_posts' string that would silently misjudge a type with its own
	 * capability_type.
	 *
	 * Negative: an author-role agent granted the type's bare base caps (edit/delete/
	 * publish on itself) but NOT the type's own others/published caps is denied deleting
	 * another author's published item - the footgun a hardcoded cap name would miss.
	 * Positive control: granting exactly those two type-specific caps flips it to
	 * allowed, proving the code read them by name rather than failing open or closed
	 * for an unrelated reason.
	 */
	public function test_custom_capability_type_cpt_resolves_its_own_cap_names(): void {
		register_post_type(
			'aafm_ledger_probe',
			array(
				'public'          => true,
				'map_meta_cap'    => true,
				'capability_type' => array( 'aafm_ledger_probe', 'aafm_ledger_probes' ),
				'label'           => 'Ledger Probes',
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'aafm_ledger_probe' ) );

		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$item  = self::factory()->post->create(
			array(
				'post_type'   => 'aafm_ledger_probe',
				'post_status' => 'publish',
				'post_author' => $owner,
			)
		);

		$agent = self::factory()->user->create( array( 'role' => 'author' ) );
		$role  = get_role( 'author' );
		$role->add_cap( 'edit_aafm_ledger_probes' );
		$role->add_cap( 'delete_aafm_ledger_probes' );
		$role->add_cap( 'publish_aafm_ledger_probes' );
		wp_set_current_user( $agent );

		$this->assertFalse(
			aafm_perm_delete_post( array( 'post_id' => $item ) ),
			"The type's own base caps must not be sufficient to delete another author's published item."
		);

		$role->add_cap( 'delete_others_aafm_ledger_probes' );
		$role->add_cap( 'delete_published_aafm_ledger_probes' );

		// Same acting-as user id: has_cap()'s allcaps cache must reflect the role change
		// (the audit's own recorded gotcha - re-resolve current_user() rather than reuse
		// a stale cached WP_User).
		wp_set_current_user( 0 );
		wp_set_current_user( $agent );

		$this->assertTrue(
			aafm_perm_delete_post( array( 'post_id' => $item ) ),
			"Granting the type's OWN others/published caps must flip this to allowed - proves the code reads the type's cap names, not a hardcoded 'delete_others_posts'."
		);

		$role->remove_cap( 'edit_aafm_ledger_probes' );
		$role->remove_cap( 'delete_aafm_ledger_probes' );
		$role->remove_cap( 'publish_aafm_ledger_probes' );
		$role->remove_cap( 'delete_others_aafm_ledger_probes' );
		$role->remove_cap( 'delete_published_aafm_ledger_probes' );
	}

	/**
	 * Proof 6: reusable-block (wp_block) ownership. A contributor cannot delete another
	 * author's block.
	 */
	public function test_contributor_cannot_delete_another_authors_reusable_block(): void {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );
		$block       = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			)
		);

		wp_set_current_user( $contributor );
		$this->assertFalse( aafm_perm_block_delete_object( array( 'block_id' => $block ) ) );
	}
}
