<?php
/**
 * A revert settles both of its rows by what actually landed: a throwing hook, a failed verifying read, a failed
 * status write, a claim that never settled, and a create whose object other plugins touch at shutdown.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\Support\QueryFaultInjector;

final class UndoSettleTest extends UndoTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
	}

	/**
	 * Run a tool and return its change id.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return int
	 */
	private function change_from( string $ability, array $input ): int {
		$result = $this->run_tool( $ability, $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return (int) ( $result['undo']['change_ids'][0] ?? 0 );
	}

	/**
	 * A post titled A, changed to B by the agent.
	 *
	 * @return array{0:int,1:int} Post id and change id.
	 */
	private function titled_b(): array {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		return array( $post, $change );
	}

	/**
	 * The status of one change row.
	 *
	 * @param int $id Change id.
	 * @return string
	 */
	private function status_of( int $id ): string {
		return (string) aafm_undo_get_view( $id )['row']['status'];
	}

	public function test_a_hook_that_throws_before_the_write_puts_the_row_back_and_a_retry_works(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		list( $post, $change ) = $this->titled_b();
		$rows                  = count( $this->change_rows() );

		$boom = static function (): void {
			throw new \RuntimeException( 'a plugin hook failed' );
		};
		add_filter( 'wp_insert_post_data', $boom );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_filter( 'wp_insert_post_data', $boom );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_failed', $result->get_error_code() );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$this->assertSame( 'ready', $this->status_of( $change ) );
		$this->assertSame( '', (string) aafm_undo_get_view( $change )['row']['reason'], 'The claim time is cleared with the claim.' );
		$this->assertCount( $rows, array_filter( $this->change_rows(), static fn( array $r ): bool => 'aafm/undo-capture' !== $r['ability'] ), 'The recovery row of the failed attempt is removed.' );

		$this->assertIsArray( aafm_undo_apply( $change, 'owner' ), 'The same change can be reverted once the hook is gone.' );
		$this->assertSame( 'A', get_post( $post )->post_title );
	}

	public function test_a_hook_that_throws_after_the_write_landed_settles_both_rows(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_false' );
		list( $post, $change ) = $this->titled_b();

		$boom = static function (): void {
			throw new \RuntimeException( 'a plugin hook failed' );
		};
		add_action( 'save_post', $boom );
		$result = aafm_undo_apply( $change, 'owner' );
		remove_action( 'save_post', $boom );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_failed', $result->get_error_code() );
		$this->assertSame( 'A', get_post( $post )->post_title, 'The write landed before the hook threw.' );
		$this->assertSame( 'reverted', $this->status_of( $change ) );
		$new = (int) aafm_undo_get_view( $change )['row']['reverted_by_change_id'];
		$this->assertGreaterThan( 0, $new );
		$this->assertSame( 'ready', $this->status_of( $new ), 'The record of the landed write can itself be reverted.' );
	}

	public function test_a_fault_is_rethrown_after_settling_when_the_house_filter_says_so(): void {
		add_filter( 'aafm_rethrow_ability_exceptions', '__return_true' );
		list( $post, $change ) = $this->titled_b();

		$boom = static function (): void {
			throw new \RuntimeException( 'a plugin hook failed' );
		};
		add_filter( 'wp_insert_post_data', $boom );
		try {
			aafm_undo_apply( $change, 'owner' );
			$this->fail( 'The fault should have been rethrown.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'a plugin hook failed', $e->getMessage() );
		} finally {
			remove_filter( 'wp_insert_post_data', $boom );
		}
		$this->assertSame( 'ready', $this->status_of( $change ), 'Settled before the rethrow, not stranded in undoing.' );
	}

	public function test_a_failed_verifying_read_keeps_the_recovery_row_and_does_not_claim_a_no_op(): void {
		global $wpdb;
		list( $post, $change ) = $this->titled_b();

		$break = QueryFaultInjector::real_error_filter( 'SELECT `post_title` FROM `' . $wpdb->posts . '`', 2 );
		$arm   = static function () use ( $break ): void {
			add_filter( 'query', $break );
		};
		add_action( 'save_post', $arm );
		ob_start();
		$suppressed = $wpdb->suppress_errors( true );
		$result     = aafm_undo_apply( $change, 'owner' );
		$wpdb->suppress_errors( $suppressed );
		ob_end_clean();
		remove_action( 'save_post', $arm );
		remove_filter( 'query', $break );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_failed', $result->get_error_code() );
		$this->assertSame( 'A', get_post( $post )->post_title, 'The write did land.' );
		$this->assertSame( 'ready', $this->status_of( $change ) );
		$recovery = array_values( array_filter( $this->change_rows(), static fn( array $r ): bool => (int) $r['reverts_change_id'] === $change ) );
		$this->assertCount( 1, $recovery, 'The recovery row stays, because nothing proved the write did not land.' );
		$this->assertSame( 'pending', $recovery[0]['status'] );
	}

	public function test_a_failed_final_status_write_is_reported_and_never_a_clean_success(): void {
		global $wpdb;
		list( $post, $change ) = $this->titled_b();

		ob_start();
		$suppressed = $wpdb->suppress_errors( true );
		$result     = QueryFaultInjector::break_query_with_real_error( array( 'UPDATE', "'reverted'" ), static fn() => aafm_undo_apply( $change, 'owner' ) );
		$wpdb->suppress_errors( $suppressed );
		ob_end_clean();

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_failed', $result->get_error_code() );
		$this->assertSame( 'A', get_post( $post )->post_title );
		$this->assertSame( 'undoing', $this->status_of( $change ), 'The stuck claim is what the stale-claim recovery below picks up.' );
	}

	public function test_a_claim_that_never_settled_is_taken_again_after_the_grace(): void {
		global $wpdb;
		list( $post, $change ) = $this->titled_b();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'undoing', reason = %s WHERE id = %d", aafm_changes_table(), (string) ( time() - AAFM_UNDO_PENDING_GRACE - 30 ), $change ) );

		$this->assertTrue( aafm_undo_row_state( aafm_undo_get_view( $change )['row'], 'owner' )['undoable'] );
		$this->assertIsArray( aafm_undo_apply( $change, 'owner' ) );
		$this->assertSame( 'A', get_post( $post )->post_title );
		$this->assertSame( 'reverted', $this->status_of( $change ) );
	}

	public function test_a_fresh_claim_and_a_claim_with_no_time_are_still_in_flight(): void {
		global $wpdb;
		list( $post, $change ) = $this->titled_b();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'undoing', reason = %s WHERE id = %d", aafm_changes_table(), (string) time(), $change ) );
		$result = aafm_undo_apply( $change, 'owner' );
		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_interrupted', $result->get_error_code() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET reason = '' WHERE id = %d", aafm_changes_table(), $change ) );
		$result = aafm_undo_apply( $change, 'owner' );
		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_interrupted', $result->get_error_code() );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_a_create_whose_ready_update_fails_is_not_announced_as_undoable(): void {
		global $wpdb;
		ob_start();
		$suppressed = $wpdb->suppress_errors( true );
		$result     = QueryFaultInjector::break_query_with_real_error( array( 'UPDATE', "'ready'", 'after_hashes' ), fn() => $this->run_tool( 'aafm/create-post', array( 'title' => 'Fresh' ) ) );
		$wpdb->suppress_errors( $suppressed );
		ob_end_clean();

		$this->assertIsArray( $result );
		$this->assertFalse( $result['undo']['undoable'] );
		$this->assertSame( 'capture_failed', $result['undo']['reason'] );
		$this->assertSame( array(), $result['undo']['change_ids'] );
	}

	/**
	 * Fire the real shutdown action with only the callbacks that matter here: the given earlier ones and the
	 * plugin's own. Core's shutdown callbacks (output buffer flushing) must not run inside a test.
	 *
	 * @param callable ...$earlier Callbacks that run before the plugin's, as other plugins' do.
	 * @return void
	 */
	private function fire_shutdown( callable ...$earlier ): void {
		global $wp_filter;
		$saved = $wp_filter['shutdown'] ?? null;
		$hook  = new \WP_Hook();
		foreach ( $earlier as $callback ) {
			$hook->add_filter( 'shutdown', $callback, 10, 1 );
		}
		foreach ( null === $saved ? array() : ( $saved->callbacks[ PHP_INT_MAX ] ?? array() ) as $entry ) {
			$hook->add_filter( 'shutdown', $entry['function'], PHP_INT_MAX, $entry['accepted_args'] );
		}
		$wp_filter['shutdown'] = $hook;
		try {
			do_action( 'shutdown' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook, fired on purpose.
		} finally {
			if ( null === $saved ) {
				unset( $wp_filter['shutdown'] );
			} else {
				$wp_filter['shutdown'] = $saved;
			}
		}
	}

	public function test_a_create_stays_revertable_when_another_plugin_writes_meta_at_shutdown(): void {
		$result = $this->run_tool( 'aafm/create-post', array( 'title' => 'Fresh' ) );
		$post   = (int) $result['post']['id'];
		$change = (int) $result['undo']['change_ids'][0];
		$before = aafm_undo_get_view( $change )['row']['after_hashes'];

		$this->assertSame( PHP_INT_MAX, has_action( 'shutdown', 'aafm_undo_settle_creates' ) );
		// What WPML does: bookkeeping meta in the shutdown phase, before the plugin's last callback.
		$this->fire_shutdown(
			static function () use ( $post ): void {
				add_post_meta( $post, '_wpml_word_count', '{"total":3}' );
			}
		);

		$this->assertNotSame( $before, aafm_undo_get_view( $change )['row']['after_hashes'], 'The fingerprint was taken again.' );
		$outcome = aafm_undo_apply( $change, 'owner' );
		$this->assertIsArray( $outcome, is_wp_error( $outcome ) ? $outcome->get_error_message() : '' );
		$this->assertSame( 'trash', get_post( $post )->post_status );
	}

	public function test_a_create_still_refuses_a_later_edit_after_the_shutdown_settle(): void {
		$result = $this->run_tool( 'aafm/create-post', array( 'title' => 'Fresh' ) );
		$post   = (int) $result['post']['id'];
		$change = (int) $result['undo']['change_ids'][0];
		$this->fire_shutdown();

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'edited by a person',
			)
		);
		$outcome = aafm_undo_apply( $change, 'owner' );
		$this->assertWPError( $outcome );
		$this->assertSame( 'aafm_undo_changed_since', $outcome->get_error_code() );
	}

	public function test_the_shutdown_settle_does_not_absorb_a_post_edit_made_after_the_call(): void {
		$result = $this->run_tool( 'aafm/create-post', array( 'title' => 'Fresh' ) );
		$post   = (int) $result['post']['id'];
		$change = (int) $result['undo']['change_ids'][0];

		// A person edits the post between the call and the end of the request.
		$this->fire_shutdown(
			static function () use ( $post ): void {
				wp_update_post(
					array(
						'ID'           => $post,
						'post_content' => 'edited by a person',
					)
				);
			}
		);

		$outcome = aafm_undo_apply( $change, 'owner' );
		$this->assertWPError( $outcome );
		$this->assertSame( 'aafm_undo_changed_since', $outcome->get_error_code() );
		$this->assertNotSame( 'trash', get_post( $post )->post_status );
	}

	public function test_a_stale_self_recording_row_id_does_not_survive_the_next_call(): void {
		aafm_undo_current_row_id( 999 );
		$this->run_tool( 'aafm/get-posts', array() );
		$this->assertSame( 0, aafm_undo_current_row_id() );
	}
}
