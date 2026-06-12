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
		register_post_type( 'aafm_book', array( 'public' => true, 'label' => 'Books' ) );
		$this->assertTrue( aafm_post_type_is_eligible( 'post' ) );
		$this->assertTrue( aafm_post_type_is_eligible( 'page' ) );
		$this->assertTrue( aafm_post_type_is_eligible( 'aafm_book' ) );
	}

	public function test_eligibility_floor_rejects_builtin_internal_and_private(): void {
		register_post_type( 'aafm_secret', array( 'public' => false, 'label' => 'Secret' ) );
		// attachment is public AND built-in — the one public-but-internal case.
		$this->assertFalse( aafm_post_type_is_eligible( 'attachment' ) );
		$this->assertFalse( aafm_post_type_is_eligible( 'revision' ) );
		$this->assertFalse( aafm_post_type_is_eligible( 'nav_menu_item' ) );
		$this->assertFalse( aafm_post_type_is_eligible( 'aafm_secret' ) );
		$this->assertFalse( aafm_post_type_is_eligible( 'totally_fake' ) );
	}

	public function test_eligible_post_types_lists_public_non_builtin(): void {
		register_post_type( 'aafm_book', array( 'public' => true, 'label' => 'Books' ) );
		$eligible = aafm_eligible_post_types();
		$this->assertContains( 'aafm_book', $eligible );
		$this->assertContains( 'post', $eligible );
		$this->assertNotContains( 'attachment', $eligible );
		$this->assertNotContains( 'revision', $eligible );
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
}
