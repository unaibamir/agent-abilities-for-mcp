#!/usr/bin/env bash
# Acceptance for the Site Ops redirect read tools, on a disposable site only.
#
#   bash tests/acceptance/siteops/redirect.sh <slug>     one adapter: eps_301 | redirection |
#                                                        safe_redirect_manager | rankmath | slim_seo
#   bash tests/acceptance/siteops/redirect.sh multi      two and five plugins at once: case X1 and
#                                                        the evaluation order
#
# Run it from the host, at the repository root. It takes a DDEV snapshot first, then runs itself
# inside the web container, where it builds the disposable site with setup.sh, runs the cases and
# removes the site with teardown.sh. It never touches database "db" or /var/www/html. Rules are
# made with each vendor's own functions, never through our tools. Every case prints PASS, FAIL or
# SKIP. The exit code is 1 when any case failed.

set -u
SELF="tests/acceptance/siteops/redirect.sh"
MODE="${1:-}"
case "$MODE" in
	eps_301|redirection|safe_redirect_manager|rankmath|slim_seo|multi) ;;
	*) echo "usage: bash $SELF eps_301|redirection|safe_redirect_manager|rankmath|slim_seo|multi"; exit 2 ;;
esac

# --- Host side: snapshot, then run inside the container ----------------------
if [ "${IS_DDEV_PROJECT:-}" != true ]; then
	command -v ddev >/dev/null 2>&1 || { echo "refusing: not inside DDEV and no ddev command"; exit 2; }
	[ -f agent-abilities-for-mcp.php ] || { echo "refusing: run from the repository root"; exit 2; }
	SNAP="pre-siteops-accept-$(date +%Y%m%d)"
	if ddev snapshot --list 2>/dev/null | grep -q "$SNAP"; then
		echo "snapshot $SNAP already exists"
	else
		ddev snapshot --name "$SNAP" || { echo "refusing: the snapshot failed"; exit 2; }
	fi
	exec ddev exec "AAFM_ACCEPT_SNAPSHOT=$SNAP bash /var/www/plugin-src/$SELF $MODE"
fi

# --- Container side -----------------------------------------------------------
[ -n "${AAFM_ACCEPT_SNAPSHOT:-}" ] || { echo "refusing: no snapshot was taken; run this script from the host"; exit 2; }
DIR=/var/www/plugin-src/tests/acceptance/siteops
ROOT=/tmp/aafm_accept_siteops
DB=aafm_accept_siteops
SUBDB=aafm_accept_siteops_sub
ZIPS=/tmp/aafm_accept_plugins
OUT="$ROOT/redirect-accept"
OURS=agent-abilities-for-mcp
ROUTE='agent-abilities-for-mcp/mcp'
INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"redirect-accept","version":"1"}}}'
TOOLS='["aafm/site-list-redirects","aafm/site-get-redirect","aafm/site-list-404s","aafm/site-test-redirect"]'
ALL="eps_301 redirection safe_redirect_manager rankmath slim_seo"
declare -A PLUGIN=( [eps_301]=eps-301-redirects [redirection]=redirection [safe_redirect_manager]=safe-redirect-manager [rankmath]=seo-by-rank-math [slim_seo]=slim-seo )
# The versions each adapter was accepted on (2026-11-20). A newer release is a separate, reported check.
declare -A PLUGIN_VERSION=( [eps_301]=2.85 [redirection]=5.10.1 [safe_redirect_manager]=2.3.0 [rankmath]=1.0.282 [slim_seo]=4.11.6 )
declare -A HAS404=( [eps_301]=1 [redirection]=1 [safe_redirect_manager]=0 [rankmath]=1 [slim_seo]=1 )
declare -A HASTIME=( [eps_301]=1 [redirection]=0 [safe_redirect_manager]=0 [rankmath]=1 [slim_seo]=1 )
declare -A RELATIVE=( [eps_301]=host_root [redirection]=host_root [safe_redirect_manager]=host_root [rankmath]=home [slim_seo]=home )
# What real requests gave on the subfolder bench (bench4 N1), for /sub/old-a, /sub/old-b and
# /sub/sub/old-b. Printed as a note when this run differs; the case itself compares tool and curl.
declare -A BENCH_SUB=( [redirection]="404 301 404" [rankmath]="301 404 404" [safe_redirect_manager]="301 404 301" [eps_301]="301 404 301" [slim_seo]="301 404 301" )

PASSES=0; FAILS=0; SKIPS=0
pass() { PASSES=$((PASSES + 1)); echo "PASS $1: $2"; }
fail() { FAILS=$((FAILS + 1)); echo "FAIL $1: $2"; }
skip() { SKIPS=$((SKIPS + 1)); echo "SKIP $1: $2"; }
die()  { echo "STOP: $1"; exit 1; }

# WPROOT, URL, HOME_PATH and APP_PW describe the site in use; use_site switches between the two.
W() { wp --allow-root --path="$WPROOT" "$@"; }
use_site() {
	if [ "$1" = sub ]; then WPROOT="$ROOT/sub"; else WPROOT="$ROOT"; fi
	URL=$(W option get home 2>/dev/null)
	HOME_PATH=$(echo "$URL" | sed -E 's#^https?://[^/]+##; s#/$##')
	ORIGIN=${URL%"$HOME_PATH"}
	APP_PW=$(cat "$WPROOT/redirect-accept-pw.txt" 2>/dev/null)
}

teardown_all() {
	mysql -hdb -uroot -proot -e "DROP DATABASE IF EXISTS \`$SUBDB\`;" 2>/dev/null
	rm -rf "$ROOT/sub"
	bash "$DIR/teardown.sh" >/dev/null 2>&1
}

# mcp_post <json body> [session id]: prints the HTTP code; body in $OUT/body.json, headers in $OUT/head.txt.
mcp_post() {
	local sid=()
	[ -n "${2:-}" ] && sid=(-H "Mcp-Session-Id: $2" -H 'MCP-Protocol-Version: 2025-06-18')
	curl -s --max-time 60 -D "$OUT/head.txt" -o "$OUT/body.json" -w '%{http_code}' -u "admin:$APP_PW" \
		-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
		${sid[@]+"${sid[@]}"} -X POST "$URL/index.php?rest_route=/$ROUTE" -d "$1"
}

# call <ability> <args json>: one tools/call. The JSON-RPC answer is left in $OUT/body.json.
call() {
	local code sid tool
	tool=$(echo "$1" | tr '/' '-')
	code=$(mcp_post "$INIT")
	[ "$code" = 200 ] || { echo "initialize answered HTTP $code" > "$OUT/body.json"; return 1; }
	sid=$(grep -i '^mcp-session-id:' "$OUT/head.txt" | tr -d '\r' | awk '{print $2}')
	code=$(mcp_post "$(jq -nc --arg n "$tool" --argjson a "$2" '{jsonrpc:"2.0",id:3,method:"tools/call",params:{name:$n,arguments:$a}}')" "$sid")
	[ "$code" = 200 ]
}
ran()     { jq -e '(.result | type) == "object" and .result.isError != true' "$OUT/body.json" >/dev/null 2>&1; }
refused() { jq -e '(.error | type) == "object" or .result.isError == true' "$OUT/body.json" >/dev/null 2>&1; }
sc()      { jq -r ".result.structuredContent$1" "$OUT/body.json" 2>/dev/null; }
errtext() { jq -r '[.error.message?, (.result.content // [])[]?.text?] | map(select(type == "string")) | join(" ")' "$OUT/body.json" 2>/dev/null; }

# hit <full path>: "<status> <path of the Location header, or empty>" for a real request. The path
# includes the home path, as the tool's own paths do.
hit() {
	local r
	r=$(curl -s --max-time 30 -o /dev/null -w '%{http_code} %{redirect_url}' "$ORIGIN$1")
	echo "${r%% *} $(echo "${r#* }" | sed -E 's#^https?://[^/]+##')"
}

# norm_target <slug> <target>: the path a browser would be sent to for a stored target.
norm_target() {
	case "$2" in
		http://*|https://*) echo "$2" | sed -E 's#^https?://[^/]+##' ;;
		*) if [ "${RELATIVE[$1]}" = home ]; then echo "$HOME_PATH/${2#/}"; else echo "/${2#/}"; fi ;;
	esac
}

# same_answer <case> <slug> <path>: the tool's first match for a path equals what a real request gets.
same_answer() {
	local real code loc tcode ttarget state
	real=$(hit "$3"); code=${real%% *}; loc=${real#* }
	if ! call aafm/site-test-redirect "$(jq -nc --arg u "$3" '{url:$u}')" || ! ran; then
		fail "$1" "$2 $3: the tool did not answer: $(errtext | head -c 200)"; return 1
	fi
	state=$(sc ".evaluated[] | select(.provider == \"$2\") | .result")
	case "$code" in
		301|302|303|307|308)
			tcode=$(sc '.first_match.code'); ttarget=$(norm_target "$2" "$(sc '.first_match.target')")
			if [ "$state" = matched ] && [ "$tcode" = "$code" ] && [ "${ttarget%/}" = "${loc%/}" ]; then
				pass "$1" "$2 $3: curl $code $loc, tool $tcode $ttarget"
			else
				fail "$1" "$2 $3: curl $code $loc, tool state=$state code=$tcode target=$ttarget"
			fi ;;
		*)
			if [ "$state" = no_match ] && [ "$(sc '.first_match')" = null ]; then
				pass "$1" "$2 $3: curl $code (not redirected), tool no_match"
			else
				fail "$1" "$2 $3: curl $code (not redirected), tool state=$state first_match=$(sc '.first_match' | tr -d '\n' | head -c 120)"
			fi ;;
	esac
}

# unknown_case <slug> <reason> <path> <real>: the tool answers unknown for <path> with <reason> for
# this plugin; with <real> 1 a real request for <path> must be redirected too, which proves the
# setting does redirect without a rule.
unknown_case() {
	local real
	if ! call aafm/site-test-redirect "$(jq -nc --arg u "$3" '{url:$u}')" || ! ran; then
		fail U1 "$1 $3: the tool did not answer: $(errtext | head -c 200)"; return 1
	fi
	if [ "$(sc ".evaluated[] | select(.provider == \"$1\") | .result + \" \" + (.reason // \"\")")" != "unknown $2" ]; then
		fail U1 "$1 $3: wanted unknown $2, the tool said $(sc '.evaluated' | tr -d '\n' | head -c 200)"; return 1
	fi
	if [ "$4" = 1 ]; then
		real=$(hit "$3")
		case "$real" in
			30[12378]*) pass U1 "$1 $3: a real request is redirected ($real) and the tool answers unknown, $2" ;;
			*) fail U1 "$1 $3: the tool answers unknown, $2, but a real request was not redirected ($real)" ;;
		esac
	else
		pass U1 "$1 $3: the tool answers unknown, $2"
	fi
}

seed() { W eval-file "$ROOT/redirect-seed.php" "$@" 2>>"$OUT/seed.log"; }

write_seed() {
	cat > "$ROOT/redirect-seed.php" <<'PHP'
<?php
// Disposable-site helper for redirect.sh: makes rules with each vendor's own functions and reads
// counters. Usage: wp eval-file redirect-seed.php <action> <slug> [more].
$action = $args[0] ?? '';
$slug   = $args[1] ?? '';

function aafm_acc_rule( string $slug, string $from, string $to, int $code = 301, string $kind = 'exact', bool $enabled = true ) {
	switch ( $slug ) {
		case 'redirection':
			$item = Red_Item::create( array( 'url' => $from, 'action_data' => array( 'url' => $to ), 'match_type' => 'url', 'action_type' => 'url', 'action_code' => $code, 'group_id' => 1, 'regex' => 'regex' === $kind ? 1 : 0 ) );
			if ( is_wp_error( $item ) ) { return ''; }
			if ( ! $enabled ) { $item->disable(); }
			return (string) $item->get_id();
		case 'rankmath':
			$id = \RankMath\Redirections\Redirection::from( array( 'sources' => array( array( 'pattern' => $from, 'comparison' => 'regex' === $kind ? 'regex' : 'exact' ) ), 'url_to' => $to, 'header_code' => $code, 'status' => $enabled ? 'active' : 'inactive' ) )->save();
			return $id ? (string) $id : '';
		case 'safe_redirect_manager':
			$id = srm_create_redirect( $from, $to, $code, 'regex' === $kind, $enabled ? 'publish' : 'draft' );
			return is_int( $id ) ? (string) $id : '';
		case 'eps_301':
			$ids = EPS_Redirects::_save_redirects( array( array( 'url_from' => $from, 'url_to' => $to, 'type' => 'url', 'status' => $enabled ? (string) $code : 'inactive' ) ) );
			return $ids ? (string) $ids[0] : '';
		case 'slim_seo':
			$map = array( 'exact' => 'exact-match', 'regex' => 'regex', 'contain' => 'contain', 'start-with' => 'start-with', 'end-with' => 'end-with' );
			return (string) ( new \SlimSEO\Redirection\Database\Redirects() )->update( array( 'type' => $code, 'condition' => $map[ $kind ] ?? 'exact-match', 'from' => $from, 'to' => $to, 'note' => '', 'enable' => $enabled ? 1 : 0, 'ignoreParameters' => 0 ) );
	}
	return '';
}

switch ( $action ) {
	case 'prepare':
		if ( 'redirection' === $slug && '' === ( Red_Options::get()['database'] ?? '' ) ) {
			\Redirection\Database\Database::get_latest_database()->install();
		}
		if ( 'rankmath' === $slug ) {
			add_option( 'rank_math_registration_skip', true );
			\RankMath\Helper::update_modules( array( 'redirections' => 'on', '404-monitor' => 'on' ) );
			// Its own attachment and date archive redirects are on after install; case U1 turns each on again.
			foreach ( array( 'general' => 'attachment_redirect_urls', 'titles' => 'disable_date_archives' ) as $group => $field ) {
				$o = get_option( 'rank-math-options-' . $group, array() );
				$o = is_array( $o ) ? $o : array();
				$o[ $field ] = 'off';
				update_option( 'rank-math-options-' . $group, $o );
			}
		}
		if ( 'slim_seo' === $slug ) {
			$o = get_option( 'slim_seo', array() );
			$o = is_array( $o ) ? $o : array();
			$o['enable_404_logs'] = 1;
			// Automatic redirects are on by default; case U1 turns them on again.
			$o['auto_redirection'] = 0;
			update_option( 'slim_seo', $o );
			( new \SlimSEO\Redirection\Database\Log404() )->create_table();
		}
		echo 'prepared';
		break;

	case 'main':
		$host = $args[2] ?? '';
		$out  = array(
			'E'        => aafm_acc_rule( $slug, '/acc-old-a', '/acc-page/', 301 ),
			'D'        => aafm_acc_rule( $slug, '/acc-old-off', '/acc-page/', 301, 'exact', false ),
			'userinfo' => aafm_acc_rule( $slug, '/acc-userinfo', 'https://user:secret@' . $host . '/x?key=abc', 301 ),
			'loop_a'   => aafm_acc_rule( $slug, '/acc-loop-a', '/acc-loop-b', 301 ),
			'loop_b'   => aafm_acc_rule( $slug, '/acc-loop-b', '/acc-loop-a', 301 ),
			'patterns' => array(),
		);
		$patterns = array(
			'redirection'           => array( array( 'regex', '^/acc-rx-(\d+)$', '/acc-rx-12' ) ),
			'rankmath'              => array( array( 'regex', 'acc-rx-(\d+)', '/acc-rx-12' ) ),
			'safe_redirect_manager' => array( array( 'regex', '^/acc-rx-(\d+)$', '/acc-rx-12' ), array( 'exact', '/acc-wild/*', '/acc-wild/anything' ) ),
			'slim_seo'              => array( array( 'regex', 'acc-rx-(\d+)', '/acc-rx-12' ), array( 'contain', 'acc-contain', '/x-acc-contain-y' ), array( 'start-with', 'acc-start', '/acc-start-here' ), array( 'end-with', 'acc-end', '/it-is-the-acc-end' ) ),
			'eps_301'               => array(),
		);
		foreach ( $patterns[ $slug ] ?? array() as $p ) {
			$id = aafm_acc_rule( $slug, $p[1], '/acc-page/', 301, $p[0] );
			if ( '' !== $id ) {
				$out['patterns'][] = array( 'id' => $id, 'path' => $p[2] );
			}
		}
		echo wp_json_encode( $out );
		break;

	case 'sub':
		echo wp_json_encode( array( aafm_acc_rule( $slug, '/old-a', '/sub/hello-world/', 301 ), aafm_acc_rule( $slug, '/sub/old-b', '/sub/sample-page/', 301 ) ) );
		break;

	case 'same':
		echo aafm_acc_rule( $slug, '/acc-same', '/t-' . str_replace( '_', '-', $slug ), 301 );
		break;

	case 'srm_extra':
		echo wp_json_encode( array( 'caf' => aafm_acc_rule( 'safe_redirect_manager', '/caf-old', '/acc-page/', 301 ), 'code' => aafm_acc_rule( 'safe_redirect_manager', '/acc-code', '/acc-page/', 301 ) ) );
		break;

	case 'broken':
		echo aafm_acc_rule( $slug, '(unclosed', '/acc-page/', 301, 'regex' );
		break;

	case 'set':
		// set <slug> <setting> <value>: one vendor setting on this disposable site; '-' removes it.
		// Rank Math settings are named group.field, as the plugin's own reader names them.
		$key   = (string) ( $args[2] ?? '' );
		$value = (string) ( $args[3] ?? '-' );
		$field = $key;
		$name  = 'slim_seo' === $slug ? 'slim_seo' : 'redirection_options';
		if ( 'rankmath' === $slug ) {
			list( $group, $field ) = explode( '.', $key, 2 );
			$name = 'rank-math-options-' . $group;
		}
		$o = get_option( $name, array() );
		$o = is_array( $o ) ? $o : array();
		if ( '-' === $value ) {
			unset( $o[ $field ] );
		} else {
			$o[ $field ] = ctype_digit( $value ) ? (int) $value : $value;
		}
		update_option( $name, $o );
		echo 'set';
		break;

	case 'probe':
		// A write made inside a function whose name starts like our adapters', for the W3 logger.
		function aafm_redirect_acc_probe() {
			update_option( 'aafm_acc_probe', (string) microtime( true ) );
		}
		aafm_redirect_acc_probe();
		delete_option( 'aafm_acc_probe' );
		echo 'probed';
		break;

	case 'counters':
		global $wpdb;
		$q = static function ( string $sql ) use ( $wpdb ) {
			$suppress = $wpdb->suppress_errors( true );
			$v        = $wpdb->get_var( $sql );
			$wpdb->suppress_errors( $suppress );
			return null === $v ? 'none' : (string) $v;
		};
		$p = $wpdb->prefix;
		$c = array(
			'redirection'           => array( "SELECT COALESCE(SUM(last_count),0) FROM {$p}redirection_items", "SELECT COUNT(*) FROM {$p}redirection_logs", "SELECT COUNT(*) FROM {$p}redirection_404" ),
			'rankmath'              => array( "SELECT COALESCE(SUM(hits),0) FROM {$p}rank_math_redirections", "SELECT COUNT(*) FROM {$p}rank_math_redirections_cache", "SELECT COALESCE(SUM(times_accessed),0) FROM {$p}rank_math_404_logs" ),
			'eps_301'               => array( "SELECT COALESCE(SUM(count),0) FROM {$p}redirects" ),
			'slim_seo'              => array( "SELECT COALESCE(SUM(hit),0) FROM {$p}slim_seo_404" ),
			'safe_redirect_manager' => array( "SELECT COUNT(*) FROM {$p}options WHERE option_name LIKE '\\_transient\\_\\_srm\\_redirects%'" ),
		);
		$out = array_map( $q, $c[ $slug ] ?? array() );
		if ( 'eps_301' === $slug ) { $out[] = (string) count( (array) get_option( 'eps_redirects_404_log', array() ) ); }
		echo implode( ',', $out );
		break;

	case 'snapshot':
		global $wpdb;
		$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A );
		$names   = array_values( array_filter( array_column( $options, 'option_name' ), static function ( $n ) { return 0 === strpos( $n, '_transient_' ); } ) );
		echo wp_json_encode( array( 'options' => md5( wp_json_encode( $options ) ), 'cron' => md5( wp_json_encode( _get_cron_array() ) ), 'posts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), 'postmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ), 'transients' => $names ) );
		break;
}
PHP
}

# install_plugin <slug>: the free plugin from wordpress.org at its pinned version, downloaded with
# curl so that the site's own HTTP guard never has to be opened.
install_plugin() {
	local name=${PLUGIN[$1]} version=${PLUGIN_VERSION[$1]}
	mkdir -p "$ZIPS"
	[ -s "$ZIPS/$name.$version.zip" ] || curl -fsSL --max-time 120 -o "$ZIPS/$name.$version.zip" "https://downloads.wordpress.org/plugin/$name.$version.zip" || die "could not download $name $version"
	W plugin is-installed "$name" 2>/dev/null || W plugin install "$ZIPS/$name.$version.zip" --quiet >/dev/null 2>&1 || die "could not install $name"
	[ "$(W plugin get "$name" --field=version 2>/dev/null)" = "$version" ] || die "$name is not version $version, the version the adapter was accepted on"
	echo "== $name $version" >&2
	W plugin activate "$name" --quiet >/dev/null 2>&1 || die "could not activate $name"
	seed prepare "$1" >/dev/null
}

# ready_site: what every site in this run needs on top of setup.sh.
ready_site() {
	W config set DISABLE_WP_CRON true --raw --quiet >/dev/null 2>&1
	if ! grep -rqs 'pre_http_request' "$WPROOT/wp-content/mu-plugins/"; then
		mkdir -p "$WPROOT/wp-content/mu-plugins"
		cat > "$WPROOT/wp-content/mu-plugins/aafm-accept-redirect-block.php" <<'PHP'
<?php
// Disposable-site guard for redirect.sh: logs every outbound request and refuses everything but this site on port 8072.
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	file_put_contents( ABSPATH . 'redirect-accept-http.log', ( $args['method'] ?? 'GET' ) . ' ' . $url . "\n", FILE_APPEND );
	// Port 8072 only: inside the container, localhost on port 80 is the main site.
	return ( in_array( $host, array( '127.0.0.1', 'localhost' ), true ) && 8072 === (int) wp_parse_url( $url, PHP_URL_PORT ) ) ? $pre : new WP_Error( 'blocked', 'outbound blocked by redirect.sh' );
}, 1, 3 );
PHP
	fi
	cat > "$WPROOT/wp-content/mu-plugins/aafm-accept-redirect-writes.php" <<'PHP'
<?php
// Disposable-site write logger for redirect.sh (case W3): every option or transient write made
// while one of our redirect adapter functions is on the stack. The plugins' own writes on a page
// request (Safe Redirect Manager rewrites its rule transient on every one) are not logged.
foreach ( array( 'setted_transient', 'added_option', 'updated_option', 'deleted_option' ) as $aafm_acc_hook ) {
	add_action(
		$aafm_acc_hook,
		static function ( $name ) {
			if ( false !== strpos( wp_debug_backtrace_summary(), 'aafm_redirect_' ) ) {
				file_put_contents( ABSPATH . 'redirect-accept-writes.log', current_action() . ' ' . $name . "\n", FILE_APPEND );
			}
		}
	);
}
PHP
	W plugin is-active "$OURS" 2>/dev/null || die "our plugin is not active on the disposable site"
	local enabled
	enabled=$(W option get aafm_enabled_abilities --format=json 2>/dev/null || echo '[]')
	W option update aafm_enabled_abilities "$(jq -nc --argjson a "$enabled" --argjson b "$TOOLS" '($a + $b) | unique')" --format=json >/dev/null 2>&1 || die "could not enable the redirect tools"
	W user application-password create admin redirect-accept --porcelain > "$WPROOT/redirect-accept-pw.txt" 2>/dev/null || die "could not create an Application Password"
	W post list --post_type=page --name=acc-page --format=ids 2>/dev/null | grep -q '[0-9]' || W post create --post_type=page --post_status=publish --post_title='Acc page' --post_name=acc-page --porcelain >/dev/null 2>&1
}

build_root() {
	# setup.sh output is kept outside the site directory and shown only when it fails.
	bash "$DIR/setup.sh" > /tmp/aafm_redirect_setup.log 2>&1 || { tail -n 30 /tmp/aafm_redirect_setup.log; rm -f /tmp/aafm_redirect_setup.log; die "setup.sh failed (its last lines are above)"; }
	rm -f /tmp/aafm_redirect_setup.log
	WPROOT="$ROOT"
	[ -f "$ROOT/wp-config.php" ] || die "setup.sh did not build $ROOT"
	[ "$(W config get DB_NAME 2>/dev/null)" = "$DB" ] || die "the site in $ROOT does not use database $DB"
	mkdir -p "$OUT"; : > "$OUT/seed.log"
	write_seed
	ready_site
	use_site root
	case "$URL" in http://127.0.0.1:8072|http://localhost:8072) ;; *) die "unexpected site address $URL" ;; esac
}

# build_sub: a second WordPress in the sub/ directory of the same docroot, served by the same
# php -S server, with both its addresses at <site>/sub. Used by case T4 only.
build_sub() {
	local root_url=$URL
	rm -rf "$ROOT/sub"; mkdir -p "$ROOT/sub" || die "could not create $ROOT/sub"
	mysql -hdb -uroot -proot -e "DROP DATABASE IF EXISTS \`$SUBDB\`; CREATE DATABASE \`$SUBDB\`;" || die "could not create database $SUBDB"
	( cd "$ROOT" && tar --exclude=./sub --exclude=./wp-config.php --exclude=./redirect-accept --exclude=./wp-content/uploads -cf - . ) | tar -xf - -C "$ROOT/sub"
	WPROOT="$ROOT/sub"
	W config create --quiet --dbname="$SUBDB" --dbuser=root --dbpass=root --dbhost=db || die "could not configure the subfolder site"
	W config set WP_ENVIRONMENT_TYPE local --quiet
	W core install --quiet --url="$root_url/sub" --title=accept-sub --admin_user=admin --admin_password=admin --admin_email=a@b.co --skip-email || die "could not install the subfolder site"
	W rewrite structure '/%postname%/' --quiet
	W plugin activate "$OURS" --quiet >/dev/null 2>&1 || die "could not activate our plugin on the subfolder site"
	ready_site
	use_site sub
	[ "$HOME_PATH" = /sub ] || die "the subfolder site has home path '$HOME_PATH'"
}

# --- Cases ----------------------------------------------------------------------
run_adapter() {
	local s=$1 ids e d page_id foreign before after n p path
	install_plugin "$s"

	ids=$(seed main "$s" "${ORIGIN#*://}")
	e=$(echo "$ids" | jq -r '.E'); d=$(echo "$ids" | jq -r '.D')
	[ -n "$e" ] && [ "$e" != null ] && [ -n "$d" ] || die "$s: the vendor did not create the test rules: $(tail -3 "$OUT/seed.log")"

	# L1: an enabled exact rule, a disabled exact rule and the pattern rules are listed as stored.
	if call aafm/site-list-redirects '{"per_page":50}' && ran; then
		if [ "$(sc ".redirects[] | select(.id == \"$e\") | [.source, (.enabled|tostring), .kind, (.code|tostring)] | join(\" \")")" = "/acc-old-a true exact 301" ] \
			&& [ "$(sc ".redirects[] | select(.id == \"$d\") | [.source, (.enabled|tostring)] | join(\" \")")" = "/acc-old-off false" ] \
			&& [ "$(sc ".providers[] | select(.provider == \"$s\") | [(.ready|tostring), (.included|tostring), (.failed|tostring)] | join(\" \")")" = "true true false" ]; then
			pass L1 "$s: the enabled and the disabled exact rule are listed with source, code and state"
		else
			fail L1 "$s: listing was $(sc '.redirects' | tr -d '\n' | head -c 400)"
		fi
		n=$(echo "$ids" | jq '.patterns | length')
		for p in $(echo "$ids" | jq -r '.patterns[].id'); do
			if [ "$(sc ".redirects[] | select(.id == \"$p\") | .kind")" = pattern ]; then pass L1 "$s: rule $p is marked as a pattern"; else fail L1 "$s: rule $p is not marked as a pattern"; fi
		done
		[ "$n" = 0 ] && skip L1 "$s: this plugin has no pattern rules"
	else
		fail L1 "$s: site-list-redirects did not answer: $(errtext | head -c 200)"
	fi

	# L2: listing twice must not make the disabled rule fire (the Safe Redirect Manager transient trap).
	call aafm/site-list-redirects '{}' >/dev/null; call aafm/site-list-redirects '{}' >/dev/null
	case "$(hit /acc-old-off)" in
		30[1278]*) fail L2 "$s: the disabled rule fired after two listings: $(hit /acc-old-off)" ;;
		*) pass L2 "$s: the disabled rule does not fire after two listings" ;;
	esac

	# L3: a target with sign-in details and a query string is output without them.
	if ! call aafm/site-list-redirects '{"search":"acc-userinfo"}' || ! ran; then
		fail L3 "$s: site-list-redirects did not answer: $(errtext | head -c 200)"
	elif [ "$(sc '.redirects | length')" = 0 ] || [ "$(echo "$ids" | jq -r '.userinfo')" = "" ]; then
		skip L3 "$s: the plugin did not store a rule with that target"
	elif sc '.redirects[0].target' | grep -q -e 'user:secret@' -e 'key=abc'; then
		fail L3 "$s: the output target still carries sign-in details or a query: $(sc '.redirects[0].target')"
	elif [ "$(sc '.redirects[0].target_trimmed')" = true ]; then
		pass L3 "$s: target $(sc '.redirects[0].target') with target_trimmed true"
	else
		skip L3 "$s: the plugin itself stored the target without sign-in details and query ($(sc '.redirects[0].target'))"
	fi

	# G1: the rule by its id; the id of an ordinary page; an id no rule of this plugin has.
	page_id=$(W post list --post_type=page --name=acc-page --format=ids)
	case "$s" in slim_seo) foreign=999999 ;; *) foreign=6afcd5d699922 ;; esac
	if call aafm/site-get-redirect "$(jq -nc --arg i "$e" '{id:$i}')" && ran && [ "$(sc '.redirect.source')" = /acc-old-a ]; then pass G1 "$s: the rule is read by its id"; else fail G1 "$s: get by id answered $(errtext | head -c 200)"; fi
	call aafm/site-get-redirect "$(jq -nc --arg i "$page_id" '{id:$i}')"
	if refused && errtext | grep -q 'No redirect with that id'; then pass G1 "$s: the id of an ordinary page is not a redirect"; else fail G1 "$s: page id $page_id answered $(jq -c '.result' "$OUT/body.json" | head -c 200)"; fi
	call aafm/site-get-redirect "$(jq -nc --arg i "$foreign" '{id:$i}')"
	if refused && errtext | grep -q 'No redirect with that id'; then pass G1 "$s: an id of another plugin is not found"; else fail G1 "$s: foreign id answered $(jq -c '.result' "$OUT/body.json" | head -c 200)"; fi

	# T1: the tool agrees with real requests, and the tool calls change no counter and no log.
	same_answer T1 "$s" /acc-old-a
	same_answer T1 "$s" /acc-none
	same_answer T1 "$s" /acc-old-off
	# The three real requests above moved the counters; only the tool calls are measured.
	before=$(seed counters "$s")
	n=0
	for path in /acc-old-a /acc-none /acc-old-off; do
		call aafm/site-test-redirect "$(jq -nc --arg u "$path" '{url:$u}')" && ran && n=$((n + 1))
	done
	after=$(seed counters "$s")
	if ! echo "$before" | grep -q -E '^[0-9a-z]+(,[0-9a-z]+)*$'; then
		fail T1 "$s: the counters could not be read ('$before'): $(tail -3 "$OUT/seed.log")"
	elif [ "$n" != 3 ]; then
		fail T1 "$s: only $n of the 3 measured tool calls answered: $(errtext | head -c 200)"
	elif [ "$before" = "$after" ]; then
		pass T1 "$s: hit counters and logs unchanged by the tool ($after)"
	else
		fail T1 "$s: counters went from $before to $after"
	fi

	# T2: a two-rule loop is found.
	if call aafm/site-test-redirect '{"url":"/acc-loop-a"}' && ran && [ "$(sc '.loop_detected')" = true ] && [ "$(sc '.chain_ends')" = loop ]; then
		pass T2 "$s: loop detected in $(sc '.chain | length') hops"
	else
		fail T2 "$s: loop_detected=$(sc '.loop_detected') chain_ends=$(sc '.chain_ends') $(errtext | head -c 120)"
	fi

	# T3: every non-exact kind the plugin offers, and a path none of them matches.
	for path in $(echo "$ids" | jq -r '.patterns[].path'); do same_answer T3 "$s" "$path"; done
	same_answer T3 "$s" /acc-none-pattern
	[ "$(echo "$ids" | jq '.patterns | length')" = 0 ] && skip T3 "$s: this plugin has exact rules only"

	# U1: a site-wide redirect that is not a rule makes the plugin answer unknown and name it.
	case "$s" in
		rankmath)
			seed set "$s" general.redirections_fallback homepage >/dev/null
			unknown_case "$s" rank_math_404_fallback /acc-none 1
			seed set "$s" general.redirections_fallback default >/dev/null
			seed set "$s" general.attachment_redirect_urls on >/dev/null
			unknown_case "$s" rank_math_attachment_redirect /acc-none 0
			seed set "$s" general.attachment_redirect_urls off >/dev/null
			seed set "$s" titles.disable_date_archives on >/dev/null
			unknown_case "$s" rank_math_archive_redirect /2025/ 1
			seed set "$s" titles.disable_date_archives off >/dev/null
			cat > "$WPROOT/wp-content/mu-plugins/aafm-accept-rm-pre-search.php" <<'PHP'
<?php
// redirect.sh case U1: another plugin's filter on Rank Math's pre_search, which runs before its rules.
add_filter( 'rank_math/redirection/pre_search', function ( $pre, $uri ) {
	return 'acc-pre-search' === $uri ? array( 'url_to' => home_url( '/acc-page/' ), 'header_code' => 302 ) : $pre;
}, 10, 2 );
PHP
			unknown_case "$s" rank_math_pre_search_filter /acc-pre-search 1
			rm -f "$WPROOT/wp-content/mu-plugins/aafm-accept-rm-pre-search.php" ;;
		slim_seo)
			seed set "$s" redirect_404_to homepage >/dev/null
			unknown_case "$s" slim_seo_404_redirect /acc-none 1
			seed set "$s" redirect_404_to - >/dev/null
			seed set "$s" auto_redirection 1 >/dev/null
			unknown_case "$s" slim_seo_auto_redirection /acc-none 0
			seed set "$s" auto_redirection 0 >/dev/null
			seed set "$s" redirect_www non-to-www >/dev/null
			unknown_case "$s" slim_seo_www_redirect /acc-none 1
			seed set "$s" redirect_www - >/dev/null ;;
		redirection)
			# The site move would also move this script's own MCP requests, which use index.php?rest_route=.
			cat > "$WPROOT/wp-content/mu-plugins/aafm-accept-red-protect.php" <<'PHP'
<?php
// redirect.sh case U1: keep the MCP endpoint of this script out of Redirection's site move.
add_filter( 'redirect_relocate_protected', function ( $paths ) {
	$paths[] = '/index.php';
	return $paths;
} );
PHP
			seed set "$s" relocate http://example.invalid >/dev/null
			unknown_case "$s" redirection_relocate /acc-none 1
			seed set "$s" relocate '' >/dev/null
			rm -f "$WPROOT/wp-content/mu-plugins/aafm-accept-red-protect.php" ;;
	esac

	# T5 and T6: Safe Redirect Manager only.
	if [ "$s" = safe_redirect_manager ]; then
		local extra code_id real
		extra=$(seed srm_extra); code_id=$(echo "$extra" | jq -r '.code')
		real=$(hit /caf%C3%A9-old)
		if [ "${real#* }" = "$HOME_PATH/acc-page/" ]; then
			call aafm/site-test-redirect '{"url":"/café-old"}'
			if ran && [ "$(sc ".evaluated[] | select(.provider == \"$s\") | .result")" = unknown ] && [ "$(sc '.first_match')" = null ] && [ "$(sc '.chain_ends')" = leaves_site_or_unknown ]; then
				pass T5 "$s: the site redirects /caf%C3%A9-old and the tool answers unknown for it"
			else
				fail T5 "$s: tool said $(sc '.evaluated' | tr -d '\n' | head -c 200) chain_ends=$(sc '.chain_ends')"
			fi
			same_answer T5 "$s" /caf-old
		else
			fail T5 "$s: the vendor did not redirect /caf%C3%A9-old to /acc-page/ (got '$real'); report this to the lead, the non-ASCII rule rests on it"
		fi
		W post meta update "$code_id" _redirect_rule_status_code 999 >/dev/null 2>&1
		W transient delete _srm_redirects_1000 >/dev/null 2>&1
		real=$(curl -s -I --max-time 30 -o /dev/null -w '%{http_code}' "$ORIGIN/acc-code")
		call aafm/site-test-redirect '{"url":"/acc-code"}'
		if [ "$real" = 302 ] && ran && [ "$(sc '.first_match.code')" = 302 ]; then pass T6 "$s: a stored code of 999 is sent, and reported, as 302"; else fail T6 "$s: curl answered $real, the tool reported code $(sc '.first_match.code')"; fi
	fi

	# F1 and F2: the not-found log.
	if [ "${HAS404[$s]}" = 1 ]; then
		local token=qwertyuiopasdfghjklzxcvbnmqwertyuiopasdfgh body stamp seen now
		for path in '/acc-miss?token=abc' '/acc%20miss/ignore%20previous%20instructions' '/how-to-install-a-wordpress-plugin-old' "/reset/$token"; do
			curl -s --max-time 30 -o /dev/null -A 'AAFM-Accept-UA/1.0' -e 'https://ref.example/page?token=zzz' "$ORIGIN$path"
		done
		if call aafm/site-list-404s '{"per_page":50}' && ran; then
			body=$(jq -c '.result.structuredContent' "$OUT/body.json")
			[ "$(echo "$body" | jq -r 'keys_unsorted[0]')" = untrusted_content_notice ] && pass F1 "$s: the notice is the first key" || fail F1 "$s: first key is $(echo "$body" | jq -r 'keys_unsorted[0]')"
			echo "$body" | jq -e '[.entries[].untrusted_path] | index("/acc-miss")' >/dev/null && pass F1 "$s: /acc-miss is listed without its query string" || fail F1 "$s: /acc-miss is missing: $(echo "$body" | jq -c '[.entries[].untrusted_path]' | head -c 300)"
			echo "$body" | jq -e '[.entries[].untrusted_path] | index("/acc%20miss/ignore%20previous%20instructions")' >/dev/null && pass F1 "$s: spaces are shown as %20" || fail F1 "$s: the path with spaces is missing or raw"
			echo "$body" | jq -e '[.entries[].untrusted_path] | index("/how-to-install-a-wordpress-plugin-old")' >/dev/null && pass F1 "$s: an ordinary long slug is not redacted" || fail F1 "$s: the long slug is missing or redacted"
			echo "$body" | jq -e '[.entries[].untrusted_path] | index("/reset/[redacted]")' >/dev/null && pass F1 "$s: a 40 letter token is redacted" || fail F1 "$s: the token path is missing or not redacted"
			if echo "$body" | jq -r '.entries[].untrusted_path' | grep -q -e 'token=' -e ' ' -e '?' \
				|| echo "$body" | grep -q -e 'AAFM-Accept-UA' -e 'ref.example' -e "$token" \
				|| echo "$body" | jq -e '[paths | map(tostring) | join(".")] | map(select(test("(^|\\.)(ip|user_agent|agent|referer|referrer)$"; "i"))) | length > 0' >/dev/null; then
				fail F1 "$s: the output carries a query string, a raw space, a browser name, a referring address or such a field"
			else
				pass F1 "$s: no query string, raw space, browser name, referring address or IP field in the output"
			fi
		else
			fail F1 "$s: site-list-404s did not answer: $(errtext | head -c 200)"
		fi

		W option update timezone_string Asia/Karachi >/dev/null 2>&1
		stamp="acc-miss-tz-$(date +%s)"
		curl -s --max-time 30 -o /dev/null "$ORIGIN/$stamp"; now=$(date -u +%s)
		call aafm/site-list-404s '{"per_page":50}'
		seen=$(sc ".entries[] | select(.untrusted_path == \"/$stamp\") | .last_seen")
		if [ "${HASTIME[$s]}" = 1 ]; then
			if [ -n "$seen" ] && [ "$seen" != null ] && [ "$(( now - $(date -u -d "$seen" +%s) ))" -ge -120 ] && [ "$(( now - $(date -u -d "$seen" +%s) ))" -le 120 ]; then
				pass F2 "$s: last_seen $seen is within 120 seconds of UTC now on a site in Asia/Karachi"
			else
				fail F2 "$s: last_seen was '$seen' at UTC $(date -u -d "@$now" +%FT%T)"
			fi
		elif [ "$seen" = null ]; then
			pass F2 "$s: this plugin's grouped log has no time, and last_seen is null"
		else
			fail F2 "$s: expected a null last_seen, got '$seen'"
		fi
		W option update timezone_string UTC >/dev/null 2>&1
	else
		skip F1 "$s: this plugin keeps no not-found log"; skip F2 "$s: this plugin keeps no not-found log"
		call aafm/site-list-404s '{}'
		if refused; then pass F1 "$s: site-list-404s is refused when no ready plugin keeps a log"; else fail F1 "$s: site-list-404s answered without a log provider"; fi
	fi

	# W3, first half: the write logger of ready_site records a write made inside an aafm_redirect_ function.
	: > "$WPROOT/redirect-accept-writes.log"
	seed probe >/dev/null
	if grep -q 'aafm_acc_probe' "$WPROOT/redirect-accept-writes.log" 2>/dev/null; then pass W3 "$s: the write logger records a write made inside an aafm_redirect_ function"; else fail W3 "$s: the write logger did not record the probe write"; fi
	: > "$WPROOT/redirect-accept-writes.log"

	# W2: from a cold cache, the four reads write nothing.
	W cache flush >/dev/null 2>&1
	[ "$s" = safe_redirect_manager ] && W transient delete _srm_redirects_1000 >/dev/null 2>&1
	mcp_post '{"jsonrpc":"2.0","id":9,"method":"ping"}' >/dev/null; seed snapshot >/dev/null
	before=$(seed snapshot)
	n=0
	call aafm/site-list-redirects '{}' && ran && n=$((n + 1))
	call aafm/site-get-redirect "$(jq -nc --arg i "$e" '{id:$i}')" && ran && n=$((n + 1))
	if [ "${HAS404[$s]}" = 1 ]; then call aafm/site-list-404s '{}' && ran && n=$((n + 1)); else call aafm/site-list-404s '{}'; refused && n=$((n + 1)); fi
	call aafm/site-test-redirect '{"url":"/acc-old-a"}' && ran && n=$((n + 1))
	after=$(seed snapshot)
	if ! echo "$before" | jq -e 'has("options")' >/dev/null 2>&1; then
		fail W2 "$s: the snapshot could not be read: $(echo "$before" | head -c 200)"
	elif [ "$n" != 4 ]; then
		fail W2 "$s: only $n of the four reads gave their expected answer: $(errtext | head -c 200)"
	elif [ "$before" = "$after" ]; then
		pass W2 "$s: options, transients, cron and post counts identical before and after the four reads"
	else
		fail W2 "$s: the snapshot changed. before=$(echo "$before" | head -c 300) after=$(echo "$after" | head -c 300)"
	fi

	# W3, second half: Safe Redirect Manager rewrites its own transient on every request, so W2 alone
	# cannot see a write by our code there; the logger can, for every plugin.
	if [ -s "$WPROOT/redirect-accept-writes.log" ]; then
		fail W3 "$s: written while our adapter code ran: $(sort -u "$WPROOT/redirect-accept-writes.log" | head -5 | tr '\n' ';')"
	else
		pass W3 "$s: no option or transient was written while our adapter code ran"
	fi

	# T3, last because it changes what every other path answers: a stored regex that does not compile.
	if [ "$s" = safe_redirect_manager ] || [ "$s" = slim_seo ]; then
		if [ -n "$(seed broken "$s")" ]; then
			call aafm/site-test-redirect '{"url":"/acc-none-after-broken"}'
			if ran && [ "$(sc ".evaluated[] | select(.provider == \"$s\") | .result")" = unknown ]; then pass T3 "$s: a rule whose regex does not compile gives unknown"; else fail T3 "$s: with a broken regex the tool said $(sc '.evaluated' | tr -d '\n' | head -c 200)"; fi
		else
			skip T3 "$s: the plugin refused to store a regex that does not compile"
		fi
	else
		skip T3 "$s: a regex that does not compile is not stored by this plugin, or it has no regex rules"
	fi

	# T4: the same plugin on a site whose home URL has a path.
	build_sub
	install_plugin "$s"
	seed sub "$s" >/dev/null
	local got=""
	for path in /sub/old-a /sub/old-b /sub/sub/old-b; do
		same_answer T4 "$s" "$path"
		got="$got $(hit "$path" | cut -d' ' -f1)"
	done
	[ "${got# }" = "${BENCH_SUB[$s]}" ] || echo "NOTE T4 $s: real requests answered '${got# }', the subfolder bench saw '${BENCH_SUB[$s]}'"
	use_site root
}

run_multi() {
	local s winner real tprov left
	for s in $ALL; do install_plugin "$s"; seed same "$s" >/dev/null; done

	# X1: with more than one redirect plugin ready, a single-rule read needs the plugin named.
	call aafm/site-get-redirect '{"id":"1"}'
	if refused && errtext | grep -q 'Name one with the provider argument'; then pass X1 "two or more plugins and no provider is refused"; else fail X1 "answered $(jq -c '.result' "$OUT/body.json" | head -c 200)"; fi

	# The evaluation order: with the same source in each plugin, the tool's first match is the plugin a real request is answered by.
	left="$ALL"
	for winner in $ALL; do
		real=$(hit /acc-same)
		call aafm/site-test-redirect '{"url":"/acc-same"}'
		tprov=$(sc '.first_match.provider')
		if ran && [ "$tprov" = "$winner" ] && [ "${real#* }" = "$HOME_PATH/t-$(echo "$winner" | tr '_' '-')" ] && [ "$(sc '.evaluated | map(.provider) | join(" ")')" = "$left" ]; then
			pass ORDER "with [$left] active the site answers with $winner and so does the tool"
		else
			fail ORDER "with [$left] active: curl '$real', tool first match '$tprov', evaluated '$(sc '.evaluated | map(.provider + "=" + .result) | join(" ")')'"
		fi
		W plugin deactivate "${PLUGIN[$winner]}" --quiet >/dev/null 2>&1
		left=$(echo " $left " | sed "s/ $winner / /; s/^ //; s/ $//")
	done
}

# --- Run --------------------------------------------------------------------------
# The disposable site and the subfolder database go however the script ends.
trap teardown_all EXIT
build_root
if [ "$MODE" = multi ]; then run_multi; else run_adapter "$MODE"; fi
if [ -s "$ROOT/redirect-accept-http.log" ]; then echo "outbound requests seen by this script's guard:"; sort "$ROOT/redirect-accept-http.log" | uniq -c | head -20; fi
echo "REDIRECT $MODE: $PASSES pass, $FAILS fail, $SKIPS skip"
[ "$FAILS" -eq 0 ]
