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
			aafm_exec_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertFalse(
			aafm_perm_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'unlisted', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertFalse(
			aafm_perm_get_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => '_edit_lock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
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
					'meta_key' => 'data', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
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
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
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
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
		aafm_exec_update_post_meta(
			array(
				'post_id'  => $id,
				'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'New <b>title</b>',
			)
		);
		$this->assertSame( 'New title', get_post_meta( $id, 'subtitle', true ) );
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
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
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
		wp_set_current_user( $owner );
		$this->assertFalse(
			aafm_perm_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => '_edit_lock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
		$this->assertFalse(
			aafm_perm_update_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'unlisted', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
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
					'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => $val,
				)
			);
			$this->assertIsArray( $first, "first write of $key should succeed" );
			// Re-send the identical value: update_post_meta no-ops (returns false). The
			// read-back guard must NOT treat that as a failure.
			$second = aafm_exec_update_post_meta(
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
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_upper' ) );
		register_post_meta(
			'post',
			'aafm_upper',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => static fn( $v ) => is_string( $v ) ? strtoupper( $v ) : $v,
			)
		);
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );

		$result = aafm_exec_update_post_meta(
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_upper', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'hello',
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( get_post_meta( $id, 'aafm_upper', true ), $result['value'] );
		$this->assertSame( 'HELLO', $result['value'] );
		unregister_post_meta( 'post', 'aafm_upper' );
	}

	public function test_delete_meta_removes_key(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		update_post_meta( $id, 'subtitle', 'gone' );
		$this->assertSame(
			array( 'deleted' => true ),
			aafm_exec_delete_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertSame( '', get_post_meta( $id, 'subtitle', true ) );
	}

	public function test_delete_meta_gates_block_and_other_author(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$id    = self::factory()->post->create( array( 'post_author' => $owner ) );
		update_post_meta( $id, 'subtitle', 'x' );
		wp_set_current_user( $other );
		$this->assertFalse(
			aafm_perm_delete_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		wp_set_current_user( $owner );
		$this->assertFalse(
			aafm_perm_delete_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => '_edit_lock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertFalse(
			aafm_perm_delete_post_meta(
				array(
					'post_id'  => $id,
					'meta_key' => 'unlisted', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
	}

	public function test_meta_abilities_registered(): void {
		$reg = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/get-post-meta', $reg );
		$this->assertArrayHasKey( 'aafm/update-post-meta', $reg );
		$this->assertArrayHasKey( 'aafm/delete-post-meta', $reg );
	}

	/**
	 * Codex final round 7 HIGH: every page-builder ownership marker (includes/page-builder-
	 * guard.php) must be absolutely blocked from update-post-meta/delete-post-meta, even when the
	 * operator has exposed every other meta key via `*` - clearing a marker (e.g.
	 * fusion_builder_status) let aafm_exec_update_post()'s ownership check pass on the very next
	 * call and write through the refusal guard entirely.
	 */
	public function test_page_builder_marker_keys_are_hard_blocked_even_with_star_exposed(): void {
		update_option( 'aafm_allowed_meta_keys', array( '*' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$id = self::factory()->post->create();
		update_post_meta( $id, 'fusion_builder_status', 'active' );

		foreach ( array_keys( aafm_page_builder_markers() ) as $marker_key ) {
			$this->assertFalse(
				aafm_perm_update_post_meta(
					array(
						'post_id'  => $id,
						'meta_key' => $marker_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					)
				),
				"$marker_key must be hard-blocked from update-post-meta even with * exposed."
			);
			$this->assertFalse(
				aafm_perm_delete_post_meta(
					array(
						'post_id'  => $id,
						'meta_key' => $marker_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					)
				),
				"$marker_key must be hard-blocked from delete-post-meta even with * exposed."
			);
		}

		$out = aafm_exec_update_post_meta(
			array(
				'post_id'  => $id,
				'meta_key' => 'fusion_builder_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => '',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'active', get_post_meta( $id, 'fusion_builder_status', true ), 'The marker must survive an attempted clear untouched.' );
	}

	/**
	 * Codex round 5 R5-2: the write-confirmation guard only checked `false ===
	 * update_post_meta(...)`, so a metadata filter that short-circuits update_post_metadata to a
	 * truthy value bypassed the write entirely while the guard never noticed - the write reported
	 * success and returned the old stored value.
	 */
	public function test_update_meta_returns_an_error_when_the_write_is_vetoed(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_note' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		update_post_meta( $id, 'aafm_note', 'old value' );

		$veto = static fn() => true;
		add_filter( 'update_post_metadata', $veto, 10, 0 );
		$out  = aafm_exec_update_post_meta(
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'new value',
			)
		);
		remove_filter( 'update_post_metadata', $veto, 10 );

		$this->assertInstanceOf(
			WP_Error::class,
			$out,
			'A vetoed meta write must return an error, not a success reporting the old value.'
		);
	}

	/**
	 * Codex round 6 B6-3: the confirmation guard compared the fresh read against the plugin's own
	 * pre-write intent, so a site-registered sanitize_post_meta_{key} callback (register_meta()'s
	 * sanitize_callback lands there, exactly like update_metadata() itself runs on every meta
	 * write) that legitimately normalizes the value on save was indistinguishable from a filter
	 * vetoing the write, and the ability returned a false error even though the write landed
	 * exactly as the site's own sanitizer defines "landed".
	 */
	public function test_update_meta_confirms_a_legitimate_sanitize_meta_normalization(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_note' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );

		// A site-registered sanitizer that appends a fixed suffix, the same shape as a vendor
		// plugin's own meta normalization (trimming, casting, or otherwise reshaping the value on
		// its way into storage).
		$normalize = static fn( $value ) => $value . '-normalized';
		add_filter( 'sanitize_post_meta_aafm_note', $normalize );
		$out       = aafm_exec_update_post_meta(
			array(
				'post_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'new value',
			)
		);
		remove_filter( 'sanitize_post_meta_aafm_note', $normalize );

		$this->assertIsArray(
			$out,
			'A write that landed in its sanitizer-normalized form must not be reported as an unconfirmed write.'
		);
		$this->assertSame(
			'new value-normalized',
			get_post_meta( $id, 'aafm_note', true ),
			'precondition: the registered sanitizer must have actually normalized the stored value.'
		);
		$this->assertSame( 'new value-normalized', $out['value'], 'The response must reflect the value actually stored, not the caller\'s pre-normalization intent.' );
	}
}
