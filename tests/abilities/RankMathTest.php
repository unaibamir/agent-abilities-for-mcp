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

		// Social image fields must point at real media-library attachments: the frontend renders the
		// image from the attachment id, so the write refuses a URL that maps to no attachment.
		$fb_att = (int) self::factory()->attachment->create_object( 'rm-og.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		$tw_att = (int) self::factory()->attachment->create_object( 'rm-tw.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		$fb_url = (string) wp_get_attachment_url( $fb_att );
		$tw_url = (string) wp_get_attachment_url( $tw_att );

		$payload = array(
			'post_id'             => $post_id,
			'title'               => 'RM Title',
			'description'         => 'RM description.',
			'focus_keyword'       => 'gadgets',
			'canonical'           => 'https://example.com/rm-canonical',
			'og_title'            => 'RM OG Title',
			'og_description'      => 'RM OG description.',
			'og_image'            => $fb_url,
			'twitter_title'       => 'RM TW Title',
			'twitter_description' => 'RM TW description.',
			'twitter_image'       => $tw_url,
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

	/**
	 * B20: a backslash in a written value must survive the update_post_meta unslash.
	 *
	 * The update_post_meta() call unslashes the value it stores, so a title/description carrying a backslash
	 * (a Windows path, a regex) lost one level unless the writer slashed first. The write reported
	 * the pre-store value while storage was mangled, so the response did not equal the persisted
	 * state. Assert the read-back (the wire value a client receives) preserves the backslash.
	 */
	public function test_rankmath_update_post_preserves_a_backslash() {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$description = 'Path C:\\Users\\test and a regex \\d+';

		$res = wp_get_ability( 'aafm/rankmath-update-post' )->execute(
			array(
				'post_id'     => $post_id,
				'description' => $description,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$read = wp_get_ability( 'aafm/rankmath-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame(
			$description,
			$read['description'],
			'A backslash in a Rank Math value must survive the update_post_meta unslash.'
		);
	}

	public function test_rankmath_social_images_render_via_id_meta_and_disable_twitter_fallback(): void {
		/*
		 * The frontend OpenGraph resolver renders the image from rank_math_{facebook,twitter}_image_id
		 * (an ATTACHMENT ID), never the URL meta, and Twitter silently falls back to Facebook whenever
		 * rank_math_twitter_use_facebook is truthy or absent. A write that only persists URL meta and
		 * leaves the fallback on renders nothing. Verified against Rank Math 1.0.274.1:
		 *   includes/opengraph/class-image.php:405-410  reads {prefix}_image_id, add_image_by_id().
		 *   includes/class-metadata.php:124-125         get_metadata() prepends rank_math_.
		 *   includes/opengraph/class-twitter.php:93-106 use_facebook switches the prefix.
		 *   includes/helpers/class-options.php:51-62    normalize_data(): only 'off' reads as false.
		 */
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$fb_att = (int) self::factory()->attachment->create_object( 'rm-og.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		$tw_att = (int) self::factory()->attachment->create_object( 'rm-tw.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );

		$res = wp_get_ability( 'aafm/rankmath-update-post' )->execute(
			array(
				'post_id'       => $post_id,
				'og_image'      => (string) wp_get_attachment_url( $fb_att ),
				'twitter_image' => (string) wp_get_attachment_url( $tw_att ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res, 'Attachment-backed social image URLs must write.' );

		// The attachment-id meta the frontend actually resolves is present and distinct per network.
		$this->assertSame( $fb_att, (int) get_post_meta( $post_id, 'rank_math_facebook_image_id', true ), 'The Facebook OG image id the frontend renders must be written.' );
		$this->assertSame( $tw_att, (int) get_post_meta( $post_id, 'rank_math_twitter_image_id', true ), 'The Twitter image id the frontend renders must be written.' );

		// The Twitter->Facebook fallback is disabled with the exact 'off' string normalize_data() reads
		// as false; an empty string, '0', or false would fall back to the truthy default.
		$this->assertSame( 'off', get_post_meta( $post_id, 'rank_math_twitter_use_facebook', true ), 'Providing a Twitter image must disable the Facebook fallback.' );
	}

	public function test_rankmath_update_post_rejects_a_social_image_url_with_no_attachment(): void {
		// A social image URL that maps to no media-library attachment cannot render (the frontend reads
		// the *_image_id, not the URL), so the write is refused rather than persisting a confident-empty
		// URL that renders nothing.
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();

		$res = wp_get_ability( 'aafm/rankmath-update-post' )->execute(
			array(
				'post_id'  => $post_id,
				'og_image' => 'https://example.com/not-in-the-library.jpg',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res, 'A non-attachment social image URL must be refused.' );

		// Nothing was written: no partial URL meta and no id meta left behind.
		$this->assertSame( '', get_post_meta( $post_id, 'rank_math_facebook_image', true ), 'The refused write must not persist the URL meta.' );
		$this->assertSame( '', get_post_meta( $post_id, 'rank_math_facebook_image_id', true ), 'The refused write must not persist an id meta.' );
	}

	public function test_rankmath_update_post_leaves_twitter_fallback_untouched_without_twitter_fields(): void {
		// Disabling the fallback is opt-in: a write that touches only Facebook/OG fields must not force
		// rank_math_twitter_use_facebook off, so a post relying on the Facebook image for Twitter keeps
		// doing so.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		$fb_att   = (int) self::factory()->attachment->create_object( 'rm-og.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );

		wp_get_ability( 'aafm/rankmath-update-post' )->execute(
			array(
				'post_id'  => $post_id,
				'og_image' => (string) wp_get_attachment_url( $fb_att ),
			)
		);
		$this->assertSame( '', get_post_meta( $post_id, 'rank_math_twitter_use_facebook', true ), 'A Facebook-only write must not touch the Twitter fallback flag.' );
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

	/**
	 * Fix round 1, delegation audit sweep (210-sweep-B5-report.md): a robots write must invalidate
	 * Rank Math's own sitemap cache, since Cache_Watcher only listens on save_post/
	 * transition_post_status, never on updated_post_meta, and sitemap inclusion reads exactly the
	 * rank_math_robots meta key this ability writes.
	 */
	public function test_rankmath_update_post_robots_write_invalidates_the_sitemap_cache(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/rankmath-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'robots'  => 'noindex',
			)
		);

		$this->assertSame(
			array( $post_id ),
			\RankMath\Sitemap\Cache_Watcher::$invalidated_post_ids,
			'A robots write must invalidate the sitemap cache for that post, exactly once.'
		);
	}

	/**
	 * Companion negative case, pinning the scoping decision: title/description/focus_keyword writes
	 * must NOT invalidate the sitemap cache. Rank Math's own bulk-edit REST controller writes those
	 * same fields via raw update_post_meta with no invalidation either (210-sweep-B5-report.md), so
	 * matching that vendor behaviour there is deliberate - only the robots field drives sitemap
	 * inclusion and needs the extra call.
	 */
	public function test_rankmath_update_post_non_robots_fields_do_not_invalidate_the_sitemap_cache(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/rankmath-update-post' )->execute(
			array(
				'post_id'       => $post_id,
				'title'         => 'A title with no robots change',
				'description'   => 'A description with no robots change.',
				'focus_keyword' => 'a keyword',
			)
		);

		$this->assertSame(
			array(),
			\RankMath\Sitemap\Cache_Watcher::$invalidated_post_ids,
			'Title/description/focus_keyword writes must not invalidate the sitemap cache, matching Rank Math\'s own REST bulk-edit endpoint.'
		);
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

	/**
	 * The sanitizer's depth bound is the only thing between a hostile JSON-LD graph and an exhausted
	 * stack, and since the bridge's schema walker was deleted aafm_sanitize_schema_array() is the
	 * last consumer of AAFM_SCHEMA_MAX_DEPTH. Nothing pinned it: the sanitizer's other tests work at
	 * depth three, which exercises the walk but never the bound. Deleting the constant would be
	 * silent too, because includes/integrations.php defines a fallback of 32 when it is missing.
	 */
	public function test_sanitize_schema_array_drops_sub_trees_past_the_depth_bound(): void {
		$leaf = 'deepest-leaf-marker';
		$node = array( 'name' => $leaf );
		for ( $i = 0; $i < AAFM_SCHEMA_MAX_DEPTH + 5; $i++ ) {
			$node = array( 'child' => $node );
		}

		$clean = aafm_sanitize_schema_array( $node );

		$this->assertStringNotContainsString(
			$leaf,
			(string) wp_json_encode( $clean ),
			'A sub-tree past the depth bound must be dropped rather than recursed into.'
		);
		$this->assertStringContainsString(
			'child',
			(string) wp_json_encode( $clean ),
			'Guard on the guard: the levels ABOVE the bound must survive, or this would pass on an empty result.'
		);
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

	/**
	 * 1.7.2 bug #5: aafm_exec_rankmath_update_schema() writes with update_post_meta() and ignores its
	 * return, then answers with the sanitized INPUT echoed straight back - never a read-back through
	 * get_post_meta() the way every sibling writer (update-post, ACF, the AIOSEO/Yoast writers) does.
	 * A filter that vetoes the postmeta write (update_post_metadata short-circuited to non-null, the
	 * documented way a caching/compliance plugin blocks a meta write) leaves storage untouched, but
	 * this ability still reports the schema it was ASKED to write as though it landed.
	 *
	 * RED against the current code: it returns $clean (the input) unconditionally, so the response
	 * shows the vetoed schema as though it were persisted, and the request is not a WP_Error.
	 */
	public function test_rankmath_update_schema_read_back_catches_a_filter_vetoed_write(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();

		// Short-circuit update_post_metadata so nothing is actually written - the documented way a
		// caching/compliance plugin can silently veto a meta write while update_post_meta() itself
		// still returns truthy.
		$veto = static function () {
			return true;
		};
		add_filter( 'update_post_metadata', $veto, 10, 0 );

		$res = wp_get_ability( 'aafm/rankmath-update-schema' )->execute(
			array(
				'post_id' => $post_id,
				'type'    => 'Article',
				'schema'  => array(
					'@type' => 'Article',
					'name'  => 'Vetoed',
				),
			)
		);

		remove_filter( 'update_post_metadata', $veto, 10 );

		// Precondition: the veto really did block the write.
		$this->assertSame(
			'',
			get_post_meta( $post_id, 'rank_math_schema_Article', true ),
			'precondition: the veto filter must have kept the schema meta unwritten.'
		);

		$this->assertInstanceOf(
			WP_Error::class,
			$res,
			'A schema write that never persisted must be reported as failed, not answered with the sanitized input echoed back as though it were stored.'
		);
	}

	/**
	 * 1.7.2 bug #6: AAFM_SCHEMA_MAX_DEPTH has two declaration sites that disagree.
	 * includes/bridge.php:60 declares `const AAFM_SCHEMA_MAX_DEPTH = 30;` (the value that actually
	 * wins, since it loads first); includes/integrations.php:220 carries a dead
	 * `define( 'AAFM_SCHEMA_MAX_DEPTH', 32 )` behind a defined() guard that never runs today, but
	 * would fatal with "Cannot redefine constant" if load order ever flipped, and disagrees with the
	 * constant's own doc comment either way.
	 *
	 * RED against the current source: the dead define() with the disagreeing literal (32) is still
	 * present in integrations.php.
	 */
	public function test_schema_max_depth_has_no_duplicate_declaration_with_a_disagreeing_value(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin source off disk, never a remote URL.
		$integrations_source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/integrations.php' );

		$this->assertStringNotContainsString(
			"AAFM_SCHEMA_MAX_DEPTH', 32",
			$integrations_source,
			"integrations.php must not carry a duplicate declaration of AAFM_SCHEMA_MAX_DEPTH whose literal (32) disagrees with bridge.php's authoritative const (30) - a latent redeclare-constant fatal if load order ever flips, and a doc/behavior mismatch either way."
		);
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

	/**
	 * B16: get-head must honour the operator's post-type exposure allowlist, not a bare edit_post.
	 *
	 * A public CPT the operator has NOT exposed is editable by an admin through core (edit_post is
	 * true), but every per-object SEO ability refuses it via aafm_can_edit_post_object(), which
	 * enforces the exposure allowlist. get-head used a bare edit_post and would leak the rendered SEO
	 * head of a non-allowlisted type; it must now return a WP_Error like its -get-meta sibling.
	 */
	public function test_rankmath_get_head_refuses_a_non_exposed_post_type(): void {
		register_post_type(
			'aafm_secret_cpt',
			array(
				'public'          => true,
				'show_in_rest'    => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);

		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create(
			array(
				'post_type'   => 'aafm_secret_cpt',
				'post_author' => $admin_id,
			)
		);

		// Sanity: the admin CAN edit this post through core (the trap the bare edit_post fell into).
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );

		$res = wp_get_ability( 'aafm/rankmath-get-head' )->execute( array( 'post_id' => $post_id ) );

		unregister_post_type( 'aafm_secret_cpt' );

		$this->assertInstanceOf(
			WP_Error::class,
			$res,
			'get-head must refuse a post type the operator has not exposed.'
		);
	}

	/**
	 * When the renderer produces nothing - the real state on a Rank Math install whose setup wizard was
	 * never completed or skipped, so rank_math()->frontend/head never initialise - the ability must
	 * report that honestly rather than returning an empty head with success. Dropping every
	 * aafm_seo_rendered_head callback models the renderer contributing no markup.
	 */
	public function test_rankmath_get_head_errors_when_renderer_produces_nothing(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		// No callback contributes markup: the seam returns the empty passthrough, the exact
		// wizard-incomplete state where the head renderer is unavailable.
		remove_all_filters( 'aafm_seo_rendered_head' );

		$res = wp_get_ability( 'aafm/rankmath-get-head' )->execute( array( 'post_id' => $post_id ) );
		$this->assertInstanceOf( WP_Error::class, $res, 'An unavailable head renderer must not report an empty-but-successful head.' );
		$this->assertSame( 'aafm_rankmath_head_unavailable', $res->get_error_code() );
	}

	/**
	 * B59: the description and disclosure must not promise the empty-string fallback the Yoast and
	 * AIOSEO siblings really have; an unrenderable Rank Math head is refused with a 409 instead.
	 */
	public function test_rankmath_get_head_copy_matches_the_refusal_contract(): void {
		$definitions = aafm_register_rankmath_full_definitions( array() );
		$description = (string) $definitions['aafm/rankmath-get-head']['description'];
		$this->assertStringNotContainsString( 'empty when', $description, 'the false empty-string promise must be gone.' );
		$this->assertStringContainsString( 'refused', $description, 'the description must state the refusal.' );

		$disclosure = (string) aafm_ability_disclosures()['aafm/rankmath-get-head'];
		$this->assertStringNotContainsString( 'empty string', $disclosure, 'the disclosure must not promise an empty string.' );
		$this->assertStringContainsString( 'refused', $disclosure, 'the disclosure must state the refusal.' );
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
