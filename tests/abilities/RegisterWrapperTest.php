<?php
/**
 * Registration wrapper: permission enforcement + audit logging on success/error/deny.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class RegisterWrapperTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->ensure_categories();
	}

	/**
	 * Register the plugin categories inside a simulated categories-init action.
	 *
	 * The Abilities API only permits category registration while the
	 * 'wp_abilities_api_categories_init' action is running; aafm_register_categories()
	 * is idempotent, so this is safe to call before every test.
	 */
	private function ensure_categories(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		aafm_register_categories();
		array_pop( $wp_current_filter );
	}

	/**
	 * Register an ability through the wrapper inside a simulated abilities-init action.
	 *
	 * @param string              $name Ability name.
	 * @param array<string,mixed> $args Ability args.
	 * @return mixed The wrapper return value (WP_Ability or null).
	 */
	private function register( string $name, array $args ) {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';
		$result              = aafm_register_ability_with_log( $name, $args );
		array_pop( $wp_current_filter );
		return $result;
	}

	public function test_missing_permission_callback_is_refused(): void {
		$this->setExpectedIncorrectUsage( 'aafm_register_ability_with_log' );
		$ability = $this->register(
			'aafm/no-perm',
			array(
				'label'            => 'No Perm',
				'description'      => 'Should not register.',
				'category'         => 'aafm-reads',
				'output_schema'    => array( 'type' => 'object' ),
				'execute_callback' => static fn() => array(),
				// permission_callback intentionally omitted.
			)
		);
		$this->assertNull( $ability );
	}

	public function test_successful_call_logs_before_and_after(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/echo-ok',
			array(
				'label'               => 'Echo',
				'description'         => 'Returns ok.',
				'category'            => 'aafm-reads',
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'foo' => array( 'type' => 'string' ) ),
					'additionalProperties' => false,
				),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'permission_callback' => '__return_true',
			)
		);

		$result = wp_get_ability( 'aafm/echo-ok' )->execute( array( 'foo' => 'bar' ) );
		$this->assertSame( array( 'ok' => true ), $result );

		$rows = aafm_query_activity( array() );
		$this->assertCount( 1, $rows ); // started row updated in place - not a second row.
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( 'aafm/echo-ok', $rows[0]['ability'] );
		$this->assertSame( 'foo', $rows[0]['arg_keys'] ); // keys, not values.
	}

	public function test_denied_call_is_logged_as_denied(): void {
		$this->acting_as( 'subscriber' );
		$this->register(
			'aafm/needs-admin',
			array(
				'label'               => 'Needs Admin',
				'description'         => 'Admin only.',
				'category'            => 'aafm-writes',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'done' => true ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);

		$ability = wp_get_ability( 'aafm/needs-admin' );
		$allowed = $ability->check_permissions( array() );
		$this->assertFalse( $allowed );

		$rows = aafm_query_activity( array( 'status' => 'denied' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'aafm/needs-admin', $rows[0]['ability'] );
	}

	/**
	 * T3-4: a permission callback returning a non-true, non-false value (null) is still a
	 * denial - the WP Abilities API admits only true - so it must be audited as denied.
	 */
	public function test_null_permission_return_is_logged_as_denied(): void {
		$this->acting_as( 'subscriber' );
		$this->register(
			'aafm/null-perm',
			array(
				'label'               => 'Null Perm',
				'description'         => 'Permission callback returns null.',
				'category'            => 'aafm-writes',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'done' => true ),
				'permission_callback' => static fn() => null,
			)
		);

		$ability = wp_get_ability( 'aafm/null-perm' );
		$ability->check_permissions( array() );

		$rows      = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $rows, 'ability' );
		$this->assertContains( 'aafm/null-perm', $abilities, 'A null permission return must be audited as denied.' );
	}

	/**
	 * L5: the activity log recorded argument KEYS but never how much data a list/read call
	 * actually returned. A read ability whose result carries a 'total' key (the shape every
	 * list ability in this plugin already returns - see aafm_exec_get_posts()) logs that count.
	 */
	public function test_read_call_with_total_logs_result_count(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/list-things',
			array(
				'label'               => 'List things',
				'description'         => 'Returns a list with a total.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array(
					'things' => array( 'a', 'b', 'c' ),
					'total'  => 3,
				),
				'permission_callback' => '__return_true',
			)
		);

		wp_get_ability( 'aafm/list-things' )->execute( null );

		$rows = aafm_query_activity( array() );
		$this->assertSame( 3, (int) $rows[0]['result_count'] );
	}

	/**
	 * Without an explicit 'total', the magnitude falls back to the length of the first
	 * sequential-list value in the result (the shape a simpler read ability might return).
	 */
	public function test_read_call_without_total_falls_back_to_list_length(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/list-no-total',
			array(
				'label'               => 'List, no total',
				'description'         => 'Returns a bare list.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'things' => array( 'a', 'b' ) ),
				'permission_callback' => '__return_true',
			)
		);

		wp_get_ability( 'aafm/list-no-total' )->execute( null );

		$rows = aafm_query_activity( array() );
		$this->assertSame( 2, (int) $rows[0]['result_count'] );
	}

	/**
	 * A write ability's result is never given a magnitude - only list/read calls are scoped in,
	 * so a write's return shape is never guessed at.
	 */
	public function test_write_call_never_logs_a_result_count(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/write-thing',
			array(
				'label'               => 'Write thing',
				'description'         => 'A write ability.',
				'category'            => 'aafm-writes',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'items' => array( 1, 2, 3 ) ),
				'permission_callback' => '__return_true',
			)
		);

		wp_get_ability( 'aafm/write-thing' )->execute( null );

		$rows = aafm_query_activity( array() );
		$this->assertNull( $rows[0]['result_count'] );
	}

	/**
	 * An errored call never logs a magnitude either - there is no result to measure.
	 */
	public function test_errored_read_call_never_logs_a_result_count(): void {
		$this->acting_as( 'administrator' );
		$this->register(
			'aafm/read-error',
			array(
				'label'               => 'Read error',
				'description'         => 'A read ability that errors.',
				'category'            => 'aafm-reads',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => new \WP_Error( 'boom', 'boom' ),
				'permission_callback' => '__return_true',
			)
		);

		wp_get_ability( 'aafm/read-error' )->execute( null );

		$rows = aafm_query_activity( array() );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertNull( $rows[0]['result_count'] );
	}

	public function test_categories_are_registered(): void {
		$this->assertInstanceOf( \WP_Ability_Category::class, wp_get_ability_category( 'aafm-reads' ) );
		$this->assertInstanceOf( \WP_Ability_Category::class, wp_get_ability_category( 'aafm-writes' ) );
	}
}
