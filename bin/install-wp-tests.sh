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

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "FAIL: required command '$1' is not installed." >&2
		echo "  $2" >&2
		exit 1
	fi
}

# The version core actually has on disk, as opposed to the one that was asked for.
core_version_on_disk() {
	sed -n "s/^\$wp_version = '\([^']*\)';.*/\1/p" \
		"${WP_CORE_DIR}/wp-includes/version.php" 2>/dev/null | head -1
}

# Reusing an existing WP_CORE_DIR is the fast path, but it is also how this script came to
# "lie about the version it installed": the early return below fires whenever ANY core is
# present, so asking for 6.9 on top of a 7.0.3 tree silently kept 7.0.3 and then announced
# 6.9. Refuse instead of guessing - re-extracting would destroy a tree another run may be
# using, and continuing would hand back a library that does not match its own label.
verify_core_version() {
	if [ "$WP_VERSION" = "trunk" ]; then
		return
	fi
	local found
	found="$(core_version_on_disk)"
	if [ "$found" != "$WP_VERSION" ]; then
		echo "FAIL: ${WP_CORE_DIR} already holds WordPress ${found:-an unreadable version}, not the requested ${WP_VERSION}." >&2
		echo "  Point WP_CORE_DIR and WP_TESTS_DIR at a different pair of directories, or remove ${WP_CORE_DIR} first." >&2
		exit 1
	fi
}

install_core() {
	if [ -f "${WP_CORE_DIR}/wp-settings.php" ]; then
		verify_core_version
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
	verify_core_version
}

install_test_suite() {
	if [ ! -f "${WP_TESTS_DIR}/wp-tests-config.php" ] || [ ! -d "${WP_TESTS_DIR}/includes" ]; then
		# Checked here rather than at the top so a re-run against an already-exported library
		# does not demand a tool it will not use. The DDEV web image does not ship Subversion,
		# and any container rebuild (changing php_version rebuilds it) drops a hand-installed
		# copy, so this fires more often than it looks like it should.
		require_command svn "Install it inside the container: sudo apt-get update && sudo apt-get install -y subversion"
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
	require_command mysqladmin "It ships with the MariaDB/MySQL client package."
	# This was a bare `mysqladmin create ... 2>/dev/null || true`, which swallowed every
	# failure while the success line at the bottom of this script printed unconditionally. A
	# DDEV run whose `db` user holds only USAGE on *.* therefore reported "ready" and then died
	# much later inside PHPUnit with "Cannot select database". tests/contract/bootstrap.php
	# already documents that privilege constraint; the installer never knew about it.
	local create_output
	if ! create_output="$(mysqladmin create "$DB_NAME" \
		--user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>&1)"; then
		# An already-existing database is the normal re-run path. Anything else is not.
		if ! printf '%s' "$create_output" | grep -qi "database exists"; then
			echo "FAIL: could not create database '${DB_NAME}' as user '${DB_USER}'." >&2
			echo "  mysqladmin said: ${create_output}" >&2
			echo "  Under DDEV the '${DB_USER}' user usually cannot CREATE DATABASE. Create it once as root:" >&2
			echo "    mysql -uroot -proot -h ${DB_HOST} -e 'CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`; GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO \"${DB_USER}\"@\"%\"; FLUSH PRIVILEGES;'" >&2
			exit 1
		fi
	fi
	# Verify rather than assume, the same as core and the test library above: being allowed to
	# create a database is not the same as being allowed to select it, and it is the select
	# that the test bootstrap actually needs.
	if ! mysql --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" \
		--execute="USE \`${DB_NAME}\`;" >/dev/null 2>&1; then
		echo "FAIL: database '${DB_NAME}' exists but user '${DB_USER}' cannot select it." >&2
		echo "  Grant it as root: GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO \"${DB_USER}\"@\"%\";" >&2
		exit 1
	fi
}

install_core
install_test_suite
install_db
echo "WordPress ${WP_VERSION} core ready at ${WP_CORE_DIR}, test library ready at ${WP_TESTS_DIR}"
