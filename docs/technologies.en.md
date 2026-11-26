# Technologies and licenses

Patrimeter is free software under the [MIT](https://opensource.org/license/mit) license. It relies on the projects
below, each with its own license. The full list of the dependencies shipped with the application, version by version,
is in
[THIRD-PARTY-LICENSES.md](https://gitlab.example.org/kartzone/patrimeter/-/blob/main/THIRD-PARTY-LICENSES.md).

## Platform

| Project | Role | License |
|---|---|---|
| [PHP](https://www.php.net/) | language of the application (8.3 to 8.5) | [PHP License](https://www.php.net/license/) |
| [SQLite](https://sqlite.org/) | database | [public domain](https://sqlite.org/copyright.html) |
| [nginx](https://nginx.org/) | web server | BSD-2-Clause |
| [Debian](https://www.debian.org/) | system of the container image and of the CI | [free licenses (DFSG)](https://www.debian.org/social_contract#guidelines) |
| [Docker](https://www.docker.com/) and [Podman](https://podman.io/) | containers | Apache-2.0 |
| [tini](https://github.com/krallin/tini) | process 1 of the container | MIT |
| [systemd](https://systemd.io/) | services and scheduled tasks on Linux | LGPL-2.1+ |

## Framework and shipped libraries

| Project | Role | License |
|---|---|---|
| [Symfony](https://symfony.com/) 7.4 LTS | framework: routes, forms, security, emails, scheduled tasks, translations… | MIT |
| [Twig](https://twig.symfony.com/) | page templates | BSD-3-Clause |
| [Doctrine DBAL and Migrations](https://www.doctrine-project.org/) | database access and migrations | MIT |
| [Monolog](https://github.com/Seldaek/monolog) | logs | MIT |
| [scheb/2fa](https://github.com/scheb/2fa) | two-factor authentication (TOTP, backup codes) | MIT |
| [OTPHP](https://github.com/Spomky-Labs/otphp) | TOTP codes | MIT |
| [WebAuthn Framework](https://webauthn-doc.spomky-labs.com/) | passkeys and FIDO2 keys | MIT |
| [endroid/qr-code](https://github.com/endroid/qr-code) and [BaconQrCode](https://github.com/Bacon/BaconQrCode) | QR code of the authenticator app | MIT, BSD-2-Clause |
| [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk) | MCP service for AI agents | Apache-2.0 |
| [Opis JSON Schema](https://opis.io/json-schema/) | validation of the MCP messages | Apache-2.0 |
| [Brick\Math](https://github.com/brick/math) | exact calculations | MIT |
| [Chart.js](https://www.chartjs.org/) | charts | MIT |

## Development tools (not shipped)

| Project | Role | License |
|---|---|---|
| [Composer](https://getcomposer.org/) | PHP dependencies | MIT |
| [PHPUnit](https://phpunit.de/) | PHP tests | BSD-3-Clause |
| [Infection](https://infection.github.io/) | mutation testing | BSD-3-Clause |
| [PHPStan](https://phpstan.org/) and [Psalm](https://psalm.dev/) | static analysis | MIT |
| [PHP-CS-Fixer](https://cs.symfony.com/) | PHP code style | MIT |
| [Node.js](https://nodejs.org/) | JavaScript tools | MIT |
| [ESLint](https://eslint.org/), [Prettier](https://prettier.io/) and [Stylelint](https://stylelint.io/) | JavaScript and CSS style | MIT |
| [markdownlint-cli2](https://github.com/DavidAnson/markdownlint-cli2) | documentation style | MIT |
| [Vitest](https://vitest.dev/) and [jsdom](https://github.com/jsdom/jsdom) | JavaScript tests | MIT |
| [fast-check](https://fast-check.dev/) | property-based tests | MIT |
| [Playwright](https://playwright.dev/) | end-to-end journeys and screenshots | Apache-2.0 |
| [axe-core](https://github.com/dequelabs/axe-core) | accessibility checks | MPL-2.0 |
| [Nu Html Checker](https://validator.github.io/validator/) | W3C validation of the HTML and CSS | MIT |
| [Zensical](https://zensical.org/) | site of this documentation | MIT |
| [Mailpit](https://mailpit.axllent.org/) | emails in development | MIT |

## CI, security and delivery

| Project | Role | License |
|---|---|---|
| [GitLab](https://about.gitlab.com/) | repository, CI, registry, Pages | MIT (community edition) |
| [Renovate](https://docs.renovatebot.com/) | dependency updates | AGPL-3.0 |
| [Buildah](https://buildah.io/) | image building | Apache-2.0 |
| [hadolint](https://github.com/hadolint/hadolint) | Dockerfile analysis | GPL-3.0 |
| [Trivy](https://trivy.dev/) | vulnerabilities of the dependencies and of the image | Apache-2.0 |
| [Semgrep](https://semgrep.dev/) | security analysis of the code | LGPL-2.1 |
| [Gitleaks](https://gitleaks.io/) | secrets in the repository | MIT |
| [SonarQube](https://www.sonarsource.com/products/sonarqube/) and [SonarScanner](https://github.com/SonarSource/sonar-scanner-cli) | code quality and quality gate | LGPL-3.0 (Community Build) |
| [ZAP](https://www.zaproxy.org/) and [Nuclei](https://projectdiscovery.io/nuclei) | security tests of the application | Apache-2.0, MIT |
| [k6](https://k6.io/) | load tests | AGPL-3.0 |
| [OpenSSF Scorecard](https://scorecard.dev/) | score of the project's practices | Apache-2.0 |
| [age](https://age-encryption.org/) | encryption of the backups copied off the server | BSD-3-Clause |

## Optional external services

- [Have I Been Pwned](https://haveibeenpwned.com/Passwords): rejecting leaked passwords, through k-anonymity (only the
  first five characters of the password's hash leave).
- [Carbure](https://github.com/ltoinel/Carbure) (MIT): bank spending tracking, linked to the budget if you wish.
