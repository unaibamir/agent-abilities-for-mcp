<?php
/**
 * The paged grant reads behind the Active grants card (SB3-2, SB3-10): active clients only,
 * existing users only, a total that counts the same rows the pages hold, junk page numbers
 * clamped, and a single-row read that finds a grant on any page.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

final class GrantsPageTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
	}

	/**
	 * Seed a client row.
	 *
	 * @param string $client_id Public client id.
	 * @param int    $is_active 1 active, 0 revoked.
	 * @return void
	 */
	private function seed_client( string $client_id, int $is_active = 1 ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'     => $client_id,
				'client_name'   => 'App ' . $client_id,
				'redirect_uris' => '[]',
				'is_active'     => $is_active,
			),
			array( '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Seed a consent (grant) row.
	 *
	 * @param int    $user_id   Granting user.
	 * @param string $client_id Granted client.
	 * @return void
	 */
	private function seed_consent( int $user_id, string $client_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => $user_id,
				'client_id'  => $client_id,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Seed $count grants on active clients, five clients per user.
	 *
	 * @param int $count Grants to seed.
	 * @return list<array{0:int,1:string}> The seeded [user_id, client_id] pairs, oldest first.
	 */
	private function seed_grants( int $count ): array {
		$clients = array( 'app_a', 'app_b', 'app_c', 'app_d', 'app_e' );
		foreach ( $clients as $client ) {
			$this->seed_client( $client );
		}
		$pairs = array();
		$users = self::factory()->user->create_many( (int) ceil( $count / count( $clients ) ) );
		foreach ( $users as $user_id ) {
			foreach ( $clients as $client ) {
				if ( count( $pairs ) === $count ) {
					break 2;
				}
				$this->seed_consent( (int) $user_id, $client );
				$pairs[] = array( (int) $user_id, $client );
			}
		}
		return $pairs;
	}

	/**
	 * Row keys of a page, user|client.
	 *
	 * @param array<int,array<string,mixed>> $rows Page rows.
	 * @return list<string>
	 */
	private function keys( array $rows ): array {
		return array_map( static fn( array $r ): string => $r['user_id'] . '|' . $r['client_id'], array_values( $rows ) );
	}

	public function test_revoked_client_grants_are_not_listed(): void {
		$user = self::factory()->user->create();
		$this->seed_client( 'live' );
		$this->seed_client( 'gone', 0 );
		$this->seed_consent( $user, 'live' );
		$this->seed_consent( $user, 'gone' );

		$this->assertSame( array( $user . '|live' ), $this->keys( aafm_oauth_list_grants() ) );
		$page = aafm_oauth_grants_page( 1 );
		$this->assertSame( 1, $page['total'] );
		$this->assertSame( array( $user . '|live' ), $this->keys( $page['rows'] ) );
		$this->assertNull( aafm_oauth_get_grant( 'gone', $user ) );
	}

	public function test_deleted_user_grants_are_skipped(): void {
		$this->seed_grants( 55 );
		foreach ( array( 999991, 999992, 999993 ) as $missing ) {
			$this->seed_consent( $missing, 'app_a' );
		}

		$first = aafm_oauth_grants_page( 1 );
		$this->assertSame( 55, $first['total'], 'A consent whose user is gone is not counted.' );
		$this->assertCount( 50, $first['rows'], 'A full page still holds 50 rows.' );
		$this->assertSame( 2, $first['pages'] );
		$second = aafm_oauth_grants_page( 2 );
		$this->assertCount( 5, $second['rows'] );
		foreach ( array_merge( $first['rows'], $second['rows'] ) as $row ) {
			$this->assertLessThan( 999991, $row['user_id'] );
		}
	}

	public function test_total_counts_same_rows_as_page_rows(): void {
		$this->seed_grants( 23 );
		$this->seed_client( 'revoked', 0 );
		$this->seed_consent( self::factory()->user->create(), 'revoked' );
		$this->seed_consent( 999999, 'app_b' );

		$first = aafm_oauth_grants_page( 1, 10 );
		$seen  = array();
		for ( $p = 1; $p <= $first['pages']; $p++ ) {
			$seen = array_merge( $seen, $this->keys( aafm_oauth_grants_page( $p, 10 )['rows'] ) );
		}
		$this->assertSame( 23, $first['total'] );
		$this->assertSame( 3, $first['pages'] );
		$this->assertCount( $first['total'], $seen );
		$this->assertCount( $first['total'], array_unique( $seen ), 'No grant appears on two pages.' );
		$this->assertSame( $this->keys( aafm_oauth_list_grants() ), $seen, 'The pages hold exactly the full listing, in the same order.' );
	}

	public function test_page_clamps_junk_input(): void {
		$this->seed_grants( 120 );

		$this->assertSame( 1, aafm_oauth_grants_page( 0 )['page'] );
		$this->assertSame( 1, aafm_oauth_grants_page( -5 )['page'] );
		$last = aafm_oauth_grants_page( 9999 );
		$this->assertSame( 3, $last['page'] );
		$this->assertSame( 3, $last['pages'] );
		$this->assertCount( 20, $last['rows'] );
	}

	public function test_501_grants_give_11_pages_last_has_one_row(): void {
		$this->seed_grants( 501 );

		$last = aafm_oauth_grants_page( 11 );
		$this->assertSame( 501, $last['total'] );
		$this->assertSame( 11, $last['pages'] );
		$this->assertSame( 11, $last['page'] );
		$this->assertCount( 1, $last['rows'] );
	}

	public function test_zero_grants_is_one_empty_page(): void {
		$page = aafm_oauth_grants_page( 4 );

		$this->assertSame(
			array(
				'rows'  => array(),
				'total' => 0,
				'page'  => 1,
				'pages' => 1,
			),
			$page
		);
		$this->assertFalse( aafm_oauth_has_active_grant() );
	}

	public function test_has_active_grant_ignores_inactive_client(): void {
		$user = self::factory()->user->create();
		$this->seed_client( 'gone', 0 );
		$this->seed_consent( $user, 'gone' );
		$this->assertFalse( aafm_oauth_has_active_grant() );

		$this->seed_client( 'live' );
		$this->seed_consent( $user, 'live' );
		$this->assertTrue( aafm_oauth_has_active_grant() );
	}

	public function test_get_grant_returns_row_from_any_page(): void {
		$pairs  = $this->seed_grants( 60 );
		$oldest = $pairs[0]; // Newest first, so the oldest grant sits on page 2.

		$this->assertNotContains( $oldest[0] . '|' . $oldest[1], $this->keys( aafm_oauth_grants_page( 1 )['rows'] ) );
		$listed = current(
			array_filter(
				aafm_oauth_list_grants(),
				static fn( array $r ): bool => $oldest[0] === $r['user_id'] && $oldest[1] === $r['client_id']
			)
		);
		$this->assertSame( $listed, aafm_oauth_get_grant( $oldest[1], $oldest[0] ), 'Same row shape as the full listing.' );
		$this->assertNull( aafm_oauth_get_grant( 'app_a', 999999 ) );
		$this->assertNull( aafm_oauth_get_grant( 'no_such_app', $oldest[0] ) );
	}
}
