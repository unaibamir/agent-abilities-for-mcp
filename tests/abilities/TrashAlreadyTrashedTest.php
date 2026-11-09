<?php
/**
 * Trashing something that is already in the Trash says so instead of the generic refusal.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class TrashAlreadyTrashedTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
	}

	public function test_trash_post_on_a_trashed_post_says_it_is_already_in_the_trash(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'trash' ) );

		$res = aafm_exec_trash_post( array( 'post_id' => $post ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_already_trashed', $res->get_error_code() );
		$this->assertStringContainsString( 'already in the Trash', $res->get_error_message() );
	}

	public function test_trash_page_on_a_trashed_page_says_it_is_already_in_the_trash(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'trash',
			)
		);

		$res = aafm_exec_trash_page( array( 'page_id' => $page ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_already_trashed', $res->get_error_code() );
	}

	public function test_trash_post_still_trashes_a_live_post(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( array( 'trashed' => true ), aafm_exec_trash_post( array( 'post_id' => $post ) ) );
		$this->assertSame( 'trash', get_post_status( $post ) );
	}
}
