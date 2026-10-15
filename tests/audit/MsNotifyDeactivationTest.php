<?php
/**
 * Network-wide deactivation must clear the notification digest event on every site, not only the
 * site the deactivation ran from.
 *
 * Runs only under tests/multisite.xml.dist; the single-site config skips it.
 *
 * @group ms-required
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

/**
 * Pins aafm_notify_unschedule() on a network.
 *
 * @group ms-required
 */
final class MsNotifyDeactivationTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
	}

	/**
	 * Whether the digest event is scheduled on a site.
	 *
	 * @param int $blog_id Site id.
	 * @return bool
	 */
	private function scheduled_on( int $blog_id ): bool {
		switch_to_blog( $blog_id );
		$next = wp_next_scheduled( 'aafm_notifications_digest' );
		restore_current_blog();
		return false !== $next;
	}

	/**
	 * Schedule the digest event on a site.
	 *
	 * @param int $blog_id Site id.
	 * @return void
	 */
	private function schedule_on( int $blog_id ): void {
		switch_to_blog( $blog_id );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aafm_notifications_digest' );
		restore_current_blog();
	}

	public function test_network_deactivation_clears_the_digest_on_every_site(): void {
		$sub  = (int) self::factory()->blog->create();
		$main = get_main_site_id();
		$this->schedule_on( $main );
		$this->schedule_on( $sub );

		aafm_notify_unschedule( true );

		$this->assertFalse( $this->scheduled_on( $main ), 'The main site is cleared.' );
		$this->assertFalse( $this->scheduled_on( $sub ), 'A subsite is cleared too.' );
	}

	public function test_per_site_deactivation_leaves_other_sites_alone(): void {
		$sub  = (int) self::factory()->blog->create();
		$main = get_main_site_id();
		$this->schedule_on( $main );
		$this->schedule_on( $sub );

		aafm_notify_unschedule( false );

		$this->assertFalse( $this->scheduled_on( $main ), 'The current site is cleared.' );
		$this->assertTrue( $this->scheduled_on( $sub ), 'A per-site deactivation does not reach other sites.' );
	}
}
