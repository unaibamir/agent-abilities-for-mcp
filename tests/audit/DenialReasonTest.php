<?php
/**
 * Refusal reasons: the closed code list, the key diagnostic (parity with the production gate), and
 * the denied row detail end to end.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ability-input array keys, not meta queries.

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class DenialReasonTest extends TestCase {

	public function test_every_code_has_a_label_without_a_hash_digit_sequence(): void {
		foreach ( array( 'not_allowlisted', 'key_denied', 'key_protected', 'reserved_key', 'object_not_editable', 'scope_list', 'rate_limit', 'missing_argument', 'capability', 'source_plugin', 'connection_paused' ) as $code ) {
			$label = aafm_denial_reason_label( $code );
			$this->assertIsString( $label, $code );
			$this->assertSame( 0, preg_match( '/#\d/', $label ), 'A label must not look like an object id to the detail linker.' );
		}
		$this->assertNull( aafm_denial_reason_label( 'bogus' ) );
	}

	public function test_compose_appends_or_stands_alone_or_leaves_the_base(): void {
		$this->assertSame( 'Attempted: x. Refused: not_allowlisted', aafm_compose_denied_detail( 'Attempted: x', 'not_allowlisted' ) );
		$this->assertSame( 'Refused: rate_limit', aafm_compose_denied_detail( null, 'rate_limit' ) );
		$this->assertSame( 'Attempted: x', aafm_compose_denied_detail( 'Attempted: x', null ) );
		$this->assertSame( 'Attempted: x', aafm_compose_denied_detail( 'Attempted: x', 'bogus' ) );
		$this->assertNull( aafm_compose_denied_detail( null, null ) );
	}

	/**
	 * Review Focus 3: the diagnostic calls the real engine, so it must agree with the gate for every key.
	 */
	public function test_the_reason_diagnostic_agrees_with_the_gate_for_every_key(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'listed_key', 'both_listed', "nbsp\xc2\xa0" ) );
		update_option( 'aafm_denied_meta_keys', array( 'both_listed', 'denied_key' ) );
		$keys = array( 'listed_key', 'not_listed', 'denied_key', 'both_listed', '_secret', '_edit_lock', 'Listed_Key', 'listed_key ', "nbsp\xc2\xa0", "listed_key\xe2\x80\x8b", '*', '', 'wp_capabilities' );

		foreach ( $keys as $key ) {
			$gate_refuses = is_wp_error( aafm_validate_meta_key( $key ) );
			$code         = aafm_meta_key_refusal_code( 'post', $key );
			$this->assertSame( $gate_refuses, null !== $code, "Parity for key '" . addcslashes( $key, "\0..\37\177..\377" ) . "'." );
		}
		$this->assertNull( aafm_meta_key_refusal_code( 'post', 'listed_key' ) );
		$this->assertSame( 'not_allowlisted', aafm_meta_key_refusal_code( 'post', 'not_listed' ) );
		$this->assertSame( 'key_denied', aafm_meta_key_refusal_code( 'post', 'denied_key' ) );
		$this->assertSame( 'key_denied', aafm_meta_key_refusal_code( 'post', 'both_listed' ) );
		$this->assertSame( 'key_protected', aafm_meta_key_refusal_code( 'post', '_secret' ) );
		$this->assertSame( 'key_protected', aafm_meta_key_refusal_code( 'post', '*' ) );
		$this->assertSame( 'key_protected', aafm_meta_key_refusal_code( 'post', '' ) );
		$this->assertNull( aafm_meta_key_refusal_code( 'nope', 'x' ), 'An unknown scope reports nothing.' );
	}

	public function test_term_and_user_scopes_use_their_own_lists(): void {
		update_option( 'aafm_exposed_term_meta_keys', array( 'term_ok' ) );
		update_option( 'aafm_exposed_user_meta_keys', array( 'user_ok' ) );
		$this->assertNull( aafm_meta_key_refusal_code( 'term', 'term_ok' ) );
		$this->assertSame( 'not_allowlisted', aafm_meta_key_refusal_code( 'term', 'user_ok' ) );
		$this->assertNull( aafm_meta_key_refusal_code( 'user', 'user_ok' ) );
		$this->assertSame( 'not_allowlisted', aafm_meta_key_refusal_code( 'user', 'term_ok' ) );
	}

	private function denied_detail( string $ability ): string {
		$rows = aafm_query_activity(
			array(
				'ability' => $ability,
				'status'  => 'denied',
			)
		);
		$this->assertNotEmpty( $rows, 'The refused call must be audited.' );
		return (string) $rows[0]['detail'];
	}

	private function post_for_admin(): int {
		return self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	public function test_a_key_not_on_the_allowlist_is_explained_on_the_row(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'listed_key' ) );
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/update-post-meta' ) );
		$post_id = $this->post_for_admin();

		wp_get_ability( 'aafm/update-post-meta' )->execute(
			array(
				'post_id'  => $post_id,
				'meta_key' => 'other_key',
				'value'    => 'x',
			)
		);

		$detail = $this->denied_detail( 'aafm/update-post-meta' );
		$this->assertStringContainsString( 'Attempted: Updated meta key `other_key` on post #' . $post_id, $detail );
		$this->assertStringContainsString( 'Refused: not_allowlisted', $detail );
	}

	public function test_get_post_meta_now_has_a_detail_and_a_reason(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'listed_key' ) );
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/get-post-meta' ) );
		$post_id = $this->post_for_admin();

		wp_get_ability( 'aafm/get-post-meta' )->execute(
			array(
				'post_id'  => $post_id,
				'meta_key' => 'other_key',
			)
		);

		$detail = $this->denied_detail( 'aafm/get-post-meta' );
		$this->assertStringContainsString( 'Read meta key `other_key` on post #' . $post_id, $detail );
		$this->assertStringContainsString( 'Refused: not_allowlisted', $detail );
	}

	public function test_a_deny_listed_key_and_a_protected_key_get_their_own_reasons(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'denied_key', '_hidden' ) );
		update_option( 'aafm_denied_meta_keys', array( 'denied_key' ) );
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/update-post-meta' ) );
		$post_id = $this->post_for_admin();

		wp_get_ability( 'aafm/update-post-meta' )->execute(
			array(
				'post_id'  => $post_id,
				'meta_key' => 'denied_key',
				'value'    => 'x',
			)
		);
		$this->assertStringContainsString( 'Refused: key_denied', $this->denied_detail( 'aafm/update-post-meta' ) );

		// No aafm_clear_activity_log() here: TestCase::set_up() documents that a TRUNCATE commits earlier option writes out of the rollback,
		// and denied_detail() reads the newest row, so the next assertion sees the second call without a clear.
		wp_get_ability( 'aafm/update-post-meta' )->execute(
			array(
				'post_id'  => $post_id,
				'meta_key' => '_hidden',
				'value'    => 'x',
			)
		);
		$detail = $this->denied_detail( 'aafm/update-post-meta' );
		$this->assertTrue(
			false !== strpos( $detail, 'Refused: key_protected' ) || false !== strpos( $detail, 'Refused: reserved_key' ),
			$detail
		);
	}

	public function test_a_listed_key_on_a_post_the_user_cannot_edit_says_the_item_is_not_editable(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'listed_key' ) );
		$admin_post = $this->post_for_admin();
		$this->acting_as( 'subscriber' );
		$this->register_enabled( array( 'aafm/update-post-meta' ) );

		wp_get_ability( 'aafm/update-post-meta' )->execute(
			array(
				'post_id'  => $admin_post,
				'meta_key' => 'listed_key',
				'value'    => 'x',
			)
		);

		$this->assertStringContainsString( 'Refused: object_not_editable', $this->denied_detail( 'aafm/update-post-meta' ) );
	}

	public function test_a_role_list_that_excludes_the_tool_is_named(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'listed_key' ) );
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'administrator',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/update-post-meta' ) );
		$post_id = $this->post_for_admin();

		wp_get_ability( 'aafm/update-post-meta' )->execute(
			array(
				'post_id'  => $post_id,
				'meta_key' => 'listed_key',
				'value'    => 'x',
			)
		);

		$this->assertStringContainsString( 'Refused: scope_list', $this->denied_detail( 'aafm/update-post-meta' ) );
	}

	public function test_an_ability_outside_the_meta_family_reports_a_capability_reason(): void {
		$this->acting_as( 'subscriber' );
		$this->register_enabled( array( 'aafm/get-activity-log' ) );

		wp_get_ability( 'aafm/get-activity-log' )->execute( array() );

		$this->assertStringContainsString( 'Refused: capability', $this->denied_detail( 'aafm/get-activity-log' ) );
	}

	public function test_a_name_with_a_trailing_newline_is_not_a_meta_ability(): void {
		$input = array( 'meta_key' => '_edit_lock' );
		$this->assertNotSame( 'capability', aafm_denial_reason_for_permission( 'aafm/get-post-meta', $input ) );
		$this->assertSame( 'capability', aafm_denial_reason_for_permission( "aafm/get-post-meta\n", $input ) );
	}

	public function test_the_wire_message_is_unchanged_and_carries_no_reason(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'listed_key' ) );
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/update-post-meta' ) );
		$post_id = $this->post_for_admin();

		$result = wp_get_ability( 'aafm/update-post-meta' )->execute(
			array(
				'post_id'  => $post_id,
				'meta_key' => 'other_key',
				'value'    => 'x',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringNotContainsString( 'allowlist', strtolower( $result->get_error_message() ) );
		$this->assertStringNotContainsString( 'Refused', wp_json_encode( $result->get_error_data() ) );
	}

	public function test_the_activity_ability_does_not_return_the_refusal_reason(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'listed_key' ) );
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/update-post-meta', 'aafm/get-activity-log' ) );
		$post_id = $this->post_for_admin();
		wp_get_ability( 'aafm/update-post-meta' )->execute(
			array(
				'post_id'  => $post_id,
				'meta_key' => 'other_key',
				'value'    => 'x',
			)
		);
		$this->assertStringContainsString( 'Refused: not_allowlisted', $this->denied_detail( 'aafm/update-post-meta' ), 'The admin log keeps the reason.' );

		$out = wp_get_ability( 'aafm/get-activity-log' )->execute(
			array(
				'ability' => 'aafm/update-post-meta',
				'status'  => 'denied',
			)
		);

		$this->assertIsArray( $out );
		$detail = (string) $out['entries'][0]['detail'];
		$this->assertStringNotContainsString( 'Refused', $detail, 'An administrator-level agent must not learn which rule refused it.' );
		$this->assertStringContainsString( 'Attempted: Updated meta key `other_key` on post #' . $post_id, $detail );
	}

	public function test_redaction_cuts_only_the_trailing_refusal_clause(): void {
		$this->assertSame( 'Attempted: x', aafm_redact_denial_reason( 'Attempted: x. Refused: not_allowlisted' ) );
		$this->assertNull( aafm_redact_denial_reason( 'Refused: rate_limit' ), 'A reason-only detail becomes null, like a row with no detail.' );
		$this->assertNull( aafm_redact_denial_reason( null ) );
		$this->assertSame( 'Read post #7', aafm_redact_denial_reason( 'Read post #7' ) );
		foreach ( array_keys( aafm_denial_reason_labels() ) as $code ) {
			$this->assertSame( 'Attempted: y', aafm_redact_denial_reason( (string) aafm_compose_denied_detail( 'Attempted: y', $code ) ), $code );
		}
	}

	/**
	 * Fake a translation that rewrites every plugin string, including a period inside a label and the
	 * "Refused: %s" format itself. Redaction must not depend on any of it.
	 */
	public function test_redaction_holds_when_the_plugin_strings_are_translated(): void {
		$fake = static function ( $translation, $text, $domain ) {
			if ( 'agent-abilities-for-mcp' !== $domain ) {
				return $translation;
			}
			if ( 'Refused: %s' === $text ) {
				return 'Abgelehnt: %s';
			}
			if ( '%1$s. Refused: %2$s' === $text ) {
				return '%1$s. Abgelehnt: %2$s';
			}
			return 'z. B. ' . $translation;
		};
		add_filter( 'gettext', $fake, 10, 3 );

		try {
			update_option( 'aafm_allowed_meta_keys', array( 'listed_key' ) );
			$this->acting_as( 'administrator' );
			$this->register_enabled( array( 'aafm/update-post-meta', 'aafm/get-activity-log' ) );
			$post_id = $this->post_for_admin();
			wp_get_ability( 'aafm/update-post-meta' )->execute(
				array(
					'post_id'  => $post_id,
					'meta_key' => 'other_key',
					'value'    => 'x',
				)
			);

			$stored = $this->denied_detail( 'aafm/update-post-meta' );
			$this->assertStringContainsString( 'Refused: not_allowlisted', $stored, 'The stored clause is the untranslated marker plus the code.' );

			$shown = aafm_localize_denied_detail( $stored );
			$this->assertStringContainsString( 'Abgelehnt: z. B. key is not on the allowlist', $shown, 'The admin surfaces translate the clause at render time.' );

			$out = wp_get_ability( 'aafm/get-activity-log' )->execute(
				array(
					'ability' => 'aafm/update-post-meta',
					'status'  => 'denied',
				)
			);
			$this->assertIsArray( $out );
			$detail = (string) $out['entries'][0]['detail'];
			$this->assertStringNotContainsString( 'Refused', $detail );
			$this->assertStringNotContainsString( 'Abgelehnt', $detail );
			$this->assertStringNotContainsString( 'allowlist', $detail );
			$this->assertStringNotContainsString( 'not_allowlisted', $detail );
		} finally {
			remove_filter( 'gettext', $fake, 10 );
		}
	}

	public function test_localize_leaves_a_detail_without_a_known_clause_alone(): void {
		$this->assertSame( 'Read post #7', aafm_localize_denied_detail( 'Read post #7' ) );
		$this->assertSame( 'Attempted: x. Refused: bogus', aafm_localize_denied_detail( 'Attempted: x. Refused: bogus' ) );
		$this->assertSame( 'Refused: rate limit reached', aafm_localize_denied_detail( 'Refused: rate_limit' ) );
		$this->assertSame( 'Attempted: x. Refused: key is on the deny list', aafm_localize_denied_detail( 'Attempted: x. Refused: key_denied' ) );
	}
}
