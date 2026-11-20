#!/usr/bin/env bash
# Build the disposable site for the Site Ops acceptance scripts. Run inside the DDEV web container:
#   ddev exec bash /var/www/plugin-src/tests/acceptance/siteops/setup.sh [--subfolder]
# It installs the plugin from a git archive of HEAD, so commit before you run it.
# Never touches database `db` or /var/www/html.
set -u
. "$(dirname "$0")/lib.sh"
acc_guard

SUB=''
[ "${1:-}" = '--subfolder' ] && SUB='/sub'
ACC_WP="$ACC_ROOT$SUB"
ACC_URL="http://localhost:$ACC_PORT$SUB"

pkill -f "php -S [^ ]*:$ACC_PORT" 2> /dev/null
rm -rf "$ACC_ROOT"
mkdir -p "$ACC_WP" || exit 1
mysql -hdb -uroot -proot -e "DROP DATABASE IF EXISTS \`$ACC_DB\`; CREATE DATABASE \`$ACC_DB\`;" || exit 1

W core download ${ACC_WP_VERSION:+--version="$ACC_WP_VERSION"} || exit 1
W config create --dbname="$ACC_DB" --dbuser=root --dbpass=root --dbhost=db || exit 1
W config set WP_DEBUG true --raw
W config set WP_DEBUG_LOG true --raw
W config set WP_DEBUG_DISPLAY false --raw
W config set WP_ENVIRONMENT_TYPE local
[ "${ACC_WP_CRON:-0}" = 1 ] || W config set DISABLE_WP_CRON true --raw
W core install --url="$ACC_URL" --title=accept --admin_user=admin --admin_password=admin --admin_email=a@b.co --skip-email || exit 1
W rewrite structure '/%postname%/' || exit 1

# The two must-use plugins: the outbound logger and the write snapshot.
mkdir -p "$ACC_WP/wp-content/mu-plugins"
cat > "$ACC_WP/wp-content/mu-plugins/aafm-accept-outbound.php" << 'PHP'
<?php
// Site Ops acceptance: log every outbound HTTP request and refuse any that leaves this site.
// Runs at priority 1 and returns its first argument unchanged for a local request, so the
// plugin's own guard still sees and counts it. ACC_ALLOW_HTTP=1 switches it off for one command.
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( getenv( 'ACC_ALLOW_HTTP' ) ) {
			return $pre;
		}
		$parts   = wp_parse_url( (string) $url );
		$host    = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$port    = is_array( $parts ) && isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$path    = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/';
		$method  = isset( $args['method'] ) ? $args['method'] : 'GET';
		// This site's port only: inside the container, localhost on port 80 is the main site.
		$allowed = in_array( $host, array( 'localhost', '127.0.0.1' ), true ) && 8072 === $port;
		file_put_contents( '/tmp/aafm_accept_siteops/outbound.log', $method . ' ' . $host . ' ' . $path . ( $allowed ? '' : ' BLOCKED' ) . "\n", FILE_APPEND | LOCK_EX );
		return $allowed ? $pre : new WP_Error( 'blocked', 'Blocked by the acceptance outbound logger.' );
	},
	1,
	3
);
PHP
cat > "$ACC_WP/wp-content/mu-plugins/aafm-accept-snapshot.php" << 'PHP'
<?php
// Site Ops acceptance: one REST route that fingerprints everything a read must leave unchanged.
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'aafm-accept/v1',
			'/snapshot',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function () {
					global $wpdb;
					$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_N );
					return array(
						'options'    => md5( serialize( $options ) ),
						'cron'       => md5( serialize( _get_cron_array() ) ),
						'posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
						'postmeta'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
						'transients' => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' ORDER BY option_name" ),
					);
				},
			)
		);
	}
);
PHP
: > "$ACC_ROOT/outbound.log"

# Our plugin, from the export a release would ship.
PLUGIN_DIR="$ACC_WP/wp-content/plugins/agent-abilities-for-mcp"
mkdir -p "$PLUGIN_DIR" || exit 1
git -c safe.directory='*' -C "$ACC_REPO" archive HEAD | tar -x -C "$PLUGIN_DIR" || exit 1
[ -f "$PLUGIN_DIR/agent-abilities-for-mcp.php" ] || { echo "the export did not produce the plugin"; exit 1; }
W plugin activate agent-abilities-for-mcp || exit 1

# Enable every Site Ops ability this build registers, plus the gated tool of the backup cases.
ENABLED=$(W eval 'echo wp_json_encode( array_values( array_merge( array_filter( array_keys( aafm_get_abilities_registry_full() ), static function ( $name ) { return 0 === strpos( $name, "aafm/site-" ); } ), array( "aafm/delete-post" ) ) ) );')
[ -n "$ENABLED" ] || { echo "could not read the registry"; exit 1; }
W option update aafm_enabled_abilities "$ENABLED" --format=json || exit 1

# Fixtures.
mkpost() { # <type> <slug> <date> [parent id] [status]
	W post create --post_type="$1" --post_status="${5:-publish}" --post_title="$2" --post_name="$2" --post_date="$3" --post_parent="${4:-0}" --post_content="Fixture $2." --porcelain
}
ACC_ID_A=$(mkpost post acc-a '2025-01-10 10:00:00')
mkpost post acc-between-1 '2025-04-10 10:00:00' > /dev/null
mkpost post acc-between-2 '2025-06-10 10:00:00' > /dev/null
mkpost post acc-between-3 '2025-09-10 10:00:00' > /dev/null
ACC_ID_FAR=$(mkpost post acc-far '2026-01-10 10:00:00')
ACC_ID_PAGE=$(mkpost page acc-page '2025-02-01 10:00:00')
ACC_ID_PARENT=$(mkpost page acc-parent '2025-02-02 10:00:00')
ACC_ID_CHILD=$(mkpost page acc-child '2025-02-03 10:00:00' "$ACC_ID_PARENT")
ACC_ID_VAR=$(mkpost post acc-var '2025-03-01 10:00:00')
ACC_ID_CAFE=$(mkpost post 'café-été' '2025-03-02 10:00:00')
# WordPress strips accents from a Latin slug on save, so the accented slug is stored directly, in
# the lower-case percent-encoded form WordPress itself uses for a non-Latin slug.
W eval "global \$wpdb; \$wpdb->update( \$wpdb->posts, array( 'post_name' => strtolower( rawurlencode( 'café-été' ) ) ), array( 'ID' => $ACC_ID_CAFE ) ); clean_post_cache( $ACC_ID_CAFE );" || exit 1
ACC_ID_HINDI=$(mkpost post 'नमस्ते' '2025-03-03 10:00:00')
ACC_ID_DRAFT=$(mkpost post acc-draft '2025-03-04 10:00:00' 0 draft)
ACC_ID_PRIVATE=$(mkpost post acc-private '2025-03-05 10:00:00' 0 private)

ACC_APP_PW=$(W user application-password create admin siteops-accept --porcelain)
[ -n "$ACC_APP_PW" ] || { echo "no Application Password"; exit 1; }

{
	echo "ACC_WP='$ACC_WP'"
	echo "ACC_URL='$ACC_URL'"
	echo "ACC_APP_PW='$ACC_APP_PW'"
	for name in A FAR PAGE PARENT CHILD VAR CAFE HINDI DRAFT PRIVATE; do
		eval "echo \"ACC_ID_$name=\$ACC_ID_$name\""
	done
	for i in $(seq 1 20); do
		echo "ACC_ID_GATE_$i=$(mkpost post "acc-gate-$i" '2025-05-01 10:00:00')"
	done
} > "$ACC_ROOT/site.env"

(PHP_CLI_SERVER_WORKERS=4 php -S "0.0.0.0:$ACC_PORT" -t "$ACC_ROOT" > "$ACC_ROOT/server.log" 2>&1 &)
sleep 2

front=$(curl -s -o /dev/null -w '%{http_code}' "$ACC_URL/")
post=$(curl -s -o /dev/null -w '%{http_code}' "$ACC_URL/acc-a/")
cafe=$(curl -s -o /dev/null -w '%{http_code}' "$ACC_URL/caf%c3%a9-%c3%a9t%c3%a9/")
echo "front $front, acc-a $post, cafe $cafe"
echo "enabled: $ENABLED"
grep -o "wp_version = '[^']*'" "$ACC_WP/wp-includes/version.php"
if [ "$front" != 200 ] || [ "$post" != 200 ] || [ "$cafe" != 200 ]; then
	echo "SETUP FAILED: the disposable site does not answer"
	exit 1
fi
echo "SETUP OK: $ACC_URL (docroot $ACC_ROOT, database $ACC_DB)"
