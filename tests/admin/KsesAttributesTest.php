<?php
/**
 * Every attribute the 1.10.0 admin renders must survive the admin kses allow list, or the
 * script that reads it finds nothing.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

/**
 * Covers aafm_admin_allowed_html() against the attributes this release adds.
 */
class KsesAttributesTest extends TestCase {

	/**
	 * Attributes this release renders. Later tasks add theirs here.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function attributes(): array {
		return array(
			'data-conn-tab'       => array( 'data-conn-tab' ),
			'data-conn-panel'     => array( 'data-conn-panel' ),
			'data-paused'         => array( 'data-paused' ),
			'data-aafm-saved'     => array( 'data-aafm-saved' ),
			'data-saved'          => array( 'data-saved' ),
			'data-tab'            => array( 'data-tab' ),
			'data-aafm-untracked' => array( 'data-aafm-untracked' ),
			'data-qc-preset'      => array( 'data-qc-preset' ),
			'aria-pressed'        => array( 'aria-pressed' ),
			'data-ttl'            => array( 'data-ttl' ),
			'data-who'            => array( 'data-who' ),
			'data-uuid'           => array( 'data-uuid' ),
		);
	}

	/**
	 * Each attribute survives the section renderer on every element that carries it.
	 *
	 * @dataProvider attributes
	 *
	 * @param string $attr Attribute name.
	 */
	public function test_every_new_data_attribute_survives_the_card_renderer( string $attr ): void {
		$body = '';
		foreach ( array( 'div', 'button', 'span', 'a', 'input', 'select' ) as $tag ) {
			$body .= sprintf( '<%1$s %2$s="v-%1$s">%3$s', $tag, $attr, 'input' === $tag ? '' : '</' . $tag . '>' );
		}
		ob_start();
		aafm_render_section(
			array(
				'title' => 'Card',
				'body'  => $body,
			)
		);
		$html = (string) ob_get_clean();
		foreach ( array( 'div', 'button', 'span', 'a', 'input', 'select' ) as $tag ) {
			$this->assertStringContainsString( $attr . '="v-' . $tag . '"', $html, $tag );
			$this->assertArrayHasKey( $attr, aafm_admin_allowed_html()[ $tag ], $tag );
		}
	}

	public function test_number_and_date_input_bounds_survive(): void {
		ob_start();
		aafm_render_section(
			array(
				'title' => 'Card',
				'body'  => '<input type="number" min="1" max="10000" step="1">',
			)
		);
		$this->assertStringContainsString( 'min="1" max="10000" step="1"', (string) ob_get_clean() );
	}
}
