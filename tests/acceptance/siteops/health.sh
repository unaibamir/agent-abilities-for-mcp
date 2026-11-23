#!/usr/bin/env bash
# The health acceptance case: the three health tools write nothing and send nothing.
# Run inside the DDEV web container, after setup.sh:
#   ddev exec bash /var/www/plugin-src/tests/acceptance/siteops/health.sh
set -u
. "$(dirname "$0")/lib.sh"
acc_guard
acc_load

TOOLS='aafm/site-health-status aafm/site-list-updates aafm/site-cron-status'

# Warm up: one call of each, not measured.
for tool in $TOOLS; do
	tools_call "$tool" '{}' > /dev/null
done

outbound_reset
snapshot "$ACC_ROOT/snap-before.json"

for tool in $TOOLS; do
	if tools_call "$tool" '{}' && ! call_refused; then
		pass health "$tool answered"
	else
		fail health "$tool did not answer: $(call_text | head -c 200) $(head -c 200 "$ACC_ROOT/body.json" | tr '\n' ' ')"
	fi
done

tools_call aafm/site-health-status '{}'
count=$(call_field '.tests | length')
if [ "${count:-0}" -ge 10 ] 2> /dev/null; then
	pass health "site-health-status returned $count checks"
else
	fail health "site-health-status returned '$count' checks, wanted at least 10"
fi

snapshot "$ACC_ROOT/snap-after.json"
if [ -s "$ACC_ROOT/snap-before.json" ] && cmp -s "$ACC_ROOT/snap-before.json" "$ACC_ROOT/snap-after.json"; then
	pass health "write snapshot identical before and after"
else
	fail health "write snapshot changed: $(diff "$ACC_ROOT/snap-before.json" "$ACC_ROOT/snap-after.json" | head -c 400 | tr '\n' ' ')"
fi

if [ -s "$ACC_ROOT/outbound.log" ]; then
	fail health "outbound log is not empty: $(outbound_log | head -5 | tr '\n' ';')"
else
	pass health "outbound log is empty"
fi

finish health
