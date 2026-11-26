# Développer et livrer à la main

Ce que fait un développeur, de la branche à la version publiée, sans outil d'assistance : le flux git, les
pipelines GitLab et leurs variables, la publication d'une version (étiquette, Release, image) et les mises à jour
des dépendances proposées chaque jour par Renovate. La CI elle-même est décrite dans [ci.md](ci.md) ; les règles du
code dans [AGENTS.md](https://gitlab.example.org/kartzone/patrimeter/-/blob/main/AGENTS.md).

## 1. Principes

- **`main`** est la seule branche durable, protégée : on n'y pousse jamais directement, tout passe par une
  **merge request** (MR).
- **Une branche par sujet**, partie de `main` à jour, nommée comme son commit principal (`feat/…`, `fix/…`,
  `docs/…`, `chore/…`, `ci/…`, `build/…`, `refactor/…`), **supprimée à la fusion**.
- **Commits** au format [Conventional Commits](https://www.conventionalcommits.org/fr/v1.0.0/), **en anglais**
  (titre et corps), comme les MR, les issues, `ROADMAP.md` et les commentaires du code.
- **Un bogue corrigé** l'est avec un test qui le reproduit (le test échoue sans le correctif).
- **Un seul pipeline à la fois sur le runner** : sous la charge d'un autre, les mesures de charge (k6) échouent.
- **Jamais de secret dans le dépôt** : jetons et clés dans les variables masquées de GitLab ou dans
  `.env.local`. Gitleaks et la détection de secrets de GitLab le vérifient à chaque pipeline.

## 2. Avant chaque commit

```sh
make ci          # syntaxe, style, PHPStan max, Psalm, PHPUnit, W3C, audit des dépendances
make js          # ESLint, Prettier, Stylelint, Vitest (après npm ci)
make docs        # si la documentation change : construction stricte des deux sites
```

Écran modifié : documentation d'utilisation dans `documentation/fr/` **et** `documentation/en/`, captures si besoin
(`tests/e2e/captures/captures.spec.mjs`), rendu vérifié à 390 px de large et en thème sombre. Texte de l'interface :
clé française, traduction dans `translations/messages.en.yaml`. Nouvelle route : la classer dans
`tests/Support/RouteCatalog.php`. Nouvelle table : une migration en SQL explicite, avec `down()`.

## 3. Une branche, une merge request

```sh
git switch main && git pull --ff-only
git switch -c feat/mon-sujet
# … modifications, make ci …
git add -A && git commit -m "feat: what the change does"
git push -u origin feat/mon-sujet
```

Ouvrir la MR : lien affiché par `git push`, ou *Code > Merge requests > New merge request*, vers `main`, avec
« Delete source branch » coché (réglage par défaut du projet). Le **pipeline de MR** lance les contrôles rapides :
lint, analyse statique, tests, couverture, W3C, Gitleaks, documentation.

**Pipeline complet sur la branche**, avant de fusionner un changement de déploiement, de nginx, de sécurité, de
l'image de conteneur ou des parcours de bout en bout : *Build > Pipelines > Run pipeline*, choisir la branche,
ajouter la variable `FULL_PIPELINE` = `1`. Annuler auparavant le pipeline de MR s'il tourne encore (un seul
pipeline à la fois).

**Fusionner** : bouton *Merge* de la MR une fois le pipeline vert. La fusion lance le **pipeline complet de
`main`** (parcours Playwright, déploiement réel, sécurité, charge, archive, image) : attendre qu'il soit vert
avant de fusionner autre chose ou de publier.

```sh
git switch main && git pull --ff-only
git fetch --prune && git branch -d feat/mon-sujet
```

## 4. Variables des pipelines

| Variable | Où | Effet |
|---|---|---|
| `FULL_PIPELINE=1` | *Run pipeline*, sur une branche | pipeline complet (comme `main`) |
| `SECURITY_SCAN=1` | planification quotidienne | analyses de sécurité seules, image de la dernière version reconstruite et republiée |
| `RENOVATE=1` | planification quotidienne, ou *Run pipeline* sur `main` | Renovate seul : merge requests de mise à jour des dépendances ([ci.md](ci.md#renovate)) |
| `REGISTRY_PUSH=1` | *Run pipeline* complet | publie aussi l'image sous le commit (`patrimeter:<sha court>`) |
| `PRODUCTION_SCAN=1` | *Run pipeline*, sur `main` | scan Nuclei de l'instance déployée (`nuclei-production`), hors planification |
| `PRODUCTION_URL` | `.gitlab-ci.yml` (ou *Settings > CI/CD > Variables*) | adresse de l'instance déployée que scanne `nuclei-production` ; vide : aucun scan |
| `DEPENDENCY_BOT_TOKEN` | *Settings > CI/CD > Variables*, masquée et protégée | jeton de Renovate ([ci.md](ci.md#renovate)) |
| `SCORECARD_TOKEN` | *Settings > CI/CD > Variables*, masquée et protégée | jeton en lecture seule d'OpenSSF Scorecard ([ci.md](ci.md#openssf-scorecard)) ; sans lui, badge « non mesuré » |

Les autres variables (images épinglées, versions recommandées de Debian et de PHP, seuils de couverture) sont dans
l'en-tête de `.gitlab-ci.yml` et se changent par une MR.

Un pipeline se lance aussi par l'API, avec un jeton personnel de portée `api` (à créer dans *User settings >
Access tokens*, avec une expiration courte, puis à révoquer) :

```sh
curl --request POST --header "PRIVATE-TOKEN: <jeton>" \
    --form "ref=feat/mon-sujet" --form "variables[][key]=FULL_PIPELINE" --form "variables[][value]=1" \
    "https://gitlab.example.org/api/v4/projects/56/pipeline"
```

## 5. Publier une version

1. **Branche `chore/version-X.Y.Z`** : dans `CHANGELOG.md` et `CHANGELOG.en.md`, la section de la version,
   `## [X.Y.Z]` (sans date tant qu'elle se prépare), prend la date du jour de publication et son lien de comparaison
   en bas de page ;
   sous-section **Installation** si la mise à jour demande une action (nginx, variable, paquet). `VERSION` passe à
   `X.Y.Z` (`VersionTest` vérifie la concordance). Mettre à jour `ROADMAP.md` (version livrée cochée). La
   documentation d'installation et de mise à jour ne cite aucun numéro de version : rien à y changer.
2. **MR, fusion, pipeline complet de `main` vert.** Un échec se corrige par une nouvelle MR ; k6 seul en échec de
   peu (seuils dépassés sans erreur) : relancer le job une fois, le runner est souvent chargé.
3. **Étiquette annotée** sur le commit de fusion de `main` :

   ```sh
   git switch main && git pull --ff-only
   git tag -a vX.Y.Z -m "Patrimeter X.Y.Z"
   git push origin vX.Y.Z
   ```

4. **Pipeline de l'étiquette** (réduit par les règles de la CI, lancé par le push : rien à annuler à la main) : il
   construit l'archive (job `release`) et l'image ;
   `container-publish` la publie sous `vX.Y.Z`, `vX.Y.Z-AAAAMMJJ-p<ID>` et `latest` une fois le smoke test et la barrière
   de sécurité passés. La barrière part parfois avant la fin des analyses et échoue faute de rapport : la relancer
   (*Retry*) quand elles sont finies ; `container-publish` suit.
5. **Release GitLab** : *Deploy > Releases > New release*, étiquette `vX.Y.Z`, titre `Patrimeter X.Y.Z`, notes =
   la section du CHANGELOG en français puis celle de `CHANGELOG.en.md`, et les quatre fichiers que citent les pages
   d'installation, en liens directs : l'archive `patrimeter-vX.Y.Z.tar.gz` et son empreinte `.sha256` (fichiers du job
   `release`), `compose.yaml` et `patrimeter.env.example` (fichiers de `docker/` à l'étiquette).
6. **Garder l'archive** : ouvrir le job `release` du pipeline de l'étiquette, bouton *Keep* (sinon elle expire au
   bout de 90 jours).

Les mêmes étapes 5 et 6 par l'API :

```sh
curl --request POST --header "PRIVATE-TOKEN: <jeton>" --header "Content-Type: application/json" \
    --data '{"tag_name": "vX.Y.Z", "name": "Patrimeter X.Y.Z", "description": "<notes en Markdown>",
             "assets": {"links": [
               {"name": "patrimeter-vX.Y.Z.tar.gz", "link_type": "package",
                "url": "https://gitlab.example.org/kartzone/patrimeter/-/jobs/artifacts/vX.Y.Z/raw/release/patrimeter-vX.Y.Z.tar.gz?job=release"},
               {"name": "patrimeter-vX.Y.Z.tar.gz.sha256", "link_type": "other",
                "url": "https://gitlab.example.org/kartzone/patrimeter/-/jobs/artifacts/vX.Y.Z/raw/release/patrimeter-vX.Y.Z.tar.gz.sha256?job=release"},
               {"name": "compose.yaml", "link_type": "other",
                "url": "https://gitlab.example.org/kartzone/patrimeter/-/raw/vX.Y.Z/docker/compose.yaml"},
               {"name": "patrimeter.env.example", "link_type": "other",
                "url": "https://gitlab.example.org/kartzone/patrimeter/-/raw/vX.Y.Z/docker/patrimeter.env.example"}]}}' \
    "https://gitlab.example.org/api/v4/projects/56/releases"
curl --request POST --header "PRIVATE-TOKEN: <jeton>" \
    "https://gitlab.example.org/api/v4/projects/56/jobs/<identifiant du job release>/artifacts/keep"
```

Une étiquette publiée ne se déplace ni ne se supprime : une erreur se corrige par une nouvelle version.

## 6. Chaque jour

La planification *Build > Pipeline schedules* (6 h chaque jour, `SECURITY_SCAN=1`) fait quatre choses
([ci.md](ci.md#controle-quotidien)) :

- **Analyses de sécurité** : un échec arrive par e-mail ; une alerte High ou Critical se corrige, ou s'exclut avec
  une justification datée dans `security/exclusions.json`.
- **Image republiée** : la dernière version est reconstruite avec les paquets du jour et republiée sous `vX.Y.Z` et
  `latest`. Rien à faire si le pipeline est vert ; sinon l'image précédente reste publiée.
- **OpenSSF Scorecard** : la note du projet, comme dans chaque pipeline de `main` ; un recul de la note se lit dans
  `scorecard/scorecard.txt` (artefact du job `scorecard`).
- **MR de Renovate** (`renovate/…`, chaque jour si une version est disponible) : la relire (paquets, notes de
  version liées dans la MR), lancer un pipeline complet sur sa branche (`FULL_PIPELINE=1`) si elle touche la CI,
  l'image ou les outils, corriger sur la branche si une vérification échoue, puis fusionner. Une version majeure
  attend votre approbation sur le tableau de bord des dépendances. Elle part dans la version suivante.

Pour GitHub, Docker Hub et les versions correctives automatiques de `stable`, voir la
[configuration de publication](ci.md#activer-les-publications-publiques).

## 7. Jetons

| Jeton | Portées | Usage | Durée |
|---|---|---|---|
| Personnel du développeur | `api`, `write_repository` | API ci-dessus, push en HTTPS | courte ; à révoquer après usage |
| Projet « Renovate » | `api`, `write_repository`, rôle Developer | `DEPENDENCY_BOT_TOKEN` | un an au plus |
| Projet « OpenSSF Scorecard » | `read_api`, `read_repository`, rôle Maintainer (lecture seule par ses portées) | `SCORECARD_TOKEN` | un an au plus |
| Projet ou personnel, lecture | `read_api`, rôle Reporter | vérification de la dernière version (`UPDATE_CHECK_TOKEN`, [supervision.md](supervision.md)) | à renouveler |
| Registre | `read_registry` | `docker login registry.example.org` | selon l'usage |

Un jeton ne s'écrit jamais dans un fichier du dépôt, ni dans une commande enregistrée dans l'historique du shell
sur une machine partagée (`HISTCONTROL=ignorespace` et une espace en tête de commande). Un jeton exposé se révoque
tout de suite (*Access tokens > Revoke*).

## 8. En cas de blocage

| Situation | Que faire |
|---|---|
| k6 en échec, seuils de peu dépassés | relancer le job (runner chargé) |
| `security-gate` en échec « rapport manquant » sur une étiquette | relancer une fois les analyses terminées |
| Job bloqué en « canceling » | `curl --request POST --header "PRIVATE-TOKEN: <jeton>" ".../api/v4/projects/56/jobs/<id>/cancel?force=true"` |
| Disque du runner presque plein (`composer` échoue tôt) | nettoyer le runner, puis relancer le pipeline |
| Pipeline de MR inutile pendant un pipeline complet | l'annuler (*Cancel*) : un seul pipeline à la fois |
