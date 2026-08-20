<?php
/**
 * The Quick Connect wizard is a first-run screen, so it has to stop appearing
 * once the site has been set up, whether or not that happened inside the wizard.
 *
 * Before this, the two wizard flags were the only thing consulted, so an
 * operator who closed the modal with the X and configured the plugin through the
 * normal tabs was treated as a first-run site forever.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class QuickConnectInferenceTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		delete_option( 'aafm_enabled_abilities' );
		delete_option( 'aafm_quickconnect_finished' );
		delete_option( 'aafm_quickconnect_dismissed' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The whole point of a first-run screen: an untouched site still gets it.
	 */
	public function test_an_untouched_site_still_gets_the_wizard(): void {
		$this->assertFalse( aafm_quickconnect_site_looks_configured() );
		$this->assertTrue( aafm_quickconnect_should_render() );
	}

	/**
	 * The reported symptom. The operator configured the plugin through the normal
	 * tabs and closed the modal with the X, which persists nothing.
	 */
	public function test_an_enabled_ability_is_enough_to_stop_treating_the_site_as_first_run(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post' ) );

		$this->assertTrue( aafm_quickconnect_site_looks_configured() );
		$this->assertFalse( aafm_quickconnect_should_render() );
	}

	/**
	 * An empty option, or one holding only empty strings, is not evidence of
	 * anything and must not suppress a genuine first run.
	 */
	public function test_an_empty_enabled_option_is_not_evidence(): void {
		update_option( 'aafm_enabled_abilities', array() );
		$this->assertTrue( aafm_quickconnect_should_render() );

		update_option( 'aafm_enabled_abilities', array( '', '' ) );
		$this->assertTrue( aafm_quickconnect_should_render() );
	}

	/**
	 * An agent has actually called something, which is about as clear as evidence
	 * of a working setup gets.
	 */
	public function test_an_activity_log_row_stops_treating_the_site_as_first_run(): void {
		$this->assertTrue( aafm_quickconnect_should_render() );

		aafm_log_activity(
			array(
				'ability' => 'aafm/get-post',
				'status'  => 'success',
			)
		);

		$this->assertTrue( aafm_quickconnect_site_looks_configured() );
		$this->assertFalse( aafm_quickconnect_should_render() );
	}

	/**
	 * Inference must not write either flag. They mean specific things, the reset
	 * and uninstall paths list them, and a render has always been free of side
	 * effects.
	 */
	public function test_inference_writes_neither_flag(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post' ) );

		$this->assertFalse( aafm_quickconnect_should_render() );
		$this->assertSame( '', (string) get_option( 'aafm_quickconnect_finished', '' ) );
		$this->assertSame( '', (string) get_option( 'aafm_quickconnect_dismissed', '' ) );
		$this->assertFalse( aafm_quickconnect_is_finished() );
		$this->assertFalse( aafm_quickconnect_is_dismissed() );
	}

	/**
	 * The two original exits keep working unchanged.
	 */
	public function test_the_finished_and_dismissed_flags_still_close_the_wizard(): void {
		update_option( 'aafm_quickconnect_finished', '1' );
		$this->assertFalse( aafm_quickconnect_should_render() );

		delete_option( 'aafm_quickconnect_finished' );
		update_option( 'aafm_quickconnect_dismissed', '1' );
		$this->assertFalse( aafm_quickconnect_should_render() );
	}

	/**
	 * Capability gating is unchanged and still comes first.
	 */
	public function test_a_user_without_manage_options_never_gets_the_wizard(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( aafm_quickconnect_should_render() );
	}

	/**
	 * The knock-on. The review-request notice is suppressed on the plugin's own
	 * page while the wizard is due, so a site stuck in the old state never saw
	 * the ask there either. Fixing the wizard has to unblock it.
	 */
	public function test_the_review_notice_is_no_longer_suppressed_on_a_configured_site(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post' ) );

		set_current_screen( 'toplevel_page_agent-abilities-for-mcp' );
		$this->assertFalse(
			aafm_quickconnect_should_render(),
			'The wizard must be closed, which is what unblocks the review ask on this screen.'
		);
	}

	/**
	 * Found by the Codex review pass: native and bridged abilities live in two separate options.
	 * A site running purely on bridged integrations has no native ability enabled at all, and
	 * reading only the native option kept calling it a first-run site.
	 */
	public function test_a_bridged_only_site_is_not_treated_as_first_run(): void {
		delete_option( 'aafm_enabled_abilities' );
		update_option( 'aafm_enabled_bridged_abilities', array( 'woocommerce/list-products' ) );

		$this->assertTrue( aafm_quickconnect_site_looks_configured() );
		$this->assertFalse( aafm_quickconnect_should_render() );
	}

	/**
	 * Found by the second Codex review pass. Revoking an OAuth client deactivates the client row
	 * and its tokens but deliberately leaves the consent row, and aafm_oauth_list_grants() does not
	 * filter on the client being active -- that listing is shipped 1.6.3 behaviour and stays as it
	 * is. What is new here is reading it as first-run evidence. The grant branch is only reached
	 * once the active-client count is already zero, so every grant it can see belongs to a
	 * deactivated or deleted client by construction: it can only ever suppress the wizard wrongly.
	 * 1.6.3 had no inference at all and always reopened the wizard, so an administrator who revoked
	 * their only client could no longer get the first-run screen back.
	 */
	public function test_a_revoked_clients_leftover_consent_is_not_first_run_evidence(): void {
		if ( ! function_exists( 'aafm_oauth_register_client' ) || ! function_exists( 'aafm_install_oauth_tables' ) ) {
			$this->markTestSkipped( 'OAuth client helpers unavailable.' );
		}

		aafm_install_oauth_tables();

		$client = aafm_oauth_register_client(
			array(
				'client_name'   => 'AAFM Revoked Client',
				'redirect_uris' => array( 'https://example.test/cb' ),
			)
		);
		$this->assertIsArray( $client );
		$client_id = (string) $client['client_id'];

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'client_id'  => $client_id,
				'wp_user_id' => get_current_user_id(),
				'granted_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->assertTrue(
			aafm_quickconnect_site_looks_configured(),
			'Sanity: while the client is still active the grant is genuine evidence.'
		);

		aafm_oauth_deactivate_client( $client_id );

		$this->assertNotSame(
			array(),
			aafm_oauth_list_grants(),
			'The admin grant listing keeps showing the row -- that is 1.6.3 behaviour and is not what this fixes.'
		);
		$this->assertFalse(
			aafm_quickconnect_site_looks_configured(),
			'A consent belonging to a revoked client is not evidence that the site is set up.'
		);
		$this->assertTrue(
			aafm_quickconnect_should_render(),
			'The administrator must be able to get the first-run wizard back after revoking their only client.'
		);
	}
}
