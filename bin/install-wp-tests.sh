#!/usr/bin/env bash
# Installs WordPress core + the PHPUnit test library + a throwaway test database.
# Intended to run inside the DDEV web container: ddev exec bin/install-wp-tests.sh
#
# Mirrors .github/workflows/test.yml's provisioning step so a local run and CI can never
# silently diverge: "latest" resolves to a concrete version (so core and the test library stay
# in lockstep), "trunk" pulls the nightly build, everything else pulls that exact tagged
# release. Never point WP_CORE_DIR at this repo's own wp/ - that tree is a full site clone with
# third-party mu-plugins that fatal during the WP test bootstrap; this script always provisions
# clean core under /tmp.
set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-db}"
DB_PASS="${3:-db}"
DB_HOST="${4:-db}"
WP_VERSION="${5:-latest}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

if [ "$WP_VERSION" = "latest" ]; then
	WP_VERSION="$(curl -fsSL https://api.wordpress.org/core/version-check/1.7/ \
		| grep -o '"version":"[0-9.]*"' | head -1 | grep -o '[0-9.]*')"
	echo "resolved \"latest\" to WordPress ${WP_VERSION}"
fi
if [ "$WP_VERSION" = "trunk" ]; then
	TAG="trunk"
else
	TAG="tags/${WP_VERSION}"
fi

install_core() {
	if [ -f "${WP_CORE_DIR}/wp-settings.php" ]; then
		return
	fi
	rm -rf "$WP_CORE_DIR"
	mkdir -p "$WP_CORE_DIR"
	if [ "$WP_VERSION" = "trunk" ]; then
		curl -fsSL https://wordpress.org/nightly-builds/wordpress-latest.zip -o /tmp/wp.zip
		unzip -q /tmp/wp.zip -d /tmp/
		mv /tmp/wordpress/* "$WP_CORE_DIR/"
	else
		curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o /tmp/wp.tgz
		tar --strip-components=1 -zxmf /tmp/wp.tgz -C "$WP_CORE_DIR"
	fi
	# Verify rather than assume: a failed download/extract must not be reported as success.
	test -f "${WP_CORE_DIR}/wp-settings.php" || {
		echo "FAIL: WordPress core did not install at ${WP_CORE_DIR}" >&2
		exit 1
	}
}

install_test_suite() {
	if [ ! -f "${WP_TESTS_DIR}/wp-tests-config.php" ] || [ ! -d "${WP_TESTS_DIR}/includes" ]; then
		rm -rf "$WP_TESTS_DIR"
		mkdir -p "$WP_TESTS_DIR"
		# --force, and no `|| true`: a failed export must stop the script, not leave an empty
		# directory behind that a later run's existence check mistakes for "already installed".
		svn export --quiet --force "https://develop.svn.wordpress.org/${TAG}/tests/phpunit/includes/" "${WP_TESTS_DIR}/includes"
		svn export --quiet --force "https://develop.svn.wordpress.org/${TAG}/tests/phpunit/data/" "${WP_TESTS_DIR}/data"
		curl -fsSL "https://develop.svn.wordpress.org/${TAG}/wp-tests-config-sample.php" -o "${WP_TESTS_DIR}/wp-tests-config.php"

		sed -i "s/youremptytestdbnamehere/${DB_NAME}/" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed -i "s/yourusernamehere/${DB_USER}/" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed -i "s/yourpasswordhere/${DB_PASS}/" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed -i "s|localhost|${DB_HOST}|" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "${WP_TESTS_DIR}/wp-tests-config.php"
	fi
	# Verify rather than assume: prove the export actually landed before declaring "ready".
	test -d "${WP_TESTS_DIR}/includes" || {
		echo "FAIL: test library includes missing at ${WP_TESTS_DIR}/includes" >&2
		exit 1
	}
	test -f "${WP_TESTS_DIR}/wp-tests-config.php" || {
		echo "FAIL: wp-tests-config.php missing at ${WP_TESTS_DIR}" >&2
		exit 1
	}
}

install_db() {
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
}

install_core
install_test_suite
install_db
echo "WordPress ${WP_VERSION} core ready at ${WP_CORE_DIR}, test library ready at ${WP_TESTS_DIR}"
