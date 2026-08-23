#!/usr/bin/env bash
# Test for bin/check-vendor-autoloader.sh. Sections 1-3 never touch the real
# git index or the real vendor/ files: they use --stdin/--list mode, which
# read one file's content from stdin with no git or filesystem interaction at
# all. Section 4 checks the real tracked tree read-only. Section 5 DOES touch
# the real git index and the real vendor/composer/autoload_static.php on
# disk, deliberately and temporarily, to prove the mode the pre-commit hook
# actually calls (no flag = staged) catches corruption - it always restores
# both before exiting, success or failure.
#
#   bash bin/check-vendor-autoloader.test.sh
set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOL="$HERE/check-vendor-autoloader.sh"
REPO_ROOT="$(cd "$HERE/.." && pwd)"

failures=0
ok()  { echo "  PASS  $1"; }
bad() { echo "  FAIL  $1 -- $2"; failures=$((failures+1)); }

if [ ! -x "$TOOL" ]; then
  bad "tool exists" "$TOOL is missing or not executable"
  echo ""
  echo "TEST-CHECK-VENDOR-AUTOLOADER: FAIL ($failures)"
  exit 1
fi

echo "== 1. a clean autoloader (wordpress + composer only) passes, and the matcher actually matched something =="
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
# A regex that stops matching anything (Composer changes its generated format,
# a typo lands in the pattern) would also make the clean case exit 0 - not
# because the file is clean, but because the check never ran. Assert the
# extraction step actually found the known-good entries, so format drift
# fails loudly instead of silently passing.
matches="$(printf '%s' "$clean" | "$TOOL" --list 2>&1)"
if printf '%s\n' "$matches" | grep -qx "wordpress" && printf '%s\n' "$matches" | grep -qx "composer"; then
  ok "the matcher's extraction step actually found wordpress and composer (not silently matching nothing)"
else
  bad "matcher extraction" "expected 'wordpress' and 'composer' in the matched-package list; got: $matches"
fi

echo "== 2. a dev-regenerated autoloader, __DIR__ form (autoload_static.php/autoload_real.php shape), fails =="
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

echo "== 3. a dev-regenerated autoloader, \$vendorDir form (autoload_classmap.php/autoload_psr4.php/autoload_files.php shape), fails =="
# This is the exact shape that slipped past the guard before it was widened:
# vendor/composer/autoload_files.php references dev packages this way, and
# the __DIR__-only pattern never matched it.
vendordir_bad="<?php
\$vendorDir = dirname(__DIR__);
\$baseDir = dirname(\$vendorDir);
return array(
    '6124b4c8570aa390c21fafd04a26c69f' => \$vendorDir . '/myclabs/deep-copy/src/DeepCopy/deep_copy.php',
    'ec07570ca5a812141189b1fa81503674' => \$vendorDir . '/phpunit/phpunit/src/Framework/Assert/Functions.php',
);"
out="$(printf '%s' "$vendordir_bad" | "$TOOL" --stdin 2>&1)"
rc=$?
if [ "$rc" -ne 0 ]; then
  ok "\$vendorDir-form bad content exits non-zero"
else
  bad "\$vendorDir-form bad content exit code" "expected non-zero, got 0"
fi
if printf '%s' "$out" | grep -q "myclabs" && printf '%s' "$out" | grep -q "phpunit"; then
  ok "error message names both offending packages (myclabs, phpunit)"
else
  bad "error message" "did not mention both 'myclabs' and 'phpunit'. Output: $out"
fi

echo "== 4. the REAL tracked autoloader files pass right now (regression check) =="
if out="$("$TOOL" --worktree 2>&1)"; then
  ok "the real tracked vendor/composer/*.php autoloader files are clean today"
else
  bad "real files" "the actual tracked autoloader failed the guard: $out"
fi

echo "== 5. the STAGED-INDEX mode (no flag - what the pre-commit hook actually calls) catches corruption =="
# Steps 5 and 7 of task 14 proved this by hand, once. This persists it as a
# repeatable regression test: break the git show ":file" branch in a future
# refactor and this section goes red, instead of the suite staying green
# while the mode production actually uses is never exercised.
AUTOLOADER="$REPO_ROOT/vendor/composer/autoload_static.php"
if ! git -C "$REPO_ROOT" diff --quiet -- "$AUTOLOADER" || ! git -C "$REPO_ROOT" diff --cached --quiet -- "$AUTOLOADER"; then
  bad "staged-mode precondition" "vendor/composer/autoload_static.php is not clean before this section (uncommitted changes present) - skipping rather than risk clobbering real work"
else
  printf '%s\n' "        'Test\\\\Fixture' => __DIR__ . '/..' . '/phpunit/phpunit/src/Framework/TestCase.php'," >> "$AUTOLOADER"
  git -C "$REPO_ROOT" add "$AUTOLOADER"

  staged_out="$("$TOOL" 2>&1)"
  staged_rc=$?

  git -C "$REPO_ROOT" restore --staged --worktree -- "$AUTOLOADER"

  if [ "$staged_rc" -ne 0 ]; then
    ok "default (staged) mode exits non-zero on a corrupted staged autoloader"
  else
    bad "default (staged) mode exit code" "expected non-zero, got 0"
  fi
  if printf '%s' "$staged_out" | grep -q "phpunit"; then
    ok "default (staged) mode names the offending package (phpunit)"
  else
    bad "default (staged) mode message" "did not mention 'phpunit'. Output: $staged_out"
  fi
  if git -C "$REPO_ROOT" diff --quiet -- "$AUTOLOADER" && git -C "$REPO_ROOT" diff --cached --quiet -- "$AUTOLOADER"; then
    ok "the staged corruption was restored cleanly"
  else
    bad "restore" "vendor/composer/autoload_static.php was not restored cleanly after this section"
  fi
fi

echo ""
if [ "$failures" -gt 0 ]; then
  echo "TEST-CHECK-VENDOR-AUTOLOADER: FAIL ($failures)"
  exit 1
fi
echo "TEST-CHECK-VENDOR-AUTOLOADER: PASS"
exit 0
