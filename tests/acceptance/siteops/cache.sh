#!/usr/bin/env bash
# Per-adapter cache acceptance on the DISPOSABLE site (design 333, section 9.4).
#
#   host:  ddev snapshot --name <name>
#   host:  ddev exec AAFM_ACCEPT_SNAPSHOT=<name> bash /var/www/plugin-src/tests/acceptance/siteops/cache.sh <slug>
#
# <slug> is one registry slug: wp_super_cache wp_fastest_cache w3_total_cache wp_optimize
# cache_enabler hummingbird jetpack_boost speedycache breeze siteground litespeed nginx_helper
# proxy_cache_purge.
#
# It installs ONE cache plugin on the disposable site built by setup.sh, runs every P, N, V1 and W1
# case that applies to it through the real MCP endpoint, prints PASS or FAIL per case, and puts the
# site back as it found it. Exit code 0 only when every case passed. A FAIL is reported to the lead.
# It is never worked around, and this script is never edited to make a case pass.
#
# PHPUnit proves that our code calls the right vendor entry point and reads the right directory.
# This script proves that the real plugin behaves as its adapter expects.
set -u
[ "${IS_DDEV_PROJECT:-}" = true ] || { echo "cache.sh: refusing, not inside the DDEV web container"; exit 2; }

HERE=$(cd "$(dirname "$0")" && pwd)

# ---- Names shared with setup.sh of batch 1. Make these equal to what setup.sh uses. ----
ROOT=/tmp/aafm_accept_siteops
DB=aafm_accept_siteops
PORT=8072
URL=http://localhost:$PORT
APP_PW_FILE=$ROOT/site.env
ADMIN_USER=admin
SNAPSHOT_ROUTE=aafm-accept/v1/snapshot
# ---- End of shared names. ----

VENDOR_SRC=/var/www/plugin-src/wp/wp-content/plugins   # Read only. The free wordpress.org builds the benches used.
CONTENT=$ROOT/wp-content
HOSTPORT=localhost:$PORT
OUT=/tmp/aafm_accept_cache_out
NGX=/tmp/aafm_accept_ngx
HTTP_LOG=$ROOT/aafm-accept-cache-http.log
ERROR_LOG=$ROOT/aafm-accept-cache-errors.log
MCP_ROUTE=agent-abilities-for-mcp/mcp
INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"aafm-accept-cache","version":"1"}}}'

SLUG=${1:-}

# ---------------------------------------------------------------------------------------------
# The expectation table. One row per adapter, restating sections 6.2 to 6.14 and the table in 9.4.
# If a row here and the adapter's section in the design disagree, the design is right and the row
# is the defect: report it, do not edit it.
# Format of P2, P3, P6, P7: "<state>|<reason>".
# ---------------------------------------------------------------------------------------------
declare -A DIR VERSION FILES RECURSIVE P2 P3 P6 P7 WAIT
row() { DIR[$1]=$2; VERSION[$1]=$3; FILES[$1]=$4; RECURSIVE[$1]=$5; P2[$1]=$6; P3[$1]=$7; P7[$1]=$8; P6[$1]=$9; WAIT[$1]=${10}; }
#   slug              plugin directory         version  files recursive  P2                              P3                              P7                              P6                              stale wait
row wp_super_cache    wp-super-cache           3.1.5    1     0          'requested|stale_window'        'requested|not_found_in_cache'  'requested|stale_window'        'purged|'                       11
row wp_fastest_cache  wp-fastest-cache         1.5.5    1     0          'purged|'                       'requested|not_found_in_cache'  'purged|'                       'purged|'                       0
row w3_total_cache    w3-total-cache           2.11.0   1     0          'requested|stale_window'        'requested|not_found_in_cache'  'requested|stale_window'        'requested|stale_window'        31
row wp_optimize       wp-optimize              4.7.1    1     0          'purged|'                       'purged|'                       'purged|'                       'purged|'                       0
row cache_enabler     cache-enabler            1.8.17   1     0          'purged|'                       'requested|not_found_in_cache'  'purged|'                       'purged|'                       0
row hummingbird       hummingbird-performance  4.0.3    1     0          'purged|'                       'requested|not_found_in_cache'  'purged|'                       'purged|'                       0
row jetpack_boost     jetpack-boost            4.8.2    1     1          'purged|'                       'requested|not_found_in_cache'  'purged|'                       'purged|'                       0
row speedycache       speedycache              1.4.3    1     0          'purged|'                       'requested|not_found_in_cache'  'purged|'                       'purged|'                       0
row breeze            breeze                   2.6.5    1     0          'purged|'                       'requested|not_found_in_cache'  'purged|'                       'purged|'                       0
row siteground        sg-cachepress            7.8.6    1     0          'purged|'                       'requested|not_found_in_cache'  'purged|'                       'purged|'                       0
row litespeed         litespeed-cache          7.9.3    0     0          'requested|no_acknowledgement'  'requested|no_acknowledgement'  'requested|no_acknowledgement'  'requested|no_acknowledgement'  0
row nginx_helper      nginx-helper             2.4.1    1     0          'purged|'                       'requested|not_found_in_cache'  'purged|'                       'purged|'                       0
row proxy_cache_purge varnish-http-purge       5.12.3   0     0          'requested|proxy_answered'      'requested|proxy_answered'      'requested|proxy_answered'      'requested|proxy_answered'      0
# The ten adapters that name their cache directory by this site's host (case N11).
HOST_NAMED=' wp_super_cache wp_fastest_cache w3_total_cache wp_optimize cache_enabler hummingbird jetpack_boost speedycache breeze siteground '
# The adapter whose plugin fetches a purged page again at the end of the purging request, so a
# fresh copy is on disk when the tool's answer arrives (design 6.5). Its checks count only copies
# older than the call (left_count), and its P3 finds that fresh copy.
RECACHES=' wp_optimize '
# The adapters whose variants the benches ran (case V1).
HAS_VARIANTS=' wp_super_cache wp_optimize cache_enabler hummingbird breeze w3_total_cache speedycache '

# ---------------------------------------------------------------------------------------------
# Refusals. Each one stops the run before anything is touched.
# ---------------------------------------------------------------------------------------------
die() { echo "cache.sh: $*"; exit 2; }
[ "${IS_DDEV_PROJECT:-}" = true ] || die "refusing, not inside the DDEV web container"
[ -n "$SLUG" ] && [ -n "${DIR[$SLUG]:-}" ] || die "usage: cache.sh <slug>; unknown or missing slug '${SLUG}'"
[ -n "${AAFM_ACCEPT_SNAPSHOT:-}" ] || die "refusing, AAFM_ACCEPT_SNAPSHOT is empty. Take 'ddev snapshot --name <name>' on the host first and pass that name"
case "$ROOT" in /tmp/aafm_accept_siteops) ;; *) die "refusing, ROOT is not the disposable site" ;; esac
[ -f "$ROOT/wp-config.php" ] || die "the disposable site does not exist. Run setup.sh first"
grep -q "define( *'DB_NAME', *'$DB' *)" "$ROOT/wp-config.php" || die "refusing, $ROOT/wp-config.php does not name database $DB"
[ "$DB" != db ] || die "refusing, DB is the main site's database"
W() { wp --allow-root --path="$ROOT" "$@"; }
[ "$(W option get siteurl 2>/dev/null)" = "$URL" ] || die "refusing, the site at $ROOT is not $URL"
[ -s "$APP_PW_FILE" ] || die "no Application Password file at $APP_PW_FILE. Run setup.sh first"
grep -l -q 'pre_http_request' "$CONTENT"/mu-plugins/*.php 2>/dev/null || die "the outbound logger of setup.sh is not installed in $CONTENT/mu-plugins"
[ -d "$VENDOR_SRC/${DIR[$SLUG]}" ] || die "no copy of ${DIR[$SLUG]} in $VENDOR_SRC"
grep -r -q -i -E "^[ */]*Version: +${VERSION[$SLUG]//./\\.}\b" "$VENDOR_SRC/${DIR[$SLUG]}"/*.php || die "${DIR[$SLUG]} in $VENDOR_SRC is not version ${VERSION[$SLUG]}, the version the adapter was benched on"
APP_PW=$(sed -n "s/^ACC_APP_PW='\(.*\)'\$/\1/p" "$APP_PW_FILE")
mkdir -p "$OUT"

# ---------------------------------------------------------------------------------------------
# Reporting.
# ---------------------------------------------------------------------------------------------
PASSED=0; FAILED=0; SKIPPED=0
pass() { PASSED=$((PASSED + 1)); echo "PASS  $1  $2"; }
fail() { FAILED=$((FAILED + 1)); echo "FAIL  $1  $2"; }
skip() { SKIPPED=$((SKIPPED + 1)); echo "n/a   $1  $2"; }
note() { echo "      $*"; }
# check <case> <what> <expected> <actual>
check() { if [ "$3" = "$4" ]; then pass "$1" "$2: $4"; else fail "$1" "$2: expected '$3', got '$4'"; fi; }

# ---------------------------------------------------------------------------------------------
# The disposable site: reset, server, vendor plugin.
# ---------------------------------------------------------------------------------------------
serve() {
	pkill -f "[p]hp -S 0.0.0.0:$PORT" 2>/dev/null; sleep 1
	(cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 setsid nohup php -S 0.0.0.0:$PORT -t "$ROOT" > "$ROOT/server.log" 2>&1 < /dev/null &)
	sleep 2
	[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/")" = 200 ] || die "the disposable site does not answer at $URL after a restart"
}

# Everything a cache plugin leaves behind: drop-ins, cache folders, config folders, wp-config edits.
reset_site() {
	[ -f "$NGX/nginx.pid" ] && nginx -c "$NGX/nginx.conf" -s quit 2>/dev/null
	pkill -f '[p]roxy-listener.php' 2>/dev/null
	local d
	for d in "${DIR[@]}"; do
		W plugin is-installed "$d" 2>/dev/null && W plugin deactivate "$d" --quiet 2>/dev/null
		rm -rf "${CONTENT:?}/plugins/$d"
	done
	( cd "$CONTENT" && ls -A | grep -v -E '^(plugins|themes|uploads|languages|upgrade|mu-plugins|index\.php|.*\.log|aafm-accept.*)$' | xargs -r rm -rf )
	rm -f "$CONTENT/mu-plugins/aafm-accept-cache.php" "$ROOT/.htaccess" "$ROOT/.user.ini" "$ROOT/aafm-accept-litespeed" "$ROOT/aafm-accept-redirect"
	rm -rf "$NGX" "$OUT"/*
	[ -f "$ROOT/wp-config.pristine.php" ] && cp "$ROOT/wp-config.pristine.php" "$ROOT/wp-config.php"
	W cron event list --fields=hook --format=csv 2>/dev/null | grep -i -E 'cache|preload|preheat|siteground|speedy|breeze|w3tc|wphb|boost|nginx|varnish|litespeed|wpo_' | sort -u | while read -r hook; do W cron event delete "$hook" --quiet 2>/dev/null; done
	serve
}

# Start from a clean site, also when an earlier run died half way. The first run on a fresh site
# keeps a copy of wp-config.php from before any cache plugin edited it.
if [ ! -f "$ROOT/wp-config.pristine.php" ]; then
	grep -q -E "define\( *'WP_CACHE'|advanced-cache" "$ROOT/wp-config.php" && die "wp-config.php already carries a cache plugin's edit and there is no pristine copy. Run teardown.sh and setup.sh again"
	cp "$ROOT/wp-config.php" "$ROOT/wp-config.pristine.php"
fi
trap 'echo; echo "cleaning up"; reset_site >/dev/null 2>&1' EXIT
reset_site
ACTIVE=$(W plugin list --status=active --field=name 2>/dev/null | grep -v -x 'agent-abilities-for-mcp' | tr '\n' ' ')
[ -z "${ACTIVE// /}" ] || die "refusing, other plugins are active on the disposable site: $ACTIVE"

cp "$HERE/cache-mu.php" "$CONTENT/mu-plugins/aafm-accept-cache.php"
: > "$HTTP_LOG"; : > "$ERROR_LOG"

LIB() { W eval-file "$HERE/cache-lib.php" "$@" 2>&1 | grep -v -E '^(Warning|Notice|Deprecated|PHP )' ; }
PATHS() { php "$HERE/cache-paths.php" "$1" "$SLUG" "$CONTENT" "$HOSTPORT" "$2"; }

enable_caching() {
	case "$SLUG" in
		w3_total_cache)
			W w3-total-cache option set pgcache.enabled true --type=boolean >/dev/null 2>&1
			W w3-total-cache option set pgcache.engine file_generic >/dev/null 2>&1
			W w3-total-cache fix_environment >/dev/null 2>&1 ;;
		litespeed)
			W litespeed-option set cache 1 >/dev/null 2>&1
			touch "$ROOT/aafm-accept-litespeed" ;;
		nginx_helper)
			W config set RT_WP_NGINX_HELPER_CACHE_PATH "$NGX/cache" --quiet
			LIB enable "$SLUG" >/dev/null ;;
		*) LIB enable "$SLUG" >/dev/null ;;
	esac
}
disable_caching() {
	case "$SLUG" in
		w3_total_cache) W w3-total-cache option set pgcache.enabled false --type=boolean >/dev/null 2>&1 ;;
		litespeed) : ;;   # Off is the request without the X-LSCACHE header.
		*) LIB disable "$SLUG" >/dev/null ;;
	esac
}

echo "== $SLUG: ${DIR[$SLUG]} ${VERSION[$SLUG]} on $URL (snapshot ${AAFM_ACCEPT_SNAPSHOT})"
cp -a "$VENDOR_SRC/${DIR[$SLUG]}" "$CONTENT/plugins/" || die "could not copy the plugin"
W plugin activate "${DIR[$SLUG]}" --quiet 2>&1 | grep -v -E '^(Warning|Notice|Deprecated|PHP |Plugin|Success)' | head -3
W plugin is-active "${DIR[$SLUG]}" || die "${DIR[$SLUG]} did not activate"

if [ "$SLUG" = nginx_helper ]; then
	mkdir -p "$NGX/cache" "$NGX/t"
	cat > "$NGX/nginx.conf" <<NGINX
# Acceptance-only nginx: own pid, logs, temp and cache under $NGX. Listens on 127.0.0.1:8073 and
# proxies to the disposable site's php -S. From scratchpad/1130-siteops/bench/cache2/ngx.conf.
worker_processes 1;
pid $NGX/nginx.pid;
error_log $NGX/error.log;
events { worker_connections 64; }
http {
	access_log off;
	client_body_temp_path $NGX/t/1; proxy_temp_path $NGX/t/2; fastcgi_temp_path $NGX/t/3; uwsgi_temp_path $NGX/t/4; scgi_temp_path $NGX/t/5;
	proxy_cache_path $NGX/cache levels=1:2 keys_zone=AAFM:1m inactive=60m;
	server {
		listen 127.0.0.1:8073;
		location / {
			proxy_pass http://127.0.0.1:$PORT;
			proxy_set_header Host $HOSTPORT;
			proxy_cache AAFM;
			proxy_cache_key "\$scheme\$request_method\$host\$request_uri";
			proxy_cache_valid 200 60m;
			proxy_ignore_headers Cache-Control Expires Set-Cookie;
			add_header X-Accept-Cache \$upstream_cache_status;
		}
	}
}
NGINX
	nginx -t -c "$NGX/nginx.conf" >/dev/null 2>&1 || die "the acceptance nginx config does not parse"
	nginx -c "$NGX/nginx.conf" || die "the acceptance nginx did not start"
fi
if [ "$SLUG" = proxy_cache_purge ]; then
	(setsid nohup php "$HERE/proxy-listener.php" "$OUT/proxy.log" "$OUT/proxy.code" > "$OUT/proxy-server.log" 2>&1 < /dev/null &)
	sleep 1
fi
enable_caching
serve

# ---------------------------------------------------------------------------------------------
# Fixtures (created by setup.sh): slug -> id, url, path.
# ---------------------------------------------------------------------------------------------
LIB fixtures | tail -1 > "$OUT/fixtures.json"
jq -e 'type == "object"' "$OUT/fixtures.json" >/dev/null 2>&1 || die "could not read the fixtures of the disposable site"
fx() { jq -r --arg k "$1" --arg f "$2" '.[$k][$f] // empty' "$OUT/fixtures.json"; }
for k in acc-a acc-far acc-page acc-parent acc-child acc-var café-été नमस्ते; do
	[ -n "$(fx "$k" id)" ] || die "fixture '$k' is missing. setup.sh creates it"
done

# ---------------------------------------------------------------------------------------------
# MCP calls. One tool call is an initialize followed by a tools/call in that session.
# MCP_QUERY is appended to the endpoint URL (case N6). MCP_HEADERS are extra request headers.
# ---------------------------------------------------------------------------------------------
MCP_QUERY=''
MCP_HEADERS=()
[ "$SLUG" = litespeed ] && MCP_HEADERS=(-H 'X-LSCACHE: on')
mcp_post() {
	local sid=()
	[ -n "${2:-}" ] && sid=(-H "Mcp-Session-Id: $2" -H 'MCP-Protocol-Version: 2025-06-18')
	curl -s --max-time 120 -D "$OUT/head.txt" -o "$OUT/body.json" -w '%{http_code}' -u "$ADMIN_USER:$APP_PW" \
		-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
		${sid[@]+"${sid[@]}"} ${MCP_HEADERS[@]+"${MCP_HEADERS[@]}"} \
		-X POST "$URL/index.php?rest_route=/$MCP_ROUTE$MCP_QUERY" -d "$1"
}
HTTP_CODE=''
# tool <tool name> <arguments json>. The answer is left in $OUT/body.json, the HTTP status in HTTP_CODE.
tool() {
	local sid
	: > "$ERROR_LOG"
	touch "$OUT/mark"
	HTTP_CODE=$(mcp_post "$INIT")
	[ "$HTTP_CODE" = 200 ] || return 1
	sid=$(grep -i '^mcp-session-id:' "$OUT/head.txt" | tr -d '\r' | awk '{print $2}')
	HTTP_CODE=$(mcp_post "$(jq -nc --arg n "$1" --argjson a "$2" '{jsonrpc:"2.0",id:3,method:"tools/call",params:{name:$n,arguments:$a}}')" "$sid")
	[ "$HTTP_CODE" = 200 ]
}
# ran: the last tool call returned a result that is not an error.
ran() { jq -e '.jsonrpc == "2.0" and (.result | type) == "object" and (.result.isError != true)' "$OUT/body.json" >/dev/null 2>&1; }
# refused: the last tool call was refused (a tool error or a JSON-RPC error).
refused() { jq -e '(.error | type) == "object" or .result.isError == true' "$OUT/body.json" >/dev/null 2>&1; }
# refused_with <code>: refused, and the tool created a WP_Error with that code during the call.
refused_with() { refused && grep -q -x "$1" "$ERROR_LOG"; }
# adapter_row: "<state>|<reason>" of this adapter in the last purge result, or "absent".
adapter_row() { jq -r --arg s "$SLUG" '[.result.structuredContent.results[]? | select(.provider == $s) | "\(.state)|\(.reason)"][0] // "absent"' "$OUT/body.json"; }
out_field() { jq -r --arg f "$1" '.result.structuredContent[$f] // empty' "$OUT/body.json"; }
answer() { jq -c '.result.structuredContent // .result.content // .error' "$OUT/body.json" 2>/dev/null | cut -c1-300; }

purge_post() { tool aafm-site-purge-post-cache "$(jq -nc --argjson id "$1" '{post_id:$id}')"; }
purge_url() { tool aafm-site-purge-url-cache "$(jq -nc --arg u "$1" '{url:$u}')"; }
purge_site() { tool aafm-site-purge-site-cache '{}'; }

# The outbound log is cut per case. Everything cut is kept for case N9.
rotate_http_log() { cat "$HTTP_LOG" >> "$OUT/http-all.log" 2>/dev/null; : > "$HTTP_LOG"; }

reset_budget() { W db query "TRUNCATE TABLE $(W eval 'echo aafm_activity_log_table();')" >/dev/null 2>&1; }

# ---------------------------------------------------------------------------------------------
# Cache files.
# ---------------------------------------------------------------------------------------------
live() { PATHS live "$(fx "$1" path)"; }
live_count() { live "$1" | grep -c . ; }
# left_count <key>: live cache files of the page that are not newer than the start of the last tool
# call. For a RECACHES adapter a newer file is the plugin's own fresh copy, not a leftover; for
# every other adapter this is live_count.
left_count() {
	if [[ "$RECACHES" == *" $SLUG "* ]]; then
		live "$1" | while read -r f; do [ -n "$(find "$f" -newer "$OUT/mark" 2>/dev/null)" ] || echo "$f"; done | grep -c .
	else
		live_count "$1"
	fi
}
stale_count() { PATHS stale "$(fx "$1" path)" | grep -c . ; }
warm() {
	local urls=() k
	for k in "$@"; do urls+=("$(fx "$k" url)"); done
	if [ "$SLUG" = nginx_helper ]; then
		bash "$HERE/warm.sh" --quiet --via http://127.0.0.1:8073 --host localhost "${urls[@]}" >/dev/null
	else
		bash "$HERE/warm.sh" --quiet "${urls[@]}" >/dev/null
	fi
}
# ensure_warm <key>...: warm, and where the adapter keeps files, make sure a live copy exists. Two
# plugins rename a purged copy and will not write a new one until their stale window has passed.
ensure_warm() {
	local k try
	warm "$@"
	[ "${FILES[$SLUG]}" = 1 ] || return 0
	for k in "$@"; do
		for try in 1 2; do
			[ "$(live_count "$k")" -gt 0 ] && break
			sleep "$(( ${WAIT[$SLUG]} > 0 ? ${WAIT[$SLUG]} : 1 ))"
			warm "$k"
		done
		[ "$(live_count "$k")" -gt 0 ] || { echo "could not warm $k"; return 1; }
	done
}
ALL_KEYS=(acc-a acc-far acc-page acc-parent acc-child)
total_live() { local n=0 k; for k in "${ALL_KEYS[@]}"; do n=$((n + $(live_count "$k"))); done; echo "$n"; }

# Canaries (N1, N4): one in wp-content, one beside the adapter's cache base.
BASE=$(PATHS base /)
CANARIES=("$CONTENT/aafm-accept-canary.txt")
[ -n "$BASE" ] && [ -d "$(dirname "$BASE")" ] && CANARIES+=("$(dirname "$BASE")/aafm-accept-canary.txt")
for c in "${CANARIES[@]}"; do echo "aafm acceptance canary" > "$c"; done
canaries_ok() { local c; for c in "${CANARIES[@]}"; do [ "$(cat "$c" 2>/dev/null)" = "aafm acceptance canary" ] || return 1; done; }

# ---------------------------------------------------------------------------------------------
# P1  The adapter is listed as serving.
# ---------------------------------------------------------------------------------------------
ensure_warm "${ALL_KEYS[@]}" || fail P1 "the fixtures could not be warmed: no cache file appeared where section 6 says the plugin keeps it"
tool aafm-site-cache-status '{}'
check P1 "status of $SLUG" serving "$(jq -r --arg s "$SLUG" '[.result.structuredContent.providers[]? | select(.provider == $s) | .status][0] // "absent"' "$OUT/body.json")"

# ---------------------------------------------------------------------------------------------
# P2  First purge of a warmed ASCII post.   P3  The same call again.
# ---------------------------------------------------------------------------------------------
reset_budget
ensure_warm acc-a acc-page acc-far || fail P2 "could not warm the fixtures"
purge_post "$(fx acc-a id)"
check P2 "row" "${P2[$SLUG]}" "$(adapter_row)"
if [ "${FILES[$SLUG]}" = 1 ]; then
	check P2 "live cache files left for acc-a" 0 "$(left_count acc-a)"
	[ "$(live_count acc-page)" -gt 0 ] && pass P2 "the survivor acc-page is still cached" || fail P2 "acc-page lost its cache file"
	if [ "$SLUG" = wp_fastest_cache ]; then
		[ "$(live_count acc-far)" -gt 0 ] && pass P2 "the far post acc-far is still cached" || fail P2 "acc-far lost its cache file"
	fi
fi
if [[ "$RECACHES" == *" $SLUG "* ]]; then
	[ "$(live_count acc-a)" -gt 0 ] && pass P3 "the plugin cached acc-a again at the end of the first purge" || fail P3 "no fresh copy of acc-a after the first purge, so the plugin's own fetch did not happen"
fi
purge_post "$(fx acc-a id)"
check P3 "row" "${P3[$SLUG]}" "$(adapter_row)"
[[ "$RECACHES" == *" $SLUG "* ]] || [ "$(adapter_row)" != 'purged|' ] || fail P3 "a second purge with nothing cached must never be purged"

# ---------------------------------------------------------------------------------------------
# P4  A URL purge, with and without the final slash.
# ---------------------------------------------------------------------------------------------
reset_budget
PARENT=$(fx acc-parent url)
ensure_warm acc-parent acc-child || fail P4 "could not warm the parent and child pages"
purge_url "$PARENT"; FIRST_URL=$(out_field url); FIRST_ROW=$(adapter_row)
if [ "${FILES[$SLUG]}" = 1 ]; then
	check P4 "live cache files left for the parent (with slash)" 0 "$(left_count acc-parent)"
	if [ "${RECURSIVE[$SLUG]}" = 1 ]; then
		check P4 "live cache files left for the child (this plugin also removes pages under the URL)" 0 "$(live_count acc-child)"
	else
		[ "$(live_count acc-child)" -gt 0 ] && pass P4 "the child page is still cached" || fail P4 "the child page lost its cache file"
	fi
fi
ensure_warm acc-parent acc-child || fail P4 "could not warm the parent and child pages a second time"
purge_url "${PARENT%/}"
check P4 "canonical url, with slash" "$PARENT" "$FIRST_URL"
check P4 "canonical url, without slash" "$PARENT" "$(out_field url)"
check P4 "row, with slash" "${P2[$SLUG]}" "$FIRST_ROW"
check P4 "row, without slash" "${P2[$SLUG]}" "$(adapter_row)"
[ "${FILES[$SLUG]}" = 1 ] && check P4 "live cache files left for the parent (without slash)" 0 "$(left_count acc-parent)"

# ---------------------------------------------------------------------------------------------
# P5  The home URL, given as the bare origin, is not a whole-site purge.
# ---------------------------------------------------------------------------------------------
reset_budget
ensure_warm acc-page || fail P5 "could not warm acc-page"
purge_url "$URL"
if ran; then pass P5 "the bare origin is accepted: $(adapter_row)"; else fail P5 "the bare origin was refused: $(answer)"; fi
check P5 "canonical url" "$URL/" "$(out_field url)"
if [ "$SLUG" = siteground ]; then
	check P5 "row" 'skipped|unsupported_operation' "$(adapter_row)"
fi
if [ "${FILES[$SLUG]}" = 1 ]; then
	[ "$(live_count acc-page)" -gt 0 ] && pass P5 "acc-page is still cached" || fail P5 "the home URL emptied the cache"
fi

# ---------------------------------------------------------------------------------------------
# P6  The whole-site purge, then again at once.
# ---------------------------------------------------------------------------------------------
reset_budget
ensure_warm "${ALL_KEYS[@]}" || fail P6 "could not warm the fixtures"
purge_site
check P6 "row" "${P6[$SLUG]}" "$(adapter_row)"
[ "${FILES[$SLUG]}" = 1 ] && check P6 "live cache files left for all fixtures" 0 "$(total_live)"
purge_site
if refused_with aafm_site_purge_cooldown; then pass P6 "the second call is refused by the cooldown"; else fail P6 "the second call was not refused with aafm_site_purge_cooldown: $(answer)"; fi

# ---------------------------------------------------------------------------------------------
# P7  Non-ASCII posts warmed under both spellings.
# ---------------------------------------------------------------------------------------------
for k in café-été नमस्ते; do
	reset_budget
	if [ "$SLUG" = nginx_helper ]; then
		bash "$HERE/warm.sh" --quiet --both-spellings --via http://127.0.0.1:8073 --host localhost "$(fx "$k" url)" >/dev/null
	else
		bash "$HERE/warm.sh" --quiet --both-spellings "$(fx "$k" url)" >/dev/null
	fi
	if [ "${FILES[$SLUG]}" = 1 ] && [ "$(live_count "$k")" -eq 0 ]; then
		sleep "$(( ${WAIT[$SLUG]} > 0 ? ${WAIT[$SLUG]} : 1 ))"
		bash "$HERE/warm.sh" --quiet --both-spellings "$(fx "$k" url)" >/dev/null
	fi
	[ "${FILES[$SLUG]}" = 0 ] || [ "$(live_count "$k")" -gt 0 ] || fail P7 "$k: no cache file appeared under either spelling"
	purge_post "$(fx "$k" id)"
	check P7 "$k: row" "${P7[$SLUG]}" "$(adapter_row)"
	[ "${FILES[$SLUG]}" = 1 ] && check P7 "$k: live cache files left under either spelling" 0 "$(left_count "$k")"
done
if [ "$SLUG" = nginx_helper ]; then
	# Settles needs-bench item N10: no cache file may still carry the page's key in either spelling.
	LEFT=$(grep -a -r -l -i -E 'KEY: httpGETlocalhost/(caf%c3%a9-%c3%a9t%c3%a9|%e0%a4%a8)' "$NGX/cache" 2>/dev/null | wc -l)
	check P7 "nginx cache files that still carry a non-ASCII page's key" 0 "$LEFT"
fi

# ---------------------------------------------------------------------------------------------
# N1 to N5  Input that must be refused before any cache plugin is called.
# ---------------------------------------------------------------------------------------------
# Plant the canaries again: the whole-site purge of P6 empties the vendor's own cache folder, the canary beside the base with it.
for c in "${CANARIES[@]}"; do echo "aafm acceptance canary" > "$c"; done
reset_budget
ensure_warm "${ALL_KEYS[@]}" || fail N1 "could not warm the fixtures"
BEFORE=$(total_live)
bad_url() { # bad_url <case> <label> <url>
	purge_url "$3"
	if refused_with aafm_site_bad_url; then pass "$1" "$2 is refused"; else fail "$1" "$2 was not refused with aafm_site_bad_url: $(answer)"; fi
}
bad_url N1 'a /../ step' "$URL/acc-parent/../acc-a/"
bad_url N2 'a * wildcard' "$URL/acc-*/"
bad_url N2 'a query string' "$URL/acc-a/?a=1"
bad_url N2 'a [ character' "$URL/acc-[a]/"
bad_url N2 'a { character' "$URL/acc-{a}/"
bad_url N2 'a segment with two dots' "$URL/a..b/"
bad_url N3 'another host' "http://evil.example/acc-a/"
bad_url N3 'another port' "http://localhost:9999/acc-a/"
bad_url N3 'user information' "http://user@$HOSTPORT/acc-a/"
bad_url N4 'an encoded .. step' "$URL/%2e%2e/acc-a/"
bad_url N4 'a double encoded .. step' "$URL/%252e%252e/acc-a/"
DRAFT=$(W post create --post_title='acc draft' --post_status=draft --porcelain 2>/dev/null | head -n 1)
PRIVATE=$(W post create --post_title='acc private' --post_status=private --porcelain 2>/dev/null | head -n 1)
tool aafm-site-purge-post-cache '{"post_id":0}'
if refused; then pass N5 "post id 0 is refused"; else fail N5 "post id 0 was not refused: $(answer)"; fi
for pair in "999999:a missing id" "$DRAFT:a draft" "$PRIVATE:a private post"; do
	purge_post "${pair%%:*}"
	if refused_with aafm_site_post_not_public; then pass N5 "${pair#*:} is refused"; else fail N5 "${pair#*:} was not refused with aafm_site_post_not_public: $(answer)"; fi
done
W post delete "$DRAFT" "$PRIVATE" --force --quiet 2>/dev/null
[ "${FILES[$SLUG]}" = 1 ] && check N1-N5 "live cache files of the fixtures after all refusals" "$BEFORE" "$(total_live)"
if canaries_ok; then pass N1-N5 "both canary files are untouched"; else fail N1-N5 "a canary file was changed or removed"; fi
if grep -q -E 'evil\.example|localhost:9999' "$HTTP_LOG" "$OUT/http-all.log" 2>/dev/null; then fail N3 "a request to the foreign host or port was attempted"; else pass N3 "no request to the foreign host or port"; fi

# ---------------------------------------------------------------------------------------------
# N6  Request parameters must not reach the vendor.
# ---------------------------------------------------------------------------------------------
reset_budget
ensure_warm acc-a acc-far acc-page || fail N6 "could not warm the fixtures"
MCP_QUERY="&action=wpfc_delete_cache&post_id=$(fx acc-far id)&url=$(jq -rn --arg u "$(fx acc-far url)" '$u|@uri')"
[ "$SLUG" = proxy_cache_purge ] || MCP_QUERY="$MCP_QUERY&vhp_flush_all=1"
purge_post "$(fx acc-a id)"
MCP_QUERY=''
check N6 "row" "${P2[$SLUG]}" "$(adapter_row)"
check N6 "the result names the post that was asked for" "$(fx acc-a id)" "$(out_field post_id)"
if [ "${FILES[$SLUG]}" = 1 ]; then
	check N6 "live cache files left for acc-a" 0 "$(left_count acc-a)"
	SURVIVOR=acc-far; [ "$SLUG" = wp_fastest_cache ] && SURVIVOR=acc-page
	[ "$(live_count "$SURVIVOR")" -gt 0 ] && pass N6 "$SURVIVOR, named only in the query string, is still cached" || fail N6 "$SURVIVOR lost its cache file"
fi
if [ "$SLUG" = proxy_cache_purge ]; then
	# The stated limit of design 4.4: the vendor reads this parameter before any tool runs.
	MCP_QUERY='&vhp_flush_all=1'
	tool aafm-site-purge-post-cache "$(jq -nc --argjson id "$(fx acc-a id)" '{post_id:$id}')"
	MCP_QUERY=''
	check N6 "HTTP status with vhp_flush_all=1 (the vendor's own refusal, no tool result)" 403 "$HTTP_CODE"
fi

# ---------------------------------------------------------------------------------------------
# N7  Plugin active, caching switched off: nothing to purge, nothing touched.
# ---------------------------------------------------------------------------------------------
if [ "$SLUG" = proxy_cache_purge ]; then
	skip N7 "this plugin has no off state (design 6.14)"
else
	reset_budget
	disable_caching
	SAVED_HEADERS=(${MCP_HEADERS[@]+"${MCP_HEADERS[@]}"}); MCP_HEADERS=()
	PLANT=''
	if [ "${FILES[$SLUG]}" = 1 ]; then
		PLANT=$(PATHS plant "$(fx acc-a path)")
		mkdir -p "$(dirname "$PLANT")" && echo planted > "$PLANT"
	fi
	purge_post "$(fx acc-a id)"
	if refused_with aafm_site_no_provider; then pass N7 "the purge is refused: nothing is serving"; else fail N7 "the purge was not refused with aafm_site_no_provider: $(answer)"; fi
	if [ -n "$PLANT" ]; then
		[ -f "$PLANT" ] && pass N7 "the planted cache file survives" || fail N7 "the planted cache file was removed"
		rm -f "$PLANT"
	fi
	tool aafm-site-cache-status '{}'
	check N7 "status of $SLUG" not_caching "$(jq -r --arg s "$SLUG" '[.result.structuredContent.providers[]? | select(.provider == $s) | .status][0] // "absent"' "$OUT/body.json")"
	MCP_HEADERS=(${SAVED_HEADERS[@]+"${SAVED_HEADERS[@]}"})
	enable_caching
	serve
fi

# ---------------------------------------------------------------------------------------------
# N8  The site-wide purge budget: 30 units, then refusals.
# ---------------------------------------------------------------------------------------------
reset_budget
rotate_http_log
ensure_warm acc-a || fail N8 "could not warm acc-a"
OK=0; REFUSED=0; LAST=''
for i in $(seq 1 40); do
	purge_post "$(fx acc-a id)"
	if [ "$i" -le 30 ]; then
		ran && OK=$((OK + 1))
		[ "$i" = 30 ] && LAST=$(out_field budget_remaining)
	else
		refused_with aafm_site_purge_budget && REFUSED=$((REFUSED + 1))
	fi
done
check N8 "calls 1 to 30 that returned a result" 30 "$OK"
check N8 "budget_remaining after call 30" 0 "$LAST"
check N8 "calls 31 to 40 refused with aafm_site_purge_budget" 10 "$REFUSED"
MOST=$(grep -v ' REDIRECT ' "$HTTP_LOG" | awk '{print $1}' | sort | uniq -c | sort -rn | awk 'NR==1{print $1}')
[ "${MOST:-0}" -le 8 ] && pass N8 "at most ${MOST:-0} vendor requests in any one call (cap 8)" || fail N8 "$MOST vendor requests in one call, over the cap of 8"

# ---------------------------------------------------------------------------------------------
# N10  wp_optimize only: the vendor's own fetch must not follow a redirect to another host.
# ---------------------------------------------------------------------------------------------
if [ "$SLUG" = wp_optimize ]; then
	reset_budget
	touch "$ROOT/aafm-accept-redirect"
	rotate_http_log
	check N10 "the test page answers" 302 "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: text/html' "$URL/acc-redirect/")"
	purge_url "$URL/acc-redirect/"
	check N10 "row for a page that was never cached" 'requested|not_found_in_cache' "$(adapter_row)"
	sleep 2
	if grep -q ' /acc-redirect/ ' "$HTTP_LOG"; then pass N10 "the vendor fetched the purged URL itself"; else fail N10 "the vendor's own request to the purged URL was not seen, so the case proves nothing"; fi
	if grep -q -E 'REDIRECT|example\.invalid' "$HTTP_LOG"; then fail N10 "the vendor's request followed the redirect: $(grep -E 'REDIRECT|example\.invalid' "$HTTP_LOG" | head -1)"; else pass N10 "no redirect was followed and nothing went to example.invalid"; fi
	rm -f "$ROOT/aafm-accept-redirect"
else
	skip N10 "wp_optimize only"
fi

# ---------------------------------------------------------------------------------------------
# N11  The Host header of the request never decides the purge target.
# ---------------------------------------------------------------------------------------------
if [[ "$HOST_NAMED" == *" $SLUG "* ]]; then
	reset_budget
	ensure_warm acc-a acc-page || fail N11 "could not warm the fixtures"
	MCP_HEADERS=(-H 'Host: other.example')
	purge_post "$(fx acc-a id)"
	MCP_HEADERS=()
	if [ "$SLUG" = w3_total_cache ]; then
		# W3 Total Cache answers every request on a foreign Host with a 301 to this site's own
		# address at init (PgCache_Plugin::redirect_on_foreign_domain, on while its page cache
		# mirrors setting is off), so no tool runs and nothing may be purged.
		check N11 "HTTP status, the plugin's own redirect" 301 "$HTTP_CODE"
		LOCATION=$(grep -i '^location:' "$OUT/head.txt" | tr -d '\r' | awk '{print $2}')
		[ "${LOCATION#"$URL/"}" != "$LOCATION" ] && pass N11 "the redirect goes to this site: $LOCATION" || fail N11 "the redirect does not go to $URL/: $LOCATION"
		[ "$(live_count acc-a)" -gt 0 ] && pass N11 "acc-a is still cached, nothing was purged" || fail N11 "acc-a lost its cache file although no tool ran"
	else
		check N11 "row" "${P2[$SLUG]}" "$(adapter_row)"
		check N11 "live cache files left for acc-a under this site's own host" 0 "$(left_count acc-a)"
	fi
	check N11 "cache folders named for the other host" 0 "$(find "$CONTENT" -name '*other.example*' 2>/dev/null | wc -l)"
else
	skip N11 "file adapters that name their cache by host only"
fi

# ---------------------------------------------------------------------------------------------
# P8  siteground only: the vendor re-caches the page inside its own purge.
# ---------------------------------------------------------------------------------------------
if [ "$SLUG" = siteground ]; then
	sg_file() { live acc-a | grep -v mobile | head -1; }
	# Preheat on.
	LIB preheat on >/dev/null; reset_budget; rotate_http_log
	ensure_warm acc-a || fail P8 "could not warm acc-a"
	sleep 2
	F=$(sg_file); INODE=$(stat -c '%i' "$F"); MTIME=$(stat -c '%Y' "$F")
	purge_post "$(fx acc-a id)"
	ROW=$(adapter_row); F2=$(sg_file)
	grep -q "$(fx acc-a path) " "$HTTP_LOG" && pass P8 "preheat on: the vendor requested the page itself" || fail P8 "preheat on: no vendor request to the page was seen"
	if [ -z "$F2" ]; then
		fail P8 "preheat on: no cache file exists again after the purge (row $ROW)"
	elif [ "$(stat -c '%i' "$F2")" != "$INODE" ]; then
		note "branch: the re-created file has a new inode"
		check P8 "preheat on: row" 'purged|recached' "$ROW"
	else
		note "branch: the file system gave the re-created file the old inode"
		check P8 "preheat on: row" 'requested|no_acknowledgement' "$ROW"
		[ "$(stat -c '%Y' "$F2")" != "$MTIME" ] && pass P8 "the mtime differs" || fail P8 "same inode and same mtime: this is the old file"
	fi
	# Preheat off.
	LIB preheat off >/dev/null; reset_budget
	ensure_warm acc-a || fail P8 "could not warm acc-a"
	purge_post "$(fx acc-a id)"
	check P8 "preheat off: row" 'purged|' "$(adapter_row)"
	check P8 "preheat off: live cache files left" 0 "$(live_count acc-a)"
	# Preheat on, and the vendor's delete made to fail (bench5 case F1).
	LIB preheat on >/dev/null; reset_budget
	ensure_warm acc-a || fail P8 "could not warm acc-a"
	sleep 2
	F=$(sg_file); INODE=$(stat -c '%i' "$F"); MTIME=$(stat -c '%Y' "$F"); D=$(dirname "$F")
	chmod 555 "$D"
	purge_post "$(fx acc-a id)"
	chmod 755 "$D"
	check P8 "delete made to fail: row" 'failed|still_cached' "$(adapter_row)"
	check P8 "delete made to fail: inode and mtime unchanged" "$INODE $MTIME" "$(stat -c '%i %Y' "$F" 2>/dev/null)"
	LIB preheat off >/dev/null
else
	skip P8 "siteground only"
fi

# ---------------------------------------------------------------------------------------------
# V1  Variants: mobile, compressed, https and logged-in copies.
# ---------------------------------------------------------------------------------------------
if [[ "$HAS_VARIANTS" == *" $SLUG "* ]]; then
	reset_budget
	LIB variants "$SLUG" >/dev/null
	serve
	COOKIE=$(LIB cookie | tail -1)
	VAR=$(fx acc-var url)
	bash "$HERE/warm.sh" --quiet "$VAR" >/dev/null
	bash "$HERE/warm.sh" --quiet --mobile "$VAR" >/dev/null
	bash "$HERE/warm.sh" --quiet --https "$VAR" >/dev/null
	bash "$HERE/warm.sh" --quiet --cookie "$COOKIE" "$VAR" >/dev/null
	note "variant files before the purge: $(live acc-var | sed "s#$CONTENT/##" | tr '\n' ' ')"
	[ "$(live_count acc-var)" -gt 0 ] || fail V1 "no cache file appeared for acc-var"
	purge_post "$(fx acc-var id)"
	check V1 "row" "${P2[$SLUG]}" "$(adapter_row)"
	check V1 "live cache files left in any directory of the adapter's On disk line" 0 "$(left_count acc-var)"
else
	skip V1 "no variant bench for this adapter"
fi

# ---------------------------------------------------------------------------------------------
# W1  The status tool writes nothing.
# ---------------------------------------------------------------------------------------------
snapshot() { curl -s --max-time 60 -u "$ADMIN_USER:$APP_PW" "$URL/index.php?rest_route=/$SNAPSHOT_ROUTE"; }
# One call first, so anything the site does on its next request has already happened.
tool aafm-site-cache-status '{}'; snapshot > /dev/null
snapshot > "$OUT/snap-1.json"
tool aafm-site-cache-status '{}'
snapshot > "$OUT/snap-2.json"
# Both must be real snapshots: two identical REST error bodies would also compare equal.
if jq -e 'has("options")' "$OUT/snap-1.json" >/dev/null 2>&1 && jq -e 'has("options")' "$OUT/snap-2.json" >/dev/null 2>&1; then
	if cmp -s "$OUT/snap-1.json" "$OUT/snap-2.json"; then pass W1 "the two write snapshots are identical"; else fail W1 "the status tool changed stored state: $(diff <(jq -S . "$OUT/snap-1.json") <(jq -S . "$OUT/snap-2.json") | head -4 | tr '\n' ' ')"; fi
else
	fail W1 "the write snapshot route of setup.sh did not answer with a snapshot"
fi

# ---------------------------------------------------------------------------------------------
# N9  Every outbound request of the whole run went to this run's own loopback addresses.
# ---------------------------------------------------------------------------------------------
rotate_http_log
# Only requests made while a tool ran are judged. A cache plugin's own traffic on page views,
# activation or WP-CLI (SiteGround's PageSpeed API call, LiteSpeed's QUIC.cloud calls) is
# not caused by a purge; it is blocked all the same and listed below.
# The exact list cache-mu.php allows. Inside the container localhost on port 80 is the main site.
ALLOWED_DEST='^(localhost:8072|127\.0\.0\.1:8072|127\.0\.0\.1:8099)$'
FOREIGN=$(grep -v ' REDIRECT ' "$OUT/http-all.log" | awk '$1 ~ /^mcp-/ {print $3}' | grep -v -E "$ALLOWED_DEST" | sort -u | tr '\n' ' ')
[ -z "$FOREIGN" ] && pass N9 "every destination requested while a tool ran is localhost:8072, 127.0.0.1:8072 or the proxy listener 127.0.0.1:8099" || fail N9 "other destinations were attempted (and blocked) while a tool ran: $FOREIGN"
OTHER=$(grep -v ' REDIRECT ' "$OUT/http-all.log" | awk '$1 !~ /^mcp-/ {print $3}' | grep -v -E "$ALLOWED_DEST" | sort -u | tr '\n' ' ')
[ -z "$OTHER" ] || note "the plugin's own requests outside any tool call, blocked: $OTHER"
BLOCKED=$(grep -c ' blocked$' "$OUT/http-all.log")
[ "$BLOCKED" = 0 ] || note "$BLOCKED request(s) to another port of this container were blocked: $(grep ' blocked$' "$OUT/http-all.log" | awk '{print $3}' | sort -u | tr '\n' ' ')"

# ---------------------------------------------------------------------------------------------
echo
echo "CACHE ACCEPTANCE $SLUG: $PASSED pass, $FAILED fail, $SKIPPED not applicable"
[ "$FAILED" = 0 ]
