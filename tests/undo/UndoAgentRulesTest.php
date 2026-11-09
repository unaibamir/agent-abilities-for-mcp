<?php
/**
 * The agent undo path, branch by branch: who owns a change, and every rule a connection's undo must pass again
 * at undo time. Each case asserts the refusal and that the stored value is untouched.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\Support\AjaxHarness;

final class UndoAgentRulesTest extends UndoTestCase {

	private const TOOLS = array(
		'aafm/update-post',
		'aafm/update-page',
		'aafm/update-post-meta',
		'aafm/add-post-terms',
		'aafm/update-term',
		'aafm/replace-sitewide',
		'aafm/undo-change',
		'aafm/list-changes',
	);

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
		$this->register_enabled( self::TOOLS );
		aafm_oauth_current_client_id( 'client-a' );
	}

	public function tear_down(): void {
		aafm_oauth_current_client_id( null, true );
		aafm_oauth_current_grant( null, true );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		delete_option( 'aafm_ability_allowlist_overrides' );
		delete_option( 'aafm_read_only_mode' );
		remove_all_filters( 'aafm_trash_is_enabled' );
		unregister_taxonomy( 'aafm_tax' );
		wp_using_ext_object_cache( false );
		parent::tear_down();
	}

	/**
	 * Run a tool on the current connection.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return mixed
	 */
	private function call( string $ability, array $input ) {
		return wp_get_ability( $ability )->execute( $input );
	}

	/**
	 * Run a tool and return its change id.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return int
	 */
	private function change_from( string $ability, array $input ): int {
		$result = $this->call( $ability, $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return (int) ( $result['undo']['change_ids'][0] ?? 0 );
	}

	/**
	 * Undo as the agent and return the error code, or 'ok'.
	 *
	 * @param int $change_id Change id.
	 * @return string
	 */
	private function undo_code( int $change_id ): string {
		$result = $this->call( 'aafm/undo-change', array( 'change_id' => $change_id ) );
		return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
	}

	/**
	 * Write a column straight to the posts table, the way an older edit or another plugin could.
	 *
	 * @param int    $post   Post id.
	 * @param string $column Column.
	 * @param string $value  Value.
	 * @return void
	 */
	private function raw_post( int $post, string $column, string $value ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->posts, array( $column => $value ), array( 'ID' => $post ) );
		clean_post_cache( $post );
	}

	public function test_a_second_user_on_the_same_client_cannot_undo_or_list_the_first_users_change(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		$other  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );

		$this->assertSame( $this->undo_code( 999999 ), $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
		$listing = aafm_exec_list_changes(
			array(
				'object_type' => 'post',
				'object_id'   => $post,
			)
		);
		$this->assertSame( array(), $listing['changes'] );
	}

	public function test_two_application_passwords_of_one_user_cannot_undo_each_other(): void {
		aafm_oauth_current_client_id( null, true );
		$post = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$GLOBALS['wp_rest_application_password_uuid'] = 'uuid-one';
		$change                                       = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);

		$GLOBALS['wp_rest_application_password_uuid'] = 'uuid-two';
		$this->assertSame( $this->undo_code( 999999 ), $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $post )->post_title );

		$GLOBALS['wp_rest_application_password_uuid'] = 'uuid-one';
		$this->assertSame( 'ok', $this->undo_code( $change ) );
		$this->assertSame( 'A', get_post( $post )->post_title );
	}

	/**
	 * The statuses a foreign row can hold.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function data_statuses(): array {
		return array(
			'pending'      => array( 'pending' ),
			'not undoable' => array( 'not_undoable' ),
			'undoing'      => array( 'undoing' ),
			'reverted'     => array( 'reverted' ),
			'expired'      => array( 'expired' ),
			'ready'        => array( 'ready' ),
		);
	}

	/**
	 * A row of another connection answers like a missing id in every status.
	 *
	 * @dataProvider data_statuses
	 *
	 * @param string $status The foreign row's status.
	 */
	public function test_foreign_rows_in_every_status_return_the_generic_error( string $status ): void {
		global $wpdb;
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			aafm_changes_table(),
			array(
				'status'     => $status,
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			),
			array( 'id' => $change )
		);
		aafm_oauth_current_client_id( 'client-b' );

		$this->assertSame( $this->undo_code( 999999 ), $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_forward_tool_outside_the_connection_allow_list_is_refused(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'oauth_client',
					'scope_id'          => 'client-a',
					'allowed_abilities' => array( 'aafm/undo-change', 'aafm/list-changes' ),
				),
			)
		);

		$this->assertSame( 'aafm_undo_not_allowed', $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_a_page_change_is_refused_once_the_page_tool_is_off(): void {
		$page   = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'A',
			)
		);
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $page,
				'title'   => 'B',
			)
		);
		update_option( 'aafm_enabled_abilities', array( 'aafm/update-post', 'aafm/undo-change' ) );

		$this->assertSame( 'aafm_undo_not_allowed', $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $page )->post_title );
	}

	public function test_replace_sitewide_undo_on_a_page_with_update_page_off_is_refused(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'needle here',
			)
		);
		$call = $this->call(
			'aafm/replace-sitewide',
			array(
				'search'    => 'needle',
				'replace'   => 'thread',
				'dry_run'   => false,
				'post_type' => 'page',
			)
		);
		$this->assertIsArray( $call, is_wp_error( $call ) ? $call->get_error_message() : '' );
		$change = (int) $call['undo']['change_ids'][0];
		update_option( 'aafm_enabled_abilities', array( 'aafm/replace-sitewide', 'aafm/undo-change' ) );

		$this->assertSame( 'aafm_undo_not_allowed', $this->undo_code( $change ) );
		$this->assertSame( 'thread here', get_post( $page )->post_content );
	}

	/**
	 * The undoable flag list-changes gives for a change, which comes from the agent rules alone.
	 *
	 * @param int $post   Post id.
	 * @param int $change Change id.
	 * @return array<string,mixed>
	 */
	private function listed( int $post, int $change ): array {
		$listing = aafm_exec_list_changes(
			array(
				'object_type' => 'post',
				'object_id'   => $post,
			)
		);
		foreach ( $listing['changes'] as $entry ) {
			if ( $entry['id'] === $change ) {
				return $entry;
			}
		}
		return array();
	}

	public function test_replace_sitewide_rows_need_the_per_post_edit_right_at_undo_time(): void {
		$author = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $author );
		$post = self::factory()->post->create(
			array(
				'post_author'  => get_current_user_id() + 1000,
				'post_content' => 'needle here',
			)
		);
		$call = $this->call(
			'aafm/replace-sitewide',
			array(
				'search'  => 'needle',
				'replace' => 'thread',
				'dry_run' => false,
			)
		);
		$this->assertIsArray( $call, is_wp_error( $call ) ? $call->get_error_message() : '' );
		$change = (int) $call['undo']['change_ids'][0];
		$this->assertTrue( $this->listed( $post, $change )['undoable'], 'precondition: an editor may undo it.' );
		// An author may still edit posts in general but no longer other people's.
		wp_get_current_user()->set_role( 'author' );

		$entry = $this->listed( $post, $change );
		$this->assertFalse( $entry['undoable'] );
		$this->assertSame( 'not_allowed', $entry['reason'] );
	}

	public function test_a_meta_change_on_a_page_is_not_undoable_once_the_page_tool_is_off(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		add_post_meta( $page, 'aafm_note', 'old' );
		$change = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $page,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'meta_key' => 'aafm_note',
				'value'    => 'new',
			)
		);
		$this->assertTrue( $this->listed( $page, $change )['undoable'], 'precondition.' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/update-post-meta', 'aafm/undo-change', 'aafm/list-changes' ) );

		$entry = $this->listed( $page, $change );
		$this->assertFalse( $entry['undoable'] );
		$this->assertSame( 'not_allowed', $entry['reason'] );
	}

	public function test_terms_undo_after_the_taxonomy_left_the_allow_list_is_refused(): void {
		register_taxonomy(
			'aafm_tax',
			'post',
			array(
				'public'       => true,
				'hierarchical' => false,
			)
		);
		$post = self::factory()->post->create();
		$keep = (int) self::factory()->term->create( array( 'taxonomy' => 'aafm_tax' ) );
		$add  = (int) self::factory()->term->create( array( 'taxonomy' => 'aafm_tax' ) );
		wp_set_object_terms( $post, array( $keep ), 'aafm_tax' );
		$change = $this->change_from(
			'aafm/add-post-terms',
			array(
				'post_id'  => $post,
				'taxonomy' => 'aafm_tax',
				'term_ids' => array( $add ),
			)
		);
		// Not public any more: the taxonomy is off the list the tools accept.
		register_taxonomy(
			'aafm_tax',
			'post',
			array(
				'public'       => false,
				'hierarchical' => false,
			)
		);

		$this->assertSame( 'aafm_undo_not_allowed', $this->undo_code( $change ) );
		$this->assertEqualsCanonicalizing( array( $keep, $add ), array_map( 'intval', wp_get_object_terms( $post, 'aafm_tax', array( 'fields' => 'ids' ) ) ) );
	}

	public function test_an_empty_terms_undo_after_the_taxonomy_left_the_allow_list_is_refused(): void {
		register_taxonomy(
			'aafm_tax',
			'post',
			array(
				'public'       => true,
				'hierarchical' => false,
			)
		);
		$post   = self::factory()->post->create();
		$add    = (int) self::factory()->term->create( array( 'taxonomy' => 'aafm_tax' ) );
		$change = $this->change_from(
			'aafm/add-post-terms',
			array(
				'post_id'  => $post,
				'taxonomy' => 'aafm_tax',
				'term_ids' => array( $add ),
			)
		);
		register_taxonomy(
			'aafm_tax',
			'post',
			array(
				'public'       => false,
				'hierarchical' => false,
			)
		);

		$this->assertSame( 'aafm_undo_not_allowed', $this->undo_code( $change ) );
		$this->assertSame( array( $add ), array_map( 'intval', wp_get_object_terms( $post, 'aafm_tax', array( 'fields' => 'ids' ) ) ) );
	}

	public function test_republish_without_the_publish_capability_is_refused(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );
		$post   = self::factory()->post->create(
			array(
				'post_author' => $user,
				'post_status' => 'publish',
			)
		);
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'status'  => 'draft',
			)
		);
		wp_get_current_user()->set_role( 'contributor' );

		$this->assertSame( 'aafm_undo_not_allowed', $this->undo_code( $change ) );
		$this->assertSame( 'draft', get_post( $post )->post_status );
	}

	/**
	 * Before-values the forward tool's sanitizer would store differently.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function data_values_the_forward_tool_would_change(): array {
		return array(
			'content with an iframe' => array( 'post_content', '<p>x</p><iframe src="https://example.test/"></iframe>', 'content' ),
			'excerpt with markup'    => array( 'post_excerpt', '<b>bold</b> excerpt', 'excerpt' ),
			'content with a script'  => array( 'post_content', '<script>alert(1)</script>', 'content' ),
		);
	}

	/**
	 * A before-value the forward tool's sanitizer would store differently is never written by an agent.
	 *
	 * @dataProvider data_values_the_forward_tool_would_change
	 *
	 * @param string $column Posts column holding the before-value.
	 * @param string $stored The before-value.
	 * @param string $input  The tool argument that changes it.
	 */
	public function test_a_value_the_forward_tool_would_change_is_refused_for_the_owner_to_revert( string $column, string $stored, string $input ): void {
		$post = self::factory()->post->create();
		$this->raw_post( $post, $column, $stored );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				$input    => 'plain new value',
			)
		);

		$this->assertSame( 'aafm_undo_needs_owner', $this->undo_code( $change ) );
		$this->assertSame( 'plain new value', get_post( $post )->$column );
		$this->assertIsArray( aafm_undo_apply( $change, 'owner' ), 'The owner can still revert it.' );
	}

	public function test_a_term_description_with_a_script_is_refused_for_the_owner_to_revert(): void {
		global $wpdb;
		$term = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->term_taxonomy, array( 'description' => '<script>alert(1)</script>' ), array( 'term_id' => $term ) );
		clean_term_cache( $term, 'category' );
		$change = $this->change_from(
			'aafm/update-term',
			array(
				'taxonomy'    => 'category',
				'term_id'     => $term,
				'description' => 'plain',
			)
		);

		$this->assertSame( 'aafm_undo_needs_owner', $this->undo_code( $change ) );
		$this->assertSame( 'plain', get_term( $term, 'category' )->description );
	}

	public function test_a_meta_value_that_is_not_a_scalar_is_refused_for_the_owner_to_revert(): void {
		$post = self::factory()->post->create();
		add_post_meta( $post, 'aafm_obj', array( 'nested' => 'value' ) );
		$change = $this->change_from(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'meta_key' => 'aafm_obj',
				'value'    => 'scalar now',
			)
		);

		$this->assertSame( 'aafm_undo_needs_owner', $this->undo_code( $change ) );
		$this->assertSame( 'scalar now', get_post_meta( $post, 'aafm_obj', true ) );
	}

	/**
	 * AIOSEO column values with whether the forward rules accept each.
	 *
	 * @return array<string,array{0:string,1:mixed,2:bool}>
	 */
	public function data_aioseo_values(): array {
		return array(
			'javascript canonical' => array( 'aioseo:canonical_url', 'javascript:alert(1)', false ),
			'https canonical'      => array( 'aioseo:canonical_url', 'https://example.test/page/', true ),
			'script in a title'    => array( 'aioseo:title', '<script>x</script>', false ),
			'plain title'          => array( 'aioseo:title', 'A plain title', true ),
			'flag out of range'    => array( 'aioseo:robots_noindex', '2', false ),
			'flag set'             => array( 'aioseo:robots_noindex', '1', true ),
			'null column'          => array( 'aioseo:title', null, true ),
		);
	}

	/**
	 * The AIOSEO column rules of the agent path, run on their own.
	 *
	 * @dataProvider data_aioseo_values
	 *
	 * @param string $field    Field id.
	 * @param mixed  $value    Before-value.
	 * @param bool   $expected Whether the forward rules accept it.
	 */
	public function test_aioseo_column_values_follow_the_forward_rules( string $field, $value, bool $expected ): void {
		$row = array(
			'ability'     => 'aafm/aioseo-update-post',
			'object_type' => 'post',
			'object_id'   => 1,
		);

		$this->assertSame( $expected, aafm_undo_value_passes_forward_rules( $field, $value, $row, array( 'taxonomy' => '' ) ) );
	}

	/**
	 * Fixed SEO meta values with whether the forward URL rule accepts each.
	 *
	 * @return array<string,array{0:string,1:string,2:mixed,3:bool}>
	 */
	public function data_seo_meta_urls(): array {
		return array(
			'yoast javascript canonical' => array( 'aafm/yoast-update-post', '_yoast_wpseo_canonical', 'javascript:alert(1)', false ),
			'yoast https canonical'      => array( 'aafm/yoast-update-post', '_yoast_wpseo_canonical', 'https://example.test/page/', true ),
			'yoast title'                => array( 'aafm/yoast-update-post', '_yoast_wpseo_title', 'A plain title', true ),
			'yoast serialized array'     => array( 'aafm/yoast-update-post', '_yoast_wpseo_canonical', 'a:1:{i:0;s:1:"x";}', false ),
			'rank math serialized array' => array( 'aafm/rankmath-update-post', 'rank_math_canonical_url', 'a:1:{i:0;s:1:"x";}', false ),
			'rank math javascript'       => array( 'aafm/rankmath-update-post', 'rank_math_canonical_url', 'javascript:alert(1)', false ),
			'rank math https'            => array( 'aafm/rankmath-update-post', 'rank_math_canonical_url', 'https://example.test/', true ),
			'slim seo array canonical'   => array( 'aafm/slim-seo-update-post', 'slim_seo', serialize( array( 'canonical' => array( 'x' ) ) ), false ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the stored shape of a legacy row.
		);
	}

	/**
	 * An older URL an agent restores must pass the URL rule its forward tool runs.
	 *
	 * @dataProvider data_seo_meta_urls
	 *
	 * @param string $ability  Forward tool.
	 * @param string $key      Meta key.
	 * @param string $value    Before-value row.
	 * @param bool   $expected Whether it passes.
	 */
	public function test_fixed_seo_meta_urls_follow_the_forward_url_rule( string $ability, string $key, string $value, bool $expected ): void {
		$row = array(
			'ability'     => $ability,
			'object_type' => 'post',
			'object_id'   => 1,
		);

		$this->assertSame( $expected, aafm_undo_value_passes_forward_rules( 'meta:' . $key, array( $value ), $row, array( 'taxonomy' => '' ) ) );
	}

	public function test_undo_change_is_refused_in_read_only_mode(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		update_option( 'aafm_read_only_mode', '1' );

		$this->assertNotSame( 'ok', $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_a_read_only_connection_preset_cannot_undo(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		aafm_oauth_current_grant(
			array_merge(
				aafm_oauth_grant_defaults(),
				array(
					'preset'    => 'read_only',
					'client_id' => 'client-a',
					'user_id'   => get_current_user_id(),
				)
			)
		);

		$this->assertNotSame( 'ok', $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_a_content_editor_connection_cannot_reach_undo_change(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$change = $this->change_from(
			'aafm/update-post',
			array(
				'post_id' => $post,
				'title'   => 'B',
			)
		);
		aafm_oauth_current_grant(
			array_merge(
				aafm_oauth_grant_defaults(),
				array(
					'preset'    => 'content_editor',
					'client_id' => 'client-a',
					'user_id'   => get_current_user_id(),
				)
			)
		);

		// undo-change is never part of a preset, so the preset refuses the call before the engine runs.
		$this->assertNotSame( 'ok', $this->undo_code( $change ) );
		$this->assertSame( 'B', get_post( $post )->post_title );
	}

	public function test_the_rate_limit_holds_under_an_object_cache_and_with_no_site_limit(): void {
		wp_using_ext_object_cache( true );
		$codes = array();
		for ( $i = 1; $i <= 31; ++$i ) {
			wp_cache_flush();
			$codes[] = $this->undo_code( 900000 + $i );
		}

		$this->assertSame( 'aafm_undo_rate_limited', $codes[30] );
		$this->assertNotSame( 'aafm_undo_rate_limited', $codes[29] );
	}
}
