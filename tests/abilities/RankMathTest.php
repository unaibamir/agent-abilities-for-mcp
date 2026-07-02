<?php
/**
 * Rank Math per-plugin abilities (Wave 5 Slice B): rankmath-get-post, rankmath-update-post,
 * rankmath-get-schema, rankmath-update-schema, rankmath-get-head.
 *
 * Rank Math is not installed on the test site, so the fixture forces the rankmath predicate active
 * and defines the minimal host signal (a RankMath marker class + the rendered-head filter) via
 * stub_rankmath(). The abilities read/write rank_math_* post meta with core get_post_meta/
 * update_post_meta - including the serialized robots array and the dynamic per-type schema keys.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use WP_Error;

final class RankMathTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'rankmath' );
		$this->stub_rankmath();
		aafm_registry_cache_should_flush( true );
		$this->register_rankmath();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Enable + register the Rank Math set so the abilities can be invoked.
	 */
	private function register_rankmath(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/rankmath-get-post',
				'aafm/rankmath-update-post',
				'aafm/rankmath-get-schema',
				'aafm/rankmath-update-schema',
				'aafm/rankmath-get-head',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_rankmath_get_post_reads_mapped_fields(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		update_post_meta( $post_id, 'rank_math_title', 'RM Title' );
		update_post_meta( $post_id, 'rank_math_description', 'RM description.' );

		$res = wp_get_ability( 'aafm/rankmath-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'rankmath', $res['plugin'] );
		$this->assertSame( 'RM Title', $res['title'] );
		$this->assertSame( 'RM description.', $res['description'] );
		$this->assertArrayHasKey( 'robots', $res );
	}

	public function test_rankmath_update_post_round_trips_every_field(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$payload = array(
			'post_id'             => $post_id,
			'title'               => 'RM Title',
			'description'         => 'RM description.',
			'focus_keyword'       => 'gadgets',
			'canonical'           => 'https://example.com/rm-canonical',
			'og_title'            => 'RM OG Title',
			'og_description'      => 'RM OG description.',
			'og_image'            => 'https://example.com/rm-og.jpg',
			'twitter_title'       => 'RM TW Title',
			'twitter_description' => 'RM TW description.',
			'twitter_image'       => 'https://example.com/rm-tw.jpg',
			'robots'              => 'noindex,nofollow',
		);
		$res     = wp_get_ability( 'aafm/rankmath-update-post' )->execute( $payload );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A full Rank Math write must succeed.' );

		$read = wp_get_ability( 'aafm/rankmath-get-post' )->execute( array( 'post_id' => $post_id ) );
		foreach ( $payload as $field => $value ) {
			if ( 'post_id' === $field ) {
				continue;
			}
			$this->assertSame( $value, $read[ $field ], $field . ' did not round-trip.' );
		}
	}

	public function test_rankmath_robots_is_stored_as_a_serialized_array(): void {
		// CRITICAL: rank_math_robots is a serialized PHP array of tokens, not a CSV string. The write
		// must store array('noindex','nofollow'); the read imploding it back to the unified string.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/rankmath-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'robots'  => 'noindex,nofollow',
			)
		);
		$stored = get_post_meta( $post_id, 'rank_math_robots', true );
		$this->assertSame( array( 'noindex', 'nofollow' ), $stored, 'rank_math_robots must be stored as an array.' );

		$read = wp_get_ability( 'aafm/rankmath-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'noindex,nofollow', $read['robots'], 'The read must implode the array back to the unified string.' );
	}

	public function test_rankmath_robots_drops_unknown_tokens(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/rankmath-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'robots'  => 'noindex,evil,noarchive',
			)
		);
		$stored = get_post_meta( $post_id, 'rank_math_robots', true );
		$this->assertSame( array( 'noindex', 'noarchive' ), $stored, 'An unknown robots token must be dropped.' );
	}

	public function test_rankmath_update_post_url_fields_are_url_sanitized(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/rankmath-update-post' )->execute(
			array(
				'post_id'   => $post_id,
				'canonical' => 'javascript:alert(1)',
				'og_image'  => 'javascript:alert(2)',
			)
		);
		$this->assertSame( '', get_post_meta( $post_id, 'rank_math_canonical_url', true ) );
		$this->assertSame( '', get_post_meta( $post_id, 'rank_math_facebook_image', true ) );
	}

	public function test_rankmath_update_post_denies_an_author_on_anothers_post(): void {
		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/rankmath-update-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_rankmath_update_post_rejects_a_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/rankmath-update-post' )->execute(
			array(
				'post_id'   => $post_id,
				'post_type' => 'attachment',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_rankmath_schema_writes_the_dynamic_per_type_key(): void {
		// CRITICAL: Rank Math schema lives under rank_math_schema_{Type}, not a flat rank_math_schema.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$schema = array(
			'@type' => 'Article',
			'name'  => 'My Article',
			'about' => array(
				'@type' => 'Thing',
				'name'  => 'Nested Thing',
			),
		);
		$res    = wp_get_ability( 'aafm/rankmath-update-schema' )->execute(
			array(
				'post_id' => $post_id,
				'type'    => 'Article',
				'schema'  => $schema,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A schema write must succeed.' );

		// The exact dynamic meta key was written, and the flat key was NOT.
		$this->assertNotEmpty( get_post_meta( $post_id, 'rank_math_schema_Article', true ), 'The dynamic per-type key must be written.' );
		$this->assertSame( '', get_post_meta( $post_id, 'rank_math_schema', true ), 'The flat key must NOT be written.' );

		$read = wp_get_ability( 'aafm/rankmath-get-schema' )->execute(
			array(
				'post_id' => $post_id,
				'type'    => 'Article',
			)
		);
		// The top-level schema map is (object)-cast so an empty one encodes as {}; populated nested
		// arrays stay arrays. Read the top level as an object property, the nested leaf as an array.
		$schema = (array) $read['schema'];
		$this->assertSame( 'Article', $schema['@type'] );
		$this->assertSame( 'Nested Thing', $schema['about']['name'] );
	}

	public function test_rankmath_schema_strips_script_and_javascript_url_at_depth(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$dirty = array(
			'@type'  => '<script>alert(1)</script>Article',
			'author' => array(
				'@type' => 'Person',
				'deep'  => array(
					'image' => 'javascript:alert(2)',
					'@id'   => 'javascript:alert(3)',
				),
			),
		);
		wp_get_ability( 'aafm/rankmath-update-schema' )->execute(
			array(
				'post_id' => $post_id,
				'type'    => 'Article',
				'schema'  => $dirty,
			)
		);
		$read = wp_get_ability( 'aafm/rankmath-get-schema' )->execute(
			array(
				'post_id' => $post_id,
				'type'    => 'Article',
			)
		);
		$json = (string) wp_json_encode( $read['schema'] );
		$this->assertStringNotContainsString( '<script>', $json, 'A <script> leaf must be stripped.' );
		$schema = (array) $read['schema'];
		$this->assertSame( '', $schema['author']['deep']['image'], 'A javascript: URL at depth must be stripped.' );
		$this->assertSame( '', $schema['author']['deep']['@id'], 'A javascript: @id at depth must be stripped.' );
	}

	public function test_rankmath_update_schema_refuses_a_non_array_payload(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/rankmath-update-schema' )->execute(
			array(
				'post_id' => $post_id,
				'type'    => 'Article',
				'schema'  => 'not-an-array',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_rankmath_update_schema_rejects_a_bad_type(): void {
		// The type suffix becomes part of a meta key, so a type with disallowed characters is refused.
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/rankmath-update-schema' )->execute(
			array(
				'post_id' => $post_id,
				'type'    => 'Article; DROP',
				'schema'  => array( '@type' => 'Article' ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A type with disallowed characters must be refused.' );
	}

	public function test_rankmath_get_schema_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/rankmath-get-schema' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_rankmath_get_head_returns_a_head_string(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/rankmath-get-head' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'rankmath', $res['plugin'] );
		$this->assertStringContainsString( 'Rank Math head', $res['head'] );
	}

	public function test_rankmath_get_post_reads_a_legacy_string_robots_value(): void {
		// Defensive: a legacy/imported row may hold rank_math_robots as a raw CSV string rather than
		// the current serialized array. The read must pass that string through, not floor it to ''.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		update_post_meta( $post_id, 'rank_math_robots', 'noindex,nofollow' );

		$res = wp_get_ability( 'aafm/rankmath-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'noindex,nofollow', $res['robots'], 'A legacy string robots value must read back as that string.' );
	}

	public function test_rankmath_get_post_unknown_id_is_rejected(): void {
		// An unknown post_id fails the per-object aafm_perm_seo_post_object gate (get_post() is not a
		// WP_Post), so the Abilities API short-circuits with ability_invalid_permissions before the
		// executor's defence-in-depth aafm_generic_error() can run. Either way the read is refused.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/rankmath-get-post' )->execute( array( 'post_id' => PHP_INT_MAX ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'ability_invalid_permissions', $res->get_error_code() );
	}

	public function test_rankmath_get_schema_unknown_id_is_rejected(): void {
		// Same per-object gate as get-post: an unknown post is refused before execute.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/rankmath-get-schema' )->execute(
			array(
				'post_id' => PHP_INT_MAX,
				'type'    => 'Article',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'ability_invalid_permissions', $res->get_error_code() );
	}

	public function test_rankmath_empty_patch_leaves_seeded_fields_unchanged(): void {
		// An update carrying only post_id (no field keys) must be a no-op: the array_key_exists skip
		// per field must NOT blank every key.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		update_post_meta( $post_id, 'rank_math_title', 'Seeded Title' );

		$res = wp_get_ability( 'aafm/rankmath-update-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'An empty PATCH must not error.' );
		$this->assertSame( 'Seeded Title', $res['title'], 'An empty PATCH must leave the seeded title untouched.' );
	}

	public function test_rankmath_get_schema_empty_store_encodes_as_object(): void {
		// A never-set schema must JSON-encode to "{}" per the output_schema's type:object, never "[]"
		// (mirrors the acf / get-all-post-meta empty-map regression pattern).
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/rankmath-get-schema' )->execute(
			array(
				'post_id' => $post_id,
				'type'    => 'Article',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertIsObject( $res['schema'], 'An empty schema must be an object, not an array.' );
		$encoded = wp_json_encode( $res );
		$this->assertIsString( $encoded );
		$this->assertStringContainsString( '"schema":{}', $encoded, 'An empty schema must encode as {}, not [].' );
	}

	public function test_rankmath_abilities_absent_when_host_inactive(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_rankmath' );
		add_filter( 'aafm_rankmath_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'rankmath' ) );
		aafm_registry_cache_should_flush( true );
		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/rankmath-get-post', $registry );
		$this->assertArrayNotHasKey( 'aafm/rankmath-update-schema', $registry );
	}
}
