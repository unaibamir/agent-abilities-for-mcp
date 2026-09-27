<?php
/**
 * Site-settings read/write abilities (Wave 2, Slice 4).
 *
 * The update-site-settings ability is the most dangerous write in the catalog: a careless
 * implementation could change siteurl/home/admin_email and lock out or take over a
 * site. These tests are the containment proof - the allowlist excludes every
 * takeover-class key, the closed schema plus the server-side allowlist reject any
 * smuggled key, and the integer bounds are clamped server-side so a 0 or 99 can never
 * be persisted.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

final class SiteSettingsTest extends TestCase {

	/**
	 * Enable the whole catalog and register categories + abilities, mirroring the
	 * idiom the catalog tests use (the Abilities API registry is process-wide).
	 */
	private function register_all(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		aafm_register_categories();
		array_pop( $wp_current_filter );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry() ) );
		$wp_current_filter[] = 'wp_abilities_api_init';
		aafm_register_enabled_abilities();
		array_pop( $wp_current_filter );
	}

	public function test_allowlist_excludes_takeover_class_keys(): void {
		$allow = aafm_allowed_site_settings();
		$this->assertContains( 'blogname', $allow );
		$this->assertContains( 'timezone_string', $allow );
		foreach ( array( 'siteurl', 'home', 'admin_email', 'default_role', 'users_can_register' ) as $danger ) {
			$this->assertNotContains( $danger, $allow, "$danger must never be agent-writable in v1." );
		}
	}

	public function test_allowlist_filter_can_narrow_but_never_widen_to_a_takeover_key(): void {
		// A rogue filter tries to ADD admin_email and siteurl. The post-filter array_diff
		// must re-strip them, so the dangerous keys can never be widened back in.
		$rogue = static function ( array $base ): array {
			$base[] = 'admin_email';
			$base[] = 'siteurl';
			return $base;
		};
		add_filter( 'aafm_allowed_site_settings', $rogue );
		$allow = aafm_allowed_site_settings();
		remove_filter( 'aafm_allowed_site_settings', $rogue );

		$this->assertNotContains( 'admin_email', $allow, 'A rogue filter widened the allowlist to admin_email.' );
		$this->assertNotContains( 'siteurl', $allow, 'A rogue filter widened the allowlist to siteurl.' );
	}

	/**
	 * B50: "the set can only be NARROWED" was only true for the five takeover-class keys the
	 * array_diff stripped. Any OTHER option - template, active_plugins, WPLANG - could be
	 * ADDED by a filter, and update-site-settings would then happily write it. Narrowing must
	 * mean the filtered set intersects the fixed base, so a filter can remove but never add.
	 */
	public function test_allowlist_filter_cannot_add_any_key_outside_the_base(): void {
		$rogue = static function ( array $base ): array {
			$base[] = 'template';       // Theme switch: not in the $never list, still dangerous.
			$base[] = 'active_plugins'; // Ditto.
			return $base;
		};
		add_filter( 'aafm_allowed_site_settings', $rogue );
		$allow = aafm_allowed_site_settings();
		remove_filter( 'aafm_allowed_site_settings', $rogue );

		$this->assertNotContains( 'template', $allow, 'A filter must not widen the allowlist beyond the fixed base.' );
		$this->assertNotContains( 'active_plugins', $allow, 'A filter must not widen the allowlist beyond the fixed base.' );
		$this->assertContains( 'blogname', $allow, 'base keys the filter kept still pass.' );
	}

	/**
	 * B50 companion: narrowing itself still works after the intersect.
	 */
	public function test_allowlist_filter_can_still_narrow(): void {
		$narrow = static fn( array $base ): array => array_diff( $base, array( 'posts_per_page' ) );
		add_filter( 'aafm_allowed_site_settings', $narrow );
		$allow  = aafm_allowed_site_settings();
		remove_filter( 'aafm_allowed_site_settings', $narrow );

		$this->assertNotContains( 'posts_per_page', $allow );
		$this->assertContains( 'blogname', $allow );
	}

	public function test_get_site_settings_returns_allowlisted_values_for_admin_only(): void {
		$this->register_all();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue( wp_get_ability( 'aafm/get-site-settings' )->check_permissions( array() ) );

		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/get-site-settings' )->execute( array() );
		$this->assertArrayHasKey( 'settings', $res );
		$this->assertArrayHasKey( 'blogname', $res['settings'] );
		$this->assertArrayNotHasKey( 'admin_email', $res['settings'], 'must never return admin_email.' );
	}

	public function test_update_site_settings_writes_only_allowlisted_keys(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array(
					'blogname'       => 'New Name',
					'posts_per_page' => 7,
				),
			)
		);
		$this->assertIsArray( $res );
		$this->assertSame( 'New Name', get_option( 'blogname' ) );
		$this->assertSame( 7, (int) get_option( 'posts_per_page' ) );
	}

	/**
	 * B11: re-submitting the current blogname as its human-readable form must be a no-op success,
	 * not a false rejection that also kills every co-submitted setting.
	 *
	 * Core stores blogname escaped ("Bob's Store" -> "Bob&#039;s Store"), so our unescaped sanitize
	 * of the same value differs from the stored value, yet sanitize_option() escapes it back to the
	 * stored value. The old code read that as core rejecting an invalid value and errored out,
	 * blocking the co-submitted posts_per_page too. The write must now succeed.
	 */
	public function test_update_site_settings_accepts_a_valid_no_op_on_an_escaped_name(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );

		// Establish the escaped stored form the way core does.
		update_option( 'blogname', "Bob's Store" );
		$stored = get_option( 'blogname' );
		$this->assertSame( 'Bob&#039;s Store', $stored, 'Precondition: core stores blogname escaped.' );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array(
					'blogname'       => "Bob's Store", // The human-readable form the caller would send.
					'posts_per_page' => 12,
				),
			)
		);

		$this->assertIsArray( $res, 'A valid no-op on blogname must not fail the whole write.' );
		$this->assertSame( 12, (int) get_option( 'posts_per_page' ), 'The co-submitted setting must apply.' );
		$this->assertSame( 'Bob&#039;s Store', get_option( 'blogname' ), 'The name is unchanged.' );
	}

	/**
	 * The B11 fix must not swallow the guard's real purpose: an invalid value core silently reverts
	 * to the current one still has to be an error, not a no-op success.
	 */
	public function test_update_site_settings_still_rejects_an_invalid_timezone_revert(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'timezone_string' => 'Not/AZone' ),
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			$res,
			'An invalid timezone core reverts must still be reported as an error.'
		);
	}

	public function test_update_site_settings_rejects_a_non_allowlisted_key(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$before = get_option( 'admin_email' );
		$res    = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'admin_email' => 'attacker@evil.test' ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'a non-allowlisted key must be rejected.' );
		$this->assertSame( $before, get_option( 'admin_email' ), 'admin_email must be untouched.' );
	}

	/**
	 * Headline containment proof: a takeover-class key smuggled alongside a legitimate one
	 * must reject the WHOLE call (fail-closed), and the site's real takeover settings -
	 * siteurl, home, admin_email, default_role, users_can_register - must be unchanged.
	 * A leak here is a site takeover or lockout.
	 */
	public function test_update_site_settings_contains_every_takeover_key(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );

		$before = array();
		foreach ( array( 'siteurl', 'home', 'admin_email', 'default_role', 'users_can_register' ) as $key ) {
			$before[ $key ] = get_option( $key );
		}

		// Smuggle every takeover key, paired with a legitimate one to prove the legitimate
		// write does NOT sneak the rest in past a partial apply.
		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array(
					'blogname'           => 'Owned',
					'siteurl'            => 'https://attacker.test',
					'home'               => 'https://attacker.test',
					'admin_email'        => 'attacker@evil.test',
					'default_role'       => 'administrator',
					'users_can_register' => 1,
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res, 'a smuggled takeover key must reject the whole call.' );
		foreach ( $before as $key => $value ) {
			$this->assertSame( $value, get_option( $key ), "$key must be unchanged after a smuggled write." );
		}
		// The legitimate key in the same call must NOT have been applied (fail-closed, not partial).
		$this->assertNotSame( 'Owned', get_option( 'blogname' ), 'a rejected call must not partial-apply blogname.' );
	}

	public function test_update_site_settings_requires_manage_options_and_is_destructive(): void {
		$this->register_all();
		$this->acting_as( 'editor' );
		$this->assertNotTrue( wp_get_ability( 'aafm/update-site-settings' )->check_permissions( array() ) );
		$ann = wp_get_ability( 'aafm/update-site-settings' )->get_meta_item( 'annotations' );
		$this->assertTrue( $ann['destructive'] );
	}

	public function test_update_site_settings_clamps_integer_ranges(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array(
					'posts_per_page' => 0,
					'start_of_week'  => 99,
				),
			)
		);
		$this->assertIsArray( $res );
		$this->assertSame( 1, (int) get_option( 'posts_per_page' ), 'posts_per_page=0 must floor to 1.' );
		$this->assertSame( 6, (int) get_option( 'start_of_week' ), 'start_of_week=99 must clamp to 6.' );
	}

	public function test_update_site_settings_clamps_a_negative_posts_per_page_to_one(): void {
		// absint would turn -5 into 5; the floor/cap form must clamp it to 1.
		$this->register_all();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'posts_per_page' => -5 ),
			)
		);
		$this->assertIsArray( $res );
		$this->assertSame( 1, (int) get_option( 'posts_per_page' ), 'a negative posts_per_page must floor to 1, not flip to 5.' );
	}

	/**
	 * Discovery: both abilities gate on manage_options object-independently, so they fall
	 * through to their permission_callback at list time. An admin must see both; an editor
	 * (no manage_options) must see neither.
	 */
	public function test_site_settings_are_discoverable_by_an_admin_and_hidden_from_an_editor(): void {
		$this->register_all();

		$this->acting_as( 'administrator' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-site-settings' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-site-settings' ) );

		$this->acting_as( 'editor' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/get-site-settings' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/update-site-settings' ) );
	}

	/**
	 * A non-scalar value is refused outright before any write - the agent can never store a
	 * structure, and the execute degrades on its OWN generic error, not the API safety net.
	 */
	public function test_update_site_settings_rejects_a_non_scalar_value(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$before = get_option( 'blogname' );
		$res    = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'blogname' => array( 'x', 'y' ) ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'a non-scalar value must be rejected.' );
		$this->assertSame( 'aafm_error', $res->get_error_code(), 'must degrade on our generic error, not the API exception net.' );
		$this->assertSame( $before, get_option( 'blogname' ), 'blogname must be untouched after a rejected non-scalar.' );
	}

	/**
	 * A malformed timezone_string is rejected by WordPress's own sanitize_option (which
	 * update_option fires): core silently REVERTS to the previously stored value rather
	 * than storing the bogus one. Left unchecked, the ability would report success on a
	 * write that never actually happened. It must instead compare the read-back to what
	 * was intended and error on the mismatch, leaving the prior value untouched.
	 */
	public function test_update_site_settings_errors_on_a_malformed_timezone_core_silently_reverts(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		$before = get_option( 'timezone_string' );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'timezone_string' => 'Not/AZone' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res, 'a bogus timezone that core silently reverts must be reported as an error.' );
		$this->assertNotSame( 'Not/AZone', get_option( 'timezone_string' ), 'a bogus timezone must not persist verbatim.' );
		$this->assertSame( $before, get_option( 'timezone_string' ), 'the prior timezone must be untouched.' );
	}

	/**
	 * A valid timezone_string is accepted and reported as changed - the new revert-detection
	 * must not false-positive on a legitimate write.
	 */
	public function test_update_site_settings_accepts_a_valid_timezone(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array( 'timezone_string' => 'Europe/Berlin' ),
			)
		);

		$this->assertIsArray( $res );
		$this->assertSame( 'Europe/Berlin', get_option( 'timezone_string' ) );
	}

	/**
	 * Write-outcome logging; every case attaches the observer itself.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function write_outcome_rows(): array {
		global $wpdb;
		$table = aafm_activity_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_type = %s ORDER BY id', $table, 'write_outcome' ), ARRAY_A );
	}

	public function test_update_site_settings_logs_one_written_row_per_key(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array(
					'blogname'       => 'New Name',
					'posts_per_page' => 7,
				),
			)
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertIsArray( $res );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 2, $rows, 'one row per submitted key.' );
		foreach ( $rows as $row ) {
			$this->assertSame( 'written', json_decode( (string) $row['detail'], true )['status'] );
		}
	}

	public function test_resubmitting_posts_per_page_as_the_same_int_logs_unchanged_and_the_response_is_unchanged(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		update_option( 'posts_per_page', 10 );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'posts_per_page' => 10 ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertIsArray( $res, 'the response body must stay byte-identical to 1.7.5.' );
		$this->assertSame( '10', $res['settings']['posts_per_page'], 'get_option() reports the stored form, same as 1.7.5.' );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'unchanged', json_decode( (string) $rows[0]['detail'], true )['status'] );
	}

	public function test_resubmitting_bobs_store_logs_unchanged_and_the_response_is_unchanged(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		update_option( 'blogname', "Bob's Store" );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'blogname' => "Bob's Store" ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertIsArray( $res, 'the response body must stay byte-identical to 1.7.5.' );
		$this->assertSame( 'Bob&#039;s Store', $res['settings']['blogname'] );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'unchanged', json_decode( (string) $rows[0]['detail'], true )['status'] );
	}

	public function test_a_pre_update_option_blogname_filter_that_keeps_the_old_value_logs_refused(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		update_option( 'blogname', 'Old Name' );
		add_filter(
			'pre_update_option_blogname',
			static function () {
				return 'Old Name';
			}
		);
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'blogname' => 'New Name' ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );
		remove_all_filters( 'pre_update_option_blogname' );

		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'refused', json_decode( (string) $rows[0]['detail'], true )['status'] );
	}

	public function test_a_faulted_read_after_a_false_update_option_logs_unconfirmed(): void {
		update_option( 'blogname', 'Old Name' );
		add_filter(
			'pre_update_option_blogname',
			static function () {
				return 'Old Name';
			}
		);
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		// Direct at the execute callback (not through wp_get_ability()->execute()), so the fault
		// targets only the option-cache read this case is about, not every $wpdb->options touch a
		// full permission-checked dispatch would also make.
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		QueryFaultInjector::reset_fired_count();
		QueryFaultInjector::fail_nth_query(
			array( $wpdb->options, "option_name = 'blogname'" ),
			2,
			static function () {
				return aafm_exec_update_site_settings( array( 'settings' => array( 'blogname' => 'New Name' ) ) );
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );
		$this->assertSame( 1, QueryFaultInjector::fired_count() );

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );
		remove_all_filters( 'pre_update_option_blogname' );

		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'unconfirmed', json_decode( (string) $rows[0]['detail'], true )['status'] );
	}

	/**
	 * Point an autoloaded option's cache view at $value while its row keeps what it holds, the state
	 * a stale persistent object cache leaves behind.
	 *
	 * @param string $option Autoloaded option name.
	 * @param string $value  The stale cached value.
	 */
	private function plant_stale_alloptions( string $option, string $value ): void {
		wp_cache_delete( $option, 'options' );
		$all            = wp_load_alloptions();
		$all[ $option ] = $value;
		wp_cache_set( 'alloptions', $all, 'options' );
	}

	/**
	 * The raw row of an option, read past every cache and filter.
	 *
	 * @param string $option Option name.
	 */
	private function option_row( string $option ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) );
	}

	/**
	 * A filter veto with agreeing cache and row views keeps 1.7.5's success body, the WPML shape:
	 * the write is refused by the filter, the row keeps the old name, the log says `refused`, and
	 * the response reports what the option layer answers (here a translation).
	 */
	public function test_a_filter_veto_with_agreeing_views_keeps_the_success_body(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		update_option( 'blogname', 'Old Name' );
		add_filter( 'pre_update_option_blogname', static fn() => 'Old Name' );
		add_filter( 'option_blogname', static fn() => 'Translated Name' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'blogname' => 'New Name' ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );
		remove_all_filters( 'pre_update_option_blogname' );
		remove_all_filters( 'option_blogname' );

		$this->assertSame( array( 'settings' => array( 'blogname' => 'Translated Name' ) ), $res );
		$this->assertSame( 'Old Name', $this->option_row( 'blogname' ) );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'refused', json_decode( (string) $rows[0]['detail'], true )['status'] );
	}

	/**
	 * A cache view holding the requested value while the row holds another would make
	 * update_option() skip the write and report success. The request refuses before any write,
	 * and the planted cache entry is left as it was.
	 */
	public function test_a_stale_cached_site_setting_returns_the_generic_error_and_writes_nothing(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		update_option( 'blogname', 'Old Name' );
		$this->plant_stale_alloptions( 'blogname', 'New Name' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'blogname' => 'New Name' ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertSame( 'The request could not be completed.', $res->get_error_message() );
		$this->assertSame( 'Old Name', $this->option_row( 'blogname' ) );
		$this->assertCount( 0, $this->write_outcome_rows() );
		$this->assertSame( 'New Name', wp_cache_get( 'alloptions', 'options' )['blogname'] );
	}

	/**
	 * A stale second key refuses the whole request before the first key is written.
	 */
	public function test_a_stale_second_key_refuses_the_whole_request_before_any_write(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		update_option( 'blogname', 'Old Name' );
		update_option( 'blogdescription', 'Old tagline' );
		$this->plant_stale_alloptions( 'blogdescription', 'New tagline' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array(
				'settings' => array(
					'blogname'        => 'New Name',
					'blogdescription' => 'New tagline',
				),
			)
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertSame( 'Old Name', $this->option_row( 'blogname' ) );
		$this->assertSame( 'Old tagline', $this->option_row( 'blogdescription' ) );
		$this->assertCount( 0, $this->write_outcome_rows() );
	}

	/**
	 * A row that cannot be read before the write refuses the request: nothing is written and
	 * nothing is logged. The needle names `SELECT option_value`, so core's own `SELECT autoload`
	 * inside update_option() is not the query it breaks.
	 */
	public function test_a_failed_views_read_refuses_the_request_before_any_write(): void {
		global $wpdb;
		update_option( 'blogname', 'Old Name' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		QueryFaultInjector::reset_fired_count();
		$res = QueryFaultInjector::break_query_with_real_error(
			array( $wpdb->options, 'SELECT option_value', "option_name = 'blogname'" ),
			static fn() => aafm_exec_update_site_settings( array( 'settings' => array( 'blogname' => 'New Name' ) ) ),
			1
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertSame( 'Old Name', $this->option_row( 'blogname' ) );
		$this->assertCount( 0, $this->write_outcome_rows() );
	}

	/**
	 * P-8 healthy pin: an empty tagline row and its empty cache agree, so the write lands.
	 */
	public function test_an_empty_tagline_row_agrees_with_its_cache_and_the_write_lands(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		update_option( 'blogdescription', '' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'blogdescription' => 'A tagline' ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertSame( array( 'settings' => array( 'blogdescription' => 'A tagline' ) ), $res );
		$this->assertSame( 'A tagline', $this->option_row( 'blogdescription' ) );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'written', json_decode( (string) $rows[0]['detail'], true )['status'] );
	}

	/**
	 * The failed-read twin on a key with no cache entry at all, with every read of the row failing:
	 * core's own read before the check leaves only a notoptions entry, so the check finds nothing
	 * cached to disagree with, and only the failed read itself can refuse the request.
	 */
	public function test_a_failed_views_read_of_an_uncached_key_refuses_the_request_before_any_write(): void {
		global $wpdb;
		update_option( 'blogname', 'Old Name' );
		wp_cache_delete( 'blogname', 'options' );
		$all = wp_load_alloptions();
		unset( $all['blogname'] );
		wp_cache_set( 'alloptions', $all, 'options' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		QueryFaultInjector::reset_fired_count();
		$res = QueryFaultInjector::break_query_with_real_error(
			array( $wpdb->options, 'SELECT option_value', "option_name = 'blogname'" ),
			static fn() => aafm_exec_update_site_settings( array( 'settings' => array( 'blogname' => 'New Name' ) ) ),
			0
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertSame( 2, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertSame( 'Old Name', $this->option_row( 'blogname' ) );
		$this->assertCount( 0, $this->write_outcome_rows() );
	}

	/**
	 * The per-option cache entry agrees with the row while the alloptions entry holds the
	 * requested value. get_option(), and so update_option()'s old-value compare, answers from
	 * alloptions first, so the write would be skipped and reported as done. The request refuses
	 * before any write.
	 */
	public function test_a_stale_alloptions_entry_beside_an_agreeing_per_option_entry_refuses_the_request(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		update_option( 'blogname', 'Old Name' );
		$this->plant_stale_alloptions( 'blogname', 'New Name' );
		wp_cache_set( 'blogname', 'Old Name', 'options' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'blogname' => 'New Name' ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertSame( 'Old Name', $this->option_row( 'blogname' ) );
		$this->assertCount( 0, $this->write_outcome_rows() );
	}

	/**
	 * A key with no alloptions entry and no notoptions entry is answered from its per-option cache
	 * entry, by get_option() and by update_option()'s old-value compare alike. A stale per-option
	 * entry holding the requested value refuses the request before any write.
	 */
	public function test_a_stale_per_option_entry_of_a_key_missing_from_alloptions_refuses_the_request(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		update_option( 'blogname', 'Old Name' );
		$all = wp_load_alloptions();
		unset( $all['blogname'] );
		wp_cache_set( 'alloptions', $all, 'options' );
		wp_cache_set( 'blogname', 'New Name', 'options' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'blogname' => 'New Name' ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertSame( 'Old Name', $this->option_row( 'blogname' ) );
		$this->assertCount( 0, $this->write_outcome_rows() );
	}

	/**
	 * An empty alloptions entry over a missing row: get_option() answers '', so update_option() runs
	 * an UPDATE that matches no row and the response would echo the stale ''. A cached value over no
	 * row refuses the request before any write.
	 */
	public function test_an_empty_alloptions_entry_over_a_missing_row_refuses_the_request(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		delete_option( 'blogname' );
		$this->plant_stale_alloptions( 'blogname', '' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'blogname' => 'New Name' ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertNull( $this->option_row( 'blogname' ) );
		$this->assertCount( 0, $this->write_outcome_rows() );
	}

	/**
	 * A notoptions entry over an existing row makes get_option() answer the registered default (10
	 * for posts_per_page), so a request for 10 is skipped by update_option()'s same-value check while
	 * the row keeps 5. A notoptions entry over a row refuses the request before any write.
	 */
	public function test_a_notoptions_entry_over_an_existing_row_refuses_the_request(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		update_option( 'posts_per_page', 5 );
		wp_cache_delete( 'posts_per_page', 'options' );
		$all = wp_load_alloptions();
		unset( $all['posts_per_page'] );
		wp_cache_set( 'alloptions', $all, 'options' );
		$not                   = (array) wp_cache_get( 'notoptions', 'options' );
		$not['posts_per_page'] = true;
		wp_cache_set( 'notoptions', $not, 'options' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'posts_per_page' => 10 ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_error', $res->get_error_code() );
		$this->assertSame( '5', $this->option_row( 'posts_per_page' ) );
		$this->assertCount( 0, $this->write_outcome_rows() );
	}

	/**
	 * P-8 healthy pin: with no row and nothing cached, the dry-run's own read leaves a notoptions
	 * entry, which agrees with the missing row, so the write lands as it did before the check.
	 */
	public function test_a_missing_row_with_nothing_cached_still_takes_the_write(): void {
		$this->register_all();
		$this->acting_as( 'administrator' );
		delete_option( 'blogdescription' );
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );

		$res = wp_get_ability( 'aafm/update-site-settings' )->execute(
			array( 'settings' => array( 'blogdescription' => 'A tagline' ) )
		);

		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );

		$this->assertSame( array( 'settings' => array( 'blogdescription' => 'A tagline' ) ), $res );
		$this->assertSame( 'A tagline', $this->option_row( 'blogdescription' ) );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'written', json_decode( (string) $rows[0]['detail'], true )['status'] );
	}
}
