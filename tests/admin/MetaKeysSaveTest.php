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
}
