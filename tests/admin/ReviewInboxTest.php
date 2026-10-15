<?php
/**
 * Needs review: the origin stamp on agent-created drafts, the inbox query, Publish and Discard
 * with their checks, the review log rows, and the dashboard card.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

final class ReviewInboxTest extends TestCase {

	use AjaxHarness;

	public function tear_down(): void {
		while ( null !== aafm_executing_ability() ) {
			aafm_executing_ability_pop();
		}
		aafm_oauth_current_client_id( null, true );
		if ( wp_has_ability( 'aafm/review-fixture' ) ) {
			wp_unregister_ability( 'aafm/review-fixture' );
		}
		$this->reset_ajax_harness();
		parent::tear_down();
	}

	/**
	 * Create a plain draft with no agent involved.
	 *
	 * @param array<string,mixed> $args Extra post fields.
	 * @return int Post id.
	 */
	private function draft( array $args = array() ): int {
		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_status' => 'draft',
					'post_title'  => 'Agent draft',
				),
				$args
			)
		);
	}

	/**
	 * Create a post the way an executing aafm ability would, so the stamp applies.
	 *
	 * @param array<string,mixed> $args Extra post fields.
	 * @param string              $tool Ability name that is executing.
	 * @return int Post id.
	 */
	private function agent_draft( array $args = array(), string $tool = 'aafm/create-post' ): int {
		aafm_executing_ability_push( $tool );
		try {
			return $this->draft( $args );
		} finally {
			aafm_executing_ability_pop();
		}
	}

	private function age( int $post_id, string $modified ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $modified,
				'post_modified_gmt' => $modified,
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );
	}

	/**
	 * The origin stamp.
	 */
	public function test_a_draft_created_while_an_aafm_ability_executes_is_stamped(): void {
		$user_id = $this->acting_as( 'administrator' );
		aafm_oauth_current_client_id( 'client-abc' );

		$id = $this->agent_draft( array(), 'aafm/create-post' );

		$this->assertSame( 'aafm/create-post', get_post_meta( $id, '_aafm_agent_tool', true ) );
		$this->assertSame( (string) $user_id, get_post_meta( $id, '_aafm_agent_user', true ) );
		$this->assertSame( 'client-abc', get_post_meta( $id, '_aafm_agent_client', true ) );
	}

	public function test_a_pending_post_is_stamped_too(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft( array( 'post_status' => 'pending' ) );
		$this->assertSame( 'aafm/create-post', get_post_meta( $id, '_aafm_agent_tool', true ) );
	}

	public function test_nothing_is_stamped_outside_an_aafm_ability(): void {
		$this->acting_as( 'administrator' );
		$id = $this->draft();
		$this->assertSame( '', get_post_meta( $id, '_aafm_agent_tool', true ) );
	}

	public function test_published_creations_and_updates_are_not_stamped(): void {
		$this->acting_as( 'administrator' );

		$published = $this->agent_draft( array( 'post_status' => 'publish' ) );
		$this->assertSame( '', get_post_meta( $published, '_aafm_agent_tool', true ), 'Nothing to review once it is live.' );

		$human = $this->draft();
		aafm_executing_ability_push( 'aafm/update-post' );
		wp_update_post(
			array(
				'ID'         => $human,
				'post_title' => 'Edited by an agent',
			)
		);
		aafm_executing_ability_pop();
		$this->assertSame( '', get_post_meta( $human, '_aafm_agent_tool', true ), 'An agent editing a human draft does not make it an agent draft.' );
	}

	public function test_the_real_ability_wrapper_stamps_and_unwinds_the_stack(): void {
		$this->acting_as( 'administrator' );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				aafm_register_ability_with_log(
					'aafm/review-fixture',
					array(
						'label'               => 'Review fixture',
						'description'         => 'Creates a draft.',
						'category'            => 'aafm-writes',
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static function () {
							return array(
								'id' => wp_insert_post(
									array(
										'post_title'  => 'From the tool',
										'post_status' => 'draft',
									)
								),
							);
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$result = wp_get_ability( 'aafm/review-fixture' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( 'aafm/review-fixture', get_post_meta( (int) $result['id'], '_aafm_agent_tool', true ) );
		$this->assertNull( aafm_executing_ability(), 'The stack is empty again once the ability returns.' );
	}

	/**
	 * The inbox query.
	 */
	public function test_the_inbox_lists_only_unpublished_agent_posts_newest_first(): void {
		$this->acting_as( 'administrator' );
		$human   = $this->draft( array( 'post_title' => 'Human draft' ) );
		$older   = $this->agent_draft( array( 'post_title' => 'Older agent draft' ) );
		$newer   = $this->agent_draft(
			array(
				'post_title'  => 'Newer agent pending',
				'post_status' => 'pending',
			)
		);
		$live    = $this->agent_draft( array( 'post_title' => 'Went live' ) );
		$trashed = $this->agent_draft( array( 'post_title' => 'Trashed' ) );
		wp_update_post(
			array(
				'ID'          => $live,
				'post_status' => 'publish',
			)
		);
		wp_trash_post( $trashed );
		$this->age( $older, '2026-01-01 10:00:00' );
		$this->age( $newer, '2026-02-01 10:00:00' );

		$ids = aafm_review_inbox_ids();

		$this->assertSame( array( $newer, $older ), $ids );
		$this->assertNotContains( $human, $ids );
	}

	/**
	 * Publish and discard.
	 */
	public function test_publish_makes_the_post_live(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();

		$this->assertTrue( aafm_review_apply( 'publish', $id ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_discard_moves_the_post_to_trash_not_to_oblivion(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();

		$this->assertTrue( aafm_review_apply( 'discard', $id ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	public function test_a_stale_item_is_refused(): void {
		$this->acting_as( 'administrator' );

		$unstamped = $this->draft();
		$result    = aafm_review_apply( 'publish', $unstamped );
		$this->assertWPError( $result );
		$this->assertSame( 'aafm_review_gone', $result->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $unstamped ) );

		$live = $this->agent_draft();
		wp_update_post(
			array(
				'ID'          => $live,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'aafm_review_gone', aafm_review_apply( 'discard', $live )->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $live ) );

		$this->assertSame( 'aafm_review_gone', aafm_review_apply( 'publish', 999999 )->get_error_code() );
	}

	public function test_a_user_without_the_capability_is_refused(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();
		$this->acting_as( 'contributor' );

		$result = aafm_review_apply( 'publish', $id );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_review_forbidden', $result->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'aafm_review_forbidden', aafm_review_apply( 'discard', $id )->get_error_code() );
	}

	public function test_discard_is_refused_when_the_trash_is_off(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();
		add_filter( 'aafm_trash_is_enabled', '__return_false' );

		$result = aafm_review_apply( 'discard', $id );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_review_no_trash', $result->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $id ), 'Nothing is deleted for good when the Trash is off.' );

		$rows = aafm_review_inbox_rows();
		$this->assertFalse( $rows[0]['can_discard'], 'The card does not offer a Discard that would be refused.' );
		$this->assertTrue( $rows[0]['can_publish'] );
	}

	public function test_discard_through_ajax_with_the_trash_off_logs_an_error_row(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();
		$this->post_as( 'administrator', array( 'post_id' => (string) $id ) );
		add_filter( 'aafm_trash_is_enabled', '__return_false' );

		$json = $this->run_handler( 'aafm_ajax_review_discard' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/review-discard' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	/**
	 * The ajax handlers and their log rows.
	 */
	public function test_publish_through_ajax_logs_one_review_row_with_ids_only(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft( array( 'post_title' => 'A very private headline' ), 'aafm/create-page' );
		$this->post_as( 'administrator', array( 'post_id' => (string) $id ) );

		$json = $this->run_handler( 'aafm_ajax_review_publish' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/review-publish' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'review_action', $rows[0]['event_type'] );
		$this->assertSame( 'success', $rows[0]['status'] );
		$this->assertStringContainsString( (string) $id, (string) $rows[0]['detail'] );
		$this->assertStringContainsString( 'aafm/create-page', (string) $rows[0]['detail'] );
		$this->assertStringNotContainsString( 'private headline', (string) wp_json_encode( $rows ) );
	}

	public function test_discard_through_ajax_trashes_and_logs(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();
		$this->post_as( 'administrator', array( 'post_id' => (string) $id ) );

		$json = $this->run_handler( 'aafm_ajax_review_discard' );

		$this->assertTrue( (bool) ( $json['success'] ?? false ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertCount( 1, aafm_query_activity( array( 'ability' => 'aafm/review-discard' ) ) );
	}

	public function test_a_non_admin_is_refused_and_the_attempt_is_logged_as_denied(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();
		$this->post_as( 'editor', array( 'post_id' => (string) $id ) );

		$json = $this->run_handler( 'aafm_ajax_review_publish' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/review-publish' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'denied', $rows[0]['status'] );
	}

	public function test_a_stale_click_reports_an_error_and_logs_it(): void {
		$this->acting_as( 'administrator' );
		$id = $this->draft(); // Never stamped.
		$this->post_as( 'administrator', array( 'post_id' => (string) $id ) );

		$json = $this->run_handler( 'aafm_ajax_review_discard' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/review-discard' ) );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	public function test_a_forged_nonce_changes_nothing_and_is_logged(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();
		$this->post_as( 'administrator', array( 'post_id' => (string) $id ) );
		$_POST['nonce']    = 'bad';
		$_REQUEST['nonce'] = 'bad';

		$json = $this->run_handler( 'aafm_ajax_review_publish' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/review-publish' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertSame( 'review_action', $rows[0]['event_type'] );
	}

	public function test_a_missing_nonce_on_discard_changes_nothing_and_is_logged(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();
		$this->post_as( 'administrator', array( 'post_id' => (string) $id ) );
		unset( $_POST['nonce'], $_REQUEST['nonce'] );

		$json = $this->run_handler( 'aafm_ajax_review_discard' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertCount( 1, aafm_query_activity( array( 'ability' => 'aafm/review-discard' ) ) );
	}

	public function test_a_logged_out_request_is_refused_and_logged(): void {
		$this->acting_as( 'administrator' );
		$id    = $this->agent_draft();
		$nonce = wp_create_nonce( 'aafm_admin' );
		wp_set_current_user( 0 );
		$_POST             = array(
			'nonce'   => $nonce,
			'post_id' => (string) $id,
		);
		$_REQUEST['nonce'] = $nonce;
		$this->intercept_die();

		$json = $this->run_handler( 'aafm_ajax_review_publish' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertCount( 1, aafm_query_activity( array( 'ability' => 'aafm/review-publish' ) ) );
	}

	public function test_an_admin_without_the_post_capability_is_refused_per_post(): void {
		// manage_options alone is not enough: the post's own caps are checked at click time.
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();
		$this->post_as( 'administrator', array( 'post_id' => (string) $id ) );
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap ): array {
				return in_array( $cap, array( 'edit_post', 'delete_post' ), true ) ? array( 'do_not_allow' ) : $caps;
			},
			10,
			2
		);

		$json = $this->run_handler( 'aafm_ajax_review_publish' );

		$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$rows = aafm_query_activity( array( 'ability' => 'aafm/review-publish' ) );
		$this->assertSame( 'denied', $rows[0]['status'] );
	}

	/**
	 * The stamp cannot be forged.
	 */
	public function test_the_stamp_keys_are_protected_and_unreachable_through_the_meta_abilities(): void {
		$admin = $this->acting_as( 'administrator' );
		$human = $this->draft();
		$keys  = array( '_aafm_agent_tool', '_aafm_agent_user', '_aafm_agent_client' );
		update_option( 'aafm_allowed_meta_keys', $keys ); // Even an explicit allowlist entry cannot open them.

		foreach ( $keys as $key ) {
			$this->assertTrue( is_protected_meta( $key, 'post' ), $key . ' is protected meta.' );
			$this->assertTrue( aafm_hard_blocked_meta_key( $key ), $key . ' is hard-blocked for agents.' );
			$this->assertFalse( current_user_can( 'edit_post_meta', $human, $key ), $key . ' is not editable by a user, admins included.' );
			$this->assertFalse(
				aafm_perm_update_post_meta(
					array(
						'post_id'  => $human,
						'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
						'value'    => 'forged',
					)
				)
			);
			$this->assertFalse(
				aafm_perm_delete_post_meta(
					array(
						'post_id'  => $human,
						'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					)
				)
			);
		}

		$this->assertSame( array(), aafm_review_inbox_ids(), 'A human draft cannot be moved into the inbox.' );
		$this->assertSame( $admin, get_current_user_id() );
	}

	public function test_a_rest_meta_write_cannot_set_or_clear_the_stamp(): void {
		$this->acting_as( 'administrator' );
		$human = $this->draft();
		$agent = $this->agent_draft();

		// The hardest case: another plugin exposes the key to REST. Core still refuses a protected key
		// for every user, administrators included.
		register_post_meta(
			'post',
			'_aafm_agent_tool',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);

		try {
			$forge = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $human );
			$forge->set_body_params( array( 'meta' => array( '_aafm_agent_tool' => 'aafm/create-post' ) ) );
			$forged = rest_get_server()->dispatch( $forge );
			$this->assertGreaterThanOrEqual( 400, $forged->get_status(), 'REST refuses the write outright.' );
			$this->assertSame( '', get_post_meta( $human, '_aafm_agent_tool', true ), 'REST meta cannot forge the stamp.' );

			$clear = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $agent );
			$clear->set_body_params( array( 'meta' => array( '_aafm_agent_tool' => '' ) ) );
			rest_get_server()->dispatch( $clear );
			$this->assertSame( 'aafm/create-post', get_post_meta( $agent, '_aafm_agent_tool', true ), 'REST meta cannot clear the stamp.' );
		} finally {
			unregister_meta_key( 'post', '_aafm_agent_tool' );
		}
	}

	public function test_the_stamp_is_written_through_the_write_contract(): void {
		$this->acting_as( 'administrator' );
		$seen = array();
		add_action(
			'aafm_write_completed',
			static function ( array $result, array $target ) use ( &$seen ): void {
				$seen[] = array( (string) $target['kind'], (string) $target['key'], (string) $result['status'] );
			},
			10,
			2
		);

		$this->agent_draft();

		foreach ( array( '_aafm_agent_tool', '_aafm_agent_user', '_aafm_agent_client' ) as $key ) {
			$this->assertContains( array( 'post_meta', $key, AAFM_WRITE_WRITTEN ), $seen, $key . ' went through aafm_meta_set().' );
		}
	}

	/**
	 * Final review I1: the two refusals any logged-in user can trigger are bounded per IP.
	 */
	public function test_forged_nonce_rows_stop_at_the_per_ip_cap(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();
		$this->post_as( 'administrator', array( 'post_id' => (string) $id ) );
		$_POST['nonce']         = 'bad';
		$_REQUEST['nonce']      = 'bad';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.91';

		for ( $i = 0; $i < AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW + 3; $i++ ) {
			$json = $this->run_handler( 'aafm_ajax_review_publish' );
			$this->assertFalse( (bool) ( $json['success'] ?? true ), 'The refusal itself is never capped.' );
		}

		$this->assertCount(
			AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW,
			aafm_query_activity(
				array(
					'ability'  => 'aafm/review-publish',
					'per_page' => 50,
				)
			)
		);
	}

	public function test_non_admin_rows_stop_at_the_per_ip_cap(): void {
		$this->acting_as( 'administrator' );
		$id = $this->agent_draft();
		$this->post_as( 'editor', array( 'post_id' => (string) $id ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.92';

		for ( $i = 0; $i < AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW + 3; $i++ ) {
			$json = $this->run_handler( 'aafm_ajax_review_discard' );
			$this->assertFalse( (bool) ( $json['success'] ?? true ) );
		}

		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertCount(
			AAFM_FAILED_AUTH_LOG_MAX_PER_WINDOW,
			aafm_query_activity(
				array(
					'ability'  => 'aafm/review-discard',
					'per_page' => 50,
				)
			)
		);
	}

	/**
	 * Final review L1: a pre_trash_post callback that returns the unchanged post is not a trashed post.
	 */
	public function test_discard_fails_when_a_filter_stops_the_trash_but_returns_the_post(): void {
		$this->acting_as( 'administrator' );
		$id   = $this->agent_draft();
		$hold = static fn( $check, $post ) => $post;
		add_filter( 'pre_trash_post', $hold, 10, 2 );

		$result = aafm_review_apply( 'discard', $id );
		remove_filter( 'pre_trash_post', $hold, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_review_failed', $result->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	/**
	 * Final review M5: the card lists at most the limit, so a full list reads "20+", not "20".
	 */
	public function test_the_badge_reads_plus_when_the_list_is_full(): void {
		$this->acting_as( 'administrator' );
		for ( $i = 0; $i < AAFM_REVIEW_INBOX_LIMIT + 2; $i++ ) {
			$this->agent_draft();
		}

		ob_start();
		aafm_render_review_inbox_card();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '>' . AAFM_REVIEW_INBOX_LIMIT . '+<', $html );
	}

	public function test_the_badge_is_the_plain_count_below_the_limit(): void {
		$this->acting_as( 'administrator' );
		$this->agent_draft();
		$this->agent_draft();

		ob_start();
		aafm_render_review_inbox_card();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aafm-review-count">2<', $html );
	}

	/**
	 * Rendering.
	 */
	public function test_the_dashboard_card_escapes_titles_and_offers_the_three_actions(): void {
		$this->acting_as( 'administrator' );
		$this->agent_draft( array( 'post_title' => '<img src=x onerror=alert(1)>' ) );

		ob_start();
		aafm_render_dashboard_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="aafm-review-inbox"', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( 'data-review-action="publish"', $html );
		$this->assertStringContainsString( 'data-review-action="discard"', $html );
		$this->assertStringContainsString( 'preview=true', $html );
	}

	public function test_the_dashboard_card_has_an_empty_state(): void {
		$this->acting_as( 'administrator' );

		ob_start();
		aafm_render_review_inbox_card();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="aafm-review-inbox"', $html );
		$this->assertStringContainsString( 'Nothing is waiting', $html );
		$this->assertStringNotContainsString( 'data-review-action', $html );
	}
}
