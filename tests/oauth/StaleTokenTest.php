<?php
/**
 * Tokens minted for an old site address (SC-22): counted and deactivated by a byte-exact
 * comparison of the stored resource with aafm_endpoint_url(), the same comparison bearer
 * validation makes. Tokens for the current address and consent rows are never touched.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class StaleTokenTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
	}

	/**
	 * Seed an access-token row.
	 *
	 * @param string $audience  Stored audience (the resource column).
	 * @param int    $is_active 1 active, 0 inactive.
	 * @return int Row id.
	 */
	private function seed_token( string $audience, int $is_active = 1 ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_access_tokens',
			array(
				'token_hash'   => hash( 'sha256', 't' . wp_rand() . $audience ),
				'refresh_hash' => hash( 'sha256', 'r' . wp_rand() . $audience ),
				'client_id'    => 'app',
				'wp_user_id'   => 7,
				'resource'     => $audience,
				'is_active'    => $is_active,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Whether a token row is still active.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	private function is_active( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT is_active FROM %i WHERE id = %d', $wpdb->prefix . 'aafm_oauth_access_tokens', $id ) );
	}

	public function test_counts_only_tokens_with_other_resource(): void {
		$this->seed_token( aafm_endpoint_url() );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$this->seed_token( 'https://older.example/wp-json/aafm/v1/mcp' );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp', 0 );

		$this->assertSame( 2, aafm_oauth_stale_token_count() );
	}

	public function test_case_variant_resource_counts_as_stale(): void {
		$current = aafm_endpoint_url();
		$this->assertNotSame( strtoupper( $current ), $current, 'Sanity: the endpoint has lower-case letters to vary.' );
		$variant = $this->seed_token( strtoupper( $current ) );
		$this->seed_token( $current . ' ' );

		$this->assertSame( 2, aafm_oauth_stale_token_count(), 'A case or trailing-space variant fails hash_equals(), so it is stale.' );
		$this->assertSame( 2, aafm_oauth_revoke_stale_tokens() );
		$this->assertFalse( $this->is_active( $variant ) );
	}

	public function test_revoke_deactivates_exact_count_and_returns_it(): void {
		$old = array(
			$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' ),
			$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' ),
			$this->seed_token( '' ),
		);

		$this->assertSame( 3, aafm_oauth_revoke_stale_tokens() );
		foreach ( $old as $id ) {
			$this->assertFalse( $this->is_active( $id ) );
		}
		$this->assertSame( 0, aafm_oauth_stale_token_count() );
		$this->assertSame( 0, aafm_oauth_revoke_stale_tokens(), 'Nothing left to deactivate.' );
	}

	public function test_current_resource_tokens_survive(): void {
		$current = $this->seed_token( aafm_endpoint_url() );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );

		$this->assertSame( 1, aafm_oauth_revoke_stale_tokens() );
		$this->assertTrue( $this->is_active( $current ) );
	}

	public function test_consent_rows_are_untouched(): void {
		global $wpdb;
		$consents = $wpdb->prefix . 'aafm_oauth_consents';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$consents,
			array(
				'wp_user_id' => 7,
				'client_id'  => 'app',
			),
			array( '%d', '%s' )
		);
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );

		$this->assertSame( 1, aafm_oauth_revoke_stale_tokens() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $consents ) ) );
	}

	/**
	 * In wp-admin on an http site with FORCE_SSL_ADMIN the endpoint computes as https, while a
	 * REST request computes it as http. A token minted for the other scheme of the same address
	 * still works there, so it is not "old" and the button must not end it.
	 */
	public function test_other_scheme_of_the_current_address_is_not_stale(): void {
		$current = aafm_endpoint_url();
		$other   = 0 === strpos( $current, 'https:' ) ? set_url_scheme( $current, 'http' ) : set_url_scheme( $current, 'https' );
		$this->assertNotSame( $current, $other );
		$kept = $this->seed_token( $other );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );

		$this->assertSame( 1, aafm_oauth_stale_token_count() );
		$this->assertSame( 1, aafm_oauth_revoke_stale_tokens() );
		$this->assertTrue( $this->is_active( $kept ) );
	}
}
