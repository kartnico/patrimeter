# Installer sur Linux

Installation à partir de l'archive d'une version. Pour un conteneur, voir [Installer avec Docker](docker.md).
Toutes les commandes se lancent en **root**, et chacune peut être rejouée sans risque.

## 1. Prérequis

- Linux avec **systemd**. Les exemples utilisent les chemins les plus répandus (`/etc/php/8.5/fpm`, service
  `php8.5-fpm`) : adaptez-les si votre distribution en a d'autres.
- **PHP 8.3, 8.4 ou 8.5** (8.5 recommandé), avec **php-fpm** et les extensions `sqlite3`, `xml`, `mbstring`, `intl`
  et `curl`.
- **nginx**.
- Les outils `sqlite3`, `openssl`, `curl` et `tar`.
- **HTTPS**, obligatoire : un proxy inverse devant le serveur, ou un certificat sur le serveur ([§ 6](#6-nginx)).
- Facultatif : un serveur SMTP, pour les e-mails (inscriptions, mot de passe oublié, alertes).

## 2. Installer l'archive

Téléchargez l'archive `patrimeter-vX.Y.Z.tar.gz` et son empreinte `patrimeter-vX.Y.Z.tar.gz.sha256` depuis la page de
la dernière version (projet GitLab, *Deploy > Releases*), dans `/tmp`.

<!-- ci:install -->
```sh
# La plus récente des archives téléchargées dans /tmp
ARCHIVE="$(ls /tmp/patrimeter-v*.tar.gz | sort -V | tail -n 1)"
# Empreinte vérifiée : la commande affiche « Réussi » (ou « OK »)
(cd /tmp && sha256sum -c "$(basename "$ARCHIVE").sha256")
# Code dans /srv/patrimeter, à root
mkdir -p /srv/patrimeter
tar -xzf "$ARCHIVE" -C /srv/patrimeter --strip-components=1
chown -R root:root /srv/patrimeter
# Seul var/ (base, sessions, cache, journaux) appartient à l'utilisateur de php-fpm
install -d -o www-data -g www-data -m 0750 /srv/patrimeter/var
install -d -o www-data -g www-data -m 0700 /srv/patrimeter/var/uploads
```

Seul `public/` est servi par nginx : ne placez pas Patrimeter sous `/var/www/`.

## 3. Configurer

La configuration du serveur et ses secrets vont dans `/srv/patrimeter/.env.local`, créé une seule fois :

<!-- ci:install -->
```sh
cd /srv/patrimeter
# Création du fichier, seulement s'il n'existe pas : les secrets ne doivent jamais changer
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
# Lisible par root et par php-fpm seulement
chown root:www-data .env.local
chmod 0640 .env.local
```

Adaptez ensuite dans `.env.local` :

- `DEFAULT_URI` : l'adresse publique du site ;
- `TRUSTED_PROXIES` : l'adresse du proxy inverse (ligne retirée sans proxy) ;
- pour les e-mails : `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `MAIL_FROM` et
  `ADMIN_EMAIL` (destinataire des alertes) ;
- pour une instance privée : `REGISTRATION_ENABLED=false` si l'administrateur crée les comptes (le mode démonstration,
  `DEMO_MODE`, est désactivé par défaut).

Pour les changer plus tard, et la liste des principaux réglages : [Réglages](reglages.md).

**Copiez `.env.local` hors du serveur** (gestionnaire de mots de passe) : sans `AUTH_SECRET_KEY`, chaque utilisateur
devrait reconfigurer son application d'authentification.

## 4. Base de données

<!-- ci:install -->
```sh
cd /srv/patrimeter
# Cache de l'application, puis création de la base
runuser -u www-data -- php bin/console cache:warmup --no-interaction
runuser -u www-data -- php bin/console doctrine:migrations:migrate --no-interaction
```

## 5. php-fpm

Un pool dédié ([php-fpm/patrimeter.conf](php-fpm/patrimeter.conf)) et le réglage d'OPcache
([php-fpm/patrimeter-opcache.ini](php-fpm/patrimeter-opcache.ini)) :

<!-- ci:install -->
```sh
# Version de PHP installée
PHP=8.5
# Pool de Patrimeter et réglage d'OPcache ; le pool par défaut ne sert pas
cp /srv/patrimeter/docs/php-fpm/patrimeter.conf "/etc/php/$PHP/fpm/pool.d/patrimeter.conf"
cp /srv/patrimeter/docs/php-fpm/patrimeter-opcache.ini "/etc/php/$PHP/fpm/conf.d/90-patrimeter.ini"
rm -f "/etc/php/$PHP/fpm/pool.d/www.conf"
# Configuration vérifiée, puis redémarrage
"php-fpm$PHP" -t
systemctl restart "php$PHP-fpm"
```

## 6. nginx

Le vhost sert les fichiers de `public/`, transmet à php-fpm les seules adresses de l'application et limite les
tentatives de connexion. L'accès est réservé aux réseaux privés (directives `allow` de
`/etc/nginx/snippets/patrimeter-app.conf`, à restreindre à vos réseaux). Choisissez **une** des deux variantes.

### 6.1 Derrière un proxy inverse

Le serveur reste en HTTP sur le port 80 ; le proxy porte le certificat.

<!-- ci:install -->
```sh
# Réglages communs, limites de connexion et vhost derrière un proxy
cp /srv/patrimeter/docs/nginx/patrimeter-app.conf /etc/nginx/snippets/patrimeter-app.conf
cp /srv/patrimeter/docs/nginx/patrimeter-limits.conf /etc/nginx/conf.d/patrimeter-limits.conf
cp /srv/patrimeter/docs/nginx/patrimeter-proxied.conf /etc/nginx/sites-available/patrimeter.example.org.conf
# Activation du vhost, à la place du site par défaut
ln -sf ../sites-available/patrimeter.example.org.conf /etc/nginx/sites-enabled/patrimeter.example.org.conf
rm -f /etc/nginx/sites-enabled/default
# Configuration vérifiée, puis redémarrage
nginx -t
systemctl restart nginx
```

Le proxy transmet les en-têtes `Host`, `X-Forwarded-Proto` et `X-Forwarded-For`, et `TRUSTED_PROXIES` contient son
adresse ; sinon, la connexion échoue. Exemple avec nginx :

```nginx
location / {
    proxy_pass http://192.168.2.10:80;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

### 6.2 HTTPS sur le serveur

Déposez le certificat et sa chaîne dans `/etc/ssl/certs/patrimeter.example.org.pem`, la clé privée dans
`/etc/ssl/private/patrimeter.example.org.key` (lisible par root seul), puis :

<!-- ci:tls -->
```sh
# Sans proxy : TRUSTED_PROXIES ne sert pas
sed -i '/^TRUSTED_PROXIES=/d' /srv/patrimeter/.env.local
# Réglages communs, limites de connexion et vhost en HTTPS
cp /srv/patrimeter/docs/nginx/patrimeter-app.conf /etc/nginx/snippets/patrimeter-app.conf
cp /srv/patrimeter/docs/nginx/patrimeter-limits.conf /etc/nginx/conf.d/patrimeter-limits.conf
cp /srv/patrimeter/docs/nginx/patrimeter-tls.conf /etc/nginx/sites-available/patrimeter.example.org.conf
# Activation du vhost, à la place du site par défaut
ln -sf ../sites-available/patrimeter.example.org.conf /etc/nginx/sites-enabled/patrimeter.example.org.conf
rm -f /etc/nginx/sites-enabled/default
# Configuration vérifiée, puis redémarrage
nginx -t
systemctl restart nginx
```

## 7. Services

Tâches planifiées (purges, rappels, entretien de la base), alertes par e-mail à `ADMIN_EMAIL` en cas d'échec, et
commande de mise à jour :

<!-- ci:install -->
```sh
# Tâches planifiées et alertes
install -m 0644 /srv/patrimeter/docs/systemd/patrimeter-scheduler.service /etc/systemd/system/patrimeter-scheduler.service
install -m 0644 /srv/patrimeter/docs/systemd/patrimeter-alert@.service /etc/systemd/system/patrimeter-alert@.service
systemctl daemon-reload
systemctl enable --now patrimeter-scheduler.service
# Commande de mise à jour (voir Mise à jour)
install -m 0755 /srv/patrimeter/docs/update/patrimeter-update.sh /usr/local/sbin/patrimeter-update
```

Mettez ensuite en place la **sauvegarde quotidienne** : [Sauvegarde et restauration](backup.md#1-mise-en-place).

## 8. Vérifier

<!-- ci:verify -->
```sh
# Cookie sécurisé, HSTS et politique de sécurité du contenu, sur une page publique : chaque commande affiche une ligne
curl -sSI https://patrimeter.example.org/mot-de-passe-oublie | grep -i '^set-cookie: __Host-patrimeter=.*secure'
curl -sSI https://patrimeter.example.org/mot-de-passe-oublie | grep -i '^strict-transport-security'
curl -sSI https://patrimeter.example.org/mot-de-passe-oublie | grep -i '^content-security-policy'
```

Si les deux premières n'affichent rien, l'application ne voit pas le HTTPS : vérifiez les en-têtes du proxy et
`TRUSTED_PROXIES` ([§ 6.1](#61-derriere-un-proxy-inverse)).

```sh
# Version de PHP installée
PHP=8.5
# Les trois services tournent : la commande répond trois fois « active »
systemctl is-active "php$PHP-fpm" nginx patrimeter-scheduler.service
# E-mail d'essai à ADMIN_EMAIL
runuser -u www-data -- php /srv/patrimeter/bin/console app:alert 'Essai'
```

Ouvrez enfin le site : l'**assistant d'installation** vérifie la configuration (HTTPS, adresse du site, envoi des
e-mails, alertes) et crée le premier compte, le vôtre ; vous configurez ensuite votre application d'authentification.
En cas d'erreur : `/srv/patrimeter/var/log/` (application) et `/var/log/nginx/` (serveur web).

## 9. Et ensuite

- [Mise à jour](mise-a-jour.md) et retour arrière.
- [Sauvegarde et restauration](backup.md).
- [Supervision](supervision.md) : sonde de santé, métriques, vérification des nouvelles versions.
- Utilisateurs : les comptes suivants se créent depuis la page de connexion (« Créer un compte », si
  `REGISTRATION_ENABLED=true`) ou par `runuser -u www-data -- php /srv/patrimeter/bin/console app:user:create
  vous@example.fr` ; `app:user:list` les liste avec leur nombre de comptes, `list app` donne toutes les commandes
  d'administration.

### Changer la clé `AUTH_SECRET_KEY`

Si la clé a pu fuiter. Les données patrimoniales n'en dépendent pas : seuls les secrets des applications
d'authentification et les codes de secours sont concernés.

```sh
# Version de PHP installée
PHP=8.5
# Sauvegarde de la base
/usr/local/sbin/patrimeter-backup
# Ancienne clé gardée dans AUTH_SECRET_KEY_PREVIOUS, nouvelle clé tirée au hasard
cd /srv/patrimeter
sed -i '/^AUTH_SECRET_KEY_PREVIOUS=/d' .env.local
sed -i 's/^AUTH_SECRET_KEY=/AUTH_SECRET_KEY_PREVIOUS=/' .env.local
echo "AUTH_SECRET_KEY=$(openssl rand -base64 32)" >> .env.local
# Redémarrage, puis rechiffrement des secrets avec la nouvelle clé
systemctl restart "php$PHP-fpm" patrimeter-scheduler.service
runuser -u www-data -- php bin/console app:auth-secrets:rotate
```

Demandez ensuite à chaque utilisateur de générer de nouveaux codes de secours (page Mon compte), puis retirez
l'ancienne clé :

```sh
# Version de PHP installée
PHP=8.5
# Ancienne clé retirée, puis redémarrage
sed -i '/^AUTH_SECRET_KEY_PREVIOUS=/d' /srv/patrimeter/.env.local
systemctl restart "php$PHP-fpm" patrimeter-scheduler.service
```

Copiez enfin le nouveau `.env.local` hors du serveur.
