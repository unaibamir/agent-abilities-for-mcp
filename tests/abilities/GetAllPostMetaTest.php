<?php
/**
 * Get-all-post-meta: returns every allowlisted scalar meta value as a map,
 * gated per-object on edit_post; skips hard-blocked and non-scalar values.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class GetAllPostMetaTest extends TestCase {

	public function test_in_registry_as_a_read(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/get-all-post-meta', $registry );
		$this->assertSame( 'reads', $registry['aafm/get-all-post-meta']['group'] );
		$this->assertSame( 'read', $registry['aafm/get-all-post-meta']['risk'] );
		$this->assertSame( 'content', $registry['aafm/get-all-post-meta']['subject'] );
	}

	public function test_returns_only_allowlisted_scalars(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle', 'rating' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		update_post_meta( $id, 'subtitle', 'A scalar' );
		update_post_meta( $id, 'rating', '5' );
		update_post_meta( $id, 'not_listed', 'secret' );

		$out  = aafm_exec_get_all_post_meta( array( 'post_id' => $id ) );
		$meta = (array) $out['meta'];

		$this->assertSame( 'A scalar', $meta['subtitle'] );
		$this->assertSame( '5', $meta['rating'] );
		$this->assertArrayNotHasKey( 'not_listed', $meta );
	}

	public function test_skips_hard_blocked_and_non_scalar(): void {
		// 'subtitle' is allowlisted and scalar (returned); 'blob' is allowlisted but
		// holds an array (skipped, never dumped). _edit_lock is hard-blocked: even if a
		// rogue filter exposed it, aafm_allowed_meta_keys() strips it, so it cannot appear.
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle', 'blob' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		update_post_meta( $id, 'subtitle', 'ok' );
		update_post_meta( $id, 'blob', array( 'x' => 1 ) );

		$out  = aafm_exec_get_all_post_meta( array( 'post_id' => $id ) );
		$meta = (array) $out['meta'];

		$this->assertSame( 'ok', $meta['subtitle'] );
		$this->assertArrayNotHasKey( 'blob', $meta );
		$this->assertArrayNotHasKey( '_edit_lock', $meta );
	}

	public function test_wildcard_returns_post_own_scalar_meta(): void {
		// Under allow-`*` the bulk reader must match the single get-post-meta reader:
		// it enumerates the post's OWN stored scalar meta, not just literal allowlist keys.
		update_option( 'aafm_allowed_meta_keys', array( '*' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		update_post_meta( $id, 'subtitle', 'A scalar' );
		update_post_meta( $id, 'rating', '5' );
		update_post_meta( $id, 'blob', array( 'x' => 1 ) ); // non-scalar, skipped.

		$out  = aafm_exec_get_all_post_meta( array( 'post_id' => $id ) );
		$meta = (array) $out['meta'];

		$this->assertSame( 'A scalar', $meta['subtitle'] );
		$this->assertSame( '5', $meta['rating'] );
		$this->assertArrayNotHasKey( 'blob', $meta );
		$this->assertArrayNotHasKey( '_edit_lock', $meta ); // protected, never under `*`.
	}

	public function test_wildcard_still_excludes_denied_and_protected_keys(): void {
		// Deny beats allow-`*` in the bulk reader exactly as in the single reader.
		update_option( 'aafm_allowed_meta_keys', array( '*' ) );
		update_option( 'aafm_denied_meta_keys', array( 'secret_key' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		update_post_meta( $id, 'subtitle', 'shown' );
		update_post_meta( $id, 'secret_key', 'hidden' );

		$out  = aafm_exec_get_all_post_meta( array( 'post_id' => $id ) );
		$meta = (array) $out['meta'];

		$this->assertSame( 'shown', $meta['subtitle'] );
		$this->assertArrayNotHasKey( 'secret_key', $meta );
	}

	public function test_denied_for_low_cap_user(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$id    = self::factory()->post->create( array( 'post_author' => $owner ) );
		// A subscriber cannot edit the post, so the per-object gate denies the read.
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_perm_get_all_post_meta( array( 'post_id' => $id ) ) );
	}

	public function test_missing_post_is_generic_error(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$this->acting_as( 'editor' );
		$out = aafm_exec_get_all_post_meta( array( 'post_id' => 999999 ) );
		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	public function test_perm_callback_returns_false_on_empty_input(): void {
		$this->assertFalse( aafm_perm_get_all_post_meta( array() ) );
	}

	public function test_empty_meta_is_a_json_object_not_array(): void {
		// Lock the contract: when no allowlisted keys resolve, 'meta' must JSON-encode
		// to "{}" (object) per the output_schema, never "[]". A dropped (object) cast
		// would silently regress this, exactly as count-media's by_mime once did.
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle', 'rating' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		// Post carries none of the allowlisted keys, so the map comes back empty.

		$out = aafm_exec_get_all_post_meta( array( 'post_id' => $id ) );

		$this->assertIsObject( $out['meta'] );
		$this->assertSame( '{}', wp_json_encode( $out['meta'] ) );
		$this->assertEmpty( (array) $out['meta'] );
	}
}
