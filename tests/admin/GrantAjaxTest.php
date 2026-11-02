<?php
/**
 * Tests for the per-grant controls' AJAX handler.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

/**
 * Covers aafm_ajax_oauth_update_grant().
 */
class GrantAjaxTest extends TestCase {

	use AjaxHarness;

	private const CLIENT = 'client_grant_ajax_3f9a';

	/**
	 * Approving user.
	 *
	 * @var int
	 */
	private int $user = 0;

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => self::CLIENT,
				'client_name' => 'Grant ajax',
				'is_active'   => 1,
			)
		);
		$this->user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $this->user,
				'client_id'  => self::CLIENT,
			)
		);
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * Post an update for the fixture grant.
	 *
	 * @param array<string,mixed> $fields Fields beyond user_id and client_id.
	 * @param string              $role   Acting role.
	 * @return array<string,mixed>
	 */
	private function update( array $fields, string $role = 'administrator' ): array {
		$this->post_as(
			$role,
			array_merge(
				array(
					'user_id'   => (string) $this->user,
					'client_id' => self::CLIENT,
				),
				$fields
			)
		);
		return $this->run_handler( 'aafm_ajax_oauth_update_grant' );
	}

	/**
	 * The fixture grant's state.
	 *
	 * @return array<string,mixed>
	 */
	private function state(): array {
		$state = aafm_oauth_grant_state( $this->user, self::CLIENT );
		$this->assertIsArray( $state );
		return $state;
	}

	/**
	 * The setting_changed details written so far.
	 *
	 * @return list<string>
	 */
	private function details(): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT detail FROM %i WHERE event_type = %s ORDER BY id', $wpdb->prefix . 'aafm_activity_log', 'setting_changed' ) );
	}

	public function test_pause_and_resume_flip_the_state_and_keep_the_tokens(): void {
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $this->user,
				'client_id'  => self::CLIENT,
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->assertIsArray( $tokens );
		$json = $this->update( array( 'paused' => '1' ) );
		$this->assertTrue( $json['success'] );
		$this->assertSame( 'paused', $json['data']['status'] );
		$this->assertTrue( $this->state()['paused'] );
		$this->assertTrue( aafm_oauth_user_client_has_active_tokens( $this->user, self::CLIENT ) );

		$json = $this->update( array( 'paused' => '0' ) );
		$this->assertTrue( $json['success'] );
		$this->assertSame( 'active', $json['data']['status'] );
		$this->assertFalse( $this->state()['paused'] );
		$this->assertTrue( aafm_oauth_user_client_has_active_tokens( $this->user, self::CLIENT ) );
	}

	public function test_a_missing_or_unknown_grant_is_refused(): void {
		$this->assertFalse(
			$this->update(
				array(
					'user_id' => '0',
					'paused'  => '1',
				)
			)['success']
		);
		$this->assertFalse(
			$this->update(
				array(
					'client_id' => 'nope',
					'paused'    => '1',
				)
			)['success']
		);
		$this->assertFalse(
			$this->update(
				array(
					'user_id' => (string) ( $this->user + 999 ),
					'paused'  => '1',
				)
			)['success']
		);
	}

	public function test_an_unknown_preset_is_refused_and_custom_clears_it(): void {
		$this->assertFalse(
			$this->update(
				array(
					'preset'        => 'bogus',
					'expect_preset' => 'custom',
				)
			)['success']
		);
		$this->assertTrue(
			$this->update(
				array(
					'preset'        => 'read_only',
					'expect_preset' => 'custom',
				)
			)['success']
		);
		$this->assertSame( 'read_only', $this->state()['preset'] );
		$this->assertTrue(
			$this->update(
				array(
					'preset'        => 'custom',
					'expect_preset' => 'read_only',
				)
			)['success']
		);
		$this->assertSame( '', $this->state()['preset'] );
	}

	public function test_an_expiry_date_is_end_of_that_day_in_the_site_timezone(): void {
		update_option( 'timezone_string', 'Asia/Karachi' );
		$year = (string) ( (int) gmdate( 'Y' ) + 1 );
		$this->assertTrue(
			$this->update(
				array(
					'expires'        => $year . '-12-31',
					'expect_expires' => '',
				)
			)['success']
		);
		$this->assertSame( $year . '-12-31 18:59:59', $this->state()['expires_at'] );
		$this->assertTrue(
			$this->update(
				array(
					'expires'        => '',
					'expect_expires' => $year . '-12-31',
				)
			)['success']
		);
		$this->assertNull( $this->state()['expires_at'] );
	}

	public function test_a_past_date_is_refused(): void {
		$json = $this->update(
			array(
				'expires'        => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ),
				'expect_expires' => '',
			)
		);
		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( 'Pick a date in the future', $json['data']['message'] );
	}

	public function test_a_malformed_date_is_refused(): void {
		foreach ( array( '2026-02-30', '2026-1-5', "2099-01-01\0", array( '2099-01-01' ), "2099-01-01\n" ) as $bad ) {
			$this->assertFalse(
				$this->update(
					array(
						'expires'        => $bad,
						'expect_expires' => '',
					)
				)['success']
			);
		}
		$this->assertNull( $this->state()['expires_at'] );
	}

	public function test_rate_accepts_digits_one_to_ten_thousand_or_empty_for_inherit(): void {
		$this->assertTrue(
			$this->update(
				array(
					'rate'        => '1',
					'expect_rate' => '',
				)
			)['success']
		);
		$this->assertTrue(
			$this->update(
				array(
					'rate'        => '10000',
					'expect_rate' => '1',
				)
			)['success']
		);
		$this->assertSame( 10000, $this->state()['rate'] );
		$this->assertTrue(
			$this->update(
				array(
					'rate'        => '',
					'expect_rate' => '10000',
				)
			)['success']
		);
		$this->assertSame( 0, $this->state()['rate'] );
		foreach ( array( '0', '-1', '1.5', 'abc', '10001', array( '5' ) ) as $bad ) {
			$out = $this->update(
				array(
					'rate'        => $bad,
					'expect_rate' => '',
				)
			);
			$this->assertFalse( $out['success'] );
			$this->assertSame( 'Use a whole number from 1 to 10,000 calls a minute, or leave it empty to use the site limit.', $out['data']['message'] ?? null );
		}
		$this->assertSame( 0, $this->state()['rate'] );
	}

	public function test_a_request_with_no_changes_is_refused(): void {
		$json = $this->update( array() );
		$this->assertFalse( $json['success'] );
		$this->assertSame( 'Nothing to change.', $json['data']['message'] );
	}

	public function test_array_params_are_refused_cleanly(): void {
		foreach ( array( 'user_id', 'client_id', 'paused', 'preset', 'expires', 'rate', 'expect_preset' ) as $key ) {
			$json = $this->update(
				array(
					'preset'        => 'read_only',
					'expect_preset' => 'custom',
					$key            => array( 'x' ),
				)
			);
			$this->assertFalse( $json['success'], $key );
			$this->assertNotEmpty( $json['data']['message'], $key );
		}
		$this->assertSame( '', $this->state()['preset'] );
	}

	public function test_every_change_writes_one_setting_changed_row_with_identifiers_only(): void {
		$this->update( array( 'paused' => '1' ) );
		$this->update(
			array(
				'preset'        => 'read_only',
				'expect_preset' => 'custom',
			)
		);
		$this->update(
			array(
				'rate'        => '30',
				'expect_rate' => '',
			)
		);
		$details = $this->details();
		$suffix  = ' (user ID ' . $this->user . ', app client_g)';
		$this->assertSame(
			array( 'Connection paused' . $suffix, 'Access preset changed to Read only' . $suffix, 'Rate limit set to 30 a minute' . $suffix ),
			$details
		);
		foreach ( $details as $detail ) {
			$this->assertStringNotContainsString( 'Grant ajax', $detail );
		}
	}

	public function test_non_admin_and_bad_nonce_are_refused(): void {
		$this->assertFalse( $this->update( array( 'paused' => '1' ), 'editor' )['success'] );
		$this->post_as(
			'administrator',
			array(
				'user_id'   => (string) $this->user,
				'client_id' => self::CLIENT,
				'paused'    => '1',
			)
		);
		$_POST['nonce']    = 'bad';
		$_REQUEST['nonce'] = 'bad';
		$this->run_handler( 'aafm_ajax_oauth_update_grant' );
		$this->assertFalse( $this->state()['paused'] );
	}

	public function test_revoke_still_works_on_a_paused_grant(): void {
		$this->update( array( 'paused' => '1' ) );
		$this->post_as(
			'administrator',
			array(
				'user_id'   => (string) $this->user,
				'client_id' => self::CLIENT,
			)
		);
		$this->assertTrue( $this->run_handler( 'aafm_ajax_oauth_revoke_grant' )['success'] );
		$this->assertFalse( aafm_oauth_has_consent( $this->user, self::CLIENT ) );
	}

	public function test_a_second_admin_tab_pausing_an_already_paused_grant_is_idempotent(): void {
		$this->assertTrue( $this->update( array( 'paused' => '1' ) )['success'] );
		$this->assertTrue( $this->update( array( 'paused' => '1' ) )['success'] );
		$this->assertTrue( $this->state()['paused'] );
	}

	public function test_a_stale_tab_cannot_restore_a_broader_preset(): void {
		aafm_oauth_grant_update( $this->user, self::CLIENT, array( 'preset' => 'shop_manager' ) );
		$this->assertTrue(
			$this->update(
				array(
					'preset'        => 'read_only',
					'expect_preset' => 'shop_manager',
				)
			)['success']
		);
		$json = $this->update(
			array(
				'preset'        => 'shop_manager',
				'expect_preset' => 'shop_manager',
			)
		);
		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( 'changed in another tab', $json['data']['message'] );
		$this->assertSame( 'read_only', $this->state()['preset'] );

		$this->assertTrue(
			$this->update(
				array(
					'rate'        => '20',
					'expect_rate' => '',
				)
			)['success']
		);
		$this->assertSame( 'read_only', $this->state()['preset'] );
	}

	public function test_a_write_without_its_baseline_is_refused(): void {
		foreach ( array(
			'preset'  => 'read_only',
			'expires' => '',
			'rate'    => '5',
		) as $key => $value ) {
			$this->assertFalse( $this->update( array( $key => $value ) )['success'], $key );
		}
		$this->assertSame( '', $this->state()['preset'] );
		$this->assertSame( 0, $this->state()['rate'] );
	}

	public function test_the_baseline_compares_the_normalised_value(): void {
		update_option( 'timezone_string', 'Asia/Karachi' );
		$this->assertTrue(
			$this->update(
				array(
					'preset'        => 'read_only',
					'expect_preset' => 'custom',
				)
			)['success']
		);
		$this->assertFalse(
			$this->update(
				array(
					'preset'        => 'custom',
					'expect_preset' => '',
				)
			)['success']
		);

		$year = (string) ( (int) gmdate( 'Y' ) + 1 );
		$this->assertTrue(
			$this->update(
				array(
					'expires'        => $year . '-06-30',
					'expect_expires' => '',
				)
			)['success']
		);
		$this->assertTrue(
			$this->update(
				array(
					'expires'        => $year . '-07-01',
					'expect_expires' => $year . '-06-30',
				)
			)['success']
		);

		$this->assertTrue(
			$this->update(
				array(
					'rate'        => '9',
					'expect_rate' => '',
				)
			)['success']
		);
		$this->assertFalse(
			$this->update(
				array(
					'rate'        => '8',
					'expect_rate' => '',
				)
			)['success']
		);
	}
}
