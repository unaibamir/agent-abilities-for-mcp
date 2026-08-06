<?php
/**
 * The activity-log detail allowlist: the type validators that keep free text out of the log.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\TestCase;

final class DetailTest extends TestCase {

	use IntegrationStubs;

	/**
	 * Give every case an empty activity log to assert against.
	 */
	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Drop the trait's filters and stub stores again after every case.
	 */
	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * A value that clears its type check comes back rendered as a string.
	 *
	 * @dataProvider accepted_values
	 *
	 * @param string $type     The field type.
	 * @param mixed  $value    The candidate value.
	 * @param string $expected The rendered value.
	 */
	public function test_accepted_values( string $type, $value, string $expected ): void {
		$this->assertSame( $expected, aafm_activity_detail_field( $type, $value ) );
	}

	/**
	 * Values that must clear their type check.
	 *
	 * @return array<string,array{0:string,1:mixed,2:string}>
	 */
	public static function accepted_values(): array {
		return array(
			'positive int id'   => array( 'id', 482, '482' ),
			'numeric string id' => array( 'id', '482', '482' ),
			'meta key'          => array( 'key', '_yoast_wpseo_metadesc', '_yoast_wpseo_metadesc' ),
			'hyphen key'        => array( 'key', 'bacs', 'bacs' ),
			'ability slug'      => array( 'slug', 'aafm/wc-create-refund', 'aafm/wc-create-refund' ),
			'zero count'        => array( 'count', 0, '0' ),
		);
	}

	/**
	 * A value that fails its type check comes back as null, never as a partial render.
	 *
	 * @dataProvider rejected_values
	 *
	 * @param string $type  The field type.
	 * @param mixed  $value The candidate value.
	 */
	public function test_rejected_values( string $type, $value ): void {
		$this->assertNull( aafm_activity_detail_field( $type, $value ) );
	}

	/**
	 * Values that must fail their type check.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public static function rejected_values(): array {
		return array(
			'zero id'            => array( 'id', 0 ),
			'negative id'        => array( 'id', -1 ),
			'non numeric id'     => array( 'id', 'abc' ),
			'key with space'     => array( 'key', 'my key' ),
			'key with markup'    => array( 'key', '<b>k</b>' ),
			'key with quote'     => array( 'key', "k'x" ),
			'over long key'      => array( 'key', str_repeat( 'a', 65 ) ),
			'sentence as key'    => array( 'key', 'The quick brown fox jumped' ),
			'slug without slash' => array( 'slug', 'wc-create-refund' ),
			'negative count'     => array( 'count', -3 ),
			'unknown type'       => array( 'string', 'anything at all' ),
			'array value'        => array( 'id', array( 1, 2 ) ),
			'null value'         => array( 'id', null ),
		);
	}

	public function test_enum_accepts_only_declared_members(): void {
		$statuses = array( 'refunded', 'cancelled', 'completed' );
		$this->assertSame( 'refunded', aafm_activity_detail_field( 'enum', 'refunded', $statuses ) );
		$this->assertNull( aafm_activity_detail_field( 'enum', 'processing', $statuses ) );
	}

	public function test_there_is_no_free_text_field_type(): void {
		foreach ( array( 'string', 'text', 'raw', 'value', 'content' ) as $forbidden ) {
			$this->assertNull(
				aafm_activity_detail_field( $forbidden, 'a sentence a user typed' ),
				"Field type '{$forbidden}' must not exist. See 146 section 6.2."
			);
		}
	}

	public function test_an_ability_absent_from_the_map_logs_no_detail(): void {
		$this->assertNull( aafm_build_activity_detail( 'aafm/get-posts', array( 'per_page' => 10 ) ) );
	}

	public function test_args_detail_renders_the_template(): void {
		$this->assertSame(
			'Updated meta key `_yoast_wpseo_metadesc` on post #482',
			aafm_build_activity_detail(
				'aafm/update-post-meta',
				array(
					'meta_key'   => '_yoast_wpseo_metadesc', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'post_id'    => 482,
					'meta_value' => 'secret text', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
	}

	public function test_a_value_never_reaches_the_detail_even_when_the_key_is_adjacent(): void {
		$detail = aafm_build_activity_detail(
			'aafm/update-post-meta',
			array(
				'meta_key'   => '_desc', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'post_id'    => 7,
				'meta_value' => 'CONFIDENTIAL PAYLOAD', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- test fixture: ability-input array key, not a meta query.
			)
		);
		$this->assertStringNotContainsString( 'CONFIDENTIAL', (string) $detail );
	}

	public function test_a_missing_allowlisted_arg_yields_null_rather_than_a_broken_string(): void {
		$this->assertNull( aafm_build_activity_detail( 'aafm/update-post-meta', array( 'post_id' => 482 ) ) );
	}

	public function test_a_failing_type_check_yields_null(): void {
		$this->assertNull(
			aafm_build_activity_detail(
				'aafm/update-post-meta',
				array(
					'meta_key' => 'a b c', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'post_id'  => 482,
				)
			)
		);
	}

	public function test_enum_field_accepts_a_real_order_status_and_rejects_junk(): void {
		$this->stub_wc_order_statuses();

		$this->assertSame(
			'Set order #91 to status `refunded`',
			aafm_build_activity_detail(
				'aafm/wc-update-order-status',
				array(
					'order_id' => 91,
					'status'   => 'refunded',
				)
			)
		);
		$this->assertNull(
			aafm_build_activity_detail(
				'aafm/wc-update-order-status',
				array(
					'order_id' => 91,
					'status'   => 'made-up',
				)
			)
		);
	}

	public function test_result_detail_reads_the_REAL_return_shape_of_the_create_ability(): void {
		// NOT a hand-built fixture. This is what aafm_exec_create_page() actually returns, so the
		// test fails if the map declares a path that does not exist in the real payload. An earlier
		// draft of this plan declared result_id => 'id' and would have logged nothing forever,
		// because a flat fabricated fixture agreed with the bug.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = aafm_exec_create_page(
			array(
				'title'   => 'Some Page',
				'content' => 'body',
			)
		);

		$this->assertIsArray( $result, 'create-page returned a WP_Error; fix the input before asserting on detail.' );
		$this->assertArrayHasKey( 'post', $result, 'The payload is wrapped under `post`; result_id must be a path into it.' );

		$this->assertSame(
			'Created page #' . (int) $result['post']['id'],
			aafm_build_activity_detail_from_result( 'aafm/create-page', $result )
		);
	}

	public function test_result_detail_never_leaks_a_sibling_content_field(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = aafm_exec_create_page(
			array(
				'title'   => 'CONFIDENTIAL TITLE',
				'content' => 'body',
			)
		);
		$detail = aafm_build_activity_detail_from_result( 'aafm/create-page', $result );
		$this->assertStringNotContainsString( 'CONFIDENTIAL', (string) $detail );
	}

	public function test_dig_returns_null_for_a_missing_segment(): void {
		$this->assertNull( aafm_activity_detail_dig( array( 'post' => array( 'id' => 5 ) ), 'user.id' ) );
		$this->assertNull( aafm_activity_detail_dig( array( 'post' => 'not-an-array' ), 'post.id' ) );
		$this->assertSame( 5, aafm_activity_detail_dig( array( 'post' => array( 'id' => 5 ) ), 'post.id' ) );
	}

	public function test_result_detail_rejects_a_non_int_id(): void {
		$this->assertNull(
			aafm_build_activity_detail_from_result( 'aafm/create-page', array( 'post' => array( 'id' => 'abc' ) ) )
		);
	}

	public function test_result_detail_ignores_an_ability_with_no_result_id_declared(): void {
		$this->assertNull(
			aafm_build_activity_detail_from_result( 'aafm/update-post-meta', array( 'post' => array( 'id' => 5 ) ) )
		);
	}

	public function test_link_type_is_declared_per_ability(): void {
		$this->assertSame( 'post', aafm_activity_detail_link_type( 'aafm/update-post-meta' ) );
		$this->assertNull( aafm_activity_detail_link_type( 'aafm/get-posts' ) );
	}

	public function test_no_map_entry_declares_a_forbidden_field_type(): void {
		foreach ( aafm_activity_detail_map() as $ability => $entry ) {
			foreach ( $entry['args'] ?? array() as $field ) {
				$this->assertContains(
					$field['type'],
					array( 'id', 'key', 'slug', 'enum', 'count' ),
					"{$ability} declares field type '{$field['type']}', which is not identifier-safe."
				);
			}
		}
	}

	public function test_no_result_id_is_a_bare_top_level_key(): void {
		// No create ability in this plugin returns its id at the top level; they all wrap the
		// payload (post.id, user.id, revision.id). A result_id with no dot is therefore almost
		// certainly the bug an earlier draft of this plan shipped. If a genuinely flat payload ever
		// appears, delete this test deliberately and say why in the commit body.
		foreach ( aafm_activity_detail_map() as $ability => $entry ) {
			if ( ! isset( $entry['result_id'] ) ) {
				continue;
			}
			$this->assertStringContainsString(
				'.',
				(string) $entry['result_id'],
				"{$ability} declares a bare result_id; the real return shape wraps the payload."
			);
		}
	}

	public function test_every_template_expects_exactly_the_arguments_the_builder_passes_it(): void {
		// vsprintf()/sprintf() throw ArgumentCountError when a template asks for more values than
		// it is handed, and ValueError on an unknown conversion. Either one would escape the
		// builder and break the call it was only meant to describe, so the arity is pinned per
		// entry rather than left to whoever hand-adds the remaining map entries.
		foreach ( aafm_activity_detail_map() as $ability => $entry ) {
			$scan = $this->sprintf_conversions( (string) $entry['template'] );

			$this->assertSame(
				'',
				$scan['residue'],
				"{$ability}'s template carries a percent sign that is not a valid conversion or a %% literal."
			);

			if ( isset( $entry['args'] ) ) {
				$this->assertSame(
					count( $entry['args'] ),
					$scan['arity'],
					"{$ability} passes " . count( $entry['args'] ) . ' argument(s) but its template expects ' . $scan['arity'] . '.'
				);
			}

			if ( isset( $entry['result_id'] ) ) {
				$this->assertSame(
					1,
					$scan['arity'],
					"{$ability} passes one resolved id but its template expects {$scan['arity']} argument(s)."
				);
			}
		}
	}

	/**
	 * Count how many arguments a sprintf template demands, and report anything left over.
	 *
	 * Positional specs (%1$s) are counted by their highest index, plain ones (%s) by how many
	 * appear, and a template mixing both needs whichever is larger. %% is a literal percent and
	 * is stripped before either count so it can never be mistaken for a conversion.
	 *
	 * @param string $template The template to scan.
	 * @return array{arity:int,residue:string}
	 */
	private function sprintf_conversions( string $template ): array {
		$scan       = str_replace( '%%', '', $template );
		$sequential = 0;
		$highest    = 0;

		$residue = preg_replace_callback(
			'/%(?:(\d+)\$)?(?:[-+ 0#]|\'.)*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/',
			static function ( array $spec ) use ( &$sequential, &$highest ): string {
				if ( isset( $spec[1] ) && '' !== $spec[1] ) {
					$highest = max( $highest, (int) $spec[1] );
				} else {
					++$sequential;
				}
				return '';
			},
			$scan
		);

		return array(
			'arity'   => max( $highest, $sequential ),
			'residue' => (string) preg_replace( '/[^%]/', '', (string) $residue ),
		);
	}

	public function test_no_map_entry_declares_more_than_one_linkable_id(): void {
		foreach ( aafm_activity_detail_map() as $ability => $entry ) {
			if ( ! isset( $entry['link'] ) ) {
				continue;
			}
			$ids = 0;
			foreach ( $entry['args'] ?? array() as $field ) {
				$ids += ( 'id' === $field['type'] ) ? 1 : 0;
			}
			$ids += isset( $entry['result_id'] ) ? 1 : 0;
			$this->assertSame( 1, $ids, "{$ability} declares {$ids} linkable ids; 146 section 6.3 allows one." );
		}
	}

	public function test_a_logged_call_carries_its_detail_end_to_end(): void {
		$id = aafm_log_activity(
			array(
				'ability'  => 'aafm/update-post-meta',
				'status'   => 'started',
				'arg_keys' => array( 'meta_key', 'post_id', 'meta_value' ),
				'detail'   => aafm_build_activity_detail(
					'aafm/update-post-meta',
					array(
						'meta_key'   => '_desc', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
						'post_id'    => 482,
						'meta_value' => 'payload', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- test fixture: ability-input array key, not a meta query.
					)
				),
			)
		);
		aafm_update_activity_status( $id, 'success' );

		$row = $this->row( $id );
		$this->assertSame( 'Updated meta key `_desc` on post #482', $row['detail'] );
		$this->assertSame( 'ability_call', $row['event_type'] );
		$this->assertStringNotContainsString( 'payload', (string) $row['detail'] );
		$this->assertStringContainsString( 'meta_value', (string) $row['arg_keys'] );
	}

	/**
	 * The registration wrapper, not the caller, is what puts the detail on the row.
	 *
	 * The test above proves the builder and the column agree; this one proves the call path
	 * actually reaches for the builder, which is the only thing that makes the feature real for
	 * an agent's call.
	 */
	public function test_the_call_path_builds_the_detail_from_the_arguments(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$post_id = self::factory()->post->create();
		$this->register_enabled( array( 'aafm/update-post-meta' ) );
		aafm_clear_activity_log();

		$result = wp_get_ability( 'aafm/update-post-meta' )->execute(
			array(
				'post_id'  => $post_id,
				'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ability-input array key, not a meta query.
				'value'    => 'CONFIDENTIAL PAYLOAD',
			)
		);
		$this->assertIsArray( $result, 'update-post-meta returned an error; fix the input before asserting on detail.' );

		$row = $this->latest_row();
		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( "Updated meta key `subtitle` on post #{$post_id}", $row['detail'] );
		$this->assertStringContainsString( 'value', (string) $row['arg_keys'] );
		$this->assertRowIsFreeOfTheValue( $row );
	}

	/**
	 * A refused call is the row an operator most wants a detail on, and it is the one built from
	 * caller input the ability's own permission check has just rejected.
	 */
	public function test_a_denied_call_still_records_what_it_tried_to_touch(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$post_id = self::factory()->post->create();
		$this->register_enabled( array( 'aafm/update-post-meta' ) );

		// Registration is done; drop to a role that cannot edit the post, so the decorated
		// permission callback denies and the execute callback never runs.
		$this->acting_as( 'subscriber' );
		aafm_clear_activity_log();

		$result = wp_get_ability( 'aafm/update-post-meta' )->execute(
			array(
				'post_id'  => $post_id,
				'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ability-input array key, not a meta query.
				'value'    => 'CONFIDENTIAL PAYLOAD',
			)
		);
		$this->assertWPError( $result, 'The call must be refused, or this is not testing the denial site.' );

		$row = $this->latest_row();
		$this->assertSame( 'denied', $row['status'] );
		$this->assertSame( "Updated meta key `subtitle` on post #{$post_id}", $row['detail'] );
		$this->assertStringContainsString( 'value', (string) $row['arg_keys'] );
		$this->assertRowIsFreeOfTheValue( $row );
		$this->assertSame( '', get_post_meta( $post_id, 'subtitle', true ), 'The denied write must not have happened.' );
	}

	/**
	 * A create's identifier does not exist until the call returns, so the resolve path has to
	 * carry it.
	 */
	public function test_the_call_path_builds_the_detail_from_the_result(): void {
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/create-page' ) );
		aafm_clear_activity_log();

		$result = wp_get_ability( 'aafm/create-page' )->execute(
			array(
				'title'   => 'Some Page',
				'content' => 'body',
			)
		);
		$this->assertIsArray( $result, 'create-page returned an error; fix the input before asserting on detail.' );

		$row = $this->latest_row();
		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( 'Created page #' . (int) $result['post']['id'], $row['detail'] );
	}

	/**
	 * An ability the map says nothing about still logs, and still logs no detail.
	 */
	public function test_an_unmapped_ability_logs_a_null_detail(): void {
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/get-posts' ) );
		aafm_clear_activity_log();

		wp_get_ability( 'aafm/get-posts' )->execute( array() );

		$row = $this->latest_row();
		$this->assertSame( 'success', $row['status'] );
		$this->assertNull( $row['detail'] );
	}

	/**
	 * F3: create-post's detail reads the real return shape, not a hand-built fixture.
	 *
	 * Calling the real executor is what would have caught the bare `id` bug an earlier draft of
	 * this map shipped: aafm_exec_create_post() delegates to the same aafm_insert_post() as
	 * create-page, wrapping its payload under `post`.
	 */
	public function test_create_post_detail_reads_the_real_return_shape(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = aafm_exec_create_post(
			array(
				'title'   => 'Some Post',
				'content' => 'body',
			)
		);
		$this->assertIsArray( $result, 'create-post returned a WP_Error; fix the input before asserting on detail.' );

		$this->assertSame(
			'Created post #' . (int) $result['post']['id'],
			aafm_build_activity_detail_from_result( 'aafm/create-post', $result )
		);
	}

	/**
	 * F3: create-draft's detail reads the real return shape.
	 */
	public function test_create_draft_detail_reads_the_real_return_shape(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = aafm_exec_create_draft(
			array(
				'title'   => 'Some Draft',
				'content' => 'body',
			)
		);
		$this->assertIsArray( $result, 'create-draft returned a WP_Error; fix the input before asserting on detail.' );

		$this->assertSame(
			'Created draft #' . (int) $result['post']['id'],
			aafm_build_activity_detail_from_result( 'aafm/create-draft', $result )
		);
	}

	/**
	 * F3: create-user's detail reads the real return shape (`user.id`, not a top-level `id`).
	 */
	public function test_create_user_detail_reads_the_real_return_shape(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = aafm_exec_create_user(
			array(
				'username' => 'aafm-detail-test-user',
				'email'    => 'aafm-detail-test-user@example.com',
			)
		);
		$this->assertIsArray( $result, 'create-user returned a WP_Error; fix the input before asserting on detail.' );
		$this->assertArrayHasKey( 'user', $result, 'The payload is wrapped under `user`; result_id must be a path into it.' );

		$this->assertSame(
			'Created user #' . (int) $result['user']['id'],
			aafm_build_activity_detail_from_result( 'aafm/create-user', $result )
		);
	}

	public function test_delete_post_meta_detail_renders_the_template(): void {
		$this->assertSame(
			'Deleted meta key `subtitle` on post #55',
			aafm_build_activity_detail(
				'aafm/delete-post-meta',
				array(
					'meta_key' => 'subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'post_id'  => 55,
				)
			)
		);
	}

	public function test_update_post_detail_renders_the_template(): void {
		$this->assertSame(
			'Updated post #310',
			aafm_build_activity_detail( 'aafm/update-post', array( 'post_id' => 310 ) )
		);
	}

	/**
	 * The update-page entry uses page_id, NOT post_id - aafm_args_update_page() names its own id
	 * field distinctly from aafm/update-post's post_id even though both edit a wp_posts row.
	 */
	public function test_update_page_detail_renders_the_template(): void {
		$this->assertSame(
			'Updated page #77',
			aafm_build_activity_detail( 'aafm/update-page', array( 'page_id' => 77 ) )
		);
	}

	public function test_delete_post_detail_renders_the_template(): void {
		$this->assertSame(
			'Deleted post #44',
			aafm_build_activity_detail( 'aafm/delete-post', array( 'post_id' => 44 ) )
		);
	}

	public function test_delete_page_detail_renders_the_template(): void {
		$this->assertSame(
			'Deleted page #99',
			aafm_build_activity_detail( 'aafm/delete-page', array( 'page_id' => 99 ) )
		);
	}

	public function test_trash_post_detail_renders_the_template(): void {
		$this->assertSame(
			'Trashed post #12',
			aafm_build_activity_detail( 'aafm/trash-post', array( 'post_id' => 12 ) )
		);
	}

	public function test_trash_page_detail_renders_the_template(): void {
		$this->assertSame(
			'Trashed page #13',
			aafm_build_activity_detail( 'aafm/trash-page', array( 'page_id' => 13 ) )
		);
	}

	/**
	 * The restore-revision entry declares only revision_id, even though the ability also requires
	 * post_id - the map names the one identifier worth showing, not the whole schema.
	 */
	public function test_restore_revision_detail_renders_the_template(): void {
		$this->assertSame(
			'Restored revision #501',
			aafm_build_activity_detail(
				'aafm/restore-revision',
				array(
					'post_id'     => 310,
					'revision_id' => 501,
				)
			)
		);
	}

	public function test_update_user_detail_renders_the_template(): void {
		$this->assertSame(
			'Updated user #8',
			aafm_build_activity_detail( 'aafm/update-user', array( 'user_id' => 8 ) )
		);
	}

	public function test_delete_user_detail_renders_the_template(): void {
		$this->assertSame(
			'Deleted user #9',
			aafm_build_activity_detail( 'aafm/delete-user', array( 'user_id' => 9 ) )
		);
	}

	public function test_wc_create_order_refund_detail_renders_the_template(): void {
		$this->assertSame(
			'Refunded order #2001',
			aafm_build_activity_detail( 'aafm/wc-create-order-refund', array( 'order_id' => 2001 ) )
		);
	}

	/**
	 * The wc-update-payment-gateway identifier is a slug (type `key`), never a numeric id, and
	 * the entry declares no link - there is no post/user/term/order to point a gateway at.
	 */
	public function test_wc_update_payment_gateway_detail_renders_the_template(): void {
		$this->assertSame(
			'Updated payment gateway `stripe`',
			aafm_build_activity_detail( 'aafm/wc-update-payment-gateway', array( 'gateway_id' => 'stripe' ) )
		);
		$this->assertNull( aafm_activity_detail_link_type( 'aafm/wc-update-payment-gateway' ) );
	}

	// -------------------------------------------------------------------------
	// The crash detail builder: the one writer that is not map-driven.
	// -------------------------------------------------------------------------

	/**
	 * A vendor exception message routinely interpolates the value that caused it, and the wp.org
	 * listing promises argument values are never stored. The builder must never read it.
	 */
	public function test_exception_detail_never_contains_the_message(): void {
		$e = new \RuntimeException( 'An account is already registered with jane.doe@clientsite.example.' );

		$detail = aafm_build_activity_detail_from_exception( $e );

		$this->assertStringNotContainsString( 'jane.doe@clientsite.example', $detail );
		$this->assertStringNotContainsString( 'already registered', $detail );
		$this->assertStringStartsWith( 'RuntimeException at ', $detail );
	}

	public function test_exception_detail_strips_the_anonymous_class_path_and_nul_byte(): void {
		$e = new class() extends \RuntimeException {};

		$detail = aafm_build_activity_detail_from_exception( $e );

		$this->assertStringNotContainsString( "\0", $detail, 'A NUL byte is valid UTF-8, so the existing sanitizer will not remove it.' );
		$this->assertStringNotContainsString( '/', $detail, 'PHP renders an anonymous class as Parent@anonymous\0<absolute path>, which leaks the install path.' );
		$this->assertStringStartsWith( 'RuntimeException@anonymous at ', $detail );
	}

	/**
	 * The bound has to be exercised by parts the builder actually copies. The previous version of
	 * this test fed in a 5000-character MESSAGE, which the builder never reads, so it passed for a
	 * reason unrelated to its name and stayed green with BOTH mb_substr() caps deleted - it was
	 * really just a weaker copy of the never-contains-the-message test above.
	 *
	 * The fixture supplies a class name over 128 characters and a file name over 64, so each cap is
	 * the only thing keeping its half short.
	 */
	public function test_exception_detail_is_bounded(): void {
		require_once dirname( __DIR__ ) . '/Fixtures/AnOverlongFixtureFileNameThatExercisesTheAuditDetailFileNameCap.php';
		$e = \AAFM\Tests\Fixtures\ExceptionWithADeliberatelyOverlongClassNameThatExistsOnlyToExerciseTheOneHundredAndTwentyEightCharacterCapOfTheAuditDetailBuilder::raised_here();

		$this->assertGreaterThan( 128, strlen( get_class( $e ) ), 'Guard on the guard: the fixture class name must exceed the cap.' );
		$this->assertGreaterThan( 64, strlen( basename( $e->getFile() ) ), 'Guard on the guard: the fixture file name must exceed the cap.' );

		$detail = aafm_build_activity_detail_from_exception( $e );

		$this->assertLessThanOrEqual( 255, strlen( $detail ) );

		$parts = explode( ' at ', $detail );
		$this->assertCount( 2, $parts );
		$this->assertSame( 128, strlen( $parts[0] ), 'The class is cut at 128.' );
		$this->assertSame( 64, strlen( substr( $parts[1], 0, strrpos( $parts[1], ':' ) ) ), 'The file is cut at 64.' );
	}

	/**
	 * The file-name filter's documented job is absorbing the suffix PHP appends to getFile() when a
	 * throw happens inside runtime-evaluated code: "/abs/path/File.php(91) : eval()'d code". Nothing
	 * exercised it - the anonymous-class test is already past the filter by the time it runs, since
	 * the NUL cut has done the work and neither half contains a character the filter would touch.
	 *
	 * Asserting the whole shape rather than the absence of one substring, because what has to hold
	 * is that the file half is a file name, not that one known suffix is missing from it.
	 */
	public function test_a_throw_from_evaluated_code_records_a_bare_file_name(): void {
		$caught = null;
		try {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_eval -- the only way to make PHP produce the getFile() suffix this filter exists to absorb.
			eval( 'throw new \RuntimeException( "thrown from evaluated code" );' );
		} catch ( \RuntimeException $e ) {
			$caught = $e;
		}

		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertStringContainsString(
			'eval',
			$caught->getFile(),
			'Guard on the guard: PHP must actually be appending the suffix, or this test proves nothing.'
		);

		$this->assertMatchesRegularExpression(
			'/^[A-Za-z0-9_\\\\@~]+ at [A-Za-z0-9._\-]+:\d+$/',
			aafm_build_activity_detail_from_exception( $caught ),
			'The file half must be a bare file name: no path, no parentheses, no quotes, no prose.'
		);
	}

	/**
	 * PHP allows bytes \x80-\xff in a class name, so the character filter can quietly turn one real
	 * class into a DIFFERENT real one and point a forensic reader at the wrong code. The filter
	 * still runs - an audit row is no place for arbitrary bytes - but the result is marked, so a
	 * reader can tell a recorded name from a recorded name that is only close.
	 */
	public function test_a_class_name_the_filter_had_to_cut_is_marked_as_lossy(): void {
		$e = new \RuntimeException( 'anything' );

		$this->assertStringStartsWith(
			'RuntimeException at ',
			aafm_build_activity_detail_from_exception( $e ),
			'An ordinary ASCII class name loses nothing, so it must NOT be marked.'
		);

		require_once dirname( __DIR__ ) . '/Fixtures/NonAsciiClassNameException.php';
		$class   = "AAFM\\Tests\\Fixtures\\Excep\xc3\xa9tion";
		$unicode = new $class( 'anything' );

		$this->assertStringStartsWith(
			'AAFM\Tests\Fixtures\Exception~ at ',
			aafm_build_activity_detail_from_exception( $unicode ),
			'Without the marker this reads as a plain AAFM\Tests\Fixtures\Exception, which is a different class a reader would go looking for.'
		);
	}

	public function test_exception_detail_names_the_throw_site(): void {
		$e = new \RuntimeException( 'anything' );

		$this->assertSame(
			sprintf( 'RuntimeException at %1$s:%2$d', basename( __FILE__ ), $e->getLine() ),
			aafm_build_activity_detail_from_exception( $e )
		);
	}

	/**
	 * An ordinary WP_Error result records its CODE, which is an identifier, and never its message,
	 * which is free-form prose that can interpolate the value that failed.
	 */
	public function test_a_wp_error_result_yields_its_code_not_its_message(): void {
		$error = new \WP_Error( 'aafm_wc_invalid_coupon_amount', 'A percentage coupon cannot discount more than 100 percent, and this would leave it at "150".' );

		$detail = aafm_build_activity_detail_from_result( 'aafm/get-post', $error );

		$this->assertSame( 'aafm_wc_invalid_coupon_amount', $detail );
		$this->assertStringNotContainsString( '150', (string) $detail );
	}

	/**
	 * "A code is an identifier by construction" is true of our own codes and only our own: every
	 * `new WP_Error(` under includes/ takes a string literal. A foreign plugin's is third-party code
	 * free to build a code out of its input, and `duplicate_sku_ABC-123-CUSTOMER` clears the key
	 * type's character check with room to spare. That is an argument value in the audit column, on
	 * the wire through aafm/get-activity-log, and in the CSV export, against a promise this file's
	 * header, the aafm_ability_resolved docblock and the admin panel all make in so many words.
	 *
	 * So bridged results skip the branch. Both halves are asserted here, because a test that only
	 * pins the exclusion would also pass if the whole branch were deleted.
	 */
	public function test_a_bridged_error_code_is_not_recorded_but_a_first_party_one_is(): void {
		$foreign = new \WP_Error( 'duplicate_sku_ABC-123-CUSTOMER', 'That SKU already exists.' );

		$this->assertNull(
			aafm_build_activity_detail_from_result( 'aafm-bridge/woocommerce-product-create', $foreign ),
			'A foreign plugin composes its own error codes, so one cannot be trusted as an identifier.'
		);
		$this->assertSame(
			'duplicate_sku_ABC-123-CUSTOMER',
			aafm_build_activity_detail_from_result( 'aafm/create-post', $foreign ),
			'Guard on the guard: this code does clear the key check, so the exclusion above is what dropped it.'
		);
	}

	/**
	 * The argument VALUE must appear in no column of the row, not just in the detail.
	 *
	 * @param array<string,mixed> $row An activity row.
	 * @return void
	 */
	private function assertRowIsFreeOfTheValue( array $row ): void {
		foreach ( $row as $column => $value ) {
			$this->assertStringNotContainsString(
				'CONFIDENTIAL',
				(string) $value,
				"The argument value leaked into the {$column} column."
			);
		}
	}

	/**
	 * Read one activity row by id.
	 *
	 * @param int $row_id Row id.
	 * @return array<string,mixed>
	 */
	private function row( int $row_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', aafm_activity_log_table(), $row_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}

	/**
	 * Read the most recently written activity row.
	 *
	 * @return array<string,mixed>
	 */
	private function latest_row(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', aafm_activity_log_table() ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}
}
