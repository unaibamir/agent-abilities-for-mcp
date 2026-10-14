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
# Env: SMOKE_DEFAULT_SERVER_STRICT=1  default-server leg becomes a hard check.
#      SMOKE_INJECT_FAULT=1           drops a PHP-warning mu-plugin (proves the gate goes red).
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
ADAPTER_URL=https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip
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
# default-server findings are KNOWN until the fix lands; strict mode makes them hard.
ds() { if [ "${SMOKE_DEFAULT_SERVER_STRICT:-0}" = 1 ]; then fail default-server "$1"; else known default-server "$1"; fi; }

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

# Download the standalone adapter once; every leg that needs it asks here.
ADAPTER_STATE=''
fetch_adapter() {
	if [ -z "$ADAPTER_STATE" ]; then
		ADAPTER_STATE=bad
		if curl -fsSL --max-time 60 -o "$SMOKE_ROOT/mcp-adapter.zip" "$ADAPTER_URL" \
			&& unzip -q -o "$SMOKE_ROOT/mcp-adapter.zip" -d "$SMOKE_ROOT/wp-content/plugins" \
			&& [ -f "$SMOKE_ROOT/wp-content/plugins/$ADAPTER" ]; then
			ADAPTER_STATE=ok
		fi
	fi
	[ "$ADAPTER_STATE" = ok ] && return 0
	fail "$1" "download $ADAPTER_URL"
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

leg_default_server() {
	local names="$SMOKE_ROOT/names.txt" base ours leaked msg label plugins want missing t
	fetch_adapter default-server || return 0
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
	# Control: the standalone adapter alone must serve its default server, or a 404 below proves nothing.
	set_state default-server 'control' "[\"$ADAPTER\"]" true 0 || return 0
	if msg=$(tools_list "$ROUTE_DEFAULT" "$names"); then
		pass default-server "control: standalone alone serves /$ROUTE_DEFAULT ($(grep -c . "$names") tools)"
	else
		fail default-server "control: standalone alone: $msg"
	fi
	for label in 'ours first' 'standalone first'; do
		if [ "$label" = 'ours first' ]; then plugins="[\"$OURS\",\"$ADAPTER\"]"; else plugins="[\"$ADAPTER\",\"$OURS\"]"; fi
		set_state default-server "$label" "$plugins" true 0 || continue
		msg=$(tools_list "$ROUTE_DEFAULT" "$names")
		case $? in
			0)
				leaked=$(grep -c '^aafm-' "$names")
				if [ "$leaked" = 0 ]; then
					pass default-server "$label: no aafm- tools on /$ROUTE_DEFAULT ($(grep -c . "$names") tools)"
				else
					ds "$label: $leaked aafm- tools on /$ROUTE_DEFAULT, e.g. $(grep '^aafm-' "$names" | head -3 | tr '\n' ' ')"
				fi
				;;
			3) pass default-server "$label: $msg (rest_no_route), so none of our tools can be listed there" ;;
			*) fail default-server "$label: $msg" ;;
		esac
		if ! msg=$(tools_list "$ROUTE_OURS" "$names"); then
			fail default-server "$label: $msg"
			continue
		fi
		ours=$(grep -c . "$names")
		if [ "$ours" = "$base" ]; then
			pass default-server "$label: our endpoint lists $ours tools, same as ours-only"
		else
			ds "$label: our endpoint lists $ours tools, ours-only lists $base"
		fi
	done
}

setup_site || { fail setup "could not build the disposable site at $SMOKE_ROOT"; finish; }
start_server || finish
leg_matrix
leg_qm
leg_upgrade
leg_default_server
finish
