# Container image of Patrimeter (docs/docker.md): Debian and PHP from Ondřej Surý's repository, never the official
# "php" images. nginx and php-fpm run in the same container; the data (SQLite database, sessions, logs) lives in the
# /srv/patrimeter/var volume. Build: docker build, podman build or buildah (CI).
#
# Recommended versions: Debian 13 and PHP 8.5 (the same as the CI: .gitlab-ci.yml, RECOMMENDED_DEBIAN_IMAGE and
# RECOMMENDED_PHP_VERSION). Base image pinned by its digest. PHP 8.3 to 8.5 and any recent Debian work:
# --build-arg DEBIAN_IMAGE=… --build-arg PHP_VERSION=….
ARG DEBIAN_IMAGE=public.ecr.aws/docker/library/debian:13-slim@sha256:a72b4433de1cf7d6bae0c4cee74d0547f58601a643b3375f070c51600d63b6b2
ARG PHP_VERSION=8.5

# ---------------------------------------------------------------- build: dependencies and assets
FROM ${DEBIAN_IMAGE} AS build
ARG PHP_VERSION
WORKDIR /src
COPY ci/install-php.sh ci/install-composer.sh ci/debsuryorg-archive-keyring.gpg ci/
RUN sh ci/install-php.sh "$PHP_VERSION" unzip "php$PHP_VERSION-curl" && sh ci/install-composer.sh
COPY . .
# Like the delivery archive (ci/build-release.sh): dependencies without the development tools, compiled assets, no
# cache nor log; RELEASE holds the version (PATRIMETER_RELEASE argument).
ARG PATRIMETER_RELEASE=local
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-scripts --classmap-authoritative --no-interaction --no-progress \
    && APP_ENV=prod php bin/console asset-map:compile --quiet \
    && test ! -d vendor/phpunit \
    && printf '%s\n' "$PATRIMETER_RELEASE" > RELEASE \
    && rm -rf var/* .cache

# ---------------------------------------------------------------- run time
FROM ${DEBIAN_IMAGE}
ARG PHP_VERSION
ARG PATRIMETER_RELEASE=local
LABEL org.opencontainers.image.title="Patrimeter" \
      org.opencontainers.image.description="Suivi de patrimoine net mensuel, en pyramide" \
      org.opencontainers.image.source="https://github.com/kartnico/patrimeter" \
      org.opencontainers.image.documentation="https://kartnico.github.io/patrimeter/" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${PATRIMETER_RELEASE}"
ENV PATRIMETER_PHP_VERSION=${PHP_VERSION} \
    APP_ENV=prod \
    PATRIMETER_DIR=/srv/patrimeter \
    PATRIMETER_BACKUP_DIR=/var/backups/patrimeter
COPY ci/install-php.sh ci/debsuryorg-archive-keyring.gpg /tmp/php/
# PHP (Surý) with php-fpm; nginx, tini (process 1), sqlite3, procps, gzip and age for the backup (Debian). Then the
# security fixes of the base image's packages (pinned, hence frozen): each build starts from the
# packages of the day.
WORKDIR /tmp/php
# OPcache: built into PHP from 8.5, separate package before.
RUN opcache="$(case "$PHP_VERSION" in 8.3|8.4) echo "php$PHP_VERSION-opcache" ;; esac)" \
    && sh install-php.sh "$PHP_VERSION" "php$PHP_VERSION-fpm" ${opcache:+"$opcache"} "php$PHP_VERSION-curl" \
    && apt-get -qq install -y --no-install-recommends nginx tini sqlite3 procps age > /dev/null \
    && apt-get -qq upgrade -y > /dev/null \
    && apt-get -qq clean && rm -rf /var/lib/apt/lists/* /tmp/php /etc/dpkg/dpkg.cfg.d/02-ci-unsafe-io \
    && rm -f /etc/nginx/sites-enabled/default "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf" \
    && sed -i 's#^error_log = .*#error_log = /proc/self/fd/2#' "/etc/php/$PHP_VERSION/fpm/php-fpm.conf" \
    && ln -sf /dev/stdout /var/log/nginx/access.log && ln -sf /dev/stderr /var/log/nginx/error.log \
    && ln -sf /dev/stdout /var/log/nginx/patrimeter.example.org.access.log \
    && ln -sf /dev/stderr /var/log/nginx/patrimeter.example.org.error.log
# /tmp/php has just been removed: the next steps start from the root.
WORKDIR /
# Same settings as on a server (docs/php-fpm, docs/nginx); the pool keeps the container's environment, which carries
# the configuration (clear_env = no).
COPY docs/php-fpm/patrimeter.conf /etc/php/${PHP_VERSION}/fpm/pool.d/patrimeter.conf
COPY docs/php-fpm/patrimeter-opcache.ini /etc/php/${PHP_VERSION}/fpm/conf.d/90-patrimeter.ini
COPY docs/nginx/patrimeter-app.conf /etc/nginx/snippets/patrimeter-app.conf
COPY docs/nginx/patrimeter-limits.conf /etc/nginx/conf.d/patrimeter-limits.conf
COPY docker/nginx.conf /etc/nginx/sites-enabled/patrimeter
COPY docker/entrypoint.sh /usr/local/bin/patrimeter-entrypoint
COPY docker/console.sh /usr/local/bin/patrimeter-console
COPY docs/backup/patrimeter-backup.sh /usr/local/sbin/patrimeter-backup
COPY docs/backup/patrimeter-restore.sh /usr/local/sbin/patrimeter-restore
# php-fpm without a version number: the entry point depends on no environment variable, which a configuration file
# or the CI could redefine.
RUN printf 'clear_env = no\n' >> "/etc/php/$PHP_VERSION/fpm/pool.d/patrimeter.conf" \
    && ln -s "/usr/sbin/php-fpm$PHP_VERSION" /usr/local/sbin/php-fpm \
    && chmod 0755 /usr/local/bin/patrimeter-entrypoint /usr/local/bin/patrimeter-console \
        /usr/local/sbin/patrimeter-backup /usr/local/sbin/patrimeter-restore
COPY --from=build /src /srv/patrimeter
WORKDIR /srv/patrimeter
# Check of the image before delivering it: nginx and php-fpm configuration, then full start on a throwaway database
# (migrations, sign-in page served), without leaving anything in the image.
RUN chown -R root:root /srv/patrimeter && install -d -o www-data -g www-data -m 0700 var \
    && install -d /run/php && nginx -t -q && "php-fpm$PHP_VERSION" -t && rm -rf /run/php \
    && patrimeter-entrypoint selftest
VOLUME ["/srv/patrimeter/var", "/var/backups/patrimeter"]
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:8080/connexion') === false ? 1 : 0);"]
ENTRYPOINT ["tini", "-g", "--", "patrimeter-entrypoint"]
CMD ["web"]
