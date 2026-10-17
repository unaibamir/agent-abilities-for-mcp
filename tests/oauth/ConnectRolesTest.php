<?php
/**
 * Tests for "roles that can connect": who may approve a connection, and whose grants keep working.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;

/**
 * The legacy edit_posts floor stays until the owner saves a list; a saved list decides by role,
 * at approval and at use.
 */
class ConnectRolesTest extends TestCase {

	use AjaxHarness;

	private const CLIENT = 'client_roles';

	/**
	 * $_SERVER as set_up() found it.
	 *
	 * @var array<string,mixed>
	 */
	private array $server = array();

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
		// Tokens are minted for the pretty-permalink endpoint the resolver checks them against.
		$this->set_permalink_structure( '/%postname%/' );
		$_SERVER['HTTPS'] = 'on';
		aafm_install_oauth_tables();
		aafm_truncate_oauth_tables();
		update_option( 'aafm_oauth_enabled', '1' );
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_clients',
			array(
				'client_id'   => self::CLIENT,
				'client_name' => 'Roles',
				'is_active'   => 1,
			)
		);
	}

	public function tear_down(): void {
		$this->reset_ajax_harness();
		remove_all_filters( 'aafm_oauth_min_capability' );
		remove_all_filters( 'query' );
		$this->set_permalink_structure( '' );
		$_SERVER = $this->server;
		SchemaV9Test::restore_schema();
		parent::tear_down();
	}

	/**
	 * A user object of a role.
	 *
	 * @param string $role Role slug.
	 * @return \WP_User
	 */
	private function user( string $role ): \WP_User {
		return get_userdata( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * Store the roles list the way the Settings card will.
	 *
	 * @param array<int,string> $roles Role slugs.
	 * @return void
	 */
	private function store_roles( array $roles ): void {
		$this->assertTrue( aafm_update_option_verified( 'aafm_oauth_connect_roles', $roles, false ) );
		aafm_policy_reset_request_state();
	}

	/**
	 * Give a user a consent and a token pair.
	 *
	 * @param int $user_id User id.
	 * @return array{access_token:string,refresh_token:string,expires_in:int}
	 */
	private function grant( int $user_id ): array {
		aafm_oauth_record_consent( $user_id, self::CLIENT );
		$tokens = aafm_oauth_mint_tokens(
			array(
				'wp_user_id' => $user_id,
				'client_id'  => self::CLIENT,
				'resource'   => aafm_endpoint_url(),
			)
		);
		$this->assertIsArray( $tokens );
		return $tokens;
	}

	/**
	 * Resolve a bearer on the MCP route.
	 *
	 * @param string $access Raw access token.
	 * @return int|false
	 */
	private function resolve( string $access ) {
		$_SERVER['REQUEST_URI']        = self::mcp_rest_path();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;
		$this->route_as_rest_request();
		aafm_policy_reset_request_state();
		return aafm_oauth_resolve_current_user( false );
	}

	/**
	 * Whether the roles row exists.
	 *
	 * @return bool
	 */
	private function roles_row_exists(): bool {
		return aafm_read_option_views( 'aafm_oauth_connect_roles' )['db_found'];
	}

	/**
	 * Drop the schema stamp row.
	 *
	 * @return void
	 */
	private function unstamp(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'aafm_oauth_schema_version' ) );
		wp_cache_delete( 'aafm_oauth_schema_version', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	public function test_an_absent_option_keeps_the_edit_posts_floor(): void {
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'contributor' ) ) );
		$this->assertFalse( aafm_oauth_user_may_connect( $this->user( 'subscriber' ) ) );
	}

	public function test_a_stored_list_decides_by_role(): void {
		$this->store_roles( array( 'administrator', 'editor' ) );
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'editor' ) ) );
		$this->assertFalse( aafm_oauth_user_may_connect( $this->user( 'author' ) ) );
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'administrator' ) ) );
	}

	public function test_administrator_is_always_allowed_even_if_missing_from_the_list(): void {
		$this->store_roles( array( 'editor' ) );
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'administrator' ) ) );
	}

	public function test_the_capability_filter_still_wins(): void {
		$this->store_roles( array( 'administrator', 'editor' ) );
		add_filter( 'aafm_oauth_min_capability', static fn() => 'manage_options' );
		$this->assertFalse( aafm_oauth_user_may_connect( $this->user( 'editor' ) ) );

		remove_all_filters( 'aafm_oauth_min_capability' );
		add_filter( 'aafm_oauth_min_capability', static fn() => 'read' );
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'subscriber' ) ) );

		remove_all_filters( 'aafm_oauth_min_capability' );
		$this->store_roles( array( 'administrator' ) );
		add_filter( 'aafm_oauth_min_capability', static fn() => 'edit_posts' );
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'author' ) ), 'A filter returning the default still wins over a stored list.' );
	}

	public function test_an_unreadable_row_allows_administrators_only(): void {
		$this->store_roles( array( 'administrator', 'editor' ) );
		$editor = $this->user( 'editor' );
		$admin  = $this->user( 'administrator' );
		$result = QueryFaultInjector::fail_query(
			'aafm_oauth_connect_roles',
			static fn() => array( aafm_oauth_user_may_connect( $editor ), aafm_oauth_user_may_connect( $admin ) )
		);
		$this->assertSame( array( false, true ), $result );
	}

	public function test_a_user_with_two_roles_is_allowed_when_either_is_listed(): void {
		$this->store_roles( array( 'administrator', 'editor' ) );
		$user = $this->user( 'author' );
		$user->add_role( 'editor' );
		$this->assertTrue( aafm_oauth_user_may_connect( $user ) );
	}

	public function test_is_super_admin_is_not_consulted_on_a_single_site(): void {
		$this->store_roles( array( 'administrator' ) );
		$editor = $this->user( 'editor' );
		grant_super_admin( $editor->ID );
		$this->assertFalse( is_multisite() );
		$this->assertFalse( aafm_oauth_user_may_connect( $editor ) );
	}

	/**
	 * Multisite only.
	 *
	 * @group ms-required
	 */
	public function test_a_super_admin_is_allowed_on_multisite(): void {
		$this->skipWithoutMultisite();
		$this->store_roles( array( 'administrator' ) );
		$user = $this->user( 'subscriber' );
		grant_super_admin( $user->ID );
		$this->assertTrue( aafm_oauth_user_may_connect( $user ) );
	}

	public function test_narrowing_the_list_ends_existing_grants_of_removed_roles(): void {
		$editor = $this->user( 'editor' );
		$admin  = $this->user( 'administrator' );
		$old    = $this->grant( $editor->ID );
		$keep   = $this->grant( $admin->ID );

		$this->store_roles( array( 'administrator' ) );
		$this->assertFalse( $this->resolve( $old['access_token'] ) );
		$refresh = aafm_oauth_rotate_refresh( $old['refresh_token'], self::CLIENT );
		$this->assertWPError( $refresh );
		$this->assertSame( 'invalid_grant', $refresh->get_error_code() );
		$this->assertSame( $admin->ID, $this->resolve( $keep['access_token'] ) );
	}

	public function test_an_install_that_never_saved_the_list_keeps_every_grant(): void {
		$editor      = $this->user( 'editor' );
		$contributor = $this->user( 'contributor' );
		$editor_t    = $this->grant( $editor->ID );
		$contrib_t   = $this->grant( $contributor->ID );
		$contributor->remove_role( 'contributor' );
		$contributor->add_role( 'subscriber' );

		$this->assertSame( $editor->ID, $this->resolve( $editor_t['access_token'] ) );
		$this->assertSame( $contributor->ID, $this->resolve( $contrib_t['access_token'] ), 'Even a user who has since lost edit_posts.' );
		$this->assertIsArray( aafm_oauth_rotate_refresh( $editor_t['refresh_token'], self::CLIENT ) );
		$this->assertIsArray( aafm_oauth_rotate_refresh( $contrib_t['refresh_token'], self::CLIENT ) );
	}

	public function test_a_filter_only_site_keeps_existing_grants(): void {
		$editor = $this->user( 'editor' );
		$tokens = $this->grant( $editor->ID );
		add_filter( 'aafm_oauth_min_capability', static fn() => 'manage_options' );
		$this->assertSame( $editor->ID, $this->resolve( $tokens['access_token'] ) );
		$this->assertFalse( aafm_oauth_user_may_connect( $editor ), 'A new approval by the Editor is refused.' );
	}

	public function test_an_upgraded_site_with_a_v8_stamp_keeps_edit_posts_through_any_settings_saves(): void {
		$editor      = $this->user( 'editor' );
		$contributor = $this->user( 'contributor' );
		$tokens      = $this->grant( $editor->ID );
		$legacy      = array( 'administrator', 'editor', 'author', 'contributor' );

		$steps = array(
			'unrelated save' => function () use ( $legacy ): void {
				$this->run_settings_save(
					array(
						'aafm_oauth_enabled'               => '1', // The site already uses OAuth; its checkbox posts as ticked.
						'aafm_oauth_connect_roles_present' => '1',
						'aafm_oauth_connect_roles'         => $legacy,
					)
				);
			},
			'oauth on save'  => function (): void {
				$this->run_settings_save( array( 'aafm_oauth_enabled' => '1' ) );
				$this->run_settings_save( array( 'aafm_oauth_enabled' => '1' ) );
			},
			'activate'       => static function (): void {
				aafm_activate();
			},
			'lazy upgrade'   => static function (): void {
				update_option( 'aafm_oauth_schema_version', '8' );
				delete_transient( 'aafm_oauth_upgrade_lock' );
				aafm_maybe_upgrade_oauth_tables();
			},
		);

		foreach ( array( $steps, array_reverse( $steps ) ) as $order ) {
			update_option( 'aafm_oauth_schema_version', '8' );
			foreach ( $order as $name => $step ) {
				$step();
				aafm_policy_reset_request_state();
				$this->assertFalse( $this->roles_row_exists(), "No roles row after: $name" );
				$this->assertTrue( aafm_oauth_user_may_connect( $contributor ), "Contributor after: $name" );
				$this->assertTrue( aafm_oauth_user_may_connect( $editor ), "Editor after: $name" );
				$this->assertSame( $editor->ID, $this->resolve( $tokens['access_token'] ), "Token after: $name" );
			}
		}
		$this->assertSame( '9', get_option( 'aafm_oauth_schema_version' ) );
	}

	public function test_an_unstamped_site_with_no_roles_option_allows_administrators_only(): void {
		aafm_truncate_oauth_tables();
		$this->unstamp();
		$this->assertTrue( aafm_oauth_install_is_fresh() );
		$this->assertFalse( aafm_oauth_user_may_connect( $this->user( 'editor' ) ) );
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'administrator' ) ) );
		add_filter( 'aafm_oauth_min_capability', static fn() => 'edit_posts' );
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'editor' ) ), 'The filter still wins.' );
	}

	public function test_an_unstamped_site_with_prior_oauth_use_keeps_edit_posts(): void {
		global $wpdb;
		$this->unstamp();
		// One client row (from set_up).
		$this->assertFalse( aafm_oauth_install_is_fresh() );
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'editor' ) ) );
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'contributor' ) ) );

		// Only a consent row.
		aafm_truncate_oauth_tables();
		$wpdb->insert(
			$wpdb->prefix . 'aafm_oauth_consents',
			array(
				'wp_user_id' => 3,
				'client_id'  => 'gone',
			)
		);
		$this->assertFalse( aafm_oauth_install_is_fresh() );
		$this->assertTrue( aafm_oauth_user_may_connect( $this->user( 'editor' ) ) );
	}

	public function test_an_unreadable_stamp_row_keeps_the_edit_posts_floor(): void {
		global $wpdb;
		aafm_truncate_oauth_tables();
		$this->unstamp();
		$editor = $this->user( 'editor' );
		$probes = array(
			"option_name = 'aafm_oauth_schema_version'",
			'FROM `' . $wpdb->prefix . 'aafm_oauth_clients`',
			'FROM `' . $wpdb->prefix . 'aafm_oauth_consents`',
		);
		foreach ( $probes as $probe ) {
			$result = QueryFaultInjector::fail_query(
				$probe,
				static fn() => array( aafm_oauth_install_is_fresh(), aafm_oauth_user_may_connect( $editor ) )
			);
			$this->assertSame( array( null, true ), $result, $probe );
		}
	}
}
