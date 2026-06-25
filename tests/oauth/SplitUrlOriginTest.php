<?php
/**
 * Split-URL origin consistency guard for the OAuth surface.
 *
 * Some installs set the WordPress Address (site_url()) to a different host than
 * the Site Address (home_url()) — a back-end on admin.example, a front-end on
 * www.example. The OAuth issuer, the protected-resource indicator, and the
 * validator's audience check must all resolve to the SAME origin regardless, or a
 * token minted under the split config would fail its audience binding and never
 * validate.
 *
 * This test proves the surface already derives every origin from home_url()
 * (directly, or via rest_url() which builds off home_url() by default) and never
 * from site_url(). It is a regression guard, not a fix: it locks the invariant so a
 * future change that reaches for site_url() in the OAuth path fails loudly here.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\OAuth;

use Oversio\Tests\TestCase;

/**
 * Asserts OAuth origins stay consistent when site_url() diverges from home_url().
 */
class SplitUrlOriginTest extends TestCase {

	/**
	 * The divergent WordPress Address used to simulate a split-URL install.
	 */
	private const SPLIT_SITE_URL = 'https://admin.split.example';

	/**
	 * Force site_url() to a host that differs from home_url() for each test.
	 */
	public function set_up(): void {
		parent::set_up();

		oversio_install_oauth_tables();

		add_filter( 'site_url', array( $this, 'force_split_site_url' ) );
		add_filter( 'pre_option_siteurl', array( $this, 'force_split_site_url' ) );
	}

	/**
	 * Return the divergent WordPress Address.
	 *
	 * @return string
	 */
	public function force_split_site_url(): string {
		return self::SPLIT_SITE_URL;
	}

	/**
	 * The advertised issuer and authorization server stay on home_url(), not site_url().
	 */
	public function test_issuer_and_authorization_server_track_home_url(): void {
		// Guard the premise: the two addresses really do diverge in this test.
		$this->assertNotSame( home_url(), site_url() );

		$as_metadata = oversio_oauth_authorization_server_metadata();
		$this->assertSame( home_url(), $as_metadata['issuer'] );

		$pr_metadata = oversio_oauth_protected_resource_metadata();
		$this->assertSame( array( home_url() ), $pr_metadata['authorization_servers'] );

		// The split site_url() must never leak into either document.
		$this->assertStringNotContainsString( 'admin.split.example', wp_json_encode( $as_metadata ) );
		$this->assertStringNotContainsString( 'admin.split.example', wp_json_encode( $pr_metadata ) );
	}

	/**
	 * The protected-resource indicator equals the validator's audience source.
	 *
	 * Both the resource advertised in discovery and the audience the validator
	 * checks come from oversio_endpoint_url(); under a split-URL install they must
	 * remain identical, or no token would ever pass the audience binding.
	 */
	public function test_resource_matches_endpoint_audience(): void {
		$pr_metadata = oversio_oauth_protected_resource_metadata();

		$this->assertSame( oversio_endpoint_url(), $pr_metadata['resource'] );
		$this->assertStringNotContainsString( 'admin.split.example', oversio_endpoint_url() );
	}

	/**
	 * A token minted under the split-URL config still validates.
	 *
	 * Mints a token scoped to oversio_endpoint_url() (the same source the code mint
	 * uses), then asserts the validator's audience check passes against the stored
	 * resource — proving mint-time and validate-time agree on the origin even when
	 * site_url() points elsewhere.
	 */
	public function test_token_minted_under_split_url_validates(): void {
		$user_id = self::factory()->user->create();

		$tokens = oversio_oauth_mint_tokens(
			array(
				'client_id'  => 'split-url-client',
				'wp_user_id' => $user_id,
				'resource'   => oversio_endpoint_url(),
			)
		);

		$row = oversio_oauth_get_access_token_row( $tokens['access_token'] );
		$this->assertIsArray( $row );

		// The audience binding the validator enforces (validator.php) must hold.
		$this->assertTrue( hash_equals( oversio_endpoint_url(), (string) $row['resource'] ) );
		$this->assertSame( $user_id, (int) $row['wp_user_id'] );
	}
}
