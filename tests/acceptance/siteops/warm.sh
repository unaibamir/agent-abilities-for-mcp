#!/usr/bin/env bash
# Warm pages of the disposable acceptance site: request each URL twice, the way a browser would,
# and list the cache files that appeared. Run inside the DDEV web container.
#
#   bash tests/acceptance/siteops/warm.sh [options] <url> [<url> ...]
#
#   --both-spellings   also request each URL that has percent escapes with upper-case escapes
#   --mobile           send an iPhone user agent instead of a desktop one
#   --https            send X-Forwarded-Proto: https
#   --cookie <c>       send this Cookie header (a logged-in visitor)
#   --via <origin>     send the requests to this origin instead of the URL's own, with the Host
#                      header in --host (used to go through the second nginx for Nginx Helper)
#   --host <name>      the Host header for --via
#   --quiet            do not list new files
#
# Prints one line per request, "<status> <url>". Exits 1 when a request did not answer 200 or 302.
set -u
ROOT=/tmp/aafm_accept_siteops
[ "${IS_DDEV_PROJECT:-}" = true ] || { echo "warm.sh: refusing, not inside the DDEV web container"; exit 2; }
[ -f "$ROOT/wp-config.php" ] || { echo "warm.sh: refusing, the disposable site $ROOT does not exist"; exit 2; }

UA='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'
UA_MOBILE='Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
BOTH=0; QUIET=0; VIA=''; HOST=''; EXTRA=()
URLS=()
while [ $# -gt 0 ]; do
	case "$1" in
		--both-spellings) BOTH=1 ;;
		--mobile) UA="$UA_MOBILE" ;;
		--https) EXTRA+=(-H 'X-Forwarded-Proto: https') ;;
		--cookie) shift; EXTRA+=(-b "$1") ;;
		--via) shift; VIA="$1" ;;
		--host) shift; HOST="$1" ;;
		--quiet) QUIET=1 ;;
		*) URLS+=("$1") ;;
	esac
	shift
done
[ ${#URLS[@]} -gt 0 ] || { echo "warm.sh: no URL given"; exit 2; }

MARK=$(mktemp)
sleep 1
FAILED=0
hit() {
	local url="$1" target="$1" code='' host=()
	if [ -n "$VIA" ]; then
		target="$VIA/${url#*://*/}"
		host=(-H "Host: $HOST")
	fi
	for _ in 1 2; do
		code=$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -A "$UA" -H 'Accept: text/html' \
			${host[@]+"${host[@]}"} ${EXTRA[@]+"${EXTRA[@]}"} "$target")
	done
	echo "$code $url"
	case "$code" in 200|302) ;; *) FAILED=1 ;; esac
}
for u in "${URLS[@]}"; do
	hit "$u"
	if [ "$BOTH" = 1 ] && [[ "$u" == *%* ]]; then
		hit "$(python3 -c 'import re,sys; print(re.sub(r"%[0-9a-fA-F]{2}", lambda m: m.group(0).upper(), sys.argv[1]))' "$u")"
	fi
done
if [ "$QUIET" = 0 ]; then
	echo "new cache files:"
	find "$ROOT/wp-content" /tmp/aafm_accept_ngx/cache -type f -newer "$MARK" \
		-not -path '*/plugins/*' -not -path '*/mu-plugins/*' -not -path '*/uploads/*' -not -name '*.log' 2>/dev/null | sort | head -60 | sed 's/^/  /'
fi
rm -f "$MARK"
exit $FAILED
