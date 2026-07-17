<?php
/**
 * AIOSEO per-plugin abilities (Wave 5 Slice B): aioseo-get-post, aioseo-update-post,
 * aioseo-get-head.
 *
 * AIOSEO is not installed on the test site, so the fixture forces the aioseo predicate active and
 * defines the minimal host signal (the aioseo() marker function + a stateful
 * AIOSEO\Plugin\Common\Models\Post model backed by AioseoStubStore + the rendered-head filter) via
 * stub_aioseo(). AIOSEO keeps post SEO in a custom table, reached through the Post model - never
 * post meta - so the read/write go through getPost()->set->save(), and the tests prove the write
 * targets the model store, never the _aioseo_* shadow meta, and never raw SQL.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use WP_Error;

final class AioseoTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->force_integration( 'aioseo' );
		$this->stub_aioseo();
		aafm_registry_cache_should_flush( true );
		$this->register_aioseo();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Enable + register the AIOSEO set so the abilities can be invoked.
	 */
	private function register_aioseo(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/aioseo-get-post', 'aafm/aioseo-update-post', 'aafm/aioseo-get-head' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_aioseo_update_then_get_round_trips_through_the_model(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$payload = array(
			'post_id'             => $post_id,
			'title'               => 'AIO Title',
			'description'         => 'AIO description.',
			'canonical'           => 'https://example.com/aio-canonical',
			'og_title'            => 'AIO OG Title',
			'og_description'      => 'AIO OG description.',
			'og_image'            => 'https://example.com/aio-og.jpg',
			'twitter_title'       => 'AIO TW Title',
			'twitter_description' => 'AIO TW description.',
			'twitter_image'       => 'https://example.com/aio-tw.jpg',
			'robots_noindex'      => true,
			'robots_nofollow'     => true,
		);
		$res     = wp_get_ability( 'aafm/aioseo-update-post' )->execute( $payload );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A full AIOSEO write must succeed through the model.' );

		$read = wp_get_ability( 'aafm/aioseo-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'aioseo', $read['plugin'] );
		$this->assertSame( 'AIO Title', $read['title'] );
		$this->assertSame( 'AIO description.', $read['description'] );
		$this->assertSame( 'https://example.com/aio-canonical', $read['canonical'] );
		$this->assertSame( 'AIO OG Title', $read['og_title'] );
		$this->assertSame( 'https://example.com/aio-og.jpg', $read['og_image'] );
		$this->assertTrue( $read['robots_noindex'] );
		$this->assertTrue( $read['robots_nofollow'] );
	}

	/**
	 * Setting a robots flag must also flip the model's robots_default column to false. AIOSEO honors the
	 * per-post robots_noindex/robots_nofollow ONLY when robots_default is falsy (its Robots meta reads
	 * the custom flags behind `! $metaData->robots_default`, and its sitemap treats robots_default = 1 as
	 * "use site default, ignore noindex"). A fresh row defaults robots_default to true, so without this
	 * flip the noindex write is a silent no-op on the real plugin. Asserted against the backing store so
	 * the regression cannot reopen.
	 */
	public function test_aioseo_update_robots_clears_robots_default(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id'        => $post_id,
				'robots_noindex' => true,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$row = \AAFM\Tests\AioseoStubStore::get( $post_id );
		$this->assertTrue( (bool) $row['robots_noindex'], 'The noindex flag must persist.' );
		$this->assertFalse( (bool) $row['robots_default'], 'Writing a robots flag must clear robots_default so AIOSEO honors it.' );
	}

	/**
	 * A write that touches no robots flag must leave robots_default untouched (still the row default), so
	 * a title-only edit does not silently change the post's indexing behavior.
	 */
	public function test_aioseo_update_without_robots_leaves_robots_default(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'Just a title',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$row = \AAFM\Tests\AioseoStubStore::get( $post_id );
		$this->assertTrue( (bool) $row['robots_default'], 'A non-robots write must not flip robots_default.' );
	}

	/**
	 * T2-2: when the model's save() reports failure (nothing persisted in the custom table), the
	 * write returns the generic error rather than a successful stale read.
	 */
	public function test_aioseo_update_save_failure_returns_error(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		\AAFM\Tests\AioseoStubStore::$save_should_fail = true;
		$res = wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'Will not persist',
			)
		);
		\AAFM\Tests\AioseoStubStore::$save_should_fail = false;

		$this->assertInstanceOf( WP_Error::class, $res, 'A custom-table save failure must surface as an error, not a stale read.' );
	}

	public function test_aioseo_write_does_not_touch_the_shadow_meta(): void {
		// The _aioseo_* post meta keys are WPML-compat shadow copies, not AIOSEO's source of truth.
		// The write must go through the model store, NOT update the shadow meta.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'Model Title',
			)
		);
		$this->assertSame( '', get_post_meta( $post_id, '_aioseo_title', true ), 'The write must not target the shadow meta key.' );

		// The model store DID change (the read reflects it).
		$read = wp_get_ability( 'aafm/aioseo-get-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertSame( 'Model Title', $read['title'], 'The model store must hold the written value.' );
	}

	public function test_aioseo_source_uses_no_raw_sql(): void {
		// AIOSEO custom-table writes must go through the model ->save(), never raw $wpdb. A source
		// grep of the ability file must be clean of $wpdb.
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/abilities/aioseo.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local test fixture, not a remote URL.
		$this->assertStringNotContainsString( '$wpdb', $source, 'aioseo.php must never use raw $wpdb.' );
	}

	public function test_aioseo_url_fields_are_url_sanitized(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id'  => $post_id,
				'og_image' => 'javascript:alert(1)',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( '', $res['og_image'], 'A javascript: og_image must be stripped to empty.' );
	}

	/**
	 * Faithful mirror of AIOSEO's rendered-image resolution for a stored row, so the image tests
	 * assert what actually lands in og:image / twitter:image, not just that a URL persisted. From the
	 * real source: Social\Facebook::getImage():42 and Social\Twitter::getImage():117 read the image
	 * SOURCE from the *_image_type column but treat empty/'default' as "use the site default source",
	 * ignoring the custom URL; Social\Image::getImage():98-106 returns the *_image_custom_url ONLY for
	 * the 'custom_image' source (and Image::getImage():113 falls back to the site default when that
	 * custom URL is empty). Returns the URL AIOSEO would render.
	 *
	 * @param array<string,mixed> $row      The stored wp_aioseo_posts row.
	 * @param string              $platform 'facebook' or 'twitter'.
	 * @return string
	 */
	private function aioseo_rendered_image( array $row, string $platform ): string {
		$site_default = 'https://site.example/aioseo-site-default.png';
		$type_col     = 'facebook' === $platform ? 'og_image_type' : 'twitter_image_type';
		$custom_col   = 'facebook' === $platform ? 'og_image_custom_url' : 'twitter_image_custom_url';

		$type   = (string) ( $row[ $type_col ] ?? '' );
		$source = ( '' !== $type && 'default' !== $type ) ? $type : 'site_default_source';
		if ( 'custom_image' !== $source ) {
			return $site_default;
		}
		$custom = (string) ( $row[ $custom_col ] ?? '' );
		return '' !== $custom ? $custom : $site_default;
	}

	/**
	 * H4: writing an og/twitter image must also set the *_image_type column to 'custom_image', or
	 * AIOSEO never renders the URL - it stays on the site default source and the read-back is a false
	 * confirmation. Asserted through the faithful render resolver, so the test fails on the pre-fix
	 * write (type left at 'default' -> resolver returns the site default, not the written URL) and
	 * passes once the type flip ships.
	 */
	public function test_aioseo_image_write_sets_custom_image_type_so_it_renders(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$og_url = 'https://example.com/aio-og.jpg';
		$tw_url = 'https://example.com/aio-tw.jpg';

		$res = wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id'       => $post_id,
				'og_image'      => $og_url,
				'twitter_image' => $tw_url,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );

		$row = \AAFM\Tests\AioseoStubStore::get( $post_id );

		// The render path: AIOSEO must resolve the written URL as the actual og:image / twitter:image.
		$this->assertSame( $og_url, $this->aioseo_rendered_image( $row, 'facebook' ), 'AIOSEO must render the written og image, not the site default.' );
		$this->assertSame( $tw_url, $this->aioseo_rendered_image( $row, 'twitter' ), 'AIOSEO must render the written twitter image, not the site default.' );

		// And the gating flag reads AIOSEO's exact custom-image value.
		$this->assertSame( 'custom_image', $row['og_image_type'], 'og_image_type must be custom_image for the URL to render.' );
		$this->assertSame( 'custom_image', $row['twitter_image_type'], 'twitter_image_type must be custom_image for the URL to render.' );
	}

	/**
	 * Clearing an image URL must reset its *_image_type back to 'default', so a cleared image falls
	 * cleanly back to the site default source instead of pointing at an empty custom URL.
	 */
	public function test_aioseo_clearing_image_resets_type_to_default(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id'  => $post_id,
				'og_image' => 'https://example.com/aio-og.jpg',
			)
		);
		$this->assertSame( 'custom_image', \AAFM\Tests\AioseoStubStore::get( $post_id )['og_image_type'] );

		wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id'  => $post_id,
				'og_image' => '',
			)
		);
		$row = \AAFM\Tests\AioseoStubStore::get( $post_id );
		$this->assertSame( '', $row['og_image_custom_url'], 'Clearing the URL must empty the custom column.' );
		$this->assertSame( 'default', $row['og_image_type'], 'Clearing the image must reset the type to default.' );
	}

	/**
	 * Clearing an image URL must NOT flip a non-custom image source (featured/attach/content/author/
	 * auto) to 'default'. AIOSEO reads the live og:image/twitter:image from whichever source the
	 * *_image_type column names (see Image::getImage()); only 'custom_image' pulls from the custom URL.
	 * So an agent sending og_image='' to clear a custom URL it never set must leave a post that renders
	 * its featured image on 'featured' - resetting to 'default' would silently swap the live tag to the
	 * site default. Only 'custom_image' (which points at the now-empty URL) may fall back to 'default'.
	 */
	public function test_aioseo_clearing_image_leaves_a_non_custom_type_untouched(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		// Seed a post whose social images render from non-custom sources (the AIOSEO editor default
		// for a post with a featured image / attachments), with no custom URL set.
		\AAFM\Tests\AioseoStubStore::save(
			$post_id,
			array(
				'og_image_type'      => 'featured',
				'twitter_image_type' => 'attach',
			)
		);

		wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id'       => $post_id,
				'og_image'      => '',
				'twitter_image' => '',
			)
		);

		$row = \AAFM\Tests\AioseoStubStore::get( $post_id );
		$this->assertSame( 'featured', $row['og_image_type'], 'Clearing an unset custom URL must not flip a featured source to default.' );
		$this->assertSame( 'attach', $row['twitter_image_type'], 'Clearing an unset custom URL must not flip an attach source to default.' );
	}

	/**
	 * A write that touches no image field must leave the *_image_type columns untouched, so a
	 * title-only edit never forces an image source the caller did not ask for.
	 */
	public function test_aioseo_non_image_write_leaves_image_type_untouched(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'No image here',
			)
		);
		$row = \AAFM\Tests\AioseoStubStore::get( $post_id );
		$this->assertSame( 'default', $row['og_image_type'], 'A non-image write must not force og_image_type.' );
		$this->assertSame( 'default', $row['twitter_image_type'], 'A non-image write must not force twitter_image_type.' );
	}

	public function test_aioseo_no_schema_ability_registers(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/aioseo-get-schema', $registry );
		$this->assertArrayNotHasKey( 'aafm/aioseo-update-schema', $registry );
	}

	public function test_aioseo_update_post_denies_an_author_on_anothers_post(): void {
		$author_a = $this->acting_as( 'author' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $author_a ) );
		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/aioseo-update-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_aioseo_get_post_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/aioseo-get-post' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_aioseo_update_post_rejects_a_smuggled_field(): void {
		$this->acting_as( 'administrator' );
		$post_id = (int) self::factory()->post->create();
		$res     = wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id'   => $post_id,
				'post_type' => 'attachment',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	public function test_aioseo_get_head_returns_a_head_string(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );

		$res = wp_get_ability( 'aafm/aioseo-get-head' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aioseo', $res['plugin'] );
		$this->assertStringContainsString( 'AIOSEO head', $res['head'] );
	}

	public function test_aioseo_get_head_denies_a_subscriber(): void {
		$post_id = (int) self::factory()->post->create();
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/aioseo-get-head' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_aioseo_get_post_unknown_id_is_rejected(): void {
		// An unknown post_id fails the per-object aafm_perm_seo_post_object gate (get_post() is not a
		// WP_Post), so the Abilities API short-circuits with ability_invalid_permissions before the
		// executor's defence-in-depth aafm_generic_error() can run. Either way the read is refused.
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/aioseo-get-post' )->execute( array( 'post_id' => PHP_INT_MAX ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'ability_invalid_permissions', $res->get_error_code() );
	}

	public function test_aioseo_empty_patch_leaves_seeded_fields_unchanged(): void {
		// An update carrying only post_id must be a no-op: the array_key_exists skip per field must
		// NOT blank every key in the model store.
		$admin_id = $this->acting_as( 'administrator' );
		$post_id  = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		wp_get_ability( 'aafm/aioseo-update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'Seeded Title',
			)
		);

		$res = wp_get_ability( 'aafm/aioseo-update-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res, 'An empty PATCH must not error.' );
		$this->assertSame( 'Seeded Title', $res['title'], 'An empty PATCH must leave the seeded title untouched.' );
	}

	public function test_aioseo_abilities_absent_when_host_inactive(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_aioseo' );
		add_filter( 'aafm_aioseo_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'aioseo' ) );
		aafm_registry_cache_should_flush( true );
		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/aioseo-get-post', $registry );
	}
}
