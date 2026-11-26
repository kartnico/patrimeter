# Patrimeter

**Français** · [English](README.en.md)

[![Licence MIT](https://img.shields.io/badge/licence-MIT-blue)](LICENSE)
[![PHP 8.3 à 8.5](https://img.shields.io/badge/PHP-8.3%20%E2%80%93%208.5-777bb4?logo=php&logoColor=white)](composer.json)
[![Symfony 7.4 LTS](https://img.shields.io/badge/Symfony-7.4%20LTS-000000?logo=symfony)](https://symfony.com/releases/7.4)
[![Accessibilité WCAG 2.2 AA](https://img.shields.io/badge/accessibilit%C3%A9-WCAG%202.2%20AA-brightgreen)](tests/e2e/accessibility.spec.mjs)
[![Image Docker et Podman](https://img.shields.io/badge/conteneur-Docker%20%7C%20Podman-2496ED?logo=docker&logoColor=white)](docs/docker.md)

Patrimeter suit chaque mois votre **patrimoine net**, affiché en **pyramide** (modèle popularisé par Finary), et le
**budget de votre foyer**. C'est une application web à héberger soi-même, sur Linux ou en conteneur, sous licence
[MIT](LICENSE).

> **Patrimeter est un outil de suivi, pas un conseil financier.** Repères indicatifs, à adapter à votre situation :
> ce n'est pas un conseil en investissement.

Tickets publics : [GitHub Issues](https://github.com/kartnico/patrimeter/issues) ; ne signalez pas les vulnérabilités publiquement.
**Documentation d'utilisation**, en français et en anglais : <https://kartnico.github.io/patrimeter/fr/>.
Nouveautés : [CHANGELOG.md](CHANGELOG.md) ; feuille de route : [ROADMAP.md](ROADMAP.md) (en anglais).

## Fonctionnalités

- **Patrimoine net mensuel** : comptes, crédits et biens immobiliers, pyramide, historique, rapport à partager en
  image, avec ou sans les montants.
- **Saisie du mois rapide** : un seul formulaire, « = » pour un solde inchangé, dernier solde reporté.
- **Premiers pas guidés** : construire sa pyramide niveau par niveau, tout saisir sur une page, ou répondre à
  quelques questions simples.
- **Budget du foyer** : compte commun, règle 50/30/20, épargne de précaution ; objectifs d'épargne et tendances.
- **Défis et étoiles** pour les bonnes habitudes, désactivables.
- **Vie privée et sécurité** : plusieurs utilisateurs cloisonnés, données chiffrées pour chacun, double
  authentification et passkeys.
- **Et aussi** : compte famille, lien avec [Carbure](https://github.com/ltoinel/Carbure), API REST et service MCP,
  export et import, mode démonstration.
- Interface en français et en anglais, pensée pour le téléphone, thème clair ou sombre, accessible.

### La pyramide

| Étage | Niveau | Exemples |
|---|---|---|
| 1 | Résidence principale | valeur du bien, moins le crédit restant dû |
| 2 | Épargne de précaution | compte courant, Livret A, LDDS, LEP |
| 3 | Fonds euros | assurance-vie en fonds euros |
| 4 | Actions \| Immobilier | PEA, compte-titres, PER \| location, SCPI |
| 5 | Exotique | crowdfunding, cryptos, or… |

## Installation

Deux façons, avec les prérequis en tête de chaque page :

- **Avec Docker** (ou Podman) : [docs/docker.md](docs/docker.md). L'image de chaque version est publiée dans
  Docker Hub : `kartnico1/patrimeter`.
- **Sur Linux**, à partir de l'archive d'une version : [docs/installation.md](docs/installation.md).
- **Mise à jour** : [docs/mise-a-jour.md](docs/mise-a-jour.md) ; **sauvegarde** : [docs/backup.md](docs/backup.md).
- **Configuration** : variables d'environnement dans `.env.local`, modèle commenté dans [.env.example](.env.example).
- **Sécurité** : [SECURITY.md](SECURITY.md).

## Développement

### Prérequis

- PHP 8.3 à 8.5, des paquets du dépôt Surý (`cli`, `sqlite3`, `xml`, `mbstring`, `intl`, et `pcov` pour la
  couverture).
- Composer et `make`.
- Node 22 ou 24 pour les outils JavaScript et les tests de bout en bout.
- Python pour construire la documentation.

Node et Python ne servent qu'aux outils : jamais en production.

### Commandes

```sh
make install          # dépendances et base de développement (var/patrimeter.sqlite)
php bin/console app:user:create vous@example.fr
make serve            # http://localhost:8000
make test             # PHPUnit : unitaires, intégration, fonctionnels, non-régression, architecture
make lint cs stan psalm
make js               # ESLint, Prettier, Stylelint, Vitest (après npm ci)
make ci               # tout ce que la CI vérifie et qui peut tourner en local
make docs             # documentation, en français et en anglais (documentation/site/)
make help             # toutes les commandes
```

### E-mails

En développement, aucun e-mail ne part tant que `SMTP_HOST` est vide. Pour les lire, lancez
[Mailpit](https://mailpit.axllent.org/) et indiquez dans `.env.local` : `SMTP_HOST=localhost`, `SMTP_PORT=1025` et
`SMTP_ENCRYPTION=none`. Les messages s'affichent sur <http://localhost:8025>.

### Qualité

La CI GitLab ([docs/ci.md](docs/ci.md)) vérifie chaque changement :

- **Tests** : PHPUnit sur PHP 8.3, 8.4 et 8.5, couverture avec seuils, tests de mutation, tests par propriétés
  (fast-check), parcours Playwright.
- **Analyse** : PHPStan au niveau max, Psalm avec analyse de taint, SonarQube et sa barrière de qualité, style
  PER-CS, ESLint, Stylelint, markdownlint.
- **Pages** : HTML et CSS valides W3C, accessibilité vérifiée par axe-core.
- **Sécurité** : Semgrep, Gitleaks, ZAP, Nuclei, Trivy et audits des dépendances.
- **Installation et charge** : installation réelle en suivant la documentation, image de conteneur testée, charge
  mesurée par k6.

Chaque jour, Renovate propose les mises à jour des dépendances. Chaque jour, les analyses de sécurité tournent de
nouveau et l'image de la dernière version est reconstruite.

### Pour aller plus loin

- Flux de travail (branches, merge requests, publication d'une version) :
  [docs/developpement.md](docs/developpement.md).
- Architecture : [docs/architecture.md](docs/architecture.md) ; choix techniques :
  [docs/choix-techniques.md](docs/choix-techniques.md).
- Règles de calcul : [AGENTS.md](AGENTS.md#règles-métier-clés).
- Technologies utilisées, avec leurs liens et leurs licences : [docs/technologies.md](docs/technologies.md).
- Documentation bilingue : chaque page existe en français et en anglais (`X.md` et `X.en.md`) ; les pages techniques
  du développement restent en français.

### Arborescence

```text
assets/          CSS et JavaScript (AssetMapper, sans build) ; Chart.js copié
bin/console      console Symfony (migrations, comptes utilisateurs, rotation de clé, alertes)
ci/              scripts de la CI
config/          configuration Symfony
docker/          image de conteneur
documentation/   documentation d'utilisation (Zensical, GitHub Pages), en français et en anglais
docs/            documentation technique et d'exploitation
migrations/      schéma SQL versionné (SQL explicite uniquement)
public/          racine web
security/        exclusions justifiées et datées de la barrière de sécurité
src/             Domain/ (calculs purs), Application/, Infrastructure/, Security/, Http/, Controller/, Twig/
templates/       gabarits Twig
tests/           PHPUnit (Unit, Integration, Functional, Regression, Architecture) ; e2e, js, perf, dast, deployment
tools/           outils de la CI (seuils, barrière de sécurité, rapport)
translations/    traductions anglaises de l'interface (clé : texte français)
```
