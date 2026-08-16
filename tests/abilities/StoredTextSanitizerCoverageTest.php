<?php
/**
 * The mechanical half of the B-18 sweep.
 *
 * PlainTextSanitizationTest pins what aafm_sanitize_plain_text() and aafm_sanitize_multiline_text()
 * DO, and proves a handful of abilities route through them. This test asks the other question, the
 * one a hand sweep kept getting wrong: is there anywhere left that does not?
 *
 * The sweep was called complete three times and was wrong three times - update-user, then the order
 * addresses, then the entire sanitize_textarea_field family - always the same shape, fixed at one
 * call site and left at its siblings. So the check here is not "did we remember everywhere". It is
 * "every raw sanitizer call in the ability sources is either gone or written down with a reason",
 * enforced on every run.
 *
 * Read ALLOWED before adding an entry. A raw sanitize_text_field() is CORRECT for a value that is
 * looked up or queried and never stored - a WP_Query search term, a report date, a template id -
 * and rewriting those to the helper would be a regression dressed as a fix. What the allowlist is
 * for is making that judgement explicit and reviewable, so a new raw call has to be argued for
 * rather than merely typed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\Support\StoredTextSanitizerScanner;

require_once dirname( __DIR__ ) . '/Support/StoredTextSanitizerScanner.php';

final class StoredTextSanitizerCoverageTest extends TestCase {

	/**
	 * Raw sanitizer calls that are correct as they stand, keyed by
	 * `<file>::<enclosing function>::<sanitizer>` and carrying the exact calls being justified plus
	 * the reason they do not need the invisible-character strip.
	 *
	 * Two rules for anyone editing this list:
	 *
	 * 1. The reason must say where the value GOES, not what it is. "It's just a slug" is not a
	 *    reason; "it is a WP_Query argument and is never written back" is.
	 * 2. If the value reaches storage in any form, it does not belong here - fix the call instead.
	 *    A constrained identifier that is validated against a real registry before it is stored
	 *    (an order status, a shipping method id) is the one borderline case that does, and the
	 *    entry has to name the validator.
	 *
	 * `calls` carries the normalised text of each justified call rather than a bare count, and that
	 * is what stops a justification transferring (R3-3). A count is satisfied by any call in the
	 * right function, so swapping a justified query call for an unsafe stored write in one edit kept
	 * the total the same and inherited the reason. Naming the calls means a new one has to be argued
	 * for on its own. It also makes the list read better: the entry shows the code it is defending.
	 *
	 * The trade is that editing a justified call - renaming its variable, changing its input key -
	 * requires updating the entry here. That fires exactly when what is being sanitized changed,
	 * which is when the reason deserves re-reading, and never on unrelated edits elsewhere.
	 *
	 * @var array<string,array{calls:array<int,string>,reason:string}>
	 */
	private const ALLOWED = array(
		'includes/abilities/blocks.php::aafm_exec_list_blocks::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $input[\'search\'] )',
			),
			'reason' => 'The list-blocks search term. It becomes WP_Query\'s `s` argument and is never written back, so nothing about it reaches storage.',
		),
		'includes/abilities/media.php::aafm_exec_count_media::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $input[\'mime_type\'] )',
			),
			'reason' => 'The mime_type filter for the media count. A read filter compared against attachment mime types; no write path touches it.',
		),
		'includes/abilities/media.php::aafm_exec_get_media::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $input[\'search\'] )',
			),
			'reason' => 'The media search term, passed as WP_Query\'s `s` inside the language-scoped closure. Query input only.',
		),
		'includes/abilities/posts.php::aafm_exec_get_posts::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $input[\'search\'] )',
			),
			'reason' => 'The get-posts search term, passed as WP_Query\'s `s`. Query input only.',
		),
		'includes/abilities/search.php::aafm_exec_search_content::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) ( $input[\'search\'] ?? \'\' ) )',
			),
			'reason' => 'The search-content term. Drives the query and is echoed back in the response; never stored.',
		),
		'includes/abilities/terms.php::aafm_exec_get_terms::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $input[\'search\'] )',
			),
			'reason' => 'The get-terms search term, passed to get_terms(). Query input only.',
		),
		'includes/abilities/themes.php::aafm_exec_get_template::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) ( $input[\'template_id\'] ?? \'\' ) )',
			),
			'reason' => 'A template_id used solely as the lookup key for get_block_template(). An id that matches nothing returns an error; it is never persisted.',
		),
		'includes/abilities/themes.php::aafm_exec_update_template::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) ( $input[\'template_id\'] ?? \'\' ) )',
			),
			'reason' => 'The same template_id lookup on the update path. The write itself uses the resolved template\'s wp_id and the kses-filtered content, not this string.',
		),
		'includes/abilities/users.php::aafm_exec_get_users::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $input[\'search\'] )',
			),
			'reason' => 'The get-users search term, wrapped in wildcards for WP_User_Query. Query input only.',
		),
		'includes/abilities/woocommerce/coupons.php::aafm_wc_apply_coupon_input::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $input[\'amount\'] )',
				'sanitize_text_field( (string) $input[\'date_expires\'] )',
				'sanitize_text_field( (string) $input[\'discount_type\'] )',
				'sanitize_text_field( (string) $input[\'maximum_amount\'] )',
				'sanitize_text_field( (string) $input[\'minimum_amount\'] )',
			),
			'reason' => 'Five non-text coupon inputs. discount_type is closed by the input schema\'s enum (percent/fixed_cart/fixed_product). amount is refused outright unless is_numeric() accepts it, which no invisible character survives. date_expires is parsed by set_date_expires() into a WC_DateTime after a strtotime() gate, so the string itself never lands. minimum_amount and maximum_amount both go through WooCommerce\'s wc_format_decimal(), which keeps only digits and a separator. The coupon CODE, which is real stored text, uses the helper.',
		),
		'includes/abilities/woocommerce/gateways.php::aafm_exec_wc_get_payment_gateway::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) ( $input[\'gateway_id\'] ?? \'\' ) )',
			),
			'reason' => 'A gateway_id used only as an array key into the live WC_Payment_Gateways map. An id that matches no gateway returns not-found; it is never written.',
		),
		'includes/abilities/woocommerce/gateways.php::aafm_exec_wc_update_payment_gateway::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) ( $input[\'gateway_id\'] ?? \'\' ) )',
			),
			'reason' => 'The same gateway_id lookup on the update path. The gateway title, which is stored and shown at checkout, uses the helper.',
		),
		'includes/abilities/woocommerce/orders.php::aafm_exec_wc_create_order_refund::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) ( $input[\'amount\'] ?? \'0.00\' ) )',
			),
			'reason' => 'The refund amount, whose input schema pins it to ^\\d+(\\.\\d{1,2})?$ - a pattern no invisible character can satisfy. The refund REASON, which is stored text, uses the multiline helper.',
		),
		'includes/abilities/woocommerce/orders.php::aafm_wc_apply_order_input::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $input[\'status\'] )',
			),
			'reason' => 'The order status. Both callers (create and update) refuse anything aafm_wc_order_status_valid() does not find in wc_get_order_statuses() before this line runs, so only a registered status slug can reach set_status().',
		),
		'includes/abilities/woocommerce/products.php::aafm_wc_attribute_shape::sanitize_text_field' => array(
			'calls'  => array(
				'array_map( \'sanitize_text_field\', array_map( \'strval\', $options ) )',
			),
			'reason' => 'A read-path shaper: it sanitizes attribute options on the way OUT, assembling the JSON response from an already-stored product. Nothing here writes.',
		),
		'includes/abilities/woocommerce/reports.php::aafm_exec_wc_get_sales_report::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) ( $input[\'end_date\'] ?? gmdate( \'Y-m-d\' ) ) )',
				'sanitize_text_field( (string) ( $input[\'start_date\'] ?? gmdate( \'Y-m-01\' ) ) )',
			),
			'reason' => 'The report start_date and end_date. Both bound a read-only query and are never persisted.',
		),
		'includes/abilities/woocommerce/reports.php::aafm_exec_wc_get_top_sellers_report::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) ( $input[\'period\'] ?? \'month\' ) )',
			),
			'reason' => 'The report period. Selects a date range for a read-only query; never persisted.',
		),
		'includes/abilities/woocommerce/shipping.php::aafm_exec_wc_create_shipping_method::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) ( $input[\'method_type\'] ?? \'\' ) )',
			),
			'reason' => 'A method_type that WC_Shipping_Zone::add_shipping_method() checks against the registered shipping-method class names and drops when unrecognised, so only a real registered method id is ever stored.',
		),
		'includes/admin/bridge-directory.php::aafm_ajax_save_bridged_abilities::sanitize_text_field' => array(
			'calls'  => array(
				'array_map( \'sanitize_text_field\', wp_unslash( $_POST[\'bridged_abilities\'] ) )',
			),
			'reason' => 'Submitted bridge slugs, intersected against the slugs aafm_discover_foreign_abilities() actually found before anything is stored, so only a slug that already exists on this site survives.',
		),
		'includes/admin/connection.php::aafm_ajax_oauth_revoke_client::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( wp_unslash( (string) $_POST[\'client_id\'] ) )',
			),
			'reason' => 'A client_id used only to look up and revoke an existing OAuth client. Nothing is written back under this value.',
		),
		'includes/admin/connection.php::aafm_ajax_oauth_revoke_grant::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( wp_unslash( (string) $_POST[\'client_id\'] ) )',
			),
			'reason' => 'The same client_id lookup on the grant-revocation path.',
		),
		'includes/admin/page.php::aafm_sanitize_enabled_input::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $name )',
			),
			'reason' => 'Ability names ticked in the admin screen, intersected against array_keys( aafm_get_abilities_registry() ) two lines later, so only a name the registry already holds can be stored.',
		),
		'includes/admin/settings.php::aafm_count_dropped_ip_lines::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $line )',
			),
			'reason' => 'The same parse run purely to count how many lines were rejected, so the save notice can say so. It returns an integer and stores nothing at all.',
		),
		'includes/admin/settings.php::aafm_sanitize_settings_input::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $line )',
			),
			'reason' => 'One line of the IP allowlist textarea. A line is stored only after aafm_is_valid_ip_or_cidr() accepts it, and no invisible character survives that.',
		),
		'includes/audit/log.php::aafm_source_ip::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( wp_unslash( $_SERVER[\'REMOTE_ADDR\'] ) )',
			),
			'reason' => 'REMOTE_ADDR, returned only when filter_var( $ip, FILTER_VALIDATE_IP ) accepts it and \'\' otherwise, so nothing carrying an invisible character can reach a log row.',
		),
		'includes/helpers.php::aafm_rich_post::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( (string) $post->post_excerpt )',
			),
			'reason' => 'A read-path shaper: the stored excerpt of a password-protected post on its way OUT into the response. Nothing here writes.',
		),
		'includes/oauth/authorize.php::aafm_oauth_handle_authorize::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( wp_unslash( $_GET[\'aafm_oauth\'] ) )',
				'sanitize_text_field( wp_unslash( $_POST[\'_wpnonce\'] ) )',
				'sanitize_text_field( wp_unslash( $_POST[\'aafm_oauth_decision\'] ) )',
				'sanitize_text_field( wp_unslash( $_SERVER[\'REQUEST_URI\'] ) )',
			),
			'reason' => 'The request-shaped reads around the consent POST: the ?aafm_oauth marker, REQUEST_URI, the _wpnonce, and the approve/deny decision. All four are compared against known values within the request and none is persisted. The client_name, which IS stored and rendered on this very screen, uses the helper.',
		),
		'includes/oauth/authorize.php::aafm_oauth_read_authorize_params::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( wp_unslash( $source[ $key ] ) )',
			),
			'reason' => 'The eight OAuth authorize parameters. Each is compared rather than displayed: redirect_uri is exact-matched against the client\'s registered URIs, response_type/code_challenge_method/scope against fixed supported sets, client_id is a lookup key, and code_challenge is a PKCE digest that either verifies against the verifier or does not. None is stored prose a person reads.',
		),
		'includes/oauth/clients.php::aafm_oauth_register_client::sanitize_text_field' => array(
			'calls'  => array(
				'array_map( \'sanitize_text_field\', $req[\'grant_types\'] )',
				'array_map( \'sanitize_text_field\', $req[\'response_types\'] )',
			),
			'reason' => 'grant_types and response_types, both intersected against the hardcoded supported sets before storage, so only a literal this plugin already names can be written. The client_name beside them uses the helper.',
		),
		'includes/oauth/discovery.php::aafm_oauth_maybe_serve_well_known::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( wp_unslash( $_SERVER[\'REQUEST_URI\'] ) )',
			),
			'reason' => 'REQUEST_URI, matched against the .well-known paths to decide whether to serve a discovery document. Routing only.',
		),
		'includes/oauth/validator.php::aafm_oauth_read_bearer_token::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( wp_unslash( $_SERVER[\'HTTP_AUTHORIZATION\'] ) )',
				'sanitize_text_field( wp_unslash( $_SERVER[\'REDIRECT_HTTP_AUTHORIZATION\'] ) )',
			),
			'reason' => 'The Authorization header in its two server spellings, parsed for a bearer token that is then hashed and compared against stored tokens. The header text itself is never stored.',
		),
		'includes/oauth/validator.php::aafm_oauth_request_targets_mcp_route::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( wp_unslash( $_GET[\'rest_route\'] ) )',
				'sanitize_text_field( wp_unslash( $_SERVER[\'REQUEST_URI\'] ) )',
			),
			'reason' => 'rest_route and REQUEST_URI, both used to decide whether this request is aimed at the MCP endpoint. Routing only, never written.',
		),
		'includes/text.php::aafm_sanitize_multiline_text::sanitize_textarea_field' => array(
			'calls'  => array(
				'sanitize_textarea_field( $value )',
			),
			'reason' => 'The multi-line helper\'s own implementation, same reason as its single-line sibling above.',
		),
		'includes/text.php::aafm_sanitize_plain_text::sanitize_text_field' => array(
			'calls'  => array(
				'sanitize_text_field( $value )',
			),
			'reason' => 'The helper\'s own implementation. This IS the chokepoint every stored write is required to route through; the WordPress sanitizer runs here and the invisible-character strip is applied to its result.',
		),
	);

	/**
	 * The headline guard: a raw sanitizer call nobody has justified fails the build.
	 *
	 * This is the one that would have caught update-user, the order addresses and the textarea
	 * family the day each of them was written, instead of one review round at a time.
	 */
	public function test_every_raw_sanitizer_call_is_allowlisted(): void {
		$records  = StoredTextSanitizerScanner::scan();
		$unlisted = array();

		foreach ( $records as $record ) {
			if ( ! isset( self::ALLOWED[ StoredTextSanitizerScanner::key( $record ) ] ) ) {
				$unlisted[] = $record;
			}
		}

		$this->assertSame(
			array(),
			$unlisted,
			"These call sites use a raw WordPress sanitizer that nobody has accounted for.\n"
			. "If the value is STORED, route it through aafm_sanitize_plain_text() (or the multiline\n"
			. "helper for a field that keeps its newlines). If it only feeds a query or a lookup and is\n"
			. "never written back, add it to ALLOWED with a reason saying where the value goes.\n\n"
			. StoredTextSanitizerScanner::format( $unlisted )
		);
	}

	/**
	 * The other direction, which is what keeps the list honest.
	 *
	 * An allowlist that only ever grows rots into a list of things that used to be true. When a
	 * call is removed or its function renamed, the stale entry has to go with it, or the next
	 * person reads a justification for code that is no longer there.
	 */
	public function test_no_allowlist_entry_survives_the_call_it_justifies(): void {
		$present = StoredTextSanitizerScanner::group( StoredTextSanitizerScanner::scan() );
		$stale   = array_values( array_diff( array_keys( self::ALLOWED ), array_keys( $present ) ) );

		$this->assertSame(
			array(),
			$stale,
			"These ALLOWED entries no longer match any raw sanitizer call. The call was removed,\n"
			. "the function was renamed, or the file moved - delete the entry.\n\n  "
			. implode( "\n  ", $stale )
		);
	}

	/**
	 * A justification covers the exact calls it was written for, and no others.
	 *
	 * R3-3. A count alone was transferable: convert a justified query call to the helper and add an
	 * unsafe stored write to the same function in one edit, and the count is unchanged, so the old
	 * reason silently covers the new call. Comparing what each call actually sanitizes closes that
	 * - the new call does not match the justified one, so it has to be argued for on its own.
	 *
	 * aafm_wc_apply_coupon_input() is the live example: five raw calls for five non-text inputs,
	 * each named, so a sixth on a stored field cannot hide among them.
	 */
	public function test_a_justification_covers_only_the_calls_it_was_written_for(): void {
		$present    = StoredTextSanitizerScanner::group( StoredTextSanitizerScanner::scan() );
		$mismatched = array();

		foreach ( self::ALLOWED as $key => $entry ) {
			if ( ! isset( $present[ $key ] ) ) {
				continue; // Reported by the staleness test; not this test's business.
			}

			$found     = $present[ $key ]['calls'];
			$justified = $entry['calls'];
			sort( $found );
			sort( $justified );

			if ( $found !== $justified ) {
				$mismatched[] = sprintf(
					"%s\n      justified: %s\n      found:     %s",
					$key,
					implode( ' | ', $justified ),
					implode( ' | ', $found )
				);
			}
		}

		$this->assertSame(
			array(),
			$mismatched,
			"The raw sanitizer calls in an allowlisted function are not the ones its reason was written\n"
			. "for. A call was added, removed, or changed what it sanitizes. Read it on its own merits -\n"
			. "the existing reason describes the OTHER calls - then either route it through the helper or\n"
			. "update the entry and extend the reason.\n\n  "
			. implode( "\n  ", $mismatched )
		);
	}

	/**
	 * An entry with no reason is an entry nobody has to defend, which is how an allowlist becomes a
	 * mute list.
	 */
	public function test_every_allowlist_entry_carries_a_written_reason(): void {
		foreach ( self::ALLOWED as $key => $entry ) {
			$this->assertNotSame( '', trim( $entry['reason'] ), $key . ' has no reason.' );
			$this->assertGreaterThan(
				40,
				strlen( $entry['reason'] ),
				$key . ': the reason must say where the value goes, not just that it is fine.'
			);
		}
	}

	/**
	 * The scanner has to actually be looking at something. A scan that silently returns nothing -
	 * a moved directory, a broken iterator - would make every assertion above pass while checking
	 * precisely zero code, which is the failure mode this whole file exists to prevent.
	 */
	public function test_the_scan_is_not_vacuous(): void {
		$this->assertGreaterThan( 20, count( StoredTextSanitizerScanner::source_files() ) );
		$this->assertNotSame( array(), StoredTextSanitizerScanner::scan() );
	}

	/**
	 * These files talk about the sanitizers constantly in docblocks and in the tool descriptions
	 * agents read. A scanner that counted prose would drown the allowlist in entries for text, so
	 * prove it reads code and only code.
	 */
	public function test_the_scanner_reads_code_and_ignores_prose(): void {
		$source = <<<'PHP'
<?php
/**
 * A docblock that mentions sanitize_text_field and sanitize_textarea_field at length.
 */
function aafm_probe_one() {
	// An inline comment about sanitize_text_field().
	$description = __( 'Values are sanitized with sanitize_text_field.', 'agent-abilities-for-mcp' );
	return $description;
}
PHP;

		$this->assertSame( array(), StoredTextSanitizerScanner::scan_source( $source, 'probe.php' ) );
	}

	/**
	 * The two forms that do count, and the enclosing function each is attributed to. The
	 * callable-string form matters because array_map( 'sanitize_text_field', … ) sanitizes exactly
	 * the same values while being invisible to anything hunting for a name followed by a paren.
	 */
	public function test_the_scanner_finds_calls_and_callable_strings(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_two( array $input ) {
	$options = array_map( 'sanitize_text_field', $input['options'] );
	$note    = sanitize_textarea_field( (string) $input['note'] );
	return array( $options, $note );
}
PHP;

		$records = StoredTextSanitizerScanner::scan_source( $source, 'probe.php' );

		$this->assertCount( 2, $records );
		$this->assertSame( 'aafm_probe_two', $records[0]['function'] );
		$this->assertSame( 'sanitize_text_field', $records[0]['sanitizer'] );
		$this->assertSame( 'callable-string', $records[0]['form'] );
		$this->assertSame( 'aafm_probe_two', $records[1]['function'] );
		$this->assertSame( 'sanitize_textarea_field', $records[1]['sanitizer'] );
		$this->assertSame( 'call', $records[1]['form'] );
	}

	/**
	 * R3-3: a fully-qualified call is the same function and must be seen.
	 *
	 * On PHP 8 `\sanitize_text_field(...)` is a single T_NAME_FULLY_QUALIFIED token, not the
	 * T_STRING the scan used to look for, so it slipped past entirely - a real bypass, and the
	 * natural spelling for anyone writing inside a namespace. On the 7.4 floor the same source
	 * tokenizes as T_NS_SEPARATOR plus T_STRING, so both spellings are asserted.
	 */
	public function test_the_scanner_finds_a_fully_qualified_call(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_qualified( array $input ) {
	return \sanitize_textarea_field( (string) $input['note'] );
}
PHP;

		$records = StoredTextSanitizerScanner::scan_source( $source, 'probe.php' );

		$this->assertCount( 1, $records, 'A leading backslash must not hide a call.' );
		$this->assertSame( 'sanitize_textarea_field', $records[0]['sanitizer'] );
		$this->assertSame( 'aafm_probe_qualified', $records[0]['function'] );
	}

	/**
	 * A partially-qualified name is a DIFFERENT function and must not be reported. Without this the
	 * fully-qualified fix would over-match and start flagging unrelated code.
	 */
	public function test_the_scanner_ignores_a_namespaced_function_of_the_same_name(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_namespaced( $value ) {
	return Vendor\Helpers\sanitize_text_field( $value );
}
PHP;

		$this->assertSame( array(), StoredTextSanitizerScanner::scan_source( $source, 'probe.php' ) );
	}

	/**
	 * R3-3: an aliased import renames the sanitizer to something no grep would look for.
	 *
	 * `use function sanitize_text_field as clean;` makes every later `clean( $v )` a raw sanitizer
	 * call under a name the allowlist has never heard of. The scan resolves the import so the call
	 * is reported under the real sanitizer's name.
	 */
	public function test_the_scanner_resolves_an_aliased_import(): void {
		$source = <<<'PHP'
<?php
use function sanitize_text_field as clean;

function aafm_probe_aliased( array $input ) {
	return clean( (string) $input['title'] );
}
PHP;

		$records = StoredTextSanitizerScanner::scan_source( $source, 'probe.php' );

		$this->assertCount( 1, $records, 'An aliased import must not hide a call.' );
		$this->assertSame( 'sanitize_text_field', $records[0]['sanitizer'] );
		$this->assertSame( 'aafm_probe_aliased', $records[0]['function'] );
	}

	/**
	 * The fingerprint has to describe what is sanitized, since that is the whole mechanism stopping
	 * a justification from transferring to a different call.
	 */
	public function test_the_call_fingerprint_records_what_is_sanitized(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_fingerprint( array $input ) {
	$a = sanitize_text_field( (string) $input['search'] );
	$b = sanitize_text_field( (string) $input['title'] );
	return array( $a, $b );
}
PHP;

		$grouped = StoredTextSanitizerScanner::group( StoredTextSanitizerScanner::scan_source( $source, 'probe.php' ) );
		$calls   = $grouped['probe.php::aafm_probe_fingerprint::sanitize_text_field']['calls'];

		$this->assertCount( 2, $calls );
		$this->assertNotSame( $calls[0], $calls[1], 'Two different values must not share a fingerprint.' );
		$this->assertStringContainsString( "\$input['search']", implode( ' ', $calls ) );
		$this->assertStringContainsString( "\$input['title']", implode( ' ', $calls ) );
	}

	/**
	 * R4-3: two callable-string uses in one function must be distinguishable.
	 *
	 * Recording only the literal made every callable string identical, so the multiset could not
	 * tell one from another. aafm_oauth_register_client() has exactly this shape in real code -
	 * two array_map() calls over different request fields - and converting one to the helper while
	 * adding an unsafe map over stored text left the allowlist byte-for-byte unchanged.
	 */
	public function test_two_callable_strings_in_one_function_have_different_fingerprints(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_two_maps( array $req ) {
	$a = array_map( 'sanitize_text_field', $req['grant_types'] );
	$b = array_map( 'sanitize_text_field', $req['response_types'] );
	return array( $a, $b );
}
PHP;

		$grouped = StoredTextSanitizerScanner::group( StoredTextSanitizerScanner::scan_source( $source, 'probe.php' ) );
		$calls   = $grouped['probe.php::aafm_probe_two_maps::sanitize_text_field']['calls'];

		$this->assertCount( 2, $calls );
		$this->assertNotSame(
			$calls[0],
			$calls[1],
			'Two callable-string uses over different values must not share a fingerprint.'
		);
		$this->assertStringContainsString( "\$req['grant_types']", implode( ' | ', $calls ) );
		$this->assertStringContainsString( "\$req['response_types']", implode( ' | ', $calls ) );
	}

	/**
	 * The hardest case for the enclosing-call walk, and the one most likely to be got subtly wrong.
	 *
	 * Three candidates are plausible here: the outer array_values, the middle array_map that
	 * actually receives the callable, and the inner array_map( 'strval', … ). Two of the three are
	 * wrong, and one of those is wrong in a way that still looks reasonable at a glance, so the
	 * exact expected text is asserted rather than a substring.
	 */
	public function test_the_enclosing_call_is_the_one_that_receives_the_callable(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_nested( array $options ) {
	return array_values( array_map( 'sanitize_text_field', array_map( 'strval', $options ) ) );
}
PHP;

		$records = StoredTextSanitizerScanner::scan_source( $source, 'probe.php' );

		$this->assertCount( 1, $records );
		$this->assertSame(
			"array_map( 'sanitize_text_field', array_map( 'strval', \$options ) )",
			$records[0]['call'],
			'The fingerprint must name the array_map that receives the callable, not array_values and not the inner map.'
		);
	}

	/**
	 * Identity must not be truncated, or two long calls sharing a prefix collide and the transfer
	 * hole reopens one layer down. Only the rendering is capped.
	 */
	public function test_a_long_call_is_not_truncated_into_a_collision(): void {
		$long_a = '$input[\'' . str_repeat( 'a', 200 ) . '_one\']';
		$long_b = '$input[\'' . str_repeat( 'a', 200 ) . '_two\']';
		$source = "<?php\nfunction aafm_probe_long( array \$input ) {\n"
			. "\t\$x = sanitize_text_field( (string) {$long_a} );\n"
			. "\t\$y = sanitize_text_field( (string) {$long_b} );\n"
			. "\treturn array( \$x, \$y );\n}\n";

		$grouped = StoredTextSanitizerScanner::group( StoredTextSanitizerScanner::scan_source( $source, 'probe.php' ) );
		$calls   = $grouped['probe.php::aafm_probe_long::sanitize_text_field']['calls'];

		$this->assertCount( 2, $calls );
		$this->assertNotSame( $calls[0], $calls[1], 'A shared 120-character prefix must not collapse two calls into one identity.' );
		$this->assertGreaterThan( 120, strlen( $calls[0] ), 'Identity is stored whole; only format() caps.' );
	}

	/**
	 * R5-3: two calls that differ ONLY in their receiver must be distinguishable.
	 *
	 * Taking the last name token before the parenthesis meant `$a->map( … )` and `$b->map( … )`
	 * produced the same identity. The pair here differs in nothing except the receiver, because
	 * that is precisely what the chain walk changes - a pair that also differed in arguments would
	 * have passed before the fix and proved nothing.
	 */
	public function test_two_calls_differing_only_in_receiver_are_distinguishable(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_receiver( $a, $b, $x ) {
	$one = $a->map( 'sanitize_text_field', $x );
	$two = $b->map( 'sanitize_text_field', $x );
	return array( $one, $two );
}
PHP;

		$records = StoredTextSanitizerScanner::scan_source( $source, 'probe.php' );

		$this->assertCount( 2, $records );
		$this->assertNotSame(
			$records[0]['call'],
			$records[1]['call'],
			'The receiver is part of what identifies the consuming call.'
		);
		$this->assertSame( "\$a->map( 'sanitize_text_field', \$x )", $records[0]['call'] );
		$this->assertSame( "\$b->map( 'sanitize_text_field', \$x )", $records[1]['call'] );
	}

	/**
	 * A static call and a chained one are the same shape and must resolve the same way.
	 */
	public function test_static_and_chained_receivers_are_captured(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_chains( $x ) {
	$one = Vendor\Mapper::map( 'sanitize_text_field', $x );
	$two = $this->services->mapper->map( 'sanitize_text_field', $x );
	return array( $one, $two );
}
PHP;

		$records = StoredTextSanitizerScanner::scan_source( $source, 'probe.php' );

		$this->assertCount( 2, $records );
		$this->assertStringContainsString( 'Vendor\Mapper::map', $records[0]['call'] );
		$this->assertStringContainsString( '$this->services->mapper->map', $records[1]['call'] );
	}

	/**
	 * The half of R5-3 that was not reported: when no callee can be named at all, the old fallback
	 * was the bare literal, which is the SAME string at every such site. Two variable-function
	 * calls therefore matched each other silently - R4-3's collision arriving through another door.
	 *
	 * The rule the fallback now follows: a fingerprint may be imprecise, but it may never be
	 * ambiguous. The statement is a coarser identity than the call, and that is fine, because a
	 * reader can see the coarseness. They cannot see a collision.
	 */
	public function test_an_unnameable_callee_falls_back_to_something_unambiguous(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_unnamed( $fn1, $fn2, $x ) {
	$one = $fn1( 'sanitize_text_field', $x );
	$two = $fn2( 'sanitize_text_field', $x );
	return array( $one, $two );
}
PHP;

		$records = StoredTextSanitizerScanner::scan_source( $source, 'probe.php' );

		$this->assertCount( 2, $records );
		$this->assertNotSame(
			$records[0]['call'],
			$records[1]['call'],
			'Two unnameable consumers must not share the one fallback string.'
		);
		$this->assertStringContainsString( '$fn1', $records[0]['call'], 'The fallback keeps what distinguishes the site.' );
		$this->assertStringContainsString( '$fn2', $records[1]['call'] );
	}

	/**
	 * The scan must reach every file that actually ships.
	 *
	 * The file that proved the hand-picked list was the wrong shape (R3-3) was uninstall.php: it
	 * ships, it was outside the scan, and only a source review caught it. The list is derived from
	 * git archive now, so this asserts the derivation really does reach it.
	 */
	public function test_the_scan_covers_every_shipped_first_party_file(): void {
		$scanned = array_map(
			static function ( string $path ): string {
				return str_replace( dirname( __DIR__, 2 ) . '/', '', $path );
			},
			StoredTextSanitizerScanner::source_files()
		);

		foreach ( array( 'uninstall.php', 'agent-abilities-for-mcp.php', 'includes/text.php', 'includes/helpers.php' ) as $expected ) {
			$this->assertContains( $expected, $scanned, $expected . ' ships and must be scanned.' );
		}

		foreach ( $scanned as $path ) {
			$this->assertStringStartsNotWith( 'vendor/', $path, 'Third-party code is deliberately out of scope.' );
			$this->assertStringStartsNotWith( 'tests/', $path, 'Tests do not ship.' );
		}
	}

	/**
	 * A call inside a closure is attributed to the named function that contains it, because that is
	 * the scope a reader of the allowlist would go looking for. aafm_exec_get_media() is the real
	 * instance: its WP_Query runs inside a language-scoped closure.
	 */
	public function test_a_call_inside_a_closure_is_attributed_to_its_named_function(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_three( array $input ) {
	return aafm_with_language(
		'en',
		static function () use ( $input ) {
			return sanitize_text_field( (string) $input['search'] );
		}
	);
}
PHP;

		$records = StoredTextSanitizerScanner::scan_source( $source, 'probe.php' );

		$this->assertCount( 1, $records );
		$this->assertSame( 'aafm_probe_three', $records[0]['function'] );
	}

	/**
	 * A method that happens to share the name is a different function entirely, and must not be
	 * reported as a raw sanitizer call the plugin is responsible for.
	 */
	public function test_the_scanner_ignores_a_method_of_the_same_name(): void {
		$source = <<<'PHP'
<?php
function aafm_probe_four( $object, $value ) {
	return $object->sanitize_text_field( $value );
}
PHP;

		$this->assertSame( array(), StoredTextSanitizerScanner::scan_source( $source, 'probe.php' ) );
	}
}
