<?php
/**
 * What a switch reaches: a generic post tool must not edit, trash or delete a page or product while
 * that type's own tool is off, and must keep working on ordinary posts whatever its own switch says.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\TestCase;

final class TypeToolReachTest extends TestCase {

	use IntegrationStubs;

	public function tear_down(): void {
		remove_filter( 'aafm_woocommerce_active', '__return_false' );
		$this->reset_integration_stubs();
		if ( post_type_exists( 'product' ) ) {
			unregister_post_type( 'product' );
		}
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * Set the enabled abilities.
	 *
	 * @param string[] $slugs Enabled abilities.
	 */
	private function enable( array $slugs ): void {
		update_option( 'aafm_enabled_abilities', $slugs );
	}

	private function page(): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'quick page',
			)
		);
	}

	private function post(): int {
		return self::factory()->post->create( array( 'post_content' => 'quick post' ) );
	}

	private function register_product_type(): int {
		register_post_type(
			'product',
			array(
				'public'       => true,
				'map_meta_cap' => true,
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'product' ) );
		return self::factory()->post->create( array( 'post_type' => 'product' ) );
	}

	public function test_update_post_cannot_reach_a_page_while_update_page_is_off(): void {
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/update-post' ) );
		$this->assertFalse( aafm_perm_update_post( array( 'post_id' => $this->page() ) ) );
	}

	public function test_trash_post_cannot_reach_a_page_while_trash_page_is_off(): void {
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/trash-post' ) );
		$this->assertFalse( aafm_perm_trash_post( array( 'post_id' => $this->page() ) ) );
	}

	public function test_delete_post_cannot_reach_a_page_while_delete_page_is_off(): void {
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/delete-post' ) );
		$this->assertFalse( aafm_perm_delete_post( array( 'post_id' => $this->page() ) ) );
	}

	public function test_replace_in_post_cannot_reach_a_page_while_update_page_is_off(): void {
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/replace-in-post' ) );
		$this->assertFalse( aafm_perm_replace_in_post( array( 'post_id' => $this->page() ) ) );
	}

	public function test_replace_sitewide_skips_pages_while_update_page_is_off(): void {
		$page = $this->page();
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/replace-sitewide' ) );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'    => 'quick',
				'replace'   => 'slow',
				'post_type' => 'page',
				'dry_run'   => false,
			)
		);

		$this->assertSame( 1, $out['skipped_no_permission'] );
		$this->assertSame( 'quick page', get_post( $page )->post_content );
	}

	public function test_update_cpt_item_cannot_reach_a_post_while_update_post_is_off(): void {
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/update-cpt-item' ) );
		$this->assertFalse( aafm_perm_update_cpt_item( array( 'post_id' => $this->post() ) ) );
		$this->assertFalse( aafm_perm_update_cpt_item( array( 'post_id' => $this->page() ) ) );
	}

	public function test_update_post_cannot_reach_a_product_while_wc_update_product_is_off(): void {
		$this->force_integration( 'woocommerce' );
		aafm_flush_registry_cache();
		$product = $this->register_product_type();
		$this->acting_as( 'administrator' );
		$this->enable( array( 'aafm/update-post' ) );

		$this->assertFalse( aafm_perm_update_post( array( 'post_id' => $product ) ) );

		$this->enable( array( 'aafm/update-post', 'aafm/wc-update-product' ) );
		$this->assertTrue( aafm_perm_update_post( array( 'post_id' => $product ) ) );
	}

	public function test_replace_in_post_still_edits_a_post_while_update_post_is_off(): void {
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/replace-in-post' ) );
		$this->assertTrue( aafm_perm_replace_in_post( array( 'post_id' => $this->post() ) ) );
	}

	public function test_replace_block_still_edits_a_post_while_update_post_is_off(): void {
		// aafm/replace-block names aafm_perm_update_post as its permission.
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/replace-block' ) );
		$this->assertTrue( aafm_perm_update_post( array( 'post_id' => $this->post() ) ) );
	}

	public function test_replace_sitewide_still_edits_posts_while_update_post_is_off(): void {
		$post = $this->post();
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/replace-sitewide' ) );

		$out = aafm_exec_replace_sitewide(
			array(
				'search'  => 'quick',
				'replace' => 'slow',
				'dry_run' => false,
			)
		);

		$this->assertSame( 0, $out['skipped_no_permission'] );
		$this->assertSame( 'slow post', get_post( $post )->post_content );
	}

	public function test_a_custom_type_with_no_tool_of_its_own_is_unaffected(): void {
		register_post_type(
			'aafm_reach_book',
			array(
				'public'       => true,
				'map_meta_cap' => true,
			)
		);
		update_option( 'aafm_allowed_post_types', array( 'aafm_reach_book' ) );
		$id = self::factory()->post->create( array( 'post_type' => 'aafm_reach_book' ) );
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/update-cpt-item' ) );

		$this->assertTrue( aafm_perm_update_cpt_item( array( 'post_id' => $id ) ) );
		$this->assertTrue( aafm_perm_update_post( array( 'post_id' => $id ) ) );
		unregister_post_type( 'aafm_reach_book' );
	}

	public function test_a_product_type_on_a_site_without_woocommerce_is_unaffected(): void {
		// The WooCommerce marker class another suite defines is process-permanent, so pin it inactive.
		add_filter( 'aafm_woocommerce_active', '__return_false' );
		aafm_flush_registry_cache();
		$product = $this->register_product_type();
		$this->acting_as( 'administrator' );
		$this->enable( array( 'aafm/update-post' ) );

		$this->assertTrue( aafm_perm_update_post( array( 'post_id' => $product ) ) );
	}

	public function test_a_role_allowlist_without_update_page_blocks_reach(): void {
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/update-post' ),
				),
			)
		);
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/update-post', 'aafm/update-page' ) );

		$this->assertFalse( aafm_perm_update_post( array( 'post_id' => $this->page() ) ) );
		$this->assertTrue( aafm_perm_update_post( array( 'post_id' => $this->post() ) ) );
	}

	public function test_both_tools_on_behaves_as_before(): void {
		$this->acting_as( 'editor' );
		$this->enable( array( 'aafm/update-post', 'aafm/update-page', 'aafm/trash-post', 'aafm/trash-page', 'aafm/delete-post', 'aafm/delete-page', 'aafm/replace-in-post', 'aafm/update-cpt-item' ) );
		$page = $this->page();

		$this->assertTrue( aafm_perm_update_post( array( 'post_id' => $page ) ) );
		$this->assertTrue( aafm_perm_trash_post( array( 'post_id' => $page ) ) );
		$this->assertTrue( aafm_perm_delete_post( array( 'post_id' => $page ) ) );
		$this->assertTrue( aafm_perm_replace_in_post( array( 'post_id' => $page ) ) );
		$this->assertTrue( aafm_perm_update_cpt_item( array( 'post_id' => $page ) ) );
		$this->enable( array( 'aafm/update-cpt-item', 'aafm/update-post' ) );
		$this->assertTrue( aafm_perm_update_cpt_item( array( 'post_id' => $this->post() ) ) );
	}
}
