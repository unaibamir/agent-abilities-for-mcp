<?php
/**
 * Covers the regression harness's resolve_tool() slug->tool-name mapping.
 *
 * The harness lives under /bin (a dev tool, export-ignored from the shipped zip). Its
 * resolve_tool() must map a short slug like `get-post` to the plugin's CANONICAL tool
 * `aafm-get-post`, even when an integration tool whose name ends with the same short slug
 * (e.g. `aafm-aioseo-get-post`) sorts BEFORE the canonical one in the alphabetical tool list.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RegressionResolveToolTest extends TestCase {

	/**
	 * Load the harness class without running it, build an instance with no constructor side
	 * effects, and invoke the private resolve_tool() against a controlled tool list.
	 *
	 * @param list<string> $tool_names Tool names as tools/list would return them (sorted).
	 */
	private function resolve( array $tool_names, string $short ): string {
		require_once dirname( __DIR__, 2 ) . '/bin/mcp-regression.php';

		$class    = new ReflectionClass( \AAFM_Mcp_Regression::class );
		$instance = $class->newInstanceWithoutConstructor();

		$names_prop = $class->getProperty( 'tool_names' );
		$names_prop->setAccessible( true );
		// tools/list output is sorted; mirror that so the shadowing order is realistic.
		sort( $tool_names );
		$names_prop->setValue( $instance, $tool_names );

		$method = $class->getMethod( 'resolve_tool' );
		$method->setAccessible( true );

		return (string) $method->invoke( $instance, $short );
	}

	public function test_prefers_canonical_over_shadowing_integration_tool(): void {
		// `aafm-aioseo-get-post` sorts before `aafm-get-post` (a < g): the old suffix-only
		// resolver returned the integration tool. The canonical must win.
		$names = [ 'aafm-aioseo-get-post', 'aafm-get-post', 'aafm-update-post' ];
		$this->assertSame( 'aafm-get-post', $this->resolve( $names, 'get-post' ) );
	}

	public function test_prefers_canonical_over_shadowing_rankmath_update_tool(): void {
		$names = [ 'aafm-rankmath-update-post', 'aafm-get-post', 'aafm-update-post' ];
		$this->assertSame( 'aafm-update-post', $this->resolve( $names, 'update-post' ) );
	}

	public function test_resolves_integration_only_tool_by_its_full_slug(): void {
		// A tool that exists only under an integration prefix is requested by its full short
		// slug, which makes `aafm-<slug>` the exact canonical name.
		$names = [ 'aafm-aioseo-get-post', 'aafm-get-post' ];
		$this->assertSame( 'aafm-aioseo-get-post', $this->resolve( $names, 'aioseo-get-post' ) );
	}

	public function test_suffix_fallback_for_non_aafm_prefix(): void {
		// A server exposing tools under a different prefix still resolves via the suffix fallback.
		$names = [ 'someserver-get-post' ];
		$this->assertSame( 'someserver-get-post', $this->resolve( $names, 'get-post' ) );
	}

	public function test_unknown_slug_returns_input_unchanged(): void {
		$this->assertSame( 'no-such-tool', $this->resolve( [ 'aafm-get-post' ], 'no-such-tool' ) );
	}
}
