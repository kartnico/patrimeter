# Technologies et licences

Patrimeter est un logiciel libre sous licence [MIT](https://opensource.org/license/mit). Il s'appuie sur les projets
ci-dessous, chacun avec sa propre licence. La liste complète des dépendances livrées avec l'application, version par
version, est dans
[THIRD-PARTY-LICENSES.md](https://gitlab.example.org/kartzone/patrimeter/-/blob/main/THIRD-PARTY-LICENSES.md).

## Plateforme

| Projet | Rôle | Licence |
|---|---|---|
| [PHP](https://www.php.net/) | langage de l'application (8.3 à 8.5) | [PHP License](https://www.php.net/license/) |
| [SQLite](https://sqlite.org/) | base de données | [domaine public](https://sqlite.org/copyright.html) |
| [nginx](https://nginx.org/) | serveur web | BSD-2-Clause |
| [Debian](https://www.debian.org/) | système de l'image de conteneur et de la CI | [licences libres (DFSG)](https://www.debian.org/social_contract#guidelines) |
| [Docker](https://www.docker.com/) et [Podman](https://podman.io/) | conteneurs | Apache-2.0 |
| [tini](https://github.com/krallin/tini) | processus 1 du conteneur | MIT |
| [systemd](https://systemd.io/) | services et tâches planifiées sur Linux | LGPL-2.1+ |

## Framework et bibliothèques livrés

| Projet | Rôle | Licence |
|---|---|---|
| [Symfony](https://symfony.com/) 7.4 LTS | framework : routes, formulaires, sécurité, e-mails, tâches planifiées, traductions… | MIT |
| [Twig](https://twig.symfony.com/) | gabarits des pages | BSD-3-Clause |
| [Doctrine DBAL et Migrations](https://www.doctrine-project.org/) | accès à la base et migrations | MIT |
| [Monolog](https://github.com/Seldaek/monolog) | journaux | MIT |
| [scheb/2fa](https://github.com/scheb/2fa) | double authentification (TOTP, codes de secours) | MIT |
| [OTPHP](https://github.com/Spomky-Labs/otphp) | codes TOTP | MIT |
| [WebAuthn Framework](https://webauthn-doc.spomky-labs.com/) | passkeys et clés FIDO2 | MIT |
| [endroid/qr-code](https://github.com/endroid/qr-code) et [BaconQrCode](https://github.com/Bacon/BaconQrCode) | QR code de l'application d'authentification | MIT, BSD-2-Clause |
| [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk) | service MCP pour les agents IA | Apache-2.0 |
| [Opis JSON Schema](https://opis.io/json-schema/) | validation des messages MCP | Apache-2.0 |
| [Brick\Math](https://github.com/brick/math) | calculs exacts | MIT |
| [Chart.js](https://www.chartjs.org/) | graphiques | MIT |

## Outils de développement (non livrés)

| Projet | Rôle | Licence |
|---|---|---|
| [Composer](https://getcomposer.org/) | dépendances PHP | MIT |
| [PHPUnit](https://phpunit.de/) | tests PHP | BSD-3-Clause |
| [Infection](https://infection.github.io/) | tests de mutation | BSD-3-Clause |
| [PHPStan](https://phpstan.org/) et [Psalm](https://psalm.dev/) | analyse statique | MIT |
| [PHP-CS-Fixer](https://cs.symfony.com/) | style du code PHP | MIT |
| [Node.js](https://nodejs.org/) | outils JavaScript | MIT |
| [ESLint](https://eslint.org/), [Prettier](https://prettier.io/) et [Stylelint](https://stylelint.io/) | style du JavaScript et du CSS | MIT |
| [markdownlint-cli2](https://github.com/DavidAnson/markdownlint-cli2) | style de la documentation | MIT |
| [Vitest](https://vitest.dev/) et [jsdom](https://github.com/jsdom/jsdom) | tests JavaScript | MIT |
| [fast-check](https://fast-check.dev/) | tests par propriétés | MIT |
| [Playwright](https://playwright.dev/) | parcours de bout en bout et captures | Apache-2.0 |
| [axe-core](https://github.com/dequelabs/axe-core) | contrôle de l'accessibilité | MPL-2.0 |
| [Nu Html Checker](https://validator.github.io/validator/) | validation W3C du HTML et du CSS | MIT |
| [Zensical](https://zensical.org/) | site de cette documentation | MIT |
| [Mailpit](https://mailpit.axllent.org/) | e-mails en développement | MIT |

## CI, sécurité et livraison

| Projet | Rôle | Licence |
|---|---|---|
| [GitLab](https://about.gitlab.com/) | dépôt, CI, registre, Pages | MIT (édition communautaire) |
| [Renovate](https://docs.renovatebot.com/) | mises à jour des dépendances | AGPL-3.0 |
| [Buildah](https://buildah.io/) | construction de l'image | Apache-2.0 |
| [hadolint](https://github.com/hadolint/hadolint) | analyse du Dockerfile | GPL-3.0 |
| [Trivy](https://trivy.dev/) | vulnérabilités des dépendances et de l'image | Apache-2.0 |
| [Semgrep](https://semgrep.dev/) | analyse de sécurité du code | LGPL-2.1 |
| [Gitleaks](https://gitleaks.io/) | secrets dans le dépôt | MIT |
| [SonarQube](https://www.sonarsource.com/products/sonarqube/) et [SonarScanner](https://github.com/SonarSource/sonar-scanner-cli) | qualité du code et barrière de qualité | LGPL-3.0 (Community Build) |
| [ZAP](https://www.zaproxy.org/) et [Nuclei](https://projectdiscovery.io/nuclei) | tests de sécurité de l'application | Apache-2.0, MIT |
| [k6](https://k6.io/) | tests de charge | AGPL-3.0 |
| [OpenSSF Scorecard](https://scorecard.dev/) | note des pratiques du projet | Apache-2.0 |
| [age](https://age-encryption.org/) | chiffrement des sauvegardes copiées hors du serveur | BSD-3-Clause |

## Services externes facultatifs

- [Have I Been Pwned](https://haveibeenpwned.com/Passwords) : refus des mots de passe divulgués, par k-anonymat
  (seuls les cinq premiers caractères de l'empreinte du mot de passe partent).
- [Carbure](https://github.com/ltoinel/Carbure) (MIT) : suivi des dépenses bancaires, relié au budget si vous le
  souhaitez.
