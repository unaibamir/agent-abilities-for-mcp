<?php
/**
 * The three site health reads: site-health-status, site-list-updates, site-cron-status.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class SiteHealthTest extends TestCase {

	private const NAMES = array( 'aafm/site-health-status', 'aafm/site-list-updates', 'aafm/site-cron-status' );

	public function set_up(): void {
		parent::set_up();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( self::NAMES );
	}

	/**
	 * What a tool must leave unchanged.
	 *
	 * @return array{0:string,1:string}
	 */
	private function snapshot(): array {
		global $wpdb;
		$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_N );
		return array( md5( serialize( $options ) ), md5( serialize( _get_cron_array() ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fingerprints only, never stored.
	}

	public function test_the_three_are_core_reads_gated_on_manage_options(): void {
		$registry = aafm_get_abilities_registry();
		foreach ( self::NAMES as $name ) {
			$this->assertSame( 'reads', $registry[ $name ]['group'] );
			$this->assertSame( 'read', $registry[ $name ]['risk'] );
			$this->assertSame( 'site', $registry[ $name ]['subject'] );
			$this->assertStringEndsWith( 'Requires the manage-options capability. On a multisite network, a super admin only.', $registry[ $name ]['description'] );

			$this->acting_as( 'editor' );
			$this->assertNotTrue( wp_get_ability( $name )->check_permissions( array() ), $name );
			$this->acting_as( 'administrator' );
			$this->assertTrue( wp_get_ability( $name )->check_permissions( array() ), $name );
		}
	}

	public function test_the_allowlist_is_the_18_tests_that_need_no_request(): void {
		$allowlist = aafm_site_health_allowlist();
		$this->assertCount( 18, $allowlist );
		$this->assertCount( 18, array_unique( $allowlist ) );
		$left_out = array( 'update_temp_backup_writable', 'php_version', 'http_requests', 'rest_availability', 'dotorg_communication', 'background_updates', 'loopback_requests', 'https_status', 'authorization_header', 'page_cache' );
		foreach ( $left_out as $name ) {
			$this->assertNotContains( $name, $allowlist );
		}
		$this->assertSame( $left_out, aafm_site_health_not_run(), 'The tests left out are named, so the counts are not read as the Site Health screen\'s.' );
		$this->assertContains( 'scheduled_events', $allowlist );
		$this->assertContains( 'persistent_object_cache', $allowlist );
	}

	public function test_health_status_returns_names_and_results_only(): void {
		$this->acting_as( 'administrator' );
		$result = wp_get_ability( 'aafm/site-health-status' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'tests', 'counts', 'not_run', 'debug_mode', 'debug_log_exists', 'recovery_mode', 'extensions_paused' ), array_keys( $result ) );
		$this->assertSame( aafm_site_health_not_run(), $result['not_run'] );
		$this->assertNotEmpty( $result['tests'] );
		foreach ( $result['tests'] as $row ) {
			$this->assertSame( array( 'test', 'status', 'label' ), array_keys( $row ) );
			$this->assertIsString( $row['label'] );
			$this->assertContains( $row['test'], aafm_site_health_allowlist() );
			$this->assertContains( $row['status'], array( 'good', 'recommended', 'critical' ) );
		}
		$this->assertSame( array( 'good', 'recommended', 'critical' ), array_keys( $result['counts'] ) );
		$this->assertSame( count( $result['tests'] ), array_sum( $result['counts'] ) );
		foreach ( array( 'debug_mode', 'debug_log_exists', 'recovery_mode', 'extensions_paused' ) as $flag ) {
			$this->assertIsBool( $result[ $flag ] );
		}

		$json = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( '<', $json, 'No label or description HTML.' );
		$this->assertStringNotContainsString( ABSPATH, $json );
		$this->assertStringNotContainsString( WP_CONTENT_DIR, $json );
		$this->assertStringNotContainsString( get_bloginfo( 'version' ), $json );
	}

	/**
	 * The label is core's own result label as plain text, never with a version number in it: a bare
	 * check id such as theme_version was read as "a theme update is waiting".
	 */
	public function test_the_label_is_core_text_without_markup_or_versions(): void {
		$this->assertSame( 'You should remove inactive themes', aafm_site_health_label( array( 'label' => 'You should remove <strong>inactive</strong> themes' ) ) );
		$this->assertSame( 'WordPress update available', aafm_site_health_label( array( 'label' => 'WordPress update available (7.1.5)' ) ) );
		$this->assertSame( "Your site isn\u{2019}t safe", aafm_site_health_label( array( 'label' => 'Your site isn&#8217;t safe' ) ) );
		$this->assertSame( '', aafm_site_health_label( array( 'label' => 'PHP 8.3.1 is running' ) ), 'A version outside brackets drops the label.' );
		$this->assertSame( '', aafm_site_health_label( null ) );
	}

	public function test_health_status_never_schedules_the_weekly_check_when_it_builds_the_instance(): void {
		$instance = new \ReflectionProperty( \WP_Site_Health::class, 'instance' );
		$instance->setAccessible( true );
		$saved = $instance->getValue();
		wp_clear_scheduled_hook( 'wp_site_health_scheduled_check' );
		$instance->setValue( null, null );

		try {
			$this->assertIsArray( aafm_exec_site_health_status() );
			$this->assertInstanceOf( \WP_Site_Health::class, $instance->getValue(), 'The cold path ran: the call built the instance.' );
			$this->assertFalse( wp_next_scheduled( 'wp_site_health_scheduled_check' ), 'Building the instance scheduled nothing.' );
			$this->assertFalse( has_filter( 'pre_schedule_event', 'aafm_site_health_block_weekly_check' ), 'The block is removed after the call.' );
			$this->assertNotFalse( wp_schedule_single_event( time() + 60, 'aafm_test_after_health' ), 'Other events still schedule.' );
		} finally {
			$instance->setValue( null, $saved );
		}
	}

	public function test_extensions_paused_is_false_for_a_stored_empty_array(): void {
		$this->acting_as( 'administrator' );
		add_option( 'aafmtest_paused_extensions', array() );
		$this->assertFalse( wp_get_ability( 'aafm/site-health-status' )->execute( array() )['extensions_paused'] );

		update_option( 'aafmtest_paused_extensions', array( 'plugin' => array( 'some-plugin' => array( 'type' => 1 ) ) ) );
		$this->assertTrue( wp_get_ability( 'aafm/site-health-status' )->execute( array() )['extensions_paused'] );
	}

	/**
	 * Store what a finished update check leaves behind.
	 */
	private function seed_updates(): void {
		set_site_transient(
			'update_core',
			(object) array(
				'last_checked' => 1763400000,
				'updates'      => array(
					(object) array(
						'response' => 'latest',
						'current'  => '7.0',
					),
					(object) array(
						'response' => 'upgrade',
						'current'  => '9.9.9',
						'package'  => 'https://example.invalid/core.zip?licence=SECRET',
					),
				),
			)
		);
		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => 1763400060,
				'response'     => array(
					'good-plugin/good-plugin.php' => (object) array(
						'new_version' => '5.9-beta+1',
						'package'     => 'https://example.invalid/pkg.zip?licence=SECRET',
					),
					'bad key!.php'                => (object) array( 'new_version' => '1.0' ),
					'bad-version/bad-version.php' => (object) array( 'new_version' => '1.0 <b>' ),
					'long-version/long.php'       => (object) array( 'new_version' => str_repeat( '1', 60 ) ),
				),
			)
		);
		set_site_transient(
			'update_themes',
			(object) array(
				'last_checked' => 1763400120,
				'response'     => array(
					'good-theme' => array(
						'new_version' => '2.0',
						'package'     => 'https://example.invalid/theme.zip?licence=SECRET',
					),
				),
			)
		);
	}

	public function test_list_updates_reads_the_last_check_and_never_a_download_address(): void {
		$this->seed_updates();
		$this->acting_as( 'administrator' );
		$result = wp_get_ability( 'aafm/site-list-updates' )->execute( array() );

		$this->assertSame(
			array(
				'core'         => array(
					'update_available' => true,
					'new_version'      => '9.9.9',
				),
				'plugins'      => array(
					array(
						'plugin'      => 'good-plugin/good-plugin.php',
						'new_version' => '5.9-beta+1',
					),
					array(
						'plugin'      => 'long-version/long.php',
						'new_version' => str_repeat( '1', 40 ),
					),
				),
				'themes'       => array(
					array(
						'stylesheet'  => 'good-theme',
						'new_version' => '2.0',
					),
				),
				'last_checked' => array(
					'core'    => gmdate( 'c', 1763400000 ),
					'plugins' => gmdate( 'c', 1763400060 ),
					'themes'  => gmdate( 'c', 1763400120 ),
				),
			),
			$result
		);

		$json = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( 'package', $json );
		$this->assertStringNotContainsString( 'SECRET', $json );
		$this->assertStringNotContainsString( 'example.invalid', $json );
	}

	public function test_list_updates_with_nothing_stored(): void {
		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		$this->acting_as( 'administrator' );

		$this->assertSame(
			array(
				'core'         => array(
					'update_available' => false,
					'new_version'      => '',
				),
				'plugins'      => array(),
				'themes'       => array(),
				'last_checked' => array(
					'core'    => null,
					'plugins' => null,
					'themes'  => null,
				),
			),
			wp_get_ability( 'aafm/site-list-updates' )->execute( array() )
		);
	}

	public function test_a_request_made_during_list_updates_is_refused(): void {
		$this->seed_updates();
		$this->acting_as( 'administrator' );

		$seen    = array();
		$licence = static function ( $value ) use ( &$seen ) {
			$seen[] = wp_remote_get( 'https://example.invalid/licence-check' );
			return $value;
		};
		add_filter( 'site_transient_update_plugins', $licence );
		wp_get_ability( 'aafm/site-list-updates' )->execute( array() );
		remove_filter( 'site_transient_update_plugins', $licence );

		$this->assertNotEmpty( $seen );
		foreach ( $seen as $response ) {
			$this->assertInstanceOf( \WP_Error::class, $response );
			$this->assertSame( 'aafm_site_no_http', $response->get_error_code() );
		}
	}

	public function test_cron_status_counts_and_sanitises(): void {
		$now = time();
		wp_schedule_single_event( $now - 600, 'aafm_test_overdue', array( 'secret-arg-value' ) );
		wp_schedule_event( $now + 3600, 'hourly', 'aafm_test_future' );
		wp_schedule_single_event( $now + 7200, 'bad hook name!' );

		$this->acting_as( 'administrator' );
		$result = wp_get_ability( 'aafm/site-cron-status' )->execute( array() );

		$this->assertSame( array( 'wp_cron_disabled', 'alternate_cron', 'total_events', 'due_now', 'overdue', 'oldest_overdue_seconds', 'hooks' ), array_keys( $result ) );
		$this->assertIsBool( $result['wp_cron_disabled'] );
		$this->assertIsBool( $result['alternate_cron'] );
		$this->assertGreaterThanOrEqual( 3, $result['total_events'] );
		$this->assertGreaterThanOrEqual( 1, $result['due_now'] );
		$this->assertGreaterThanOrEqual( 1, $result['overdue'] );
		$this->assertGreaterThanOrEqual( 600, $result['oldest_overdue_seconds'] );

		$by_hook = array();
		$last    = -1;
		foreach ( $result['hooks'] as $row ) {
			$this->assertSame( array( 'hook', 'next_run_in_seconds', 'schedule', 'events' ), array_keys( $row ) );
			$this->assertGreaterThanOrEqual( $last, $row['next_run_in_seconds'], 'Sorted by next run.' );
			$last                    = $row['next_run_in_seconds'];
			$by_hook[ $row['hook'] ] = $row;
		}
		$this->assertSame( 0, $by_hook['aafm_test_overdue']['next_run_in_seconds'] );
		$this->assertSame( 'single', $by_hook['aafm_test_overdue']['schedule'] );
		$this->assertSame( 'hourly', $by_hook['aafm_test_future']['schedule'] );
		$this->assertGreaterThan( 3500, $by_hook['aafm_test_future']['next_run_in_seconds'] );
		$this->assertLessThanOrEqual( 3600, $by_hook['aafm_test_future']['next_run_in_seconds'] );
		$this->assertArrayHasKey( 'other', $by_hook );
		$this->assertArrayNotHasKey( 'bad hook name!', $by_hook );

		$json = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( 'secret-arg-value', $json );
		$this->assertStringNotContainsString( 'bad hook name', $json );
	}

	public function test_cron_status_lists_at_most_50_hooks(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			wp_schedule_single_event( time() + 100 + $i, 'aafm_test_many_' . $i );
		}
		$this->acting_as( 'administrator' );
		$result = wp_get_ability( 'aafm/site-cron-status' )->execute( array() );
		$this->assertCount( 50, $result['hooks'] );
		$this->assertGreaterThanOrEqual( 60, $result['total_events'] );
	}

	public function test_no_health_tool_changes_an_option_or_the_cron_array(): void {
		$this->seed_updates();
		foreach ( array( 'aafm_exec_site_health_status', 'aafm_exec_site_list_updates', 'aafm_exec_site_cron_status' ) as $callback ) {
			$before = $this->snapshot();
			$this->assertIsArray( $callback() );
			$this->assertSame( $before, $this->snapshot(), $callback );
		}
	}
}
