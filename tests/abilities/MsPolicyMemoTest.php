<?php
/**
 * PM build-3 test (b): the policy memo is kept per blog, so a switch_to_blog() inside a request
 * reads the other site's own row. Runs only under tests/multisite.xml.dist.
 *
 * @group ms-required
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

/**
 * Two sites, one policy option, two rows.
 *
 * @group ms-required
 */
final class MsPolicyMemoTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
		$_SERVER['REQUEST_URI'] = self::mcp_rest_path();
		aafm_policy_reset_request_state();
	}

	public function test_a_switched_blog_reads_its_own_policy_row(): void {
		$other = self::factory()->blog->create();
		update_option( 'aafm_rate_limit_per_min', 5 );
		switch_to_blog( $other );
		update_option( 'aafm_rate_limit_per_min', 7 );
		restore_current_blog();
		aafm_policy_reset_request_state();

		$this->assertSame( 5, aafm_rate_limit_per_min() );
		switch_to_blog( $other );
		try {
			$this->assertSame( 7, aafm_rate_limit_per_min() );
		} finally {
			restore_current_blog();
		}
	}
}
