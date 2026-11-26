#!/bin/sh
# Backup of the Patrimeter database: consistent copy (SQLite's backup API, never "cp" on a database in WAL mode),
# checked then compressed into a folder reserved to root.
# Documentation: docs/backup.md. To install as /usr/local/sbin/patrimeter-backup.
#
# Variables: PATRIMETER_DIR (/srv/patrimeter), PATRIMETER_BACKUP_DIR (/var/backups/patrimeter),
# PATRIMETER_BACKUP_KEEP (30 archives kept); off-server copy (docs/backup.md § 2): PATRIMETER_BACKUP_AGE_RECIPIENTS
# (age public keys, or the absolute path of a file of them) and PATRIMETER_BACKUP_COPY (shell command that receives
# the encrypted archive as $1, or as its last argument without $1).
set -eu
umask 077

project="${PATRIMETER_DIR:-/srv/patrimeter}"
backups="${PATRIMETER_BACKUP_DIR:-/var/backups/patrimeter}"
keep="${PATRIMETER_BACKUP_KEEP:-30}"
database="$project/var/patrimeter.sqlite"

if [ "$(id -u)" -ne 0 ]; then
    echo "patrimeter-backup : à lancer en root." >&2
    exit 1
fi
recipients="${PATRIMETER_BACKUP_AGE_RECIPIENTS:-}"
copy_command="${PATRIMETER_BACKUP_COPY:-}"
# Off the server, never in clear: the copy command needs the encryption.
if [ -n "$copy_command" ] && [ -z "$recipients" ]; then
    echo "patrimeter-backup : PATRIMETER_BACKUP_COPY sans PATRIMETER_BACKUP_AGE_RECIPIENTS : copie en clair refusée." >&2
    exit 1
fi
if [ -n "$recipients" ] && ! command -v age > /dev/null; then
    echo "patrimeter-backup : age absent (apt-get install age)." >&2
    exit 1
fi
case "$recipients" in
    /*)
        [ -f "$recipients" ] || { echo "patrimeter-backup : fichier des destinataires introuvable ($recipients)." >&2; exit 1; } ;;
esac
if [ ! -f "$database" ]; then
    echo "patrimeter-backup : base introuvable ($database)." >&2
    exit 1
fi

mkdir -p "$backups"
chmod 0700 "$backups"

# The copy is made as www-data, owner of the database: root never opens the live file, and thus leaves no -wal or
# -shm file of its own there.
work="$(mktemp -d "$project/var/backup.XXXXXX")"
trap 'rm -rf "$work"' EXIT
chown www-data:www-data "$work"
copy="$work/copy.sqlite"
runuser -u www-data -- sqlite3 -bail "$database" ".timeout 30000" ".backup '$copy'"

integrity="$(runuser -u www-data -- sqlite3 -bail "$copy" 'PRAGMA integrity_check;')"
if [ "$integrity" != "ok" ]; then
    echo "patrimeter-backup : copie corrompue : $integrity" >&2
    exit 1
fi

stamp="$(date -u +%Y%m%d-%H%M%S)"
archive="$backups/patrimeter-$stamp.sqlite.gz"
gzip -9 < "$copy" > "$archive.tmp"
mv "$archive.tmp" "$archive"
(cd "$backups" && sha256sum "patrimeter-$stamp.sqlite.gz" > "patrimeter-$stamp.sqlite.gz.sha256")

# Rotation by number of archives, not by age: if the backups stop, the latest ones remain.
# The name contains the date: the alphabetical order is the chronological order.
find "$backups" -maxdepth 1 -name 'patrimeter-*.sqlite.gz' | sort -r | tail -n "+$((keep + 1))" | while read -r old; do
    rm -f "$old" "$old.sha256"
done

# Off-server copy: the archive encrypted with age for the given public keys (the server holds no private key and
# cannot decrypt it), then handed to the copy command. A failure stops the script: alert (OnFailure=) and no
# monitoring marker. The encrypted archives follow the same rotation, in their own folder.
if [ -n "$recipients" ]; then
    case "$recipients" in
        /*)
            set -- --recipients-file "$recipients"
            ;;
        *)
            set --
            for recipient in $recipients; do
                set -- "$@" --recipient "$recipient"
            done
            ;;
    esac
    offsite="$backups/chiffrees"
    mkdir -p "$offsite"
    chmod 0700 "$offsite"
    encrypted="$offsite/patrimeter-$stamp.sqlite.gz.age"
    age --encrypt "$@" --output "$encrypted.tmp" "$archive"
    mv "$encrypted.tmp" "$encrypted"
    if [ -n "$copy_command" ]; then
        # The archive is $1 of the command, or its last argument when the command does not mention $1 (an
        # environment file of docker compose would replace "$1" itself).
        case "$copy_command" in
            *'$1'*) ;;
            *) copy_command="$copy_command \"\$1\"" ;;
        esac
        sh -c "$copy_command" patrimeter-backup-copy "$encrypted"
        echo "Copie hors du serveur : $encrypted"
    fi
    find "$offsite" -maxdepth 1 -name 'patrimeter-*.sqlite.gz.age' | sort -r | tail -n "+$((keep + 1))" | while read -r old; do
        rm -f "$old"
    done
fi

# Monitoring marker (/sante, docs/supervision.md): date of the last successful backup, readable by the application,
# which has no access to the archives folder.
mkdir -p "$project/var/supervision"
date +%s > "$project/var/supervision/sauvegarde"
chown -R www-data:www-data "$project/var/supervision"

echo "Sauvegarde : $archive ($(wc -c < "$archive") octets)"
