<?php
/**
 * Audit-log internals not covered by LogTest: source-IP validation, status
 * normalization, the ability/status query filters, query pagination clamping,
 * the no-op update guard, and the started -> success transition driven through a
 * real ability call (end-to-end, not just a hand-written row).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Audit;

use Oversio\Tests\TestCase;

final class LogInternalsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
		oversio_clear_activity_log();
	}

	public function test_source_ip_validates_remote_addr(): void {
		// Stashing the real value to restore after the test; no sanitization needed.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$original = $_SERVER['REMOTE_ADDR'] ?? null;

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( '198.51.100.7', oversio_source_ip() );

		// A non-IP value is rejected (returns empty), never stored verbatim.
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip; DROP TABLE';
		$this->assertSame( '', oversio_source_ip() );

		if ( null === $original ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $original;
		}
	}

	public function test_unknown_status_is_normalized_to_error(): void {
		oversio_log_activity(
			array(
				'ability' => 'oversio/get-posts',
				'status'  => 'bananas',
			)
		);
		$rows = oversio_query_activity( array() );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	public function test_update_status_with_unknown_value_is_normalized_to_error(): void {
		$id = oversio_log_activity(
			array(
				'ability' => 'oversio/get-posts',
				'status'  => 'started',
			)
		);
		oversio_update_activity_status( $id, 'wat' );
		$rows = oversio_query_activity( array() );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	public function test_update_status_on_zero_row_is_a_noop(): void {
		// Guard branch: a 0 row id must not touch the table.
		oversio_update_activity_status( 0, 'success' );
		$this->assertCount( 0, oversio_query_activity( array() ) );
	}

	public function test_query_filters_by_ability(): void {
		oversio_log_activity(
			array(
				'ability' => 'oversio/get-posts',
				'status'  => 'success',
			)
		);
		oversio_log_activity(
			array(
				'ability' => 'oversio/trash-post',
				'status'  => 'success',
			)
		);

		$rows = oversio_query_activity( array( 'ability' => 'oversio/trash-post' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'oversio/trash-post', $rows[0]['ability'] );
	}

	public function test_query_combines_status_and_ability_filters(): void {
		oversio_log_activity(
			array(
				'ability' => 'oversio/get-posts',
				'status'  => 'denied',
			)
		);
		oversio_log_activity(
			array(
				'ability' => 'oversio/get-posts',
				'status'  => 'success',
			)
		);
		oversio_log_activity(
			array(
				'ability' => 'oversio/trash-post',
				'status'  => 'denied',
			)
		);

		$rows = oversio_query_activity(
			array(
				'status'  => 'denied',
				'ability' => 'oversio/get-posts',
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 'oversio/get-posts', $rows[0]['ability'] );
		$this->assertSame( 'denied', $rows[0]['status'] );
	}

	public function test_query_per_page_is_clamped_and_paginates(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			oversio_log_activity(
				array(
					'ability' => 'oversio/get-posts',
					'status'  => 'success',
				)
			);
		}
		// per_page below 1 is clamped up to 1.
		$first = oversio_query_activity( array( 'per_page' => 0 ) );
		$this->assertCount( 1, $first );

		// Page 2 at per_page 2 returns the next slice.
		$page2 = oversio_query_activity(
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertCount( 2, $page2 );
	}

	public function test_arg_keys_are_sanitized_keys_only_never_values(): void {
		oversio_log_activity(
			array(
				'ability'  => 'oversio/update-post',
				'status'   => 'success',
				'arg_keys' => array( 'Post-ID', 'title', 'secret value!' ),
			)
		);
		$rows = oversio_query_activity( array() );
		// sanitize_key lowercases and strips disallowed chars; no raw value survives.
		$this->assertSame( 'post-id,title,secretvalue', $rows[0]['arg_keys'] );
	}

	public function test_ability_called_action_fires_on_write(): void {
		$fired = 0;
		$cb    = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'oversio_ability_called', $cb );
		oversio_log_activity(
			array(
				'ability' => 'oversio/get-posts',
				'status'  => 'success',
			)
		);
		remove_action( 'oversio_ability_called', $cb );

		$this->assertSame( 1, $fired );
	}

	public function test_real_ability_call_records_started_then_success_in_one_row(): void {
		// End-to-end: a successful execute should leave exactly one row that has
		// transitioned started -> success (the in-place update contract).
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option( 'oversio_enabled_abilities', array( 'oversio/get-taxonomies' ) );
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		oversio_clear_activity_log();
		wp_get_ability( 'oversio/get-taxonomies' )->execute( array() );

		$rows = oversio_query_activity( array( 'ability' => 'oversio/get-taxonomies' ) );
		$this->assertCount( 1, $rows, 'One row per call: started is updated in place, not duplicated.' );
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( $user_id, (int) $rows[0]['principal_user_id'] );
	}
}
