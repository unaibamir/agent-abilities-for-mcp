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

	public function tear_down(): void {
		unset( $_SERVER['HTTPS'] );
		parent::tear_down();
	}

	/**
	 * After the site moves from http to https, a REST request expects the https endpoint, so a
	 * token minted for the http address is rejected by validation and must count as old.
	 */
	public function test_http_token_after_an_https_move_is_stale(): void {
		$http = set_url_scheme( aafm_endpoint_url(), 'http' );
		update_option( 'home', set_url_scheme( (string) get_option( 'home' ), 'https' ) );
		update_option( 'siteurl', set_url_scheme( (string) get_option( 'siteurl' ), 'https' ) );
		$this->assertStringStartsWith( 'https:', aafm_endpoint_url() );
		$old  = $this->seed_token( $http );
		$kept = $this->seed_token( aafm_endpoint_url() );

		$this->assertSame( 1, aafm_oauth_stale_token_count() );
		$this->assertSame( 1, aafm_oauth_revoke_stale_tokens() );
		$this->assertFalse( $this->is_active( $old ) );
		$this->assertTrue( $this->is_active( $kept ) );
	}

	/**
	 * Every audience validation accepts on an http site (the endpoint a plain REST request builds
	 * and the one an https REST request builds) is never counted, and each passes the validator's
	 * own comparison, hash_equals() against aafm_endpoint_url() in that request's context.
	 */
	public function test_a_token_the_validator_accepts_is_never_stale(): void {
		$this->assertStringStartsWith( 'http:', (string) get_option( 'home' ), 'Fixture: an http site.' );
		$plain            = aafm_endpoint_url();
		$_SERVER['HTTPS'] = 'on';
		$secure           = aafm_endpoint_url();
		unset( $_SERVER['HTTPS'] );
		$this->assertNotSame( $plain, $secure, 'Core builds a different endpoint for an https request.' );

		foreach ( array( $plain, $secure ) as $audience ) {
			$this->seed_token( $audience );
			$this->assertTrue( aafm_oauth_audience_accepted( $audience ) );
		}
		// Both request-context endpoints are listed as audiences, so a byte-exact compare against
		// the admin screen's own endpoint alone (which is only one of them) would fail this.
		$this->assertEqualsCanonicalizing( array( $plain, $secure ), aafm_oauth_accepted_audiences() );
		$this->assertSame( 0, aafm_oauth_stale_token_count() );
		$this->assertSame( 0, aafm_oauth_revoke_stale_tokens() );
	}

	public function test_grouped_count_equals_the_id_list_count(): void {
		$accepted = aafm_endpoint_url();
		$this->seed_token( $accepted );
		$this->seed_token( $accepted );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$this->seed_token( strtoupper( $accepted ) );
		$this->seed_token( $accepted . ' ' );
		$this->seed_token( $accepted, 0 );

		$this->assertSame( 4, aafm_oauth_stale_token_count() );
		$this->assertSame( count( (array) aafm_oauth_stale_token_ids() ), aafm_oauth_stale_token_count() );
	}

	public function test_count_runs_one_grouped_statement(): void {
		$this->seed_token( aafm_endpoint_url() );
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$seen = array();
		$spy  = static function ( string $sql ) use ( &$seen ): string {
			if ( false !== strpos( $sql, 'aafm_oauth_access_tokens' ) ) {
				$seen[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $spy );
		$count = aafm_oauth_stale_token_count();
		remove_filter( 'query', $spy );

		$this->assertSame( 1, $count );
		$this->assertCount( 1, $seen );
		$this->assertStringContainsString( 'GROUP BY', $seen[0] );
		$this->assertStringContainsString( 'COUNT(', $seen[0] );
	}

	public function test_failed_read_counts_zero(): void {
		$this->seed_token( 'https://old.example/wp-json/aafm/v1/mcp' );
		$break = static function ( string $sql ): string {
			return false !== strpos( $sql, 'aafm_oauth_access_tokens' ) ? 'SELECT nope FROM no_such_table_aafm' : $sql;
		};
		add_filter( 'query', $break );
		$count = aafm_oauth_stale_token_count();
		remove_filter( 'query', $break );
		$this->assertSame( 0, $count );
	}

	public function test_rows_with_an_empty_resource_count_as_stale(): void {
		$this->seed_token( '' );
		$this->seed_token( aafm_endpoint_url() );
		$this->assertSame( 1, aafm_oauth_stale_token_count() );
		$this->assertSame( count( (array) aafm_oauth_stale_token_ids() ), aafm_oauth_stale_token_count() );
	}
}
