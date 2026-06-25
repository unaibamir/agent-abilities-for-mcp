<?php
/**
 * CVE-class term-meta precedence: hard-block -> deny -> allow/`*`.
 *
 * Mirrors MetaPrecedenceTest and UserMetaPrecedenceTest for the term-meta surface. The
 * hard-block (shared with post meta, aafm_hard_blocked_meta_key) stays absolute under both
 * allow-`*` and an explicit allow; an explicit deny (or deny-`*`) always beats the allow
 * list (including allow-`*`); the default-empty state is default-deny. Also pins the
 * option/filter UNION: the legacy aafm_allowed_term_meta_keys filter can only ADD to the
 * option-backed exposed list, never shrink it, and can never re-admit a hard-blocked key.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;
use WP_Error;

final class TermMetaPrecedenceTest extends TestCase {

	/**
	 * Set both term-meta options directly. Transaction rollback isolates each test, and the
	 * suite runs against the isolated test DB — the live DDEV option store is never touched.
	 *
	 * @param array<int,string> $exposed Exposed option value.
	 * @param array<int,string> $deny    Deny option value.
	 * @return void
	 */
	private function set_term_meta_options( array $exposed, array $deny ): void {
		update_option( 'aafm_exposed_term_meta_keys', $exposed );
		update_option( 'aafm_denied_term_meta_keys', $deny );
	}

	/**
	 * Row 1 — hard-block beats `*` (an auth key can never leak under allow-all).
	 */
	public function test_hard_block_beats_allow_star(): void {
		$this->set_term_meta_options( array( '*' ), array() );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( 'session_tokens' ) );
	}

	/**
	 * Row 2 — hard-block beats an explicit allow entry.
	 */
	public function test_hard_block_beats_explicit_allow(): void {
		$this->set_term_meta_options( array( 'session_tokens' ), array() );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( 'session_tokens' ) );
	}

	/**
	 * Row 3 — deny beats allow.
	 */
	public function test_deny_beats_explicit_allow(): void {
		$this->set_term_meta_options( array( 'foo' ), array( 'foo' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( 'foo' ) );
	}

	/**
	 * Row 4 — deny beats `*`: the denied key is rejected, a sibling under `*` stays exposed.
	 */
	public function test_deny_beats_allow_star(): void {
		$this->set_term_meta_options( array( '*' ), array( 'foo' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( 'foo' ) );
		$this->assertSame( 'bar', aafm_validate_term_meta_key( 'bar' ) );
	}

	/**
	 * Row 5 — deny-`*` wins even when allow-`*` is set (deny-all kill switch).
	 */
	public function test_deny_star_wins_even_with_allow_star(): void {
		$this->set_term_meta_options( array( '*' ), array( '*' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( 'foo' ) );
	}

	/**
	 * Row 6 — default-empty = default-deny.
	 */
	public function test_default_empty_is_default_deny(): void {
		$this->set_term_meta_options( array(), array() );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( 'foo' ) );
	}

	/**
	 * Row 7 — `*` exposes a normal key.
	 */
	public function test_allow_star_exposes_normal_key(): void {
		$this->set_term_meta_options( array( '*' ), array() );
		$this->assertSame( 'foo', aafm_validate_term_meta_key( 'foo' ) );
	}

	/**
	 * Trailing whitespace — trim() runs before floor-1, so padding cannot smuggle a
	 * hard-blocked key past the strict in_array hard-block; a normal key trims and exposes.
	 */
	public function test_trailing_whitespace_cannot_smuggle_blocked_and_trims_normal(): void {
		$this->set_term_meta_options( array( '*' ), array() );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( ' session_tokens ' ) );
		$this->assertSame( 'foo', aafm_validate_term_meta_key( 'foo ' ) );
	}

	/**
	 * The bare `*` sentinel is never an addressable key — even when exposed=['*']. Reject it
	 * with the SAME generic error code so the validator never hands `'*'` to a term-meta op.
	 */
	public function test_wildcard_sentinel_is_not_an_addressable_key(): void {
		$this->set_term_meta_options( array( '*' ), array() );
		$result = aafm_validate_term_meta_key( '*' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aafm_term_meta_key_not_allowed', $result->get_error_code() );
	}

	/**
	 * Oracle — rows 1, 3, 5, 6 all return the SAME error code, so deny / not-allowed /
	 * hard-block / deny-all are indistinguishable to a caller.
	 */
	public function test_all_reject_modes_share_one_error_code(): void {
		$code = 'aafm_term_meta_key_not_allowed';

		$this->set_term_meta_options( array( '*' ), array() );
		$hard_block = aafm_validate_term_meta_key( 'session_tokens' );

		$this->set_term_meta_options( array( 'foo' ), array( 'foo' ) );
		$deny = aafm_validate_term_meta_key( 'foo' );

		$this->set_term_meta_options( array( '*' ), array( '*' ) );
		$deny_all = aafm_validate_term_meta_key( 'foo' );

		$this->set_term_meta_options( array(), array() );
		$not_allowed = aafm_validate_term_meta_key( 'foo' );

		$this->assertSame( $code, $hard_block->get_error_code() );
		$this->assertSame( $code, $deny->get_error_code() );
		$this->assertSame( $code, $deny_all->get_error_code() );
		$this->assertSame( $code, $not_allowed->get_error_code() );
	}

	/**
	 * UNION — a legacy filter returning [] cannot shrink the option-backed exposed list, so
	 * a key set only in the option stays exposed.
	 */
	public function test_filter_returning_empty_cannot_shrink_option_exposure(): void {
		$this->set_term_meta_options( array( 'foo' ), array() );
		add_filter( 'aafm_allowed_term_meta_keys', static fn() => array() );
		$this->assertSame( 'foo', aafm_validate_term_meta_key( 'foo' ) );
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	/**
	 * UNION — the filter ADDS to the option base: a key only the filter contributes and a
	 * key only the option contributes are BOTH exposed.
	 */
	public function test_filter_unions_with_option_base(): void {
		$this->set_term_meta_options( array( 'foo' ), array() );
		add_filter( 'aafm_allowed_term_meta_keys', static fn( $keys ) => array_merge( (array) $keys, array( 'bar' ) ) );
		$this->assertSame( 'foo', aafm_validate_term_meta_key( 'foo' ) );
		$this->assertSame( 'bar', aafm_validate_term_meta_key( 'bar' ) );
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	/**
	 * Hard-block stays absolute under the filter — a filter that tries to add an auth key
	 * cannot re-admit it (re-floored against the hard-block on the merged set).
	 */
	public function test_filter_cannot_re_admit_hard_blocked_key(): void {
		$this->set_term_meta_options( array(), array() );
		add_filter( 'aafm_allowed_term_meta_keys', static fn( $keys ) => array_merge( (array) $keys, array( 'session_tokens' ) ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( 'session_tokens' ) );
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}
}
