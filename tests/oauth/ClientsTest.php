<?php
/**
 * Tests for the OAuth dynamic client registry.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\OAuth;

use AAFM\Tests\TestCase;
use WP_Error;

/**
 * Verifies client registration validates redirect URIs and persists a public client.
 */
class ClientsTest extends TestCase {

	/**
	 * Read a client row back by its public client_id.
	 *
	 * The WordPress test suite rewrites plugin `CREATE TABLE` to its `TEMPORARY`
	 * form, so each DB test must call aafm_install_oauth_tables() first and then
	 * select the row back — the temporary table is invisible to `SHOW TABLES`.
	 *
	 * @param string $client_id The public client identifier.
	 * @return array<string,mixed>|null
	 */
	private function fetch_client( string $client_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				"SELECT * FROM {$wpdb->prefix}aafm_oauth_clients WHERE client_id = %s",
				$client_id
			),
			ARRAY_A
		);
		return $row;
	}

	/**
	 * A valid https redirect registers and persists a row with a 32-hex client_id.
	 */
	public function test_registers_valid_client(): void {
		aafm_install_oauth_tables();

		$res = aafm_oauth_register_client(
			array(
				'redirect_uris' => array( 'https://app.example/cb' ),
				'client_name'   => 'Test',
			)
		);

		$this->assertIsArray( $res );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $res['client_id'] );

		$row = $this->fetch_client( $res['client_id'] );
		$this->assertNotNull( $row );
		$this->assertSame( $res['client_id'], $row['client_id'] );
		$this->assertSame( 'Test', $row['client_name'] );
	}

	/**
	 * A plain http non-localhost redirect is rejected.
	 */
	public function test_rejects_insecure_redirect(): void {
		aafm_install_oauth_tables();

		$res = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'http://app.example/cb' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * The http://localhost host is accepted under the loopback exception.
	 */
	public function test_accepts_http_localhost_redirect(): void {
		aafm_install_oauth_tables();

		$res = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'http://localhost/cb' ) )
		);

		$this->assertIsArray( $res );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $res['client_id'] );
	}

	/**
	 * The http://127.0.0.1 host is accepted under the loopback exception.
	 */
	public function test_accepts_http_loopback_ip_redirect(): void {
		aafm_install_oauth_tables();

		$res = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'http://127.0.0.1/cb' ) )
		);

		$this->assertIsArray( $res );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $res['client_id'] );
	}

	/**
	 * A javascript: scheme redirect is rejected.
	 */
	public function test_rejects_javascript_scheme(): void {
		aafm_install_oauth_tables();

		$res = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'javascript:alert(1)' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * The data: and file: scheme redirects are rejected.
	 */
	public function test_rejects_data_and_file_schemes(): void {
		aafm_install_oauth_tables();

		$data = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'data:text/html,<h1>x</h1>' ) )
		);
		$this->assertInstanceOf( WP_Error::class, $data );

		$file = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'file:///etc/passwd' ) )
		);
		$this->assertInstanceOf( WP_Error::class, $file );
	}

	/**
	 * A redirect URI carrying a fragment is rejected.
	 */
	public function test_rejects_fragment(): void {
		aafm_install_oauth_tables();

		$res = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'https://app.example/cb#section' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * A redirect URI carrying userinfo is rejected.
	 */
	public function test_rejects_userinfo(): void {
		aafm_install_oauth_tables();

		$res = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'https://user:pass@app.example/cb' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * A redirect URI carrying a CRLF sequence is rejected.
	 *
	 * The wp_parse_url() call strips control characters before parsing, so the host
	 * would validate clean while the raw string we persist still carries the CR/LF —
	 * a header-splitting / open-redirect seed. Registration must reject it outright.
	 */
	public function test_rejects_crlf_in_redirect_uri(): void {
		aafm_install_oauth_tables();

		$res = aafm_oauth_register_client(
			array( 'redirect_uris' => array( "https://app.example/cb\r\nLocation: https://evil.com" ) )
		);

		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * A redirect URI carrying a bare tab or newline control char is rejected.
	 */
	public function test_rejects_bare_control_chars_in_redirect_uri(): void {
		aafm_install_oauth_tables();

		$tab = aafm_oauth_register_client(
			array( 'redirect_uris' => array( "https://app.example/cb\tpath" ) )
		);
		$this->assertInstanceOf( WP_Error::class, $tab );

		$newline = aafm_oauth_register_client(
			array( 'redirect_uris' => array( "https://app.example/cb\npath" ) )
		);
		$this->assertInstanceOf( WP_Error::class, $newline );
	}

	/**
	 * A redirect URI containing a wildcard is rejected.
	 */
	public function test_rejects_wildcard(): void {
		aafm_install_oauth_tables();

		$res = aafm_oauth_register_client(
			array( 'redirect_uris' => array( 'https://*.app.example/cb' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * More than ten redirect URIs is rejected.
	 */
	public function test_rejects_too_many_redirect_uris(): void {
		aafm_install_oauth_tables();

		$uris = array();
		for ( $i = 0; $i < 11; $i++ ) {
			$uris[] = 'https://app.example/cb' . $i;
		}

		$res = aafm_oauth_register_client( array( 'redirect_uris' => $uris ) );

		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * A missing or empty redirect_uris list is rejected.
	 */
	public function test_rejects_empty_redirect_uris(): void {
		aafm_install_oauth_tables();

		$missing = aafm_oauth_register_client( array( 'client_name' => 'No URIs' ) );
		$this->assertInstanceOf( WP_Error::class, $missing );

		$empty = aafm_oauth_register_client( array( 'redirect_uris' => array() ) );
		$this->assertInstanceOf( WP_Error::class, $empty );
	}
}
