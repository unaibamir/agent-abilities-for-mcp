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

	/**
	 * Define a real-shaped rank_math() stub whose head() runs the callable in
	 * $GLOBALS['aafm_test_render'], so each test decides what the head render does.
	 */
	private function stub_rank_math_with_a_render_callback(): void {
		if ( ! function_exists( 'rank_math' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- process-isolated test stub, never shipped.
				'class AAFM_Test_RankMath_Callback_Stub {'
				. 'public function head() { ( $GLOBALS["aafm_test_render"] )(); }'
				. '}'
				. 'function rank_math() {'
				. 'static $plugin;'
				. 'if ( null === $plugin ) {'
				. '$plugin = new \stdClass();'
				. '$plugin->head = new \AAFM_Test_RankMath_Callback_Stub();'
				. '}'
				. 'return $plugin;'
				. '}'
			);
		}
	}

	/**
	 * Step 12 (fixsurface-4 a, b): a render that throws after the globals swap, with a buffer of its
	 * own still open. The fallback head comes back, both query globals and $post are what they were,
	 * no absent post-data global is left set, and only buffers opened inside the scope are closed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_render_that_throws_after_the_globals_swap_restores_them_and_closes_only_its_own_buffers(): void {
		$post_a  = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post_b  = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->stub_rank_math_with_a_render_callback();
		$GLOBALS['aafm_test_render'] = static function (): void {
			echo '<meta a>';
			ob_start();
			echo '<meta b>';
			throw new \RuntimeException( 'thrown after the globals swap' );
		};

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

		$level_before = ob_get_level();
		ob_start();
		echo 'caller-owned-buffer';
		$result = aafm_rankmath_rendered_head( 'fallback-head', $post_id, 'rankmath' );
		$level  = ob_get_level();
		$caller = ob_get_contents();
		ob_end_clean();

		$this->assertSame( 'fallback-head', $result );
		$this->assertSame( $level_before + 1, $level, 'Only the buffers opened inside the scope may be closed.' );
		$this->assertSame( 'caller-owned-buffer', $caller );
		$this->assertSame( $main_query, $GLOBALS['wp_query'] );
		$this->assertSame( $main_query, $GLOBALS['wp_the_query'] );
		$this->assertSame( $in_loop, $GLOBALS['post'] );
		foreach ( $absent as $name ) {
			$this->assertArrayNotHasKey( $name, $GLOBALS, "The render must not leave the \$$name global behind." );
		}
	}

	/**
	 * Step 12 (b5hunta-3): a render that returns with one more buffer open than it found. The scope
	 * collects every buffer opened after entry in order, closes them, and leaves the caller's alone.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_render_that_leaves_a_buffer_open_returns_all_of_its_output_and_closes_that_buffer(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->stub_rank_math_with_a_render_callback();
		$GLOBALS['aafm_test_render'] = static function (): void {
			echo '<meta a>';
			ob_start();
			echo '<meta b>';
		};

		$level_before = ob_get_level();
		ob_start();
		echo 'caller-owned-buffer';
		$result = aafm_rankmath_rendered_head( 'fallback-head', $post_id, 'rankmath' );
		$level  = ob_get_level();
		$caller = ob_get_contents();
		while ( ob_get_level() > $level_before ) {
			ob_end_clean();
		}

		$this->assertSame( '<meta a><meta b>', $result );
		$this->assertSame( $level_before + 1, $level, 'The buffer the render left open must be closed.' );
		$this->assertSame( 'caller-owned-buffer', $caller );
	}

	/**
	 * Step 12 (b5c2r1-codex-4): a render that closes the scope's own buffer. The scope takes the
	 * fallback and never reads or closes the caller's buffer as if it were the head.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_render_that_closes_the_scope_buffer_returns_the_fallback_and_leaves_the_caller_buffer(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->stub_rank_math_with_a_render_callback();
		$GLOBALS['aafm_test_render'] = static function (): void {
			echo '<meta a>';
			ob_end_clean();
		};

		$level_before = ob_get_level();
		ob_start();
		echo 'caller-owned-buffer';
		$result = aafm_rankmath_rendered_head( 'fallback-head', $post_id, 'rankmath' );
		$level  = ob_get_level();
		$caller = ob_get_level() > $level_before ? ob_get_contents() : false;
		while ( ob_get_level() > $level_before ) {
			ob_end_clean();
		}

		$this->assertSame( 'fallback-head', $result );
		$this->assertSame( $level_before + 1, $level, 'The caller-owned buffer must still be open.' );
		$this->assertSame( 'caller-owned-buffer', $caller );
	}

	/**
	 * Step 12 (fixsurface-4 c, R1-5): a head hook opens a buffer without the removable flag, so
	 * ob_end_clean() cannot close it and the level never drops. The scope must stop at that buffer
	 * and return, on the success path and the throw path alike, instead of looping forever.
	 * PHPUnit cannot hold such a buffer (its own teardown loops on ob_end_clean() until the level
	 * drops), so this runs helpers.php in a child PHP process under `timeout`, with stand-ins for
	 * the few WordPress names the scope touches.
	 */
	public function test_a_buffer_the_render_cannot_close_stops_the_unwind_instead_of_hanging(): void {
		$helpers = dirname( __DIR__, 2 ) . '/includes/helpers.php';
		$code    = "define( 'ABSPATH', '/' );"
			. 'class WP_Post {}'
			. 'class WP_Query { public function __construct( $args ) {} public function have_posts() { return false; } }'
			. 'function aafm_exact_object( $type, $id ) { return new WP_Post(); }'
			. 'require $argv[1];'
			. '$entry = ob_get_level();'
			. '$out = aafm_with_seo_render_scope( 1, static function () { echo "a"; ob_start( null, 0, 0 ); echo "b"; if ( "throw" === $GLOBALS["argv"][2] ) { throw new RuntimeException( "x" ); } } );'
			. 'fwrite( STDOUT, json_encode( array( "out" => $out, "extra" => ob_get_level() - $entry ) ) );';

		foreach ( array( 'return', 'throw' ) as $mode ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- test-only: a buffer no code can close would hang PHPUnit's own teardown, so it lives in a child process.
			$proc = proc_open(
				array( 'timeout', '20', PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code, '--', $helpers, $mode ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			$this->assertIsResource( $proc );
			$out = stream_get_contents( $pipes[1] );
			$err = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- test-only: closes the child process pipe, not a WP file operation.
			fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- test-only: closes the child process pipe, not a WP file operation.
			$this->assertSame( 0, proc_close( $proc ), "The {$mode} path must return, not hang: " . $err );

			$result = json_decode( (string) substr( (string) $out, 0, (int) strpos( (string) $out, '}' ) + 1 ), true );
			$this->assertSame( '', $result['out'], "The {$mode} path cannot collect its own buffer, so it takes the fallback." );
			$this->assertLessThanOrEqual( 2, $result['extra'], "The {$mode} path leaves at most its own buffer and the unclosable one." );
		}
	}
}
