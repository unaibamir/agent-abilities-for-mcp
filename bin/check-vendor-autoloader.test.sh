#!/usr/bin/env bash
# Test for bin/check-vendor-autoloader.sh. Never touches the real git index or
# the real vendor/ files: uses --stdin mode, which the script under test reads
# one file's content from stdin with no git or filesystem interaction at all.
#
#   bash bin/check-vendor-autoloader.test.sh
set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOL="$HERE/check-vendor-autoloader.sh"

failures=0
ok()  { echo "  PASS  $1"; }
bad() { echo "  FAIL  $1 -- $2"; failures=$((failures+1)); }

if [ ! -x "$TOOL" ]; then
  bad "tool exists" "$TOOL is missing or not executable"
  echo ""
  echo "TEST-CHECK-VENDOR-AUTOLOADER: FAIL ($failures)"
  exit 1
fi

echo "== 1. a clean autoloader (wordpress + composer only) passes =="
clean="<?php
class X {
    public static \$classMap = array(
        'Composer\\\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'WP\\\\MCP\\\\Core\\\\McpAdapter' => __DIR__ . '/..' . '/wordpress/mcp-adapter/includes/Core/McpAdapter.php',
    );
}"
if out="$(printf '%s' "$clean" | "$TOOL" --stdin 2>&1)"; then
  ok "clean content exits 0"
else
  bad "clean content" "expected exit 0, got non-zero. Output: $out"
fi

echo "== 2. a dev-regenerated autoloader (references vendor/phpunit) fails =="
bad_content="<?php
class X {
    public static \$classMap = array(
        'Composer\\\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'PHPUnit\\\\Framework\\\\TestCase' => __DIR__ . '/..' . '/phpunit/phpunit/src/Framework/TestCase.php',
    );
}"
out="$(printf '%s' "$bad_content" | "$TOOL" --stdin 2>&1)"
rc=$?
if [ "$rc" -ne 0 ]; then
  ok "bad content exits non-zero"
else
  bad "bad content exit code" "expected non-zero, got 0"
fi
if printf '%s' "$out" | grep -q "phpunit"; then
  ok "error message names the offending package (phpunit)"
else
  bad "error message" "did not mention 'phpunit'. Output: $out"
fi

echo "== 3. the REAL tracked autoloader files pass right now (regression check) =="
if out="$("$TOOL" --worktree 2>&1)"; then
  ok "the real vendor/composer/autoload_static.php and autoload_real.php are clean today"
else
  bad "real files" "the actual tracked autoloader failed the guard: $out"
fi

echo ""
if [ "$failures" -gt 0 ]; then
  echo "TEST-CHECK-VENDOR-AUTOLOADER: FAIL ($failures)"
  exit 1
fi
echo "TEST-CHECK-VENDOR-AUTOLOADER: PASS"
exit 0
