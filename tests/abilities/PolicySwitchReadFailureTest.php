<?php
/**
 * Policy switches whose option read can fail: each one re-reads its row from the database when
 * get_option() gives the permissive default, and takes a fail-closed answer when that read fails.
 *
 * Core turns a failed per-option SELECT into a `notoptions` entry plus the default, and under a
 * persistent object cache that entry outlives the request, so a restrictive stored value would read
 * as its permissive default until the option is next written. Every row below calls a switch's
 * getter directly, so the only options SELECTs are the switch's own.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

final class PolicySwitchReadFailureTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_oauth_tables();
		QueryFaultInjector::reset_fired_count();
	}

	/**
	 * Every switch: its option, its getter, a restrictive stored value and the answer it gives, the
	 * permissive default's answer, the fail-closed answer, and a pre_option_{name} value that short-
	 * circuits core and normalises to the permissive answer.
	 *
	 * @return array<string,array{option:string,get:callable,stored:mixed,restrictive:mixed,permissive:mixed,closed:mixed,filter:mixed}>
	 */
	private function switches(): array {
		return array(
			'read-only'        => array(
				'option'      => 'aafm_read_only_mode',
				'get'         => static fn() => aafm_read_only_mode(),
				'stored'      => '1',
				'restrictive' => true,
				'permissive'  => false,
				'closed'      => true,
				'filter'      => '0',
			),
			'meta deny list'   => array(
				'option'      => 'aafm_denied_meta_keys',
				'get'         => static fn() => aafm_denied_meta_keys(),
				'stored'      => array( 'secret_key' ),
				'restrictive' => array( 'secret_key' ),
				'permissive'  => array(),
				'closed'      => array( '*' ),
				'filter'      => array(),
			),
			'meta deny star'   => array(
				'option'      => 'aafm_denied_meta_keys',
				'get'         => static fn() => aafm_meta_deny_has_star(),
				'stored'      => array( '*' ),
				'restrictive' => true,
				'permissive'  => false,
				'closed'      => true,
				'filter'      => array(),
			),
			'IP allowlist'     => array(
				'option'      => 'aafm_ip_allowlist',
				'get'         => static fn() => aafm_ip_allowlist(),
				'stored'      => array( '10.0.0.1' ),
				'restrictive' => array( '10.0.0.1' ),
				'permissive'  => array(),
				'closed'      => array( 'aafm-allowlist-read-failed' ),
				'filter'      => array(),
			),
			'rate limit'       => array(
				'option'      => 'aafm_rate_limit_per_min',
				'get'         => static fn() => aafm_rate_limit_per_min(),
				'stored'      => 5,
				'restrictive' => 5,
				'permissive'  => 0,
				'closed'      => 1,
				'filter'      => 0,
			),
			'force-draft'      => array(
				'option'      => 'aafm_force_draft',
				'get'         => static fn() => aafm_force_draft(),
				'stored'      => '1',
				'restrictive' => true,
				'permissive'  => false,
				'closed'      => true,
				'filter'      => '0',
			),
			'title cap'        => array(
				'option'      => 'aafm_max_title_len',
				'get'         => static fn() => aafm_max_title_len(),
				'stored'      => 50,
				'restrictive' => 50,
				'permissive'  => 0,
				'closed'      => 1,
				'filter'      => 0,
			),
			'strict guard'     => array(
				'option'      => 'aafm_block_guard_strict',
				'get'         => static fn() => aafm_block_guard_is_strict(),
				'stored'      => '1',
				'restrictive' => true,
				'permissive'  => false,
				'closed'      => true,
				'filter'      => '0',
			),
			'DCR'              => array(
				'option'      => 'aafm_oauth_dcr_enabled',
				'get'         => static fn() => aafm_oauth_dcr_enabled(),
				'stored'      => '0',
				'restrictive' => false,
				'permissive'  => true,
				'closed'      => false,
				'filter'      => '1',
			),
			'access lifetime'  => array(
				'option'      => 'aafm_oauth_access_ttl',
				'get'         => fn() => $this->minted_lifetimes()[0],
				'stored'      => 60,
				'restrictive' => 60,
				'permissive'  => AAFM_OAUTH_ACCESS_TTL,
				'closed'      => 'error',
				'filter'      => AAFM_OAUTH_ACCESS_TTL,
			),
			'refresh lifetime' => array(
				'option'      => 'aafm_oauth_refresh_ttl',
				'get'         => fn() => $this->minted_lifetimes()[1],
				'stored'      => 120,
				'restrictive' => 120,
				'permissive'  => AAFM_OAUTH_REFRESH_TTL,
				'closed'      => 'error',
				'filter'      => AAFM_OAUTH_REFRESH_TTL,
			),
			'log retention'    => array(
				'option'      => 'aafm_log_retention_days',
				'get'         => static fn() => aafm_log_retention_days(),
				'stored'      => 90,
				'restrictive' => 90,
				'permissive'  => 30,
				'closed'      => 0,
				'filter'      => 30,
			),
		);
	}

	/**
	 * Mint a token pair and return its access and refresh lifetimes in seconds, both read from the
	 * minted row against one clock, or 'error' twice when the mint refused.
	 *
	 * @return array{0:int|string,1:int|string}
	 */
	private function minted_lifetimes(): array {
		global $wpdb;
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => 1,
				'client_id'  => 'c',
				'resource'   => 'https://example.com/mcp',
			)
		);
		if ( $tokens instanceof WP_Error ) {
			return array( 'error', 'error' );
		}
		$row     = $wpdb->get_row( $wpdb->prepare( 'SELECT expires_at, refresh_expires_at FROM %i WHERE token_hash = %s', $wpdb->prefix . 'aafm_oauth_access_tokens', hash( 'sha256', $tokens['access_token'] ) ), ARRAY_A );
		$access  = (int) $tokens['expires_in'];
		$refresh = (int) strtotime( $row['refresh_expires_at'] . ' UTC' ) - (int) strtotime( $row['expires_at'] . ' UTC' ) + $access;
		return array( $access, $refresh );
	}

	/**
	 * Store a row directly (autoload off, so it is never in alloptions) and drop every cache view
	 * of it, so the next read sees only what the test plants.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value to store.
	 */
	private function plant_row( string $option, $value ): void {
		global $wpdb;
		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => $option,
				'option_value' => maybe_serialize( $value ),
				'autoload'     => 'off',
			)
		);
		$this->forget( $option );
	}

	/**
	 * Delete the row and every cache view of it.
	 *
	 * @param string $option Option name.
	 */
	private function remove_row( string $option ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
		$this->forget( $option );
	}

	/**
	 * Drop the per-option cache entry and the option's notoptions and alloptions entries.
	 *
	 * @param string $option Option name.
	 */
	private function forget( string $option ): void {
		wp_cache_delete( $option, 'options' );
		foreach ( array( 'notoptions', 'alloptions' ) as $key ) {
			$list = wp_cache_get( $key, 'options' );
			if ( is_array( $list ) && array_key_exists( $option, $list ) ) {
				unset( $list[ $option ] );
				wp_cache_set( $key, $list, 'options' );
			}
		}
	}

	/**
	 * Mark the option as known-absent, the sticky state a failed core read leaves behind.
	 *
	 * @param string $option Option name.
	 */
	private function plant_notoptions( string $option ): void {
		$list            = wp_cache_get( 'notoptions', 'options' );
		$list            = is_array( $list ) ? $list : array();
		$list[ $option ] = true;
		wp_cache_set( 'notoptions', $list, 'options' );
	}

	/**
	 * Run $callback and count the `SELECT option_value` statements it sends for $option.
	 *
	 * @param string   $option   Option name.
	 * @param callable $callback Code to run.
	 * @return array{0:mixed,1:int} The callback's result and the count.
	 */
	private function count_selects( string $option, callable $callback ): array {
		$count  = 0;
		$filter = static function ( $query ) use ( $option, &$count ) {
			if ( false !== strpos( (string) $query, 'SELECT option_value' ) && false !== strpos( (string) $query, "option_name = '{$option}'" ) ) {
				++$count;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$result = $callback();
		} finally {
			remove_filter( 'query', $filter );
		}
		return array( $result, $count );
	}

	/**
	 * Run $callback with the matching options query broken by a real SQL error.
	 *
	 * @param string   $option     Option name.
	 * @param callable $callback   Code to run.
	 * @param int      $occurrence 1-based match to break; 0 breaks every match.
	 * @return mixed The callback's result.
	 */
	private function faulted( string $option, callable $callback, int $occurrence ) {
		global $wpdb;
		return QueryFaultInjector::break_query_with_real_error( array( $wpdb->options, "option_name = '{$option}'" ), $callback, $occurrence );
	}

	/**
	 * Row (i), P-8 healthy: no row, core has cached the key as absent. The permissive answer, and
	 * exactly one SELECT, the re-read's; this is the cost pin.
	 */
	public function test_an_absent_row_keeps_the_permissive_answer_for_one_select(): void {
		foreach ( $this->switches() as $label => $switch ) {
			$this->remove_row( $switch['option'] );
			( $switch['get'] )();
			list( $answer, $selects ) = $this->count_selects( $switch['option'], $switch['get'] );
			$this->assertSame( $switch['permissive'], $answer, $label );
			$this->assertSame( 1, $selects, $label );
		}
	}

	/**
	 * Row (ii): the row holds a restrictive value while notoptions says the key is absent. The row
	 * decides.
	 */
	public function test_a_sticky_absent_entry_does_not_hide_a_restrictive_row(): void {
		foreach ( $this->switches() as $label => $switch ) {
			$this->plant_row( $switch['option'], $switch['stored'] );
			$this->plant_notoptions( $switch['option'] );
			$this->assertSame( $switch['restrictive'], ( $switch['get'] )(), $label );
			$this->remove_row( $switch['option'] );
		}
	}

	/**
	 * Row (iii): row (ii) with the re-read failing. Core sends no SELECT of its own (notoptions
	 * answers it), so the fault hits the re-read, and the switch takes its fail-closed answer.
	 */
	public function test_a_failed_reread_takes_the_fail_closed_answer(): void {
		foreach ( $this->switches() as $label => $switch ) {
			$this->plant_row( $switch['option'], $switch['stored'] );
			$this->plant_notoptions( $switch['option'] );
			QueryFaultInjector::reset_fired_count();
			$answer = $this->faulted( $switch['option'], $switch['get'], 1 );
			$this->assertSame( 1, QueryFaultInjector::fired_count(), $label );
			$this->assertSame( $switch['closed'], $answer, $label );
			$this->remove_row( $switch['option'] );
		}
	}

	/**
	 * Row (iv): core's own SELECT fails and the re-read succeeds. Core answers the default; the
	 * re-read finds the restrictive row.
	 */
	public function test_a_failed_core_read_is_corrected_by_the_reread(): void {
		foreach ( $this->switches() as $label => $switch ) {
			$this->plant_row( $switch['option'], $switch['stored'] );
			QueryFaultInjector::reset_fired_count();
			$answer = $this->faulted( $switch['option'], $switch['get'], 1 );
			$this->assertSame( 1, QueryFaultInjector::fired_count(), $label );
			$this->assertSame( $switch['restrictive'], $answer, $label );
			$this->remove_row( $switch['option'] );
		}
	}

	/**
	 * Row (v): core's SELECT and the re-read both fail: the fail-closed answer.
	 */
	public function test_both_reads_failing_takes_the_fail_closed_answer(): void {
		foreach ( $this->switches() as $label => $switch ) {
			$this->plant_row( $switch['option'], $switch['stored'] );
			QueryFaultInjector::reset_fired_count();
			$answer = $this->faulted( $switch['option'], $switch['get'], 0 );
			$this->assertSame( 2, QueryFaultInjector::fired_count(), $label );
			$this->assertSame( $switch['closed'], $answer, $label );
			$this->remove_row( $switch['option'] );
		}
	}

	/**
	 * Row (vi): a filter answers exactly the default get_option() was passed while the row holds a
	 * restrictive value. The row wins, following the one-directional rule of read-only mode. For most
	 * switches a pre_option_{name} filter answers it, for one SELECT (core's is short-circuited). The
	 * read-only, force-draft and strict-guard default is false, which a pre_option filter cannot
	 * answer (core reads false as no short-circuit), so an option_{name} filter answers false over the
	 * row core has just read, for two SELECTs: core's and the re-read.
	 */
	public function test_a_filter_hiding_a_restrictive_row_does_not_win(): void {
		foreach ( $this->switches() as $label => $switch ) {
			$by_option = in_array( $label, array( 'read-only', 'force-draft', 'strict guard' ), true );
			$hook      = ( $by_option ? 'option_' : 'pre_option_' ) . $switch['option'];
			$this->plant_row( $switch['option'], $switch['stored'] );
			$value                    = $by_option ? false : $switch['filter'];
			$filter                   = static fn() => $value;
			add_filter( $hook, $filter );
			list( $answer, $selects ) = $this->count_selects( $switch['option'], $switch['get'] );
			remove_filter( $hook, $filter );
			$this->assertSame( $switch['restrictive'], $answer, $label );
			$this->assertSame( $by_option ? 2 : 1, $selects, $label );
			$this->remove_row( $switch['option'] );
		}
	}

	/**
	 * One row per switch whose pre_option_{name} answer is not the default get_option() was passed but
	 * normalises to the permissive answer, over a restrictive row: 1.7.5's answer and no row read,
	 * because only a raw answer identical to that default can be a failed read's.
	 *
	 * @return array<string,array{option:string,get:callable,stored:mixed,filter:mixed,answer:mixed}>
	 */
	private function normalised_default_answers(): array {
		return array(
			'read-only'        => array(
				'option' => 'aafm_read_only_mode',
				'get'    => static fn() => aafm_read_only_mode(),
				'stored' => '1',
				'filter' => '0',
				'answer' => false,
			),
			'meta deny list'   => array(
				'option' => 'aafm_denied_meta_keys',
				'get'    => static fn() => aafm_denied_meta_keys(),
				'stored' => array( 'secret_key' ),
				'filter' => 'secret_key',
				'answer' => array(),
			),
			'meta deny star'   => array(
				'option' => 'aafm_denied_meta_keys',
				'get'    => static fn() => aafm_meta_deny_has_star(),
				'stored' => array( '*' ),
				'filter' => '*',
				'answer' => false,
			),
			'IP allowlist'     => array(
				'option' => 'aafm_ip_allowlist',
				'get'    => static fn() => aafm_ip_allowlist(),
				'stored' => array( '10.0.0.1' ),
				'filter' => array( ' ' ),
				'answer' => array(),
			),
			'rate limit'       => array(
				'option' => 'aafm_rate_limit_per_min',
				'get'    => static fn() => aafm_rate_limit_per_min(),
				'stored' => 5,
				'filter' => -1,
				'answer' => 0,
			),
			'force-draft'      => array(
				'option' => 'aafm_force_draft',
				'get'    => static fn() => aafm_force_draft(),
				'stored' => '1',
				'filter' => '0',
				'answer' => false,
			),
			'title cap'        => array(
				'option' => 'aafm_max_title_len',
				'get'    => static fn() => aafm_max_title_len(),
				'stored' => 50,
				'filter' => 'abc',
				'answer' => 0,
			),
			'strict guard'     => array(
				'option' => 'aafm_block_guard_strict',
				'get'    => static fn() => aafm_block_guard_is_strict(),
				'stored' => '1',
				'filter' => '0',
				'answer' => false,
			),
			'DCR'              => array(
				'option' => 'aafm_oauth_dcr_enabled',
				'get'    => static fn() => aafm_oauth_dcr_enabled(),
				'stored' => '0',
				'filter' => 'yes',
				'answer' => true,
			),
			'access lifetime'  => array(
				'option' => 'aafm_oauth_access_ttl',
				'get'    => fn() => $this->minted_lifetimes()[0],
				'stored' => 60,
				'filter' => (string) AAFM_OAUTH_ACCESS_TTL,
				'answer' => AAFM_OAUTH_ACCESS_TTL,
			),
			'refresh lifetime' => array(
				'option' => 'aafm_oauth_refresh_ttl',
				'get'    => fn() => $this->minted_lifetimes()[1],
				'stored' => 120,
				'filter' => (string) AAFM_OAUTH_REFRESH_TTL,
				'answer' => AAFM_OAUTH_REFRESH_TTL,
			),
			'log retention'    => array(
				'option' => 'aafm_log_retention_days',
				'get'    => static fn() => aafm_log_retention_days(),
				'stored' => 90,
				'filter' => '30',
				'answer' => 30,
			),
		);
	}

	/**
	 * Row (vi-c): a filter answer that only normalises to the default still decides, as in 1.7.5,
	 * and the row is not read.
	 */
	public function test_a_filter_answer_that_normalises_to_the_default_still_decides(): void {
		foreach ( $this->normalised_default_answers() as $label => $row ) {
			$this->plant_row( $row['option'], $row['stored'] );
			$value                    = $row['filter'];
			$filter                   = static fn() => $value;
			add_filter( 'pre_option_' . $row['option'], $filter );
			list( $answer, $selects ) = $this->count_selects( $row['option'], $row['get'] );
			remove_filter( 'pre_option_' . $row['option'], $filter );
			$this->assertSame( $row['answer'], $answer, $label );
			$this->assertSame( 0, $selects, $label );
			$this->remove_row( $row['option'] );
		}
	}

	/**
	 * Row (vi-b): a filter pins the default while the row is less restrictive. The filter's value
	 * stands, as in 1.7.5, for one SELECT: the row replaces an answer only in the restrictive
	 * direction.
	 *
	 * @param string $label  Switch label.
	 * @param int    $stored The less restrictive stored value.
	 */
	private function assert_a_less_restrictive_row_does_not_override_a_filter( string $label, int $stored ): void {
		$switch = $this->switches()[ $label ];
		$this->plant_row( $switch['option'], $stored );
		$value                    = $switch['filter'];
		$filter                   = static fn() => $value;
		add_filter( 'pre_option_' . $switch['option'], $filter );
		list( $answer, $selects ) = $this->count_selects( $switch['option'], $switch['get'] );
		remove_filter( 'pre_option_' . $switch['option'], $filter );
		$this->assertSame( $switch['permissive'], $answer, $label );
		$this->assertSame( 1, $selects, $label );
	}

	/**
	 * Row (vi-b), access lifetime: a stored lifetime twice the constant under a filter pinning the
	 * constant mints at the constant.
	 */
	public function test_a_longer_access_lifetime_row_does_not_override_a_filter(): void {
		$this->assert_a_less_restrictive_row_does_not_override_a_filter( 'access lifetime', 2 * AAFM_OAUTH_ACCESS_TTL );
	}

	/**
	 * Row (vi-b), refresh lifetime: the same for the refresh token.
	 */
	public function test_a_longer_refresh_lifetime_row_does_not_override_a_filter(): void {
		$this->assert_a_less_restrictive_row_does_not_override_a_filter( 'refresh lifetime', 2 * AAFM_OAUTH_REFRESH_TTL );
	}

	/**
	 * Row (vi-b), retention: a stored 7 days under a filter pinning 30 keeps 30.
	 */
	public function test_a_shorter_retention_row_does_not_override_a_filter(): void {
		$this->assert_a_less_restrictive_row_does_not_override_a_filter( 'log retention', 7 );
	}

	/**
	 * Row (vii): a restrictive value already cached costs no extra SELECT.
	 */
	public function test_a_cached_restrictive_value_costs_no_select(): void {
		foreach ( $this->switches() as $label => $switch ) {
			update_option( $switch['option'], $switch['stored'] );
			list( $answer, $selects ) = $this->count_selects( $switch['option'], $switch['get'] );
			$this->assertSame( $switch['restrictive'], $answer, $label );
			$this->assertSame( 0, $selects, $label );
			$this->remove_row( $switch['option'] );
		}
	}

	/**
	 * A stored empty string is a stored value: Dynamic Client Registration off. Under a sticky
	 * notoptions entry the re-read must see it.
	 */
	public function test_an_empty_dcr_row_turns_registration_off(): void {
		$this->plant_row( 'aafm_oauth_dcr_enabled', '' );
		$this->plant_notoptions( 'aafm_oauth_dcr_enabled' );
		$this->assertFalse( aafm_oauth_dcr_enabled() );
	}

	/**
	 * A stored empty retention is 0, keep forever.
	 */
	public function test_an_empty_retention_row_keeps_every_entry(): void {
		$this->plant_row( 'aafm_log_retention_days', '' );
		$this->plant_notoptions( 'aafm_log_retention_days' );
		$this->assertSame( 0, aafm_log_retention_days() );
	}

	/**
	 * A stored empty access lifetime is 0: the token expires as it is minted.
	 */
	public function test_an_empty_access_lifetime_row_mints_an_expired_token(): void {
		$this->plant_row( 'aafm_oauth_access_ttl', '' );
		$this->plant_notoptions( 'aafm_oauth_access_ttl' );
		$this->remove_row( 'aafm_oauth_refresh_ttl' );
		$this->assertSame( array( 0, AAFM_OAUTH_REFRESH_TTL ), $this->minted_lifetimes() );
	}

	/**
	 * A stored empty refresh lifetime is 0: the refresh token expires as it is minted.
	 */
	public function test_an_empty_refresh_lifetime_row_mints_an_expired_refresh_token(): void {
		$this->plant_row( 'aafm_oauth_refresh_ttl', '' );
		$this->plant_notoptions( 'aafm_oauth_refresh_ttl' );
		$this->remove_row( 'aafm_oauth_access_ttl' );
		$this->assertSame( array( AAFM_OAUTH_ACCESS_TTL, 0 ), $this->minted_lifetimes() );
	}

	/**
	 * The fail-closed deny list refuses a key even under allow-`*`.
	 */
	public function test_a_failed_deny_list_read_refuses_every_key(): void {
		update_option( 'aafm_allowed_meta_keys', array( '*' ) );
		$this->plant_row( 'aafm_denied_meta_keys', array( 'secret_key' ) );
		$this->plant_notoptions( 'aafm_denied_meta_keys' );
		$result = $this->faulted( 'aafm_denied_meta_keys', static fn() => aafm_validate_meta_key( 'any_key' ), 0 );
		$this->assertInstanceOf( WP_Error::class, $result );

		// Only the list read fails (the `*` check before it reads the row): the list's own
		// fail-closed `*` refuses.
		QueryFaultInjector::reset_fired_count();
		$result = $this->faulted( 'aafm_denied_meta_keys', static fn() => aafm_validate_meta_key( 'any_key' ), 2 );
		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * The fail-closed allowlist marker matches no IPv4 or IPv6 address, and is not a valid entry,
	 * so a settings save drops it.
	 */
	public function test_a_failed_allowlist_read_refuses_every_address(): void {
		$this->plant_row( 'aafm_ip_allowlist', array( '10.0.0.1' ) );
		$this->plant_notoptions( 'aafm_ip_allowlist' );
		$allowed = $this->faulted(
			'aafm_ip_allowlist',
			static fn() => array_map( 'aafm_ip_is_allowed', array( '127.0.0.1', '203.0.113.7', '::1', '2001:db8::1' ) ),
			0
		);
		$this->assertSame( array( false, false, false, false ), $allowed );
		$this->assertFalse( aafm_is_valid_ip_or_cidr( 'aafm-allowlist-read-failed' ) );
	}

	/**
	 * A failed lifetime read refuses the mint with the existing error, before any row is written.
	 */
	public function test_a_failed_lifetime_read_mints_nothing(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'aafm_oauth_access_tokens';
		$this->plant_row( 'aafm_oauth_access_ttl', 60 );
		$this->plant_notoptions( 'aafm_oauth_access_ttl' );
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$result = $this->faulted(
			'aafm_oauth_access_ttl',
			static fn() => aafm_oauth_mint_tokens(
				array(
					'wp_user_id' => 1,
					'client_id'  => 'c',
				)
			),
			1
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'server_error', $result->get_error_code() );
		$this->assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
	}

	/**
	 * The term and user deny getters: each scope's list and `*` read, with the same stored,
	 * restrictive and fail-closed values as the post scope's rows in switches().
	 *
	 * @return array<string,array{option:string,get:callable,stored:mixed,restrictive:mixed,closed:mixed}>
	 */
	private function scoped_deny_switches(): array {
		return array(
			'term deny list' => array(
				'option'      => 'aafm_denied_term_meta_keys',
				'get'         => static fn() => aafm_denied_term_meta_keys(),
				'stored'      => array( 'secret_key' ),
				'restrictive' => array( 'secret_key' ),
				'closed'      => array( '*' ),
			),
			'term deny star' => array(
				'option'      => 'aafm_denied_term_meta_keys',
				'get'         => static fn() => aafm_term_meta_deny_has_star(),
				'stored'      => array( '*' ),
				'restrictive' => true,
				'closed'      => true,
			),
			'user deny list' => array(
				'option'      => 'aafm_denied_user_meta_keys',
				'get'         => static fn() => aafm_denied_user_meta_keys(),
				'stored'      => array( 'secret_key' ),
				'restrictive' => array( 'secret_key' ),
				'closed'      => array( '*' ),
			),
			'user deny star' => array(
				'option'      => 'aafm_denied_user_meta_keys',
				'get'         => static fn() => aafm_user_meta_deny_has_star(),
				'stored'      => array( '*' ),
				'restrictive' => true,
				'closed'      => true,
			),
		);
	}

	/**
	 * Row (ii) for the term and user deny getters: notoptions over a restrictive row, the row
	 * decides.
	 */
	public function test_a_sticky_absent_entry_does_not_hide_a_term_or_user_deny_row(): void {
		foreach ( $this->scoped_deny_switches() as $label => $switch ) {
			$this->plant_row( $switch['option'], $switch['stored'] );
			$this->plant_notoptions( $switch['option'] );
			$this->assertSame( $switch['restrictive'], ( $switch['get'] )(), $label );
			$this->remove_row( $switch['option'] );
		}
	}

	/**
	 * Row (iii) for the term and user deny getters: the re-read fails, the fail-closed answer.
	 */
	public function test_a_failed_term_or_user_deny_reread_takes_the_fail_closed_answer(): void {
		foreach ( $this->scoped_deny_switches() as $label => $switch ) {
			$this->plant_row( $switch['option'], $switch['stored'] );
			$this->plant_notoptions( $switch['option'] );
			QueryFaultInjector::reset_fired_count();
			$answer = $this->faulted( $switch['option'], $switch['get'], 1 );
			$this->assertSame( 1, QueryFaultInjector::fired_count(), $label );
			$this->assertSame( $switch['closed'], $answer, $label );
			$this->remove_row( $switch['option'] );
		}
	}

	/**
	 * Row (v) for the term and user deny getters: core's SELECT and the re-read both fail, the
	 * fail-closed answer.
	 */
	public function test_both_term_or_user_deny_reads_failing_take_the_fail_closed_answer(): void {
		foreach ( $this->scoped_deny_switches() as $label => $switch ) {
			$this->plant_row( $switch['option'], $switch['stored'] );
			QueryFaultInjector::reset_fired_count();
			$answer = $this->faulted( $switch['option'], $switch['get'], 0 );
			$this->assertSame( 2, QueryFaultInjector::fired_count(), $label );
			$this->assertSame( $switch['closed'], $answer, $label );
			$this->remove_row( $switch['option'] );
		}
	}

	/**
	 * A site may define either lifetime constant as a string in wp-config. The mint still reads
	 * both lifetime rows when get_option() answers the constant, so a failed read or a shorter
	 * stored lifetime is never hidden behind it. A constant cannot be redefined once tokens.php has
	 * loaded, so this runs tokens.php in a child PHP process with string constants and stubs that
	 * record each row read (every row absent, and a token insert that fails).
	 */
	public function test_a_string_lifetime_constant_still_reads_the_lifetime_rows(): void {
		$tokens = dirname( __DIR__, 2 ) . '/includes/oauth/tokens.php';
		$code   = "define( 'ABSPATH', '/' );"
			. "define( 'AAFM_OAUTH_ACCESS_TTL', '3600' );"
			. "define( 'AAFM_OAUTH_REFRESH_TTL', '2592000' );"
			. 'function get_option( $option, $fallback = false ) { return $fallback; }'
			. 'function __( $text, $domain = "default" ) { return $text; }'
			. 'function aafm_option_row( $option ) { $GLOBALS["aafm_rows"][] = $option; return array( "ok" => true, "found" => false, "value" => false ); }'
			. 'class WP_Error { public $code; public function __construct( $code = "", $message = "" ) { $this->code = $code; } }'
			. '$GLOBALS["wpdb"] = new class() { public $prefix = "wp_"; public function insert() { return false; } };'
			. 'require $argv[1];'
			. '$result = aafm_oauth_mint_tokens( array() );'
			. 'echo json_encode( array( "rows" => $GLOBALS["aafm_rows"] ?? array(), "error" => $result instanceof WP_Error ? $result->code : null ) );';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- test-only: runs tokens.php in a child PHP process so its constants can be strings; never runs on a live site.
		$proc = proc_open(
			array( PHP_BINARY, '-r', $code, '--', $tokens ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $proc );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- test-only: closes the child process pipe, not a WP file operation.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- test-only: closes the child process pipe, not a WP file operation.
		$this->assertSame( 0, proc_close( $proc ), (string) $err );

		$this->assertSame(
			array(
				'rows'  => array( 'aafm_oauth_access_ttl', 'aafm_oauth_refresh_ttl' ),
				'error' => 'server_error',
			),
			json_decode( (string) $out, true )
		);
	}
}
