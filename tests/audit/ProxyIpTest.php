<?php
/**
 * Tests for the trusted-proxy visitor address.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Off by default; a forwarded header is believed only from a trusted proxy; every caller of
 * aafm_source_ip() sees the resolved address.
 */
final class ProxyIpTest extends TestCase {

	/**
	 * $_SERVER as set_up() found it.
	 *
	 * @var array<string,mixed>
	 */
	private array $server = array();

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
	}

	public function tear_down(): void {
		$_SERVER = $this->server;
		parent::tear_down();
	}

	/**
	 * Store the proxy setting.
	 *
	 * @param mixed $value Option value.
	 * @return void
	 */
	private function proxy( $value ): void {
		update_option( 'aafm_trusted_proxy', $value );
		aafm_policy_reset_request_state();
	}

	/**
	 * Set the connection address and forwarded headers.
	 *
	 * @param string               $remote  REMOTE_ADDR.
	 * @param array<string,string> $headers $_SERVER keys and values.
	 * @return void
	 */
	private function request( string $remote, array $headers = array() ): void {
		$_SERVER['REMOTE_ADDR'] = $remote;
		foreach ( $headers as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}
	}

	/**
	 * A custom-mode setting.
	 *
	 * @param string   $header Header name.
	 * @param string[] $ranges Trusted ranges.
	 * @return void
	 */
	private function custom( string $header, array $ranges ): void {
		$this->proxy(
			array(
				'mode'   => 'custom',
				'header' => $header,
				'ranges' => $ranges,
			)
		);
	}

	public function test_off_by_default_ignores_every_forwarded_header(): void {
		$this->request(
			'198.51.100.7',
			array(
				'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
				'HTTP_X_FORWARDED_FOR'  => '203.0.113.9',
				'HTTP_X_REAL_IP'        => '203.0.113.9',
			)
		);
		$this->assertSame( '198.51.100.7', aafm_source_ip() );
		$this->request( '::ffff:198.51.100.7' );
		$this->assertSame( '::ffff:198.51.100.7', aafm_source_ip() );
	}

	public function test_cloudflare_mode_uses_cf_connecting_ip_only_from_a_cloudflare_address(): void {
		$this->proxy( array( 'mode' => 'cloudflare' ) );
		$this->request( '173.245.48.1', array( 'HTTP_CF_CONNECTING_IP' => '203.0.113.9' ) );
		$this->assertSame( '203.0.113.9', aafm_source_ip() );
	}

	public function test_a_forged_header_from_an_untrusted_address_is_ignored(): void {
		$this->proxy( array( 'mode' => 'cloudflare' ) );
		$this->request( '198.51.100.7', array( 'HTTP_CF_CONNECTING_IP' => '203.0.113.9' ) );
		$this->assertSame( '198.51.100.7', aafm_source_ip() );
	}

	public function test_xff_walks_from_the_right(): void {
		$this->custom( 'X-Forwarded-For', array( '10.0.0.0/8' ) );
		$this->request( '10.0.0.1', array( 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9, 10.0.0.2' ) );
		$this->assertSame( '203.0.113.9', aafm_source_ip() );
		$this->request( '10.0.0.1', array( 'HTTP_X_FORWARDED_FOR' => '10.0.0.3, 10.0.0.2' ) );
		$this->assertSame( '10.0.0.1', aafm_source_ip(), 'All trusted: the remote.' );
		$this->request( '10.0.0.1', array( 'HTTP_X_FORWARDED_FOR' => 'not-an-ip' ) );
		$this->assertSame( '10.0.0.1', aafm_source_ip(), 'Unparseable: the remote.' );
	}

	public function test_ipv6_ranges_and_values(): void {
		$this->custom( 'X-Real-IP', array( '2001:db8::/32' ) );
		$this->request( '2001:db8::5', array( 'HTTP_X_REAL_IP' => '2606:4700:10::1' ) );
		$this->assertSame( '2606:4700:10::1', aafm_source_ip() );
	}

	public function test_a_mapped_ipv4_remote_matches_a_v4_range(): void {
		$this->custom( 'X-Real-IP', array( '10.0.0.0/8' ) );
		$this->request( '::ffff:10.0.0.1', array( 'HTTP_X_REAL_IP' => '::ffff:203.0.113.9' ) );
		$this->assertSame( '203.0.113.9', aafm_source_ip() );
	}

	public function test_the_header_must_be_on_the_closed_list(): void {
		foreach ( array( 'Host', 'X-Evil' ) as $header ) {
			$this->custom( $header, array( '10.0.0.0/8' ) );
			$this->assertSame( 'off', aafm_trusted_proxy_config()['mode'], $header );
		}
	}

	public function test_wide_ranges_are_never_trusted(): void {
		foreach ( array( '0.0.0.0/0', '10.0.0.0/7', '::/0', '2001::/15' ) as $range ) {
			$this->assertFalse( aafm_proxy_range_ok( $range ), $range );
		}
		foreach ( array( '10.0.0.0/8', '2001:db8::/16', '203.0.113.5' ) as $range ) {
			$this->assertTrue( aafm_proxy_range_ok( $range ), $range );
		}
		$this->custom( 'X-Real-IP', array( '0.0.0.0/0', '::/0' ) );
		$this->assertSame( 'off', aafm_trusted_proxy_config()['mode'] );
	}

	public function test_a_very_long_header_is_bounded(): void {
		$this->custom( 'X-Forwarded-For', array( '10.0.0.0/8' ) );
		$value = implode( ', ', array_fill( 0, 4999, '1.2.3.4' ) ) . ', 203.0.113.9';
		$this->request( '10.0.0.1', array( 'HTTP_X_FORWARDED_FOR' => $value ) );
		$this->assertSame( '203.0.113.9', aafm_source_ip() );
		$this->assertSame( '', aafm_pick_visitor_ip( '1.2.3.4,' . implode( ',', array_fill( 0, 30, '10.0.0.9' ) ), array( '10.0.0.0/8' ) ), 'Entries past the last 20 are never read.' );
	}

	public function test_every_embedded_cloudflare_range_parses(): void {
		$this->assertNotEmpty( aafm_cloudflare_ranges() );
		foreach ( aafm_cloudflare_ranges() as $range ) {
			$this->assertTrue( aafm_proxy_range_ok( $range ), $range );
		}
	}

	public function test_all_five_callers_see_the_resolved_address(): void {
		$this->proxy( array( 'mode' => 'cloudflare' ) );
		$this->request( '173.245.48.1', array( 'HTTP_CF_CONNECTING_IP' => '203.0.113.9' ) );

		// The denial cap keys on the visitor.
		$this->assertTrue( aafm_denial_log_within_cap( 'tst' ) );
		$this->assertSame( 1, (int) get_transient( 'aafm_tst_' . md5( '203.0.113.9' ) ) );

		// The activity row records the visitor.
		$id = aafm_log_activity(
			array(
				'ability' => 'proxy-test',
				'status'  => 'success',
			)
		);
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( '203.0.113.9', aafm_query_activity( array( 'ability' => 'proxy-test' ) )[0]['source_ip'] );

		// The OAuth endpoint throttle counts the visitor.
		aafm_oauth_rate_ok( 'proxytest', 10, 100 );
		$this->assertSame( 1, (int) get_transient( 'aafm_oauth_rl_ip_' . md5( 'proxytest|203.0.113.9' ) ) );

		// A registered client records the visitor.
		aafm_install_oauth_tables();
		$client = aafm_oauth_register_client(
			array(
				'redirect_uris' => array( 'https://app.example/cb' ),
				'client_name'   => 'Proxy test',
			)
		);
		$this->assertIsArray( $client );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( '203.0.113.9', $wpdb->get_var( $wpdb->prepare( 'SELECT created_by_ip FROM %i WHERE client_id = %s', $wpdb->prefix . 'aafm_oauth_clients', $client['client_id'] ) ) );

		// The IP allowlist decides on the visitor.
		update_option( 'aafm_ip_allowlist', array( '203.0.113.9' ) );
		aafm_policy_reset_request_state();
		$this->assertTrue( aafm_ip_is_allowed( aafm_source_ip() ) );
		update_option( 'aafm_ip_allowlist', array( '173.245.48.1' ) );
		aafm_policy_reset_request_state();
		$this->assertFalse( aafm_ip_is_allowed( aafm_source_ip() ) );
	}

	public function test_an_unreadable_row_means_off(): void {
		$this->proxy( array( 'mode' => 'cloudflare' ) );
		$this->request( '173.245.48.1', array( 'HTTP_CF_CONNECTING_IP' => '203.0.113.9' ) );
		$this->assertSame( '173.245.48.1', QueryFaultInjector::fail_query( 'aafm_trusted_proxy', static fn() => aafm_source_ip() ) );
	}

	public function test_source_ip_guards_the_proxy_branch_for_early_callers(): void {
		$fn     = new \ReflectionFunction( 'aafm_source_ip' );
		$lines  = (array) file( (string) $fn->getFileName() );
		$source = implode( '', array_slice( $lines, $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1 ) );
		$this->assertStringContainsString( "function_exists( 'aafm_trusted_proxy_visitor' ) ? aafm_trusted_proxy_visitor( \$ip ) : \$ip", $source );
		$this->request( '198.51.100.7', array( 'HTTP_CF_CONNECTING_IP' => '203.0.113.9' ) );
		$this->assertSame( '198.51.100.7', aafm_source_ip() );
	}

	public function test_the_allowlist_still_blocks_through_the_shared_loop(): void {
		update_option( 'aafm_ip_allowlist', array( '10.0.0.0/8', '2001:db8::1', '192.168.1.5' ) );
		aafm_policy_reset_request_state();
		foreach ( array(
			'10.1.2.3'    => true,
			'2001:db8::1' => true,
			'192.168.1.5' => true,
			'192.168.1.6' => false,
			'11.0.0.1'    => false,
			'2001:db8::2' => false,
		) as $ip => $allowed ) {
			$this->assertSame( $allowed, aafm_ip_is_allowed( (string) $ip ), (string) $ip );
		}
	}
}
