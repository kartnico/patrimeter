#!/bin/sh
# Run by Renovate after it updated a version, in its branch, before its commit (postUpgradeTasks of renovate.json,
# allowed by RENOVATE_ALLOWED_COMMANDS of the "renovate" job). Renovate updates the versions, not what depends on
# them; this script does it:
# - the SHA-256 checksums of the tools that the CI downloads (Composer, k6, Nuclei, SonarScanner, OpenSSF Scorecard,
#   Docker Compose), only for a version that just changed: the new release is downloaded and its checksum written next to it;
# - the tag of the CI image (CI_PHP_IMAGE), the SHA-256 of what builds it (CiPhpImageTest);
# - THIRD-PARTY-LICENSES.md, written again from composer.lock when it changed (ThirdPartyLicensesTest).
# Usage: sh ci/renovate-post-upgrade.sh (from the root of the repository)
set -eu
cd "$(dirname "$0")/.."

checksum() {
    file="$(mktemp)"
    if command -v curl > /dev/null; then
        curl -fsSL --retry 3 -o "$file" "$1"
    else
        wget -q -O "$file" "$1"
    fi
    sha256sum "$file" | cut -d' ' -f1
    rm -f "$file"
}

# Value of a line "name=value" of a script, or "  NAME: "value"" of .gitlab-ci.yml.
script_value() {
    sed -n "s/^$1=\(.*\)$/\1/p" "$2" | head -n 1
}
ci_value() {
    sed -n "s/^  $1: \"\(.*\)\"$/\1/p" .gitlab-ci.yml | head -n 1
}

changed() {
    git diff -U0 -- "$2" | grep -q "^+$1"
}

# Tools installed by the scripts of ci/: version=…, then sha256=… on the next line.
if changed 'version=' ci/install-composer.sh; then
    version="$(script_value version ci/install-composer.sh)"
    sum="$(checksum "https://getcomposer.org/download/$version/composer.phar")"
    sed -i "s/^sha256=.*/sha256=$sum/" ci/install-composer.sh
    echo "Composer $version: $sum"
fi
if changed 'version=' ci/install-k6.sh; then
    version="$(script_value version ci/install-k6.sh)"
    sum="$(checksum "https://github.com/grafana/k6/releases/download/v$version/k6-v$version-linux-amd64.tar.gz")"
    sed -i "s/^sha256=.*/sha256=$sum/" ci/install-k6.sh
    echo "k6 $version: $sum"
fi
if changed 'version=' ci/install-nuclei.sh; then
    version="$(script_value version ci/install-nuclei.sh)"
    sum="$(checksum "https://github.com/projectdiscovery/nuclei/releases/download/v$version/nuclei_${version}_linux_amd64.zip")"
    sed -i "s/^sha256=.*/sha256=$sum/" ci/install-nuclei.sh
    echo "Nuclei $version: $sum"
fi
if changed 'version=' ci/install-sonar-scanner.sh; then
    version="$(script_value version ci/install-sonar-scanner.sh)"
    sum="$(checksum "https://binaries.sonarsource.com/Distribution/sonar-scanner-cli/sonar-scanner-cli-$version-linux-x64.zip")"
    sed -i "s/^sha256=.*/sha256=$sum/" ci/install-sonar-scanner.sh
    echo "SonarScanner $version: $sum"
fi

# Tools pinned in the variables of .gitlab-ci.yml: NAME_VERSION, then NAME_SHA256.
if changed '  SCORECARD_VERSION:' .gitlab-ci.yml; then
    version="$(ci_value SCORECARD_VERSION)"
    sum="$(checksum "https://github.com/ossf/scorecard/releases/download/v$version/scorecard_${version}_linux_amd64.tar.gz")"
    sed -i "s/^  SCORECARD_SHA256: \".*\"$/  SCORECARD_SHA256: \"$sum\"/" .gitlab-ci.yml
    echo "Scorecard $version: $sum"
fi
if changed '  COMPOSE_VERSION:' .gitlab-ci.yml; then
    version="$(ci_value COMPOSE_VERSION)"
    sum="$(checksum "https://github.com/docker/compose/releases/download/$version/docker-compose-linux-x86_64")"
    sed -i "s/^  COMPOSE_SHA256: \".*\"$/  COMPOSE_SHA256: \"$sum\"/" .gitlab-ci.yml
    echo "Docker Compose $version: $sum"
fi

# Tag of the CI image: start of the SHA-256 of its Dockerfile, of the two scripts, of the key and of DEBIAN_IMAGE.
tag="$({ cat ci/php/Dockerfile ci/install-php.sh ci/install-composer.sh ci/debsuryorg-archive-keyring.gpg; printf '%s' "$(ci_value DEBIAN_IMAGE)"; } | sha256sum | cut -c1-12)"
sed -i "s#^  CI_PHP_IMAGE: \"\$CI_REGISTRY_IMAGE/ci-php:[0-9a-f]*\"\$#  CI_PHP_IMAGE: \"\$CI_REGISTRY_IMAGE/ci-php:$tag\"#" .gitlab-ci.yml
echo "CI image: ci-php:$tag"

# Third-party license notices: they follow composer.lock (PHP is part of Renovate's full image).
if ! git diff --quiet -- composer.lock; then
    php tools/third-party-licenses.php
fi
