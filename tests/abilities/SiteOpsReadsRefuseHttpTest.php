<?php
/**
 * A request another plugin sends while a Site Ops read calls into it is refused.
 *
 * Each read wraps its vendor call in aafm_siteops_no_http_call(). A stub that sends a request from
 * inside the vendor call records the answer it got; a lower-priority pre_http_request answer stands
 * in for the network, so a request that is not refused never leaves the test process.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use Aafm_Siteops_Redirect_Stub as Stub;
use WP_Error;

final class SiteOpsReadsRefuseHttpTest extends TestCase {

	/**
	 * The answer each request sent from inside a vendor call got.
	 *
	 * @var array<int,mixed>
	 */
	private $answers = array();

	/**
	 * The stand-in for the network.
	 *
	 * @var callable
	 */
	private $network;

	public function set_up(): void {
		parent::set_up();
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-updraft.php';
		aafm_updraft_stub_activate();
		Stub::reset();
		$this->answers = array();
		$this->network = static function () {
			return array(
				'headers'  => array(),
				'body'     => 'sent',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->network, 10 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->network, 10 );
		remove_all_filters( 'aafm_site_redirect_adapters' );
		remove_all_filters( 'query' );
		Stub::reset();
		aafm_updraft_stub_deactivate();
		parent::tear_down();
	}

	/**
	 * The vendor's own request, sent from inside the vendor call.
	 */
	private function phone(): void {
		$this->answers[] = wp_remote_get( 'https://vendor.example/phone-home' );
	}

	/**
	 * Every request sent from inside a vendor call was refused, and at least one was sent.
	 *
	 * @param string $read Which read.
	 */
	private function assertAllRefused( string $read ): void {
		$this->assertNotSame( array(), $this->answers, "{$read}: the stub must send a request." );
		foreach ( $this->answers as $answer ) {
			$this->assertInstanceOf( WP_Error::class, $answer, "{$read}: the vendor's request went out." );
			$this->assertSame( 'aafm_site_no_http', $answer->get_error_code(), $read );
		}
		$this->assertSame( 'sent', wp_remote_retrieve_body( wp_remote_get( 'https://vendor.example/after' ) ), "{$read}: the refusal must end with the read." );
	}

	public function test_redirect_reads_refuse_the_vendors_request(): void {
		add_filter(
			'aafm_site_redirect_adapters',
			static function () {
				return array( 'fake_a' => Stub::entry( 'Fake A' ) );
			}
		);
		Stub::$fake['fake_a']['during'] = function (): void {
			$this->phone();
		};

		aafm_site_redirect_call( 'fake_a', 'all' );
		$this->assertAllRefused( 'redirect all()' );

		$this->answers = array();
		$cache         = array();
		aafm_site_redirect_match( 'fake_a', '/old', $cache );
		$this->assertAllRefused( 'redirect match()' );
	}

	public function test_backup_reads_refuse_the_vendors_request(): void {
		$GLOBALS['aafm_updraft_stub']['during_read'] = function (): void {
			$this->phone();
		};

		aafm_backup_gate_state();
		$this->assertAllRefused( 'backup gate state' );

		$this->answers = array();
		aafm_exec_site_list_backups( array() );
		$this->assertAllRefused( 'site-list-backups' );

		unset( $GLOBALS['aafm_updraft_stub']['during_read'] );
		$this->answers = array();
		add_filter(
			'query',
			function ( $query ) {
				if ( is_string( $query ) && false !== strpos( $query, 'jobdata' ) ) {
					$this->phone();
				}
				return $query;
			}
		);
		aafm_exec_site_backup_status();
		$this->assertAllRefused( 'site-backup-status jobs read' );
	}
}
