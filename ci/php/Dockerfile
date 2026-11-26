# CI image of Patrimeter (job "ci-php-image", docs/ci.md): Debian 13 with PHP 8.3, 8.4 and 8.5 from Ondřej Surý's
# repository, the extensions the jobs ask for, and Composer, installed by the very scripts of the jobs
# (ci/install-php.sh, ci/install-composer.sh): on this image, they only select the version. Never used in production.
ARG DEBIAN_IMAGE=public.ecr.aws/docker/library/debian:13-slim@sha256:a72b4433de1cf7d6bae0c4cee74d0547f58601a643b3375f070c51600d63b6b2
FROM ${DEBIAN_IMAGE}
LABEL org.opencontainers.image.title="Patrimeter CI PHP" \
      org.opencontainers.image.source="https://gitlab.example.org/kartzone/patrimeter" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="ci"

COPY ci/install-php.sh ci/install-composer.sh ci/debsuryorg-archive-keyring.gpg /opt/patrimeter-ci/

# pcov installed but disabled: install-php.sh enables it for the jobs that ask for it (coverage, mutation tests).
# OPcache is a package up to PHP 8.4 only: from 8.5 on, it is part of PHP itself.
RUN set -eu; \
    for version in 8.3 8.4 8.5; do \
        opcache="$(case "$version" in 8.3|8.4) echo "php$version-opcache" ;; esac)"; \
        sh /opt/patrimeter-ci/install-php.sh "$version" "php$version-curl" "php$version-pcov" ${opcache:+"$opcache"} unzip git; \
    done; \
    phpdismod -v ALL pcov; \
    sh /opt/patrimeter-ci/install-composer.sh; \
    rm -rf /var/lib/apt/lists/* /.cache /etc/dpkg/dpkg.cfg.d/02-ci-unsafe-io
