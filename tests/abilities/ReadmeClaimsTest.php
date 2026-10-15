<?php
/**
 * Readme permission claims that had no test naming them (1.8.0 readme-against-behaviour audit).
 *
 * Each test quotes the readme sentence it pins. A sentence that stops being true has to fail here
 * before it ships.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ReadmeClaimsTest extends TestCase {

	public function tear_down(): void {
		delete_option( 'aafm_force_draft' );
		parent::tear_down();
	}

	/**
	 * "An Editor account can write and publish posts but cannot install plugins or change settings."
	 */
	public function test_an_editor_cannot_discover_the_site_settings_ability_but_an_administrator_can(): void {
		$this->register_enabled( array( 'aafm/update-site-settings' ) );

		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/update-site-settings' ) );

		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-site-settings' ) );
	}

	/**
	 * "Anything the agent creates, and any request to publish, is saved as a draft." The create case
	 * is covered in SafetyEnforcementTest; this is the update-to-publish half, through the registered
	 * ability so the wrapper is in the path, and it checks the returned post as well as the stored one.
	 */
	public function test_force_draft_turns_an_update_to_publish_into_a_draft(): void {
		update_option( 'aafm_force_draft', true );
		$this->register_enabled( array( 'aafm/update-post' ) );
		$editor_id = $this->acting_as( 'editor' );
		$post_id   = (int) self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $editor_id,
			)
		);

		$result = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id' => $post_id,
				'status'  => 'publish',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'draft', $result['post']['status'] );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * "Edits to a post that is already published still go live." An edit that sends no status is
	 * not a request to publish, so force draft leaves the post published.
	 */
	public function test_force_draft_leaves_an_edit_to_a_published_post_live(): void {
		update_option( 'aafm_force_draft', true );
		$this->register_enabled( array( 'aafm/update-post' ) );
		$editor_id = $this->acting_as( 'editor' );
		$post_id   = (int) self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $editor_id,
				'post_title'  => 'Before',
			)
		);

		$result = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'After',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'publish', $result['post']['status'] );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( 'After', get_the_title( $post_id ) );
	}

	/**
	 * "...unless the edit asks for a publish status again, which is also saved as a draft." The other
	 * half of the sentence above: force draft treats an explicit publish request as a request to
	 * publish, even when the post is already live.
	 */
	public function test_force_draft_saves_a_repeated_publish_request_as_a_draft(): void {
		update_option( 'aafm_force_draft', true );
		$this->register_enabled( array( 'aafm/update-post' ) );
		$editor_id = $this->acting_as( 'editor' );
		$post_id   = (int) self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $editor_id,
			)
		);

		$result = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id' => $post_id,
				'status'  => 'publish',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'draft', $result['post']['status'] );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * "An Editor can write, edit and publish any post; an Author only their own; a Contributor can
	 * write but not publish ... and cannot edit published posts at all." The update ability's
	 * permission check follows core's capabilities for each role.
	 */
	public function test_update_post_permission_follows_the_role_ladder_the_readme_describes(): void {
		$this->register_enabled( array( 'aafm/update-post' ) );
		$ability   = wp_get_ability( 'aafm/update-post' );
		$other_id  = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$others    = (int) self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $other_id,
			)
		);
		$publish_a = array(
			'post_id' => 0,
			'status'  => 'publish',
		);

		// Editor: any post, publish included.
		$this->acting_as( 'editor' );
		$this->assertTrue( $ability->check_permissions( array( 'post_id' => $others ) ) );

		// Author: not someone else's post; publishes their own draft.
		$author_id = $this->acting_as( 'author' );
		$own_draft = (int) self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $author_id,
			)
		);
		$this->assertFalse( $ability->check_permissions( array( 'post_id' => $others ) ) );
		$this->assertTrue( $ability->check_permissions( array_merge( $publish_a, array( 'post_id' => $own_draft ) ) ) );

		// Contributor: edits their own draft, cannot publish it, cannot touch it once it is live.
		$contributor_id = $this->acting_as( 'contributor' );
		$draft          = (int) self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $contributor_id,
			)
		);
		$live           = (int) self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $contributor_id,
			)
		);
		$this->assertTrue( $ability->check_permissions( array( 'post_id' => $draft ) ) );
		$this->assertFalse( $ability->check_permissions( array_merge( $publish_a, array( 'post_id' => $draft ) ) ) );
		$this->assertFalse( $ability->check_permissions( array( 'post_id' => $live ) ) );
	}
}
