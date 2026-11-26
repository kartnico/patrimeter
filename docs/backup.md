# Sauvegarde et restauration

Toutes les commandes sont lancées en **root**. La CI exécute cette page sur un vrai déploiement :
sauvegarde, modification des données, restauration, puis connexion et lecture des données restaurées
(job `deployment`, voir [ci.md](ci.md#deploiement)).

## Ce qu'il faut sauvegarder

| Élément | Comment | Pourquoi |
|---|---|---|
| Base `/srv/patrimeter/var/patrimeter.sqlite` | Chaque jour, [§ 1](#1-mise-en-place) | Toutes les données : comptes, relevés, utilisateurs, enveloppes de clés |
| `/srv/patrimeter/.env.local` | **Une fois**, à la main, dans un gestionnaire de mots de passe | `AUTH_SECRET_KEY` y est seule : sans elle, les TOTP sont à reconfigurer ([§ 7](#7-si-envlocal-est-perdu)) |
| Certificat TLS, si le serveur en porte un ([installation, § 6.2](installation.md#62-https-sur-le-serveur)) | À la main | Évite de le redemander |

Le reste se régénère et ne se sauvegarde pas : code, `vendor/`, `public/assets/`, `var/cache/`,
`var/sessions/`, `var/log/`. Les fichiers installés dans `/etc/systemd/system/` et `/usr/local/sbin/`
viennent de l'archive (`docs/`) et sont réinstallés à chaque mise à jour.

**Ne copiez jamais la base avec `cp` pendant que l'application tourne** : en mode WAL, une partie des
données récentes est dans le fichier `-wal`, et la copie peut être incohérente. Le script utilise
l'API de sauvegarde de SQLite, qui fonctionne à chaud.

**Contenu d'une archive** : les données patrimoniales y sont chiffrées, et illisibles sans le mot de
passe de chaque utilisateur ([encryption.md](encryption.md)). Y restent en clair les adresses e-mail,
les empreintes de mots de passe, le journal des connexions (adresses IP) et les mois saisis : ne
déposez pas les archives à un endroit que vous ne contrôlez pas sans les chiffrer. Gardez
`.env.local` ailleurs : voler une archive ne donne alors pas non plus les secrets TOTP.

## 1. Mise en place

La commande `sqlite3` fait partie des [prérequis](installation.md#1-prerequis).

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
systemctl start patrimeter-backup.service   # première sauvegarde, tout de suite
```

Vérification :

```sh
systemctl list-timers patrimeter-backup.timer       # prochaine exécution
journalctl -u patrimeter-backup.service -n 5 --no-pager
ls -lh /var/backups/patrimeter/
```

Chaque nuit à 03:15, le script copie la base, contrôle la copie (`PRAGMA integrity_check`), la
compresse dans `/var/backups/patrimeter/patrimeter-AAAAMMJJ-HHMMSS.sqlite.gz` (heure UTC) avec son
empreinte `.sha256`, et garde les **30 dernières archives**. La rotation se fait au nombre et non à
l'âge : si les sauvegardes s'arrêtent, les dernières restent. Si le serveur est éteint à 03:15, la
sauvegarde part à son redémarrage.

Pour en garder un autre nombre, `systemctl edit patrimeter-backup.service`, puis :

```ini
[Service]
Environment=PATRIMETER_BACKUP_KEEP=60
```

## 2. Copie hors du serveur

Des archives sur le même serveur que la base ne protègent ni de sa perte, ni d'un disque mort. Mettez en place
**l'une** de ces copies.

**Copie chiffrée par Patrimeter** (recommandée) : après chaque sauvegarde, l'archive est chiffrée avec
[age](https://age-encryption.org) pour une ou plusieurs **clés publiques**, puis confiée à une commande de copie de
votre choix (`rsync`, `scp`, `rclone`…). Le serveur n'a jamais la clé privée : une archive copiée ailleurs ne se lit
qu'avec elle. Sans chiffrement, la commande de copie est refusée.

1. Sur la machine qui gardera les copies (ou un poste sûr) : `apt-get install age`, puis
   `age-keygen -o patrimeter-sauvegarde.key`. La commande affiche la clé publique (`age1…`). **Gardez la clé
   privée hors du serveur** (gestionnaire de mots de passe, coffre) : sans elle, les copies sont illisibles.
2. Sur le serveur, `apt-get install age`, puis `systemctl edit patrimeter-backup.service` :

   ```ini
   [Service]
   Environment=PATRIMETER_BACKUP_AGE_RECIPIENTS=age1…
   Environment='PATRIMETER_BACKUP_COPY=rsync -a "$1" sauvegarde@nas.lan:patrimeter/'
   ```

   - `PATRIMETER_BACKUP_AGE_RECIPIENTS` : une ou plusieurs clés publiques séparées par des espaces, ou le chemin
     absolu d'un fichier qui en contient une par ligne.
   - `PATRIMETER_BACKUP_COPY` : commande lancée en root, qui reçoit l'archive chiffrée en `$1`, ou en dernier
     argument si elle ne cite pas `$1` (`/var/backups/patrimeter/chiffrees/patrimeter-AAAAMMJJ-HHMMSS.sqlite.gz.age`).
     Sans elle, les archives
     chiffrées restent dans ce dossier, prêtes à être tirées depuis une autre machine.
3. Lancez une sauvegarde (`systemctl start patrimeter-backup.service`) et vérifiez la copie. Pour la relire, sur la
   machine de la clé privée : `age --decrypt -i patrimeter-sauvegarde.key archive.sqlite.gz.age > archive.sqlite.gz`.

Une copie qui échoue fait échouer la sauvegarde : alerte par e-mail, et la supervision signale une sauvegarde trop
ancienne ([supervision.md](supervision.md)). L'archive locale, elle, est faite.

**Autres possibilités** :

- **Sauvegarde du serveur entier** (instantané de machine virtuelle, sauvegarde système…) : planifiez-la après
  03:15. `/var/backups/patrimeter/` en fait partie. Après une restauration du serveur, repartez d'une
  archive ([§ 4](#4-restaurer-sur-le-meme-serveur)) plutôt que de la base, qui a pu être copiée en pleine écriture.
- **Copie tirée depuis une autre machine** : c'est elle qui lit, donc un serveur compromis ne peut
  pas effacer ses copies. Paquet `rsync` et clé SSH de son côté, `rsync` sur le serveur. Avec le chiffrement
  ci-dessus et sans commande de copie, tirez seulement `chiffrees/` :

  ```sh
  # Sur la machine de sauvegarde, chaque jour après 03:15.
  rsync -a --ignore-existing --exclude 'avant-restauration-*' \
      root@<adresse du serveur>:/var/backups/patrimeter/ /chemin/de/sauvegarde/patrimeter/
  ```

## 3. Surveiller et contrôler

Une sauvegarde qui échoue envoie un e-mail à `ADMIN_EMAIL` (`OnFailure=`, voir
[installation, § 7](installation.md#7-services)), si le SMTP est configuré. Une sauvegarde
qui ne se lance plus du tout ne prévient personne : à surveiller aussi, par votre supervision ou à la main :

```sh
systemctl is-failed patrimeter-backup.service      # « failed » si la dernière a échoué
find /var/backups/patrimeter -name 'patrimeter-*.sqlite.gz' -mtime -2 | grep -q . && echo 'récente' || echo 'TROP ANCIENNE'
```

Contrôle complet d'une archive, sans rien modifier : empreinte, décompression, intégrité, clés
étrangères, compatibilité avec le code installé.

<!-- ci:restore -->
```sh
ARCHIVE="$(find /var/backups/patrimeter -maxdepth 1 -name 'patrimeter-*.sqlite.gz' | sort | tail -n 1)"
/usr/local/sbin/patrimeter-restore --check "$ARCHIVE"
```

Ce même contrôle tourne seul le **1er de chaque mois** (`patrimeter-restore-check.timer`, à 04:30, sur la
dernière archive) : un échec envoie un e-mail à `ADMIN_EMAIL`. Pour le lancer tout de suite :
`systemctl start patrimeter-restore-check.service`.

Ce contrôle porte sur la structure : l'administrateur ne peut pas déchiffrer les données. Seule une
connexion d'un utilisateur le prouve : faites une restauration d'essai sur un serveur jetable ([§ 5](#5-restaurer-sur-un-nouveau-serveur))
de temps en temps.

## 4. Restaurer sur le même serveur

Le service est coupé le temps de la restauration (nginx répond 502), tâches planifiées comprises : elles
gardent la base ouverte. Remplacez `$ARCHIVE` par l'archive voulue si ce n'est pas la dernière, trouvée
au [§ 3](#3-surveiller-et-controler). Adaptez `PHP` à la version installée.

<!-- ci:restore -->
```sh
PHP=8.5
systemctl stop "php$PHP-fpm" patrimeter-scheduler.service
/usr/local/sbin/patrimeter-restore "$ARCHIVE"
systemctl start "php$PHP-fpm" patrimeter-scheduler.service
```

Le script refuse une archive invalide ou plus récente que le code, et refuse de tourner tant que
php-fpm ou les tâches planifiées sont actifs. Il conserve la base remplacée dans
`/var/backups/patrimeter/avant-restauration-…/`, supprime les fichiers `-wal` et `-shm`, ferme les
sessions et applique les migrations manquantes. Si php-fpm a planté, il peut rester un socket
orphelin : supprimez `/run/php/patrimeter.sock` une fois php-fpm réellement arrêté.

Pour annuler, php-fpm et les tâches planifiées arrêtés :

```sh
cp -p /var/backups/patrimeter/avant-restauration-…/patrimeter.sqlite* /srv/patrimeter/var/
```

Ces dossiers ne sont pas soumis à la rotation : supprimez-les quand tout va bien.

Après une mise à jour ratée, le code doit d'abord revenir à la version précédente :
[mise-a-jour.md](mise-a-jour.md#3-revenir-en-arriere-linux).

## 5. Restaurer sur un nouveau serveur

1. Suivez l'[installation](installation.md) jusqu'au [§ 3](installation.md#3-configurer) compris, avec une version
   **au moins aussi récente** que l'archive, et votre `.env.local` remis dans `/srv/patrimeter/`.
2. Faites les [§ 5](installation.md#5-php-fpm) et [6](installation.md#6-nginx), mais pas encore le
   [§ 7](installation.md#7-services) : les tâches planifiées ouvriraient la base.
3. Déposez l'archive sur le serveur et restaurez :

   ```sh
   # Version de PHP installée, et archive à restaurer (exemple)
   PHP=8.5
   ARCHIVE=/tmp/patrimeter-20270115-031500.sqlite.gz
   # Commande de restauration, puis restauration, php-fpm arrêté
   install -m 0755 /srv/patrimeter/docs/backup/patrimeter-restore.sh /usr/local/sbin/patrimeter-restore
   systemctl stop "php$PHP-fpm"
   /usr/local/sbin/patrimeter-restore "$ARCHIVE"
   systemctl start "php$PHP-fpm"
   ```

4. Terminez avec le [§ 7](installation.md#7-services) de l'installation, la sauvegarde ([§ 1](#1-mise-en-place) et
   [2](#2-copie-hors-du-serveur) de cette page) et la [vérification](installation.md#8-verifier). Pointez enfin le
   proxy inverse vers la nouvelle adresse : sur un même domaine, les passkeys continuent de fonctionner.

## 6. Ce qu'une restauration change pour les utilisateurs

Toute la base revient à la date de l'archive, pour **tous** les utilisateurs. À leur annoncer :

- les saisies et les comptes créés depuis sont perdus ; les comptes utilisateurs créés depuis
  n'existent plus ;
- **les moyens d'accès reviennent aussi à cette date** : un mot de passe changé depuis redevient
  l'ancien, et de même pour l'application TOTP, les codes de secours et la clé de récupération. Une
  passkey ajoutée depuis n'est plus reconnue : à retirer de l'appareil puis à réenregistrer ;
- ce qui a été supprimé depuis réapparaît, y compris des données effacées par un « mot de passe oublié » ;
- **les accès à l'API retirés depuis redeviennent valables** s'ils n'ont pas expiré : après une restauration
  consécutive à un vol de jeton, faites retirer et recréer les accès depuis la page Mon compte. Ceux accordés
  depuis n'existent plus ;
- tout le monde est déconnecté ;
- les compteurs de tentatives de connexion reviennent aussi à cette date (ils sont dans la base).

## 7. Si `.env.local` est perdu

Recréez-le comme au [§ 3](installation.md#3-configurer) de l'installation. Les données restent lisibles :
elles dépendent du mot de
passe de chaque utilisateur, pas de ce fichier.

- Un nouveau `APP_SECRET` est sans conséquence sur les données.
- Avec une nouvelle `AUTH_SECRET_KEY`, **toute connexion par mot de passe se termine sur une page
  d'erreur** pour les comptes qui ont un TOTP, car son secret est illisible. Réinitialisez chaque
  compte, après avoir vérifié l'identité de son titulaire si vous n'êtes pas seul sur ce serveur ;
  il configurera une nouvelle application à sa prochaine connexion :

  ```sh
  runuser -u www-data -- sqlite3 /srv/patrimeter/var/patrimeter.sqlite 'SELECT email FROM users;'
  runuser -u www-data -- php /srv/patrimeter/bin/console app:user:reset-2fa <e-mail>
  ```
