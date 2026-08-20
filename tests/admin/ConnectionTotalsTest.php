<?php
/**
 * The Connections tab shows how many clients are registered and how many grants
 * are live. The headings used to be bare, so the only way to know the totals was
 * to count the table rows by eye.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ConnectionTotalsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Render the OAuth management section and return its HTML.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		aafm_render_oauth_management();
		return (string) ob_get_clean();
	}

	/**
	 * With nothing registered the heading stays bare. The empty state underneath
	 * already says there is nothing, and "Registered clients (0)" above "No
	 * clients have registered yet" states it twice.
	 */
	public function test_the_headings_carry_no_count_when_there_is_nothing_to_count(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Registered clients</h3>', $html );
		$this->assertStringContainsString( 'Active grants</h3>', $html );
		$this->assertStringNotContainsString( 'Registered clients (0)', $html );
		$this->assertStringNotContainsString( 'Active grants (0)', $html );
	}

	/**
	 * The reported gap: with clients registered, the total is visible without
	 * counting rows.
	 */
	public function test_the_client_heading_carries_the_total(): void {
		// The OAuth tables are created on activation, which the fixture does not run.
		aafm_install_oauth_tables();

		aafm_oauth_register_client(
			array(
				'client_name'   => 'First client',
				'redirect_uris' => array( 'https://one.example/cb' ),
			)
		);
		aafm_oauth_register_client(
			array(
				'client_name'   => 'Second client',
				'redirect_uris' => array( 'https://two.example/cb' ),
			)
		);

		$this->assertStringContainsString( 'Registered clients (2)', $this->render() );
	}
}
