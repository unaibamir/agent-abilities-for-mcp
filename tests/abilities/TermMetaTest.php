<?php
/**
 * Governed term-meta: default-deny allowlist (aafm_allowed_term_meta_keys), the hard-block
 * floor (reused from post-meta), scalar-only values, and per-object edit_term gating.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class TermMetaTest extends TestCase {

	public function test_allowlist_defaults_empty_then_opts_in(): void {
		$this->assertSame( array(), aafm_allowed_term_meta_keys() );
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->assertSame( array( 'seo_title' ), aafm_allowed_term_meta_keys() );
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	public function test_validate_key_rejects_unlisted_and_hard_blocked(): void {
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->assertSame( 'seo_title', aafm_validate_term_meta_key( 'seo_title' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( 'unlisted' ) );
		// A `_`-prefixed protected key is hard-blocked even if someone tried to allowlist it.
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( '_secret' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( '_secret' ) );
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	public function test_sanitize_value_refuses_non_scalar(): void {
		$this->assertInstanceOf( WP_Error::class, aafm_sanitize_term_meta_value( 'seo_title', array( 'x' => 1 ) ) );
		$this->assertSame( 'hello', aafm_sanitize_term_meta_value( 'seo_title', 'hello' ) );
	}

	public function test_get_term_meta_happy_path_and_gates(): void {
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term_id, 'seo_title', 'Hello' );

		$this->assertTrue(
			aafm_perm_get_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertSame(
			array(
				'term_id'  => (int) $term_id,
				'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'Hello',
			),
			aafm_exec_get_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		// Non-allowlisted key is denied at the gate.
		$this->assertFalse(
			aafm_perm_get_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'unlisted', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	public function test_get_term_meta_requires_edit_term(): void {
		// EDIT 2: term meta can hold private data, so the read requires per-object edit_term
		// (mirroring get-post-meta's edit_post gate). A low-cap user on a locked taxonomy is denied.
		register_taxonomy(
			'aafm_readlock',
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'edit_terms' => 'manage_options' ),
			)
		);
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' ); // lacks manage_options, so cannot edit_term here.
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'aafm_readlock' ) );
		$this->assertFalse(
			aafm_perm_get_term_meta(
				array(
					'taxonomy' => 'aafm_readlock',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
		unregister_taxonomy( 'aafm_readlock' );
	}

	public function test_get_term_meta_refuses_non_scalar(): void {
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'blob' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term_id, 'blob', array( 'x' => 1 ) );
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_get_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'blob', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	public function test_update_term_meta_writes_allowlisted_scalar(): void {
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$result = aafm_exec_update_term_meta(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'New title',
			)
		);
		$this->assertSame( 'New title', $result['value'] );
		$this->assertSame( 'New title', get_term_meta( $term_id, 'seo_title', true ) );
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	public function test_update_term_meta_rejects_non_allowlisted_key(): void {
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		// Empty allowlist (default-deny): every key is rejected.
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_update_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'anything', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
	}

	public function test_update_term_meta_denied_for_low_cap_user(): void {
		// Decouple edit_terms from the editor's caps with a custom taxonomy.
		register_taxonomy(
			'aafm_locked',
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'edit_terms' => 'manage_options' ),
			)
		);
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' ); // lacks manage_options.
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'aafm_locked' ) );
		$this->assertFalse(
			aafm_perm_update_term_meta(
				array(
					'taxonomy' => 'aafm_locked',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
		unregister_taxonomy( 'aafm_locked' );
	}

	public function test_delete_term_meta_removes_allowlisted_key(): void {
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term_id, 'seo_title', 'Bye' );

		$this->assertSame(
			array(
				'deleted'      => true,
				'status'       => 'deleted',
				'previous'     => 'Bye',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => false,
					'count'  => 0,
				),
			),
			aafm_exec_delete_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertSame( '', get_term_meta( $term_id, 'seo_title', true ) );
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	public function test_delete_term_meta_denied_for_low_cap_user(): void {
		register_taxonomy(
			'aafm_locked2',
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'edit_terms' => 'manage_options' ),
			)
		);
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'aafm_locked2' ) );
		$this->assertFalse(
			aafm_perm_delete_term_meta(
				array(
					'taxonomy' => 'aafm_locked2',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
		unregister_taxonomy( 'aafm_locked2' );
	}

	public function test_term_meta_writes_discoverable_to_term_editors(): void {
		$this->acting_as( 'editor' );
		foreach ( array( 'aafm/get-term-meta', 'aafm/update-term-meta', 'aafm/delete-term-meta' ) as $name ) {
			$predicate = aafm_ability_list_permission( $name );
			$this->assertIsCallable( $predicate, $name . ' needs a discovery override (object-dependent gate)' );
			$this->assertTrue( $predicate(), $name . ' should be discoverable to an editor' );
		}
		$this->acting_as( 'subscriber' );
		foreach ( array( 'aafm/update-term-meta', 'aafm/delete-term-meta' ) as $name ) {
			$predicate = aafm_ability_list_permission( $name );
			$this->assertFalse( $predicate(), $name . ' must be hidden from a low-cap user' );
		}
	}

	public function test_hard_blocked_key_rejected_even_if_allowlisted(): void {
		// A filter cannot un-block a protected key: it is stripped after the filter.
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( '_edit_lock' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->assertFalse(
			aafm_perm_update_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => '_edit_lock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	public function test_update_term_meta_refuses_non_scalar_value(): void {
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_update_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => array( 'x' => 1 ),
				)
			)
		);
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	/**
	 * Codex round 5 R5-2: the write-confirmation guard only checked `false ===
	 * update_term_meta(...)`, so a metadata filter that short-circuits update_term_metadata to a
	 * truthy value bypassed the write entirely while the guard never noticed - the write reported
	 * success and returned the old stored value.
	 */
	public function test_update_term_meta_returns_an_error_when_the_write_is_vetoed(): void {
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term_id, 'seo_title', 'old value' );

		$veto = static fn() => true;
		add_filter( 'update_term_metadata', $veto, 10, 0 );
		$out  = aafm_exec_update_term_meta(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'new value',
			)
		);
		remove_filter( 'update_term_metadata', $veto, 10 );
		remove_all_filters( 'aafm_allowed_term_meta_keys' );

		$this->assertInstanceOf(
			WP_Error::class,
			$out,
			'A vetoed term meta write must return an error, not a success reporting the old value.'
		);
	}

	/**
	 * A term with `aafm_note` allowlisted, acting as a user who may edit its meta.
	 *
	 * @return int Object id.
	 */
	private function note_term(): int {
		update_option( 'aafm_exposed_term_meta_keys', array( 'aafm_note' ) );
		$this->acting_as( 'editor' );
		return (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
	}

	/**
	 * Run update-term-meta for `aafm_note`.
	 *
	 * @param int   $id    Object id.
	 * @param mixed $value Value.
	 * @return array<string,mixed>|WP_Error
	 */
	private function update_note( int $id, $value ) {
		return aafm_exec_update_term_meta(
			array(
				'taxonomy' => 'category',
				'term_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => $value,
			)
		);
	}

	/**
	 * Run delete-term-meta for `aafm_note`.
	 *
	 * @param int $id Object id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function delete_note( int $id ) {
		return aafm_exec_delete_term_meta(
			array(
				'taxonomy' => 'category',
				'term_id'  => $id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
			)
		);
	}

	/**
	 * Assert an error with the ability's code, the status's message and identifier-only data.
	 *
	 * @param mixed  $out     The ability result.
	 * @param string $status  Expected status.
	 * @param string $message Expected message.
	 * @param int    $id      Object id.
	 */
	private function assert_meta_error( $out, string $status, string $message, int $id ): void {
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertSame( $message, $out->get_error_message() );
		$this->assertSame(
			array(
				'status'    => $status,
				'kind'      => 'term_meta',
				'object_id' => $id,
				'key'       => 'aafm_note',
			),
			$out->get_error_data()
		);
	}

	public function test_update_term_meta_reports_written_with_the_previous_value(): void {
		$id = $this->note_term();
		update_term_meta( $id, 'aafm_note', 'old' );

		$this->assertSame(
			array(
				'term_id'      => $id,
				'meta_key'     => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'        => 'new',
				'status'       => 'written',
				'previous'     => 'old',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => true,
					'count'  => 1,
				),
			),
			$this->update_note( $id, 'new' )
		);
	}

	public function test_update_term_meta_under_a_veto_false_filter_returns_the_refused_error(): void {
		$id = $this->note_term();
		update_term_meta( $id, 'aafm_note', 'old' );
		add_filter( 'update_term_metadata', '__return_false' );
		$out = $this->update_note( $id, 'new' );
		remove_filter( 'update_term_metadata', '__return_false' );

		$this->assert_meta_error( $out, 'refused', 'The site refused or failed the write; read the key to see its current state.', $id );
		$this->assertSame( 'old', get_term_meta( $id, 'aafm_note', true ) );
	}

	public function test_update_term_meta_under_a_veto_true_filter_returns_the_unconfirmed_error(): void {
		$id = $this->note_term();
		update_term_meta( $id, 'aafm_note', 'old' );
		add_filter( 'update_term_metadata', '__return_true' );
		$out = $this->update_note( $id, 'new' );
		remove_filter( 'update_term_metadata', '__return_true' );

		$this->assert_meta_error( $out, 'unconfirmed', 'The write could not be confirmed; read the key to see its current state.', $id );
		$this->assertSame( 'old', get_term_meta( $id, 'aafm_note', true ) );
	}

	public function test_update_term_meta_with_a_failed_read_back_returns_the_unconfirmed_error(): void {
		global $wpdb;
		$id = $this->note_term();
		update_term_meta( $id, 'aafm_note', 'old' );

		$table      = $wpdb->termmeta;
		$suppressed = $wpdb->suppress_errors( true );
		$armed      = false;
		$arm        = static function () use ( &$armed ): void {
			$armed = true;
		};
		add_action( 'updated_term_meta', $arm );
		$fail = static function ( string $query ) use ( &$armed, $table ): string {
			return ( $armed && false !== strpos( $query, $table ) && 0 === stripos( ltrim( $query ), 'SELECT' ) ) ? '' : $query;
		};
		add_filter( 'query', $fail );
		ob_start();
		$out = $this->update_note( $id, 'new' );
		ob_end_clean();
		remove_filter( 'query', $fail );
		remove_action( 'updated_term_meta', $arm );
		$wpdb->suppress_errors( $suppressed );

		$this->assert_meta_error( $out, 'unconfirmed', 'The write could not be confirmed; read the key to see its current state.', $id );
		wp_cache_delete( $id, 'term_meta' );
		$this->assertSame( 'new', get_term_meta( $id, 'aafm_note', true ), 'the write itself landed' );
	}

	public function test_delete_term_meta_reports_deleted_and_then_absent(): void {
		$id = $this->note_term();
		update_term_meta( $id, 'aafm_note', 'old' );

		$this->assertSame(
			array(
				'deleted'      => true,
				'status'       => 'deleted',
				'previous'     => 'old',
				'acknowledged' => true,
				'observed'     => array(
					'exists' => false,
					'count'  => 0,
				),
			),
			$this->delete_note( $id )
		);
		$this->assertSame(
			array(
				'deleted' => true,
				'status'  => 'absent',
			),
			$this->delete_note( $id )
		);
	}

	public function test_delete_term_meta_with_a_surviving_row_returns_the_refused_error(): void {
		$id = $this->note_term();
		update_term_meta( $id, 'aafm_note', 'old' );
		add_filter( 'delete_term_metadata', '__return_true' );
		$out = $this->delete_note( $id );
		remove_filter( 'delete_term_metadata', '__return_true' );

		$this->assert_meta_error( $out, 'refused', 'The site refused or failed the delete; read the key to see its current state.', $id );
		$this->assertSame( 'old', get_term_meta( $id, 'aafm_note', true ) );
	}

	public function test_delete_term_meta_with_a_failed_baseline_read_returns_the_read_failed_error(): void {
		global $wpdb;
		$id = $this->note_term();
		update_term_meta( $id, 'aafm_note', 'old' );

		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$out = \AAFM\Tests\Support\QueryFaultInjector::fail_query(
			$wpdb->termmeta,
			function () use ( $id ) {
				return $this->delete_note( $id );
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		$this->assert_meta_error( $out, 'read_failed', 'The current value could not be read, so nothing was deleted. Try again.', $id );
		wp_cache_delete( $id, 'term_meta' );
		$this->assertSame( 'old', get_term_meta( $id, 'aafm_note', true ) );
	}

	/**
	 * `value` is read through core after the write, exactly as get_term_meta( ..., true )
	 * reads it, so a read filter shapes it as it always has, on written and on unchanged.
	 */
	public function test_update_term_meta_value_is_read_through_core_with_its_filters(): void {
		global $wpdb;
		$id = $this->note_term();
		update_term_meta( $id, 'aafm_note', '7' );
		$table  = $wpdb->termmeta;
		$column = 'term_id';
		$as_int = static function ( $value, $object_id, $meta_key, $single ) use ( $wpdb, $table, $column ) {
			if ( 'aafm_note' !== $meta_key ) {
				return $value;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE %i = %d AND meta_key = %s', $table, $column, $object_id, $meta_key ) );
			if ( null === $stored ) {
				return $value;
			}
			return $single ? (int) $stored : array( (int) $stored );
		};
		add_filter( 'get_term_metadata', $as_int, 10, 4 );

		$unchanged = $this->update_note( $id, '7' );
		$written   = $this->update_note( $id, '8' );
		$core      = get_term_meta( $id, 'aafm_note', true );

		remove_filter( 'get_term_metadata', $as_int, 10 );

		$this->assertSame( 'unchanged', $unchanged['status'] );
		$this->assertSame( 7, $unchanged['value'] );
		$this->assertSame( 'written', $written['status'] );
		$this->assertSame( 8, $written['value'] );
		$this->assertSame( $core, $written['value'] );
	}

	/**
	 * A failed load on that response read is an error, never a made-up value.
	 */
	public function test_update_term_meta_with_its_response_read_faulted_returns_the_unconfirmed_error(): void {
		global $wpdb;
		foreach ( array( 'no-flush', 'real-error' ) as $shape ) {
			$id = $this->note_term();
			update_term_meta( $id, 'aafm_note', 'old' );
			$run        = function () use ( $id ) {
				return $this->update_note( $id, 'old' );
			};
			$needle     = array( 'meta_key, meta_value FROM', $wpdb->termmeta, ' IN (' );
			$suppressed = $wpdb->suppress_errors( true );
			\AAFM\Tests\Support\QueryFaultInjector::reset_fired_count();
			ob_start();
			$out = 'no-flush' === $shape
				? \AAFM\Tests\Support\QueryFaultInjector::fail_query( $needle, $run )
				: \AAFM\Tests\Support\QueryFaultInjector::break_query_with_real_error( $needle, $run );
			ob_end_clean();
			$wpdb->suppress_errors( $suppressed );

			$this->assertGreaterThan( 0, \AAFM\Tests\Support\QueryFaultInjector::fired_count(), $shape );
			$this->assert_meta_error( $out, 'unconfirmed', 'The write could not be confirmed; read the key to see its current state.', $id );
		}
	}

	public function test_update_term_meta_with_a_failed_baseline_read_returns_the_read_failed_error(): void {
		global $wpdb;
		$id = $this->note_term();
		update_term_meta( $id, 'aafm_note', 'old' );

		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$out = \AAFM\Tests\Support\QueryFaultInjector::fail_query(
			$wpdb->termmeta,
			function () use ( $id ) {
				return $this->update_note( $id, 'new' );
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		$this->assert_meta_error( $out, 'read_failed', 'The current value could not be read, so nothing was written. Try again.', $id );
		wp_cache_delete( $id, 'term_meta' );
		$this->assertSame( 'old', get_term_meta( $id, 'aafm_note', true ) );
	}

	/**
	 * A key that fails the activity-log key rule reaches error_data as null, still present, so an
	 * agent-supplied string never lands in the error log.
	 */
	public function test_a_term_meta_error_for_a_malformed_key_carries_a_null_key(): void {
		update_option( 'aafm_exposed_term_meta_keys', array( '*' ) );
		$this->acting_as( 'editor' );
		$id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		add_filter( 'update_term_metadata', '__return_false' );
		$out = aafm_exec_update_term_meta(
			array(
				'taxonomy' => 'category',
				'term_id'  => $id,
				'meta_key' => 'bad key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'x',
			)
		);
		remove_filter( 'update_term_metadata', '__return_false' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame(
			array(
				'status'    => 'refused',
				'kind'      => 'term_meta',
				'object_id' => $id,
				'key'       => null,
			),
			$out->get_error_data()
		);
	}
}
