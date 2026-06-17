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

	/**
	 * A2: post fields.
	 */
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
				'fields'  => array( 'field_1' => '<script>alert(1)</script>clean' ),
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

	/**
	 * A3: term fields.
	 */
	public function test_get_term_fields_returns_hydrated_values_per_object_gated(): void {
		$this->acting_as( 'administrator' );
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$res = wp_get_ability( 'aafm/acf-get-term-fields' )->execute( array( 'term_id' => $term_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $term_id, $res['term_id'] );
		$this->assertArrayHasKey( 'fields', $res );
		$this->assertSame( 'Hello', $res['fields']['field_1'] );
	}

	public function test_get_term_fields_denies_a_subscriber(): void {
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-get-term-fields' )->check_permissions( array( 'term_id' => $term_id ) )
		);
	}

	public function test_update_term_fields_writes_through_the_term_selector_and_round_trips(): void {
		$this->acting_as( 'administrator' );
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$res = wp_get_ability( 'aafm/acf-update-term-fields' )->execute(
			array(
				'term_id' => $term_id,
				'fields'  => array( 'field_1' => 'Term value' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Term value', $res['fields']['field_1'] );

		// The write was recorded under the "term_{id}" ACF selector, not the post bucket.
		$this->assertSame(
			'Term value',
			\AAFM\Tests\AcfStubStore::value( 'field_1', 'term_' . $term_id ),
			'The term write must use the term_{id} selector.'
		);

		$read = wp_get_ability( 'aafm/acf-get-term-fields' )->execute( array( 'term_id' => $term_id ) );
		$this->assertSame( 'Term value', $read['fields']['field_1'] );
	}

	public function test_update_term_fields_rejects_a_smuggled_top_level_field(): void {
		$this->acting_as( 'administrator' );
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$res     = wp_get_ability( 'aafm/acf-update-term-fields' )->execute(
			array(
				'term_id' => $term_id,
				'fields'  => array( 'field_1' => 'ok' ),
				'parent'  => 1,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema rejects a smuggled top-level field.' );
	}

	public function test_update_term_fields_denies_a_subscriber(): void {
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-update-term-fields' )->check_permissions( array( 'term_id' => $term_id ) )
		);
	}

	public function test_update_term_fields_denies_an_author_without_edit_term(): void {
		// Per-object gate at execute: an author clears the edit_posts discovery floor yet lacks
		// edit_term (manage_categories) on the target term, so the real callback denies.
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-update-term-fields' )->check_permissions( array( 'term_id' => $term_id ) ),
			'An author without edit_term must be denied the term ACF write.'
		);
	}

	public function test_update_term_fields_write_is_audited(): void {
		$this->acting_as( 'administrator' );
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$res = wp_get_ability( 'aafm/acf-update-term-fields' )->execute(
			array(
				'term_id' => $term_id,
				'fields'  => array( 'field_1' => 'Audited' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/acf-update-term-fields', $abilities );
	}

	public function test_update_term_fields_denied_is_audited(): void {
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-update-term-fields' )->check_permissions( array( 'term_id' => $term_id ) )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/acf-update-term-fields', $abilities );
	}

	/**
	 * A4: user fields (PII under the disclaimer).
	 *
	 * Re-seed the ACF stub with a user_email-type field carrying a real address, then re-register.
	 *
	 * @param string $email The seeded email value.
	 * @return void
	 */
	private function stub_acf_with_user_email( string $email ): void {
		$this->reset_integration_stubs();
		$this->force_integration( 'acf' );
		$this->stub_acf(
			array(
				'groups' => array(
					array(
						'key'    => 'group_user',
						'title'  => 'Profile',
						'fields' => array(
							array(
								'key'   => 'field_email',
								'label' => 'Contact email',
								'type'  => 'user', // ACF user_email-style field: stores an address.
							),
						),
					),
				),
				'values' => array( 'field_email' => $email ),
			)
		);
		aafm_registry_cache_should_flush( true );
		$this->register_acf();
	}

	public function test_get_user_fields_returns_hydrated_values_per_object_gated(): void {
		$this->acting_as( 'administrator' );
		$user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$res = wp_get_ability( 'aafm/acf-get-user-fields' )->execute( array( 'user_id' => $user_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $user_id, $res['user_id'] );
		$this->assertArrayHasKey( 'fields', $res );
		$this->assertSame( 'Hello', $res['fields']['field_1'] );
	}

	public function test_get_user_fields_exposes_the_user_email_field_value_not_stripped(): void {
		// LOCKED decision: a user_email-type ACF field is returned IN FULL under the disclaimer.
		// The edit_user gate + default-OFF + audit are the governance, not a redactor.
		$this->stub_acf_with_user_email( 'acf-pii@example.com' );

		$this->acting_as( 'administrator' );
		$user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$res  = wp_get_ability( 'aafm/acf-get-user-fields' )->execute( array( 'user_id' => $user_id ) );
		$json = (string) wp_json_encode( $res['fields'] );
		$this->assertStringContainsString(
			'acf-pii@example.com',
			$json,
			'A user_email-type ACF field VALUE must be exposed under the disclaimer, not stripped.'
		);
	}

	public function test_get_user_fields_denies_a_subscriber(): void {
		$user_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-get-user-fields' )->check_permissions( array( 'user_id' => $user_id ) )
		);
	}

	public function test_get_user_fields_denies_an_author_without_edit_user(): void {
		// Per-object gate: an author cannot edit_user on another account.
		$target = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-get-user-fields' )->check_permissions( array( 'user_id' => $target ) ),
			'An author without edit_user must be denied the user ACF read.'
		);
	}

	public function test_update_user_fields_writes_through_the_user_selector_and_round_trips(): void {
		$this->acting_as( 'administrator' );
		$user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$res = wp_get_ability( 'aafm/acf-update-user-fields' )->execute(
			array(
				'user_id' => $user_id,
				'fields'  => array( 'field_1' => 'User value' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'User value', $res['fields']['field_1'] );

		$this->assertSame(
			'User value',
			\AAFM\Tests\AcfStubStore::value( 'field_1', 'user_' . $user_id ),
			'The user write must use the user_{id} selector.'
		);

		$read = wp_get_ability( 'aafm/acf-get-user-fields' )->execute( array( 'user_id' => $user_id ) );
		$this->assertSame( 'User value', $read['fields']['field_1'] );
	}

	public function test_update_user_fields_rejects_a_smuggled_top_level_field(): void {
		$this->acting_as( 'administrator' );
		$user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$res     = wp_get_ability( 'aafm/acf-update-user-fields' )->execute(
			array(
				'user_id' => $user_id,
				'fields'  => array( 'field_1' => 'ok' ),
				'role'    => 'administrator',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema rejects a smuggled top-level field (e.g. role).' );
	}

	public function test_update_user_fields_denies_a_subscriber(): void {
		$user_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-update-user-fields' )->check_permissions( array( 'user_id' => $user_id ) )
		);
	}

	public function test_update_user_fields_denies_an_author_on_another_user(): void {
		$target = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-update-user-fields' )->check_permissions( array( 'user_id' => $target ) ),
			'An author without edit_user must be denied the user ACF write.'
		);
	}

	public function test_update_user_fields_write_is_audited(): void {
		$this->acting_as( 'administrator' );
		$user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$res = wp_get_ability( 'aafm/acf-update-user-fields' )->execute(
			array(
				'user_id' => $user_id,
				'fields'  => array( 'field_1' => 'Audited' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/acf-update-user-fields', $abilities );
	}

	public function test_update_user_fields_denied_is_audited(): void {
		$user_id = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/acf-update-user-fields' )->check_permissions( array( 'user_id' => $user_id ) )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/acf-update-user-fields', $abilities );
	}
}
