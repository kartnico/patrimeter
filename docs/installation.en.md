# Installing on Linux

Installation from the archive of a version. For a container, see [Installing with Docker](docker.en.md).
Run the commands as **root**. You can repeat the installation steps with the same archive without changing permissions
on data in `var/`. For a new version, follow the [update procedure](mise-a-jour.en.md).

## 1. Requirements

- Linux with **systemd**. The examples use the most common paths (`/etc/php/8.5/fpm`, `php8.5-fpm` service): adapt
  them if your distribution has others.
- **PHP 8.3, 8.4 or 8.5** (8.5 recommended), with **php-fpm** and the `sqlite3`, `xml`, `mbstring`, `intl` and
  `curl` extensions.
- **nginx**.
- The `sqlite3`, `openssl`, `curl` and `tar` tools.
- **HTTPS**, mandatory: a reverse proxy in front of the server, or a certificate on the server ([§ 6](#6-nginx)).
- Optional: an SMTP server, for the emails (registrations, forgotten password, alerts).

## 2. Installing the archive

Download the `patrimeter-vX.Y.Z.tar.gz` archive and its `patrimeter-vX.Y.Z.tar.gz.sha256` checksum from the page of
the latest version (GitLab project, *Deploy > Releases*), into `/tmp`.

<!-- ci:install -->
```sh
# The most recent of the archives downloaded into /tmp
ARCHIVE="$(ls /tmp/patrimeter-v*.tar.gz | sort -V | tail -n 1)"
# Checksum checked: the command prints "OK"
(cd /tmp && sha256sum -c "$(basename "$ARCHIVE").sha256")
# Extract the code as root without touching existing data or secrets
mkdir -p /srv/patrimeter
tar -xzf "$ARCHIVE" -C /srv/patrimeter --strip-components=1 --no-same-owner \
    --anchored --no-wildcards-match-slash --exclude='*/var' --exclude='*/.env.local'
# Create only the missing directories
cd /srv/patrimeter
[ -d var ] || install -d -o www-data -g www-data -m 0750 var
[ -d var/uploads ] || install -d -o www-data -g www-data -m 0700 var/uploads
```

Only `public/` is served by nginx: do not put Patrimeter under `/var/www/`.

## 3. Configuring

The server's configuration and its secrets go into `/srv/patrimeter/.env.local`, created once:

<!-- ci:install -->
```sh
cd /srv/patrimeter
# File created only if it does not exist: the secrets must never change
if [ ! -f .env.local ]; then
    {
        echo 'APP_ENV=prod'
        echo "APP_SECRET=$(openssl rand -hex 32)"
        echo "AUTH_SECRET_KEY=$(openssl rand -base64 32)"
        echo 'DEFAULT_URI=https://patrimeter.example.org'
        echo 'SESSION_COOKIE_NAME=__Host-patrimeter'
        echo 'TRUSTED_PROXIES=192.168.2.1'
    } > .env.local
fi
# Readable by root and php-fpm only
chown root:www-data .env.local
chmod 0640 .env.local
```

Then adapt in `.env.local`:

- `DEFAULT_URI`: the public address of the site;
- `TRUSTED_PROXIES`: the address of the reverse proxy (line removed without a proxy);
- for the emails: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `MAIL_FROM` and
  `ADMIN_EMAIL` (recipient of the alerts);
- for a private instance: `REGISTRATION_ENABLED=false` if the administrator creates the accounts (the demonstration
  mode, `DEMO_MODE`, is off by default).

To change them later, and the list of the main settings: [Settings](reglages.en.md).

**Copy `.env.local` off the server** (password manager): without `AUTH_SECRET_KEY`, every user would have to set up
their authenticator app again.

## 4. Database

<!-- ci:install -->
```sh
cd /srv/patrimeter
# Application cache, then the database
runuser -u www-data -- php bin/console cache:warmup --no-interaction
runuser -u www-data -- php bin/console doctrine:migrations:migrate --no-interaction
```

## 5. php-fpm

A dedicated pool ([php-fpm/patrimeter.conf](php-fpm/patrimeter.conf)) and the OPcache setting
([php-fpm/patrimeter-opcache.ini](php-fpm/patrimeter-opcache.ini)):

<!-- ci:install -->
```sh
# Installed PHP version
PHP=8.5
# Patrimeter's pool and OPcache setting; the default pool is not used
cp /srv/patrimeter/docs/php-fpm/patrimeter.conf "/etc/php/$PHP/fpm/pool.d/patrimeter.conf"
cp /srv/patrimeter/docs/php-fpm/patrimeter-opcache.ini "/etc/php/$PHP/fpm/conf.d/90-patrimeter.ini"
rm -f "/etc/php/$PHP/fpm/pool.d/www.conf"
# Configuration checked, then restart
"php-fpm$PHP" -t
systemctl restart "php$PHP-fpm"
```

## 6. nginx

The virtual host serves the files of `public/`, passes to php-fpm only the application's addresses and limits the
sign-in attempts. Access is restricted to private networks (`allow` directives of
`/etc/nginx/snippets/patrimeter-app.conf`, to narrow down to your networks). Choose **one** of the two variants.

### 6.1 Behind a reverse proxy

The server stays on HTTP, port 80; the proxy holds the certificate.

<!-- ci:install -->
```sh
# Common settings, sign-in limits and virtual host behind a proxy
cp /srv/patrimeter/docs/nginx/patrimeter-app.conf /etc/nginx/snippets/patrimeter-app.conf
cp /srv/patrimeter/docs/nginx/patrimeter-limits.conf /etc/nginx/conf.d/patrimeter-limits.conf
cp /srv/patrimeter/docs/nginx/patrimeter-proxied.conf /etc/nginx/sites-available/patrimeter.example.org.conf
# Virtual host enabled, in place of the default site
ln -sf ../sites-available/patrimeter.example.org.conf /etc/nginx/sites-enabled/patrimeter.example.org.conf
rm -f /etc/nginx/sites-enabled/default
# Configuration checked, then restart
nginx -t
systemctl restart nginx
```

The proxy passes the `Host`, `X-Forwarded-Proto` and `X-Forwarded-For` headers, and `TRUSTED_PROXIES` holds its
address; otherwise, signing in fails. Example with nginx:

```nginx
location / {
    proxy_pass http://192.168.2.10:80;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

### 6.2 HTTPS on the server

Put the certificate and its chain into `/etc/ssl/certs/patrimeter.example.org.pem`, the private key into
`/etc/ssl/private/patrimeter.example.org.key` (readable by root only), then:

<!-- ci:tls -->
```sh
# Without a proxy: TRUSTED_PROXIES is not used
sed -i '/^TRUSTED_PROXIES=/d' /srv/patrimeter/.env.local
# Common settings, sign-in limits and virtual host over HTTPS
cp /srv/patrimeter/docs/nginx/patrimeter-app.conf /etc/nginx/snippets/patrimeter-app.conf
cp /srv/patrimeter/docs/nginx/patrimeter-limits.conf /etc/nginx/conf.d/patrimeter-limits.conf
cp /srv/patrimeter/docs/nginx/patrimeter-tls.conf /etc/nginx/sites-available/patrimeter.example.org.conf
# Virtual host enabled, in place of the default site
ln -sf ../sites-available/patrimeter.example.org.conf /etc/nginx/sites-enabled/patrimeter.example.org.conf
rm -f /etc/nginx/sites-enabled/default
# Configuration checked, then restart
nginx -t
systemctl restart nginx
```

## 7. Services

Scheduled tasks (purges, reminders, database upkeep), email alerts to `ADMIN_EMAIL` on failure, and the update
command:

<!-- ci:install -->
```sh
# Scheduled tasks and alerts
install -m 0644 /srv/patrimeter/docs/systemd/patrimeter-scheduler.service /etc/systemd/system/patrimeter-scheduler.service
install -m 0644 /srv/patrimeter/docs/systemd/patrimeter-alert@.service /etc/systemd/system/patrimeter-alert@.service
systemctl daemon-reload
systemctl enable --now patrimeter-scheduler.service
# Update command (see Updating)
install -m 0755 /srv/patrimeter/docs/update/patrimeter-update.sh /usr/local/sbin/patrimeter-update
```

Then set up the **daily backup**: [Backup and restore](backup.en.md#1-setting-up).

## 8. Checking

<!-- ci:verify -->
```sh
# Secure cookie, HSTS and content security policy, on a public page: each command prints one line
curl -sSI https://patrimeter.example.org/mot-de-passe-oublie | grep -i '^set-cookie: __Host-patrimeter=.*secure'
curl -sSI https://patrimeter.example.org/mot-de-passe-oublie | grep -i '^strict-transport-security'
curl -sSI https://patrimeter.example.org/mot-de-passe-oublie | grep -i '^content-security-policy'
```

If the first two print nothing, the application does not see HTTPS: check the proxy's headers and `TRUSTED_PROXIES`
([§ 6.1](#61-behind-a-reverse-proxy)).

```sh
# Installed PHP version
PHP=8.5
# The three services are running: the command answers "active" three times
systemctl is-active "php$PHP-fpm" nginx patrimeter-scheduler.service
# Test email to ADMIN_EMAIL
runuser -u www-data -- php /srv/patrimeter/bin/console app:alert 'Test'
```

Finally open the site: the **installation wizard** checks the configuration (HTTPS, address of the site, sending
emails, alerts) and creates the first account, yours; you then set up your authenticator app. On error:
`/srv/patrimeter/var/log/` (application) and `/var/log/nginx/` (web server).

## 9. What next

- [Updating](mise-a-jour.en.md) and rolling back.
- [Backup and restore](backup.en.md).
- [Monitoring](supervision.en.md): health probe, metrics, check of new versions.
- Users: the next accounts are created from the sign-in page ("Create an account", if `REGISTRATION_ENABLED=true`)
  or with `runuser -u www-data -- php /srv/patrimeter/bin/console app:user:create you@example.com`; `app:user:list`
  lists them with their number of accounts, `list app` gives every administration command.

### Rotating `AUTH_SECRET_KEY`

If the key may have leaked. Wealth data does not depend on it: only the secrets of the authenticator apps and the
backup codes are concerned.

```sh
# Installed PHP version
PHP=8.5
# Database backup
/usr/local/sbin/patrimeter-backup
# Old key kept in AUTH_SECRET_KEY_PREVIOUS, new key drawn at random
cd /srv/patrimeter
sed -i '/^AUTH_SECRET_KEY_PREVIOUS=/d' .env.local
sed -i 's/^AUTH_SECRET_KEY=/AUTH_SECRET_KEY_PREVIOUS=/' .env.local
echo "AUTH_SECRET_KEY=$(openssl rand -base64 32)" >> .env.local
# Restart, then the secrets are encrypted again with the new key
systemctl restart "php$PHP-fpm" patrimeter-scheduler.service
runuser -u www-data -- php bin/console app:auth-secrets:rotate
```

Then ask each user to generate new backup codes (My account page), and remove the old key:

```sh
# Installed PHP version
PHP=8.5
# Old key removed, then restart
sed -i '/^AUTH_SECRET_KEY_PREVIOUS=/d' /srv/patrimeter/.env.local
systemctl restart "php$PHP-fpm" patrimeter-scheduler.service
```

Finally copy the new `.env.local` off the server.
