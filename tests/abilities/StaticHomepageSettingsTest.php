<?php
/**
 * The static homepage through update-site-settings (F-11b, 1.11.3): show_on_front, page_on_front and
 * page_for_posts are validated as one state, written in a fixed order, read back from the database,
 * and a key that did not land turns the call into an error naming what landed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class StaticHomepageSettingsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( 'aafm/update-site-settings', 'aafm/get-site-settings' ) );
		$this->acting_as( 'administrator' );
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		update_option( 'page_for_posts', 0 );
	}

	private function page( string $status = 'publish' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => $status,
			)
		);
	}

	private function update( array $settings ) {
		return wp_get_ability( 'aafm/update-site-settings' )->execute( array( 'settings' => $settings ) );
	}

	/**
	 * The three keys as stored in the database rows.
	 *
	 * @return array<string,string>
	 */
	private function stored(): array {
		$out = array();
		foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts' ) as $key ) {
			$out[ $key ] = (string) aafm_option_row( $key )['value'];
		}
		return $out;
	}

	public function test_show_on_front_page_with_published_front_page(): void {
		$front = $this->page();
		$blog  = $this->page();

		$res = $this->update(
			array(
				'show_on_front'  => 'page',
				'page_on_front'  => $front,
				'page_for_posts' => $blog,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame(
			array(
				'show_on_front'  => 'page',
				'page_on_front'  => (string) $front,
				'page_for_posts' => (string) $blog,
			),
			$this->stored()
		);
		$this->assertSame( array( 'page_on_front', 'page_for_posts', 'show_on_front' ), $res['landed'] );
		$this->assertSame( array(), $res['failed'] );
		$this->assertContains( 'show_on_front', aafm_allowed_site_settings() );
	}

	public function test_front_page_must_be_a_published_page(): void {
		$post = (int) self::factory()->post->create();

		$res = $this->update(
			array(
				'show_on_front' => 'page',
				'page_on_front' => $post,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_homepage_not_a_published_page', $res->get_error_code() );
		$this->assertSame( 'posts', $this->stored()['show_on_front'] );
	}

	public function test_draft_page_is_refused(): void {
		$res = $this->update( array( 'page_for_posts' => $this->page( 'draft' ) ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_homepage_not_a_published_page', $res->get_error_code() );
		$this->assertSame( '0', $this->stored()['page_for_posts'] );
	}

	public function test_front_and_posts_page_must_differ(): void {
		$page = $this->page();

		$res = $this->update(
			array(
				'show_on_front'  => 'page',
				'page_on_front'  => $page,
				'page_for_posts' => $page,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_homepage_same_page', $res->get_error_code() );

		// Against the stored value too: the front page is already set, and only the posts page is sent.
		update_option( 'page_on_front', $page );
		$res = $this->update( array( 'page_for_posts' => $page ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_homepage_same_page', $res->get_error_code() );
		$this->assertSame( '0', $this->stored()['page_for_posts'] );
	}

	public function test_zero_clears_a_page_setting(): void {
		update_option( 'page_for_posts', $this->page() );

		$res = $this->update( array( 'page_for_posts' => 0 ) );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( '0', $this->stored()['page_for_posts'] );
		$this->assertSame( array( 'page_for_posts' ), $res['landed'] );
	}

	public function test_show_on_front_page_without_a_front_page_is_refused(): void {
		$res = $this->update( array( 'show_on_front' => 'page' ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_homepage_no_front_page', $res->get_error_code() );

		$bad = $this->update( array( 'show_on_front' => 'blog' ) );
		$this->assertInstanceOf( WP_Error::class, $bad, 'Only posts and page are accepted.' );
		$this->assertSame( 'posts', $this->stored()['show_on_front'] );
	}

	public function test_partial_failure_returns_error_listing_landed_and_failed(): void {
		$front = $this->page();
		$blog  = $this->page();
		// The site keeps its old posts page: core's update_option() then reports nothing changed.
		add_filter( 'pre_update_option_page_for_posts', static fn( $value, $old ) => $old, 10, 2 );

		$res = $this->update(
			array(
				'show_on_front'  => 'page',
				'page_on_front'  => $front,
				'page_for_posts' => $blog,
			)
		);
		remove_all_filters( 'pre_update_option_page_for_posts' );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_settings_partial', $res->get_error_code() );
		$data = $res->get_error_data();
		$this->assertSame( array( 'page_on_front' ), $data['landed'] );
		$this->assertSame( array( 'page_for_posts', 'show_on_front' ), $data['failed'] );
		$this->assertStringContainsString( 'page_on_front', $res->get_error_message() );
		$this->assertSame( 'posts', $this->stored()['show_on_front'], 'show_on_front is never written after an earlier key failed.' );
		$this->assertSame( (string) $front, $this->stored()['page_on_front'] );
	}

	public function test_keys_are_written_in_the_fixed_order(): void {
		$front = $this->page();
		$blog  = $this->page();
		$order = array();
		$spy   = static function ( string $option ) use ( &$order ): void {
			if ( in_array( $option, array( 'show_on_front', 'page_on_front', 'page_for_posts', 'blogname' ), true ) ) {
				$order[] = $option;
			}
		};
		add_action( 'update_option', $spy );

		$res = $this->update(
			array(
				'show_on_front'  => 'page',
				'blogname'       => 'Ordered',
				'page_for_posts' => $blog,
				'page_on_front'  => $front,
			)
		);
		remove_action( 'update_option', $spy );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( array( 'blogname', 'page_on_front', 'page_for_posts', 'show_on_front' ), $order );
	}

	public function test_unchanged_keys_are_listed_as_unchanged(): void {
		$front = $this->page();
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );

		$res = $this->update(
			array(
				'show_on_front' => 'page',
				'page_on_front' => $front,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( array(), $res['landed'] );
		$this->assertSame( array( 'page_on_front', 'show_on_front' ), $res['unchanged'] );
	}

	public function test_unlisted_core_options_still_refused(): void {
		foreach ( array( 'siteurl', 'home', 'admin_email', 'default_role', 'users_can_register', 'page_comments', 'sticky_posts' ) as $key ) {
			$res = $this->update( array( $key => '1' ) );
			$this->assertInstanceOf( WP_Error::class, $res, $key );
			$this->assertSame( 'aafm_setting_not_allowed', $res->get_error_code(), $key );
		}
	}

	public function test_an_escaped_site_name_beside_the_homepage_keys_all_land(): void {
		$front = $this->page();
		$blog  = $this->page();

		$res = $this->update(
			array(
				'blogname'       => "Joe's Bakery & Cafe",
				'page_on_front'  => $front,
				'page_for_posts' => $blog,
				'show_on_front'  => 'page',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertSame( array( 'blogname', 'page_on_front', 'page_for_posts', 'show_on_front' ), $res['landed'] );
		$this->assertSame( 'page', $this->stored()['show_on_front'] );
	}

	public function test_a_failed_non_homepage_key_does_not_stop_the_homepage_keys(): void {
		$front = $this->page();
		add_filter( 'pre_update_option_blogdescription', static fn( $value, $old ) => $old, 10, 2 );

		$res = $this->update(
			array(
				'blogdescription' => 'A new tagline',
				'page_on_front'   => $front,
				'show_on_front'   => 'page',
			)
		);
		remove_all_filters( 'pre_update_option_blogdescription' );

		$this->assertInstanceOf( WP_Error::class, $res, 'A key that did not land is still reported.' );
		$data = $res->get_error_data();
		$this->assertSame( array( 'blogdescription' ), $data['failed'] );
		$this->assertSame( array( 'page_on_front', 'show_on_front' ), $data['landed'] );
		$this->assertSame( 'page', $this->stored()['show_on_front'] );
	}

	public function test_show_on_front_page_alone_checks_the_stored_front_page_is_published(): void {
		$front = $this->page();
		update_option( 'page_on_front', $front );
		wp_update_post(
			array(
				'ID'          => $front,
				'post_status' => 'draft',
			)
		);

		$res = $this->update( array( 'show_on_front' => 'page' ) );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_homepage_not_a_published_page', $res->get_error_code() );
		$this->assertSame( 'posts', $this->stored()['show_on_front'] );
	}

	public function test_output_schema_declares_the_report_lists(): void {
		$properties = aafm_args_update_site_settings()['output_schema']['properties'];
		foreach ( array( 'landed', 'unchanged', 'failed' ) as $key ) {
			$this->assertSame( 'array', $properties[ $key ]['type'], $key );
		}
	}
}
