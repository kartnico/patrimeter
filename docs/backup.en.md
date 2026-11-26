# Backup and restore

All commands are run as **root**. The CI runs this page on a real deployment: backup, data change, restore,
then signing in and reading the restored data (`deployment` job, see [ci.md](ci.md#deploiement), in French).
The commands are the same as in the French version of this page.

## What to back up

| Item | How | Why |
|---|---|---|
| Database `/srv/patrimeter/var/patrimeter.sqlite` | Every day, [§ 1](#1-setting-up) | All the data: accounts, statements, users, key envelopes |
| `/srv/patrimeter/.env.local` | **Once**, by hand, in a password manager | `AUTH_SECRET_KEY` is only there: without it, TOTP must be set up again ([§ 7](#7-if-envlocal-is-lost)) |
| TLS certificate, if the server holds one ([installation, § 6.2](installation.en.md#62-https-on-the-server)) | By hand | Saves requesting it again |

Everything else is regenerated and is not backed up: code, `vendor/`, `public/assets/`, `var/cache/`,
`var/sessions/`, `var/log/`. The files installed in `/etc/systemd/system/` and `/usr/local/sbin/` come from
the archive (`docs/`) and are reinstalled at every update.

**Never copy the database with `cp` while the application is running**: in WAL mode, part of the recent data
is in the `-wal` file, and the copy may be inconsistent. The script uses SQLite's backup API, which works on
a live database.

**Content of an archive**: wealth data is encrypted in it, and unreadable without each user's password
([encryption.md](encryption.en.md)). What remains in clear text: email addresses, password hashes, the
sign-in log (IP addresses) and the months entered. Do not put the archives somewhere you do not control
without encrypting them. Keep `.env.local` elsewhere: stealing an archive then does not give away the TOTP
secrets either.

## 1. Setting up

The `sqlite3` command is one of the [requirements](installation.en.md#1-requirements).

<!-- ci:backup -->
```sh
install -m 0755 /srv/patrimeter/docs/backup/patrimeter-backup.sh /usr/local/sbin/patrimeter-backup
install -m 0755 /srv/patrimeter/docs/backup/patrimeter-restore.sh /usr/local/sbin/patrimeter-restore
install -m 0644 /srv/patrimeter/docs/backup/patrimeter-backup.service /etc/systemd/system/patrimeter-backup.service
install -m 0644 /srv/patrimeter/docs/backup/patrimeter-backup.timer /etc/systemd/system/patrimeter-backup.timer
install -m 0644 /srv/patrimeter/docs/backup/patrimeter-restore-check.service /etc/systemd/system/patrimeter-restore-check.service
install -m 0644 /srv/patrimeter/docs/backup/patrimeter-restore-check.timer /etc/systemd/system/patrimeter-restore-check.timer
systemctl daemon-reload
systemctl enable --now patrimeter-backup.timer patrimeter-restore-check.timer
systemctl start patrimeter-backup.service   # first backup, right away
```

Checking:

```sh
systemctl list-timers patrimeter-backup.timer       # next run
journalctl -u patrimeter-backup.service -n 5 --no-pager
ls -lh /var/backups/patrimeter/
```

Every night at 03:15, the script copies the database, checks the copy (`PRAGMA integrity_check`), compresses
it into `/var/backups/patrimeter/patrimeter-YYYYMMDD-HHMMSS.sqlite.gz` (UTC time) with its `.sha256` hash,
and keeps the **last 30 archives**. Rotation is by count, not by age: if backups stop, the last ones remain.
If the server is off at 03:15, the backup runs when it starts again.

To keep another number of archives, `systemctl edit patrimeter-backup.service`, then:

```ini
[Service]
Environment=PATRIMETER_BACKUP_KEEP=60
```

## 2. Copying off the server

Archives on the same server as the database protect neither from losing it nor from a dead disk. Set up
**one** of these copies.

**Copy encrypted by Patrimeter** (recommended): after each backup, the archive is encrypted with
[age](https://age-encryption.org) for one or more **public keys**, then handed to a copy command of your choice
(`rsync`, `scp`, `rclone`…). The server never has the private key: an archive copied elsewhere can only be read with
it. Without encryption, the copy command is refused.

1. On the machine that will keep the copies (or a safe computer): `apt-get install age`, then
   `age-keygen -o patrimeter-sauvegarde.key`. The command shows the public key (`age1…`). **Keep the private key off
   the server** (password manager, vault): without it, the copies are unreadable.
2. On the server, `apt-get install age`, then `systemctl edit patrimeter-backup.service`:

   ```ini
   [Service]
   Environment=PATRIMETER_BACKUP_AGE_RECIPIENTS=age1…
   Environment='PATRIMETER_BACKUP_COPY=rsync -a "$1" sauvegarde@nas.lan:patrimeter/'
   ```

   - `PATRIMETER_BACKUP_AGE_RECIPIENTS`: one or more public keys separated by spaces, or the absolute path of a
     file holding one per line.
   - `PATRIMETER_BACKUP_COPY`: command run as root, which receives the encrypted archive as `$1`, or as its last
     argument if it does not mention `$1`
     (`/var/backups/patrimeter/chiffrees/patrimeter-YYYYMMDD-HHMMSS.sqlite.gz.age`). Without it, the encrypted
     archives stay in that folder, ready to be pulled from another machine.
3. Run a backup (`systemctl start patrimeter-backup.service`) and check the copy. To read it back, on the machine of
   the private key: `age --decrypt -i patrimeter-sauvegarde.key archive.sqlite.gz.age > archive.sqlite.gz`.

A failing copy makes the backup fail: email alert, and the monitoring reports a backup that is too old
([supervision.md](supervision.en.md)). The local archive, for its part, is made.

**Other options**:

- **Backup of the whole server** (virtual machine snapshot, system backup…): schedule it after 03:15.
  `/var/backups/patrimeter/` is part of it. After restoring the server, start again from an archive
  ([§ 4](#4-restoring-on-the-same-server)) rather than from the database, which may have been copied in the
  middle of a write.
- **Copy pulled from another machine**: that machine does the reading, so a compromised server cannot erase
  its copies. `rsync` package and SSH key on its side, `rsync` on the server. With the encryption above and no copy
  command, pull only `chiffrees/`:

  ```sh
  # On the backup machine, every day after 03:15.
  rsync -a --ignore-existing --exclude 'avant-restauration-*' \
      root@<server address>:/var/backups/patrimeter/ /path/to/backups/patrimeter/
  ```

## 3. Monitoring and checking

A failed backup sends an email to `ADMIN_EMAIL` (`OnFailure=`, see
[installation, § 7](installation.en.md#7-services)), if SMTP is configured. A backup that no
longer starts at all warns nobody: watch for it too, with your monitoring or by hand:

```sh
systemctl is-failed patrimeter-backup.service      # "failed" if the last one failed
find /var/backups/patrimeter -name 'patrimeter-*.sqlite.gz' -mtime -2 | grep -q . && echo 'recent' || echo 'TOO OLD'
```

Full check of an archive, without changing anything: hash, decompression, integrity, foreign keys,
compatibility with the installed code.

<!-- ci:restore -->
```sh
ARCHIVE="$(find /var/backups/patrimeter -maxdepth 1 -name 'patrimeter-*.sqlite.gz' | sort | tail -n 1)"
/usr/local/sbin/patrimeter-restore --check "$ARCHIVE"
```

This same check runs on its own on the **1st of every month** (`patrimeter-restore-check.timer`, at 04:30, on the
latest archive): a failure sends an email to `ADMIN_EMAIL`. To run it right away:
`systemctl start patrimeter-restore-check.service`.

This check covers the structure: the administrator cannot decrypt the data. Only a user signing in proves it:
run a test restore on a throwaway server ([§ 5](#5-restoring-on-a-new-server)) from time to time.

## 4. Restoring on the same server

The service is stopped during the restore (nginx answers 502), scheduled tasks included: they keep the
database open. Replace `$ARCHIVE` with the archive you want if it is not the latest one, found in
[§ 3](#3-monitoring-and-checking). Set `PHP` to the installed version.

<!-- ci:restore -->
```sh
PHP=8.5
systemctl stop "php$PHP-fpm" patrimeter-scheduler.service
/usr/local/sbin/patrimeter-restore "$ARCHIVE"
systemctl start "php$PHP-fpm" patrimeter-scheduler.service
```

The script refuses an invalid archive or one more recent than the code, and refuses to run while php-fpm or
the scheduled tasks are active. It keeps the replaced database in
`/var/backups/patrimeter/avant-restauration-…/` ("before restore"), deletes the `-wal` and `-shm` files,
closes the sessions and applies the missing migrations. If php-fpm crashed, an orphan socket may remain:
delete `/run/php/patrimeter.sock` once php-fpm has really stopped.

To undo, with php-fpm and the scheduled tasks stopped:

```sh
cp -p /var/backups/patrimeter/avant-restauration-…/patrimeter.sqlite* /srv/patrimeter/var/
```

These folders are not rotated: delete them once all is well.

After a failed update, the code must first go back to the previous version:
[mise-a-jour.en.md](mise-a-jour.en.md#3-rolling-back-linux).

## 5. Restoring on a new server

1. Follow the [installation](installation.en.md) up to [§ 3](installation.en.md#3-configuring) included, with a version
   **at least as recent** as the archive, and your `.env.local` put back into `/srv/patrimeter/`.
2. Do [§ 5](installation.en.md#5-php-fpm) and [6](installation.en.md#6-nginx), but not
   [§ 7](installation.en.md#7-services) yet: the scheduled tasks would open the database.
3. Put the archive on the server and restore:

   ```sh
   # Installed PHP version, and archive to restore (example)
   PHP=8.5
   ARCHIVE=/tmp/patrimeter-20270115-031500.sqlite.gz
   # Restore command, then the restore, php-fpm stopped
   install -m 0755 /srv/patrimeter/docs/backup/patrimeter-restore.sh /usr/local/sbin/patrimeter-restore
   systemctl stop "php$PHP-fpm"
   /usr/local/sbin/patrimeter-restore "$ARCHIVE"
   systemctl start "php$PHP-fpm"
   ```

4. Finish with [§ 7](installation.en.md#7-services) of the installation, the backup ([§ 1](#1-setting-up) and
   [2](#2-copying-off-the-server) of this page) and the [check](installation.en.md#8-checking). Finally, point the
   reverse proxy to the new address: on the same domain, the passkeys keep working.

## 6. What a restore changes for users

The whole database goes back to the date of the archive, for **all** users. To tell them:

- entries and accounts created since are lost; user accounts created since no longer exist;
- **sign-in methods also go back to that date**: a password changed since becomes the old one again, and the
  same goes for the TOTP app, the backup codes and the recovery key. A passkey added since is no longer
  recognised: remove it from the device, then register it again;
- what was deleted since reappears, including data erased by a "forgotten password";
- **API access revoked since becomes valid again** if it has not expired: after a restore following a stolen
  token, have the access revoked and created again from the My account page. Access granted since no longer
  exists;
- everyone is signed out;
- sign-in attempt counters also go back to that date (they are in the database).

## 7. If `.env.local` is lost

Create it again as in [§ 3](installation.en.md#3-configuring) of the installation. The data remains readable:
it depends on each
user's password, not on this file.

- A new `APP_SECRET` has no effect on the data.
- With a new `AUTH_SECRET_KEY`, **every password sign-in ends on an error page** for the accounts that have
  TOTP, because its secret can no longer be read. Reset each account, after checking the identity of its
  holder if you are not alone on this server; they will set up a new app at their next sign-in:

  ```sh
  runuser -u www-data -- sqlite3 /srv/patrimeter/var/patrimeter.sqlite 'SELECT email FROM users;'
  runuser -u www-data -- php /srv/patrimeter/bin/console app:user:reset-2fa <email>
  ```
