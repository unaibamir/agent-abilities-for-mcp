#!/usr/bin/env bash
# Installs the WordPress PHPUnit test library + a throwaway test database.
# Intended to run inside the DDEV web container: ddev exec bin/install-wp-tests.sh
set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-db}"
DB_PASS="${3:-db}"
DB_HOST="${4:-db}"
WP_VERSION="${5:-latest}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

download() { curl -fsSL "$1" > "$2"; }

if [ "$WP_VERSION" = "latest" ]; then
	WP_TESTS_TAG="trunk"
else
	WP_TESTS_TAG="tags/${WP_VERSION}"
fi

install_test_suite() {
	if [ ! -d "$WP_TESTS_DIR" ]; then
		mkdir -p "$WP_TESTS_DIR"
		svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "${WP_TESTS_DIR}/includes" || true
		svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "${WP_TESTS_DIR}/data" || true
	fi
	if [ ! -f "${WP_TESTS_DIR}/wp-tests-config.php" ]; then
		download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed -i "s/youremptytestdbnamehere/${DB_NAME}/" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed -i "s/yourusernamehere/${DB_USER}/" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed -i "s/yourpasswordhere/${DB_PASS}/" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed -i "s|localhost|${DB_HOST}|" "${WP_TESTS_DIR}/wp-tests-config.php"
		sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "${WP_TESTS_DIR}/wp-tests-config.php"
	fi
}

install_db() {
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
}

install_test_suite
install_db
echo "WP test library ready at ${WP_TESTS_DIR}"
