<?php
/**
 * CSV export of the activity log: header, batching past the page cap, the status filter, and the
 * formula-injection guard.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class LogExportTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function test_the_header_row_names_every_exported_column(): void {
		$csv = $this->capture_export();
		$this->assertStringStartsWith(
			'created_at,event_type,ability,detail,status,arg_keys,principal_login,principal_user_id,source_ip,client_id,result_count',
			$csv
		);
	}

	public function test_it_exports_every_row_past_the_two_hundred_page_cap(): void {
		for ( $i = 0; $i < 205; $i++ ) {
			aafm_log_activity(
				array(
					'ability' => 'aafm/get-posts',
					'status'  => 'success',
				)
			);
		}
		// 205 rows + the header line. Proves the exporter paginates past aafm_query_activity()'s
		// 200-row cap rather than silently truncating at it.
		$this->assertSame( 206, substr_count( $this->capture_export(), "\n" ) );
	}

	public function test_it_honours_the_status_filter(): void {
		aafm_log_activity(
			array(
				'ability' => 'a/ok',
				'status'  => 'success',
			)
		);
		aafm_log_activity(
			array(
				'ability' => 'a/no',
				'status'  => 'denied',
			)
		);

		$csv = $this->capture_export( 'denied' );
		$this->assertStringContainsString( 'a/no', $csv );
		$this->assertStringNotContainsString( 'a/ok', $csv );
	}

	/**
	 * A leading formula-triggering character is neutralised with a leading single quote.
	 *
	 * @dataProvider injection_prefixes
	 *
	 * @param string $prefix A spreadsheet-formula-triggering leading character.
	 */
	public function test_a_formula_prefix_is_neutralised( string $prefix ): void {
		aafm_log_activity(
			array(
				'ability' => 'a/x',
				'status'  => 'success',
				'detail'  => $prefix . 'SUM(A1)',
			)
		);
		$this->assertStringContainsString( "'" . $prefix . 'SUM(A1)', $this->capture_export() );
	}

	/**
	 * The formula-triggering leading characters a spreadsheet would otherwise execute.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function injection_prefixes(): array {
		return array(
			'equals' => array( '=' ),
			'plus'   => array( '+' ),
			'minus'  => array( '-' ),
			'at'     => array( '@' ),
		);
	}

	public function test_a_field_with_no_formula_prefix_is_left_untouched(): void {
		aafm_log_activity(
			array(
				'ability' => 'aafm/get-posts',
				'status'  => 'success',
			)
		);
		$csv = $this->capture_export();
		$this->assertStringContainsString( 'aafm/get-posts', $csv );
		$this->assertStringNotContainsString( "'aafm/get-posts", $csv );
	}

	/**
	 * The admin_post handler refuses a caller without manage_options before it ever reaches the
	 * exporter - checked via the same wp_die() interception pattern ActivityTabTest and
	 * OauthRevokeAjaxTest use, so the success path's raw exit() is never invoked by a test.
	 */
	public function test_the_export_handler_refuses_a_caller_without_manage_options(): void {
		$this->acting_as( 'subscriber' );
		$this->intercept_die();

		$nonce                = wp_create_nonce( 'aafm_admin' );
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$this->expectException( \WPDieException::class );
		aafm_handle_export_activity_log();
	}

	/**
	 * The admin_post handler refuses a request carrying no valid nonce, even from an
	 * administrator - check_admin_referer() runs before the capability check.
	 */
	public function test_the_export_handler_refuses_a_missing_nonce(): void {
		$this->acting_as( 'administrator' );
		$this->intercept_die();

		unset( $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->expectException( \WPDieException::class );
		aafm_handle_export_activity_log();
	}

	/**
	 * Capture aafm_export_activity_csv()'s streamed output.
	 *
	 * @param string|null $status Optional status filter, mirrored to the tested call.
	 * @return string
	 */
	private function capture_export( ?string $status = null ): string {
		ob_start();
		aafm_export_activity_csv( $status );
		return (string) ob_get_clean();
	}

	/**
	 * Route wp_die through a throwing handler so a refusal is observable in-process instead of
	 * terminating the test run. Mirrors ActivityTabTest::intercept_die().
	 *
	 * @return void
	 */
	private function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}
}
