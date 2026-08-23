#!/usr/bin/env bash
# Guard: the committed composer autoloader may reference only the shipped
# vendor set (vendor/wordpress, vendor/composer). Every other vendor
# subdirectory is dev-only and excluded from the release zip by .distignore,
# so any OTHER reference means the autoloader was regenerated with dev
# dependencies present - the fb9e16f class of bug: a `require` that resolves
# locally (the dev packages are on disk during a dev commit) but does not
# exist in the shipped tree, fatalling every install.
#
# Two source shapes are checked, because Composer emits vendor paths
# differently across the generated files:
#   __DIR__ . '/..' . '/<package>/...'   (autoload_static.php, autoload_real.php)
#   $vendorDir . '/<package>/...'         (autoload_classmap.php, autoload_psr4.php,
#                                          autoload_namespaces.php, autoload_files.php)
#
# Scope depends on composer.json's config.optimize-autoloader: true. With it
# set, autoload_real.php's getLoader() only ever requires autoload_static.php
# at runtime, so autoload_classmap.php/autoload_psr4.php/autoload_files.php
# are not loaded and could not fatal an install on their own - they are
# checked anyway as defence in depth, so a dev-polluted copy of one can never
# sit committed waiting to be `git add`ed by someone in a hurry. If that flag
# is ever turned off, re-verify this file list is still the right one.
#
# This guard only runs locally, and only in a checkout where .git/hooks/pre-commit
# is wired to call it (git never tracks .git/hooks/, so a fresh clone or another
# contributor gets none of it, and --no-verify bypasses it entirely). The real,
# unbypassable backstop is CI: .github/workflows/test.yml's `lint74` job exports
# `git archive HEAD` (line 227), requires the shipped vendor/autoload.php (line 258),
# and asserts WP\MCP\Core\McpAdapter resolves from it (line 267) - that step fails
# the same class of bug server-side even if this hook never ran.
#
#   bin/check-vendor-autoloader.sh              check the STAGED (index) files
#   bin/check-vendor-autoloader.sh --worktree    check the working-copy files instead
#   bin/check-vendor-autoloader.sh --stdin       read ONE autoloader's content from
#                                                 stdin (tests: no git or filesystem
#                                                 interaction at all)
#   bin/check-vendor-autoloader.sh --list        read ONE autoloader's content from
#                                                 stdin and print every vendor package
#                                                 name the extraction step found, one
#                                                 per line, unfiltered (tests only: lets
#                                                 a test prove the matcher actually
#                                                 matched something, not that it silently
#                                                 matched nothing).
#
# Exit 0: clean. Exit 1: a reference to a non-shipped vendor package was
# found, printed to stderr, one per offending file.

set -u

MODE="staged"
case "${1:-}" in
  --worktree) MODE="worktree" ;;
  --stdin)    MODE="stdin" ;;
  --list)     MODE="list" ;;
esac

# Extract every vendor package name a composer autoloader's __DIR__/$vendorDir
# concatenation form references, deduped and sorted, unfiltered (no allow/deny
# applied here - that happens in check_content()).
extract_packages() {
	local content="$1"
	{
		printf '%s\n' "$content" \
			| grep -oE "__DIR__ \. '/\.\.' \. '/[A-Za-z0-9_.-]+" \
			| sed -E "s#.*\. '/##"
		printf '%s\n' "$content" \
			| grep -oE "\\\$vendorDir \. '/[A-Za-z0-9_.-]+" \
			| sed -E "s#.*'/##"
	} | sort -u
}

check_content() {
	local label="$1"
	local content="$2"
	local bad
	bad="$(extract_packages "$content" | grep -vE '^(wordpress|composer)$' || true)"
	if [ -n "$bad" ]; then
		echo "✗ $label references vendor package(s) outside the shipped set (vendor/wordpress, vendor/composer):" >&2
		echo "$bad" | sed 's/^/    - vendor\//' >&2
		return 1
	fi
	return 0
}

if [ "$MODE" = "list" ]; then
	content="$(cat)"
	extract_packages "$content"
	exit 0
fi

if [ "$MODE" = "stdin" ]; then
	content="$(cat)"
	if ! check_content "<stdin>" "$content"; then
		echo "" >&2
		echo "This is the fb9e16f class of bug: a dev-regenerated autoloader references" >&2
		echo "packages that are not in the release zip and fatal on activation." >&2
		echo "Fix with: composer install --no-dev && composer dump-autoload --no-dev" >&2
		exit 1
	fi
	exit 0
fi

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$REPO_ROOT" || exit 1

FILES="vendor/composer/autoload_static.php vendor/composer/autoload_real.php vendor/composer/autoload_classmap.php vendor/composer/autoload_psr4.php vendor/composer/autoload_namespaces.php vendor/composer/autoload_files.php"
fail=0

for f in $FILES; do
	if [ "$MODE" = "staged" ]; then
		content="$(git show ":$f" 2>/dev/null)"
	else
		content="$(cat "$f" 2>/dev/null)"
	fi
	[ -z "$content" ] && continue

	if ! check_content "$f" "$content"; then
		fail=1
	fi
done

if [ "$fail" -eq 1 ]; then
	echo "" >&2
	echo "This is the fb9e16f class of bug: a dev-regenerated autoloader references" >&2
	echo "packages that are not in the release zip and fatal on activation." >&2
	echo "Fix with: composer install --no-dev && composer dump-autoload --no-dev" >&2
	exit 1
fi
exit 0
