# Monitoring

Two addresses, without session or authentication, for a probe or a metrics collector. They give no user data;
like the rest of the site, nginx only serves them to private networks
([§ 8](installation.en.md#6-nginx) of the [installation](installation.en.md)). To
restrict them to the monitoring machine, add a
`location ^~ /sante` with its own `allow` directives to `/etc/nginx/snippets/patrimeter-app.conf`.

| Address | Format | For |
|---|---|---|
| `/sante` | JSON (`application/health+json`, ["health check" format](https://datatracker.ietf.org/doc/html/draft-inadarei-api-health-check)) | availability probe: Uptime Kuma, proxy monitoring… |
| `/sante/metriques` | [Prometheus](https://prometheus.io/docs/instrumenting/exposition_formats/) text | Prometheus, Grafana Alloy, OpenTelemetry Collector |

**Why Prometheus rather than OpenTelemetry?** The Prometheus format can be written without a library, whereas
OpenTelemetry would require its SDK, a dependency to add outside Symfony. The OpenTelemetry Collector reads
this format natively (`prometheus` receiver), and so do most tools: nothing is lost.

## Checks

| Check | Failure | Threshold |
|---|---|---|
| Database | `fail`: `/sante` answers **503** | the database does not answer a basic query |
| Last successful backup | `warn` | more than 26 hours (daily backup at 03:15) |
| Last scheduled tasks | `warn` | more than 2 hours (run every hour) |
| Free space on the `var/` disk | `warn` | less than 512 MiB |
| New version | `warn` | a more recent version is published (daily check, if enabled) |

A warning keeps the answer at 200: the application remains usable. The age of the backup comes from a marker
file that the backup script writes in `var/supervision/` (the application has no access to the archive
folder); the scheduled tasks write their own at every run. As long as one of them has never succeeded, its
check stays in warning.

**Latest published version**: disabled by default, Patrimeter then calls nothing outside. With `UPDATE_CHECK_URL`
(address of the GitLab API of the latest Release, for instance
`https://gitlab.example.org/api/v4/projects/56/releases/permalink/latest`) and, for a private project,
`UPDATE_CHECK_TOKEN` (access token with the `read_api` scope, Reporter role), a scheduled task asks for it every day.
A more recent version is reported once by email to `ADMIN_EMAIL`, then by the `update:available` check and the
`patrimeter_update_available` metric until the update.

```json
{
  "status": "pass",
  "checks": {
    "database:responseTime": [{"status": "pass", "observedValue": 0.41, "observedUnit": "ms"}],
    "backup:age": [{"status": "pass", "observedValue": 21340, "observedUnit": "s"}],
    "scheduler:age": [{"status": "pass", "observedValue": 1250, "observedUnit": "s"}],
    "disk:free": [{"status": "pass", "observedValue": 21474836480, "observedUnit": "bytes"}],
    "update:available": [{"status": "pass", "observedValue": null}]
  }
}
```

## Metrics

| Metric | Meaning |
|---|---|
| `patrimeter_up` | 1 if no check fails (the database answers) |
| `patrimeter_database_up` | 1 if the database answers |
| `patrimeter_database_response_seconds` | duration of a basic query on the database |
| `patrimeter_last_backup_timestamp_seconds` | Unix time of the last successful backup (0: none) |
| `patrimeter_last_maintenance_timestamp_seconds` | Unix time of the last run of the scheduled tasks (0: none) |
| `patrimeter_disk_free_bytes` | free space on the `var/` disk |
| `patrimeter_update_available` | 1 if a more recent version is published (daily check enabled) |

Timestamps rather than ages: this is the Prometheus way, the age is computed in the alerting rule.

## Examples

Prometheus:

```yaml
scrape_configs:
  - job_name: patrimeter
    scheme: https
    metrics_path: /sante/metriques
    static_configs:
      - targets: ['patrimeter.example.org']
```

Alerting rules:

```yaml
groups:
  - name: patrimeter
    rules:
      - alert: PatrimeterDown
        expr: up{job="patrimeter"} == 0 or patrimeter_up == 0
        for: 5m
      - alert: PatrimeterBackupTooOld
        expr: time() - patrimeter_last_backup_timestamp_seconds > 26 * 3600
      - alert: PatrimeterScheduledTasksStopped
        expr: time() - patrimeter_last_maintenance_timestamp_seconds > 2 * 3600
      - alert: PatrimeterDiskFull
        expr: patrimeter_disk_free_bytes < 512 * 1024 * 1024
```

OpenTelemetry Collector:

```yaml
receivers:
  prometheus:
    config:
      scrape_configs:
        - job_name: patrimeter
          scheme: https
          metrics_path: /sante/metriques
          static_configs:
            - targets: ['patrimeter.example.org']
```

Uptime Kuma: "HTTP(s) - Json Query" probe on `https://patrimeter.example.org/sante`, expression `status`,
expected value `pass` (or a plain HTTP probe: 503 if the database does not answer).

## Other signals

- **Email alerts** (`ADMIN_EMAIL`): critical application error, failed backup or scheduled tasks
  ([installation](installation.en.md#7-services)).
- **CSP violations** reported by browsers: `var/log/csp-YYYY-MM-DD.log` ([SECURITY.md](../SECURITY.en.md)).
