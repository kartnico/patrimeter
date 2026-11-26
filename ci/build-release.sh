#!/bin/sh
# Builds the delivery archive release/<name>.tar.gz and its .sha256 checksum: versioned files, without the tests and
# development tools, plus vendor/ without development dependencies and public/assets/.
# Then checks that the archive starts on its own. Usage: sh ci/build-release.sh <name>
set -eu

name="$1"
root="$PWD"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Versioned files only: nothing local (.env.local, var/) can slip in. In the CI, the repository does not belong to
# the container's user: safe.directory avoids git's refusal. tar.umask 0022: nothing writable by the group (git's
# default, 0002, would leave the files of the installation group-writable).
git -c safe.directory="$root" -c tar.umask=0022 archive --format=tar --prefix="$name/" HEAD \
    ':(exclude)tests' ':(exclude)ci' ':(exclude).gitlab-ci.yml' ':(exclude)package.json' ':(exclude)package-lock.json' \
    ':(exclude)playwright.config.mjs' ':(exclude)phpstan.dist.neon' ':(exclude)psalm.xml' ':(exclude)phpunit.dist.xml' \
    ':(exclude).php-cs-fixer.dist.php' ':(exclude)tools' ':(exclude)security' ':(exclude).env.dev' ':(exclude).env.test' \
    ':(exclude).gitleaksignore' ':(exclude).editorconfig' ':(exclude)Makefile' ':(exclude)AGENTS.md' \
    ':(exclude)documentation' ':(exclude)playwright.captures.config.mjs' \
    | tar -x -C "$work"

cd "$work/$name"
# Installed version, readable on the server (docs/mise-a-jour.md): name of the archive and commit.
printf '%s\n%s\n' "$name" "$(git -c safe.directory="$root" -C "$root" rev-parse HEAD)" > RELEASE
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-scripts --classmap-authoritative --no-interaction --no-progress
APP_ENV=prod php bin/console asset-map:compile --quiet
rm -rf var/cache var/log
test ! -d vendor/phpunit

# The archive starts with nothing but a .env.local: valid container, known migrations.
printf 'APP_SECRET=%s\nAUTH_SECRET_KEY=%s\n' "$(php -r 'echo bin2hex(random_bytes(32));')" "$(php -r 'echo base64_encode(random_bytes(32));')" > .env.local
APP_ENV=prod php bin/console lint:container --quiet
APP_ENV=prod php bin/console doctrine:migrations:list --quiet
rm -rf .env.local var/cache var/log var/*.sqlite*

cd "$work"
mkdir -p "$root/release"
tar -czf "$root/release/$name.tar.gz" "$name"
cd "$root/release"
sha256sum "$name.tar.gz" > "$name.tar.gz.sha256"
echo "Archive : release/$name.tar.gz ($(wc -c < "$name.tar.gz") octets)"
