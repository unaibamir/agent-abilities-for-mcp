<?php
/**
 * Shared helpers for the change history tests.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\TestCase;

abstract class UndoTestCase extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_undo_reset_request_state();
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_note', 'api_key', 'aafm_obj' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'aafm_rethrow_ability_exceptions' );
		remove_all_filters( 'pre_option_aafm_undo_capture_off' );
		remove_all_filters( 'aafm_undo_secret_key_patterns' );
		delete_option( 'aafm_allowed_meta_keys' );
		parent::tear_down();
	}

	/**
	 * Register the given abilities as enabled, act as an administrator, and run one through the
	 * decorated execute closure the way a tools/call does.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return mixed The result.
	 */
	protected function run_tool( string $ability, array $input ) {
		if ( ! wp_has_ability( $ability ) ) {
			$this->register_enabled( array( $ability ) );
		}
		return wp_get_ability( $ability )->execute( $input );
	}

	/**
	 * Every change row, oldest first.
	 *
	 * @return list<array<string,mixed>>
	 */
	protected function change_rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', aafm_changes_table() ), ARRAY_A );
	}

	/**
	 * The fields column of a row as a list.
	 *
	 * @param array<string,mixed> $row Change row.
	 * @return list<string>
	 */
	protected function row_fields( array $row ): array {
		return (array) json_decode( (string) $row['fields'], true );
	}
}
