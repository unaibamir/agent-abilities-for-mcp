#!/usr/bin/env bash
# Shared by the Site Ops acceptance scripts. Source it, do not run it.
# Everything here acts on the disposable site only: /tmp/aafm_accept_siteops, database
# aafm_accept_siteops, port 8072. Nothing here may touch database `db` or /var/www/html.

ACC_ROOT=/tmp/aafm_accept_siteops
ACC_DB=aafm_accept_siteops
ACC_PORT=8072
ACC_REPO=/var/www/plugin-src
ACC_ROUTE='agent-abilities-for-mcp/mcp'
ACC_INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"siteops-accept","version":"1"}}}'
ACC_PASS=0
ACC_FAIL=0

# acc_guard: refuse to run anywhere but inside the DDEV web container.
acc_guard() {
	if [ "${IS_DDEV_PROJECT:-}" != true ]; then
		echo "refusing: not inside the DDEV web container"
		exit 2
	fi
	if [ ! -f "$ACC_REPO/agent-abilities-for-mcp.php" ]; then
		echo "refusing: plugin source not found at $ACC_REPO"
		exit 2
	fi
}

# acc_load: read what setup.sh wrote. Fails when no disposable site exists.
acc_load() {
	if [ ! -f "$ACC_ROOT/site.env" ]; then
		echo "no disposable site: run setup.sh first"
		exit 2
	fi
	# shellcheck disable=SC1091
	. "$ACC_ROOT/site.env"
}

# W: WP-CLI against the disposable site, never the main one.
W() { wp --allow-root --quiet --path="$ACC_WP" "$@"; }

pass() { ACC_PASS=$((ACC_PASS + 1)); echo "PASS $1: $2"; }
fail() { ACC_FAIL=$((ACC_FAIL + 1)); echo "FAIL $1: $2"; }

# finish <script name>: print the totals and exit 0 only when nothing failed.
finish() {
	echo "ACCEPT $1: $ACC_PASS pass, $ACC_FAIL fail"
	[ "$ACC_FAIL" -eq 0 ] && exit 0
	exit 1
}

# mcp_post <json body> [session id]: prints the HTTP code; body in body.json, headers in head.txt.
# ACC_CURL_EXTRA (array) adds curl arguments. ACC_QUERY (string starting with &) is appended to the URL.
mcp_post() {
	local sid=()
	[ -n "${2:-}" ] && sid=(-H "Mcp-Session-Id: $2" -H 'MCP-Protocol-Version: 2025-06-18')
	curl -s --max-time 120 -D "$ACC_ROOT/head.txt" -o "$ACC_ROOT/body.json" -w '%{http_code}' \
		-u "admin:$ACC_APP_PW" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
		${sid[@]+"${sid[@]}"} ${ACC_CURL_EXTRA[@]+"${ACC_CURL_EXTRA[@]}"} \
		-X POST "$ACC_URL/index.php?rest_route=/$ACC_ROUTE${ACC_QUERY:-}" -d "$1"
}

# acc_session: initialize and print the session id. Returns 1 when initialize did not answer 200.
acc_session() {
	local code
	code=$(mcp_post "$ACC_INIT")
	if [ "$code" != 200 ]; then
		echo "initialize HTTP $code $(head -c 200 "$ACC_ROOT/body.json" | tr '\n' ' ')" >&2
		return 1
	fi
	grep -i '^mcp-session-id:' "$ACC_ROOT/head.txt" | tr -d '\r' | awk '{print $2}'
}

# tools_call <ability name> <arguments json>: call one tool. The ability name is the registry
# name (aafm/site-cron-status); the wire name replaces the slash with a hyphen. The JSON-RPC
# answer is left in body.json. Returns 0 when both requests answered 200.
tools_call() {
	local sid code tool
	tool=$(printf '%s' "$1" | tr '/' '-')
	sid=$(acc_session) || return 1
	code=$(mcp_post "$(jq -nc --arg n "$tool" --argjson a "$2" '{jsonrpc:"2.0",id:3,method:"tools/call",params:{name:$n,arguments:$a}}')" "$sid")
	[ "$code" = 200 ]
}

# tools_list <outfile>: one wire tool name per line.
tools_list() {
	local sid code cursor='' n=0 params
	: > "$1"
	sid=$(acc_session) || return 1
	while [ "$n" -lt 50 ]; do
		params='{}'
		[ -n "$cursor" ] && params=$(jq -nc --arg c "$cursor" '{cursor:$c}')
		code=$(mcp_post "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/list\",\"params\":$params}" "$sid")
		[ "$code" = 200 ] || return 1
		jq -r '.result.tools[].name' "$ACC_ROOT/body.json" >> "$1"
		cursor=$(jq -r '.result.nextCursor // empty' "$ACC_ROOT/body.json")
		n=$((n + 1))
		[ -n "$cursor" ] || break
	done
	return 0
}

# call_refused: true when the last tools_call answer is an error.
call_refused() {
	jq -e '(.error | type) == "object" or .result.isError == true' "$ACC_ROOT/body.json" > /dev/null 2>&1
}

# call_field <jq path>: one value of the last answer's structured content, for example
# call_field '.summary' or call_field '.results[0].state'.
call_field() {
	jq -r ".result.structuredContent$1" "$ACC_ROOT/body.json"
}

# call_text: the text of the last answer, which carries the message of a refusal.
call_text() {
	jq -r '.result.content[0].text // .error.message // empty' "$ACC_ROOT/body.json"
}

# snapshot <outfile>: what a read must leave unchanged, as sorted JSON. Anything that is not a
# snapshot, such as a REST error body, leaves the file empty, so two failed reads never compare equal.
snapshot() {
	curl -s --max-time 60 -u "admin:$ACC_APP_PW" "$ACC_URL/index.php?rest_route=/aafm-accept/v1/snapshot" | jq -S . > "$1"
	jq -e 'has("options")' "$1" > /dev/null 2>&1 || { : > "$1"; return 1; }
}

# outbound_reset: empty the outbound log. outbound_log: print it.
outbound_reset() { : > "$ACC_ROOT/outbound.log"; }
outbound_log() { cat "$ACC_ROOT/outbound.log" 2> /dev/null; }
