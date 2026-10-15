#!/usr/bin/env bash
# Pre-release smoke gate. Builds a disposable WordPress site, loads the working tree
# into it in the plugin-order and cache combinations that have bitten us before, and
# asserts on what the HTTP responses and debug.log actually say.
#
#   ddev exec bash /var/www/plugin-src/bin/smoke-gate.sh [--from <version>] [--keep]
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
PAGES=('wp-admin/index.php' 'wp-admin/admin.php?page=agent-abilities-for-mcp' 'wp-admin/plugins.php' '')
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
while [ $# -gt 0 ]; do
	case "$1" in
		--from)
			[ $# -ge 2 ] || { echo "usage: smoke-gate.sh [--from <version>] [--keep]"; exit 2; }
			FROM="$2"
			shift 2
			;;
		--keep)
			KEEP=1
			shift
			;;
		*)
			echo "usage: smoke-gate.sh [--from <version>] [--keep]"
			exit 2
			;;
	esac
done

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
	pkill -f 'php -S localhost:8070' 2>/dev/null
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
	pkill -f 'php -S localhost:8070' 2>/dev/null
	sleep 1
	if [ "$(curl -s --max-time 3 -o /dev/null -w '%{http_code}' "$URL/")" != 000 ]; then
		fail setup "port 8070 busy"
		return 1
	fi
	(php -S localhost:8070 -t "$SMOKE_ROOT" > "$SMOKE_ROOT/server.log" 2>&1 &)
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
mcp_post() {
	local sid=()
	[ -n "${3:-}" ] && sid=(-H "Mcp-Session-Id: $3" -H 'MCP-Protocol-Version: 2025-06-18')
	curl -s --max-time 60 -D "$SMOKE_ROOT/head.txt" -o "$SMOKE_ROOT/body.json" -w '%{http_code}' \
		-u "admin:$APP_PW" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
		${sid[@]+"${sid[@]}"} -X POST "$URL/index.php?rest_route=/$1" -d "$2"
}

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

# pages_ok <leg> <label>: three admin pages and the front page, logged in as the disposable admin.
pages_ok() {
	local jar="$SMOKE_ROOT/jar.txt" p code hits bad=''
	rm -f "$jar"
	curl -s --max-time 60 -c "$jar" -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null \
		-d "log=admin&pwd=admin&wp-submit=Log+In&redirect_to=$URL/wp-admin/&testcookie=1" "$URL/wp-login.php"
	for p in "${PAGES[@]}"; do
		code=$(curl -s --max-time 60 -b "$jar" -o "$SMOKE_ROOT/page.html" -w '%{http_code}' "$URL/$p")
		hits=$(grep -c -i -E "$BAD_BODY" "$SMOKE_ROOT/page.html")
		if [ "$code" != 200 ] || [ "$hits" != 0 ]; then bad="$bad /$p (HTTP $code, $hits error strings)"; fi
	done
	if [ -z "$bad" ]; then
		pass "$1" "$2: 4 pages HTTP 200, no fatal/deprecated text in the body"
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
	[ -L "$dir" ] || rm -rf "$dir"
	ln -sfn "$PLUGIN_SRC" "$dir"
	: > "$LOG"
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

setup_site || { fail setup "could not build the disposable site at $SMOKE_ROOT"; finish; }
start_server || finish
leg_matrix
leg_qm
leg_upgrade
leg_default_server
leg_brief
finish
