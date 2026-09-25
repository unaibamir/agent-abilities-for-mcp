<?php
/**
 * WPML language layer: feature detection, resolution, and switch/restore.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use RuntimeException;

/**
 * Simulates WPML via fake hooks, since the unit suite runs against a bare WP install.
 *
 * @covers ::aafm_wpml_active
 * @covers ::aafm_resolve_lang
 * @covers ::aafm_with_language
 */
final class WpmlLanguageTest extends TestCase {

	/**
	 * Languages switched to, in call order, via the fake wpml_switch_language action.
	 *
	 * @var string[]
	 */
	private array $switches = array();

	/**
	 * For each faulted query, the functions on the stack when it fired.
	 *
	 * @var array<int,string[]>
	 */
	private array $stages = array();

	public function tear_down(): void {
		remove_all_filters( 'wpml_active_languages' );
		remove_all_filters( 'wpml_current_language' );
		remove_all_filters( 'wpml_default_language' );
		remove_all_actions( 'wpml_switch_language' );
		$this->switches = array();
		parent::tear_down();
	}

	/**
	 * Register fake WPML hooks and mark it "loaded".
	 *
	 * @param string $current Current language code to report.
	 * @param string $default Default language code to report.
	 *
	 * phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- matches the interface named in the plan.
	 */
	private function fake_wpml( string $current = 'is', string $default = 'is' ): void {
		// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound
		add_filter(
			'wpml_active_languages',
			fn() => array(
				'is' => array( 'code' => 'is' ),
				'en' => array( 'code' => 'en' ),
			)
		);
		add_filter( 'wpml_current_language', fn() => $this->switches ? end( $this->switches ) : $current );
		add_filter( 'wpml_default_language', fn() => $default );
		add_action(
			'wpml_switch_language',
			function ( $code ) {
				$this->switches[] = $code;
			}
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook fired to simulate WPML being loaded.
		do_action( 'wpml_loaded' );
	}

	public function test_inactive_when_wpml_absent(): void {
		$this->assertFalse( aafm_wpml_active() );
		$this->assertNull( aafm_resolve_lang( array( 'lang' => 'en' ) ) );
	}

	public function test_resolve_lang_validates_against_active_set(): void {
		$this->fake_wpml();
		$this->assertSame( 'en', aafm_resolve_lang( array( 'lang' => 'en' ) ) );
		$this->assertSame( 'all', aafm_resolve_lang( array( 'lang' => 'all' ) ) );
		$this->assertNull( aafm_resolve_lang( array() ) ); // None requested.

		// B48 contract change: an unknown code used to coerce to null, which both served the
		// default language silently AND reported `language: null` - documented as "WPML
		// inactive". Two false statements; now it is an actionable refusal naming the codes.
		$invalid = aafm_resolve_lang( array( 'lang' => 'zz' ) );
		$this->assertInstanceOf( \WP_Error::class, $invalid );
		$this->assertSame( 'aafm_invalid_lang', $invalid->get_error_code() );
		$this->assertStringContainsString( 'is, en', $invalid->get_error_message(), 'the refusal must list the valid codes.' );
	}

	/**
	 * B48 at the wire layer: a list read with an invalid lang refuses instead of silently
	 * answering in the default language with language:null.
	 */
	public function test_get_posts_refuses_an_invalid_lang_instead_of_coercing(): void {
		$this->fake_wpml();
		$this->acting_as( 'administrator' );
		self::factory()->post->create( array( 'post_title' => 'Hello' ) );

		$out = aafm_exec_get_posts( array( 'lang' => 'zz' ) );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_invalid_lang', $out->get_error_code() );
	}

	/**
	 * B48: without WPML the lang input stays documented as ignored - no refusal.
	 */
	public function test_get_posts_ignores_lang_when_wpml_is_off(): void {
		$this->acting_as( 'administrator' );
		self::factory()->post->create( array( 'post_title' => 'Hello' ) );

		$out = aafm_exec_get_posts( array( 'lang' => 'zz' ) );

		$this->assertIsArray( $out );
		$this->assertNull( $out['language'], 'language:null keeps meaning "WPML inactive".' );
	}

	public function test_with_language_switches_and_restores(): void {
		$this->fake_wpml( 'is', 'is' );
		$seen = null;
		$out  = aafm_with_language(
			'en',
			function () use ( &$seen ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook, read back to confirm the switch took effect.
				$seen = apply_filters( 'wpml_current_language', null );
				return 'done';
			}
		);
		$this->assertSame( 'done', $out );
		$this->assertSame( 'en', $seen ); // Scoped during the callback.
		$this->assertSame( 'is', end( $this->switches ) ); // Restored to original after.
	}

	/**
	 * Doc 214, finding 4 (investigated, LEFT AS-IS): if a misbehaving third party overrides
	 * `wpml_current_language` to return something empty while WPML otherwise reports itself
	 * loaded, aafm_wpml_current_language() resolves that to null. This pins the deliberate
	 * behavior: aafm_with_language() must skip the switch rather than switch into a state it
	 * cannot safely restore out of. Restoring would call `wpml_switch_language` with null, an
	 * undocumented input to a third party's own action - a strictly worse failure than not
	 * switching, since it could mis-scope every WPML-aware operation for the rest of the request
	 * instead of just this one call. See the comment on aafm_with_language() for the full reasoning.
	 */
	public function test_with_language_skips_the_switch_when_current_language_resolves_to_null(): void {
		add_filter(
			'wpml_active_languages',
			fn() => array(
				'is' => array( 'code' => 'is' ),
				'en' => array( 'code' => 'en' ),
			)
		);
		add_filter( 'wpml_current_language', '__return_empty_string' );
		add_action(
			'wpml_switch_language',
			function ( $code ) {
				$this->switches[] = $code;
			}
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook fired to simulate WPML being loaded.
		do_action( 'wpml_loaded' );
		$this->assertNull( aafm_wpml_current_language(), 'The scenario requires $original to actually resolve to null.' );

		$out = aafm_with_language( 'en', fn() => 'done' );

		$this->assertSame( 'done', $out, 'The callback must still run even though the requested language could not be safely applied.' );
		$this->assertSame( array(), $this->switches, 'No switch (and so no restore-to-null) may be attempted when the original language is unknown.' );
	}

	public function test_with_language_restores_on_exception(): void {
		$this->fake_wpml( 'is', 'is' );
		try {
			aafm_with_language(
				'en',
				function () {
					throw new RuntimeException( 'boom' );
				}
			);
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'is', end( $this->switches ) ); // Still restored.
			return;
		}
		$this->fail( 'exception should have propagated' );
	}

	/**
	 * B47: get-post pinned the WPML element type to 'post' for every id, while WPML's
	 * wpml_object_id filter resolves per the element's REAL type - so a lang request on a
	 * CPT item never matched a translation and silently served the untranslated item.
	 * The element type must be derived from the actual post type.
	 */
	public function test_get_post_lang_resolution_uses_the_actual_post_type(): void {
		$this->fake_wpml();
		register_post_type(
			'aafm_book',
			array(
				'public'       => true,
				'map_meta_cap' => true,
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) ); // Exposed, as an operator would, so the read gate passes.
		$original   = (int) self::factory()->post->create(
			array(
				'post_type'  => 'aafm_book',
				'post_title' => 'Icelandic Book',
			)
		);
		$translated = (int) self::factory()->post->create(
			array(
				'post_type'  => 'aafm_book',
				'post_title' => 'English Book',
			)
		);

		// Faithful to WPML: the translation only resolves when the caller passes the
		// element's real type. A wrong type falls through to the original id.
		add_filter(
			'wpml_object_id',
			static function ( $id, $type ) use ( $original, $translated ) {
				return ( 'aafm_book' === $type && $original === (int) $id ) ? $translated : $id;
			},
			10,
			2
		);

		$this->acting_as( 'administrator' );
		$out = aafm_exec_get_post(
			array(
				'post_id' => $original,
				'lang'    => 'en',
			)
		);

		remove_all_filters( 'wpml_object_id' );

		$this->assertIsArray( $out );
		$this->assertSame( $translated, $out['post']['id'], 'the CPT lang request must resolve through the real element type.' );
	}

	public function test_redact_post_surfaces_language_when_wpml_on(): void {
		$this->fake_wpml();
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature must match the fake WPML filter's arity.
		add_filter( 'wpml_post_language_details', fn( $x, $id ) => array( 'language_code' => 'en' ), 10, 2 );

		$post_id = self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		$shape   = aafm_redact_post( get_post( $post_id ) );

		$this->assertSame( 'en', $shape['lang'] );
		remove_all_filters( 'wpml_post_language_details' );
	}

	public function test_redact_post_omits_language_when_wpml_off(): void {
		$post_id = self::factory()->post->create();
		$shape   = aafm_redact_post( get_post( $post_id ) );
		$this->assertArrayNotHasKey( 'lang', $shape );
	}

	/**
	 * Branch review fix (lang scope and result shaping): an EXPLICIT lang request on
	 * aafm/get-post must shape the result under the REQUESTED language, not ambient. Uses the
	 * same wpml_object_id translation fixture as
	 * test_get_post_lang_resolution_uses_the_actual_post_type above, since a real single-item
	 * lang request always goes through that same translation lookup before aafm_rich_post()
	 * ever runs. Pre-fix, aafm_rich_post() ran fully unscoped after the id had already been
	 * resolved to the translation, so the excerpt reflected whatever was ambient instead of
	 * the language the caller actually asked for.
	 */
	public function test_get_post_with_explicit_lang_shapes_under_the_requested_language(): void {
		$this->fake_wpml( 'is', 'is' );
		add_filter( 'get_the_excerpt', static fn() => aafm_wpml_current_language() );

		$original   = (int) self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => '',
			)
		);
		$translated = (int) self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => '',
			)
		);
		add_filter(
			'wpml_object_id',
			static function ( $id, $type ) use ( $original, $translated ) {
				return ( 'post' === $type && $original === (int) $id ) ? $translated : $id;
			},
			10,
			2
		);

		$this->acting_as( 'administrator' );
		$out = aafm_exec_get_post(
			array(
				'post_id' => $original,
				'lang'    => 'en',
			)
		);

		remove_all_filters( 'wpml_object_id' );
		remove_all_filters( 'get_the_excerpt' );

		$this->assertIsArray( $out );
		$this->assertSame( $translated, $out['post']['id'], 'Fixture check: the request must resolve to the translated post.' );
		$this->assertSame(
			'en',
			$out['post']['excerpt'],
			'The excerpt must be shaped under the REQUESTED language ("en"), not ambient ("is").'
		);
	}

	/**
	 * Core's own SELECT for one object.
	 *
	 * @param string $type 'post', 'term' or 'comment'.
	 * @param int    $id   Object id.
	 * @return string
	 */
	private function load_sql( string $type, int $id ): string {
		global $wpdb;
		switch ( $type ) {
			case 'term':
				return sprintf( 'SELECT t.*, tt.* FROM %1$s AS t INNER JOIN %2$s AS tt ON t.term_id = tt.term_id WHERE t.term_id = %3$d', $wpdb->terms, $wpdb->term_taxonomy, $id );
			case 'comment':
				return sprintf( 'SELECT * FROM %1$s WHERE comment_ID = %2$d LIMIT 1', $wpdb->comments, $id );
		}
		return sprintf( 'SELECT * FROM %1$s WHERE ID = %2$d LIMIT 1', $wpdb->posts, $id );
	}

	/**
	 * A `query` filter that answers the $occurrence-th query matching $needle with the rows of
	 * $leak_sql (none when $leak_sql selects nothing) and records the call stack it fired in.
	 *
	 * @param string|string[] $needle     Exact query, or substrings that must all be present.
	 * @param string          $leak_sql   The query whose rows are left behind.
	 * @param int             $occurrence Which match to answer; 0 for every one.
	 * @return callable
	 */
	private function recording_leak( $needle, string $leak_sql, int $occurrence = 1 ): callable {
		$inner = QueryFaultInjector::leak_row_filter( $needle, $leak_sql, $occurrence, ! is_array( $needle ) );
		return function ( string $query ) use ( $inner ): string {
			$before = QueryFaultInjector::fired_count();
			$out    = $inner( $query );
			if ( QueryFaultInjector::fired_count() > $before ) {
				$this->stages[] = array_map(
					static function ( array $frame ): string {
						return (string) ( $frame['function'] ?? '' );
					},
					( new \Exception() )->getTrace()
				);
			}
			return $out;
		};
	}

	/**
	 * Fault the next load of object $a: its cache entry is dropped and its SELECT reads object
	 * $b's row, or no row when $b is null.
	 *
	 * @param string   $type       Object type.
	 * @param int      $a          The object asked for.
	 * @param int|null $b          The object whose row is left behind, or null for none.
	 * @param int      $occurrence Which matching load to answer.
	 * @return callable
	 */
	private function fault_load( string $type, int $a, ?int $b = null, int $occurrence = 1 ): callable {
		global $wpdb;
		wp_cache_delete(
			$a,
			array(
				'post'    => 'posts',
				'term'    => 'terms',
				'comment' => 'comment',
			)[ $type ]
		);
		$leak = null === $b ? sprintf( 'SELECT * FROM %s WHERE 1 = 0', $wpdb->posts ) : $this->load_sql( $type, $b );
		return $this->recording_leak( $this->load_sql( $type, $a ), $leak, $occurrence );
	}

	/**
	 * Run $run with $filter on `query`, database errors suppressed and output discarded.
	 *
	 * @param callable|null $filter The `query` filter, or null for none.
	 * @param callable      $run    The call.
	 * @return mixed
	 */
	private function armed( ?callable $filter, callable $run ) {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		if ( null !== $filter ) {
			add_filter( 'query', $filter );
		}
		ob_start();
		try {
			return $run();
		} finally {
			ob_end_clean();
			if ( null !== $filter ) {
				remove_filter( 'query', $filter );
			}
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * The fault fired once, inside $name.
	 *
	 * @param string $name  Function name expected on the stack.
	 * @param string $label Case label.
	 */
	private function assert_fired_in( string $name, string $label ): void {
		$this->assertSame( 1, QueryFaultInjector::fired_count(), "$label: the load was faulted" );
		$this->assertContains( $name, $this->stages[0] ?? array(), "$label: the faulted SELECT fired inside $name" );
	}

	/**
	 * A post.
	 *
	 * @param array<string,mixed> $args Post fields.
	 */
	private function post( array $args = array() ): int {
		return (int) self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
	}

	/**
	 * T7 (F2): the resolver loads the original exactly. When that load reads another post's row,
	 * the resolver gives 0, so the permission and the executor both refuse instead of guessing
	 * the element type from the wrong row.
	 */
	public function test_get_post_refuses_when_the_translation_resolvers_load_reads_another_row(): void {
		QueryFaultInjector::reset_fired_count();
		$this->stages = array();
		$this->acting_as( 'subscriber' );
		$original = $this->post();
		$other    = $this->post( array( 'post_type' => 'page' ) );
		$this->fake_wpml();
		add_filter(
			'wpml_object_id',
			static function ( $id, $type ) use ( $original ) {
				return (int) $id === $original && 'post' === $type ? $original + 100000 : $id;
			},
			10,
			4
		);
		get_post( $other );
		$input = array(
			'post_id' => $original,
			'lang'    => 'en',
		);

		$allowed = $this->armed(
			$this->fault_load( 'post', $original, $other ),
			static function () use ( $input ) {
				return aafm_perm_get_post( $input );
			}
		);
		$this->assertFalse( $allowed, 'permission refused' );
		$this->assert_fired_in( 'aafm_get_post_lang_resolved_id', 'resolver load (permission)' );

		QueryFaultInjector::reset_fired_count();
		$this->stages = array();
		$out          = $this->armed(
			$this->fault_load( 'post', $original, $other ),
			static function () use ( $input ) {
				return aafm_exec_get_post( $input );
			}
		);
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assert_fired_in( 'aafm_get_post_lang_resolved_id', 'resolver load (executor)' );
	}

	/**
	 * T7 (executor re-check): the permission and the executor each resolve the translation, so a
	 * resolver that answers differently the second time hands the executor an object the
	 * permission never checked. The executor re-runs the read check on the object it serves.
	 *
	 * @dataProvider data_getters
	 *
	 * @param string $getter 'post' or 'page'.
	 */
	public function test_a_getter_refuses_an_object_its_permission_did_not_check( string $getter ): void {
		$this->acting_as( 'subscriber' );
		$type    = 'page' === $getter ? 'page' : 'post';
		$public  = $this->post( array( 'post_type' => $type ) );
		$private = $this->post(
			array(
				'post_type'   => $type,
				'post_status' => 'private',
			)
		);
		$calls   = 0;
		$this->fake_wpml();
		add_filter(
			'wpml_object_id',
			static function ( $id ) use ( $public, $private, &$calls ) {
				if ( (int) $id !== $public ) {
					return $id;
				}
				++$calls;
				return 1 === $calls ? $public : $private;
			},
			10,
			4
		);
		$key   = 'page' === $getter ? 'page_id' : 'post_id';
		$input = array(
			$key   => $public,
			'lang' => 'en',
		);
		$perm  = 'page' === $getter ? 'aafm_perm_get_page' : 'aafm_perm_get_post';
		$exec  = 'page' === $getter ? 'aafm_exec_get_page' : 'aafm_exec_get_post';

		$this->assertTrue( $perm( $input ), 'the permission checked the public post' );
		$out = $exec( $input );

		$this->assertInstanceOf( \WP_Error::class, $out, 'the private translation was not served' );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
		$this->assertSame( 2, $calls, 'each call resolved once' );
	}

	/**
	 * The two getters that serve a resolved translation.
	 *
	 * @return iterable<string,array{0:string}>
	 */
	public function data_getters(): iterable {
		yield 'get-post' => array( 'post' );
		yield 'get-page' => array( 'page' );
	}

	/**
	 * T7 healthy: a translated read serves the translation, as before.
	 */
	public function test_get_post_serves_the_translation_when_healthy(): void {
		$this->acting_as( 'subscriber' );
		$original    = $this->post();
		$translation = $this->post();
		$this->fake_wpml();
		add_filter(
			'wpml_object_id',
			static function ( $id, $type ) use ( $original, $translation ) {
				return (int) $id === $original && 'post' === $type ? $translation : $id;
			},
			10,
			4
		);
		$input = array(
			'post_id' => $original,
			'lang'    => 'en',
		);

		$this->assertTrue( aafm_perm_get_post( $input ) );
		$out = aafm_exec_get_post( $input );

		$this->assertIsArray( $out );
		$this->assertSame( $translation, $out['post']['id'] );
	}
}
