# Réglages

Les réglages de Patrimeter sont des variables, une par ligne (`NOM=valeur`), dans un seul fichier :

- **sur Linux** : `/srv/patrimeter/.env.local` ([installation](installation.md#3-configurer)) ;
- **avec Docker** : `patrimeter.env`, à côté de `compose.yaml` ([Docker](docker.md)).

Ils se changent à tout moment, sans toucher aux données. La liste complète, commentée, est dans
[`.env.example`](../.env.example).

## 1. Changer un réglage

### Sur Linux

```sh
# Version de PHP installée
PHP=8.5
# Ouvrir le fichier des réglages et modifier la ligne voulue
nano /srv/patrimeter/.env.local
# Prise en compte : le site et les tâches planifiées repartent avec les nouveaux réglages
systemctl restart "php$PHP-fpm" patrimeter-scheduler.service
```

### Avec Docker

```sh
cd /srv/patrimeter-docker
# Ouvrir le fichier des réglages et modifier la ligne voulue
nano patrimeter.env
# Prise en compte : le conteneur est recréé avec les nouveaux réglages, la base est conservée
docker compose up -d
```

Dans `patrimeter.env`, les valeurs s'écrivent sans guillemets (`MAIL_FROM=Patrimeter <patrimeter@example.fr>`) ; dans
`.env.local`, une valeur qui contient des espaces se met entre guillemets.

## 2. Exemples

### Activer le mode démonstration

Le bouton « Essayer la démo » de la page de connexion ouvre un compte temporaire, rempli de données d'exemple, sans
inscription ; il est effacé au bout de 2 heures ou à la déconnexion. Désactivé par défaut : ne l'activez que sur une
instance montrée à des visiteurs.

```text
DEMO_MODE=true
```

### Changer de serveur d'envoi des e-mails

```text
SMTP_HOST=smtp.example.fr
SMTP_PORT=587
SMTP_USER=patrimeter@example.fr
SMTP_PASSWORD=mot-de-passe-du-compte-smtp
SMTP_ENCRYPTION=starttls
MAIL_FROM="Patrimeter <patrimeter@example.fr>"
```

`SMTP_ENCRYPTION` : `starttls` (port 587), `tls` (port 465) ou `none` (relais local). Après le redémarrage, un e-mail
d'essai part vers `ADMIN_EMAIL` :

```sh
# Sur Linux
runuser -u www-data -- php /srv/patrimeter/bin/console app:alert 'Essai'
# Avec Docker
docker compose exec app patrimeter-console app:alert 'Essai'
```

## 3. Principaux réglages

| Réglage | Par défaut | Effet |
|---|---|---|
| `DEFAULT_URI` | — | adresse publique du site : liens des e-mails, clés d'accès |
| `TRUSTED_PROXIES` | vide | adresse du proxy inverse qui porte le HTTPS |
| `APP_TIMEZONE` | `Europe/Paris` | fuseau horaire des dates et du mois en cours |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `MAIL_FROM` | vide | envoi des e-mails ; `SMTP_HOST` vide : aucun e-mail |
| `ADMIN_EMAIL` | `root@localhost` | destinataire des alertes du serveur |
| `REGISTRATION_ENABLED` | `true` | « Créer un compte » sur la page de connexion |
| `REGISTRATION_ALLOWED_DOMAINS` | vide | inscriptions réservées à ces domaines (`example.fr,example.com`) |
| `DEMO_MODE` | `false` | bouton « Essayer la démo » |
| `GAMIFICATION` | `true` | défis et étoiles (chacun peut aussi les masquer pour lui) |
| `API_ENABLED`, `MCP_ENABLED` | `false` | API REST et service MCP pour les agents IA ([API](api.md)) |
| `CARBURE_ALLOWED_HOSTS` | vide | instances Carbure autorisées (vide : toutes) |
| `DOCUMENTATION_URL`, `DOCUMENTATION_ON_LOGIN` | site de la documentation, `true` | lien vers cette documentation |
| `SHOW_VERSION` | `true` | version au pied des pages |
| `UPDATE_CHECK_URL`, `UPDATE_CHECK_TOKEN` | vide | vérification quotidienne des nouvelles versions ([supervision](supervision.md)) |

## 4. À ne pas changer

- `APP_SECRET` : le changer déconnecte tout le monde.
- `AUTH_SECRET_KEY` : se change seulement par la procédure prévue
  ([changer la clé](installation.md#changer-la-cle-auth_secret_key)), sans quoi chaque utilisateur devrait reconfigurer
  son application d'authentification.
- `SESSION_COOKIE_NAME` et `DATABASE_URL` : fixés par l'installation.
