#!/usr/bin/env bash
# Install the REAL, version-pinned vendor plugins the contract test suite runs against.
#
# These land in the throwaway WordPress test install ($WP_CORE_DIR, default /tmp/wordpress),
# NEVER in the DDEV avia bench at <repo>/wp. Green contract tests then prove the vendor symbol
# genuinely exists and behaves as the abilities assume, at the exact pinned version.
#
# Local (DDEV):  ddev exec tests/bin/install-vendors.sh
# CI:            tests/bin/install-vendors.sh   (WP_CORE_DIR exported by the workflow)
#
# Pins are the declared integration floors / behavioural cliffs (WooCommerce tracks the clone). Bump deliberately: the point of
# pinning is that "green" means "this exact contract", so a version change is a contract change.
set -euo pipefail

WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
PLUGINS_DIR="${WP_CORE_DIR%/}/wp-content/plugins"
FORCE="${FORCE:-0}"
# The contract matrix runs two WooCommerce legs: the declared 9.1.0 floor and the clone's 11.1.2.
WOOCOMMERCE_VERSION="${WOOCOMMERCE_VERSION:-11.1.2}"

# Guard: never install into the avia bench. The bench lives under the repo root at wp/; the test
# core must be an out-of-tree throwaway. Abort if the target resolves inside the repo.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TARGET_ABS="$(cd "$WP_CORE_DIR" 2>/dev/null && pwd || echo "$WP_CORE_DIR")"
case "$TARGET_ABS" in
	"$REPO_ROOT"|"$REPO_ROOT"/*)
		echo "REFUSING: WP_CORE_DIR ($TARGET_ABS) is inside the repo — that is the live bench, not the test core." >&2
		echo "Point WP_CORE_DIR at a throwaway WordPress install (e.g. /tmp/wordpress)." >&2
		exit 1
		;;
esac

if [ ! -d "$PLUGINS_DIR" ]; then
	echo "REFUSING: $PLUGINS_DIR does not exist — provision the WP test core first (bin/install-wp-tests.sh)." >&2
	exit 1
fi

# slug<TAB>version — one line per vendor. Versions are the contract pins (see plan doc 131 §work item 3).
VENDORS="
woocommerce	${WOOCOMMERCE_VERSION}
advanced-custom-fields	6.3.6
wordpress-seo	24.0
all-in-one-seo-pack	4.7.0
seo-by-rank-math	1.0.240
"

install_one() {
	local slug="$1" version="$2"
	local dest="${PLUGINS_DIR}/${slug}"

	# A present plugin is kept only when it was installed at this exact pin, so switching a pin
	# locally reinstalls instead of silently keeping the old version.
	if [ -d "$dest" ] && [ "$FORCE" != "1" ] && [ "$(cat "${dest}/.aafm-pin" 2>/dev/null)" = "$version" ]; then
		echo "  ${slug} ${version}: present, skipping (FORCE=1 to reinstall)"
		return 0
	fi

	local url="https://downloads.wordpress.org/plugin/${slug}.${version}.zip"
	local tmp
	tmp="$(mktemp -d)"
	echo "  ${slug} ${version}: downloading"
	curl -fsSL "$url" -o "${tmp}/plugin.zip"
	rm -rf "$dest"
	unzip -q "${tmp}/plugin.zip" -d "$PLUGINS_DIR"
	rm -rf "$tmp"
	if [ ! -d "$dest" ]; then
		echo "REFUSING: expected ${dest} after unzip of ${slug}; the zip layout changed." >&2
		exit 1
	fi
	printf '%s' "$version" > "${dest}/.aafm-pin"
}

echo "Installing pinned vendor plugins into ${PLUGINS_DIR}"
printf '%s\n' "$VENDORS" | while IFS=$'\t' read -r slug version; do
	[ -z "$slug" ] && continue
	install_one "$slug" "$version"
done
echo "Installed WooCommerce $(grep -m1 'Version:' "${PLUGINS_DIR}/woocommerce/woocommerce.php")"
echo "Vendor plugins ready."
