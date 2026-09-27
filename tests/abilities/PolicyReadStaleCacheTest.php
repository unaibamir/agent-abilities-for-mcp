<?php
/**
 * Policy reads compare the cache copy get_option() answered from with the batched row (step 14,
 * W-3; ledger s14hunta-2, s14hunta-3, s14w1-code-1).
 *
 * Every case runs as an MCP REST request, so policy reads take the batched path; row g sets a
 * front-end page path, which keeps 1.7.5's answer.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

final class PolicyReadStaleCacheTest extends TestCase {

	/**
	 * A row value meaning "no row".
	 */
	private const ABSENT = '__aafm_absent__';

	/**
	 * A row value meaning "a serialized stdClass".
	 */
	private const OBJECT = '__aafm_object__';

	/**
	 * The batched policy read, and nothing else.
	 */
	private const BATCH_NEEDLE = array( 'SELECT option_name, option_value', "option_name IN ('aafm_enabled_abilities'" );

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REQUEST_URI'] = self::mcp_rest_path();
		aafm_policy_reset_request_state();
		aafm_install_oauth_tables();
		QueryFaultInjector::reset_fired_count();
	}

	/**
	 * Every W-3 site: its option, how to read its answer, the restrictive row, the permissive
	 * cache copy, and the literal answer for each row of the fault-state table (design P3-1.4).
	 *
	 * Rows: a healthy row R with an agreeing cache; b a stale runtime alloptions copy P over R;
	 * c (P19) a stale copy narrower than R; d a notoptions entry over R; e the batched read fails
	 * over a healthy row P; f a serialized stdClass row; g row b's state on a front-end page;
	 * h (P9, P11) an option filter answering the exact default over R.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function sites(): array {
		$refuse_all = array( 'aafm-allowlist-read-failed' );
		return array(
			'P1'    => array(
				'option' => 'aafm_enabled_abilities',
				'get'    => static fn() => aafm_get_enabled_abilities(),
				'R'      => array( 'aafm/get-post' ),
				'P'      => array( 'aafm/get-post', 'aafm/get-posts' ),
				'expect' => array(
					'a' => array( 'aafm/get-post' ),
					'b' => array( 'aafm/get-post' ),
					'd' => array(),
					'e' => array(),
					'f' => array(),
					'g' => array( 'aafm/get-post', 'aafm/get-posts' ),
				),
			),
			'P2'    => array(
				'option' => 'aafm_enabled_bridged_abilities',
				'get'    => static fn() => aafm_get_stored_bridged_abilities_raw(),
				'R'      => array( 'demo/one' ),
				'P'      => array( 'demo/one', 'demo/two' ),
				'expect' => array(
					'a' => array( 'demo/one' ),
					'b' => array( 'demo/one' ),
					'd' => array(),
					'e' => array(),
					'f' => array(),
					'g' => array( 'demo/one', 'demo/two' ),
				),
			),
			'P3'    => array(
				'option' => 'aafm_high_risk_abilities_unlocked',
				'get'    => static fn() => aafm_high_risk_unlocked(),
				'R'      => '0',
				'P'      => '1',
				'expect' => array(
					'a' => false,
					'b' => false,
					'd' => false,
					'e' => false,
					'f' => false,
					'g' => true,
				),
			),
			'P4'    => array(
				'option' => 'aafm_oauth_enabled',
				'get'    => static fn() => aafm_oauth_enabled(),
				'R'      => '0',
				'P'      => '1',
				'expect' => array(
					'a' => false,
					'b' => false,
					'd' => false,
					'e' => false,
					'f' => false,
					'g' => true,
				),
			),
			'P4dcr' => array(
				'option' => 'aafm_oauth_dcr_enabled',
				'get'    => static fn() => aafm_oauth_dcr_enabled(),
				'R'      => '0',
				'P'      => '1',
				'expect' => array(
					'a' => false,
					'b' => false,
					'd' => false,
					'e' => false,
					'f' => false,
					'g' => false,
				),
			),
			'P5'    => array(
				'option' => 'aafm_allowed_post_types',
				'get'    => static fn() => aafm_allowed_post_types(),
				'prep'   => static function (): void {
					register_post_type( 'aafm_prc_one', array( 'public' => true ) );
					register_post_type( 'aafm_prc_two', array( 'public' => true ) );
				},
				'R'      => array( 'aafm_prc_one' ),
				'P'      => array( 'aafm_prc_one', 'aafm_prc_two' ),
				'expect' => array(
					'a' => array( 'post', 'page', 'aafm_prc_one' ),
					'b' => array( 'post', 'page', 'aafm_prc_one' ),
					'd' => array( 'post', 'page' ),
					'e' => array( 'post', 'page' ),
					'f' => array( 'post', 'page' ),
					'g' => array( 'post', 'page', 'aafm_prc_one', 'aafm_prc_two' ),
				),
			),
			'P6'    => array(
				'option' => 'aafm_allowed_meta_keys',
				'get'    => static fn() => aafm_allowed_meta_keys(),
				'R'      => array( 'note_one' ),
				'P'      => array( 'note_one', 'note_two' ),
				'expect' => array(
					'a' => array( 'note_one' ),
					'b' => array( 'note_one' ),
					'd' => array(),
					'e' => array(),
					'f' => array(),
					'g' => array( 'note_one', 'note_two' ),
				),
			),
			'P7'    => array(
				'option' => 'aafm_denied_meta_keys',
				'get'    => static fn() => array( aafm_denied_meta_keys(), aafm_meta_deny_has_star() ),
				'R'      => array( 'note_deny', 'note_other' ),
				'P'      => array( 'note_other' ),
				'expect' => array(
					'a' => array( array( 'note_deny', 'note_other' ), false ),
					'b' => array( array( 'note_other', 'note_deny' ), false ),
					'd' => array( array( 'note_deny', 'note_other' ), false ),
					'e' => array( array( '*' ), true ),
					'f' => array( array(), true ),
					'g' => array( array( 'note_other' ), false ),
				),
			),
			'P8'    => array(
				'option' => 'aafm_allowed_meta_keys',
				'get'    => static fn() => aafm_meta_allow_has_star(),
				'R'      => array( 'note_one' ),
				'P'      => array( '*' ),
				'expect' => array(
					'a' => false,
					'b' => false,
					'd' => false,
					'e' => false,
					'f' => false,
					'g' => true,
				),
			),
			'P9'    => array(
				'option' => 'aafm_read_only_mode',
				'get'    => static fn() => aafm_read_only_mode(),
				'R'      => '1',
				'P'      => '',
				'h'      => false,
				'expect' => array(
					'a' => true,
					'b' => true,
					'd' => true,
					'e' => true,
					'f' => true,
					'g' => false,
					'h' => true,
				),
			),
			'P10'   => array(
				'option' => 'aafm_block_guard_strict',
				'get'    => static fn() => aafm_block_guard_is_strict(),
				'R'      => '1',
				'P'      => '',
				'expect' => array(
					'a' => true,
					'b' => true,
					'd' => true,
					'e' => true,
					'f' => true,
					'g' => false,
				),
			),
			'P11'   => array(
				'option' => 'aafm_rate_limit_per_min',
				'get'    => static fn() => aafm_rate_limit_per_min(),
				'R'      => '5',
				'P'      => '0',
				'h'      => 0,
				'expect' => array(
					'a' => 5,
					'b' => 5,
					'd' => 5,
					'e' => 1,
					'f' => 1,
					'g' => 0,
					'h' => 5,
				),
			),
			'P12'   => array(
				'option' => 'aafm_ip_allowlist',
				'get'    => static fn() => aafm_ip_allowlist(),
				'R'      => array( '10.0.0.1' ),
				'P'      => array( '10.0.0.2' ),
				'expect' => array(
					'a' => array( '10.0.0.1' ),
					'b' => $refuse_all,
					'd' => $refuse_all,
					'e' => $refuse_all,
					'f' => $refuse_all,
					'g' => array( '10.0.0.2' ),
				),
			),
			'P13'   => array(
				'option' => 'aafm_force_draft',
				'get'    => static fn() => aafm_force_draft(),
				'R'      => '1',
				'P'      => '',
				'expect' => array(
					'a' => true,
					'b' => true,
					'd' => true,
					'e' => true,
					'f' => true,
					'g' => false,
				),
			),
			'P14'   => array(
				'option' => 'aafm_max_title_len',
				'get'    => static fn() => aafm_max_title_len(),
				'R'      => '50',
				'P'      => '0',
				'expect' => array(
					'a' => 50,
					'b' => 50,
					'd' => 50,
					'e' => 1,
					'f' => 1,
					'g' => 0,
				),
			),
			'P15'   => array(
				'option' => 'aafm_log_retention_days',
				'get'    => static fn() => aafm_log_retention_days(),
				'R'      => '90',
				'P'      => '10',
				'expect' => array(
					'a' => 90,
					'b' => 90,
					'd' => 90,
					'e' => 0,
					'f' => 0,
					'g' => 10,
				),
			),
			'P16'   => array(
				'option' => 'aafm_oauth_access_ttl',
				'get'    => fn() => $this->minted_access_lifetime(),
				// A stored refresh lifetime, so its own O5 re-read never decides the row.
				'prep'   => static function (): void {
					update_option( 'aafm_oauth_refresh_ttl', 86400 );
				},
				'R'      => '60',
				'P'      => '7200',
				'expect' => array(
					'a' => 60,
					'b' => 60,
					'd' => 60,
					'e' => 'error',
					'f' => 0,
					'g' => 7200,
				),
			),
			'P17'   => array(
				'option' => 'default_role',
				'get'    => fn() => $this->created_role(),
				'R'      => 'author',
				'P'      => 'editor',
				'expect' => array(
					'a' => 'author',
					'b' => 'refused',
					'd' => 'refused',
					'e' => 'refused',
					'f' => 'refused',
					'g' => 'editor',
				),
			),
			'P18'   => array(
				'option' => 'aafm_ability_allowlist_overrides',
				'get'    => fn() => $this->author_may_delete_posts(),
				'R'      => array(
					array(
						'scope_type'        => 'role',
						'scope_id'          => 'author',
						'allowed_abilities' => array( 'aafm/get-posts' ),
					),
				),
				'P'      => array(),
				'expect' => array(
					'a' => false,
					'b' => false,
					'd' => false,
					'e' => false,
					'f' => false,
					'g' => false,
				),
			),
			'P19'   => array(
				'option' => 'aafm_enabled_abilities',
				'get'    => static fn() => aafm_get_stored_enabled_abilities_raw(),
				'R'      => array( 'aafm/get-post', 'aafm/get-posts' ),
				'P'      => array( 'aafm/get-post', 'aafm/get-posts', 'aafm/delete-post' ),
				'c'      => array( 'aafm/get-post' ),
				'expect' => array(
					'a' => array( 'aafm/get-post', 'aafm/get-posts' ),
					'b' => array( 'aafm/get-post', 'aafm/get-posts' ),
					'c' => array( 'aafm/get-post', 'aafm/get-posts' ),
					'd' => array( 'aafm/get-post', 'aafm/get-posts' ),
					'e' => array( false, true ),
					'f' => array(),
					'g' => array( 'aafm/get-post', 'aafm/get-posts', 'aafm/delete-post' ),
				),
			),
			'P20'   => array(
				'option' => 'aafm_high_risk_abilities_unlocked',
				'get'    => fn() => $this->rendered_switch( 'aafm_high_risk_abilities_unlocked' ),
				'R'      => '0',
				'P'      => '1',
				'expect' => array(
					'a' => false,
					'b' => false,
					'd' => false,
					'e' => false,
					'f' => false,
					'g' => true,
				),
			),
			'P21'   => array(
				'option' => 'aafm_delete_data_on_uninstall',
				'get'    => fn() => $this->rendered_switch( 'aafm_delete_data_on_uninstall' ),
				'R'      => '0',
				'P'      => '1',
				'expect' => array(
					'a' => false,
					'b' => false,
					'd' => false,
					'e' => false,
					'f' => false,
					'g' => true,
				),
			),
		);
	}

	/**
	 * PR-T1 keys: P<n>-<row>.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function site_row_provider(): array {
		$rows  = array(
			'P1'    => 'abdefg',
			'P2'    => 'abdefg',
			'P3'    => 'abdefg',
			'P4'    => 'abdefg',
			'P4dcr' => 'abdefg',
			'P5'    => 'abdefg',
			'P6'    => 'abdefg',
			'P7'    => 'abdefg',
			'P8'    => 'abdefg',
			'P9'    => 'abdefgh',
			'P10'   => 'abdefg',
			'P11'   => 'abdefgh',
			'P12'   => 'abdefg',
			'P13'   => 'abdefg',
			'P14'   => 'abdefg',
			'P15'   => 'abdefg',
			'P16'   => 'abdefg',
			'P17'   => 'abdefg',
			'P18'   => 'abdefg',
			'P19'   => 'abcdefg',
			'P20'   => 'abdefg',
			'P21'   => 'abdefg',
		);
		$cases = array();
		foreach ( $rows as $site => $letters ) {
			foreach ( str_split( $letters ) as $row ) {
				$cases[ "{$site}-{$row}" ] = array( $site, $row );
			}
		}
		return $cases;
	}

	/**
	 * PR-T1: each site's answer in each fault state.
	 *
	 * @dataProvider site_row_provider
	 *
	 * @param string $site The W-3 site id.
	 * @param string $row  The fault-state table row.
	 */
	public function test_each_policy_site_answers_its_row( string $site, string $row ): void {
		$spec   = $this->sites()[ $site ];
		$option = $spec['option'];
		if ( isset( $spec['prep'] ) ) {
			$spec['prep']();
		}

		switch ( $row ) {
			case 'a':
				$this->plant( $option, $spec['R'] );
				break;
			case 'b':
			case 'g':
				$this->plant( $option, $spec['R'], array( 'alloptions' => $spec['P'] ) );
				break;
			case 'c':
				$this->plant( $option, $spec['R'], array( 'alloptions' => $spec['c'] ) );
				break;
			case 'd':
				$this->plant( $option, $spec['R'], 'notoptions' );
				break;
			case 'e':
				$this->plant( $option, $spec['P'] );
				break;
			case 'f':
				$this->plant( $option, self::OBJECT );
				break;
			case 'h':
				$this->plant( $option, $spec['R'] );
				$hide = $spec['h'];
				add_filter( 'option_' . $option, static fn() => $hide );
				break;
		}
		if ( 'g' === $row ) {
			$this->on_a_front_end_page();
		}

		if ( 'e' === $row ) {
			$get = 'P19' === $site ? fn() => $this->save_enabled_abilities( $option ) : $spec['get'];
			$got = QueryFaultInjector::break_query_with_real_error( self::BATCH_NEEDLE, $get );
			$this->assertGreaterThan( 0, QueryFaultInjector::fired_count(), 'The batched read must have faulted.' );
		} else {
			$got = $spec['get']();
		}

		$this->assertSame( $spec['expect'][ $row ], $got );
	}

	/**
	 * PR-T2 (cost pin): on an MCP request, a second pass over every policy read runs one batched
	 * query and no per-option SELECT.
	 */
	public function test_a_rest_request_reads_policy_in_one_query(): void {
		$this->read_every_policy_option();
		aafm_policy_reset_request_state();
		$this->assertSame( array( 1, 0 ), $this->count_policy_queries( fn() => $this->read_every_policy_option() ) );
	}

	/**
	 * PR-T2 (front end): no batched query, and one per-option SELECT for each O5 option at its
	 * default plus the allowlist row read, as in 1.7.5.
	 */
	public function test_a_front_end_request_reads_policy_as_before(): void {
		$this->on_a_front_end_page();
		$this->read_every_policy_option();
		aafm_policy_reset_request_state();
		$this->assertSame( array( 0, 13 ), $this->count_policy_queries( fn() => $this->read_every_policy_option() ) );
	}

	/**
	 * PR-T3 (memo, row i): a helper write and a read in the same request agree, both ways. The
	 * high-risk unlock is the case a kept memo row would break: the row read before the save says
	 * locked, and the unlock needs the row to agree.
	 */
	public function test_a_policy_write_then_read_in_one_request_reads_the_new_row(): void {
		$this->assertFalse( aafm_read_only_mode() );
		$this->assertTrue( aafm_persist_operator_switch( 'aafm_read_only_mode', true ) );
		$this->assertTrue( aafm_read_only_mode() );
		$this->assertTrue( aafm_delete_option_cache_safe( 'aafm_read_only_mode' ) );
		$this->assertFalse( aafm_read_only_mode() );

		$this->assertFalse( aafm_high_risk_unlocked() );
		$this->assertTrue( aafm_persist_operator_switch( 'aafm_high_risk_abilities_unlocked', true ) );
		$this->assertTrue( aafm_high_risk_unlocked() );
	}

	/**
	 * PM build-3 test (a): the memo is cleared at shutdown, so a persistent worker's next request
	 * reads the policy rows again.
	 */
	public function test_a_shutdown_forgets_the_policy_rows(): void {
		aafm_force_draft();
		$this->assertSame( array( 0, 0 ), $this->count_policy_queries( static fn() => aafm_force_draft() ), 'A second read in the request is served from the memo.' );

		// Core's own shutdown callback flushes every output buffer, PHPUnit's included; the hooks
		// are restored after the test.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
		do_action( 'shutdown' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action, fired as core fires it.

		$this->assertSame( 1, $this->count_policy_queries( static fn() => aafm_force_draft() )[0], 'After shutdown the rows are read again.' );
	}

	/**
	 * PM build-5 (s14a3-4): a request that never batches keeps no memo and registers no hook.
	 */
	public function test_a_front_end_request_keeps_no_memo_and_registers_no_hook(): void {
		global $aafm_policy_state;
		$this->on_a_front_end_page();
		aafm_force_draft();
		aafm_read_only_mode();

		$this->assertArrayNotHasKey( 'rows', (array) $aafm_policy_state );
		$this->assertFalse( has_action( 'shutdown', 'aafm_policy_reset_request_state' ) );
		$this->assertFalse( has_action( 'updated_option', 'aafm_policy_forget_row' ) );
	}

	/**
	 * PM build-5 (s14a3-4): an option updated after the batch is read again, alone.
	 */
	public function test_an_option_updated_after_the_batch_is_read_again(): void {
		$this->assertFalse( aafm_force_draft() );
		update_option( 'aafm_force_draft', '1' );

		$single = 0;
		$filter = static function ( $query ) use ( &$single ) {
			if ( false !== strpos( (string) $query, 'SELECT option_value FROM' ) && false !== strpos( (string) $query, "option_name = 'aafm_force_draft'" ) ) {
				++$single;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		$on = aafm_force_draft();
		remove_filter( 'query', $filter );

		$this->assertTrue( $on );
		$this->assertSame( 1, $single, 'The dropped row is read again on its own.' );
	}

	/**
	 * PR-T4 (P18): a found override row that is not a list denies; an absent row allows.
	 */
	public function test_an_override_row_that_is_not_a_list_denies(): void {
		$this->plant( 'aafm_ability_allowlist_overrides', 'not-a-list' );
		$this->assertFalse( aafm_ability_allowed_for_principal( 'aafm/get-post', 0, null ) );

		$this->plant( 'aafm_ability_allowlist_overrides', self::ABSENT );
		aafm_policy_reset_request_state();
		$this->assertTrue( aafm_ability_allowed_for_principal( 'aafm/get-post', 0, null ) );
	}

	/**
	 * PR-T5 (hunt-a probes, ported): a stale cached unlock over a locked row.
	 */
	public function test_hunt_a_stale_high_risk_unlock_does_not_open_the_floor(): void {
		$this->plant( 'aafm_high_risk_abilities_unlocked', '0', array( 'alloptions' => '1' ) );
		$this->assertFalse( aafm_high_risk_unlocked() );
	}

	/**
	 * PR-T5: a stale cached enabled list and deny list over their rows.
	 */
	public function test_hunt_a_stale_enabled_and_deny_copies_do_not_widen_policy(): void {
		$this->plant( 'aafm_enabled_abilities', array(), array( 'single' => array( 'aafm/get-post' ) ) );
		$this->plant( 'aafm_denied_meta_keys', array( 'secret_key' ), array( 'single' => array( 'other_key' ) ) );

		$this->assertFalse( aafm_is_ability_enabled( 'aafm/get-post' ) );
		$this->assertContains( 'secret_key', aafm_scoped_deny_option_raw( 'aafm_denied_meta_keys' ) );
	}

	/**
	 * PR-T5: a cached string '0' over a stored read-only '1'.
	 */
	public function test_hunt_a_string_zero_copy_does_not_turn_read_only_off(): void {
		$this->plant( 'aafm_read_only_mode', '1', array( 'single' => '0' ) );
		$this->assertTrue( aafm_read_only_mode() );
	}

	/**
	 * PR-T5: object rows do not unlock high risk or OAuth, and do not pass the allowlist.
	 */
	public function test_hunt_a_object_rows_fail_closed(): void {
		foreach ( array( 'aafm_high_risk_abilities_unlocked', 'aafm_oauth_enabled', 'aafm_ability_allowlist_overrides' ) as $option ) {
			$this->plant( $option, self::OBJECT );
		}
		$this->assertSame(
			array( false, false, false ),
			array( aafm_high_risk_unlocked(), aafm_oauth_enabled(), aafm_ability_allowed_for_principal( 'aafm/get-post', 0, null ) )
		);
	}

	/**
	 * PR-T6: a stale cached enabled list wider than the row is not written back by the abilities
	 * save; the row after the save is the submitted set plus the row's own carry-forward.
	 */
	public function test_an_abilities_save_does_not_write_back_a_stale_wider_list(): void {
		$this->plant(
			'aafm_enabled_abilities',
			array( 'aafm/get-posts', 'aafm/zz-inactive-host' ),
			array( 'alloptions' => array( 'aafm/get-posts', 'aafm/zz-inactive-host', 'aafm/delete-post' ) )
		);

		$saved = aafm_set_enabled_abilities( array( 'aafm/get-post' ), $persisted );

		$this->assertTrue( $persisted );
		$this->assertSame( array( 'aafm/get-post' ), $saved );
		$this->assertSame( array( 'aafm/get-post' ), aafm_option_row( 'aafm_enabled_abilities' )['value'] );
	}

	/**
	 * PR-T7 (P5-1): an abilities save after a failed policy batch writes nothing and reports the
	 * write as not persisted.
	 */
	public function test_an_abilities_save_after_a_failed_policy_batch_writes_nothing(): void {
		$this->plant( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		$before = $this->row_md5( 'aafm_enabled_abilities' );

		$this->assertSame( array( false, true ), QueryFaultInjector::break_query_with_real_error( self::BATCH_NEEDLE, fn() => $this->save_enabled_abilities( 'aafm_enabled_abilities' ) ) );
		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$this->assertSame( $before, $this->row_md5( 'aafm_enabled_abilities' ) );
	}

	/**
	 * Make this request a front-end page load, which reads policy as 1.7.5 did.
	 */
	private function on_a_front_end_page(): void {
		$_SERVER['REQUEST_URI'] = '/sample-page/';
		aafm_policy_reset_request_state();
	}

	/**
	 * Run the abilities save and report whether it persisted and whether the row is unchanged.
	 *
	 * @param string $option The enabled-abilities option.
	 * @return array{0:bool,1:bool}
	 */
	private function save_enabled_abilities( string $option ): array {
		$before = $this->row_md5( $option );
		aafm_set_enabled_abilities( array( 'aafm/get-post' ), $persisted );
		return array( (bool) $persisted, $before === $this->row_md5( $option ) );
	}

	/**
	 * The md5 of an option's stored bytes, read straight from the table.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	private function row_md5( string $option ): string {
		global $wpdb;
		return md5( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $option ) ) );
	}

	/**
	 * Store an option's row and set up the cache state get_option() will answer from.
	 *
	 * The row is written with autoload off, and every cache copy is dropped. Then $cache is
	 * applied: 'agree' leaves no copy (get_option() reads the row itself), 'notoptions' marks the
	 * option absent, array( 'alloptions' => v ) or array( 'single' => v ) plant a stale copy v.
	 *
	 * @param string       $option Option name.
	 * @param mixed        $row    The row's value, self::ABSENT or self::OBJECT.
	 * @param string|array $cache  The cache state.
	 */
	private function plant( string $option, $row, $cache = 'agree' ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
		if ( self::ABSENT !== $row ) {
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $option,
					'option_value' => self::OBJECT === $row ? serialize( new \stdClass() ) : maybe_serialize( $row ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- test fixture: the malformed row shape.
					'autoload'     => 'off',
				)
			);
		}

		wp_cache_delete( $option, 'options' );
		$all = wp_load_alloptions();
		unset( $all[ $option ] );
		$not = wp_cache_get( 'notoptions', 'options' );
		$not = is_array( $not ) ? $not : array();
		unset( $not[ $option ] );

		if ( 'notoptions' === $cache ) {
			$not[ $option ] = true;
		} elseif ( is_array( $cache ) && array_key_exists( 'alloptions', $cache ) ) {
			$all[ $option ] = maybe_serialize( $cache['alloptions'] );
		} elseif ( is_array( $cache ) && array_key_exists( 'single', $cache ) ) {
			wp_cache_set( $option, maybe_serialize( $cache['single'] ), 'options' );
		}
		wp_cache_set( 'alloptions', $all, 'options' );
		wp_cache_set( 'notoptions', $not, 'options' );
		aafm_policy_reset_request_state();
	}

	/**
	 * Read every policy option through its getter.
	 */
	private function read_every_policy_option(): void {
		aafm_get_enabled_abilities();
		aafm_get_stored_bridged_abilities_raw();
		aafm_high_risk_unlocked();
		aafm_oauth_enabled();
		aafm_oauth_dcr_enabled();
		aafm_allowed_post_types();
		aafm_allowed_meta_keys();
		aafm_allowed_term_meta_keys();
		aafm_allowed_user_meta_keys();
		aafm_denied_meta_keys();
		aafm_denied_term_meta_keys();
		aafm_denied_user_meta_keys();
		aafm_meta_allow_has_star();
		aafm_read_only_mode();
		aafm_block_guard_is_strict();
		aafm_rate_limit_per_min();
		aafm_ip_allowlist();
		aafm_force_draft();
		aafm_max_title_len();
		aafm_log_retention_days();
		aafm_ability_allowed_for_principal( 'aafm/get-post', 0, null );
		aafm_get_stored_enabled_abilities_raw();
	}

	/**
	 * Count the batched policy query and the per-option policy SELECTs $callback runs.
	 *
	 * @param callable $callback Code to run.
	 * @return array{0:int,1:int} Batched queries, per-option SELECTs of a policy option.
	 */
	private function count_policy_queries( callable $callback ): array {
		$batched  = 0;
		$single   = 0;
		$policies = aafm_policy_options();
		$filter   = static function ( $query ) use ( &$batched, &$single, $policies ) {
			$query = (string) $query;
			if ( false !== strpos( $query, "option_name IN ('aafm_enabled_abilities'" ) ) {
				++$batched;
			} elseif ( false !== strpos( $query, 'SELECT option_value' ) ) {
				foreach ( $policies as $option ) {
					if ( false !== strpos( $query, "option_name = '{$option}'" ) ) {
						++$single;
					}
				}
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$callback();
		} finally {
			remove_filter( 'query', $filter );
		}
		return array( $batched, $single );
	}

	/**
	 * The access-token lifetime a mint issues, or 'error' when it issues none.
	 *
	 * @return int|string
	 */
	private function minted_access_lifetime() {
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => 1,
				'client_id'  => 'c',
				'resource'   => 'https://example.com/mcp',
			)
		);
		return $tokens instanceof WP_Error ? 'error' : (int) $tokens['expires_in'];
	}

	/**
	 * Create a user through create-user's body as an administrator, and report the role it got,
	 * or 'refused'.
	 *
	 * @return string
	 */
	private function created_role(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$login = 'pr_' . wp_generate_password( 8, false );
		$res   = aafm_exec_create_user(
			array(
				'username' => $login,
				'email'    => $login . '@example.com',
			)
		);
		if ( $res instanceof WP_Error ) {
			$this->assertFalse( username_exists( $login ), 'A refused create-user creates nobody.' );
			return 'refused';
		}
		$user = get_user_by( 'login', $login );
		return $user ? implode( ',', $user->roles ) : 'missing';
	}

	/**
	 * Whether an author may call delete-post through the per-principal allowlist.
	 *
	 * @return bool
	 */
	private function author_may_delete_posts(): bool {
		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		return aafm_ability_allowed_for_principal( 'aafm/delete-post', $user, null );
	}

	/**
	 * Whether the settings screen renders a switch checked.
	 *
	 * @param string $name The switch's input name.
	 * @return bool
	 */
	private function rendered_switch( string $name ): bool {
		ob_start();
		aafm_render_settings_tab();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, preg_match( '/<input[^>]*name="' . preg_quote( $name, '/' ) . '"[^>]*>/', $html, $m ) );
		return false !== strpos( $m[0], 'checked' );
	}
}
