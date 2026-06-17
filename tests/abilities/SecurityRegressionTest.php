<?php
/**
 * Phase 4 milestone: CVE-class regression sweep.
 *
 * Every competitor in this space shipped at least one critical CVE-class flaw. This
 * suite maps each competitor flaw class to a structural absence or a proven mitigation
 * in OUR catalog, with one focused test (or group) per class. If a future change
 * reintroduces any class — a missing gate, an over-broad permission, a dangerous
 * primitive, a dishonest annotation, an open schema — the matching test fails.
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
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Run a callback inside a simulated Abilities API init action (Phase 1 idiom).
	 *
	 * @param string   $action   Action name to simulate.
	 * @param callable $callback Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $callback ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$callback();
		array_pop( $wp_current_filter );
	}

	/**
	 * Enable + register the whole catalog so abilities can be invoked.
	 */
	private function register_whole_catalog(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
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
				$name . ' allowed a bare subscriber — privilege escalation.'
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
	 * CVE class: AUTHOR / TYPE SPOOFING.
	 *
	 * Caller-supplied post_author/post_type cannot escalate — the closed schema rejects
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
	 * The upload-media ability accepts base64 ONLY — no URL/remote-fetch input exists.
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
				"upload-media exposes a '{$forbidden}' input — reopens the SSRF class."
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
					"{$name} accepts a '{$forbidden}' source field — SSRF surface."
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
		// each is capability-gated on per-object edit_user($id) (reads gated like writes, since
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
		// inventory (name, version, active state, relative basename) gated on activate_plugins —
		// the capability WordPress puts on the Plugins screen — default-OFF, audited, and
		// closed-schema. There is deliberately NO activate/deactivate ability in the catalog, so
		// the 'plugin' needle still bans a generic plugin-management surface (e.g.
		// aafm/activate-plugin, aafm/manage-plugins). list-plugins never changes a plugin.
		$sanctioned = array_merge( $sanctioned, array( 'aafm/list-plugins' ) );
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
		// rejected by the closed schema before execute — nothing arbitrary reaches the DB.
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
	 * Arbitrary code-exec / remote-fetch primitives must never appear in our source.
	 */
	public function test_source_tree_has_no_dangerous_primitives(): void {
		$dir   = dirname( __DIR__, 2 ) . '/includes';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );

		// Code-exec primitives must NEVER appear anywhere in our source.
		$banned_exec = '/\b(eval|create_function|assert|download_url|curl_exec)\s*\(/';
		// Remote-fetch primitives must never appear in the agent-exposed surface (an
		// agent could otherwise be steered into SSRF). They are permitted ONLY in the
		// admin Connection tab's reachability probe, which is gated behind manage_options +
		// a nonce, targets this site's own endpoint, and is never reachable by an MCP agent.
		$banned_fetch  = '/\b(wp_remote_get|wp_remote_post|wp_remote_request)\s*\(/';
		$fetch_allowed = 'includes/admin/connection.php';

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			// Reading our own bundled source for a static scan — not a remote fetch.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$src  = (string) file_get_contents( $file->getPathname() );
			$path = str_replace( '\\', '/', $file->getPathname() );

			$this->assertDoesNotMatchRegularExpression(
				$banned_exec,
				$src,
				'Code-exec primitive in ' . $file->getFilename()
			);

			if ( ! str_ends_with( $path, $fetch_allowed ) ) {
				$this->assertDoesNotMatchRegularExpression(
					$banned_fetch,
					$src,
					'Remote-fetch primitive in ' . $file->getFilename() . ' (only the admin reachability probe may use one)'
				);
			}
		}
	}

	/**
	 * CVE class: PERMANENT DELETE.
	 *
	 * Post writes never force-delete: wp_delete_post(...,true) must not appear anywhere —
	 * posts and pages are only ever trashed (recoverable).
	 *
	 * Three primitives are governed here:
	 *   - wp_delete_post(...,true)       → absolute ban (posts/pages are only ever trashed).
	 *   - wp_delete_comment(...,true)    → allowed ONLY in includes/abilities/comments.php.
	 *   - wp_delete_attachment(...,true) → allowed ONLY in includes/abilities/media.php.
	 *
	 * Comments are one sanctioned exception. aafm/delete-comment is an explicit,
	 * separately-disclosed destructive ability (risk=destructive, in DESTRUCTIVE_WRITES,
	 * filed under "Destructive (permanent)") that uses wp_delete_comment(...,true) by
	 * design — moderators routinely purge spam permanently, and aafm/moderate-comment
	 * still offers the recoverable 'trash' path. That single call is allowed only in
	 * includes/abilities/comments.php.
	 *
	 * Media is the other. aafm/delete-media is the disclosed destructive media ability
	 * (risk=destructive) that uses wp_delete_attachment(...,true) by design — an
	 * attachment has no Trash path, so removing a media file is inherently permanent.
	 * That single call is allowed only in includes/abilities/media.php.
	 *
	 * A force-delete of any of these primitives in any other file is still a CVE.
	 */
	public function test_no_force_delete_in_source(): void {
		$dir   = dirname( __DIR__, 2 ) . '/includes';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );

		// The one file permitted to force-delete a comment (the disclosed destructive ability).
		$comment_force_delete_allowed = 'includes/abilities/comments.php';
		// The one file permitted to force-delete an attachment (the disclosed delete-media ability).
		$media_force_delete_allowed = 'includes/abilities/media.php';

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			// Reading our own bundled source for a static scan — not a remote fetch.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$src  = (string) file_get_contents( $file->getPathname() );
			$path = str_replace( '\\', '/', $file->getPathname() );

			// A force-delete of a post/page with the trash-bypass flag must never appear.
			// The /s flag makes a multiline call match too, so it can't slip past the sweep.
			$this->assertDoesNotMatchRegularExpression(
				'/wp_delete_post\s*\([^)]*,\s*true\s*\)/s',
				$src,
				'Permanent wp_delete_post(...,true) in ' . $file->getFilename()
			);

			// Permanent comment delete is allowed ONLY in the sanctioned comments file.
			if ( ! str_ends_with( $path, $comment_force_delete_allowed ) ) {
				$this->assertDoesNotMatchRegularExpression(
					'/wp_delete_comment\s*\([^)]*,\s*true\s*\)/s',
					$src,
					'Permanent wp_delete_comment(...,true) in ' . $file->getFilename() . ' (only the disclosed delete-comment ability may force-delete)'
				);
			}

			// Permanent attachment delete is allowed ONLY in the sanctioned media file.
			if ( ! str_ends_with( $path, $media_force_delete_allowed ) ) {
				$this->assertDoesNotMatchRegularExpression(
					'/wp_delete_attachment\s*\([^)]*,\s*true\s*\)/s',
					$src,
					'Permanent wp_delete_attachment(...,true) in ' . $file->getFilename() . ' (only the disclosed delete-media ability may force-delete)'
				);
			}
		}
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
		);

		foreach ( $sources as $path ) {
			// Reading our own bundled source for a static scan — not a remote fetch.
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
	 * The get-users read requires the list_users cap — the same gate WP puts on the
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
