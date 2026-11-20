#!/usr/bin/env bash
# Remove the disposable site of the Site Ops acceptance scripts. Run inside the DDEV web container:
#   ddev exec bash /var/www/plugin-src/tests/acceptance/siteops/teardown.sh
set -u
. "$(dirname "$0")/lib.sh"
acc_guard

pkill -f "php -S [^ ]*:$ACC_PORT" 2> /dev/null
mysql -hdb -uroot -proot -e "DROP DATABASE IF EXISTS \`$ACC_DB\`" 2> /dev/null
rm -rf "$ACC_ROOT"
echo "TEARDOWN OK: removed $ACC_ROOT and database $ACC_DB"
