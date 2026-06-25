<?php
/**
 * Role x ability capability matrix.
 *
 * Sweeps subscriber / contributor / author / editor / administrator against the
 * abilities whose gate is a fixed site-wide capability, asserting the exact
 * allow/deny cell for each role and that every DENY also writes a 'denied' audit
 * row (the wrapper's denial logging is the accountability contract). Per-object
 * caps (edit_post, edit_comment) are covered in the per-ability write tests; this
 * file pins the coarse capability floor that decides who can reach an ability at all.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;

final class CapabilityMatrixTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
		oversio_clear_activity_log();

		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array(
				// reads.
				'oversio/get-posts',
				'oversio/get-pages',
				'oversio/get-terms',
				'oversio/get-taxonomies',
				'oversio/get-post-types',
				'oversio/get-site-info',
				'oversio/get-comments',
				'oversio/get-pending-comments',
				'oversio/get-media',
				'oversio/get-users',
				// writes (site-wide-cap gated).
				'oversio/create-draft',
				'oversio/create-post',
				'oversio/create-page',
				'oversio/create-term',
				'oversio/upload-media',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	/**
	 * The expected allow/deny matrix: ability => [ role => bool ].
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function matrix(): array {
		$all_allowed = array(
			'subscriber'    => true,
			'contributor'   => true,
			'author'        => true,
			'editor'        => true,
			'administrator' => true,
		);

		// read cap: any logged-in user with 'read' (every default role has it).
		$reads_open = $all_allowed;

		// get-pending-comments / get-media / get-users / writes step up.
		return array(
			'oversio/get-posts'            => $reads_open,
			'oversio/get-pages'            => $reads_open,
			'oversio/get-terms'            => $reads_open,
			'oversio/get-taxonomies'       => $reads_open,
			'oversio/get-post-types'       => $reads_open,
			'oversio/get-site-info'        => $reads_open,
			// get-comments with no post_id is the whole-site approved listing → 'read'.
			'oversio/get-comments'         => $reads_open,

			// moderate_comments: editor + admin only.
			'oversio/get-pending-comments' => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => false,
				'editor'        => true,
				'administrator' => true,
			),

			// upload_files OR edit_posts: contributor has edit_posts; subscriber neither.
			'oversio/get-media'            => array(
				'subscriber'    => false,
				'contributor'   => true,
				'author'        => true,
				'editor'        => true,
				'administrator' => true,
			),

			// list_users: admin only by default.
			'oversio/get-users'            => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => false,
				'editor'        => false,
				'administrator' => true,
			),

			// edit_posts: contributor and up.
			'oversio/create-draft'         => array(
				'subscriber'    => false,
				'contributor'   => true,
				'author'        => true,
				'editor'        => true,
				'administrator' => true,
			),

			// publish_posts: author and up.
			'oversio/create-post'          => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => true,
				'editor'        => true,
				'administrator' => true,
			),

			// publish_pages: editor and up (authors cannot touch pages).
			'oversio/create-page'          => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => false,
				'editor'        => true,
				'administrator' => true,
			),

			// manage_categories (manage_terms for category): editor and up.
			'oversio/create-term'          => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => false,
				'editor'        => true,
				'administrator' => true,
			),

			// upload_files: author and up (contributor lacks it).
			'oversio/upload-media'         => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => true,
				'editor'        => true,
				'administrator' => true,
			),
		);
	}

	public function test_capability_matrix_holds_for_every_role_and_ability(): void {
		foreach ( $this->matrix() as $ability => $by_role ) {
			foreach ( $by_role as $role => $expected ) {
				$this->acting_as( $role );
				$actual = wp_get_ability( $ability )->check_permissions( array() );
				$this->assertSame(
					$expected,
					$actual,
					sprintf( 'Cell mismatch: %s for role %s expected %s', $ability, $role, $expected ? 'ALLOW' : 'DENY' )
				);
			}
		}
	}

	public function test_every_deny_cell_writes_a_denied_audit_row(): void {
		oversio_clear_activity_log();

		$expected_denials = array();
		foreach ( $this->matrix() as $ability => $by_role ) {
			foreach ( $by_role as $role => $expected ) {
				if ( false === $expected ) {
					$this->acting_as( $role );
					wp_get_ability( $ability )->check_permissions( array() );
					$expected_denials[ $ability ] = true;
				}
			}
		}

		$denied    = oversio_query_activity(
			array(
				'status'   => 'denied',
				'per_page' => 200,
			)
		);
		$abilities = array_unique( wp_list_pluck( $denied, 'ability' ) );

		foreach ( array_keys( $expected_denials ) as $ability ) {
			$this->assertContains(
				$ability,
				$abilities,
				sprintf( 'Expected a denied audit row for %s but none was written.', $ability )
			);
		}
	}

	public function test_allow_cell_does_not_write_a_denied_row(): void {
		oversio_clear_activity_log();
		// An admin passing every gate must produce zero denial rows from the
		// permission checks alone (check_permissions does not log success).
		$this->acting_as( 'administrator' );
		foreach ( array_keys( $this->matrix() ) as $ability ) {
			wp_get_ability( $ability )->check_permissions( array() );
		}
		$this->assertCount(
			0,
			oversio_query_activity(
				array(
					'status'   => 'denied',
					'per_page' => 200,
				)
			)
		);
	}
}
