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
	 * The wp_die handler filter intercept_die() added, so reset removes only that callback.
	 *
	 * @var callable|null
	 */
	private $ajax_die_filter = null;

	/**
	 * Remove the wp_die filter intercept_die() added, if any.
	 *
	 * @return void
	 */
	private function remove_die_filter(): void {
		if ( null !== $this->ajax_die_filter ) {
			remove_filter( 'wp_die_ajax_handler', $this->ajax_die_filter );
			remove_filter( 'wp_die_handler', $this->ajax_die_filter );
			$this->ajax_die_filter = null;
		}
	}

	/**
	 * Route wp_send_json through a throwing wp_die so the handler is observable in-process.
	 *
	 * @return void
	 */
	protected function intercept_die(): void {
		// A second call replaces the first filter instead of orphaning it.
		$this->remove_die_filter();
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die                   = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		$this->ajax_die_filter = static fn() => $die;
		add_filter( 'wp_die_ajax_handler', $this->ajax_die_filter );
		add_filter( 'wp_die_handler', $this->ajax_die_filter );
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
		$this->remove_die_filter();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$_POST = array();
		unset( $_REQUEST['nonce'] );
	}

	/**
	 * Run a real Settings save as an administrator, then put back every setting it wrote except the
	 * roles list. A test that runs the OAuth installer afterwards commits through its DDL, so the
	 * save's other writes would otherwise outlive the test.
	 *
	 * @param array<string,mixed> $fields POST fields.
	 * @return void
	 */
	protected function run_settings_save( array $fields ): void {
		$options = array_diff( array_unique( array_merge( aafm_config_option_names(), aafm_policy_options() ) ), array( 'aafm_oauth_connect_roles' ) );
		$before  = array();
		foreach ( $options as $option ) {
			$before[ $option ] = aafm_read_option_views( $option );
		}
		$this->post_as( 'administrator', $fields );
		$this->run_handler( 'aafm_ajax_save_settings' );
		$this->reset_ajax_harness();
		foreach ( $before as $option => $view ) {
			if ( $view['db_found'] ) {
				update_option( $option, $view['db_value'] );
			} else {
				delete_option( $option );
			}
		}
		aafm_policy_reset_request_state();
	}
}
