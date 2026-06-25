<?php
/**
 * Safety option getters: filterable, bounded, default off/neutral.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Unit;

use Oversio\Tests\TestCase;

final class SafetyTest extends TestCase {

	public function test_safety_getters_default_off(): void {
		delete_option( 'oversio_rate_limit_per_min' );
		delete_option( 'oversio_ip_allowlist' );
		delete_option( 'oversio_force_draft' );
		delete_option( 'oversio_max_title_len' );
		$this->assertSame( 0, oversio_rate_limit_per_min() );
		$this->assertSame( array(), oversio_ip_allowlist() );
		$this->assertFalse( oversio_force_draft() );
		$this->assertSame( 0, oversio_max_title_len() );
	}

	public function test_safety_getters_read_and_bound(): void {
		update_option( 'oversio_rate_limit_per_min', '-5' );      // Clamp to >= 0.
		$this->assertSame( 0, oversio_rate_limit_per_min() );
		update_option( 'oversio_rate_limit_per_min', '30' );
		$this->assertSame( 30, oversio_rate_limit_per_min() );
		update_option( 'oversio_force_draft', '1' );
		$this->assertTrue( oversio_force_draft() );
		update_option( 'oversio_max_title_len', '120' );
		$this->assertSame( 120, oversio_max_title_len() );
		update_option( 'oversio_ip_allowlist', array( '10.0.0.1', '192.168.0.0/24' ) );
		$this->assertSame( array( '10.0.0.1', '192.168.0.0/24' ), oversio_ip_allowlist() );
	}

	public function test_ip_allowlist_normalizes_stored_and_filtered(): void {
		update_option( 'oversio_ip_allowlist', array( '  10.0.0.1  ', '', 0 ) );
		$this->assertSame( array( '10.0.0.1' ), oversio_ip_allowlist() ); // Trimmed, empties dropped.

		$inject = static fn() => array( '  192.168.0.5  ', 7, '' );
		add_filter( 'oversio_ip_allowlist', $inject );
		$this->assertSame( array( '192.168.0.5' ), oversio_ip_allowlist() ); // Filter output re-floored.
		remove_filter( 'oversio_ip_allowlist', $inject );
	}

	/**
	 * T3-3: a filter returning a non-positive rate limit must not disable the limiter. The
	 * post-filter value is re-clamped to the stored limit, so a buggy filter can't turn off a
	 * fail-closed control.
	 */
	public function test_rate_limit_filter_cannot_disable_a_configured_limit(): void {
		update_option( 'oversio_rate_limit_per_min', '30' );

		$bad = static fn() => -1;
		add_filter( 'oversio_rate_limit_per_min', $bad );
		$this->assertSame( 30, oversio_rate_limit_per_min(), 'A filter returning -1 must not switch off a configured limit.' );
		remove_filter( 'oversio_rate_limit_per_min', $bad );

		// A filter may still RAISE or LOWER the limit to another positive value.
		$lower = static fn() => 5;
		add_filter( 'oversio_rate_limit_per_min', $lower );
		$this->assertSame( 5, oversio_rate_limit_per_min(), 'A filter may set another positive limit.' );
		remove_filter( 'oversio_rate_limit_per_min', $lower );
	}

	/**
	 * T3-3: a filter returning an empty allowlist must not widen an operator-configured
	 * non-empty allowlist to allow-all. The stored list wins when the filter empties it.
	 */
	public function test_ip_allowlist_filter_cannot_empty_a_configured_allowlist(): void {
		update_option( 'oversio_ip_allowlist', array( '10.0.0.1', '192.168.0.0/24' ) );

		$empty = static fn() => array();
		add_filter( 'oversio_ip_allowlist', $empty );
		$this->assertSame(
			array( '10.0.0.1', '192.168.0.0/24' ),
			oversio_ip_allowlist(),
			'A filter returning [] must not widen a non-empty operator allowlist.'
		);
		remove_filter( 'oversio_ip_allowlist', $empty );

		// An operator-set empty allowlist (no filter) stays empty/off by design.
		update_option( 'oversio_ip_allowlist', array() );
		$this->assertSame( array(), oversio_ip_allowlist() );
	}

	public function test_cidr_match_ipv4_and_ipv6(): void {
		$this->assertTrue( oversio_cidr_match( '192.168.1.50', '192.168.1.0/24' ) );
		$this->assertFalse( oversio_cidr_match( '192.168.2.50', '192.168.1.0/24' ) );
		$this->assertTrue( oversio_cidr_match( '10.0.0.7', '10.0.0.7' ) );        // Bare IP == /32.
		$this->assertTrue( oversio_cidr_match( '2001:db8::1', '2001:db8::/32' ) );
		$this->assertFalse( oversio_cidr_match( '2001:dead::1', '2001:db8::/32' ) );
		$this->assertFalse( oversio_cidr_match( 'garbage', '192.168.1.0/24' ) );
	}

	public function test_ip_is_allowed_empty_allows_all_else_restricts(): void {
		update_option( 'oversio_ip_allowlist', array() );
		$this->assertTrue( oversio_ip_is_allowed( '203.0.113.9' ) );           // Empty = allow all.
		update_option( 'oversio_ip_allowlist', array( '10.0.0.0/8' ) );
		$this->assertTrue( oversio_ip_is_allowed( '10.1.2.3' ) );
		$this->assertFalse( oversio_ip_is_allowed( '203.0.113.9' ) );          // Not in list -> blocked.
	}

	public function test_cidr_match_partial_byte_masks(): void {
		// IPv4 /20 -> mask 255.255.240.0; remainder 4 bits in the 3rd byte (0xF0).
		$this->assertTrue( oversio_cidr_match( '192.168.16.5', '192.168.16.0/20' ) );    // 0x10 & 0xF0 == 0x10
		$this->assertTrue( oversio_cidr_match( '192.168.31.255', '192.168.16.0/20' ) );  // top of the /20 block.
		$this->assertFalse( oversio_cidr_match( '192.168.32.5', '192.168.16.0/20' ) );   // 0x20 & 0xF0 != 0x10 — just outside
		// IPv4 /28 -> last-nibble boundary (0xF0 in the 4th byte).
		$this->assertTrue( oversio_cidr_match( '10.0.0.5', '10.0.0.0/28' ) );
		$this->assertFalse( oversio_cidr_match( '10.0.0.20', '10.0.0.0/28' ) );          // .20 = 0x14, outside .0/28
		// IPv6 /35 -> partial byte 0xE0 (top 3 bits of the 5th byte).
		$this->assertTrue( oversio_cidr_match( '2001:db8:2000::1', '2001:db8:2000::/35' ) );
		$this->assertFalse( oversio_cidr_match( '2001:db8:4000::1', '2001:db8:2000::/35' ) );
	}

	public function test_cidr_match_is_fail_closed_on_malformed(): void {
		$this->assertFalse( oversio_cidr_match( '192.168.1.1', 'not-a-cidr' ) );
		$this->assertFalse( oversio_cidr_match( '192.168.1.1', '192.168.1.0/999' ) ); // Out-of-range prefix.
		$this->assertFalse( oversio_cidr_match( '192.168.1.1', '192.168.1.0/-1' ) );
		$this->assertFalse( oversio_cidr_match( '192.168.1.1', '' ) );
		$this->assertFalse( oversio_cidr_match( '', '192.168.1.0/24' ) );
		$this->assertFalse( oversio_cidr_match( '192.168.1.1', '2001:db8::/32' ) ); // Family mismatch v4 vs v6.
		$this->assertFalse( oversio_cidr_match( '2001:db8::1', '192.168.1.0/24' ) ); // Family mismatch v6 vs v4.
	}

	public function test_ip_is_allowed_nonempty_all_invalid_blocks_not_allows_all(): void {
		// CRITICAL fail-closed: a non-empty list that happens to be all-garbage must NOT silently allow everyone.
		update_option( 'oversio_ip_allowlist', array( 'garbage', 'not-a-cidr' ) );
		$this->assertFalse( oversio_ip_is_allowed( '203.0.113.9' ) );
	}

	public function test_rate_limit_consume_blocks_over_limit(): void {
		update_option( 'oversio_rate_limit_per_min', 2 );
		$uid = 77;
		$this->assertTrue( oversio_rate_limit_consume( $uid ) );   // 1
		$this->assertTrue( oversio_rate_limit_consume( $uid ) );   // 2
		$this->assertFalse( oversio_rate_limit_consume( $uid ) );  // 3 -> over
	}

	public function test_rate_limit_off_or_no_principal_always_allows(): void {
		update_option( 'oversio_rate_limit_per_min', 0 );          // off.
		$this->assertTrue( oversio_rate_limit_consume( 5 ) );
		update_option( 'oversio_rate_limit_per_min', 1 );
		$this->assertTrue( oversio_rate_limit_consume( 0 ) );      // no real principal -> not limited.
	}

	public function test_rate_limit_is_per_principal(): void {
		update_option( 'oversio_rate_limit_per_min', 1 );
		$this->assertTrue( oversio_rate_limit_consume( 101 ) );    // user 101: 1st ok.
		$this->assertFalse( oversio_rate_limit_consume( 101 ) );   // user 101: 2nd over.
		$this->assertTrue( oversio_rate_limit_consume( 202 ) );    // user 202 independent window -> ok.
	}
}
