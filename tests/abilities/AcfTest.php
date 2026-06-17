<?php
/**
 * ACF / SCF integration abilities (slice W4-A).
 *
 * The DDEV site ships no ACF host plugin, so each test forces the integration active through its
 * per-slug filter and defines the minimal ACF host surface via stub_acf() (the IntegrationStubs
 * trait). The abilities walk acf_get_field_groups()/acf_get_fields() for discovery and read/write
 * hydrated values through get_fields()/get_field()/update_field(), all served by the stub store.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use WP_Error;

final class AcfTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'acf' );
		$this->stub_acf(
			array(
				'groups' => array(
					array(
						'key'    => 'group_1',
						'title'  => 'Hero',
						'fields' => array(
							array(
								'key'   => 'field_1',
								'label' => 'Headline',
								'type'  => 'text',
							),
						),
					),
				),
				'values' => array( 'field_1' => 'Hello' ),
			)
		);
		aafm_registry_cache_should_flush( true );
		$this->register_acf();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * @param string   $action Action name to simulate.
	 * @param callable $cb     Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $cb ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$cb();
		array_pop( $wp_current_filter );
	}

	/**
	 * Enable + register the ACF set so the abilities can be invoked.
	 */
	private function register_acf(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/acf-list-field-groups',
				'aafm/acf-get-post-fields',
				'aafm/acf-update-post-fields',
				'aafm/acf-get-term-fields',
				'aafm/acf-update-term-fields',
				'aafm/acf-get-user-fields',
				'aafm/acf-update-user-fields',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_list_field_groups_requires_edit_posts_and_returns_discovery_shape(): void {
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue( wp_get_ability( 'aafm/acf-list-field-groups' )->check_permissions( array() ) );

		$this->acting_as( 'editor' );
		$res = wp_get_ability( 'aafm/acf-list-field-groups' )->execute( array() );
		$this->assertArrayHasKey( 'field_groups', $res );
		$this->assertSame( 'group_1', $res['field_groups'][0]['key'] );
		$this->assertSame( 'Hero', $res['field_groups'][0]['title'] );
		$this->assertSame( 'field_1', $res['field_groups'][0]['fields'][0]['key'] );
		$this->assertSame( 'Headline', $res['field_groups'][0]['fields'][0]['label'] );
		$this->assertSame( 'text', $res['field_groups'][0]['fields'][0]['type'] );
		// Discovery shape only — never any stored VALUE.
		$json = (string) wp_json_encode( $res );
		$this->assertStringNotContainsString( 'Hello', $json, 'list-field-groups must not expose stored values.' );
	}

	public function test_acf_abilities_absent_when_host_inactive(): void {
		// HIGH-2: assert at the REGISTRY level (not via aafm_user_can_discover_ability, which leaks
		// through the process-wide raw-permission $store once any test registered the set). The
		// stub_acf() helper defines get_field() process-wide, so real detection still reports ACF
		// active after removing the force filter — pin it off through the aafm_acf_active seam.
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_acf' );
		add_filter( 'aafm_acf_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'acf' ) );
		aafm_registry_cache_should_flush( true );
		$this->assertArrayNotHasKey( 'aafm/acf-list-field-groups', aafm_get_abilities_registry() );
		remove_filter( 'aafm_acf_active', '__return_false', 99 );
	}

	// --- A2: post fields ---------------------------------------------------------------------

	public function test_get_post_fields_returns_hydrated_values_per_object_gated(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/acf-get-post-fields' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $post_id, $res['post_id'] );
		$this->assertArrayHasKey( 'fields', $res );
		$this->assertSame( 'Hello', $res['fields']['field_1'] );
	}

	public function test_get_post_fields_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-get-post-fields' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_update_post_fields_writes_and_round_trips(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_1' => 'Updated headline' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Updated headline', $res['fields']['field_1'] );

		$read = wp_get_ability( 'aafm/acf-get-post-fields' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'Updated headline', $read['fields']['field_1'], 'The ACF post write must round-trip.' );
	}

	public function test_update_post_fields_sanitizes_each_value(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_1' => "<script>alert(1)</script>clean" ),
			)
		);
		$read = wp_get_ability( 'aafm/acf-get-post-fields' )->execute( array( 'post_id' => $post_id ) );
		$json = (string) wp_json_encode( $read['fields'] );
		$this->assertStringNotContainsString( '<script>', $json, 'A text-field write must be sanitized.' );
	}

	public function test_update_post_fields_sanitizes_array_values_recursively(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		// A repeater-style nested array: every leaf must be sanitized at depth.
		wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array(
					'field_1' => array(
						array( 'inner' => '<script>alert(2)</script>row' ),
					),
				),
			)
		);
		$read = wp_get_ability( 'aafm/acf-get-post-fields' )->execute( array( 'post_id' => $post_id ) );
		$json = (string) wp_json_encode( $read['fields'] );
		$this->assertStringNotContainsString( '<script>', $json, 'Nested array leaves must be sanitized.' );
		$this->assertStringNotContainsString( 'alert(2)', $json, 'Deeply nested script content must be stripped.' );
	}

	public function test_update_post_fields_rejects_a_smuggled_top_level_field(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_1' => 'ok' ),
				'role'    => 'administrator',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema rejects a smuggled top-level field.' );
	}

	public function test_update_post_fields_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-update-post-fields' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_update_post_fields_denies_an_author_on_anothers_post(): void {
		// Per-object gate: an author clears edit_posts yet is denied on another author's post.
		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-update-post-fields' )->check_permissions( array( 'post_id' => $post_id ) ),
			'An author must be denied an ACF write on another author\'s post.'
		);
	}

	public function test_update_post_fields_write_is_audited(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_1' => 'Audited' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/acf-update-post-fields', $abilities );
	}

	public function test_update_post_fields_denied_is_audited(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-update-post-fields' )->check_permissions( array( 'post_id' => $post_id ) )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/acf-update-post-fields', $abilities );
	}
}
