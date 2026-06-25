<?php
/**
 * Governed term-meta: default-deny allowlist (oversio_allowed_term_meta_keys), the hard-block
 * floor (reused from post-meta), scalar-only values, and per-object edit_term gating.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use WP_Error;

final class TermMetaTest extends TestCase {

	public function test_allowlist_defaults_empty_then_opts_in(): void {
		$this->assertSame( array(), oversio_allowed_term_meta_keys() );
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->assertSame( array( 'seo_title' ), oversio_allowed_term_meta_keys() );
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
	}

	public function test_validate_key_rejects_unlisted_and_hard_blocked(): void {
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->assertSame( 'seo_title', oversio_validate_term_meta_key( 'seo_title' ) );
		$this->assertInstanceOf( WP_Error::class, oversio_validate_term_meta_key( 'unlisted' ) );
		// A `_`-prefixed protected key is hard-blocked even if someone tried to allowlist it.
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( '_secret' ) );
		$this->assertInstanceOf( WP_Error::class, oversio_validate_term_meta_key( '_secret' ) );
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
	}

	public function test_sanitize_value_refuses_non_scalar(): void {
		$this->assertInstanceOf( WP_Error::class, oversio_sanitize_term_meta_value( 'seo_title', array( 'x' => 1 ) ) );
		$this->assertSame( 'hello', oversio_sanitize_term_meta_value( 'seo_title', 'hello' ) );
	}

	public function test_get_term_meta_happy_path_and_gates(): void {
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term_id, 'seo_title', 'Hello' );

		$this->assertTrue(
			oversio_perm_get_term_meta(
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
			oversio_exec_get_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		// Non-allowlisted key is denied at the gate.
		$this->assertFalse(
			oversio_perm_get_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'unlisted', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
	}

	public function test_get_term_meta_requires_edit_term(): void {
		// EDIT 2: term meta can hold private data, so the read requires per-object edit_term
		// (mirroring get-post-meta's edit_post gate). A low-cap user on a locked taxonomy is denied.
		register_taxonomy(
			'oversio_readlock',
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'edit_terms' => 'manage_options' ),
			)
		);
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' ); // lacks manage_options, so cannot edit_term here.
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'oversio_readlock' ) );
		$this->assertFalse(
			oversio_perm_get_term_meta(
				array(
					'taxonomy' => 'oversio_readlock',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
		unregister_taxonomy( 'oversio_readlock' );
	}

	public function test_get_term_meta_refuses_non_scalar(): void {
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( 'blob' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term_id, 'blob', array( 'x' => 1 ) );
		$this->assertInstanceOf(
			WP_Error::class,
			oversio_exec_get_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'blob', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
	}

	public function test_update_term_meta_writes_allowlisted_scalar(): void {
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$result = oversio_exec_update_term_meta(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				'value'    => 'New title',
			)
		);
		$this->assertSame( 'New title', $result['value'] );
		$this->assertSame( 'New title', get_term_meta( $term_id, 'seo_title', true ) );
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
	}

	public function test_update_term_meta_rejects_non_allowlisted_key(): void {
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		// Empty allowlist (default-deny): every key is rejected.
		$this->assertInstanceOf(
			WP_Error::class,
			oversio_exec_update_term_meta(
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
			'oversio_locked',
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'edit_terms' => 'manage_options' ),
			)
		);
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' ); // lacks manage_options.
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'oversio_locked' ) );
		$this->assertFalse(
			oversio_perm_update_term_meta(
				array(
					'taxonomy' => 'oversio_locked',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
		unregister_taxonomy( 'oversio_locked' );
	}

	public function test_delete_term_meta_removes_allowlisted_key(): void {
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term_id, 'seo_title', 'Bye' );

		$this->assertSame(
			array( 'deleted' => true ),
			oversio_exec_delete_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		$this->assertSame( '', get_term_meta( $term_id, 'seo_title', true ) );
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
	}

	public function test_delete_term_meta_denied_for_low_cap_user(): void {
		register_taxonomy(
			'oversio_locked2',
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'edit_terms' => 'manage_options' ),
			)
		);
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'oversio_locked2' ) );
		$this->assertFalse(
			oversio_perm_delete_term_meta(
				array(
					'taxonomy' => 'oversio_locked2',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
				)
			)
		);
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
		unregister_taxonomy( 'oversio_locked2' );
	}

	public function test_term_meta_writes_discoverable_to_term_editors(): void {
		$this->acting_as( 'editor' );
		foreach ( array( 'oversio/get-term-meta', 'oversio/update-term-meta', 'oversio/delete-term-meta' ) as $name ) {
			$predicate = oversio_ability_list_permission( $name );
			$this->assertIsCallable( $predicate, $name . ' needs a discovery override (object-dependent gate)' );
			$this->assertTrue( $predicate(), $name . ' should be discoverable to an editor' );
		}
		$this->acting_as( 'subscriber' );
		foreach ( array( 'oversio/update-term-meta', 'oversio/delete-term-meta' ) as $name ) {
			$predicate = oversio_ability_list_permission( $name );
			$this->assertFalse( $predicate(), $name . ' must be hidden from a low-cap user' );
		}
	}

	public function test_hard_blocked_key_rejected_even_if_allowlisted(): void {
		// A filter cannot un-block a protected key: it is stripped after the filter.
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( '_edit_lock' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->assertFalse(
			oversio_perm_update_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => '_edit_lock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => 'x',
				)
			)
		);
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
	}

	public function test_update_term_meta_refuses_non_scalar_value(): void {
		add_filter( 'oversio_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->assertInstanceOf(
			WP_Error::class,
			oversio_exec_update_term_meta(
				array(
					'taxonomy' => 'category',
					'term_id'  => $term_id,
					'meta_key' => 'seo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test fixture: ability-input array key, not a meta query.
					'value'    => array( 'x' => 1 ),
				)
			)
		);
		remove_all_filters( 'oversio_allowed_term_meta_keys' );
	}
}
