<?php
/**
 * Shared admin section + set-row render helpers: markup, escaping, collapsible state.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class ComponentsTest extends TestCase {

	public function test_render_section_emits_a_card_with_title_and_body(): void {
		ob_start();
		aafm_render_section(
			array(
				'title' => 'Safety controls',
				'body'  => '<p class="inner">hi</p>',
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'aafm-section', $html );
		$this->assertStringContainsString( 'Safety controls', $html );
		$this->assertStringContainsString( '<p class="inner">hi</p>', $html );
		// Non-collapsible default is a <section>, not <details>.
		$this->assertStringContainsString( '<section', $html );
		$this->assertStringNotContainsString( '<details', $html );
	}

	public function test_render_section_collapsible_emits_details_with_open_state(): void {
		ob_start();
		aafm_render_section(
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
		aafm_render_section(
			array(
				'title' => '<b>x</b>',
				'body'  => '<i>kept</i>',
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<b>x</b>', $html ); // title is esc_html'd.
		$this->assertStringContainsString( '<i>kept</i>', $html ); // body is pre-escaped by caller.
	}

	public function test_card_head_html_is_the_one_head_pattern(): void {
		$html = aafm_card_head_html( 'clock', '<i>Title</i>', 'title-id', '<span class="aafm-pill">P</span>' );
		$this->assertStringStartsWith( '<span class="aafm-card-head-ic"><svg', $html );
		$this->assertStringContainsString( '<div class="aafm-card-head-text"><h2 class="aafm-card-head-title" id="title-id">&lt;i&gt;Title&lt;/i&gt;</h2></div><span class="aafm-pill">P</span>', $html );
		$this->assertStringStartsWith( '<div class="aafm-card-head-text"><h2', aafm_card_head_html( '', 'T' ) );
	}

	public function test_render_section_puts_the_description_in_the_body_not_the_head(): void {
		ob_start();
		aafm_render_section(
			array(
				'title'       => 'Card',
				'description' => 'Explains the card.',
				'body'        => '<p>body</p>',
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<div class="aafm-card-pad aafm-section-body"><p class="aafm-card-desc">Explains the card.</p><p>body</p></div>', $html );
		$this->assertStringContainsString( '<h2 class="aafm-card-head-title">Card</h2></div></div>', $html );
	}

	public function test_render_set_row_emits_label_and_control(): void {
		ob_start();
		aafm_render_set_row(
			array(
				'label'   => 'Rate limit',
				'control' => '<input name="x">',
				'help'    => 'requests per minute',
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'aafm-set-row', $html );
		$this->assertStringContainsString( 'Rate limit', $html );
		$this->assertStringContainsString( '<input name="x">', $html );
		$this->assertStringContainsString( 'requests per minute', $html );
	}
}
