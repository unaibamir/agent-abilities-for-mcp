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

	public function test_the_already_trashed_message_is_only_given_for_a_post_the_caller_can_read(): void {
		register_post_type(
			'aafm_unexposed',
			array(
				'public' => true,
				'label'  => 'Unexposed',
			)
		);
		$readable   = get_post( self::factory()->post->create( array( 'post_status' => 'trash' ) ) );
		$unexposed  = get_post( self::factory()->post->create( array( 'post_type' => 'aafm_unexposed', 'post_status' => 'trash' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$specific   = aafm_already_trashed_error( $readable );
		$other_type = aafm_already_trashed_error( $unexposed );
		unregister_post_type( 'aafm_unexposed' );

		$this->assertSame( 'aafm_already_trashed', $specific->get_error_code() );
		$this->assertSame( 'aafm_error', $other_type->get_error_code(), 'A post the caller cannot read gets the generic answer.' );
	}

	public function test_trash_post_still_trashes_a_live_post(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( array( 'trashed' => true ), aafm_exec_trash_post( array( 'post_id' => $post ) ) );
		$this->assertSame( 'trash', get_post_status( $post ) );
	}
}
