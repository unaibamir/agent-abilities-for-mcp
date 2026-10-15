<?php
/**
 * The numbers and promises in the alerts FAQ entry are held by constants and behaviour in code.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class NotificationClaimsTest extends TestCase {

	private function faq_entry(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/= Can I get an email when something important happens\? =(.+?)(?:\n= |\n== )/s', $readme, $m ), 'The alerts FAQ entry must exist.' );
		return $m[1];
	}

	public function test_the_throttle_numbers_in_the_faq_are_the_real_constants(): void {
		$faq = $this->faq_entry();
		$this->assertSame( 900, AAFM_NOTIFY_WINDOW );
		$this->assertStringContainsString( '15 minutes', $faq );
		$this->assertSame( 6, AAFM_NOTIFY_GLOBAL_MAX );
		$this->assertStringContainsString( 'six emails an hour', $faq );
		$this->assertSame( 2, AAFM_NOTIFY_DENIAL_MAX );
		$this->assertStringContainsString( 'two more an hour for bursts of denied calls', $faq );
	}

	public function test_the_faq_says_alerts_are_off_by_default_and_they_are(): void {
		$this->assertStringContainsString( 'off by default', strtolower( $this->faq_entry() ) );
		$this->assertSame( 'off', aafm_notifications_normalize( array() )['mode'] );
	}

	public function test_the_faq_names_the_four_triggers_and_three_modes_the_settings_card_offers(): void {
		$this->assertSame( array( 'off', 'notice', 'immediate', 'digest' ), aafm_notify_modes() );
		$this->assertCount( 4, aafm_notify_triggers() );
		$faq = strtolower( $this->faq_entry() );
		foreach ( array( 'admin notice only', 'email right away', 'daily email', 'application password', 'permanent delete', 'denied calls', 'read-only mode' ) as $phrase ) {
			$this->assertStringContainsString( $phrase, $faq );
		}
	}

	public function test_the_faq_says_emails_carry_no_user_name_or_ip_and_a_test_proves_it(): void {
		$this->assertStringContainsString( 'user name or an IP address', $this->faq_entry() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'test_the_message_never_names_a_user_or_an_ip_address', (string) file_get_contents( __DIR__ . '/../audit/NotificationsTest.php' ), 'The behavioural proof of the promise must exist.' );
	}

	public function test_the_faq_qualifies_the_application_password_and_app_authorization_triggers(): void {
		$faq = $this->faq_entry();
		$this->assertStringContainsString( 'an app being authorized to connect', $faq );
		$this->assertStringContainsString( 'created for a user who can edit posts or for the agent user', $faq );
		$this->assertStringContainsString( 'destructive tool from another plugin', $faq );
		$this->assertStringContainsString( 'count separately', $faq );
	}
}
