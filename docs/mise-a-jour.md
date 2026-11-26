# Mettre à jour Patrimeter

Avant chaque mise à jour, lisez la rubrique **Installation** du [CHANGELOG](../CHANGELOG.md) pour chaque version plus
récente que la vôtre, et faites ce qu'elle demande (une nouvelle variable de configuration, par exemple). Le plus
souvent, il n'y a rien à faire. La version installée est affichée au pied de chaque page.

Dans les deux cas, la base est **sauvegardée automatiquement** avant tout changement.

## 1. Avec Docker

Voir [Installer avec Docker, § 4](docker.md#4-mettre-a-jour) : `docker compose pull`, puis `docker compose up -d`.

## 2. Sur Linux

Téléchargez l'archive de la nouvelle version et son empreinte (projet GitLab, *Deploy > Releases*) dans `/tmp`, puis,
en **root** :

<!-- ci:update -->
```sh
# La plus récente des archives téléchargées dans /tmp
ARCHIVE="$(ls /tmp/patrimeter-v*.tar.gz | sort -V | tail -n 1)"
# Sauvegarde de la base, puis installation de cette version
patrimeter-update "$ARCHIVE"
```

`patrimeter-update` vérifie l'empreinte de l'archive, sauvegarde la base, coupe le service une à deux minutes,
installe la nouvelle version à côté de l'ancienne, applique les migrations, puis redémarre. Il garde l'ancienne
version pour un retour arrière.

Vérifiez ensuite :

```sh
# Version de PHP installée
PHP=8.5
# Les trois services tournent : la commande répond trois fois « active »
systemctl is-active "php$PHP-fpm" nginx patrimeter-scheduler.service
# L'application répond : état « pass »
curl -fsS https://patrimeter.example.org/sante
```

Connectez-vous : vos montants sont ceux d'avant, et le pied de page affiche la nouvelle version.

## 3. Revenir en arrière (Linux)

Si la nouvelle version ne fonctionne pas :

<!-- ci:rollback -->
```sh
# Version précédente et base d'avant la mise à jour
patrimeter-update --rollback
```

Les saisies faites depuis la mise à jour sont perdues.
