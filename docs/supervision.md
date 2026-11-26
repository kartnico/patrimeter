# Supervision

Deux adresses, sans session ni authentification, pour une sonde ou un collecteur de métriques. Elles ne
donnent aucune donnée d'utilisateur ; nginx ne les sert, comme le reste du site, qu'aux réseaux privés
([§ 8](installation.md#6-nginx) de l'[installation](installation.md)). Pour les réserver à
la machine de supervision, ajoutez à
`/etc/nginx/snippets/patrimeter-app.conf` un `location ^~ /sante` avec ses propres `allow`.

| Adresse | Format | Pour |
|---|---|---|
| `/sante` | JSON (`application/health+json`, [format « health check »](https://datatracker.ietf.org/doc/html/draft-inadarei-api-health-check)) | sonde de disponibilité : Uptime Kuma, supervision du proxy… |
| `/sante/metriques` | texte de [Prometheus](https://prometheus.io/docs/instrumenting/exposition_formats/) | Prometheus, Grafana Alloy, collecteur OpenTelemetry |

**Pourquoi Prometheus plutôt qu'OpenTelemetry ?** Le format de Prometheus s'écrit sans bibliothèque, alors
qu'OpenTelemetry demanderait son SDK, une dépendance à ajouter hors de Symfony. Le collecteur OpenTelemetry
lit ce format nativement (récepteur `prometheus`), et la plupart des outils aussi : rien n'est perdu.

## Vérifications

| Vérification | Échec | Seuil |
|---|---|---|
| Base | `fail` : `/sante` répond **503** | la base ne répond pas à une requête élémentaire |
| Dernière sauvegarde réussie | `warn` | plus de 26 heures (sauvegarde quotidienne à 03:15) |
| Dernières tâches planifiées | `warn` | plus de 2 heures (passage chaque heure) |
| Place libre du disque de `var/` | `warn` | moins de 512 Mio |
| Nouvelle version | `warn` | une version plus récente est publiée (vérification quotidienne, si elle est activée) |

Un avertissement laisse la réponse en 200 : l'application reste utilisable. L'âge de la sauvegarde vient
d'un fichier témoin que le script de sauvegarde écrit dans `var/supervision/` (l'application n'a pas accès
au dossier des archives) ; les tâches planifiées écrivent le leur à chaque passage. Tant que l'une n'a jamais
abouti, sa vérification reste en avertissement.

**Dernière version publiée** : désactivée par défaut, Patrimeter n'appelle alors rien à l'extérieur. Avec
`UPDATE_CHECK_URL` (adresse de l'API GitLab de la dernière Release, par exemple
`https://gitlab.example.org/api/v4/projects/56/releases/permalink/latest`) et, pour un projet privé,
`UPDATE_CHECK_TOKEN` (jeton d'accès de portée `read_api`, rôle Reporter), une tâche planifiée la demande chaque
jour. Une version plus récente est signalée une fois par e-mail à `ADMIN_EMAIL`, puis par la vérification
`update:available` et la métrique `patrimeter_update_available` jusqu'à la mise à jour.

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

## Métriques

| Métrique | Sens |
|---|---|
| `patrimeter_up` | 1 si aucune vérification n'échoue (la base répond) |
| `patrimeter_database_up` | 1 si la base répond |
| `patrimeter_database_response_seconds` | durée d'une requête élémentaire sur la base |
| `patrimeter_last_backup_timestamp_seconds` | date Unix de la dernière sauvegarde réussie (0 : aucune) |
| `patrimeter_last_maintenance_timestamp_seconds` | date Unix du dernier passage des tâches planifiées (0 : aucun) |
| `patrimeter_disk_free_bytes` | espace libre du disque de `var/` |
| `patrimeter_update_available` | 1 si une version plus récente est publiée (vérification quotidienne activée) |

Des dates plutôt que des âges : c'est l'usage de Prometheus, l'âge se calcule dans la règle d'alerte.

## Exemples

Prometheus :

```yaml
scrape_configs:
  - job_name: patrimeter
    scheme: https
    metrics_path: /sante/metriques
    static_configs:
      - targets: ['patrimeter.example.org']
```

Règles d'alerte :

```yaml
groups:
  - name: patrimeter
    rules:
      - alert: PatrimeterIndisponible
        expr: up{job="patrimeter"} == 0 or patrimeter_up == 0
        for: 5m
      - alert: PatrimeterSauvegardeAncienne
        expr: time() - patrimeter_last_backup_timestamp_seconds > 26 * 3600
      - alert: PatrimeterTachesPlanifieesArretees
        expr: time() - patrimeter_last_maintenance_timestamp_seconds > 2 * 3600
      - alert: PatrimeterDisquePlein
        expr: patrimeter_disk_free_bytes < 512 * 1024 * 1024
```

Collecteur OpenTelemetry :

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

Uptime Kuma : sonde « HTTP(s) - Json Query » sur `https://patrimeter.example.org/sante`, expression
`status`, valeur attendue `pass` (ou sonde HTTP simple : 503 si la base ne répond pas).

## Autres signaux

- **Alertes par e-mail** (`ADMIN_EMAIL`) : erreur critique de l'application, échec de la sauvegarde ou des
  tâches planifiées ([installation](installation.md#7-services)).
- **Violations de la CSP** signalées par les navigateurs : `var/log/csp-AAAA-MM-JJ.log`
  ([SECURITY.md](../SECURITY.md)).
