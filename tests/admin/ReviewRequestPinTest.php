<?php
/**
 * The review prompt is the one that shipped in 1.7.x. These pins keep its wordpress.org-facing
 * contract from drifting: admins only, a bare review link, a bounded ask, no incentive wording.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ReviewRequestPinTest extends TestCase {

	public function test_the_link_is_the_plain_review_page(): void {
		$this->assertSame( 'https://wordpress.org/support/plugin/agent-abilities-for-mcp/reviews/#new-post', aafm_review_request_url() );
	}

	public function test_the_ask_is_bounded(): void {
		$this->assertSame( 10, aafm_review_request_call_threshold() );
		$this->assertSame( 7 * DAY_IN_SECONDS, aafm_review_request_wait_seconds() );
		$this->assertSame( 2, aafm_review_request_snooze_cap() );
	}

	public function test_it_is_never_eligible_for_a_user_who_cannot_manage_options(): void {
		update_option(
			'aafm_review_request',
			array(
				'status'                => 'pending',
				'first_success_seen_at' => time() - 30 * DAY_IN_SECONDS,
				'threshold_met'         => 1,
			)
		);
		foreach ( array( 'editor', 'author', 'subscriber' ) as $role ) {
			$this->acting_as( $role );
			$this->assertFalse( aafm_review_request_eligible(), $role );
		}
	}

	public function test_the_notice_source_offers_no_incentive_wording(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src   = strtolower( (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/review-request.php' ) );
		$shown = (string) substr( $src, (int) strpos( $src, 'function aafm_render_review_request_notice' ) );
		foreach ( array( 'discount', 'free license', 'reward', 'in exchange', 'five star', '5 star', '5-star' ) as $word ) {
			$this->assertStringNotContainsString( $word, $shown, $word );
		}
	}
}
