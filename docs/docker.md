# Installer avec Docker

Patrimeter tourne dans trois conteneurs décrits par `compose.yaml` : l'application, les tâches planifiées et la
sauvegarde quotidienne. Les commandes se lancent en **root** (ou avec un utilisateur du groupe `docker`).

## 1. Prérequis

- Linux avec **Docker** et **Docker Compose** (ou Podman 4.7 et plus récent, avec `podman compose`).
- Le nom du dépôt Docker Hub public indiqué dans le README de la version ; aucun compte GitLab nécessaire.
- Un **proxy inverse HTTPS** devant le conteneur : HTTPS est obligatoire.
- Facultatif : un serveur SMTP, pour les e-mails (inscriptions, mot de passe oublié, alertes).

## 2. Installer

Ouvrez le tag de version sur le dépôt GitHub public. Téléchargez `compose.yaml` et `patrimeter.env.example` depuis
le dossier `docker/`. Remplacez `kartnico1/patrimeter` ci-dessous par le dépôt publié.

```sh
# Dossier de l'installation, avec les deux fichiers téléchargés
mkdir -p /srv/patrimeter-docker
cd /srv/patrimeter-docker
# Configuration à partir du modèle, lisible par root seulement
printf 'PATRIMETER_IMAGE=docker.io/kartnico1/patrimeter:latest\n' > .env
cp patrimeter.env.example patrimeter.env
chmod 600 patrimeter.env
# Deux secrets à recopier dans patrimeter.env : APP_SECRET, puis AUTH_SECRET_KEY
openssl rand -hex 32
openssl rand -base64 32
```

Dans `patrimeter.env`, renseignez aussi :

- `DEFAULT_URI` : l'adresse publique du site, en `https://` ;
- `TRUSTED_PROXIES` : l'adresse du proxy inverse ;
- `SMTP_*`, `MAIL_FROM` et `ADMIN_EMAIL`, pour les e-mails.

Gardez une copie de `patrimeter.env` hors du serveur : sans `AUTH_SECRET_KEY`, les utilisateurs doivent reconfigurer
leur application d'authentification.

## 3. Démarrer

```sh
cd /srv/patrimeter-docker
# Démarrage des trois services
docker compose up -d
# État des services : « app » doit être « healthy »
docker compose ps
```

Le conteneur sert le site en HTTP sur le port 8080. Le proxy inverse le publie en HTTPS, par exemple avec nginx :

```nginx
server {
    listen 443 ssl;
    server_name patrimeter.example.fr;
    # ssl_certificate et ssl_certificate_key : votre certificat

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Port 443;
    }
}
```

Ouvrez enfin le site : l'**assistant d'installation** vérifie la configuration (HTTPS, adresse du site, envoi des
e-mails, alertes) et crée le premier compte, le vôtre. Les comptes suivants : « Créer un compte » sur la page de
connexion, ou `docker compose exec app patrimeter-console app:user:create vous@example.fr`. Pour changer un réglage
plus tard (mode démonstration, serveur d'e-mails…) : [Réglages](reglages.md).

## 4. Mettre à jour

Lisez d'abord la rubrique **Installation** du [CHANGELOG](../CHANGELOG.md) pour chaque version plus récente.

```sh
cd /srv/patrimeter-docker
# Image de la dernière version
docker compose pull
# Redémarrage : le conteneur « app » sauvegarde la base, puis applique les migrations
docker compose up -d
```

À chaque nouvelle version, la base est sauvegardée avant tout changement, dans le volume des données
(`var/backups-before-update`, les trois dernières). Le pied de chaque page affiche la version installée.

**Déploiement automatique facultatif (Docker Compose v2)** : après avoir testé vos sauvegardes, planifiez
`docker compose pull && docker compose up -d --wait` depuis le dossier d’installation. Surveillez les échecs. Cela suit
le tag choisi et conserve les volumes nommés ; ne faites jamais `down -v`. Une empreinte reste figée.

**Revenir en arrière** : redémarrez sur l'image de la version précédente, puis restaurez la sauvegarde faite avant la
mise à jour.

```sh
cd /srv/patrimeter-docker
# Version précédente (exemple)
export PATRIMETER_IMAGE=docker.io/kartnico1/patrimeter:vX.Y.Z
# Arrêt, puis restauration de la dernière sauvegarde d'avant la mise à jour
docker compose stop app scheduler backup
docker compose run --rm --no-deps app sh -c \
    'patrimeter-restore "$(ls var/backups-before-update/patrimeter-*.sqlite.gz | sort | tail -n 1)"'
# Redémarrage sur la version précédente
docker compose up -d
```

## 5. Sauvegarder et restaurer

Le service `backup` sauvegarde la base chaque jour dans le volume `backups` (30 archives gardées). Copiez ces archives
hors du serveur, avec `patrimeter.env` ([sauvegarde](backup.md)).

```sh
cd /srv/patrimeter-docker
# Sauvegarde immédiate, puis liste des archives
docker compose exec backup patrimeter-backup
docker compose exec backup ls -l /var/backups/patrimeter
```

Restaurer une archive :

```sh
cd /srv/patrimeter-docker
# Archive à restaurer (exemple)
ARCHIVE=/var/backups/patrimeter/patrimeter-20270115-031500.sqlite.gz
# Arrêt, contrôle de l'archive, restauration, redémarrage
docker compose stop app scheduler backup
docker compose run --rm --no-deps backup patrimeter-restore --check "$ARCHIVE"
docker compose run --rm --no-deps backup patrimeter-restore "$ARCHIVE"
docker compose up -d
```

## 6. Podman

`compose.yaml` fonctionne tel quel avec `podman compose`, y compris sans root : gardez alors le port 8080 (un port
au-dessus de 1024).
