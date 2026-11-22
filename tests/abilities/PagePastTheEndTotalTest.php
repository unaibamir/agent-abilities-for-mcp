<?php
/**
 * A page past the last one still reports the real total. Core leaves found_posts at 0 when the page
 * holds no rows, so an agent paging "to the end" by total read the collection as empty.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class PagePastTheEndTotalTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
		self::factory()->post->create_many( 3, array( 'post_title' => 'Paging probe' ) );
		self::factory()->attachment->create_many( 3, array( 'post_title' => 'Paging probe' ) );
	}

	/**
	 * The list abilities and an input that matches exactly the three fixtures.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function lists(): array {
		return array(
			'get-posts'      => array( 'aafm/get-posts', array( 'search' => 'Paging probe' ) ),
			'search-content' => array( 'aafm/search-content', array( 'search' => 'Paging probe' ) ),
			'get-media'      => array( 'aafm/get-media', array( 'search' => 'Paging probe' ) ),
		);
	}

	/**
	 * Page 50 of a three-row result is empty and still totals three.
	 *
	 * @dataProvider lists
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 */
	public function test_a_page_past_the_end_reports_the_real_total( string $ability, array $input ): void {
		$this->register_enabled( array( $ability ) );
		$ability = wp_get_ability( $ability );

		$first = $ability->execute( $input + array( 'per_page' => 2 ) );
		$this->assertNotWPError( $first );
		$this->assertSame( 3, $first['total'], 'precondition: the fixtures match' );

		$past = $ability->execute(
			$input + array(
				'per_page' => 2,
				'page'     => 50,
			)
		);
		$this->assertNotWPError( $past );
		$this->assertSame( 3, $past['total'] );
	}
}
