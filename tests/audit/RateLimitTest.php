<?php
/**
 * Tests for the per-user rate limit and the optional lower per-app limit beneath it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

/**
 * The per-user bucket is the ceiling; an app override only lowers it; a refused call consumes nothing.
 */
final class RateLimitTest extends TestCase {

	/**
	 * The calling user.
	 *
	 * @var int
	 */
	private int $user = 0;

	public function set_up(): void {
		parent::set_up();
		$this->user = self::factory()->user->create();
	}

	/**
	 * Set the site limit.
	 *
	 * @param int $limit Calls per minute, 0 for off.
	 * @return void
	 */
	private function site_limit( int $limit ): void {
		update_option( 'aafm_rate_limit_per_min', $limit );
		aafm_policy_reset_request_state();
	}

	/**
	 * Put an OAuth grant in the request store, or clear it.
	 *
	 * @param string $client_id Client id, '' for none.
	 * @param int    $rate      Per-app override, 0 for none.
	 * @return void
	 */
	private function as_app( string $client_id, int $rate = 0 ): void {
		aafm_oauth_current_grant(
			array_merge(
				aafm_oauth_grant_defaults(),
				array(
					'rate'      => $rate,
					'client_id' => $client_id,
					'user_id'   => $this->user,
				)
			)
		);
	}

	/**
	 * The per-user bucket key for this minute.
	 *
	 * @return string
	 */
	private function user_key(): string {
		return 'aafm_rl_' . $this->user . '_' . gmdate( 'YmdHi' );
	}

	/**
	 * The per-app bucket key for this minute.
	 *
	 * @param string $client_id Client id.
	 * @return string
	 */
	private function app_key( string $client_id ): string {
		return 'aafm_rla_' . $this->user . '_' . substr( md5( $client_id ), 0, 12 ) . '_' . gmdate( 'YmdHi' );
	}

	/**
	 * Run n calls and return the answers.
	 *
	 * @param int $n Calls.
	 * @return array<int,bool>
	 */
	private function calls( int $n ): array {
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$out[] = aafm_rate_limit_consume( $this->user );
		}
		return $out;
	}

	public function test_a_call_with_no_override_behaves_exactly_as_before(): void {
		$this->site_limit( 2 );
		$this->assertSame( array( true, true, false ), $this->calls( 3 ) );
		$this->assertSame( 2, (int) get_transient( $this->user_key() ) );

		delete_transient( $this->user_key() );
		$this->as_app( 'app-a' );
		$this->assertTrue( aafm_rate_limit_consume( $this->user ) );
		$this->as_app( 'app-b' );
		$this->assertTrue( aafm_rate_limit_consume( $this->user ) );
		$this->as_app( 'app-a' );
		$this->assertFalse( aafm_rate_limit_consume( $this->user ), 'Two apps of one user share the one user bucket.' );
		$this->assertSame( 2, (int) get_transient( $this->user_key() ) );
	}

	public function test_a_per_app_override_lowers_the_limit_for_that_app_only(): void {
		$this->site_limit( 5 );
		$this->as_app( 'app-a', 1 );
		$this->assertSame( array( true, false ), $this->calls( 2 ) );
		$this->as_app( 'app-b' );
		$this->assertSame( array( true, true, true, true, false ), $this->calls( 5 ), 'B reaches the user ceiling: 1 from A plus 4.' );
	}

	public function test_an_override_above_the_site_limit_changes_nothing(): void {
		$this->site_limit( 2 );
		$this->as_app( 'app-a', 9 );
		$this->assertSame( array( true, true, false ), $this->calls( 3 ) );
		$this->assertSame( 2, (int) get_transient( $this->app_key( 'app-a' ) ) );
	}

	public function test_a_refused_call_consumes_neither_bucket(): void {
		$this->site_limit( 5 );
		$this->as_app( 'app-a', 2 );
		set_transient( $this->app_key( 'app-a' ), 2, 2 * MINUTE_IN_SECONDS );
		$this->assertFalse( aafm_rate_limit_consume( $this->user ) );
		$this->assertFalse( get_transient( $this->user_key() ), 'App full: the user bucket is not touched.' );

		delete_transient( $this->app_key( 'app-a' ) );
		set_transient( $this->user_key(), 5, 2 * MINUTE_IN_SECONDS );
		$this->assertFalse( aafm_rate_limit_consume( $this->user ) );
		$this->assertFalse( get_transient( $this->app_key( 'app-a' ) ), 'User full: the app bucket is not touched.' );
	}

	public function test_override_with_the_site_limit_off_still_caps_that_app(): void {
		$this->site_limit( 0 );
		$this->as_app( 'app-a', 3 );
		$this->assertSame( array( true, true, true, false ), $this->calls( 4 ) );
		$this->as_app( '' );
		$this->assertSame( array( true, true, true, true, true ), $this->calls( 5 ), 'An Application Password call stays unlimited.' );
	}

	public function test_override_zero_means_none(): void {
		$this->site_limit( 2 );
		$this->as_app( 'app-a', 0 );
		$this->assertSame( array( true, true, false ), $this->calls( 3 ) );
		$this->assertFalse( get_transient( $this->app_key( 'app-a' ) ) );
	}

	public function test_the_app_key_never_carries_the_raw_client_id(): void {
		global $wpdb;
		$client = 'client-secret-looking-id';
		$this->site_limit( 0 );
		$this->as_app( $client, 3 );
		$this->assertTrue( aafm_rate_limit_consume( $this->user ) );
		$this->assertSame( 1, (int) get_transient( $this->app_key( $client ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s", '%' . $wpdb->esc_like( $client ) . '%' ) ) );
	}

	public function test_an_application_password_call_ignores_any_override(): void {
		$this->site_limit( 2 );
		aafm_oauth_current_grant(
			array_merge(
				aafm_oauth_grant_defaults(),
				array(
					'rate'      => 1,
					'client_id' => '',
					'user_id'   => $this->user,
				)
			)
		);
		$this->assertSame( array( true, true, false ), $this->calls( 3 ) );
	}
}
