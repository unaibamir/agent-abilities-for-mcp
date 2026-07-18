<?php
/**
 * Tests for OAuth lifecycle audit logging.
 *
 * The register/authorize/token/refresh/revoke lifecycle records one activity-log row
 * per event so an illicit-consent-grant intrusion is no longer invisible. These tests
 * verify the rows are written with the right event/status/context and that only
 * non-secret context is recorded.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;

/**
 * Verifies aafm_oauth_log_event() and its host-derivation helper.
 */
class OauthAuditTest extends TestCase {

	/**
	 * The lifecycle rows land in the activity log, so it must exist for the current blog.
	 */
	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Fetch the single most recent activity row, or null when the log is empty.
	 *
	 * @return array<string,mixed>|null
	 */
	private function latest_row(): ?array {
		$rows = aafm_query_activity( array( 'per_page' => 5 ) );
		return isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * A token-mint event writes one row: ability `oauth:token`, status success, the acting
	 * user, and the client_id + redirect host as non-secret context in the argument-keys.
	 */
	public function test_token_event_writes_a_row_with_client_and_host_context(): void {
		aafm_oauth_log_event(
			'token',
			'success',
			array(
				'client_id'     => 'abc123def456abc123def456abc12345',
				'redirect_host' => 'app.example.com',
				'user_id'       => 7,
				'user_login'    => 'agent',
			)
		);

		$row = $this->latest_row();
		$this->assertIsArray( $row, 'A token event must write an activity row.' );
		$this->assertSame( 'oauth:token', $row['ability'] );
		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( 7, (int) $row['principal_user_id'] );
		$this->assertSame( 'agent', $row['principal_login'] );

		// The client_id (the join key) survives intact; the host is recorded as a normalised slug.
		$this->assertStringContainsString( 'client_abc123def456abc123def456abc12345', (string) $row['arg_keys'] );
		$this->assertStringContainsString( 'host_app-example-com', (string) $row['arg_keys'] );
	}

	/**
	 * A hostile redirect host is normalised to a sanitize_key-safe slug, never stored raw, so
	 * it cannot smuggle unexpected characters into the log.
	 */
	public function test_host_is_normalised_in_the_log(): void {
		aafm_oauth_log_event(
			'register',
			'success',
			array(
				'client_id'     => 'deadbeef',
				'redirect_host' => 'EVIL.Example.COM:8443',
			)
		);

		$row = $this->latest_row();
		$this->assertIsArray( $row );
		$this->assertSame( 'oauth:register', $row['ability'] );
		$this->assertStringContainsString( 'host_evil-example-com-8443', (string) $row['arg_keys'] );
	}

	/**
	 * An unknown event name is a no-op: no row is written, so a typo can never create a bogus
	 * audit entry.
	 */
	public function test_unknown_event_writes_nothing(): void {
		aafm_oauth_log_event( 'not_a_real_event', 'success', array( 'client_id' => 'x' ) );

		$this->assertNull( $this->latest_row(), 'An unknown event must not write a row.' );
	}

	/**
	 * A failed refresh is auditable as a `denied` row (this is the reuse-detection signal path).
	 */
	public function test_refresh_denied_event_records_denied_status(): void {
		aafm_oauth_log_event( 'refresh', 'denied', array( 'client_id' => 'c0ffee' ) );

		$row = $this->latest_row();
		$this->assertIsArray( $row );
		$this->assertSame( 'oauth:refresh', $row['ability'] );
		$this->assertSame( 'denied', $row['status'] );
	}

	/**
	 * A denied bearer event (a presented-but-invalid aafm_oat_ credential) is auditable,
	 * same as a denied refresh - this is the resolver's failed-authentication signal.
	 */
	public function test_bearer_denied_event_records_denied_status(): void {
		aafm_oauth_log_event( 'bearer', 'denied', array( 'client_id' => 'c0ffee' ) );

		$row = $this->latest_row();
		$this->assertIsArray( $row );
		$this->assertSame( 'oauth:bearer', $row['ability'] );
		$this->assertSame( 'denied', $row['status'] );
		$this->assertStringContainsString( 'client_c0ffee', (string) $row['arg_keys'] );
	}

	/**
	 * A bearer event with no resolvable client_id (an unknown token never reaches a client
	 * row) still writes a row - the context is simply empty, never a reason to drop the event.
	 */
	public function test_bearer_denied_event_without_client_id_still_writes_a_row(): void {
		aafm_oauth_log_event( 'bearer', 'denied' );

		$row = $this->latest_row();
		$this->assertIsArray( $row );
		$this->assertSame( 'oauth:bearer', $row['ability'] );
		$this->assertSame( 'denied', $row['status'] );
	}

	/**
	 * The host helper derives host[:port] from a redirect URI and returns '' when unparseable.
	 */
	public function test_audit_host_from_uri(): void {
		$this->assertSame( 'app.example.com', aafm_oauth_audit_host_from_uri( 'https://app.example.com/cb?x=1' ) );
		$this->assertSame( 'localhost:8443', aafm_oauth_audit_host_from_uri( 'https://localhost:8443/callback' ) );
		$this->assertSame( '', aafm_oauth_audit_host_from_uri( '/relative/only' ) );
	}
}
