<?php
/**
 * A change to an exposed list (content types, post, user or term meta keys) writes one
 * setting_changed Activity Log row: a label and two counts, never a key name. The row is written
 * where the certified write is, so the combined save and the five old actions log the same thing.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class ExposedListChangeLogTest extends TestCase {

	private const OPTIONS = array(
		'aafm_denied_meta_keys',
		'aafm_allowed_meta_keys',
		'aafm_denied_user_meta_keys',
		'aafm_exposed_user_meta_keys',
		'aafm_denied_term_meta_keys',
		'aafm_exposed_term_meta_keys',
		'aafm_allowed_post_types',
	);

	public function set_up(): void {
		parent::set_up();
		register_post_type(
			'aafm_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);
		register_post_type(
			'aafm_film',
			array(
				'public' => true,
				'label'  => 'Films',
			)
		);
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_actions( 'added_option' );
		remove_all_actions( 'updated_option' );
		remove_all_actions( 'deleted_option' );
		remove_all_filters( 'query' );
		$_POST    = array();
		$_REQUEST = array();
		wp_cache_delete( 'alloptions', 'options' );
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		parent::tear_down();
	}

	/**
	 * Keep $option's row at a given list whatever is written to it.
	 *
	 * @param string            $option Option name.
	 * @param array<int,string> $value  The stuck list.
	 */
	private function stuck_at( string $option, array $value ): void {
		$raw    = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE.
		$revert = static function () use ( $option, $raw ): void {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"REPLACE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'yes')",
					$option,
					$raw
				)
			);
		};
		$guard  = static function ( $changed ) use ( $option, $revert ): void {
			if ( $changed === $option ) {
				$revert();
			}
		};
		add_action( 'added_option', $guard );
		add_action( 'updated_option', $guard );
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
	 * Run an AJAX handler with $fields posted and return the decoded body. wpdb's error output is
	 * hidden while it runs because the fault injection breaks a query on purpose.
	 *
	 * @param string              $handler Handler function name.
	 * @param array<string,mixed> $fields  Posted fields.
	 * @return array<string,mixed>
	 */
	private function post_handler( string $handler, array $fields ): array {
		$this->assertTrue( is_callable( $handler ), "The handler {$handler}() must exist." );
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST             = array_merge( array( 'nonce' => $nonce ), $fields );
		$_REQUEST['nonce'] = $nonce;
		$this->intercept_die();
		global $wpdb;
		$had_errors_shown = $wpdb->hide_errors();
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$wpdb->show_errors( $had_errors_shown );
		remove_all_filters( 'query' );
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Post $fields to the combined action, naming $sections.
	 *
	 * @param array<string,mixed> $fields   Posted fields.
	 * @param array<int,string>   $sections Sections to name.
	 * @return array<string,mixed>
	 */
	private function save( array $fields, array $sections ): array {
		return $this->post_handler( 'aafm_ajax_save_abilities_page', array_merge( $fields, array( 'aafm_sections' => $sections ) ) );
	}

	/**
	 * Every setting_changed Activity Log row, oldest first.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function change_rows(): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT ability, status, event_type, detail FROM %i WHERE event_type = %s ORDER BY id', aafm_activity_log_table(), 'setting_changed' ),
			ARRAY_A
		);
	}

	/**
	 * The setting_changed rows with status success.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function success_rows(): array {
		return array_values( array_filter( $this->change_rows(), static fn( array $row ): bool => 'success' === $row['status'] ) );
	}

	/**
	 * Each exposed list: section, option, old list, fields, expected detail.
	 *
	 * @return array<string,array{0:string,1:string,2:array<int,string>,3:array<string,mixed>,4:string}>
	 */
	public function list_provider(): array {
		return array(
			'post meta'  => array(
				'meta_keys',
				'aafm_allowed_meta_keys',
				array( 'k1', 'k2' ),
				array(
					'aafm_meta_keys'      => "k2\nk3\nk4",
					'aafm_deny_meta_keys' => '',
				),
				'Exposed post meta keys changed: 2 added, 1 removed',
			),
			'user meta'  => array(
				'user_meta_keys',
				'aafm_exposed_user_meta_keys',
				array( 'k1' ),
				array(
					'aafm_exposed_user_meta_keys' => "k2\nk3",
					'aafm_denied_user_meta_keys'  => '',
				),
				'Exposed user meta keys changed: 2 added, 1 removed',
			),
			'term meta'  => array(
				'term_meta_keys',
				'aafm_exposed_term_meta_keys',
				array(),
				array(
					'aafm_exposed_term_meta_keys' => 'k1',
					'aafm_denied_term_meta_keys'  => '',
				),
				'Exposed term meta keys changed: 1 added, 0 removed',
			),
			'post types' => array(
				'post_types',
				'aafm_allowed_post_types',
				array( 'aafm_film' ),
				array( 'aafm_post_types' => array( '', 'aafm_book' ) ),
				'Exposed content types changed: 1 added, 1 removed',
			),
		);
	}

	/**
	 * A changed exposed list writes exactly one row naming the list and the two counts.
	 *
	 * @dataProvider list_provider
	 * @param string              $section Section name.
	 * @param string              $option  Option the list is stored in.
	 * @param array<int,string>   $old     Old exposed list.
	 * @param array<string,mixed> $fields  Posted fields.
	 * @param string              $detail  Expected row detail.
	 */
	public function test_a_changed_exposed_list_writes_one_setting_changed_row( string $section, string $option, array $old, array $fields, string $detail ): void {
		update_option( $option, $old );

		$json = $this->save( $fields, array( $section ) );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame(
			array(
				array(
					'ability'    => $option,
					'status'     => 'success',
					'event_type' => 'setting_changed',
					'detail'     => $detail,
				),
			),
			$this->change_rows()
		);
	}

	public function test_no_row_when_the_list_is_unchanged_or_only_reordered(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'a', 'b' ) );

		$this->save(
			array(
				'aafm_meta_keys'      => "b\na",
				'aafm_deny_meta_keys' => '',
			),
			array( 'meta_keys' )
		);

		$this->assertSame( array(), $this->change_rows() );
	}

	public function test_no_change_row_when_the_exposed_write_did_not_certify(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'old' ) );
		$this->stuck_at( 'aafm_allowed_meta_keys', array( 'old' ) );

		$this->save(
			array(
				'aafm_meta_keys'      => 'new',
				'aafm_deny_meta_keys' => '',
			),
			array( 'meta_keys' )
		);

		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertSame( 'aafm_allowed_meta_keys', $rows[0]['ability'] );
	}

	public function test_a_stage_three_failure_still_logs_the_exposed_change(): void {
		update_option( 'aafm_denied_meta_keys', array( 'old-deny' ) );
		update_option( 'aafm_allowed_meta_keys', array( 'old' ) );
		$this->stuck_at( 'aafm_denied_meta_keys', array( 'old-deny' ) );

		$this->save(
			array(
				'aafm_meta_keys'      => 'new',
				'aafm_deny_meta_keys' => '',
			),
			array( 'meta_keys' )
		);

		$by_ability = array();
		foreach ( $this->change_rows() as $row ) {
			$by_ability[ $row['ability'] ] = $row['status'];
		}
		$this->assertSame(
			array(
				'aafm_allowed_meta_keys' => 'success',
				'aafm_denied_meta_keys'  => 'error',
			),
			$by_ability
		);
		$this->assertCount( 2, $this->change_rows() );
	}

	public function test_the_detail_never_names_a_key(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_probe_key_old' ) );

		$this->save(
			array(
				'aafm_meta_keys'      => 'aafm_probe_key_q4',
				'aafm_deny_meta_keys' => '',
			),
			array( 'meta_keys' )
		);

		$rows = $this->change_rows();
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertStringNotContainsString( 'aafm_probe_key', $row['detail'] );
		}
	}

	public function test_deny_list_changes_write_no_change_row(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'same' ) );
		update_option( 'aafm_denied_meta_keys', array( 'old-deny' ) );

		$this->save(
			array(
				'aafm_meta_keys'      => 'same',
				'aafm_deny_meta_keys' => 'new-deny',
			),
			array( 'meta_keys' )
		);

		$this->assertSame( array(), $this->success_rows() );
	}

	public function test_a_wrapper_save_logs_the_same_row(): void {
		update_option( 'aafm_allowed_post_types', array( 'aafm_film' ) );

		$json = $this->post_handler( 'aafm_ajax_save_post_types', array( 'aafm_post_types' => array( 'aafm_book' ) ) );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame(
			array(
				array(
					'ability'    => 'aafm_allowed_post_types',
					'status'     => 'success',
					'event_type' => 'setting_changed',
					'detail'     => 'Exposed content types changed: 1 added, 1 removed',
				),
			),
			$this->change_rows()
		);
	}

	public function test_an_unreadable_before_value_skips_the_row_not_the_save(): void {
		update_option( 'aafm_allowed_post_types', array( 'aafm_film' ) );
		add_filter( 'query', QueryFaultInjector::real_error_filter( "option_name = 'aafm_allowed_post_types'", 1 ) );

		$json = $this->save( array( 'aafm_post_types' => array( '', 'aafm_book' ) ), array( 'post_types' ) );

		$this->assertTrue( $json['success'] ?? false, 'The save still certifies.' );
		$this->assertSame( array( 'aafm_book' ), aafm_read_option_views( 'aafm_allowed_post_types' )['db_value'] );
		$this->assertSame( array(), $this->change_rows(), 'No row: the change cannot be stated without a before value.' );
	}

	public function test_a_held_section_writes_no_row(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'old-exp' ) );
		update_option( 'aafm_allowed_post_types', array( 'aafm_film' ) );
		$this->stuck_at( 'aafm_allowed_meta_keys', array( 'old-exp' ) );

		$json = $this->save(
			array(
				'aafm_meta_keys'      => 'new',
				'aafm_deny_meta_keys' => '',
				'aafm_post_types'     => array( '', 'aafm_film', 'aafm_book' ),
			),
			array( 'meta_keys', 'post_types' )
		);

		$this->assertFalse( $json['data']['sections']['post_types']['ok'] ?? true );
		foreach ( $this->change_rows() as $row ) {
			$this->assertNotSame( 'aafm_allowed_post_types', $row['ability'], 'A held section logs nothing, of any status.' );
		}
	}
}
