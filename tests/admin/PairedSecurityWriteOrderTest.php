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
		unset(
			$_POST['nonce'],
			$_REQUEST['nonce'],
			$_POST['aafm_meta_keys'],
			$_POST['aafm_deny_meta_keys'],
			$_POST['aafm_exposed_user_meta_keys'],
			$_POST['aafm_denied_user_meta_keys'],
			$_POST['aafm_exposed_term_meta_keys'],
			$_POST['aafm_denied_term_meta_keys']
		);
		wp_cache_delete( 'alloptions', 'options' );
		delete_option( 'aafm_allowed_meta_keys' );
		delete_option( 'aafm_denied_meta_keys' );
		delete_option( 'aafm_exposed_user_meta_keys' );
		delete_option( 'aafm_denied_user_meta_keys' );
		delete_option( 'aafm_exposed_term_meta_keys' );
		delete_option( 'aafm_denied_term_meta_keys' );
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
	 * PersistentObjectCacheSwitchTest::run_handler().
	 *
	 * @param callable $handler Handler function to invoke.
	 * @return array<string,mixed>
	 */
	private function run_handler( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
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
}
