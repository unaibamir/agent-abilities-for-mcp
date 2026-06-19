<?php
/**
 * Replace-in-post: literal find/replace in post_content, kses'd, per-object gated.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class ReplaceInPostTest extends TestCase {

	public function test_in_registry_as_a_non_destructive_write(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/replace-in-post', $registry );
		$this->assertSame( 'writes', $registry['aafm/replace-in-post']['group'] );
		$this->assertSame( 'write', $registry['aafm/replace-in-post']['risk'] );
		$this->assertSame( 'content', $registry['aafm/replace-in-post']['subject'] );
	}

	public function test_replaces_and_returns_count(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'red fox red fox red',
			)
		);

		$out = aafm_exec_replace_in_post(
			array(
				'post_id' => $id,
				'search'  => 'red',
				'replace' => 'blue',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 3, $out['replacements'] );
		$this->assertSame( $id, $out['post']['id'] );
		$this->assertStringContainsString( 'blue fox blue fox blue', get_post( $id )->post_content );
	}

	public function test_no_op_when_search_absent_returns_zero(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'unchanged body',
			)
		);

		$out = aafm_exec_replace_in_post(
			array(
				'post_id' => $id,
				'search'  => 'missing',
				'replace' => 'x',
			)
		);

		$this->assertSame( 0, $out['replacements'] );
		// A no-op is NOT an error — it returns the unchanged post.
		$this->assertSame( 'unchanged body', get_post( $id )->post_content );
	}

	public function test_denied_for_non_post_editor(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$id    = self::factory()->post->create( array( 'post_author' => $owner ) );
		wp_set_current_user( $other );
		$this->assertFalse(
			aafm_perm_replace_in_post(
				array(
					'post_id' => $id,
					'search'  => 'a',
					'replace' => 'b',
				)
			)
		);
	}

	public function test_result_is_kses_stripped(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_content' => 'safe MARKER text',
			)
		);

		// Even if the replacement injects a script tag, wp_kses_post strips it.
		aafm_exec_replace_in_post(
			array(
				'post_id' => $id,
				'search'  => 'MARKER',
				'replace' => '<script>alert(1)</script>',
			)
		);

		$content = get_post( $id )->post_content;
		$this->assertStringNotContainsString( '<script', $content );
	}

	public function test_status_is_never_changed(): void {
		$author = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $author );
		$id = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_status'  => 'draft',
				'post_content' => 'alpha',
			)
		);

		aafm_exec_replace_in_post(
			array(
				'post_id' => $id,
				'search'  => 'alpha',
				'replace' => 'beta',
			)
		);

		// Body changed; status stayed draft — replace touches only content.
		$this->assertSame( 'draft', get_post( $id )->post_status );
		$this->assertStringContainsString( 'beta', get_post( $id )->post_content );
	}

	public function test_missing_post_is_generic_error(): void {
		$this->acting_as( 'editor' );
		$out = aafm_exec_replace_in_post(
			array(
				'post_id' => 999999,
				'search'  => 'a',
				'replace' => 'b',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_perm_callback_returns_false_on_empty_input(): void {
		$this->assertFalse( aafm_perm_replace_in_post( array() ) );
	}
}
