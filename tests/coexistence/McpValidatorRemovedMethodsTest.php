<?php
/**
 * Coexistence: what actually happens when code calls one of McpValidator's 0.5.0 public MIME/icon
 * validation methods after our eager load has committed the site to our bundled 0.6.1 copy.
 *
 * Includes/adapter-loader.php's eager load declares every WP\MCP\ class from our bundle during
 * the plugin-include phase, forcing every request site-wide onto whichever McpValidator version
 * we ship - a sibling plugin bundling an older adapter and calling a method that version still had
 * gets OUR copy instead, not its own. The plan's original research notes claimed 0.6.1 removed
 * five methods - McpValidator::validate_image_mime_type(), validate_audio_mime_type(),
 * validate_icon_mime_type(), get_icon_validation_errors(), and validate_icons_array() - sourced
 * from the adapter's own PR changelog prose, not independently verified against the vendored
 * source at plan-writing time (the network fetch that would have confirmed it directly failed).
 * Checked directly against the just-bumped bundle: of those five, three are actually gone
 * (validate_image_mime_type(), validate_audio_mime_type(), validate_icon_mime_type() - the raw
 * MIME-string checkers), and two are not (get_icon_validation_errors() and validate_icons_array()
 * still exist, now validating icon structure through validate_icon_src()/validate_icon_size()/
 * validate_icon_theme() instead of a MIME string). A full method-by-method diff of the 0.5.0 and
 * 0.6.1 vendored classes (caught by an independent review, not the original research) found a
 * FOURTH real removal outside the original five-name list: the general-purpose
 * validate_mime_type() (0.5.0 line 528), which nothing in this plugin's own code called but which
 * carries the identical sibling-plugin risk as the other three. This test checks the real bundle
 * directly instead of trusting either the changelog prose or the original plan's shortlist, and
 * separately proves what a caller actually experiences if it calls any of the four genuinely-
 * removed methods anyway: a catchable PHP \Error (PHP 7+ turns "call to undefined method" into a
 * catchable Error, not an uncatchable fatal), which is a real - but non-fatal-to-the-whole-
 * request-if-caught - risk, not the site-wide white-screen the Rank Math 0.4.1
 * McpAdapter-class-collision case would be.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class McpValidatorRemovedMethodsTest extends TestCase {

	/**
	 * The methods genuinely absent from McpValidator at 0.6.1, confirmed directly against the
	 * vendored source (the plan's original research had claimed five; two of those five - see
	 * retained_icon_methods() below - turned out to still exist). Kept as one list so this and
	 * the sibling-call test below stay in sync, and this is the single place to update if a
	 * later adapter bump changes the removed set again.
	 *
	 * @return array<int,string>
	 */
	private static function removed_methods(): array {
		return array(
			'validate_image_mime_type',
			'validate_audio_mime_type',
			'validate_icon_mime_type',
			'validate_mime_type',
		);
	}

	/**
	 * The two methods the plan's original research wrongly claimed were removed. They are still
	 * present at 0.6.1 - kept as a positive regression guard so a future bump that DOES remove
	 * them is caught here rather than only in the (already-corrected) prose.
	 *
	 * @return array<int,string>
	 */
	private static function retained_icon_methods(): array {
		return array(
			'get_icon_validation_errors',
			'validate_icons_array',
		);
	}

	/**
	 * Confirms the real removed set directly against the vendored 0.6.1 source rather than
	 * trusting the adapter's own changelog prose, in both directions: the three MIME-string
	 * checkers are gone, and the two icon-structure validators the changelog's prose implied
	 * were also gone are, in fact, still there. A result in either direction that contradicts
	 * this is a real finding to report, not a reason to loosen either assertion.
	 */
	public function test_removed_and_retained_methods_match_the_bundled_mcpvalidator(): void {
		$this->assertTrue(
			class_exists( \WP\MCP\Domain\Utils\McpValidator::class ),
			'McpValidator must still exist as a class at 0.6.1 even if some methods were removed from it.'
		);

		foreach ( self::removed_methods() as $method ) {
			$this->assertFalse(
				method_exists( \WP\MCP\Domain\Utils\McpValidator::class, $method ),
				sprintf( 'Expected McpValidator::%s() to be absent at 0.6.1 - if this fails, a later bundled version restored it.', $method )
			);
		}

		foreach ( self::retained_icon_methods() as $method ) {
			$this->assertTrue(
				method_exists( \WP\MCP\Domain\Utils\McpValidator::class, $method ),
				sprintf( 'Expected McpValidator::%s() to still exist at 0.6.1 - if this fails, a later bundled version removed it and the sibling-call risk this test class documents now applies to it too.', $method )
			);
		}
	}

	/**
	 * Simulates the sibling-plugin case directly: our eager load has already run (every test in
	 * this suite boots through the plugin bootstrap, so this is always true here), then something
	 * else calls a method that existed at 0.5.0. Proves the failure mode is a catchable \Error
	 * with a class-not-found-style message, not a white-screen the site cannot recover from if the
	 * caller (or a global error handler) wraps the call.
	 *
	 * Exercises EVERY entry in removed_methods(), not just the first - an earlier draft only
	 * tested one, which is exactly how a genuine fourth removed method (validate_mime_type(), see
	 * the class docblock) stayed unverified even after this test existed. Looping over the whole
	 * list closes that gap for any future removal too.
	 */
	public function test_calling_a_removed_method_after_eager_load_throws_a_catchable_error(): void {
		$this->assertTrue(
			class_exists( \WP\MCP\Domain\Utils\McpValidator::class, false ),
			'McpValidator must already be declared by this point in the suite (eager-loaded at plugin bootstrap).'
		);

		$reflect = new \ReflectionClass( \WP\MCP\Domain\Utils\McpValidator::class );
		$checked = 0;

		foreach ( self::removed_methods() as $method ) {
			if ( $reflect->hasMethod( $method ) ) {
				continue; // Covered by the "still exists" failure path in the test above instead.
			}

			$caught = null;
			try {
				// @phpstan-ignore-next-line -- deliberately calling a method proven absent above.
				\WP\MCP\Domain\Utils\McpValidator::$method( 'image/png' );
			} catch ( \Throwable $e ) {
				$caught = $e;
			}

			$this->assertNotNull( $caught, sprintf( 'Calling removed static method %s() must throw something catchable, not fatal uncatchably.', $method ) );
			$this->assertInstanceOf( \Error::class, $caught );
			$this->assertStringContainsStringIgnoringCase( 'undefined method', $caught->getMessage() );
			++$checked;
		}

		$this->assertGreaterThan( 0, $checked, 'No removed methods were available to check - the fixture or removed_methods() list is broken.' );
	}
}
