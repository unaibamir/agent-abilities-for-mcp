<?php
/**
 * Seeds OAuth grants (consent rows) for the paged grants tests.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

/**
 * One copy of the grant seeder the grants page and the Connection cards tests share.
 */
trait SeedsGrants {

	/**
	 * Seed $count grants on five active clients, five grants per user.
	 *
	 * @param int    $count  Grants to seed.
	 * @param string $prefix Client id prefix, so each suite keeps its own client ids.
	 * @return list<array{0:int,1:string}> User id and client id per grant, in seed order.
	 */
	private function seed_grants( int $count, string $prefix = 'app_' ): array {
		global $wpdb;
		$clients = array();
		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $suffix ) {
			$clients[] = $prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'aafm_oauth_clients',
				array(
					'client_id'     => $prefix . $suffix,
					'client_name'   => 'App ' . $prefix . $suffix,
					'redirect_uris' => '[]',
					'is_active'     => 1,
				),
				array( '%s', '%s', '%s', '%d' )
			);
		}
		$pairs = array();
		foreach ( self::factory()->user->create_many( (int) ceil( $count / count( $clients ) ) ) as $user_id ) {
			foreach ( $clients as $client ) {
				if ( count( $pairs ) === $count ) {
					break 2;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$wpdb->prefix . 'aafm_oauth_consents',
					array(
						'wp_user_id' => (int) $user_id,
						'client_id'  => $client,
					),
					array( '%d', '%s' )
				);
				$pairs[] = array( (int) $user_id, $client );
			}
		}
		return $pairs;
	}
}
