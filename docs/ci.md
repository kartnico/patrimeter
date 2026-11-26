# Intégration continue

Le pipeline GitLab ([`.gitlab-ci.yml`](../.gitlab-ci.yml)) s'exécute à chaque push, sur toutes les
branches, et sur chaque merge request, sans pipeline en double. Un nouveau push annule le pipeline
en cours de la même branche. **Tous les jobs sont bloquants**, sauf `phpunit-next`, sur la prochaine
version de PHP (8.6), qui prévient sans bloquer.

| Pipeline | Quand | Jobs | Durée |
|---|---|---|---|
| Rapide | branches et merge requests | `ci-php-image`, `composer`, `php-lint`, `coding-style`, `symfony-lint`, `phpstan`, `psalm`, `phpunit`, `coverage`, `w3c`, `documentation`, `gitleaks` | 15 min environ |
| Complet | `main`, `stable` ou `FULL_PIPELINE=1` | tous, plus `captures`, `release` et `container-image` | 70 min environ |
| Étiquette | étiquette `vX.Y.Z` | `ci-php-image`, `composer`, l'image et son smoke test, les analyses de sécurité, la barrière, le rapport, `release` et `container-publish` | 30 min environ |
| Sécurité | planification quotidienne (`SECURITY_SCAN=1`) | analyses de sécurité, barrière et rapport | voir plus bas |

Le pipeline complet ajoute les parcours de bout en bout, le déploiement réel, les analyses de sécurité, la
charge et l'archive de livraison. Pour l'obtenir sur une branche avant de fusionner (changement de
déploiement, de nginx, des parcours) : *Build > Pipelines > Run pipeline*, branche, variable
`FULL_PIPELINE` = `1`. Configurer séparément les planifications de sécurité et de Renovate (*Build > Pipeline
schedules*) ; une planification sans `SECURITY_SCAN` lancerait le pipeline complet.

## Environnement des jobs

- **Versions des jobs** : Debian 13 (la version recommandée, comme l'image de conteneur et le job `deployment`),
  Node 24 (LTS) pour les outils JavaScript, Python 3.14 pour la documentation et Semgrep. Toutes les versions
  épinglées (images, outils, scripts) se mettent à jour avec un recul d'au moins deux semaines sur leur publication.
- **PHP vient du dépôt d'Ondřej Surý**, installé sur l'image Debian officielle par
  [`ci/install-php.sh`](../ci/install-php.sh), exactement comme en production. Les jobs PHP partent d'une
  **image CI prête à l'emploi** ([`ci/php/Dockerfile`](../ci/php/Dockerfile)) : PHP 8.3, 8.4 et 8.5, pcov, Composer,
  déjà installés par ces mêmes scripts. Le job `ci-php-image` la construit avec Buildah et la pousse dans le registre
  du projet (`ci-php:<empreinte>`) seulement si elle n'y est pas encore : son étiquette est l'empreinte du Dockerfile,
  des deux scripts, de la clé et de l'image Debian (`CI_PHP_IMAGE`, vérifiée par `CiPhpImageTest`), donc toute
  modification de l'un d'eux produit une nouvelle image. Le jour (`SECURITY_SCAN=1`), elle est reconstruite pour
  prendre les correctifs de Debian et de Surý. Dans un job, `install-php.sh` ne fait alors que choisir la version
  demandée (`update-alternatives`). La clé du dépôt
  est versionnée dans `ci/` (empreinte `1505 8500 A023 5D97 F5D1 0063 B188 E2B6 95BD 4743`,
  valable jusqu'au 04/02/2028 : la remplacer par la nouvelle clé publiée par Surý avant cette date).
  Les jobs qui installent encore PHP eux-mêmes gardent les paquets téléchargés en cache GitLab (`.cache/apt`, une
  clé par version de PHP et de Debian).
- **Composer** : phar officiel en version figée, empreinte SHA-256 vérifiée
  ([`ci/install-composer.sh`](../ci/install-composer.sh)). Le job `composer` installe les
  dépendances une seule fois et les transmet aux autres jobs (artefact `vendor/`, conservé 3 h).
- **Images** épinglées par empreinte (`@sha256:…`) et prises hors de Docker Hub pour éviter ses
  limites de téléchargement : miroir AWS des images officielles (`public.ecr.aws/docker/library`)
  et `ghcr.io`. Mettre à jour une image = changer son empreinte dans les variables du pipeline.
- **Base de données** : fichier SQLite temporaire créé par les migrations au début de chaque job de
  tests ; aucun service de base n'est nécessaire.

## Jobs

| Stage | Job | Rôle |
|---|---|---|
| prepare | `ci-php-image` | image CI des jobs PHP ([ci/php/Dockerfile](../ci/php/Dockerfile)), construite et poussée seulement si son étiquette manque au registre, ou le jour ; quelques secondes sinon |
| prepare | `composer` | contrôle de la place sur le runner, `composer validate --strict`, installation des dépendances |
| prepare | `npm` | outils Node : ESLint, Prettier, Stylelint, Vitest, Playwright et axe-core (jamais en production) |
| lint | `php-lint` | syntaxe de tous les fichiers PHP sur chaque version prise en charge : PHP 8.3, 8.4 et 8.5 |
| lint | `coding-style` | PHP-CS-Fixer : PER Coding Style 3.0 et règles de migration jusqu'à PHP 8.3 ; rapport Code Quality |
| lint | `symfony-lint` | gabarits Twig, fichiers YAML (configuration, traductions, `docs/openapi.yaml`), cohérence du conteneur d'injection, dépréciations signalées à sa compilation (`tools/container-deprecations.sh`) ; migrations sur une base neuve : toutes jouées et connues (`doctrine:migrations:up-to-date`) ; leur retour arrière est testé par `MigrationTest` |
| lint | `js-css-lint` | ESLint (`eslint.config.mjs`, configuration « flat », règles recommandées), Prettier (`.prettierrc.json`), Stylelint (`.stylelintrc.json`, configuration standard adaptée au style compact du projet), markdownlint (`.markdownlint-cli2.jsonc` : Markdown du dépôt et du site de documentation, voir [Documentation d'utilisation](#documentation-dutilisation)) |
| lint | `hadolint` | `docker/Dockerfile` et `ci/php/Dockerfile`, au seuil le plus strict (`.hadolint.yaml`) : toute remarque fait échouer ; étiquettes OCI exigées |
| lint | `compose-config` | `docker/compose.yaml` résolu par `docker compose config` (binaire officiel épinglé, empreinte vérifiée), sans démon |
| static-analysis | `phpstan` | PHPStan niveau max + règles strictes, pour PHP 8.3 à 8.5, plus la règle du projet interdisant le SQL hors des dépôts ; rapport Code Quality |
| static-analysis | `psalm` | Psalm niveau 1 avec analyse de taint (injections SQL, XSS…) ; rapport SARIF |
| static-analysis | `container-image` | image de conteneur ([docker.md](docker.md)) construite par Buildah sans démon ni mode privilégié, contrôlée à sa construction ; poussée dans le registre du projet sous `ci-<pipeline>` pour les jobs suivants (ou sous le commit avec `REGISTRY_PUSH=1`) ; paquets de l'image de base mis à jour (`apt-get upgrade`) ; archive `image/patrimeter-<version>.tar.gz` et son empreinte, gardée deux semaines. Pipeline complet, ou branche qui modifie l'image |
| test | `phpunit` | toutes les suites sur PHP 8.3 et 8.5 ; rapport JUnit |
| test | `coverage` | toutes les suites sur PHP 8.4 avec pcov : rapports JUnit et Cobertura, seuils de couverture, captures HTML pour le W3C |
| test | `sonarqube` | analyse [SonarQube](#sonarqube) (bugs, vulnérabilités, mauvaises pratiques, duplication, couverture PHP du job `coverage`) ; échoue si la barrière de qualité du serveur n'est pas franchie. Sur `main` et dans les pipelines complets |
| test | `w3c` | Nu Html Checker sur les pages rendues (connexion, erreurs, tous les écrans) et sur le CSS |
| test | `vitest` | tests unitaires du JavaScript du navigateur (`tests/js`) dans un DOM simulé (jsdom) ; rapport JUnit |
| test | `infection` | tests de mutation de `src/Domain` et `src/Infrastructure/Crypto` (`infection.json5`) : MSI d'au moins 92 % ; rapport Code Quality |
| test | `container-smoke` | l'image démarre vraiment (service GitLab, point d'entrée `web`) : page de connexion et en-têtes de sécurité, supervision, assets servis, fichiers internes refusés (`ci/container-smoke.sh`) |
| test | `phpunit-next` | toutes les suites sur la prochaine version de PHP ; échec autorisé |
| e2e | `playwright` | parcours dans Chromium, application en mode production, Mailpit, authentificateur WebAuthn virtuel avec PRF ; accessibilité de toutes les pages avec axe-core (WCAG 2.2 A et AA, thèmes clair et sombre) |
| e2e | `deployment` | installation réelle sur Debian, dans sa version recommandée, selon la documentation : nginx et php-fpm du dépôt, proxy inverse et TLS, sauvegarde, restauration, mise à jour |
| security | `composer-audit` | vulnérabilités connues des dépendances (`composer audit`) |
| security | `gitleaks` | secrets dans tout l'historique git ; faux positifs vérifiés dans `.gitleaksignore` |
| security | `npm-audit` | vulnérabilités des outils Node |
| security | `semgrep` | règles PHP, OWASP Top 10 et audit de sécurité |
| security | `trivy` | CVE et licences des dépendances Composer et npm |
| security | `trivy-image` | CVE corrigées de l'image construite (paquets Debian et PHP compris), transmises à la barrière |
| security | `semgrep-sast`, `secret_detection` | modèles GitLab SAST et Secret Detection |
| security | `zap` | ZAP baseline sur une session authentifiée (analyse passive, formulaires non soumis) |
| security | `nuclei` | Nuclei ([`ci/install-nuclei.sh`](../ci/install-nuclei.sh), version et empreinte figées) sur l'application lancée dans le job : modèles HTTP du jour (CVE connues, mauvaises configurations, fichiers exposés), gravité « low » et plus, sans les modèles intrusifs (déni de service, fuzzing, OSINT), débit limité ; rapport `reports/nuclei.jsonl` transmis à la barrière |
| security | `nuclei-production` | le même scan sur l'instance déployée (`PRODUCTION_URL`), sans compte et à 10 requêtes par seconde : le jour et à la demande (`PRODUCTION_SCAN=1`) ; une trouvaille High ou Critical fait échouer le job, donc l'e-mail du pipeline ; jamais dans un pipeline de `main` ni d'étiquette, il ne bloque aucune livraison |
| performance | `k6` | charge, coût d'Argon2id, en dernier et seul sur le runner (attend tous les jobs des étapes test, e2e et security ; Pages publie sur tag uniquement : `PerformancePipelineTest`) ; résultats conservés 90 jours |
| report | `security-gate` | bloque sur toute vulnérabilité High ou Critical non exclue |
| report | `pipeline-report` | synthèse en artefact : `reports/pipeline-report.md` |
| prepare | `renovate` | chaque jour : merge requests de mise à jour des dépendances ([Renovate](#renovate)) |
| release | `container-publish` | publication de l'image une fois le smoke test et la barrière de sécurité passés : sous `vX.Y.Z`, `latest` et `vX.Y.Z-AAAAMMJJ-p<ID>` pour une étiquette de version, et chaque jour pour la dernière version reconstruite ([pipeline du jour](#controle-quotidien)) ; au moindre échec, rien n'est publié |
| release | `release` | archive de livraison `release/patrimeter-<commit>.tar.gz` et son empreinte, vérifiée au démarrage ([`ci/build-release.sh`](../ci/build-release.sh)) ; conservée 90 jours |

Toute dépréciation, notice ou avertissement PHP fait échouer PHPUnit.

Versions de PHP (vérifié par `PhpVersionsCiTest`) : la syntaxe est contrôlée et toutes les suites tournent sur **8.3**,
la version minimale (`composer.json` : `php >=8.3`,
plateforme 8.3.27), sur **8.4** (job `coverage`, version des outils) et sur **8.5**, la version recommandée (celle de
l'image de conteneur) ; la suivante (`phpunit-next`) est essayée sans bloquer.

## Seuils de couverture (lignes)

| Périmètre | Seuil |
|---|---|
| Tout `src/` | 80 % |
| `src/Domain/` (calculs) | 95 % |
| `src/Security/` (authentification) | 95 % |
| `src/Infrastructure/Crypto/` (chiffrement) | 95 % |

Les seuils sont dans la variable `COVERAGE_THRESHOLDS` et contrôlés par
[`tools/coverage-check.php`](../tools/coverage-check.php). Le taux global s'affiche dans GitLab
(colonne « Coverage ») et les lignes couvertes dans le diff des merge requests.

## Rapports dans GitLab

- **Tests** : onglet « Tests » du pipeline et widget de merge request (JUnit).
- **Couverture** : visualisation dans le diff des merge requests (Cobertura).
- **Code Quality** : PHP-CS-Fixer et PHPStan.
- **Sécurité** : rapports SARIF de Psalm et Gitleaks en artefacts (le widget sécurité des merge
  requests est réservé à GitLab Ultimate).

## Lancer les vérifications en local

Prérequis : PHP 8.3+ du dépôt Surý (`cli`, `sqlite3`, `xml`, `mbstring`, `intl`, `pcov`),
Composer, `make` ; Java 17+ et `vnu.jar` pour la validation W3C.

| Commande | Équivalent CI |
|---|---|
| `make lint` | `php-lint`, `symfony-lint`, `composer validate` |
| `make cs` (`make cs-fix` pour corriger) | `coding-style` |
| `make stan` | `phpstan` |
| `make psalm` | `psalm` |
| `make test` (ou `make test-unit`, `test-integration`, `test-functional`, `test-regression`, `test-architecture`) | `phpunit` |
| `make coverage` | `coverage` |
| `make w3c VNU="java -jar /chemin/vnu.jar"` | `w3c` |
| `make audit` | `composer-audit` |
| `make ci` | tout ce qui précède |

## Prérequis côté runner

- Exécuteur Docker avec accès Internet sortant (paquets Debian et Surý, Packagist, images).
- Au moins 4 jobs en parallèle (`concurrent` dans la configuration du runner) : le pipeline compte
  jusqu'à 6 jobs simultanés par stage.
- **Disque : 30 Go environ** (ordre de grandeur) pour les images, dont ZAP et les analyseurs GitLab,
  et les dossiers de travail de chaque job parallèle. Avec 8 Go, le pipeline complet a saturé le
  disque : images impossibles à télécharger, artefacts tronqués, délais d'une heure dépassés.
- Le runner ne purge pas ses images : prévoir une purge périodique des anciennes images
  (`docker image prune`). Le job `composer` arrête le pipeline s'il reste moins de
  `RUNNER_MIN_FREE_GB` Go libres (5 par défaut).

## Parcours de bout en bout

`tests/e2e/*.spec.mjs`, à la largeur mobile de référence (390 px). Tout parcours échoue sur une
violation de CSP ou une erreur JavaScript.

- Inscription, confirmation de l'adresse, TOTP, clé de récupération, reconnexion.
- Connexion par mot de passe et TOTP, refus d'un mauvais mot de passe.
- Mot de passe oublié avec la clé de récupération.
- Passkey avec PRF, passkey sans PRF, clé FIDO2 en second facteur.
- Client MCP : découverte, connexion et consentement dans le navigateur, échange PKCE, appel d'un outil.
- Thème, avec et sans JavaScript ; saisie mensuelle ; bien immobilier avec ses crédits.

[`ci/start-app.sh`](../ci/start-app.sh) lance l'application en mode production sur `php -S` : base
neuve, clés jetables, assets compilés, utilisateurs `e2e-NN@example.test` créés par `tests/e2e/seed.php`
avec le vrai code de chiffrement. Ces mêmes conditions servent à ZAP et à k6. nginx et php-fpm sont
vérifiés à part, par le job `deployment`.

## Déploiement

Le job `deployment` ([`ci/check-deployment.sh`](../ci/check-deployment.sh)) part d'un Debian neuf (version recommandée),
y installe les prérequis (PHP du dépôt Surý, nginx, sqlite3), construit l'archive de livraison
([`ci/build-release.sh`](../ci/build-release.sh)) et la dépose dans `/tmp`, puis **exécute la documentation** : les
blocs de [installation.md](installation.md), [backup.md](backup.md) et [mise-a-jour.md](mise-a-jour.md) précédés d'un
commentaire `<!-- ci:… -->`. Une commande documentée qui ne fonctionne plus fait donc échouer le pipeline. Seul
`systemctl` est remplacé ([`ci/fake-systemctl.sh`](../ci/fake-systemctl.sh) : pas de systemd dans un conteneur).

Sont vérifiés, avec les fichiers de `docs/` tels quels :

- **Syntaxe** : `nginx -t`, `php-fpm -t`, réglages du pool effectivement chargés (`php-fpm -tt`), unités
  systemd si `systemd-analyze` est présent.
- **Droits** : `.env.local`, `var/`, socket, archives ; `www-data` ne peut écrire ni le code, ni la
  configuration ; dépendances de développement absentes.
- **Comportement** ([`tests/deployment/smoke.php`](../tests/deployment/smoke.php), vu du client) :
  en-têtes, cookie `__Host-` avec `Secure`, HSTS, cache et type des assets, fichiers et scripts
  interdits (`.env`, `.git`, base, `*.php`, traversée de chemins), limite de 64 Ko, page d'erreur sans détail.
- **Pile complète sous les restrictions du pool** (`open_basedir`, `disable_functions`,
  `allow_url_fopen=off`) : première connexion, TOTP, clé de récupération, saisie, inscription avec e-mail
  (Mailpit), client MCP
  complet (découverte, inscription, consentement, jeton, outils) et API REST avec un jeton personnel.
- **Deux variantes de vhost** : derrière un proxy inverse ([`reverse-proxy.conf`](../tests/deployment/reverse-proxy.conf))
  pour les parcours, TLS sur le serveur pour les contrôles HTTP.
- **Sauvegarde et restauration** : archive sans donnée en clair, refus de restaurer sous php-fpm ou tâches
  planifiées actifs,
  montant modifié après la sauvegarde qui revient à sa valeur, données déchiffrées après reconnexion.
- **Tâches planifiées et alertes** ([§ 7](installation.md#7-services)) : unités valides, tâche
  horaire déclarée, e-mail d'alerte reçu.
- **Mise à jour et retour arrière** ([mise-a-jour.md](mise-a-jour.md#2-sur-linux)) : `patrimeter-update` vers une
  seconde archive (sauvegarde faite, version précédente gardée, données intactes), puis `patrimeter-update --rollback`
  (version et base d'avant) ; journaux sans mot de passe et sans erreur applicative.

Ne couvre pas : l'accès refusé depuis une adresse publique (`deny all`, un conteneur n'a pas d'autre
source que le réseau privé), le STARTTLS du SMTP, `systemd` lui-même, et votre véritable proxy inverse. Les
passkeys sont testées par `playwright`, sur `php -S`.

Le script ne tourne qu'en CI (`CI=true`) : il modifie le système. Il n'a pas de cible `make`.

## Barrière de sécurité

`npm-audit`, `semgrep`, `trivy`, `zap` et les modèles GitLab produisent un rapport sans bloquer.
[`tools/security-gate.php`](../tools/security-gate.php) les réunit et bloque sur tout résultat High
ou Critical. Un rapport absent ou illisible bloque aussi : un outil en panne ne vaut pas un résultat
propre.

Une exclusion se versionne dans [`security/exclusions.json`](../security/exclusions.json), avec
`tool`, `id`, `path` facultatif, `reason` et `expires` (AAAA-MM-JJ). Sans motif ou passée sa date,
elle ne s'applique pas.

## Charge

`tests/perf/load.js` : 20 utilisateurs simultanés, chacun avec 10 ans d'historique, montée en charge
sur 60 s puis 90 s de régime. Seuils : erreurs < 1 %, p95 < 800 ms pour les pages, < 1 s pour la saisie,
< 2 s pour la connexion. Les requêtes N+1 sont détectées par `tests/Functional/QueryCountTest`.

Ce job surveille le code, pas la capacité de la production : il tourne sur `php -S` avec 8 processus,
alors que `docs/php-fpm/patrimeter.conf` en prévoit 4, avec OPcache comme en production. Le disque du runner est
lent et bloque par moments : une page lente y traduisait d'abord une attente du disque (p95 de 0,85 à 1,5 s le
24/11/2026 pour une médiane de 25 ms, sans aucune différence de code avec une mesure verte). Le job place donc
`var/` (base, sessions, cache) en mémoire (`/dev/shm`) : il mesure l'application, pas le disque.

## En local

```sh
make e2e    # Mailpit doit écouter ; SMTP_PORT et MAILPIT_API si les ports diffèrent
make dast   # zap-baseline.py dans le PATH
make perf   # k6 dans le PATH
```

Variables utiles : `PHP`, `CHROMIUM_PATH` (Chromium du système plutôt que celui de Playwright).

## Branches et étiquettes

- **`main`** est la seule branche durable ; elle est protégée (fusion par merge request seulement).
- **Une branche par sujet**, nommée comme le commit qui la résume (`feat/…`, `fix/…`, `docs/…`, `chore/…`),
  partie de `main` à jour et **supprimée à la fusion** : c'est le réglage par défaut du projet (*Settings >
  Merge requests > Delete source branch*). Une branche abandonnée est supprimée plutôt que laissée en place.
- **Étiquettes** : uniquement les versions livrées, `vX.Y.Z`, annotées, posées sur `main` (ci-dessous) ; jamais
  déplacées ni supprimées une fois publiées.
- En local, `git fetch --prune` retire les branches distantes disparues ; `git branch --merged main` liste
  celles qui peuvent être supprimées (`git branch -d`).

## Publier une version

Pas à pas, avec les commandes et les appels à l'API de GitLab : [developpement.md](developpement.md#5-publier-une-version).
Chaque version livrée porte une **étiquette git annotée** `vX.Y.Z` (versionnage sémantique), posée sur le
commit de `main` qui la contient :

1. Dans le CHANGELOG, les changements s'écrivent sous la version qu'ils préparent, `## [X.Y.Z]`, sans date tant
   qu'elle n'est pas publiée ; le jour de la publication, elle devient `## [X.Y.Z] - AAAA-MM-JJ`, avec son lien de
   comparaison en bas de page. De même dans `CHANGELOG.en.md`. Le fichier
   `VERSION` passe à `X.Y.Z` : c'est la version affichée au pied de chaque page (`VersionTest` vérifie que les trois
   concordent).
2. Merge request, puis fusion dans `main` une fois le pipeline vert.
3. Étiquette sur le commit de fusion, poussée sur GitLab :

   ```sh
   git switch main && git pull --ff-only
   git tag -a vX.Y.Z -m "Patrimeter X.Y.Z"
   git push origin vX.Y.Z
   ```

4. Release GitLab (*Deploy > Releases*) sur cette étiquette : notes reprises de la section du CHANGELOG (en
   français, puis en anglais), lien vers l'archive du job `release`
   (`https://gitlab.example.org/kartzone/patrimeter/-/jobs/artifacts/vX.Y.Z/download?job=release`).
   L'archive d'une version est à conserver sans limite (*Keep* sur le job), sinon elle expire au bout de 90 jours.

L'étiquette déclenche un **pipeline réduit** : le commit vient d'être vérifié par le pipeline complet de `main`, donc
lint, analyses statiques, tests, documentation, parcours de bout en bout et charge (k6) ne sont pas rejoués (règle
`.not-tag` de `.gitlab-ci.yml`, vérifiée par `TagPipelineTest`). Restent les jobs qui produisent ou protègent la
livraison : `composer`, l'image et son smoke test, les analyses de sécurité et leur barrière, le rapport, l'archive
`patrimeter-vX.Y.Z` (`release`) et la publication de l'image (`container-publish`).

## Pipeline du lundi

Cette ancienne planification devient quotidienne.

### Contrôle quotidien

Une planification GitLab (*Build > Pipeline schedules*) lance chaque jour à 6 h, heure de Paris, un
pipeline sur `main` avec la variable `SECURITY_SCAN=1`. Un échec est envoyé par e-mail au propriétaire de la
planification (notifications de GitLab). Les autres jobs s'en excluent par la règle `.not-security-scan` de
`.gitlab-ci.yml` ; une planification sans cette variable lancerait le pipeline complet. Deux tâches (les dépendances,
elles, sont suivies chaque jour par [Renovate](#renovate)) :

1. **Analyses de sécurité** : `composer audit`, `npm audit`, Trivy, Semgrep, Gitleaks, modèles SAST et Secret
   Detection de GitLab, ZAP, Nuclei, puis la barrière de sécurité et le rapport ; Nuclei analyse aussi l'instance
   déployée (`nuclei-production`, `PRODUCTION_URL`). Une faille publiée dans une dépendance est
   ainsi signalée même sans nouveau code. **OpenSSF Scorecard** y note aussi le projet, comme dans chaque pipeline de
   `main` ([ci-dessous](#openssf-scorecard)).
2. **Image republiée** : `container-image` reconstruit la **dernière version publiée** (la plus récente étiquette
   `vX.Y.Z`, pas `main`) avec les paquets Debian et PHP du jour ; `container-smoke` la démarre, `trivy-image`
   l'analyse et la barrière de sécurité tranche. Si tout passe, `container-publish` la republie sous `vX.Y.Z` et
   `latest`, et sous `vX.Y.Z-AAAAMMJJ-p<ID>` (identifiant du pipeline). Un échec des contrôles conserve
   l’image publique précédente.

## Activer les publications publiques

Le GitLab et son registre de travail restent privés. Aucun push de branche ordinaire ni pipeline Renovate ne publie.
Avant activation, auditer **tout le contenu du tag**, y compris les fichiers cachés, exemples, hôtes, licences,
images et documents techniques. Le code public contient les fichiers suivis du tag (hors workflows GitHub), pas les
parents Git privés. Les copies destinées au conteneur, au code public et à Pages remplacent le domaine de
l’infrastructure privée par `example.org`. GitHub Issues reçoit les nouveaux tickets ; aucun ticket privé n’est
synchronisé.

1. Utiliser le [dépôt GitHub public](https://github.com/kartnico/patrimeter). Activer Issues et les signalements
   privés (*Private vulnerability reporting*). Ne pas activer Renovate sur cette copie.
   Créer un jeton finement limité à ce dépôt (`Contents: Read and write`). Autoriser ce compte à mettre à jour `main`,
   `gh-pages` et les tags publics ; réserver ces références au robot. Aucun workflow GitHub à écrire.
2. Créer le dépôt **public Docker Hub** et un jeton permettant de pousser les images, sans droit de suppression.
3. Dans GitLab, protéger `main`, `stable` et les tags `v*`. Restreindre les créations de tags et les déclenchements de
   pipelines aux responsables autorisés. Conserver le registre GitLab privé pour les images intermédiaires.
4. Dans *Settings > CI/CD > Variables*, déclarer les coordonnées et les options ci-dessous comme variables protégées
   (portée `*`). Les jetons sont **masqués, protégés**, sans expansion, avec la portée d’environnement indiquée.

| Variable | Valeur / portée |
|---|---|
| `GITHUB_REPOSITORY` | `kartnico/patrimeter` sur github.com (site Pages de projet, sans domaine personnalisé) |
| `DOCKERHUB_REPOSITORY` | `namespace/dépôt` sur Docker Hub, sans hôte ni tag |
| `PUBLIC_FIRST_VERSION` | premier tag relu pour publication, par exemple `vX.Y.Z` ; aucune ancienne image ne doit sortir |
| `PUBLIC_RELEASE_ENABLED` | `1` après revue du premier tag ; absent ou `0` désactive les publications publiques |
| `DOCKERHUB_USERNAME`, `DOCKERHUB_TOKEN` | compte de publication et jeton, environnement `public/dockerhub` |
| `GITHUB_PUBLISH_TOKEN` | jeton du seul dépôt public, environnement `public/github` |
| `STABLE_RELEASE_ENABLED` | `1` après initialisation de `stable` pour activer Renovate dessus et les tags correctifs |
| `STABLE_RELEASE_TOKEN` | jeton **de projet GitLab** `write_repository`, environnement `private/stable` ; robot Maintainer autorisé à fusionner une MR vers `stable` et à créer les tags protégés `v*`, sans push direct sur `stable` |

Créer la première version **contenant cette CI** selon la procédure de publication. Après la première publication,
choisir sur GitHub *Settings > Pages > Deploy from a branch > gh-pages > / (root)*. Vérifier l’accès anonyme à la
page d’accueil, aux deux langues, au code et à l’image. Les deux plateformes ne forment pas une transaction : si
GitHub échoue après Docker Hub, relancer uniquement le job GitHub du même tag. Les archives GitLab restent privées.

Créer `stable` sur ce tag, **sans commits de développement**. Une fusion de dépendances déclenche le pipeline complet.
Le job `stable-patch` compare les audits Composer de production du dernier tag et du candidat. Il ne prépare une
version que si des avis existaient, qu’il n’en reste aucun et que seules les dépendances verrouillées et leurs
mentions de licence ont changé. Le robot écrit `VERSION` et les deux CHANGELOG sur `release/security-vX.Y.Z` et
ouvre une MR vers `stable` avec fusion automatique après les contrôles. Garder « push direct : personne » sur `stable`
et exiger un pipeline réussi avant fusion. Une approbation obligatoire reste à donner ; si la MR est en conflit ou
bloquée, la traiter sur GitLab. Après fusion, le pipeline complet de `stable` vérifie le résultat puis crée le tag
annoté. Le push par jeton de projet (pas `CI_JOB_TOKEN`) déclenche le pipeline du tag. Une erreur d’audit bloque
l’opération. Un correctif nécessitant de modifier les contraintes Composer doit être livré manuellement.
Après un correctif, reporter les changements dans `main` par MR ; à chaque nouvelle version fonctionnelle, réaligner
`stable` sur le nouveau tag livré par avance rapide ou MR contrôlée, jamais sur une `main` non publiée.

Planifier sur `main` **deux** pipelines distincts : `RENOVATE=1` à 3 h et `SECURITY_SCAN=1` à 6 h (`0 6 * * *`,
Europe/Paris). Cette dernière planification exécute aussi les analyses auparavant hebdomadaires. Le déploiement sur
les installations n’est pas déclenché par GitLab : voir [Docker](docker.md#4-mettre-a-jour).

## Renovate

[Renovate](https://docs.renovatebot.com/) (job `renovate`, image épinglée par empreinte, configuration
[`renovate.json`](../renovate.json)) ouvre les merge requests de mise à jour des dépendances. Une planification
GitLab le lance **chaque jour** à 3 h, heure de Paris (fini avant le pipeline de sécurité du jour, à 6 h), sur
`main` avec la variable `RENOVATE=1` : seul ce job tourne dans ce pipeline, les autres s'en excluent par la règle
`.not-renovate` (vérifié par `RenovatePipelineTest`). Renovate ouvre les MR automatiquement ; par défaut, une personne
les relit et les fusionne. Seuls les correctifs de Prettier, Stylelint et markdownlint-cli2 sont fusionnés
sans intervention après un pipeline vert. Il suit :

| Quoi | Où | Comment |
|---|---|---|
| Bibliothèques PHP et leurs dépendances | `composer.json`, `composer.lock` | dans les contraintes de `composer.json` (plateforme PHP 8.3, Symfony 7.4 LTS) : le fichier de verrouillage suit ; tout le fichier de verrouillage est rafraîchi chaque jour (dépendances indirectes) |
| Outils Node (tests, lint) | `package.json`, `package-lock.json` | versions mineures et correctifs groupés |
| Images de la CI | variables `*_IMAGE` de `.gitlab-ci.yml` | étiquette et empreinte (`@sha256:…`) |
| Outils de la CI | Composer, k6, Nuclei, SonarScanner (`ci/install-*.sh`), OpenSSF Scorecard, Docker Compose, Semgrep, Zensical, Renovate lui-même | version ; l'empreinte SHA-256 du binaire est recalculée par [`ci/renovate-post-upgrade.sh`](../ci/renovate-post-upgrade.sh), qui recalcule aussi l'étiquette de l'image CI (`CI_PHP_IMAGE`) |
| Image du conteneur | `ARG DEBIAN_IMAGE` de `docker/Dockerfile`, avec l'image Debian de la CI | étiquette et empreinte, en un seul groupe |

Règles ([`renovate.json`](../renovate.json)) :

- versions publiées depuis **au moins 14 jours** ; les registres qui ne donnent pas la date de publication (ECR, ghcr,
  quay) n'ont pas ce délai : la relecture de la MR le remplace ;
- **une MR par groupe** (dépendances PHP, outils Node, images et outils de la CI, image Debian), au plus 4 ouvertes à
  la fois et 2 par heure ; les correctifs autorisés à la fusion automatique ont leur propre groupe. Les versions
  majeures, les rafraîchissements des fichiers de verrouillage et les changements de production ou de publication
  restent à relire et à fusionner manuellement ;
- versions retenues, chacune avec sa raison dans `renovate.json` : celles qui exigent PHP 8.4 (doctrine-bundle 3,
  endroid/qr-code 6.1, jusqu'à la 4.0.0) et celles qu'un blocage de la CI attend (Buildah, Python, Scorecard) ;
- messages de commit `build(deps): …` (Conventional Commits) ; une faille connue (base OSV) déclenche une MR sans
  attendre le groupe ni le délai de 14 jours. Pour Composer, OSV cible les dépendances directes ; les indirectes
  relèvent du rafraîchissement du verrou et de l’audit Composer ;
- l'image de l'application elle-même et les paquets Debian et PHP de l'image (dépôt Surý, non épinglés : reconstruits
  le jour) ne sont pas suivis ; Chart.js, copié dans `assets/vendor/`, reste mis à jour à la main.

Le pipeline de chaque MR refait les contrôles rapides ; le pipeline complet de `main`, après la fusion, refait tout
(image, déploiement, parcours, sécurité, charge). Une MR de Renovate ne met pas à jour le CHANGELOG : la version
suivante la mentionne.

Jeton, à créer une fois :

1. *Settings > Access tokens* du projet : jeton « Renovate », rôle **Maintainer** (seuls les Maintainers fusionnent
   dans `main`, branche protégée), portées **api** et **write_repository**, expiration d'un an au plus (à renouveler).
2. *Settings > CI/CD > Variables* : `DEPENDENCY_BOT_TOKEN` = ce jeton, **masquée** et **protégée** (la planification
   tourne sur `main`, branche protégée).
   Aucun jeton GitHub : l'image `-full` de Renovate contient déjà Node, PHP et Composer, qui mettent à jour
   `package-lock.json` et `composer.lock` sans rien télécharger depuis GitHub (dont la limite anonyme les bloquait).
   Sans jeton, les notes de version ne sont pas récupérées (`fetchChangeLogs` à `off`) ; les dépendances bloquées par
   PHP 8.3 sont retenues dans `renovate.json` (doctrine-bundle sous 3, endroid/qr-code sous 6.1).
3. *Build > Pipeline schedules* : planification « Renovate », `0 3 * * *`, fuseau Europe/Paris, branche `main`,
   variable `RENOVATE=1`. Pour le lancer à la main : *Run pipeline* sur `main` avec `RENOVATE=1`.

## OpenSSF Scorecard

[Badge interne du dernier pipeline de main](https://gitlab.example.org/kartzone/patrimeter/-/jobs/artifacts/main/raw/scorecard/badge.svg?job=scorecard).

Le job `scorecard` ([`ci/scorecard.py`](../ci/scorecard.py)) note les pratiques de sécurité du projet avec
[OpenSSF Scorecard](https://scorecard.dev/) : protection des branches, revue de code, tests en CI, outil de mise à
jour des dépendances, épinglage des dépendances, politique de sécurité, publications signées, vulnérabilités connues…
Il tourne dans **chaque pipeline de `main`**, celui du jour compris, et ne bloque jamais (`allow_failure`).
Scorecard est téléchargé depuis ses publications GitHub, à la version `SCORECARD_VERSION`, et vérifié par son empreinte
`SCORECARD_SHA256` (`.gitlab-ci.yml`).

Le dépôt étant privé, Scorecard lit GitLab avec un jeton en **lecture seule**, à créer une fois :

1. *Settings > Access tokens* du projet : jeton « OpenSSF Scorecard », rôle **Maintainer** (pour lire la protection
   des branches et les règles d'approbation), portées **read_api** et **read_repository** seulement (aucune écriture
   possible), expiration d'un an au plus (à renouveler).
2. *Settings > CI/CD > Variables* : `SCORECARD_TOKEN` = ce jeton, **masquée** et **protégée**.

Résultats dans les artefacts du job (`scorecard/scorecard.txt`, `scorecard.json`) et dans le **badge** du README :
`scorecard/badge.svg` du dernier pipeline réussi de `main`, servi par GitLab aux membres du projet. Sans
`SCORECARD_TOKEN`, le badge affiche « non mesuré ». Sur une instance GitLab auto-hébergée, certaines vérifications ne
s'appliquent pas (SAST, Dangerous-Workflow, Token-Permissions sont propres à GitHub) : elles comptent pour « - 1 » et
n'entrent pas dans la note.

## SonarQube

Le job `sonarqube` envoie le code à [SonarQube](https://www.sonarsource.com/products/sonarqube/) et attend son verdict
(`sonar.qualitygate.wait`) : il échoue si la **barrière de qualité** du serveur n'est pas franchie (« Sonar way » :
aucun nouveau problème, 80 % de couverture et au plus 3 % de duplication du nouveau code, points sensibles de sécurité
tous revus). Le nouveau code part de la version en cours (`sonar.projectVersion`, lue dans `VERSION`).

- **Configuration** : [`sonar-project.properties`](../sonar-project.properties) (code analysé, tests, exclusions).
  La couverture est celle du PHP, mesurée par le job `coverage` (`var/coverage/clover.xml`) ; le JavaScript du
  navigateur est analysé, mais couvert par les parcours Playwright, qui ne donnent pas de couverture par ligne : il
  sort du calcul, comme les gabarits, les migrations et les points d'entrée.
- **Scanner** : SonarScanner CLI ([`ci/install-sonar-scanner.sh`](../ci/install-sonar-scanner.sh)), téléchargé chez
  SonarSource, version et empreinte figées, Java fourni ; Renovate le met à jour.
- **Quand** : dans chaque pipeline de `main` et dans les pipelines complets (`FULL_PIPELINE=1`) ; sur une branche, à
  la demande, avec `SONARQUBE=1`. Jamais dans une merge request : l'édition Community ne garde qu'une branche, celle
  de `main`, qu'une analyse de merge request remplacerait.
- **Serveur et jeton**, à créer une fois : sur SonarQube, un jeton d'analyse du projet `patrimeter` ; dans *Settings >
  CI/CD > Variables* du projet GitLab, `SONAR_HOST_URL` (adresse du serveur) et `SONAR_TOKEN` (ce jeton, **masquée**).
  Le scanner lit le jeton dans l'environnement : il n'apparaît dans aucune commande ni aucun fichier.

Vérifié par `SonarQubeJobTest`.

## Documentation d'utilisation

Site Zensical bilingue publié sur GitHub Pages depuis les étiquettes protégées, après validation de l’image.

- Deux sites jumeaux aux mêmes chemins : `fr/` (`zensical.toml`, pages dans `documentation/fr/`) et `en/`
  (`zensical.en.toml`, pages dans `documentation/en/`) ; leur sélecteur de langue reste sur la même page.
- La racine (`documentation/racine/`) ouvre la langue du navigateur, comme l'application, et ses redirections
  (pages de redirection statiques) mènent les adresses d'avant la 2.7.0 au site français.
- Construction en mode strict (`documentation/build.sh`) : un lien ou une ancre cassés la font échouer.
- **Markdown vérifié** dans le pipeline rapide (job `js-css-lint`, `make js`) : markdownlint
  ([`.markdownlint-cli2.jsonc`](../.markdownlint-cli2.jsonc)) sur les fichiers suivis du dépôt, `docs/` et
  `documentation/`, réglé pour Zensical (listes imbriquées à 4 espaces, encadrés et onglets, tableaux compacts,
  lignes de 120 caractères hors tableaux et code) ; `DocumentationLinksTest` vérifie les fichiers liés et les ancres
  des pages du site avec la règle de Zensical (sans accents ni ponctuation), y compris entre `documentation/` et
  `docs/`.

| Job | Pipeline | Rôle |
|---|---|---|
| `documentation` | tous (sauf sécurité quotidienne) | construit les deux sites, pour vérifier qu'ils se construisent |
| `captures` | complet et tag public | captures d'écran de l'application réelle, dans chaque langue, données de démonstration (`playwright.captures.config.mjs`) |
| `github-publish` | tag public | publie atomiquement le code, le tag et les sites avec captures sur GitHub |

Les procédures d'exploitation (`docs/X.md` et leur traduction `docs/X.en.md`), le CHANGELOG, SECURITY.md et la
référence de l'API (générée depuis `docs/openapi.yaml` et `docs/openapi.en.yaml`) sont repris dans chaque site à la
construction (`documentation/prepare.sh`) : une seule source. Les pages techniques en français seulement (liste
`french_only`, dont celle-ci) restent dans le site français, et le site anglais y renvoie. En local : `make docs`
(Zensical installé par `pip install zensical`), sites dans `documentation/site/`.
