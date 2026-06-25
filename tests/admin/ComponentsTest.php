<?php
/**
 * Shared admin section + set-row render helpers: markup, escaping, collapsible state.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Admin;

use Oversio\Tests\TestCase;

final class ComponentsTest extends TestCase {

	public function test_render_section_emits_a_card_with_title_and_body(): void {
		ob_start();
		oversio_render_section(
			array(
				'title' => 'Safety controls',
				'body'  => '<p class="inner">hi</p>',
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'oversio-section', $html );
		$this->assertStringContainsString( 'Safety controls', $html );
		$this->assertStringContainsString( '<p class="inner">hi</p>', $html );
		// Non-collapsible default is a <section>, not <details>.
		$this->assertStringContainsString( '<section', $html );
		$this->assertStringNotContainsString( '<details', $html );
	}

	public function test_render_section_collapsible_emits_details_with_open_state(): void {
		ob_start();
		oversio_render_section(
			array(
				'title'       => 'Completed steps',
				'body'        => 'x',
				'collapsible' => true,
				'open'        => false,
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<details', $html );
		$this->assertStringContainsString( '<summary', $html );
		$this->assertStringNotContainsString( ' open', $html ); // open=false → no open attr.
	}

	public function test_render_section_escapes_the_title_but_trusts_prebuilt_body(): void {
		ob_start();
		oversio_render_section(
			array(
				'title' => '<b>x</b>',
				'body'  => '<i>kept</i>',
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<b>x</b>', $html ); // title is esc_html'd.
		$this->assertStringContainsString( '<i>kept</i>', $html ); // body is pre-escaped by caller.
	}

	public function test_render_set_row_emits_label_and_control(): void {
		ob_start();
		oversio_render_set_row(
			array(
				'label'   => 'Rate limit',
				'control' => '<input name="x">',
				'help'    => 'requests per minute',
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'oversio-set-row', $html );
		$this->assertStringContainsString( 'Rate limit', $html );
		$this->assertStringContainsString( '<input name="x">', $html );
		$this->assertStringContainsString( 'requests per minute', $html );
	}
}
