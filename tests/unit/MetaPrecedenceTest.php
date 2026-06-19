<?php
/**
 * CVE-class post-meta precedence: hard-block -> deny -> allow/`*`.
 *
 * The exposure predicate must be absolute. A hard-blocked auth key can NEVER leak no matter
 * what the allow/deny options hold; an explicit deny (or a deny-`*`) always beats the allow
 * list (including allow-`*`); the default-empty state is default-deny. Every reject returns
 * the SAME generic error code so a caller cannot tell deny from not-allowed from hard-block.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;
use WP_Error;

final class MetaPrecedenceTest extends TestCase {

	/**
	 * Set both meta-key options directly. Transaction rollback isolates each test, and the
	 * suite runs against the isolated test DB — the live DDEV option store is never touched.
	 *
	 * @param array<int,string> $allow Allow option value.
	 * @param array<int,string> $deny  Deny option value.
	 * @return void
	 */
	private function set_meta_options( array $allow, array $deny ): void {
		update_option( 'aafm_allowed_meta_keys', $allow );
		update_option( 'aafm_denied_meta_keys', $deny );
	}

	/**
	 * Row 1 — hard-block beats `*`.
	 */
	public function test_hard_block_beats_allow_star(): void {
		$this->set_meta_options( array( '*' ), array() );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_meta_key( 'session_tokens' ) );
	}

	/**
	 * Row 2 — hard-block beats an explicit allow entry.
	 */
	public function test_hard_block_beats_explicit_allow(): void {
		$this->set_meta_options( array( 'session_tokens' ), array() );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_meta_key( 'session_tokens' ) );
	}

	/**
	 * Row 3 — deny beats allow.
	 */
	public function test_deny_beats_explicit_allow(): void {
		$this->set_meta_options( array( 'foo' ), array( 'foo' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_meta_key( 'foo' ) );
	}

	/**
	 * Row 4 — deny beats `*`: the denied key is rejected, a sibling under `*` stays exposed.
	 */
	public function test_deny_beats_allow_star(): void {
		$this->set_meta_options( array( '*' ), array( 'foo' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_meta_key( 'foo' ) );
		$this->assertSame( 'bar', aafm_validate_meta_key( 'bar' ) );
	}

	/**
	 * Row 5 — deny-`*` wins even when allow-`*` is set (deny-all kill switch).
	 */
	public function test_deny_star_wins_even_with_allow_star(): void {
		$this->set_meta_options( array( '*' ), array( '*' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_meta_key( 'foo' ) );
	}

	/**
	 * Row 6 — default-empty = default-deny.
	 */
	public function test_default_empty_is_default_deny(): void {
		$this->set_meta_options( array(), array() );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_meta_key( 'foo' ) );
	}

	/**
	 * Row 7 — `*` exposes a normal key.
	 */
	public function test_allow_star_exposes_normal_key(): void {
		$this->set_meta_options( array( '*' ), array() );
		$this->assertSame( 'foo', aafm_validate_meta_key( 'foo' ) );
	}

	/**
	 * Trailing whitespace — trim() runs before floor-1, so padding cannot smuggle a
	 * hard-blocked key past the strict in_array hard-block; a normal key trims and exposes.
	 */
	public function test_trailing_whitespace_cannot_smuggle_blocked_and_trims_normal(): void {
		$this->set_meta_options( array( '*' ), array() );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_meta_key( ' session_tokens ' ) );
		$this->assertSame( 'foo', aafm_validate_meta_key( 'foo ' ) );
	}

	/**
	 * Oracle — rows 1, 3, 5, 6 all return the SAME error code, so deny / not-allowed /
	 * hard-block / deny-all are indistinguishable to a caller.
	 */
	public function test_all_reject_modes_share_one_error_code(): void {
		$code = 'aafm_meta_key_not_allowed';

		$this->set_meta_options( array( '*' ), array() );
		$hard_block = aafm_validate_meta_key( 'session_tokens' );

		$this->set_meta_options( array( 'foo' ), array( 'foo' ) );
		$deny = aafm_validate_meta_key( 'foo' );

		$this->set_meta_options( array( '*' ), array( '*' ) );
		$deny_all = aafm_validate_meta_key( 'foo' );

		$this->set_meta_options( array(), array() );
		$not_allowed = aafm_validate_meta_key( 'foo' );

		$this->assertSame( $code, $hard_block->get_error_code() );
		$this->assertSame( $code, $deny->get_error_code() );
		$this->assertSame( $code, $deny_all->get_error_code() );
		$this->assertSame( $code, $not_allowed->get_error_code() );
	}

	/**
	 * Bulk-loop `*` asymmetry pin — the get-all-post-meta bulk reader iterates the literal
	 * allow list, so allow=['*'] alone (no literals) yields an EMPTY map. This pins the
	 * documented single/write-honor-`*` vs bulk-explicit-only asymmetry — a future change
	 * that walks the whole post-meta table to honor `*` in bulk goes red here.
	 */
	public function test_bulk_get_all_post_meta_does_not_honor_allow_star(): void {
		$this->set_meta_options( array( '*' ), array() );
		$id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $id, 'subtitle', 'a custom value' );

		$result = aafm_exec_get_all_post_meta( array( 'post_id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertEquals( (object) array(), $result['meta'] );
	}
}
