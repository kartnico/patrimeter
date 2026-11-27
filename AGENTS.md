# AGENTS.md — Patrimeter

Suivi de patrimoine net mensuel affiché en pyramide (modèle « Finary »). Application web
auto-hébergée (Debian ou conteneur, réseau local), licence MIT. Architecture :
[docs/architecture.md](docs/architecture.md) ;
choix techniques et alternatives : [docs/choix-techniques.md](docs/choix-techniques.md) ; installation :
[docs/installation.md](docs/installation.md) ; mise à jour : [docs/mise-a-jour.md](docs/mise-a-jour.md) ;
sauvegarde : [docs/backup.md](docs/backup.md) ; feuille de route : [ROADMAP.md](ROADMAP.md) ; flux de travail et
publication à la main : [docs/developpement.md](docs/developpement.md).

## Contraintes non négociables

- **PHP 8.3 à 8.5** : aucune syntaxe ni fonction postérieure à 8.3 (pas de property hooks ni de
  visibilité asymétrique, pas d'opérateur `|>`…), aucune dépréciation sur 8.5. PHPStan vérifie `phpVersion` 8.3 → 8.5 ;
  la CI contrôle la syntaxe et fait tourner toutes les suites sur 8.3, 8.4 et 8.5 (`PhpVersionsCiTest`).
- **Logiciels système : paquets Debian uniquement.** Sur l’hôte et dans l’image de production, installer les outils
  et bibliothèques système (y compris `age` et `age-keygen`) par APT depuis les dépôts Debian de la version utilisée
  ou son dépôt de sécurité. Pour PHP, utiliser le dépôt Surý indiqué ci-dessous. Ne compiler aucun de ces composants,
  même dans une étape de construction séparée, et ne pas télécharger de binaire amont. Si aucun paquet corrigé
  n’est disponible, signaler la vulnérabilité et attendre sa mise à jour sans masquer l’alerte.
- **PHP vient du dépôt d'Ondřej Surý sur Debian** (packages.sury.org), partout : production, CI
  (`ci/install-php.sh`) et l'image de conteneur (`docker/Dockerfile`, [docs/docker.md](docs/docker.md)). Jamais les images
  `php:*` officielles.
- **Symfony 7.4 LTS** : utiliser d'abord ce qu'il propose nativement. Toute nouvelle dépendance doit supporter
  PHP 8.3 à 8.5, être maintenue, sous licence compatible MIT, et être **signalée avant** d'être
  ajoutée. `composer.json` : `"php": ">=8.3"`, `config.platform.php` = 8.3.27.
- **Base SQLite** (fichier dans `var/`), Doctrine DBAL ; **requêtes préparées uniquement**, SQL
  confiné à `src/Infrastructure/Persistence/`.
- **Migrations** : uniquement un nouveau fichier `migrations/VersionAAAAMMJJhhmmss.php` écrit en
  **SQL explicite** (`addSql`) ; jamais l'API Schema ni la comparaison automatique (désactivée, voir
  `SqlOnlySchemaDiffProvider`). Jamais modifier une migration publiée. Prévoir `down()`.
  Attention : sous SQLite, `DROP TABLE` d'une table parente déclenche les `ON DELETE CASCADE`.
- **Frontend** : HTML/CSS/JavaScript sans framework, servis par AssetMapper **sans importmap**.
  Seule bibliothèque tierce : Chart.js (copiée dans `assets/vendor/`, empreinte vérifiée). Aucun CDN.
  Node (tests e2e, captures) et Python (Zensical, documentation) ne servent qu'aux outils, jamais en production.
- **CSP stricte** : aucun JS inline (`onclick=`, `<script>` exécutable), aucun CSS inline
  (`style=`, `<style>`). Données pour le JS : attributs `data-*` ou `<script type="application/json">`.
- **HTML et CSS valides W3C** (vérifiés en CI par vnu), HTML sémantique, `<label>` associés.
- **Pas de conseil financier** (cadre réglementaire : le conseil en investissement est réservé aux professionnels
  agréés, CIF enregistrés à l'ORIAS sous le contrôle de l'AMF) : toujours rappeler que Patrimeter est un outil de
  suivi, pas un conseil financier ni un conseil en investissement. Tout écran, e-mail, réponse de l'API ou du MCP,
  page de documentation, README ou texte de version qui présente des repères, recommandations, objectifs, scores,
  défis ou suggestions porte ce rappel (formule de l'interface : « Repères indicatifs, à adapter à votre situation :
  ce n'est pas un conseil en investissement. »). Jamais de produit, d'établissement ou d'allocation personnalisée
  présentés comme une recommandation à suivre.
- Mobile d'abord. Interface **multilingue : français (langue source) et anglais**, montants en euros au format de la
  langue (`1 234,56 €`, `€1,234.56`). Tout texte affiché passe par le traducteur (`|trans` dans Twig, `t()` ou
  `Http\Sentences` en PHP) ; clé = texte français ; traductions dans `translations/*.en.yaml` (domaines `messages`,
  `validators`, `levels`, `account_types`), complètes : `TranslationCompletenessTest`.
- Secrets uniquement dans l'environnement (`.env.local`), jamais dans le dépôt ni dans les journaux.

## Conventions

- PHP 8.3 : `#[\Override]` sur toute méthode qui en redéfinit une autre (PHPStan et Psalm l'exigent) ;
  `#[\SensitiveParameter]` sur tout paramètre secret. Configuration des services par attributs à côté du
  code (`#[Autowire]`, `#[AsAlias]`…) ; YAML seulement pour les classes tierces.
- `declare(strict_types=1);` partout ; typage complet ; PSR-4 (`Patrimeter\` → `src/`,
  `Patrimeter\Tests\` → `tests/`) ; style PER-CS 3.0 (PHP-CS-Fixer).
- Code, identifiants et **commentaires du code en anglais**, scripts compris (shell, CI, outils, gabarits Twig,
  CSS, JavaScript) ; variables shell des procédures comprises ; documentation technique en français ; interface,
  documentation d'utilisation et d'exploitation, README, CHANGELOG et SECURITY en français et
  en anglais (voir « Documentation bilingue »).
- Montants : **entiers en centimes**. Conversion uniquement en entrée (`Money::parse`) et en sortie
  (filtre Twig `money`). Jamais de `float` pour l'argent.
- Mois : objet `Month` (`AAAA-MM`). Dates : `AAAA-MM-JJ`. Date du jour et mois courant : service
  `Calendar` (horloge injectée), jamais `date()` ni `new DateTime()`.
- Nature d'un compte : `asset` ou `liability` (montant positif = capital restant dû, soustrait du
  niveau). Niveaux : `residence`, `precaution`, `fonds_euros`, `actions`, `immobilier`, `exotique`.
- Contrôleurs minces ; logique dans `Domain/` (pur) ou des services ; gabarits Twig sans logique
  métier (préparer les données côté PHP, ex. `Twig\MonthPicker`).
- **Cloisonnement** : le propriétaire vient toujours de `CurrentUser::id()` (jamais de la requête)
  et se passe à chaque méthode des dépôts de données ; chaque requête SQL de ces dépôts mentionne
  `user_id` (test `tests/Architecture`). Compte lu : `denyAccessUnlessGranted(OwnedResourceVoter::OWNER)`.
- **Nouvelle route** : la classer dans `tests/Support/RouteCatalog.php` (publique, données
  patrimoniales, réglages, jeton porteur…), sinon les robots `AccessControlTest`/`IsolationTest`/
  `ApiIsolationTest` échouent ; une route de données s'ajoute aussi au robot de cloisonnement. Un compte de
  démonstration (`DEMO_MODE`) n'atteint que les routes de `DemoGuard::ALLOWED_ROUTES` : y ajouter la route si la
  démonstration doit l'utiliser (jamais réglages de sécurité, API, import ni envoi d'e-mail).
- **API et MCP** : préférer ce que propose Symfony (`access_token`, `access_control`, `Request::toArray()`,
  `#[MapQueryParameter]`, `symfony/mcp-bundle`), et prévenir avant toute autre bibliothèque. Lectures et écritures
  dans `PortfolioService`, partagé par les deux ; routes `stateless: true`. Toute route ou tout champ de l'API se
  décrit dans `docs/openapi.yaml` (vérifié par `OpenApiTest`) ; la page « Référence de l'API » en est générée.
  Jetons au préfixe `patrimeter_` : `ApiTokens::PATTERN` est la règle de `.gitleaks.toml` (ApiTest). Origine des
  requêtes MCP vérifiée avant le pare-feu par `ApiSubscriber` (site et `MCP_ALLOWED_ORIGINS`).
- **Données patrimoniales** : chiffrées dans les dépôts (`Aead`, clé obtenue de `Vault`, contexte lié
  à la ligne et au propriétaire). Toute nouvelle donnée patrimoniale suit le même chemin ; rien en
  clair en base. Aucune migration des anciennes données : le projet repart d'une base neuve.
- Twig : un `set` dans une boucle écrase la variable de même nom du contexte (donc de la page) :
  noms propres dans `base.html.twig`.
- Secrets d'authentification (TOTP, codes de secours) : via `AuthSecrets` (clé `AUTH_SECRET_KEY`, rotation
  par `AUTH_SECRET_KEY_PREVIOUS`), jamais en clair en base ni en session. Liens envoyés par e-mail : `PublicUrl` (`DEFAULT_URI`).
- Formulaires : champ caché `_csrf` = `csrf_token('patrimeter')` ; la vérification est centralisée
  (`CsrfProtectionSubscriber`). Redirections après POST : 303.
- Messages flash hors contrôleur : `Patrimeter\Http\Flash::add()`.
- Couleurs : uniquement des variables CSS ; le jeu sombre est défini deux fois dans `app.css`
  (thème du système et choix explicite `data-theme="dark"`) : modifier les deux. Contrastes WCAG AA et niveaux
  distincts pour les daltoniens : `tests/Architecture/ColorAccessibilityTest.php`. Jamais d'information par la seule couleur.
- Redirection vers une adresse fournie par l'utilisateur : toujours `InternalPath::sanitize()`.
- Renvoi à une section (« § 12.1 ») : toujours un lien vers elle, avec l'ancre de Zensical (sans accents ni
  ponctuation : `installation.md#121-depuis-git`), sauf dans un bloc de code.
- Écran ajouté ou modifié : mettre à jour la documentation d'utilisation (`documentation/fr/` et `documentation/en/`)
  et, si
  besoin, la liste des captures (`tests/e2e/captures/captures.spec.mjs`).
- Chaque version livrée : CHANGELOG daté (`CHANGELOG.md` et `CHANGELOG.en.md`), **étiquette git annotée `vX.Y.Z`** sur
  `main`, puis Release GitLab
  (procédure : [docs/ci.md](docs/ci.md#publier-une-version)).
- Commits : Conventional Commits (`feat:`, `fix:`, `test:`, `docs:`, `ci:`, `build:`, `refactor:`),
  messages **en anglais** (voir « Langue du projet ») ; une branche et une merge request par sujet, branche supprimée à
  la fusion ; étiquettes :
  seulement les versions `vX.Y.Z` ([docs/ci.md](docs/ci.md#branches-et-étiquettes)) ; CHANGELOG à jour.
- Chaque bug corrigé est accompagné d'un test qui le reproduit.

## Langue du projet

Projet international : **commits** (titre et corps), **issues**, **merge requests** (titre, description,
commentaires et fils de revue) et **`ROADMAP.md`** s'écrivent **exclusivement en anglais**, quelle que soit la langue de
la demande. Les noms de branches aussi. **Les commentaires du code** aussi, dans tous les fichiers (PHP, scripts
shell, CI, Twig, CSS, JavaScript, configuration) : tout commentaire écrit ou modifié l'est en anglais, et un
commentaire français rencontré dans le code qu'on modifie se traduit au passage. Le reste suit les règles ci-dessus et
ci-dessous : interface en français et en anglais, documentation bilingue, documentation technique en français.

## Documentation bilingue

- Le français fait référence, l'anglais le traduit à l'identique : `README.md` → `README.en.md`, `CHANGELOG.md` →
  `CHANGELOG.en.md`, `SECURITY.md` → `SECURITY.en.md`, `docs/X.md` → `docs/X.en.md`, `docs/openapi.yaml` →
  `docs/openapi.en.yaml` (seuls les textes changent), `documentation/fr/` → `documentation/en/` (mêmes chemins).
- Toute modification se fait dans les deux langues : mêmes sections, blocs de code et captures ; dans les blocs
  `<!-- ci:… -->`, mêmes commandes (seuls les commentaires se traduisent). Une page anglaise renvoie aux pages
  anglaises (`X.en.md`). Vérifié par `tests/Architecture/DocumentationTranslationTest.php` et `OpenApiTest`.
- Pages techniques du développement en français seulement (architecture, CI, choix techniques, modèle de données,
  parcours d'authentification) : liste `french_only` de `documentation/prepare.sh` ; une page
  anglaise qui y renvoie l'indique (« in French »).
- Libellés de l'interface cités : ceux de `translations/messages.en.yaml`. Captures d'écran prises dans chaque langue
  (`tests/e2e/captures/captures.spec.mjs`, comptes de démonstration aux noms anglais).

## Arborescence

```text
assets/        CSS, JS, Chart.js (assets/vendor/)
bin/console    console Symfony
ci/            scripts de la CI (PHP Surý, Composer, k6, ZAP, Nuclei, OpenSSF Scorecard, lancement de l'appli, déploiement réel)
docker/        image de conteneur : Dockerfile, compose.yaml, point d'entrée, nginx, console, modèle de patrimeter.env
config/        configuration Symfony (services.yaml et packages/rate_limiter.yaml : paramètres fonctionnels)
migrations/    migrations Doctrine (SQL explicite)
public/        racine web unique (index.php, favicon)
src/           Domain/, Application/, Infrastructure/Persistence/, Security/, Http/, Controller/, Twig/
templates/     gabarits Twig
tests/         Unit/, Integration/, Functional/, Regression/, Architecture/, Support/, Fixtures/, e2e/, perf/, dast/, deployment/
security/      exclusions justifiées et datées de la barrière de sécurité
tools/         coverage-check.php, security-gate.php, pipeline-report.php, règle PHPStan (PHPStan/), routeur de make serve
var/           base SQLite, sessions, cache, journaux (seul dossier inscriptible par www-data)
docs/          installation Linux, conteneur (docker.md), mise à jour, sauvegarde (backup/), architecture, chiffrement, CI, nginx, php-fpm
documentation/ documentation d'utilisation (Zensical, GitLab Pages) : pages dans fr/ et en/, docs/ repris à la construction
```

## Commandes utiles

```sh
make install                     # composer install + migrations (base de dev)
php bin/console app:user:create <e-mail>   # compte (mot de passe sur stdin si non interactif)
make serve                       # http://localhost:8000 (php -S)
make test                        # PHPUnit (toutes les suites)
make lint cs stan psalm          # syntaxe, style, PHPStan max, Psalm + taint
make coverage w3c audit          # couverture et seuils, validation W3C, composer audit
make e2e dast perf               # Playwright (Mailpit requis), ZAP, k6
make ci                          # tout, hors e2e, dast et perf
make docs                        # documentation d'utilisation, français et anglais (Zensical, strict) : documentation/site/
```

Tests : base SQLite de test recréée par les migrations à chaque exécution, transaction annulée
après chaque test, horloge figée au 15/11/2026 12:00 (`config/services.yaml`, `when@test`).
Utilisateurs de test : `Support\TestUsers` (TOTP au secret connu) ; `logIn()` fait la connexion
complète (mot de passe puis code). Un même code TOTP ne sert qu'une fois : avancer l'horloge de
30 s avant une seconde connexion du même utilisateur. E-mails : assertions Mailer de Symfony, sur
la dernière requête uniquement.
Après un changement de gabarit, vider `var/cache/test` avant de relancer les tests non-debug.
Le cache Symfony dépend de la version de PHP (objets paresseux natifs à partir de 8.4) : le vider
en changeant de version, sinon des dépréciations parasites apparaissent.

## CI

Branches et MR : pipeline rapide (lint, analyse, tests, couverture, W3C, Gitleaks, construction de la
documentation). Pipeline complet sur `main`, ou sur une branche en lançant un pipeline avec `FULL_PIPELINE=1` :
à faire avant de fusionner un changement de déploiement, de nginx, de sécurité ou des parcours e2e. `main` est
protégée : fusion par merge request seulement. Un seul pipeline à la fois sur le runner : sous la charge d'un
autre, k6 échoue. Le lundi (`SECURITY_SCAN=1`) : analyses de sécurité, image de la dernière version reconstruite et
republiée. Chaque jour (`RENOVATE=1`) : Renovate seul (`renovate.json`, `DEPENDENCY_BOT_TOKEN`,
[docs/ci.md](docs/ci.md#renovate)), qui ouvre les MR de mise à jour des dépendances ; tout nouveau job s'exclut de ce
pipeline (`.not-renovate`, `RenovatePipelineTest`). OpenSSF Scorecard
dans chaque pipeline de `main` (`SCORECARD_TOKEN` en lecture seule ; son badge est le dernier artefact de `main`,
[docs/ci.md](docs/ci.md#openssf-scorecard)). Jobs PHP sur l'image CI `ci-php` (`ci/php/Dockerfile`, construite par
`ci-php-image` si son étiquette manque au registre) : modifier ce Dockerfile, `ci/install-php.sh`,
`ci/install-composer.sh` ou la clé change l'étiquette attendue dans `CI_PHP_IMAGE` (`CiPhpImageTest` donne la
nouvelle). Pipeline d'étiquette réduit par la règle `.not-tag` : tout nouveau job la reçoit, sauf s'il sert la
livraison (liste de `TagPipelineTest`). Markdown vérifié par markdownlint (`.markdownlint-cli2.jsonc`, `make js`) et
ancres de Zensical par `DocumentationLinksTest` : listes imbriquées à 4 espaces, lignes de 120 caractères. Nuclei
(`ci/run-nuclei.sh`) : sur l'application du pipeline (barrière de sécurité), et sur l'instance déployée le lundi
(`nuclei-production`, `PRODUCTION_URL`).

## Documentation exécutée

Les blocs `sh` précédés de `<!-- ci:… -->` dans `docs/installation.md` et `docs/backup.md` sont exécutés
par le job `deployment` (`ci/check-deployment.sh`) : les garder sans indentation, et identiques dans leur traduction
anglaise (`.en.md`). Tout changement de nginx, php-fpm, paquets ou `.env.local` passe par ces pages. Le fastcgi_params
de Debian retire le port de `Host` : les redirections absolues ne fonctionnent que sur 80 et 443.

## Vérifications avant commit

- `make ci` vert (PHPUnit sans dépréciation, PHPStan max, Psalm, style, W3C).
- Rendu vérifié à 390 px de large (mobile) et en thème sombre.
- Les résultats de référence v1 (`tests/Fixtures/regression/`) ne se modifient jamais pour faire
  passer un test.

## Règles métier clés

- Valeur d'un compte pour un mois M = relevé de M s'il existe, sinon dernier relevé antérieur
  (« reporté »), sinon aucune valeur.
- Un compte compte pour le mois M si `mois(ouverture) <= M` et (`clôture` vide ou `mois(clôture) > M`).
- Net d'un niveau = somme des actifs − somme des passifs rattachés à ce niveau.
- Part d'un bien pour le mois M = dernière part dont le mois d'effet est ≤ M, sinon 100 %, en
  millionièmes (`OwnershipShares`, arrondi au centime le plus proche). Valeur retenue d'un actif =
  relevé × part ; crédit lié `prorata` = relevé × part du bien ; crédit lié `own` ou non lié = relevé.
