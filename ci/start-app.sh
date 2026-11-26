#!/bin/sh
# Prepares the application in production mode (new database, migrations, compiled assets, test users) then starts
# it on http://localhost:$APP_PORT.
#
# Usage: sh ci/start-app.sh [--background]
# Variables: PHP, APP_PORT, SEED_USERS, SEED_YEARS, SEED_ENGLISH_USERS, PHP_CLI_SERVER_WORKERS, SMTP_HOST.
set -eu
cd "$(dirname "$0")/.."

rm -rf var/e2e var/cache/prod var/cache/seed public/assets
. ci/app-env.sh

$PHP bin/console doctrine:migrations:migrate --no-interaction --quiet
$PHP bin/console asset-map:compile --quiet
$PHP tests/e2e/seed.php "${SEED_USERS:-16}" "${SEED_YEARS:-2}" "${SEED_ENGLISH_USERS:-0}"

export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
# Surý's php.ini (variables_order=GPCS) hides the environment from php -S: without this setting, .env wins and the
# application runs in dev mode on another database.
server="$PHP -d variables_order=EGPCS -S 127.0.0.1:$APP_PORT -t public tools/dev-server-router.php"
if [ "${1:-}" != "--background" ]; then
    exec $server
fi

$server > var/e2e/server.log 2>&1 &
echo $! > var/e2e/server.pid
$PHP -r '
$url = $argv[1];
for ($i = 0; $i < 120; ++$i) {
    if (@file_get_contents($url) !== false) {
        exit(0);
    }
    usleep(500000);
}
fwrite(STDERR, "Application injoignable : $url\n");
exit(1);
' "$DEFAULT_URI/connexion"
echo "Application prête : $DEFAULT_URI"
