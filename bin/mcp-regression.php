<?php
/**
 * MCP regression harness for the Agent Abilities for MCP plugin.
 *
 * Drives the live MCP endpoint over HTTP exactly the way a real agent would
 * (initialize -> tools/list -> tools/call), exercising every enabled tool against
 * throwaway fixtures it creates and then permanently deletes. Nothing pre-existing
 * on the site is read or mutated; a shutdown handler guarantees cleanup even if a
 * step throws.
 *
 * This is a developer QA tool, not part of the shipped plugin. It lives under
 * /bin, which is export-ignored from the wordpress.org build.
 *
 * LOCAL vs REMOTE TARGETS
 *   Some fixtures (the post-meta/term-meta allowlists, the throwaway agent-writable CPT, the ACF
 *   field group, and a database-backed wp_template to edit) are FILTER/code configuration that can
 *   only be set host-side through a LOCAL DDEV WP-CLI bridge (`ddev` / `ddev wp` / `ddev exec`). That
 *   bridge always talks to the local DDEV site, never the --url target, so it is usable only when the
 *   harness's --url actually IS that local site ("local mode").
 *
 *   The harness auto-detects this: it reads the DDEV site's home URL once and compares its host to the
 *   --url host. They match -> local mode (full coverage, every fixture configured and cleaned via the
 *   bridge). They differ, or `ddev` is unavailable -> "remote mode": the bridge is NEVER touched (so a
 *   remote run cannot mutate the local site), the fixture-setup-dependent checks SKIP with a clear
 *   reason, and only the over-the-wire tools run. Pass --no-cli (alias --remote) to force remote mode
 *   regardless of detection.
 *
 *   In remote mode the harness also refuses, by default, to create anything it could not clean up purely
 *   over MCP: terms (no delete-term ability) and reusable blocks (delete-block only trashes — there is no
 *   MCP force-delete) are SKIPped rather than left as orphans; read-path term tests against pre-existing
 *   terms still run. Escape hatches close the remaining remote gaps without the bridge: the meta write
 *   paths run over pure MCP when the operator names an allowlisted key (--meta-key / --term-meta-key /
 *   --user-meta-key) — term-meta is exercised against the EXISTING category 1 so no term is created — and
 *   CPT/template coverage auto-discovers an agent-writable type / a custom template from the remote's own
 *   config. The term create/update lifecycle (--remote-terms) and the reusable-block lifecycle
 *   (--remote-blocks) stay opt-in because neither can be fully cleaned over MCP; each leaves one warned
 *   residue (an orphan category term, a trashed wp_block).
 *
 * USAGE
 *   php bin/mcp-regression.php --url=https://example.com --user=admin --pass="xxxx xxxx xxxx xxxx xxxx xxxx"
 *
 *   --url            Site base URL (the MCP route is appended) or the full MCP endpoint.
 *   --user           WordPress username for Application Password auth.
 *   --pass           Application Password (spaces are fine; they are stripped).
 *   --bearer         OAuth bearer token, used instead of --user/--pass.
 *   --meta-key       An allowlisted post-meta key to exercise the post-meta tools for real
 *                    (pure MCP, snapshot-safe; works on a remote target too).
 *   --term-meta-key  An allowlisted term-meta key to exercise the term-meta tools against the
 *                    EXISTING category 1 ("Uncategorized") over MCP (snapshot-safe; remote-capable).
 *   --user-meta-key  An allowlisted user-meta key to exercise the user-meta tools against the
 *                    throwaway user the harness creates and deletes (pure MCP; remote-capable).
 *   --cpt            An agent-writable custom post type slug to exercise the CPT tools. On a remote
 *                    target, when omitted, the harness auto-discovers a writable type via get-post-types.
 *   --template-id    A specific (custom / DB-backed) template id to exercise update-template against.
 *                    On a remote target, when omitted, the harness auto-discovers one from list-templates.
 *   --remote-blocks  On a remote target, opt in to the full reusable-block lifecycle. delete-block only
 *                    trashes (no MCP force-delete), so one trashed wp_block is left behind and warned about.
 *   --remote-terms   On a remote target, opt in to the full term create/update lifecycle (create-term ->
 *                    get-term -> get-terms -> update-term). There is no delete-term MCP ability, so one
 *                    orphan category term is left behind and warned about. No-op in local mode (terms are
 *                    created and swept via the WP-CLI bridge there as usual).
 *   --no-cli         Force remote mode: never use the local DDEV WP-CLI bridge, even if it would
 *   --remote         reach the target. (Alias of --no-cli.) Local-only fixture-setup tests SKIP instead.
 *   --keep           Do not delete the fixtures (for inspecting state after a run).
 *   --list           Only initialize + tools/list, then exit (quick connectivity check).
 *   --verbose        Print every request and response.
 *
 * Note: against a REMOTE target the post-/term-/user-meta, CPT, and template write paths run only when
 * the matching config actually exists on that site — supplied explicitly via --meta-key / --term-meta-key /
 * --user-meta-key / --cpt / --template-id, or auto-discovered (writable CPT via get-post-types, custom
 * template via list-templates). When neither a flag nor a discovered fixture is available, the check SKIPs
 * with a clear reason. Every remote write path is snapshot-safe and leaves zero residue; the exceptions are
 * the two opt-in lifecycles: the reusable-block lifecycle (--remote-blocks) leaves one trashed wp_block, and
 * the term create/update lifecycle (--remote-terms) leaves one orphan category term — both warned at the
 * end, because neither delete-block (only trashes, no MCP force-delete) nor a delete-term ability (none
 * exists) can finish the cleanup over the wire.
 *
 * Environment fallbacks: AAFM_MCP_URL, AAFM_MCP_USER, AAFM_MCP_PASS, AAFM_MCP_BEARER.
 *
 * Exit code is the number of FAILed checks (0 = clean).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

/**
 * Thrown by fatal() to abort the current run. Catchable on purpose: a transport
 * fatal that fires DURING cleanup (e.g. the endpoint is unreachable — the exact
 * case cleanup exists to handle) must not exit() and skip the remaining
 * restores. The top-level bootstrap catches it for the normal-run path; the
 * per-step guards in cleanup()/flush_pending_restores() (and call_quiet()) swallow
 * it during teardown so one dead call can't abort the rest.
 */
final class AAFM_Fatal extends \Exception {}

/**
 * Tiny client + assertion harness for the MCP endpoint. Single class keeps the
 * file self-contained — no autoloader, no WordPress bootstrap.
 */
final class AAFM_Mcp_Regression {

	private const MCP_ROUTE       = '/wp-json/agent-abilities-for-mcp/mcp';
	private const PROTOCOL        = '2025-06-18';
	private const FIXTURE_PREFIX  = 'AAFM-REGRESSION';

	/** @var array<string,mixed> */
	private array $opts;
	private string $endpoint;
	private string $auth_header;
	private string $marker;
	private ?string $session_id      = null;
	private string $protocol_version = self::PROTOCOL;
	private int $rpc_id              = 0;

	/** @var list<array{section:string,label:string,status:string,detail:string}> */
	private array $results = [];

	/** @var list<int> */
	private array $created_posts = [];
	/** @var list<int> */
	private array $created_pages = [];
	/** @var list<int> */
	private array $created_comments = [];
	/** @var list<int> */
	private array $created_users = [];
	/** @var list<int> */
	private array $created_media = [];
	/** @var list<int> */
	private array $created_menus = [];
	/** @var list<int> */
	private array $created_menu_items = [];
	/** @var list<int> */
	private array $created_blocks = [];
	/** @var list<int> */
	private array $created_terms = [];
	/**
	 * Database-backed wp_template/wp_template_part posts created host-side via WP-CLI to give
	 * update-template a custom (source!=theme, wp_id-backed) template to edit.
	 *
	 * @var list<int>
	 */
	private array $created_templates = [];

	private bool $use_color;

	/**
	 * Cached result of cli_targets_endpoint(): does the local DDEV WP-CLI bridge reach the --url
	 * target? null = not yet probed. true = "local mode" (bridge usable). false = "remote mode"
	 * (bridge NEVER touched — fixture setup/cleanup that needs it is skipped, and a remote run cannot
	 * mutate the local site). Forced to false by --no-cli/--remote.
	 */
	private ?bool $cli_targets_endpoint = null;

	/** Whether the temporary fixtures mu-plugin (term-meta allowlist + ACF field group) is installed. */
	private bool $fixture_plugin_installed = false;

	/**
	 * Pending option restores, keyed by option name => prior snapshot (or null when the snapshot
	 * could not be read). Registered BEFORE an option is mutated so that cleanup() — which runs on
	 * shutdown, including after fatal() exits mid-test — can put every mutated option back to its
	 * exact prior state. A clean inline restore de-registers its entry; whatever remains is the set
	 * cleanup() must repair.
	 *
	 * @var array<string,array{exists:bool,json:string}|null>
	 */
	private array $pending_option_restores = [];

	/**
	 * Pending non-option reversals, keyed by a label => closure that undoes one MCP-mediated mutation
	 * (e.g. the tagline flip and the template edit, which are not WP options). Registered BEFORE the
	 * mutation and de-registered after a verified inline restore, so cleanup() reverses any that a
	 * mid-test fatal() left pending.
	 *
	 * @var array<string,callable():void>
	 */
	private array $pending_restores = [];

	/**
	 * @param array<string,mixed> $opts Parsed CLI options.
	 */
	public function __construct( array $opts ) {
		$this->opts = $opts;

		$url = rtrim( (string) ( $opts['url'] ?? '' ), '/' );
		if ( '' === $url ) {
			$this->fatal( 'Missing --url (or AAFM_MCP_URL).' );
		}
		// Accept either a base URL or the full endpoint.
		if ( false !== strpos( $url, '/wp-json/' ) || false !== strpos( $url, 'rest_route=' ) ) {
			$this->endpoint = $url;
		} else {
			$this->endpoint = $url . self::MCP_ROUTE;
		}

		$bearer = (string) ( $opts['bearer'] ?? '' );
		if ( '' !== $bearer ) {
			$this->auth_header = 'Authorization: Bearer ' . $bearer;
		} else {
			$user = (string) ( $opts['user'] ?? '' );
			$pass = str_replace( ' ', '', (string) ( $opts['pass'] ?? '' ) );
			if ( '' === $user || '' === $pass ) {
				$this->fatal( 'Provide --user and --pass (Application Password), or --bearer.' );
			}
			$this->auth_header = 'Authorization: Basic ' . base64_encode( $user . ':' . $pass );
		}

		// Unique, identifiable marker so fixtures are easy to spot if cleanup is skipped.
		$this->marker    = self::FIXTURE_PREFIX . '-' . gmdate( 'Ymd-His' ) . '-' . substr( (string) getmypid(), -4 );
		$this->use_color = ! isset( $opts['no-color'] ) && false !== getenv( 'TERM' );
	}

	/** Entry point. */
	public function run(): int {
		// Cleanup always runs, even on an uncaught throwable mid-flight.
		register_shutdown_function( [ $this, 'cleanup' ] );

		$this->line( "MCP regression -> {$this->endpoint}" );
		$this->line( "Fixture marker: {$this->marker}" );
		$this->line( '' );

		$this->bootstrap_session();

		$tools = $this->discover_tools();

		if ( isset( $this->opts['purge'] ) ) {
			$this->purge_orphans();
			return 0;
		}

		if ( isset( $this->opts['list'] ) ) {
			$this->line( 'Exposed tools (' . count( $tools ) . "):" );
			foreach ( $tools as $name ) {
				$this->line( "  - {$name}" );
			}
			return 0;
		}

		$this->test_posts_lifecycle( $tools );
		$this->test_meta_lifecycle();
		$this->test_revisions_lifecycle();
		$this->test_terms();
		$this->test_terms_full_lifecycle();
		$this->test_comments_lifecycle();
		$this->test_users_lifecycle();
		$this->test_user_meta_lifecycle();
		$this->test_pages_lifecycle( $tools );
		$this->test_cpt_lifecycle();
		$this->test_structure_reads();
		$this->test_search_lifecycle();
		$this->test_plugins_reads();
		$this->test_activity_log_reads();
		$this->test_themes_lifecycle();
		$this->test_settings_lifecycle();
		$this->test_media_lifecycle();
		$this->test_menus_lifecycle();
		$this->test_blocks_lifecycle();
		$this->test_acf_lifecycle();

		// Cleanup runs now (so the baseline comparison sees a clean site), and the
		// shutdown handler becomes a no-op afterwards.
		$this->cleanup();
		$this->verify_baseline_restored();

		return $this->summary();
	}

	/* ---------------------------------------------------------------------
	 * Protocol plumbing
	 * ------------------------------------------------------------------- */

	private function bootstrap_session(): void {
		[ $body, $headers ] = $this->http_post( [
			'jsonrpc' => '2.0',
			'id'      => ++$this->rpc_id,
			'method'  => 'initialize',
			'params'  => [
				'protocolVersion' => self::PROTOCOL,
				'capabilities'    => (object) [],
				'clientInfo'      => [ 'name' => 'aafm-regression', 'version' => '1.0.0' ],
			],
		] );

		$sid = $headers['mcp-session-id'] ?? null;
		if ( $sid ) {
			$this->session_id = $sid;
		}
		$negotiated = $body['result']['protocolVersion'] ?? null;
		if ( is_string( $negotiated ) && '' !== $negotiated ) {
			$this->protocol_version = $negotiated;
		}

		if ( isset( $body['error'] ) ) {
			$this->fatal( 'initialize failed: ' . wp_json_safe( $body['error'] ) );
		}
		$this->line( "Session established (protocol {$this->protocol_version})" . ( $this->session_id ? '' : ' [no session header returned]' ) );

		// Be a well-behaved client: tell the server we are ready. Notification -> no response body.
		$this->http_post( [
			'jsonrpc' => '2.0',
			'method'  => 'notifications/initialized',
			'params'  => (object) [],
		], true );
		$this->line( '' );
	}

	/**
	 * @return list<string> Exposed tool names (short, without the server prefix).
	 */
	private function discover_tools(): array {
		$body = $this->rpc( 'tools/list', (object) [] );
		$tools = $body['result']['tools'] ?? [];
		$names = [];
		foreach ( $tools as $t ) {
			if ( isset( $t['name'] ) ) {
				$names[] = (string) $t['name'];
			}
		}
		sort( $names );
		$this->tool_names = $names; // Publish for resolve_tool().

		$expected = [
			'add-post-terms', 'count-posts', 'create-cpt-item', 'create-draft', 'create-page',
			'create-post', 'delete-page', 'delete-post', 'delete-post-meta', 'delete-revision',
			'get-all-post-meta', 'get-page', 'get-pages', 'get-post', 'get-post-meta', 'get-posts',
			'get-revision', 'list-revisions', 'replace-in-post', 'restore-revision', 'trash-page',
			'trash-post', 'update-cpt-item', 'update-page', 'update-post', 'update-post-meta',
		];
		// Tool names may carry a server-specific prefix; match on suffix.
		$present = static function ( string $needle ) use ( $names ): bool {
			foreach ( $names as $n ) {
				if ( $n === $needle || str_ends_with( $n, $needle ) ) {
					return true;
				}
			}
			return false;
		};
		$missing = array_values( array_filter( $expected, static fn( $e ) => ! $present( $e ) ) );

		$this->record(
			'Discovery',
			'tools/list exposes the expected tool set',
			empty( $missing ) ? 'PASS' : 'FAIL',
			empty( $missing )
				? count( $names ) . ' tools exposed'
				: 'missing: ' . implode( ', ', $missing )
		);

		return $names;
	}

	/* ---------------------------------------------------------------------
	 * Test sections
	 * ------------------------------------------------------------------- */

	private function test_posts_lifecycle( array $tools ): void {
		$section  = 'Posts';
		$baseline = $this->find_count( $this->call_data( 'count-posts', [ 'post_type' => 'post' ] ), 'publish' );
		$this->record( $section, 'count-posts returns a publish total', null === $baseline ? 'FAIL' : 'PASS', 'publish=' . var_export( $baseline, true ) );
		$this->baseline_publish = $baseline;

		// create-post (publishes).
		$created = $this->call( 'create-post', [
			'title'   => $this->marker . ' post',
			'content' => '<p>alpha BETA gamma</p>',
			'status'  => 'publish',
		] );
		$post_id = (int) ( $this->post_of( (array) ( $created['data'] ?? [] ) )['id'] ?? 0 );
		if ( $post_id > 0 ) {
			$this->created_posts[] = $post_id;
		}
		$this->record( $section, 'create-post creates a published post', $post_id > 0 && ! $created['isError'] ? 'PASS' : 'FAIL', "id={$post_id}" );
		if ( $post_id <= 0 ) {
			$this->record( $section, 'remaining post tests', 'FAIL', 'no post id; skipping dependent steps' );
			return;
		}

		// get-post round-trips the fields.
		$got = $this->call_post( 'get-post', [ 'post_id' => $post_id, 'content_format' => 'raw' ] );
		$ok  = isset( $got['title'] ) && false !== strpos( (string) $this->scalar( $got['title'] ), $this->marker )
			&& 'publish' === ( $got['status'] ?? '' )
			&& false !== strpos( (string) ( $got['content'] ?? '' ), 'alpha' );
		$this->record( $section, 'get-post round-trips title/status/content', $ok ? 'PASS' : 'FAIL', 'status=' . ( $got['status'] ?? '?' ) );

		// get-posts finds it by search.
		$list  = $this->call_data( 'get-posts', [ 'search' => $this->marker, 'per_page' => 10 ] );
		$found = false;
		foreach ( ( $list['posts'] ?? $list['items'] ?? [] ) as $item ) {
			if ( (int) ( $item['id'] ?? 0 ) === $post_id ) {
				$found = true;
				break;
			}
		}
		$this->record( $section, 'get-posts finds the post via search', $found ? 'PASS' : 'FAIL', 'total=' . var_export( $list['total'] ?? null, true ) );

		// update-post changes title + content.
		$upd = $this->call( 'update-post', [
			'post_id' => $post_id,
			'title'   => $this->marker . ' post updated',
			'content' => '<p>delta EPSILON</p>',
		] );
		$after = $this->call_post( 'get-post', [ 'post_id' => $post_id, 'content_format' => 'raw' ] );
		$ok    = ! $upd['isError']
			&& false !== strpos( (string) $this->scalar( $after['title'] ?? '' ), 'updated' )
			&& false !== strpos( (string) ( $after['content'] ?? '' ), 'delta' );
		$this->record( $section, 'update-post changes title and content', $ok ? 'PASS' : 'FAIL', '' );

		// replace-in-post swaps a literal string.
		$rep   = $this->call( 'replace-in-post', [ 'post_id' => $post_id, 'search' => 'delta', 'replace' => 'omega' ] );
		$after = $this->call_post( 'get-post', [ 'post_id' => $post_id, 'content_format' => 'raw' ] );
		$body  = (string) ( $after['content'] ?? '' );
		$ok    = ! $rep['isError'] && false !== strpos( $body, 'omega' ) && false === strpos( $body, 'delta' );
		$this->record( $section, 'replace-in-post swaps the literal string', $ok ? 'PASS' : 'FAIL', '' );

		// create-draft stays a draft.
		$draft    = $this->call( 'create-draft', [ 'title' => $this->marker . ' draft', 'content' => '<p>draft body</p>' ] );
		$draft_id = (int) ( $this->post_of( (array) ( $draft['data'] ?? [] ) )['id'] ?? 0 );
		if ( $draft_id > 0 ) {
			$this->created_posts[] = $draft_id;
		}
		$status = $draft_id ? ( $this->call_post( 'get-post', [ 'post_id' => $draft_id ] )['status'] ?? '?' ) : '?';
		$this->record( $section, 'create-draft creates a draft (not published)', ( $draft_id > 0 && 'draft' === $status ) ? 'PASS' : 'FAIL', "id={$draft_id} status={$status}" );

		$this->primary_post_id = $post_id;
	}

	private function test_meta_lifecycle(): void {
		$section = 'Post meta';
		$post_id = $this->primary_post_id ?? 0;
		if ( ! $post_id ) {
			$this->record( $section, 'meta lifecycle', 'SKIP', 'no post fixture' );
			return;
		}

		// get-all-post-meta always works (returns a map; empty by default).
		$all = $this->call( 'get-all-post-meta', [ 'post_id' => $post_id ] );
		$this->record( $section, 'get-all-post-meta returns a map', ! $all['isError'] && is_array( $all['data'] ) ? 'PASS' : 'FAIL', '' );

		// Resolve the key to exercise: an explicit --meta-key override wins (pure MCP, snapshot-safe; works
		// on a remote target too); otherwise the fixtures mu-plugin's allowlisted key (aafm_regression_pm
		// via the aafm_allowed_meta_keys filter, local mode only). If neither is available (no override and
		// the mu-plugin could not install), SKIP the write path with a clear reason rather than fabricate a
		// permanent option entry.
		$key      = (string) ( $this->opts['meta-key'] ?? '' );
		$override = '' !== $key;

		// Governance probe: an unlisted post-meta key MUST be refused under a default-deny allowlist.
		// 'aafm_regression_probe' is never the configured key. The probe meta dies with the throwaway
		// primary post at cleanup, so an accepted write leaves nothing behind.
		$probe = $this->call( 'update-post-meta', [ 'post_id' => $post_id, 'meta_key' => 'aafm_regression_probe', 'value' => '1' ] );
		$this->record_default_deny_probe( $section, 'update-post-meta refuses an unlisted key (default)', (bool) $probe['isError'], $override );
		if ( ! $override ) {
			if ( ! $this->install_fixture_plugin() ) {
				$reason = $this->cli_targets_endpoint()
					? 'could not install the fixtures mu-plugin via the DDEV bridge'
					: 'requires local DDEV WP-CLI to configure the post-meta allowlist, or pass --meta-key for a remote target';
				$this->record( $section, 'meta write/get/delete (allowlist not configurable)', 'SKIP', $reason );
				return;
			}
			$key = 'aafm_regression_pm';
		}

		// Choose the host post. The mu-plugin (non-override) path keeps using the primary post fixture so
		// LOCAL coverage is unchanged. The --override path runs on a dedicated throwaway post and asserts
		// the key is absent first, so it is fully snapshot-safe against any target (local or remote) and
		// never reads/mutates a pre-existing meta value.
		$meta_pid = $post_id;
		if ( $override ) {
			$meta_host = $this->call( 'create-post', [
				'title'   => $this->marker . ' meta-host',
				'content' => '<p>post-meta host body</p>',
				'status'  => 'publish',
			] );
			$meta_pid = (int) ( $this->post_of( (array) ( $meta_host['data'] ?? [] ) )['id'] ?? 0 );
			if ( $meta_pid > 0 ) {
				$this->created_posts[] = $meta_pid;
			}
			if ( $meta_pid <= 0 ) {
				$this->record( $section, 'meta write/get/delete', 'FAIL', 'could not create the meta-host post' );
				return;
			}
			$before = $this->call_data( 'get-post-meta', [ 'post_id' => $meta_pid, 'meta_key' => $key ] );
			$this->record( $section, "post-meta key '{$key}' starts absent on the fixture", '' === (string) $this->scalar( $before['value'] ?? '' ) ? 'PASS' : 'FAIL', '' );

			// The harness cannot know the remote allowlists the supplied key; a refused write is handled
			// (reported SKIP), not a crash, so passing a non-allowlisted key on a remote stays clean.
			$probe_w = $this->call( 'update-post-meta', [ 'post_id' => $meta_pid, 'meta_key' => $key, 'value' => 'aafm-meta-value' ] );
			if ( $probe_w['isError'] ) {
				$this->record( $section, "update-post-meta writes '{$key}'", 'SKIP', 'target did not allowlist the supplied --meta-key (write refused cleanly)' );
				return;
			}
			$this->record( $section, "update-post-meta writes '{$key}'", 'PASS', '' );
		} else {
			// Real exercise against the allowlisted key: write -> get -> get-all -> delete.
			$w = $this->call( 'update-post-meta', [ 'post_id' => $meta_pid, 'meta_key' => $key, 'value' => 'aafm-meta-value' ] );
			$this->record( $section, "update-post-meta writes '{$key}'", ! $w['isError'] ? 'PASS' : 'FAIL', '' );
		}

		$r  = $this->call_data( 'get-post-meta', [ 'post_id' => $meta_pid, 'meta_key' => $key ] );
		$ok = 'aafm-meta-value' === (string) $this->scalar( $r['value'] ?? $r['meta_value'] ?? ( $r[ $key ] ?? '' ) );
		$this->record( $section, 'get-post-meta reads the value back', $ok ? 'PASS' : 'FAIL', '' );

		$all2    = $this->call_data( 'get-all-post-meta', [ 'post_id' => $meta_pid ] );
		$present = is_array( $all2 ) && ( array_key_exists( $key, $all2 ) || array_key_exists( $key, $all2['meta'] ?? [] ) );
		$this->record( $section, 'get-all-post-meta includes the key', $present ? 'PASS' : 'FAIL', '' );

		$d       = $this->call( 'delete-post-meta', [ 'post_id' => $meta_pid, 'meta_key' => $key ] );
		$after_d = $this->call_data( 'get-post-meta', [ 'post_id' => $meta_pid, 'meta_key' => $key ] );
		$del_ok  = ! $d['isError'] && (bool) ( $d['data']['deleted'] ?? false ) && '' === (string) $this->scalar( $after_d['value'] ?? '' );
		$this->record( $section, 'delete-post-meta removes the key', $del_ok ? 'PASS' : 'FAIL', '' );
	}

	private function test_revisions_lifecycle(): void {
		$section = 'Revisions';
		$post_id = $this->primary_post_id ?? 0;
		if ( ! $post_id ) {
			$this->record( $section, 'revisions lifecycle', 'SKIP', 'no post fixture' );
			return;
		}

		$list = $this->call_data( 'list-revisions', [ 'post_id' => $post_id ] );
		$revs = $list['items'] ?? $list['revisions'] ?? ( is_array( $list ) && isset( $list[0] ) ? $list : [] );
		if ( empty( $revs ) ) {
			$this->record( $section, 'list-revisions returns the edit history', 'SKIP', 'no revisions (revisions may be disabled on the site)' );
			return;
		}
		$this->record( $section, 'list-revisions returns the edit history', 'PASS', count( $revs ) . ' revision(s)' );

		$latest_id = (int) ( $revs[0]['id'] ?? 0 );

		$one     = $this->call( 'get-revision', [ 'post_id' => $post_id, 'revision_id' => $latest_id, 'content_format' => 'raw', 'with_diff' => true ] );
		$rev_doc = is_array( $one['data'] ) ? ( $one['data']['revision'] ?? $one['data'] ) : [];
		$this->record( $section, 'get-revision returns a revision body', ! $one['isError'] && isset( $rev_doc['content'] ) ? 'PASS' : 'FAIL', "rev={$latest_id}" );

		// Restore is verified body-first, not by a fixed marker. The original 'alpha' publish
		// state is NOT guaranteed to exist as a stored revision — WordPress captures revisions on
		// update, so a short edit chain can leave only the post-update bodies (e.g. 'delta', then
		// 'omega') in history with no 'alpha' revision at all. The old test restored end($revs)
		// and asserted the live body contained 'alpha', which fails whenever no alpha revision
		// exists or the list ordering differs. Instead: pick the OLDEST revision id (lowest id —
		// ordering-independent), capture its actual raw body via get-revision, restore THAT
		// revision, and assert the live post body now equals the revision's captured body. This
		// proves the restore round-trips whatever the revision genuinely holds.
		$target_id = 0;
		foreach ( $revs as $rv ) {
			$rid = (int) ( $rv['id'] ?? 0 );
			if ( $rid > 0 && ( 0 === $target_id || $rid < $target_id ) ) {
				$target_id = $rid;
			}
		}
		$rev_body = (string) ( $this->call( 'get-revision', [ 'post_id' => $post_id, 'revision_id' => $target_id, 'content_format' => 'raw' ] )['data']['revision']['content'] ?? '' );

		$res   = $this->call( 'restore-revision', [ 'post_id' => $post_id, 'revision_id' => $target_id ] );
		$after = (string) ( $this->call_post( 'get-post', [ 'post_id' => $post_id, 'content_format' => 'raw' ] )['content'] ?? '' );
		$ok    = ! $res['isError'] && '' !== $rev_body && trim( $after ) === trim( $rev_body );
		$this->record( $section, 'restore-revision reverts the live post', $ok ? 'PASS' : 'FAIL', "rev={$target_id} body-match=" . var_export( '' !== $rev_body && trim( $after ) === trim( $rev_body ), true ) );

		// Delete a revision and confirm it is gone from the history.
		$fresh    = $this->call_data( 'list-revisions', [ 'post_id' => $post_id ] );
		$fresh_revs = $fresh['items'] ?? $fresh['revisions'] ?? ( is_array( $fresh ) && isset( $fresh[0] ) ? $fresh : [] );
		$victim   = (int) ( end( $fresh_revs )['id'] ?? 0 );
		if ( $victim > 0 ) {
			$del   = $this->call( 'delete-revision', [ 'post_id' => $post_id, 'revision_id' => $victim ] );
			$again = $this->call_data( 'list-revisions', [ 'post_id' => $post_id ] );
			$again_revs = $again['items'] ?? $again['revisions'] ?? ( is_array( $again ) && isset( $again[0] ) ? $again : [] );
			$gone  = true;
			foreach ( $again_revs as $rv ) {
				if ( (int) ( $rv['id'] ?? 0 ) === $victim ) {
					$gone = false;
					break;
				}
			}
			$this->record( $section, 'delete-revision removes a revision', ! $del['isError'] && $gone ? 'PASS' : 'FAIL', "rev={$victim}" );
		} else {
			$this->record( $section, 'delete-revision removes a revision', 'SKIP', 'no revision to delete' );
		}
	}

	private function test_terms(): void {
		$section = 'Terms';
		$post_id = $this->primary_post_id ?? 0;
		if ( ! $post_id ) {
			$this->record( $section, 'add-post-terms', 'SKIP', 'no post fixture' );
			return;
		}
		// Category term 1 (Uncategorized) exists on every WordPress install.
		$add = $this->call( 'add-post-terms', [ 'post_id' => $post_id, 'taxonomy' => 'category', 'term_ids' => [ 1 ] ] );
		$got = $this->call_post( 'get-post', [ 'post_id' => $post_id ] );
		$has = false;
		foreach ( ( $got['terms']['category'] ?? [] ) as $term ) {
			if ( (int) ( $term['id'] ?? 0 ) === 1 ) {
				$has = true;
				break;
			}
		}
		$this->record( $section, 'add-post-terms appends a category', ! $add['isError'] && $has ? 'PASS' : 'FAIL', '' );
	}

	/**
	 * Read-path term tests against the PRE-EXISTING category 1 (Uncategorized). No fixture is created,
	 * so these never orphan anything. Used as the remote-mode stand-in for the create-term-based reads
	 * that test_terms_full_lifecycle() must skip when there is no local WP-CLI bridge to clean up the
	 * created term.
	 */
	private function test_terms_read_paths(): void {
		$section = 'Terms (full)';

		$gt     = $this->call_unwrap( 'get-term', [ 'taxonomy' => 'category', 'term_id' => 1 ], 'term' );
		$get_ok = (int) ( $gt['id'] ?? 0 ) === 1
			&& 'category' === ( $gt['taxonomy'] ?? '' )
			&& array_key_exists( 'count', $gt );
		$this->record( $section, 'get-term returns an existing term by id', $get_ok ? 'PASS' : 'FAIL', '' );

		$terms     = $this->call_data( 'get-terms', [ 'taxonomy' => 'category', 'per_page' => 50 ] )['terms'] ?? [];
		$found_one = false;
		foreach ( $terms as $t ) {
			if ( (int) ( $t['id'] ?? 0 ) === 1 ) {
				$found_one = true;
				break;
			}
		}
		$this->record( $section, 'get-terms lists existing category terms', $found_one ? 'PASS' : 'FAIL', 'count=' . count( $terms ) );
	}

	/**
	 * Remote-mode term create/update lifecycle (opt-in via --remote-terms). Mirrors --remote-blocks:
	 * there is NO delete-term MCP tool, so a created category term cannot be removed over the wire and
	 * the local WP-CLI bridge can't reach a remote target. Rather than skip the write path entirely,
	 * this runs create-term -> get-term -> get-terms -> update-term over pure MCP, then TRACKS the
	 * created term id in $remote_orphan_terms purely so the run warns at the end about the one orphan
	 * category it deliberately leaves behind. The id is NOT added to $created_terms (the WP-CLI cleanup
	 * list) because that sweep is a no-op in remote mode and would only mislead.
	 */
	private function test_terms_remote_lifecycle( string $section ): void {
		// create-term: marker name in category; returns {term:{id,name,slug,parent}}.
		$cr      = $this->call_unwrap( 'create-term', [
			'taxonomy'    => 'category',
			'name'        => $this->marker . ' term',
			'description' => 'aafm regression term',
		], 'term' );
		$term_id = (int) ( $cr['id'] ?? 0 );
		if ( $term_id > 0 ) {
			$this->remote_orphan_terms[] = $term_id;
		}
		$create_ok = $term_id > 0 && false !== strpos( (string) ( $cr['name'] ?? '' ), $this->marker );
		$this->record( $section, 'create-term creates a category term', $create_ok ? 'PASS' : 'FAIL', "id={$term_id}" );
		if ( $term_id <= 0 ) {
			$this->record( $section, 'remaining term tests', 'FAIL', 'no term id; skipping dependent steps' );
			return;
		}

		// get-term: round-trips the rich shape by id.
		$gt     = $this->call_unwrap( 'get-term', [ 'taxonomy' => 'category', 'term_id' => $term_id ], 'term' );
		$get_ok = (int) ( $gt['id'] ?? 0 ) === $term_id
			&& false !== strpos( (string) ( $gt['name'] ?? '' ), $this->marker )
			&& 'category' === ( $gt['taxonomy'] ?? '' )
			&& array_key_exists( 'count', $gt );
		$this->record( $section, 'get-term returns the term by id', $get_ok ? 'PASS' : 'FAIL', '' );

		// get-terms: the new term is findable via search by its marker name.
		$found = false;
		foreach ( ( $this->call_data( 'get-terms', [ 'taxonomy' => 'category', 'search' => $this->marker, 'per_page' => 50 ] )['terms'] ?? [] ) as $t ) {
			if ( (int) ( $t['id'] ?? 0 ) === $term_id ) {
				$found = true;
				break;
			}
		}
		$this->record( $section, 'get-terms finds the term via search', $found ? 'PASS' : 'FAIL', '' );

		// update-term: rename + change description, verify both round-trip via get-term.
		$ut       = $this->call( 'update-term', [
			'taxonomy'    => 'category',
			'term_id'     => $term_id,
			'name'        => $this->marker . ' term renamed',
			'description' => 'aafm regression term edited',
		] );
		$after_ut = $this->call_unwrap( 'get-term', [ 'taxonomy' => 'category', 'term_id' => $term_id ], 'term' );
		$upd_ok   = ! $ut['isError']
			&& false !== strpos( (string) ( $after_ut['name'] ?? '' ), 'renamed' )
			&& false !== strpos( (string) ( $after_ut['description'] ?? '' ), 'edited' );
		$this->record( $section, 'update-term renames + re-describes the term', $upd_ok ? 'PASS' : 'FAIL', '' );
	}

	/**
	 * Full term CRUD lifecycle on a throwaway category term + the term-meta governance/write path.
	 *
	 * There is NO delete-term MCP tool, so created terms carry the AAFM-REGRESSION marker in their
	 * NAME and are swept host-side via WP-CLI (`wp term delete category <id>`) in cleanup() and
	 * purge_orphans(). Term shape (aafm_redact_term / aafm_term_write_result): create/update return
	 * {term:{id,name,slug,parent}}; get/get-terms return the richer {id,name,slug,taxonomy,parent,
	 * count,description}. Term-meta params are taxonomy/term_id/meta_key/value (note meta_key, NOT
	 * the user-meta family's `key`); update returns {term_id,meta_key,value}, delete returns
	 * {deleted}.
	 *
	 * The term-meta allowlist is FILTER-only (aafm_allowed_term_meta_keys) — there is no option to
	 * snapshot/restore. The write path is exercised by dropping a temporary mu-plugin that adds one
	 * test key (and the ACF field group for test_acf_lifecycle), then removing it — a reversible code
	 * drop-in, not a live-state mutation. The probe (an unlisted key MUST be refused) is the
	 * governance PASS and runs regardless of whether the write path can be configured.
	 */
	private function test_terms_full_lifecycle(): void {
		$section  = 'Terms (full)';
		$meta_key = 'aafm_regression_tm';

		// There is NO delete-term MCP tool — created terms are swept host-side via the local WP-CLI
		// bridge. In remote mode that bridge can't reach the target, so creating a term here would
		// orphan it on the remote site. By default, run the read paths against the pre-existing
		// category 1 instead (no fixture created), then either exercise the term-meta write path against
		// that EXISTING term (when --term-meta-key names an allowlisted key — pure MCP, snapshot-safe, no
		// term created) or SKIP it. --remote-terms opts in to the full create/update lifecycle anyway,
		// accepting one warned orphan category term that cannot be removed over the wire.
		if ( ! $this->cli_targets_endpoint() ) {
			if ( isset( $this->opts['remote-terms'] ) ) {
				// Opt-in: run the create -> get -> get-terms -> update lifecycle over MCP, tracking the
				// created term for the end-of-run orphan warning (no MCP delete-term to remove it).
				$this->test_terms_remote_lifecycle( $section );
			} else {
				// Default: read-only against the pre-existing category 1, plus a SKIP noting the
				// create/update lifecycle is opt-in (it would orphan a term with no MCP delete).
				$this->test_terms_read_paths();
				$this->record( $section, 'create-term + update-term lifecycle', 'SKIP', 'create-term has no MCP delete; cleanup requires local DDEV WP-CLI. Pass --remote-terms to run it anyway (leaves one warned orphan category term)' );
			}
			$tmk = (string) ( $this->opts['term-meta-key'] ?? '' );
			if ( '' !== $tmk ) {
				$this->exercise_term_meta_on_existing( $section, $tmk );
			} else {
				$this->record( $section, 'term-meta write/get/delete', 'SKIP', 'term-meta allowlist (aafm_allowed_term_meta_keys) is filter-only and not configurable via an admin option on the target. Pass --term-meta-key to exercise term-meta on the existing category 1 over MCP' );
			}
			return;
		}

		// create-term: marker name in category; returns {term:{id,name,slug,parent}}.
		$cr      = $this->call_unwrap( 'create-term', [
			'taxonomy'    => 'category',
			'name'        => $this->marker . ' term',
			'description' => 'aafm regression term',
		], 'term' );
		$term_id = (int) ( $cr['id'] ?? 0 );
		if ( $term_id > 0 ) {
			$this->created_terms[] = $term_id;
		}
		$create_ok = $term_id > 0 && false !== strpos( (string) ( $cr['name'] ?? '' ), $this->marker );
		$this->record( $section, 'create-term creates a category term', $create_ok ? 'PASS' : 'FAIL', "id={$term_id}" );
		if ( $term_id <= 0 ) {
			$this->record( $section, 'remaining term tests', 'FAIL', 'no term id; skipping dependent steps' );
			return;
		}

		// get-term: round-trips the rich shape by id.
		$gt     = $this->call_unwrap( 'get-term', [ 'taxonomy' => 'category', 'term_id' => $term_id ], 'term' );
		$get_ok = (int) ( $gt['id'] ?? 0 ) === $term_id
			&& false !== strpos( (string) ( $gt['name'] ?? '' ), $this->marker )
			&& 'category' === ( $gt['taxonomy'] ?? '' )
			&& array_key_exists( 'count', $gt );
		$this->record( $section, 'get-term returns the term by id', $get_ok ? 'PASS' : 'FAIL', '' );

		// get-terms: the new term is findable via search by its marker name.
		$found = false;
		foreach ( ( $this->call_data( 'get-terms', [ 'taxonomy' => 'category', 'search' => $this->marker, 'per_page' => 50 ] )['terms'] ?? [] ) as $t ) {
			if ( (int) ( $t['id'] ?? 0 ) === $term_id ) {
				$found = true;
				break;
			}
		}
		$this->record( $section, 'get-terms finds the term via search', $found ? 'PASS' : 'FAIL', '' );

		// update-term: rename + change description, verify both round-trip via get-term.
		$ut       = $this->call( 'update-term', [
			'taxonomy'    => 'category',
			'term_id'     => $term_id,
			'name'        => $this->marker . ' term renamed',
			'description' => 'aafm regression term edited',
		] );
		$after_ut = $this->call_unwrap( 'get-term', [ 'taxonomy' => 'category', 'term_id' => $term_id ], 'term' );
		$upd_ok   = ! $ut['isError']
			&& false !== strpos( (string) ( $after_ut['name'] ?? '' ), 'renamed' )
			&& false !== strpos( (string) ( $after_ut['description'] ?? '' ), 'edited' );
		$this->record( $section, 'update-term renames + re-describes the term', $upd_ok ? 'PASS' : 'FAIL', '' );

		// Governance probe: an unlisted term-meta key MUST be refused (default-deny allowlist).
		$probe    = $this->call( 'update-term-meta', [ 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => 'aafm_regression_unlisted', 'value' => '1' ] );
		$governed = $probe['isError'];
		$this->record( $section, 'update-term-meta refuses an unlisted key (default)', $governed ? 'PASS' : 'FAIL', $governed ? 'correctly refused' : 'UNEXPECTEDLY accepted an unlisted key' );

		// An explicit --term-meta-key override wins: exercise it against the EXISTING category 1 over MCP
		// (snapshot-safe), then publish the created term for ACF and return. Otherwise configure the
		// allowlist via the temporary fixtures mu-plugin and exercise the write path on the created term.
		$tmk = (string) ( $this->opts['term-meta-key'] ?? '' );
		if ( '' !== $tmk ) {
			$this->exercise_term_meta_on_existing( $section, $tmk );
			$this->primary_term_id = $term_id;
			return;
		}

		// Configure the allowlist via the temporary fixtures mu-plugin, then exercise write/get/delete.
		// If the drop-in cannot be installed, record a justified SKIP for the write path.
		if ( ! $this->install_fixture_plugin() ) {
			$this->record( $section, 'term-meta write/get/delete (allowlist not configurable)', 'SKIP', 'could not install the fixtures mu-plugin via the DDEV bridge' );
			return;
		}

		// update-term-meta: write the allowlisted key; returns {term_id,meta_key,value}.
		$w        = $this->call_data( 'update-term-meta', [ 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => $meta_key, 'value' => 'aafm-tm-value' ] );
		$write_ok = 'aafm-tm-value' === (string) ( $w['value'] ?? '' ) && ( $w['meta_key'] ?? '' ) === $meta_key;
		$this->record( $section, 'update-term-meta writes the allowlisted key', $write_ok ? 'PASS' : 'FAIL', '' );

		// get-term-meta: read it back.
		$r       = $this->call_data( 'get-term-meta', [ 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => $meta_key ] );
		$read_ok = 'aafm-tm-value' === (string) ( $r['value'] ?? '' );
		$this->record( $section, 'get-term-meta reads the value back', $read_ok ? 'PASS' : 'FAIL', '' );

		// delete-term-meta: remove the key, confirm it reads back empty.
		$d       = $this->call( 'delete-term-meta', [ 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => $meta_key ] );
		$after_d = $this->call_data( 'get-term-meta', [ 'taxonomy' => 'category', 'term_id' => $term_id, 'meta_key' => $meta_key ] );
		$del_ok  = ! $d['isError'] && (bool) ( $d['data']['deleted'] ?? false ) && '' === (string) ( $after_d['value'] ?? '' );
		$this->record( $section, 'delete-term-meta removes the key', $del_ok ? 'PASS' : 'FAIL', '' );

		// Publish the created term id for test_acf_lifecycle (its term-fields path needs an editable term).
		$this->primary_term_id = $term_id;
	}

	/**
	 * Exercise the term-meta write path against the EXISTING category 1 ("Uncategorized") over pure MCP
	 * (no WP-CLI bridge), snapshot-safe. Category 1 is a real pre-existing term, so the meta key MUST be
	 * snapshotted and restored exactly: assert absent first, write, verify, delete, confirm gone — and if
	 * it was somehow present before, restore the prior value. Only this one meta key on the term is ever
	 * touched, and only when --term-meta-key names it; the term itself is never created or modified.
	 *
	 * The harness cannot know the supplied key is allowlisted on a given target, so a refused write is a
	 * handled SKIP, not a crash — passing a non-allowlisted key on a remote stays clean.
	 */
	private function exercise_term_meta_on_existing( string $section, string $meta_key ): void {
		$existing_term = 1; // Uncategorized — present on every install. Never created/deleted here.

		// Snapshot the prior value so a pre-existing meta is restored exactly (expected absent).
		$snap        = $this->call_data( 'get-term-meta', [ 'taxonomy' => 'category', 'term_id' => $existing_term, 'meta_key' => $meta_key ] );
		$prior_value = (string) ( $snap['value'] ?? '' );
		$this->record( $section, "term-meta key '{$meta_key}' starts absent on category 1", '' === $prior_value ? 'PASS' : 'FAIL', '' );

		// Register a restore BEFORE the write so a mid-test fatal() puts category 1 back exactly. The
		// restore deletes the key when it was absent before, else re-writes the prior value.
		$this->register_restore( 'term-meta:' . $existing_term . ':' . $meta_key, function () use ( $existing_term, $meta_key, $prior_value ): void {
			if ( '' === $prior_value ) {
				$this->call_quiet( 'delete-term-meta', [ 'taxonomy' => 'category', 'term_id' => $existing_term, 'meta_key' => $meta_key ] );
			} else {
				$this->call_quiet( 'update-term-meta', [ 'taxonomy' => 'category', 'term_id' => $existing_term, 'meta_key' => $meta_key, 'value' => $prior_value ] );
			}
		} );

		$w = $this->call( 'update-term-meta', [ 'taxonomy' => 'category', 'term_id' => $existing_term, 'meta_key' => $meta_key, 'value' => 'aafm-tm-value' ] );
		if ( $w['isError'] ) {
			$this->clear_pending_restore( 'term-meta:' . $existing_term . ':' . $meta_key );
			$this->record( $section, "update-term-meta writes '{$meta_key}' on category 1", 'SKIP', 'target did not allowlist the supplied --term-meta-key (write refused cleanly)' );
			return;
		}
		$write_ok = 'aafm-tm-value' === (string) ( $w['data']['value'] ?? '' );
		$this->record( $section, "update-term-meta writes '{$meta_key}' on category 1", $write_ok ? 'PASS' : 'FAIL', '' );

		$r       = $this->call_data( 'get-term-meta', [ 'taxonomy' => 'category', 'term_id' => $existing_term, 'meta_key' => $meta_key ] );
		$read_ok = 'aafm-tm-value' === (string) ( $r['value'] ?? '' );
		$this->record( $section, 'get-term-meta reads the value back', $read_ok ? 'PASS' : 'FAIL', '' );

		// Restore category 1 to its exact prior state (delete when it was absent before, else re-write).
		$d = $this->call( 'delete-term-meta', [ 'taxonomy' => 'category', 'term_id' => $existing_term, 'meta_key' => $meta_key ] );
		if ( '' !== $prior_value ) {
			$this->call( 'update-term-meta', [ 'taxonomy' => 'category', 'term_id' => $existing_term, 'meta_key' => $meta_key, 'value' => $prior_value ] );
		}
		$after   = $this->call_data( 'get-term-meta', [ 'taxonomy' => 'category', 'term_id' => $existing_term, 'meta_key' => $meta_key ] );
		$now     = (string) ( $after['value'] ?? '' );
		$del_ok  = ! $d['isError'] && $now === $prior_value;
		$this->record( $section, 'delete-term-meta restores category 1 to its prior state', $del_ok ? 'PASS' : 'FAIL', '' );
		if ( $del_ok ) {
			$this->clear_pending_restore( 'term-meta:' . $existing_term . ':' . $meta_key );
		}
	}

	/**
	 * Full comment CRUD + moderation lifecycle on a throwaway post.
	 *
	 * create-comment lands a PENDING comment (the ability pins status to hold), so the
	 * moderation steps assert that posture: get-pending-comments must list it while it is
	 * held, moderate-comment(approve) must flip it to 'approved', and delete-comment cleans
	 * up permanently. Comment shape (aafm_redact_comment): id, post_id, author_name, content,
	 * status, date_gmt, parent — never email or IP.
	 */
	private function test_comments_lifecycle(): void {
		$section = 'Comments';

		// A dedicated throwaway post to host the comment, tracked for cleanup.
		$created = $this->call( 'create-post', [
			'title'   => $this->marker . ' comment-host',
			'content' => '<p>comment host body</p>',
			'status'  => 'publish',
		] );
		$post_id = (int) ( $this->post_of( (array) ( $created['data'] ?? [] ) )['id'] ?? 0 );
		if ( $post_id > 0 ) {
			$this->created_posts[] = $post_id;
		}
		if ( $post_id <= 0 ) {
			$this->record( $section, 'comment lifecycle', 'FAIL', 'could not create host post' );
			return;
		}

		// create-comment: returns {comment:{...}}, status pinned to pending.
		$marker_text = $this->marker . ' comment body';
		$cc          = $this->call_unwrap( 'create-comment', [ 'post_id' => $post_id, 'content' => $marker_text ], 'comment' );
		$comment_id  = (int) ( $cc['id'] ?? 0 );
		if ( $comment_id > 0 ) {
			$this->created_comments[] = $comment_id;
		}
		$created_ok = $comment_id > 0
			&& (int) ( $cc['post_id'] ?? 0 ) === $post_id
			&& false !== strpos( (string) ( $cc['content'] ?? '' ), $this->marker )
			&& in_array( (string) ( $cc['status'] ?? '' ), [ 'unapproved', 'hold' ], true );
		$this->record( $section, 'create-comment adds a pending comment', $created_ok ? 'PASS' : 'FAIL', "id={$comment_id} status=" . ( $cc['status'] ?? '?' ) );
		if ( $comment_id <= 0 ) {
			return;
		}

		// get-comment: round-trips the fields, no email/IP keys.
		$gc       = $this->call_unwrap( 'get-comment', [ 'comment_id' => $comment_id ], 'comment' );
		$no_pii   = ! array_key_exists( 'author_email', $gc ) && ! array_key_exists( 'comment_author_IP', $gc );
		$get_ok   = (int) ( $gc['id'] ?? 0 ) === $comment_id
			&& false !== strpos( (string) ( $gc['content'] ?? '' ), $this->marker )
			&& $no_pii;
		$this->record( $section, 'get-comment returns the comment without email/IP', $get_ok ? 'PASS' : 'FAIL', '' );

		// get-pending-comments: the moderation queue must include the held comment.
		$pending  = $this->call_data( 'get-pending-comments', [ 'per_page' => 50 ] );
		$in_queue = false;
		foreach ( ( $pending['comments'] ?? [] ) as $item ) {
			if ( (int) ( $item['id'] ?? 0 ) === $comment_id ) {
				$in_queue = true;
				break;
			}
		}
		$this->record( $section, 'get-pending-comments lists the held comment', $in_queue ? 'PASS' : 'FAIL', 'queue=' . count( $pending['comments'] ?? [] ) );

		// update-comment: changes only the body; verify the new text round-trips.
		$new_text = $this->marker . ' comment body edited';
		$uc       = $this->call( 'update-comment', [ 'comment_id' => $comment_id, 'content' => $new_text ] );
		$after_uc = $this->call_unwrap( 'get-comment', [ 'comment_id' => $comment_id ], 'comment' );
		$upd_ok   = ! $uc['isError'] && false !== strpos( (string) ( $after_uc['content'] ?? '' ), 'edited' );
		$this->record( $section, 'update-comment changes the content', $upd_ok ? 'PASS' : 'FAIL', '' );

		// moderate-comment: approve, then verify the status flipped to approved.
		$mc        = $this->call( 'moderate-comment', [ 'comment_id' => $comment_id, 'action' => 'approve' ] );
		$mc_status = (string) ( $mc['data']['status'] ?? '' );
		$after_mc  = (string) ( $this->call_unwrap( 'get-comment', [ 'comment_id' => $comment_id ], 'comment' )['status'] ?? '' );
		$mod_ok    = ! $mc['isError'] && 'approved' === $mc_status && 'approved' === $after_mc;
		$this->record( $section, 'moderate-comment approves the comment', $mod_ok ? 'PASS' : 'FAIL', "status={$after_mc}" );

		// get-comments: the approved comment must now be visible in the post listing.
		$list  = $this->call_data( 'get-comments', [ 'post_id' => $post_id, 'per_page' => 50 ] );
		$found = false;
		foreach ( ( $list['comments'] ?? [] ) as $item ) {
			if ( (int) ( $item['id'] ?? 0 ) === $comment_id ) {
				$found = true;
				break;
			}
		}
		$this->record( $section, 'get-comments lists the approved comment', $found ? 'PASS' : 'FAIL', 'count=' . count( $list['comments'] ?? [] ) );

		// delete-comment: permanent removal; confirm it is gone and drop it from cleanup tracking.
		$dc      = $this->call( 'delete-comment', [ 'comment_id' => $comment_id ] );
		$gone    = $this->call( 'get-comment', [ 'comment_id' => $comment_id ] )['isError'];
		$del_ok  = ! $dc['isError'] && (bool) ( $dc['data']['deleted'] ?? false ) && $gone;
		$this->record( $section, 'delete-comment permanently removes the comment', $del_ok ? 'PASS' : 'FAIL', '' );
		if ( $del_ok ) {
			$this->created_comments = array_values( array_filter( $this->created_comments, static fn( $id ) => $id !== $comment_id ) );
		}
	}

	/**
	 * Full user CRUD lifecycle on a throwaway user. Never touches the agent user or any
	 * pre-existing account — only the one created here, carrying the AAFM-REGRESSION marker
	 * in both login and email so purge_orphans() can sweep a leak.
	 *
	 * User shape (aafm_rich_user): id, display_name, email, roles, post_count, registered,
	 * bio — never login or password hash. delete-user requires a reassign target; the agent
	 * user is the reassignment recipient (its content is never the victim's).
	 */
	private function test_users_lifecycle(): void {
		$section = 'Users';

		$rand  = substr( (string) random_int( 100000, 999999 ), 0, 6 );
		$login = 'aafm_regression_' . $rand;
		$email = 'aafm_regression_' . $rand . '@example.test';

		// create-user: role is forced to the site default server-side; returns {user:{...}}.
		$cu      = $this->call_unwrap( 'create-user', [
			'username'     => $login,
			'email'        => $email,
			'display_name' => $this->marker . ' user',
		], 'user' );
		$user_id = (int) ( $cu['id'] ?? 0 );
		if ( $user_id > 0 ) {
			$this->created_users[] = $user_id;
		}
		$no_secret = ! array_key_exists( 'user_login', $cu ) && ! array_key_exists( 'user_pass', $cu );
		$create_ok = $user_id > 0 && ( $cu['email'] ?? '' ) === $email && $no_secret;
		$this->record( $section, 'create-user creates a user (no login/password leaked)', $create_ok ? 'PASS' : 'FAIL', "id={$user_id}" );
		if ( $user_id <= 0 ) {
			return;
		}

		// get-user: round-trips the rich shape for the created id.
		$gu     = $this->call_unwrap( 'get-user', [ 'user_id' => $user_id ], 'user' );
		$get_ok = (int) ( $gu['id'] ?? 0 ) === $user_id
			&& ( $gu['email'] ?? '' ) === $email
			&& array_key_exists( 'registered', $gu );
		$this->record( $section, 'get-user returns the rich user shape', $get_ok ? 'PASS' : 'FAIL', '' );

		// get-users: the created user must be findable via search by its marker email.
		$list  = $this->call_data( 'get-users', [ 'search' => $email, 'per_page' => 50 ] );
		$found = false;
		foreach ( ( $list['users'] ?? [] ) as $item ) {
			if ( (int) ( $item['id'] ?? 0 ) === $user_id ) {
				$found = true;
				break;
			}
		}
		$this->record( $section, 'get-users finds the user via search', $found ? 'PASS' : 'FAIL', 'count=' . count( $list['users'] ?? [] ) );

		// update-user: change the display name and verify it round-trips.
		$new_name = $this->marker . ' user renamed';
		$uu       = $this->call( 'update-user', [ 'user_id' => $user_id, 'display_name' => $new_name ] );
		$after_uu = $this->call_unwrap( 'get-user', [ 'user_id' => $user_id ], 'user' );
		$upd_ok   = ! $uu['isError'] && false !== strpos( (string) ( $after_uu['display_name'] ?? '' ), 'renamed' );
		$this->record( $section, 'update-user changes the display name', $upd_ok ? 'PASS' : 'FAIL', '' );

		// delete-user: reassign the victim's content to an existing OTHER user (never the victim
		// itself), confirm removal, then drop it from cleanup tracking. The target is resolved via the
		// MCP get-users tool so it works in every auth mode (Application Password and --bearer) and
		// against remote sites — not the local-only WP-CLI bridge.
		$reassign = $this->reassign_target_user( $user_id );
		if ( $reassign <= 0 || $reassign === $user_id ) {
			$this->record( $section, 'delete-user removes the user', 'SKIP', 'no distinct reassign target available' );
			return;
		}
		$du     = $this->call( 'delete-user', [ 'user_id' => $user_id, 'reassign_to' => $reassign ] );
		$gone   = $this->call( 'get-user', [ 'user_id' => $user_id ] )['isError'];
		$del_ok = ! $du['isError'] && (bool) ( $du['data']['deleted'] ?? false ) && $gone;
		$this->record( $section, 'delete-user removes the user', $del_ok ? 'PASS' : 'FAIL', '' );
		if ( $del_ok ) {
			$this->created_users = array_values( array_filter( $this->created_users, static fn( $id ) => $id !== $user_id ) );
		}
	}

	/**
	 * User-meta governance + write lifecycle on a throwaway user.
	 *
	 * Probe first: an unlisted key must be refused by the default-deny allowlist (that
	 * refusal is the governance PASS). To exercise the real write path, snapshot the
	 * aafm_exposed_user_meta_keys option, add a test key, run update -> get -> delete-user-meta
	 * against a freshly created throwaway user, assert, then RESTORE the option exactly. If the
	 * option cannot be configured (e.g. no WP-CLI), record the refusal PASS + a justified SKIP.
	 *
	 * Param names (user-meta.php): user_id, key, value. Returns {user_id,key,value} / {deleted}.
	 */
	private function test_user_meta_lifecycle(): void {
		$section = 'User meta';
		$option  = 'aafm_exposed_user_meta_keys';
		$test_key = 'aafm_regression_um';

		// A dedicated throwaway user to own the meta, tracked for cleanup.
		$rand  = substr( (string) random_int( 100000, 999999 ), 0, 6 );
		$login = 'aafm_regression_um_' . $rand;
		$email = 'aafm_regression_um_' . $rand . '@example.test';
		$cu      = $this->call_unwrap( 'create-user', [
			'username'     => $login,
			'email'        => $email,
			'display_name' => $this->marker . ' meta-user',
		], 'user' );
		$user_id = (int) ( $cu['id'] ?? 0 );
		if ( $user_id > 0 ) {
			$this->created_users[] = $user_id;
		}
		if ( $user_id <= 0 ) {
			$this->record( $section, 'user-meta lifecycle', 'FAIL', 'could not create meta-host user' );
			return;
		}

		// An explicit --user-meta-key override wins: exercise the write path against the throwaway user
		// over pure MCP (no WP-CLI bridge), assuming the operator already allowlisted the key on the
		// target. Snapshot-safe by construction — the user is created blank this run and deleted in
		// cleanup, so the key starts absent and dies with the user. A refused write is a handled SKIP.
		$umk = (string) ( $this->opts['user-meta-key'] ?? '' );

		// Governance probe: an unlisted key must be refused under a default-deny allowlist. The probe
		// meta dies with the throwaway user at cleanup, so an accepted write leaves nothing behind.
		$probe = $this->call( 'update-user-meta', [ 'user_id' => $user_id, 'key' => 'aafm_regression_unlisted', 'value' => '1' ] );
		$this->record_default_deny_probe( $section, 'update-user-meta refuses an unlisted key (default)', (bool) $probe['isError'], '' !== $umk );
		if ( '' !== $umk ) {
			$before = $this->call_data( 'get-user-meta', [ 'user_id' => $user_id, 'key' => $umk ] );
			$this->record( $section, "user-meta key '{$umk}' starts absent on the throwaway user", '' === (string) ( $before['value'] ?? '' ) ? 'PASS' : 'FAIL', '' );

			$wo = $this->call( 'update-user-meta', [ 'user_id' => $user_id, 'key' => $umk, 'value' => 'aafm-um-value' ] );
			if ( $wo['isError'] ) {
				$this->record( $section, "update-user-meta writes '{$umk}'", 'SKIP', 'target did not allowlist the supplied --user-meta-key (write refused cleanly)' );
				return;
			}
			$wo_ok = 'aafm-um-value' === (string) ( $wo['data']['value'] ?? '' ) && ( $wo['data']['key'] ?? '' ) === $umk;
			$this->record( $section, "update-user-meta writes '{$umk}'", $wo_ok ? 'PASS' : 'FAIL', '' );

			$ro      = $this->call_data( 'get-user-meta', [ 'user_id' => $user_id, 'key' => $umk ] );
			$ro_ok   = 'aafm-um-value' === (string) ( $ro['value'] ?? '' );
			$this->record( $section, 'get-user-meta reads the value back', $ro_ok ? 'PASS' : 'FAIL', '' );

			$do      = $this->call( 'delete-user-meta', [ 'user_id' => $user_id, 'key' => $umk ] );
			$after_o = $this->call_data( 'get-user-meta', [ 'user_id' => $user_id, 'key' => $umk ] );
			$do_ok   = ! $do['isError'] && (bool) ( $do['data']['deleted'] ?? false ) && '' === (string) ( $after_o['value'] ?? '' );
			$this->record( $section, 'delete-user-meta removes the key', $do_ok ? 'PASS' : 'FAIL', '' );
			return;
		}

		// Snapshot the allowlist option (registered for restore BEFORE the mutation so cleanup() reverses
		// it even if a later transport fatal() exits before the inline restore below), add the test key,
		// exercise the write path, then restore.
		$snapshot = $this->snapshot_option_for_restore( $option );
		$configured = $this->cli_set_option_array( $option, [ $test_key ] );
		if ( ! $configured ) {
			$this->clear_pending_restore( $option );
			$reason = $this->cli_targets_endpoint()
				? 'could not snapshot/set ' . $option . ' via WP-CLI'
				: 'requires local DDEV WP-CLI to configure ' . $option . '; not available for a remote target';
			$this->record( $section, 'user-meta write/get/delete (allowlist not configurable)', 'SKIP', $reason );
			return;
		}

		// update-user-meta: write the allowlisted key; returns {user_id,key,value}.
		$w  = $this->call_data( 'update-user-meta', [ 'user_id' => $user_id, 'key' => $test_key, 'value' => 'aafm-um-value' ] );
		$write_ok = 'aafm-um-value' === (string) ( $w['value'] ?? '' ) && ( $w['key'] ?? '' ) === $test_key;
		$this->record( $section, 'update-user-meta writes the allowlisted key', $write_ok ? 'PASS' : 'FAIL', '' );

		// get-user-meta: read it back.
		$r  = $this->call_data( 'get-user-meta', [ 'user_id' => $user_id, 'key' => $test_key ] );
		$read_ok = 'aafm-um-value' === (string) ( $r['value'] ?? '' );
		$this->record( $section, 'get-user-meta reads the value back', $read_ok ? 'PASS' : 'FAIL', '' );

		// delete-user-meta: remove the key, then confirm it reads back empty.
		$d  = $this->call( 'delete-user-meta', [ 'user_id' => $user_id, 'key' => $test_key ] );
		$after_d = $this->call_data( 'get-user-meta', [ 'user_id' => $user_id, 'key' => $test_key ] );
		$del_ok  = ! $d['isError'] && (bool) ( $d['data']['deleted'] ?? false ) && '' === (string) ( $after_d['value'] ?? '' );
		$this->record( $section, 'delete-user-meta removes the key', $del_ok ? 'PASS' : 'FAIL', '' );

		// Restore the option to its EXACT prior state (delete when it did not exist before). On a clean
		// restore, de-register it so cleanup() does not redundantly restore it again.
		$restored = $this->cli_restore_option( $option, $snapshot );
		if ( $restored ) {
			$this->clear_pending_restore( $option );
		}
		$this->record( $section, $option . ' option restored to its prior state', $restored ? 'PASS' : 'FAIL', '' );
	}

	private function test_pages_lifecycle( array $tools ): void {
		$section = 'Pages';

		$created = $this->call( 'create-page', [ 'title' => $this->marker . ' page', 'content' => '<p>page body</p>', 'status' => 'publish' ] );
		$page_id = (int) ( $this->post_of( (array) ( $created['data'] ?? [] ) )['id'] ?? 0 );
		if ( $page_id > 0 ) {
			$this->created_pages[] = $page_id;
		}
		$this->record( $section, 'create-page creates a page', $page_id > 0 && ! $created['isError'] ? 'PASS' : 'FAIL', "id={$page_id}" );
		if ( $page_id <= 0 ) {
			return;
		}

		$got = $this->call_post( 'get-page', [ 'page_id' => $page_id ] );
		$this->record( $section, 'get-page returns the page', isset( $got['id'] ) && (int) $got['id'] === $page_id ? 'PASS' : 'FAIL', '' );

		$list  = $this->call_data( 'get-pages', [ 'search' => $this->marker, 'per_page' => 10 ] );
		$found = false;
		foreach ( ( $list['posts'] ?? $list['items'] ?? [] ) as $item ) {
			if ( (int) ( $item['id'] ?? 0 ) === $page_id ) {
				$found = true;
				break;
			}
		}
		$this->record( $section, 'get-pages finds the page via search', $found ? 'PASS' : 'FAIL', '' );

		$upd   = $this->call( 'update-page', [ 'page_id' => $page_id, 'title' => $this->marker . ' page updated' ] );
		$after = $this->call_post( 'get-page', [ 'page_id' => $page_id ] );
		$ok    = ! $upd['isError'] && false !== strpos( (string) $this->scalar( $after['title'] ?? '' ), 'updated' );
		$this->record( $section, 'update-page changes the title', $ok ? 'PASS' : 'FAIL', '' );
	}

	private function test_cpt_lifecycle(): void {
		$section = 'Custom post types';

		// Governance probe runs regardless: a type that is NOT exposed to agents must be refused. The
		// probe type 'aafm_regression_unexposed' is never registered or allowlisted by the mu-plugin.
		$probe    = $this->call( 'create-cpt-item', [ 'post_type' => 'aafm_regression_unexposed', 'title' => $this->marker . ' cpt' ] );
		$governed = $probe['isError'];
		$this->record( $section, 'create-cpt-item refuses an unexposed type (default)', $governed ? 'PASS' : 'FAIL', $governed ? 'correctly refused' : 'UNEXPECTEDLY accepted' );

		// Resolve the writable type to exercise. Precedence:
		//   1. --cpt override always wins.
		//   2. Local mode: the fixtures mu-plugin registers + allowlists a throwaway type
		//      ('aafm_regression_cpt': public, show_in_rest, map_meta_cap, capability_type post so the
		//      admin agent can create/edit/publish it).
		//   3. Remote mode: auto-discover an agent-writable type from get-post-types — the first item with
		//      writable===true that is not post/page — and exercise the lifecycle against it. If none is
		//      writable, SKIP with a clear reason rather than register a permanent type.
		$cpt      = (string) ( $this->opts['cpt'] ?? '' );
		$override = '' !== $cpt;
		if ( ! $override ) {
			if ( $this->cli_targets_endpoint() ) {
				if ( ! $this->install_fixture_plugin() ) {
					$this->record( $section, 'CPT create/update (no agent-writable type)', 'SKIP', 'could not install the fixtures mu-plugin via the DDEV bridge' );
					return;
				}
				$cpt = 'aafm_regression_cpt';
			} else {
				$cpt = $this->discover_writable_cpt();
				if ( '' === $cpt ) {
					$this->record( $section, 'CPT create/update (no agent-writable type)', 'SKIP', 'no agent-writable custom post type advertised by get-post-types on the target; pass --cpt to force one' );
					return;
				}
			}
		}

		// create-cpt-item: publish so the round-trip is exercised end-to-end (the agent admin holds the
		// type's publish cap; force-draft would only kick in if the operator enabled it).
		$created = $this->call( 'create-cpt-item', [ 'post_type' => $cpt, 'title' => $this->marker . ' cpt', 'content' => '<p>cpt body</p>', 'status' => 'publish' ] );
		$item_id = (int) ( $this->post_of( (array) ( $created['data'] ?? [] ) )['id'] ?? 0 );
		if ( $item_id > 0 ) {
			$this->created_posts[] = $item_id; // delete-post handles any post type the agent can edit.
		}
		$this->record( $section, "create-cpt-item creates a '{$cpt}' item", $item_id > 0 && ! $created['isError'] ? 'PASS' : 'FAIL', "id={$item_id}" );
		if ( $item_id <= 0 ) {
			$this->record( $section, 'update-cpt-item updates the item', 'FAIL', 'no item id; skipping dependent step' );
			return;
		}

		// get-post round-trips the CPT item (CPT items are posts; the get-post read accepts any
		// allowlisted type), confirming the create actually persisted the title.
		$got     = $this->call_post( 'get-post', [ 'post_id' => $item_id ] );
		$read_ok = (int) ( $got['id'] ?? 0 ) === $item_id
			&& false !== strpos( (string) $this->scalar( $got['title'] ?? '' ), $this->marker )
			&& $cpt === ( $got['type'] ?? '' );
		$this->record( $section, 'get-post round-trips the CPT item', $read_ok ? 'PASS' : 'FAIL', 'type=' . ( $got['type'] ?? '?' ) );

		// update-cpt-item: rename, then verify it round-trips via get-post.
		$upd      = $this->call( 'update-cpt-item', [ 'post_id' => $item_id, 'title' => $this->marker . ' cpt updated' ] );
		$after    = $this->call_post( 'get-post', [ 'post_id' => $item_id ] );
		$upd_ok   = ! $upd['isError'] && false !== strpos( (string) $this->scalar( $after['title'] ?? '' ), 'updated' );
		$this->record( $section, 'update-cpt-item updates the item', $upd_ok ? 'PASS' : 'FAIL', '' );
	}

	/**
	 * Discover an agent-writable custom post type from get-post-types for remote-mode CPT coverage.
	 * Returns the slug of the first item whose writable flag is true and whose slug is neither 'post'
	 * nor 'page' (both are exercised elsewhere and not custom types), or '' when none is writable.
	 * The created item is deleted via delete-post in cleanup, so any item-supporting writable type
	 * round-trips and cleans up purely over MCP.
	 */
	private function discover_writable_cpt(): string {
		$types = $this->call_data( 'get-post-types', [] )['post_types'] ?? [];
		foreach ( $types as $t ) {
			$slug = (string) ( $t['slug'] ?? '' );
			if ( '' === $slug || 'post' === $slug || 'page' === $slug ) {
				continue;
			}
			if ( ! empty( $t['writable'] ) ) {
				return $slug;
			}
		}
		return '';
	}

	private function test_structure_reads(): void {
		$section = 'Structure';

		// get-site-info: returns a {site:{name,tagline,url,language}} descriptor.
		$info = $this->call_data( 'get-site-info', [] );
		$site = is_array( $info['site'] ?? null ) ? $info['site'] : [];
		$ok   = isset( $site['name'], $site['url'] ) && '' !== (string) $site['url'] && array_key_exists( 'language', $site );
		$this->record( $section, 'get-site-info returns name/url/language', $ok ? 'PASS' : 'FAIL', 'url=' . ( $site['url'] ?? '?' ) );

		// get-post-types: list of public types with a writable flag; must include post + page.
		$types_data = $this->call_data( 'get-post-types', [] );
		$types      = $types_data['post_types'] ?? [];
		$slugs      = [];
		$flag_ok    = true;
		foreach ( $types as $t ) {
			$slugs[] = (string) ( $t['slug'] ?? '' );
			if ( ! is_array( $t ) || ! array_key_exists( 'writable', $t ) ) {
				$flag_ok = false;
			}
		}
		$has_pp = in_array( 'post', $slugs, true ) && in_array( 'page', $slugs, true );
		$this->record( $section, 'get-post-types includes post + page', $has_pp ? 'PASS' : 'FAIL', 'count=' . count( $slugs ) );
		$this->record( $section, 'get-post-types items carry a writable flag', ( $types && $flag_ok ) ? 'PASS' : 'FAIL', '' );

		// get-taxonomies: list of public taxonomies; must include category + post_tag.
		$tax_data = $this->call_data( 'get-taxonomies', [] );
		$tax      = $tax_data['taxonomies'] ?? [];
		$tslugs   = [];
		foreach ( $tax as $tx ) {
			$tslugs[] = (string) ( $tx['slug'] ?? '' );
		}
		$has_tax = in_array( 'category', $tslugs, true ) && in_array( 'post_tag', $tslugs, true );
		$this->record( $section, 'get-taxonomies includes category + post_tag', $has_tax ? 'PASS' : 'FAIL', 'count=' . count( $tslugs ) );
	}

	private function test_search_lifecycle(): void {
		$section = 'Search';

		// A throwaway published post carrying a unique marker token search-content can find.
		$token   = $this->marker . '-SEARCH';
		$created = $this->call( 'create-post', [
			'title'   => $token . ' searchable',
			'content' => '<p>' . $token . ' body content</p>',
			'status'  => 'publish',
		] );
		$post_id = (int) ( $this->post_of( (array) ( $created['data'] ?? [] ) )['id'] ?? 0 );
		if ( $post_id > 0 ) {
			$this->created_posts[] = $post_id;
		}
		if ( $post_id <= 0 ) {
			$this->record( $section, 'search-content finds a published post by marker', 'FAIL', 'could not create search fixture' );
			return;
		}

		$res     = $this->call( 'search-content', [ 'search' => $token, 'per_page' => 10 ] );
		$data    = is_array( $res['data'] ) ? $res['data'] : [];
		$results = $data['results'] ?? [];
		$found   = false;
		foreach ( $results as $item ) {
			if ( (int) ( $item['id'] ?? 0 ) === $post_id ) {
				$found = true;
				break;
			}
		}
		$ok = ! $res['isError'] && $found && isset( $data['total'] );
		$this->record( $section, 'search-content finds a published post by marker', $ok ? 'PASS' : 'FAIL', 'total=' . var_export( $data['total'] ?? null, true ) );
	}

	private function test_plugins_reads(): void {
		$section = 'Plugins';

		$data    = $this->call_data( 'list-plugins', [] );
		$plugins = $data['plugins'] ?? [];
		$self    = false;
		foreach ( $plugins as $p ) {
			$file = (string) ( $p['plugin'] ?? '' );
			$name = (string) ( $p['name'] ?? '' );
			if ( false !== stripos( $file, 'agent-abilities-for-mcp' ) || false !== stripos( $name, 'Agent Abilities for MCP' ) ) {
				$self = true;
				break;
			}
		}
		$this->record( $section, 'list-plugins returns a non-empty inventory', ! empty( $plugins ) ? 'PASS' : 'FAIL', 'count=' . count( $plugins ) );
		$this->record( $section, 'list-plugins includes this plugin', $self ? 'PASS' : 'FAIL', '' );
	}

	private function test_activity_log_reads(): void {
		$section = 'Activity log';

		$r       = $this->call( 'get-activity-log', [ 'per_page' => 5 ] );
		$data    = is_array( $r['data'] ) ? $r['data'] : [];
		$entries = $data['entries'] ?? null;
		$ok      = ! $r['isError'] && is_array( $entries );
		$this->record( $section, 'get-activity-log returns an entries list', $ok ? 'PASS' : 'FAIL', is_array( $entries ) ? count( $entries ) . ' entry(ies)' : 'no entries key' );
	}

	private function test_themes_lifecycle(): void {
		$section = 'Themes (FSE)';

		// get-active-theme: header fields + the is_block_theme flag.
		$active   = $this->call_data( 'get-active-theme', [] );
		$is_block = ! empty( $active['is_block_theme'] );
		$ok       = isset( $active['name'], $active['stylesheet'] ) && array_key_exists( 'is_block_theme', $active );
		$this->record( $section, 'get-active-theme returns name/stylesheet/is_block_theme', $ok ? 'PASS' : 'FAIL', 'theme=' . ( $active['name'] ?? '?' ) . ' block=' . var_export( $is_block, true ) );

		// list-themes: array with the active one flagged.
		$themes      = $this->call_data( 'list-themes', [] )['themes'] ?? [];
		$has_active  = false;
		foreach ( $themes as $t ) {
			if ( 'active' === ( $t['status'] ?? '' ) ) {
				$has_active = true;
				break;
			}
		}
		$this->record( $section, 'list-themes lists themes with the active one flagged', ( $themes && $has_active ) ? 'PASS' : 'FAIL', 'count=' . count( $themes ) );

		// get-global-styles: resolved theme.json settings + styles.
		$gs    = $this->call_data( 'get-global-styles', [] );
		$gs_ok = is_array( $gs['settings'] ?? null ) && is_array( $gs['styles'] ?? null );
		$this->record( $section, 'get-global-styles returns settings + styles', $gs_ok ? 'PASS' : 'FAIL', '' );

		// The remaining three are template-backed. Without a block theme there are no
		// database templates to read or edit; record justified SKIPs and stop.
		if ( ! $is_block ) {
			$this->record( $section, 'list-templates returns block templates', 'SKIP', 'active theme is not a block theme; no FSE templates' );
			$this->record( $section, 'get-template reads one template body', 'SKIP', 'active theme is not a block theme; no FSE templates' );
			$this->record( $section, 'update-template edits + restores a template', 'SKIP', 'active theme is not a block theme; no FSE templates' );
			return;
		}

		// list-templates: id/slug/title/type/source per template.
		$templates = $this->call_data( 'list-templates', [ 'type' => 'wp_template' ] )['templates'] ?? [];
		$this->record( $section, 'list-templates returns block templates', ! empty( $templates ) ? 'PASS' : 'FAIL', 'count=' . count( $templates ) );
		if ( empty( $templates ) ) {
			$this->record( $section, 'get-template reads one template body', 'SKIP', 'no templates listed' );
			$this->record( $section, 'update-template edits + restores a template', 'SKIP', 'no templates listed' );
			return;
		}

		// Pick a database-backed template so update-template has a wp_id to edit; fall back to the first
		// template for the read-only get-template assertion. Precedence:
		//   1. --template-id override: use the listed template with that exact id.
		//   2. A custom / DB-backed template already in the listing (source 'custom', or a wp_id present).
		//   3. Local mode only: drop a throwaway DB-backed template host-side via the WP-CLI bridge.
		$template_override = (string) ( $this->opts['template-id'] ?? '' );
		$editable          = null;
		if ( '' !== $template_override ) {
			foreach ( $templates as $t ) {
				if ( (string) ( $t['id'] ?? '' ) === $template_override ) {
					$editable = $t;
					break;
				}
			}
		}
		if ( null === $editable ) {
			foreach ( $templates as $t ) {
				if ( 'custom' === ( $t['source'] ?? '' ) || ! empty( $t['wp_id'] ) ) {
					$editable = $t;
					break;
				}
			}
		}

		// A fresh block-theme install ships only theme-FILE templates (source 'theme'), which
		// update-template refuses by design. In LOCAL mode, drop one throwaway database-backed (source
		// 'custom') template host-side via the WP-CLI bridge so the write path is genuinely exercised,
		// then re-read the listing so $editable is the real ability-returned shape (it carries the wp_id).
		// The template post is tracked and force-deleted in cleanup() — never a pre-existing template.
		// On a remote target the bridge is unavailable, so there is nothing to seed: $editable stays null
		// when no custom template already exists, and the write path SKIPs below.
		if ( null === $editable && '' === $template_override ) {
			$created = $this->create_db_template();
			if ( null !== $created ) {
				$templates = $this->call_data( 'list-templates', [ 'type' => 'wp_template' ] )['templates'] ?? $templates;
				foreach ( $templates as $t ) {
					if ( (string) ( $t['id'] ?? '' ) === $created['id'] && 'custom' === ( $t['source'] ?? '' ) ) {
						$editable = $t;
						break;
					}
				}
			}
		}
		$read_id = (string) ( ( $editable['id'] ?? $templates[0]['id'] ) ?? '' );

		// get-template: rich shape including markup.
		$tpl = $this->call_data( 'get-template', [ 'template_id' => $read_id, 'type' => 'wp_template' ] );
		$this->record( $section, 'get-template reads one template body', ( isset( $tpl['id'] ) && array_key_exists( 'content', $tpl ) ) ? 'PASS' : 'FAIL', "id={$read_id}" );

		// update-template (write): only a database-backed template can be edited. Theme-file
		// templates have no backing post and are refused by design — that is not a failure here,
		// so SKIP with the reason when none is database-backed.
		if ( null === $editable ) {
			$reason = $this->cli_targets_endpoint()
				? 'no database-backed (custom) template to edit; theme-file templates are refused by design'
				: 'no custom (DB-backed) template on the target to edit; theme-file templates are refused by design. Pass --template-id to force one';
			$this->record( $section, 'update-template edits + restores a template', 'SKIP', $reason );
			return;
		}

		$edit_id  = (string) $editable['id'];
		$snapshot = (string) ( $this->call_data( 'get-template', [ 'template_id' => $edit_id, 'type' => 'wp_template' ] )['content'] ?? '' );
		$probe    = '<!-- ' . $this->marker . '-TPL -->';
		$new      = $snapshot . "\n" . $probe;

		// Register the reversal BEFORE the edit so cleanup() restores the original content even if a
		// fatal() exits between the edit and the inline restore below.
		$this->register_restore( 'template:' . $edit_id, function () use ( $edit_id, $snapshot ): void {
			$this->call_quiet( 'update-template', [ 'template_id' => $edit_id, 'type' => 'wp_template', 'content' => $snapshot ] );
		} );

		$upd      = $this->call( 'update-template', [ 'template_id' => $edit_id, 'type' => 'wp_template', 'content' => $new ] );
		$after    = (string) ( $this->call_data( 'get-template', [ 'template_id' => $edit_id, 'type' => 'wp_template' ] )['content'] ?? '' );
		$took     = ! $upd['isError'] && false !== strpos( $after, $this->marker . '-TPL' );

		// Restore the original content regardless of whether the edit assertion passed. Verify the
		// restore semantically: the probe marker is gone AND the content matches the snapshot modulo
		// the whitespace reflow wp_kses_post() applies on save (a byte compare false-fails there).
		$restore     = $this->call( 'update-template', [ 'template_id' => $edit_id, 'type' => 'wp_template', 'content' => $snapshot ] );
		$restored    = (string) ( $this->call_data( 'get-template', [ 'template_id' => $edit_id, 'type' => 'wp_template' ] )['content'] ?? '' );
		$marker_gone = false === strpos( $restored, $this->marker . '-TPL' );
		$reverted    = ! $restore['isError'] && $marker_gone && $this->templates_equivalent( $restored, $snapshot );
		if ( $reverted ) {
			$this->clear_pending_restore( 'template:' . $edit_id );
		}
		$ok       = $took && $reverted;
		$this->record( $section, 'update-template edits + restores a template', $ok ? 'PASS' : 'FAIL', "id={$edit_id} reverted=" . var_export( $reverted, true ) );
	}

	private function test_settings_lifecycle(): void {
		$section = 'Site settings';

		// get-site-settings: a {settings:{...}} map of the allowlisted keys.
		$read     = $this->call_data( 'get-site-settings', [] );
		$settings = is_array( $read['settings'] ?? null ) ? $read['settings'] : [];
		$has_keys = array_key_exists( 'blogname', $settings ) && array_key_exists( 'blogdescription', $settings );
		$this->record( $section, 'get-site-settings returns the allowlisted map', $has_keys ? 'PASS' : 'FAIL', 'keys=' . count( $settings ) );
		if ( ! $has_keys ) {
			$this->record( $section, 'update-site-settings changes + restores tagline', 'FAIL', 'could not snapshot settings' );
			return;
		}

		// Snapshot the exact current tagline, flip it to a marker value, confirm, then restore. Register
		// the reversal BEFORE the flip so cleanup() restores the tagline even if a fatal() exits between
		// the flip and the inline restore below.
		$original = (string) $settings['blogdescription'];
		$probe    = $this->marker . ' tagline';
		$this->register_restore( 'tagline', function () use ( $original ): void {
			$this->call_quiet( 'update-site-settings', [ 'settings' => [ 'blogdescription' => $original ] ] );
		} );

		$upd      = $this->call( 'update-site-settings', [ 'settings' => [ 'blogdescription' => $probe ] ] );
		$mid      = (string) ( $this->call_data( 'get-site-settings', [] )['settings']['blogdescription'] ?? '' );
		$changed  = ! $upd['isError'] && $mid === $probe;

		// Restore the exact original value (runs whether or not the change assertion passed).
		$restore  = $this->call( 'update-site-settings', [ 'settings' => [ 'blogdescription' => $original ] ] );
		$back      = (string) ( $this->call_data( 'get-site-settings', [] )['settings']['blogdescription'] ?? '' );
		if ( ! $restore['isError'] && $back === $original ) {
			$this->clear_pending_restore( 'tagline' );
		}
		$ok        = $changed && ! $restore['isError'] && $back === $original;
		$this->record( $section, 'update-site-settings changes + restores tagline', $ok ? 'PASS' : 'FAIL', 'restored=' . var_export( $back === $original, true ) );
	}

	/**
	 * Full media CRUD lifecycle on a throwaway 1x1 PNG attachment + a throwaway host post.
	 *
	 * upload-media takes inline base64 (filename + data_base64), byte-sniffs the decoded payload
	 * against a raster-image allowlist, and returns {attachment_id, media:{...}}. The whole chain
	 * stays on fixtures this run creates: a 1x1 PNG attachment (tracked in $created_media) and a
	 * throwaway post (tracked in $created_posts) to receive the featured image. Both are deleted
	 * in cleanup(). Media shape (aafm_redact_media / aafm_media_item_payload): id, title, mime_type,
	 * url, alt, width, height (+ caption/description/date_gmt/filesize/parent/sizes on the item).
	 */
	private function test_media_lifecycle(): void {
		$section = 'Media';

		// Minimal valid 1x1 PNG (transparent), base64-encoded — finfo sniffs it as image/png.
		$png_b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

		$baseline = (int) ( $this->call_data( 'count-media', [] )['total'] ?? 0 );

		// upload-media: returns {attachment_id, media:{...}}.
		$up      = $this->call_data( 'upload-media', [
			'filename' => $this->marker . '-pixel.png',
			'data_base64' => $png_b64,
			'alt'         => $this->marker . ' alt text',
		] );
		$att_id  = (int) ( $up['attachment_id'] ?? 0 );
		if ( $att_id > 0 ) {
			$this->created_media[] = $att_id;
		}
		$media     = is_array( $up['media'] ?? null ) ? $up['media'] : [];
		$upload_ok = $att_id > 0
			&& 'image/png' === ( $media['mime_type'] ?? '' )
			&& false !== strpos( (string) ( $media['url'] ?? '' ), '.png' );
		$this->record( $section, 'upload-media uploads a base64 PNG', $upload_ok ? 'PASS' : 'FAIL', "id={$att_id} mime=" . ( $media['mime_type'] ?? '?' ) );
		if ( $att_id <= 0 ) {
			$this->record( $section, 'remaining media tests', 'FAIL', 'no attachment id; skipping dependent steps' );
			return;
		}

		// get-media-item: rich shape by id, with the alt we set on upload.
		$item    = $this->call_unwrap( 'get-media-item', [ 'attachment_id' => $att_id ], 'media' );
		$item_ok = (int) ( $item['id'] ?? 0 ) === $att_id
			&& false !== strpos( (string) ( $item['alt'] ?? '' ), $this->marker )
			&& array_key_exists( 'filesize', $item ) && array_key_exists( 'sizes', $item );
		$this->record( $section, 'get-media-item returns the rich item shape', $item_ok ? 'PASS' : 'FAIL', '' );

		// get-media: the inventory list includes the new attachment (searchable by its title marker).
		$list  = $this->call_data( 'get-media', [ 'search' => $this->marker, 'per_page' => 50 ] );
		$found = false;
		foreach ( ( $list['media'] ?? [] ) as $m ) {
			if ( (int) ( $m['id'] ?? 0 ) === $att_id ) {
				$found = true;
				break;
			}
		}
		$this->record( $section, 'get-media finds the attachment via search', $found ? 'PASS' : 'FAIL', 'count=' . count( $list['media'] ?? [] ) );

		// count-media: the total grew by at least one over the pre-upload baseline.
		$now = (int) ( $this->call_data( 'count-media', [] )['total'] ?? 0 );
		$this->record( $section, 'count-media total reflects the new upload', $now >= $baseline + 1 ? 'PASS' : 'FAIL', "before={$baseline} after={$now}" );

		// update-media: change the title + alt, verify both round-trip.
		$new_title = $this->marker . ' pixel updated';
		$uw        = $this->call( 'update-media', [
			'attachment_id' => $att_id,
			'title'         => $new_title,
			'alt'           => $this->marker . ' alt updated',
		] );
		$after_uw  = $this->call_unwrap( 'get-media-item', [ 'attachment_id' => $att_id ], 'media' );
		$upd_ok    = ! $uw['isError']
			&& false !== strpos( (string) $this->scalar( $after_uw['title'] ?? '' ), 'updated' )
			&& false !== strpos( (string) ( $after_uw['alt'] ?? '' ), 'alt updated' );
		$this->record( $section, 'update-media changes title and alt', $upd_ok ? 'PASS' : 'FAIL', '' );

		// set-featured-image: needs a throwaway host post; set the attachment as its thumbnail.
		$host    = $this->call( 'create-post', [
			'title'   => $this->marker . ' featured-host',
			'content' => '<p>featured image host</p>',
			'status'  => 'publish',
		] );
		$post_id = (int) ( $this->post_of( (array) ( $host['data'] ?? [] ) )['id'] ?? 0 );
		if ( $post_id > 0 ) {
			$this->created_posts[] = $post_id;
		}
		if ( $post_id <= 0 ) {
			$this->record( $section, 'set-featured-image sets the post thumbnail', 'FAIL', 'could not create host post' );
		} else {
			$sf      = $this->call( 'set-featured-image', [ 'post_id' => $post_id, 'attachment_id' => $att_id ] );
			$got     = $this->call_post( 'get-post', [ 'post_id' => $post_id ] );
			$fid     = (int) ( $got['featured_media'] ?? $got['featured_image_id'] ?? $got['thumbnail_id'] ?? 0 );
			$set_ok  = ! $sf['isError'] && (bool) ( $sf['data']['set'] ?? false ) && ( 0 === $fid || $fid === $att_id );
			$this->record( $section, 'set-featured-image sets the post thumbnail', $set_ok ? 'PASS' : 'FAIL', 'set=' . var_export( $sf['data']['set'] ?? null, true ) );
		}

		// delete-media: permanent removal; confirm get-media-item now errors, then drop from tracking.
		$dm     = $this->call( 'delete-media', [ 'attachment_id' => $att_id ] );
		$gone   = $this->call( 'get-media-item', [ 'attachment_id' => $att_id ] )['isError'];
		$del_ok = ! $dm['isError'] && (bool) ( $dm['data']['deleted'] ?? false ) && $gone;
		$this->record( $section, 'delete-media permanently removes the attachment', $del_ok ? 'PASS' : 'FAIL', '' );
		if ( $del_ok ) {
			$this->created_media = array_values( array_filter( $this->created_media, static fn( $id ) => $id !== $att_id ) );
		}
	}

	/**
	 * Full navigation-menu CRUD lifecycle on a throwaway menu + a throwaway item.
	 *
	 * Menus carry the AAFM-REGRESSION marker in their NAME so purge_orphans() can sweep a leak via
	 * list-menus. Menu items have no title search, but they die with their menu (delete-menu removes
	 * all items), and the run deletes the item explicitly first. Single-object menu/item reads and
	 * writes return their fields DIRECTLY (not enveloped): get-menu/create-menu/update-menu →
	 * {id,name,slug,count}; create/update-menu-item → {id,title,url,type,object,object_id,parent,order}.
	 */
	private function test_menus_lifecycle(): void {
		$section = 'Menus';

		// create-menu: marker name; returns {id,name,slug,count} directly.
		$cm      = $this->call( 'create-menu', [ 'name' => $this->marker . ' menu' ] );
		$menu_id = (int) ( $cm['data']['id'] ?? 0 );
		if ( $menu_id > 0 ) {
			$this->created_menus[] = $menu_id;
		}
		$create_ok = ! $cm['isError'] && $menu_id > 0 && false !== strpos( (string) ( $cm['data']['name'] ?? '' ), $this->marker );
		$this->record( $section, 'create-menu creates a menu', $create_ok ? 'PASS' : 'FAIL', "id={$menu_id}" );
		if ( $menu_id <= 0 ) {
			$this->record( $section, 'remaining menu tests', 'FAIL', 'no menu id; skipping dependent steps' );
			return;
		}

		// get-menu: round-trips the menu by id.
		$gm     = $this->call_data( 'get-menu', [ 'menu_id' => $menu_id ] );
		$get_ok = (int) ( $gm['id'] ?? 0 ) === $menu_id && false !== strpos( (string) ( $gm['name'] ?? '' ), $this->marker );
		$this->record( $section, 'get-menu returns the menu by id', $get_ok ? 'PASS' : 'FAIL', '' );

		// list-menus: the new menu is in the listing.
		$listed = false;
		foreach ( ( $this->call_data( 'list-menus', [] )['menus'] ?? [] ) as $m ) {
			if ( (int) ( $m['id'] ?? 0 ) === $menu_id ) {
				$listed = true;
				break;
			}
		}
		$this->record( $section, 'list-menus lists the new menu', $listed ? 'PASS' : 'FAIL', '' );

		// update-menu: rename, verify it round-trips.
		$um       = $this->call( 'update-menu', [ 'menu_id' => $menu_id, 'name' => $this->marker . ' menu renamed' ] );
		$after_um = $this->call_data( 'get-menu', [ 'menu_id' => $menu_id ] );
		$ren_ok   = ! $um['isError'] && false !== strpos( (string) ( $after_um['name'] ?? '' ), 'renamed' );
		$this->record( $section, 'update-menu renames the menu', $ren_ok ? 'PASS' : 'FAIL', '' );

		// create-menu-item: a custom link; returns the item fields directly.
		$ci      = $this->call( 'create-menu-item', [
			'menu_id' => $menu_id,
			'title'   => $this->marker . ' item',
			'url'     => 'https://example.com/aafm',
		] );
		$item_id = (int) ( $ci['data']['id'] ?? 0 );
		if ( $item_id > 0 ) {
			$this->created_menu_items[] = $item_id;
		}
		$ci_ok = ! $ci['isError'] && $item_id > 0 && false !== strpos( (string) ( $ci['data']['title'] ?? '' ), $this->marker );
		$this->record( $section, 'create-menu-item adds a custom-link item', $ci_ok ? 'PASS' : 'FAIL', "id={$item_id}" );

		if ( $item_id > 0 ) {
			// list-menu-items: the new item appears inside the menu.
			$in_menu = false;
			foreach ( ( $this->call_data( 'list-menu-items', [ 'menu_id' => $menu_id ] )['items'] ?? [] ) as $it ) {
				if ( (int) ( $it['id'] ?? 0 ) === $item_id ) {
					$in_menu = true;
					break;
				}
			}
			$this->record( $section, 'list-menu-items lists the new item', $in_menu ? 'PASS' : 'FAIL', '' );

			// update-menu-item: change label + url, verify both round-trip.
			$ui       = $this->call( 'update-menu-item', [
				'menu_id' => $menu_id,
				'item_id' => $item_id,
				'title'   => $this->marker . ' item updated',
				'url'     => 'https://example.com/aafm-updated',
			] );
			$ui_ok    = ! $ui['isError']
				&& false !== strpos( (string) ( $ui['data']['title'] ?? '' ), 'updated' )
				&& false !== strpos( (string) ( $ui['data']['url'] ?? '' ), 'aafm-updated' );
			$this->record( $section, 'update-menu-item changes label and url', $ui_ok ? 'PASS' : 'FAIL', '' );

			// delete-menu-item: permanent; confirm it is gone from the menu, then drop from tracking.
			$di      = $this->call( 'delete-menu-item', [ 'item_id' => $item_id ] );
			$still   = false;
			foreach ( ( $this->call_data( 'list-menu-items', [ 'menu_id' => $menu_id ] )['items'] ?? [] ) as $it ) {
				if ( (int) ( $it['id'] ?? 0 ) === $item_id ) {
					$still = true;
					break;
				}
			}
			$di_ok = ! $di['isError'] && (bool) ( $di['data']['deleted'] ?? false ) && ! $still;
			$this->record( $section, 'delete-menu-item removes the item', $di_ok ? 'PASS' : 'FAIL', '' );
			if ( $di_ok ) {
				$this->created_menu_items = array_values( array_filter( $this->created_menu_items, static fn( $id ) => $id !== $item_id ) );
			}
		} else {
			$this->record( $section, 'list-menu-items lists the new item', 'FAIL', 'no item id' );
			$this->record( $section, 'update-menu-item changes label and url', 'FAIL', 'no item id' );
			$this->record( $section, 'delete-menu-item removes the item', 'FAIL', 'no item id' );
		}

		// delete-menu: permanent removal of the menu (and any remaining items); drop from tracking.
		$dm     = $this->call( 'delete-menu', [ 'menu_id' => $menu_id ] );
		$gone   = $this->call( 'get-menu', [ 'menu_id' => $menu_id ] )['isError'];
		$dm_ok  = ! $dm['isError'] && (bool) ( $dm['data']['deleted'] ?? false ) && $gone;
		$this->record( $section, 'delete-menu permanently removes the menu', $dm_ok ? 'PASS' : 'FAIL', '' );
		if ( $dm_ok ) {
			$this->created_menus = array_values( array_filter( $this->created_menus, static fn( $id ) => $id !== $menu_id ) );
		}
	}

	/**
	 * Full reusable-block (wp_block) CRUD lifecycle on a throwaway synced block.
	 *
	 * Blocks carry the AAFM-REGRESSION marker in their TITLE so purge_orphans() can sweep a leak via
	 * list-blocks (which supports a search). create/get/update-block return the rich shape DIRECTLY
	 * (not enveloped): {id,title,slug,status,modified,content,date}. delete-block TRASHES the block
	 * (recoverable) and returns {id,status:'trash'}; cleanup then force-purges it.
	 */
	private function test_blocks_lifecycle(): void {
		$section = 'Blocks';

		// delete-block only TRASHES (wp_trash_post) — there is no MCP force-delete for wp_block, so a
		// created block can only be permanently removed host-side via the local WP-CLI bridge. In remote
		// mode that bridge can't reach the target, so create-block would leave a trashed orphan we cannot
		// clean up over the wire. By default SKIP the whole block lifecycle rather than orphan a trashed
		// block; --remote-blocks opts in to running it anyway, accepting one warned trashed wp_block.
		$remote          = ! $this->cli_targets_endpoint();
		$remote_blocks   = $remote && isset( $this->opts['remote-blocks'] );
		if ( $remote && ! $remote_blocks ) {
			$this->record( $section, 'block create/get/update/delete lifecycle', 'SKIP', 'delete-block only trashes; permanent removal needs local DDEV WP-CLI. Pass --remote-blocks to run it anyway (leaves one warned trashed wp_block)' );
			return;
		}

		$markup = '<!-- wp:paragraph --><p>' . $this->marker . ' block body</p><!-- /wp:paragraph -->';

		// create-block: marker title + block markup; returns the rich shape directly. In local mode the
		// block is tracked for the host-side WP-CLI force-delete in cleanup(). In --remote-blocks mode there
		// is no MCP force-delete, so it is tracked separately ($remote_trashed_blocks) purely so the run can
		// warn about the one trashed block it deliberately leaves behind.
		$cb       = $this->call( 'create-block', [ 'title' => $this->marker . ' block', 'content' => $markup ] );
		$block_id = (int) ( $cb['data']['id'] ?? 0 );
		if ( $block_id > 0 && ! $remote_blocks ) {
			$this->created_blocks[] = $block_id;
		}
		$create_ok = ! $cb['isError'] && $block_id > 0
			&& false !== strpos( (string) ( $cb['data']['title'] ?? '' ), $this->marker )
			&& false !== strpos( (string) ( $cb['data']['content'] ?? '' ), 'block body' );
		$this->record( $section, 'create-block creates a reusable block', $create_ok ? 'PASS' : 'FAIL', "id={$block_id}" );
		if ( $block_id <= 0 ) {
			$this->record( $section, 'remaining block tests', 'FAIL', 'no block id; skipping dependent steps' );
			return;
		}

		// get-block: round-trips title + markup.
		$gb     = $this->call_data( 'get-block', [ 'block_id' => $block_id ] );
		$get_ok = (int) ( $gb['id'] ?? 0 ) === $block_id
			&& false !== strpos( (string) ( $gb['content'] ?? '' ), 'block body' )
			&& false !== strpos( (string) ( $gb['content'] ?? '' ), 'wp:paragraph' );
		$this->record( $section, 'get-block returns the block with its markup', $get_ok ? 'PASS' : 'FAIL', '' );

		// list-blocks: the new block is findable via search by its title marker.
		$found = false;
		foreach ( ( $this->call_data( 'list-blocks', [ 'search' => $this->marker, 'per_page' => 50 ] )['blocks'] ?? [] ) as $b ) {
			if ( (int) ( $b['id'] ?? 0 ) === $block_id ) {
				$found = true;
				break;
			}
		}
		$this->record( $section, 'list-blocks finds the block via search', $found ? 'PASS' : 'FAIL', '' );

		// update-block: change the markup, verify it round-trips.
		$new_markup = '<!-- wp:paragraph --><p>' . $this->marker . ' block body edited</p><!-- /wp:paragraph -->';
		$ub         = $this->call( 'update-block', [ 'block_id' => $block_id, 'content' => $new_markup ] );
		$after_ub   = $this->call_data( 'get-block', [ 'block_id' => $block_id ] );
		$upd_ok     = ! $ub['isError'] && false !== strpos( (string) ( $after_ub['content'] ?? '' ), 'body edited' );
		$this->record( $section, 'update-block changes the markup', $upd_ok ? 'PASS' : 'FAIL', '' );

		// delete-block: trash it (recoverable), confirm status flipped. In local mode cleanup force-purges
		// the trashed block via WP-CLI. In --remote-blocks mode there is no MCP force-delete, so the trash
		// move is the documented terminal state — record it as the trashed id so the run warns at the end.
		$db     = $this->call( 'delete-block', [ 'block_id' => $block_id ] );
		$del_ok = ! $db['isError'] && 'trash' === (string) ( $db['data']['status'] ?? '' );
		$this->record( $section, 'delete-block moves the block to the trash', $del_ok ? 'PASS' : 'FAIL', 'status=' . ( $db['data']['status'] ?? '?' ) );
		if ( $remote_blocks && $del_ok ) {
			$this->remote_trashed_blocks[] = $block_id;
		}
	}

	/**
	 * ACF / SCF integration lifecycle. The 7 ACF abilities register only when an ACF host plugin is
	 * active AND (for the field reads/writes to round-trip) a field group exists.
	 *
	 * This site has ACF active but ships no field group, so the harness drops a temporary fixtures
	 * mu-plugin (the same one the term-meta write path uses) that registers ONE text field
	 * (aafm_reg_text / field key field_aafm_reg_text) on post + category + user. That makes the full
	 * round-trip exercisable on throwaway fixtures: a throwaway post, the created category term, and a
	 * throwaway user. Field values are snapshot-free — each fixture is created blank by this run and
	 * deleted in cleanup(), so writing a field value mutates only throwaway objects. The mu-plugin is
	 * removed at the end (reversible code drop-in, not a live-state change).
	 *
	 * Shapes (acf.php): acf-list-field-groups -> {field_groups:[{key,title,fields:[{key,label,type}]}]};
	 * acf-get/update-*-fields -> {post_id|term_id|user_id, fields:{<field name> => value}} (the hydrated
	 * map is keyed by field NAME, while update_field accepts the field KEY).
	 */
	private function test_acf_lifecycle(): void {
		$section    = 'ACF';
		$field_key  = 'field_aafm_reg_text';
		$field_name = 'aafm_reg_text';

		// Decide host state up front so the SKIP reason is accurate. The ACF tools are only exposed
		// when the host is active; if they are absent from tools/list, ACF is inactive/not installed.
		$acf_exposed = false;
		foreach ( $this->tool_names as $n ) {
			if ( str_ends_with( $n, 'acf-list-field-groups' ) ) {
				$acf_exposed = true;
				break;
			}
		}
		if ( ! $acf_exposed ) {
			$this->record( $section, 'ACF abilities (7) exercised', 'SKIP', 'ACF plugin not active on the test site (integration-style group)' );
			return;
		}

		// Remote mode: no local mu-plugin to register a known field group. Exercise the ACF tools
		// against whatever field groups already exist on the remote, using throwaway fixtures and
		// MCP-only snapshot/restore. If the remote has no field groups, SKIP every ACF check.
		if ( ! $this->cli_targets_endpoint() ) {
			$this->test_acf_remote();
			return;
		}

		// Install the fixtures mu-plugin so a field group exists to read/write. Without it the host is
		// active but there is no field group, so the value round-trips cannot be proven — SKIP with a
		// clear reason rather than fabricate a permanent field group.
		if ( ! $this->install_fixture_plugin() ) {
			$this->record( $section, 'ACF field reads/writes (no field group)', 'SKIP', 'could not install the fixtures mu-plugin to register a throwaway field group' );
			return;
		}

		// acf-list-field-groups: the throwaway group + its text field must appear (structure only).
		$groups   = $this->call_data( 'acf-list-field-groups', [] )['field_groups'] ?? [];
		$has_group = false;
		foreach ( $groups as $g ) {
			if ( 'group_aafm_regression' === ( $g['key'] ?? '' ) ) {
				foreach ( ( $g['fields'] ?? [] ) as $f ) {
					if ( ( $f['key'] ?? '' ) === $field_key && 'text' === ( $f['type'] ?? '' ) ) {
						$has_group = true;
					}
				}
			}
		}
		$this->record( $section, 'acf-list-field-groups lists the field group structure', $has_group ? 'PASS' : 'FAIL', 'groups=' . count( $groups ) );

		// --- Post fields: a throwaway post, blank by creation. ---
		$created = $this->call( 'create-post', [
			'title'   => $this->marker . ' acf-post',
			'content' => '<p>acf host body</p>',
			'status'  => 'publish',
		] );
		$post_id = (int) ( $this->post_of( (array) ( $created['data'] ?? [] ) )['id'] ?? 0 );
		if ( $post_id > 0 ) {
			$this->created_posts[] = $post_id;
		}
		if ( $post_id <= 0 ) {
			$this->record( $section, 'acf post-field read/write round-trips', 'FAIL', 'could not create host post' );
		} else {
			// get (blank), update (write the field by key), get (read back by name).
			$g0      = $this->call_data( 'acf-get-post-fields', [ 'post_id' => $post_id ] );
			$wrote   = $this->call( 'acf-update-post-fields', [ 'post_id' => $post_id, 'fields' => [ $field_key => $this->marker . ' acf post value' ] ] );
			$g1      = $this->call_data( 'acf-get-post-fields', [ 'post_id' => $post_id ] );
			$value   = (string) ( ( $g1['fields'][ $field_name ] ?? '' ) );
			$post_ok = isset( $g0['post_id'] ) && ! $wrote['isError'] && false !== strpos( $value, 'acf post value' );
			$this->record( $section, 'acf post-field read/write round-trips', $post_ok ? 'PASS' : 'FAIL', '' );
		}

		// --- Term fields: the category term created by test_terms_full_lifecycle(). ---
		$term_id = $this->primary_term_id ?? 0;
		if ( $term_id <= 0 ) {
			$this->record( $section, 'acf term-field read/write round-trips', 'SKIP', 'no term fixture from the terms lifecycle' );
		} else {
			$gt0     = $this->call_data( 'acf-get-term-fields', [ 'term_id' => $term_id ] );
			$wrote_t = $this->call( 'acf-update-term-fields', [ 'term_id' => $term_id, 'fields' => [ $field_key => $this->marker . ' acf term value' ] ] );
			$gt1     = $this->call_data( 'acf-get-term-fields', [ 'term_id' => $term_id ] );
			$tvalue  = (string) ( ( $gt1['fields'][ $field_name ] ?? '' ) );
			$term_ok = isset( $gt0['term_id'] ) && ! $wrote_t['isError'] && false !== strpos( $tvalue, 'acf term value' );
			$this->record( $section, 'acf term-field read/write round-trips', $term_ok ? 'PASS' : 'FAIL', '' );
		}

		// --- User fields: a throwaway user, blank by creation. ---
		$rand    = substr( (string) random_int( 100000, 999999 ), 0, 6 );
		$cu      = $this->call_unwrap( 'create-user', [
			'username'     => 'aafm_regression_acf_' . $rand,
			'email'        => 'aafm_regression_acf_' . $rand . '@example.test',
			'display_name' => $this->marker . ' acf-user',
		], 'user' );
		$user_id = (int) ( $cu['id'] ?? 0 );
		if ( $user_id > 0 ) {
			$this->created_users[] = $user_id;
		}
		if ( $user_id <= 0 ) {
			$this->record( $section, 'acf user-field read/write round-trips', 'FAIL', 'could not create host user' );
		} else {
			$gu0     = $this->call_data( 'acf-get-user-fields', [ 'user_id' => $user_id ] );
			$wrote_u = $this->call( 'acf-update-user-fields', [ 'user_id' => $user_id, 'fields' => [ $field_key => $this->marker . ' acf user value' ] ] );
			$gu1     = $this->call_data( 'acf-get-user-fields', [ 'user_id' => $user_id ] );
			$uvalue  = (string) ( ( $gu1['fields'][ $field_name ] ?? '' ) );
			$user_ok = isset( $gu0['user_id'] ) && ! $wrote_u['isError'] && false !== strpos( $uvalue, 'acf user value' );
			$this->record( $section, 'acf user-field read/write round-trips', $user_ok ? 'PASS' : 'FAIL', '' );
		}
	}

	/**
	 * Remote-mode ACF exercise. There is no local mu-plugin field group to lean on, so this works
	 * against the field groups that already exist on the target:
	 *
	 *   - acf-list-field-groups always runs (structure read). If the remote has ZERO field groups,
	 *     every value round-trip is SKIPped with a clear reason.
	 *   - Otherwise it finds an existing group with a post-located and/or user-located text-like field,
	 *     then exercises acf get/update against a THROWAWAY post and user (created blank by this run,
	 *     deleted in cleanup), snapshotting the field via MCP and restoring it via MCP afterwards.
	 *
	 * No WP-CLI bridge is touched and no live object is mutated — only throwaway fixtures, whose own
	 * field values are written then created-fresh-and-deleted anyway. The term path is intentionally
	 * omitted on remote: creating a category term to host a field has no MCP delete (it would orphan).
	 */
	private function test_acf_remote(): void {
		$section = 'ACF';

		$groups = $this->call_data( 'acf-list-field-groups', [] )['field_groups'] ?? [];
		$this->record( $section, 'acf-list-field-groups lists the field group structure', ! empty( $groups ) ? 'PASS' : 'FAIL', 'groups=' . count( $groups ) );
		if ( empty( $groups ) ) {
			$this->record( $section, 'acf field reads/writes against an existing group', 'SKIP', 'ACF active but no field groups on the target' );
			return;
		}

		// Pick the first simple, writable text-like field key from any group. ACF list shape exposes
		// {key,label,type} per field; text/textarea/url/email round-trip a plain string cleanly.
		$writable_types = [ 'text', 'textarea', 'url', 'email', 'number', 'password' ];
		$field_key      = '';
		foreach ( $groups as $g ) {
			foreach ( ( $g['fields'] ?? [] ) as $f ) {
				if ( in_array( (string) ( $f['type'] ?? '' ), $writable_types, true ) && '' !== (string) ( $f['key'] ?? '' ) ) {
					$field_key = (string) $f['key'];
					break 2;
				}
			}
		}
		if ( '' === $field_key ) {
			$this->record( $section, 'acf field reads/writes against an existing group', 'SKIP', 'no simple text-like field in any existing group to round-trip safely' );
			return;
		}

		$probe_value = $this->marker . ' acf remote value';

		// --- Post fields against a throwaway post (blank by creation; deleted in cleanup). ---
		$created = $this->call( 'create-post', [
			'title'   => $this->marker . ' acf-post',
			'content' => '<p>acf host body</p>',
			'status'  => 'publish',
		] );
		$post_id = (int) ( $this->post_of( (array) ( $created['data'] ?? [] ) )['id'] ?? 0 );
		if ( $post_id > 0 ) {
			$this->created_posts[] = $post_id;
		}
		if ( $post_id <= 0 ) {
			$this->record( $section, 'acf post-field read/write round-trips (existing group)', 'FAIL', 'could not create host post' );
		} else {
			// The field key may not be located on plain posts in the remote's group config. Probe get
			// first; only assert the round-trip when the field actually hydrates for this object.
			$g0       = $this->call( 'acf-get-post-fields', [ 'post_id' => $post_id ] );
			$applies  = ! $g0['isError'] && isset( $g0['data']['post_id'] );
			if ( ! $applies ) {
				$this->record( $section, 'acf post-field read/write round-trips (existing group)', 'SKIP', 'no ACF field group located on plain posts on the target' );
			} else {
				$wrote   = $this->call( 'acf-update-post-fields', [ 'post_id' => $post_id, 'fields' => [ $field_key => $probe_value ] ] );
				$g1      = $this->call_data( 'acf-get-post-fields', [ 'post_id' => $post_id ] );
				$present = false;
				foreach ( (array) ( $g1['fields'] ?? [] ) as $v ) {
					if ( is_string( $v ) && false !== strpos( $v, 'acf remote value' ) ) {
						$present = true;
						break;
					}
				}
				$this->record( $section, 'acf post-field read/write round-trips (existing group)', ( ! $wrote['isError'] && $present ) ? 'PASS' : 'FAIL', '' );
			}
			// The post is a throwaway deleted in cleanup, so the written field value dies with it — no
			// restore needed.
		}

		// --- User fields against a throwaway user (blank by creation; deleted in cleanup). ---
		$rand    = substr( (string) random_int( 100000, 999999 ), 0, 6 );
		$cu      = $this->call_unwrap( 'create-user', [
			'username'     => 'aafm_regression_acf_' . $rand,
			'email'        => 'aafm_regression_acf_' . $rand . '@example.test',
			'display_name' => $this->marker . ' acf-user',
		], 'user' );
		$user_id = (int) ( $cu['id'] ?? 0 );
		if ( $user_id > 0 ) {
			$this->created_users[] = $user_id;
		}
		if ( $user_id <= 0 ) {
			$this->record( $section, 'acf user-field read/write round-trips (existing group)', 'SKIP', 'could not create host user' );
		} else {
			$gu0     = $this->call( 'acf-get-user-fields', [ 'user_id' => $user_id ] );
			$applies = ! $gu0['isError'] && isset( $gu0['data']['user_id'] );
			if ( ! $applies ) {
				$this->record( $section, 'acf user-field read/write round-trips (existing group)', 'SKIP', 'no ACF field group located on users on the target' );
			} else {
				$wrote_u = $this->call( 'acf-update-user-fields', [ 'user_id' => $user_id, 'fields' => [ $field_key => $probe_value ] ] );
				$gu1     = $this->call_data( 'acf-get-user-fields', [ 'user_id' => $user_id ] );
				$present = false;
				foreach ( (array) ( $gu1['fields'] ?? [] ) as $v ) {
					if ( is_string( $v ) && false !== strpos( $v, 'acf remote value' ) ) {
						$present = true;
						break;
					}
				}
				$this->record( $section, 'acf user-field read/write round-trips (existing group)', ( ! $wrote_u['isError'] && $present ) ? 'PASS' : 'FAIL', '' );
			}
		}
	}

	private function verify_baseline_restored(): void {
		if ( isset( $this->opts['keep'] ) ) {
			return;
		}
		$baseline = $this->baseline_publish ?? null;
		if ( null === $baseline ) {
			return;
		}
		$now = $this->find_count( $this->call_data( 'count-posts', [ 'post_type' => 'post' ] ), 'publish' );
		$this->record( 'Cleanup', 'published count returns to baseline', $now === $baseline ? 'PASS' : 'FAIL', "before={$baseline} after=" . var_export( $now, true ) );
	}

	/** Trash + permanently delete every fixture. Idempotent; safe to call twice. */
	public function cleanup(): void {
		// Always restore any options/state a test mutated but did not get to restore inline (e.g. a
		// transport fatal() exited mid-test). This runs even under --keep: a leaked option is live
		// configuration, not a throwaway fixture object, and must never be left behind.
		$this->flush_pending_restores();
		if ( isset( $this->opts['keep'] ) ) {
			if ( $this->created_posts || $this->created_pages || $this->created_comments || $this->created_users || $this->created_media || $this->created_menus || $this->created_menu_items || $this->created_blocks || $this->created_terms || $this->created_templates ) {
				$this->line( '' );
				$this->line( '--keep set; leaving fixtures: posts=' . implode( ',', $this->created_posts ) . ' pages=' . implode( ',', $this->created_pages ) . ' comments=' . implode( ',', $this->created_comments ) . ' users=' . implode( ',', $this->created_users ) . ' media=' . implode( ',', $this->created_media ) . ' menus=' . implode( ',', $this->created_menus ) . ' menu_items=' . implode( ',', $this->created_menu_items ) . ' blocks=' . implode( ',', $this->created_blocks ) . ' terms=' . implode( ',', $this->created_terms ) . ' templates=' . implode( ',', $this->created_templates ) );
			}
			// Still remove the temporary fixtures mu-plugin even under --keep — it is a code drop-in,
			// not a fixture object, and must never be left behind. Guarded so removal can't be skipped.
			try {
				$this->remove_fixture_plugin();
			} catch ( \Throwable $e ) {
				$this->line( 'fixtures mu-plugin removal failed: ' . $e->getMessage() );
			}
			return;
		}
		// Every sub-step below is individually guarded so one failure — most importantly a transport
		// fatal() (now throwable) when the MCP endpoint is unreachable, the exact case this path exists
		// to survive — can never skip the remaining deletions, the host-side WP-CLI restores, or the
		// mu-plugin removal. call_quiet() already swallows \Throwable; the host-side WP-CLI sub-steps
		// (ddev_wp/delete_db_template) and the get-users lookup in reassign_target_user() get their own
		// try/catch here. WP-CLI restores do not depend on the HTTP endpoint, so they still run when it
		// is down.

		// Comments first: a host post delete cascades its comments, but a comment may outlive
		// its host in a failed run, so delete the tracked ids explicitly and idempotently.
		foreach ( $this->created_comments as $id ) {
			$this->call_quiet( 'delete-comment', [ 'comment_id' => $id ] );
		}
		foreach ( $this->created_posts as $id ) {
			$this->call_quiet( 'trash-post', [ 'post_id' => $id ] );
			$this->call_quiet( 'delete-post', [ 'post_id' => $id ] );
		}
		foreach ( $this->created_pages as $id ) {
			$this->call_quiet( 'trash-page', [ 'page_id' => $id ] );
			$this->call_quiet( 'delete-page', [ 'page_id' => $id ] );
		}
		// Menu items first (a delete-menu cascades its items, but an item may outlive its menu in a
		// failed run), then the menus themselves. Both deletes are permanent (nav menus have no Trash).
		foreach ( $this->created_menu_items as $id ) {
			$this->call_quiet( 'delete-menu-item', [ 'item_id' => $id ] );
		}
		foreach ( $this->created_menus as $id ) {
			$this->call_quiet( 'delete-menu', [ 'menu_id' => $id ] );
		}
		// Media attachments: permanent delete via the ability.
		foreach ( $this->created_media as $id ) {
			$this->call_quiet( 'delete-media', [ 'attachment_id' => $id ] );
		}
		// Reusable blocks: the ability only TRASHES (recoverable by design), so a trashed wp_block
		// still carries the marker. There is no MCP force-delete for wp_block (it is outside the
		// content allowlist), so finish the purge host-side via WP-CLI — same DDEV bridge used for
		// option snapshot/restore — to leave zero AAFM-REGRESSION objects.
		foreach ( $this->created_blocks as $id ) {
			$this->call_quiet( 'delete-block', [ 'block_id' => $id ] );
			try {
				$this->ddev_wp( [ 'post', 'delete', (string) $id, '--force' ] );
			} catch ( \Throwable $e ) {
				$this->line( "block force-delete #{$id} failed: " . $e->getMessage() );
			}
		}
		// DB-backed templates: created host-side (no MCP create-template tool), so force-delete them
		// host-side too. delete_db_template re-confirms the marker title before deleting.
		foreach ( $this->created_templates as $id ) {
			try {
				$this->delete_db_template( $id );
			} catch ( \Throwable $e ) {
				$this->line( "template delete #{$id} failed: " . $e->getMessage() );
			}
		}
		// Terms: there is NO delete-term MCP tool, so the created category terms are removed host-side
		// via WP-CLI. Re-confirm the name carries the marker before deleting — never touch a term we
		// did not create.
		foreach ( $this->created_terms as $id ) {
			try {
				$name = $this->ddev_wp( [ 'term', 'get', 'category', (string) $id, '--field=name' ] );
				if ( null !== $name && false !== strpos( $name, self::FIXTURE_PREFIX ) ) {
					$this->ddev_wp( [ 'term', 'delete', 'category', (string) $id ] );
				}
			} catch ( \Throwable $e ) {
				$this->line( "term delete #{$id} failed: " . $e->getMessage() );
			}
		}
		// Users last: delete-user needs a reassign target. Reassign any leftover content to an
		// existing OTHER user (resolved via get-users, transport-agnostic) and never to the victim.
		// reassign_target_user() hits the endpoint, so guard it: a dead endpoint must not abort the
		// later mu-plugin removal.
		foreach ( $this->created_users as $id ) {
			try {
				$reassign = $this->reassign_target_user( $id );
				if ( $reassign > 0 && $reassign !== $id ) {
					$this->call_quiet( 'delete-user', [ 'user_id' => $id, 'reassign_to' => $reassign ] );
				}
			} catch ( \Throwable $e ) {
				$this->line( "user delete #{$id} failed: " . $e->getMessage() );
			}
		}
		// The temporary fixtures mu-plugin (term-meta allowlist + ACF field group) must never outlive
		// the run — remove it regardless of whether the test path installed it. Host-side WP-CLI, so it
		// runs even when the HTTP endpoint is down; guarded so nothing can leave it behind.
		try {
			$this->remove_fixture_plugin();
		} catch ( \Throwable $e ) {
			$this->line( 'fixtures mu-plugin removal failed: ' . $e->getMessage() );
		}
		$this->created_posts      = [];
		$this->created_pages      = [];
		$this->created_comments   = [];
		$this->created_users      = [];
		$this->created_media      = [];
		$this->created_menus      = [];
		$this->created_menu_items = [];
		$this->created_blocks     = [];
		$this->created_terms      = [];
		$this->created_templates  = [];
	}

	/**
	 * Find and permanently delete any leftover fixtures whose title carries the
	 * fixture prefix — orphans from a run that died before cleanup. Searches posts
	 * and pages across every status.
	 */
	private function purge_orphans(): void {
		$statuses = [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ];
		$sets     = [
			[ 'get-posts', 'trash-post', 'delete-post', 'post_id' ],
			[ 'get-pages', 'trash-page', 'delete-page', 'page_id' ],
		];
		$purged = 0;
		foreach ( $sets as [ $list_tool, $trash_tool, $delete_tool, $id_arg ] ) {
			$seen = [];
			foreach ( $statuses as $status ) {
				$data = $this->call_data( $list_tool, [ 'search' => self::FIXTURE_PREFIX, 'status' => $status, 'per_page' => 50 ] );
				foreach ( ( $data['posts'] ?? [] ) as $item ) {
					$id    = (int) ( $item['id'] ?? 0 );
					$title = (string) $this->scalar( $item['title'] ?? '' );
					if ( $id <= 0 || isset( $seen[ $id ] ) || false === strpos( $title, self::FIXTURE_PREFIX ) ) {
						continue;
					}
					$seen[ $id ] = true;
					$this->call_quiet( $trash_tool, [ $id_arg => $id ] );
					$this->call_quiet( $delete_tool, [ $id_arg => $id ] );
					$this->line( "purged {$list_tool} #{$id}  ({$title})" );
					++$purged;
				}
			}
		}

		// Sweep marked comments. There is no title search for comments, so match the marker in
		// the body across both the approved site-wide listing and the held moderation queue.
		$seen_comments = [];
		foreach ( [
			[ 'get-comments', [ 'per_page' => 50 ] ],
			[ 'get-pending-comments', [ 'per_page' => 50 ] ],
		] as [ $tool, $args ] ) {
			$data = $this->call_data( $tool, $args );
			foreach ( ( $data['comments'] ?? [] ) as $item ) {
				$id   = (int) ( $item['id'] ?? 0 );
				$body = (string) ( $item['content'] ?? '' );
				if ( $id <= 0 || isset( $seen_comments[ $id ] ) || false === strpos( $body, self::FIXTURE_PREFIX ) ) {
					continue;
				}
				$seen_comments[ $id ] = true;
				$this->call_quiet( 'delete-comment', [ 'comment_id' => $id ] );
				$this->line( "purged comment #{$id}" );
				++$purged;
			}
		}

		// Sweep marked media: the throwaway attachment titles carry the prefix; get-media searches by
		// title. delete-media is a permanent attachment delete.
		$media = $this->call_data( 'get-media', [ 'search' => self::FIXTURE_PREFIX, 'per_page' => 50 ] );
		foreach ( ( $media['media'] ?? [] ) as $item ) {
			$id    = (int) ( $item['id'] ?? 0 );
			$title = (string) $this->scalar( $item['title'] ?? '' );
			if ( $id <= 0 || false === strpos( $title, self::FIXTURE_PREFIX ) ) {
				continue;
			}
			$this->call_quiet( 'delete-media', [ 'attachment_id' => $id ] );
			$this->line( "purged media #{$id}  ({$title})" );
			++$purged;
		}

		// Sweep marked menus: the throwaway menu NAMES carry the prefix. delete-menu permanently
		// removes the menu and all of its items, so the items are cleaned with the menu.
		foreach ( ( $this->call_data( 'list-menus', [] )['menus'] ?? [] ) as $item ) {
			$id   = (int) ( $item['id'] ?? 0 );
			$name = (string) ( $item['name'] ?? '' );
			if ( $id <= 0 || false === strpos( $name, self::FIXTURE_PREFIX ) ) {
				continue;
			}
			$this->call_quiet( 'delete-menu', [ 'menu_id' => $id ] );
			$this->line( "purged menu #{$id}  ({$name})" );
			++$purged;
		}

		// Sweep marked reusable blocks: the throwaway TITLES carry the prefix; list-blocks searches by
		// title and surfaces publish+draft rows. delete-block only trashes, so finish each one host-side
		// with a WP-CLI force-delete. A separate WP-CLI pass catches any already-trashed marked block
		// (which list-blocks no longer returns).
		foreach ( ( $this->call_data( 'list-blocks', [ 'search' => self::FIXTURE_PREFIX, 'per_page' => 50 ] )['blocks'] ?? [] ) as $item ) {
			$id    = (int) ( $item['id'] ?? 0 );
			$title = (string) ( $item['title'] ?? '' );
			if ( $id <= 0 || false === strpos( $title, self::FIXTURE_PREFIX ) ) {
				continue;
			}
			$this->call_quiet( 'delete-block', [ 'block_id' => $id ] );
			$this->ddev_wp( [ 'post', 'delete', (string) $id, '--force' ] );
			$this->line( "purged block #{$id}  ({$title})" );
			++$purged;
		}
		$purged += $this->purge_orphan_blocks_cli();

		// Sweep marked users: the throwaway logins/emails carry the prefix, so a search by it finds
		// them. Reassign each one's content to an existing OTHER user (resolved via get-users, so the
		// sweep works in every auth mode and on remote sites); never reassign to the victim itself.
		$users = $this->call_data( 'get-users', [ 'search' => self::FIXTURE_PREFIX, 'per_page' => 50 ] );
		foreach ( ( $users['users'] ?? [] ) as $item ) {
			$id    = (int) ( $item['id'] ?? 0 );
			$email = (string) ( $item['email'] ?? '' );
			if ( $id <= 0 || false === stripos( $email, 'aafm_regression' ) ) {
				continue;
			}
			$reassign = $this->reassign_target_user( $id );
			if ( $reassign > 0 && $reassign !== $id ) {
				$this->call_quiet( 'delete-user', [ 'user_id' => $id, 'reassign_to' => $reassign ] );
				$this->line( "purged user #{$id}  ({$email})" );
				++$purged;
			}
		}

		// Sweep marked category terms host-side. There is no delete-term MCP tool and get-terms'
		// search is name-scoped, but a leaked term carries the fixture prefix in its NAME, so a
		// WP-CLI term-list search by the prefix finds every orphan across the taxonomy.
		$purged += $this->purge_orphan_terms_cli();

		// Sweep marked DB-backed templates host-side. There is no MCP create/delete-template tool, so a
		// leaked throwaway wp_template/wp_template_part is found by its marker TITLE and force-deleted.
		$purged += $this->purge_orphan_templates_cli();

		// Remove any leftover fixtures mu-plugin from a run that died before cleanup.
		$this->remove_fixture_plugin();

		$this->line( "Purged {$purged} fixture object(s)." );
	}

	/**
	 * Host-side sweep for marked category terms. delete-term has no MCP ability, so leaked throwaway
	 * terms are found by their marker NAME via `wp term list` and removed with `wp term delete`. Each
	 * candidate's name is re-confirmed to carry the fixture prefix before deletion — never touch a
	 * term this harness did not create.
	 *
	 * @return int Count of terms deleted.
	 */
	private function purge_orphan_terms_cli(): int {
		$out = $this->ddev_wp( [ 'term', 'list', 'category', '--search=' . self::FIXTURE_PREFIX, '--field=term_id', '--format=ids' ] );
		if ( null === $out || '' === trim( $out ) ) {
			return 0;
		}
		$purged = 0;
		foreach ( preg_split( '/\s+/', trim( $out ) ) as $id ) {
			if ( ! ctype_digit( $id ) ) {
				continue;
			}
			$name = $this->ddev_wp( [ 'term', 'get', 'category', $id, '--field=name' ] );
			if ( null === $name || false === strpos( $name, self::FIXTURE_PREFIX ) ) {
				continue;
			}
			$this->ddev_wp( [ 'term', 'delete', 'category', $id ] );
			$this->line( "purged term #{$id}  ({$name})" );
			++$purged;
		}
		return $purged;
	}

	/**
	 * Create a database-backed wp_template post for the active (block) theme, host-side via WP-CLI,
	 * and return its block-template id ({stylesheet}//{slug}) — or null on failure.
	 *
	 * update-template only edits a template that has a backing post (source 'custom', wp_id set);
	 * a fresh block-theme install has only theme-FILE templates, which the ability refuses by design.
	 * This drops one throwaway custom template (marker in its title) so the write path is exercisable,
	 * tracked in $created_templates and force-deleted in cleanup()/purge. The post id is parsed back so
	 * cleanup can delete exactly the post this created — never a pre-existing template.
	 *
	 * @return array{id:string,post_id:int}|null
	 */
	private function create_db_template(): ?array {
		$slug = 'aafm-regression-tpl-' . substr( (string) getmypid(), -4 );
		$php  = '$slug = ' . var_export( $slug, true ) . ';'
			. '$stylesheet = get_stylesheet();'
			. '$pid = wp_insert_post( array('
			. '"post_title" => "' . self::FIXTURE_PREFIX . ' template",'
			. '"post_name" => $slug,'
			. '"post_content" => "<!-- wp:paragraph --><p>aafm regression template seed</p><!-- /wp:paragraph -->",'
			. '"post_status" => "publish",'
			. '"post_type" => "wp_template",'
			. '), true );'
			. 'if ( is_wp_error( $pid ) ) { echo "ERR"; return; }'
			. 'wp_set_object_terms( $pid, $stylesheet, "wp_theme" );'
			. 'echo $pid . "|" . $stylesheet . "//" . $slug;';
		$out = $this->ddev_wp( [ 'eval', $php ] );
		if ( null === $out ) {
			return null;
		}
		$out = trim( $out );
		if ( '' === $out || false === strpos( $out, '|' ) ) {
			return null;
		}
		[ $pid, $id ] = explode( '|', $out, 2 );
		if ( ! ctype_digit( $pid ) || '' === $id ) {
			return null;
		}
		$post_id = (int) $pid;
		$this->created_templates[] = $post_id;
		return [ 'id' => $id, 'post_id' => $post_id ];
	}

	/**
	 * Force-delete a tracked DB-backed template post host-side, re-confirming its title carries the
	 * fixture prefix first — never touch a template this harness did not create.
	 */
	private function delete_db_template( int $post_id ): void {
		$title = $this->ddev_wp( [ 'post', 'get', (string) $post_id, '--field=post_title' ] );
		if ( null !== $title && false !== strpos( $title, self::FIXTURE_PREFIX ) ) {
			$this->ddev_wp( [ 'post', 'delete', (string) $post_id, '--force' ] );
		}
	}

	/**
	 * Host-side sweep for marked DB-backed templates (wp_template + wp_template_part) left by a run that
	 * died before cleanup. Found by their marker TITLE across all statuses and force-deleted.
	 *
	 * @return int Count of template posts force-deleted.
	 */
	private function purge_orphan_templates_cli(): int {
		$out = $this->ddev_wp( [ 'post', 'list', '--post_type=wp_template,wp_template_part', '--post_status=any', '--s=' . self::FIXTURE_PREFIX, '--field=ID', '--format=ids' ] );
		if ( null === $out || '' === trim( $out ) ) {
			return 0;
		}
		$purged = 0;
		foreach ( preg_split( '/\s+/', trim( $out ) ) as $id ) {
			if ( ! ctype_digit( $id ) ) {
				continue;
			}
			$title = $this->ddev_wp( [ 'post', 'get', $id, '--field=post_title' ] );
			if ( null === $title || false === strpos( $title, self::FIXTURE_PREFIX ) ) {
				continue;
			}
			$this->ddev_wp( [ 'post', 'delete', $id, '--force' ] );
			$this->line( "purged template #{$id}" );
			++$purged;
		}
		return $purged;
	}

	/* ---------------------------------------------------------------------
	 * Temporary fixtures mu-plugin bridge (host-side, via DDEV).
	 *
	 * The term-meta allowlist (aafm_allowed_term_meta_keys) and ACF field groups are FILTER/code
	 * configuration, not options, so they cannot be set over WP-CLI option writes. To exercise the
	 * term-meta write path and the ACF field round-trips, a tiny mu-plugin is dropped into the WP
	 * install for the duration of the run and removed afterwards — a reversible code drop-in that
	 * touches no live data. It registers exactly one allowlisted term-meta key and one throwaway ACF
	 * text field on post + category + user.
	 * ------------------------------------------------------------------- */

	/** Absolute path (inside the web container) to the temporary fixtures mu-plugin. */
	private function fixture_plugin_path(): string {
		return 'wp/wp-content/mu-plugins/aafm-regression-fixtures.php';
	}

	/**
	 * Drop the fixtures mu-plugin into the WP install via the DDEV web container. Idempotent: a second
	 * call is a no-op once installed. Returns true when the file is present and the integrations are
	 * live (verified by re-reading the term-meta allowlist + ACF group through WP-CLI).
	 */
	private function install_fixture_plugin(): bool {
		if ( $this->fixture_plugin_installed ) {
			return true;
		}
		// Remote mode: the bridge would drop the mu-plugin onto the LOCAL DDEV site, not the --url
		// target — useless for the test and a forbidden mutation of the local site. Refuse so callers
		// SKIP the fixture-dependent write paths.
		if ( ! $this->cli_targets_endpoint() ) {
			return false;
		}
		$php = <<<'PHP'
<?php
// AAFM-REGRESSION temporary fixtures. Registers, for the duration of a regression run: one allowlisted
// term-meta key, one allowlisted post-meta key, one throwaway agent-writable custom post type, and one
// throwaway ACF text field (post + category + user). This lets the harness exercise the term-meta and
// post-meta write paths, the CPT create/update path, and the ACF field round-trips against throwaway
// fixtures. Every addition is a FILTER drop-in (no live option is touched) and the file is auto-removed
// by bin/mcp-regression.php at the end of the run, so nothing here outlives the run.
add_filter( 'aafm_allowed_term_meta_keys', function ( $keys ) { $keys[] = 'aafm_regression_tm'; return $keys; } );
add_filter( 'aafm_allowed_meta_keys', function ( $keys ) { $keys[] = 'aafm_regression_pm'; return $keys; } );
// Expose the throwaway CPT to agents. The filter is re-floored by the plugin against the eligibility
// gate (public + non-builtin), so the type must be registered first (priority default fires after init).
add_filter( 'aafm_allowed_post_types', function ( $types ) { $types[] = 'aafm_regression_cpt'; return $types; } );
// A throwaway, agent-writable custom post type. public + show_in_rest clears the eligibility floor;
// capability_type 'post' + map_meta_cap reuses the administrator's edit/publish/delete_post caps (so the
// mcp-agent admin can create, edit, publish, and delete it) and satisfies the update path's map_meta_cap
// requirement. Not registered as a real content type anywhere else — it dies with this mu-plugin.
add_action( 'init', function () {
	register_post_type( 'aafm_regression_cpt', array(
		'label'           => 'AAFM Regression CPT',
		'public'          => true,
		'show_in_rest'    => true,
		'capability_type' => 'post',
		'map_meta_cap'    => true,
		'supports'        => array( 'title', 'editor' ),
	) );
} );
add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) { return; }
	acf_add_local_field_group( array(
		'key'      => 'group_aafm_regression',
		'title'    => 'AAFM-REGRESSION fields',
		'fields'   => array(
			array( 'key' => 'field_aafm_reg_text', 'label' => 'AAFM Reg Text', 'name' => 'aafm_reg_text', 'type' => 'text' ),
		),
		'location' => array(
			array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
			array( array( 'param' => 'taxonomy', 'operator' => '==', 'value' => 'category' ) ),
			array( array( 'param' => 'user_form', 'operator' => '==', 'value' => 'all' ) ),
		),
	) );
} );
PHP;
		$path = $this->fixture_plugin_path();
		// Write the file inside the container via base64 to avoid any shell-quoting hazards.
		$b64 = base64_encode( $php );
		$cmd = 'ddev exec ' . escapeshellarg( 'mkdir -p wp/wp-content/mu-plugins && echo ' . escapeshellarg( $b64 ) . ' | base64 -d > ' . escapeshellarg( $path ) ) . ' 2>/dev/null';
		shell_exec( $cmd );

		// Verify the integrations are live before declaring success. The term-meta + post-meta allowlists
		// and the throwaway CPT are the gate (the write-path tests depend on them); the ACF group is a
		// bonus consumed only by test_acf_lifecycle.
		$allow_tm = $this->ddev_wp( [ 'eval', 'echo in_array("aafm_regression_tm", aafm_allowed_term_meta_keys(), true) ? "1" : "0";' ] );
		$allow_pm = $this->ddev_wp( [ 'eval', 'echo in_array("aafm_regression_pm", aafm_allowed_meta_keys(), true) ? "1" : "0";' ] );
		$cpt      = $this->ddev_wp( [ 'eval', 'echo ( post_type_exists("aafm_regression_cpt") && in_array("aafm_regression_cpt", aafm_allowed_post_types(), true) ) ? "1" : "0";' ] );
		$group    = $this->ddev_wp( [ 'eval', 'echo function_exists("acf_get_field_groups") && in_array("group_aafm_regression", array_map(function($g){return $g["key"];}, (array) acf_get_field_groups()), true) ? "1" : "0";' ] );
		$ok       = ( null !== $allow_tm && '1' === trim( $allow_tm ) )
			&& ( null !== $allow_pm && '1' === trim( $allow_pm ) )
			&& ( null !== $cpt && '1' === trim( $cpt ) );
		if ( ! $ok ) {
			$this->remove_fixture_plugin();
			return false;
		}
		$this->fixture_plugin_installed = true;
		return true;
	}

	/** Remove the fixtures mu-plugin if present. Best-effort; idempotent. No-op in remote mode (the
	 * bridge is never used there, so nothing was dropped on the local site to remove). */
	private function remove_fixture_plugin(): void {
		if ( ! $this->cli_targets_endpoint() ) {
			$this->fixture_plugin_installed = false;
			return;
		}
		$path = $this->fixture_plugin_path();
		$cmd  = 'ddev exec ' . escapeshellarg( 'rm -f ' . escapeshellarg( $path ) ) . ' 2>/dev/null';
		shell_exec( $cmd );
		$this->fixture_plugin_installed = false;
	}

	/**
	 * Resolve a reassign target for delete-user over the MCP API itself (transport-agnostic),
	 * so it works in EVERY auth mode — Application Password (--user) AND --bearer — and against
	 * remote sites, neither of which the WP-CLI bridge can serve. Calls get-users and returns the
	 * id of the first existing user that is NOT the throwaway fixture being deleted ($exclude_id),
	 * preferring an administrator when the role is visible in the response (any valid target is
	 * harmless — a freshly created throwaway user owns no content). Cached after the first lookup.
	 *
	 * Returns 0 when no other user exists (which never happens in practice — the agent account
	 * itself is always present); callers degrade to a SKIP rather than create an uncleanable user.
	 *
	 * @param int $exclude_id A user id to never return (the fixture about to be deleted).
	 */
	private function reassign_target_user( int $exclude_id = 0 ): int {
		if ( null === $this->reassign_user_id ) {
			$this->reassign_user_id = 0;
			$first_other            = 0;
			foreach ( ( $this->call_data( 'get-users', [ 'per_page' => 50 ] )['users'] ?? [] ) as $user ) {
				$id = (int) ( $user['id'] ?? 0 );
				if ( $id <= 0 || $id === $exclude_id ) {
					continue;
				}
				if ( 0 === $first_other ) {
					$first_other = $id;
				}
				// Prefer an administrator when the role is exposed; otherwise the first other id is fine.
				if ( in_array( 'administrator', (array) ( $user['roles'] ?? [] ), true ) ) {
					$this->reassign_user_id = $id;
					break;
				}
			}
			if ( 0 === $this->reassign_user_id ) {
				$this->reassign_user_id = $first_other;
			}
		}
		// Never reassign a user's content to itself, even if the cached pick happens to be excluded.
		return $this->reassign_user_id === $exclude_id ? 0 : $this->reassign_user_id;
	}

	/* ---------------------------------------------------------------------
	 * WP-CLI bridge (host-side, via DDEV) for option snapshot/restore.
	 * Used only to configure the user-meta allowlist for the write-path test;
	 * every value is snapshotted and restored exactly.
	 * ------------------------------------------------------------------- */

	/**
	 * Host-side sweep for marked reusable blocks (wp_block), including ALREADY-TRASHED ones the
	 * list-blocks ability no longer returns. delete-block only trashes, so a leaked marked block can
	 * sit in the Trash forever; this WP-CLI pass finds every wp_block whose title carries the fixture
	 * prefix across all statuses and force-deletes it, guaranteeing zero leftover block fixtures.
	 *
	 * @return int Count of blocks force-deleted.
	 */
	private function purge_orphan_blocks_cli(): int {
		$out = $this->ddev_wp( [ 'post', 'list', '--post_type=wp_block', '--post_status=any', '--s=' . self::FIXTURE_PREFIX, '--field=ID', '--format=ids' ] );
		if ( null === $out || '' === trim( $out ) ) {
			return 0;
		}
		$purged = 0;
		foreach ( preg_split( '/\s+/', trim( $out ) ) as $id ) {
			if ( ! ctype_digit( $id ) ) {
				continue;
			}
			// Re-confirm the title carries the marker before deleting — never touch a block we did not create.
			$title = $this->ddev_wp( [ 'post', 'get', $id, '--field=post_title' ] );
			if ( null === $title || false === strpos( $title, self::FIXTURE_PREFIX ) ) {
				continue;
			}
			$this->ddev_wp( [ 'post', 'delete', $id, '--force' ] );
			$this->line( "purged trashed block #{$id}" );
			++$purged;
		}
		return $purged;
	}

	/**
	 * Does the local DDEV WP-CLI bridge actually target the --url endpoint? Cached after the first
	 * probe. This is the single gate for every `ddev`/`ddev wp`/`ddev exec` usage: true = "local mode"
	 * (the bridge talks to the same site the harness is testing, so fixture setup/cleanup through it is
	 * safe and meaningful); false = "remote mode" (the bridge would hit the local DDEV site, NOT the
	 * --url target — so we must never touch it, both to avoid mutating the local site and because the
	 * fixtures would land on the wrong site).
	 *
	 * Detection: --no-cli/--remote forces false. Otherwise run `ddev wp option get home` once; if
	 * `ddev` is missing or errors, false. Compare the host of that URL to the host of the harness's
	 * --url endpoint — true only when they match.
	 */
	private function cli_targets_endpoint(): bool {
		if ( null !== $this->cli_targets_endpoint ) {
			return $this->cli_targets_endpoint;
		}
		// Explicit override: never use the bridge.
		if ( isset( $this->opts['no-cli'] ) || isset( $this->opts['remote'] ) ) {
			return $this->cli_targets_endpoint = false;
		}

		$endpoint_host = $this->host_of( $this->endpoint );
		if ( '' === $endpoint_host ) {
			return $this->cli_targets_endpoint = false;
		}

		// Probe the DDEV site's own URL. shell_exec returns null when `ddev` is unavailable or the
		// command fails; either way the bridge is unusable for this target. WP-CLI can emit PHP
		// notices ahead of the value, so scan EVERY output line for a URL whose host matches — never
		// assume the value is the whole (possibly multi-line) blob.
		$home    = shell_exec( 'ddev wp option get home 2>/dev/null' );
		$siteurl = shell_exec( 'ddev wp option get siteurl 2>/dev/null' );
		foreach ( [ $home, $siteurl ] as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}
			foreach ( preg_split( '/\r?\n/', $candidate ) as $line ) {
				$host = $this->host_of( trim( $line ) );
				if ( '' !== $host && $host === $endpoint_host ) {
					return $this->cli_targets_endpoint = true;
				}
			}
		}
		return $this->cli_targets_endpoint = false;
	}

	/** Lowercased host portion of a URL (no port), or '' when it cannot be parsed. */
	private function host_of( string $url ): string {
		$host = (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' );
		return strtolower( $host );
	}

	/**
	 * Run `ddev wp <args>` and return trimmed STDOUT, or null on failure. Quiet on stderr. Returns null
	 * immediately in remote mode so no caller can reach the local DDEV site against a remote --url.
	 *
	 * @param list<string> $args WP-CLI arguments.
	 */
	private function ddev_wp( array $args ): ?string {
		if ( ! $this->cli_targets_endpoint() ) {
			return null;
		}
		$cmd = 'ddev wp ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>/dev/null';
		$out = shell_exec( $cmd );
		if ( null === $out ) {
			return null;
		}
		return $out;
	}

	/**
	 * Snapshot an option as a portable token: null when the option does not exist, otherwise
	 * its JSON encoding. Restored verbatim by cli_restore_option().
	 *
	 * @return array{exists:bool,json:string}|null
	 */
	private function cli_get_option( string $name ): ?array {
		if ( ! $this->cli_targets_endpoint() ) {
			return null;
		}
		$exists = $this->ddev_wp( [ 'option', 'get', $name, '--format=json' ] );
		if ( null === $exists || '' === trim( $exists ) ) {
			// Distinguish "absent" from "empty/error": option list lookup.
			return [ 'exists' => false, 'json' => '' ];
		}
		return [ 'exists' => true, 'json' => trim( $exists ) ];
	}

	/**
	 * Set an option to a JSON array value. Returns true on success.
	 *
	 * @param list<string> $value Array of string keys.
	 */
	private function cli_set_option_array( string $name, array $value ): bool {
		if ( ! $this->cli_targets_endpoint() ) {
			return false;
		}
		$json = (string) json_encode( array_values( $value ) );
		$cmd  = 'ddev wp option update ' . escapeshellarg( $name ) . ' ' . escapeshellarg( $json ) . ' --format=json 2>/dev/null';
		$out  = shell_exec( $cmd );
		if ( null === $out ) {
			return false;
		}
		// Verify the write actually took.
		$check = $this->cli_get_option( $name );
		return null !== $check && $check['exists'] && $check['json'] === $json;
	}

	/**
	 * Restore an option to the exact state captured by cli_get_option(): re-set the prior JSON,
	 * or delete it again when it did not exist before. Returns true on success.
	 *
	 * @param array{exists:bool,json:string}|null $snapshot Prior state.
	 */
	private function cli_restore_option( string $name, ?array $snapshot ): bool {
		if ( ! $this->cli_targets_endpoint() ) {
			return false;
		}
		if ( null === $snapshot || ! $snapshot['exists'] ) {
			$this->ddev_wp( [ 'option', 'delete', $name ] );
			$after = $this->cli_get_option( $name );
			return null !== $after && ! $after['exists'];
		}
		$cmd = 'ddev wp option update ' . escapeshellarg( $name ) . ' ' . escapeshellarg( $snapshot['json'] ) . ' --format=json 2>/dev/null';
		shell_exec( $cmd );
		$after = $this->cli_get_option( $name );
		return null !== $after && $after['exists'] && $after['json'] === $snapshot['json'];
	}

	/* ---------------------------------------------------------------------
	 * Pending-restore registry (reversed by cleanup() on shutdown / fatal())
	 * ------------------------------------------------------------------- */

	/**
	 * Snapshot an option and register it for restore BEFORE it is mutated. Returns the snapshot so the
	 * caller can still assert an inline restore. If a fatal() exits before the caller's inline restore
	 * runs, cleanup() restores the option from this registry.
	 *
	 * @return array{exists:bool,json:string}|null
	 */
	private function snapshot_option_for_restore( string $name ): ?array {
		$snapshot                              = $this->cli_get_option( $name );
		$this->pending_option_restores[ $name ] = $snapshot;
		return $snapshot;
	}

	/**
	 * Register a reversal closure for a non-option mutation (e.g. a tagline flip or template edit)
	 * BEFORE it is applied. cleanup() invokes any closure still pending when it runs.
	 *
	 * @param callable():void $undo
	 */
	private function register_restore( string $label, callable $undo ): void {
		$this->pending_restores[ $label ] = $undo;
	}

	/** Drop a registry entry once its inline restore has verifiably succeeded (idempotent). */
	private function clear_pending_restore( string $label ): void {
		unset( $this->pending_option_restores[ $label ], $this->pending_restores[ $label ] );
	}

	/**
	 * Restore every still-pending option and run every still-pending reversal closure. Called by
	 * cleanup() so that a mid-test fatal() (which exits before inline restores run) still leaves the
	 * live site exactly as it was found. Entries that were already restored inline are de-registered,
	 * so this is a no-op on the happy path.
	 */
	private function flush_pending_restores(): void {
		foreach ( $this->pending_option_restores as $name => $snapshot ) {
			// Guard each restore independently: these run over the WP-CLI bridge (transport-independent,
			// so they still work when the HTTP endpoint is down), but one failure must never stop the
			// rest of the option restores or the pending reversals below.
			try {
				$this->cli_restore_option( $name, $snapshot );
			} catch ( \Throwable $e ) {
				$this->line( "option restore '{$name}' failed: " . $e->getMessage() );
			}
			unset( $this->pending_option_restores[ $name ] );
		}
		foreach ( $this->pending_restores as $label => $undo ) {
			try {
				$undo();
			} catch ( \Throwable $e ) {
				// Best-effort: a failed reversal must never abort the rest of cleanup.
				$this->line( "pending restore '{$label}' failed: " . $e->getMessage() );
			}
			unset( $this->pending_restores[ $label ] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Call helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Call a tool. Returns ['isError'=>bool, 'data'=>mixed (structuredContent), 'message'=>string].
	 *
	 * @param string              $short Tool short name (resolved to the exposed prefix).
	 * @param array<string,mixed> $args  Arguments.
	 * @return array{isError:bool,data:mixed,message:string,raw:mixed}
	 */
	private function call( string $short, array $args ): array {
		$name = $this->resolve_tool( $short );
		$body = $this->rpc( 'tools/call', [ 'name' => $name, 'arguments' => empty( $args ) ? (object) [] : $args ] );

		if ( isset( $body['error'] ) ) {
			return [ 'isError' => true, 'data' => null, 'message' => (string) ( $body['error']['message'] ?? 'rpc error' ), 'raw' => $body ];
		}
		$result   = $body['result'] ?? [];
		$is_error = (bool) ( $result['isError'] ?? false );
		$data     = $result['structuredContent'] ?? null;
		$message  = '';
		if ( isset( $result['content'][0]['text'] ) ) {
			$message = (string) $result['content'][0]['text'];
			if ( null === $data && ! $is_error ) {
				$decoded = json_decode( $message, true );
				if ( null !== $decoded ) {
					$data = $decoded;
				}
			}
		}
		return [ 'isError' => $is_error, 'data' => $data, 'message' => $message, 'raw' => $result ];
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed> structuredContent (or [] on error).
	 */
	private function call_data( string $short, array $args ): array {
		$r = $this->call( $short, $args );
		return is_array( $r['data'] ) ? $r['data'] : [];
	}

	/**
	 * Single-post tools wrap their result as {post:{...}}. Unwrap to the post fields.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	private function call_post( string $short, array $args ): array {
		return $this->post_of( $this->call_data( $short, $args ) );
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	private function post_of( array $data ): array {
		return isset( $data['post'] ) && is_array( $data['post'] ) ? $data['post'] : $data;
	}

	/**
	 * Call a single-object write/read and unwrap its named envelope. Single-object tools wrap
	 * their result as {comment:{...}}, {user:{...}}, etc.; this returns the inner object (or the
	 * raw data when the key is absent), mirroring call_post() for arbitrary keys.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	private function call_unwrap( string $short, array $args, string $key ): array {
		$data = $this->call_data( $short, $args );
		return isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : $data;
	}

	/** Best-effort call used during cleanup; never records a result. */
	private function call_quiet( string $short, array $args ): void {
		try {
			$this->call( $short, $args );
		} catch ( \Throwable $e ) {
			// Ignore — cleanup is best-effort.
		}
	}

	private function resolve_tool( string $short ): string {
		foreach ( $this->tool_names as $n ) {
			if ( $n === $short || str_ends_with( $n, $short ) ) {
				return $n;
			}
		}
		return $short;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed> Decoded JSON-RPC envelope.
	 */
	private function rpc( string $method, $params ) {
		[ $body ] = $this->http_post( [
			'jsonrpc' => '2.0',
			'id'      => ++$this->rpc_id,
			'method'  => $method,
			'params'  => $params,
		] );
		return $body;
	}

	/* ---------------------------------------------------------------------
	 * Transport
	 * ------------------------------------------------------------------- */

	/**
	 * POST a JSON-RPC payload. Returns [decoded_body, lowercased_headers].
	 *
	 * @param array<string,mixed> $payload
	 * @param bool                $notification When true, no response body is expected.
	 * @return array{0:array<string,mixed>,1:array<string,string>}
	 */
	private function http_post( array $payload, bool $notification = false ): array {
		$headers = [
			'Content-Type: application/json',
			'Accept: application/json, text/event-stream',
			$this->auth_header,
		];
		if ( $this->session_id ) {
			$headers[] = 'Mcp-Session-Id: ' . $this->session_id;
			$headers[] = 'Mcp-Protocol-Version: ' . $this->protocol_version;
		}

		$json = (string) json_encode( $payload );
		if ( isset( $this->opts['verbose'] ) ) {
			$this->line( ">> {$json}" );
		}

		$ch = curl_init( $this->endpoint );
		curl_setopt_array( $ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $json,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_TIMEOUT        => 30,
			// Do NOT auto-follow redirects: --url is arbitrary and carries live credentials
			// (Application Password / bearer), and following a 30x to another host would leak the
			// Authorization header. The MCP endpoint is a fixed POST route that never legitimately
			// redirects, so a 3xx is surfaced below instead of followed. UNRESTRICTED_AUTH=false is
			// belt-and-suspenders in case a redirect is ever followed by other means.
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_UNRESTRICTED_AUTH => false,
		] );
		if ( isset( $this->opts['insecure'] ) ) {
			// Local DDEV serves a self-signed cert; --insecure skips peer verification.
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
		}
		$raw = curl_exec( $ch );
		if ( false === $raw ) {
			$err = curl_error( $ch );
			curl_close( $ch );
			$this->fatal( "HTTP error: {$err}" );
		}
		$status      = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		curl_close( $ch );

		$raw_headers = substr( (string) $raw, 0, $header_size );
		$raw_body    = substr( (string) $raw, $header_size );
		$head        = $this->parse_headers( $raw_headers );

		if ( isset( $this->opts['verbose'] ) ) {
			$this->line( "<< [{$status}] " . trim( $raw_body ) );
		}

		// A 3xx means the endpoint tried to redirect us. We deliberately do not follow it (see the
		// CURLOPT_FOLLOWLOCATION note above) because that would re-send the credentialed Authorization
		// header to the redirect target. Surface the Location instead of silently chasing it.
		if ( $status >= 300 && $status < 400 ) {
			$location = $head['location'] ?? '(no Location header)';
			$this->fatal( "Endpoint redirected ({$status}) to {$location}; re-run with the final URL." );
		}

		if ( $notification ) {
			return [ [], $head ];
		}
		if ( $status >= 400 ) {
			// Surface auth/permission failures clearly instead of a JSON parse error.
			$snippet = trim( substr( $raw_body, 0, 400 ) );
			$this->fatal( "HTTP {$status} from endpoint: {$snippet}" );
		}

		$decoded = $this->decode_body( $raw_body );
		return [ is_array( $decoded ) ? $decoded : [], $head ];
	}

	/** Accepts plain JSON or an SSE-framed (data:) body. */
	private function decode_body( string $body ) {
		$body = trim( $body );
		if ( '' === $body ) {
			return [];
		}
		if ( '{' !== $body[0] && '[' !== $body[0] && false !== strpos( $body, 'data:' ) ) {
			foreach ( preg_split( '/\r?\n/', $body ) as $ln ) {
				if ( 0 === strpos( $ln, 'data:' ) ) {
					$candidate = trim( substr( $ln, 5 ) );
					$decoded   = json_decode( $candidate, true );
					if ( null !== $decoded ) {
						return $decoded;
					}
				}
			}
		}
		return json_decode( $body, true );
	}

	/** @return array<string,string> Lowercased header name => value (last wins). */
	private function parse_headers( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
			$pos = strpos( $line, ':' );
			if ( false !== $pos ) {
				$out[ strtolower( trim( substr( $line, 0, $pos ) ) ) ] = trim( substr( $line, $pos + 1 ) );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Reporting + small utilities
	 * ------------------------------------------------------------------- */

	private function record( string $section, string $label, ?string $status, string $detail ): void {
		$status = $status ?? 'FAIL';
		$this->results[] = compact( 'section', 'label', 'status', 'detail' );
		$tag = $this->colorize( str_pad( $status, 4 ), $status );
		$this->line( sprintf( '  [%s] %s%s', $tag, $label, '' !== $detail ? "  ({$detail})" : '' ) );
	}

	/**
	 * Record a default-deny governance probe (an unlisted meta key MUST be refused).
	 *
	 * A refused write is always a PASS — governance honored. An ACCEPTED write only proves a bug
	 * when we know the target is default-deny, which holds in LOCAL mode with no override key (the
	 * fixtures bridge controls the allowlist there). When access is opened — an override key was
	 * supplied, or the target is remote and may run allow-all (`*`) — an accepted write is the
	 * operator's own configuration, not a regression, so it is a SKIP rather than a FAIL.
	 */
	private function record_default_deny_probe( string $section, string $label, bool $refused, bool $override ): void {
		if ( $refused ) {
			$this->record( $section, $label, 'PASS', 'correctly refused' );
			return;
		}
		if ( $override || ! $this->cli_targets_endpoint() ) {
			$this->record( $section, $label, 'SKIP', 'allowlist is opened on this target (allow-all or operator-configured); default-deny not assertable' );
			return;
		}
		$this->record( $section, $label, 'FAIL', 'UNEXPECTEDLY accepted an unlisted key' );
	}

	/**
	 * Whether two template bodies are equivalent ignoring insignificant whitespace.
	 *
	 * update-template runs saved content through wp_kses_post(), which can reflow newlines and
	 * attribute spacing, so a byte-for-byte compare flags a semantically correct restore as a
	 * mismatch. Collapsing whitespace runs verifies the substantive markup matches without that
	 * false negative.
	 */
	private function templates_equivalent( string $a, string $b ): bool {
		$norm = static fn( string $s ): string => trim( (string) preg_replace( '/\s+/', ' ', $s ) );
		return $norm( $a ) === $norm( $b );
	}

	private function summary(): int {
		$counts  = [ 'PASS' => 0, 'FAIL' => 0, 'SKIP' => 0 ];
		$section = '';
		foreach ( $this->results as $r ) {
			$counts[ $r['status'] ] = ( $counts[ $r['status'] ] ?? 0 ) + 1;
		}
		$this->line( '' );
		$this->line( str_repeat( '-', 60 ) );
		$this->line( sprintf(
			'%s   %s   %s',
			$this->colorize( "PASS {$counts['PASS']}", 'PASS' ),
			$this->colorize( "FAIL {$counts['FAIL']}", $counts['FAIL'] ? 'FAIL' : 'SKIP' ),
			$this->colorize( "SKIP {$counts['SKIP']}", 'SKIP' )
		) );
		if ( $counts['FAIL'] ) {
			$this->line( '' );
			$this->line( 'Failures:' );
			foreach ( $this->results as $r ) {
				if ( 'FAIL' === $r['status'] ) {
					$this->line( "  - [{$r['section']}] {$r['label']}" . ( '' !== $r['detail'] ? " ({$r['detail']})" : '' ) );
				}
			}
		}
		$this->warn_remote_trashed_blocks();
		$this->warn_remote_orphan_terms();
		return $counts['FAIL'];
	}

	/**
	 * Print a prominent warning when the --remote-blocks path left one or more wp_block posts in the
	 * remote's Trash. delete-block only trashes and there is no MCP force-delete for wp_block on a remote
	 * target, so these cannot be removed over the wire — the operator must empty the Trash (or run
	 * `wp post delete <id> --force`) to finish the cleanup. Never let a trashed block pass silently.
	 */
	private function warn_remote_trashed_blocks(): void {
		if ( empty( $this->remote_trashed_blocks ) ) {
			return;
		}
		$ids = implode( ', ', $this->remote_trashed_blocks );
		$this->line( '' );
		$this->line( $this->colorize( str_repeat( '!', 60 ), 'SKIP' ) );
		$this->line( $this->colorize( 'WARNING: ' . count( $this->remote_trashed_blocks ) . ' trashed reusable block(s) remain on the remote.', 'SKIP' ) );
		$this->line( $this->colorize( "  wp_block id(s): {$ids}", 'SKIP' ) );
		$this->line( $this->colorize( '  delete-block only moves to Trash and there is no MCP force-delete for wp_block,', 'SKIP' ) );
		$this->line( $this->colorize( '  so the harness cannot remove these over the wire. Empty the Trash on the remote', 'SKIP' ) );
		$this->line( $this->colorize( '  (or run `wp post delete <id> --force`) to finish cleanup.', 'SKIP' ) );
		$this->line( $this->colorize( str_repeat( '!', 60 ), 'SKIP' ) );
	}

	/**
	 * Print a prominent warning when the --remote-terms path created one or more category terms on the
	 * remote that cannot be removed over the wire. There is no delete-term MCP tool, and the local
	 * WP-CLI bridge can't reach a remote target, so the operator must delete these by hand (or run
	 * `wp term delete category <id>`). Never let an orphan term pass silently.
	 */
	private function warn_remote_orphan_terms(): void {
		if ( empty( $this->remote_orphan_terms ) ) {
			return;
		}
		$ids = implode( ', ', $this->remote_orphan_terms );
		$this->line( '' );
		$this->line( $this->colorize( str_repeat( '!', 60 ), 'SKIP' ) );
		$this->line( $this->colorize( 'WARNING: ' . count( $this->remote_orphan_terms ) . ' category term(s) created by --remote-terms remain on the remote.', 'SKIP' ) );
		$this->line( $this->colorize( "  category term id(s): {$ids}", 'SKIP' ) );
		$this->line( $this->colorize( '  There is no delete-term MCP ability, so the harness cannot remove these over the', 'SKIP' ) );
		$this->line( $this->colorize( '  wire. Delete them on the remote (or run `wp term delete category <id>`) to finish', 'SKIP' ) );
		$this->line( $this->colorize( '  cleanup.', 'SKIP' ) );
		$this->line( $this->colorize( str_repeat( '!', 60 ), 'SKIP' ) );
	}

	/** Pull an integer count for a status out of count-posts' varied shapes. */
	private function find_count( array $data, string $status ): ?int {
		if ( isset( $data[ $status ] ) && is_numeric( $data[ $status ] ) ) {
			return (int) $data[ $status ];
		}
		foreach ( [ 'counts', 'by_status', 'statuses' ] as $wrap ) {
			if ( isset( $data[ $wrap ][ $status ] ) && is_numeric( $data[ $wrap ][ $status ] ) ) {
				return (int) $data[ $wrap ][ $status ];
			}
		}
		return null;
	}

	/** Reduce a possibly-{rendered:...} field to a plain string. */
	private function scalar( $v ): string {
		if ( is_array( $v ) ) {
			return (string) ( $v['rendered'] ?? $v['raw'] ?? reset( $v ) );
		}
		return (string) $v;
	}

	private function colorize( string $text, string $status ): string {
		if ( ! $this->use_color ) {
			return $text;
		}
		$code = [ 'PASS' => '0;32', 'FAIL' => '0;31', 'SKIP' => '0;33' ][ $status ] ?? '0';
		return "\033[{$code}m{$text}\033[0m";
	}

	private function line( string $s ): void {
		fwrite( STDOUT, $s . "\n" );
	}

	private function fatal( string $s ): void {
		// Throw rather than exit(): an exit() during the shutdown cleanup() path is uncatchable and
		// would skip every remaining fixture deletion + option restore. As a throwable, call_quiet()
		// and the per-step guards in cleanup()/flush_pending_restores() swallow it so teardown runs to
		// the end; the top-level bootstrap catches it for the normal run and exits non-zero (code 2).
		throw new AAFM_Fatal( $s );
	}

	// Late-bound scratch state.
	/** @var list<string> */
	private array $tool_names = [];
	private ?int $baseline_publish = null;
	private ?int $primary_post_id  = null;
	private ?int $primary_term_id  = null;
	private ?int $reassign_user_id = null;

	/**
	 * Reusable-block ids the --remote-blocks path moved to the trash and could NOT permanently remove
	 * (delete-block only trashes; there is no MCP force-delete for wp_block on a remote target). Warned
	 * about prominently at the end of the run so a trashed block is never left silently.
	 *
	 * @var list<int>
	 */
	private array $remote_trashed_blocks = [];

	/**
	 * Category term ids the --remote-terms path created over MCP and could NOT remove (there is no
	 * delete-term MCP tool, and the local WP-CLI bridge can't reach a remote target). Warned about
	 * prominently at the end of the run so an orphan term is never left silently.
	 *
	 * @var list<int>
	 */
	private array $remote_orphan_terms = [];
}

/* -------------------------------------------------------------------------
 * Bootstrap
 * ----------------------------------------------------------------------- */

/**
 * @return array<string,mixed>
 */
function aafm_parse_argv( array $argv ): array {
	$opts = [];
	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( 0 !== strpos( $arg, '--' ) ) {
			continue;
		}
		$arg = substr( $arg, 2 );
		if ( false !== strpos( $arg, '=' ) ) {
			[ $k, $v ]  = explode( '=', $arg, 2 );
			$opts[ $k ] = $v;
		} else {
			$opts[ $arg ] = true;
		}
	}
	// Environment fallbacks.
	$opts['url']    = $opts['url']    ?? ( getenv( 'AAFM_MCP_URL' )    ?: null );
	$opts['user']   = $opts['user']   ?? ( getenv( 'AAFM_MCP_USER' )   ?: null );
	$opts['pass']   = $opts['pass']   ?? ( getenv( 'AAFM_MCP_PASS' )   ?: null );
	$opts['bearer'] = $opts['bearer'] ?? ( getenv( 'AAFM_MCP_BEARER' ) ?: null );
	return $opts;
}

$opts = aafm_parse_argv( $argv );
if ( isset( $opts['help'] ) ) {
	fwrite( STDOUT, "See the header of this file for usage.\n" );
	exit( 0 );
}

// Minimal json helper used in one error message above.
if ( ! function_exists( 'wp_json_safe' ) ) {
	function wp_json_safe( $v ): string {
		return (string) json_encode( $v );
	}
}

$runner = new AAFM_Mcp_Regression( $opts );
try {
	$code = $runner->run();
} catch ( AAFM_Fatal $e ) {
	// Preserve today's behavior: a fatal during the normal run prints FATAL: ... and exits non-zero.
	// The registered shutdown cleanup() still runs after this exit, restoring any mutated state.
	fwrite( STDERR, 'FATAL: ' . $e->getMessage() . "\n" );
	$code = 2;
}
exit( $code );
