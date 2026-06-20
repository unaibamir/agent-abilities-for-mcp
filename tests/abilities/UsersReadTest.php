<?php
/**
 * User read abilities: list_users gating + redaction.
 *
 * This is the most PII-sensitive read in the catalog — competitors leaked user
 * data here. The tests prove a low-privilege caller is denied (and audited). Email
 * is exposed by a locked decision (gated upstream by list_users + audited), but the
 * output never carries the login or the password hash.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class UsersReadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions, simulated
		// by pushing the action name onto $wp_current_filter — the idiom WP core's own
		// ability test trait uses. wp_register_ability() refuses to run otherwise.
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-users' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_get_users_is_in_registry(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/get-users', $registry );
		$this->assertSame( 'reads', $registry['aafm/get-users']['group'] );
		$this->assertSame( 'read', $registry['aafm/get-users']['risk'] );
	}

	/**
	 * Only a caller with the list_users capability (the cap WP itself gates the
	 * user list behind) may enumerate users. An author is denied; an admin allowed.
	 */
	public function test_requires_list_users_cap(): void {
		$this->acting_as( 'author' );
		$this->assertFalse( wp_get_ability( 'aafm/get-users' )->check_permissions( array() ) );

		$this->acting_as( 'administrator' );
		$this->assertTrue( wp_get_ability( 'aafm/get-users' )->check_permissions( array() ) );
	}

	/**
	 * A denied user-enumeration attempt writes a `denied` audit row via the
	 * registration wrapper's permission decorator — proven on the live path.
	 */
	public function test_denied_enumeration_is_audited(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( wp_get_ability( 'aafm/get-users' )->check_permissions( array() ) );

		$rows  = aafm_query_activity( array( 'status' => 'denied' ) );
		$names = wp_list_pluck( $rows, 'ability' );
		$this->assertContains( 'aafm/get-users', $names );
	}

	/**
	 * LOCKED reversal (47- line 144): user email IS exposed in the redacted shape
	 * now, gated upstream by list_users + audited. Login and the password hash stay
	 * stripped — those are NEVER returned. Builds a user with a known email/login and
	 * asserts the email is present while login/hash are absent, and that the per-row
	 * shape carries the safe whitelist (id, display_name, email, roles, post_count).
	 */
	public function test_output_exposes_email_but_never_login_or_password(): void {
		$this->acting_as( 'administrator' );
		$uid  = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_email'   => 'show@example.com',
				'user_login'   => 'leaklogin',
				'user_pass'    => 'SuperSecretPlainPass',
				'display_name' => 'Visible Display Name',
			)
		);
		$hash = get_userdata( $uid )->user_pass;

		$out  = wp_get_ability( 'aafm/get-users' )->execute( array() );
		$json = (string) wp_json_encode( $out );

		// Email IS exposed (locked reversal); login and the password hash never are.
		$this->assertStringContainsString( 'show@example.com', $json );
		$this->assertStringNotContainsString( 'leaklogin', $json );
		$this->assertStringNotContainsString( $hash, $json );

		// Per-row shape: the safe whitelist incl. email; login/pass structurally absent.
		foreach ( $out['users'] as $u ) {
			$this->assertSame(
				array( 'id', 'display_name', 'email', 'roles', 'post_count' ),
				array_keys( $u )
			);
			$this->assertArrayNotHasKey( 'user_login', $u );
			$this->assertArrayNotHasKey( 'user_pass', $u );
			$this->assertArrayHasKey( 'display_name', $u );
		}
	}

	/**
	 * The role filter narrows the result set without ever widening the field shape.
	 */
	public function test_role_filter_narrows_results(): void {
		$this->acting_as( 'administrator' );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$out = wp_get_ability( 'aafm/get-users' )->execute( array( 'role' => 'editor' ) );
		$ids = wp_list_pluck( $out['users'], 'id' );

		$this->assertContains( $editor, $ids );
		foreach ( $out['users'] as $u ) {
			$this->assertContains( 'editor', $u['roles'] );
		}
	}

	/**
	 * Post counts must stay accurate after the batch-count refactor: each user's
	 * count is their own post total, never another user's.
	 */
	public function test_post_count_is_accurate_per_user(): void {
		$admin    = $this->acting_as( 'administrator' );
		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );

		self::factory()->post->create_many( 3, array( 'post_author' => $author_a ) );
		self::factory()->post->create( array( 'post_author' => $author_b ) );

		$out    = wp_get_ability( 'aafm/get-users' )->execute( array() );
		$counts = array();
		foreach ( $out['users'] as $u ) {
			$counts[ $u['id'] ] = $u['post_count'];
		}

		$this->assertSame( 3, $counts[ $author_a ] );
		$this->assertSame( 1, $counts[ $author_b ] );
		// The acting admin authored nothing in this test.
		$this->assertSame( 0, $counts[ $admin ] );
	}

	/**
	 * No N+1: the post counts are resolved in one batched query, so the total query
	 * count for the listing does not grow per user. The old code fired one
	 * count_user_posts() COUNT(*) per row.
	 */
	public function test_post_counts_do_not_fan_out_per_user(): void {
		global $wpdb;
		$this->acting_as( 'administrator' );

		// Baseline: query cost to list a small user set.
		$baseline_users = self::factory()->user->create_many( 3, array( 'role' => 'author' ) );
		foreach ( $baseline_users as $uid ) {
			self::factory()->post->create( array( 'post_author' => $uid ) );
		}
		wp_cache_flush();
		$before_q = $wpdb->num_queries;
		wp_get_ability( 'aafm/get-users' )->execute( array( 'per_page' => 50 ) );
		$small_cost = $wpdb->num_queries - $before_q;

		// Add many more authored users; a per-user COUNT(*) would scale the cost up.
		$more = self::factory()->user->create_many( 12, array( 'role' => 'author' ) );
		foreach ( $more as $uid ) {
			self::factory()->post->create( array( 'post_author' => $uid ) );
		}
		wp_cache_flush();
		$before_q = $wpdb->num_queries;
		wp_get_ability( 'aafm/get-users' )->execute( array( 'per_page' => 50 ) );
		$large_cost = $wpdb->num_queries - $before_q;

		// With batching the cost is flat-ish; the old N+1 added ~12 extra COUNT(*)
		// queries for the 12 added users. Allow a small constant slack.
		$this->assertLessThanOrEqual(
			$small_cost + 2,
			$large_cost,
			'get-users query count scaled with the number of users — the N+1 count is back.'
		);
	}

	/**
	 * LOCKED reversal at the redactor layer: aafm_redact_user() exposes email but
	 * never the login or the password hash. This pins the shape both the list and
	 * the single-user assembler build on.
	 */
	public function test_redact_user_exposes_email_but_never_login_or_pass(): void {
		$uid  = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_email'   => 'show@example.com',
				'user_login'   => 'secretlogin',
				'display_name' => 'Public Author',
			)
		);
		$user = get_userdata( $uid );
		$out  = aafm_redact_user( $user, 0 );

		// LOCKED reversal: email IS now part of the user read shape.
		$this->assertSame( 'show@example.com', $out['email'] ?? null );
		// Login and password hash are NEVER exposed.
		$json = (string) wp_json_encode( $out );
		$this->assertStringNotContainsString( 'secretlogin', $json, 'user_login leaked.' );
		$this->assertStringNotContainsString( $user->user_pass, $json, 'password hash leaked.' );
		$this->assertArrayNotHasKey( 'user_login', $out );
		$this->assertArrayNotHasKey( 'user_pass', $out );
	}

	/**
	 * The rich single-user assembler layers registration date + bio over the lean
	 * redacted shape (which it calls), so the list stays lean. Login/pass stay out.
	 */
	public function test_rich_user_adds_registered_and_bio_over_the_lean_shape(): void {
		$uid = self::factory()->user->create(
			array(
				'role'        => 'author',
				'user_email'  => 'rich@example.com',
				'description' => 'Bio text here',
			)
		);
		$out = aafm_rich_user( get_userdata( $uid ), 0 );

		$this->assertSame( 'rich@example.com', $out['email'] );      // From the lean redactor.
		$this->assertArrayHasKey( 'registered', $out );              // Rich-only.
		$this->assertSame( 'Bio text here', $out['bio'] ?? null );   // Rich-only.
		// Still no login/pass.
		$this->assertArrayNotHasKey( 'user_login', $out );
		$this->assertStringNotContainsString( 'user_pass', (string) wp_json_encode( $out ) );
	}

	/**
	 * Enable + register the whole catalog so by-id reads like get-user resolve.
	 */
	private function register_users(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry() ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_get_user_returns_rich_shape_and_requires_list_users(): void {
		$this->register_users();
		$target = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'one@example.com',
			)
		);

		// Subscriber denied (no list_users).
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/get-user' )->check_permissions( array( 'user_id' => $target ) ),
			'get-user must deny a subscriber.'
		);

		// Admin allowed; gets the rich shape incl. email.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/get-user' )->execute( array( 'user_id' => $target ) );
		$this->assertIsArray( $res );
		$this->assertSame( 'one@example.com', $res['user']['email'] ?? null );
		$this->assertArrayHasKey( 'registered', $res['user'] );
	}

	public function test_get_user_unknown_id_is_a_clean_error(): void {
		$this->register_users();
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/get-user' )->execute( array( 'user_id' => 999999 ) );
		$this->assertInstanceOf( \WP_Error::class, $res );
	}
}
