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
				array( 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => 'seo_title' )
			)
		);
		$this->assertSame(
			array(
				'term_id'  => (int) $term_id,
				'meta_key' => 'seo_title',
				'value'    => 'Hello',
			),
			aafm_exec_get_term_meta(
				array( 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => 'seo_title' )
			)
		);
		// Non-allowlisted key is denied at the gate.
		$this->assertFalse(
			aafm_perm_get_term_meta(
				array( 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => 'unlisted' )
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
				array( 'taxonomy' => 'aafm_readlock', 'term_id' => $term_id, 'meta_key' => 'seo_title' )
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
				array( 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => 'blob' )
			)
		);
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	public function test_update_term_meta_writes_allowlisted_scalar(): void {
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->acting_as( 'editor' );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$result = aafm_exec_update_term_meta(
			array( 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => 'seo_title', 'value' => 'New title' )
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
				array( 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => 'anything', 'value' => 'x' )
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
				array( 'taxonomy' => 'aafm_locked', 'term_id' => $term_id, 'meta_key' => 'seo_title', 'value' => 'x' )
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
			array( 'deleted' => true ),
			aafm_exec_delete_term_meta(
				array( 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => 'seo_title' )
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
				array( 'taxonomy' => 'aafm_locked2', 'term_id' => $term_id, 'meta_key' => 'seo_title' )
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
}
