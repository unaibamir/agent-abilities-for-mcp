#!/usr/bin/env bash
# Backup acceptance for 1.13.0 Site Ops (design 9.4, cases B1 to B14, B11, B12, W3).
#
# Runs on the disposable site only, inside the DDEV web container:
#
#   ddev snapshot --name pre-siteops-backup                      (on the host, first)
#   ddev exec 'AAFM_ACCEPT_SNAPSHOT=pre-siteops-backup bash /var/www/plugin-src/tests/acceptance/siteops/backup.sh --readers --no-verifier'
#   ddev exec 'AAFM_ACCEPT_SNAPSHOT=pre-siteops-backup bash /var/www/plugin-src/tests/acceptance/siteops/backup.sh --readers'
#   ddev exec 'AAFM_ACCEPT_SNAPSHOT=pre-siteops-backup bash /var/www/plugin-src/tests/acceptance/siteops/backup.sh'
#
#   --readers       Batch 9: the Readers column only. The gate is switched on with wp option update,
#                   no gated tool is called, and the verifier is run by firing its hook.
#   --no-verifier   With --readers, batch 9a: skip B4 and B7, the cases that run the verifier.
#   (no flag)       Batch 10: both columns of every case. The gate is switched on through the
#                   settings endpoint and the verifier is run through WP-Cron.
#   --keep          Leave the disposable site up afterwards.
#
# It builds the site with setup.sh, installs UpdraftPlus from wordpress.org, and removes the site
# with teardown.sh. It never touches /var/www/html or the database named db. Backups are started
# with UpdraftPlus's own hook, never through this plugin's tools.
#
# Prints PASS or FAIL per check and "BACKUP: <n> pass, <n> fail". Exit 0 only with 0 fail.

set -u

HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=/tmp/aafm_accept_siteops
DB=aafm_accept_siteops
URL=http://localhost:8072
ROUTE='agent-abilities-for-mcp/mcp'
INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"backup-accept","version":"1"}}}'
GATED_ABILITY='aafm/delete-post'
GATED_TOOL='aafm-delete-post'

MODE=full
VERIFIER=1
KEEP=0
for arg in "$@"; do
	case "$arg" in
		--readers) MODE=readers ;;
		--no-verifier) VERIFIER=0 ;;
		--keep) KEEP=1 ;;
		*) echo "usage: backup.sh [--readers [--no-verifier]] [--keep]"; exit 2 ;;
	esac
done
if [ "$VERIFIER" = 0 ] && [ "$MODE" != readers ]; then
	echo "refusing: --no-verifier is only for --readers"; exit 2
fi

[ "${IS_DDEV_PROJECT:-}" = true ] || { echo "refusing: not inside the DDEV web container"; exit 1; }
[ -n "${AAFM_ACCEPT_SNAPSHOT:-}" ] || { echo "refusing: take a snapshot first (ddev snapshot --name <name>) and pass its name as AAFM_ACCEPT_SNAPSHOT"; exit 1; }
case "$ROOT" in /tmp/aafm_accept_*) ;; *) echo "refusing: the site root must be under /tmp/aafm_accept_"; exit 1 ;; esac
[ "$DB" != db ] || { echo "refusing: database db is the main site"; exit 1; }
command -v jq >/dev/null 2>&1 || { echo "refusing: jq is not installed"; exit 1; }
echo "snapshot taken before this run: $AAFM_ACCEPT_SNAPSHOT"

# ---------------------------------------------------------------------------------------------
# Expected results, as data. Key: <case>.<what>. The design's table (9.4) is the source.
# A gated call is "ran" or "refused:<reason code of the denied row>".
# ---------------------------------------------------------------------------------------------
declare -A WANT=(
	[B1.last_good]=null [B1.gate_on]=true [B1.satisfied]=false [B1.reason]=no_backup [B1.gated]=refused:backup_gate
	[B2.database_check]=verified [B2.checked_at]=null [B2.satisfied]=true [B2.gated]=ran
	[B3.list]=failed [B3.last_good]=null [B3.reason]=no_backup [B3.gated]=refused:backup_gate
	[B4.last_good]=null [B4.reason]=no_backup [B4.list_before]=pending [B4.list_after]=failed [B4.checked_at_set]=yes [B4.gated_before]=refused:backup_gate [B4.gated_after]=refused:backup_gate
	[B5.list]=failed [B5.last_good]=null [B5.reason]=no_backup [B5.gated]=refused:backup_gate
	[B6.list]=pending [B6.last_good]=null [B6.reason]=verification_pending [B6.gated]=refused:backup_gate_pending
	[B7.verifier]=clean [B7.list]=verified [B7.checked_at_set]=yes [B7.database_check]=verified [B7.same_checked_at]=yes [B7.satisfied]=true [B7.gated]=ran
	[B8.gated_off]=refused:backup_gate_pending [B8.list_on]=size_only [B8.database_check_on]=size_only [B8.gated_on]=ran
	[B9.failed_job]=true [B9.running]=false [B9.last_good_is_older]=yes
	[B10.after_on]=1 [B10.after_page_load]=1 [B10.after_off]=0
	[B13.run_output]=ran [B13.list]=too_large [B13.last_good]=null [B13.reason]=too_large_to_verify [B13.pill]=yes [B13.gated]=refused:backup_gate_too_large [B13.message_says_too_large]=yes [B13.message_says_try_later]=no [B13.list_after_cron]=too_large [B13.list_large_on]=size_only [B13.gated_large_on]=ran
	[B14.deactivated]=yes [B14.inactive_between]=yes [B14.activated]=yes [B14.active]=yes [B14.fatal]=no [B14.event_after_admin_load]=1
	[B11.leak]=none
	[B12.backup_tools]='aafm-site-backup-status aafm-site-list-backups' [B12.start_or_restore]=none
	[W3.snapshot]=identical
)

P=0
F=0
pass() { P=$((P + 1)); echo "PASS $1: $2"; }
fail() { F=$((F + 1)); echo "FAIL $1: $2"; }
# check <case> <what> <actual>: compare with WANT.
check() {
	local want="${WANT[$1.$2]-__missing__}"
	if [ "$want" = __missing__ ]; then fail "$1" "$2 has no expected value in WANT"; return; fi
	if [ "$3" = "$want" ]; then pass "$1" "$2 is '$3'"; else fail "$1" "$2 is '$3', expected '$want'"; fi
}
# gate_only: true in the mode that checks the Gate column.
gate_only() { [ "$MODE" = full ]; }

W() { wp --allow-root --path="$ROOT" "$@"; }

finish() {
	echo "BACKUP: $P pass, $F fail"
	[ "$KEEP" = 1 ] && echo "kept: $ROOT (database $DB)"
	[ "$F" -eq 0 ] && exit 0
	exit 1
}
# The disposable site goes however the script ends, an interrupt included, unless --keep was given.
teardown_all() {
	[ "$KEEP" = 1 ] || bash "$HERE/teardown.sh" >/dev/null 2>&1
}
trap teardown_all EXIT
trap 'exit 130' INT TERM

# ---------------------------------------------------------------------------------------------
# Site
# ---------------------------------------------------------------------------------------------
bash "$HERE/setup.sh" || { echo "setup.sh failed"; exit 1; }
[ "$(W option get siteurl 2>/dev/null)" = "$URL" ] || { echo "refusing: $ROOT is not the disposable site at $URL"; exit 1; }
[ "$(W eval 'echo DB_NAME;' 2>/dev/null)" = "$DB" ] || { echo "refusing: $ROOT does not use database $DB"; exit 1; }

BODY="$ROOT/accept-backup-body.json"
HEAD="$ROOT/accept-backup-head.txt"
ALL_TEXT="$ROOT/accept-backup-all.txt"
NONCES="$ROOT/accept-backup-nonces.txt"
JAR="$ROOT/accept-backup-jar.txt"
DEBUG_LOG="$ROOT/wp-content/debug.log"
UPDRAFT_DIR="$ROOT/wp-content/updraft"
: > "$ALL_TEXT"
: > "$NONCES"

# A second lock on outbound requests, whatever setup.sh installed: nothing but the site itself.
mkdir -p "$ROOT/wp-content/mu-plugins"
cat > "$ROOT/wp-content/mu-plugins/aafm-accept-backup-block.php" <<'PHP'
<?php
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		// Port 8072 only: inside the container, localhost on port 80 is the main site.
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) && 8072 === (int) wp_parse_url( (string) $url, PHP_URL_PORT ) ) {
			return $pre;
		}
		return new WP_Error( 'aafm_accept_blocked', 'blocked by backup.sh' );
	},
	2,
	3
);
PHP

# UpdraftPlus 1.26.16, the wordpress.org build the gate was accepted on (2026-11-20), installed from
# a local zip so no WordPress HTTP call is needed.
UPDRAFT_VERSION=1.26.16
if ! W plugin is-installed updraftplus 2>/dev/null; then
	curl -fsL --max-time 120 -o /tmp/aafm_accept_updraftplus.zip "https://downloads.wordpress.org/plugin/updraftplus.$UPDRAFT_VERSION.zip" || { echo "could not download UpdraftPlus $UPDRAFT_VERSION"; exit 1; }
	W plugin install /tmp/aafm_accept_updraftplus.zip >/dev/null 2>&1 || { rm -f /tmp/aafm_accept_updraftplus.zip; echo "could not install UpdraftPlus"; exit 1; }
	rm -f /tmp/aafm_accept_updraftplus.zip
fi
W plugin activate updraftplus >/dev/null 2>&1 || { echo "could not activate UpdraftPlus"; exit 1; }
[ "$(W plugin get updraftplus --field=version 2>/dev/null)" = "$UPDRAFT_VERSION" ] || { echo "UpdraftPlus is not version $UPDRAFT_VERSION"; exit 1; }
echo "UpdraftPlus $UPDRAFT_VERSION"

# The hourly event must never fire by itself between two steps of a case.
W config set DISABLE_WP_CRON true --raw >/dev/null 2>&1

W eval '$e = (array) get_option( "aafm_enabled_abilities", array() ); update_option( "aafm_enabled_abilities", array_values( array_unique( array_merge( $e, array( "aafm/site-backup-status", "aafm/site-list-backups", "aafm/delete-post" ) ) ) ) );' >/dev/null 2>&1
APP_PW=$(W user application-password create admin "backup-accept-$$" --porcelain 2>/dev/null)
[ -n "$APP_PW" ] || { echo "could not create an Application Password"; exit 1; }

# Twenty throwaway posts for the gated tool.
GATE_POSTS=()
for i in $(seq 1 20); do
	GATE_POSTS+=("$(W post create --post_title="acc-gate-$i" --post_name="acc-gate-$i-$$" --post_status=publish --porcelain 2>/dev/null)")
done
# The index of the next unused post lives in a file: gated() runs inside $( ), a subshell.
GATE_NEXT_FILE="$ROOT/accept-backup-next.txt"
echo 0 > "$GATE_NEXT_FILE"

# ---------------------------------------------------------------------------------------------
# MCP helpers (the recipe of bin/smoke-gate.sh)
# ---------------------------------------------------------------------------------------------
mcp_post() {
	local sid=()
	[ -n "${2:-}" ] && sid=(-H "Mcp-Session-Id: $2" -H 'MCP-Protocol-Version: 2025-06-18')
	curl -s --max-time 120 -D "$HEAD" -o "$BODY" -w '%{http_code}' -u "admin:$APP_PW" \
		-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
		${sid[@]+"${sid[@]}"} -X POST "$URL/index.php?rest_route=/$ROUTE" -d "$1"
}
session() {
	local code
	code=$(mcp_post "$INIT")
	[ "$code" = 200 ] || { echo ""; return 1; }
	grep -i '^mcp-session-id:' "$HEAD" | tr -d '\r' | awk '{print $2}'
}
# tool <wire name> <args json>: one tools/call. Sets VERDICT to ran, refused or broken; the answer is in $BODY.
VERDICT=broken
tool() {
	local sid code
	VERDICT=broken
	sid=$(session) || return 1
	code=$(mcp_post "$(jq -nc --arg n "$1" --argjson a "$2" '{jsonrpc:"2.0",id:3,method:"tools/call",params:{name:$n,arguments:$a}}')" "$sid")
	cat "$BODY" >> "$ALL_TEXT"; echo >> "$ALL_TEXT"
	[ "$code" = 200 ] || return 1
	if ! jq -e 'type == "object" and .jsonrpc == "2.0"' "$BODY" >/dev/null 2>&1; then return 1; fi
	if jq -e '(.error | type) == "object" or .result.isError == true' "$BODY" >/dev/null 2>&1; then
		VERDICT=refused
	elif jq -e '(.result | type) == "object"' "$BODY" >/dev/null 2>&1; then
		VERDICT=ran
	fi
	return 0
}
status() { tool aafm-site-backup-status '{}'; }
list() { tool aafm-site-list-backups '{"per_page":20}'; }
# st <jq path under structuredContent>: one value of the last answer, with null printed as "null".
st() { jq -r ".result.structuredContent | $1 | if . == null then \"null\" else tostring end" "$BODY" 2>/dev/null; }
message() { jq -r '.result.content[0].text // .error.message // empty' "$BODY" 2>/dev/null; }

# gated: call the gated tool on the next throwaway post. Prints "ran" or "refused:<reason>" or a diagnosis.
gated() {
	local next id there reason
	next=$(cat "$GATE_NEXT_FILE")
	id="${GATE_POSTS[$next]:-0}"
	echo $((next + 1)) > "$GATE_NEXT_FILE"
	W eval 'aafm_clear_activity_log();' >/dev/null 2>&1 # the disposable database only
	tool "$GATED_TOOL" "{\"post_id\":$id}" || { echo "broken"; return; }
	there=$(W eval "echo get_post( $id ) instanceof WP_Post ? 'there' : 'gone';" 2>/dev/null)
	if [ "$VERDICT" = ran ] && [ "$there" = gone ]; then echo ran; return; fi
	if [ "$VERDICT" = refused ] && [ "$there" = there ]; then
		reason=$(W eval '$r = aafm_query_activity( array( "ability" => "aafm/delete-post", "status" => "denied", "per_page" => 1 ) ); echo isset( $r[0] ) ? (string) $r[0]["detail"] : "";' 2>/dev/null | sed -n 's/.*Refused: \([a-z_]*\).*/\1/p')
		echo "refused:${reason:-no_denied_row}"
		return
	fi
	echo "verdict=$VERDICT post=$there"
}

# ---------------------------------------------------------------------------------------------
# Owner actions (full mode): the settings endpoint with an admin cookie
# ---------------------------------------------------------------------------------------------
ADMIN_NONCE=''
admin_login() {
	rm -f "$JAR"
	curl -s --max-time 60 -c "$JAR" -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null \
		-d "log=admin&pwd=admin&wp-submit=Log+In&redirect_to=$URL/wp-admin/&testcookie=1" "$URL/wp-login.php"
	ADMIN_NONCE=$(curl -s --max-time 60 -b "$JAR" "$URL/wp-admin/admin.php?page=agent-abilities-for-mcp&tab=settings" | sed -n 's/.*var aafmAdmin = {[^}]*"nonce":"\([a-f0-9]*\)".*/\1/p' | head -1)
	[ -n "$ADMIN_NONCE" ]
}
settings_page() { curl -s --max-time 60 -b "$JAR" "$URL/wp-admin/admin.php?page=agent-abilities-for-mcp&tab=settings"; }
# save_gate <on|off> [large]: one Settings save that carries the gate fields, as the admin script posts them.
save_gate() {
	local fields=(--data-urlencode 'action=aafm_save_settings' --data-urlencode "nonce=$ADMIN_NONCE" --data-urlencode 'aafm_backup_gate_present=1' --data-urlencode "aafm_backup_gate_abilities[]=$GATED_ABILITY")
	[ "$1" = on ] && fields+=(--data-urlencode 'aafm_backup_gate=1')
	[ "${2:-}" = large ] && fields+=(--data-urlencode 'aafm_backup_gate_accept_size=1')
	curl -s --max-time 60 -b "$JAR" -o "$ROOT/accept-backup-save.json" "${fields[@]}" "$URL/wp-admin/admin-ajax.php"
	[ "$(jq -r '.success' "$ROOT/accept-backup-save.json" 2>/dev/null)" = true ] || { fail setup "settings save ($*) answered $(head -c 200 "$ROOT/accept-backup-save.json")"; return 1; }
}
gate_on() {
	if [ "$MODE" = readers ]; then W option update aafm_backup_gate 1 >/dev/null 2>&1; else save_gate on; fi
}
event_count() { W cron event list --fields=hook --format=csv 2>/dev/null | grep -c '^aafm_backup_verify$'; }

# ---------------------------------------------------------------------------------------------
# The three state functions of the design
# ---------------------------------------------------------------------------------------------
NONCE=''
ARCHIVE=''
# fresh_backup: spoil every earlier database archive, then run one database-only backup inline.
fresh_backup() {
	local state
	rm -f "$UPDRAFT_DIR"/*-db.gz "$UPDRAFT_DIR"/*-db.gz.crypt 2>/dev/null
	sleep 1 # the history key is the start second; two sets must not share it
	NONCE=$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')
	echo "$NONCE" >> "$NONCES"
	W eval "do_action( 'updraft_backupnow_backup_database', array( 'nocloud' => 1, 'use_nonce' => '$NONCE' ) );" >/dev/null 2>&1
	state=$(W eval "\$r = get_option( 'updraft_jobdata_$NONCE' ); echo false === \$r ? 'none' : ( is_array( \$r ) ? (string) ( \$r['jobstatus'] ?? '' ) : '' );" 2>/dev/null)
	case "$state" in
		none | finished) ;;
		*) fail setup "fresh_backup: job $NONCE ended as '$state'"; return 1 ;;
	esac
	ARCHIVE=$(ls "$UPDRAFT_DIR"/*"$NONCE"*-db.gz 2>/dev/null | head -1)
	[ -f "$ARCHIVE" ] || { fail setup "fresh_backup: no database archive for $NONCE"; return 1; }
}
# run_verifier: a fresh process that has loaded nothing lazy. Prints "clean" or what went wrong.
run_verifier() {
	local out
	if [ "$MODE" = readers ]; then
		out=$(W eval 'var_export( function_exists( "aafm_backup_verify_run" ) ); do_action( "aafm_backup_verify" ); var_export( function_exists( "aafm_backup_verify_run" ) );' 2>&1)
		if [ "$out" = falsetrue ]; then echo clean; else echo "output: $out"; fi
	else
		out=$(W cron event run aafm_backup_verify 2>&1)
		case "$out" in
			*'PHP '* | *'Fatal'* | *'Error:'*) echo "output: $out" ;;
			*'Executed the cron event'*) echo clean ;;
			*) echo "output: $out" ;;
		esac
	fi
}
# budget_1k: from here on a real archive is over the inline budget.
budget_1k() {
	cat > "$ROOT/wp-content/mu-plugins/aafm-accept-backup-budget.php" <<'PHP'
<?php
add_filter(
	'aafm_backup_gate_inline_checksum_max_bytes',
	static function () {
		return 1024;
	}
);
PHP
}

# write_snapshot <file>: one line per option (name and md5 of its value), then cron, posts, postmeta.
write_snapshot() {
	W eval 'global $wpdb; foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ) as $r ) { echo $r["option_name"] . " " . md5( (string) $r["option_value"] ) . "\n"; } echo "#cron " . md5( serialize( _get_cron_array() ) ) . "\n#posts " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) . "\n#postmeta " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ) . "\n";' > "$1" 2>/dev/null
}

# ---------------------------------------------------------------------------------------------
# Cases, in the design's order. Each starts from what the one before left.
# ---------------------------------------------------------------------------------------------
if gate_only; then
	admin_login || { fail setup "could not sign in as the disposable admin or read the admin nonce"; finish; }
fi
gate_on || finish

# B1: no backup.
status
check B1 last_good "$(st '.last_good')"
check B1 gate_on "$(st '.gate.on')"
check B1 satisfied "$(st '.gate.satisfied')"
check B1 reason "$(st '.gate.reason')"
gate_only && check B1 gated "$(gated)"

# B2: a small good archive.
fresh_backup || finish
status
check B2 database_check "$(st '.last_good.database_check')"
check B2 checked_at "$(st '.last_good.checked_at')"
check B2 satisfied "$(st '.gate.satisfied')"
gate_only && check B2 gated "$(gated)"

# B3: that archive, one byte short.
truncate -s -1 "$ARCHIVE"
list
check B3 list "$(st '.backups[0].database_check')"
status
check B3 last_good "$(st '.last_good')"
check B3 reason "$(st '.gate.reason')"
gate_only && check B3 gated "$(gated)"

# B4: same-size damage, which only the checksum catches.
if [ "$VERIFIER" = 1 ]; then
	fresh_backup || finish
	size=$(stat -c %s "$ARCHIVE")
	printf 'ZZZZZZZZZZZZZZZZ' | dd of="$ARCHIVE" bs=1 seek=$((size / 2)) conv=notrunc status=none
	status
	check B4 last_good "$(st '.last_good')"
	check B4 reason "$(st '.gate.reason')"
	list
	check B4 list_before "$(st '.backups[0].database_check')"
	gate_only && check B4 gated_before "$(gated)"
	verifier=$(run_verifier)
	[ "$verifier" = clean ] || fail B4 "the verifier run: $verifier"
	list
	check B4 list_after "$(st '.backups[0].database_check')"
	check B4 checked_at_set "$([ "$(st '.backups[0].checked_at')" != null ] && echo yes || echo no)"
	gate_only && check B4 gated_after "$(gated)"
fi

# B5: the archive deleted.
fresh_backup || finish
rm -f "$ARCHIVE"
list
check B5 list "$(st '.backups[0].database_check')"
status
check B5 last_good "$(st '.last_good')"
check B5 reason "$(st '.gate.reason')"
gate_only && check B5 gated "$(gated)"

# B6: an archive over the inline budget, not checked yet.
budget_1k
fresh_backup || finish
list
check B6 list "$(st '.backups[0].database_check')"
status
check B6 last_good "$(st '.last_good')"
check B6 reason "$(st '.gate.reason')"
gate_only && check B6 gated "$(gated)"

# B7: the verifier, in a process that loaded nothing lazy. In full mode three ways.
b7_checks() {
	local listed
	check B7 verifier "$1"
	list
	check B7 list "$(st '.backups[0].database_check')"
	listed=$(st '.backups[0].checked_at')
	check B7 checked_at_set "$([ "$listed" != null ] && echo yes || echo no)"
	status
	check B7 database_check "$(st '.last_good.database_check')"
	check B7 same_checked_at "$([ "$(st '.last_good.checked_at')" = "$listed" ] && echo yes || echo no)"
	check B7 satisfied "$(st '.gate.satisfied')"
	gate_only && check B7 gated "$(gated)"
}
if [ "$VERIFIER" = 1 ]; then
	b7_checks "$(run_verifier)"
	if gate_only; then
		fresh_backup || finish
		b7_checks "$(run_verifier)"

		fresh_backup || finish
		W cron event delete aafm_backup_verify >/dev/null 2>&1
		W cron event schedule aafm_backup_verify now hourly >/dev/null 2>&1
		lines=$(wc -l < "$DEBUG_LOG" 2>/dev/null || echo 0)
		curl -s --max-time 120 -o /dev/null "$URL/wp-cron.php"
		if tail -n +"$((lines + 1))" "$DEBUG_LOG" 2>/dev/null | grep -q 'PHP Fatal'; then
			b7_checks "wp-cron.php logged a fatal error"
		else
			b7_checks clean
		fi
	fi
fi

# B8: accepting a large backup on its size alone.
if gate_only; then
	fresh_backup || finish
	check B8 gated_off "$(gated)"
	save_gate on large
	list
	check B8 list_on "$(st '.backups[0].database_check')"
	status
	check B8 database_check_on "$(st '.last_good.database_check')"
	check B8 gated_on "$(gated)"
	save_gate on
fi

# B9: a backup killed part way, with nothing left to resume it.
# 300 MB of random data in uploads, as on the bench, so a full backup runs long enough to be killed.
mkdir -p "$ROOT/wp-content/uploads"
head -c 300000000 /dev/urandom > "$ROOT/wp-content/uploads/acc-bulk.bin"
KILLED=$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')
echo "$KILLED" >> "$NONCES"
KILL_START=$(date +%s)
(W eval "do_action( 'updraft_backupnow_backup_all', array( 'nocloud' => 1, 'use_nonce' => '$KILLED' ) );" >/dev/null 2>&1 &)
sleep 3
pkill -9 -f "$KILLED"
killed_state=$(W eval "\$r = get_option( 'updraft_jobdata_$KILLED' ); echo is_array( \$r ) ? (string) ( \$r['jobstatus'] ?? '' ) : 'none';" 2>/dev/null)
rm -f "$ROOT/wp-content/uploads/acc-bulk.bin"
if [ "$killed_state" = none ] || [ "$killed_state" = finished ]; then
	fail B9 "could not leave a killed job behind (job row: '$killed_state')"
else
	W cron event delete updraft_backup_resume >/dev/null 2>&1
	echo "B9: waiting 130 seconds for the killed job to read as dead"
	sleep 130
	status
	check B9 failed_job "$(st '.failed_job')"
	check B9 running "$(st '.running')"
	started=$(st '.last_good.started_at')
	if [ "$(st '.last_good')" = null ] || [ "$(date -d "$started" +%s 2>/dev/null || echo 0)" -lt "$KILL_START" ]; then
		check B9 last_good_is_older yes
	else
		check B9 last_good_is_older no
	fi
fi

# B10: the schedule follows the switch.
if gate_only; then
	save_gate off
	save_gate on
	check B10 after_on "$(event_count)"
	W cron event delete aafm_backup_verify >/dev/null 2>&1
	curl -s --max-time 60 -b "$JAR" -o /dev/null "$URL/wp-admin/index.php"
	check B10 after_page_load "$(event_count)"
	save_gate off
	check B10 after_off "$(event_count)"
	save_gate on
fi

# B13: an archive the verifier cannot finish.
if gate_only; then
	fresh_backup || finish
	check B13 run_output "$(W eval 'require_once AAFM_PLUGIN_DIR . "includes/siteops/vendor-call.php"; require_once AAFM_PLUGIN_DIR . "includes/siteops/backup-updraft.php"; echo aafm_backup_verify_run( 0 );' 2>/dev/null)"
	list
	check B13 list "$(st '.backups[0].database_check')"
	status
	check B13 last_good "$(st '.last_good')"
	check B13 reason "$(st '.gate.reason')"
	check B13 pill "$(settings_page | grep -q 'Backup too large to check' && echo yes || echo no)"
	check B13 gated "$(gated)"
	msg=$(message)
	check B13 message_says_too_large "$(case "$msg" in *'too large'*) echo yes ;; *) echo no ;; esac)"
	check B13 message_says_try_later "$(case "$msg" in *'Try again later'*) echo yes ;; *) echo no ;; esac)"
	W cron event run aafm_backup_verify >/dev/null 2>&1
	list
	check B13 list_after_cron "$(st '.backups[0].database_check')"
	save_gate on large
	list
	check B13 list_large_on "$(st '.backups[0].database_check')"
	check B13 gated_large_on "$(gated)"
	save_gate on
fi

# B14: deactivating and reactivating never fails, and the event comes back.
# Each action must succeed, and the plugin must be inactive between the two, in both paths: a
# missing link or a failed request is a failed check, never a skipped one.
b14_round() { # <label> <how: cli|screen>
	local lines href action ok
	lines=$(wc -l < "$DEBUG_LOG" 2>/dev/null || echo 0)
	for action in deactivate activate; do
		ok=no
		if [ "$2" = cli ]; then
			W plugin "$action" agent-abilities-for-mcp >/dev/null 2>&1 && ok=yes
		else
			href=$(curl -s --max-time 60 -b "$JAR" "$URL/wp-admin/plugins.php" | grep -o "plugins.php?action=$action&amp;plugin=agent-abilities-for-mcp[^\"']*" | head -1 | sed 's/&amp;/\&/g')
			[ -n "$href" ] && curl -sf --max-time 60 -b "$JAR" -o /dev/null -L "$URL/wp-admin/$href" && ok=yes
		fi
		check B14 "${action}d" "$ok"
		if [ "$action" = deactivate ]; then
			check B14 inactive_between "$(W plugin is-active agent-abilities-for-mcp 2>/dev/null && echo no || echo yes)"
		fi
	done
	check B14 active "$(W plugin is-active agent-abilities-for-mcp 2>/dev/null && echo yes || echo no)"
	check B14 fatal "$(tail -n +"$((lines + 1))" "$DEBUG_LOG" 2>/dev/null | grep -q 'PHP Fatal' && echo yes || echo no)"
	echo "     ($1)"
}
if gate_only; then
	b14_round 'gate on, wp plugin' cli
	curl -s --max-time 60 -b "$JAR" -o /dev/null "$URL/wp-admin/index.php"
	check B14 event_after_admin_load "$(event_count)"
	b14_round 'gate on, Plugins screen' screen
	curl -s --max-time 60 -b "$JAR" -o /dev/null "$URL/wp-admin/index.php"
	check B14 event_after_admin_load "$(event_count)"
	admin_login
	save_gate off
	b14_round 'gate off, wp plugin' cli
	b14_round 'gate off, Plugins screen' screen
	admin_login
	save_gate on
fi

# W3: the two reads write nothing.
write_snapshot "$ROOT/accept-backup-snap-a.txt"
status
list
write_snapshot "$ROOT/accept-backup-snap-b.txt"
if [ ! -s "$ROOT/accept-backup-snap-a.txt" ] || [ ! -s "$ROOT/accept-backup-snap-b.txt" ]; then
	check W3 snapshot "empty: the snapshot could not be read"
elif cmp -s "$ROOT/accept-backup-snap-a.txt" "$ROOT/accept-backup-snap-b.txt"; then
	check W3 snapshot identical
else
	check W3 snapshot "changed: $(diff "$ROOT/accept-backup-snap-a.txt" "$ROOT/accept-backup-snap-b.txt" | grep '^[<>]' | awk '{print $2}' | sort -u | tr '\n' ' ')"
fi

# B11: nothing in any answer names a path, an archive or a nonce.
leak=''
grep -q "$UPDRAFT_DIR" "$ALL_TEXT" && leak="$leak path"
grep -q '\.gz' "$ALL_TEXT" && leak="$leak .gz"
grep -q '\.zip' "$ALL_TEXT" && leak="$leak .zip"
while read -r n; do
	[ -n "$n" ] && grep -q "$n" "$ALL_TEXT" && leak="$leak nonce:$n"
done < "$NONCES"
check B11 leak "${leak:-none}"

# B12: the only backup tools are the two reads.
sid=$(session)
mcp_post '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' "$sid" >/dev/null
names=$(jq -r '.result.tools[].name' "$BODY" 2>/dev/null | grep backup | sort | tr '\n' ' ' | sed 's/ $//')
check B12 backup_tools "$names"
check B12 start_or_restore "$(jq -r '.result.tools[].name' "$BODY" 2>/dev/null | grep backup | grep -E 'run-backup|restore' | tr '\n' ' ' | sed 's/ $//' | grep . || echo none)"

finish
