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
	 * `<file>::<enclosing function>::<sanitizer>` and carrying the number of uses in that function
	 * plus the reason they do not need the invisible-character strip.
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
	 * The count matters. It is what stops a second, stored-write call from quietly inheriting the
	 * justification written for the query-path call already sitting in the same function.
	 *
	 * @var array<string,array{count:int,reason:string}>
	 */
	private const ALLOWED = array(
		// --- includes/text.php: the helpers themselves -------------------------------------------
		'includes/text.php::aafm_sanitize_plain_text::sanitize_text_field'                              => array(
			'count'  => 1,
			'reason' => 'The helper\'s own implementation. This IS the chokepoint every stored write is required to route through; the WordPress sanitizer runs here and the invisible-character strip is applied to its result.',
		),
		'includes/text.php::aafm_sanitize_multiline_text::sanitize_textarea_field'                      => array(
			'count'  => 1,
			'reason' => 'The multi-line helper\'s own implementation, same reason as its single-line sibling above.',
		),

		// --- includes/admin: operator input ------------------------------------------------------
		'includes/admin/bridge-directory.php::aafm_ajax_save_bridged_abilities::sanitize_text_field'    => array(
			'count'  => 1,
			'reason' => 'Submitted bridge slugs, intersected against the slugs aafm_discover_foreign_abilities() actually found before anything is stored, so only a slug that already exists on this site survives.',
		),
		'includes/admin/connection.php::aafm_ajax_oauth_revoke_client::sanitize_text_field'             => array(
			'count'  => 1,
			'reason' => 'A client_id used only to look up and revoke an existing OAuth client. Nothing is written back under this value.',
		),
		'includes/admin/connection.php::aafm_ajax_oauth_revoke_grant::sanitize_text_field'              => array(
			'count'  => 1,
			'reason' => 'The same client_id lookup on the grant-revocation path.',
		),
		'includes/admin/page.php::aafm_sanitize_enabled_input::sanitize_text_field'                     => array(
			'count'  => 1,
			'reason' => 'Ability names ticked in the admin screen, intersected against array_keys( aafm_get_abilities_registry() ) two lines later, so only a name the registry already holds can be stored.',
		),
		'includes/admin/settings.php::aafm_sanitize_settings_input::sanitize_text_field'                => array(
			'count'  => 1,
			'reason' => 'One line of the IP allowlist textarea. A line is stored only after aafm_is_valid_ip_or_cidr() accepts it, and no invisible character survives that.',
		),
		'includes/admin/settings.php::aafm_count_dropped_ip_lines::sanitize_text_field'                 => array(
			'count'  => 1,
			'reason' => 'The same parse run purely to count how many lines were rejected, so the save notice can say so. It returns an integer and stores nothing at all.',
		),

		// --- includes/audit ----------------------------------------------------------------------
		'includes/audit/log.php::aafm_source_ip::sanitize_text_field'                                   => array(
			'count'  => 1,
			'reason' => 'REMOTE_ADDR, returned only when filter_var( $ip, FILTER_VALIDATE_IP ) accepts it and \'\' otherwise, so nothing carrying an invisible character can reach a log row.',
		),

		// --- includes/helpers.php ----------------------------------------------------------------
		'includes/helpers.php::aafm_rich_post::sanitize_text_field'                                     => array(
			'count'  => 1,
			'reason' => 'A read-path shaper: the stored excerpt of a password-protected post on its way OUT into the response. Nothing here writes.',
		),

		// --- includes/oauth: protocol parameters -------------------------------------------------
		'includes/oauth/authorize.php::aafm_oauth_read_authorize_params::sanitize_text_field'           => array(
			'count'  => 1,
			'reason' => 'The eight OAuth authorize parameters. Each is compared rather than displayed: redirect_uri is exact-matched against the client\'s registered URIs, response_type/code_challenge_method/scope against fixed supported sets, client_id is a lookup key, and code_challenge is a PKCE digest that either verifies against the verifier or does not. None is stored prose a person reads.',
		),
		'includes/oauth/authorize.php::aafm_oauth_handle_authorize::sanitize_text_field'                => array(
			'count'  => 4,
			'reason' => 'The request-shaped reads around the consent POST: the ?aafm_oauth marker, REQUEST_URI, the _wpnonce, and the approve/deny decision. All four are compared against known values within the request and none is persisted. The client_name, which IS stored and rendered on this very screen, uses the helper.',
		),
		'includes/oauth/clients.php::aafm_oauth_register_client::sanitize_text_field'                   => array(
			'count'  => 2,
			'reason' => 'grant_types and response_types, both intersected against the hardcoded supported sets before storage, so only a literal this plugin already names can be written. The client_name beside them uses the helper.',
		),
		'includes/oauth/discovery.php::aafm_oauth_maybe_serve_well_known::sanitize_text_field'          => array(
			'count'  => 1,
			'reason' => 'REQUEST_URI, matched against the .well-known paths to decide whether to serve a discovery document. Routing only.',
		),
		'includes/oauth/validator.php::aafm_oauth_request_targets_mcp_route::sanitize_text_field'       => array(
			'count'  => 2,
			'reason' => 'rest_route and REQUEST_URI, both used to decide whether this request is aimed at the MCP endpoint. Routing only, never written.',
		),
		'includes/oauth/validator.php::aafm_oauth_read_bearer_token::sanitize_text_field'               => array(
			'count'  => 2,
			'reason' => 'The Authorization header in its two server spellings, parsed for a bearer token that is then hashed and compared against stored tokens. The header text itself is never stored.',
		),

		// --- includes/abilities ------------------------------------------------------------------
		'includes/abilities/blocks.php::aafm_exec_list_blocks::sanitize_text_field'                             => array(
			'count'  => 1,
			'reason' => 'The list-blocks search term. It becomes WP_Query\'s `s` argument and is never written back, so nothing about it reaches storage.',
		),
		'includes/abilities/media.php::aafm_exec_count_media::sanitize_text_field'                              => array(
			'count'  => 1,
			'reason' => 'The mime_type filter for the media count. A read filter compared against attachment mime types; no write path touches it.',
		),
		'includes/abilities/media.php::aafm_exec_get_media::sanitize_text_field'                                => array(
			'count'  => 1,
			'reason' => 'The media search term, passed as WP_Query\'s `s` inside the language-scoped closure. Query input only.',
		),
		'includes/abilities/posts.php::aafm_exec_get_posts::sanitize_text_field'                                => array(
			'count'  => 1,
			'reason' => 'The get-posts search term, passed as WP_Query\'s `s`. Query input only.',
		),
		'includes/abilities/search.php::aafm_exec_search_content::sanitize_text_field'                          => array(
			'count'  => 1,
			'reason' => 'The search-content term. Drives the query and is echoed back in the response; never stored.',
		),
		'includes/abilities/terms.php::aafm_exec_get_terms::sanitize_text_field'                                => array(
			'count'  => 1,
			'reason' => 'The get-terms search term, passed to get_terms(). Query input only.',
		),
		'includes/abilities/themes.php::aafm_exec_get_template::sanitize_text_field'                            => array(
			'count'  => 1,
			'reason' => 'A template_id used solely as the lookup key for get_block_template(). An id that matches nothing returns an error; it is never persisted.',
		),
		'includes/abilities/themes.php::aafm_exec_update_template::sanitize_text_field'                         => array(
			'count'  => 1,
			'reason' => 'The same template_id lookup on the update path. The write itself uses the resolved template\'s wp_id and the kses-filtered content, not this string.',
		),
		'includes/abilities/users.php::aafm_exec_get_users::sanitize_text_field'                                => array(
			'count'  => 1,
			'reason' => 'The get-users search term, wrapped in wildcards for WP_User_Query. Query input only.',
		),
		'includes/abilities/woocommerce/coupons.php::aafm_wc_apply_coupon_input::sanitize_text_field'           => array(
			'count'  => 5,
			'reason' => 'Five non-text coupon inputs. discount_type is closed by the input schema\'s enum (percent/fixed_cart/fixed_product). amount is refused outright unless is_numeric() accepts it, which no invisible character survives. date_expires is parsed by set_date_expires() into a WC_DateTime after a strtotime() gate, so the string itself never lands. minimum_amount and maximum_amount both go through WooCommerce\'s wc_format_decimal(), which keeps only digits and a separator. The coupon CODE, which is real stored text, uses the helper.',
		),
		'includes/abilities/woocommerce/gateways.php::aafm_exec_wc_get_payment_gateway::sanitize_text_field'    => array(
			'count'  => 1,
			'reason' => 'A gateway_id used only as an array key into the live WC_Payment_Gateways map. An id that matches no gateway returns not-found; it is never written.',
		),
		'includes/abilities/woocommerce/gateways.php::aafm_exec_wc_update_payment_gateway::sanitize_text_field' => array(
			'count'  => 1,
			'reason' => 'The same gateway_id lookup on the update path. The gateway title, which is stored and shown at checkout, uses the helper.',
		),
		'includes/abilities/woocommerce/orders.php::aafm_exec_wc_create_order_refund::sanitize_text_field'      => array(
			'count'  => 1,
			'reason' => 'The refund amount, whose input schema pins it to ^\\d+(\\.\\d{1,2})?$ - a pattern no invisible character can satisfy. The refund REASON, which is stored text, uses the multiline helper.',
		),
		'includes/abilities/woocommerce/orders.php::aafm_wc_apply_order_input::sanitize_text_field'             => array(
			'count'  => 1,
			'reason' => 'The order status. Both callers (create and update) refuse anything aafm_wc_order_status_valid() does not find in wc_get_order_statuses() before this line runs, so only a registered status slug can reach set_status().',
		),
		'includes/abilities/woocommerce/products.php::aafm_wc_attribute_shape::sanitize_text_field'             => array(
			'count'  => 1,
			'reason' => 'A read-path shaper: it sanitizes attribute options on the way OUT, assembling the JSON response from an already-stored product. Nothing here writes.',
		),
		'includes/abilities/woocommerce/reports.php::aafm_exec_wc_get_sales_report::sanitize_text_field'        => array(
			'count'  => 2,
			'reason' => 'The report start_date and end_date. Both bound a read-only query and are never persisted.',
		),
		'includes/abilities/woocommerce/reports.php::aafm_exec_wc_get_top_sellers_report::sanitize_text_field'  => array(
			'count'  => 1,
			'reason' => 'The report period. Selects a date range for a read-only query; never persisted.',
		),
		'includes/abilities/woocommerce/shipping.php::aafm_exec_wc_create_shipping_method::sanitize_text_field' => array(
			'count'  => 1,
			'reason' => 'A method_type that WC_Shipping_Zone::add_shipping_method() checks against the registered shipping-method class names and drops when unrecognised, so only a real registered method id is ever stored.',
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
			"These ability call sites use a raw WordPress sanitizer that nobody has accounted for.\n"
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
	 * A function already allowlisted for one query-path call must not silently absorb a second.
	 *
	 * Without this, the archetype survives in miniature: aafm_wc_apply_coupon_input() is allowed
	 * five raw calls for five non-text inputs, and a sixth raw call on a stored field would sit
	 * inside an entry that already reads "allowed" and never be looked at again.
	 */
	public function test_an_allowlisted_function_gains_no_extra_raw_calls(): void {
		$present    = StoredTextSanitizerScanner::group( StoredTextSanitizerScanner::scan() );
		$mismatched = array();

		foreach ( self::ALLOWED as $key => $entry ) {
			if ( ! isset( $present[ $key ] ) ) {
				continue; // Reported by the staleness test; not this test's business.
			}
			if ( $present[ $key ]['count'] !== $entry['count'] ) {
				$mismatched[] = sprintf(
					'%s: allowlisted for %d, found %d (lines %s)',
					$key,
					$entry['count'],
					$present[ $key ]['count'],
					implode( ', ', $present[ $key ]['lines'] )
				);
			}
		}

		$this->assertSame(
			array(),
			$mismatched,
			"The number of raw sanitizer calls in an allowlisted function changed. Read the new call\n"
			. "on its own merits - the existing reason was written for the OTHER calls - then either fix\n"
			. "it or update the count and extend the reason.\n\n  "
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
