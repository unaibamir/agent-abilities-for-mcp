<?php
/**
 * Append-terms-to-post ability: APPENDS terms (does not replace), is gated per-object
 * on edit_post AND the taxonomy's assign_terms cap + term-existence (reused C2 validator),
 * and returns the post's resulting terms in that taxonomy.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class AddPostTermsTest extends TestCase {

	public function test_append_adds_without_replacing(): void {
		$editor = $this->acting_as( 'editor' );
		$keep   = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Keep' ) );
		$add    = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Add' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $editor, 'post_type' => 'post' ) );
		wp_set_post_terms( $post, array( $keep ), 'category', false );

		$result = aafm_exec_add_post_terms(
			array(
				'post_id'  => $post,
				'taxonomy' => 'category',
				'term_ids' => array( $add ),
			)
		);

		$ids = wp_list_pluck( $result['terms'], 'id' );
		sort( $ids );
		$expected = array( (int) $keep, (int) $add );
		sort( $expected );
		// APPEND: the pre-existing term is still attached.
		$this->assertSame( $expected, $ids );
	}

	public function test_denied_for_non_post_editor(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$post  = self::factory()->post->create( array( 'post_author' => $owner, 'post_type' => 'post' ) );
		$this->acting_as( 'author' ); // a DIFFERENT author cannot edit owner's post.
		$this->assertFalse(
			aafm_perm_add_post_terms(
				array(
					'post_id'  => $post,
					'taxonomy' => 'category',
					'term_ids' => array( 1 ),
				)
			)
		);
	}

	public function test_denied_without_assign_terms_cap(): void {
		// A custom public taxonomy whose assign_terms cap is decoupled from edit_posts.
		register_taxonomy(
			'aafm_proj',
			'post',
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => 'manage_options' ),
			)
		);
		$editor = $this->acting_as( 'editor' ); // editor lacks manage_options.
		$term   = self::factory()->term->create( array( 'taxonomy' => 'aafm_proj' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $editor, 'post_type' => 'post' ) );

		$result = aafm_exec_add_post_terms(
			array(
				'post_id'  => $post,
				'taxonomy' => 'aafm_proj',
				'term_ids' => array( $term ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		unregister_taxonomy( 'aafm_proj' );
	}

	public function test_cross_taxonomy_term_is_rejected(): void {
		$editor = $this->acting_as( 'editor' );
		$tag    = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $editor, 'post_type' => 'post' ) );
		// A post_tag id passed as a category term must be rejected by the C2 validator.
		$this->assertInstanceOf(
			WP_Error::class,
			aafm_exec_add_post_terms(
				array(
					'post_id'  => $post,
					'taxonomy' => 'category',
					'term_ids' => array( $tag ),
				)
			)
		);
	}
}
