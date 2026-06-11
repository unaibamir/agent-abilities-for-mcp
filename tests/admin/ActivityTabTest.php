<?php
/**
 * Activity tab renders rows including denials, escaped.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ActivityTabTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function test_tab_lists_a_denied_row(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability'  => 'aafm/trash-post',
				'status'   => 'denied',
				'arg_keys' => array( 'post_id' ),
			)
		);

		ob_start();
		aafm_render_activity_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aafm/trash-post', $html );
		$this->assertStringContainsString( 'denied', $html );
		$this->assertStringContainsString( 'post_id', $html );
	}

	public function test_tab_escapes_ability_names(): void {
		$this->acting_as( 'administrator' );
		aafm_log_activity(
			array(
				'ability' => '<script>x</script>',
				'status'  => 'error',
			)
		);

		ob_start();
		aafm_render_activity_tab();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>x</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_empty_log_shows_placeholder_row(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_activity_tab();
		$html = (string) ob_get_clean();

		// A clear-log control is always present; the empty state renders without fataling.
		$this->assertStringContainsString( 'aafm-clear-log', $html );
		$this->assertStringContainsString( 'aafm-log-table', $html );
	}
}
