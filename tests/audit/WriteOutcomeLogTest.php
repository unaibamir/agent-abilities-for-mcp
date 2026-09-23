<?php
/**
 * The write-outcome log observer (aafm_activity_log_write_outcome()): one activity-log row per
 * emission, carrying identifiers only, never a value.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class WriteOutcomeLogTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The fixture (tests/TestCase.php) detaches the observer by default; every case here
		// attaches it itself, matching production wiring (the earliest possible priority, two
		// arguments).
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
	}

	public function tear_down(): void {
		remove_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN );
		parent::tear_down();
	}

	private function write_outcome_rows(): array {
		global $wpdb;
		$table = aafm_activity_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_type = %s ORDER BY id', $table, 'write_outcome' ), ARRAY_A );
	}

	public function test_a_written_call_inserts_exactly_one_row_with_the_eight_detail_keys(): void {
		$post_id = self::factory()->post->create();

		aafm_meta_set( 'post', $post_id, 'aafm_wol_key', 'new', 'post', true );

		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'aafm/write-outcome', $rows[0]['ability'] );
		$this->assertSame( 'success', $rows[0]['status'] );

		$detail = json_decode( (string) $rows[0]['detail'], true );
		$this->assertSame(
			array( 'kind', 'entity', 'object_id', 'key', 'status', 'rows', 'modified_by_site', 'key_omitted' ),
			array_keys( $detail )
		);
	}

	public function test_a_refused_call_inserts_one_error_row(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_wol_key', 'old' );
		add_filter( 'update_post_metadata', '__return_false' );

		aafm_meta_set( 'post', $post_id, 'aafm_wol_key', 'new', 'post', true );

		remove_filter( 'update_post_metadata', '__return_false' );

		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
		$detail = json_decode( (string) $rows[0]['detail'], true );
		$this->assertSame( 'refused', $detail['status'] );
		$this->assertSame(
			array( 'kind', 'entity', 'object_id', 'key', 'status', 'rows', 'modified_by_site', 'key_omitted' ),
			array_keys( $detail )
		);
	}

	public function test_a_read_failed_call_inserts_one_error_row(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();

		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		QueryFaultInjector::fail_query(
			$wpdb->postmeta,
			static function () use ( $post_id ) {
				return aafm_meta_set( 'post', $post_id, 'aafm_wol_key', 'new', 'post', true );
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
		$detail = json_decode( (string) $rows[0]['detail'], true );
		$this->assertSame( 'read_failed', $detail['status'] );
		$this->assertSame(
			array( 'kind', 'entity', 'object_id', 'key', 'status', 'rows', 'modified_by_site', 'key_omitted' ),
			array_keys( $detail )
		);
	}

	public function test_the_row_carries_the_current_principal_and_client_id(): void {
		$user_id = $this->acting_as( 'administrator' );
		if ( function_exists( 'aafm_oauth_current_client_id' ) ) {
			aafm_oauth_current_client_id( 'aafm-test-client' );
		}

		$post_id = self::factory()->post->create();
		aafm_meta_set( 'post', $post_id, 'aafm_wol_key', 'new', 'post', true );

		$rows = $this->write_outcome_rows();
		$this->assertSame( $user_id, (int) $rows[0]['principal_user_id'] );
		$user = get_userdata( $user_id );
		$this->assertSame( $user->user_login, $rows[0]['principal_login'] );
		if ( function_exists( 'aafm_oauth_current_client_id' ) ) {
			$this->assertSame( 'aafm-test-client', $rows[0]['client_id'] );
			aafm_oauth_current_client_id( '' );
		}
	}

	public function test_a_write_before_init_logs_principal_zero_and_never_resolves_the_current_user(): void {
		$this->acting_as( 'administrator' );
		// Created before the window below: post creation itself resolves the current user (for
		// post_author) as ordinary core behaviour, which is not what this test is about.
		$post_id = self::factory()->post->create();

		$prior_init_count = $GLOBALS['wp_actions']['init'] ?? null;
		unset( $GLOBALS['wp_actions']['init'] );
		$prior_current_user      = $GLOBALS['current_user'];
		$GLOBALS['current_user'] = null;

		aafm_meta_set( 'post', $post_id, 'aafm_wol_key', 'new', 'post', true );

		if ( null !== $prior_init_count ) {
			$GLOBALS['wp_actions']['init'] = $prior_init_count;
		}
		$current_user_after_call = $GLOBALS['current_user'];
		$GLOBALS['current_user'] = $prior_current_user;

		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 0, (int) $rows[0]['principal_user_id'] );
		$this->assertSame( '', $rows[0]['principal_login'] );
		$this->assertSame( '', $rows[0]['client_id'] );
		$this->assertNull( $current_user_after_call, 'a write before init must never resolve the current user.' );
	}

	public function test_a_group_call_of_two_keys_inserts_two_rows(): void {
		$post_id = self::factory()->post->create();

		aafm_meta_set_group(
			'post',
			$post_id,
			array(
				'aafm_wol_g1' => 'a',
				'aafm_wol_g2' => 'b',
			),
			'post'
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

		$rows = $this->write_outcome_rows();
		$this->assertCount( 2, $rows );
	}

	public function test_a_long_key_logs_null_with_key_omitted(): void {
		$post_id  = self::factory()->post->create();
		$long_key = str_repeat( 'k', 65 );

		aafm_meta_set( 'post', $post_id, $long_key, 'new', 'post', true );

		$rows   = $this->write_outcome_rows();
		$detail = json_decode( (string) $rows[0]['detail'], true );
		$this->assertNull( $detail['key'] );
		$this->assertTrue( $detail['key_omitted'] );
	}

	public function test_an_empty_key_logs_null_with_key_omitted(): void {
		$post_id = self::factory()->post->create();

		aafm_meta_delete( 'post', $post_id, '' );

		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$detail = json_decode( (string) $rows[0]['detail'], true );
		$this->assertNull( $detail['key'] );
		$this->assertTrue( $detail['key_omitted'] );
	}

	public function test_an_observer_that_mutates_an_object_baseline_then_throws_leaves_the_returned_previous_unchanged(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_wol_key', (object) array( 'k' => 'old' ) );
		add_action(
			'aafm_write_completed',
			static function ( $result ) {
				$result['previous']->k = 'changed-by-observer';
				throw new \RuntimeException( 'boom' );
			}
		);

		$result = aafm_meta_set( 'post', $post_id, 'aafm_wol_key', 'new', 'post', true );

		$this->assertSame( 'written', $result['status'] );
		$this->assertSame( 'old', $result['previous']->k );
	}

	public function test_an_object_or_array_returned_reaches_observers_as_null_and_a_scalar_one_unchanged(): void {
		$seen     = array();
		$observer = static function ( $result ) use ( &$seen ) {
			$seen[] = $result;
		};
		add_action( 'aafm_write_completed', $observer );
		$target = array(
			'kind'      => 'option',
			'entity'    => null,
			'object_id' => null,
			'key'       => 'aafm_wol_opt',
		);

		aafm_emit_write_outcome(
			array(
				'status'   => 'refused',
				'returned' => (object) array( 'k' => 'old' ),
			),
			$target
		);
		aafm_emit_write_outcome(
			array(
				'status'   => 'refused',
				'returned' => false,
			),
			$target
		);

		remove_action( 'aafm_write_completed', $observer );

		$this->assertCount( 2, $seen );
		$this->assertArrayHasKey( 'returned', $seen[0] );
		$this->assertNull( $seen[0]['returned'] );
		$this->assertFalse( $seen[1]['returned'] );
	}

	public function test_a_value_whose_wakeup_throws_reaches_observers_as_null_and_the_call_returns(): void {
		$seen     = array();
		$observer = static function ( $result ) use ( &$seen ) {
			$seen[] = $result;
		};
		add_action( 'aafm_write_completed', $observer );

		$thrown = '';
		try {
			aafm_emit_write_outcome(
				array(
					'status' => 'written',
					'value'  => new WakeupThrowsValue(),
				),
				array(
					'kind'      => 'post_meta',
					'entity'    => null,
					'object_id' => 1,
					'key'       => 'aafm_wol_key',
				)
			);
		} catch ( \Throwable $e ) {
			$thrown = get_class( $e ) . ': ' . $e->getMessage();
		}

		remove_action( 'aafm_write_completed', $observer );

		$this->assertSame( '', $thrown, 'copying the result for observers must never throw out of emission.' );
		$this->assertCount( 1, $seen );
		$this->assertArrayHasKey( 'value', $seen[0] );
		$this->assertNull( $seen[0]['value'] );
	}

	public function test_the_maximum_length_row_stores_intact_at_238_characters(): void {
		aafm_activity_log_write_outcome(
			array(
				'status'           => 'unconfirmed',
				'rows'             => PHP_INT_MAX,
				'modified_by_site' => false,
			),
			array(
				'kind'      => 'post_meta',
				'entity'    => null,
				'object_id' => PHP_INT_MAX,
				'key'       => str_repeat( 'k', 64 ),
			)
		);

		$rows   = $this->write_outcome_rows();
		$detail = (string) $rows[0]['detail'];
		$this->assertNotNull( json_decode( $detail, true ), 'the maximum row must decode intact, never truncated by the 255-character sanitizer.' );
		$this->assertSame( 238, strlen( $detail ), 'the longest possible row is exactly 238 characters, well under the 255-character sanitizer cut.' );
	}

	public function test_no_row_ever_carries_a_value(): void {
		$post_id = self::factory()->post->create();
		aafm_meta_set( 'post', $post_id, 'aafm_wol_key', 'a-secret-value-nobody-should-see', 'post', true );

		$rows = $this->write_outcome_rows();
		$this->assertStringNotContainsString( 'a-secret-value-nobody-should-see', (string) $rows[0]['detail'] );
	}

	public function test_option_write_reports_and_logs_written_for_a_change(): void {
		add_option( 'aafm_wol_opt', 'old' );

		$result = aafm_option_write( 'aafm_wol_opt', 'new' );

		$this->assertSame( AAFM_WRITE_WRITTEN, $result['status'] );
		$rows = $this->write_outcome_rows();
		$this->assertSame( 'written', json_decode( (string) $rows[0]['detail'], true )['status'] );

		delete_option( 'aafm_wol_opt' );
	}

	public function test_option_write_reports_and_logs_unchanged_for_a_same_value_write(): void {
		add_option( 'aafm_wol_opt', 'same' );

		$result = aafm_option_write( 'aafm_wol_opt', 'same' );

		$this->assertSame( AAFM_WRITE_UNCHANGED, $result['status'] );
		$rows = $this->write_outcome_rows();
		$this->assertSame( 'unchanged', json_decode( (string) $rows[0]['detail'], true )['status'] );

		delete_option( 'aafm_wol_opt' );
	}

	public function test_option_write_reports_refused_when_a_filter_keeps_the_old_value(): void {
		add_option( 'aafm_wol_opt', 'old' );
		add_filter(
			'pre_update_option_aafm_wol_opt',
			static function () {
				return 'old';
			}
		);

		$result = aafm_option_write( 'aafm_wol_opt', 'new' );

		remove_all_filters( 'pre_update_option_aafm_wol_opt' );

		$this->assertSame( 'refused', $result['status'] );

		delete_option( 'aafm_wol_opt' );
	}

	public function test_option_write_reports_unconfirmed_when_the_database_read_after_a_false_update_fails(): void {
		global $wpdb;
		add_option( 'aafm_wol_opt', 'old' );
		add_filter(
			'pre_update_option_aafm_wol_opt',
			static function () {
				return 'old';
			}
		);

		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = QueryFaultInjector::fail_query(
			$wpdb->options,
			static function () {
				return aafm_option_write( 'aafm_wol_opt', 'new' );
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		remove_all_filters( 'pre_update_option_aafm_wol_opt' );

		$this->assertSame( 'unconfirmed', $result['status'] );

		delete_option( 'aafm_wol_opt' );
	}

	public function test_an_observer_that_throws_leaves_the_returned_result_unchanged(): void {
		add_action(
			'aafm_write_completed',
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		$post_id = self::factory()->post->create();
		$result  = aafm_meta_set( 'post', $post_id, 'aafm_wol_key', 'new', 'post', true );

		$this->assertSame( AAFM_WRITE_WRITTEN, $result['status'] );
	}

	public function test_a_throwing_listener_before_or_after_the_log_observer_cannot_stop_its_row(): void {
		$throw = static function () {
			throw new \RuntimeException( 'boom' );
		};
		add_action( 'aafm_write_completed', $throw, -1000 );
		add_action( 'aafm_write_completed', $throw, 10 );

		$post_id = self::factory()->post->create();
		$result  = aafm_meta_set( 'post', $post_id, 'aafm_wol_key', 'new', 'post', true );

		remove_action( 'aafm_write_completed', $throw, -1000 );
		remove_action( 'aafm_write_completed', $throw, 10 );

		$this->assertSame( AAFM_WRITE_WRITTEN, $result['status'], 'the returned result must stay unchanged.' );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows, 'the log observer runs at the earliest priority, so a throwing listener at any other priority cannot stop its row.' );
	}

	/**
	 * The bootstrap points error_log at one temporary file for the whole run (the suite's own
	 * WP_DEBUG is true), so this reads only the bytes this call appends, never an earlier test's
	 * lines.
	 */
	public function test_wp_debug_diagnostic_line_carries_identifiers_and_no_value(): void {
		$this->assertTrue( defined( 'WP_DEBUG' ) && WP_DEBUG, 'precondition: this suite runs under WP_DEBUG true.' );

		clearstatcache( true, AAFM_TEST_ERROR_LOG );
		$offset = filesize( AAFM_TEST_ERROR_LOG );

		$post_id = self::factory()->post->create();
		aafm_meta_set( 'post', $post_id, 'aafm_wol_key', 'a-secret-value-nobody-should-see', 'post', true );

		clearstatcache( true, AAFM_TEST_ERROR_LOG );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this suite's own redirected error_log file (tests/bootstrap.php), not a remote URL.
		$contents = (string) file_get_contents( AAFM_TEST_ERROR_LOG, false, null, false === $offset ? 0 : $offset );

		$this->assertStringContainsString( 'status=written', $contents );
		$this->assertStringContainsString( 'key=aafm_wol_key', $contents );
		$this->assertStringNotContainsString( 'a-secret-value-nobody-should-see', $contents );
	}

	public function test_wp_debug_diagnostic_line_redacts_a_key_that_fails_the_key_rule(): void {
		$this->assertTrue( defined( 'WP_DEBUG' ) && WP_DEBUG, 'precondition: this suite runs under WP_DEBUG true.' );

		clearstatcache( true, AAFM_TEST_ERROR_LOG );
		$offset = filesize( AAFM_TEST_ERROR_LOG );

		$post_id  = self::factory()->post->create();
		$long_key = str_repeat( 'k', 65 );
		aafm_meta_set( 'post', $post_id, $long_key, 'new', 'post', true );

		clearstatcache( true, AAFM_TEST_ERROR_LOG );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this suite's own redirected error_log file (tests/bootstrap.php), not a remote URL.
		$contents = (string) file_get_contents( AAFM_TEST_ERROR_LOG, false, null, false === $offset ? 0 : $offset );

		$this->assertStringContainsString( 'key=-', $contents );
		$this->assertStringNotContainsString( $long_key, $contents );
	}
}

/**
 * A value that serializes but whose __wakeup() throws, so copying it for observers fails on the way
 * back in.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- a value class only the test above uses.
final class WakeupThrowsValue {

	/**
	 * Something to serialize.
	 *
	 * @var string
	 */
	public $k = 'old';

	public function __wakeup(): void {
		throw new \Error( 'refusing to wake up' );
	}
}
