<?php
/**
 * Playbooks storage: one option row, read from the database, cleaned on save and on read, with
 * failed reads and writes refused rather than mistaken for an empty library.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class PlaybooksStoreTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'query' );
		remove_all_filters( 'pre_update_option_aafm_playbooks' );
		delete_option( 'aafm_playbooks' );
		wp_cache_delete( 'aafm_playbooks', 'options' );
		parent::tear_down();
	}

	/**
	 * Valid fields with overrides.
	 *
	 * @param array<string,mixed> $over Field overrides.
	 * @return array<string,mixed>
	 */
	private function fields( array $over = array() ): array {
		return array_merge(
			array(
				'title'       => 'Publish checklist',
				'description' => 'Check a draft before it goes out.',
				'body'        => "Read the draft.\nReport what is missing.",
				'active'      => true,
			),
			$over
		);
	}

	/**
	 * The playbook Activity Log rows.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function log_rows(): array {
		global $wpdb;
		$table = aafm_activity_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ability = %s ORDER BY id ASC", 'aafm/playbooks' ), ARRAY_A );
	}

	/**
	 * The option row straight from the database.
	 *
	 * @return mixed The raw row value, unserialized.
	 */
	private function db_row() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'aafm_playbooks' ) );
		return null === $raw ? null : maybe_unserialize( $raw );
	}

	private function break_row_reads(): void {
		add_filter(
			'query',
			static function ( string $query ): string {
				if ( false !== strpos( $query, 'SELECT' ) && false !== strpos( $query, "'aafm_playbooks'" ) ) {
					return 'SELECT * FROM aafm_missing_table_for_test';
				}
				return $query;
			}
		);
	}

	public function test_no_row_means_no_playbooks(): void {
		$this->assertSame( array(), aafm_playbooks() );
		$this->assertNull( get_option( 'aafm_playbooks', null ) );
	}

	public function test_save_creates_with_a_slug_from_the_title(): void {
		$first  = aafm_save_playbook( $this->fields() );
		$second = aafm_save_playbook( $this->fields() );
		$this->assertSame( 'publish-checklist', $first['slug'] );
		$this->assertSame( 'publish-checklist-2', $second['slug'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $first['updated_gmt'] );
	}

	public function test_non_latin_titles_get_ascii_slugs(): void {
		$slugs = array();
		foreach ( array( '日本語のプレイブック', '日本語のプレイブック', 'Café plan' ) as $title ) {
			$slugs[] = aafm_save_playbook( $this->fields( array( 'title' => $title ) ) )['slug'];
		}
		$this->assertSame( array( 'playbook', 'playbook-2', 'cafe-plan' ), $slugs );
		$this->assertSame( $slugs, array_column( aafm_playbooks(), 'slug' ) );
		foreach ( $slugs as $slug ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]{1,64}$/', $slug );
		}
	}

	public function test_long_duplicate_titles_keep_slugs_within_64(): void {
		$title = str_repeat( 'abcdefghi ', 8 );
		$slugs = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$slugs[] = aafm_save_playbook( $this->fields( array( 'title' => $title ) ) )['slug'];
		}
		$this->assertCount( 3, array_unique( $slugs ) );
		foreach ( $slugs as $slug ) {
			$this->assertLessThanOrEqual( 64, strlen( $slug ) );
		}
	}

	public function test_each_save_writes_one_row(): void {
		aafm_save_playbook( $this->fields() );
		$this->assertCount( 1, $this->log_rows() );
		aafm_save_playbook( $this->fields(), '', 'added_starter' );
		$rows = $this->log_rows();
		$this->assertCount( 2, $rows );
		$this->assertStringContainsString( 'Starter playbook added', (string) $rows[1]['detail'] );
	}

	public function test_slug_never_changes_on_edit(): void {
		aafm_save_playbook( $this->fields() );
		$edited = aafm_save_playbook( $this->fields( array( 'title' => 'Something else' ) ), 'publish-checklist' );
		$this->assertSame( 'publish-checklist', $edited['slug'] );
		$this->assertSame( 'Something else', aafm_playbook( 'publish-checklist' )['title'] );
	}

	public function test_limits_name_the_range(): void {
		$cases = array(
			array( array( 'title' => str_repeat( 'a', 81 ) ), '80' ),
			array( array( 'title' => '' ), '80' ),
			array( array( 'description' => str_repeat( 'a', 201 ) ), '200' ),
			array( array( 'body' => '' ), number_format_i18n( 8000 ) ),
			array( array( 'body' => str_repeat( 'a', 8001 ) ), number_format_i18n( 8000 ) ),
		);
		foreach ( $cases as $case ) {
			$result = aafm_save_playbook( $this->fields( $case[0] ) );
			$this->assertWPError( $result );
			$this->assertSame( 'aafm_playbook_invalid', $result->get_error_code() );
			$this->assertStringContainsString( $case[1], $result->get_error_message() );
		}
		$list = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$list[] = array(
				'slug'  => 'p' . $i,
				'title' => 'P' . $i,
				'body'  => 'b',
			);
		}
		update_option( 'aafm_playbooks', $list, false );
		$this->assertSame( 'aafm_playbook_limit', aafm_save_playbook( $this->fields() )->get_error_code() );
	}

	public function test_body_is_stored_as_plain_text(): void {
		$saved = aafm_save_playbook( $this->fields( array( 'body' => "<b>Step</b>\r\n<script>x()</script>\u{202E}two" ) ) );
		$row   = $this->db_row();
		$body  = $row[0]['body'];
		$this->assertStringNotContainsString( '<', $body );
		$this->assertStringNotContainsString( "\r", $body );
		$this->assertStringNotContainsString( "\u{202E}", $body );
		$this->assertSame( $body, aafm_playbook( $saved['slug'] )['body'] );

		$second = aafm_save_playbook( $this->fields( array( 'body' => "one\r\ntwo\rthree" ) ) );
		$this->assertSame( "one\ntwo\nthree", $this->db_row()[1]['body'] );
		$this->assertSame( "one\ntwo\nthree", aafm_playbook( $second['slug'] )['body'] );
	}

	public function test_hand_written_row_is_cleaned_on_read(): void {
		update_option(
			'aafm_playbooks',
			array(
				array(
					'slug'  => 'x',
					'title' => str_repeat( 'a', 300 ),
					'body'  => 'b',
				),
				'junk',
				array(
					'slug'  => 'y',
					'title' => 'Y',
					'body'  => "a\r\nb",
				),
			)
		);
		$list = aafm_playbooks();
		$this->assertCount( 2, $list );
		$this->assertSame( 80, mb_strlen( $list[0]['title'] ) );
		$this->assertSame( "a\nb", $list[1]['body'] );
	}

	public function test_save_certifies_under_a_stale_cache_entry(): void {
		wp_cache_set(
			'aafm_playbooks',
			array(
				array(
					'slug'  => 'stale',
					'title' => 'Stale',
					'body'  => 'old',
				),
			),
			'options'
		);
		$saved = aafm_save_playbook( $this->fields() );
		$row   = $this->db_row();
		$this->assertSame( array( $saved['slug'] ), array_column( $row, 'slug' ) );

		$this->assertTrue( aafm_delete_playbook( $saved['slug'] ) );
		$this->assertSame( array(), aafm_playbooks() );
	}

	public function test_stale_cache_after_delete_never_serves(): void {
		$saved = aafm_save_playbook( $this->fields() );
		$old   = get_option( 'aafm_playbooks' );
		aafm_delete_playbook( $saved['slug'] );
		wp_cache_set( 'aafm_playbooks', $old, 'options' );
		$this->assertSame( array(), aafm_playbooks() );
		$this->assertNull( aafm_playbook( $saved['slug'] ) );

		$saved = aafm_save_playbook( $this->fields() );
		$old   = get_option( 'aafm_playbooks' );
		aafm_save_playbook( $this->fields( array( 'body' => 'new body' ) ), $saved['slug'] );
		wp_cache_set( 'aafm_playbooks', $old, 'options' );
		$this->assertSame( 'new body', aafm_playbook( $saved['slug'] )['body'] );
	}

	public function test_unreadable_row_fails_closed(): void {
		aafm_save_playbook( $this->fields() );
		$this->break_row_reads();
		ob_start();
		$list = aafm_playbooks();
		$read = aafm_playbooks_read();
		ob_end_clean();
		$this->assertSame( array(), $list );
		$this->assertFalse( $read['ok'] );
	}

	public function test_a_failed_read_never_overwrites_the_library(): void {
		$first  = aafm_save_playbook( $this->fields() );
		$second = aafm_save_playbook( $this->fields( array( 'title' => 'Second' ) ) );
		$before = $this->db_row();

		$this->break_row_reads();
		ob_start();
		$create = aafm_save_playbook( $this->fields( array( 'title' => 'Third' ) ) );
		$update = aafm_save_playbook( $this->fields( array( 'body' => 'changed' ) ), $first['slug'] );
		$delete = aafm_delete_playbook( $second['slug'] );
		ob_end_clean();
		remove_all_filters( 'query' );

		foreach ( array( $create, $update, $delete ) as $result ) {
			$this->assertWPError( $result );
			$this->assertSame( 'aafm_playbook_not_saved', $result->get_error_code() );
		}
		$this->assertSame( $before, $this->db_row() );
	}

	public function test_create_appends_to_the_row_as_it_is_at_write_time(): void {
		aafm_save_playbook( $this->fields( array( 'title' => 'One' ) ) );
		aafm_save_playbook( $this->fields( array( 'title' => 'Two' ) ) );
		$list   = get_option( 'aafm_playbooks' );
		$list[] = array(
			'slug'  => 'three',
			'title' => 'Three',
			'body'  => 'b',
		);
		update_option( 'aafm_playbooks', $list, false );
		aafm_save_playbook( $this->fields( array( 'title' => 'Four' ) ) );
		$this->assertSame( array( 'one', 'two', 'three', 'four' ), array_column( $this->db_row(), 'slug' ) );

		$list   = get_option( 'aafm_playbooks' );
		$list[] = array(
			'slug'  => 'five',
			'title' => 'Five',
			'body'  => 'b',
		);
		update_option( 'aafm_playbooks', $list, false );
		aafm_save_playbook(
			$this->fields(
				array(
					'title' => 'One',
					'body'  => 'edited',
				)
			),
			'one'
		);
		aafm_delete_playbook( 'two' );
		$this->assertSame( array( 'one', 'three', 'four', 'five' ), array_column( $this->db_row(), 'slug' ) );
		$this->assertSame( 'edited', aafm_playbook( 'one' )['body'] );
	}

	public function test_failed_write_is_reported(): void {
		add_filter(
			'pre_update_option_aafm_playbooks',
			static function ( $value, $old ) {
				unset( $value );
				return $old;
			},
			10,
			2
		);
		$result = aafm_save_playbook( $this->fields( array( 'body' => 'secret step text' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'aafm_playbook_not_saved', $result->get_error_code() );
		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertStringContainsString( 'publish-checklist', (string) $rows[0]['detail'] );
		$this->assertStringNotContainsString( 'Publish checklist', (string) $rows[0]['detail'] );
		$this->assertStringNotContainsString( 'secret step text', (string) $rows[0]['detail'] );
	}

	public function test_reset_and_uninstall_clear_the_row(): void {
		$this->assertContains( 'aafm_playbooks', aafm_config_option_names() );
	}

	public function test_change_rows_name_the_slug_not_the_text(): void {
		aafm_save_playbook(
			$this->fields(
				array(
					'title' => 'Quarterly Zebra Audit',
					'body'  => 'secret step text',
				)
			)
		);
		aafm_save_playbook(
			$this->fields(
				array(
					'title' => 'Quarterly Zebra Audit',
					'body'  => 'secret step text 2',
				)
			),
			'quarterly-zebra-audit'
		);
		aafm_delete_playbook( 'quarterly-zebra-audit' );
		$rows = $this->log_rows();
		$this->assertCount( 3, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'setting_changed', $row['event_type'] );
			$this->assertSame( 'success', $row['status'] );
			$this->assertStringContainsString( 'quarterly-zebra-audit', (string) $row['detail'] );
			$this->assertStringNotContainsString( 'Quarterly Zebra Audit', (string) $row['detail'] );
			$this->assertStringNotContainsString( 'secret step text', (string) $row['detail'] );
		}
	}

	public function test_a_corrupt_row_is_a_failed_read_and_never_overwritten(): void {
		update_option( 'aafm_playbooks', 'not a list', false );
		$this->assertFalse( aafm_playbooks_read()['ok'] );
		$this->assertSame( array(), aafm_playbooks() );
		$result = aafm_save_playbook( $this->fields() );
		$this->assertWPError( $result );
		$this->assertSame( 'aafm_playbook_not_saved', $result->get_error_code() );
		$this->assertSame( 'not a list', $this->db_row() );
	}
}
