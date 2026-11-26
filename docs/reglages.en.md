# Settings

Patrimeter's settings are variables, one per line (`NAME=value`), in a single file:

- **on Linux**: `/srv/patrimeter/.env.local` ([installation](installation.en.md#3-configuring));
- **with Docker**: `patrimeter.env`, next to `compose.yaml` ([Docker](docker.en.md)).

They can be changed at any time, without touching the data. The full commented list is in
[`.env.example`](../.env.example).

## 1. Changing a setting

### On Linux

```sh
# Installed PHP version
PHP=8.5
# Open the settings file and change the line you want
nano /srv/patrimeter/.env.local
# Taken into account: the site and the scheduled tasks start again with the new settings
systemctl restart "php$PHP-fpm" patrimeter-scheduler.service
```

### With Docker

```sh
cd /srv/patrimeter-docker
# Open the settings file and change the line you want
nano patrimeter.env
# Taken into account: the container is recreated with the new settings, the database is kept
docker compose up -d
```

In `patrimeter.env`, values are written without quotes (`MAIL_FROM=Patrimeter <patrimeter@example.com>`); in
`.env.local`, a value that contains spaces goes between quotes.

## 2. Examples

### Turning the demonstration mode on

The "Try the demo" button of the sign-in page opens a temporary account, filled with example data, without
registration; it is deleted after 2 hours or at sign-out. Off by default: only turn it on for an instance shown to
visitors.

```text
DEMO_MODE=true
```

### Changing the email server

```text
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=patrimeter@example.com
SMTP_PASSWORD=password-of-the-smtp-account
SMTP_ENCRYPTION=starttls
MAIL_FROM="Patrimeter <patrimeter@example.com>"
```

`SMTP_ENCRYPTION`: `starttls` (port 587), `tls` (port 465) or `none` (local relay). After the restart, a test email
is sent to `ADMIN_EMAIL`:

```sh
# On Linux
runuser -u www-data -- php /srv/patrimeter/bin/console app:alert 'Test'
# With Docker
docker compose exec app patrimeter-console app:alert 'Test'
```

## 3. Main settings

| Setting | Default | Effect |
|---|---|---|
| `DEFAULT_URI` | — | public address of the site: links of the emails, passkeys |
| `TRUSTED_PROXIES` | empty | address of the reverse proxy that carries HTTPS |
| `APP_TIMEZONE` | `Europe/Paris` | time zone of the dates and of the current month |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `MAIL_FROM` | empty | sending emails; empty `SMTP_HOST`: no email |
| `ADMIN_EMAIL` | `root@localhost` | recipient of the server's alerts |
| `REGISTRATION_ENABLED` | `true` | "Create an account" on the sign-in page |
| `REGISTRATION_ALLOWED_DOMAINS` | empty | registrations limited to these domains (`example.com,example.org`) |
| `DEMO_MODE` | `false` | "Try the demo" button |
| `GAMIFICATION` | `true` | challenges and stars (each user can also hide them) |
| `API_ENABLED`, `MCP_ENABLED` | `false` | REST API and MCP service for AI agents ([API](api.en.md)) |
| `CARBURE_ALLOWED_HOSTS` | empty | Carbure instances allowed (empty: all) |
| `DOCUMENTATION_URL`, `DOCUMENTATION_ON_LOGIN` | documentation site, `true` | link to this documentation |
| `SHOW_VERSION` | `true` | version at the bottom of the pages |
| `UPDATE_CHECK_URL`, `UPDATE_CHECK_TOKEN` | empty | daily check for new versions ([monitoring](supervision.en.md)) |

## 4. Not to change

- `APP_SECRET`: changing it signs everyone out.
- `AUTH_SECRET_KEY`: only changed with the planned procedure ([rotating the key](installation.en.md#rotating-auth_secret_key)),
  otherwise every user would have to set up their authenticator app again.
- `SESSION_COOKIE_NAME` and `DATABASE_URL`: set by the installation.
