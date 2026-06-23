<?php
/**
 * Coexistence: the eager-load autoloader that wins the WP\MCP\ class-declaration race.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Coexistence;

use AAFM\Tests\TestCase;

final class AdapterEagerLoadTest extends TestCase {

	public function test_register_is_idempotent(): void {
		// Capture the autoloader count before and after a second registration. The plugin bootstrap
		// already registered our loader once; a second call must not add another.
		aafm_register_adapter_autoloader();
		$before = count( (array) spl_autoload_functions() );

		aafm_register_adapter_autoloader();
		$after = count( (array) spl_autoload_functions() );

		$this->assertSame( $before, $after, 'Calling the registrar twice must register at most one loader.' );
	}

	public function test_our_loader_is_registered_and_resolves_the_adapter(): void {
		// The bootstrap registers our loader; assert the autoload chain is non-empty and our mapper
		// resolves WP\MCP\Core\McpAdapter to a path inside our bundle. We do NOT assert spl_autoload_
		// functions()[0]: other prepended Composer/Jetpack loaders sit in front by suite runtime, so a
		// "we are at index 0" claim would be false. The real guarantee — that PHP commits to our 0.5.0
		// copy — is covered by test_eager_load_declares_adapter_from_our_bundle().
		$functions = (array) spl_autoload_functions();
		$this->assertNotEmpty( $functions );

		$resolved = aafm_adapter_class_to_path( \WP\MCP\Core\McpAdapter::class );
		$this->assertNotNull( $resolved );
		$this->assertStringEndsWith( 'vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php', $resolved );
	}

	public function test_class_to_path_resolves_adapter_inside_our_bundle(): void {
		$path = aafm_adapter_class_to_path( \WP\MCP\Core\McpAdapter::class );

		$this->assertNotNull( $path );

		$expected = realpath( AAFM_PLUGIN_DIR . 'vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php' );
		$this->assertSame( $expected, $path, 'McpAdapter must resolve to our bundled copy.' );

		// And the resolved path is strictly inside our adapter directory.
		$base = realpath( AAFM_PLUGIN_DIR . 'vendor/wordpress/mcp-adapter/includes' );
		$this->assertStringStartsWith( $base, $path );
	}

	public function test_class_to_path_resolves_schema_inside_our_bundle(): void {
		// The adapter declares return types in the WP\McpSchema\ package, so the loader must own
		// that namespace too (otherwise PHP's covariance check fatals at adapter declaration time).
		$path = aafm_adapter_class_to_path( 'WP\\McpSchema\\Server\\Tools\\DTO\\Tool' );

		$this->assertNotNull( $path );

		$expected = realpath( AAFM_PLUGIN_DIR . 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/Tool.php' );
		$this->assertSame( $expected, $path, 'Schema DTOs must resolve to our bundled copy.' );
	}

	public function test_eager_load_declares_adapter_from_our_bundle(): void {
		// The eager load (run at plugin-include time) must have committed PHP to our McpAdapter.
		aafm_eager_load_adapter();

		$this->assertTrue(
			class_exists( \WP\MCP\Core\McpAdapter::class, false ),
			'Eager load must declare WP\\MCP\\Core\\McpAdapter without further autoloading.'
		);

		$file     = ( new \ReflectionClass( \WP\MCP\Core\McpAdapter::class ) )->getFileName();
		$expected = realpath( AAFM_PLUGIN_DIR . 'vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php' );
		$this->assertSame( $expected, realpath( (string) $file ), 'McpAdapter must be declared from our bundle.' );
		$this->assertSame( '0.5.0', \WP\MCP\Core\McpAdapter::VERSION );
	}

	public function test_eager_load_is_idempotent(): void {
		// Re-running must be a no-op (require_once + static guard) and never fatal on redeclaration.
		aafm_eager_load_adapter();
		aafm_eager_load_adapter();

		$this->assertTrue( class_exists( \WP\MCP\Core\McpAdapter::class, false ) );
	}

	public function test_non_adapter_class_maps_to_null(): void {
		$this->assertNull( aafm_adapter_class_to_path( 'Some\\Other\\Vendor\\Thing' ) );
		$this->assertNull( aafm_adapter_class_to_path( 'WP\\Other\\NotMcp' ) );
		// WP\MCP\ must NOT swallow the WP\McpSchema\ prefix (and vice versa): both are owned, but a
		// bare WP\Mcp* class outside either real prefix stays unresolved.
		$this->assertNull( aafm_adapter_class_to_path( 'WP\\McpFoo\\Bar' ) );
	}

	public function test_traversal_attempt_maps_to_null(): void {
		// A crafted name that tries to escape the adapter directory must never resolve.
		$this->assertNull( aafm_adapter_class_to_path( 'WP\\MCP\\..\\..\\Evil' ) );
		$this->assertNull( aafm_adapter_class_to_path( 'WP\\MCP\\Core\\..\\..\\..\\Evil' ) );
		$this->assertNull( aafm_adapter_class_to_path( 'WP\\MCP\\foo..bar' ) );
	}

	public function test_unknown_adapter_class_maps_to_null(): void {
		// Inside the WP\MCP\ namespace but with no matching file: must return null so other
		// autoloaders get a chance, never a path to a missing file.
		$this->assertNull( aafm_adapter_class_to_path( 'WP\\MCP\\Core\\DoesNotExistAtAll' ) );
	}
}
