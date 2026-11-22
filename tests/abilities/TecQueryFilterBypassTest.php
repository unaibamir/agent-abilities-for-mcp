<?php
/**
 * The Events Calendar hides past events from any event-only WP_Query it does not see suppressed, so
 * every generic agent query that can target the event type opts out of that display filter while
 * TEC is active. Without it, search-content and get-posts for tribe_events skipped every past event.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class TecQueryFilterBypassTest extends TestCase {

	/**
	 * The tribe_suppress_query_filters value of every WP_Query run while recording.
	 *
	 * @var list<mixed>
	 */
	private array $seen = array();

	public function set_up(): void {
		parent::set_up();
		$this->acting_as( 'administrator' );
		self::factory()->post->create( array( 'post_title' => 'Governance hour' ) );
		add_action( 'pre_get_posts', array( $this, 'record' ) );
	}

	public function tear_down(): void {
		remove_action( 'pre_get_posts', array( $this, 'record' ) );
		remove_all_filters( 'aafm_tec_active' );
		parent::tear_down();
	}

	/**
	 * Record one query's suppression flag.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function record( \WP_Query $query ): void {
		$this->seen[] = $query->get( 'tribe_suppress_query_filters', null );
	}

	/**
	 * Run an ability and return the flags its queries carried.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 * @return list<mixed>
	 */
	private function flags_of( string $ability, array $input ): array {
		$this->register_enabled( array( $ability ) );
		$this->seen = array();
		$result     = wp_get_ability( $ability )->execute( $input );
		$this->assertNotWPError( $result );
		$this->assertNotSame( array(), $this->seen, 'precondition: the ability ran a WP_Query' );
		return array_values( array_unique( $this->seen, SORT_REGULAR ) );
	}

	/**
	 * The generic query abilities and an input for each.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function abilities(): array {
		return array(
			'get-posts'        => array( 'aafm/get-posts', array() ),
			'search-content'   => array( 'aafm/search-content', array( 'search' => 'Governance' ) ),
			'replace-sitewide' => array(
				'aafm/replace-sitewide',
				array(
					'search'  => 'Governance',
					'replace' => 'Governance',
				),
			),
		);
	}

	/**
	 * While TEC is active every query of the ability carries the suppression flag.
	 *
	 * @dataProvider abilities
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 */
	public function test_queries_suppress_the_tec_display_filter_while_tec_is_active( string $ability, array $input ): void {
		add_filter( 'aafm_tec_active', '__return_true' );
		$this->assertSame( array( true ), $this->flags_of( $ability, $input ) );
	}

	/**
	 * Without TEC no query carries the flag.
	 *
	 * @dataProvider abilities
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 */
	public function test_queries_are_untouched_without_tec( string $ability, array $input ): void {
		add_filter( 'aafm_tec_active', '__return_false' );
		$this->assertSame( array( null ), $this->flags_of( $ability, $input ) );
	}
}
