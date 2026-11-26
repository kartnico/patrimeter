# Choix techniques

Toutes les briques de Patrimeter, avec les alternatives envisagées et la raison de chaque choix.
Versions au 10/11/2026 (v2.0.0). Le fonctionnement d'ensemble est décrit dans
[architecture.md](architecture.md), avec l'état des bonnes pratiques de Symfony.

Contraintes qui guident presque tous ces choix :

- application **auto-hébergée** sur Linux, sur un réseau local, pour quelques
  utilisateurs ;
- **données patrimoniales chiffrées**, illisibles par l'administrateur du serveur ;
- **sécurité stricte côté navigateur** : CSP sans script ni style en ligne, HTML et CSS valides W3C ;
- **peu de dépendances**, maintenues et sous licence compatible MIT, et **ce que propose Symfony
  d'abord** ;
- exploitation simple : aucun service à part (pas de serveur de base de données, pas de Node en
  production).

## Exécution et hébergement

| Besoin | Retenu | Alternatives | Pourquoi ce choix |
|---|---|---|---|
| Système | Linux, avec l'archive d'une version ([installation.md](installation.md)), ou image de conteneur sur Debian ([docker.md](docker.md)) | Hébergement mutualisé ou PaaS | Toute distribution avec PHP, nginx et systemd convient ; l'image part de Debian, stable, sobre et bien outillé, comme la CI |
| Langage | PHP 8.5 (8.3 à 8.5 pris en charge) | Node.js, Python | Continuité avec la v1 ; 8.3 est le minimum de PHPUnit 12 et de DBAL 4.3 ; 8.5 recommandée |
| Source des paquets PHP | Dépôt d'Ondřej Surý (packages.sury.org) | Paquets de Debian (souvent en retard sur PHP), images Docker `php:*` officielles, compilation | Versions récentes et correctifs de sécurité rapides, mêmes paquets en production et en CI ; images officielles exclues par choix du projet |
| Serveur web | nginx + php-fpm (pool dédié, socket Unix) | Apache + mod_php, Caddy, FrankenPHP, RoadRunner | Couple standard, documenté et éprouvé sur Debian ; le pool isole l'application (`open_basedir`, fonctions d'exécution coupées) |
| HTTPS | Proxy inverse existant qui porte le certificat (ou TLS sur le serveur) | Certificat sur chaque service, Let's Encrypt en HTTP | Le réseau a déjà un proxy avec un certificat générique ; WebAuthn exige HTTPS |
| Services de fond | Unités systemd (tâches planifiées, sauvegarde, alertes) | cron, supervisord, conteneurs à part | systemd est déjà là : redémarrage automatique, minuteurs rattrapés après un arrêt, `OnFailure=` pour alerter |
| Cache du code | OPcache : 256 Mo, dates des fichiers non vérifiées, sans préchargement | Réglages par défaut de Debian, préchargement (`opcache.preload`) | Recommandations de performance de Symfony ; chaque procédure de mise à jour redémarre php-fpm ; le préchargement fausse les appels de fonctions avec la liste `disable_functions` du pool |

## Framework et composants

| Besoin | Retenu | Alternatives | Pourquoi ce choix |
|---|---|---|---|
| Framework | Symfony 7.4 LTS | PHP natif (v1), Laravel, Slim ou Mezzio, Symfony 8 | Composants de sécurité éprouvés (pare-feu, CSRF, sessions, voters) au lieu de code maison ; Laravel repose sur des façades (état global) plus difficiles à analyser ; Symfony 8 exige PHP 8.4 et n'est pas LTS ; 7.4 est maintenue jusqu'en 11/2029 |
| Configuration | Variables d'environnement (`.env.local`) | Coffre de secrets de Symfony, fichier PHP de configuration (v1) | Chaque installation a ses propres secrets, gardés hors du dépôt ; un coffre chiffré versionné n'apporte rien ici |
| Injection de dépendances | Conteneur de Symfony, injection automatique | Configuration manuelle des services | Moins de configuration, erreurs détectées à la compilation du conteneur |
| Langues de l'interface | Composant Translation de Symfony, catalogues YAML, clé = texte français | Clés abstraites (`dashboard.title`) en XLIFF, gettext, traduction dans le navigateur | Composant natif de Symfony ; le français reste lisible dans les gabarits et s'affiche si une traduction manque ; un test vérifie que l'anglais est complet |
| Gabarits | Twig 3 | PHP natif (v1), interface monopage React ou Vue | Échappement automatique selon le contexte ; rendu côté serveur, compatible avec une CSP stricte et sans JavaScript obligatoire |
| Formulaires | HTML écrit dans les gabarits, données validées par le Validator de Symfony | Composant Form de Symfony | Contrôle total du HTML (W3C, CSP, mobile) et code existant de la v1 ; écart aux bonnes pratiques de Symfony, migration possible |
| Validation | Validator de Symfony (`PasswordStrength`, `NotCompromisedPassword`, `Collection`…) | Contrôles écrits à la main (v1) | Contraintes déclaratives et réutilisées par les pages, l'API et le MCP |
| Limitation des tentatives | RateLimiter de Symfony, compteurs en base | Compteurs maison (v1), fail2ban, `limit_req` de nginx | Composant natif ; compteurs par IP, par compte et global, qui survivent aux redémarrages |
| Tâches planifiées | Scheduler et Messenger de Symfony | cron + commandes, minuteurs systemd par tâche | Tâches déclarées dans le code, testées comme le reste ; un seul service systemd |
| E-mails | Mailer de Symfony, SMTP, texte brut | `mail()` de PHP, service d'envoi tiers (API) | Natif ; serveur SMTP de l'utilisateur ; texte brut : rien à styler, rien de distant à charger |
| Journaux et alertes | Monolog : fichiers quotidiens gardés 14 jours, e-mail sur erreur critique (dédoublonné) | Sentry, Prometheus et Alertmanager, syslog | Aucun service supplémentaire à héberger ; une alerte par e-mail suffit pour quelques utilisateurs |
| Client HTTP | HttpClient de Symfony (`NoPrivateNetworkHttpClient`) | Guzzle, curl direct | Natif ; interdit les adresses privées, utile contre les requêtes forgées vers l'intérieur |
| Identifiants | UUID v7 (symfony/uid) pour les utilisateurs | Entiers auto-incrémentés, UUID v4 | Non devinables, connus avant l'insertion (le chiffrement lie chaque donnée à sa ligne), triés dans le temps |

## Données

| Besoin | Retenu | Alternatives | Pourquoi ce choix |
|---|---|---|---|
| Base | SQLite en mode WAL (fichier dans `var/`) | PostgreSQL, MariaDB | Aucun service à installer ni à sauvegarder à part ; largement suffisant pour quelques utilisateurs ; lectures non bloquées par les écritures (WAL) |
| Accès aux données | Doctrine DBAL, requêtes SQL préparées dans des dépôts | Doctrine ORM, Eloquent, PDO direct | Chaque valeur est chiffrée et déchiffrée ligne par ligne : un ORM n'apporterait que des contournements ; le SQL reste confiné à `Infrastructure/Persistence/` et filtré par propriétaire |
| Schéma | Doctrine Migrations en **SQL explicite**, avec `down()` | Comparaison automatique du schéma, SQL à la main (v1) | Sous SQLite, la comparaison automatique reconstruisait des tables et déclenchait des suppressions en cascade ; désactivée (`SqlOnlySchemaDiffProvider`) |
| Montants | Entiers en centimes | Nombres à virgule flottante, `DECIMAL`, bibliothèque monétaire (brick/money) | Aucune erreur d'arrondi ; une seule monnaie (euro) ; conversion seulement à la saisie et à l'affichage |
| Quotes-parts | Entiers en millionièmes (100 % = 1 000 000) | Pourcentages flottants | Calculs exacts, arrondi au centime documenté et testé |
| Cache de sécurité | Pool de cache Symfony sur la base (`cache.adapter.doctrine_dbal`) | Fichiers, Redis, APCu | Compteurs de tentatives et codes TOTP déjà utilisés conservés par `cache:clear` et sauvegardés avec la base ; pas de service en plus |
| Sessions | Fichiers dans `var/sessions/` | Base, Redis | Natif et suffisant ; la clé de données n'y est jamais en clair |

## Chiffrement et authentification

| Besoin | Retenu | Alternatives | Pourquoi ce choix |
|---|---|---|---|
| Chiffrement des données | libsodium : XChaCha20-Poly1305, une clé de données par utilisateur, contexte lié à la table, à la ligne et au propriétaire | Chiffrement du disque, SQLCipher, OpenSSL AES-GCM, defuse/php-encryption, chiffrement de bout en bout dans le navigateur | Protège contre un administrateur passif (base, sauvegardes, sessions) sans dépendance ; nonce aléatoire de 192 bits sans risque de collision ; une ligne copiée ailleurs ne se déchiffre pas ; le chiffrement dans le navigateur ne protège pas mieux d'un administrateur actif, qui servirait un JavaScript piégé ([encryption.md](encryption.md)) |
| Clés d'enveloppe | Argon2id (`sodium_crypto_pwhash`, 64 Mio, 3 passes) depuis le mot de passe ; HKDF-SHA256 depuis une passkey (PRF) ou la clé de récupération | PBKDF2, scrypt | Argon2id résiste aux attaques par GPU ; HKDF suffit pour des secrets déjà aléatoires |
| Mots de passe | Argon2id (hacheur de Symfony), 12 caractères au moins, robustesse estimée, mots de passe divulgués refusés (Have I Been Pwned, k-anonymat) | bcrypt (`auto` de Symfony), règles de composition | Recommandé par l'OWASP ; le refus des mots de passe connus vaut mieux que des règles de composition |
| Second facteur | scheb/2fa-bundle 7 : TOTP (spomky-labs/otphp) et codes de secours | TOTP écrit à la main, SMS, e-mail | Intégré au pare-feu : aucune page accessible entre le mot de passe et le code ; pas de dépendance à un opérateur |
| Passkeys et clés FIDO2 | web-auth/webauthn-lib 5.3, extension PRF | web-auth/webauthn-symfony-bundle, lbuchs/WebAuthn | Vérification complète des cérémonies ; la bibliothèque seule laisse les identifiants dans un dépôt SQL comme le reste ; PRF déverrouille les données sans mot de passe |
| Secrets serveur | Secrets TOTP et codes de secours chiffrés par une clé serveur (`AUTH_SECRET_KEY`) | En clair en base | Une fuite de la base seule ne suffit pas pour passer le second facteur |
| QR code | endroid/qr-code 6, matrice dessinée en SVG intégré | Image PNG, JavaScript, service en ligne | Ni style ni script en ligne (CSP) ; le secret ne quitte pas le serveur |
| Protection du navigateur | CSP stricte, HSTS, `nosniff`, Referrer-Policy, Permissions-Policy, jeton CSRF vérifié de façon centralisée | CSP permissive, jeton par formulaire | Défense en profondeur contre l'injection de script ; un seul point de contrôle du CSRF |
| Cloisonnement | Propriétaire passé à chaque dépôt, clés étrangères composites `(id, user_id)`, voters, robots de test sur toutes les routes | Filtre SQL global, bases séparées par utilisateur | Plusieurs barrières indépendantes, vérifiées automatiquement à chaque nouvelle route |

## API et MCP

| Besoin | Retenu | Alternatives | Pourquoi ce choix |
|---|---|---|---|
| API REST | Contrôleurs Symfony, `#[MapRequestPayload]` et Validator | API Platform, FOSRestBundle | Peu de ressources, déjà servies par `PortfolioService` ; mécanismes natifs, aucune dépendance lourde |
| Authentification de l'API | Authentificateur `access_token` de Symfony, jetons porteurs, `access_control` | Clés d'API en clair, JWT, LexikJWTAuthenticationBundle | Natif ; jeton révocable, haché en base ; la clé de déchiffrement voyage dans le jeton, le serveur ne peut pas lire les données seul |
| Serveur d'autorisation | OAuth 2.1 écrit dans le projet (PKCE, RFC 7591, 8414, 9728, 8707, 9207) | league/oauth2-server et son bundle, Keycloak ou Authentik externe | Symfony n'en fournit pas ; la clé de données doit être enveloppée dans le jeton au consentement, ce qu'aucun serveur externe ne sait faire |
| Service MCP | symfony/mcp-bundle et mcp/sdk (officiels, expérimentaux) | php-mcp/server, serveur MCP dans un autre langage | Bundle de Symfony et SDK officiel de MCP ; mêmes services et même authentification que l'API |
| Clients MCP | Documents de métadonnées des clients (récupérés par HttpClient, adresses privées interdites) et inscription dynamique | Clients déclarés à la main | Ce que demande la spécification MCP de 2026 ; aucune étape manuelle pour l'utilisateur |

## Interface

| Besoin | Retenu | Alternatives | Pourquoi ce choix |
|---|---|---|---|
| Code du navigateur | HTML, CSS et JavaScript sans framework, amélioration progressive | React, Vue, Svelte, htmx, Stimulus et Turbo | Chaque écran fonctionne sans JavaScript ; pas de build ; compatible avec une CSP stricte |
| Assets | AssetMapper de Symfony, **sans importmap** | Webpack Encore, Vite, importmap | Fichiers versionnés par empreinte, sans Node ni build ; l'importmap impose un script en ligne, refusé par la CSP |
| Graphiques | Chart.js 4.6.1 copié dans `assets/vendor/`, empreinte vérifiée | Apache ECharts, D3, uPlot, SVG dessiné côté serveur | Bibliothèque légère et maintenue, déjà utilisée par la v1 (3.x, plus maintenue) ; aucun CDN ; la pyramide reste un SVG produit côté serveur |
| Thème clair et sombre | Variables CSS, thème écrit par le serveur dans `data-theme` | Bascule en JavaScript, deux feuilles de style | Aucun flash au chargement, aucun script en ligne ; bouton qui reste un formulaire sans JavaScript |
| Langue et formats | Français seulement, montants au format français | Traductions (XLIFF) | Public francophone ; une seule langue évite toute une couche de traduction |

## Qualité et tests

| Besoin | Retenu | Alternatives | Pourquoi ce choix |
|---|---|---|---|
| Tests | PHPUnit 12 : unitaires, intégration, fonctionnels, non-régression, architecture ; base de test annulée après chaque test (dama/doctrine-test-bundle) ; horloge figée | Pest, Codeception | Standard de PHP, intégré à Symfony ; tests rapides et déterministes |
| Non-régression | Résultats de la v1 figés comme référence | Tests écrits à nouveau | Mêmes chiffres que la v1, prouvés à chaque exécution |
| Analyse statique | PHPStan niveau max avec règles strictes, plus une règle du projet (SQL hors des dépôts) ; Psalm 7 pour l'analyse de propagation (injections) | Un seul des deux, Phan | PHPStan pour les types ; Psalm pour suivre les données non fiables jusqu'au SQL et au HTML |
| Style | PHP-CS-Fixer, PER Coding Style 3.0 | PHP_CodeSniffer, style Symfony | Norme du PHP-FIG la plus récente ; correction automatique |
| Parcours de bout en bout | Playwright 1.65 (authentificateur WebAuthn virtuel), Mailpit pour les e-mails | Symfony Panther, Cypress, Selenium | Navigateurs réels, authentificateur virtuel avec PRF ; Node limité aux outils de test |
| Charge | k6 (20 utilisateurs, 10 ans d'historique) | JMeter, Gatling, Locust | Scénarios en JavaScript, seuils p95 bloquants, binaire unique |
| HTML et CSS | Nu Html Checker (vnu) sur les pages rendues par les tests | Validation à la main | Validité W3C de tous les écrans, à chaque pipeline |
| Sécurité | `composer audit`, `npm audit`, Gitleaks, Semgrep, Trivy, modèles SAST et Secret Detection de GitLab, ZAP authentifié ; barrière avec exclusions justifiées et datées | SonarQube, Snyk, Dependabot | Outils libres qui tournent sur le runner, sans service externe ; aucune alerte grave ne passe sans justification écrite |
| Couverture | pcov, seuils par dossier | Xdebug | Plus rapide ; seuils plus hauts pour le domaine, la sécurité et le chiffrement |

## Intégration continue et livraison

| Besoin | Retenu | Alternatives | Pourquoi ce choix |
|---|---|---|---|
| CI | GitLab CI sur le runner de l'utilisateur (Docker) | GitHub Actions, Jenkins, Woodpecker | Dépôt déjà sur le GitLab de l'utilisateur ; aucune donnée ne sort |
| Pipelines | Rapide sur les branches et MR (environ 15 min) ; complet sur `main`, les étiquettes et à la demande | Tout à chaque push | Le runner est partagé et son disque est lent : le complet (environ 70 min) est réservé à ce qui est livré |
| Images | Debian minimale (miroir public.ecr.aws), outils sur ghcr.io, épinglées par empreinte | Docker Hub, étiquettes flottantes | Pas de limite de téléchargement de Docker Hub ; une image compromise ne peut pas se substituer (cas de Trivy en mars 2026) |
| Documentation testée | Le job `deployment` exécute `installation.md` et `backup.md` sur un Debian neuf | Documentation relue à la main | La procédure d'installation, de sauvegarde, de restauration et de mise à jour ne peut pas se périmer sans que la CI échoue |
| Livraison | Archive construite par la CI (job `release`), versions étiquetées et Releases GitLab | `git pull` et Composer sur le serveur | Serveur sans Composer ni accès à Packagist |
| Versions | Versionnage sémantique, CHANGELOG au format Keep a Changelog, messages Conventional Commits | Numéros libres | Chaque version annonce ses étapes manuelles ; historique lisible |

## Exploitation

| Besoin | Retenu | Alternatives | Pourquoi ce choix |
|---|---|---|---|
| Sauvegarde | API de sauvegarde de SQLite (`sqlite3 .backup`), contrôle d'intégrité, gzip et empreinte SHA-256, 30 archives, minuteur systemd | Copie du fichier (`cp`), Litestream, restic ou Borg | Copie cohérente à chaud, sans service en plus ; rotation au nombre : si les sauvegardes s'arrêtent, les dernières restent ([backup.md](backup.md)) |
| Copie hors du serveur | Sauvegarde du serveur entier, ou copie tirée par une autre machine (`rsync`) | Copie poussée par le serveur | Un serveur compromis ne peut pas effacer des copies qu'il ne fait que laisser lire |
| Restauration | Script qui vérifie l'archive (intégrité, clés étrangères, version), garde la base remplacée et refuse de tourner sous php-fpm ou les tâches planifiées actifs | Remplacement du fichier à la main | Pas de base corrompue par un journal WAL rejoué ou par un processus encore ouvert |
| Mise à jour | Procédure documentée et testée : sauvegarde, service arrêté, cache supprimé, migrations, redémarrage ; retour arrière décrit | Mise à jour à chaud | Ni code modifié sous des requêtes, ni cache d'une version précédente |
