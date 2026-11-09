<?php
/**
 * Change history against the REAL, pinned SEO plugins: Yoast, Rank Math and AIOSEO. No stubs for the
 * restore: a revert is only called exact when the vendor's own storage shows the original bytes.
 *
 * Run: vendor/bin/phpunit -c phpunit-contract.xml.dist --filter UndoContractTest (after tests/bin/install-vendors.sh).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Contract;

require_once dirname( __DIR__ ) . '/undo/UndoTestCase.php';

use AAFM\Tests\Undo\UndoTestCase;

/**
 * @group contract
 */
final class UndoContractTest extends UndoTestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_flush_registry_cache();
		$this->acting_as( 'administrator' );
	}

	/**
	 * Run a tool, return the change id its response names.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return int
	 */
	private function change_from( string $ability, array $input ): int {
		$result = $this->run_tool( $ability, $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		$this->assertTrue( $result['undo']['undoable'], 'precondition: ' . wp_json_encode( $result['undo'] ) );
		return (int) $result['undo']['change_ids'][0];
	}

	/**
	 * Revert as the owner and return the result.
	 *
	 * @param int $change_id Change id.
	 * @return array<string,mixed>
	 */
	private function revert( int $change_id ): array {
		$result = aafm_undo_apply( $change_id, 'owner' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		return $result;
	}

	/**
	 * Raw stored rows of a post meta key.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return list<string>
	 */
	private function raw( int $post_id, string $key ): array {
		return aafm_meta_raw_snapshot( 'post', $post_id, array( $key ) )['by_key'][ $key ]['raw'];
	}

	/**
	 * The raw AIOSEO row of a post, straight from its own table.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>|null
	 */
	private function aioseo_row( int $post_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $wpdb->prefix . 'aioseo_posts', $post_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Skip unless the named real vendor is loaded.
	 *
	 * @param string $symbol Class or function name.
	 * @return void
	 */
	private function need( string $symbol ): void {
		if ( ! class_exists( $symbol ) && ! function_exists( $symbol ) ) {
			$this->markTestSkipped( $symbol . ' is not provisioned: run tests/bin/install-vendors.sh.' );
		}
	}

	public function test_yoast_fields_round_trip_through_real_yoast(): void {
		$this->need( 'WPSEO_Meta' );
		$post = self::factory()->post->create();
		update_post_meta( $post, '_yoast_wpseo_title', 'Old SEO title' );
		$title  = $this->raw( $post, '_yoast_wpseo_title' );
		$robots = $this->raw( $post, '_yoast_wpseo_meta-robots-noindex' );
		$change = $this->change_from(
			'aafm/yoast-update-post',
			array(
				'post_id'        => $post,
				'title'          => 'New SEO title',
				'robots_noindex' => '1',
			)
		);
		$this->assertSame( 'reverted', $this->revert( $change )['outcome'] );
		$this->assertSame( $title, $this->raw( $post, '_yoast_wpseo_title' ) );
		$this->assertSame( $robots, $this->raw( $post, '_yoast_wpseo_meta-robots-noindex' ) );
	}

	public function test_rank_math_robots_round_trip_and_the_sitemap_entry_updates(): void {
		$this->need( 'RankMath' );
		$this->need( 'RankMath\\Sitemap\\Cache_Watcher' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post, 'rank_math_robots', array( 'index' ) );
		$before = $this->raw( $post, 'rank_math_robots' );
		$change = $this->change_from(
			'aafm/rankmath-update-post',
			array(
				'post_id' => $post,
				'robots'  => 'noindex,nofollow',
			)
		);
		// Rank Math queues the post type for its sitemap cache to clear; read that queue by effect.
		$queue = new \ReflectionProperty( 'RankMath\\Sitemap\\Cache_Watcher', 'clear_types' );
		$queue->setAccessible( true );
		$queue->setValue( null, array() );
		$result = $this->revert( $change );
		$this->assertSame( 'reverted', $result['outcome'] );
		$this->assertSame( $before, $this->raw( $post, 'rank_math_robots' ) );
		$this->assertContains( 'post', (array) $queue->getValue(), 'the revert queued the post sitemap for clearing' );
	}

	public function test_rank_math_schema_round_trips(): void {
		$this->need( 'RankMath' );
		$post   = self::factory()->post->create();
		$before = $this->raw( $post, 'rank_math_schema_Article' );
		$change = $this->change_from(
			'aafm/rankmath-update-schema',
			array(
				'post_id' => $post,
				'type'    => 'Article',
				'schema'  => array( 'headline' => 'H' ),
			)
		);
		$this->assertNotSame( $before, $this->raw( $post, 'rank_math_schema_Article' ) );
		$this->assertSame( 'reverted', $this->revert( $change )['outcome'] );
		$this->assertSame( $before, $this->raw( $post, 'rank_math_schema_Article' ) );
	}

	/**
	 * Give a post an AIOSEO row, the way AIOSEO's own editor does on first save.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function give_aioseo_row( int $post_id ): void {
		$class = 'AIOSEO\\Plugin\\Common\\Models\\Post';
		$class::savePost(
			$post_id,
			array(
				'title'       => 'Original title',
				'description' => 'Original description',
			)
		);
		$this->assertNotNull( $this->aioseo_row( $post_id ), 'precondition: AIOSEO stored a row' );
	}

	public function test_aioseo_fields_round_trip_through_the_real_model(): void {
		$this->need( 'AIOSEO\\Plugin\\Common\\Models\\Post' );
		$post = self::factory()->post->create();
		$this->give_aioseo_row( $post );
		$before = $this->aioseo_row( $post );
		$change = $this->change_from(
			'aafm/aioseo-update-post',
			array(
				'post_id'        => $post,
				'title'          => 'Changed title',
				'description'    => 'Changed description',
				'og_image'       => 'https://example.com/og.png',
				'robots_noindex' => true,
			)
		);
		$this->assertNotSame( $before['title'], $this->aioseo_row( $post )['title'] );
		$result = $this->revert( $change );
		$this->assertSame( 'reverted', $result['outcome'], wp_json_encode( $result ) );
		$after = $this->aioseo_row( $post );
		foreach ( array( 'title', 'description', 'og_image_custom_url', 'og_image_type', 'robots_default', 'robots_noindex', 'robots_nofollow' ) as $column ) {
			$this->assertSame( $before[ $column ], $after[ $column ], $column );
		}
	}

	public function test_aioseo_default_format_title_round_trips(): void {
		$this->need( 'AIOSEO\\Plugin\\Common\\Models\\Post' );
		$post = self::factory()->post->create();
		$this->give_aioseo_row( $post );
		$format = trim( aioseo()->meta->title->getPostTypeTitle( 'post' ) );
		// The forward tool reads the stored null back as a miss and answers with an error, but the write landed,
		// so the change is recorded and can be reverted.
		$this->run_tool(
			'aafm/aioseo-update-post',
			array(
				'post_id' => $post,
				'title'   => $format,
			)
		);
		$rows = $this->change_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ready', $rows[0]['status'] );
		// AIOSEO stores a title equal to its default format as null.
		$this->assertNull( $this->aioseo_row( $post )['title'] );
		$result = $this->revert( (int) $rows[0]['id'] );
		$this->assertContains( $result['outcome'], array( 'reverted', 'reverted_with_differences' ) );
		$this->assertSame( 'Original title', $this->aioseo_row( $post )['title'] );
	}

	public function test_aioseo_post_without_a_row_is_not_undoable(): void {
		$this->need( 'AIOSEO\\Plugin\\Common\\Models\\Post' );
		$post = self::factory()->post->create();
		$this->assertNull( $this->aioseo_row( $post ), 'precondition: no row yet' );
		$result = $this->run_tool(
			'aafm/aioseo-update-post',
			array(
				'post_id' => $post,
				'title'   => 'First title',
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFalse( $result['undo']['undoable'] );
		$this->assertSame( 'no_vendor_row', $result['undo']['reason'] );
	}
}
