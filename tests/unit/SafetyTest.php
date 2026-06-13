<?php
/**
 * Safety option getters: filterable, bounded, default off/neutral.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class SafetyTest extends TestCase {

	public function test_safety_getters_default_off(): void {
		delete_option( 'aafm_rate_limit_per_min' );
		delete_option( 'aafm_ip_allowlist' );
		delete_option( 'aafm_force_draft' );
		delete_option( 'aafm_max_title_len' );
		$this->assertSame( 0, aafm_rate_limit_per_min() );
		$this->assertSame( array(), aafm_ip_allowlist() );
		$this->assertFalse( aafm_force_draft() );
		$this->assertSame( 0, aafm_max_title_len() );
	}

	public function test_safety_getters_read_and_bound(): void {
		update_option( 'aafm_rate_limit_per_min', '-5' );      // Clamp to >= 0.
		$this->assertSame( 0, aafm_rate_limit_per_min() );
		update_option( 'aafm_rate_limit_per_min', '30' );
		$this->assertSame( 30, aafm_rate_limit_per_min() );
		update_option( 'aafm_force_draft', '1' );
		$this->assertTrue( aafm_force_draft() );
		update_option( 'aafm_max_title_len', '120' );
		$this->assertSame( 120, aafm_max_title_len() );
		update_option( 'aafm_ip_allowlist', array( '10.0.0.1', '192.168.0.0/24' ) );
		$this->assertSame( array( '10.0.0.1', '192.168.0.0/24' ), aafm_ip_allowlist() );
	}

	public function test_ip_allowlist_normalizes_stored_and_filtered(): void {
		update_option( 'aafm_ip_allowlist', array( '  10.0.0.1  ', '', 0 ) );
		$this->assertSame( array( '10.0.0.1' ), aafm_ip_allowlist() ); // Trimmed, empties dropped.

		$inject = static fn() => array( '  192.168.0.5  ', 7, '' );
		add_filter( 'aafm_ip_allowlist', $inject );
		$this->assertSame( array( '192.168.0.5' ), aafm_ip_allowlist() ); // Filter output re-floored.
		remove_filter( 'aafm_ip_allowlist', $inject );
	}

	public function test_cidr_match_ipv4_and_ipv6(): void {
		$this->assertTrue( aafm_cidr_match( '192.168.1.50', '192.168.1.0/24' ) );
		$this->assertFalse( aafm_cidr_match( '192.168.2.50', '192.168.1.0/24' ) );
		$this->assertTrue( aafm_cidr_match( '10.0.0.7', '10.0.0.7' ) );        // Bare IP == /32.
		$this->assertTrue( aafm_cidr_match( '2001:db8::1', '2001:db8::/32' ) );
		$this->assertFalse( aafm_cidr_match( '2001:dead::1', '2001:db8::/32' ) );
		$this->assertFalse( aafm_cidr_match( 'garbage', '192.168.1.0/24' ) );
	}

	public function test_ip_is_allowed_empty_allows_all_else_restricts(): void {
		update_option( 'aafm_ip_allowlist', array() );
		$this->assertTrue( aafm_ip_is_allowed( '203.0.113.9' ) );           // Empty = allow all.
		update_option( 'aafm_ip_allowlist', array( '10.0.0.0/8' ) );
		$this->assertTrue( aafm_ip_is_allowed( '10.1.2.3' ) );
		$this->assertFalse( aafm_ip_is_allowed( '203.0.113.9' ) );          // Not in list -> blocked.
	}

	public function test_cidr_match_partial_byte_masks(): void {
		// IPv4 /20 -> mask 255.255.240.0; remainder 4 bits in the 3rd byte (0xF0).
		$this->assertTrue( aafm_cidr_match( '192.168.16.5', '192.168.16.0/20' ) );    // 0x10 & 0xF0 == 0x10
		$this->assertTrue( aafm_cidr_match( '192.168.31.255', '192.168.16.0/20' ) );  // top of the /20 block.
		$this->assertFalse( aafm_cidr_match( '192.168.32.5', '192.168.16.0/20' ) );   // 0x20 & 0xF0 != 0x10 — just outside
		// IPv4 /28 -> last-nibble boundary (0xF0 in the 4th byte).
		$this->assertTrue( aafm_cidr_match( '10.0.0.5', '10.0.0.0/28' ) );
		$this->assertFalse( aafm_cidr_match( '10.0.0.20', '10.0.0.0/28' ) );          // .20 = 0x14, outside .0/28
		// IPv6 /35 -> partial byte 0xE0 (top 3 bits of the 5th byte).
		$this->assertTrue( aafm_cidr_match( '2001:db8:2000::1', '2001:db8:2000::/35' ) );
		$this->assertFalse( aafm_cidr_match( '2001:db8:4000::1', '2001:db8:2000::/35' ) );
	}

	public function test_cidr_match_is_fail_closed_on_malformed(): void {
		$this->assertFalse( aafm_cidr_match( '192.168.1.1', 'not-a-cidr' ) );
		$this->assertFalse( aafm_cidr_match( '192.168.1.1', '192.168.1.0/999' ) ); // Out-of-range prefix.
		$this->assertFalse( aafm_cidr_match( '192.168.1.1', '192.168.1.0/-1' ) );
		$this->assertFalse( aafm_cidr_match( '192.168.1.1', '' ) );
		$this->assertFalse( aafm_cidr_match( '', '192.168.1.0/24' ) );
		$this->assertFalse( aafm_cidr_match( '192.168.1.1', '2001:db8::/32' ) ); // Family mismatch v4 vs v6.
		$this->assertFalse( aafm_cidr_match( '2001:db8::1', '192.168.1.0/24' ) ); // Family mismatch v6 vs v4.
	}

	public function test_ip_is_allowed_nonempty_all_invalid_blocks_not_allows_all(): void {
		// CRITICAL fail-closed: a non-empty list that happens to be all-garbage must NOT silently allow everyone.
		update_option( 'aafm_ip_allowlist', array( 'garbage', 'not-a-cidr' ) );
		$this->assertFalse( aafm_ip_is_allowed( '203.0.113.9' ) );
	}

	public function test_rate_limit_consume_blocks_over_limit(): void {
		update_option( 'aafm_rate_limit_per_min', 2 );
		$uid = 77;
		$this->assertTrue( aafm_rate_limit_consume( $uid ) );   // 1
		$this->assertTrue( aafm_rate_limit_consume( $uid ) );   // 2
		$this->assertFalse( aafm_rate_limit_consume( $uid ) );  // 3 -> over
	}

	public function test_rate_limit_off_or_no_principal_always_allows(): void {
		update_option( 'aafm_rate_limit_per_min', 0 );          // off.
		$this->assertTrue( aafm_rate_limit_consume( 5 ) );
		update_option( 'aafm_rate_limit_per_min', 1 );
		$this->assertTrue( aafm_rate_limit_consume( 0 ) );      // no real principal -> not limited.
	}

	public function test_rate_limit_is_per_principal(): void {
		update_option( 'aafm_rate_limit_per_min', 1 );
		$this->assertTrue( aafm_rate_limit_consume( 101 ) );    // user 101: 1st ok.
		$this->assertFalse( aafm_rate_limit_consume( 101 ) );   // user 101: 2nd over.
		$this->assertTrue( aafm_rate_limit_consume( 202 ) );    // user 202 independent window -> ok.
	}
}
