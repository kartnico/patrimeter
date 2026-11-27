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

Starting with the 3.5 updater, `patrimeter-update` prepares the new release before stopping the web service and
scheduled tasks. It then backs up the last confirmed entry, verifies the copy, applies migrations and restarts.
The copy stays with the previous release, outside backup rotation. If creating or checking it fails, the old
release restarts. A rollback verifies the copy before interrupting the running site.

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
