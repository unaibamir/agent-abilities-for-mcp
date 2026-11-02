<?php
/**
 * Tests for the pause-all card, its handler and the page-header pill.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * Covers aafm_render_pause_all_card(), aafm_ajax_set_pause_all() and the header pill.
 */
class PauseAllUiTest extends TestCase {

	use AjaxHarness;

	public function tear_down(): void {
		$this->reset_ajax_harness();
		remove_all_filters( 'query' );
		parent::tear_down();
	}

	private function set( $paused, string $role = 'administrator' ): array {
		$this->post_as( $role, array( 'paused' => $paused ) );
		return $this->run_handler( 'aafm_ajax_set_pause_all' );
	}

	private function rows(): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT detail FROM %i WHERE event_type = %s ORDER BY id', $wpdb->prefix . 'aafm_activity_log', 'setting_changed' ) );
	}

	public function test_the_card_shows_state_and_a_single_button(): void {
		ob_start();
		aafm_render_pause_all_card();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, '<button' ) );
		$this->assertStringContainsString( 'id="aafm-pause-all" aria-pressed="false">Pause all access</button>', $html );
		$this->assertStringContainsString( 'Application Passwords still work with the rest of the WordPress REST API', $html );

		aafm_set_pause_all( true );
		ob_start();
		aafm_render_pause_all_card();
		$this->assertStringContainsString( 'id="aafm-pause-all" aria-pressed="true">Resume all access</button>', (string) ob_get_clean() );
	}

	public function test_the_header_pill_shows_when_paused(): void {
		$this->acting_as( 'administrator' );
		$render = static function (): string {
			ob_start();
			aafm_render_admin_page();
			return (string) ob_get_clean();
		};
		$this->assertMatchesRegularExpression( '/data-posture="paused" hidden>Access paused</', $render() );
		$this->assertStringContainsString( '<div class="aafm-pause-all-notice" hidden>', $this->connection_tab() );
		aafm_set_pause_all( true );
		$this->assertMatchesRegularExpression( '/data-posture="paused">Access paused</', $render() );
		$this->assertStringContainsString( '<div class="aafm-pause-all-notice">', $this->connection_tab() );
	}

	private function connection_tab(): string {
		ob_start();
		aafm_render_connection_tab();
		return (string) ob_get_clean();
	}

	public function test_set_pause_all_requires_nonce_and_manage_options(): void {
		$this->assertFalse( $this->set( '1', 'editor' )['success'] );
		$this->post_as( 'administrator', array( 'paused' => '1' ) );
		$_POST['nonce']    = 'bad';
		$_REQUEST['nonce'] = 'bad';
		$this->run_handler( 'aafm_ajax_set_pause_all' );
		$this->assertFalse( aafm_pause_all() );
	}

	public function test_it_validates_the_value(): void {
		foreach ( array( '2', array( '1' ), '' ) as $bad ) {
			$this->assertFalse( $this->set( $bad )['success'] );
		}
		$this->assertFalse( aafm_pause_all() );
	}

	public function test_it_writes_a_setting_changed_row_for_each_real_change_and_none_for_a_no_op(): void {
		$this->assertTrue( $this->set( '1' )['data']['paused'] );
		$this->assertTrue( aafm_pause_all() );
		$this->set( '1' );
		$this->assertFalse( $this->set( '0' )['data']['paused'] );
		$this->assertFalse( aafm_pause_all() );
		$this->set( '0' );
		$this->assertSame( array( 'All access paused', 'All access resumed' ), $this->rows() );
	}

	public function test_it_reports_when_the_write_did_not_take(): void {
		$json = QueryFaultInjector::fail_query(
			array( 'INSERT INTO', 'aafm_pause_all' ),
			fn() => $this->set( '1' )
		);
		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( 'Pause all access', $json['data']['message'] );
		$this->assertFalse( aafm_option_row( 'aafm_pause_all' )['found'] );
		$this->assertSame( array(), $this->rows() );
	}
}
