<?php
/**
 * Counting media without loading every attachment row (H-2, 1.11.3). The query-based counter, used on
 * WPML sites and for a caller limited to their own uploads, counts each mime type through a
 * WP_Query that loads at most one id, so the language and ownership filters still apply.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class MediaCountTest extends TestCase {

	/**
	 * The fake WPML's current language, set through wpml_switch_language.
	 *
	 * @var string
	 */
	private $language = 'en';

	public function set_up(): void {
		parent::set_up();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/count-media' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'wpml_active_languages' );
		remove_all_filters( 'wpml_current_language' );
		remove_all_filters( 'wpml_default_language' );
		remove_all_actions( 'wpml_switch_language' );
		remove_all_filters( 'posts_where' );
		parent::tear_down();
	}

	private function attachment( string $mime, int $author, string $title = 'file' ): int {
		return (int) self::factory()->attachment->create_object(
			$title . '.' . str_replace( '/', '-', $mime ),
			0,
			array(
				'post_mime_type' => $mime,
				'post_type'      => 'attachment',
				'post_author'    => $author,
				'post_title'     => $title,
			)
		);
	}

	/**
	 * Attachment counts by mime type straight from the table, for comparison.
	 *
	 * @param int|null $author Restrict to this author.
	 * @return array<string,int>
	 */
	private function counts_from_rows( ?int $author = null ): array {
		$counts = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'author'         => $author ?? '',
			)
		) as $post ) {
			$counts[ $post->post_mime_type ] = ( $counts[ $post->post_mime_type ] ?? 0 ) + 1;
		}
		ksort( $counts );
		return $counts;
	}

	public function test_media_count_matches_row_count_for_admin(): void {
		$admin = $this->acting_as( 'administrator' );
		$this->attachment( 'image/jpeg', $admin );
		$this->attachment( 'image/jpeg', $admin );
		$this->attachment( 'image/png', $admin );
		$this->attachment( 'application/pdf', $admin );

		$counts = aafm_query_attachment_counts_by_mime( null );
		ksort( $counts );

		$this->assertSame( $this->counts_from_rows(), $counts );
	}

	public function test_media_count_respects_own_uploads_for_limited_caller(): void {
		$author = $this->acting_as( 'author' );
		$other  = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$this->attachment( 'image/jpeg', $author );
		$this->attachment( 'image/png', $other );
		$this->attachment( 'image/png', $other );
		$this->assertSame( $author, aafm_media_scope_author_id(), 'precondition: an author is limited to their own uploads.' );

		$res = wp_get_ability( 'aafm/count-media' )->execute( array() );

		$this->assertSame( 1, $res['total'] );
		$this->assertSame( array( 'image/jpeg' => 1 ), (array) $res['by_mime'] );
	}

	public function test_media_count_respects_wpml_language_filter(): void {
		$admin = $this->acting_as( 'administrator' );
		$this->attachment( 'image/jpeg', $admin, 'de-one' );
		$this->attachment( 'image/jpeg', $admin, 'de-two' );
		$this->attachment( 'image/png', $admin, 'en-one' );
		$this->fake_wpml_filtering_by_title_prefix();

		$de = wp_get_ability( 'aafm/count-media' )->execute( array( 'lang' => 'de' ) );
		$en = wp_get_ability( 'aafm/count-media' )->execute( array( 'lang' => 'en' ) );

		$this->assertSame( array( 'image/jpeg' => 2 ), (array) $de['by_mime'] );
		$this->assertSame( array( 'image/png' => 1 ), (array) $en['by_mime'] );
	}

	public function test_media_count_does_not_load_rows(): void {
		$admin = $this->acting_as( 'administrator' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->attachment( 'image/jpeg', $admin );
		}
		$queries = array();
		$spy     = static function ( \WP_Query $query ) use ( &$queries ): void {
			if ( 'attachment' === $query->get( 'post_type' ) ) {
				$queries[] = array( (int) $query->get( 'posts_per_page' ), (string) $query->get( 'fields' ) );
			}
		};
		add_action( 'pre_get_posts', $spy );
		$counts = aafm_query_attachment_counts_by_mime( null );
		remove_action( 'pre_get_posts', $spy );

		$this->assertSame( 5, $counts['image/jpeg'] );
		$this->assertSame( array( array( 500, 'ids' ), array( 500, 'ids' ) ), $queries, 'Ids-only queries of at most 500 (the second comes back empty), never every row.' );
	}

	public function test_media_count_pages_past_one_batch(): void {
		$admin = $this->acting_as( 'administrator' );
		for ( $i = 0; $i < 501; $i++ ) {
			$this->attachment( 0 === $i % 2 ? 'image/jpeg' : 'image/png', $admin );
		}
		$batches = 0;
		$spy     = static function ( \WP_Query $query ) use ( &$batches ): void {
			if ( 'attachment' === $query->get( 'post_type' ) ) {
				++$batches;
			}
		};
		add_action( 'pre_get_posts', $spy );
		$counts = aafm_query_attachment_counts_by_mime( null );
		remove_action( 'pre_get_posts', $spy );

		ksort( $counts );
		$this->assertSame( $this->counts_from_rows(), $counts );
		$this->assertSame( 3, $batches, 'Two id batches for 501 attachments, then an empty one.' );
	}

	/**
	 * Simulate WPML: two languages, a switch action, and a posts_where filter that keeps only the
	 * attachments whose title starts with the current language code.
	 */
	private function fake_wpml_filtering_by_title_prefix(): void {
		add_filter(
			'wpml_active_languages',
			static fn() => array(
				'de' => array( 'code' => 'de' ),
				'en' => array( 'code' => 'en' ),
			)
		);
		add_filter( 'wpml_current_language', fn() => $this->language );
		add_filter( 'wpml_default_language', static fn() => 'en' );
		add_action(
			'wpml_switch_language',
			function ( $code ) {
				$this->language = (string) $code;
			}
		);
		add_filter(
			'posts_where',
			function ( string $where, \WP_Query $query ): string {
				global $wpdb;
				if ( 'attachment' !== $query->get( 'post_type' ) ) {
					return $where;
				}
				return $where . $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s", $wpdb->esc_like( $this->language . '-' ) . '%' );
			},
			10,
			2
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook fired to simulate WPML being loaded.
		do_action( 'wpml_loaded' );
	}

	public function test_media_count_keeps_going_when_a_filter_caps_the_batch(): void {
		$admin = $this->acting_as( 'administrator' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->attachment( 'image/jpeg', $admin );
		}
		$cap = static function ( \WP_Query $query ): void {
			if ( 'attachment' === $query->get( 'post_type' ) ) {
				$query->set( 'posts_per_page', 2 );
			}
		};
		add_action( 'pre_get_posts', $cap );
		$counts = aafm_query_attachment_counts_by_mime( null );
		remove_action( 'pre_get_posts', $cap );

		$this->assertSame( 5, $counts['image/jpeg'], 'A short batch is not the end; only an empty one is.' );
	}

	public function test_media_count_pages_by_id_not_by_offset(): void {
		$admin = $this->acting_as( 'administrator' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->attachment( 'image/jpeg', $admin );
		}
		$offsets = array();
		$cap     = static function ( \WP_Query $query ) use ( &$offsets ): void {
			if ( 'attachment' === $query->get( 'post_type' ) ) {
				$query->set( 'posts_per_page', 2 );
				$offsets[] = (int) $query->get( 'paged' ) + (int) $query->get( 'offset' );
			}
		};
		add_action( 'pre_get_posts', $cap );
		aafm_query_attachment_counts_by_mime( null );
		remove_action( 'pre_get_posts', $cap );

		$this->assertCount( 4, $offsets, 'Three batches of ids and one empty batch.' );
		$this->assertSame( array( 0, 0, 0, 0 ), $offsets, 'Every batch starts after the last id seen, never at a page offset.' );
	}
}
