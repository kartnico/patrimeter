#!/bin/sh
# Restore of the Patrimeter database from a patrimeter-backup archive.
# Documentation: docs/backup.md. To install as /usr/local/sbin/patrimeter-restore.
#
# Usage: patrimeter-restore --check <archive>   checks the archive, without changing anything
#        patrimeter-restore --check-latest      checks the latest archive (monthly test, patrimeter-restore-check)
#        patrimeter-restore <archive>           replaces the database (php-fpm and patrimeter-scheduler stopped)
#
# Variables: PATRIMETER_DIR (/srv/patrimeter), PATRIMETER_BACKUP_DIR (/var/backups/patrimeter),
# PATRIMETER_SOCKET (/run/php/patrimeter.sock).
set -eu
umask 077

project="${PATRIMETER_DIR:-/srv/patrimeter}"
backups="${PATRIMETER_BACKUP_DIR:-/var/backups/patrimeter}"
socket="${PATRIMETER_SOCKET:-/run/php/patrimeter.sock}"
database="$project/var/patrimeter.sqlite"

fail() {
    echo "patrimeter-restore : $1" >&2
    exit 1
}

check_only=0
if [ "${1:-}" = "--check-latest" ] && [ "$#" -eq 1 ]; then
    latest="$(find "$backups" -maxdepth 1 -name 'patrimeter-*.sqlite.gz' | sort | tail -n 1)"
    [ -n "$latest" ] || fail "aucune archive dans $backups."
    set -- --check "$latest"
fi
if [ "${1:-}" = "--check" ]; then
    check_only=1
    shift
fi
[ "$#" -eq 1 ] || fail "usage : patrimeter-restore [--check] <archive.sqlite.gz> | --check-latest"
archive="$1"

[ "$(id -u)" -eq 0 ] || fail "à lancer en root."
[ -f "$archive" ] || fail "archive introuvable : $archive"
command -v sqlite3 > /dev/null || fail "sqlite3 absent (apt-get install sqlite3)."

# Checksum, if it came with the archive.
if [ -f "$archive.sha256" ]; then
    (cd "$(dirname "$archive")" && sha256sum --check --quiet "$(basename "$archive").sha256") \
        || fail "l'empreinte ne correspond pas : archive altérée."
fi

mkdir -p "$backups"
chmod 0700 "$backups"
work="$(mktemp -d "$backups/.restore.XXXXXX")"
trap 'rm -rf "$work"' EXIT
candidate="$work/candidate.sqlite"
gzip -dc "$archive" > "$candidate" || fail "décompression impossible."

integrity="$(sqlite3 -bail "$candidate" 'PRAGMA integrity_check;')" || fail "ce n'est pas une base SQLite."
[ "$integrity" = "ok" ] || fail "base corrompue : $integrity"
violations="$(sqlite3 -bail "$candidate" 'PRAGMA foreign_key_check;')"
[ -z "$violations" ] || fail "clés étrangères violées : $violations"
users="$(sqlite3 -bail "$candidate" 'SELECT count(*) FROM users;')" || fail "table des utilisateurs absente."
accounts="$(sqlite3 -bail "$candidate" 'SELECT count(*) FROM accounts;')" || fail "table des comptes absente."

# The code must know all the migrations of the archive; it will apply the missing ones itself.
for version in $(sqlite3 -bail "$candidate" 'SELECT version FROM doctrine_migration_versions;'); do
    [ -f "$project/migrations/${version##*\\}.php" ] \
        || fail "cette archive vient d'une version plus récente du code ($version) : installez cette version, puis relancez."
done

echo "Archive valide : $users utilisateur(s), $accounts compte(s)."
if [ "$check_only" -eq 1 ]; then
    exit 0
fi

# php-fpm removes its socket when stopping: replacing the database under an active process would corrupt it.
[ ! -e "$socket" ] || fail "php-fpm tourne encore ($socket) : arrêtez-le d'abord (voir docs/backup.md)."
# The scheduled tasks (messenger:consume) keep the database open: they would write into the old one.
command -v pgrep > /dev/null || fail "pgrep absent (apt-get install procps)."
if pgrep -f 'bin/console messenger:consume' > /dev/null; then
    fail "les tâches planifiées tournent encore : arrêtez patrimeter-scheduler.service d'abord (voir docs/backup.md)."
fi
[ -d "$project/var" ] || fail "dossier introuvable : $project/var."

# Copy of the current database, with its journal, to be able to go back.
if [ -f "$database" ]; then
    safety="$(mktemp -d "$backups/avant-restauration-$(date -u +%Y%m%d-%H%M%S).XXXX")"
    cp -p "$database" "$safety/"
    for extra in "$database-wal" "$database-shm"; do
        if [ -f "$extra" ]; then
            cp -p "$extra" "$safety/"
        fi
    done
    echo "Base précédente conservée : $safety"
fi

# A -wal journal left in place would be replayed on the restored database and corrupt it.
rm -f "$database-wal" "$database-shm"
install -o www-data -g www-data -m 0640 "$candidate" "$database.tmp"
mv "$database.tmp" "$database"

# The open sessions describe a state the restored database no longer knows.
rm -rf "$project/var/sessions"

# An archive older than the code receives here the migrations it lacks.
cd "$project"
runuser -u www-data -- php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --quiet
runuser -u www-data -- php bin/console doctrine:migrations:up-to-date --quiet
runuser -u www-data -- php bin/console cache:clear --quiet

echo "Base restaurée depuis $archive. Redémarrez php-fpm et patrimeter-scheduler.service."
