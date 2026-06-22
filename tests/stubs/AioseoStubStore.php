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
	 * When true, the model's save() reports failure and stores nothing — modelling an AIOSEO
	 * custom-table save failure so the write-failure path is exercisable.
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
			'twitter_title'            => '',
			'twitter_description'      => '',
			'twitter_image_custom_url' => '',
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
