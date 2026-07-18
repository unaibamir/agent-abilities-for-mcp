<?php
/**
 * The page publish-authority gate keys on core's public-status registry, not the literal 'publish'.
 *
 * Before this fix, aafm_perm_update_page matched only the literal 'publish' status, so a custom PUBLIC
 * status another plugin registers (register_post_status( 'x', array( 'public' => true ) )) slipped
 * past the publish gate and was reachable with edit-only authority. It now shares the same helper as
 * aafm_perm_update_post, so any publish-equivalent status requires the page type's publish_pages cap.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_User;

final class PagesPublishAuthorityTest extends TestCase {

	/**
	 * A custom, publicly-viewable status registered for the duration of each test.
	 */
	private const CUSTOM_STATUS = 'aafm_featured_page';

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
	 * A user who can edit the page but holds no publish_pages cap is denied when the requested status
	 * is the custom PUBLIC status - the gate no longer keys on the literal 'publish'.
	 */
	public function test_update_page_denies_custom_public_status_without_publish_cap(): void {
		// An editor edits pages; strip publish_pages so it can edit but not publish.
		$uid  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user = new WP_User( $uid );
		$user->add_cap( 'publish_pages', false );
		wp_set_current_user( $uid );

		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);

		$this->assertTrue( current_user_can( 'edit_page', $page ), 'the editor can still edit the page.' );
		$this->assertFalse( current_user_can( 'publish_pages' ), 'publish_pages was stripped for this user.' );

		$this->assertFalse(
			aafm_perm_update_page(
				array(
					'page_id' => $page,
					'status'  => self::CUSTOM_STATUS,
				)
			),
			'a custom public status must require page publish authority, not just edit.'
		);
	}

	/**
	 * A user who holds publish_pages may move the page to the custom public status.
	 */
	public function test_update_page_allows_custom_public_status_with_publish_cap(): void {
		$this->acting_as( 'editor' );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);

		$this->assertTrue( current_user_can( 'publish_pages' ) );
		$this->assertTrue(
			aafm_perm_update_page(
				array(
					'page_id' => $page,
					'status'  => self::CUSTOM_STATUS,
				)
			),
			'a user with page publish authority may set the custom public status.'
		);
	}
}
