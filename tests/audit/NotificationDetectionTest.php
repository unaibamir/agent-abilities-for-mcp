<?php
/**
 * The five detection points: switch flips, OAuth grants, Application Passwords, risky runs, and denial
 * bursts plus rate-limit trips. Each fires through the real call path or the real log function.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class NotificationDetectionTest extends TestCase {

	/**
	 * Messages captured instead of sent.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $mail = array();

	/**
	 * The captured messages, after the end-of-request flush a real request runs on shutdown.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function sent(): array {
		aafm_notify_flush_outbox();
		return $this->mail;
	}

	public function set_up(): void {
		parent::set_up();
		$this->mail = array();
		aafm_notify_outbox( 'take' ); // Drop anything an earlier test left queued.
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		aafm_update_option_verified(
			'aafm_notifications',
			array(
				'mode'     => 'immediate',
				'email'    => 'owner@example.com',
				'triggers' => array(
					'new_app'      => true,
					'risky_run'    => true,
					'denial_burst' => true,
					'switch_flip'  => true,
				),
			),
			true
		);
	}

	/**
	 * Capture the message instead of sending it.
	 *
	 * @param mixed               $pre  Short-circuit value.
	 * @param array<string,mixed> $atts wp_mail() arguments.
	 * @return bool
	 */
	public function capture_mail( $pre, array $atts ): bool {
		unset( $pre );
		$this->mail[] = $atts;
		return true;
	}

	public function tear_down(): void {
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'wirefix/', 8 ) || 0 === strncmp( $slug, 'aafm-bridge/wirefix-', 20 ) ) {
				wp_unregister_ability( $slug );
			}
		}
		if ( wp_has_ability_category( 'notify-fixture' ) ) {
			wp_unregister_ability_category( 'notify-fixture' );
		}
		delete_option( 'aafm_enabled_bridged_abilities' );
		parent::tear_down();
	}

	/**
	 * Register a foreign ability with the given annotations and bridge it through the real wrapper path.
	 * Same shape as BridgeObjectRefusalWireTest::bridge_foreign().
	 *
	 * @param string              $slug        Foreign slug, "wirefix/<name>".
	 * @param array<string,mixed> $annotations Declared annotations, possibly none.
	 * @return string The wrapper ability name.
	 */
	private function bridge_foreign( string $slug, array $annotations ): string {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'notify-fixture' ) ) {
					wp_register_ability_category(
						'notify-fixture',
						array(
							'label'       => 'Notify fixture',
							'description' => 'Throwaway fixture.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $slug, $annotations ): void {
				wp_register_ability(
					$slug,
					array(
						'label'               => $slug,
						'description'         => 'Fixture.',
						'category'            => 'notify-fixture',
						'input_schema'        => array( 'type' => 'object' ),
						'execute_callback'    => static fn() => array(),
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => $annotations ),
					)
				);
			}
		);
		update_option( 'aafm_enabled_bridged_abilities', array( $slug ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_bridged_abilities' );
		return aafm_bridge_tool_name( $slug );
	}

	public function test_a_read_only_flip_notifies_with_its_direction_and_row(): void {
		aafm_log_read_only_switch_change( false, true, true );
		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'Read-only mode was turned on', $this->sent()[0]['subject'] );
		$row = aafm_query_activity( array( 'ability' => 'aafm/read-only-mode' ) )[0];
		$this->assertStringContainsString( 'tab=activity&row=' . $row['id'], $this->sent()[0]['message'] );

		aafm_log_read_only_switch_change( true, false, true );
		$this->assertCount( 2, $this->sent() );
		$this->assertStringContainsString( 'turned off', $this->sent()[1]['subject'] );
	}

	public function test_a_flip_that_did_not_persist_or_did_not_change_is_silent(): void {
		aafm_log_read_only_switch_change( false, true, false );
		aafm_log_read_only_switch_change( true, true, true );
		aafm_log_high_risk_switch_change( false, false, true );
		$this->assertSame( array(), $this->sent() );
	}

	public function test_a_high_risk_lock_flip_notifies_with_its_direction(): void {
		aafm_log_high_risk_switch_change( false, true, true );
		$this->assertStringContainsString( 'The high-risk lock was opened', $this->sent()[0]['subject'] );
		aafm_log_high_risk_switch_change( true, false, true );
		$this->assertStringContainsString( 'The high-risk lock was closed', $this->sent()[1]['subject'] );
	}

	public function test_an_approved_oauth_grant_notifies_but_a_registration_does_not(): void {
		aafm_install_oauth_tables();
		$client = aafm_oauth_register_client(
			array(
				'redirect_uris' => array( 'https://app.example/cb' ),
				'client_name'   => 'Claude',
			)
		);
		$this->assertIsArray( $client );

		aafm_oauth_log_event( 'register', 'success', array( 'client_id' => $client['client_id'] ) );
		aafm_oauth_log_event( 'authorize', 'denied', array( 'client_id' => $client['client_id'] ) );
		$this->assertSame( array(), $this->sent() );

		aafm_oauth_log_event(
			'authorize',
			'success',
			array(
				'client_id'     => $client['client_id'],
				'redirect_host' => 'app.example',
				'user_id'       => 1,
			)
		);
		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'An app was authorized to connect', $this->sent()[0]['subject'] );
		$this->assertStringContainsString( $client['client_id'], $this->sent()[0]['message'] );
		$this->assertStringContainsString( 'The name the app gave itself: Claude', $this->sent()[0]['message'] );
		$this->assertStringNotContainsString( 'Claude', $this->sent()[0]['subject'], 'An app-chosen name is never in the subject.' );
	}

	public function test_creating_an_application_password_notifies(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'Demo' ) );
		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'An Application Password was created', $this->sent()[0]['subject'] );
		$this->assertStringContainsString( '#' . $user_id, $this->sent()[0]['message'] );
		$this->assertStringNotContainsString( 'Demo', $this->sent()[0]['message'], 'The app name is not read.' );
	}

	public function test_an_application_password_for_a_member_who_cannot_edit_is_not_an_alert(): void {
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\WP_Application_Passwords::create_new_application_password( $member, array( 'name' => 'Phone' ) );
		$this->assertSame( array(), $this->sent(), 'Core lets every role make one; a signed-up member must not keep the owner\'s alerts busy.' );
		$this->assertSame( array(), aafm_notify_pending_list() );
	}

	public function test_an_application_password_for_the_plugins_agent_user_is_an_alert_even_without_edit_rights(): void {
		$agent = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $agent, aafm_agent_user_marker_meta_key(), 1 );
		\WP_Application_Passwords::create_new_application_password( $agent, array( 'name' => 'Agent' ) );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_a_bridged_tool_alarms_exactly_when_the_bridge_says_destructive(): void {
		$cases = array(
			'undeclared'               => array( array(), 'bridged_destructive' ),
			'read only'                => array( array( 'readonly' => true ), null ),
			'declared not destructive' => array( array( 'destructive' => false ), null ),
			'declared destructive'     => array( array( 'destructive' => true ), 'bridged_destructive' ),
			'contradictory'            => array(
				array(
					'readonly'    => true,
					'destructive' => true,
				),
				'bridged_destructive',
			),
		);
		$n     = 0;
		foreach ( $cases as $label => $case ) {
			list( $annotations, $expected ) = $case;
			$wrapper                        = $this->bridge_foreign( 'wirefix/probe-' . ( ++$n ), $annotations );
			$this->assertSame( $expected, aafm_notify_ability_risk( $wrapper ), $label );
			$this->assertSame( null !== $expected, ! empty( aafm_bridge_risk( wp_get_ability( $wrapper ) )['destructive'] ), $label . ': the alert and the admin confirm share one rule.' );
		}
	}

	public function test_risk_classification(): void {
		add_filter(
			'aafm_high_risk_abilities',
			static function ( array $extra ): array {
				$extra[] = 'aafm/get-taxonomies';
				return $extra;
			}
		);
		$this->assertSame( 'high_risk', aafm_notify_ability_risk( 'aafm/get-taxonomies' ) );
		$this->assertSame( 'permanent_delete', aafm_notify_ability_risk( 'aafm/delete-post' ) );
		$this->assertNull( aafm_notify_ability_risk( 'aafm/trash-post' ), 'Recoverable trash is not alarming.' );
		$this->assertNull( aafm_notify_ability_risk( 'aafm/update-post' ) );
		$this->assertNull( aafm_notify_ability_risk( 'aafm/get-posts' ) );
	}

	public function test_only_a_successful_risky_run_notifies(): void {
		aafm_notify_ability_finished( 'aafm/delete-post', 'error', 5 );
		aafm_notify_ability_finished( 'aafm/delete-post', 'denied', 5 );
		aafm_notify_ability_finished( 'aafm/update-post', 'success', 5 );
		$this->assertSame( array(), $this->sent() );

		aafm_notify_ability_finished( 'aafm/delete-post', 'success', 5 );
		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'aafm/delete-post', $this->sent()[0]['message'] );
	}

	public function test_a_real_high_risk_call_notifies_end_to_end(): void {
		add_filter(
			'aafm_high_risk_abilities',
			static function ( array $extra ): array {
				$extra[] = 'aafm/get-taxonomies';
				return $extra;
			}
		);
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/get-taxonomies' ) );

		wp_get_ability( 'aafm/get-taxonomies' )->execute( array() );

		$this->assertCount( 1, $this->sent() );
		$row = aafm_query_activity( array( 'ability' => 'aafm/get-taxonomies' ) )[0];
		$this->assertStringContainsString( 'tab=activity&row=' . $row['id'], $this->sent()[0]['message'] );
	}

	public function test_a_denial_burst_fires_once_at_the_threshold(): void {
		for ( $i = 1; $i < AAFM_NOTIFY_BURST_COUNT; $i++ ) {
			aafm_announce_ability_resolved( null, 'denied', null, null );
		}
		$this->assertSame( array(), $this->sent(), 'One short of the threshold is silent.' );
		aafm_announce_ability_resolved( null, 'denied', null, null );
		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'Many calls were denied', $this->sent()[0]['subject'] );
		$this->assertStringContainsString( 'filter=denied', $this->sent()[0]['message'] );
		aafm_announce_ability_resolved( null, 'success', null, null );
		$this->assertCount( 1, $this->sent(), 'Other statuses are not counted.' );
	}

	public function test_a_rate_limit_trip_notifies(): void {
		update_option( 'aafm_rate_limit_per_min', 1 );
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/get-taxonomies' ) );

		wp_get_ability( 'aafm/get-taxonomies' )->execute( array() );
		$this->assertSame( array(), $this->sent() );
		wp_get_ability( 'aafm/get-taxonomies' )->execute( array() );

		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'A connection hit the rate limit', $this->sent()[0]['subject'] );
		$detail = (string) aafm_query_activity(
			array(
				'ability' => 'aafm/get-taxonomies',
				'status'  => 'denied',
			)
		)[0]['detail'];
		$this->assertStringContainsString( 'Refused: rate limit reached', aafm_localize_denied_detail( $detail ), 'The stored marker is the untranslated code; the admin localizes it.' );
	}

	public function test_with_notifications_off_the_call_path_does_nothing_extra(): void {
		delete_option( 'aafm_notifications' );
		aafm_log_read_only_switch_change( false, true, true );
		aafm_notify_ability_finished( 'aafm/delete-post', 'success', 5 );
		aafm_announce_ability_resolved( null, 'denied', null, null );
		$this->assertSame( array(), $this->sent() );
		$this->assertSame( array(), aafm_notify_pending_list() );
	}
}
