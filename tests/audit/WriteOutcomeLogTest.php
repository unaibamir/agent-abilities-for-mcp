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

	public function test_option_write_reports_refused_without_throwing_when_the_option_holds_an_object(): void {
		add_option( 'aafm_wol_opt', (object) array( 'k' => 'old' ) );
		add_filter(
			'pre_update_option_aafm_wol_opt',
			static function ( $value, $old_value ) {
				return $old_value;
			},
			10,
			2
		);

		$thrown = '';
		$result = array();
		try {
			$result = aafm_option_write( 'aafm_wol_opt', 'new' );
		} catch ( \Throwable $e ) {
			$thrown = get_class( $e ) . ': ' . $e->getMessage();
		}

		remove_all_filters( 'pre_update_option_aafm_wol_opt' );

		$this->assertSame( '', $thrown, 'a stored object must be compared by value, never cast to a string.' );
		$this->assertSame( 'refused', $result['status'] );
		$this->assertFalse( $result['returned'] );
		$this->assertCount( 1, $this->write_outcome_rows() );

		delete_option( 'aafm_wol_opt' );
	}

	public function test_option_write_reports_refused_without_a_warning_when_the_option_holds_an_array(): void {
		add_option( 'aafm_wol_opt', array( 'k' => 'old' ) );
		add_filter(
			'pre_update_option_aafm_wol_opt',
			static function ( $value, $old_value ) {
				return $old_value;
			},
			10,
			2
		);

		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- captures any warning the comparison raises, restored below.
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
				$warnings[] = $errstr;
				return true;
			},
			E_WARNING | E_NOTICE
		);
		try {
			$result = aafm_option_write( 'aafm_wol_opt', 'new' );
		} finally {
			restore_error_handler();
		}

		remove_all_filters( 'pre_update_option_aafm_wol_opt' );

		$this->assertSame( array(), $warnings, 'a stored array must be compared by value, never cast to a string.' );
		$this->assertSame( 'refused', $result['status'] );

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

	public function test_the_observer_is_registered_in_production_at_php_int_min(): void {
		// TestCase::set_up() records the priority the observer was attached at, before detaching
		// it, so this proves the production wiring directly rather than trusting that this
		// fixture's own attach/detach calls name the right priority.
		$this->assertSame( PHP_INT_MIN, $this->write_outcome_observer_priority );
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

	/**
	 * Post-field and option-cache production callers.
	 */
	public function test_create_post_logs_one_post_field_row_per_confirmed_field(): void {
		$this->acting_as( 'editor' );

		$result = aafm_exec_create_post(
			array(
				'title'   => 'A title',
				'content' => 'Body copy.',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$post_id = (int) $result['post']['id'];

		$rows = $this->write_outcome_rows();
		$this->assertCount( 3, $rows );
		$rows_by_key = array();
		foreach ( $rows as $row ) {
			$this->assertSame( 'success', $row['status'] );
			$detail = json_decode( (string) $row['detail'], true );
			$this->assertSame( 'post_field', $detail['kind'] );
			$rows_by_key[ $detail['key'] ] = $detail;
		}
		// aafm_insert_post() confirms exactly these three fields on every create, excerpt included
		// even when the caller never sent one, so a missing or duplicated row must fail this.
		$this->assertSame( array( 'post_content', 'post_excerpt', 'post_title' ), $this->sorted_keys_of( $rows_by_key ) );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			$this->assertSame( (string) $post_id, $rows_by_key[ $field ]['object_id'], "$field must be logged against the created post id" );
			$this->assertSame( 'written', $rows_by_key[ $field ]['status'] );
		}
	}

	/**
	 * A detail array's own keys, sorted, for a literal comparison.
	 *
	 * @param array $rows_by_key Detail arrays keyed by field name.
	 * @return string[]
	 */
	private function sorted_keys_of( array $rows_by_key ): array {
		$keys = array_keys( $rows_by_key );
		sort( $keys );
		return $keys;
	}

	public function test_a_rewriting_filter_logs_unconfirmed_for_that_field_while_the_ability_still_returns_its_own_error(): void {
		$this->acting_as( 'editor' );

		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				$data['post_title'] = 'rewritten by a filter';
				return $data;
			}
		);

		$result = aafm_exec_create_post(
			array(
				'title'   => 'A title',
				'content' => 'Body copy.',
			)
		);

		remove_all_filters( 'wp_insert_post_data' );

		$this->assertInstanceOf( \WP_Error::class, $result, 'the ability must still return its existing error on an unconfirmed field.' );

		$rows          = $this->write_outcome_rows();
		$title_details = array();
		foreach ( $rows as $row ) {
			$detail = json_decode( (string) $row['detail'], true );
			if ( 'post_field' === $detail['kind'] ) {
				$title_details[] = $detail;
			}
		}
		// aafm_insert_post() checks post_title first and returns on its first unconfirmed field, so
		// a rewritten title logs exactly that one row: post_content and post_excerpt never run.
		$this->assertCount( 1, $title_details );
		$this->assertSame( 'post_title', $title_details[0]['key'] );
		$this->assertSame( 'unconfirmed', $title_details[0]['status'] );
	}

	public function test_update_option_verified_logs_one_written_row_for_an_admin_save(): void {
		add_option( 'aafm_wol_verified_opt', 'old' );

		aafm_update_option_verified( 'aafm_wol_verified_opt', 'new' );

		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertSame( 'written', json_decode( (string) $rows[0]['detail'], true )['status'] );

		delete_option( 'aafm_wol_verified_opt' );
	}

	public function test_update_option_verified_logs_written_for_a_same_value_resubmission(): void {
		add_option( 'aafm_wol_verified_opt', 'same' );

		aafm_update_option_verified( 'aafm_wol_verified_opt', 'same' );

		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'written', json_decode( (string) $rows[0]['detail'], true )['status'], 'the bool confirmer certifies true as written, even for a same-value write.' );

		delete_option( 'aafm_wol_verified_opt' );
	}

	/**
	 * A $wp_object_cache double whose set() refuses the alloptions/notoptions rewrite is what
	 * makes aafm_forget_option_caches() report failure and take the early exit
	 * aafm_update_option_verified() takes when its cache rewrite could not be trusted. Everything
	 * but set() forwards to the real cache, so the option's own read views stay real.
	 */
	public function test_update_option_verified_logs_one_unconfirmed_row_when_the_cache_rewrite_is_refused(): void {
		add_option( 'aafm_wol_verified_opt', 'old' );
		wp_cache_get( 'alloptions', 'options', true ); // Prime the runtime cache before the double takes over.

		global $wp_object_cache;
		$real            = $wp_object_cache;
		$wp_object_cache = new class( $real ) {
			/**
			 * The real object cache every method but set() forwards to.
			 *
			 * @var mixed
			 */
			private $real;

			/**
			 * Remember the real object cache.
			 *
			 * @param mixed $real The real object cache to forward every other call to.
			 */
			public function __construct( $real ) {
				$this->real = $real;
			}

			/**
			 * Refuse the alloptions/notoptions rewrite; forward every other set() call.
			 *
			 * @param string $key    Cache key.
			 * @param mixed  $data   Value to cache.
			 * @param string $group  Cache group.
			 * @param int    $expire Expiration, in seconds.
			 * @return bool
			 */
			public function set( $key, $data, $group = '', $expire = 0 ) {
				if ( 'options' === $group && in_array( $key, array( 'alloptions', 'notoptions' ), true ) ) {
					return false;
				}
				return $this->real->set( $key, $data, $group, $expire );
			}

			/**
			 * Forward every other object-cache method to the real cache.
			 *
			 * @param string  $name Method name.
			 * @param mixed[] $args Method arguments.
			 * @return mixed
			 */
			public function __call( $name, $args ) {
				return $this->real->$name( ...$args );
			}
		};

		$result = aafm_update_option_verified( 'aafm_wol_verified_opt', 'new' );

		$wp_object_cache = $real;

		$this->assertFalse( $result, 'a refused alloptions rewrite must take the early exit and report false.' );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'unconfirmed', json_decode( (string) $rows[0]['detail'], true )['status'] );

		delete_option( 'aafm_wol_verified_opt' );
	}

	public function test_persist_operator_switch_off_logs_one_deleted_row(): void {
		update_option( 'aafm_wol_switch', true );

		$result = aafm_persist_operator_switch( 'aafm_wol_switch', false );

		$this->assertTrue( $result );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'deleted', json_decode( (string) $rows[0]['detail'], true )['status'] );
	}

	/**
	 * The off branch runs aafm_option_write_certified()'s own database read twice: once inside
	 * aafm_delete_option_cache_safe()'s own certification, once again for the switch's own
	 * certification. Targeting the read's own SQL text ("SELECT option_value FROM"), occurrence 2,
	 * hits only the outer switch certification, after the inner delete's own certification has
	 * already passed clean.
	 */
	public function test_persist_operator_switch_off_logs_one_unconfirmed_row_when_its_own_certification_read_fails(): void {
		update_option( 'aafm_wol_switch', true );
		QueryFaultInjector::reset_fired_count();

		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = QueryFaultInjector::fail_nth_query(
			'SELECT option_value FROM',
			2,
			static function () {
				return aafm_persist_operator_switch( 'aafm_wol_switch', false );
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 1, QueryFaultInjector::fired_count(), 'the fault must hit exactly the outer certification read, its own second occurrence.' );
		$this->assertFalse( $result );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows, 'the inner delete helper call must not emit its own row; only the outer switch call emits.' );
		$this->assertSame( 'unconfirmed', json_decode( (string) $rows[0]['detail'], true )['status'] );
	}

	public function test_delete_option_cache_safe_on_an_option_that_never_existed_logs_one_deleted_row(): void {
		delete_option( 'aafm_wol_never_existed' );

		$result = aafm_delete_option_cache_safe( 'aafm_wol_never_existed' );

		$this->assertTrue( $result );
		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'deleted', json_decode( (string) $rows[0]['detail'], true )['status'] );
	}

	private function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}

	/**
	 * Run an AJAX handler and return its captured JSON payload.
	 *
	 * @param callable $handler The AJAX action's callback.
	 * @return array<string,mixed>
	 */
	private function run_ajax_handler( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	public function test_bridge_no_op_resave_logs_exactly_one_option_row(): void {
		$this->acting_as( 'administrator' );
		$this->intercept_die();

		delete_option( 'aafm_enabled_bridged_abilities' );
		update_option( 'aafm_enabled_bridged_abilities', array() );

		$nonce                      = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']             = $nonce;
		$_REQUEST['nonce']          = $nonce;
		$_POST['bridged_abilities'] = array();

		$this->run_ajax_handler( 'aafm_ajax_save_bridged_abilities' );

		$rows = $this->write_outcome_rows();
		$this->assertCount( 1, $rows, 'a no-op resave must log exactly one option row and nothing else.' );
		$this->assertSame( 'option', json_decode( (string) $rows[0]['detail'], true )['kind'] );
		$this->assertSame( 'aafm_enabled_bridged_abilities', json_decode( (string) $rows[0]['detail'], true )['key'] );
		$this->assertSame( 'written', json_decode( (string) $rows[0]['detail'], true )['status'] );

		unset( $_POST['nonce'], $_REQUEST['nonce'], $_POST['bridged_abilities'] );
	}

	public function test_uninstall_with_delete_data_detaches_the_observer_during_teardown_and_reattaches_it_after(): void {
		aafm_install_activity_log();
		update_option( 'aafm_delete_data_on_uninstall', true );

		// aafm_delete_data_on_uninstall is the last of the six deletes the teardown runs after the
		// table drop, so this catches the detach directly, from inside the teardown, rather than
		// only proving the table guard kept a post-drop row from printing.
		$observed_during_teardown = 'not observed';
		$capture                  = static function ( string $option ) use ( &$observed_during_teardown ): void {
			if ( 'aafm_delete_data_on_uninstall' === $option ) {
				$observed_during_teardown = has_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome' );
			}
		};
		add_action( 'delete_option', $capture );

		ob_start();
		aafm_uninstall_site_data();
		$printed = ob_get_clean();

		remove_action( 'delete_option', $capture );

		$this->assertSame( '', $printed, 'the teardown must print nothing even with the plugin loaded.' );
		$this->assertFalse( $observed_during_teardown, 'the observer must be detached while the teardown deletes options.' );
		$this->assertSame(
			\PHP_INT_MIN,
			has_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome' ),
			'the observer must be re-attached at its original priority after the teardown.'
		);
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
