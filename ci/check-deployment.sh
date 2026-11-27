#!/bin/sh
# "deployment" job: builds the release archive, then really installs it on the job's new Debian by running the
# marked blocks of docs/installation.md, docs/backup.md and docs/mise-a-jour.md, and checks the result from the
# outside: nginx and php-fpm files of the archive, HTTPS, permissions, code without development dependencies, full
# flows, backup and restore, update to another archive and rollback.
# Documentation: docs/ci.md.
#
# Changes the system (packages, /etc, /srv): only runs in CI, never on a real server.
set -eu

if [ "${CI:-}" != "true" ]; then
    echo "check-deployment : à lancer uniquement en CI, ce script modifie le système." >&2
    exit 1
fi

project="$PWD"
domain=patrimeter.example.org
email=marie@example.fr
account='Livret de Marie'
export PASSWORD='une phrase de passe assez longue pour le test'
export DEBIAN_FRONTEND=noninteractive PHP="$PHP_VERSION"
export STATE_FILE=/tmp/patrimeter-smoke.json MAILPIT_API=http://mailpit:8025
smoke="tests/deployment/smoke.php"

step() {
    printf '\n========== %s\n' "$1"
}

# Runs the blocks of a page marked <!-- ci:<label> -->, in order and in a single shell.
run_doc() {
    blocks="$(mktemp)"
    awk -v marker="<!-- ci:$2 -->" -f ci/doc-blocks.awk "$1" > "$blocks"
    if [ ! -s "$blocks" ]; then
        echo "check-deployment : aucun bloc « $2 » dans $1." >&2
        exit 1
    fi
    sh -eux "$blocks"
}

# Unexpected value of a file: fails the job with a clear message.
expect_stat() {
    actual="$(stat -c '%U:%G %a' "$1")"
    if [ "$actual" != "$2" ]; then
        echo "check-deployment : $1 est $actual au lieu de $2." >&2
        exit 1
    fi
    echo "  ok : $1 $actual"
}

step 'Préparation du conteneur : les prérequis de docs/installation.md'
# Throwaway container: packages kept in the GitLab cache, without syncing each dpkg write.
mkdir -p "$project/.cache/apt/partial"
echo "Dir::Cache::Archives \"$project/.cache/apt\";" > /etc/apt/apt.conf.d/90ci
echo 'force-unsafe-io' > /etc/dpkg/dpkg.cfg.d/02-ci-unsafe-io
apt-get -qq update
apt-get -qq install -y --no-install-recommends ca-certificates curl openssl > /dev/null
# PHP from Ondřej Surý's repository (as in the container image), nginx and the tools listed by the requirements; git and
# unzip only build the archive.
install -m 0644 ci/debsuryorg-archive-keyring.gpg /usr/share/keyrings/debsuryorg-archive-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(. /etc/os-release && echo "$VERSION_CODENAME") main" \
    > /etc/apt/sources.list.d/php.list
apt-get -qq update
apt-get -qq install -y --no-install-recommends nginx \
    "php$PHP-fpm" "php$PHP-cli" "php$PHP-sqlite3" "php$PHP-xml" "php$PHP-mbstring" "php$PHP-intl" "php$PHP-curl" \
    sqlite3 tar git unzip > /dev/null
# No systemd in the container.
install -m 0755 ci/fake-systemctl.sh /usr/local/bin/systemctl
mkdir -p /run/php

step "Archive de livraison (ci/build-release.sh), déposée dans /tmp comme après un téléchargement"
sh ci/install-composer.sh
COMPOSER_CACHE_DIR="$project/.cache/composer" sh ci/build-release.sh patrimeter-v0.0.1
cp release/patrimeter-v0.0.1.tar.gz release/patrimeter-v0.0.1.tar.gz.sha256 /tmp/
# Certificate at the documented locations, trusted by this container.
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=$domain" -addext "subjectAltName=DNS:$domain" \
    -keyout "/etc/ssl/private/$domain.key" -out "/etc/ssl/certs/$domain.pem" 2> /dev/null
install -m 0644 "/etc/ssl/certs/$domain.pem" "/usr/local/share/ca-certificates/$domain.crt"
update-ca-certificates > /dev/null
echo "127.0.0.1 $domain" >> /etc/hosts

step 'Procédure : docs/installation.md'
run_doc docs/installation.md install
# First account, its password read on standard input (docs/installation.md § 4).
printf '%s\n' "$PASSWORD" | runuser -u www-data -- php /srv/patrimeter/bin/console app:user:create "$email"

# Repeat the actual installation over live data, including a private uploaded file.
install -o www-data -g www-data -m 0600 /dev/null /srv/patrimeter/var/uploads/replay-test
secret_checksum="$(sha256sum /srv/patrimeter/.env.local)"
run_doc docs/installation.md install
runuser -u www-data -- sqlite3 -bail /srv/patrimeter/var/patrimeter.sqlite 'BEGIN IMMEDIATE; UPDATE users SET id = id WHERE 0; ROLLBACK;'
expect_stat /srv/patrimeter/var/uploads/replay-test 'www-data:www-data 600'
test "$(sha256sum /srv/patrimeter/.env.local)" = "$secret_checksum"
rm /srv/patrimeter/var/uploads/replay-test

step 'Proxy inverse de test et réglages propres à ce conteneur'
sed -i 's/^TRUSTED_PROXIES=.*/TRUSTED_PROXIES=127.0.0.1/' /srv/patrimeter/.env.local
{
    echo 'ADMIN_EMAIL=admin@example.fr'
    echo 'API_ENABLED=true'
    echo 'MCP_ENABLED=true'
    echo 'SMTP_HOST=mailpit'
    echo 'SMTP_PORT=1025'
    echo 'SMTP_ENCRYPTION=none'
    echo 'MAIL_FROM="Patrimeter <patrimeter@example.org>"'
} >> /srv/patrimeter/.env.local
install -m 0644 tests/deployment/reverse-proxy.conf /etc/nginx/sites-enabled/reverse-proxy-test.conf
systemctl reload nginx

step 'Syntaxe des configurations installées'
# Outside the procedure: in "nginx -t && …", set -e does not detect a failure of the first term.
nginx -t
"php-fpm$PHP" -t
pool="$("php-fpm$PHP" -tt 2>&1)"
for setting in 'open_basedir' 'disable_functions' 'expose_php' 'allow_url_fopen'; do
    echo "$pool" | grep -q "php_admin_value\[$setting\]" || {
        echo "check-deployment : réglage $setting absent du pool." >&2
        exit 1
    }
done
test ! -e "/etc/php/$PHP/fpm/pool.d/www.conf"
# OPcache set up as Symfony recommends (docs/php-fpm/patrimeter-opcache.ini), without preloading.
fpm_info="$("php-fpm$PHP" -i 2>&1)"
for setting in 'opcache.enable => On' 'opcache.validate_timestamps => Off' 'opcache.memory_consumption => 256' \
    'opcache.preload => no value'; do
    echo "$fpm_info" | grep -q "^$setting" || {
        echo "check-deployment : réglage OPcache absent : $setting." >&2
        exit 1
    }
done

step 'Droits et contenu de ce qui est installé'
expect_stat /srv/patrimeter/.env.local 'root:www-data 640'
expect_stat /srv/patrimeter/var 'www-data:www-data 750'
expect_stat /srv/patrimeter/var/patrimeter.sqlite 'www-data:www-data 644'
expect_stat /srv/patrimeter/src 'root:root 755'
expect_stat /run/php/patrimeter.sock 'www-data:www-data 660'
for path in /srv/patrimeter/src /srv/patrimeter/public /srv/patrimeter/config /srv/patrimeter/vendor /srv/patrimeter/.env.local; do
    runuser -u www-data -- test ! -w "$path"
done
test ! -d /srv/patrimeter/vendor/phpunit

step 'Vérification de la procédure : docs/installation.md § 8'
run_doc docs/installation.md verify

step "Supervision, rapports de la CSP et envoi du fichier d'import, à travers nginx et php-fpm"
curl -fsS "https://$domain/sante" | grep -q '"database:responseTime"'
curl -fsS "https://$domain/sante/metriques" | grep -q '^patrimeter_database_up 1$'
test "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/csp-report' --data '{"csp-report": {"effective-directive": "img-src"}}' "https://$domain/csp/rapport")" = 204
expect_stat /srv/patrimeter/var/uploads 'www-data:www-data 700'
head -c 102400 /dev/zero > /tmp/patrimeter-100k.json
# 100 KB: refused everywhere by nginx (413), except at the import addresses, where php-fpm receives the file then
# the sign-in is asked for (303), for lack of a session.
test "$(curl -s -o /dev/null -w '%{http_code}' -F 'fichier=@/tmp/patrimeter-100k.json' "https://$domain/connexion")" = 413
test "$(curl -s -o /dev/null -w '%{http_code}' -F 'fichier=@/tmp/patrimeter-100k.json' "https://$domain/donnees/import")" = 303
# CSV import of accounts: up to 1 MB too (it was refused by nginx above 64 KB).
test "$(curl -s -o /dev/null -w '%{http_code}' -F 'fichier_csv=@/tmp/patrimeter-100k.json' "https://$domain/donnees/import-csv")" = 303
# Import of a family account member's file: same limit as the data import; not the removal.
test "$(curl -s -o /dev/null -w '%{http_code}' -F 'fichier=@/tmp/patrimeter-100k.json' "https://$domain/famille/membres")" = 303
test "$(curl -s -o /dev/null -w '%{http_code}' -F 'fichier=@/tmp/patrimeter-100k.json' "https://$domain/famille/membres/1")" = 303
test "$(curl -s -o /dev/null -w '%{http_code}' -F 'fichier=@/tmp/patrimeter-100k.json' "https://$domain/famille/membres/1/supprimer")" = 413
# Bank statement read on an account page: same limit; not the other statement forms.
test "$(curl -s -o /dev/null -w '%{http_code}' -F 'releve=@/tmp/patrimeter-100k.json' "https://$domain/comptes/1/releves/fichier")" = 303
test "$(curl -s -o /dev/null -w '%{http_code}' -F 'releve=@/tmp/patrimeter-100k.json' "https://$domain/comptes/1/releves")" = 413
echo '  ok : /sante, /sante/metriques, /csp/rapport, envoi de 100 Ko limité aux imports'

step 'Parcours sur le vhost derrière un proxy (variante 6.1)'
php "$smoke" web proxied
php "$smoke" first-login "$email" "$PASSWORD"
php "$smoke" add-account "$email" "$PASSWORD" "$account" '1 234,56'
php "$smoke" register inscrit@example.fr 'une autre phrase de passe pour le test'
php "$smoke" api "$email" "$PASSWORD" '1 234,56'

step 'Sauvegarde : docs/backup.md'
run_doc docs/backup.md backup
expect_stat /var/backups/patrimeter 'root:root 700'
archive="$(find /var/backups/patrimeter -maxdepth 1 -name 'patrimeter-*.sqlite.gz' | sort | tail -n 1)"
test -n "$archive"
expect_stat "$archive" 'root:root 600'
expect_stat /srv/patrimeter/var/patrimeter.sqlite 'www-data:www-data 644'
if zcat "$archive" | grep -aq "$account"; then
    echo "check-deployment : donnée patrimoniale en clair dans l'archive." >&2
    exit 1
fi
if command -v systemd-analyze > /dev/null; then
    systemd-analyze verify /etc/systemd/system/patrimeter-backup.service /etc/systemd/system/patrimeter-backup.timer \
        /etc/systemd/system/patrimeter-restore-check.service /etc/systemd/system/patrimeter-restore-check.timer
    echo '  ok : unités systemd valides (systemd-analyze)'
else
    echo '  non vérifié : systemd-analyze absent de ce conteneur'
fi
/usr/local/sbin/patrimeter-restore --check-latest
echo '  ok : restauration d'"'"'essai de la dernière archive'

step 'Copie chiffrée hors du serveur : docs/backup.md § 2'
# A throwaway key pair stands for the backup machine's; the copy command drops the file into a local folder.
apt-get install -y -qq --no-install-recommends age > /dev/null
offsite_key="$(mktemp -d)/key.txt"
age-keygen -o "$offsite_key" 2> /dev/null
offsite_dir="$(mktemp -d)"
if PATRIMETER_BACKUP_COPY='cp "$1" /tmp/' /usr/local/sbin/patrimeter-backup 2> /dev/null; then
    echo "check-deployment : copie hors du serveur acceptée sans chiffrement." >&2
    exit 1
fi
PATRIMETER_BACKUP_AGE_RECIPIENTS="$(age-keygen -y "$offsite_key")" PATRIMETER_BACKUP_COPY="cp \"\$1\" $offsite_dir/" \
    /usr/local/sbin/patrimeter-backup
copied="$(find "$offsite_dir" -name 'patrimeter-*.sqlite.gz.age' | head -n 1)"
test -n "$copied"
latest="$(find /var/backups/patrimeter -maxdepth 1 -name 'patrimeter-*.sqlite.gz' | sort | tail -n 1)"
age --decrypt --identity "$offsite_key" "$copied" | cmp - "$latest"
expect_stat /var/backups/patrimeter/chiffrees 'root:root 700'
echo '  ok : archive chiffrée avec age, copiée, et déchiffrable seulement avec la clé privée'

step 'Tâches planifiées et alertes : docs/installation.md § 7'
if command -v systemd-analyze > /dev/null; then
    systemd-analyze verify /etc/systemd/system/patrimeter-scheduler.service /etc/systemd/system/patrimeter-alert@.service
fi
runuser -u www-data -- php /srv/patrimeter/bin/console debug:scheduler --no-interaction | grep -q 'PurgeExpiredData'
echo '  ok : tâche horaire de purge déclarée'
systemctl start patrimeter-alert@patrimeter-backup.service.service
php "$smoke" mail admin@example.fr 'patrimeter-backup.service a échoué'

step 'Restauration : docs/backup.md'
php "$smoke" set-amount "$email" "$PASSWORD" "$account" '9 999,99'
if /usr/local/sbin/patrimeter-restore "$archive"; then
    echo "check-deployment : restauration acceptée alors que php-fpm tourne." >&2
    exit 1
fi
# The scheduled tasks keep the database open: restore refused while they run. The fake systemctl does not start
# this long-running service: it is started by hand, php-fpm stopped.
systemctl stop "php$PHP-fpm"
runuser -u www-data -- php /srv/patrimeter/bin/console messenger:consume scheduler_maintenance --time-limit=120 --no-interaction \
    > /tmp/scheduler.log 2>&1 &
waited=0
until pgrep -u www-data -f 'bin/console messenger:consume' > /dev/null; do
    waited=$((waited + 1))
    [ "$waited" -lt 100 ] || { echo "check-deployment : tâches planifiées non démarrées." >&2; cat /tmp/scheduler.log >&2; exit 1; }
    sleep 0.1
done
if /usr/local/sbin/patrimeter-restore "$archive"; then
    echo "check-deployment : restauration acceptée alors que les tâches planifiées tournent." >&2
    exit 1
fi
pkill -u www-data -f 'bin/console messenger:consume'
while pgrep -u www-data -f 'bin/console messenger:consume' > /dev/null; do
    sleep 0.1
done
run_doc docs/backup.md restore
php "$smoke" expect-amount "$email" "$PASSWORD" "$account" '1 234,56'
expect_stat /srv/patrimeter/var/patrimeter.sqlite 'www-data:www-data 640'

step 'Mise à jour : docs/mise-a-jour.md (patrimeter-update)'
# A "newer" archive (the same code under a later version name) in /tmp, next to the first one.
cp /tmp/patrimeter-v0.0.1.tar.gz /tmp/patrimeter-v0.0.2.tar.gz
(cd /tmp && sha256sum patrimeter-v0.0.2.tar.gz > patrimeter-v0.0.2.tar.gz.sha256)
# Fail snapshot creation after stopping writers: the old site must restart with its data intact.
if PATRIMETER_BACKUP_COPY=false PATRIMETER_BACKUP_AGE_RECIPIENTS='' patrimeter-update /tmp/patrimeter-v0.0.2.tar.gz; then
    exit 1
fi
php "$smoke" expect-amount "$email" "$PASSWORD" "$account" '1 234,56'
# Simulate a final acknowledged web request just before the real service stop.
mkdir /tmp/adm-update-test
cat > /tmp/adm-update-test/systemctl <<'SH'
#!/bin/sh
set -eu
if [ "$1" = stop ]; then
    php "$UPDATE_SMOKE" set-amount "$UPDATE_EMAIL" "$PASSWORD" "$UPDATE_ACCOUNT" '2 345,67'
fi
exec /usr/local/bin/systemctl "$@"
SH
chmod 0755 /tmp/adm-update-test/systemctl
export UPDATE_SMOKE="$project/$smoke" UPDATE_EMAIL="$email" UPDATE_ACCOUNT="$account"
PATH="/tmp/adm-update-test:$PATH" run_doc docs/mise-a-jour.md update
rm -r /tmp/adm-update-test
set -- /srv/patrimeter.previous/.rollback/patrimeter-*.sqlite.gz
snapshot="$1"
test -s "$snapshot.sha256"
test -d /srv/patrimeter.previous
test ! -e /srv/patrimeter.previous/var
expect_stat /srv/patrimeter/.env.local 'root:www-data 640'
expect_stat /srv/patrimeter/src 'root:root 755'
php "$smoke" web proxied
php "$smoke" expect-amount "$email" "$PASSWORD" "$account" '2 345,67'

step 'Retour arrière : docs/mise-a-jour.md (patrimeter-update --rollback)'
php "$smoke" set-amount "$email" "$PASSWORD" "$account" '5 555,55'
PATRIMETER_BACKUP_KEEP=1 patrimeter-backup
mv "$snapshot" "$snapshot.valid"
for damage in missing corrupt; do
    if [ "$damage" = corrupt ]; then printf 'invalid' > "$snapshot"; fi
    if patrimeter-update --rollback; then exit 1; fi
    php "$smoke" expect-amount "$email" "$PASSWORD" "$account" '5 555,55'
done
mv "$snapshot.valid" "$snapshot"
run_doc docs/mise-a-jour.md rollback
test ! -e /srv/patrimeter.previous
php "$smoke" web proxied
php "$smoke" expect-amount "$email" "$PASSWORD" "$account" '2 345,67'

step 'patrimeter-update refuse une option inconnue, une archive absente et un appel sans root'
if /usr/local/sbin/patrimeter-update --pas-une-option 2> /dev/null; then
    echo "check-deployment : option inconnue acceptée." >&2
    exit 1
fi
if /usr/local/sbin/patrimeter-update /tmp/absente.tar.gz 2> /dev/null; then
    echo "check-deployment : archive absente acceptée." >&2
    exit 1
fi
if runuser -u www-data -- /usr/local/sbin/patrimeter-update 2> /dev/null; then
    echo "check-deployment : mise à jour lancée sans root." >&2
    exit 1
fi

step 'Journaux sans secret ni erreur'
# var/log only exists after a first error: the production logs only keep errors.
if grep -rqFs "$PASSWORD" /srv/patrimeter/var/log /var/log/nginx; then
    echo "check-deployment : mot de passe présent dans les journaux." >&2
    exit 1
fi
if grep -rEs 'request\.(CRITICAL|ERROR)|app\.(CRITICAL|ERROR)' /srv/patrimeter/var/log; then
    echo "check-deployment : erreur applicative pendant les parcours." >&2
    exit 1
fi

step 'Vhost qui termine lui-même le TLS (variante 6.2)'
rm -f /etc/nginx/sites-enabled/reverse-proxy-test.conf
run_doc docs/installation.md tls
nginx -t
php "$smoke" web tls

step 'Déploiement vérifié'
