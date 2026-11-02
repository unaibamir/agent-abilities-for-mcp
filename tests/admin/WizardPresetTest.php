<?php
/**
 * Tests for the default-access preset in Quick Connect.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

/**
 * Covers the preset select and the finish handler's preset field.
 */
class WizardPresetTest extends TestCase {

	use AjaxHarness;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_quickconnect_finished' );
		delete_option( 'aafm_quickconnect_dismissed' );
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		delete_option( 'aafm_quickconnect_finished' );
		parent::tear_down();
	}

	/**
	 * Run the finish handler.
	 *
	 * @param array<string,mixed> $fields POST fields.
	 * @return array<string,mixed>
	 */
	private function finish( array $fields ): array {
		$this->post_as( 'administrator', $fields );
		return $this->run_handler( 'aafm_ajax_quickconnect_finish' );
	}

	public function test_finish_without_a_preset_field_leaves_the_default_alone(): void {
		$this->assertTrue( $this->finish( array() )['success'] );
		$this->assertFalse( aafm_option_row( 'aafm_oauth_default_preset' )['found'] );
	}

	public function test_finish_stores_the_posted_preset_through_the_verified_writer(): void {
		$this->assertTrue( $this->finish( array( 'preset' => 'content_editor' ) )['success'] );
		$this->assertSame( 'content_editor', aafm_option_row( 'aafm_oauth_default_preset' )['value'] );
		$this->assertSame( 'content_editor', aafm_default_preset() );

		$this->assertTrue( $this->finish( array( 'preset' => 'custom' ) )['success'] );
		$this->assertSame( 'custom', aafm_option_row( 'aafm_oauth_default_preset' )['value'] );
		$this->assertSame( '', aafm_default_preset() );
	}

	public function test_finish_refuses_an_unknown_preset_without_failing_the_rest(): void {
		foreach ( array( 'bogus', array( 'read_only' ), '' ) as $bad ) {
			delete_option( 'aafm_quickconnect_finished' );
			$this->assertTrue( $this->finish( array( 'preset' => $bad ) )['success'] );
			$this->assertSame( '1', get_option( 'aafm_quickconnect_finished' ) );
			$this->assertFalse( aafm_option_row( 'aafm_oauth_default_preset' )['found'] );
		}
	}

	public function test_the_wizard_step_renders_a_preset_select_defaulting_to_custom(): void {
		$this->acting_as( 'administrator' );
		ob_start();
		aafm_quickconnect_render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<select id="aafm-qc-preset" data-qc-preset>', $html );
		$this->assertMatchesRegularExpression( '/<option value="custom" selected/', $html );
		$this->assertStringContainsString( 'New connections start with this access.', $html );
	}
}
