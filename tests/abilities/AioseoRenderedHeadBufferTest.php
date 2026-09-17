<?php
/**
 * Register 1.6: aafm_aioseo_rendered_head() must never close an output buffer it does not own.
 *
 * Same shape and same bug as aafm_rankmath_rendered_head() (see RankMathRenderedHeadBufferTest):
 * ob_start() sits partway down the try block, after the throwaway WP_Query is built and the_post()
 * runs, and the catch closed whatever buffer happened to be open with no record of the level that
 * existed on entry.
 *
 * Runs in its own process: the normal stub suite's aioseo() marker (IntegrationStubs::stub_aioseo())
 * returns a bare stdClass with no ->head, so it cannot exercise this code path, and a global
 * function can never be redeclared - defining a real-shaped one here would otherwise leak into
 * every later test in the same run.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Query;

final class AioseoRenderedHeadBufferTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rendered_head_leaves_a_pre_existing_buffer_open_when_the_exception_is_thrown_before_its_own_ob_start(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		if ( ! function_exists( 'aioseo' ) ) {
			// A minimal real-shaped stub: ->head exists with a public output() method, so the
			// function passes its early guards and reaches the WP_Query build inside the try.
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- process-isolated test stub, never shipped.
				'class AAFM_Test_Aioseo_Head_Stub {'
				. 'public function output() { echo "<title>stub head that must never run</title>"; }'
				. '}'
				. 'function aioseo() {'
				. 'static $plugin;'
				. 'if ( null === $plugin ) {'
				. '$plugin = new \stdClass();'
				. '$plugin->head = new \AAFM_Test_Aioseo_Head_Stub();'
				. '}'
				. 'return $plugin;'
				. '}'
			);
		}

		// Throws while the throwaway WP_Query is still being built - before aafm_aioseo_rendered_head()
		// ever reaches its own ob_start().
		$thrower = static function ( WP_Query $query ) use ( $post_id ): void {
			if ( $post_id === (int) $query->get( 'p' ) ) {
				throw new \RuntimeException( 'thrown before the renderer opens its own buffer' );
			}
		};
		add_action( 'pre_get_posts', $thrower );

		$level_before = ob_get_level();
		ob_start();
		echo 'caller-owned-buffer';

		try {
			$result = aafm_aioseo_rendered_head( 'fallback-head', $post_id, 'aioseo' );
		} finally {
			remove_action( 'pre_get_posts', $thrower );
		}

		$this->assertSame( 'fallback-head', $result, 'A thrown exception must fall back to the passed-in head.' );
		$this->assertSame(
			$level_before + 1,
			ob_get_level(),
			'The renderer must not close a buffer it never opened.'
		);
		$this->assertSame( 'caller-owned-buffer', ob_get_contents(), 'The caller-owned buffer content must survive untouched.' );

		ob_end_clean();
	}
}
