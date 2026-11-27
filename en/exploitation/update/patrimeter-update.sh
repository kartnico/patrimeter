#!/bin/sh
# Updates Patrimeter from a release archive (patrimeter-vX.Y.Z.tar.gz, downloaded by the administrator): checksum,
# services stopped, verified snapshot of the database, new code next to the old one, migrations and systemd units,
# restart, checks. The previous code is kept for a rollback.
# Documentation: docs/mise-a-jour.md. Install as /usr/local/sbin/patrimeter-update.
#
# Usage: patrimeter-update <archive.tar.gz>   installs this archive (its .sha256 file, next to it, is checked)
#        patrimeter-update --rollback          back to the previous version and to the database of before the update
#
# Variables: PATRIMETER_DIR (/srv/patrimeter), PATRIMETER_BACKUP_DIR (/var/backups/patrimeter), PHP (PHP version,
# otherwise that of the php command).
#
# Two phases: this script checks, stops writers, backs up and swaps the code, then hands over to the script of the NEW
# version (--apply option), which knows the steps of that version.
set -eu

project="${PATRIMETER_DIR:-/srv/patrimeter}"
backups="${PATRIMETER_BACKUP_DIR:-/var/backups/patrimeter}"
previous="$project.previous"
phase=prepare
archive=

fail() {
    echo "patrimeter-update : $*" >&2
    exit 1
}

for argument in "$@"; do
    case "$argument" in
        --apply) phase=apply ;;
        --rollback) phase=rollback ;;
        -h | --help)
            sed -n '7,11p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        -*) fail "option inconnue : $argument." ;;
        *) archive="$argument" ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    fail "à lancer en root."
fi
PHP="${PHP:-$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')}"
export PHP

services() {
    systemctl "$1" "php$PHP-fpm" patrimeter-scheduler.service
}

if [ "$phase" = rollback ]; then
    [ -d "$previous" ] || fail "aucune version précédente ($previous)."
    if [ -d "$previous/.rollback" ]; then
        set -- "$previous"/.rollback/patrimeter-*.sqlite.gz
        [ "$#" -eq 1 ] && [ -f "$1.sha256" ] || fail "instantané de retour arrière incomplet."
        database="$1"
    else
        # Updates initiated by older versions used a pointer into the rotating backups.
        [ -s "$backups/before-update.archive" ] || fail "sauvegarde d'avant la mise à jour introuvable."
        database="$(cat "$backups/before-update.archive")"
    fi
    PATRIMETER_DIR="$previous" /usr/local/sbin/patrimeter-restore --check "$database"
    [ ! -e "$project.failed" ] || fail "retour arrière précédent à examiner : $project.failed."
    services stop
    mv "$project/var" "$previous/var"
    mv "$project" "$project.failed"
    mv "$previous" "$project"
    cp -p "$project.failed/.env.local" "$project/.env.local"
    rm -rf "$project/var/cache/prod"
    case "$database" in "$previous"/.rollback/*) database="$project/.rollback/${database##*/}" ;; esac
    /usr/local/sbin/patrimeter-restore "$database"
    rm -rf "$project/.rollback" "$project.failed"
    services start
    echo "Patrimeter revenu à la version $(cat "$project/VERSION"), base du $(basename "$database")."
    exit 0
fi

if [ "$phase" = prepare ]; then
    [ -n "$archive" ] || fail "indiquez l'archive à installer (patrimeter-update /chemin/patrimeter-vX.Y.Z.tar.gz)."
    [ -f "$archive" ] || fail "archive introuvable : $archive."
    [ -f "$project/VERSION" ] || fail "$project n'est pas une installation de Patrimeter."
    if [ -f "$archive.sha256" ]; then
        (cd "$(dirname "$archive")" && sha256sum --quiet -c "$(basename "$archive").sha256") || fail "empreinte de l'archive incorrecte."
    fi
    echo "Version installée : $(cat "$project/VERSION")"

    # The new code, ready before anything stops.
    rm -rf "$project.new"
    mkdir "$project.new"
    tar -xzf "$archive" -C "$project.new" --strip-components=1
    [ -f "$project.new/VERSION" ] || fail "$archive n'est pas une archive de Patrimeter."
    chown -R root:root "$project.new"

    [ -f "$project.new/docs/update/patrimeter-update.sh" ] || fail "script de mise à jour absent de l'archive."
    rm -rf "$project.new/var"
    cp -p "$project/.env.local" "$project.new/.env.local"
    # A private directory pairs this snapshot with its code, independently of scheduled backup rotation.
    [ ! -e "$project/.rollback" ] || fail "instantané précédent à examiner : $project/.rollback."
    snapshot="$(mktemp -d "$project/.rollback.XXXXXX")"
    trap 'rm -rf "$snapshot"; services start' EXIT
    services stop
    PATRIMETER_BACKUP_DIR="$snapshot" /usr/local/sbin/patrimeter-backup
    set -- "$snapshot"/patrimeter-*.sqlite.gz
    [ "$#" -eq 1 ] && [ -f "$1.sha256" ] || fail "instantané incomplet."
    /usr/local/sbin/patrimeter-restore --check "$1"
    mv "$snapshot" "$project/.rollback"
    trap - EXIT
    mv "$project/var" "$project.new/var"
    rm -rf "$previous"
    mv "$project" "$previous"
    rm -rf "$previous/var"
    mv "$project.new" "$project"
    rm -f "$backups/before-update.archive"

    copy="$(mktemp)"
    cp "$project/docs/update/patrimeter-update.sh" "$copy"
    PATRIMETER_UPDATE_SELF="$copy" exec sh "$copy" --apply
fi

# Phase 2: script of the new version. On failure, the services stay stopped.
self="${PATRIMETER_UPDATE_SELF:-}"
finish() {
    status=$?
    [ -n "$self" ] && rm -f "$self"
    if [ "$status" -ne 0 ]; then
        echo "patrimeter-update : échec ; services arrêtés. Revenir à la version précédente : patrimeter-update --rollback" >&2
    fi
}
trap finish EXIT

cd "$project"
rm -rf var/cache/prod
install -d -o www-data -g www-data -m 0700 var/uploads
chown -R www-data:www-data var
runuser -u www-data -- php bin/console cache:warmup --no-interaction
runuser -u www-data -- php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
install -m 0755 docs/backup/patrimeter-backup.sh /usr/local/sbin/patrimeter-backup
install -m 0755 docs/backup/patrimeter-restore.sh /usr/local/sbin/patrimeter-restore
install -m 0755 docs/update/patrimeter-update.sh /usr/local/sbin/patrimeter-update
install -m 0644 docs/backup/patrimeter-backup.service docs/backup/patrimeter-backup.timer \
    docs/backup/patrimeter-restore-check.service docs/backup/patrimeter-restore-check.timer \
    docs/systemd/patrimeter-scheduler.service docs/systemd/patrimeter-alert@.service /etc/systemd/system/
install -m 0644 docs/php-fpm/patrimeter-opcache.ini "/etc/php/$PHP/fpm/conf.d/90-patrimeter.ini"
systemctl daemon-reload
systemctl enable patrimeter-scheduler.service
systemctl enable --now patrimeter-restore-check.timer
services restart

systemctl is-active --quiet "php$PHP-fpm" patrimeter-scheduler.service || fail "php-fpm ou les tâches planifiées ne démarrent pas."
runuser -u www-data -- php bin/console doctrine:migrations:up-to-date --no-interaction > /dev/null || fail "des migrations restent à appliquer."
echo "Patrimeter mis à jour : version $(cat VERSION). Retour arrière possible : patrimeter-update --rollback"
