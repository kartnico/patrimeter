# Updating Patrimeter

Before each update, read the **Installation** part of the [CHANGELOG](../CHANGELOG.en.md) for each version newer than
yours, and do what it asks (a new configuration variable, for example). Most of the time, there is nothing to do. The
installed version is shown at the bottom of every page.

In both cases, the database is **backed up automatically** before any change.

## 1. With Docker

See [Installing with Docker, § 4](docker.en.md#4-updating): `docker compose pull`, then `docker compose up -d`.

## 2. On Linux

Download the archive of the new version and its checksum (GitLab project, *Deploy > Releases*) into `/tmp`, then, as
**root**:

<!-- ci:update -->
```sh
# The most recent of the archives downloaded into /tmp
ARCHIVE="$(ls /tmp/patrimeter-v*.tar.gz | sort -V | tail -n 1)"
# Backup of the database, then this version installed
patrimeter-update "$ARCHIVE"
```

`patrimeter-update` checks the archive's checksum, backs up the database, stops the service for one or two minutes,
installs the new version next to the old one, applies the migrations, then starts again. It keeps the old version for
a rollback.

Then check:

```sh
# Installed PHP version
PHP=8.5
# The three services are running: the command answers "active" three times
systemctl is-active "php$PHP-fpm" nginx patrimeter-scheduler.service
# The application answers: status "pass"
curl -fsS https://patrimeter.example.org/sante
```

Sign in: your amounts are those from before, and the bottom of the page shows the new version.

## 3. Rolling back (Linux)

If the new version does not work:

<!-- ci:rollback -->
```sh
# Previous version and database from before the update
patrimeter-update --rollback
```

The entries made since the update are lost.
