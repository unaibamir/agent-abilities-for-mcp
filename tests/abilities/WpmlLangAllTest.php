<?php
/**
 * B-headline: aafm_resolve_lang()/aafm_with_language() pass the literal string 'all' to
 * do_action('wpml_switch_language', 'all'), which WPML ignores, so a caller asking to span
 * every language silently gets only the currently-active one while the plugin reports success.
 *
 * Measured on the sim clone this session (.scratch/mcp-sim/llm/drills/probe-p1-attribute.php):
 * 31 'is' + 9 'en' = 40 published posts; a request for lang:"all" returned 31, missing the
 * target entirely, while reporting language:"all" as if it had worked.
 *
 * The fake WPML built here is deliberately faithful to that measured shape: a switch to an
 * UNRECOGNIZED code (like the literal 'all') is silently ignored by the real vendor, so the
 * "current" language stays at whatever it was before the switch attempt - it does NOT become
 * 'all'. Reproducing that (rather than a simpler "current becomes 'all'" fake) is what makes
 * this fake's pre-fix result match the real probe's pre-fix result.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Term;

final class WpmlLangAllTest extends TestCase {

	/**
	 * The only two codes this fake WPML install recognizes as valid switch targets.
	 */
	private const VALID_CODES = array( 'is', 'en' );

	/**
	 * The fake's notion of "the current WPML language" - a single scalar, not a log, because
	 * that is what wpml_current_language() reports on a real install.
	 *
	 * @var string
	 */
	private string $current_lang = 'is';

	/**
	 * Every code the wpml_switch_language action was actually fired with, valid or not - kept
	 * so a test can assert the FIX iterates the right set of codes without caring whether the
	 * fake's internal state changed.
	 *
	 * @var string[]
	 */
	private array $switch_log = array();

	/**
	 * The ambient language actually in effect at the moment each non-suppressed WP_Query ran,
	 * in call order - proof of iteration that does not depend on whether wpml_switch_language
	 * fired (aafm_with_language() legitimately skips firing it when the target already equals
	 * the ambient language, so the switch log alone cannot tell "iterated but already there"
	 * from "never iterated").
	 *
	 * @var string[]
	 */
	private array $query_lang_log = array();

	public function tear_down(): void {
		remove_all_filters( 'wpml_active_languages' );
		remove_all_filters( 'wpml_current_language' );
		remove_all_filters( 'wpml_default_language' );
		remove_all_actions( 'wpml_switch_language' );
		remove_all_actions( 'pre_get_posts' );
		remove_all_filters( 'get_terms_args' );
		remove_all_filters( 'wpml_post_language_details' );
		$this->current_lang   = 'is';
		$this->switch_log     = array();
		$this->query_lang_log = array();
		parent::tear_down();
	}

	/**
	 * Fake WPML with REAL post-language filtering: a pre_get_posts hook restricts every
	 * non-suppressed WP_Query to posts tagged with the fake's own `_aafm_test_lang` meta key,
	 * matching the ambient language the way WPML's own SQL-join filters restrict a real query.
	 *
	 * @param string $default The ambient language to start the fake at.
	 *
	 * phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- matches the interface named in the plan.
	 */
	private function fake_wpml_with_post_filtering( string $default = 'is' ): void {
		// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound
		$this->current_lang = $default;

		add_filter(
			'wpml_active_languages',
			fn() => array(
				'is' => array( 'code' => 'is' ),
				'en' => array( 'code' => 'en' ),
			)
		);
		add_filter( 'wpml_current_language', fn() => $this->current_lang );
		add_filter( 'wpml_default_language', fn() => $default );
		add_action(
			'wpml_switch_language',
			function ( $code ) {
				$this->switch_log[] = (string) $code;
				if ( in_array( $code, self::VALID_CODES, true ) ) {
					$this->current_lang = (string) $code;
				}
			}
		);
		add_action(
			'pre_get_posts',
			function ( \WP_Query $query ): void {
				if ( true === $query->get( 'suppress_filters' ) ) {
					return;
				}
				$this->query_lang_log[] = $this->current_lang;
				$meta_query             = (array) $query->get( 'meta_query' );
				$meta_query[]           = array(
					'key'   => '_aafm_test_lang',
					'value' => $this->current_lang,
				);
				$query->set( 'meta_query', $meta_query );
			}
		);
		// Mirrors the real vendor: a post/attachment's language comes from its own tagged
		// meta, not the ambient "current" language - needed so aafm_redact_post()'s
		// aafm_wpml_post_language() lookup (and therefore the 'lang' key on every returned
		// post) reflects each item's own language under a lang:"all" merge.
		add_filter(
			'wpml_post_language_details',
			static function ( $details, $post_id ) {
				$lang = get_post_meta( (int) $post_id, '_aafm_test_lang', true );
				return '' !== $lang ? array( 'language_code' => (string) $lang ) : $details;
			},
			10,
			2
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook fired to simulate WPML being loaded.
		do_action( 'wpml_loaded' );
	}

	/**
	 * Seed N published posts tagged with a fake WPML language via post meta.
	 *
	 * @param string $lang  Language code to tag every created post with.
	 * @param int    $count How many to create.
	 */
	private function seed_posts_for_lang( string $lang, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			update_post_meta( $id, '_aafm_test_lang', $lang );
		}
	}

	/**
	 * The headline reproduction: 3 'is' posts + 2 'en' posts = 5 total. A request for
	 * lang:"all" must see all 5 and report language:"all". Pre-fix this returns 3 (only the
	 * ambient 'is' language, since the switch to 'all' is silently ignored) while still
	 * reporting language:"all" - the exact "reports success while missing data" shape doc 206
	 * measured on the sim clone.
	 */
	public function test_get_posts_lang_all_spans_every_active_language(): void {
		$this->fake_wpml_with_post_filtering();
		$this->seed_posts_for_lang( 'is', 3 );
		$this->seed_posts_for_lang( 'en', 2 );
		$this->acting_as( 'administrator' );

		$scoped_is = aafm_exec_get_posts( array( 'per_page' => 50 ) );
		$this->assertIsArray( $scoped_is );
		$this->assertSame( 3, $scoped_is['total'], 'sanity: the ambient language alone sees only its own 3 posts.' );

		$scoped_en = aafm_exec_get_posts(
			array(
				'lang'     => 'en',
				'per_page' => 50,
			)
		);
		$this->assertIsArray( $scoped_en );
		$this->assertSame( 2, $scoped_en['total'], 'sanity: an explicit single-language switch works today.' );

		$all = aafm_exec_get_posts(
			array(
				'lang'     => 'all',
				'per_page' => 50,
			)
		);
		$this->assertIsArray( $all );
		$this->assertSame( 'all', $all['language'], 'the response must still say it was scoped to "all".' );
		$this->assertSame(
			5,
			$all['total'],
			'lang:"all" must see every active language\'s posts, not just the one WPML happened to be on.'
		);

		$seen_langs = array();
		foreach ( $all['posts'] as $post ) {
			if ( isset( $post['lang'] ) ) {
				$seen_langs[ $post['lang'] ] = true;
			}
		}
		$this->assertArrayHasKey( 'is', $seen_langs, 'the "is" posts must be present in the merged result.' );
		$this->assertArrayHasKey( 'en', $seen_langs, 'the "en" posts must be present too - this is the content lang:"all" was missing.' );
	}

	/**
	 * Mechanical proof the fix iterates the SET of active codes, in the order
	 * aafm_wpml_active_language_codes() reports them - not some other set. Asserted via the
	 * ambient language actually in effect when each query ran (query_lang_log), not the raw
	 * wpml_switch_language action log: aafm_with_language() legitimately skips firing that
	 * action when the target already equals the ambient language, which the very first
	 * iteration here does (ambient starts at "is", the first code iterated), so the action
	 * log alone would under-report a real, in-order iteration.
	 */
	public function test_get_posts_lang_all_switches_through_every_active_code(): void {
		$this->fake_wpml_with_post_filtering();
		$this->seed_posts_for_lang( 'is', 1 );
		$this->seed_posts_for_lang( 'en', 1 );
		$this->acting_as( 'administrator' );

		aafm_exec_get_posts( array( 'lang' => 'all' ) );

		$this->assertSame(
			array( 'is', 'en' ),
			$this->query_lang_log,
			'the fix must run one query per active code, in aafm_wpml_active_language_codes() order.'
		);
	}

	/**
	 * The count-posts side of the same defect, through the shared
	 * aafm_wpml_count_posts_by_status() helper. 2 'is' publish posts + 1 'en' publish post: the
	 * lang:"all" publish count must be 3, not stuck at whichever language was ambient.
	 */
	public function test_count_posts_lang_all_sums_every_active_language(): void {
		$this->fake_wpml_with_post_filtering();
		$this->seed_posts_for_lang( 'is', 2 );
		$this->seed_posts_for_lang( 'en', 1 );
		$this->acting_as( 'administrator' );

		$out = aafm_exec_count_posts( array( 'lang' => 'all' ) );

		$this->assertIsArray( $out );
		$this->assertSame( 'all', $out['language'] );
		$this->assertSame(
			3,
			(int) ( (array) $out['by_status'] )['publish'],
			'the publish count for lang:"all" must be the sum across every active language.'
		);
		$this->assertSame( 3, $out['total'] );
	}

	/**
	 * Same defect on aafm/search-content: a published 'en' post matching the search term must be
	 * found under lang:"all" even though the ambient language is 'is'.
	 */
	public function test_search_content_lang_all_spans_every_active_language(): void {
		$this->fake_wpml_with_post_filtering();
		$is_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Findme shared title',
			)
		);
		update_post_meta( $is_id, '_aafm_test_lang', 'is' );
		$en_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Findme shared title',
			)
		);
		update_post_meta( $en_id, '_aafm_test_lang', 'en' );
		$this->acting_as( 'administrator' );

		$out = aafm_exec_search_content(
			array(
				'search' => 'Findme',
				'lang'   => 'all',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 2, $out['total'], 'both languages\' matches must be found under lang:"all".' );
		$found_ids = array_map( static fn( array $r ): int => $r['id'], $out['results'] );
		$this->assertContains( $en_id, $found_ids, 'the "en" post is exactly the content a broken lang:"all" would miss.' );
	}

	/**
	 * Seed N attachments tagged with a fake WPML language via post meta.
	 *
	 * @param string $lang  Language code.
	 * @param int    $count How many to create.
	 */
	private function seed_media_for_lang( string $lang, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$id = self::factory()->attachment->create_object(
				'test-' . $lang . '-' . $i . '.jpg',
				0,
				array(
					'post_mime_type' => 'image/jpeg',
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
				)
			);
			update_post_meta( $id, '_aafm_test_lang', $lang );
		}
	}

	public function test_get_media_lang_all_spans_every_active_language(): void {
		$this->fake_wpml_with_post_filtering();
		$this->seed_media_for_lang( 'is', 2 );
		$this->seed_media_for_lang( 'en', 1 );
		$this->acting_as( 'administrator' );

		$out = aafm_exec_get_media(
			array(
				'lang'     => 'all',
				'per_page' => 50,
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 3, $out['total'], 'lang:"all" must see attachments from every active language.' );
	}

	public function test_count_media_lang_all_sums_every_active_language(): void {
		$this->fake_wpml_with_post_filtering();
		$this->seed_media_for_lang( 'is', 2 );
		$this->seed_media_for_lang( 'en', 1 );
		$this->acting_as( 'administrator' );

		$out = aafm_exec_count_media( array( 'lang' => 'all' ) );

		$this->assertIsArray( $out );
		$this->assertSame( 3, $out['total'] );
		$this->assertSame( 3, (int) ( (array) $out['by_mime'] )['image/jpeg'] );
	}
}
