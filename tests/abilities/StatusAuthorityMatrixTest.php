<?php
/**
 * The full role x status x ability x force-draft matrix for the create/update status gate.
 *
 * Two real regressions shipped from testing only the input the author expected: d3b8fe2 let a
 * Contributor's create-draft request for status:"future" land as a live "publish" post (future
 * was never in the "requires publish cap" set), and aafm_exec_create_cpt_item() silently
 * downgraded any non-"publish" status (including "private"/"future"/"pending") to draft with no
 * error. This test checks every stock role against every status the schema recognises, on every
 * status-aware write ability, with force-draft both off and on - and asserts by CAPABILITY
 * (current_user_can(), not a hardcoded per-role table), so a site with customised roles is
 * covered the same way.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class StatusAuthorityMatrixTest extends TestCase {

	/**
	 * Every stock role from subscriber up to administrator.
	 *
	 * @var string[]
	 */
	private const ROLES = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

	/**
	 * Every status the schema recognises (draft/pending/future/private/publish), plus one
	 * always-rejected value (trash) and one unknown value, exercised against every role.
	 *
	 * @var string[]
	 */
	private const STATUSES = array( 'draft', 'pending', 'private', 'future', 'publish', 'trash', 'bogus_status' );

	/**
	 * Statuses that must be refused outright regardless of any capability - never a valid
	 * resolved status, not even for an administrator.
	 *
	 * @var string[]
	 */
	private const ALWAYS_REJECTED = array( 'trash', 'bogus_status' );

	/**
	 * Statuses that require the type's own publish capability to set at all.
	 *
	 * @var string[]
	 */
	private const PUBLISH_GATED = array( 'publish', 'future', 'private' );

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/create-draft', 'aafm/create-post', 'aafm/create-page', 'aafm/create-cpt-item', 'aafm/update-cpt-item' ) );
	}

	public function tear_down(): void {
		delete_option( 'aafm_force_draft' );
		parent::tear_down();
	}

	/**
	 * Total row count across posts and pages, any status including trash - the "nothing was
	 * written" proof independent of which status a refusal might otherwise have landed at.
	 */
	private static function total_post_and_page_rows(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only row count across two fixed, non-user-input type names.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','page')" );
	}

	/**
	 * Expected outcome for a create ability given the caller's own capabilities.
	 *
	 * @param bool   $can_call    Whether the caller clears the ability's own permission floor.
	 * @param bool   $can_publish Whether the caller holds the type's publish capability.
	 * @param string $status      Requested status.
	 * @param bool   $force_draft Whether the operator's force-draft setting is on.
	 * @return string|null Expected resulting post_status, or null meaning "must be refused,
	 *                      nothing written".
	 */
	private static function expected_create_result( bool $can_call, bool $can_publish, string $status, bool $force_draft ): ?string {
		if ( ! $can_call ) {
			return null;
		}
		if ( in_array( $status, self::ALWAYS_REJECTED, true ) ) {
			return null;
		}
		if ( in_array( $status, self::PUBLISH_GATED, true ) && ! $can_publish ) {
			return null;
		}
		if ( $force_draft ) {
			return 'draft';
		}
		// Core itself (wp_insert_post()/wp_update_post()) normalizes 'future' straight to
		// 'publish' when the post's date is not actually in the future - we never send a
		// future post_date here, so an authorized 'future' request lands as 'publish', not
		// literally 'future'. The AUTHORITY question is unaffected: it still required the
		// publish capability to reach this point.
		return 'future' === $status ? 'publish' : $status;
	}

	/**
	 * Expected outcome for an UPDATE ability - same authority rules as expected_create_result(),
	 * but a different force-draft rule. Create's force-draft (aafm_insert_post()) coerces every
	 * resulting status to draft unconditionally, even draft/pending. Update's force-draft
	 * (aafm_exec_update_post()) only coerces a status that would make the post publicly visible
	 * now or later (publish/future/private) - draft/pending are left as requested, which is what
	 * guarantees force-draft can never retro-unpublish an already-published post via a no-op
	 * "still draft" edit.
	 *
	 * @param bool   $can_call    Whether the caller clears the per-object edit floor.
	 * @param bool   $can_publish Whether the caller holds the type's publish capability.
	 * @param string $status      Requested status.
	 * @param bool   $force_draft Whether the operator's force-draft setting is on.
	 * @return string|null Expected resulting post_status, or null meaning "must be refused".
	 */
	private static function expected_update_result( bool $can_call, bool $can_publish, string $status, bool $force_draft ): ?string {
		if ( ! $can_call ) {
			return null;
		}
		if ( in_array( $status, self::ALWAYS_REJECTED, true ) ) {
			return null;
		}
		if ( in_array( $status, self::PUBLISH_GATED, true ) && ! $can_publish ) {
			return null;
		}
		if ( $force_draft && in_array( $status, self::PUBLISH_GATED, true ) ) {
			return 'draft';
		}
		return 'future' === $status ? 'publish' : $status;
	}

	/**
	 * The shared assertion for one (role, status, ability, force-draft) cell: execute the create
	 * ability, assert either the expected resulting status or a refusal, and assert refusal never
	 * leaves a row behind.
	 *
	 * @param string              $ability     Ability name.
	 * @param array<string,mixed> $input       Ability input (already includes `status`).
	 * @param string|null         $expected    Expected resulting status, or null for "must refuse".
	 * @param string              $context     Human label for the assertion message.
	 */
	private function assert_create_matches( string $ability, array $input, ?string $expected, string $context ): void {
		$before = self::total_post_and_page_rows();
		$out    = wp_get_ability( $ability )->execute( $input );

		if ( null === $expected ) {
			$this->assertInstanceOf( WP_Error::class, $out, "{$context}: expected a refusal." );
			$this->assertSame( $before, self::total_post_and_page_rows(), "{$context}: a refusal must not write a row." );
			return;
		}

		$this->assertIsArray( $out, "{$context}: expected success, got " . ( $out instanceof WP_Error ? $out->get_error_message() : 'non-array' ) );
		$post_id = $out['post']['id'] ?? 0;
		$this->assertSame( $expected, get_post_status( $post_id ), "{$context}: resulting status." );
	}

	/**
	 * Exercises create-draft across every role x every status x force-draft on/off.
	 *
	 * Floor: edit_posts. Publish-gated statuses additionally require publish_posts. This is the
	 * exact escalation regression's ability - the named case below pins it permanently.
	 */
	public function test_create_draft_matrix(): void {
		foreach ( array( false, true ) as $force_draft ) {
			update_option( 'aafm_force_draft', $force_draft );
			foreach ( self::ROLES as $role ) {
				$uid         = $this->acting_as( $role );
				$can_call    = user_can( $uid, 'edit_posts' );
				$can_publish = user_can( $uid, 'publish_posts' );
				foreach ( self::STATUSES as $status ) {
					$expected = self::expected_create_result( $can_call, $can_publish, $status, $force_draft );
					$this->assert_create_matches(
						'aafm/create-draft',
						array(
							'title'   => "create-draft {$role} {$status}",
							'content' => 'Body',
							'status'  => $status,
						),
						$expected,
						"create-draft/{$role}/{$status}/force-draft=" . ( $force_draft ? 'on' : 'off' )
					);
				}
			}
		}
	}

	/**
	 * The named permanent regression: a Contributor (edit_posts, no publish_posts) asking
	 * create-draft for status "future" must be refused and must NEVER produce a published (or
	 * any other) post. This is the exact chain the operator measured live: create-draft asked
	 * future -> publish, a live post from a Contributor.
	 */
	public function test_contributor_create_draft_future_is_refused_and_writes_nothing(): void {
		$this->acting_as( 'contributor' );
		$before_total   = self::total_post_and_page_rows();
		$before_publish = (int) wp_count_posts( 'post' )->publish;

		$out = wp_get_ability( 'aafm/create-draft' )->execute(
			array(
				'title'   => 'Contributor scheduling attempt',
				'content' => 'Body',
				'status'  => 'future',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out, 'A Contributor must never be able to schedule a post.' );
		$this->assertSame( $before_total, self::total_post_and_page_rows(), 'The refused future request must not create any post at all.' );
		// Also prove it did not land as a live "publish" post specifically - the exact
		// escalation shape that was measured (create-draft asked future -> publish).
		$this->assertSame( $before_publish, (int) wp_count_posts( 'post' )->publish, 'The refused future request must not publish a post.' );
	}

	/**
	 * Exercises create-post across every role x every status x force-draft on/off.
	 *
	 * Floor: publish_posts UNCONDITIONALLY (the ability cannot be called at all without it,
	 * regardless of the status requested) - so can_call and can_publish are the same capability.
	 */
	public function test_create_post_matrix(): void {
		foreach ( array( false, true ) as $force_draft ) {
			update_option( 'aafm_force_draft', $force_draft );
			foreach ( self::ROLES as $role ) {
				$uid         = $this->acting_as( $role );
				$can_publish = user_can( $uid, 'publish_posts' );
				foreach ( self::STATUSES as $status ) {
					$expected = self::expected_create_result( $can_publish, $can_publish, $status, $force_draft );
					$this->assert_create_matches(
						'aafm/create-post',
						array(
							'title'   => "create-post {$role} {$status}",
							'content' => 'Body',
							'status'  => $status,
						),
						$expected,
						"create-post/{$role}/{$status}/force-draft=" . ( $force_draft ? 'on' : 'off' )
					);
				}
			}
		}
	}

	/**
	 * Exercises create-page across every role x every status x force-draft on/off. Floor:
	 * publish_pages unconditionally, mirroring create-post's shape for the page type.
	 */
	public function test_create_page_matrix(): void {
		foreach ( array( false, true ) as $force_draft ) {
			update_option( 'aafm_force_draft', $force_draft );
			foreach ( self::ROLES as $role ) {
				$uid         = $this->acting_as( $role );
				$can_publish = user_can( $uid, 'publish_pages' );
				foreach ( self::STATUSES as $status ) {
					$expected = self::expected_create_result( $can_publish, $can_publish, $status, $force_draft );
					$this->assert_create_matches(
						'aafm/create-page',
						array(
							'title'   => "create-page {$role} {$status}",
							'content' => 'Body',
							'status'  => $status,
						),
						$expected,
						"create-page/{$role}/{$status}/force-draft=" . ( $force_draft ? 'on' : 'off' )
					);
				}
			}
		}
	}

	/**
	 * Exercises create-cpt-item across every role x every status x force-draft on/off, using post_type=post as
	 * an allowlisted, always-eligible stand-in type - the type's own capability resolution
	 * (aafm_type_caps()) for 'post' is the real, unmodified edit_posts/publish_posts primitives,
	 * so this exercises the generic CPT chokepoint (aafm_perm_create_cpt_item/
	 * aafm_exec_create_cpt_item) with the exact same floor as create-draft. This is the ability
	 * whose exec previously string-compared the literal 'publish' and silently downgraded
	 * everything else to draft with no error.
	 */
	public function test_create_cpt_item_matrix(): void {
		foreach ( array( false, true ) as $force_draft ) {
			update_option( 'aafm_force_draft', $force_draft );
			foreach ( self::ROLES as $role ) {
				$uid         = $this->acting_as( $role );
				$can_call    = user_can( $uid, 'edit_posts' );
				$can_publish = user_can( $uid, 'publish_posts' );
				foreach ( self::STATUSES as $status ) {
					$expected = self::expected_create_result( $can_call, $can_publish, $status, $force_draft );
					$this->assert_create_matches(
						'aafm/create-cpt-item',
						array(
							'post_type' => 'post',
							'title'     => "create-cpt-item {$role} {$status}",
							'content'   => 'Body',
							'status'    => $status,
						),
						$expected,
						"create-cpt-item/{$role}/{$status}/force-draft=" . ( $force_draft ? 'on' : 'off' )
					);
				}
			}
		}
	}

	/**
	 * Exercises update-cpt-item across every role x every status x force-draft on/off, on a fresh draft the
	 * role itself authored (own-post editing is the floor every stock role above subscriber
	 * clears) - using post_type=post as the stand-in type for the same reason as the create
	 * matrix. This is the path this task's audit found actually using the wrong capability
	 * (edit_others_posts) to gate future/private, since fixed to the type's own publish cap.
	 */
	public function test_update_cpt_item_matrix(): void {
		foreach ( array( false, true ) as $force_draft ) {
			update_option( 'aafm_force_draft', $force_draft );
			foreach ( self::ROLES as $role ) {
				$uid         = $this->acting_as( $role );
				$can_publish = user_can( $uid, 'publish_posts' );

				foreach ( self::STATUSES as $status ) {
					$post_id = self::factory()->post->create(
						array(
							'post_author' => $uid,
							'post_status' => 'draft',
						)
					);
					// Belt-and-braces: keep the acting user pinned before every execute() call.
					wp_set_current_user( $uid );

					$can_call = user_can( $uid, 'edit_post', $post_id );
					$expected = self::expected_update_result( $can_call, $can_publish, $status, $force_draft );
					$context  = "update-cpt-item/{$role}/{$status}/force-draft=" . ( $force_draft ? 'on' : 'off' );

					$out = wp_get_ability( 'aafm/update-cpt-item' )->execute(
						array(
							'post_id' => $post_id,
							'status'  => $status,
						)
					);

					if ( null === $expected ) {
						$this->assertInstanceOf( WP_Error::class, $out, "{$context}: expected a refusal." );
						$this->assertSame( 'draft', get_post_status( $post_id ), "{$context}: a refusal must leave the post exactly as it was." );
					} else {
						$this->assertIsArray( $out, "{$context}: expected success." );
						$this->assertSame( $expected, get_post_status( $post_id ), "{$context}: resulting status." );
					}
				}
			}
		}
	}
}
