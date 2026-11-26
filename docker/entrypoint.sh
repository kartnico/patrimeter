#!/bin/bash
# Entry point of the Patrimeter image (docker/Dockerfile, docs/docker.md). First argument:
#   web        nginx and php-fpm (on a new version, backs up the database then applies the migrations);
#   scheduler  scheduled tasks (purges, database maintenance), like patrimeter-scheduler.service;
#   backup     daily backup of the database into /var/backups/patrimeter (patrimeter-backup);
#   selftest   check of the image when it is built (throwaway database, nothing is kept);
#   other      command run as is (patrimeter-console, patrimeter-restore, sh…).
set -euo pipefail

cd "$PATRIMETER_DIR"
ready="var/cache/prod/.patrimeter-ready"

console() {
    runuser -u www-data -- php bin/console "$@"
}

# Volume directories, cache rebuilt for this image, database up to date. Only the « web » container does it; the
# others wait until it is done for this version (file $ready, which holds the version), so as not to rebuild the
# cache at the same time nor to start on the previous version's.
prepare() {
    install -d -o www-data -g www-data -m 0700 var var/log var/uploads var/sessions
    rm -rf var/cache/prod
    chown -R www-data:www-data var
    console cache:warmup --no-interaction > /dev/null
    # New version (or migrations to apply) on an existing database: backup first, in the data volume (the three
    # latest are kept; restorable with patrimeter-restore). var/.patrimeter-version holds the last version started.
    if [ -s var/patrimeter.sqlite ] && { [ "$(cat var/.patrimeter-version 2> /dev/null)" != "$(cat VERSION)" ] \
        || ! console doctrine:migrations:up-to-date --no-interaction > /dev/null 2>&1; }; then
        echo "patrimeter : nouvelle version $(cat VERSION) ; sauvegarde de la base avant la mise à jour."
        PATRIMETER_BACKUP_DIR="$PATRIMETER_DIR/var/backups-before-update" PATRIMETER_BACKUP_KEEP=3 patrimeter-backup
    fi
    console doctrine:migrations:migrate --no-interaction --allow-no-migration
    cat VERSION > var/.patrimeter-version && chown www-data:www-data var/.patrimeter-version
    install -d -o www-data -g www-data /run/php
    cat VERSION > "$ready" && chown www-data:www-data "$ready"
}

wait_ready() {
    for _ in $(seq 1 120); do
        [ "$(cat "$ready" 2> /dev/null)" = "$(cat VERSION)" ] && return 0
        sleep 2
    done
    echo "patrimeter : le conteneur « web » n'a pas fini de démarrer (base et cache)." >&2
    exit 1
}

# nginx and php-fpm; the first one that stops stops the container, which its restart policy starts again.
serve() {
    php-fpm --nodaemonize --force-stderr &
    nginx -g 'daemon off;' &
    trap 'kill -TERM $(jobs -p) 2>/dev/null' TERM INT
    set +e
    wait -n
    status=$?
    kill -TERM $(jobs -p) 2>/dev/null
    wait
    return "$status"
}

case "${1:-web}" in
    web)
        prepare
        serve
        ;;
    scheduler)
        wait_ready
        exec runuser -u www-data -- php bin/console messenger:consume scheduler_maintenance \
            --time-limit=3600 --memory-limit=128M --no-interaction
        ;;
    backup)
        wait_ready
        while true; do
            # Backup, then full check of the archive just made (the monthly test restore of a server, here every day).
            if patrimeter-backup; then
                patrimeter-restore --check-latest || echo "patrimeter : la dernière archive ne passe pas le contrôle." >&2
            else
                echo "patrimeter : la sauvegarde a échoué ; nouvel essai dans une heure." >&2
            fi
            sleep "${PATRIMETER_BACKUP_INTERVAL:-86400}" || sleep 3600
        done
        ;;
    selftest)
        # Throwaway secrets, empty database: full start, sign-in page served, then everything is erased.
        export APP_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
        export AUTH_SECRET_KEY="$(php -r 'echo base64_encode(random_bytes(32));')"
        export DEFAULT_URI=http://localhost:8080
        prepare
        serve &
        server=$!
        for _ in $(seq 1 30); do
            if php -r 'exit(str_contains((string) @file_get_contents("http://127.0.0.1:8080/connexion"), "Patrimeter") ? 0 : 1);'; then
                ok=1
                break
            fi
            sleep 1
        done
        kill -TERM "$server" 2>/dev/null
        wait "$server" || true
        rm -rf var/* var/.[!.]* /run/php
        [ "${ok:-0}" = 1 ] || { echo "patrimeter : la page de connexion ne répond pas." >&2; exit 1; }
        echo "Image contrôlée : migrations appliquées, page de connexion servie."
        ;;
    *)
        exec "$@"
        ;;
esac
