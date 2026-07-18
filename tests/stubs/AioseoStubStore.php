<?php
/**
 * Process-wide backing store for the AIOSEO host stub (Wave 5 SEO slice).
 *
 * AIOSEO v4+ keeps post SEO in a custom table (wp_aioseo_posts), reached through the
 * AIOSEO\Plugin\Common\Models\Post model: getPost($id) returns the model populated from the row,
 * set the public props, ->save() writes the row. The real plugin is not installed on the test site,
 * so this static store stands in for that table: a value written through ->save() is visible to a
 * following getPost($id) inside one test. Lives in its own file so the IntegrationStubs trait file
 * holds a single object structure. Required from the test bootstrap, never shipped.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

/**
 * Process-wide backing store for the AIOSEO Post-model stub.
 */
class AioseoStubStore {

	/**
	 * Rows keyed by post id: id => array of column => value (the model's prop source).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $rows = array();

	/**
	 * When true, the model's save() stores nothing (it still returns void either way, matching real
	 * AIOSEO - L11) - modelling an AIOSEO custom-table save failure so the read-back-mismatch
	 * write-failure path is exercisable.
	 *
	 * @var bool
	 */
	public static bool $save_should_fail = false;

	/**
	 * Clear all state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$rows             = array();
		self::$save_should_fail = false;
	}

	/**
	 * The stored row for a post id, or an empty defaults row (mirroring getPost() creating a blank
	 * instance when no row exists yet).
	 *
	 * @param int $id Post id.
	 * @return array<string,mixed>
	 */
	public static function get( int $id ): array {
		return self::$rows[ $id ] ?? self::defaults( $id );
	}

	/**
	 * Persist a row for a post id (the model ->save() path).
	 *
	 * @param int                 $id  Post id.
	 * @param array<string,mixed> $row Column => value.
	 * @return void
	 */
	public static function save( int $id, array $row ): void {
		$row['post_id']    = $id;
		self::$rows[ $id ] = array_merge( self::defaults( $id ), $row );
	}

	/**
	 * The site-default social image AIOSEO falls back to when a source resolves to nothing (stands in
	 * for the configured global default; any non-custom source resolves here in the stub).
	 *
	 * @var string
	 */
	public const SITE_DEFAULT_IMAGE = 'https://site.example/aioseo-site-default.png';

	/**
	 * Model AIOSEO\Plugin\Common\Social\Facebook::getImage -> Social\Image::getImage for a stored row:
	 * the OG image renders og_image_custom_url ONLY when og_image_type is 'custom_image'; every other
	 * source resolves to the site default here.
	 *
	 * @param int $id Post id.
	 * @return string
	 */
	public static function resolve_facebook_image( int $id ): string {
		$row = self::get( $id );
		if ( 'custom_image' === (string) ( $row['og_image_type'] ?? 'default' ) ) {
			$url = (string) ( $row['og_image_custom_url'] ?? '' );
			return '' !== $url ? $url : self::SITE_DEFAULT_IMAGE;
		}
		return self::SITE_DEFAULT_IMAGE;
	}

	/**
	 * Model AIOSEO\Plugin\Common\Social\Twitter::getImage, including the short-circuit the H4 twitter
	 * fix must defeat: when twitter_use_og is truthy (its real default) getImage returns the
	 * Facebook/OG image and never looks at the twitter-specific image. Only with twitter_use_og false
	 * does a 'custom_image' twitter_image_type render twitter_image_custom_url. Faithful to Twitter.php
	 * lines 111-124: this is the resolution the update ability's twitter_use_og flip has to unlock.
	 *
	 * @param int $id Post id.
	 * @return string
	 */
	public static function resolve_twitter_image( int $id ): string {
		$row = self::get( $id );
		if ( ! empty( $row['twitter_use_og'] ) ) {
			return self::resolve_facebook_image( $id );
		}
		if ( 'custom_image' === (string) ( $row['twitter_image_type'] ?? 'default' ) ) {
			$url = (string) ( $row['twitter_image_custom_url'] ?? '' );
			return '' !== $url ? $url : self::resolve_facebook_image( $id );
		}
		return self::resolve_facebook_image( $id );
	}

	/**
	 * Model AIOSEO\Plugin\Common\Social\Facebook::getTitle for a row (simplified to the OG title the
	 * ability writes), the value the Twitter title falls back to.
	 *
	 * @param int $id Post id.
	 * @return string
	 */
	public static function resolve_facebook_title( int $id ): string {
		return (string) ( self::get( $id )['og_title'] ?? '' );
	}

	/**
	 * Model AIOSEO\Plugin\Common\Social\Twitter::getTitle (Twitter.php lines 145-154): when
	 * twitter_use_og is truthy it returns the Facebook/OG title; when false it returns twitter_title,
	 * falling back to the Facebook/OG title when twitter_title is empty. That empty-field fallback is
	 * why flipping twitter_use_og off never blanks a card whose twitter title was left empty.
	 *
	 * @param int $id Post id.
	 * @return string
	 */
	public static function resolve_twitter_title( int $id ): string {
		$row = self::get( $id );
		if ( ! empty( $row['twitter_use_og'] ) ) {
			return self::resolve_facebook_title( $id );
		}
		$twitter_title = (string) ( $row['twitter_title'] ?? '' );
		return '' !== $twitter_title ? $twitter_title : self::resolve_facebook_title( $id );
	}

	/**
	 * The default column shape every row reads back with, so a fresh post reads a complete shape.
	 *
	 * @param int $id Post id.
	 * @return array<string,mixed>
	 */
	private static function defaults( int $id ): array {
		return array(
			'post_id'                  => $id,
			'title'                    => '',
			'description'              => '',
			'canonical_url'            => '',
			'og_title'                 => '',
			'og_description'           => '',
			'og_image_custom_url'      => '',
			// Mirrors the real wp_aioseo_posts columns: the *_image_type default is 'default', which makes
			// AIOSEO ignore the *_image_custom_url and use the site default image source. The custom URL
			// only renders once the update ability flips these to 'custom_image', so the stub must carry
			// them to catch a regression where that flip is dropped.
			'og_image_type'            => 'default',
			'twitter_title'            => '',
			'twitter_description'      => '',
			'twitter_image_custom_url' => '',
			'twitter_image_type'       => 'default',
			// Mirrors the real wp_aioseo_posts column: Post::setDynamicDefaults copies the site
			// social->twitter->general->useOgData option, whose default is true (Options.php), so a fresh
			// row reads twitter_use_og = true. AIOSEO's Twitter renderer then returns the Facebook/OG
			// value and ignores the twitter-specific title/description/image until this is flipped false.
			// The update ability flips it whenever a Twitter field is written, so the stub must carry it
			// to catch a regression where that flip is dropped.
			'twitter_use_og'           => true,
			'robots_noindex'           => false,
			'robots_nofollow'          => false,
			// Mirrors the real wp_aioseo_posts column: a fresh row defaults to true ("use site default"),
			// and AIOSEO ignores the per-post noindex/nofollow until this is flipped false. The update
			// ability flips it whenever a robots flag is set, so the stub must carry it to catch a
			// regression where that flip is dropped.
			'robots_default'           => true,
		);
	}
}
