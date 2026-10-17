#!/usr/bin/env bash
# Pre-release smoke gate. Builds a disposable WordPress site, loads the working tree
# into it in the plugin-order and cache combinations that have bitten us before, and
# asserts on what the HTTP responses and debug.log actually say.
#
#   ddev exec bash /var/www/plugin-src/bin/smoke-gate.sh [--from <version>] [--keep] [--shots]
#
# Exit 0 = every hard check passed. Lines are PASS / FAIL / KNOWN <leg>: <detail>, then
# a final "SMOKE: <n> pass, <n> fail, <n> known".
#
# Env: SMOKE_DEFAULT_SERVER_STRICT=0  downgrades default-server findings from FAIL to KNOWN
#                                      (strict is the default since 1.8.0).
#      SMOKE_INJECT_FAULT=1           drops a PHP-warning mu-plugin (proves the gate goes red).
#      SMOKE_INJECT_LEAK=1            makes our abilities public again and removes the default-server
#                                      guards (proves the default-server leg goes red).
#      SMOKE_DB / SMOKE_ROOT          override the disposable site; only to test the guard.
#      SMOKE_PLUGIN_SRC               plugin tree to symlink in (under /var/www/plugin-src); only to
#                                     run the gate against an older release export.
#
# Never touches the DDEV site database (`db`) or anything under /var/www/html.

set -u

REPO_SRC=/var/www/plugin-src
PLUGIN_SRC="${SMOKE_PLUGIN_SRC:-$REPO_SRC}"
SMOKE_DB="${SMOKE_DB:-aafm_smoke}"
SMOKE_ROOT="${SMOKE_ROOT:-/tmp/aafm-smoke}"
URL=http://localhost:8070
INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}'
OURS='agent-abilities-for-mcp/agent-abilities-for-mcp.php'
ADAPTER='mcp-adapter/mcp-adapter.php'
QM='query-monitor/query-monitor.php'
ROUTE_OURS='agent-abilities-for-mcp/mcp'
ROUTE_DEFAULT='mcp/mcp-adapter-default-server'
PAGES=('wp-admin/index.php' 'wp-admin/admin.php?page=agent-abilities-for-mcp' 'wp-admin/admin.php?page=agent-abilities-for-mcp&tab=connection' 'wp-admin/admin.php?page=agent-abilities-for-mcp&tab=settings' 'wp-admin/plugins.php' '')
BAD_BODY='Fatal error|Uncaught|already defined|is deprecated'
# Abilities enabled on the disposable site so tools/list has something to count.
ENABLED='["aafm/get-posts","aafm/get-pages","aafm/count-posts"]'

# Guard: refuse before anything is created.
if [ ! -f "$REPO_SRC/agent-abilities-for-mcp.php" ]; then
	echo "refusing: not inside the DDEV web container"
	exit 2
fi
if [ "$SMOKE_DB" = "db" ]; then
	echo "refusing: SMOKE_DB=db is the DDEV site database"
	exit 2
fi
case "$SMOKE_DB" in
	*[!A-Za-z0-9_]*)
		echo "refusing: SMOKE_DB may only contain letters, digits and underscores"
		exit 2
		;;
	aafm_smoke*) ;;
	*)
		echo "refusing: SMOKE_DB must start with aafm_smoke"
		exit 2
		;;
esac
case "$SMOKE_ROOT" in
	/var/www/html* | *..*)
		echo "refusing: SMOKE_ROOT must not be under /var/www/html or contain .."
		exit 2
		;;
	*[!A-Za-z0-9_./-]*)
		echo "refusing: SMOKE_ROOT has characters outside letters, digits and _ . / -"
		exit 2
		;;
	/tmp/aafm-smoke) ;;
	/tmp/aafm-smoke-*)
		case "${SMOKE_ROOT#/tmp/aafm-smoke-}" in
			'' | *[!A-Za-z0-9_]*)
				echo "refusing: SMOKE_ROOT must be /tmp/aafm-smoke or /tmp/aafm-smoke-<letters, digits, underscores>"
				exit 2
				;;
		esac
		;;
	*)
		echo "refusing: SMOKE_ROOT must be /tmp/aafm-smoke or /tmp/aafm-smoke-<letters, digits, underscores>"
		exit 2
		;;
esac
if [ -L "$SMOKE_ROOT" ]; then
	echo "refusing: SMOKE_ROOT is a symlink"
	exit 2
fi
case "$PLUGIN_SRC" in
	"$REPO_SRC" | "$REPO_SRC"/*) ;;
	*)
		echo "refusing: SMOKE_PLUGIN_SRC must be under $REPO_SRC"
		exit 2
		;;
esac
if [ ! -f "$PLUGIN_SRC/agent-abilities-for-mcp.php" ]; then
	echo "refusing: no agent-abilities-for-mcp.php in $PLUGIN_SRC"
	exit 2
fi

FROM=''
KEEP=0
SHOTS=0
BIND=localhost
while [ $# -gt 0 ]; do
	case "$1" in
		--from)
			[ $# -ge 2 ] || { echo "usage: smoke-gate.sh [--from <version>] [--keep] [--shots]"; exit 2; }
			FROM="$2"
			shift 2
			;;
		--keep)
			KEEP=1
			shift
			;;
		--shots)
			SHOTS=1
			shift
			;;
		*)
			echo "usage: smoke-gate.sh [--from <version>] [--keep] [--shots]"
			exit 2
			;;
	esac
done
[ "$SHOTS" = 1 ] && BIND=0.0.0.0

P=0
F=0
K=0
pass() { P=$((P + 1)); echo "PASS $1: $2"; }
fail() { F=$((F + 1)); echo "FAIL $1: $2"; }
known() { K=$((K + 1)); echo "KNOWN $1: $2"; }
# default-server findings are hard failures; SMOKE_DEFAULT_SERVER_STRICT=0 makes them KNOWN.
ds() { if [ "${SMOKE_DEFAULT_SERVER_STRICT:-1}" = 1 ]; then fail default-server "$1"; else known default-server "$1"; fi; }

LOG="$SMOKE_ROOT/wp-content/debug.log"
W() { wp --allow-root --quiet --path="$SMOKE_ROOT" "$@"; }
# WT: the same, for commands that download; bounded so a dead network cannot hang the gate.
WT() { timeout 120 wp --allow-root --quiet --path="$SMOKE_ROOT" "$@"; }

finish() {
	echo "SMOKE: $P pass, $F fail, $K known"
	[ "$F" -eq 0 ] && exit 0
	exit 1
}

teardown() {
	[ "$KEEP" = 1 ] && { echo "kept: $SMOKE_ROOT (database $SMOKE_DB, server may still be running)"; return; }
	pkill -f 'php -S [^ ]*:8070' 2>/dev/null
	mysql -hdb -uroot -proot -e "DROP DATABASE IF EXISTS \`$SMOKE_DB\`" 2>/dev/null
	rm -rf "$SMOKE_ROOT"
}
trap teardown EXIT
trap 'exit 130' INT TERM

setup_site() {
	rm -rf "$SMOKE_ROOT"
	mkdir -p "$SMOKE_ROOT" || return 1
	mysql -hdb -uroot -proot -e "DROP DATABASE IF EXISTS \`$SMOKE_DB\`; CREATE DATABASE \`$SMOKE_DB\`;" 2>/dev/null || return 1
	WT core download >/dev/null 2>&1 || return 1
	W config create --dbname="$SMOKE_DB" --dbuser=root --dbpass=root --dbhost=db >/dev/null 2>&1 || return 1
	W config set WP_DEBUG true --raw >/dev/null 2>&1 || return 1
	W config set WP_DEBUG_LOG true --raw >/dev/null 2>&1 || return 1
	W config set WP_DEBUG_DISPLAY false --raw >/dev/null 2>&1 || return 1
	W config set WP_ENVIRONMENT_TYPE local >/dev/null 2>&1 || return 1
	W core install --url="$URL" --title=smoke --admin_user=admin --admin_password=admin \
		--admin_email=a@b.co --skip-email >/dev/null 2>&1 || return 1
	ln -sfn "$PLUGIN_SRC" "$SMOKE_ROOT/wp-content/plugins/agent-abilities-for-mcp" || return 1
	W plugin activate agent-abilities-for-mcp >/dev/null 2>&1 || return 1
	W option update aafm_enabled_abilities "$ENABLED" --format=json >/dev/null 2>&1 || return 1
	APP_PW=$(W user application-password create admin smoke --porcelain 2>/dev/null)
	[ -n "$APP_PW" ] || return 1
	mkdir -p "$SMOKE_ROOT/wp-content/mu-plugins"
	if [ "${SMOKE_INJECT_FAULT:-0}" = 1 ]; then
		cat > "$SMOKE_ROOT/wp-content/mu-plugins/inject-fault.php" <<'PHP'
<?php
add_action( 'admin_init', function () {
	trigger_error( 'agent-abilities-for-mcp smoke injected fault', E_USER_WARNING );
	_deprecated_function( 'agent-abilities-for-mcp-smoke-fault', '0.0.0' );
} );
PHP
	fi
	if [ "${SMOKE_INJECT_LEAK:-0}" = 1 ]; then
		cat > "$SMOKE_ROOT/wp-content/mu-plugins/inject-leak.php" <<'PHP'
<?php
// Proof-of-red for the default-server leg: make our abilities public again and strip the guards.
add_filter( 'wp_register_ability_args', function ( $args, $name ) {
	if ( 0 === strpos( (string) $name, 'aafm/' ) || 0 === strpos( (string) $name, 'aafm-bridge/' ) ) {
		$args['meta']['mcp']['public'] = true;
	}
	return $args;
}, PHP_INT_MAX, 2 );
add_action( 'plugins_loaded', function () {
	remove_filter( 'mcp_adapter_tools_list', 'aafm_guard_default_server_tools_list', 1 );
	remove_filter( 'mcp_adapter_pre_tool_call', 'aafm_guard_default_server_tool_call', 1 );
	remove_filter( 'mcp_adapter_tool_call_result', 'aafm_guard_default_server_call_result', 1 );
}, 99 );
PHP
	fi
	return 0
}

start_server() {
	pkill -f 'php -S [^ ]*:8070' 2>/dev/null
	sleep 1
	if [ "$(curl -s --max-time 3 -o /dev/null -w '%{http_code}' "$URL/")" != 000 ]; then
		fail setup "port 8070 busy"
		return 1
	fi
	# Four workers so the built-in server can answer a request that a command it is serving makes to itself.
	(PHP_CLI_SERVER_WORKERS=4 php -S "$BIND:8070" -t "$SMOKE_ROOT" > "$SMOKE_ROOT/server.log" 2>&1 &)
	local i
	for i in $(seq 1 20); do
		[ "$(curl -s --max-time 3 -o /dev/null -w '%{http_code}' "$URL/")" != 000 ] && return 0
		sleep 0.5
	done
	fail setup "php -S did not start"
	return 1
}

# Install the standalone adapter plugin at a version (default 0.7.0), downloading each release once.
# Only one version is installed at a time, so legs can compare 0.6.1 and 0.7.0 on the same site.
ADAPTER_INSTALLED=''
fetch_adapter() { # <leg> [version]
	local leg="$1" ver="${2:-0.7.0}"
	local zip="$SMOKE_ROOT/mcp-adapter-$ver.zip" url="https://github.com/WordPress/mcp-adapter/releases/download/v$ver/mcp-adapter.zip"
	[ "$ADAPTER_INSTALLED" = "$ver" ] && return 0
	if [ ! -s "$zip" ] && ! curl -fsSL --max-time 60 -o "$zip" "$url"; then
		rm -f "$zip"
		fail "$leg" "download $url"
		return 1
	fi
	rm -rf "$SMOKE_ROOT/wp-content/plugins/mcp-adapter"
	if unzip -q -o "$zip" -d "$SMOKE_ROOT/wp-content/plugins" && [ -f "$SMOKE_ROOT/wp-content/plugins/$ADAPTER" ]; then
		ADAPTER_INSTALLED="$ver"
		return 0
	fi
	fail "$leg" "unpack $url"
	return 1
}

# set_state <leg> <label> <active_plugins json> <WP_DEBUG true|false> [predefine WP_MCP_VERSION 1|0]
# Applies the state, then reads it back from a booted WordPress. FAILs the leg and returns 1 if any
# setter failed or the loaded state is not the requested one (WordPress silently drops invalid
# active_plugins entries, so B or C could otherwise fall back to A and still PASS).
set_state() {
	local leg="$1" label="$2" plugins="$3" debug="$4" pre="${5:-0}" want got mcpver wantdbg
	# Clear the previous scenario's predefine first: with a fake WP_MCP_VERSION loaded, WP-CLI's
	# own option update can fail.
	rm -f "$SMOKE_ROOT/wp-content/mu-plugins/predefine.php"
	# WP-CLI exits 1 when the new value equals the stored one, so a failed update only counts if
	# the stored list is not the wanted one.
	if ! W option update active_plugins "$plugins" --format=json >/dev/null 2>&1 \
		&& [ "$(W option get active_plugins --format=json 2>/dev/null | jq -c .)" != "$(echo "$plugins" | jq -c .)" ]; then
		fail "$leg" "$label: could not set active_plugins"
		return 1
	fi
	W config set WP_DEBUG "$debug" --raw >/dev/null 2>&1 || { fail "$leg" "$label: could not set WP_DEBUG"; return 1; }
	if [ "$pre" = 1 ]; then
		echo "<?php define( 'WP_MCP_VERSION', '9.9.9' );" > "$SMOKE_ROOT/wp-content/mu-plugins/predefine.php" || { fail "$leg" "$label: could not write the WP_MCP_VERSION mu-plugin"; return 1; }
	fi
	got=$(W eval 'foreach ( wp_get_active_and_valid_plugins() as $p ) { echo "plugin:" . plugin_basename( $p ) . "\n"; } echo "debug:" . var_export( WP_DEBUG, true ) . "\n"; echo "mcpver:" . ( defined( "WP_MCP_VERSION" ) ? WP_MCP_VERSION : "undef" ) . "\n";' 2>/dev/null)
	want=$(echo "$plugins" | jq -r '.[]' | sed 's/^/plugin:/')
	if [ "$(echo "$got" | grep '^plugin:')" != "$want" ]; then
		fail "$leg" "$label: loaded plugins are $(echo "$got" | grep '^plugin:' | sed 's/^plugin://' | tr '\n' ' '), wanted $(echo "$want" | sed 's/^plugin://' | tr '\n' ' ')"
		return 1
	fi
	wantdbg=$debug
	if [ "$(echo "$got" | grep '^debug:')" != "debug:$wantdbg" ]; then
		fail "$leg" "$label: WP_DEBUG reads $(echo "$got" | grep '^debug:'), wanted $wantdbg"
		return 1
	fi
	mcpver=$(echo "$got" | grep '^mcpver:' | sed 's/^mcpver://')
	if { [ "$pre" = 1 ] && [ "$mcpver" != 9.9.9 ]; } || { [ "$pre" != 1 ] && [ "$mcpver" = 9.9.9 ]; }; then
		fail "$leg" "$label: WP_MCP_VERSION reads '$mcpver', predefine=$pre"
		return 1
	fi
	: > "$LOG"
	return 0
}

# mcp_post <route> <json body> [session id]: prints the HTTP code; body in body.json, headers in head.txt.
# Authenticates with the admin's Application Password, or with MCP_BEARER when that is set.
# MCP_XFF, when set, is sent as X-Forwarded-For.
MCP_BEARER=''
MCP_XFF=''
mcp_post() {
	local sid=() auth=(-u "admin:$APP_PW")
	[ -n "${3:-}" ] && sid=(-H "Mcp-Session-Id: $3" -H 'MCP-Protocol-Version: 2025-06-18')
	[ -n "$MCP_BEARER" ] && auth=(-H "Authorization: Bearer $MCP_BEARER")
	[ -n "$MCP_XFF" ] && auth+=(-H "X-Forwarded-For: $MCP_XFF")
	curl -s --max-time 60 -D "$SMOKE_ROOT/head.txt" -o "$SMOKE_ROOT/body.json" -w '%{http_code}' \
		"${auth[@]}" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
		${sid[@]+"${sid[@]}"} -X POST "$URL/index.php?rest_route=/$1" -d "$2"
}

# token_post <form body>: POST to the OAuth token endpoint; prints the HTTP code, body in body.json.
token_post() {
	curl -s --max-time 60 -o "$SMOKE_ROOT/body.json" -w '%{http_code}' -d "$1" \
		"$URL/index.php?rest_route=/agent-abilities-for-mcp/oauth/token"
}

# bearer_lists <leg> <label> <token> <want file>: the token's tools/list equals the file, byte for byte.
bearer_lists() {
	local out="$SMOKE_ROOT/tools-bearer-check.txt"
	if MCP_BEARER="$3" tools_list "$ROUTE_OURS" "$out" >/dev/null && cmp -s "$out" "$4"; then
		pass "$1" "$2: tools/list is the same $(wc -l < "$4" | tr -d ' ') tools"
	else
		fail "$1" "$2: tools/list was '$(tr '\n' ' ' < "$out")', wanted '$(tr '\n' ' ' < "$4")'"
	fi
}

# init_code <token>: HTTP code of an initialize sent with that bearer token ('' = Application Password).
init_code() { MCP_BEARER="$1" mcp_post "$ROUTE_OURS" "$INIT"; }

# init_ok <leg> <label> <route>
init_ok() {
	local code
	code=$(mcp_post "$3" "$INIT")
	if [ "$code" = 200 ] && grep -q '"protocolVersion"' "$SMOKE_ROOT/body.json"; then
		pass "$1" "$2: initialize /$3 HTTP 200"
	else
		fail "$1" "$2: initialize /$3 HTTP $code $(head -c 150 "$SMOKE_ROOT/body.json" | tr '\n' ' ')"
	fi
}

# admin_login <jar>: sign the disposable admin in and leave the cookies in <jar>.
admin_login() {
	rm -f "$1"
	curl -s --max-time 60 -c "$1" -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null \
		-d "log=admin&pwd=admin&wp-submit=Log+In&redirect_to=$URL/wp-admin/&testcookie=1" "$URL/wp-login.php"
}

# pages_ok <leg> <label>: three admin pages and the front page, logged in as the disposable admin.
pages_ok() {
	local jar="$SMOKE_ROOT/jar.txt" p code hits bad=''
	admin_login "$jar"
	for p in "${PAGES[@]}"; do
		code=$(curl -s --max-time 60 -b "$jar" -o "$SMOKE_ROOT/page.html" -w '%{http_code}' "$URL/$p")
		hits=$(grep -c -i -E "$BAD_BODY" "$SMOKE_ROOT/page.html")
		if [ "$code" != 200 ] || [ "$hits" != 0 ]; then bad="$bad /$p (HTTP $code, $hits error strings)"; fi
	done
	if [ -z "$bad" ]; then
		pass "$1" "$2: ${#PAGES[@]} pages HTTP 200, no fatal/deprecated text in the body"
	else
		fail "$1" "$2:$bad"
	fi
}

# log_ok <leg> <label>: debug.log lines about us or the adapter that are not clean.
log_ok() {
	local hits
	if [ ! -r "$LOG" ]; then
		fail "$1" "$2: debug.log is missing or unreadable"
		return
	fi
	hits=$(grep -i -E 'deprecat|already defined|fatal|warning|notice' "$LOG" 2>/dev/null | grep -E 'plugin-src|agent-abilities-for-mcp|mcp-adapter')
	if [ -z "$hits" ]; then
		pass "$1" "$2: debug.log clean"
	else
		fail "$1" "$2: debug.log has $(echo "$hits" | wc -l | tr -d ' ') line(s): $(echo "$hits" | head -2 | cut -c1-200 | tr '\n' '|')"
	fi
}

matrix_scenario() { # label plugins debug predefine
	set_state matrix "$1" "$2" "$3" "$4" || return 0
	init_ok matrix "$1" "$ROUTE_OURS"
	pages_ok matrix "$1"
	log_ok matrix "$1"
}

leg_matrix() {
	fetch_adapter matrix || return 0
	matrix_scenario 'A ours only, WP_DEBUG on' "[\"$OURS\"]" true 0
	matrix_scenario 'B ours then standalone 0.7.0' "[\"$OURS\",\"$ADAPTER\"]" true 0
	matrix_scenario 'C standalone 0.7.0 then ours' "[\"$ADAPTER\",\"$OURS\"]" true 0
	matrix_scenario 'A ours only, WP_DEBUG off' "[\"$OURS\"]" false 0
	matrix_scenario 'A ours only, WP_MCP_VERSION predefined' "[\"$OURS\"]" true 1
	rm -f "$SMOKE_ROOT/wp-content/mu-plugins/predefine.php" # later legs must not run with the constant defined
}

leg_qm() {
	local listener="$SMOKE_ROOT/wp-content/mu-plugins/smoke-listener.php" seen="$SMOKE_ROOT/wp-content/smoke-listener.log" qmurl=https://downloads.wordpress.org/plugin/query-monitor.latest-stable.zip
	if ! WT plugin install query-monitor --activate >/dev/null 2>&1; then
		fail qm "download $qmurl"
		return 0
	fi
	cat > "$listener" <<'PHP'
<?php
// Stand-in for what Query Monitor records: every deprecation and doing-it-wrong signal WordPress raises.
add_action( 'deprecated_function_run', function ( $f, $r, $v ) { file_put_contents( WP_CONTENT_DIR . '/smoke-listener.log', "deprecated_function $f since $v\n", FILE_APPEND ); }, 10, 3 );
add_action( 'doing_it_wrong_run', function ( $f, $m, $v ) { file_put_contents( WP_CONTENT_DIR . '/smoke-listener.log', "doing_it_wrong $f: $m\n", FILE_APPEND ); }, 10, 3 );
foreach ( array( 'deprecated_argument_run', 'deprecated_hook_run', 'deprecated_file_included', 'deprecated_class_run' ) as $smoke_hook ) {
	add_action( $smoke_hook, function ( $what = '' ) use ( $smoke_hook ) { file_put_contents( WP_CONTENT_DIR . '/smoke-listener.log', "$smoke_hook $what\n", FILE_APPEND ); }, 10, 1 );
}
PHP
	# Query Monitor reorders active_plugins to load itself first, as it does on a real site.
	if set_state qm 'ours + Query Monitor' "[\"$QM\",\"$OURS\"]" true 0; then
		: > "$seen"
		init_ok qm 'ours + Query Monitor' "$ROUTE_OURS"
		pages_ok qm 'ours + Query Monitor'
		if [ ! -s "$seen" ]; then
			pass qm 'ours + Query Monitor: no deprecation or doing_it_wrong listener entries'
		else
			fail qm "ours + Query Monitor: $(wc -l < "$seen" | tr -d ' ') listener entr(ies): $(head -2 "$seen" | cut -c1-200 | tr '\n' '|')"
		fi
		log_ok qm 'ours + Query Monitor'
	fi
	rm -f "$listener" "$seen"
	W plugin deactivate query-monitor >/dev/null 2>&1
	W plugin delete query-monitor >/dev/null 2>&1
	rm -f "$SMOKE_ROOT/wp-content/db.php" # Query Monitor's drop-in; later legs run without it
}

leg_upgrade() {
	local plugins="$SMOKE_ROOT/wp-content/plugins" dir cur snap after vold vnew t
	dir="$plugins/agent-abilities-for-mcp"
	cur=$(grep -m1 -E '^ \* Version:' "$PLUGIN_SRC/agent-abilities-for-mcp.php" | sed 's/.*: *//' | tr -d '\r')
	if [ -z "$FROM" ]; then
		# Newest 1.* tag that sorts strictly below the version under test.
		for t in $(git -c safe.directory='*' -C "$REPO_SRC" tag --list '1.*' --sort=-v:refname 2>/dev/null); do
			if [ "$t" != "$cur" ] && [ "$(printf '%s\n%s\n' "$t" "$cur" | sort -V | head -1)" = "$t" ]; then
				FROM=$t
				break
			fi
		done
	fi
	if [ -z "$FROM" ]; then
		fail upgrade "no previous release tag found; pass --from <version>"
		return 0
	fi
	[ -L "$dir" ] || { fail upgrade "expected $dir to be the working-tree symlink"; return 0; }
	W option update active_plugins '[]' --format=json >/dev/null 2>&1
	rm "$dir" || { fail upgrade "could not remove the plugin symlink"; return 0; } # symlink only, never the repo it points at
	[ ! -L "$dir" ] || { fail upgrade "plugin symlink still present after rm"; return 0; }
	# WP-CLI sometimes answers "Plugin already installed" (exit 0) for a slug whose directory it
	# just lost, installing nothing. Check the directory, and retry once with --force; the symlink
	# is gone by now, so --force cannot write through into the repo.
	WT plugin install agent-abilities-for-mcp --version="$FROM" --activate >/dev/null 2>&1
	if [ ! -f "$dir/agent-abilities-for-mcp.php" ] && [ ! -L "$dir" ]; then
		WT plugin install agent-abilities-for-mcp --version="$FROM" --activate --force >/dev/null 2>&1
	fi
	if [ -L "$dir" ] || [ ! -f "$dir/agent-abilities-for-mcp.php" ]; then
		[ -L "$dir" ] || rm -rf "$dir" # a failed install can leave a partial directory behind
		ln -sfn "$PLUGIN_SRC" "$dir"
		W option update active_plugins "[\"$OURS\"]" --format=json >/dev/null 2>&1
		fail upgrade "download https://downloads.wordpress.org/plugin/agent-abilities-for-mcp.$FROM.zip"
		return 0
	fi
	W option update aafm_enabled_abilities "$ENABLED" --format=json >/dev/null 2>&1
	snap=$(W option get aafm_enabled_abilities --format=json 2>/dev/null)
	vold=$(W plugin get agent-abilities-for-mcp --field=version 2>/dev/null)
	if [ "$vold" = "$FROM" ] && [ -n "$snap" ]; then
		pass upgrade "release $FROM installed and active, enabled abilities snapshot $snap"
	else
		fail upgrade "release install: version '$vold' (wanted $FROM), snapshot '$snap'"
	fi
	# A live grant under the OLD code: a client, a consent, a token pair, an unredeemed PKCE code and a
	# client allowlist row, plus the admin's Application Password from setup.
	local seed="$SMOKE_ROOT/seed.json" bearer consents_before
	rm -f "$seed"
	# A php -S worker keeps its realpath cache, so one that served the symlinked tree could still run the
	# new code for the "before" requests. A fresh server runs only the release now on disk.
	start_server || return 0
	W eval-file "$PLUGIN_SRC/bin/smoke-seed-grants.php" "$seed" >/dev/null 2>&1
	bearer=$(jq -r '.access // empty' "$seed" 2>/dev/null)
	if [ -z "$bearer" ]; then
		fail upgrade "could not seed grants on $FROM"
	fi
	MCP_BEARER="$bearer" tools_list "$ROUTE_OURS" "$SMOKE_ROOT/tools-bearer-before.txt" >/dev/null
	MCP_BEARER='' tools_list "$ROUTE_OURS" "$SMOKE_ROOT/tools-basic-before.txt" >/dev/null
	consents_before=$(W eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aafm_oauth_consents" );' 2>/dev/null)
	[ -L "$dir" ] || rm -rf "$dir"
	ln -sfn "$PLUGIN_SRC" "$dir"
	: > "$LOG"
	start_server || return 0
	[ -n "$bearer" ] && upgrade_grant_checks "$seed" "$consents_before"
	vnew=$(W plugin get agent-abilities-for-mcp --field=version 2>/dev/null)
	after=$(W option get aafm_enabled_abilities --format=json 2>/dev/null)
	if W plugin is-active agent-abilities-for-mcp >/dev/null 2>&1 && [ "$vnew" = "$cur" ]; then
		pass upgrade "after swap to the working tree: still active at $vnew"
	else
		fail upgrade "after swap: active/version check failed (version '$vnew', wanted $cur)"
	fi
	if [ "$after" = "$snap" ]; then
		pass upgrade "aafm_enabled_abilities identical after the swap"
	else
		fail upgrade "aafm_enabled_abilities changed: before $snap, after $after"
	fi
	init_ok upgrade "$FROM to $cur" "$ROUTE_OURS"
	pages_ok upgrade "$FROM to $cur"
	log_ok upgrade "$FROM to $cur"
	local schema col
	schema=$(W eval 'echo aafm_read_option_views( "aafm_activity_log_schema_version" )["db_value"];' 2>/dev/null)
	col=$(W eval 'echo aafm_activity_log_has_column( aafm_activity_log_table(), "duration_ms" ) ? "yes" : "no";' 2>/dev/null)
	if [ "$schema" = 6 ] && [ "$col" = yes ]; then
		pass upgrade "after $FROM to $cur the activity log is schema 6 and has duration_ms"
	else
		fail upgrade "after $FROM to $cur: schema '$schema', duration_ms '$col' (wanted 6 and yes)"
	fi
}

# upgrade_grant_checks <seed file> <consent count before>: the grant seeded under the old release, read
# right after the swap. The code redeems first because it lives only 60 seconds; the new-column check runs
# before any bearer call, which would stamp last_used_at.
upgrade_grant_checks() {
	local seed="$1" before="$2" cid code access fresh got
	cid=$(jq -r '.client_id' "$seed")
	code=$(token_post "grant_type=authorization_code&code=$(jq -r '.code' "$seed")&code_verifier=$(jq -r '.code_verifier' "$seed")&client_id=$cid&redirect_uri=$(jq -rn --arg u "$(jq -r '.redirect_uri' "$seed")" '$u|@uri')")
	access=$(jq -r '.access_token // empty' "$SMOKE_ROOT/body.json" 2>/dev/null)
	if [ "$code" = 200 ] && [ -n "$access" ]; then
		pass upgrade "a PKCE code minted before the update redeems after it (HTTP 200)"
	else
		fail upgrade "pre-update code redemption: HTTP $code $(head -c 150 "$SMOKE_ROOT/body.json" | tr '\n' ' ')"
	fi
	got=$(W eval 'global $wpdb; $t = $wpdb->prefix . "aafm_oauth_consents"; $ok = true; foreach ( aafm_oauth_grant_columns() as $c ) { $ok = $ok && aafm_oauth_table_has_column( $t, $c ); } echo aafm_read_option_views( "aafm_oauth_schema_version" )["db_value"], " ", $ok ? "columns" : "missing", " ", (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ), " ", (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE COALESCE( paused_at, expires_at, last_used_at, last_used_ip, preset, rate_per_min ) IS NOT NULL" );' 2>/dev/null)
	if [ "$got" = "9 columns $before 0" ]; then
		pass upgrade "OAuth schema 9, all six grant columns present, $before consent row(s) kept, every new column NULL"
	else
		fail upgrade "schema, columns, consent rows, non-NULL rows: '$got' (wanted '9 columns $before 0')"
	fi
	if [ "$(wc -l < "$SMOKE_ROOT/tools-bearer-before.txt" | tr -d ' ')" = 2 ] && [ "$(sort "$SMOKE_ROOT/tools-bearer-before.txt" | tr '\n' ' ')" = "aafm-count-posts aafm-get-posts " ]; then
		pass upgrade "before the update the bearer listed exactly the two allowlisted tools"
	else
		fail upgrade "before the update the bearer listed '$(tr '\n' ' ' < "$SMOKE_ROOT/tools-bearer-before.txt")'"
	fi
	bearer_lists upgrade 'the old bearer after the update' "$(jq -r '.access' "$seed")" "$SMOKE_ROOT/tools-bearer-before.txt"
	bearer_lists upgrade 'the token from the pre-update code' "$access" "$SMOKE_ROOT/tools-bearer-before.txt"
	if MCP_BEARER='' tools_list "$ROUTE_OURS" "$SMOKE_ROOT/tools-basic-after.txt" >/dev/null && cmp -s "$SMOKE_ROOT/tools-basic-after.txt" "$SMOKE_ROOT/tools-basic-before.txt"; then
		pass upgrade "the Application Password's tools/list is unchanged"
	else
		fail upgrade "Application Password tools/list changed: '$(tr '\n' ' ' < "$SMOKE_ROOT/tools-basic-after.txt")'"
	fi
	code=$(token_post "grant_type=refresh_token&refresh_token=$(jq -r '.refresh' "$seed")&client_id=$cid")
	fresh=$(jq -r '.access_token // empty' "$SMOKE_ROOT/body.json" 2>/dev/null)
	if [ "$code" = 200 ] && [ -n "$fresh" ]; then
		pass upgrade "a refresh token minted before the update rotates (HTTP 200)"
		bearer_lists upgrade 'the rotated token' "$fresh" "$SMOKE_ROOT/tools-bearer-before.txt"
	else
		fail upgrade "pre-update refresh: HTTP $code $(head -c 150 "$SMOKE_ROOT/body.json" | tr '\n' ' ')"
	fi
	W eval "aafm_oauth_grant_update( 1, '$cid', array( 'paused' => true ) );" >/dev/null 2>&1
	got="$(init_code "$access") $(init_code '')"
	W eval "aafm_oauth_grant_update( 1, '$cid', array( 'paused' => false ) );" >/dev/null 2>&1
	got="$got $(init_code "$access")"
	if [ "$got" = '403 200 200' ]; then
		pass upgrade "pausing the upgraded grant gives the bearer 403, the Application Password still 200, Resume 200"
	else
		fail upgrade "pause then resume: bearer, Application Password, bearer answered '$got' (wanted '403 200 200')"
	fi
}

# tools_list <route> <outfile>: initialize, then page through tools/list; one tool name per line.
tools_list() {
	local code sid cursor='' n=0 params
	: > "$2"
	code=$(mcp_post "$1" "$INIT")
	if [ "$code" = 404 ] && grep -q rest_no_route "$SMOKE_ROOT/body.json"; then echo "/$1 is not registered"; return 3; fi
	if [ "$code" != 200 ]; then echo "initialize /$1 HTTP $code"; return 1; fi
	sid=$(grep -i '^mcp-session-id:' "$SMOKE_ROOT/head.txt" | tr -d '\r' | awk '{print $2}')
	while [ "$n" -lt 50 ]; do
		params='{}'
		[ -n "$cursor" ] && params=$(jq -nc --arg c "$cursor" '{cursor:$c}')
		code=$(mcp_post "$1" "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/list\",\"params\":$params}" "$sid")
		if [ "$code" != 200 ] || ! jq -e '.result.tools' "$SMOKE_ROOT/body.json" >/dev/null 2>&1; then
			echo "tools/list /$1 HTTP $code $(head -c 150 "$SMOKE_ROOT/body.json" | tr '\n' ' ')"
			return 1
		fi
		jq -r '.result.tools[].name' "$SMOKE_ROOT/body.json" >> "$2"
		cursor=$(jq -r '.result.nextCursor // empty' "$SMOKE_ROOT/body.json")
		n=$((n + 1))
		[ -n "$cursor" ] || break
	done
	return 0
}

# tools_call <route> <tool> <args json>: initialize, then call one tool. Prints the HTTP status of
# the call (or an error line); the JSON-RPC response is left in body.json. Returns 1 unless both
# requests answered 200.
tools_call() {
	local code sid
	code=$(mcp_post "$1" "$INIT")
	if [ "$code" != 200 ]; then echo "initialize /$1 HTTP $code"; return 1; fi
	sid=$(grep -i '^mcp-session-id:' "$SMOKE_ROOT/head.txt" | tr -d '\r' | awk '{print $2}')
	code=$(mcp_post "$1" "$(jq -nc --arg n "$2" --argjson a "$3" '{jsonrpc:"2.0",id:3,method:"tools/call",params:{name:$n,arguments:$a}}')" "$sid")
	echo "$code"
	[ "$code" = 200 ]
}

# call_verdict: classifies the JSON-RPC answer left in body.json by the last tools_call.
#   refused  an isError result or a JSON-RPC error object
#   ran      a result object that is not an error
#   broken   anything else (not JSON, not a JSON-RPC answer): a transport problem proves nothing
call_verdict() {
	local b="$SMOKE_ROOT/body.json"
	if ! jq -e 'type == "object" and .jsonrpc == "2.0"' "$b" >/dev/null 2>&1; then echo broken; return; fi
	if jq -e '(.error | type) == "object" or .result.isError == true' "$b" >/dev/null 2>&1; then
		echo refused
	elif jq -e '(.result | type) == "object"' "$b" >/dev/null 2>&1; then
		echo ran
	else
		echo broken
	fi
}

# refusal_check <label> <tool> <args json> <what>: a call on the default server that must be refused.
# A positive refusal (isError or a JSON-RPC error) is a PASS: the adapter itself refuses an ability
# whose meta.mcp.public is false, and this plugin's guard refuses one that is stamped public. While
# REQUIRE_GUARD=1 (our abilities re-stamped public) only the guard's message counts. An answer that
# ran is a default-server finding; a transport error or non-JSON body is a hard FAIL.
REQUIRE_GUARD=0
refusal_check() {
	local verdict msg
	if tools_call "$ROUTE_DEFAULT" "$2" "$3" >/dev/null; then verdict=$(call_verdict); else verdict=broken; fi
	case "$verdict" in
		refused)
			msg=$(jq -r '[.error.message?, (.result.content // [])[]?.text?] | map(select(type == "string")) | join(" ")' "$SMOKE_ROOT/body.json")
			case "$msg" in
				*"only available through the Agent Abilities for MCP endpoint"*) pass default-server "$1: $4 is refused by the guard" ;;
				*)
					if [ "$REQUIRE_GUARD" = 1 ]; then
						fail default-server "$1: $4 was refused, but not by the guard: $(echo "$msg" | head -c 150)"
					else
						pass default-server "$1: $4 is refused ($(echo "$msg" | head -c 80))"
					fi
					;;
			esac
			;;
		ran) ds "$1: $4 was answered through the default server: $(head -c 120 "$SMOKE_ROOT/body.json" | tr '\n' ' ')" ;;
		*) fail default-server "$1: $4 gave no usable JSON-RPC answer: $(head -c 150 "$SMOKE_ROOT/body.json" | tr '\n' ' ')" ;;
	esac
}

# A stand-in for another plugin that publishes a tool to the adapter's default server.
PUBLISHER="$SMOKE_ROOT/wp-content/mu-plugins/smoke-foreign-publisher.php"
publisher() { # on|off
	if [ "$1" = on ]; then
		cat > "$PUBLISHER" <<'PHP'
<?php
add_action( 'wp_abilities_api_categories_init', function () {
	if ( ! wp_has_ability_category( 'smokefix' ) ) {
		wp_register_ability_category( 'smokefix', array( 'label' => 'Smoke fixture', 'description' => 'Smoke fixture category.' ) );
	}
} );
add_action( 'wp_abilities_api_init', function () {
	wp_register_ability( 'smokefix/ping', array(
		'label'               => 'Smoke ping',
		'description'         => 'A foreign plugin ability that publishes itself to the default server.',
		'category'            => 'smokefix',
		'execute_callback'    => function () { return array( 'pong' => true ); },
		'permission_callback' => '__return_true',
		'meta'                => array( 'mcp' => array( 'public' => true ) ),
	) );
} );
PHP
	else
		rm -f "$PUBLISHER"
	fi
}

# A stand-in for a plugin or a future adapter that re-stamps our abilities public after we register
# them (a late wp_register_ability_args filter). The guards must hold with the stamp undone.
RESTAMP="$SMOKE_ROOT/wp-content/mu-plugins/smoke-restamp.php"
restamp() { # on|off
	if [ "$1" = on ]; then
		cat > "$RESTAMP" <<'PHP'
<?php
add_filter( 'wp_register_ability_args', function ( $args, $name ) {
	if ( 0 === strpos( (string) $name, 'aafm/' ) || 0 === strpos( (string) $name, 'aafm-bridge/' ) ) {
		$args['meta']['mcp']['public'] = true;
	}
	return $args;
}, PHP_INT_MAX, 2 );
PHP
	else
		rm -f "$RESTAMP"
	fi
}

# set_mode <auto|on|off|unset>: the owner's default-server setting. WP-CLI exits 1 on an unchanged value.
set_mode() {
	if [ "$1" = unset ]; then
		W option delete aafm_default_server_mode >/dev/null 2>&1
	else
		W option update aafm_default_server_mode "$1" >/dev/null 2>&1
	fi
	return 0
}

# ds_route_state <leg label>: sets DS_STATE to up|down and fills names.txt when up.
DS_STATE=''
ds_route_state() {
	local msg
	if msg=$(tools_list "$ROUTE_DEFAULT" "$SMOKE_ROOT/names.txt"); then
		DS_STATE=up
	else
		DS_STATE=down
		case "$msg" in *"not registered"*) ;; *) fail default-server "$1: $msg" ;; esac
	fi
}

# ds_up_checks <label> <expect publisher listed: 1|0>: the default server is up. Assert the three
# guarantees on tool CALLS, plus a control call so a refusal means something.
ds_up_checks() {
	local label="$1" want_pub="$2" names="$SMOKE_ROOT/names.txt" disc exe info found leaked ctl
	disc=$(grep 'discover-abilities$' "$names" | head -1)
	exe=$(grep 'execute-ability$' "$names" | head -1)
	info=$(grep 'get-ability-info$' "$names" | head -1)
	if [ -z "$disc" ] || [ -z "$exe" ] || [ -z "$info" ]; then
		fail default-server "$label: default server lists [$(tr '\n' ' ' < "$names")], expected its three meta tools"
		return
	fi
	if [ "$(grep -c '^aafm-' "$names")" != 0 ]; then
		ds "$label: our tools in the default server's tools/list: $(grep '^aafm-' "$names" | head -3 | tr '\n' ' ')"
	fi
	if tools_call "$ROUTE_DEFAULT" "$disc" '{}' >/dev/null && [ "$(call_verdict)" = ran ]; then
		found=$(jq -r '(.result.structuredContent.abilities // [])[].name' "$SMOKE_ROOT/body.json")
		leaked=$(echo "$found" | grep -c -E '^(aafm|aafm-bridge)/')
		if [ "$leaked" = 0 ]; then
			pass default-server "$label: discover-abilities lists none of our abilities"
		else
			ds "$label: discover-abilities lists $leaked of our abilities, e.g. $(echo "$found" | grep -E '^(aafm|aafm-bridge)/' | head -3 | tr '\n' ' ')"
		fi
		if [ "$want_pub" = 1 ]; then
			if echo "$found" | grep -qx 'smokefix/ping'; then
				pass default-server "$label: discover-abilities lists the foreign plugin's public ability"
			else
				fail default-server "$label: discover-abilities does not list smokefix/ping"
			fi
		fi
	else
		fail default-server "$label: discover-abilities call failed: $(head -c 150 "$SMOKE_ROOT/body.json" | tr '\n' ' ')"
	fi
	# Control: a public foreign ability (the fixture's, or core's when nobody publishes) must run
	# through the same tool with the same argument shape, so the refusals below are real refusals.
	ctl=smokefix/ping
	[ "$want_pub" = 1 ] || ctl=core/get-site-info
	if tools_call "$ROUTE_DEFAULT" "$exe" "{\"ability_name\":\"$ctl\",\"parameters\":{}}" >/dev/null \
		&& jq -e '.result.isError == false and .result.structuredContent.success == true' "$SMOKE_ROOT/body.json" >/dev/null 2>&1; then
		pass default-server "$label: control, $ctl executes through the default server"
	else
		fail default-server "$label: control call to $ctl did not succeed: $(head -c 150 "$SMOKE_ROOT/body.json" | tr '\n' ' ')"
	fi
	refusal_check "$label" "$exe" '{"ability_name":"aafm/get-posts","parameters":{}}' 'execute-ability for aafm/get-posts'
	refusal_check "$label" "$info" '{"ability_name":"aafm/get-posts"}' 'get-ability-info for aafm/get-posts'
}

# ds_scenario <label> <plugins json> <publisher on|off> <mode> <expect: up|down> <base tool count> <adapter version that must load>
# The label names the adapter copy under test, so the loaded McpAdapter::VERSION is read back and a
# mismatch is a FAIL (our bundled copy wins whenever we are declared first).
ds_scenario() {
	local label="$1" plugins="$2" pub="$3" mode="$4" expect="$5" base="$6" want_ver="$7" ours msg got_ver
	publisher "$pub"
	set_mode "$mode"
	set_state default-server "$label" "$plugins" true 0 || return 0
	got_ver=$(W eval 'echo class_exists( "WP\\MCP\\Core\\McpAdapter" ) ? \WP\MCP\Core\McpAdapter::VERSION : "none";' 2>/dev/null)
	if [ "$got_ver" != "$want_ver" ]; then
		fail default-server "$label: McpAdapter::VERSION reads '$got_ver', the scenario needs $want_ver"
		return 0
	fi
	ds_route_state "$label"
	if [ "$expect" = up ]; then
		if [ "$DS_STATE" = up ]; then
			ds_up_checks "$label" "$([ "$pub" = on ] && echo 1 || echo 0)"
		else
			fail default-server "$label: the default server should be up and is not registered"
		fi
	elif [ "$DS_STATE" = down ]; then
		pass default-server "$label: default server not registered, as expected"
	else
		ds "$label: default server is registered but should not be"
	fi
	if ! msg=$(tools_list "$ROUTE_OURS" "$SMOKE_ROOT/ours.txt"); then
		fail default-server "$label: $msg"
		return 0
	fi
	ours=$(grep -c . "$SMOKE_ROOT/ours.txt")
	if [ "$ours" = "$base" ]; then
		pass default-server "$label: our endpoint lists $ours tools, same as ours-only"
	else
		ds "$label: our endpoint lists $ours tools, ours-only lists $base"
	fi
	log_ok default-server "$label"
}

leg_default_server_run() {
	local names="$SMOKE_ROOT/names.txt" base msg ver order plugins want missing t found disc exe stamped lv name
	fetch_adapter default-server 0.7.0 || return 0
	publisher off
	restamp off
	set_mode unset
	set_state default-server 'ours-only baseline' "[\"$OURS\"]" true 0 || return 0
	if ! msg=$(tools_list "$ROUTE_OURS" "$names"); then
		fail default-server "ours-only baseline: $msg"
		return 0
	fi
	base=$(grep -c . "$names")
	want=$(echo "$ENABLED" | jq -r '.[]' | tr / -)
	missing=''
	for t in $want; do grep -qx "$t" "$names" || missing="$missing $t"; done
	if [ "$base" -le 0 ] || [ -n "$missing" ]; then
		fail default-server "ours-only baseline lists $base tools, missing:${missing:- (any)}"
		return 0
	fi
	pass default-server "ours-only baseline: $base tools on /$ROUTE_OURS, including every enabled tool"
	# Control: the standalone adapter alone must serve its default server, or a "down" below proves nothing.
	set_state default-server 'control' "[\"$ADAPTER\"]" true 0 || return 0
	if msg=$(tools_list "$ROUTE_DEFAULT" "$names"); then
		pass default-server "control: standalone alone serves /$ROUTE_DEFAULT ($(grep -c . "$names") tools)"
	else
		fail default-server "control: standalone alone: $msg"
	fi
	for ver in 0.7.0 0.6.1; do
		fetch_adapter default-server "$ver" || continue
		for order in 'ours first' 'standalone first'; do
			if [ "$order" = 'ours first' ]; then
				# Declared first, our bundled 0.7.0 loads whatever standalone version is installed.
				plugins="[\"$OURS\",\"$ADAPTER\"]"
				lv=0.7.0
				name="bundled 0.7.0 loads, standalone $ver installed, ours first"
			else
				plugins="[\"$ADAPTER\",\"$OURS\"]"
				lv=$ver
				name="adapter $ver, standalone first"
			fi
			ds_scenario "$name, another plugin publishes, Automatic" "$plugins" on unset up "$base" "$lv"
			ds_scenario "$name, another plugin publishes, Always off" "$plugins" on off down "$base" "$lv"
			ds_scenario "$name, nobody publishes, Automatic" "$plugins" off unset down "$base" "$lv"
			ds_scenario "$name, nobody publishes, Always on" "$plugins" off on up "$base" "$lv"
		done
	done
	# Our abilities re-stamped public by a late filter: the guards alone must keep them off the default server.
	fetch_adapter default-server 0.7.0 || return 0
	restamp on
	REQUIRE_GUARD=1
	for order in 'ours first' 'standalone first'; do
		if [ "$order" = 'ours first' ]; then plugins="[\"$OURS\",\"$ADAPTER\"]"; else plugins="[\"$ADAPTER\",\"$OURS\"]"; fi
		if set_state default-server "re-stamped public, $order" "$plugins" true 0; then
			stamped=$(W eval '$a = wp_get_ability( "aafm/get-posts" ); echo $a ? var_export( $a->get_meta()["mcp"]["public"] ?? null, true ) : "missing";' 2>/dev/null)
			if [ "$stamped" = true ]; then
				pass default-server "re-stamped public, $order: aafm/get-posts reads meta.mcp.public = true, so the guards are what is under test"
				ds_scenario "adapter 0.7.0, $order, re-stamped public, another plugin publishes, Automatic" "$plugins" on unset up "$base" 0.7.0
			else
				fail default-server "re-stamped public, $order: the fixture did not take effect (meta.mcp.public reads '$stamped')"
			fi
		fi
	done
	# A bridged copy of the foreign public ability is ours and must stay off the default server.
	publisher on
	set_mode unset
	W option update aafm_enabled_bridged_abilities '["smokefix/ping"]' --format=json >/dev/null 2>&1
	if set_state default-server 'bridged copy' "[\"$OURS\",\"$ADAPTER\"]" true 0; then
		if tools_list "$ROUTE_OURS" "$names" >/dev/null && grep -qx 'aafm-bridge-smokefix-ping' "$names"; then
			pass default-server "bridged copy: aafm-bridge-smokefix-ping is on our endpoint"
		else
			fail default-server "bridged copy: aafm-bridge-smokefix-ping is missing from our endpoint, so the next checks prove nothing"
		fi
		ds_route_state 'bridged copy'
		if [ "$DS_STATE" = up ]; then
			disc=$(grep 'discover-abilities$' "$names" | head -1)
			exe=$(grep 'execute-ability$' "$names" | head -1)
			if tools_call "$ROUTE_DEFAULT" "$disc" '{}' >/dev/null && [ "$(call_verdict)" = ran ]; then
				found=$(jq -r '(.result.structuredContent.abilities // [])[].name' "$SMOKE_ROOT/body.json")
				if echo "$found" | grep -qx 'aafm-bridge/smokefix-ping'; then
					ds "bridged copy: discover-abilities lists aafm-bridge/smokefix-ping"
				else
					pass default-server "bridged copy: discover-abilities does not list the bridged copy"
				fi
			else
				fail default-server "bridged copy: discover-abilities call failed"
			fi
			refusal_check 'bridged copy' "$exe" '{"ability_name":"aafm-bridge/smokefix-ping","parameters":{}}' 'execute-ability for aafm-bridge/smokefix-ping'
		else
			fail default-server "bridged copy: the default server should be up (another plugin publishes) and is not"
		fi
	fi
	W option delete aafm_enabled_bridged_abilities >/dev/null 2>&1
	REQUIRE_GUARD=0
	restamp off
	publisher off
	set_mode unset
}

# leg_default_server: runs the leg, then puts the site back to the plain state on every exit path, so
# an early return (a failed download, a failed set_state) cannot leave a mode, a publisher, the
# re-stamp fixture or a bridged-abilities row behind for the next leg.
leg_default_server() {
	leg_default_server_run
	REQUIRE_GUARD=0
	restamp off
	publisher off
	set_mode unset
	W option delete aafm_enabled_bridged_abilities >/dev/null 2>&1
	return 0
}

leg_brief() {
	local brief='Smoke rule: always answer in British spelling.' got
	set_state brief 'ours only' "[\"$OURS\"]" true 0 || return 0
	W option update aafm_site_brief "$brief" >/dev/null 2>&1
	if [ "$(mcp_post "$ROUTE_OURS" "$INIT")" = 200 ]; then
		got=$(jq -r '.result.instructions // empty' "$SMOKE_ROOT/body.json")
		case "$got" in
			*"$brief") pass brief "initialize instructions end with the Site Brief" ;;
			*) fail brief "initialize instructions were: $(echo "$got" | head -c 200)" ;;
		esac
	else
		fail brief "initialize /$ROUTE_OURS did not answer 200"
	fi
	W option delete aafm_site_brief >/dev/null 2>&1
	if [ "$(mcp_post "$ROUTE_OURS" "$INIT")" = 200 ]; then
		got=$(jq -r '.result.instructions // empty' "$SMOKE_ROOT/body.json")
		case "$got" in
			*"$brief"*) fail brief "the brief is still in the instructions after it was deleted" ;;
			*) pass brief "instructions carry no brief once it is deleted" ;;
		esac
	else
		fail brief "initialize /$ROUTE_OURS did not answer 200 after the brief was deleted"
	fi
	log_ok brief 'Site Brief'
}

doctor_status() { echo "$DOCTOR_OUT" | jq -r --arg id "$1" '.[] | select(.id==$id) | .status'; }
# doctor_wire <label> <token> <want>: POST the probe headers over the wire; want is "answer" or "silence".
doctor_wire() {
	local code
	code=$(curl -s --max-time 20 -o "$SMOKE_ROOT/body.json" -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
		-H "X-AAFM-Doctor: $2" -H "Authorization: AAFM-Doctor $2" -H 'MCP-Protocol-Version: 2025-06-18' -H 'Mcp-Session-Id: aafm-doctor' \
		-d '{"aafm_doctor":"json"}' "$URL/index.php?rest_route=/$ROUTE_OURS")
	if [ "$3" = answer ]; then
		if [ "$code" = 200 ] && [ "$(jq -c . "$SMOKE_ROOT/body.json" 2>/dev/null)" = '{"aafm_doctor":{"authorization_seen":true,"route_registered":true,"transport_headers_seen":true,"json_body_seen":true}}' ]; then
			pass doctor "$1: HTTP 200 with exactly the four booleans, MCP headers and JSON body seen"
		else
			fail doctor "$1: HTTP $code $(head -c 200 "$SMOKE_ROOT/body.json" | tr '\n' ' ')"
		fi
	elif grep -q 'aafm_doctor' "$SMOKE_ROOT/body.json" 2>/dev/null; then
		fail doctor "$1: the probe answered when it must not have"
	else
		pass doctor "$1: not answered (HTTP $code)"
	fi
	if grep -q -F "$2" "$SMOKE_ROOT/body.json" 2>/dev/null; then
		fail doctor "$1: the token was echoed in the response body"
	fi
}
leg_doctor() {
	local before after id want token
	set_state doctor 'ours only' "[\"$OURS\"]" true 0 || return 0
	before=$(W eval 'echo aafm_activity_count();' 2>/dev/null)
	DOCTOR_OUT=$(W eval 'wp_set_current_user( 1 ); echo wp_json_encode( aafm_doctor_run_checks() );' 2>/dev/null)
	after=$(W eval 'echo aafm_activity_count();' 2>/dev/null)
	if ! echo "$DOCTOR_OUT" | jq -e 'type == "array"' >/dev/null 2>&1; then
		fail doctor "aafm_doctor_run_checks() did not return rows: $(echo "$DOCTOR_OUT" | head -c 200)"
		return 0
	fi
	# The disposable site is plain HTTP on plain permalinks with a local environment type.
	for want in endpoint:pass auth_header:pass app_passwords:pass https:warn permalinks:warn security_plugins:pass transport_headers:pass json_post:pass; do
		id=${want%%:*}
		if [ "$(doctor_status "$id")" = "${want##*:}" ]; then
			pass doctor "$id is ${want##*:}"
		else
			fail doctor "$id is '$(doctor_status "$id")', wanted ${want##*:}"
		fi
	done
	if [ "$before" = "$after" ]; then
		pass doctor "a run wrote no activity rows ($before before and after)"
	else
		fail doctor "activity rows went from $before to $after during a run"
	fi
	# The probe token over the wire: answered once, then never; a wrong or unarmed token is never answered;
	# the answer never carries the token, and none of it writes an activity row.
	token="1.$(printf 'a%.0s' $(seq 1 32))"
	W eval "set_transient( aafm_doctor_probe_key( 1, '$token' ), hash( 'sha256', '$token' ), 30 );" >/dev/null 2>&1
	before=$(W eval 'echo aafm_activity_count();' 2>/dev/null)
	doctor_wire 'armed token' "$token" answer
	doctor_wire 'the same token again' "$token" silence
	doctor_wire 'a token nobody armed' "1.$(printf 'b%.0s' $(seq 1 32))" silence
	after=$(W eval 'echo aafm_activity_count();' 2>/dev/null)
	if [ "$(W eval "echo false === get_transient( aafm_doctor_probe_key( 1, '$token' ) ) ? 'gone' : 'stored';" 2>/dev/null)" = gone ]; then
		pass doctor "the spent token is gone from storage"
	else
		fail doctor "a probe token is still stored after it was used"
	fi
	if [ "$before" = "$after" ]; then
		pass doctor "the wire probes wrote no activity rows ($before before and after)"
	else
		fail doctor "activity rows went from $before to $after across the wire probes"
	fi
	log_ok doctor 'Connection Doctor'
}

# leg_grants: per-connection state on the current code. Presets, pause, pause-all, a per-app rate limit,
# Check endpoint while paused, and an end date, each read over the real endpoint.
leg_grants() {
	local seed="$SMOKE_ROOT/seed-grants.json" cid bearer verdict got jar nonce
	set_state grants 'ours only' "[\"$OURS\"]" true 0 || return 0
	W option update aafm_enabled_abilities '["aafm/get-posts","aafm/get-pages","aafm/count-posts","aafm/create-post","aafm/delete-post"]' --format=json >/dev/null 2>&1
	rm -f "$seed"
	W eval-file "$PLUGIN_SRC/bin/smoke-seed-grants.php" "$seed" >/dev/null 2>&1
	W option delete aafm_ability_allowlist_overrides >/dev/null 2>&1
	cid=$(jq -r '.client_id // empty' "$seed" 2>/dev/null)
	bearer=$(jq -r '.access // empty' "$seed" 2>/dev/null)
	[ -n "$bearer" ] || { fail grants "could not seed a grant"; return 0; }
	grant() { W eval "aafm_oauth_grant_update( 1, '$cid', $1 );" >/dev/null 2>&1; }

	grant "array( 'preset' => 'read_only' )"
	MCP_BEARER="$bearer" tools_list "$ROUTE_OURS" "$SMOKE_ROOT/g.txt" >/dev/null
	if [ "$(sort "$SMOKE_ROOT/g.txt" | tr '\n' ' ')" = "aafm-count-posts aafm-get-pages aafm-get-posts " ]; then
		pass grants "Read only: tools/list holds only the three reads"
	else
		fail grants "Read only: tools/list was '$(tr '\n' ' ' < "$SMOKE_ROOT/g.txt")'"
	fi
	if MCP_BEARER="$bearer" tools_call "$ROUTE_OURS" aafm-create-post '{"title":"smoke"}' >/dev/null; then verdict=$(call_verdict); else verdict=broken; fi
	if [ "$verdict" = refused ]; then pass grants "Read only: a write tools/call is refused"; else fail grants "Read only: a write tools/call was '$verdict'"; fi
	grant "array( 'preset' => 'content_editor' )"
	MCP_BEARER="$bearer" tools_list "$ROUTE_OURS" "$SMOKE_ROOT/g.txt" >/dev/null
	if grep -qx aafm-create-post "$SMOKE_ROOT/g.txt" && ! grep -qx aafm-delete-post "$SMOKE_ROOT/g.txt"; then
		pass grants "Content editor: create-post listed, delete-post hidden"
	else
		fail grants "Content editor: tools/list was '$(tr '\n' ' ' < "$SMOKE_ROOT/g.txt")'"
	fi
	grant "array( 'preset' => '' )"

	grant "array( 'paused' => true )"
	got="$(init_code "$bearer") $(init_code '')"
	if [ "$got" = '403 200' ]; then
		pass grants "a paused grant gives the bearer a plain 403 while the same user's Application Password still works"
	else
		fail grants "paused grant: bearer, Application Password answered '$got' (wanted '403 200')"
	fi
	grant "array( 'paused' => false )"

	W eval 'aafm_set_pause_all( true );' >/dev/null 2>&1
	W option update aafm_default_server_mode on >/dev/null 2>&1
	got="$(init_code "$bearer") $(init_code '') $(MCP_BEARER='' mcp_post "$ROUTE_DEFAULT" "$INIT")"
	if [ "$got" = '403 403 403' ]; then
		pass grants "pause-all: 403 for the bearer and the Application Password, and on the adapter's default server"
	else
		fail grants "pause-all: bearer, Application Password, default server answered '$got' (wanted '403 403 403')"
	fi
	jar="$SMOKE_ROOT/jar.txt"
	admin_login "$jar"
	curl -s --max-time 60 -b "$jar" -o "$SMOKE_ROOT/page.html" "$URL/wp-admin/admin.php?page=agent-abilities-for-mcp&tab=connection"
	nonce=$(sed -n 's/.*var aafmAdmin = \({.*}\);.*/\1/p' "$SMOKE_ROOT/page.html" | head -1 | jq -r '.nonce // empty' 2>/dev/null)
	curl -s --max-time 60 -b "$jar" -o "$SMOKE_ROOT/body.json" -d "action=aafm_test_connection&nonce=$nonce" "$URL/wp-admin/admin-ajax.php"
	if [ -n "$nonce" ] && jq -r '.data.message // empty' "$SMOKE_ROOT/body.json" 2>/dev/null | grep -q 'paused'; then
		pass grants "Check endpoint says access is paused"
	else
		fail grants "Check endpoint while paused: nonce '${nonce:+set}', answer $(head -c 150 "$SMOKE_ROOT/body.json" | tr '\n' ' ')"
	fi
	W eval 'aafm_set_pause_all( false );' >/dev/null 2>&1
	W option delete aafm_default_server_mode >/dev/null 2>&1
	got="$(init_code "$bearer") $(init_code '')"
	if [ "$got" = '200 200' ]; then pass grants "resuming all access lets both credentials in again"; else fail grants "after resume-all: '$got' (wanted '200 200')"; fi

	grant "array( 'rate' => 1 )"
	got=''
	for verdict in 1 2; do
		if MCP_BEARER="$bearer" tools_call "$ROUTE_OURS" aafm-count-posts '{}' >/dev/null; then got="$got $(call_verdict)"; else got="$got broken"; fi
	done
	if [ "$got" = ' ran refused' ]; then
		pass grants "a per-app limit of 1 lets one call through and refuses the next"
	else
		fail grants "per-app limit of 1: calls were '$got' (wanted ran refused)"
	fi
	grant "array( 'rate' => 0 )"

	grant "array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) )"
	got="$(init_code "$bearer") $(token_post "grant_type=refresh_token&refresh_token=$(jq -r '.refresh' "$seed")&client_id=$cid")"
	case "$got" in
		'401 200') fail grants "an expired grant: the bearer got 401 but the refresh was accepted" ;;
		401\ *) pass grants "an expired grant: the bearer gets 401 and the refresh is refused (HTTP ${got#401 })" ;;
		*) fail grants "an expired grant: bearer and refresh answered '$got'" ;;
	esac
	W option update aafm_enabled_abilities "$ENABLED" --format=json >/dev/null 2>&1
	log_ok grants 'grant state'
}

# leg_proxy: the trusted-proxy visitor address, read from the activity log's source_ip.
leg_proxy() {
	local direct got
	set_state proxy 'ours only' "[\"$OURS\"]" true 0 || return 0
	last_ip() { W eval 'global $wpdb; echo $wpdb->get_var( "SELECT source_ip FROM {$wpdb->prefix}aafm_activity_log WHERE event_type = \"ability_call\" ORDER BY id DESC LIMIT 1" );' 2>/dev/null; }
	call_ip() { MCP_XFF="$1" tools_call "$ROUTE_OURS" aafm-count-posts '{}' >/dev/null; last_ip; }
	proxy() { W option update aafm_trusted_proxy "{\"mode\":\"custom\",\"header\":\"X-Forwarded-For\",\"ranges\":[\"$1\"]}" --format=json >/dev/null 2>&1; }
	direct=$(call_ip '')
	case "$direct" in
		*:*) direct_range="$direct/128" ;;
		*) direct_range="$direct/32" ;;
	esac
	got=$(call_ip 203.0.113.9)
	if [ -n "$direct" ] && [ "$got" = "$direct" ]; then pass proxy "off by default: a forwarded header is ignored ($direct logged)"; else fail proxy "off: logged '$got', connection is '$direct'"; fi
	proxy "$direct_range"
	got=$(call_ip 203.0.113.9)
	if [ "$got" = 203.0.113.9 ]; then pass proxy "from a trusted proxy ($direct_range) the forwarded address is logged"; else fail proxy "trusted proxy: logged '$got', wanted 203.0.113.9"; fi
	proxy 10.0.0.0/8
	got=$(call_ip 203.0.113.9)
	if [ "$got" = "$direct" ]; then pass proxy "from an untrusted address the forged header is ignored"; else fail proxy "untrusted proxy: logged '$got', wanted $direct"; fi
	proxy "$direct_range"
	W option update aafm_ip_allowlist '["203.0.113.9/32"]' --format=json >/dev/null 2>&1
	got="$(MCP_XFF=203.0.113.9 mcp_post "$ROUTE_OURS" "$INIT") $(mcp_post "$ROUTE_OURS" "$INIT")"
	if [ "$got" = '200 403' ]; then
		pass proxy "an IP allowlist of the visitor address admits it through the proxy and refuses the proxy itself"
	else
		fail proxy "allowlist through the proxy: with header, without answered '$got' (wanted '200 403')"
	fi
	W option delete aafm_ip_allowlist >/dev/null 2>&1
	: > "$LOG"
	if W plugin deactivate agent-abilities-for-mcp >/dev/null 2>&1 && W plugin activate agent-abilities-for-mcp >/dev/null 2>&1; then
		pass proxy "deactivate and activate with a trusted proxy stored"
	else
		fail proxy "deactivate or activate failed with a trusted proxy stored"
	fi
	W option delete aafm_trusted_proxy >/dev/null 2>&1
	log_ok proxy 'trusted proxy'
}

# shots_mode: build the disposable site, seed demo data, point WordPress at the container's own IP so a
# browser on the host can use it, then wait until $SMOKE_ROOT/shots.done exists (or two hours pass).
# The site is the gate's own disposable one (database aafm_smoke, /tmp/aafm-smoke), never the DDEV site.
# AAFM_SHOTS_SEED=0 skips the seed, for the one shot (the first-run wizard) that needs an unconfigured
# site; seed afterwards with: wp --allow-root --path=/tmp/aafm-smoke eval-file <repo>/bin/screenshots/seed-demo.php
shots_mode() {
	local cip i
	cip=$(hostname -i | awk '{print $1}')
	if [ -z "$cip" ]; then
		fail shots "could not read the container IP"
		return 1
	fi
	WT plugin install woocommerce --activate >/dev/null 2>&1 || known shots "WooCommerce did not install; screenshots 5 and 6 need it"
	WT plugin install wordpress-seo --activate >/dev/null 2>&1 || known shots "Yoast SEO did not install; screenshot 6 will show fewer running integrations"
	cp "$PLUGIN_SRC/bin/screenshots/shots-mu.php" "$PLUGIN_SRC/bin/screenshots/demo-plugin.php" "$SMOKE_ROOT/wp-content/mu-plugins/" || { fail shots "could not copy the capture mu-plugins"; return 1; }
	if [ "${AAFM_SHOTS_SEED:-1}" = 1 ]; then
		if W eval-file "$PLUGIN_SRC/bin/screenshots/seed-demo.php" >/dev/null 2>&1; then
			pass shots "demo data seeded"
		else
			fail shots "seed-demo.php failed"
			return 1
		fi
	else
		echo "SHOTS_UNSEEDED"
	fi
	W option update home "http://$cip:8070" >/dev/null 2>&1
	W option update siteurl "http://$cip:8070" >/dev/null 2>&1
	echo "SHOTS_URL=http://$cip:8070"
	echo "SHOTS_READY"
	for i in $(seq 1 3600); do
		[ -e "$SMOKE_ROOT/shots.done" ] && return 0
		sleep 2
	done
	known shots "timed out waiting for $SMOKE_ROOT/shots.done"
}

# leg_log: duration on a real call, filtered CSV equals the filtered table and count, the tab pages.
leg_log() {
	local dur parity jar="$SMOKE_ROOT/jar.txt" q code hits
	set_state log 'ours only' "[\"$OURS\"]" true 0 || return 0
	W eval 'aafm_clear_activity_log();' >/dev/null 2>&1 # the disposable database only
	if tools_call "$ROUTE_OURS" aafm-get-pages '{}' >/dev/null && [ "$(call_verdict)" = ran ]; then
		dur=$(W eval '$r = aafm_query_activity( array( "ability" => "aafm/get-pages", "per_page" => 1 ) ); echo ( isset( $r[0] ) && null !== $r[0]["duration_ms"] ) ? "ok:" . $r[0]["duration_ms"] : "null";' 2>/dev/null)
		case "$dur" in
			ok:*) pass log "a real tools/call recorded duration_ms ${dur#ok:}" ;;
			*) fail log "no duration on the row for a real tools/call ('$dur')" ;;
		esac
	else
		fail log "tools/call aafm-get-pages did not run"
	fi
	W eval 'for ( $i = 1; $i <= 24; $i++ ) { aafm_log_activity( array( "ability" => ( 0 === $i % 3 ? "aafm/trash-post" : "aafm/get-posts" ), "status" => ( 0 === $i % 4 ? "denied" : "success" ), "principal_login" => ( 0 === $i % 2 ? "ana" : "bo" ), "detail" => "smoke-" . $i ) ); }' >/dev/null 2>&1
	parity=$(W eval '
		wp_set_current_user( 1 );
		$sets = array( array( "status" => "denied" ), array( "user" => "ana", "tool" => "aafm/trash-post" ), array( "q" => "smoke-1" ), array( "from" => gmdate( "Y-m-d" ), "status" => "success" ) );
		$bad  = array();
		foreach ( $sets as $f ) {
			$count = aafm_activity_count_matching( $f );
			$rows  = aafm_query_activity( array_merge( $f, array( "per_page" => 200 ) ) );
			ob_start();
			aafm_export_activity_csv( $f );
			$csv   = (string) ob_get_clean();
			$lines = count( array_filter( explode( "\n", trim( $csv ) ) ) ) - 1;
			if ( $count !== count( $rows ) || $count !== $lines ) {
				$bad[] = wp_json_encode( $f ) . ":" . $count . "/" . count( $rows ) . "/" . $lines;
			}
		}
		echo $bad ? "BAD " . implode( " ", $bad ) : "OK";' 2>/dev/null)
	if [ "$parity" = OK ]; then
		pass log "filtered count, table query and CSV agree on four filter sets"
	else
		fail log "filtered count, table and CSV disagree: $parity"
	fi
	admin_login "$jar"
	for q in 'tab=activity' 'tab=activity&filter=denied&q=smoke&from=2020-01-01&user=ana&plugin=core&conn=none' 'tab=activity&row=1' 'tab=activity&q=%25%5C%27%22'; do
		code=$(curl -s --max-time 60 -b "$jar" -o "$SMOKE_ROOT/page.html" -w '%{http_code}' "$URL/wp-admin/admin.php?page=agent-abilities-for-mcp&$q")
		hits=$(grep -c -i -E "$BAD_BODY" "$SMOKE_ROOT/page.html")
		if [ "$code" = 200 ] && [ "$hits" = 0 ] && grep -q 'id="aafm-log-summary"' "$SMOKE_ROOT/page.html"; then
			pass log "Activity tab ?$q answers 200 with the summary card and no error text"
		else
			fail log "Activity tab ?$q: HTTP $code, $hits error strings, summary card present: $(grep -c 'id="aafm-log-summary"' "$SMOKE_ROOT/page.html")"
		fi
	done
	log_ok log 'Activity Log'
}

# leg_logscale: 50,000 rows, time every filter class and the 7-day summary, and the v6 upgrade itself.
# Soft limit 1000 ms (KNOWN), hard limit 5000 ms (FAIL). The disposable database only.
leg_logscale() {
	local out name ms
	set_state logscale 'ours only' "[\"$OURS\"]" true 0 || return 0
	out=$(W eval '
		global $wpdb;
		$t = aafm_activity_log_table();
		aafm_clear_activity_log();
		for ( $b = 0; $b < 50; $b++ ) {
			$vals = array();
			for ( $i = 0; $i < 1000; $i++ ) {
				$vals[] = $wpdb->prepare( "(%s,%d,%s,%s,%s,%s,%s,%s,%s,%s)", "aafm/tool-" . mt_rand( 0, 149 ), 1, "user" . mt_rand( 0, 4 ), array( "success", "error", "denied" )[ mt_rand( 0, 2 ) ], "post_id", "203.0.113.9", mt_rand( 0, 1 ) ? "client-a" : "", "ability_call", "scale seed", gmdate( "Y-m-d H:i:s", time() - mt_rand( 0, 30 * 86400 ) ) );
			}
			$wpdb->query( "INSERT INTO {$t} (ability,principal_user_id,principal_login,status,arg_keys,source_ip,client_id,event_type,detail,created_at) VALUES " . implode( ",", $vals ) );
		}
		$sets = array(
			"none"   => array(),
			"status" => array( "status" => "denied" ),
			"tool"   => array( "tool" => "aafm/tool-7" ),
			"user"   => array( "user" => "user3" ),
			"conn"   => array( "conn" => "client-a" ),
			"date"   => array( "from" => gmdate( "Y-m-d", time() - 3 * 86400 ) ),
			"q"      => array( "q" => "tool-7" ),
			"combo"  => array( "user" => "user2", "status" => "error", "from" => gmdate( "Y-m-d", time() - 10 * 86400 ) ),
		);
		foreach ( $sets as $name => $f ) {
			$t0 = microtime( true );
			aafm_activity_count_matching( $f );
			aafm_query_activity( array_merge( $f, array( "per_page" => 50 ) ) );
			echo $name . "=" . round( ( microtime( true ) - $t0 ) * 1000 ) . "\n";
		}
		$t0 = microtime( true );
		aafm_activity_summary( true );
		echo "summary=" . round( ( microtime( true ) - $t0 ) * 1000 ) . "\n";
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i DROP COLUMN duration_ms", $t ) );
		$t0 = microtime( true );
		aafm_install_activity_log();
		echo "upgrade=" . round( ( microtime( true ) - $t0 ) * 1000 ) . "\n";
		echo "schema_ok=" . ( aafm_activity_log_schema_verify() ? "1" : "0" ) . "\n";' 2>/dev/null)
	if [ -z "$out" ]; then
		fail logscale "the 50,000-row seed and timing run produced no output"
		return 0
	fi
	while IFS== read -r name ms; do
		[ -n "$name" ] || continue
		if [ "$name" = schema_ok ]; then
			if [ "$ms" = 1 ]; then pass logscale "schema verifies after the timed v6 upgrade"; else fail logscale "schema did not verify after the timed upgrade"; fi
		elif [ "$ms" -gt 5000 ] 2>/dev/null; then
			fail logscale "$name took ${ms} ms on 50,000 rows (hard limit 5000)"
		elif [ "$ms" -gt 1000 ] 2>/dev/null; then
			known logscale "$name took ${ms} ms on 50,000 rows (soft limit 1000)"
		else
			pass logscale "$name took ${ms} ms on 50,000 rows"
		fi
	done <<EOF
$out
EOF
	log_ok logscale '50,000-row log'
}

# leg_notify: real flips send real (captured) mail, throttle holds, the digest cron sends once, the
# admin notice shows, and WP_DEBUG stays clean. Mail is captured by a mu-plugin; nothing leaves the box.
leg_notify() {
	local mailfile="$SMOKE_ROOT/wp-content/mail.jsonl" mu="$SMOKE_ROOT/wp-content/mu-plugins/smoke-mail.php" jar="$SMOKE_ROOT/jar.txt" n subj
	mkdir -p "$SMOKE_ROOT/wp-content/mu-plugins"
	set_state notify 'ours only' "[\"$OURS\"]" true 0 || return 0
	cat > "$mu" <<'PHP'
<?php
// Capture instead of send. Smoke gate only.
add_filter( 'pre_wp_mail', function ( $pre, $atts ) {
	file_put_contents( WP_CONTENT_DIR . '/mail.jsonl', wp_json_encode( array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'body' => $atts['message'], 'headers' => $atts['headers'] ) ) . "\n", FILE_APPEND );
	return true;
}, 10, 2 );
PHP
	rm -f "$mailfile"
	W eval 'delete_option( "aafm_notify_pending" ); delete_option( "aafm_notify_queue" ); foreach ( array( "aafm_ntf_global" ) as $t ) { delete_transient( $t ); }' >/dev/null 2>&1
	W option update aafm_notifications '{"mode":"immediate","email":"owner@example.com","triggers":{"new_app":true,"risky_run":true,"denial_burst":true,"switch_flip":true}}' --format=json >/dev/null 2>&1

	W eval 'wp_set_current_user( 1 ); $b = aafm_read_only_mode_stored(); aafm_set_read_only_mode( true ); aafm_log_read_only_switch_change( $b, true, true );' >/dev/null 2>&1
	n=$(wc -l < "$mailfile" 2>/dev/null | tr -d ' ')
	subj=$(head -1 "$mailfile" 2>/dev/null | jq -r '.subject' 2>/dev/null)
	if [ "$n" = 1 ] && echo "$subj" | grep -q 'Read-only mode was turned on'; then
		pass notify "a real read-only flip sent one mail: $subj"
	else
		fail notify "after a read-only flip: $n mail(s), subject '$subj'"
	fi
	if head -1 "$mailfile" 2>/dev/null | jq -e '.to == "owner@example.com" and (.body | contains("tab=activity&row=")) and (.body | test("<[a-z]") | not) and (.headers == "" or .headers == [] or .headers == null)' >/dev/null 2>&1; then
		pass notify "the mail goes to the saved address, links the log row, carries no markup and no custom headers"
	else
		fail notify "mail shape: $(head -1 "$mailfile" | head -c 300)"
	fi

	W eval 'wp_set_current_user( 1 ); aafm_set_read_only_mode( false ); aafm_log_read_only_switch_change( true, false, true ); aafm_set_read_only_mode( true ); aafm_log_read_only_switch_change( false, true, true );' >/dev/null 2>&1
	n=$(wc -l < "$mailfile" 2>/dev/null | tr -d ' ')
	if [ "$n" = 2 ]; then
		pass notify "the off flip mailed, the repeated on flip was throttled (2 mails)"
	else
		fail notify "after off then on again: $n mail(s), wanted 2"
	fi
	W eval 'aafm_set_read_only_mode( false );' >/dev/null 2>&1

	admin_login "$jar"
	curl -s --max-time 60 -b "$jar" -o "$SMOKE_ROOT/page.html" "$URL/wp-admin/index.php"
	if grep -q 'View in Activity Log' "$SMOKE_ROOT/page.html"; then
		pass notify "the admin notice shows on the dashboard"
	else
		fail notify "no admin notice on the dashboard after two alerts"
	fi

	W option update aafm_notifications '{"mode":"digest","email":"owner@example.com","triggers":{"new_app":true,"risky_run":true,"denial_burst":true,"switch_flip":true}}' --format=json >/dev/null 2>&1
	W eval 'aafm_notify_sync_schedule(); aafm_log_high_risk_switch_change( false, true, true );' >/dev/null 2>&1
	n=$(wc -l < "$mailfile" 2>/dev/null | tr -d ' ')
	if [ "$n" = 2 ] && [ "$(W eval 'echo count( aafm_notify_queue_list() );' 2>/dev/null)" = 1 ]; then
		pass notify "digest mode queues instead of mailing"
	else
		fail notify "digest mode: $n mail(s), queue '$(W eval 'echo count( aafm_notify_queue_list() );' 2>/dev/null)'"
	fi
	W cron event run aafm_notifications_digest >/dev/null 2>&1
	n=$(wc -l < "$mailfile" 2>/dev/null | tr -d ' ')
	if [ "$n" = 3 ] && tail -1 "$mailfile" | jq -r '.subject' | grep -q 'daily summary' && [ "$(W eval 'echo count( aafm_notify_queue_list() );' 2>/dev/null)" = 0 ]; then
		pass notify "the digest cron event sent one summary and cleared the queue"
	else
		fail notify "digest run: $n mail(s), last '$(tail -1 "$mailfile" | jq -r '.subject' 2>/dev/null)'"
	fi

	W option delete aafm_notifications >/dev/null 2>&1
	W eval 'wp_clear_scheduled_hook( "aafm_notifications_digest" ); delete_option( "aafm_notify_pending" ); delete_option( "aafm_notify_queue" );' >/dev/null 2>&1
	rm -f "$mu" "$mailfile"
	log_ok notify 'Notifications'
}

setup_site || { fail setup "could not build the disposable site at $SMOKE_ROOT"; finish; }
start_server || finish
if [ "$SHOTS" = 1 ]; then shots_mode; finish; fi
leg_matrix
leg_qm
leg_upgrade
leg_default_server
leg_brief
leg_doctor
leg_grants
leg_proxy
leg_log
leg_logscale
leg_notify
finish
