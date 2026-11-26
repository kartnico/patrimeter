# Installing with Docker

Patrimeter runs in three containers described by `compose.yaml`: the application, the scheduled tasks and the daily
backup. The commands run as **root** (or as a user of the `docker` group).

## 1. Requirements

- Linux with **Docker** and **Docker Compose** (or Podman 4.7 or newer, with `podman compose`).
- The public Docker Hub repository name from the release README; no GitLab account is needed.
- An **HTTPS reverse proxy** in front of the container: HTTPS is mandatory.
- Optional: an SMTP server, for the emails (registrations, forgotten password, alerts).

## 2. Installing

Open the version tag in the public GitHub repository. Download `compose.yaml` and `patrimeter.env.example` from
the `docker/` directory. Replace `kartnico1/patrimeter` below with the published repository.

```sh
# Folder of the installation, with the two downloaded files
mkdir -p /srv/patrimeter-docker
cd /srv/patrimeter-docker
# Configuration from the template, readable by root only
printf 'PATRIMETER_IMAGE=docker.io/kartnico1/patrimeter:latest\n' > .env
cp patrimeter.env.example patrimeter.env
chmod 600 patrimeter.env
# Two secrets to copy into patrimeter.env: APP_SECRET, then AUTH_SECRET_KEY
openssl rand -hex 32
openssl rand -base64 32
```

In `patrimeter.env`, also set:

- `DEFAULT_URI`: the public address of the site, in `https://`;
- `TRUSTED_PROXIES`: the address of the reverse proxy;
- `SMTP_*`, `MAIL_FROM` and `ADMIN_EMAIL`, for the emails.

Keep a copy of `patrimeter.env` off the server: without `AUTH_SECRET_KEY`, the users must set up their
authenticator app again.

## 3. Starting

```sh
cd /srv/patrimeter-docker
# Start the three services
docker compose up -d
# Status of the services: "app" must be "healthy"
docker compose ps
```

The container serves the site over HTTP on port 8080. The reverse proxy publishes it over HTTPS, for example with
nginx:

```nginx
server {
    listen 443 ssl;
    server_name patrimeter.example.fr;
    # ssl_certificate and ssl_certificate_key: your certificate

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Port 443;
    }
}
```

Finally open the site: the **installation wizard** checks the configuration (HTTPS, address of the site, sending
emails, alerts) and creates the first account, yours. The next accounts: "Create an account" on the sign-in page, or
`docker compose exec app patrimeter-console app:user:create you@example.com`. To change a setting later
(demonstration mode, email server…): [Settings](reglages.en.md).

## 4. Updating

First read the **Installation** part of the [CHANGELOG](../CHANGELOG.en.md) for each newer version.

```sh
cd /srv/patrimeter-docker
# Image of the latest version
docker compose pull
# Restart: the "app" container backs up the database, then applies the migrations
docker compose up -d
```

On every new version, the database is backed up before any change, in the data volume
(`var/backups-before-update`, the latest three). The bottom of every page shows the installed version.

**Optional automatic rollout (Docker Compose v2)**: after testing your backups, schedule
`docker compose pull && docker compose up -d --wait` from the installation directory. Monitor failures. This follows
the selected tag and preserves named volumes; never use `down -v`. A digest stays pinned.

**Rolling back**: restart on the image of the previous version, then restore the backup made before the update.

```sh
cd /srv/patrimeter-docker
# Previous version (example)
export PATRIMETER_IMAGE=docker.io/kartnico1/patrimeter:vX.Y.Z
# Stop, then restore the latest backup made before the update
docker compose stop app scheduler backup
docker compose run --rm --no-deps app sh -c \
    'patrimeter-restore "$(ls var/backups-before-update/patrimeter-*.sqlite.gz | sort | tail -n 1)"'
# Restart on the previous version
docker compose up -d
```

## 5. Backing up and restoring

The `backup` service backs up the database every day into the `backups` volume (30 archives kept). Copy these archives
off the server, with `patrimeter.env` ([backup](backup.en.md)).

```sh
cd /srv/patrimeter-docker
# Immediate backup, then list of the archives
docker compose exec backup patrimeter-backup
docker compose exec backup ls -l /var/backups/patrimeter
```

Restoring an archive:

```sh
cd /srv/patrimeter-docker
# Archive to restore (example)
ARCHIVE=/var/backups/patrimeter/patrimeter-20270115-031500.sqlite.gz
# Stop, check the archive, restore, restart
docker compose stop app scheduler backup
docker compose run --rm --no-deps backup patrimeter-restore --check "$ARCHIVE"
docker compose run --rm --no-deps backup patrimeter-restore "$ARCHIVE"
docker compose up -d
```

## 6. Podman

`compose.yaml` works as is with `podman compose`, even without root: then keep port 8080 (a port above 1024).
