<?php
/**
 * CPT governance: read/write routing through the post-type allowlist gates.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class CptGovernanceTest extends TestCase {

	public function test_get_post_denies_cpt_object_until_allowlisted(): void {
		register_post_type(
			'aafm_book',
			array(
				'public'          => true,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
				'label'           => 'Books',
			)
		);
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'aafm_book',
				'post_status' => 'publish',
			)
		);

		delete_option( 'aafm_allowed_post_types' );
		$this->assertFalse( aafm_perm_get_post( array( 'post_id' => $id ) ), 'CPT read must be denied before opt-in.' );

		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) );
		$this->assertTrue( aafm_perm_get_post( array( 'post_id' => $id ) ), 'CPT read allowed after opt-in.' );
	}

	public function test_get_post_denies_attachment_object(): void {
		$this->acting_as( 'administrator' );
		$att = self::factory()->attachment->create();
		$this->assertFalse( aafm_perm_get_post( array( 'post_id' => $att ) ), 'Attachment must not be readable via get-post.' );
	}

	public function test_post_and_page_behaviour_is_unchanged(): void {
		$this->acting_as( 'administrator' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$this->assertTrue( aafm_perm_get_post( array( 'post_id' => $post ) ) );
		$this->assertTrue( aafm_perm_update_post( array( 'post_id' => $post ) ) );
		$this->assertTrue( aafm_perm_trash_post( array( 'post_id' => $post ) ) );
		$this->assertTrue( aafm_perm_get_post( array( 'post_id' => $page ) ) );
	}
}
