<?php
/**
 * Sweep: every content-write execute callback refuses a builder-owned post.
 *
 * Update-cpt-item and update-page both delegate wholesale to aafm_exec_update_post() (confirmed
 * by reading posts.php/pages.php before wiring the guard), so wiring the guard once into
 * aafm_exec_update_post() covers all three - this sweep proves that delegation actually carries
 * the guard through, rather than trusting the delegation claim.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class PageBuilderGuardSweepTest extends TestCase {

	/**
	 * Every wired write execute callback refuses a post the guard flags as builder-owned.
	 *
	 * @dataProvider provide_write_execute_callbacks
	 *
	 * @param string              $exec_function Function name under test.
	 * @param array<string,mixed> $extra_input   Extra input merged with the builder-owned post's id.
	 * @param string              $id_key        The input key the callback expects the post id under.
	 * @param string              $post_type     Post type the fixture must be created as.
	 */
	public function test_every_content_write_refuses_a_builder_owned_post( string $exec_function, array $extra_input, string $id_key, string $post_type ): void {
		$post = self::factory()->post->create( array( 'post_type' => $post_type ) );
		update_post_meta( $post, '_elementor_data', '[]' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $exec_function( array_merge( array( $id_key => $post ), $extra_input ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_page_builder_owned', $result->get_error_code() );
	}

	/**
	 * Data provider: one row per write execute callback under test.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:string}>
	 */
	public function provide_write_execute_callbacks(): array {
		return array(
			'update-post'     => array( 'aafm_exec_update_post', array( 'title' => 'x' ), 'post_id', 'post' ),
			'replace-in-post' => array(
				'aafm_exec_replace_in_post',
				array(
					'search'  => 'x',
					'replace' => 'y',
				),
				'post_id',
				'post',
			),
			'update-page'     => array( 'aafm_exec_update_page', array( 'title' => 'x' ), 'page_id', 'page' ),
			'update-cpt-item' => array( 'aafm_exec_update_cpt_item', array( 'title' => 'x' ), 'post_id', 'post' ),
		);
	}

	public function test_replace_sitewide_skips_and_counts_a_builder_owned_candidate_instead_of_aborting(): void {
		$owned = self::factory()->post->create(
			array(
				'post_content' => 'find me here',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $owned, '_elementor_data', '[]' );
		$plain = self::factory()->post->create(
			array(
				'post_content' => 'find me here',
				'post_status'  => 'publish',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = aafm_exec_replace_sitewide(
			array(
				'search'    => 'find me',
				'replace'   => 'found',
				'post_type' => 'post',
				'status'    => 'publish',
				'dry_run'   => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['skipped_builder_owned'] );
		$this->assertSame( 1, $result['updated_posts'] );

		$owned_post = get_post( $owned );
		$this->assertSame( 'find me here', $owned_post->post_content, 'The builder-owned post must be left byte-for-byte untouched.' );
		$plain_post = get_post( $plain );
		$this->assertSame( 'found here', $plain_post->post_content );
	}
}
