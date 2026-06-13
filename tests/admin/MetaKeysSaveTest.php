<?php
/**
 * Exposed-meta-keys sanitizer coverage.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class MetaKeysSaveTest extends TestCase {

	public function test_sanitize_parses_multiline_trims_dedupes_drops_blocked(): void {
		$out = aafm_sanitize_allowed_meta_keys_input(
			array( 'aafm_meta_keys' => "subtitle\n subtitle \n\n_edit_lock\nwp_capabilities\nfeatured_color" )
		);
		$this->assertSame( array( 'subtitle', 'featured_color' ), $out );
	}

	public function test_detected_keys_scopes_and_excludes_blocked(): void {
		delete_transient( 'aafm_detected_meta_keys' );
		$id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $id, 'subtitle', 'x' );
		update_post_meta( $id, '_edit_lock', '123' );
		$keys = aafm_detected_meta_keys();
		$this->assertContains( 'subtitle', $keys );
		$this->assertNotContains( '_edit_lock', $keys );
	}

	public function test_meta_selector_is_nested_form_safe(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'subtitle', 'x' );
		ob_start();
		aafm_render_abilities_tab();
		$html = ob_get_clean();
		$this->assertSame( 1, substr_count( $html, '<form' ) );
		$this->assertStringContainsString( 'id="aafm-meta-keys-form"', $html );
		$this->assertStringContainsString( 'name="aafm_meta_keys"', $html );
		$this->assertStringContainsString( 'id="aafm-meta-keys-save"', $html );
		$this->assertStringContainsString( 'aafm-meta-chip', $html );

		// The privacy warning now renders through the shared notice component (a <div> with a
		// dashicon), not the old ad-hoc <p class="aafm-notice">. The <div> never opens a form,
		// so the one-form invariant above still holds.
		$this->assertStringContainsString( 'aafm-notice aafm-notice-warning', $html );
		$this->assertStringContainsString( 'dashicons-warning', $html );
	}
}
