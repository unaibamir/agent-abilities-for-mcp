<?php
/**
 * The site-wide purge budget: prices, clamps, the cooldown, the row that must exist, and the
 * state function. The three early refusals that need the purge abilities are in
 * SiteCacheDispatchTest.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class SitePurgeBudgetTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/budget.php';
		aafm_current_invocation_row_id( 0 );
	}

	public function tear_down(): void {
		aafm_current_invocation_row_id( 0 );
		remove_all_filters( 'aafm_site_purge_budget' );
		parent::tear_down();
	}

	/**
	 * Insert one activity row and return its id.
	 *
	 * @param string $ability Ability name.
	 * @param string $status  Row status.
	 * @param int    $age     Seconds in the past.
	 * @return int
	 */
	private function row( string $ability, string $status = 'success', int $age = 0 ): int {
		global $wpdb;
		$id = aafm_log_activity(
			array(
				'ability'  => $ability,
				'status'   => $status,
				'arg_keys' => array(),
			)
		);
		$this->assertGreaterThan( 0, $id );
		if ( $age > 0 ) {
			$wpdb->update( aafm_activity_log_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $age ) ), array( 'id' => $id ) );
		}
		return $id;
	}

	/**
	 * Open the row of "the call now running" and register it as such.
	 *
	 * @param string $ability Ability name.
	 * @return int
	 */
	private function current( string $ability ): int {
		$id = $this->row( $ability, 'started' );
		aafm_current_invocation_row_id( $id );
		return $id;
	}

	/**
	 * Override budget settings for this test.
	 *
	 * @param array<string,mixed> $values Settings to override.
	 */
	private function settings( array $values ): void {
		add_filter(
			'aafm_site_purge_budget',
			static function ( $settings ) use ( $values ) {
				return array_merge( (array) $settings, $values );
			}
		);
	}

	public function test_the_accessor_stores_and_returns_one_integer(): void {
		$this->assertSame( 0, aafm_current_invocation_row_id() );
		$this->assertSame( 41, aafm_current_invocation_row_id( 41 ) );
		$this->assertSame( 41, aafm_current_invocation_row_id() );
		$this->assertSame( 0, aafm_current_invocation_row_id( 0 ) );
		$this->assertSame( 0, aafm_current_invocation_row_id() );
	}

	public function test_the_row_id_is_set_while_a_tool_runs_and_cleared_after(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/list-plugins' ) );
		$this->acting_as( 'administrator' );

		$seen  = 0;
		$probe = static function ( $value ) use ( &$seen ) {
			$seen = max( $seen, aafm_current_invocation_row_id() );
			return $value;
		};
		add_filter( 'option_active_plugins', $probe );
		$result = wp_get_ability( 'aafm/list-plugins' )->execute( array() );
		remove_filter( 'option_active_plugins', $probe );

		$this->assertIsArray( $result );
		global $wpdb;
		$newest = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE ability = %s', aafm_activity_log_table(), 'aafm/list-plugins' ) );
		$this->assertGreaterThan( 0, $newest );
		$this->assertSame( $newest, $seen, 'The execute callback saw the id of its own activity row.' );
		$this->assertSame( 0, aafm_current_invocation_row_id(), 'Cleared when the call ended.' );
	}

	public function test_default_settings(): void {
		$this->assertSame(
			array(
				'window'        => 600,
				'limit'         => 30,
				'site_cost'     => 10,
				'site_cooldown' => 900,
				'wall_seconds'  => 10,
				'http_cap'      => 8,
				'http_timeout'  => 3,
			),
			aafm_site_purge_budget_settings()
		);
	}

	public function test_every_setting_is_clamped_to_its_floor(): void {
		$this->settings( array_fill_keys( array( 'window', 'limit', 'site_cost', 'site_cooldown', 'wall_seconds', 'http_cap', 'http_timeout' ), 0 ) );
		$this->assertSame(
			array(
				'window'        => 60,
				'limit'         => 1,
				'site_cost'     => 1,
				'site_cooldown' => 60,
				'wall_seconds'  => 1,
				'http_cap'      => 1,
				'http_timeout'  => 1,
			),
			aafm_site_purge_budget_settings()
		);
	}

	public function test_every_setting_is_clamped_to_its_ceiling(): void {
		$this->settings( array_fill_keys( array( 'window', 'limit', 'site_cost', 'site_cooldown', 'wall_seconds', 'http_cap', 'http_timeout' ), 999999 ) );
		$this->assertSame(
			array(
				'window'        => 3600,
				'limit'         => 300,
				'site_cost'     => 300,
				'site_cooldown' => 86400,
				'wall_seconds'  => 30,
				'http_cap'      => 32,
				'http_timeout'  => 10,
			),
			aafm_site_purge_budget_settings()
		);
	}

	public function test_site_cost_cannot_exceed_the_limit_and_bad_values_fall_back(): void {
		$this->settings(
			array(
				'limit'     => 5,
				'site_cost' => 10,
				'window'    => 'soon',
			)
		);
		$settings = aafm_site_purge_budget_settings();
		$this->assertSame( 5, $settings['limit'] );
		$this->assertSame( 5, $settings['site_cost'] );
		$this->assertSame( 600, $settings['window'] );

		remove_all_filters( 'aafm_site_purge_budget' );
		add_filter( 'aafm_site_purge_budget', '__return_false' );
		$this->assertSame( 30, aafm_site_purge_budget_settings()['limit'] );
	}

	public function test_a_call_without_a_row_is_refused_before_any_query(): void {
		global $wpdb;
		$before = $wpdb->num_queries;
		$result = aafm_site_purge_budget_check( 'post' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_site_purge_not_recorded', $result->get_error_code() );
		$this->assertSame( $before, $wpdb->num_queries );
	}

	public function test_post_and_url_calls_cost_one_unit_and_a_site_call_costs_site_cost(): void {
		$this->row( 'aafm/site-purge-post-cache' );
		$this->row( 'aafm/site-purge-url-cache' );
		$this->assertSame( 2, aafm_site_purge_budget_state()['used'] );

		$this->row( 'aafm/site-purge-site-cache' );
		$state = aafm_site_purge_budget_state();
		$this->assertSame( 12, $state['used'] );
		$this->assertSame( 18, $state['remaining'] );

		$this->row( 'aafm/get-posts' );
		$this->assertSame( 12, aafm_site_purge_budget_state()['used'], 'Other tools cost nothing.' );
	}

	public function test_the_current_call_is_counted_and_the_last_unit_can_be_used(): void {
		$this->settings( array( 'limit' => 3 ) );
		$this->row( 'aafm/site-purge-post-cache' );
		$this->row( 'aafm/site-purge-url-cache' );
		$this->current( 'aafm/site-purge-post-cache' );
		$this->assertTrue( aafm_site_purge_budget_check( 'post' ), 'Used equals the limit: the last unit.' );
		$this->assertSame( 0, aafm_site_purge_budget_state()['remaining'] );

		$this->current( 'aafm/site-purge-url-cache' );
		$result = aafm_site_purge_budget_check( 'url' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_site_purge_budget', $result->get_error_code() );
	}

	public function test_refused_and_old_rows_are_not_counted(): void {
		$this->row( 'aafm/site-purge-post-cache', 'error' );
		$this->row( 'aafm/site-purge-post-cache', 'denied' );
		$this->row( 'aafm/site-purge-post-cache', 'success', 601 );
		$this->row( 'aafm/site-purge-site-cache', 'error' );
		$this->assertSame( 0, aafm_site_purge_budget_state()['used'] );

		$this->row( 'aafm/site-purge-post-cache', 'started' );
		$this->row( 'aafm/site-purge-post-cache', 'success', 599 );
		$this->assertSame( 2, aafm_site_purge_budget_state()['used'] );
	}

	public function test_a_second_site_purge_inside_the_cooldown_is_refused(): void {
		$this->current( 'aafm/site-purge-site-cache' );
		$this->assertTrue( aafm_site_purge_budget_check( 'all' ), 'The current call alone is allowed.' );

		$this->row( 'aafm/site-purge-site-cache', 'success', 100 );
		$result = aafm_site_purge_budget_check( 'all' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_site_purge_cooldown', $result->get_error_code() );
		$this->assertTrue( aafm_site_purge_budget_check( 'post' ), 'The cooldown is for whole-site purges only.' );
	}

	public function test_a_site_purge_older_than_the_cooldown_does_not_block(): void {
		$this->row( 'aafm/site-purge-site-cache', 'success', 901 );
		$this->current( 'aafm/site-purge-site-cache' );
		$this->assertTrue( aafm_site_purge_budget_check( 'all' ) );
	}

	public function test_the_cooldown_reaches_past_the_budget_window(): void {
		$this->row( 'aafm/site-purge-site-cache', 'success', 700 );
		$this->current( 'aafm/site-purge-site-cache' );
		$result = aafm_site_purge_budget_check( 'all' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_site_purge_cooldown', $result->get_error_code() );
	}

	public function test_state_reports_when_a_site_purge_is_available_again(): void {
		$this->assertSame(
			array(
				'used'                    => 0,
				'remaining'               => 30,
				'site_purge_available_in' => 0,
			),
			aafm_site_purge_budget_state()
		);

		$this->row( 'aafm/site-purge-site-cache', 'success', 100 );
		$state = aafm_site_purge_budget_state();
		$this->assertSame( 10, $state['used'] );
		$this->assertGreaterThanOrEqual( 795, $state['site_purge_available_in'] );
		$this->assertLessThanOrEqual( 800, $state['site_purge_available_in'] );
	}

	public function test_an_unreadable_count_fails_closed(): void {
		global $wpdb;
		$this->current( 'aafm/site-purge-post-cache' );
		$break    = static function ( $sql ) {
			return false !== strpos( (string) $sql, 'aafm/site-purge-' ) ? 'SELECT aafm_no_such_function()' : $sql;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		$check = aafm_site_purge_budget_check( 'post' );
		$state = aafm_site_purge_budget_state();
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $suppress );

		$this->assertInstanceOf( \WP_Error::class, $check );
		$this->assertSame( 'aafm_site_budget_unreadable', $check->get_error_code() );
		$this->assertSame(
			array(
				'used'                    => -1,
				'remaining'               => 0,
				'site_purge_available_in' => 0,
			),
			$state
		);
	}
}
