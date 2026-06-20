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
		$fields = (array) $res['fields'];
		$this->assertSame( 'Hello', $fields['field_1'] );
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
		$this->assertSame( 'Updated headline', ( (array) $res['fields'] )['field_1'] );

		// LOW-1: pin the selector like the term/user tests so a broken post selector can't pass via
		// the stub's global seed merge — the write must land under the post-id bucket specifically.
		$this->assertSame(
			'Updated headline',
			\AAFM\Tests\AcfStubStore::value( 'field_1', $post_id ),
			'The post write must use the post-id selector.'
		);

		$read = wp_get_ability( 'aafm/acf-get-post-fields' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'Updated headline', ( (array) $read['fields'] )['field_1'], 'The ACF post write must round-trip.' );
	}

	/**
	 * T2-1: when update_field() fails (nothing stored), the write returns the generic error,
	 * not a fake-success refreshed read.
	 */
	public function test_update_post_fields_update_failure_returns_error(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		\AAFM\Tests\AcfStubStore::$update_should_fail = true;
		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_1' => 'Will not persist' ),
			)
		);
		\AAFM\Tests\AcfStubStore::$update_should_fail = false;

		$this->assertInstanceOf( WP_Error::class, $res, 'A failed update_field must surface as an error, not a fake-success read.' );
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
		$fields = (array) $res['fields'];
		$this->assertSame( 'Hello', $fields['field_1'] );
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
		$this->assertSame( 'Term value', ( (array) $res['fields'] )['field_1'] );

		// The write was recorded under the "term_{id}" ACF selector, not the post bucket.
		$this->assertSame(
			'Term value',
			\AAFM\Tests\AcfStubStore::value( 'field_1', 'term_' . $term_id ),
			'The term write must use the term_{id} selector.'
		);

		$read = wp_get_ability( 'aafm/acf-get-term-fields' )->execute( array( 'term_id' => $term_id ) );
		$this->assertSame( 'Term value', ( (array) $read['fields'] )['field_1'] );
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
		$fields = (array) $res['fields'];
		$this->assertSame( 'Hello', $fields['field_1'] );
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
		$this->assertSame( 'User value', ( (array) $res['fields'] )['field_1'] );

		$this->assertSame(
			'User value',
			\AAFM\Tests\AcfStubStore::value( 'field_1', 'user_' . $user_id ),
			'The user write must use the user_{id} selector.'
		);

		$read = wp_get_ability( 'aafm/acf-get-user-fields' )->execute( array( 'user_id' => $user_id ) );
		$this->assertSame( 'User value', ( (array) $read['fields'] )['field_1'] );
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

	/**
	 * Re-seed the ACF stub with an EMPTY value set, then re-register, so an object with no ACF data
	 * exercises the empty-map path (the default fixture always seeds field_1 => 'Hello').
	 *
	 * @return void
	 */
	private function stub_acf_empty(): void {
		$this->reset_integration_stubs();
		$this->force_integration( 'acf' );
		$this->stub_acf(
			array(
				'groups' => array(),
				'values' => array(),
			)
		);
		aafm_registry_cache_should_flush( true );
		$this->register_acf();
	}

	/**
	 * HIGH-1: an object with no ACF data returns an EMPTY fields map that JSON-encodes to "{}"
	 * (object), never "[]" (list), per the type:object output schema. Mirrors the get-all-post-meta
	 * regression. The default fixture always seeds a value, so this re-seeds empty to exercise it.
	 */
	public function test_get_post_fields_empty_encodes_as_object_not_array(): void {
		$this->stub_acf_empty();
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/acf-get-post-fields' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( '{}', (string) wp_json_encode( $res['fields'] ), 'Empty post fields must encode as {}.' );
	}

	public function test_get_term_fields_empty_encodes_as_object_not_array(): void {
		$this->stub_acf_empty();
		$this->acting_as( 'administrator' );
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$res = wp_get_ability( 'aafm/acf-get-term-fields' )->execute( array( 'term_id' => $term_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( '{}', (string) wp_json_encode( $res['fields'] ), 'Empty term fields must encode as {}.' );
	}

	public function test_get_user_fields_empty_encodes_as_object_not_array(): void {
		$this->stub_acf_empty();
		$this->acting_as( 'administrator' );
		$user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$res = wp_get_ability( 'aafm/acf-get-user-fields' )->execute( array( 'user_id' => $user_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( '{}', (string) wp_json_encode( $res['fields'] ), 'Empty user fields must encode as {}.' );
	}

	/**
	 * HIGH-1: an empty-fields WRITE also returns "{}" on the refreshed read shape.
	 */
	public function test_update_post_fields_empty_write_encodes_as_object_not_array(): void {
		$this->stub_acf_empty();
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array(),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( '{}', (string) wp_json_encode( $res['fields'] ), 'An empty write must refresh to {}.' );
	}

	/**
	 * Re-seed the ACF stub with one typed field, then re-register.
	 *
	 * @param string              $key  Field key.
	 * @param string              $type ACF field type (e.g. 'url', 'link', 'wysiwyg').
	 * @param array<string,mixed> $vals Seed values (field key => value), defaults to empty.
	 * @return void
	 */
	private function stub_acf_typed_field( string $key, string $type, array $vals = array() ): void {
		$this->reset_integration_stubs();
		$this->force_integration( 'acf' );
		$this->stub_acf(
			array(
				'groups' => array(
					array(
						'key'    => 'group_typed',
						'title'  => 'Typed',
						'fields' => array(
							array(
								'key'   => $key,
								'label' => ucfirst( $type ),
								'type'  => $type,
							),
						),
					),
				),
				'values' => $vals,
			)
		);
		aafm_registry_cache_should_flush( true );
		$this->register_acf();
	}

	/**
	 * MEDIUM: a link-typed field carries a structured array {title,url,target}. The recursive
	 * sanitizer must NOT esc_url_raw the plain-text members — the title must survive intact and the
	 * url must be preserved as a URL.
	 */
	public function test_update_post_fields_link_type_preserves_title_and_url(): void {
		$this->stub_acf_typed_field( 'field_link', 'link' );
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array(
					'field_link' => array(
						'title'  => 'Read more',
						'url'    => 'https://x.test',
						'target' => '_blank',
					),
				),
			)
		);
		$stored = \AAFM\Tests\AcfStubStore::value( 'field_link', $post_id );
		$this->assertIsArray( $stored );
		$this->assertSame( 'Read more', $stored['title'], 'A link title is plain text and must survive intact.' );
		$this->assertSame( 'https://x.test', $stored['url'], 'A link url must be preserved as a URL.' );
		$this->assertSame( '_blank', $stored['target'], 'A link target is plain text and must survive intact.' );
	}

	/**
	 * SecOps Low: a url-typed SCALAR field with a javascript: scheme has the scheme stripped by
	 * esc_url_raw before it reaches update_field.
	 */
	public function test_update_post_fields_url_scalar_strips_javascript_scheme(): void {
		$this->stub_acf_typed_field( 'field_url', 'url' );
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_url' => 'javascript:alert(1)' ),
			)
		);
		$stored = (string) \AAFM\Tests\AcfStubStore::value( 'field_url', $post_id );
		$this->assertStringNotContainsString( 'javascript:', $stored, 'esc_url_raw must strip a javascript: scheme.' );
	}

	/**
	 * TF-1: an image/file field stores an attachment ID but ACF reads it back FORMATTED (an array).
	 * A successful write must not be reported as an error just because the formatted read-back
	 * differs from the stored attachment id. The verify step has to compare the RAW stored value.
	 */
	public function test_update_post_fields_image_attachment_id_writes_without_false_error(): void {
		$this->stub_acf_typed_field( 'field_image', 'image' );
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_image' => 42 ),
			)
		);

		$this->assertNotInstanceOf(
			WP_Error::class,
			$res,
			'An image field writing an attachment id must not be reported as a failed write.'
		);
		$this->assertSame(
			'42',
			(string) \AAFM\Tests\AcfStubStore::value( 'field_image', $post_id ),
			'The raw attachment id must persist under the post selector.'
		);
	}

	/**
	 * TF-1: a date_picker field stores Ymd but reads back FORMATTED (d/m/Y). A successful write
	 * must not surface as an error from the format divergence alone.
	 */
	public function test_update_post_fields_date_writes_without_false_error(): void {
		$this->stub_acf_typed_field( 'field_date', 'date_picker' );
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_date' => '20260620' ),
			)
		);

		$this->assertNotInstanceOf(
			WP_Error::class,
			$res,
			'A date field write must not be reported as a failure due to formatted read-back.'
		);
		$this->assertSame(
			'20260620',
			(string) \AAFM\Tests\AcfStubStore::value( 'field_date', $post_id ),
			'The raw Ymd date must persist under the post selector.'
		);
	}

	/**
	 * TF-1: the genuine-failure detection from T2-1 still holds for a formatted field — when
	 * update_field() stores nothing, the write still returns the generic error.
	 */
	public function test_update_post_fields_formatted_field_real_failure_still_errors(): void {
		$this->stub_acf_typed_field( 'field_image', 'image' );
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		\AAFM\Tests\AcfStubStore::$update_should_fail = true;
		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_image' => 42 ),
			)
		);
		\AAFM\Tests\AcfStubStore::$update_should_fail = false;

		$this->assertInstanceOf(
			WP_Error::class,
			$res,
			'A real failed write on a formatted field must still surface as the generic error.'
		);
	}

	/**
	 * T1-6: a repeater whose sub_fields include a URL subfield must run that nested leaf through
	 * esc_url_raw, not the plain-text sanitizer — otherwise a javascript: scheme stored in a
	 * repeater row survives to be rendered by a theme. The plain-text sub_field round-trips
	 * intact, and the structured shape is preserved.
	 */
	public function test_update_post_fields_repeater_url_subfield_strips_javascript(): void {
		$this->reset_integration_stubs();
		$this->force_integration( 'acf' );
		$this->stub_acf(
			array(
				'groups' => array(
					array(
						'key'    => 'group_rep',
						'title'  => 'Repeater group',
						'fields' => array(
							array(
								'key'        => 'field_rep',
								'name'       => 'rep',
								'label'      => 'Rows',
								'type'       => 'repeater',
								'sub_fields' => array(
									array(
										'key'  => 'field_rep_link',
										'name' => 'link',
										'type' => 'url',
									),
									array(
										'key'  => 'field_rep_caption',
										'name' => 'caption',
										'type' => 'text',
									),
								),
							),
						),
					),
				),
			)
		);
		aafm_registry_cache_should_flush( true );
		$this->register_acf();

		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array(
					'field_rep' => array(
						array(
							'link'    => 'javascript:alert(1)',
							'caption' => 'Plain caption',
						),
					),
				),
			)
		);

		$stored = \AAFM\Tests\AcfStubStore::value( 'field_rep', $post_id );
		$this->assertIsArray( $stored );
		$this->assertStringNotContainsString( 'javascript:', (string) $stored[0]['link'], 'A url subfield in a repeater must have its javascript: scheme stripped at depth.' );
		$this->assertSame( 'Plain caption', $stored[0]['caption'], 'A plain-text subfield must round-trip intact.' );
	}

	/**
	 * SecOps Low: a wysiwyg field is sanitized with wp_kses_post — a <script> is dropped while a
	 * benign <strong> is kept (the policy stated in the build log).
	 */
	public function test_update_post_fields_wysiwyg_strips_script_keeps_strong(): void {
		$this->stub_acf_typed_field( 'field_wys', 'wysiwyg' );
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_wys' => '<script>alert(1)</script><strong>bold</strong>' ),
			)
		);
		$stored = (string) \AAFM\Tests\AcfStubStore::value( 'field_wys', $post_id );
		$this->assertStringNotContainsString( '<script>', $stored, 'wp_kses_post must drop a <script>.' );
		$this->assertStringContainsString( '<strong>', $stored, 'wp_kses_post must keep a benign <strong>.' );
	}

	/**
	 * MEDIUM-1: a nonexistent id on each READ returns a graceful WP_Error (not a fatal). The
	 * per-object permission callback already rejects a nonexistent id, so this drives ->execute()
	 * directly to exercise the execute-level existence guard (the second line of defence).
	 */
	public function test_get_post_fields_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/acf-get-post-fields' )->execute( array( 'post_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_get_term_fields_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/acf-get-term-fields' )->execute( array( 'term_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_get_user_fields_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/acf-get-user-fields' )->execute( array( 'user_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * MEDIUM-1: a nonexistent id on each WRITE returns a graceful WP_Error (not a fatal), driven
	 * through ->execute() to exercise the execute-level existence guard.
	 */
	public function test_update_post_fields_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => 999999,
				'fields'  => array( 'field_1' => 'x' ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_update_term_fields_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/acf-update-term-fields' )->execute(
			array(
				'term_id' => 999999,
				'fields'  => array( 'field_1' => 'x' ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_update_user_fields_nonexistent_id_is_graceful_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/acf-update-user-fields' )->execute(
			array(
				'user_id' => 999999,
				'fields'  => array( 'field_1' => 'x' ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * MEDIUM-3: an empty-fields write succeeds, leaves prior values intact, and round-trips.
	 */
	public function test_update_post_fields_empty_write_preserves_prior_values(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		// Seed a value via a first write, then an empty write must not disturb it.
		wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_1' => 'Seeded' ),
			)
		);
		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array(),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Seeded', ( (array) $res['fields'] )['field_1'], 'An empty write must leave prior values intact.' );

		$read = wp_get_ability( 'aafm/acf-get-post-fields' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'Seeded', ( (array) $read['fields'] )['field_1'], 'The prior value must round-trip.' );
	}

	/**
	 * MEDIUM-3: an unknown field key (no ACF definition — acf_get_field returns false) is sanitized
	 * as plain text and round-trips without a fatal.
	 */
	public function test_update_post_fields_unknown_field_key_sanitizes_as_text(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_unknown' => '<script>alert(1)</script>plain' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$stored = (string) \AAFM\Tests\AcfStubStore::value( 'field_unknown', $post_id );
		$this->assertStringNotContainsString( '<script>', $stored, 'An unknown field key sanitizes as text.' );
		$this->assertStringContainsString( 'plain', $stored, 'The benign remainder round-trips.' );
	}

	/**
	 * MEDIUM-2: the user-field abilities route to the edit_users discovery floor in server.php (the
	 * only ACF floor differing from edit_posts). An editor (edit_posts, NOT edit_users) discovers
	 * the post-field ability but NOT the user-field one; an admin discovers both. Discovery-helper
	 * use is correct here — this is the positive floor proof, distinct from the registry-level
	 * host-inactive test.
	 */
	public function test_user_fields_discovery_respects_the_edit_users_floor(): void {
		$this->acting_as( 'editor' );
		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/acf-get-post-fields' ),
			'An editor (edit_posts) must discover the post-field ability.'
		);
		$this->assertFalse(
			aafm_user_can_discover_ability( 'aafm/acf-get-user-fields' ),
			'An editor lacking edit_users must NOT discover the user-field ability.'
		);

		$this->acting_as( 'administrator' );
		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/acf-get-post-fields' ),
			'An admin must discover the post-field ability.'
		);
		$this->assertTrue(
			aafm_user_can_discover_ability( 'aafm/acf-get-user-fields' ),
			'An admin (edit_users) must discover the user-field ability.'
		);
	}
}
