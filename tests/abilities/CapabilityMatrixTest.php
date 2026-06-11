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

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class CapabilityMatrixTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				// reads.
				'aafm/get-posts',
				'aafm/get-pages',
				'aafm/get-terms',
				'aafm/get-taxonomies',
				'aafm/get-post-types',
				'aafm/get-site-info',
				'aafm/get-comments',
				'aafm/get-pending-comments',
				'aafm/get-media',
				'aafm/get-users',
				// writes (site-wide-cap gated).
				'aafm/create-draft',
				'aafm/create-post',
				'aafm/create-page',
				'aafm/create-term',
				'aafm/upload-media',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * @param string   $action   Action name to simulate.
	 * @param callable $callback Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $callback ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$callback();
		array_pop( $wp_current_filter );
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
			'aafm/get-posts'            => $reads_open,
			'aafm/get-pages'            => $reads_open,
			'aafm/get-terms'            => $reads_open,
			'aafm/get-taxonomies'       => $reads_open,
			'aafm/get-post-types'       => $reads_open,
			'aafm/get-site-info'        => $reads_open,
			// get-comments with no post_id is the whole-site approved listing → 'read'.
			'aafm/get-comments'         => $reads_open,

			// moderate_comments: editor + admin only.
			'aafm/get-pending-comments' => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => false,
				'editor'        => true,
				'administrator' => true,
			),

			// upload_files OR edit_posts: contributor has edit_posts; subscriber neither.
			'aafm/get-media'            => array(
				'subscriber'    => false,
				'contributor'   => true,
				'author'        => true,
				'editor'        => true,
				'administrator' => true,
			),

			// list_users: admin only by default.
			'aafm/get-users'            => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => false,
				'editor'        => false,
				'administrator' => true,
			),

			// edit_posts: contributor and up.
			'aafm/create-draft'         => array(
				'subscriber'    => false,
				'contributor'   => true,
				'author'        => true,
				'editor'        => true,
				'administrator' => true,
			),

			// publish_posts: author and up.
			'aafm/create-post'          => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => true,
				'editor'        => true,
				'administrator' => true,
			),

			// publish_pages: editor and up (authors cannot touch pages).
			'aafm/create-page'          => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => false,
				'editor'        => true,
				'administrator' => true,
			),

			// manage_categories (manage_terms for category): editor and up.
			'aafm/create-term'          => array(
				'subscriber'    => false,
				'contributor'   => false,
				'author'        => false,
				'editor'        => true,
				'administrator' => true,
			),

			// upload_files: author and up (contributor lacks it).
			'aafm/upload-media'         => array(
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
		aafm_clear_activity_log();

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

		$denied    = aafm_query_activity(
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
		aafm_clear_activity_log();
		// An admin passing every gate must produce zero denial rows from the
		// permission checks alone (check_permissions does not log success).
		$this->acting_as( 'administrator' );
		foreach ( array_keys( $this->matrix() ) as $ability ) {
			wp_get_ability( $ability )->check_permissions( array() );
		}
		$this->assertCount(
			0,
			aafm_query_activity(
				array(
					'status'   => 'denied',
					'per_page' => 200,
				)
			)
		);
	}
}
