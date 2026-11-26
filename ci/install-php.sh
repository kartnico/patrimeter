#!/bin/sh
# Installs PHP <version> from Ondřej Surý's repository on a Debian image, as in production
# (docs/installation.md, § 1). The repository's key is versioned next to this script
# (ci/debsuryorg-archive-keyring.gpg, "DEB.SURY.ORG Automatic Signing Key",
# fingerprint 1505 8500 A023 5D97 F5D1 0063 B188 E2B6 95BD 4743, valid until 04/02/2028).
# The downloaded packages are kept in .cache/apt (GitLab cache). On the CI image (ci/php/Dockerfile), where the packages
# are already installed, it only selects the version: a few seconds instead of a few minutes.
#
# Usage: sh ci/install-php.sh 8.4 [extra package…]
set -eu

version="$1"
shift

# Selects this version for "php" (several versions on the CI image) and enables pcov if it is asked for.
select_version() {
    if [ -x "/usr/bin/php$version" ] && command -v update-alternatives > /dev/null; then
        update-alternatives --set php "/usr/bin/php$version" > /dev/null
    fi
    case " $* " in
        *" php$version-pcov "*) phpenmod -v "$version" pcov ;;
    esac
    php -v | head -n 1
}

# Everything already installed (CI image): nothing to download.
installed=1
for package in "php$version-cli" "php$version-sqlite3" "php$version-xml" "php$version-mbstring" "php$version-intl" "$@"; do
    if ! dpkg-query -W -f='${Status}' "$package" 2> /dev/null | grep -q 'install ok installed'; then
        installed=0
    fi
done
if [ "$installed" = 1 ]; then
    select_version "$@"
    exit 0
fi

export DEBIAN_FRONTEND=noninteractive
archives="${CI_PROJECT_DIR:-$PWD}/.cache/apt"
mkdir -p "$archives/partial"

# Throwaway container: no need for dpkg to sync each write to the disk (much faster).
echo 'force-unsafe-io' > /etc/dpkg/dpkg.cfg.d/02-ci-unsafe-io

apt_get() {
    apt-get -qq -o Dir::Cache::Archives="$archives" "$@"
}

# ca-certificates: needed to reach the Surý repository over HTTPS.
apt_get update
apt_get install -y --no-install-recommends ca-certificates > /dev/null

install -m 0644 "$(dirname "$0")/debsuryorg-archive-keyring.gpg" /usr/share/keyrings/debsuryorg-archive-keyring.gpg
# Debian version read from the system: the same script works for any version.
codename="$(. /etc/os-release && echo "$VERSION_CODENAME")"
echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $codename main" \
    > /etc/apt/sources.list.d/php.list
apt_get update -o Dir::Etc::SourceList=sources.list.d/php.list -o Dir::Etc::SourceParts=- -o APT::Get::List-Cleanup=0

# Same extensions as in production (requirements of docs/installation.md), plus the packages asked for.
apt_get install -y --no-install-recommends \
    "php$version-cli" "php$version-sqlite3" "php$version-xml" "php$version-mbstring" "php$version-intl" \
    "$@" > /dev/null

select_version "$@"
