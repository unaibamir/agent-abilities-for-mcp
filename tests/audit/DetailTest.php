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
}
