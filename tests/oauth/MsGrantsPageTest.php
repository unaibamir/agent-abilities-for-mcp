<?php
/**
 * Multisite: the paged grant read lists a grant whose user is not a member of the current site.
 * The users join reads the network's users table, where get_users() would drop such a user and
 * leave a grant nobody can see or revoke.
 *
 * Runs only under tests/multisite.xml.dist; the single-site config skips it.
 *
 * @group ms-required
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Pins the multisite membership case of aafm_oauth_grants_page().
 *
 * @group ms-required
 */
final class MsGrantsPageTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
	}

	public function test_grants_page_row_for_user_not_member_of_site(): void {
		global $wpdb;
		$user_id = self::factory()->user->create();
		remove_user_from_blog( $user_id, get_current_blog_id() );
		$this->assertFalse( is_user_member_of_blog( $user_id, get_current_blog_id() ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'     => 'app',
				'client_name'   => 'App',
				'redirect_uris' => '[]',
				'is_active'     => 1,
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $user_id,
				'client_id'  => 'app',
			)
		);

		$page = aafm_oauth_grants_page( 1 );
		$this->assertSame( 1, $page['total'] );
		$this->assertSame( $user_id, $page['rows'][0]['user_id'] );
		$this->assertNotSame( '', $page['rows'][0]['user_login'] );
	}
}
