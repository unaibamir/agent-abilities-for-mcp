<?php
/**
 * Governed post-meta abilities: the shared per-object + per-key gate, and the
 * scalar-only read path that refuses to leak arrays/serialized blobs.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class PostMetaTest extends TestCase {

	public function test_get_meta_happy_path_and_gates(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_type'   => 'post',
			)
		);
		update_post_meta( $id, 'subtitle', 'A scalar' );

		$this->assertTrue(
			aafm_perm_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle',
				)
			)
		);
		$this->assertSame(
			array(
				'post_id'  => $id,
				'meta_key' => 'subtitle',
				'value'    => 'A scalar',
			),
			aafm_exec_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle',
				)
			)
		);
		$this->assertFalse(
			aafm_perm_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'unlisted',
				)
			)
		);
		$this->assertFalse(
			aafm_perm_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => '_edit_lock',
				)
			)
		);
	}

	public function test_get_meta_refuses_non_scalar_value(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'data' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		update_post_meta( $id, 'data', array( 'x' => 1 ) );
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'data',
				)
			)
		);
	}

	public function test_get_meta_denies_other_authors_post(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$id    = self::factory()->post->create( array( 'post_author' => $owner ) );
		update_post_meta( $id, 'subtitle', 'x' );
		wp_set_current_user( $other );
		$this->assertFalse(
			aafm_perm_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle',
				)
			)
		);
	}

	public function test_perm_callback_returns_false_on_empty_input(): void {
		$this->assertFalse( aafm_perm_get_post_meta( array() ) );
	}

	public function test_update_meta_writes_scalar_and_blocks_array(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );

		$this->assertTrue(
			aafm_perm_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle',
					'value'    => 'x',
				)
			)
		);
		aafm_exec_update_post_meta(
			array(
				'post_id'  => $id,
				'meta_key' => 'subtitle',
				'value'    => 'New <b>title</b>',
			)
		);
		$this->assertSame( 'New title', get_post_meta( $id, 'subtitle', true ) );
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle',
					'value'    => array( 1 ),
				)
			)
		);
	}

	public function test_update_meta_denies_blocked_and_other_author(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$id    = self::factory()->post->create( array( 'post_author' => $owner ) );
		wp_set_current_user( $other );
		$this->assertFalse(
			aafm_perm_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle',
					'value'    => 'x',
				)
			)
		);
		wp_set_current_user( $owner );
		$this->assertFalse(
			aafm_perm_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => '_edit_lock',
					'value'    => 'x',
				)
			)
		);
		$this->assertFalse(
			aafm_perm_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'unlisted',
					'value'    => 'x',
				)
			)
		);
	}

	public function test_update_meta_idempotent_resend_of_non_string_scalars(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'count', 'ratio', 'flag' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );

		foreach ( array(
			'count' => 7,
			'ratio' => 1.5,
			'flag'  => true,
		) as $key => $val ) {
			$first = aafm_exec_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => $key,
					'value'    => $val,
				)
			);
			$this->assertIsArray( $first, "first write of $key should succeed" );
			// Re-send the identical value: update_post_meta no-ops (returns false). The
			// read-back guard must NOT treat that as a failure.
			$second = aafm_exec_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => $key,
					'value'    => $val,
				)
			);
			$this->assertIsArray( $second, "idempotent re-send of $key must not error" );
			$this->assertArrayHasKey( 'value', $second );
		}
	}
}
