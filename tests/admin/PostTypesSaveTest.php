<?php
/**
 * Exposed-content-types sanitizer + AJAX save coverage.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class PostTypesSaveTest extends TestCase {

	public function test_sanitize_keeps_only_eligible_opted_in_types(): void {
		register_post_type(
			'aafm_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);
		$posted = array( 'aafm_post_types' => array( 'aafm_book', 'attachment', 'revision', 'post', '<script>' ) );
		$clean  = aafm_sanitize_allowed_post_types_input( $posted );
		// Only the eligible CPT survives. attachment/revision fail the floor; post/page are
		// always-on and never stored in the option; <script> sanitizes to nothing eligible.
		$this->assertSame( array( 'aafm_book' ), $clean );
	}

	public function test_sanitize_empty_post_stores_nothing(): void {
		$this->assertSame( array(), aafm_sanitize_allowed_post_types_input( array() ) );
	}

	public function test_post_and_page_are_never_persisted_to_the_option(): void {
		$clean = aafm_sanitize_allowed_post_types_input( array( 'aafm_post_types' => array( 'post', 'page' ) ) );
		$this->assertSame( array(), $clean ); // they are forced on by the helper, not stored.
	}
}
