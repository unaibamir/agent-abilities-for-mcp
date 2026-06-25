<?php
/**
 * Registration wrapper: permission enforcement + audit logging on success/error/deny.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;

final class RegisterWrapperTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
		oversio_clear_activity_log();
		$this->ensure_categories();
	}

	/**
	 * Register the plugin categories inside a simulated categories-init action.
	 *
	 * The Abilities API only permits category registration while the
	 * 'wp_abilities_api_categories_init' action is running; oversio_register_categories()
	 * is idempotent, so this is safe to call before every test.
	 */
	private function ensure_categories(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		oversio_register_categories();
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
		$result              = oversio_register_ability_with_log( $name, $args );
		array_pop( $wp_current_filter );
		return $result;
	}

	public function test_missing_permission_callback_is_refused(): void {
		$this->setExpectedIncorrectUsage( 'oversio_register_ability_with_log' );
		$ability = $this->register(
			'oversio/no-perm',
			array(
				'label'            => 'No Perm',
				'description'      => 'Should not register.',
				'category'         => 'oversio-reads',
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
			'oversio/echo-ok',
			array(
				'label'               => 'Echo',
				'description'         => 'Returns ok.',
				'category'            => 'oversio-reads',
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

		$result = wp_get_ability( 'oversio/echo-ok' )->execute( array( 'foo' => 'bar' ) );
		$this->assertSame( array( 'ok' => true ), $result );

		$rows = oversio_query_activity( array() );
		$this->assertCount( 1, $rows ); // started row updated in place — not a second row.
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( 'oversio/echo-ok', $rows[0]['ability'] );
		$this->assertSame( 'foo', $rows[0]['arg_keys'] ); // keys, not values.
	}

	public function test_denied_call_is_logged_as_denied(): void {
		$this->acting_as( 'subscriber' );
		$this->register(
			'oversio/needs-admin',
			array(
				'label'               => 'Needs Admin',
				'description'         => 'Admin only.',
				'category'            => 'oversio-writes',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'done' => true ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);

		$ability = wp_get_ability( 'oversio/needs-admin' );
		$allowed = $ability->check_permissions( array() );
		$this->assertFalse( $allowed );

		$rows = oversio_query_activity( array( 'status' => 'denied' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'oversio/needs-admin', $rows[0]['ability'] );
	}

	/**
	 * T3-4: a permission callback returning a non-true, non-false value (null) is still a
	 * denial — the WP Abilities API admits only true — so it must be audited as denied.
	 */
	public function test_null_permission_return_is_logged_as_denied(): void {
		$this->acting_as( 'subscriber' );
		$this->register(
			'oversio/null-perm',
			array(
				'label'               => 'Null Perm',
				'description'         => 'Permission callback returns null.',
				'category'            => 'oversio-writes',
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'done' => true ),
				'permission_callback' => static fn() => null,
			)
		);

		$ability = wp_get_ability( 'oversio/null-perm' );
		$ability->check_permissions( array() );

		$rows      = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $rows, 'ability' );
		$this->assertContains( 'oversio/null-perm', $abilities, 'A null permission return must be audited as denied.' );
	}

	public function test_categories_are_registered(): void {
		$this->assertInstanceOf( \WP_Ability_Category::class, wp_get_ability_category( 'oversio-reads' ) );
		$this->assertInstanceOf( \WP_Ability_Category::class, wp_get_ability_category( 'oversio-writes' ) );
	}
}
