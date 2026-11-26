#!/bin/sh
# Installs Composer (official phar) in a pinned version, whose checksum is verified.
# Downloaded by PHP itself (no need for curl); PHP must be installed.
# Usage: sh ci/install-composer.sh
set -eu

version=2.11.1
sha256=6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c

# Already there with the expected checksum (CI image): nothing to download.
if [ -x /usr/local/bin/composer ] && echo "$sha256  /usr/local/bin/composer" | sha256sum -c - > /dev/null 2>&1; then
    composer --version --no-ansi | head -n 1
    exit 0
fi

php -r 'copy($argv[1], $argv[2]) || exit(1);' "https://getcomposer.org/download/$version/composer.phar" /usr/local/bin/composer
echo "$sha256  /usr/local/bin/composer" | sha256sum -c - > /dev/null
chmod 0755 /usr/local/bin/composer
composer --version --no-ansi | head -n 1
