<?php
/**
 * Validation allowlists + redaction + pagination + generic error.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;
use WP_Error;
use WP_Post_Type;

final class HelpersTest extends TestCase {

	public function test_post_type_allowlist_accepts_public_type(): void {
		$this->assertSame( 'post', aafm_validate_post_type( 'post' ) );
		$this->assertSame( 'page', aafm_validate_post_type( 'page' ) );
	}

	public function test_post_type_allowlist_rejects_unknown_or_private(): void {
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'nav_menu_item' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'totally_fake' ) );
		// `attachment` is public AND built-in. It must never pass the content floor —
		// media has its own redacted path; the content abilities must not touch it.
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'attachment' ) );
		// Other built-in internal types stay rejected even though some are queryable.
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'revision' ) );
	}

	public function test_eligibility_floor_accepts_post_page_and_public_cpt(): void {
		register_post_type(
			'aafm_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);
		$this->assertTrue( aafm_post_type_is_eligible( 'post' ) );
		$this->assertTrue( aafm_post_type_is_eligible( 'page' ) );
		$this->assertTrue( aafm_post_type_is_eligible( 'aafm_book' ) );
	}

	public function test_eligibility_floor_rejects_builtin_internal_and_private(): void {
		register_post_type(
			'aafm_secret',
			array(
				'public' => false,
				'label'  => 'Secret',
			)
		);
		// attachment is public AND built-in — the one public-but-internal case.
		$this->assertFalse( aafm_post_type_is_eligible( 'attachment' ) );
		$this->assertFalse( aafm_post_type_is_eligible( 'revision' ) );
		$this->assertFalse( aafm_post_type_is_eligible( 'nav_menu_item' ) );
		$this->assertFalse( aafm_post_type_is_eligible( 'aafm_secret' ) );
		$this->assertFalse( aafm_post_type_is_eligible( 'totally_fake' ) );
	}

	public function test_eligible_post_types_lists_public_non_builtin(): void {
		register_post_type(
			'aafm_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);
		$eligible = aafm_eligible_post_types();
		$this->assertContains( 'aafm_book', $eligible );
		$this->assertContains( 'post', $eligible );
		$this->assertNotContains( 'attachment', $eligible );
		$this->assertNotContains( 'revision', $eligible );
	}

	public function test_allowlist_defaults_to_post_and_page_only(): void {
		delete_option( 'aafm_allowed_post_types' );
		$allowed = aafm_allowed_post_types();
		$this->assertContains( 'post', $allowed );
		$this->assertContains( 'page', $allowed );
		$this->assertCount( 2, $allowed );
	}

	public function test_allowlist_adds_opted_in_eligible_type(): void {
		register_post_type(
			'aafm_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) );
		$allowed = aafm_allowed_post_types();
		$this->assertContains( 'aafm_book', $allowed );
		$this->assertContains( 'post', $allowed ); // post/page always forced on.
	}

	public function test_allowlist_floor_strips_injected_ineligible_types(): void {
		// A junk option write (or a rogue filter) must never get attachment/revision through.
		update_option( 'aafm_allowed_post_types', array( 'attachment', 'revision', 'totally_fake' ) );
		$allowed = aafm_allowed_post_types();
		$this->assertNotContains( 'attachment', $allowed );
		$this->assertNotContains( 'revision', $allowed );
		$this->assertNotContains( 'totally_fake', $allowed );
		$this->assertSame( array( 'post', 'page' ), $allowed );
	}

	public function test_validate_denies_eligible_cpt_until_allowlisted(): void {
		register_post_type(
			'aafm_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);
		delete_option( 'aafm_allowed_post_types' );
		// Eligible but not opted in → denied.
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'aafm_book' ) );
		// Opted in → passes.
		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) );
		$this->assertSame( 'aafm_book', aafm_validate_post_type( 'aafm_book' ) );
	}

	public function test_validate_floor_beats_a_forced_ineligible_allowlist_entry(): void {
		// Even if attachment is jammed into the option, the floor in validate rejects it.
		update_option( 'aafm_allowed_post_types', array( 'attachment' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'attachment' ) );
	}

	public function test_type_caps_reports_mapped_flag(): void {
		register_post_type(
			'aafm_book',
			array(
				'public'          => true,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
				'label'           => 'Books',
			)
		);
		register_post_type(
			'aafm_unmapped',
			array(
				'public'          => true,
				'map_meta_cap'    => false,
				'capability_type' => array( 'aafm_unmapped', 'aafm_unmappeds' ),
				'label'           => 'Unmapped',
			)
		);
		$this->assertTrue( aafm_type_caps( 'aafm_book' )['mapped'] );
		$this->assertFalse( aafm_type_caps( 'aafm_unmapped' )['mapped'] );
		$this->assertInstanceOf( WP_Post_Type::class, aafm_type_caps( 'post' )['object'] );
	}

	public function test_edit_gate_refuses_writes_to_non_mapped_type(): void {
		register_post_type(
			'aafm_unmapped',
			array(
				'public'          => true,
				'map_meta_cap'    => false,
				'capability_type' => array( 'aafm_unmapped', 'aafm_unmappeds' ),
				'label'           => 'Unmapped',
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'aafm_unmapped' ) );

		// Grant an administrator the bare singular caps — the footgun would let this through.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$role  = get_role( 'administrator' );
		$role->add_cap( 'edit_aafm_unmapped' );
		$role->add_cap( 'delete_aafm_unmapped' );
		wp_set_current_user( $admin );

		$id   = self::factory()->post->create(
			array(
				'post_type'   => 'aafm_unmapped',
				'post_status' => 'publish',
			)
		);
		$post = get_post( $id );

		$this->assertFalse( aafm_can_edit_post_object( $post ), 'Non-mapped CPT writes must be refused.' );
		$this->assertFalse( aafm_can_delete_post_object( $post ), 'Non-mapped CPT deletes must be refused.' );

		$role->remove_cap( 'edit_aafm_unmapped' );
		$role->remove_cap( 'delete_aafm_unmapped' );
	}

	public function test_read_gate_allows_public_status_of_allowlisted_type(): void {
		register_post_type(
			'aafm_book',
			array(
				'public'          => true,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
				'label'           => 'Books',
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) );
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'aafm_book',
				'post_status' => 'publish',
			)
		);
		$this->assertTrue( aafm_can_read_post_object( get_post( $id ) ) );
	}

	public function test_read_gate_denies_non_allowlisted_type(): void {
		register_post_type(
			'aafm_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);
		delete_option( 'aafm_allowed_post_types' ); // not opted in.
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'aafm_book',
				'post_status' => 'publish',
			)
		);
		$this->assertFalse( aafm_can_read_post_object( get_post( $id ) ) );
	}

	public function test_taxonomy_allowlist(): void {
		$this->assertSame( 'category', aafm_validate_taxonomy( 'category' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_taxonomy( 'nav_menu' ) );
	}

	public function test_status_guard_blocks_any_and_widening(): void {
		// 'any' must never resolve to a usable status — this is the royal-mcp bypass.
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_status( 'any', false ) );
		// A low-priv caller cannot request private content.
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_status( 'private', false ) );
		// Public statuses are fine.
		$this->assertSame( 'publish', aafm_validate_post_status( 'publish', false ) );
		// Privileged caller may request private.
		$this->assertSame( 'private', aafm_validate_post_status( 'private', true ) );
	}

	public function test_redact_user_exposes_no_pii(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_email'   => 'secret@example.com',
				'user_login'   => 'secretlogin',
				'display_name' => 'Public Name',
				'user_url'     => 'https://example.com',
			)
		);
		$out     = aafm_redact_user( get_userdata( $user_id ) );

		$this->assertSame( 'Public Name', $out['display_name'] );
		$this->assertSame( $user_id, $out['id'] );
		$this->assertArrayHasKey( 'roles', $out );
		$json = wp_json_encode( $out );
		$this->assertStringNotContainsString( 'secret@example.com', $json );
		$this->assertStringNotContainsString( 'secretlogin', $json );
		$this->assertArrayNotHasKey( 'user_email', $out );
		$this->assertArrayNotHasKey( 'user_login', $out );
	}

	public function test_redact_comment_drops_email_and_ip(): void {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_author'       => 'Jane',
				'comment_author_email' => 'jane@example.com',
				'comment_author_IP'    => '203.0.113.9',
				'comment_content'      => 'hello',
			)
		);
		$out        = aafm_redact_comment( get_comment( $comment_id ) );
		$json       = wp_json_encode( $out );

		$this->assertSame( 'Jane', $out['author_name'] );
		$this->assertStringNotContainsString( 'jane@example.com', $json );
		$this->assertStringNotContainsString( '203.0.113.9', $json );
	}

	public function test_pagination_is_bounded(): void {
		$args = aafm_paginate_args(
			array(
				'per_page' => 9999,
				'page'     => 0,
			),
			50
		);
		$this->assertSame( 50, $args['per_page'] );
		$this->assertSame( 1, $args['page'] );
	}

	public function test_generic_error_leaks_nothing(): void {
		$err = aafm_generic_error();
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'aafm_error', $err->get_error_code() );
	}

	public function test_hard_block_catches_protected_auth_and_cap_keys(): void {
		global $wpdb;
		foreach ( array(
			'_thumbnail_id', '_edit_lock', 'session_tokens', '_application_passwords',
			'wp_capabilities', 'wp_user_level', 'default_password_nonce', '_new_email',
			$wpdb->prefix . 'capabilities', $wpdb->prefix . 'user_level',
			$wpdb->prefix . '2_capabilities', '',
		) as $key ) {
			$this->assertTrue( aafm_hard_blocked_meta_key( $key ), "$key must be hard-blocked" );
		}
		$this->assertFalse( aafm_hard_blocked_meta_key( 'subtitle' ) );
	}

	public function test_hard_block_filter_can_add_but_not_remove(): void {
		add_filter( 'aafm_hard_blocked_meta_keys', static fn( $k ) => array_merge( $k, array( 'company_revenue' ) ) );
		$this->assertTrue( aafm_hard_blocked_meta_key( 'company_revenue' ) );
		// Cannot remove a built-in even by returning [].
		add_filter( 'aafm_hard_blocked_meta_keys', static fn() => array(), 99 );
		$this->assertTrue( aafm_hard_blocked_meta_key( 'wp_capabilities' ) );
		remove_all_filters( 'aafm_hard_blocked_meta_keys' );
	}

	public function test_allowed_meta_keys_default_empty_and_refloors(): void {
		delete_option( 'aafm_allowed_meta_keys' );
		$this->assertSame( array(), aafm_allowed_meta_keys() );
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle', 'wp_capabilities', '_edit_lock' ) );
		$this->assertSame( array( 'subtitle' ), aafm_allowed_meta_keys() ); // hard-blocked stripped on read.
	}

	public function test_validate_meta_key_allowlist_and_block(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );
		$this->assertSame( 'subtitle', aafm_validate_meta_key( 'subtitle' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_meta_key( 'unlisted' ) );
		// Hard-blocked beats a forced allowlist entry.
		update_option( 'aafm_allowed_meta_keys', array( 'wp_capabilities' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_meta_key( 'wp_capabilities' ) );
	}

	public function test_validate_meta_key_uses_one_generic_code(): void {
		update_option( 'aafm_allowed_meta_keys', array() );
		$this->assertSame( 'aafm_meta_key_not_allowed', aafm_validate_meta_key( 'unlisted' )->get_error_code() );
		$this->assertSame( 'aafm_meta_key_not_allowed', aafm_validate_meta_key( '_edit_lock' )->get_error_code() );
	}
}
