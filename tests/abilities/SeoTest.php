<?php
/**
 * SEO integration abilities — the unified set routed across Yoast / Rank Math / AIOSEO.
 *
 * Drives the SEO slice (W4-S). The host SEO plugins are NOT installed on the test site, so
 * each test forces the integration active through its per-slug filter and defines the minimal
 * host signal via the IntegrationStubs trait (for SEO that is just the active-plugin marker —
 * the abilities read/write the mapped keys with core get_post_meta/update_post_meta once the
 * key map resolves).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use WP_Error;

final class SeoTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'seo' );  // aafm_integration_active_seo => true.
		$this->stub_seo_plugin( 'yoast' );  // Defines WPSEO_VERSION so the Yoast key map applies.
		aafm_registry_cache_should_flush( true );
		$this->register_seo();
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
	 * Enable + register the SEO set so the abilities can be invoked.
	 */
	private function register_seo(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/seo-get-post', 'aafm/seo-update-post', 'aafm/seo-get-schema', 'aafm/seo-update-schema', 'aafm/seo-get-head' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_seo_get_post_reads_mapped_fields_per_object_gated(): void {
		$editor_id = $this->acting_as( 'editor' );
		$post_id   = (int) self::factory()->post->create(
			array(
				'post_author' => $editor_id,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_yoast_wpseo_title', 'SEO Title' );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'A description.' );

		$res = wp_get_ability( 'aafm/seo-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'yoast', $res['plugin'] );
		$this->assertSame( $post_id, $res['post_id'] );
		$this->assertSame( 'SEO Title', $res['title'] );
		$this->assertSame( 'A description.', $res['description'] );
		// Every unified field for the active plugin is present in the shape (empty when unset).
		$this->assertArrayHasKey( 'focus_keyword', $res );
		$this->assertArrayHasKey( 'canonical', $res );
		$this->assertArrayHasKey( 'og_title', $res );
	}

	public function test_seo_get_post_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-get-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_seo_get_post_denies_an_editor_on_anothers_post(): void {
		// Per-object gate: an editor can edit_post in general, but this proves the gate is
		// genuinely per-object (an editor DOES have edit_others_posts, so use an author whose
		// floor clears edit_posts yet is denied on another author's post).
		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		// A different author cannot edit the first author's post.
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-get-post' )->check_permissions( array( 'post_id' => $post_id ) ),
			'An author must be denied SEO read on another author\'s post.'
		);
	}

	public function test_seo_get_post_rejects_a_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/seo-get-post' )->execute(
			array(
				'post_id' => $post_id,
				'plugin'  => 'rankmath',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema rejects a smuggled field.' );
	}

	public function test_seo_update_post_round_trips_every_unified_field(): void {
		// MEDIUM-3: every field the read exposes must also be writable. A field missing from the
		// write schema would be rejected by additionalProperties:false and never round-trip.
		$editor_id = $this->acting_as( 'administrator' );
		$post_id   = (int) self::factory()->post->create( array( 'post_author' => $editor_id ) );

		$payload = array(
			'post_id'             => $post_id,
			'title'               => 'My Title',
			'description'         => 'My description.',
			'focus_keyword'       => 'widgets',
			'canonical'           => 'https://example.com/canonical',
			'robots'              => 'noindex,nofollow',
			'og_title'            => 'OG Title',
			'og_description'      => 'OG description.',
			'og_image'            => 'https://example.com/og.jpg',
			'twitter_title'       => 'TW Title',
			'twitter_description' => 'TW description.',
			'twitter_image'       => 'https://example.com/tw.jpg',
		);
		$res     = wp_get_ability( 'aafm/seo-update-post' )->execute( $payload );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A full unified write must succeed.' );

		// Read it back through seo-get-post: every field round-trips.
		$read = wp_get_ability( 'aafm/seo-get-post' )->execute( array( 'post_id' => $post_id ) );
		foreach ( aafm_seo_fields() as $field ) {
			$this->assertSame( $payload[ $field ], $read[ $field ], $field . ' did not round-trip.' );
		}
	}

	public function test_seo_update_post_url_fields_are_url_sanitized(): void {
		$editor_id = $this->acting_as( 'administrator' );
		$post_id   = (int) self::factory()->post->create( array( 'post_author' => $editor_id ) );

		// A javascript: scheme on a URL field is stripped to empty by esc_url_raw.
		wp_get_ability( 'aafm/seo-update-post' )->execute(
			array(
				'post_id'   => $post_id,
				'canonical' => 'javascript:alert(1)',
				'og_image'  => 'javascript:alert(2)',
			)
		);
		$this->assertSame( '', get_post_meta( $post_id, '_yoast_wpseo_canonical', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ) );
	}

	public function test_seo_update_post_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-update-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_seo_update_post_rejects_a_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/seo-update-post' )->execute(
			array(
				'post_id'   => $post_id,
				'post_type' => 'attachment',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A closed schema rejects a smuggled field.' );
	}

	public function test_seo_schema_round_trips_under_rank_math(): void {
		// Schema is Rank Math-primary. The RankMath stub marker makes aafm_seo_active_plugin()
		// report rankmath (the class is checked ahead of the Yoast define), so the schema
		// routing reads/writes the Rank Math storage.
		$this->reset_integration_stubs();
		$this->force_integration( 'seo' );
		$this->stub_seo_plugin( 'rankmath' );
		add_filter( 'aafm_seo_active_plugin', static fn() => 'rankmath' );
		aafm_registry_cache_should_flush( true );
		$this->register_seo();

		$editor_id = $this->acting_as( 'administrator' );
		$post_id   = (int) self::factory()->post->create( array( 'post_author' => $editor_id ) );

		$schema = array(
			'@type' => 'Article',
			'name'  => 'My Article',
			'about' => array(
				'@type' => 'Thing',
				'name'  => 'Nested Thing',
			),
		);
		$res    = wp_get_ability( 'aafm/seo-update-schema' )->execute(
			array(
				'post_id' => $post_id,
				'schema'  => $schema,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A schema write under Rank Math must succeed.' );

		$read = wp_get_ability( 'aafm/seo-get-schema' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'Article', $read['schema']['@type'] );
		$this->assertSame( 'Nested Thing', $read['schema']['about']['name'] );
	}

	public function test_seo_update_schema_strips_script_at_every_depth(): void {
		$this->reset_integration_stubs();
		$this->force_integration( 'seo' );
		$this->stub_seo_plugin( 'rankmath' );
		add_filter( 'aafm_seo_active_plugin', static fn() => 'rankmath' );
		aafm_registry_cache_should_flush( true );
		$this->register_seo();

		$editor_id = $this->acting_as( 'administrator' );
		$post_id   = (int) self::factory()->post->create( array( 'post_author' => $editor_id ) );

		$dirty = array(
			'@type' => '<script>alert(1)</script>Article',
			'about' => array(
				'name'   => '<script>alert(2)</script>Deep',
				'deeper' => array(
					'evil' => '<script>alert(3)</script>',
				),
			),
		);
		wp_get_ability( 'aafm/seo-update-schema' )->execute(
			array(
				'post_id' => $post_id,
				'schema'  => $dirty,
			)
		);
		$read = wp_get_ability( 'aafm/seo-get-schema' )->execute( array( 'post_id' => $post_id ) );
		$json = (string) wp_json_encode( $read['schema'] );
		$this->assertStringNotContainsString( '<script>', $json, 'Script must be stripped at every depth.' );
		$this->assertStringNotContainsString( 'alert(2)', $json, 'Nested script content must be stripped.' );
		$this->assertStringNotContainsString( 'alert(3)', $json, 'Deeply nested script content must be stripped.' );
	}

	public function test_seo_update_schema_refuses_a_non_array_payload(): void {
		$this->reset_integration_stubs();
		$this->force_integration( 'seo' );
		$this->stub_seo_plugin( 'rankmath' );
		add_filter( 'aafm_seo_active_plugin', static fn() => 'rankmath' );
		aafm_registry_cache_should_flush( true );
		$this->register_seo();

		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/seo-update-schema' )->execute(
			array(
				'post_id' => $post_id,
				'schema'  => 'not-an-array',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A non-array schema payload must be refused.' );
	}

	public function test_seo_get_schema_degrades_gracefully_on_non_rank_math(): void {
		// Schema is Rank Math-primary, so on Yoast the schema abilities return a documented
		// generic error rather than guessing at Yoast's storage. Pin yoast through the active-
		// plugin filter: an earlier schema test defines the RankMath marker class process-wide,
		// so detection order alone would otherwise still report rankmath here.
		add_filter( 'aafm_seo_active_plugin', static fn() => 'yoast' );
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/seo-get-schema' )->execute( array( 'post_id' => $post_id ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'Schema read on a non-Rank-Math plugin must degrade to an error.' );
	}

	public function test_seo_get_head_returns_a_head_string_best_effort(): void {
		$editor_id = $this->acting_as( 'administrator' );
		$post_id   = (int) self::factory()->post->create( array( 'post_author' => $editor_id ) );

		$res = wp_get_ability( 'aafm/seo-get-head' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $post_id, $res['post_id'] );
		$this->assertArrayHasKey( 'head', $res );
		// No host frontend-head API is stubbed, so head is an empty string — never fatal.
		$this->assertIsString( $res['head'] );
		$this->assertSame( '', $res['head'] );
	}

	public function test_seo_get_head_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-get-head' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_seo_update_post_write_is_audited(): void {
		// API HIGH-1: a successful SEO write must leave a 'success' audit row for the ability.
		$editor_id = $this->acting_as( 'administrator' );
		$post_id   = (int) self::factory()->post->create( array( 'post_author' => $editor_id ) );

		$res = wp_get_ability( 'aafm/seo-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'Audited Title',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/seo-update-post', $abilities );
	}

	public function test_seo_update_post_denied_is_audited(): void {
		// API HIGH-1: a denied SEO write must leave a 'denied' audit row for the ability.
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-update-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/seo-update-post', $abilities );
	}

	public function test_seo_update_schema_write_is_audited(): void {
		// API HIGH-1: same audit guarantee on the schema write path, exercised live under Rank Math.
		$this->reset_integration_stubs();
		$this->force_integration( 'seo' );
		$this->stub_seo_plugin( 'rankmath' );
		add_filter( 'aafm_seo_active_plugin', static fn() => 'rankmath' );
		aafm_registry_cache_should_flush( true );
		$this->register_seo();

		$editor_id = $this->acting_as( 'administrator' );
		$post_id   = (int) self::factory()->post->create( array( 'post_author' => $editor_id ) );

		$res = wp_get_ability( 'aafm/seo-update-schema' )->execute(
			array(
				'post_id' => $post_id,
				'schema'  => array( '@type' => 'Article' ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/seo-update-schema', $abilities );
	}

	public function test_seo_update_schema_denied_is_audited(): void {
		// API HIGH-1: a denied schema write must leave a 'denied' audit row for the ability.
		$this->reset_integration_stubs();
		$this->force_integration( 'seo' );
		$this->stub_seo_plugin( 'rankmath' );
		add_filter( 'aafm_seo_active_plugin', static fn() => 'rankmath' );
		aafm_registry_cache_should_flush( true );
		$this->register_seo();

		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-update-schema' )->check_permissions( array( 'post_id' => $post_id ) )
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/seo-update-schema', $abilities );
	}

	public function test_seo_get_schema_denies_a_subscriber(): void {
		// API HIGH-2: the schema read gates per-object, even under Rank Math.
		$this->reset_integration_stubs();
		$this->force_integration( 'seo' );
		$this->stub_seo_plugin( 'rankmath' );
		add_filter( 'aafm_seo_active_plugin', static fn() => 'rankmath' );
		aafm_registry_cache_should_flush( true );
		$this->register_seo();

		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-get-schema' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_seo_update_schema_denies_a_subscriber(): void {
		// API HIGH-2: the schema write gates per-object, even under Rank Math.
		$this->reset_integration_stubs();
		$this->force_integration( 'seo' );
		$this->stub_seo_plugin( 'rankmath' );
		add_filter( 'aafm_seo_active_plugin', static fn() => 'rankmath' );
		aafm_registry_cache_should_flush( true );
		$this->register_seo();

		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-update-schema' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_seo_update_schema_denies_an_author_on_anothers_post(): void {
		// API HIGH-2: this catches the gate degrading from edit_post($id) to a bare edit_posts
		// floor. An author clears edit_posts in general yet must be denied the schema write on
		// another author's post.
		$this->reset_integration_stubs();
		$this->force_integration( 'seo' );
		$this->stub_seo_plugin( 'rankmath' );
		add_filter( 'aafm_seo_active_plugin', static fn() => 'rankmath' );
		aafm_registry_cache_should_flush( true );
		$this->register_seo();

		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-update-schema' )->check_permissions( array( 'post_id' => $post_id ) ),
			'An author must be denied a schema write on another author\'s post.'
		);
	}

	public function test_seo_get_post_errors_on_missing_post(): void {
		// API MEDIUM-1: a never-created id resolves to no WP_Post. The permission gate fails first
		// (get_post() returns null inside aafm_perm_seo_post), so check_permissions is not true and
		// execute would also WP_Error — proving the read never operates on a phantom id.
		$this->acting_as( 'administrator' );
		$missing = 999999;
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-get-post' )->check_permissions( array( 'post_id' => $missing ) ),
			'A missing post must fail the per-object permission gate.'
		);
	}

	public function test_seo_round_trips_on_a_custom_post_type(): void {
		// API MEDIUM-1: the per-object gate keys on edit_post($id), which is post-type-agnostic, so
		// SEO read/write must round-trip on a public CPT just as on a core post.
		register_post_type(
			'aafm_seo_cpt',
			array(
				'public'          => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);

		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create(
			array(
				'post_type'   => 'aafm_seo_cpt',
				'post_author' => $admin_id,
			)
		);

		wp_get_ability( 'aafm/seo-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'CPT SEO Title',
			)
		);
		$read = wp_get_ability( 'aafm/seo-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $read );
		$this->assertSame( 'CPT SEO Title', $read['title'], 'SEO must round-trip on a custom post type.' );

		unregister_post_type( 'aafm_seo_cpt' );
	}

	public function test_seo_update_post_denies_an_author_on_anothers_post(): void {
		// API MEDIUM-2: the subscriber case only proves the floor. This proves the per-object
		// refinement: an author clears edit_posts yet is denied the write on another author's post.
		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/seo-update-post' )->check_permissions( array( 'post_id' => $post_id ) ),
			'An author must be denied an SEO write on another author\'s post.'
		);
	}

	public function test_seo_update_post_round_trips_every_unified_field_under_rank_math(): void {
		// API MEDIUM-3: the round-trip under Yoast cannot catch a typo in the rank_math_* key map.
		// Drive the same all-fields write/read under Rank Math so a wrong key fails to round-trip.
		$this->reset_integration_stubs();
		$this->force_integration( 'seo' );
		$this->stub_seo_plugin( 'rankmath' );
		add_filter( 'aafm_seo_active_plugin', static fn() => 'rankmath' );
		aafm_registry_cache_should_flush( true );
		$this->register_seo();

		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$payload = array(
			'post_id'             => $post_id,
			'title'               => 'RM Title',
			'description'         => 'RM description.',
			'focus_keyword'       => 'gadgets',
			'canonical'           => 'https://example.com/rm-canonical',
			'robots'              => 'noindex,nofollow',
			'og_title'            => 'RM OG Title',
			'og_description'      => 'RM OG description.',
			'og_image'            => 'https://example.com/rm-og.jpg',
			'twitter_title'       => 'RM TW Title',
			'twitter_description' => 'RM TW description.',
			'twitter_image'       => 'https://example.com/rm-tw.jpg',
		);
		$res     = wp_get_ability( 'aafm/seo-update-post' )->execute( $payload );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A full unified write under Rank Math must succeed.' );

		$read = wp_get_ability( 'aafm/seo-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'rankmath', $read['plugin'] );
		foreach ( aafm_seo_fields() as $field ) {
			$this->assertSame( $payload[ $field ], $read[ $field ], $field . ' did not round-trip under Rank Math.' );
		}
	}

	public function test_seo_update_schema_strips_javascript_url_at_depth(): void {
		// API LOW-3: the depth test proves <script> value-stripping, but not the URL-key
		// esc_url_raw() at depth. A javascript: value under a url-ish key nested two levels deep
		// must be stripped to empty.
		$this->reset_integration_stubs();
		$this->force_integration( 'seo' );
		$this->stub_seo_plugin( 'rankmath' );
		add_filter( 'aafm_seo_active_plugin', static fn() => 'rankmath' );
		aafm_registry_cache_should_flush( true );
		$this->register_seo();

		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$dirty = array(
			'@type'  => 'Article',
			'author' => array(
				'@type' => 'Person',
				'deep'  => array(
					'image' => 'javascript:alert(1)',
					'@id'   => 'javascript:alert(2)',
				),
			),
		);
		wp_get_ability( 'aafm/seo-update-schema' )->execute(
			array(
				'post_id' => $post_id,
				'schema'  => $dirty,
			)
		);
		$read = wp_get_ability( 'aafm/seo-get-schema' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( '', $read['schema']['author']['deep']['image'], 'A javascript: URL value at depth must be stripped.' );
		$this->assertSame( '', $read['schema']['author']['deep']['@id'], 'A javascript: @id value at depth must be stripped.' );
	}

	public function test_seo_abilities_absent_when_host_inactive(): void {
		// HIGH-2: assert at the REGISTRY level, not via aafm_user_can_discover_ability().
		// The discovery helper falls through to aafm_user_can_call_ability → the process-wide
		// static raw-permission $store stashed at registration, which persists for the
		// lifetime of the process once any test registered the SEO set. The registry is the
		// honest source of truth: a host-inactive integration contributes zero entries.
		//
		// The Yoast stub define()s WPSEO_VERSION process-wide (a constant cannot be undefined),
		// so real detection still reports yoast active here — drive the predicate to inactive
		// through its own filter (the same seam production detection passes through).
		$this->reset_integration_stubs();
		add_filter( 'aafm_integration_active_seo', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'seo' ) );
		aafm_registry_cache_should_flush( true ); // Rebuild the memo with SEO now inactive.
		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey(
			'aafm/seo-get-post',
			$registry,
			'A host-inactive integration ability must not be in the registry.'
		);
	}
}
