<?php
/**
 * The Abilities tab's one Save: aafm_save_abilities_page runs every section the request names
 * through the writer it already used, gates before doors, never touches a section it was not
 * given, and refuses (rather than clears) a named section whose fields are missing. The five old
 * actions stay registered and answer exactly as they did.
 *
 * Every expected value is a literal; nothing here asks a production helper what the answer
 * should be. Stored values are read from the database row.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

final class AbilitiesPageSaveTest extends TestCase {

	private const INCOMPLETE = 'This section was not saved because the request was incomplete. Reload the page and try again.';

	private const HOLD_ABILITIES = 'Enabled abilities was not saved because another section on this page failed. Fix that section and save again.';

	private const HOLD_POST_TYPES = 'Exposed content types was not saved because another section on this page failed. Fix that section and save again.';

	private const MSG_A_SUFFIX = ' could not be changed: the site\'s persistent object cache is still returning the old value. Flush the object cache (Redis, Memcached, or your host\'s cache) and save again.';

	/**
	 * The eight options the five sections write, with the value planted before each save.
	 *
	 * @var array<string,list<string>>
	 */
	private const OLD = array(
		'aafm_denied_meta_keys'       => array( 'old-deny' ),
		'aafm_allowed_meta_keys'      => array( 'old-exp' ),
		'aafm_denied_user_meta_keys'  => array( 'old-u-deny' ),
		'aafm_exposed_user_meta_keys' => array( 'old-u-exp' ),
		'aafm_denied_term_meta_keys'  => array( 'old-t-deny' ),
		'aafm_exposed_term_meta_keys' => array( 'old-t-exp' ),
		'aafm_allowed_post_types'     => array( 'aafm_film' ),
		'aafm_enabled_abilities'      => array( 'aafm/get-posts' ),
	);

	/**
	 * What each option holds after the full valid request of fields().
	 *
	 * @var array<string,list<string>>
	 */
	private const NEW = array(
		'aafm_denied_meta_keys'       => array( 'new-deny' ),
		'aafm_allowed_meta_keys'      => array( 'new-exp', 'second-exp' ),
		'aafm_denied_user_meta_keys'  => array( 'u-deny' ),
		'aafm_exposed_user_meta_keys' => array( 'u-exp' ),
		'aafm_denied_term_meta_keys'  => array( 't-deny' ),
		'aafm_exposed_term_meta_keys' => array( 't-exp' ),
		'aafm_allowed_post_types'     => array( 'aafm_book' ),
		'aafm_enabled_abilities'      => array( 'aafm/get-pages' ),
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
		$this->plant( self::OLD );
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_actions( 'added_option' );
		remove_all_actions( 'updated_option' );
		remove_all_actions( 'deleted_option' );
		remove_all_filters( 'query' );
		remove_all_filters( 'aafm_abilities_registry' );
		$_POST    = array();
		$_REQUEST = array();
		wp_cache_delete( 'alloptions', 'options' );
		foreach ( array_keys( self::OLD ) as $option ) {
			delete_option( $option );
		}
		parent::tear_down();
	}

	/**
	 * Write each option's planted value.
	 *
	 * @param array<string,array<int,string>> $values Option => value.
	 */
	private function plant( array $values ): void {
		foreach ( $values as $option => $value ) {
			update_option( $option, $value );
		}
	}

	/**
	 * Whatever is written to $option, a raw query puts the row straight back to $stuck_raw_value, so
	 * the write can never persist (the 1.7.3 persistent-object-cache shape).
	 *
	 * @param string $option          Option name.
	 * @param string $stuck_raw_value Raw (serialized) value the row is kept at.
	 */
	private function make_option_write_unpersistable( string $option, string $stuck_raw_value ): void {
		$revert = static function () use ( $option, $stuck_raw_value ): void {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"REPLACE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'yes')",
					$option,
					$stuck_raw_value
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

	/**
	 * Keep $option's row at a given list whatever is written to it.
	 *
	 * @param string            $option Option name.
	 * @param array<int,string> $value  The stuck list.
	 */
	private function stuck_at( string $option, array $value ): void {
		$this->make_option_write_unpersistable( $option, serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- raw row value for a direct REPLACE.
	}

	/**
	 * Fail every database read of the option's row (not merely read it absent).
	 *
	 * @param string $option Option name.
	 */
	private function fail_option_read( string $option ): void {
		add_filter( 'query', QueryFaultInjector::real_error_filter( "option_name = '{$option}'" ) );
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
	 * Run an AJAX handler and return its raw output. wpdb's own error output is hidden while it
	 * runs: the fault injection breaks a query on purpose and the test bootstrap would otherwise
	 * print an HTML error block into the body being read.
	 *
	 * @param string $handler Handler function name.
	 */
	private function run_raw( string $handler ): string {
		$this->assertTrue( is_callable( $handler ), "The handler {$handler}() must exist." );
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
		return $body;
	}

	/**
	 * Decode a JSON body, or an empty array when it is not one.
	 *
	 * @param string $body Raw body.
	 * @return array<string,mixed>
	 */
	private function decode( string $body ): array {
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Every field the five sections read, with a valid new value.
	 *
	 * @param array<string,mixed> $overrides Fields to replace.
	 * @return array<string,mixed>
	 */
	private function fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'aafm_meta_keys'              => "new-exp\nsecond-exp",
				'aafm_deny_meta_keys'         => 'new-deny',
				'aafm_exposed_user_meta_keys' => 'u-exp',
				'aafm_denied_user_meta_keys'  => 'u-deny',
				'aafm_exposed_term_meta_keys' => 't-exp',
				'aafm_denied_term_meta_keys'  => 't-deny',
				'aafm_post_types'             => array( '', 'aafm_book' ),
				'aafm_abilities'              => array( '', 'aafm/get-pages' ),
				'aafm_scope'                  => array( 'content' ),
			),
			$overrides
		);
	}

	/**
	 * Post $fields to the combined action, naming $sections.
	 *
	 * @param array<string,mixed> $fields   Posted fields.
	 * @param array<int,string>   $sections Section names to send.
	 * @return array<string,mixed> Decoded body.
	 */
	private function save( array $fields, array $sections ): array {
		return $this->decode( $this->save_raw( $fields, $sections ) );
	}

	/**
	 * Post $fields to the combined action and return the raw body.
	 *
	 * @param array<string,mixed> $fields   Posted fields.
	 * @param array<int,string>   $sections Section names to send.
	 */
	private function save_raw( array $fields, array $sections ): string {
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST             = array_merge( array( 'nonce' => $nonce ), $fields );
		$_REQUEST['nonce'] = $nonce;
		if ( array() !== $sections ) {
			$_POST['aafm_sections'] = $sections;
		}
		$this->intercept_die();
		return $this->run_raw( 'aafm_ajax_save_abilities_page' );
	}

	/**
	 * The database row of an option, bypassing the object cache.
	 *
	 * @param string $option Option name.
	 * @return mixed
	 */
	private function stored( string $option ) {
		remove_all_filters( 'query' );
		return aafm_read_option_views( $option )['db_value'];
	}

	/**
	 * Every Activity Log row, oldest first.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function log_rows(): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ability, status, event_type, detail FROM %i ORDER BY id', aafm_activity_log_table() ), ARRAY_A );
	}

	/**
	 * The Activity Log rows written for one ability or option name.
	 *
	 * @param string $ability Ability or option name.
	 * @return array<int,array<string,string>>
	 */
	private function rows_for( string $ability ): array {
		return array_values(
			array_filter(
				$this->log_rows(),
				static fn( array $row ): bool => $ability === $row['ability']
			)
		);
	}

	/**
	 * The Activity Log rows of one event type.
	 *
	 * @param string $event_type Event type.
	 * @return array<int,array<string,string>>
	 */
	private function rows_of_event( string $event_type ): array {
		return array_values(
			array_filter(
				$this->log_rows(),
				static fn( array $row ): bool => $event_type === $row['event_type']
			)
		);
	}

	/**
	 * Make the post meta pair fail at stage 2 (the exposed write never persists), the earlier
	 * failure the hold on additions reacts to.
	 */
	private function fail_the_post_meta_pair(): void {
		$this->stuck_at( 'aafm_allowed_meta_keys', array( 'old-exp' ) );
	}

	private function assert_options_hold( array $expected ): void {
		foreach ( $expected as $option => $value ) {
			$this->assertSame( $value, $this->stored( $option ), "The row of {$option}." );
		}
	}

	/**
	 * Each section with the options it writes.
	 *
	 * @return array<string,array{0:string,1:array<int,string>}>
	 */
	public function section_provider(): array {
		return array(
			'meta_keys'      => array( 'meta_keys', array( 'aafm_denied_meta_keys', 'aafm_allowed_meta_keys' ) ),
			'user_meta_keys' => array( 'user_meta_keys', array( 'aafm_denied_user_meta_keys', 'aafm_exposed_user_meta_keys' ) ),
			'term_meta_keys' => array( 'term_meta_keys', array( 'aafm_denied_term_meta_keys', 'aafm_exposed_term_meta_keys' ) ),
			'post_types'     => array( 'post_types', array( 'aafm_allowed_post_types' ) ),
			'abilities'      => array( 'abilities', array( 'aafm_enabled_abilities' ) ),
		);
	}

	/**
	 * A section named alone writes its own options and nothing else.
	 *
	 * @dataProvider section_provider
	 * @param string            $section     Section name.
	 * @param array<int,string> $own_options Options the section writes.
	 */
	public function test_each_section_alone_writes_only_its_own_options( string $section, array $own_options ): void {
		$json = $this->save( $this->fields(), array( $section ) );

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame( array( $section ), array_keys( $json['data']['sections'] ?? array() ) );
		foreach ( self::NEW as $option => $new_value ) {
			$expected = in_array( $option, $own_options, true ) ? $new_value : self::OLD[ $option ];
			$this->assertSame( $expected, $this->stored( $option ), "The row of {$option} after naming only {$section}." );
		}
	}

	public function test_all_sections_together_persist_and_report_ok(): void {
		$json = $this->save( $this->fields(), array( 'abilities', 'term_meta_keys', 'post_types', 'meta_keys', 'user_meta_keys' ) );

		$this->assertTrue( $json['success'] ?? false );
		$sections = $json['data']['sections'] ?? array();
		$this->assertSame( array( 'meta_keys', 'user_meta_keys', 'term_meta_keys', 'post_types', 'abilities' ), array_keys( $sections ), 'Results come back in write order, whatever order the request named them in.' );
		foreach ( $sections as $key => $result ) {
			$this->assertTrue( $result['ok'], "Section {$key} ok." );
			$this->assertSame( '', $result['message'], "Section {$key} message." );
		}
		$this->assert_options_hold( self::NEW );
	}

	public function test_an_unnamed_section_is_never_touched(): void {
		$json = $this->save( $this->fields(), array( 'meta_keys' ) );

		$this->assertTrue( $json['success'] ?? false );
		$this->assert_options_hold(
			array_merge(
				self::OLD,
				array(
					'aafm_denied_meta_keys'  => array( 'new-deny' ),
					'aafm_allowed_meta_keys' => array( 'new-exp', 'second-exp' ),
				)
			)
		);
	}

	public function test_a_named_meta_section_without_its_deny_field_is_refused_not_cleared(): void {
		$fields = $this->fields();
		unset( $fields['aafm_deny_meta_keys'] );
		$json = $this->save( $fields, array( 'meta_keys' ) );

		$this->assertFalse( $json['success'] ?? true );
		$result = $json['data']['sections']['meta_keys'] ?? array();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( self::INCOMPLETE, $result['message'] );
		$this->assertSame( array(), $result['data'] );
		$this->assert_options_hold( self::OLD );
	}

	public function test_a_textarea_posted_as_an_array_is_refused(): void {
		$json = $this->save( $this->fields( array( 'aafm_meta_keys' => array( 'x' ) ) ), array( 'meta_keys' ) );

		$this->assertFalse( $json['data']['sections']['meta_keys']['ok'] ?? true );
		$this->assertSame( self::INCOMPLETE, $json['data']['sections']['meta_keys']['message'] ?? '' );
		$this->assert_options_hold( self::OLD );
	}

	public function test_a_list_field_posted_as_a_string_is_refused(): void {
		$json = $this->save( $this->fields( array( 'aafm_post_types' => 'aafm_book' ) ), array( 'post_types' ) );

		$this->assertSame( self::INCOMPLETE, $json['data']['sections']['post_types']['message'] ?? '' );
		$this->assert_options_hold( self::OLD );
	}

	public function test_emptied_lists_clear_when_named(): void {
		add_filter(
			'aafm_abilities_registry',
			static function ( array $registry ): array {
				$registry['aafm/fake-wc'] = array(
					'subject' => 'woocommerce',
					'risk'    => 'read',
				);
				return $registry;
			}
		);
		aafm_registry_cache_should_flush( true );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/fake-wc' ) );

		$json = $this->save(
			$this->fields(
				array(
					'aafm_meta_keys'              => '',
					'aafm_deny_meta_keys'         => '',
					'aafm_exposed_user_meta_keys' => '',
					'aafm_denied_user_meta_keys'  => '',
					'aafm_exposed_term_meta_keys' => '',
					'aafm_denied_term_meta_keys'  => '',
					'aafm_post_types'             => array( '' ),
					'aafm_abilities'              => array( '' ),
				)
			),
			array( 'meta_keys', 'user_meta_keys', 'term_meta_keys', 'post_types', 'abilities' )
		);

		$this->assertTrue( $json['success'] ?? false );
		$this->assert_options_hold(
			array(
				'aafm_denied_meta_keys'       => array(),
				'aafm_allowed_meta_keys'      => array(),
				'aafm_denied_user_meta_keys'  => array(),
				'aafm_exposed_user_meta_keys' => array(),
				'aafm_denied_term_meta_keys'  => array(),
				'aafm_exposed_term_meta_keys' => array(),
				'aafm_allowed_post_types'     => array(),
				'aafm_enabled_abilities'      => array( 'aafm/fake-wc' ),
			)
		);
	}

	public function test_unknown_section_names_are_ignored(): void {
		$json = $this->save( $this->fields(), array( 'bogus', 'meta_keys' ) );
		$this->assertSame( array( 'meta_keys' ), array_keys( $json['data']['sections'] ?? array() ) );

		$this->plant( self::OLD );
		$body = $this->save_raw( $this->fields(), array( 'bogus' ) );
		$this->assertSame( '{"success":true,"data":{"sections":[]}}', $body );
		$this->assert_options_hold( self::OLD );
	}

	public function test_no_sections_named_writes_nothing(): void {
		$body = $this->save_raw( $this->fields(), array() );

		$this->assertSame( '{"success":true,"data":{"sections":[]}}', $body );
		$this->assert_options_hold( self::OLD );
	}

	public function test_a_failed_meta_section_does_not_stop_the_other_meta_sections(): void {
		$this->fail_the_post_meta_pair();

		$json = $this->save( $this->fields(), array( 'meta_keys', 'user_meta_keys', 'term_meta_keys' ) );

		$this->assertFalse( $json['success'] ?? true );
		$sections = $json['data']['sections'] ?? array();
		$this->assertFalse( $sections['meta_keys']['ok'] );
		$this->assertStringContainsString( 'Exposed post meta keys', $sections['meta_keys']['message'] );
		$this->assertTrue( $sections['user_meta_keys']['ok'] );
		$this->assertTrue( $sections['term_meta_keys']['ok'] );
		$this->assert_options_hold(
			array(
				'aafm_denied_meta_keys'       => array( 'old-deny', 'new-deny' ),
				'aafm_allowed_meta_keys'      => array( 'old-exp' ),
				'aafm_denied_user_meta_keys'  => array( 'u-deny' ),
				'aafm_exposed_user_meta_keys' => array( 'u-exp' ),
				'aafm_denied_term_meta_keys'  => array( 't-deny' ),
				'aafm_exposed_term_meta_keys' => array( 't-exp' ),
			)
		);
	}

	public function test_stage_one_failure_in_a_combined_request(): void {
		$this->stuck_at( 'aafm_denied_meta_keys', array( 'old-deny' ) );

		$json = $this->save( $this->fields(), array( 'meta_keys' ) );

		$result = $json['data']['sections']['meta_keys'] ?? array();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Denied post meta keys' . self::MSG_A_SUFFIX, $result['message'] );
		$this->assert_options_hold(
			array(
				'aafm_denied_meta_keys'  => array( 'old-deny' ),
				'aafm_allowed_meta_keys' => array( 'old-exp' ),
			)
		);
		$this->assertSame( 'error', $this->rows_for( 'aafm_denied_meta_keys' )[0]['status'] ?? '' );
	}

	public function test_stage_three_failure_in_a_combined_request(): void {
		$this->stuck_at( 'aafm_denied_meta_keys', array( 'old-deny' ) );

		$json = $this->save( $this->fields( array( 'aafm_deny_meta_keys' => '' ) ), array( 'meta_keys' ) );

		$result = $json['data']['sections']['meta_keys'] ?? array();
		$this->assertFalse( $result['ok'] );
		$this->assertStringStartsWith( 'Exposed post meta keys was saved, but Denied post meta keys could not be changed', $result['message'] );
		$this->assert_options_hold(
			array(
				'aafm_denied_meta_keys'  => array( 'old-deny' ),
				'aafm_allowed_meta_keys' => array( 'new-exp', 'second-exp' ),
			)
		);
	}

	public function test_old_deny_read_failure_aborts_the_pair_only(): void {
		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) );
		$this->fail_option_read( 'aafm_denied_meta_keys' );

		$json = $this->save( $this->fields( array( 'aafm_post_types' => array( '' ) ) ), array( 'meta_keys', 'post_types' ) );

		$sections = $json['data']['sections'] ?? array();
		$this->assertFalse( $sections['meta_keys']['ok'] );
		$this->assertTrue( $sections['post_types']['ok'], 'A removal-only content type change is still written after the pair failed.' );
		$this->assert_options_hold(
			array(
				'aafm_denied_meta_keys'   => array( 'old-deny' ),
				'aafm_allowed_meta_keys'  => array( 'old-exp' ),
				'aafm_allowed_post_types' => array(),
			)
		);
	}

	public function test_post_types_cache_failure_is_reported_for_that_section(): void {
		$this->stuck_at( 'aafm_allowed_post_types', array( 'aafm_film' ) );

		$json = $this->save( $this->fields(), array( 'post_types' ) );

		$result = $json['data']['sections']['post_types'] ?? array();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Exposed content types' . self::MSG_A_SUFFIX, $result['message'] );
		$this->assertSame( array( 'aafm_film' ), $this->stored( 'aafm_allowed_post_types' ) );
		$this->assertSame( 'error', $this->rows_for( 'aafm_allowed_post_types' )[0]['status'] ?? '' );
	}

	public function test_abilities_cache_failure_is_reported_for_that_section(): void {
		$this->stuck_at( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );

		$json = $this->save( $this->fields(), array( 'abilities' ) );

		$result = $json['data']['sections']['abilities'] ?? array();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Enabled abilities' . self::MSG_A_SUFFIX, $result['message'] );
		$this->assertSame( array( 'aafm/get-posts' ), $this->stored( 'aafm_enabled_abilities' ) );
		$this->assertSame( array(), $this->rows_of_event( 'ability_enabled' ), 'No toggle row for a change that did not take.' );
		$this->assertSame( 'error', $this->rows_for( 'aafm_enabled_abilities' )[0]['status'] ?? '' );
	}

	public function test_an_added_ability_after_a_failed_meta_pair_is_held(): void {
		$this->fail_the_post_meta_pair();

		$json = $this->save( $this->fields( array( 'aafm_abilities' => array( '', 'aafm/get-posts', 'aafm/get-post-meta' ) ) ), array( 'meta_keys', 'abilities' ) );

		$result = $json['data']['sections']['abilities'] ?? array();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( self::HOLD_ABILITIES, $result['message'] );
		$this->assertSame( array(), $result['data'] );
		$this->assertSame( array( 'aafm/get-posts' ), $this->stored( 'aafm_enabled_abilities' ) );
		$this->assertSame( array(), $this->rows_of_event( 'ability_enabled' ) );
		$this->assertSame( array(), $this->rows_for( 'aafm_enabled_abilities' ), 'A held section logs nothing, not even a persist failure.' );
	}

	public function test_a_removal_only_abilities_change_after_a_failed_meta_pair_is_written(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/get-pages' ) );
		$this->fail_the_post_meta_pair();

		$json = $this->save( $this->fields( array( 'aafm_abilities' => array( '', 'aafm/get-posts' ) ) ), array( 'meta_keys', 'abilities' ) );

		$this->assertTrue( $json['data']['sections']['abilities']['ok'] ?? false );
		$this->assertSame( array( 'aafm/get-posts' ), $this->stored( 'aafm_enabled_abilities' ) );
	}

	public function test_a_mixed_abilities_change_after_a_failed_meta_pair_is_held_whole(): void {
		$this->fail_the_post_meta_pair();

		$json = $this->save( $this->fields( array( 'aafm_abilities' => array( '', 'aafm/get-pages' ) ) ), array( 'meta_keys', 'abilities' ) );

		$this->assertSame( self::HOLD_ABILITIES, $json['data']['sections']['abilities']['message'] ?? '' );
		$this->assertSame( array( 'aafm/get-posts' ), $this->stored( 'aafm_enabled_abilities' ), 'Neither the addition nor the removal was written.' );
	}

	public function test_an_added_post_type_after_a_failed_meta_pair_is_held(): void {
		$this->fail_the_post_meta_pair();

		$json = $this->save( $this->fields( array( 'aafm_post_types' => array( '', 'aafm_film', 'aafm_book' ) ) ), array( 'meta_keys', 'post_types' ) );

		$result = $json['data']['sections']['post_types'] ?? array();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( self::HOLD_POST_TYPES, $result['message'] );
		$this->assertSame( array( 'aafm_film' ), $this->stored( 'aafm_allowed_post_types' ) );
		$this->assertSame( array(), $this->rows_for( 'aafm_allowed_post_types' ) );
	}

	public function test_an_added_ability_after_a_failed_post_types_section_is_held(): void {
		$this->stuck_at( 'aafm_allowed_post_types', array( 'aafm_film' ) );

		$json = $this->save( $this->fields( array( 'aafm_post_types' => array( '', 'aafm_film', 'aafm_book' ) ) ), array( 'post_types', 'abilities' ) );

		$sections = $json['data']['sections'] ?? array();
		$this->assertSame( 'Exposed content types' . self::MSG_A_SUFFIX, $sections['post_types']['message'] ?? '' );
		$this->assertSame( self::HOLD_ABILITIES, $sections['abilities']['message'] ?? '' );
		$this->assertSame( array( 'aafm/get-posts' ), $this->stored( 'aafm_enabled_abilities' ) );
	}

	public function test_a_held_post_types_section_also_holds_an_added_ability(): void {
		$this->fail_the_post_meta_pair();

		$json = $this->save( $this->fields( array( 'aafm_post_types' => array( '', 'aafm_film', 'aafm_book' ) ) ), array( 'meta_keys', 'post_types', 'abilities' ) );

		$sections = $json['data']['sections'] ?? array();
		$this->assertSame( self::HOLD_POST_TYPES, $sections['post_types']['message'] ?? '' );
		$this->assertSame( self::HOLD_ABILITIES, $sections['abilities']['message'] ?? '' );
		$this->assert_options_hold(
			array(
				'aafm_allowed_post_types' => array( 'aafm_film' ),
				'aafm_enabled_abilities'  => array( 'aafm/get-posts' ),
			)
		);
	}

	public function test_an_unreadable_post_types_before_read_counts_as_adding(): void {
		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) );
		$this->fail_the_post_meta_pair();
		$this->fail_option_read( 'aafm_allowed_post_types' );

		$json = $this->save( $this->fields( array( 'aafm_post_types' => array( '' ) ) ), array( 'meta_keys', 'post_types' ) );

		$this->assertSame( self::HOLD_POST_TYPES, $json['data']['sections']['post_types']['message'] ?? '', 'A removal-only change is held when the before read failed.' );
		$this->assertSame( array( 'aafm_book' ), $this->stored( 'aafm_allowed_post_types' ) );
	}

	public function test_an_incomplete_earlier_section_holds_an_added_ability(): void {
		$fields = $this->fields( array( 'aafm_abilities' => array( '', 'aafm/get-posts', 'aafm/get-post-meta' ) ) );
		unset( $fields['aafm_deny_meta_keys'] );

		$json = $this->save( $fields, array( 'meta_keys', 'abilities' ) );

		$sections = $json['data']['sections'] ?? array();
		$this->assertSame( self::INCOMPLETE, $sections['meta_keys']['message'] ?? '' );
		$this->assertSame( self::HOLD_ABILITIES, $sections['abilities']['message'] ?? '' );
		$this->assertSame( array( 'aafm/get-posts' ), $this->stored( 'aafm_enabled_abilities' ) );
	}

	public function test_a_hard_blocked_key_comes_back_removed(): void {
		$json = $this->save( $this->fields( array( 'aafm_meta_keys' => "*\n_yoast_wpseo_title\nfoo" ) ), array( 'meta_keys' ) );

		$result = $json['data']['sections']['meta_keys'] ?? array();
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( '*', 'foo' ), $result['data']['meta_keys'] ?? null );
		$this->assertSame( array( 'new-deny' ), $result['data']['deny_meta_keys'] ?? null );
		$this->assertSame( array( '*', 'foo' ), $this->stored( 'aafm_allowed_meta_keys' ) );
	}

	public function test_write_order_is_gates_before_doors(): void {
		$this->plant(
			array(
				'aafm_denied_meta_keys'       => array( 'a' ),
				'aafm_allowed_meta_keys'      => array(),
				'aafm_denied_user_meta_keys'  => array( 'a' ),
				'aafm_exposed_user_meta_keys' => array(),
				'aafm_denied_term_meta_keys'  => array( 'a' ),
				'aafm_exposed_term_meta_keys' => array(),
			)
		);
		$seen     = array();
		$listener = static function ( $option ) use ( &$seen ): void {
			if ( 0 === strpos( (string) $option, 'aafm_' ) ) {
				$seen[] = $option;
			}
		};
		add_action( 'added_option', $listener );
		add_action( 'updated_option', $listener );

		$json = $this->save(
			$this->fields(
				array(
					'aafm_meta_keys'              => 'x',
					'aafm_deny_meta_keys'         => 'b',
					'aafm_exposed_user_meta_keys' => 'x',
					'aafm_denied_user_meta_keys'  => 'b',
					'aafm_exposed_term_meta_keys' => 'x',
					'aafm_denied_term_meta_keys'  => 'b',
				)
			),
			array( 'abilities', 'post_types', 'term_meta_keys', 'user_meta_keys', 'meta_keys' )
		);

		$this->assertTrue( $json['success'] ?? false );
		$this->assertSame(
			array(
				'aafm_denied_meta_keys',
				'aafm_allowed_meta_keys',
				'aafm_denied_meta_keys',
				'aafm_denied_user_meta_keys',
				'aafm_exposed_user_meta_keys',
				'aafm_denied_user_meta_keys',
				'aafm_denied_term_meta_keys',
				'aafm_exposed_term_meta_keys',
				'aafm_denied_term_meta_keys',
				'aafm_allowed_post_types',
				'aafm_enabled_abilities',
			),
			$seen
		);
	}

	public function test_star_round_trips_through_the_combined_save(): void {
		$json = $this->save(
			$this->fields(
				array(
					'aafm_meta_keys'              => '*',
					'aafm_deny_meta_keys'         => '*',
					'aafm_exposed_user_meta_keys' => '*',
					'aafm_denied_user_meta_keys'  => '*',
				)
			),
			array( 'meta_keys', 'user_meta_keys' )
		);

		$this->assertTrue( $json['success'] ?? false );
		$this->assert_options_hold(
			array(
				'aafm_allowed_meta_keys'      => array( '*' ),
				'aafm_denied_meta_keys'       => array( '*' ),
				'aafm_exposed_user_meta_keys' => array( '*' ),
				'aafm_denied_user_meta_keys'  => array( '*' ),
			)
		);
		ob_start();
		aafm_render_meta_keys_selector();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/<textarea name="aafm_meta_keys"[^>]*>\*<\/textarea>/', $html );
	}

	public function test_a_subscriber_is_refused_before_anything_is_read(): void {
		$this->acting_as( 'subscriber' );

		$body = $this->save_raw( $this->fields(), array( 'meta_keys', 'abilities' ) );

		$this->assertSame( '{"success":false,"data":{"message":"You are not allowed to do this."}}', $body );
		$this->assert_options_hold( self::OLD );
	}

	public function test_a_missing_nonce_writes_nothing(): void {
		$_POST    = array_merge( $this->fields(), array( 'aafm_sections' => array( 'meta_keys', 'abilities' ) ) );
		$_REQUEST = array();
		$this->intercept_die();

		$body = $this->run_raw( 'aafm_ajax_save_abilities_page' );

		$this->assertSame( '', $body, 'check_ajax_referer() dies before the handler answers anything.' );
		$this->assert_options_hold( self::OLD );
		$this->assertSame( array(), $this->log_rows() );
	}

	/**
	 * Each old action: handler, planted options, fields, the option held unpersistable and its
	 * stuck list, and the exact raw body. Responses must not move by a byte (key order included).
	 *
	 * @return array<string,array{0:string,1:array<string,array<int,string>>,2:array<string,mixed>,3:string,4:array<int,string>,5:string}>
	 */
	public function wrapper_provider(): array {
		$msg_a = static fn( string $label ): string => '{"success":false,"data":{"message":"' . $label . self::MSG_A_SUFFIX . '"}}';
		return array(
			'abilities ok'      => array(
				'aafm_ajax_save_abilities',
				array(),
				array(
					'aafm_abilities' => array( 'aafm/get-pages' ),
					'aafm_scope'     => array( 'content' ),
				),
				'',
				array(),
				'{"success":true,"data":{"enabled":["aafm\/get-pages"],"ability_enabled_total":1}}',
			),
			'abilities cache'   => array(
				'aafm_ajax_save_abilities',
				array(),
				array(
					'aafm_abilities' => array( 'aafm/get-pages' ),
					'aafm_scope'     => array( 'content' ),
				),
				'aafm_enabled_abilities',
				array( 'aafm/get-posts' ),
				$msg_a( 'Enabled abilities' ),
			),
			'post types ok'     => array(
				'aafm_ajax_save_post_types',
				array(),
				array( 'aafm_post_types' => array( 'aafm_book' ) ),
				'',
				array(),
				'{"success":true,"data":{"post_types":["aafm_book"]}}',
			),
			'post types cache'  => array(
				'aafm_ajax_save_post_types',
				array(),
				array( 'aafm_post_types' => array( 'aafm_book' ) ),
				'aafm_allowed_post_types',
				array( 'aafm_film' ),
				$msg_a( 'Exposed content types' ),
			),
			'post meta ok'      => array(
				'aafm_ajax_save_meta_keys',
				array(),
				array(
					'aafm_meta_keys'      => "a\nb",
					'aafm_deny_meta_keys' => 'c',
				),
				'',
				array(),
				'{"success":true,"data":{"meta_keys":["a","b"],"deny_meta_keys":["c"]}}',
			),
			'post meta stage 1' => array(
				'aafm_ajax_save_meta_keys',
				array(),
				array(
					'aafm_meta_keys'      => "a\nb",
					'aafm_deny_meta_keys' => 'c',
				),
				'aafm_denied_meta_keys',
				array( 'old-deny' ),
				$msg_a( 'Denied post meta keys' ),
			),
			'user meta ok'      => array(
				'aafm_ajax_save_user_meta_keys',
				array(),
				array(
					'aafm_exposed_user_meta_keys' => 'a',
					'aafm_denied_user_meta_keys'  => 'c',
				),
				'',
				array(),
				'{"success":true,"data":{"exposed_user_meta_keys":["a"],"denied_user_meta_keys":["c"]}}',
			),
			'user meta stage 2' => array(
				'aafm_ajax_save_user_meta_keys',
				array(),
				array(
					'aafm_exposed_user_meta_keys' => 'a',
					'aafm_denied_user_meta_keys'  => 'c',
				),
				'aafm_exposed_user_meta_keys',
				array( 'old-u-exp' ),
				'{"success":false,"data":{"message":"Denied user meta keys was saved, but Exposed user meta keys could not be changed: the site\'s persistent object cache is still returning the old value. The site is now stricter than requested. Flush the object cache (Redis, Memcached, or your host\'s cache) and save again."}}',
			),
			'term meta ok'      => array(
				'aafm_ajax_save_term_meta_keys',
				array(),
				array(
					'aafm_exposed_term_meta_keys' => 'a',
					'aafm_denied_term_meta_keys'  => 'c',
				),
				'',
				array(),
				'{"success":true,"data":{"exposed_term_meta_keys":["a"],"denied_term_meta_keys":["c"]}}',
			),
			'term meta stage 3' => array(
				'aafm_ajax_save_term_meta_keys',
				array(),
				array(
					'aafm_exposed_term_meta_keys' => 'a',
					'aafm_denied_term_meta_keys'  => '',
				),
				'aafm_denied_term_meta_keys',
				array( 'old-t-deny' ),
				'{"success":false,"data":{"message":"Exposed term meta keys was saved, but Denied term meta keys could not be changed: the site\'s persistent object cache is still returning the old value. The site is now stricter than requested. Flush the object cache (Redis, Memcached, or your host\'s cache) and save again."}}',
			),
		);
	}

	/**
	 * Each old action answers with the exact body it always did.
	 *
	 * @dataProvider wrapper_provider
	 * @param string                          $handler       Handler function name.
	 * @param array<string,array<int,string>> $planted       Extra planted options.
	 * @param array<string,mixed>             $fields        Posted fields.
	 * @param string                          $stuck_option  Option held unpersistable ('' for none).
	 * @param array<int,string>               $stuck_value   Its stuck list.
	 * @param string                          $expected_body Exact raw body.
	 */
	public function test_each_old_wrapper_answers_exactly_as_before( string $handler, array $planted, array $fields, string $stuck_option, array $stuck_value, string $expected_body ): void {
		$this->plant( $planted );
		if ( '' !== $stuck_option ) {
			$this->stuck_at( $stuck_option, $stuck_value );
		}
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST             = array_merge( array( 'nonce' => $nonce ), $fields );
		$_REQUEST['nonce'] = $nonce;
		$this->intercept_die();

		$this->assertSame( $expected_body, $this->run_raw( $handler ) );
	}

	public function test_the_meta_wrapper_still_clears_deny_when_the_field_is_absent(): void {
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST             = array(
			'nonce'          => $nonce,
			'aafm_meta_keys' => 'a',
		);
		$_REQUEST['nonce'] = $nonce;
		$this->intercept_die();

		$this->run_raw( 'aafm_ajax_save_meta_keys' );

		$this->assertSame( array(), $this->stored( 'aafm_denied_meta_keys' ), 'The legacy action reads a missing deny field as empty, as it always did.' );
		$this->assertSame( array( 'a' ), $this->stored( 'aafm_allowed_meta_keys' ) );
	}

	public function test_wrappers_are_never_held(): void {
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST             = array(
			'nonce'          => $nonce,
			'aafm_abilities' => array( 'aafm/get-posts', 'aafm/get-pages' ),
			'aafm_scope'     => array( 'content' ),
		);
		$_REQUEST['nonce'] = $nonce;
		$this->intercept_die();

		$body = $this->run_raw( 'aafm_ajax_save_abilities' );

		$this->assertSame( '{"success":true,"data":{"enabled":["aafm\/get-posts","aafm\/get-pages"],"ability_enabled_total":2}}', $body );
		$this->assertSame( array( 'aafm/get-posts', 'aafm/get-pages' ), $this->stored( 'aafm_enabled_abilities' ) );
	}
}
