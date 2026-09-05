<?php
/**
 * The agent-identity marker: a per-app-password-user marker (existing) and a new
 * per-OAuth-client flag, both surfaced as one is_agent_identity field on the activity log.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ActivityLogAgentIdentityTest extends TestCase {

	public function test_activity_log_flags_an_agent_marked_app_password_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, aafm_agent_user_marker_meta_key(), 1 );

		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'status'            => 'success',
				'principal_user_id' => $user_id,
				'principal_login'   => (string) get_userdata( $user_id )->user_login,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$out = aafm_exec_get_activity_log( array() );

		$this->assertTrue( $out['entries'][0]['is_agent_identity'] );
	}

	public function test_activity_log_does_not_flag_an_ordinary_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'status'            => 'success',
				'principal_user_id' => $user_id,
				'principal_login'   => (string) get_userdata( $user_id )->user_login,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$out = aafm_exec_get_activity_log( array() );

		$this->assertFalse( $out['entries'][0]['is_agent_identity'] );
	}

	public function test_activity_log_flags_a_row_attributed_to_a_flagged_oauth_client(): void {
		aafm_install_oauth_tables();
		$client = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'https://example.com/callback' ) )
		);
		$this->assertIsArray( $client );
		$client_id = $client['client_id'];

		$this->assertTrue( aafm_oauth_set_client_agent_identity( $client_id, true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'status'            => 'success',
				'principal_user_id' => $user_id,
				'principal_login'   => (string) get_userdata( $user_id )->user_login,
				'client_id'         => $client_id,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$out = aafm_exec_get_activity_log( array() );

		$this->assertTrue( $out['entries'][0]['is_agent_identity'] );
	}

	public function test_activity_log_does_not_flag_a_row_from_an_unflagged_oauth_client(): void {
		aafm_install_oauth_tables();
		$client = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'https://example.com/callback' ) )
		);
		$this->assertIsArray( $client );
		$client_id = $client['client_id'];

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		aafm_log_activity(
			array(
				'ability'           => 'aafm/get-posts',
				'status'            => 'success',
				'principal_user_id' => $user_id,
				'principal_login'   => (string) get_userdata( $user_id )->user_login,
				'client_id'         => $client_id,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$out = aafm_exec_get_activity_log( array() );

		$this->assertFalse( $out['entries'][0]['is_agent_identity'] );
	}

	public function test_get_client_reflects_the_stored_flag_not_a_derived_guess(): void {
		aafm_install_oauth_tables();
		$client = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'https://example.com/callback' ) )
		);
		$this->assertIsArray( $client );
		$client_id = $client['client_id'];

		// Every registered client has a non-empty client_id, so a derived
		// "client_id is non-empty" reading would flag every client as an agent identity. The
		// stored column must default to false regardless.
		$fresh = aafm_oauth_get_client( $client_id );
		$this->assertIsArray( $fresh );
		$this->assertFalse( $fresh['is_agent_identity'] );

		$this->assertTrue( aafm_oauth_set_client_agent_identity( $client_id, true ) );
		$flagged = aafm_oauth_get_client( $client_id );
		$this->assertIsArray( $flagged );
		$this->assertTrue( $flagged['is_agent_identity'] );

		$this->assertTrue( aafm_oauth_set_client_agent_identity( $client_id, false ) );
		$unflagged = aafm_oauth_get_client( $client_id );
		$this->assertIsArray( $unflagged );
		$this->assertFalse( $unflagged['is_agent_identity'] );
	}

	public function test_set_client_agent_identity_returns_false_for_an_unknown_client(): void {
		aafm_install_oauth_tables();
		$this->assertFalse( aafm_oauth_set_client_agent_identity( 'not-a-real-client-id', true ) );
	}
}
