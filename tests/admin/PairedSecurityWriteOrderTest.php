<?php
/**
 * Codex round 5, R5-1: the paired exposed/deny AJAX handlers in page.php write the deny
 * (restrictive) option before the exposed (permissive) one, so a second-write failure leaves the
 * site stricter than requested, never wider. Before this fix the order was reversed: an exposed
 * write that succeeded followed by a deny write that failed left a key reachable that the
 * operator asked to deny.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class PairedSecurityWriteOrderTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_actions( 'added_option' );
		remove_all_actions( 'updated_option' );
		remove_all_actions( 'deleted_option' );
		// fail_option_read() leaves its query filter in place; without removing it here, the
		// deletes below hit the same broken query it set up and drag the leftover fault into
		// every later test in this file.
		remove_all_filters( 'query' );
		unset(
			$_POST['nonce'],
			$_REQUEST['nonce'],
			$_POST['aafm_meta_keys'],
			$_POST['aafm_deny_meta_keys'],
			$_POST['aafm_exposed_user_meta_keys'],
			$_POST['aafm_denied_user_meta_keys'],
			$_POST['aafm_exposed_term_meta_keys'],
			$_POST['aafm_denied_term_meta_keys'],
			$_POST['aafm_oauth_enabled'],
			$_POST['aafm_ip_allowlist']
		);
		wp_cache_delete( 'alloptions', 'options' );
		delete_option( 'aafm_allowed_meta_keys' );
		delete_option( 'aafm_denied_meta_keys' );
		delete_option( 'aafm_exposed_user_meta_keys' );
		delete_option( 'aafm_denied_user_meta_keys' );
		delete_option( 'aafm_exposed_term_meta_keys' );
		delete_option( 'aafm_denied_term_meta_keys' );
		delete_option( 'aafm_oauth_enabled' );
		delete_option( 'aafm_oauth_dcr_enabled' );
		delete_option( 'aafm_ip_allowlist' );
		parent::tear_down();
	}

	/**
	 * Mirrors PersistentObjectCacheSwitchTest::make_option_write_unpersistable(): whatever the
	 * handler under test writes to $option, a raw query puts the row straight back to
	 * $stuck_raw_value immediately afterward, so the write can never actually persist.
	 *
	 * @param string $option          Option name.
	 * @param mixed  $stuck_raw_value Raw (already-serialized) value the row is kept at.
	 * @return void
	 */
	private function make_option_write_unpersistable( string $option, $stuck_raw_value ): void {
		$revert = static function () use ( $option, $stuck_raw_value ): void {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"REPLACE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'yes')",
					$option,
					$stuck_raw_value
				)
			);
		};
		$guard  = static function ( $changed ) use ( $option, $revert ): void {
			if ( $changed === $option ) {
				$revert();
			}
		};
		add_action( 'added_option', $guard );
		add_action( 'updated_option', $guard );
	}

	private function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}

	/**
	 * Run an AJAX handler and return its captured JSON payload. Mirrors
	 * PersistentObjectCacheSwitchTest::run_handler(), plus hiding wpdb's own error output for the
	 * duration: fail_option_read() below deliberately breaks a query to simulate a read failure,
	 * and the WP test bootstrap turns wpdb::$show_errors on, so that broken query would otherwise
	 * print an HTML error block straight into this same output buffer and corrupt the JSON body
	 * being captured here - not a defect in the handler, just this file's own fault-injection
	 * leaking into the response it is trying to read.
	 *
	 * @param callable $handler Handler function to invoke.
	 * @return array<string,mixed>
	 */
	private function run_handler( callable $handler ): array {
		global $wpdb;
		$had_errors_shown = $wpdb->hide_errors();
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$wpdb->show_errors( $had_errors_shown );
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Post-meta pair: deny an already-exposed key while its deny write cannot persist. The
	 * exposed write must still land (it is written second, unconditionally), but the key must
	 * remain reachable only if the deny write's failure does not silently leave it un-denied -
	 * here the deny write is forced to keep the OLD (empty) denied list, so the key stays
	 * accessible; the assertion that matters is that the handler reports the failure honestly and
	 * that the exposed list was never written on top of a deny that did not take.
	 */
	public function test_post_meta_writes_deny_before_exposed_and_reports_partial_failure(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_allowed_meta_keys', array() );
		update_option( 'aafm_denied_meta_keys', array() );
		// The deny write can never persist: whatever is written, the row snaps back to empty.
		$this->make_option_write_unpersistable( 'aafm_denied_meta_keys', serialize( array() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE, mirrors PersistentObjectCacheSwitchTest.

		$nonce                        = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']               = $nonce;
		$_REQUEST['nonce']            = $nonce;
		$_POST['aafm_meta_keys']      = 'secret';
		$_POST['aafm_deny_meta_keys'] = 'secret';

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_meta_keys' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must report an error: the deny write did not persist.' );
		$this->assertSame( array(), get_option( 'aafm_denied_meta_keys' ), 'Precondition check: the deny write genuinely could not persist.' );
		$this->assertSame(
			array(),
			get_option( 'aafm_allowed_meta_keys' ),
			'The exposed write must never be attempted once the restrictive (deny) write for the same request has already failed - the site must stay at its old, narrower state, not gain the newly requested exposure.'
		);
	}

	/**
	 * User-meta pair: same shape as the post-meta test above, scoped to the user selector.
	 */
	public function test_user_meta_writes_deny_before_exposed_and_reports_partial_failure(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_exposed_user_meta_keys', array() );
		update_option( 'aafm_denied_user_meta_keys', array() );
		$this->make_option_write_unpersistable( 'aafm_denied_user_meta_keys', serialize( array() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE, mirrors PersistentObjectCacheSwitchTest.

		$nonce                                = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']                       = $nonce;
		$_REQUEST['nonce']                    = $nonce;
		$_POST['aafm_exposed_user_meta_keys'] = 'secret';
		$_POST['aafm_denied_user_meta_keys']  = 'secret';

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_user_meta_keys' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		$this->assertSame( array(), get_option( 'aafm_denied_user_meta_keys' ) );
		$this->assertSame(
			array(),
			get_option( 'aafm_exposed_user_meta_keys' ),
			'The exposed user-meta write must never run once the paired deny write already failed.'
		);
	}

	/**
	 * Term-meta pair: same shape again, scoped to the taxonomy selector.
	 */
	public function test_term_meta_writes_deny_before_exposed_and_reports_partial_failure(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_exposed_term_meta_keys', array() );
		update_option( 'aafm_denied_term_meta_keys', array() );
		$this->make_option_write_unpersistable( 'aafm_denied_term_meta_keys', serialize( array() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE, mirrors PersistentObjectCacheSwitchTest.

		$nonce                                = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']                       = $nonce;
		$_REQUEST['nonce']                    = $nonce;
		$_POST['aafm_exposed_term_meta_keys'] = 'secret';
		$_POST['aafm_denied_term_meta_keys']  = 'secret';

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_term_meta_keys' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		$this->assertSame( array(), get_option( 'aafm_denied_term_meta_keys' ) );
		$this->assertSame(
			array(),
			get_option( 'aafm_exposed_term_meta_keys' ),
			'The exposed term-meta write must never run once the paired deny write already failed.'
		);
	}

	/**
	 * When the exposed (second, permissive) write is the one that fails, the deny write that ran
	 * first must have already landed - the site ends up stricter than requested (the new deny
	 * took, the new exposure did not), never wider.
	 */
	public function test_post_meta_exposed_failure_leaves_the_new_deny_in_place(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_allowed_meta_keys', array() );
		update_option( 'aafm_denied_meta_keys', array() );
		// The exposed write can never persist; the deny write is untouched and free to succeed.
		$this->make_option_write_unpersistable( 'aafm_allowed_meta_keys', serialize( array() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE, mirrors PersistentObjectCacheSwitchTest.

		$nonce                        = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']               = $nonce;
		$_REQUEST['nonce']            = $nonce;
		$_POST['aafm_meta_keys']      = 'subtitle';
		$_POST['aafm_deny_meta_keys'] = 'secret';

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_meta_keys' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must report an error: the exposed write did not persist.' );
		$this->assertSame( array( 'secret' ), get_option( 'aafm_denied_meta_keys' ), 'The new, more restrictive deny list must have landed even though the paired exposed write failed.' );
		$this->assertSame( array(), get_option( 'aafm_allowed_meta_keys' ), 'The exposed list must stay at its old (narrower) value, not the requested one.' );
	}

	/**
	 * Codex round 6, B6-1: a single request that removes a key from BOTH the deny list and the
	 * exposed list at once (the bundled UI can post both fields together). The simple round-5
	 * "deny before exposed" order is not direction-aware here: the deny write below drops
	 * 'secret' immediately, so if the exposed write (which still lists 'secret') then fails, the
	 * key would end up neither denied nor freshly un-exposed - still reachable through the old
	 * exposed list. The three-stage write must instead land the deny option at
	 * union(old deny, new deny) first, so 'secret' is never briefly undenied even though the
	 * request asked to remove it from deny.
	 */
	public function test_post_meta_mixed_direction_stage_two_failure_keeps_union_deny(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_allowed_meta_keys', array( 'secret' ) );
		update_option( 'aafm_denied_meta_keys', array( 'secret' ) );
		// The exposed write can never persist; it snaps back to the old (still 'secret') list.
		$this->make_option_write_unpersistable( 'aafm_allowed_meta_keys', serialize( array( 'secret' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE, mirrors PersistentObjectCacheSwitchTest.

		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		// Both fields drop 'secret': the request asks to remove it from deny AND from exposed.
		unset( $_POST['aafm_meta_keys'], $_POST['aafm_deny_meta_keys'] );

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_meta_keys' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must report an error: the exposed write did not persist.' );
		$this->assertSame(
			array( 'secret' ),
			get_option( 'aafm_denied_meta_keys' ),
			'The deny option must land at the union of the old and new deny lists, not the bare new (empty) one, so the key stays denied while the exposed write is still unresolved.'
		);
		$this->assertSame( array( 'secret' ), get_option( 'aafm_allowed_meta_keys' ), 'The exposed list must stay at its old value: the write failed and was never applied.' );
		$this->assertStringContainsString( 'stricter than requested', (string) ( $json['data']['message'] ?? '' ), 'The message is honest here: deny is still broader than the empty list that was requested.' );
	}

	/**
	 * Codex round 6, B6-1, the other failure point in the same mixed-direction request: the
	 * union write (stage 1) and the exposed write (stage 2) both land, but narrowing deny down
	 * from the union to the final requested (empty) list (stage 3) fails. The key stays denied
	 * (deny remains at the old, broader value), which is still at least as strict as requested,
	 * never wider.
	 */
	public function test_post_meta_mixed_direction_stage_three_failure_leaves_deny_at_union(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_allowed_meta_keys', array( 'secret' ) );
		update_option( 'aafm_denied_meta_keys', array( 'secret' ) );
		// Any write to the deny option snaps back to the old ('secret') value. Stage 1 writes the
		// union, which for this scenario equals the old value, so it certifies as a no-op success;
		// stage 3's narrower (empty) write is the one that then fails to persist.
		$this->make_option_write_unpersistable( 'aafm_denied_meta_keys', serialize( array( 'secret' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE, mirrors PersistentObjectCacheSwitchTest.

		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		unset( $_POST['aafm_meta_keys'], $_POST['aafm_deny_meta_keys'] );

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_meta_keys' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must report an error: narrowing deny to the final requested list did not persist.' );
		$this->assertSame( array(), get_option( 'aafm_allowed_meta_keys' ), 'The exposed write succeeded and must hold the newly requested (empty) value.' );
		$this->assertSame(
			array( 'secret' ),
			get_option( 'aafm_denied_meta_keys' ),
			'Deny must stay at the union, not fall through to the requested empty list, since the narrowing write never persisted.'
		);
		$this->assertStringContainsString( 'stricter than requested', (string) ( $json['data']['message'] ?? '' ), 'Exposed genuinely saved as requested; deny is the half that could not reach its final, narrower value - the message must say so honestly.' );
	}

	/**
	 * Plant a real, unrelated-to-`*` DB row while making a persistent-cache-style layer answer
	 * with a different, narrower value for the same option - the same shape UpgradeMigrationTest
	 * and PersistentObjectCacheSwitchTest use, adapted to a non-absent row.
	 *
	 * @param string            $option      Option name.
	 * @param array<int,string> $stale_value Value the cache should serve instead of the real row.
	 * @return void
	 */
	private function plant_stale_alloptions_value( string $option, array $stale_value ): void {
		$all            = wp_load_alloptions( true );
		$all[ $option ] = $stale_value;
		wp_cache_set( 'alloptions', $all, 'options' );
		$this->assertSame( $stale_value, get_option( $option, 'MISSING' ), 'Precondition: the stale cache is what get_option() sees.' );
	}

	/**
	 * Codex round 7, R7-1: the deny-all sentinel `*` must survive the three-stage union even
	 * when both requested lists are submitted empty. Before this fix, the "old deny" half of
	 * the union came from aafm_denied_meta_keys(), which strips `*` for display purposes, so
	 * stage 1 certified an EMPTY union - discarding a live deny-all - and a subsequent exposed
	 * write failure left the old exposed key reachable with nothing left denying it, wider than
	 * both the old and the requested policy.
	 */
	public function test_post_meta_wildcard_deny_survives_stage_one_when_new_lists_are_empty(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_denied_meta_keys', array( '*' ) );
		update_option( 'aafm_allowed_meta_keys', array( 'secret' ) );
		// The exposed write can never persist; it snaps back to the old ('secret') list.
		$this->make_option_write_unpersistable( 'aafm_allowed_meta_keys', serialize( array( 'secret' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE, mirrors PersistentObjectCacheSwitchTest.

		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		// Both fields submitted empty: neither list is meant to change.
		unset( $_POST['aafm_meta_keys'], $_POST['aafm_deny_meta_keys'] );

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_meta_keys' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must report an error: the exposed write did not persist.' );
		$this->assertContains(
			'*',
			(array) get_option( 'aafm_denied_meta_keys' ),
			'The deny-all sentinel must still be in effect after stage 1 - it must never be dropped just because both submitted lists were empty.'
		);
		$this->assertSame( array( 'secret' ), get_option( 'aafm_allowed_meta_keys' ), 'The exposed list must stay at its old value: the write failed and was never applied.' );
	}

	/**
	 * Codex round 7, R7-1's other origin for a wrong "old deny" snapshot: a persistent object
	 * cache still answering with a narrower value than the real database row. Before this fix,
	 * the "old deny" half of the union came from a cache-trusting get_option(), so a stale cache
	 * claiming the deny list was already empty would make stage 1 certify an empty union even
	 * though the database still explicitly denied the key - permanently erasing that denial
	 * regardless of whether the paired exposed write ever succeeds.
	 */
	public function test_post_meta_stale_cache_old_deny_does_not_narrow_the_union(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_denied_meta_keys', array( 'secret' ) );
		update_option( 'aafm_allowed_meta_keys', array() );
		// The exposed write can never persist; it snaps back to the old (empty) list.
		$this->make_option_write_unpersistable( 'aafm_allowed_meta_keys', serialize( array() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE, mirrors PersistentObjectCacheSwitchTest.
		// A stale cache layer claims the deny list is already empty, even though the real row
		// (which aafm_read_option_views() must consult instead) still holds 'secret'.
		$this->plant_stale_alloptions_value( 'aafm_denied_meta_keys', array() );

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['aafm_meta_keys'] = 'secret';
		unset( $_POST['aafm_deny_meta_keys'] );

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_meta_keys' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must report an error: the exposed write did not persist.' );
		$this->assertSame(
			array( 'secret' ),
			get_option( 'aafm_denied_meta_keys' ),
			'The union must be built from the real database row, not a stale cache value that hides the live deny entry.'
		);
	}

	/**
	 * R2-4 sibling (1.7.5 deferred, round 2): a query that itself FAILS reading the old deny row
	 * is not the same as a genuinely absent/empty row - the stale-cache test above already proves
	 * the read must consult the database, but a failed database read must not then be treated as
	 * "nothing was denied before", which would build the stage-1 union from an empty old-deny list
	 * and silently drop 'secret' the moment this request also removes it from the requested deny
	 * list. This fails if aafm_paired_meta_write_three_stage() stops checking db_error on the old
	 * deny read and falls through to treating the failed read as an empty list.
	 */
	public function test_post_meta_read_failure_on_old_deny_aborts_instead_of_narrowing(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_denied_meta_keys', array( 'secret' ) );
		update_option( 'aafm_allowed_meta_keys', array() );
		$this->fail_option_read( 'aafm_denied_meta_keys' );

		$nonce                   = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']          = $nonce;
		$_REQUEST['nonce']       = $nonce;
		$_POST['aafm_meta_keys'] = 'secret';
		unset( $_POST['aafm_deny_meta_keys'] ); // Request no longer denies 'secret'.

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_meta_keys' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must report an error: the old deny row could not be certified.' );
		$this->assertSame(
			array( 'secret' ),
			get_option( 'aafm_denied_meta_keys' ),
			"A failed read of the old deny list must not be treated as empty - that would drop 'secret' instead of refusing the write."
		);
	}

	/**
	 * Makes the direct database SELECT aafm_read_option_views() issues for $option fail (not
	 * merely read absent), by rewriting that one query to target a table that does not exist.
	 * Mirrors OauthRevokeAjaxTest::fail_query_containing(), applied to a read instead of a write.
	 *
	 * @param string $option Option name whose row-fetch query should fail.
	 * @return void
	 */
	private function fail_option_read( string $option ): void {
		add_filter(
			'query',
			static function ( string $query ) use ( $option ): string {
				return false !== strpos( $query, "option_name = '{$option}'" )
					? 'SELECT * FROM aafm_missing_table_for_test'
					: $query;
			}
		);
	}

	/**
	 * Codex round 6, B6-1's settings.php half: the IP allowlist write already runs before any
	 * OAuth-on write in aafm_ajax_save_settings(), so if the allowlist write fails, OAuth must
	 * never be turned on in the same request - even when the request explicitly asked for it.
	 */
	public function test_settings_allowlist_failure_blocks_oauth_from_turning_on(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_oauth_enabled', '0' );
		update_option( 'aafm_ip_allowlist', array( '10.0.0.1' ) );
		// The allowlist write can never persist; it snaps back to the old list.
		$this->make_option_write_unpersistable( 'aafm_ip_allowlist', serialize( array( '10.0.0.1' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE, mirrors PersistentObjectCacheSwitchTest.

		$nonce                       = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']              = $nonce;
		$_REQUEST['nonce']           = $nonce;
		$_POST['aafm_oauth_enabled'] = '1';
		$_POST['aafm_ip_allowlist']  = '10.0.0.2';

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_settings' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The save must report an error: the allowlist write did not persist.' );
		$this->assertSame( '0', get_option( 'aafm_oauth_enabled' ), 'OAuth must stay off: it must never turn on in a request whose allowlist write failed.' );
		$this->assertSame( array( '10.0.0.1' ), get_option( 'aafm_ip_allowlist' ), 'The allowlist must stay at its old value: the write failed and was never applied.' );
	}
}
