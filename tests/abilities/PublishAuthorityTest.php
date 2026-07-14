<?php
/**
 * The publish-authority gate keys on core's public-status registry, not the literal 'publish'.
 *
 * A custom PUBLIC status another plugin registers (register_post_status( 'x', array( 'public' =>
 * true ) )) is publish-equivalent, so moving a post to it must require the type's publish cap - it
 * must not be reachable with only edit capability.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class PublishAuthorityTest extends TestCase {

	/**
	 * A custom, publicly-viewable status registered for the duration of each test.
	 */
	private const CUSTOM_STATUS = 'aafm_featured';

	public function set_up(): void {
		parent::set_up();
		register_post_status(
			self::CUSTOM_STATUS,
			array(
				'label'  => 'Featured',
				'public' => true,
			)
		);
	}

	/**
	 * Remove the custom status. register_post_status writes to the global status registry, which the
	 * transactional rollback does not reset, so it must be unset explicitly to not bleed across tests.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['wp_post_statuses'][ self::CUSTOM_STATUS ] );
		parent::tear_down();
	}

	/**
	 * The custom status is recognised as publish-equivalent by the shared gate helper.
	 */
	public function test_custom_public_status_requires_publish_cap(): void {
		$this->assertTrue( aafm_status_requires_publish_cap( self::CUSTOM_STATUS ) );
	}

	/**
	 * A user who can edit the post but holds no publish cap is denied when the requested status is
	 * the custom PUBLIC status - the gate no longer keys on the literal 'publish'.
	 */
	public function test_update_post_denies_custom_public_status_without_publish_cap(): void {
		$uid  = $this->acting_as( 'contributor' );
		$post = self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'draft',
			)
		);

		// The contributor can edit its own draft but holds no publish_posts cap.
		$this->assertTrue( current_user_can( 'edit_post', $post ) );
		$this->assertFalse( current_user_can( 'publish_posts' ) );

		$this->assertFalse(
			aafm_perm_update_post(
				array(
					'post_id' => $post,
					'status'  => self::CUSTOM_STATUS,
				)
			),
			'A custom public status must require publish authority, not just edit.'
		);
	}

	/**
	 * A user who holds the publish cap is allowed to move their post to the custom public status.
	 */
	public function test_update_post_allows_custom_public_status_with_publish_cap(): void {
		$uid  = $this->acting_as( 'author' );
		$post = self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'draft',
			)
		);

		$this->assertTrue( current_user_can( 'publish_posts' ) );
		$this->assertTrue(
			aafm_perm_update_post(
				array(
					'post_id' => $post,
					'status'  => self::CUSTOM_STATUS,
				)
			),
			'A user with publish authority may set the custom public status.'
		);
	}
}
