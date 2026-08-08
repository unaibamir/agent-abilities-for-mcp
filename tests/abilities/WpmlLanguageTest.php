<?php
/**
 * WPML language layer: feature detection, resolution, and switch/restore.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

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
}
