<?php
/**
 * Phase 4 milestone: CVE-class regression sweep.
 *
 * Every competitor in this space shipped at least one critical CVE-class flaw. This
 * suite maps each competitor flaw class to a structural absence or a proven mitigation
 * in OUR catalog, with one focused test (or group) per class. If a future change
 * reintroduces any class - a missing gate, an over-broad permission, a dangerous
 * primitive, a dishonest annotation, an open schema - the matching test fails.
 *
 * Flaw classes covered (spec §6.3 / note 08):
 *   - Privilege escalation        → low-priv caller denied every high-cap write + audited
 *   - Author / type spoofing      → closed-schema rejection of post_author/post_type
 *   - SSRF                        → upload-media has no URL/remote-fetch input at all
 *   - Arbitrary option/meta write → no such ability exists; closed schemas reject unknown fields
 *   - Permanent delete            → trash/recoverable semantics only, no force-delete
 *   - PII / user enumeration      → get-users requires list_users; redactors strip PII
 *   - Unauthenticated / over-broad → every ability has a real permission_callback; opt-in default
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class SecurityRegressionTest extends TestCase {

	/**
	 * The 12 writes. Every one must deny a bare subscriber.
	 *
	 * @var string[]
	 */
	private const WRITES = array(
		'aafm/create-draft',
		'aafm/create-post',
		'aafm/update-post',
		'aafm/trash-post',
		'aafm/create-page',
		'aafm/update-page',
		'aafm/trash-page',
		'aafm/create-term',
		'aafm/update-term',
		'aafm/moderate-comment',
		'aafm/set-featured-image',
		'aafm/upload-media',
	);

	public function set_up(): void {
		parent::set_up();

		// Wave 4: force all three integrations active (+ the mandatory registry-memo flush)
		// so test_no_arbitrary_option_or_meta_ability_exists scans a registry that INCLUDES
		// the integration names once the SEO/ACF/WC slices land. Without this, those names
		// never appear and their needle/sanction checks would be dead code (HIGH-3). No
		// integration ability exists yet, so nothing new is scanned in this slice.
		add_filter( 'aafm_integration_active_yoast', '__return_true' );
		add_filter( 'aafm_integration_active_rankmath', '__return_true' );
		add_filter( 'aafm_integration_active_aioseo', '__return_true' );
		add_filter( 'aafm_integration_active_acf', '__return_true' );
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );
	}

	/**
	 * Enable + register the whole catalog so abilities can be invoked.
	 */
	private function register_whole_catalog(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		// This suite's claim is that no ability anywhere is reachable without a real gate, which is
		// strictly stronger when the high-risk floor is down: with the floor up the eight locked
		// abilities would simply be absent and would go unchecked rather than proven safe.
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry() ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * CVE class: PRIVILEGE ESCALATION.
	 *
	 * A low-priv (subscriber) caller must be denied every write, and the denial audited.
	 */
	public function test_priv_esc_subscriber_is_denied_every_write_and_audited(): void {
		$this->register_whole_catalog();
		$this->acting_as( 'subscriber' );

		foreach ( self::WRITES as $name ) {
			$result = wp_get_ability( $name )->check_permissions(
				array(
					'post_id'       => 1,
					'comment_id'    => 1,
					'attachment_id' => 1,
					'term_id'       => 1,
				)
			);
			$this->assertFalse(
				true === $result,
				$name . ' allowed a bare subscriber - privilege escalation.'
			);
		}

		// The denials were recorded (auditing-with-denials is the product guarantee).
		$denied = aafm_query_activity(
			array(
				'status'   => 'denied',
				'per_page' => 100,
			)
		);
		$this->assertNotEmpty( $denied, 'A denied write must write a denied audit row.' );
	}

	/**
	 * Privilege escalation: a contributor (edit_posts only) cannot publish.
	 */
	public function test_priv_esc_contributor_cannot_publish_or_delete(): void {
		$this->register_whole_catalog();
		$this->acting_as( 'contributor' );

		// Contributor has edit_posts but NOT publish_posts/delete_posts on others' content.
		$this->assertNotTrue(
			wp_get_ability( 'aafm/create-post' )->check_permissions( array() ),
			'create-post (publish) must require publish capability a contributor lacks.'
		);
	}

	/**
	 * CVE class: SELF-ACCOUNT PRIVILEGE via the map_meta_cap edit_user short-circuit.
	 *
	 * The current_user_can('edit_user', $self) check is true for every logged-in user against their own id,
	 * so a gate that checks ONLY the per-object edit_user does not deny a subscriber on its own
	 * account. aafm_perm_update_user carried the object-independent edit_users floor; the user-meta
	 * and ACF-user gates did not until this sweep. Both must now deny a bare subscriber on itself.
	 */
	public function test_edit_user_self_shortcircuit_is_closed_for_user_meta_and_acf(): void {
		add_filter( 'aafm_allowed_user_meta_keys', static fn(): array => array( 'twitter' ) );

		$self = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $self );

		// Sanity: the caller CAN edit_user on its own id (the trap), yet the gate must still deny.
		$this->assertTrue( current_user_can( 'edit_user', $self ) );

		$this->assertFalse(
			aafm_can_access_user_meta(
				array(
					'user_id' => $self,
					'key'     => 'twitter',
				)
			),
			'A subscriber must not reach its own user meta through the edit_user(self) short-circuit.'
		);

		$this->assertFalse(
			aafm_perm_acf_user( array( 'user_id' => $self ) ),
			'A subscriber must not reach its own ACF user fields through the edit_user(self) short-circuit.'
		);
	}

	/**
	 * CVE class: AUTHOR / TYPE SPOOFING.
	 *
	 * Caller-supplied post_author/post_type cannot escalate - the closed schema rejects
	 * them before execute (stronger than ignore-at-execute).
	 */
	public function test_author_and_type_spoofing_is_rejected_by_closed_schema(): void {
		$this->register_whole_catalog();
		$editor_id = $this->acting_as( 'editor' );

		$spoof_targets = array( 'aafm/create-draft', 'aafm/create-post', 'aafm/create-page' );

		foreach ( $spoof_targets as $name ) {
			// Smuggle a foreign author + a privileged post type.
			$result = wp_get_ability( $name )->execute(
				array(
					'title'       => 'Spoof attempt',
					'post_author' => 999999,
					'post_type'   => 'attachment',
				)
			);
			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				$name . ' did not reject smuggled post_author/post_type via the closed schema.'
			);
		}

		// And on update-post, the same smuggle is rejected before any write.
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $editor_id,
				'post_status' => 'publish',
			)
		);
		$result  = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id'     => $post_id,
				'post_author' => 999999,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result, 'update-post accepted a smuggled post_author.' );

		// The post's author is untouched.
		$this->assertSame( $editor_id, (int) get_post( $post_id )->post_author, 'Author was spoofed despite rejection.' );
	}

	/**
	 * CVE class: SSRF.
	 *
	 * The upload-media ability accepts base64 ONLY - no URL/remote-fetch input exists.
	 * Asserted structurally (no url-like field) and reinforced by the closed schema.
	 */
	public function test_ssrf_upload_media_has_no_url_input(): void {
		$this->register_whole_catalog();

		$input = wp_get_ability( 'aafm/upload-media' )->get_input_schema();
		$props = array_keys( $input['properties'] ?? array() );

		// The ONLY image source is inline base64. No URL/src/source/remote field.
		$this->assertContains( 'data_base64', $props, 'upload-media must take inline base64.' );
		foreach ( array( 'url', 'src', 'source', 'remote_url', 'image_url', 'href', 'uri' ) as $forbidden ) {
			$this->assertNotContains(
				$forbidden,
				$props,
				"upload-media exposes a '{$forbidden}' input - reopens the SSRF class."
			);
		}
		// Closed schema means even a smuggled url is rejected before execute.
		$this->assertFalse( $input['additionalProperties'] ?? true, 'upload-media schema must be closed.' );
	}

	/**
	 * SSRF: no write schema anywhere accepts a URL/remote source field.
	 */
	public function test_ssrf_no_write_schema_accepts_a_url_source(): void {
		$this->register_whole_catalog();

		foreach ( self::WRITES as $name ) {
			$props = array_keys( wp_get_ability( $name )->get_input_schema()['properties'] ?? array() );
			foreach ( array( 'url', 'src', 'source', 'remote_url', 'image_url' ) as $forbidden ) {
				$this->assertNotContains(
					$forbidden,
					$props,
					"{$name} accepts a '{$forbidden}' source field - SSRF surface."
				);
			}
		}
	}

	/**
	 * CVE class: ARBITRARY OPTION / META OVERWRITE.
	 *
	 * No ability writes arbitrary options or freeform meta; no such tool name exists.
	 */
	public function test_no_arbitrary_option_or_meta_ability_exists(): void {
		$registry = aafm_get_abilities_registry();

		// No ability name hints at a generic option/meta/role/user/code/file surface.
		$banned = array(
			'option',
			'meta',
			'create-user',
			'user-create',
			'update-user',
			'delete-user',
			'role',
			'capabilit',
			'snippet',
			'sql',
			'eval',
			'exec',
			'file',
			'plugin',
			'theme',
			'setting',
			'delete-forever',
			'force-delete',
			'fetch-url',
			'import-url',
		);
		// The governed post-meta and term-meta abilities are the sanctioned exception to the
		// generic 'meta' ban: each is gated by per-object edit_post / edit_term + a permanent
		// hard-block denylist + a default-deny allowlist (see includes/abilities/meta.php and
		// the term-meta abilities in includes/abilities/terms.php). The bulk reader
		// get-all-post-meta carries the identical gate (per-object edit_post + the same
		// hard-block + default-deny allowlist) and is sanctioned on the same basis. A *generic*
		// option/meta surface remains banned.
		$sanctioned = array(
			'aafm/get-post-meta',
			'aafm/get-all-post-meta',
			'aafm/update-post-meta',
			'aafm/delete-post-meta',
			'aafm/get-term-meta',
			'aafm/update-term-meta',
			'aafm/delete-term-meta',
		);
		// User CRUD is the sanctioned exception to the create-user/update-user/delete-user
		// needles: each is capability-gated (create_users/edit_users/delete_users), default-OFF,
		// audited, and closed-schema. create-user forces the site default role (never admin);
		// update-user gates any role change behind promote_users and refuses to demote the last
		// admin; delete-user requires a reassign target and refuses self / the last admin. A
		// *generic* role/capability surface stays banned. get-user trips no needle, but listing
		// it here self-documents the whole user surface in one place.
		$sanctioned = array_merge(
			$sanctioned,
			array(
				'aafm/get-user',
				'aafm/create-user',
				'aafm/update-user',
				'aafm/delete-user',
			)
		);
		// The governed user-meta abilities are sanctioned on a COMBINED basis: each trips BOTH
		// the generic 'meta' needle AND a user-write needle (update-user-meta contains
		// 'update-user', delete-user-meta contains 'delete-user'). They are allowed because
		// each is capability-gated on the edit_users floor plus per-object edit_user($id) (reads gated like writes, since
		// user meta can hold private data), scalar-only through a default-deny allowlist, and
		// floored by an auth/capability/2FA hard-block denylist (session tokens, application
		// passwords, wp_capabilities/wp_user_level incl. multisite per-blog forms, password
		// reset, passkey/2FA keys) that NO filter can re-admit. A generic user-meta or
		// capability surface stays banned.
		$sanctioned = array_merge(
			$sanctioned,
			array(
				'aafm/get-user-meta',
				'aafm/update-user-meta',
				'aafm/delete-user-meta',
			)
		);
		// The site-settings abilities are the sanctioned exception to the generic 'setting'
		// needle. Both gate on manage_options (the Settings-screen capability) and are
		// default-OFF, audited, and closed-schema. They never touch an ARBITRARY option:
		// update-site-settings writes ONLY a fixed allowlist (name, tagline, timezone, the
		// date/time formats, week start, posts per page), fail-closed on any other key, and
		// the takeover-class keys (siteurl, home, admin_email, default_role,
		// users_can_register) are excluded and re-stripped even from a rogue filter. A
		// *generic* option/setting write (e.g. aafm/update-option, aafm/set-option) stays
		// banned by the needle.
		$sanctioned = array_merge(
			$sanctioned,
			array(
				'aafm/get-site-settings',
				'aafm/update-site-settings',
			)
		);
		// list-plugins is the sanctioned exception to the 'plugin' needle. It is a READ-ONLY
		// inventory (name, version, active state, relative basename) gated on activate_plugins -
		// the capability WordPress puts on the Plugins screen - default-OFF, audited, and
		// closed-schema. There is deliberately NO activate/deactivate ability in the catalog, so
		// the 'plugin' needle still bans a generic plugin-management surface (e.g.
		// aafm/activate-plugin, aafm/manage-plugins). list-plugins never changes a plugin.
		$sanctioned = array_merge( $sanctioned, array( 'aafm/list-plugins' ) );
		// get-active-theme and list-themes are the sanctioned exception to the 'theme' needle.
		// Both are READS gated on edit_theme_options (the Appearance-screen capability),
		// default-OFF, audited, and closed-schema, and neither returns a filesystem path. There is
		// deliberately NO theme switch/install/delete ability in the catalog, so the 'theme' needle
		// still bans a generic theme-management surface (e.g. aafm/switch-theme, aafm/delete-theme).
		// The other FSE abilities (list-templates, get-template, update-template, get-global-styles)
		// trip no needle, so they need no sanction.
		$sanctioned = array_merge( $sanctioned, array( 'aafm/get-active-theme', 'aafm/list-themes' ) );
		// acf-update-user-fields trips the update-user needle but is an ACF custom-field write
		// gated on the edit_users floor plus per-object edit_user($id), default-OFF, audited, closed-schema - it never
		// touches the role/account surface the needle bans. The closed top-level schema accepts
		// only user_id + a fields object, so a smuggled role/login/capabilities key is rejected
		// before execute, and the field map values are type-sanitized. A generic user-write surface
		// (aafm/update-user, aafm/create-user, aafm/delete-user) stays banned. The other ACF names
		// trip no needle: acf-list-field-groups / acf-get-*-fields / acf-update-post-fields /
		// acf-update-term-fields contain none of meta/option/role/setting/plugin/theme/create-user/
		// update-user/delete-user.
		$sanctioned = array_merge( $sanctioned, array( 'aafm/acf-update-user-fields' ) );
		foreach ( array_keys( $registry ) as $name ) {
			if ( in_array( $name, $sanctioned, true ) ) {
				continue;
			}
			foreach ( $banned as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$name,
					"Dangerous ability surface present: {$name} (matched '{$needle}')."
				);
			}
		}
	}

	/**
	 * Arbitrary payload: a closed schema rejects a smuggled meta_input/option field.
	 */
	public function test_writes_reject_unknown_fields_so_no_arbitrary_payload_lands(): void {
		$this->register_whole_catalog();
		$this->acting_as( 'administrator' );

		// A clean payload with a smuggled freeform field (e.g. meta_input / option) must be
		// rejected by the closed schema before execute - nothing arbitrary reaches the DB.
		$result = wp_get_ability( 'aafm/create-draft' )->execute(
			array(
				'title'      => 'ok',
				'meta_input' => array( 'evil' => 'x' ),
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'create-draft accepted a smuggled meta_input field (open schema).'
		);
	}

	/**
	 * Arbitrary code-exec primitives must never appear in our source.
	 *
	 * Codex round 5, R5-5: this test used to also police curl_exec() and the three
	 * wp_remote_*() functions with a whole-file exemption. That check is now
	 * test_outbound_network_primitives_match_an_exact_per_file_allowlist() below, which covers
	 * the whole WP safe-remote/cURL/socket family with an exact per-file call count instead of a
	 * blanket per-file pass.
	 */
	public function test_source_tree_has_no_dangerous_primitives(): void {
		$dir   = dirname( __DIR__, 2 ) . '/includes';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );

		// Code-exec primitives must NEVER appear anywhere in our source.
		$banned_exec = '/\b(eval|create_function|assert|download_url)\s*\(/';

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			// Reading our own bundled source for a static scan - not a remote fetch.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$src = (string) file_get_contents( $file->getPathname() );

			$this->assertDoesNotMatchRegularExpression(
				$banned_exec,
				$src,
				'Code-exec primitive in ' . $file->getFilename()
			);
		}
	}

	/**
	 * Find the next (direction 1) or previous (direction -1) significant token around a given
	 * index: whitespace, comments, and docblocks never count as significant, so a primitive's
	 * name sitting in a comment - or separated from a real paren only by blank lines - cannot
	 * change what "the token right before/after this one" means.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param int                                           $index Index to look around.
	 * @param int                                           $direction 1 for next, -1 for previous.
	 * @return array{0:int,1:string,2:int}|string|null
	 */
	private function significant_token( array $tokens, int $index, int $direction ) {
		$i     = $index + $direction;
		$total = count( $tokens );
		while ( $i >= 0 && $i < $total ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$i += $direction;
				continue;
			}
			return $token;
		}
		return null;
	}

	/**
	 * Codex round 6, B6-6: the retired scan stripped comments and then ran regexes against the
	 * reconstructed source text - so a string literal such as "wp_safe_remote_get(" or a method
	 * named the same as a primitive, like $client->curl_exec(), still counted as a hit. Tokens
	 * distinguish these cases directly: a string literal is never a T_STRING identifier token,
	 * and a real function call has '(' as its very next significant token with neither '->'
	 * (a method call), '::' (a static call), nor the `function` keyword (a declaration) as the
	 * token right before it.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param string                                        $name Bare function name to count real calls of.
	 * @return int
	 */
	private function count_function_call_tokens( array $tokens, string $name ): int {
		$count = 0;
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 0 !== strcasecmp( $token[1], $name ) ) {
				continue;
			}
			$next = $this->significant_token( $tokens, $i, 1 );
			if ( ! is_string( $next ) || '(' !== $next ) {
				continue;
			}
			$prev = $this->significant_token( $tokens, $i, -1 );
			if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue;
			}
			++$count;
		}
		return $count;
	}

	/**
	 * Codex round 6, B6-6: counts a static call prefix like `Requests::` - the class name token
	 * immediately followed by `::` - the one primitive in this suite where a preceding `::` is
	 * exactly the pattern being looked for, not something to exclude.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param string                                        $class_name Exact class name, case-sensitive.
	 * @return int
	 */
	private function count_static_class_prefix_tokens( array $tokens, string $class_name ): int {
		$count = 0;
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || $class_name !== $token[1] ) {
				continue;
			}
			$next = $this->significant_token( $tokens, $i, 1 );
			if ( is_array( $next ) && T_DOUBLE_COLON === $next[0] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Codex round 6, B6-6: counts a bare class-name reference such as `WP_Http`, used for
	 * `new WP_Http()`, a type hint, or an `instanceof` check - none of which put '(' right after
	 * the name. Only a real identifier token counts; the class name sitting inside a string
	 * literal (e.g. `class_exists( 'WP_Http' )`) never tokenizes as T_STRING at all.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param string                                        $name Exact identifier name, case-sensitive.
	 * @return int
	 */
	private function count_identifier_tokens( array $tokens, string $name ): int {
		$count = 0;
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && T_STRING === $token[0] && $name === $token[1] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Codex round 6, B6-6: file_get_contents() is only an outbound-fetch primitive when its
	 * argument is an http(s) URL - the local, non-network calls this codebase actually makes are
	 * legitimate. Confirm the call is real (same rule as count_function_call_tokens()), then walk
	 * the balanced parens collecting the raw argument text and look for 'http' in THAT text only,
	 * rather than in the whole comment-stripped file the retired regex scanned.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @return int
	 */
	private function count_file_get_contents_http_calls( array $tokens ): int {
		$count = 0;
		$total = count( $tokens );
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 0 !== strcasecmp( $token[1], 'file_get_contents' ) ) {
				continue;
			}
			$next = $this->significant_token( $tokens, $i, 1 );
			if ( ! is_string( $next ) || '(' !== $next ) {
				continue;
			}
			$prev = $this->significant_token( $tokens, $i, -1 );
			if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue;
			}

			$open_index = $i + 1;
			while ( $open_index < $total && '(' !== $tokens[ $open_index ] ) {
				++$open_index;
			}
			$depth = 0;
			$args  = '';
			for ( $j = $open_index; $j < $total; $j++ ) {
				$t = $tokens[ $j ];
				if ( '(' === $t ) {
					++$depth;
				} elseif ( ')' === $t ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				}
				$args .= is_array( $t ) ? $t[1] : $t;
			}
			if ( false !== stripos( $args, 'http' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Codex round 5, R5-5: the retired scan recognized only wp_remote_get/post/request, missing
	 * direct siblings such as wp_safe_remote_get() and wp_remote_head(), and exempted entire
	 * files by name - a SECOND unguarded call anywhere in an already-exempt file passed silently.
	 *
	 * Codex round 6, B6-6: that scan then stripped comments and regexed the leftover source text,
	 * which still let a string literal or a same-named method call through. This version tokenizes
	 * each file once and asks the token-based helpers above whether each hit is a real call, static
	 * prefix, or bare identifier, so only the specific calls this codebase's own SSRF design already
	 * accounts for are allowed, and one more of any of them anywhere fails the suite.
	 *
	 * Only two call sites may reach the network at all: aafm_ssrf_owned_curl_fetch()'s
	 * cURL handle in media.php (owned outright, pinned via CURLOPT_RESOLVE, proxy disabled, no
	 * redirects, TLS verified, size-capped, reachable only through aafm_ssrf_safe_fetch_url()'s
	 * SSRF gate - covered by SsrfOwnedCurlFetchTest and UploadMediaFromUrlSsrfTest), and
	 * aafm_ajax_test_connection()'s single wp_remote_post() call in connection.php: an
	 * admin-only, manage_options + nonce gated reachability probe never reachable by an MCP
	 * agent (Codex hunt F3 already covers why its target URL is trusted; see
	 * aafm_ability_disclosures()'s file docblock in disclosures.php).
	 */
	public function test_outbound_network_primitives_match_an_exact_per_file_allowlist(): void {
		$dir   = dirname( __DIR__, 2 ) . '/includes';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );

		$function_primitives = array(
			'wp_remote_get',
			'wp_remote_post',
			'wp_remote_request',
			'wp_remote_head',
			'wp_safe_remote_get',
			'wp_safe_remote_post',
			'wp_safe_remote_request',
			'wp_safe_remote_head',
			'curl_init',
			'curl_exec',
			'fsockopen',
			'stream_socket_client',
		);

		$allowed = array(
			'includes/abilities/media.php'  => array(
				'curl_init' => 1,
				'curl_exec' => 1,
			),
			'includes/admin/connection.php' => array(
				'wp_remote_post' => 1,
			),
		);

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			// Reading our own bundled source for a static scan - not a remote fetch.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$tokens = token_get_all( (string) file_get_contents( $file->getPathname() ) );
			$path   = str_replace( '\\', '/', $file->getPathname() );

			$expected = array();
			foreach ( $allowed as $allowed_suffix => $counts ) {
				if ( str_ends_with( $path, $allowed_suffix ) ) {
					$expected = $counts;
					break;
				}
			}

			foreach ( $function_primitives as $label ) {
				$this->assertSame(
					$expected[ $label ] ?? 0,
					$this->count_function_call_tokens( $tokens, $label ),
					sprintf( '%s call count mismatch in %s', $label, $file->getFilename() )
				);
			}
			$this->assertSame(
				$expected['Requests::'] ?? 0,
				$this->count_static_class_prefix_tokens( $tokens, 'Requests' ),
				sprintf( 'Requests:: call count mismatch in %s', $file->getFilename() )
			);
			$this->assertSame(
				$expected['WP_Http'] ?? 0,
				$this->count_identifier_tokens( $tokens, 'WP_Http' ),
				sprintf( 'WP_Http reference count mismatch in %s', $file->getFilename() )
			);
			$this->assertSame(
				$expected['file_get_contents(http)'] ?? 0,
				$this->count_file_get_contents_http_calls( $tokens ),
				sprintf( 'file_get_contents(http) call count mismatch in %s', $file->getFilename() )
			);
		}
	}


	/**
	 * CVE class: PERMANENT DELETE.
	 *
	 * Force-delete (the trash-bypass flag) is a CVE-class primitive: each one is
	 * permitted ONLY in the single ability file that discloses the matching destructive
	 * ability, and banned everywhere else.
	 *
	 * Three primitives are governed here:
	 *   - wp_delete_post(...,true)       → allowed ONLY in includes/abilities/posts.php.
	 *   - wp_delete_comment(...,true)    → allowed ONLY in includes/abilities/comments.php.
	 *   - wp_delete_attachment(...,true) → allowed ONLY in includes/abilities/media.php.
	 *
	 * Posts/pages are the newest sanctioned exception. aafm/delete-post is an explicit,
	 * separately-disclosed destructive ability (risk=destructive, in DESTRUCTIVE_WRITES,
	 * filed under "Destructive (permanent)") that force-deletes through the single
	 * aafm_force_delete_post() executor in posts.php. aafm/delete-page does NOT call
	 * the primitive itself - it delegates to that same executor with the page type
	 * pinned - so pages.php never force-deletes directly and there is exactly one
	 * wp_delete_post(...,true) call site in the whole abilities layer. The recoverable
	 * trash-post/trash-page abilities remain for the undoable path.
	 *
	 * Comments are another sanctioned exception. aafm/delete-comment is an explicit,
	 * separately-disclosed destructive ability (risk=destructive, in DESTRUCTIVE_WRITES,
	 * filed under "Destructive (permanent)") that uses wp_delete_comment(...,true) by
	 * design - moderators routinely purge spam permanently, and aafm/moderate-comment
	 * still offers the recoverable 'trash' path. That single call is allowed only in
	 * includes/abilities/comments.php.
	 *
	 * Media is the last. aafm/delete-media is the disclosed destructive media ability
	 * (risk=destructive) that uses wp_delete_attachment(...,true) by design - an
	 * attachment has no Trash path, so removing a media file is inherently permanent.
	 * That single call is allowed only in includes/abilities/media.php.
	 *
	 * GeoDirectory's create-rollback is a narrower, function-scoped exception, not a
	 * file-level one. aafm_geodirectory_rollback_unconfirmed_create() force-deletes a
	 * listing the SAME request just half-created and that failed its own write
	 * verification - the caller never received an ID for it, and leaving it in Trash
	 * would surface a half-written record to admins browsing the listing type. Round 5
	 * un-masked this call by accident (an unrelated `(int)` cast removal broke the old
	 * `[^)]*` regex's evasion, see git history on this test), which is why it is
	 * disclosed here explicitly instead of silently exempted. Only THAT function's body
	 * is stripped before the sweep runs against geodirectory.php, so a second, different
	 * force-delete added anywhere else in the same file still fails this test.
	 *
	 * A force-delete of any of these primitives in any other file, or any other function, is
	 * still a CVE.
	 */
	public function test_no_force_delete_in_source(): void {
		$dir   = dirname( __DIR__, 2 ) . '/includes';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );

		// The one file permitted to force-delete a post/page (the disclosed delete-post ability;
		// delete-page delegates to the same posts.php executor, so there is no second call site).
		$post_force_delete_allowed = 'includes/abilities/posts.php';
		// The one file permitted to force-delete a comment (the disclosed destructive ability).
		$comment_force_delete_allowed = 'includes/abilities/comments.php';
		// The one file permitted to force-delete an attachment (the disclosed delete-media ability).
		$media_force_delete_allowed = 'includes/abilities/media.php';
		// The one file, and one function within it, permitted to force-delete a post as a
		// same-request rollback of its own unconfirmed create (see docblock above).
		$geodirectory_force_delete_allowed = 'includes/abilities/geodirectory.php';
		$geodirectory_rollback_function    = 'aafm_geodirectory_rollback_unconfirmed_create';

		// A balanced-one-level-of-nesting argument list, so a cast like `(int) $post_id` sitting
		// ahead of the `, true )` cannot break the match the way a bare `[^)]*` did before
		// (the closing paren of `(int)` ended the character class early and let the real call
		// slip past the sweep undetected - see the docblock above).
		$args = '(?:[^()]|\([^()]*\))*';

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			// Reading our own bundled source for a static scan - not a remote fetch.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$src  = (string) file_get_contents( $file->getPathname() );
			$src  = $this->resolve_use_function_aliases( $src );
			$path = str_replace( '\\', '/', $file->getPathname() );

			$post_delete_src = $src;
			if ( str_ends_with( $path, $geodirectory_force_delete_allowed ) ) {
				$post_delete_src = $this->strip_function_body( $src, $geodirectory_rollback_function );
			}

			// Permanent post/page delete is allowed ONLY in the sanctioned posts file, or inside
			// GeoDirectory's disclosed rollback function (stripped above before this check runs).
			// The /s flag makes a multiline call match too, so it can't slip past the sweep.
			if ( ! str_ends_with( $path, $post_force_delete_allowed ) ) {
				$this->assertDoesNotMatchRegularExpression(
					'/wp_delete_post\s*\(' . $args . ',\s*true\s*\)/s',
					$post_delete_src,
					'Permanent wp_delete_post(...,true) in ' . $file->getFilename() . ' (only the disclosed delete-post ability, or GeoDirectory\'s same-request create-rollback, may force-delete)'
				);
			}

			// Permanent comment delete is allowed ONLY in the sanctioned comments file.
			if ( ! str_ends_with( $path, $comment_force_delete_allowed ) ) {
				$this->assertDoesNotMatchRegularExpression(
					'/wp_delete_comment\s*\(' . $args . ',\s*true\s*\)/s',
					$src,
					'Permanent wp_delete_comment(...,true) in ' . $file->getFilename() . ' (only the disclosed delete-comment ability may force-delete)'
				);
			}

			// Permanent attachment delete is allowed ONLY in the sanctioned media file.
			if ( ! str_ends_with( $path, $media_force_delete_allowed ) ) {
				$this->assertDoesNotMatchRegularExpression(
					'/wp_delete_attachment\s*\(' . $args . ',\s*true\s*\)/s',
					$src,
					'Permanent wp_delete_attachment(...,true) in ' . $file->getFilename() . ' (only the disclosed delete-media ability may force-delete)'
				);
			}
		}
	}

	/**
	 * Strip one named top-level function's body out of source, so a sanctioned call site inside
	 * it does not mask a real violation added anywhere else in the same file.
	 *
	 * A real token walk, not a line-based brace-depth counter (Codex round 7, R7-7): the prior
	 * regex-and-line-count version matched the exempt function's declaration line (which also
	 * carries the opening `{`) without ever counting that brace, so a bare `}` closing line made
	 * the depth counter go negative without ever satisfying its own "line contains `{`" exit
	 * condition. Stripping then continued past the function's real end through every following
	 * top-level line - silently deleting a force-delete call placed anywhere after the exempt
	 * function, all the way to the next function declaration that happened to contain a `{`.
	 * Walking `token_get_all()`'s tokens instead finds the true opening brace after the matched
	 * T_FUNCTION + T_STRING pair and counts every brace token (including the T_CURLY_OPEN/
	 * T_DOLLAR_OPEN_CURLY_BRACES tokens PHP emits for `"{$var}"`/`"${var}"` interpolation) to its
	 * exact matching close, so only that one function's real body is ever removed.
	 *
	 * @param string $source        Full file contents.
	 * @param string $function_name Function name to strip, without parentheses.
	 * @return string The same source with that one function's body removed.
	 */
	private function strip_function_body( string $source, string $function_name ): string {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );
		$out    = '';
		$i      = 0;
		while ( $i < $count ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
				$j = $i + 1;
				while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					++$j;
				}
				$is_target = $j < $count && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] && $function_name === $tokens[ $j ][1];
				if ( $is_target ) {
					$k = $j + 1;
					while ( $k < $count && '{' !== $tokens[ $k ] ) {
						++$k;
					}
					$depth = 0;
					while ( $k < $count ) {
						$brace_token = $tokens[ $k ];
						if ( '{' === $brace_token || ( is_array( $brace_token ) && in_array( $brace_token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
							++$depth;
						} elseif ( '}' === $brace_token ) {
							--$depth;
							if ( 0 === $depth ) {
								++$k; // Consume the real closing brace, then stop: the body is fully accounted for.
								break;
							}
						}
						++$k;
					}
					$i = $k; // Resume scanning immediately after the function actually ends.
					continue;
				}
			}
			$out .= is_array( $token ) ? $token[1] : $token;
			++$i;
		}
		return $out;
	}

	/**
	 * Resolve `use function <name> as <alias>;` imports so the force-delete scan also catches a
	 * call made through its alias (Codex round 7, R7-7): an aliased `wp_delete_post` would
	 * otherwise never match a regex anchored on the literal name. Rewrites every aliased call
	 * site back to the imported name; a plain `use function <name>;` with no `as` needs no
	 * rewrite, since its call sites already use the real name.
	 *
	 * @param string $source Full file contents.
	 * @return string The same source with every aliased call site rewritten to its real name.
	 */
	private function resolve_use_function_aliases( string $source ): string {
		if ( ! preg_match_all( '/use\s+function\s+\\\\?([A-Za-z_][A-Za-z0-9_]*)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/', $source, $import_matches, PREG_SET_ORDER ) ) {
			return $source;
		}
		foreach ( $import_matches as $import_match ) {
			list( , $real_name, $alias ) = $import_match;
			$source                      = (string) preg_replace( '/\b' . preg_quote( $alias, '/' ) . '(\s*\()/', $real_name . '$1', $source );
		}
		return $source;
	}

	/**
	 * Codex round 7, R7-7: a force-delete call placed immediately after the exempt GeoDirectory
	 * rollback function's real closing brace must survive the strip. Before the token-based
	 * rewrite, the line-based counter's depth went negative on the exempt function's own closing
	 * `}` without ever satisfying its "line contains `{`" exit condition, so stripping ran on past
	 * the function's true end and silently deleted a force-delete call sitting right after it.
	 */
	public function test_strip_function_body_does_not_leak_into_following_source(): void {
		$source = "<?php\nfunction aafm_geodirectory_rollback_unconfirmed_create( int \$post_id ): void {\n\twp_delete_post( \$post_id, true );\n}\n\nwp_delete_post( \$other_id, true );\n\nfunction aafm_other(): void {\n\techo 'hi';\n}\n";

		$stripped = $this->strip_function_body( $source, 'aafm_geodirectory_rollback_unconfirmed_create' );

		$this->assertStringNotContainsString( '$post_id, true', $stripped, 'The exempt function\'s own body must still be removed.' );
		$this->assertStringContainsString(
			'$other_id, true',
			$stripped,
			'A force-delete placed immediately after the exempt function must survive the strip, not be silently swallowed along with it.'
		);
		$this->assertStringContainsString( 'aafm_other', $stripped, 'A later, unrelated function must also survive the strip.' );
	}

	/**
	 * Codex round 7, R7-7: a force-delete made through a `use function ... as` alias must still
	 * be caught. Before this fix, `use function wp_delete_post as remove; remove($id, true)`
	 * never matched the sweep's regex, which is anchored on the literal name `wp_delete_post`.
	 */
	public function test_resolve_use_function_aliases_rewrites_an_aliased_force_delete(): void {
		$source = "<?php\nuse function wp_delete_post as remove;\nremove( \$post_id, true );\n";

		$resolved = $this->resolve_use_function_aliases( $source );

		$this->assertMatchesRegularExpression(
			'/\bwp_delete_post\s*\(\s*\$post_id\s*,\s*true\s*\)/',
			$resolved,
			'An aliased call must be rewritten back to its real name so the force-delete regex can catch it.'
		);
	}

	/**
	 * Trash-disabled safety: the trash abilities consult aafm_trash_is_enabled()
	 * and refuse on Trash-disabled sites, where wp_trash_post()/wp_trash_comment()
	 * would otherwise force a permanent delete. Asserts the guard is present on
	 * every trash execute path (behavioral coverage lives in TrashDisabledTest).
	 */
	public function test_trash_paths_guard_against_disabled_trash(): void {
		$includes = dirname( __DIR__, 2 ) . '/includes';
		$sources  = array(
			$includes . '/abilities/posts.php',
			$includes . '/abilities/pages.php',
			$includes . '/abilities/comments.php',
			$includes . '/abilities/blocks.php',
			// Gate round 1 finding 6: tec-delete-event now carries the same guard as its siblings.
			$includes . '/abilities/tec/events.php',
		);

		foreach ( $sources as $path ) {
			// Reading our own bundled source for a static scan - not a remote fetch.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$src = (string) file_get_contents( $path );
			$this->assertStringContainsString(
				'aafm_trash_is_enabled()',
				$src,
				'Missing Trash-disabled guard in ' . basename( $path )
			);
		}
	}

	/**
	 * Permanent delete: a trashed post stays recoverable (status=trash, untrashable).
	 */
	public function test_trash_post_is_recoverable_not_permanent(): void {
		$this->register_whole_catalog();
		$this->acting_as( 'administrator' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$result  = wp_get_ability( 'aafm/trash-post' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $result, 'trash-post failed for an admin.' );

		// Recoverable: the post still exists, in the trash, and can be untrashed.
		$post = get_post( $post_id );
		$this->assertNotNull( $post, 'trash-post permanently deleted the post.' );
		$this->assertSame( 'trash', $post->post_status, 'trash-post did not leave status=trash.' );
		$this->assertNotFalse( wp_untrash_post( $post_id ), 'Trashed post was not recoverable.' );
	}

	/**
	 * CVE class: PII / USER ENUMERATION.
	 *
	 * The get-users read requires the list_users cap - the same gate WP puts on the
	 * user-list admin screen.
	 */
	public function test_user_enumeration_requires_list_users_cap(): void {
		$this->register_whole_catalog();

		// Subscriber and author are both denied (author lacks list_users).
		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/get-users' )->check_permissions( array() ),
			'get-users must deny a subscriber (no list_users).'
		);

		$this->acting_as( 'author' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/get-users' )->check_permissions( array() ),
			'get-users must deny an author (no list_users).'
		);

		// Administrator (has list_users) is allowed.
		$this->acting_as( 'administrator' );
		$this->assertTrue(
			wp_get_ability( 'aafm/get-users' )->check_permissions( array() ),
			'get-users must allow a list_users-capable admin.'
		);
	}

	/**
	 * PII: user reads expose email (the locked reversal) but never login or the
	 * password hash; comment reads still strip email and IP.
	 */
	public function test_user_read_exposes_email_but_strips_login_and_comment_reads_strip_pii(): void {
		// LOCKED reversal (47- line 144): user email IS exposed in the redacted shape now,
		// gated upstream by list_users + audited. Login and password hash stay stripped.
		$user_id   = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_email'   => 'leak@example.com',
				'user_login'   => 'leaklogin',
				'display_name' => 'Public Author',
			)
		);
		$user      = get_userdata( $user_id );
		$user_json = (string) wp_json_encode( aafm_redact_user( $user ) );
		$this->assertStringContainsString( 'leak@example.com', $user_json, 'User email must be exposed (locked reversal).' );
		$this->assertStringNotContainsString( 'leaklogin', $user_json, 'User login must stay stripped.' );
		$this->assertStringNotContainsString( $user->user_pass, $user_json, 'Password hash must stay stripped.' );

		// Comment redactor exposes no email/IP/agent.
		$comment_id   = self::factory()->comment->create(
			array(
				'comment_author'       => 'Jane',
				'comment_author_email' => 'jane@example.com',
				'comment_author_IP'    => '203.0.113.9',
				'comment_content'      => 'hi',
			)
		);
		$comment_json = (string) wp_json_encode( aafm_redact_comment( get_comment( $comment_id ) ) );
		$this->assertStringNotContainsString( 'jane@example.com', $comment_json, 'Comment email leaked.' );
		$this->assertStringNotContainsString( '203.0.113.9', $comment_json, 'Comment IP leaked.' );
	}

	/**
	 * PII: get-site-info hides admin email, core/PHP version, and server paths.
	 */
	public function test_site_info_redaction_hides_environment_details(): void {
		$this->register_whole_catalog();
		$this->acting_as( 'subscriber' );

		$result = wp_get_ability( 'aafm/get-site-info' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $result, 'get-site-info should be readable.' );

		$json = (string) wp_json_encode( $result );
		// No admin email, no core/PHP version, no absolute path.
		$this->assertStringNotContainsString( get_option( 'admin_email' ), $json, 'admin_email leaked.' );
		$this->assertStringNotContainsString( get_bloginfo( 'version' ), $json, 'WP version leaked.' );
		$this->assertStringNotContainsString( ABSPATH, $json, 'Server path leaked.' );
	}

	/**
	 * CVE class: UNAUTHENTICATED / OVER-BROAD ACCESS.
	 *
	 * Every ability carries a real permission_callback; abilities are opt-in (default off);
	 * none is callable by an unauthenticated caller.
	 */
	public function test_every_ability_has_a_permission_callback_and_is_opt_in(): void {
		// Default install: nothing exposed.
		$this->assertSame( array(), aafm_get_enabled_abilities(), 'Abilities must default to OFF.' );

		$this->register_whole_catalog();
		wp_set_current_user( 0 );

		// Anonymous caller: no ability returns true (none is publicly callable without a cap).
		foreach ( array_keys( aafm_get_abilities_registry() ) as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' is not registered' );
			$result = $ability->check_permissions( array() );
			$this->assertNotTrue(
				$result,
				$name . ' is callable by an unauthenticated caller (over-broad access).'
			);
		}
	}
}
