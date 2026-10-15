<?php
/**
 * Shared helpers for tests that drive an admin-ajax handler in-process.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

trait AjaxHarness {

	/**
	 * Route wp_send_json through a throwing wp_die so the handler is observable in-process.
	 *
	 * @return void
	 */
	protected function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}

	/**
	 * Run an AJAX handler and return its captured JSON payload ('' output gives array()).
	 *
	 * @param callable $handler The AJAX callback to invoke.
	 * @return array<string,mixed>
	 */
	protected function run_handler( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e ); // wp_send_json* always dies; the body is already buffered.
		}
		$json = json_decode( (string) ob_get_clean(), true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Act as a user of the given role with a valid aafm_admin nonce and the given POST fields.
	 *
	 * @param string              $role   WordPress role slug.
	 * @param array<string,mixed> $fields Extra POST fields.
	 * @return int The acting user's id.
	 */
	protected function post_as( string $role, array $fields = array() ): int {
		$user_id           = $this->acting_as( $role );
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST             = array_merge( array( 'nonce' => $nonce ), $fields );
		$_REQUEST['nonce'] = $nonce;
		$this->intercept_die();
		return $user_id;
	}

	/**
	 * Undo post_as() and intercept_die(). Call from tear_down() before parent::tear_down().
	 *
	 * @return void
	 */
	protected function reset_ajax_harness(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$_POST = array();
		unset( $_REQUEST['nonce'] );
	}
}
