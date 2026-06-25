<?php
/**
 * Governed post-meta abilities: the shared per-object + per-key gate, and the
 * scalar-only read path that refuses to leak arrays/serialized blobs.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use WP_Error;

final class PostMetaTest extends TestCase {

	public function test_get_meta_happy_path_and_gates(): void {
		update_option( 'oversio_allowed_meta_keys', array( 'subtitle' ) );
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
			oversio_perm_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertSame(
			array(
				'post_id'  => $id,
				'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'A scalar',
			),
			oversio_exec_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertFalse(
			oversio_perm_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'unlisted', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertFalse(
			oversio_perm_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => '_edit_lock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
	}

	public function test_get_meta_refuses_non_scalar_value(): void {
		update_option( 'oversio_allowed_meta_keys', array( 'data' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		update_post_meta( $id, 'data', array( 'x' => 1 ) );
		$this->assertInstanceOf(
			WP_Error::class,
			oversio_exec_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'data', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
	}

	public function test_get_meta_denies_other_authors_post(): void {
		update_option( 'oversio_allowed_meta_keys', array( 'subtitle' ) );
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$id    = self::factory()->post->create( array( 'post_author' => $owner ) );
		update_post_meta( $id, 'subtitle', 'x' );
		wp_set_current_user( $other );
		$this->assertFalse(
			oversio_perm_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
	}

	public function test_perm_callback_returns_false_on_empty_input(): void {
		$this->assertFalse( oversio_perm_get_post_meta( array() ) );
	}

	public function test_update_meta_writes_scalar_and_blocks_array(): void {
		update_option( 'oversio_allowed_meta_keys', array( 'subtitle' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );

		$this->assertTrue(
			oversio_perm_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
		oversio_exec_update_post_meta(
			array(
				'post_id'  => $id,
				'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'New <b>title</b>',
			)
		);
		$this->assertSame( 'New title', get_post_meta( $id, 'subtitle', true ) );
		$this->assertInstanceOf(
			WP_Error::class,
			oversio_exec_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => array( 1 ),
				)
			)
		);
	}

	public function test_update_meta_denies_blocked_and_other_author(): void {
		update_option( 'oversio_allowed_meta_keys', array( 'subtitle' ) );
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$id    = self::factory()->post->create( array( 'post_author' => $owner ) );
		wp_set_current_user( $other );
		$this->assertFalse(
			oversio_perm_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
		wp_set_current_user( $owner );
		$this->assertFalse(
			oversio_perm_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => '_edit_lock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
		$this->assertFalse(
			oversio_perm_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'unlisted', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
	}

	public function test_update_meta_idempotent_resend_of_non_string_scalars(): void {
		update_option( 'oversio_allowed_meta_keys', array( 'count', 'ratio', 'flag' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );

		foreach ( array(
			'count' => 7,
			'ratio' => 1.5,
			'flag'  => true,
		) as $key => $val ) {
			$first = oversio_exec_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => $val,
				)
			);
			$this->assertIsArray( $first, "first write of $key should succeed" );
			// Re-send the identical value: update_post_meta no-ops (returns false). The
			// read-back guard must NOT treat that as a failure.
			$second = oversio_exec_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => $val,
				)
			);
			$this->assertIsArray( $second, "idempotent re-send of $key must not error" );
			$this->assertArrayHasKey( 'value', $second );
		}
	}

	public function test_update_meta_response_reflects_stored_value(): void {
		update_option( 'oversio_allowed_meta_keys', array( 'oversio_upper' ) );
		register_post_meta(
			'post',
			'oversio_upper',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => static fn( $v ) => is_string( $v ) ? strtoupper( $v ) : $v,
			)
		);
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );

		$result = oversio_exec_update_post_meta(
			array(
				'post_id'  => $id,
				'meta_key' => 'oversio_upper', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'hello',
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( get_post_meta( $id, 'oversio_upper', true ), $result['value'] );
		$this->assertSame( 'HELLO', $result['value'] );
		unregister_post_meta( 'post', 'oversio_upper' );
	}

	public function test_delete_meta_removes_key(): void {
		update_option( 'oversio_allowed_meta_keys', array( 'subtitle' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		update_post_meta( $id, 'subtitle', 'gone' );
		$this->assertSame(
			array( 'deleted' => true ),
			oversio_exec_delete_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertSame( '', get_post_meta( $id, 'subtitle', true ) );
	}

	public function test_delete_meta_gates_block_and_other_author(): void {
		update_option( 'oversio_allowed_meta_keys', array( 'subtitle' ) );
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$id    = self::factory()->post->create( array( 'post_author' => $owner ) );
		update_post_meta( $id, 'subtitle', 'x' );
		wp_set_current_user( $other );
		$this->assertFalse(
			oversio_perm_delete_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		wp_set_current_user( $owner );
		$this->assertFalse(
			oversio_perm_delete_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => '_edit_lock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertFalse(
			oversio_perm_delete_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'unlisted', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
	}

	public function test_meta_abilities_registered(): void {
		$reg = oversio_get_abilities_registry();
		$this->assertArrayHasKey( 'oversio/get-post-meta', $reg );
		$this->assertArrayHasKey( 'oversio/update-post-meta', $reg );
		$this->assertArrayHasKey( 'oversio/delete-post-meta', $reg );
	}
}
