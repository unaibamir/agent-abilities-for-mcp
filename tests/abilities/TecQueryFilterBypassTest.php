<?php
/**
 * The Events Calendar hides past events from any event-only WP_Query it does not see suppressed, so
 * every generic agent query that can return the event type opts out of that display filter. It is
 * keyed on the post type, not on TEC passing the version floor of the dedicated event tools: an
 * older TEC still filters. Without it, search-content and get-posts for tribe_events skipped every
 * past event.
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

	/**
	 * Whether this test registered the event post type (TEC itself is not loaded in the suite).
	 *
	 * @var bool
	 */
	private bool $registered = false;

	public function set_up(): void {
		parent::set_up();
		if ( ! post_type_exists( 'tribe_events' ) ) {
			register_post_type( 'tribe_events', array( 'public' => true ) );
			$this->registered = true;
		}
		update_option( 'aafm_allowed_post_types', array( 'tribe_events' ) );
		$this->acting_as( 'administrator' );
		self::factory()->post->create( array( 'post_title' => 'Governance hour' ) );
		self::factory()->post->create(
			array(
				'post_title' => 'Governance office hours',
				'post_type'  => 'tribe_events',
			)
		);
		// Reported inactive, as a TEC below the floor is: the flag must not depend on it.
		add_filter( 'aafm_tec_active', '__return_false' );
		add_action( 'pre_get_posts', array( $this, 'record' ) );
	}

	public function tear_down(): void {
		remove_action( 'pre_get_posts', array( $this, 'record' ) );
		remove_all_filters( 'aafm_tec_active' );
		delete_option( 'aafm_allowed_post_types' );
		if ( $this->registered ) {
			unregister_post_type( 'tribe_events' );
		}
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
	 * Each generic query ability, aimed at events and at posts, with the flag its queries must carry.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:true|null}>
	 */
	public function queries(): array {
		$replace = array(
			'search'  => 'Governance',
			'replace' => 'Governance',
		);
		return array(
			'get-posts events'        => array( 'aafm/get-posts', array( 'post_type' => 'tribe_events' ), true ),
			'get-posts posts'         => array( 'aafm/get-posts', array(), null ),
			'search-content events'   => array(
				'aafm/search-content',
				array(
					'search'     => 'Governance',
					'post_types' => array( 'tribe_events' ),
				),
				true,
			),
			'search-content posts'    => array(
				'aafm/search-content',
				array(
					'search'     => 'Governance',
					'post_types' => array( 'post' ),
				),
				null,
			),
			'replace-sitewide events' => array( 'aafm/replace-sitewide', $replace + array( 'post_type' => 'tribe_events' ), true ),
			'replace-sitewide posts'  => array( 'aafm/replace-sitewide', $replace, null ),
		);
	}

	/**
	 * A query that can return events carries the flag, whatever TEC's version; a posts-only query does not.
	 *
	 * @dataProvider queries
	 *
	 * @param string              $ability  Ability name.
	 * @param array<string,mixed> $input    Input.
	 * @param true|null           $expected The flag every query of the call carries.
	 */
	public function test_the_flag_follows_the_queried_post_type( string $ability, array $input, ?bool $expected ): void {
		$this->register_enabled( array( $ability ) );
		$this->seen = array();
		$result     = wp_get_ability( $ability )->execute( $input );
		$this->assertNotWPError( $result );
		$this->assertNotSame( array(), $this->seen, 'precondition: the ability ran a WP_Query' );
		$this->assertSame( array( $expected ), array_values( array_unique( $this->seen, SORT_REGULAR ) ) );
	}
}
