<?php
/**
 * The settings-redaction corpus: every key name any version of this denylist has classified,
 * and every property the out-of-band withheld-fields report has ever been asked to hold.
 *
 * ROWS IN THIS FILE ARE APPEND-ONLY. NEVER DELETE ONE.
 *
 * Same reasoning as the replace-in-post corpus next door, learned from the same failure. This
 * denylist has now been rewritten three times: widened when a traffic sim read real bank fields
 * back off a live gateway, narrowed when the widening started marking a logo and a piece of button
 * copy, and re-shaped when the withheld-fields report turned out not to be parseable. Each pass
 * pinned only what the round in front of it had found, so a later pass could drop an earlier one's
 * coverage with every gate green.
 *
 * This file is the union instead. Each row carries where it came from, and the rows are pinned
 * together so the next rewrite fails loudly rather than quietly shipping a hole. If you are here to
 * change the pattern: add rows, do not remove them. A row you cannot satisfy is a conversation to
 * have, not a line to delete.
 *
 * Both directions are held. A denylist that only ever proves it catches secrets is half a test -
 * over-redaction is what produced the concrete wrong answer this release started from, where an
 * agent was asked whether a carrier method required a signature on delivery, saw no such key, and
 * reported that no such setting existed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class GatewayRedactionCorpusTest extends TestCase {

	/**
	 * Every key name, with the classification it must have.
	 *
	 * Each row is [ key, is_secret ]. `true` means the value must be withheld; `false` means the
	 * value must be returned in full.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provide_key_classifications(): array {
		$rows = array(
			// -----------------------------------------------------------------
			// 1.6.2 baseline. These passed before any of this release's changes
			// and sit here as regression pins.
			// -----------------------------------------------------------------
			'1.6.2 password'                  => array( 'password', true ),
			'1.6.2 passwd'                    => array( 'passwd', true ),
			'1.6.2 pass'                      => array( 'pass', true ),
			'1.6.2 account_number'            => array( 'account_number', true ),
			'1.6.2 merchant_id'               => array( 'merchant_id', true ),
			'1.6.2 license'                   => array( 'license', true ),
			'1.6.2 license_key'               => array( 'license_key', true ),
			'1.6.2 username'                  => array( 'username', true ),
			'1.6.2 api_key'                   => array( 'api_key', true ),
			'1.6.2 apikey'                    => array( 'apikey', true ),
			'1.6.2 auth_token'                => array( 'auth_token', true ),
			'1.6.2 sign_key'                  => array( 'sign_key', true ),

			// -----------------------------------------------------------------
			// The traffic sim, under-redaction half. Every one of these was
			// seeded on a real gateway and read back IN FULL. iban, bic and
			// sort_code are core WooCommerce's own bacs fields, present on a
			// default install before anything is touched.
			// -----------------------------------------------------------------
			'sim leak passcode'               => array( 'passcode', true ),
			'sim leak hmac'                   => array( 'hmac', true ),
			'sim leak seed'                   => array( 'seed', true ),
			'sim leak iv'                     => array( 'iv', true ),
			'sim leak cvv'                    => array( 'cvv', true ),
			'sim leak security_code'          => array( 'security_code', true ),
			'sim leak iban'                   => array( 'iban', true ),
			'sim leak bic'                    => array( 'bic', true ),
			'sim leak swift'                  => array( 'swift', true ),
			'sim leak sort_code'              => array( 'sort_code', true ),
			'sim leak routing'                => array( 'routing', true ),
			'sim leak bank_details'           => array( 'bank_details', true ),
			'sim leak epin'                   => array( 'epin', true ),
			'sim leak terminal_id'            => array( 'terminal_id', true ),
			'sim leak mid'                    => array( 'mid', true ),
			'sim leak x_login'                => array( 'x_login', true ),

			// -----------------------------------------------------------------
			// The traffic sim, over-redaction half: ordinary English words that
			// merely contain a credential substring. Unanchored, key matched
			// monkey_bars and api matched rapid_dispatch, and the matched field
			// was DELETED, so the caller could not tell withheld from absent.
			// -----------------------------------------------------------------
			'sim overreach monkey_bars'       => array( 'monkey_bars', false ),
			'sim overreach rapid_dispatch'    => array( 'rapid_dispatch', false ),
			'sim overreach design_template'   => array( 'design_template', false ),
			'sim overreach author'            => array( 'author', false ),
			'sim overreach capital_city_only' => array( 'capital_city_only', false ),
			'sim overreach consignment_note'  => array( 'consignment_note', false ),
			'sim overreach requires_signoff'  => array( 'requires_signoff', false ),

			// signature_required is the sim's own worked example, and it is
			// pinned as a SECRET deliberately. It matches the loose "signature"
			// token, which predates this release. The sim's complaint was never
			// that it matched - it was that matching DELETED it, so the agent
			// answered that no such setting existed. Marking it keeps the key
			// visible with its value withheld, which resolves that. Releasing
			// it would be a separate argument about the loose group, which no
			// round has made.
			'sim signature_required marked'   => array( 'signature_required', true ),

			// -----------------------------------------------------------------
			// R6-6: security and terminal became compounds only. Bare, this
			// release's own widening had marked a badge and a display setting.
			// -----------------------------------------------------------------
			'R6-6 security_badge released'    => array( 'security_badge', false ),
			'R6-6 terminal_display released'  => array( 'terminal_display', false ),
			'R6-6 security_code held'         => array( 'security_code', true ),
			'R6-6 security_key held'          => array( 'security_key', true ),
			'R6-6 terminal_id held'           => array( 'terminal_id', true ),
			'R6-6 terminal_secret held'       => array( 'terminal_secret', true ),

			// -----------------------------------------------------------------
			// R6-6: the tokens deliberately left broad. user and number predate
			// this release; narrowing them on a review's say-so trades a
			// withheld benign value for a possible leak, and those are not
			// symmetric.
			// -----------------------------------------------------------------
			'R6-6 broad user'                 => array( 'user', true ),
			'R6-6 broad account_number'       => array( 'account_number', true ),

			// -----------------------------------------------------------------
			// ROUND 7, this fix. bank and login stayed broad but stopped
			// marking names whose last segment says how a thing is displayed.
			// The two the reviewer named come first.
			// -----------------------------------------------------------------
			'R7 bank_logo released'           => array( 'bank_logo', false ),
			'R7 login_button_label released'  => array( 'login_button_label', false ),
			'R7 bank_title released'          => array( 'bank_title', false ),
			'R7 login_logo released'          => array( 'login_logo', false ),
			'R7 bank_transfer_note released'  => array( 'bank_transfer_note', false ),
			'R7 login_message released'       => array( 'login_message', false ),

			// The other direction, and the reason the fix is subtractive rather
			// than a narrowing: everything bank and login were ADDED to catch
			// must still be caught. bank_details and x_login are the two the sim
			// proved leak; the rest are the credential-shaped neighbours a
			// compound rewrite would have had to enumerate and would have missed.
			'R7 bank_details still held'      => array( 'bank_details', true ),
			'R7 x_login still held'           => array( 'x_login', true ),
			'R7 bare bank held'               => array( 'bank', true ),
			'R7 bare login held'              => array( 'login', true ),
			'R7 bank_account held'            => array( 'bank_account', true ),
			'R7 bank_iban held'               => array( 'bank_iban', true ),
			'R7 bank_reference held'          => array( 'bank_reference', true ),
			'R7 bank_name held'               => array( 'bank_name', true ),
			'R7 login_token held'             => array( 'login_token', true ),
			'R7 login_id held'                => array( 'login_id', true ),

			// A URL is deliberately NOT presentational. A secret carried in a
			// query string is the one failure mode the name denylist is already
			// documented as unable to see, so releasing a login URL by name
			// would open a hole exactly where the value-shaped hole already is.
			'R7 login_url held'               => array( 'login_url', true ),
			'R7 login_redirect held'          => array( 'login_redirect', true ),

			// The carve-out fires ONLY when bank or login was the sole
			// credential signal. Any other signal wins outright.
			'R7 bank_logo_password held'      => array( 'bank_logo_password', true ),
			'R7 bank_account_label held'      => array( 'bank_account_label', true ),

			// -----------------------------------------------------------------
			// Ordinary configuration, from the shapes these abilities actually
			// return on a default install. None of it may ever be withheld.
			// -----------------------------------------------------------------
			'ordinary title'                  => array( 'title', false ),
			'ordinary cost'                   => array( 'cost', false ),
			'ordinary tax_status'             => array( 'tax_status', false ),
			'ordinary instructions'           => array( 'instructions', false ),
			'ordinary enabled'                => array( 'enabled', false ),
			'ordinary description'            => array( 'description', false ),
			'ordinary sandbox'                => array( 'sandbox', false ),

			// -----------------------------------------------------------------
			// camelCase, from Codex round 8 (R8C-8). Until then this whole file
			// held not one camelCase row, which is exactly why a rewrite could
			// move bare `key`, `api`, `auth` and `sign` behind an underscore or
			// hyphen boundary with every gate green: a camelCase hump is not a
			// boundary, so twelve names that 1.6.3 withheld came back exposed.
			// Authorize.Net's own field names are in here for a reason.
			// -----------------------------------------------------------------
			'camel accessKey'                 => array( 'accessKey', true ),
			'camel publicKey'                 => array( 'publicKey', true ),
			'camel merchantKey'               => array( 'merchantKey', true ),
			'camel storeKey'                  => array( 'storeKey', true ),
			'camel liveKey'                   => array( 'liveKey', true ),
			'camel testKey'                   => array( 'testKey', true ),
			'camel publishableKey'            => array( 'publishableKey', true ),
			'camel transactionKey'            => array( 'transactionKey', true ),
			'camel apiLoginID'                => array( 'apiLoginID', true ),
			'camel authKey'                   => array( 'authKey', true ),
			'camel xAuthValue'                => array( 'xAuthValue', true ),
			'camel mySignValue'               => array( 'mySignValue', true ),
			'camel restApiUrl'                => array( 'restApiUrl', true ),
			'camel apiEndpoint'               => array( 'apiEndpoint', true ),
			'camel sharedSecret'              => array( 'sharedSecret', true ),
			'camel routingNumber'             => array( 'routingNumber', true ),

			// -----------------------------------------------------------------
			// Leading ACRONYMS, from the round 8 follow-up. Splitting only the
			// lower-to-upper hump leaves `SSLCertificate` untouched, so an
			// anchored-only token never sees a boundary and the key goes out in
			// full. `APIKey` and `APIToken` masked how bad this was: they
			// survive on the LOOSE `api[_-]?key` and bare `token`, so the hole
			// only shows on tokens that are anchored-only.
			// -----------------------------------------------------------------
			'acronym SSLCertificate'          => array( 'SSLCertificate', true ),
			'acronym MIDValue'                => array( 'MIDValue', true ),
			'acronym IBANNumber'              => array( 'IBANNumber', true ),
			'acronym APIKey'                  => array( 'APIKey', true ),
			'acronym APIToken'                => array( 'APIToken', true ),
			'acronym XAuthToken'              => array( 'XAuthToken', true ),
			'acronym OAuthClientID'           => array( 'OAuthClientID', true ),
			'acronym PINCode'                 => array( 'PINCode', true ),
			'acronym IVSeed'                  => array( 'IVSeed', true ),

			// The acronym pass must not start withholding presentation either.
			// `ssl` is in neither group, so this row moves if and only if the
			// split has begun over-matching.
			'acronym benign SSLEnabled'       => array( 'SSLEnabled', false ),

			// Deliberately WITHHELD, and not an over-block: `api` is an anchored
			// token, so `api_endpoint_label` is withheld today by the same rule.
			// Pinned so nobody later reads this as collateral from the acronym
			// pass and "fixes" it into a hole. Narrowing `api` is a separate
			// argument to have on its own merits, for both spellings at once.
			'acronym APIEndpointLabel'        => array( 'APIEndpointLabel', true ),

			// Released, and consistent: `https_proxy_url` is released today too,
			// because none of https, proxy or url is in either group. Here so a
			// future reader can tell a considered release from an oversight.
			'acronym benign HTTPSProxyURL'    => array( 'HTTPSProxyURL', false ),

			// The other direction, and it is the half that keeps the split
			// honest. Splitting humps must not start withholding presentation.
			'camel benign checkoutTitle'      => array( 'checkoutTitle', false ),
			'camel benign displayName'        => array( 'displayName', false ),
			'camel benign buttonText'         => array( 'buttonText', false ),
			'camel benign bankLogo'           => array( 'bankLogo', false ),
			'camel benign loginButtonLabel'   => array( 'loginButtonLabel', false ),
			'camel benign iconUrl'            => array( 'iconUrl', false ),
			'camel benign taxStatus'          => array( 'taxStatus', false ),
		);

		return $rows;
	}

	/**
	 * No row in this file was silently overwritten by a duplicate name.
	 *
	 * PHP collapses duplicate keys in an array literal at PARSE time, keeping the last one and
	 * discarding the earlier silently. So by the time a test can see the provider's return value the
	 * evidence is already gone: the row count is simply one lower than the file appears to declare,
	 * every remaining row still passes, and all four gates stay green. That is a corpus which
	 * structurally cannot report the one failure it exists to prevent, and it is the same shape as
	 * B2-02 attempt 2, which deleted attempt 1's protection with everything passing.
	 *
	 * So this counts the row names in the SOURCE and compares against what the provider actually
	 * returns. The source is the only place the duplicate is still visible.
	 *
	 * Reading __FILE__ in a test is unusual and deliberate. There is no way to ask PHP after the
	 * fact which keys it dropped, and an append-only file whose losses are invisible is worth one
	 * odd test.
	 */
	public function test_no_corpus_row_was_silently_overwritten(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this test's own source from disk; wp_remote_get() is for URLs and cannot do this.
		$source = file_get_contents( __FILE__ );
		$this->assertIsString( $source, 'The corpus source must be readable for this guard to mean anything.' );

		// Scope to THIS provider's body before counting. Other tests in this file build nested
		// settings fixtures that share the row shape (a quoted key, then `=> array( '`), and a
		// file-wide count silently borrows them: the first version of this guard read 107 against a
		// provider of 106 and accused a lost row that never existed. The lesson is the guard's own:
		// a count is only evidence if you know exactly what it counted.
		$start = strpos( $source, 'function provide_key_classifications' );
		$this->assertNotFalse( $start, 'Provider not found; fix this guard rather than deleting it.' );
		$end = strpos( $source, 'return $rows;', $start );
		$this->assertNotFalse( $end, 'Provider terminator not found; fix this guard rather than deleting it.' );

		$body = substr( $source, $start, $end - $start );

		preg_match_all( "/^[ \t]+'([^']+)'[ \t]*=> array\( '/m", $body, $matches );
		$declared = $matches[1];

		$this->assertNotEmpty(
			$declared,
			'The row pattern matched nothing, so this guard is vacuous. Fix the pattern, do not delete the test.'
		);

		$duplicates = array_keys(
			array_filter(
				array_count_values( $declared ),
				static function ( $count ) {
					return $count > 1;
				}
			)
		);

		$this->assertSame(
			array(),
			$duplicates,
			'These row names appear more than once, so the earlier row has been silently discarded: '
				. implode( ', ', $duplicates )
		);

		$this->assertCount(
			count( $declared ),
			self::provide_key_classifications(),
			'The provider returns fewer rows than the file declares, which means at least one was dropped.'
		);
	}

	/**
	 * The classifier's own contract, key by key.
	 *
	 * @dataProvider provide_key_classifications
	 *
	 * @param string $key    Settings key.
	 * @param bool   $secret Whether the value must be withheld.
	 */
	public function test_key_is_classified_as_expected( string $key, bool $secret ): void {
		$this->assertSame(
			$secret,
			aafm_wc_settings_key_is_secret( $key ),
			sprintf( '"%s" must %sbe treated as a secret.', $key, $secret ? '' : 'NOT ' )
		);
	}

	/**
	 * The same corpus through the actual walk, because the classifier agreeing is not the product.
	 *
	 * A withheld key keeps its place and loses its value; a released key keeps both. Losing the key
	 * is the failure that started this - absence reads as "not configured", which is a wrong answer
	 * rather than a withheld one.
	 *
	 * @dataProvider provide_key_classifications
	 *
	 * @param string $key    Settings key.
	 * @param bool   $secret Whether the value must be withheld.
	 */
	public function test_walk_withholds_or_returns_the_value( string $key, bool $secret ): void {
		$report = aafm_wc_redact_settings_report( array( $key => 'SENTINEL-LIVE-VALUE' ) );

		$this->assertArrayHasKey(
			$key,
			$report['settings'],
			sprintf( 'The key "%s" must survive; dropping it tells the caller the setting does not exist.', $key )
		);

		if ( $secret ) {
			$this->assertSame( aafm_wc_redaction_marker(), $report['settings'][ $key ] );
			$this->assertSame( array( array( $key ) ), $report['redacted'] );
			return;
		}

		$this->assertSame( 'SENTINEL-LIVE-VALUE', $report['settings'][ $key ] );
		$this->assertSame( array(), $report['redacted'] );
	}

	// =========================================================================
	// The withheld-fields report, and the properties it has been asked to hold.
	// =========================================================================

	/**
	 * ROUND 7: two structurally different settings arrays must never produce the same report.
	 *
	 * This is the defect, stated as a test. A settings key is an arbitrary array key, so it may
	 * contain a dot; the nested key "api_key" under "a" and a single flat key literally named
	 * "a.api_key" both used to report the string "a.api_key". A caller could not tell which field
	 * had been withheld, which makes the channel that is meant to be authoritative unparseable.
	 *
	 * Segments cannot collide by construction, and the assertion is equality against the exact
	 * expected structure rather than a "not equal to the other one" check, so it stays meaningful
	 * if either arm is later changed.
	 */
	public function test_a_key_containing_a_dot_is_not_confusable_with_a_nested_path(): void {
		$nested = aafm_wc_redact_settings_report(
			array(
				'a' => array(
					'b'       => 'benign',
					'api_key' => 'live_sk_nested',
				),
			)
		);
		$flat   = aafm_wc_redact_settings_report(
			array(
				'a.b'       => 'benign',
				'a.api_key' => 'live_sk_flat',
			)
		);

		$this->assertSame( array( array( 'a', 'api_key' ) ), $nested['redacted'] );
		$this->assertSame( array( array( 'a.api_key' ) ), $flat['redacted'] );
		$this->assertNotEquals(
			$nested['redacted'],
			$flat['redacted'],
			'Two different settings shapes must never report the same withheld path.'
		);
	}

	/**
	 * ROUND 7: the segments round-trip, so a caller can reach the exact value that was withheld.
	 *
	 * Reporting an unambiguous path is only half of it. The path has to actually index the returned
	 * settings, or the caller has an identifier they cannot use.
	 */
	public function test_every_reported_path_indexes_the_returned_settings(): void {
		$report = aafm_wc_redact_settings_report(
			array(
				'api_key'    => 'live_sk_top',
				'weird.name' => array( 'passcode' => 'live_sk_dotted_parent' ),
				'advanced'   => array(
					'live' => array(
						'passcode' => 'live_sk_deep',
						'label'    => 'Live',
					),
				),
			)
		);

		$this->assertCount( 3, $report['redacted'] );

		foreach ( $report['redacted'] as $segments ) {
			$cursor = $report['settings'];
			foreach ( $segments as $segment ) {
				$this->assertIsArray( $cursor, sprintf( 'Path %s ran off the end of the settings.', wp_json_encode( $segments ) ) );
				$this->assertArrayHasKey( $segment, $cursor, sprintf( 'Path %s does not index the settings.', wp_json_encode( $segments ) ) );
				$cursor = $cursor[ $segment ];
			}
			$this->assertSame( aafm_wc_redaction_marker(), $cursor, sprintf( 'Path %s does not land on a withheld value.', wp_json_encode( $segments ) ) );
		}

		$this->assertSame( 'Live', $report['settings']['advanced']['live']['label'], 'A benign sibling survives at depth.' );
	}

	/**
	 * R6-6: a real value that happens to BE the marker must not read as withheld.
	 *
	 * The marker sits in the same arbitrary-string domain as real data, so it can always be forged
	 * by accident. This is the assertion that the out-of-band list, not the marker, carries the
	 * signal: both fields read "[redacted]", and only one is listed.
	 */
	public function test_a_real_marker_string_is_not_reported_as_withheld(): void {
		$report = aafm_wc_redact_settings_report(
			array(
				'instructions' => aafm_wc_redaction_marker(),
				'api_key'      => 'live_sk_real_secret',
			)
		);

		$this->assertSame( aafm_wc_redaction_marker(), $report['settings']['instructions'], 'The real value is returned untouched.' );
		$this->assertSame( aafm_wc_redaction_marker(), $report['settings']['api_key'], 'The secret is withheld.' );
		$this->assertSame( array( array( 'api_key' ) ), $report['redacted'] );
	}

	/**
	 * A secret-NAMED key holding an array is withheld whole, not recursed into: the name already
	 * says the subtree is credential material, and reporting its children would name them.
	 */
	public function test_a_secret_named_subtree_is_withheld_whole(): void {
		$report = aafm_wc_redact_settings_report(
			array(
				'credential' => array(
					'id'     => 'benign-looking',
					'secret' => 'live_sk',
				),
			)
		);

		$this->assertSame( aafm_wc_redaction_marker(), $report['settings']['credential'] );
		$this->assertSame( array( array( 'credential' ) ), $report['redacted'] );
	}

	/**
	 * Nothing withheld means an empty list, not a missing one. A caller reading `redacted_fields`
	 * must never have to distinguish "absent" from "nothing".
	 */
	public function test_a_clean_settings_array_reports_an_empty_list(): void {
		$settings = array(
			'title'      => 'Flat rate',
			'cost'       => '5.00',
			'tax_status' => 'taxable',
		);
		$report   = aafm_wc_redact_settings_report( $settings );

		$this->assertSame( $settings, $report['settings'] );
		$this->assertSame( array(), $report['redacted'] );
	}

	/**
	 * The declared schema has to describe what the code actually emits: a list of lists of strings.
	 *
	 * The schema is what an agent reads, so a schema still promising a flat list of path strings
	 * would be the same defect one level up. Asserted against the shared fragment because all three
	 * shapes that carry this field now point at it.
	 */
	public function test_the_declared_schema_describes_a_list_of_segment_arrays(): void {
		$schema = aafm_wc_redacted_fields_schema();

		$this->assertSame( 'array', $schema['type'] );
		$this->assertSame( 'array', $schema['items']['type'] );
		$this->assertSame( 'string', $schema['items']['items']['type'] );
		$this->assertStringContainsString( 'SEGMENTS', (string) $schema['description'] );
	}

	/**
	 * The three shapes that return this field all declare it, and all declare it the same way.
	 *
	 * The contract used to be written out three times, which is how a fact drifts. Pinning the
	 * identity, not just the presence, is what makes a fourth copy fail here rather than on the wire.
	 */
	public function test_all_three_shapes_declare_the_same_redacted_fields_schema(): void {
		$expected = aafm_wc_redacted_fields_schema();

		$get    = aafm_args_wc_get_payment_gateway();
		$update = aafm_args_wc_update_payment_gateway();
		$ship   = aafm_wc_shipping_method_output_properties();

		$this->assertSame( $expected, $get['output_schema']['properties']['redacted_fields'] );
		$this->assertSame( $expected, $update['output_schema']['properties']['redacted_fields'] );
		$this->assertSame( $expected, $ship['redacted_fields'] );
	}
}
