<?php
/**
 * Negative redaction proofs: assert the DANGEROUS fields are ABSENT from every
 * agent-facing shape, not merely that the safe fields are present.
 *
 * The competitor CVEs this plugin exists to avoid were leaks - emails, logins,
 * password hashes, IPs, absolute paths, post passwords, WP/PHP versions, the
 * admin email. Each redactor here is asserted to omit those keys and to not
 * carry their values anywhere in the serialized payload.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Comment;
use WP_Post;
use WP_Term;
use WP_User;

final class RedactionProofsTest extends TestCase {

	/**
	 * Assert none of the given substrings appear anywhere in the JSON payload.
	 *
	 * @param array<int,string>   $needles Forbidden substrings.
	 * @param array<string,mixed> $shape   The redacted shape.
	 */
	private function assertNoneLeak( array $needles, array $shape ): void {
		$json = (string) wp_json_encode( $shape );
		foreach ( $needles as $needle ) {
			if ( '' === $needle ) {
				continue;
			}
			$this->assertStringNotContainsString(
				$needle,
				$json,
				sprintf( 'Redacted shape leaked "%s": %s', $needle, $json )
			);
		}
	}

	public function test_redact_post_omits_password_and_raw_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'TopSecretPass123',
				'post_content'  => 'Body with <script>alert(1)</script> and SECRETMARKER.',
			)
		);
		$shape   = aafm_redact_post( get_post( $post_id ) );

		$this->assertArrayNotHasKey( 'post_password', $shape );
		$this->assertArrayNotHasKey( 'content', $shape );
		$this->assertArrayNotHasKey( 'comment_count', $shape );
		$this->assertArrayNotHasKey( 'ping_status', $shape );
		// The post_password value must never appear anywhere in the payload.
		$this->assertNoneLeak( array( 'TopSecretPass123' ), $shape );
		// Only the whitelisted keys are present.
		$this->assertSame(
			array( 'id', 'title', 'status', 'type', 'slug', 'excerpt', 'link', 'author_id', 'date_gmt', 'modified_gmt' ),
			array_keys( $shape )
		);
	}

	public function test_redact_user_exposes_email_but_omits_login_and_pass(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'          => 'author',
				'user_login'    => 'secretlogin',
				'user_email'    => 'leak@example.com',
				'user_pass'     => 'hunter2hunter2',
				'user_nicename' => 'secretnice',
				// display_name is deliberately unrelated to the login so the
				// "login value absent" assertion below is meaningful, not an
				// artifact of display_name happening to echo the login.
				'display_name'  => 'Public Display Name',
			)
		);
		$user    = new WP_User( $user_id );
		$shape   = aafm_redact_user( $user );

		// LOCKED reversal (47- line 144): email IS exposed in the user read shape,
		// gated upstream by list_users + audited.
		$this->assertSame( 'leak@example.com', $shape['email'] ?? null );

		// Login, password, registration date, URL, and capabilities stay out of the lean shape.
		foreach ( array( 'user_email', 'user_login', 'login', 'user_pass', 'pass', 'user_registered', 'user_url', 'allcaps' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $shape );
		}
		$this->assertNoneLeak(
			array( 'secretlogin', 'hunter2hunter2' ),
			$shape
		);
		$this->assertSame( array( 'id', 'display_name', 'email', 'roles', 'post_count' ), array_keys( $shape ) );
	}

	/**
	 * Task 17 (1.6.1): this test used to assert `assertSame( array(), aafm_redact_user( false ) )`
	 * - the PHP array() this branch returned before the fix. That value encodes as [] against
	 * the 'user' => object declarations (users.php:170, :322, :465), so the assertion was
	 * pinning the bug rather than proving anything safe. Flipped to assert the corrected
	 * (object) array() return - see OutputSchemaFidelityTest for the wire-encoding proof.
	 */
	public function test_redact_user_on_non_user_returns_an_empty_object(): void {
		$this->assertEquals( (object) array(), aafm_redact_user( false ) );
	}

	public function test_redact_comment_omits_email_ip_and_agent(): void {
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Jane Public',
				'comment_author_email' => 'jane@private.example',
				'comment_author_IP'    => '203.0.113.42',
				'comment_author_url'   => 'http://spam.example',
				'comment_agent'        => 'EvilBot/9000',
				'comment_content'      => 'Hello there',
			)
		);
		$shape      = aafm_redact_comment( get_comment( $comment_id ) );

		foreach ( array( 'comment_author_email', 'comment_author_IP', 'comment_author_url', 'comment_agent', 'author_email', 'author_ip', 'author_url' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $shape );
		}
		$this->assertNoneLeak(
			array( 'jane@private.example', '203.0.113.42', 'EvilBot/9000', 'http://spam.example' ),
			$shape
		);
		$this->assertSame(
			array( 'id', 'post_id', 'author_name', 'content', 'status', 'date_gmt', 'parent' ),
			array_keys( $shape )
		);
	}

	public function test_redact_comment_on_non_comment_returns_empty(): void {
		$this->assertSame( array(), aafm_redact_comment( null ) );
	}

	public function test_redact_term_exposes_only_safe_fields(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Safe Term',
			)
		);
		$shape   = aafm_redact_term( get_term( $term_id, 'category' ) );
		$this->assertArrayNotHasKey( 'term_taxonomy_id', $shape );
		$this->assertArrayNotHasKey( 'filter', $shape );
		$this->assertSame(
			array( 'id', 'name', 'slug', 'taxonomy', 'parent', 'count', 'description' ),
			array_keys( $shape )
		);
	}

	public function test_redact_media_omits_absolute_path(): void {
		$att   = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);
		$file  = (string) get_attached_file( $att );
		$shape = aafm_redact_media( get_post( $att ) );

		$this->assertArrayNotHasKey( '_wp_attached_file', $shape );
		$this->assertArrayNotHasKey( 'path', $shape );
		$this->assertArrayNotHasKey( 'file', $shape );
		// The absolute server path must never appear in the inventory shape.
		if ( '' !== $file ) {
			$this->assertNoneLeak( array( $file ), $shape );
		}
		$this->assertSame(
			array( 'id', 'title', 'mime_type', 'url', 'alt', 'width', 'height' ),
			array_keys( $shape )
		);

		if ( '' !== $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	public function test_redact_media_null_dimensions_for_non_image(): void {
		// An attachment with no metadata yields null width/height, not a fatal.
		$att   = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
			)
		);
		$shape = aafm_redact_media( get_post( $att ) );
		$this->assertNull( $shape['width'] );
		$this->assertNull( $shape['height'] );
	}

	public function test_site_info_hides_environment_and_admin_email(): void {
		// Drive the execute callback directly; assert the leaked-by-competitors fields
		// are absent from the descriptor.
		$shape = aafm_exec_get_site_info();
		$this->assertArrayHasKey( 'site', $shape );
		$site = $shape['site'];

		foreach ( array( 'version', 'php_version', 'admin_email', 'debug', 'wp_version', 'server', 'plugins', 'theme', 'path', 'abspath' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $site );
		}
		$this->assertSame( array( 'name', 'tagline', 'url', 'language' ), array_keys( $site ) );

		$admin_email = (string) get_option( 'admin_email' );
		$wp_version  = (string) get_bloginfo( 'version' );
		$this->assertNoneLeak(
			array_filter( array( $admin_email, $wp_version, (string) PHP_VERSION, (string) ABSPATH ) ),
			$site
		);
	}

	// =========================================================================
	// aafm_wc_redact_settings_deep() - the denylist behind every gateway
	// settings shape and every shipping-method instance-settings row. A pure
	// array walk: no WooCommerce stub needed.
	//
	// The redactor MARKS a matching key, it no longer drops it. Dropping meant a
	// caller could not tell "not configured" from "withheld", so a benign field
	// caught by a loose pattern simply vanished and the agent answered questions
	// about it wrongly. These assertions therefore check BOTH halves - the key
	// survives AND its value is gone - which is strictly stronger than the
	// assertArrayNotHasKey they replaced, since absence alone never proved the
	// secret had not been returned somewhere else in the shape.
	// =========================================================================

	/**
	 * Every key in the provider must be withheld, at any depth, and still be visible as a key.
	 *
	 * @dataProvider sensitive_setting_keys
	 *
	 * @param string $key The settings key expected to be redacted.
	 */
	public function test_sensitive_settings_keys_are_redacted( string $key ): void {
		$redacted = aafm_wc_redact_settings_deep(
			array(
				$key    => 'super-secret-value',
				'title' => 'Flat rate',
			)
		);

		$this->assertArrayHasKey( $key, $redacted, sprintf( 'The key "%s" must still be visible, so the caller can tell withheld from absent.', $key ) );
		$this->assertSame( aafm_wc_redaction_marker(), $redacted[ $key ], sprintf( 'The value of "%s" must be the marker.', $key ) );
		$this->assertNotSame( 'super-secret-value', $redacted[ $key ] );
	}

	/**
	 * The whole contract, not only the keys the widening added: some of these
	 * (password, license_key via "key") already passed before 1.6.2 and sit
	 * here as regression pins.
	 *
	 * @return array<string,array{string}>
	 */
	public function sensitive_setting_keys(): array {
		return array(
			'password'       => array( 'password' ),
			'passwd'         => array( 'passwd' ),
			'pass'           => array( 'pass' ),
			'account_number' => array( 'account_number' ),
			'merchant_id'    => array( 'merchant_id' ),
			'license'        => array( 'license' ),
			'license_key'    => array( 'license_key' ),
			'username'       => array( 'username' ),
		);
	}

	public function test_ordinary_settings_survive_redaction(): void {
		$settings = array(
			'title'      => 'Flat rate',
			'cost'       => '5.00',
			'tax_status' => 'taxable',
		);

		$this->assertSame( $settings, aafm_wc_redact_settings_deep( $settings ) );
	}

	public function test_nested_sensitive_keys_are_redacted(): void {
		$redacted = aafm_wc_redact_settings_deep(
			array(
				'carrier' => array(
					'passwd' => 'x',
					'label'  => 'DHL',
				),
			)
		);

		$this->assertSame( aafm_wc_redaction_marker(), $redacted['carrier']['passwd'] );
		$this->assertSame( 'DHL', $redacted['carrier']['label'] );
	}

	/**
	 * The names two sim lanes proved leak on a REAL gateway. iban, bic and sort_code are core
	 * WooCommerce's own bacs fields, present on a default install before anything is touched.
	 *
	 * @dataProvider newly_covered_secret_keys
	 *
	 * @param string $key The settings key that used to be returned in full.
	 */
	public function test_credential_shaped_keys_proven_to_leak_are_now_redacted( string $key ): void {
		$redacted = aafm_wc_redact_settings_deep( array( $key => 'SENTINEL-LIVE-VALUE' ) );

		$this->assertSame(
			aafm_wc_redaction_marker(),
			$redacted[ $key ],
			sprintf( 'The key "%s" was returned in full by the shipped redactor.', $key )
		);
	}

	/**
	 * Cases: credential-shaped field names the sim seeded and read back intact.
	 *
	 * @return array<string,array{string}>
	 */
	public function newly_covered_secret_keys(): array {
		$keys  = array(
			'passcode',
			'hmac',
			'seed',
			'iv',
			'cvv',
			'security_code',
			'iban',
			'bic',
			'swift',
			'sort_code',
			'routing',
			'bank_details',
			'epin',
			'terminal_id',
			'mid',
			'x_login',
		);
		$cases = array();
		foreach ( $keys as $key ) {
			$cases[ $key ] = array( $key );
		}
		return $cases;
	}

	/**
	 * The over-redaction half: a benign key caught by the loose pattern must never VANISH.
	 *
	 * Losing the key is what produced a concrete wrong answer. Asked whether a carrier method
	 * required a signature on delivery, the agent saw no signature_required key and reported that
	 * no such setting existed, when it existed and was set to yes.
	 *
	 * @dataProvider benign_keys_that_used_to_vanish
	 *
	 * @param string $key A benign settings key.
	 */
	public function test_benign_keys_are_never_dropped( string $key ): void {
		$redacted = aafm_wc_redact_settings_deep( array( $key => 'benign-value' ) );

		$this->assertArrayHasKey(
			$key,
			$redacted,
			sprintf( 'The benign key "%s" must survive; dropping it tells the caller the setting does not exist.', $key )
		);
	}

	/**
	 * Cases: benign names the shipped pattern silently deleted.
	 *
	 * @return array<string,array{string}>
	 */
	public function benign_keys_that_used_to_vanish(): array {
		$keys  = array(
			'signature_required',
			'consignment_note',
			'requires_signoff',
			'design_template',
			'rapid_dispatch',
			'capital_city_only',
			'author',
			'monkey_bars',
		);
		$cases = array();
		foreach ( $keys as $key ) {
			$cases[ $key ] = array( $key );
		}
		return $cases;
	}

	/**
	 * The tightened words must no longer fire on an ordinary English word that merely contains
	 * them, which is what made the loose group delete benign settings.
	 *
	 * @return void
	 */
	/**
	 * R6-6: a real setting whose value IS the marker must be distinguishable from a withheld one.
	 *
	 * The marker lives in the same arbitrary-string domain as real values, so it can always be
	 * forged by accident. The out-of-band path list is the authoritative signal, and this is the
	 * assertion that proves it: both fields read "[redacted]", and only one is listed.
	 */
	public function test_a_real_redacted_string_is_distinguishable_from_a_withheld_field(): void {
		$report = aafm_wc_redact_settings_report(
			array(
				'instructions' => '[redacted]',
				'api_key'      => 'live_sk_real_secret',
			)
		);

		$this->assertSame( '[redacted]', $report['settings']['instructions'], 'The real value is returned untouched.' );
		$this->assertSame( aafm_wc_redaction_marker(), $report['settings']['api_key'], 'The secret is withheld.' );

		$this->assertNotContains(
			'instructions',
			$report['redacted'],
			'A benign field that merely holds the marker string must NOT be reported as withheld.'
		);
		$this->assertContains( 'api_key', $report['redacted'], 'The withheld field must be reported.' );
	}

	/**
	 * R6-6: nested withheld fields are reported by their full dot path, and benign siblings survive.
	 */
	public function test_nested_withheld_fields_are_reported_by_path(): void {
		$report = aafm_wc_redact_settings_report(
			array(
				'advanced' => array(
					'live' => array(
						'passcode' => 'x',
						'label'    => 'Live',
					),
				),
			)
		);

		$this->assertSame( array( 'advanced.live.passcode' ), $report['redacted'] );
		$this->assertSame( 'Live', $report['settings']['advanced']['live']['label'] );
	}

	/**
	 * R6-6: the two tokens this release added and got wrong are compounds only now.
	 *
	 * The names security_badge and terminal_display are ordinary UI configuration. Marking them
	 * withheld an answer the operator actually wanted. The credential compounds those tokens exist for must
	 * still be caught, which is the other half of the assertion.
	 *
	 * @dataProvider provide_tightened_token_cases
	 *
	 * @param string $key    Settings key.
	 * @param bool   $secret Whether it must be treated as a secret.
	 */
	public function test_security_and_terminal_are_compounds_only( string $key, bool $secret ): void {
		$this->assertSame(
			$secret,
			aafm_wc_settings_key_is_secret( $key ),
			sprintf( '"%s" should %sbe treated as a secret.', $key, $secret ? '' : 'NOT ' )
		);
	}

	/**
	 * Cases: benign UI names versus the credential compounds.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provide_tightened_token_cases(): array {
		return array(
			'security_badge'   => array( 'security_badge', false ),
			'terminal_display' => array( 'terminal_display', false ),
			'security_code'    => array( 'security_code', true ),
			'security_key'     => array( 'security_key', true ),
			'terminal_id'      => array( 'terminal_id', true ),
			'terminal_secret'  => array( 'terminal_secret', true ),
		);
	}

	/**
	 * The four tokens deliberately left broad stay broad.
	 *
	 * The tokens user and number predate this release, and a considered decision keeps them wide
	 * rather than risk un-redacting something an earlier review relied on being caught. bank and login are new
	 * but genuinely credential-flavoured. Pinned so a later narrowing has to argue with this test
	 * rather than slip through as tidying.
	 *
	 * @return void
	 */
	public function test_the_deliberately_broad_tokens_stay_broad(): void {
		foreach ( array( 'user', 'account_number', 'bank_details', 'x_login' ) as $key ) {
			$this->assertTrue(
				aafm_wc_settings_key_is_secret( $key ),
				sprintf( '"%s" is deliberately caught; narrowing it trades a withheld benign value for a possible leak.', $key )
			);
		}
	}

	public function test_tightened_words_no_longer_match_mid_word(): void {
		foreach ( array( 'monkey_bars', 'rapid_dispatch', 'design_template', 'author', 'capital_city_only' ) as $benign ) {
			$this->assertFalse(
				aafm_wc_settings_key_is_secret( $benign ),
				sprintf( '"%s" is not a secret; key/api/sign/auth are anchored now precisely so it does not match.', $benign )
			);
		}
		foreach ( array( 'api_key', 'apikey', 'account_number', 'auth_token', 'sign_key' ) as $secret ) {
			$this->assertTrue(
				aafm_wc_settings_key_is_secret( $secret ),
				sprintf( 'Anchoring must not have cost us "%s".', $secret )
			);
		}
	}
}
