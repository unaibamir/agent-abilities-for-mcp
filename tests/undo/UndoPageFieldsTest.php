<?php
/**
 * Change history for a page's parent and template (F-11a, 1.11.3): update-page records post_parent
 * and the page template meta, a revert puts them back, and a revert that would point at a deleted
 * parent, build a loop or name a template the theme no longer offers is refused before any write.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

final class UndoPageFieldsTest extends UndoTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'theme_page_templates', array( $this, 'add_templates' ) );
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/update-page', 'aafm/update-post', 'aafm/update-post-meta', 'aafm/undo-change' ) );
		aafm_oauth_current_client_id( 'client-a' );
	}

	public function tear_down(): void {
		aafm_oauth_current_client_id( null, true );
		remove_filter( 'theme_page_templates', array( $this, 'add_templates' ) );
		remove_all_filters( 'aafm_test_drop_template' );
		parent::tear_down();
	}

	/**
	 * Two templates; a test can drop one through aafm_test_drop_template.
	 *
	 * @param array<string,string> $templates Templates.
	 * @return array<string,string>
	 */
	public function add_templates( $templates ): array {
		$templates = array_merge( (array) $templates, array( 'tpl-a.php' => 'A', 'tpl-b.php' => 'B' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$drop      = (string) apply_filters( 'aafm_test_drop_template', '' );
		unset( $templates[ $drop ] );
		return $templates;
	}

	private function page( int $parent_id = 0 ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent_id,
			)
		);
	}

	private function change_from( array $input ): int {
		$result = wp_get_ability( 'aafm/update-page' )->execute( $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['undo']['undoable'], 'precondition: ' . wp_json_encode( $result['undo'] ) );
		return (int) $result['undo']['change_ids'][0];
	}

	private function parent_of( int $id ): int {
		clean_post_cache( $id );
		return (int) get_post( $id )->post_parent;
	}

	public function test_undo_restores_parent_and_template(): void {
		$old_parent = $this->page();
		$new_parent = $this->page();
		$page       = $this->page( $old_parent );
		update_post_meta( $page, '_wp_page_template', 'tpl-a.php' );

		$change = $this->change_from(
			array(
				'page_id'   => $page,
				'parent_id' => $new_parent,
				'template'  => 'tpl-b.php',
			)
		);
		$this->assertSame( $new_parent, $this->parent_of( $page ) );

		$row = $this->change_rows();
		$this->assertContains( 'post:post_parent', $this->row_fields( end( $row ) ) );
		$this->assertContains( 'meta:_wp_page_template', $this->row_fields( end( $row ) ) );

		$result = aafm_undo_apply( $change, 'owner' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( 'reverted', $result['outcome'] );
		$this->assertSame( $old_parent, $this->parent_of( $page ) );
		wp_cache_delete( $page, 'post_meta' );
		$this->assertSame( 'tpl-a.php', get_page_template_slug( $page ) );
	}

	public function test_undo_refused_when_recorded_parent_was_deleted(): void {
		$old_parent = $this->page();
		$new_parent = $this->page();
		$page       = $this->page( $old_parent );
		$change     = $this->change_from(
			array(
				'page_id'   => $page,
				'parent_id' => $new_parent,
			)
		);
		wp_delete_post( $old_parent, true );
		$this->assertSame( $new_parent, $this->parent_of( $page ), 'precondition: deleting the old parent left the page alone.' );

		$result = aafm_undo_apply( $change, 'owner' );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_dependency_gone', $result->get_error_code() );
		$this->assertSame( $new_parent, $this->parent_of( $page ), 'Nothing was written.' );
	}

	public function test_undo_refused_when_restore_would_create_a_cycle(): void {
		$old_parent = $this->page();
		$page       = $this->page( $old_parent );
		$change     = $this->change_from(
			array(
				'page_id'   => $page,
				'parent_id' => 0,
			)
		);
		// The old parent now sits under the page, so putting the page back under it would loop.
		wp_update_post(
			array(
				'ID'          => $old_parent,
				'post_parent' => $page,
			)
		);

		$result = aafm_undo_apply( $change, 'owner' );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_not_allowed', $result->get_error_code() );
		$this->assertSame( 0, $this->parent_of( $page ) );
		$this->assertSame( $page, $this->parent_of( $old_parent ) );
	}

	public function test_undo_refused_when_recorded_template_is_gone(): void {
		$page = $this->page();
		update_post_meta( $page, '_wp_page_template', 'tpl-a.php' );
		$change = $this->change_from(
			array(
				'page_id'  => $page,
				'template' => 'tpl-b.php',
			)
		);
		add_filter( 'aafm_test_drop_template', static fn(): string => 'tpl-a.php' );

		$result = aafm_undo_apply( $change, 'owner' );

		$this->assertWPError( $result );
		$this->assertSame( 'aafm_undo_dependency_gone', $result->get_error_code() );
		wp_cache_delete( $page, 'post_meta' );
		$this->assertSame( 'tpl-b.php', get_page_template_slug( $page ) );
	}

	public function test_agent_undo_may_restore_page_template_key_only(): void {
		$page = $this->page();
		update_post_meta( $page, '_wp_page_template', 'tpl-a.php' );
		$change = $this->change_from(
			array(
				'page_id'  => $page,
				'template' => 'tpl-b.php',
			)
		);

		$result = wp_get_ability( 'aafm/undo-change' )->execute( array( 'change_id' => $change ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		wp_cache_delete( $page, 'post_meta' );
		$this->assertSame( 'tpl-a.php', get_page_template_slug( $page ) );
	}

	public function test_agent_undo_refuses_a_template_the_theme_dropped(): void {
		$page = $this->page();
		update_post_meta( $page, '_wp_page_template', 'tpl-a.php' );
		$change = $this->change_from(
			array(
				'page_id'  => $page,
				'template' => 'tpl-b.php',
			)
		);
		add_filter( 'aafm_test_drop_template', static fn(): string => 'tpl-a.php' );

		$row = aafm_undo_get_view( $change )['row'] ?? null;
		$this->assertIsArray( $row );
		$this->assertNotNull( aafm_undo_agent_refusal( $row ), 'The agent exception checks the template is still offered.' );
		$result = wp_get_ability( 'aafm/undo-change' )->execute( array( 'change_id' => $change ) );
		$this->assertWPError( $result );
		wp_cache_delete( $page, 'post_meta' );
		$this->assertSame( 'tpl-b.php', get_page_template_slug( $page ) );
	}

	public function test_agent_undo_still_refuses_other_protected_meta(): void {
		$post = (int) self::factory()->post->create();
		$row  = array(
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			'reverts_change_id' => 0,
			'ability'           => 'aafm/update-post',
			'object_type'       => 'post',
			'object_id'         => $post,
			'undo_op'           => 'restore',
			'fields'            => wp_json_encode( array( 'meta:_wp_page_template' ) ),
			'before_values'     => wp_json_encode( array( 'meta:_wp_page_template' => array( 'tpl-a.php' ) ) ),
			'after_hashes'      => wp_json_encode( array() ),
			'perm_input'        => wp_json_encode( array( 'post_id' => $post ) ),
			'taxonomy'          => '',
		);
		$this->assertSame( 'not_allowed', aafm_undo_agent_refusal( $row ), 'The page template key passes only on an update-page change.' );

		$page              = $this->page();
		$row['ability']    = 'aafm/update-page';
		$row['object_id']  = $page;
		$row['perm_input'] = wp_json_encode( array( 'page_id' => $page ) );
		$row['fields']     = wp_json_encode( array( 'meta:_wp_page_template', 'meta:_edit_lock' ) );
		$this->assertSame( 'not_allowed', aafm_undo_agent_refusal( $row ), 'Any other protected key is still refused.' );
	}

	public function test_post_and_cpt_undo_fields_do_not_gain_page_fields(): void {
		$input  = array(
			'parent_id' => 5,
			'template'  => 'tpl-a.php',
		);
		$fields = aafm_undo_fields_post_write( $input );
		$this->assertNotContains( 'post:post_parent', $fields );
		$this->assertNotContains( 'meta:_wp_page_template', $fields );
		$this->assertNotContains( 'post:post_parent', aafm_undo_post_field_ids() );
		$surfaces = aafm_undo_surfaces();
		$this->assertSame( 'aafm_undo_fields_post_write', $surfaces['aafm/update-post']['fields'] );
		$this->assertSame( 'aafm_undo_fields_post_write', $surfaces['aafm/update-cpt-item']['fields'] );
		$this->assertSame( 'aafm_undo_fields_page_write', $surfaces['aafm/update-page']['fields'] );
		$page_fields = aafm_undo_fields_page_write( $input );
		$this->assertContains( 'post:post_parent', $page_fields );
		$this->assertContains( 'meta:_wp_page_template', $page_fields );
		$this->assertNotContains( 'post:post_parent', aafm_undo_fields_page_write( array( 'title' => 'x' ) ) );
	}
}
