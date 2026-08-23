#!/usr/bin/env bash
# Guard: the committed composer autoloader may reference only the shipped
# vendor set (vendor/wordpress, vendor/composer). Every other vendor
# subdirectory is dev-only and excluded from the release zip by .distignore,
# so any OTHER reference means the autoloader was regenerated with dev
# dependencies present - the fb9e16f class of bug: a `require` that resolves
# locally (the dev packages are on disk during a dev commit) but does not
# exist in the shipped tree, fatalling every install.
#
#   bin/check-vendor-autoloader.sh              check the STAGED (index) files
#   bin/check-vendor-autoloader.sh --worktree    check the working-copy files instead
#   bin/check-vendor-autoloader.sh --stdin       read ONE autoloader's content from
#                                                 stdin (tests: no git or filesystem
#                                                 interaction at all)
#
# Exit 0: clean. Exit 1: a reference to a non-shipped vendor package was
# found, printed to stderr, one per offending file.

set -u

MODE="staged"
case "${1:-}" in
  --worktree) MODE="worktree" ;;
  --stdin)    MODE="stdin" ;;
esac

check_content() {
	local label="$1"
	local content="$2"
	local bad
	bad="$(printf '%s\n' "$content" \
		| grep -oE "__DIR__ \. '/\.\.' \. '/[A-Za-z0-9_.-]+" \
		| sed -E "s#.*\. '/##" \
		| sort -u \
		| grep -vE '^(wordpress|composer)$' || true)"
	if [ -n "$bad" ]; then
		echo "✗ $label references vendor package(s) outside the shipped set (vendor/wordpress, vendor/composer):" >&2
		echo "$bad" | sed 's/^/    - vendor\//' >&2
		return 1
	fi
	return 0
}

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

FILES="vendor/composer/autoload_static.php vendor/composer/autoload_real.php"
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
