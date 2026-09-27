<?php
/**
 * Register 1.6: aafm_rankmath_rendered_head() must never close an output buffer it does not own.
 *
 * Its ob_start() sits partway down the try block, after the throwaway WP_Query is built and
 * the_post() runs. Before this fix the catch block closed whatever buffer happened to be open
 * (`if ( ob_get_level() > 0 ) { ob_end_clean(); }`) with no record of the level that existed on
 * entry. An exception thrown earlier in the try - during WP_Query construction, before this
 * function ever calls its own ob_start() - reached that catch with only the CALLER's buffer on the
 * stack, and closed it. The visible symptom lands wherever some unrelated plugin or theme had a
 * buffer open, nowhere near this one.
 *
 * Runs in its own process: rank_math() does not exist anywhere in the normal stub suite (only the
 * separate contract suite provisions the real plugin), and a global function can never be
 * redeclared, so defining it here would otherwise leak into every later test in the same run.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Query;

final class RankMathRenderedHeadBufferTest extends TestCase {

	/**
	 * Isolated so the rank_math() stub this test defines never leaks into a later test.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rendered_head_leaves_a_pre_existing_buffer_open_when_the_exception_is_thrown_before_its_own_ob_start(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		if ( ! function_exists( 'rank_math' ) ) {
			// A minimal real-shaped stub: ->head exists with a public head() method, so the
			// function passes its early guards and reaches the WP_Query build inside the try.
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- process-isolated test stub, never shipped.
				'class AAFM_Test_RankMath_Head_Stub {'
				. 'public function head() { echo "<title>stub head that must never run</title>"; }'
				. '}'
				. 'function rank_math() {'
				. 'static $plugin;'
				. 'if ( null === $plugin ) {'
				. '$plugin = new \stdClass();'
				. '$plugin->head = new \AAFM_Test_RankMath_Head_Stub();'
				. '}'
				. 'return $plugin;'
				. '}'
			);
		}

		// Throws while the throwaway WP_Query is still being built - before aafm_rankmath_rendered_head()
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
			$result = aafm_rankmath_rendered_head( 'fallback-head', $post_id, 'rankmath' );
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

	/**
	 * Step 12 (R1-6): the render scope puts back exactly what was there before it, never a state
	 * rebuilt from the main query. The main query points at post A while the global $post is post B
	 * (a secondary loop in progress). After the render, $post is still B, both query globals are the
	 * same objects, and no post-data global the render set is left behind where it was absent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rendered_head_restores_the_prior_globals_exactly_instead_of_resetting_to_the_main_query(): void {
		$post_a  = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post_b  = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		if ( ! function_exists( 'rank_math' ) ) {
			// A real-shaped stub whose head() prints what the scope resolves, so the test also pins
			// that the render ran against the requested post.
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- process-isolated test stub, never shipped.
				'class AAFM_Test_RankMath_Scope_Stub {'
				. 'public function head() { echo "<title>" . get_queried_object_id() . "|" . get_the_ID() . "</title>"; }'
				. '}'
				. 'function rank_math() {'
				. 'static $plugin;'
				. 'if ( null === $plugin ) {'
				. '$plugin = new \stdClass();'
				. '$plugin->head = new \AAFM_Test_RankMath_Scope_Stub();'
				. '}'
				. 'return $plugin;'
				. '}'
			);
		}

		$main_query = new WP_Query( array( 'p' => $post_a ) );
		$in_loop    = get_post( $post_b );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test setup, the state under test.
		$GLOBALS['wp_query'] = $main_query;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test setup, the state under test.
		$GLOBALS['wp_the_query'] = $main_query;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test setup, the state under test.
		$GLOBALS['post'] = $in_loop;

		$absent = array( 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
		foreach ( $absent as $name ) {
			unset( $GLOBALS[ $name ] );
		}

		$result = aafm_rankmath_rendered_head( 'fallback-head', $post_id, 'rankmath' );

		$this->assertSame( '<title>' . $post_id . '|' . $post_id . '</title>', $result, 'The head must render against the requested post.' );
		$this->assertSame( $main_query, $GLOBALS['wp_query'], 'The main query global must be the same object as before.' );
		$this->assertSame( $main_query, $GLOBALS['wp_the_query'], 'The wp_the_query global must be the same object as before.' );
		$this->assertSame( $in_loop, $GLOBALS['post'], 'The global $post must be post B again, not the main query\'s post A.' );
		foreach ( $absent as $name ) {
			$this->assertArrayNotHasKey( $name, $GLOBALS, "The render must not leave the \$$name global behind." );
		}
	}
}
