<?php
/**
 * Coexistence: what a caller experiences when it calls an older McpValidator method after our
 * eager load has committed the request to our bundled 0.7.0 copy.
 *
 * Includes/adapter-loader.php declares every WP\MCP\ class from our bundle during the
 * plugin-include phase, so a sibling plugin that bundles an older adapter and calls a method its
 * own version still had gets OUR McpValidator instead. Releases since 0.4.1 have trimmed that
 * class: 0.6.1 dropped the raw MIME-string checkers, and 0.7.0 dropped the icon, role, priority,
 * base64 and meta helpers as well. The lists below are checked against the real bundle rather than
 * a changelog, and the second test proves the failure a caller sees is a catchable \Error, not an
 * uncatchable fatal, so it stays a contained risk and not a site-wide white screen.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class McpValidatorRemovedMethodsTest extends TestCase {

	/**
	 * The public static methods absent from McpValidator at 0.7.0, confirmed against the vendored
	 * source. One list so this and the sibling-call test below stay in sync; update it here when an
	 * adapter bump changes the set.
	 *
	 * @return array<int,string>
	 */
	private static function removed_methods(): array {
		return array(
			'validate_image_mime_type',
			'validate_audio_mime_type',
			'validate_icon_mime_type',
			'validate_mime_type',
			'validate_base64',
			'validate_icons_array',
			'get_icon_validation_errors',
			'validate_icon_src',
			'validate_icon_size',
			'validate_icon_theme',
			'validate_roles_array',
			'validate_role',
			'validate_priority',
			'normalize_meta',
		);
	}

	/**
	 * The public static methods McpValidator still has at 0.7.0. A positive guard, so a later bump
	 * that removes one of them is caught here.
	 *
	 * @return array<int,string>
	 */
	private static function retained_methods(): array {
		return array(
			'validate_name',
			'get_annotation_validation_errors',
			'validate_iso8601_timestamp',
			'validate_resource_uri',
			'fold_uri_scheme',
		);
	}

	/**
	 * Confirms the removed and retained sets directly against the vendored source, in both
	 * directions.
	 */
	public function test_removed_and_retained_methods_match_the_bundled_mcpvalidator(): void {
		$this->assertTrue(
			class_exists( \WP\MCP\Domain\Utils\McpValidator::class ),
			'McpValidator must still exist as a class at 0.7.0 even if some methods were removed from it.'
		);

		foreach ( self::removed_methods() as $method ) {
			$this->assertFalse(
				method_exists( \WP\MCP\Domain\Utils\McpValidator::class, $method ),
				sprintf( 'Expected McpValidator::%s() to be absent at 0.7.0 - if this fails, a later bundled version restored it.', $method )
			);
		}

		foreach ( self::retained_methods() as $method ) {
			$this->assertTrue(
				method_exists( \WP\MCP\Domain\Utils\McpValidator::class, $method ),
				sprintf( 'Expected McpValidator::%s() to still exist at 0.7.0 - if this fails, a later bundled version removed it and the sibling-call risk this test class documents now applies to it too.', $method )
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
	 * Exercises every entry in removed_methods(), so a future removal is covered by adding it to
	 * the list.
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
