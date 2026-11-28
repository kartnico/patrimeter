# Patrimeter

[Français](README.md) · **English**

[![MIT license](https://img.shields.io/badge/license-MIT-blue)](LICENSE)
[![PHP 8.3 to 8.5](https://img.shields.io/badge/PHP-8.3%20%E2%80%93%208.5-777bb4?logo=php&logoColor=white)](composer.json)
[![Symfony 7.4 LTS](https://img.shields.io/badge/Symfony-7.4%20LTS-000000?logo=symfony)](https://symfony.com/releases/7.4)
[![WCAG 2.2 AA accessibility](https://img.shields.io/badge/accessibility-WCAG%202.2%20AA-brightgreen)](tests/e2e/accessibility.spec.mjs)
[![Docker and Podman image](https://img.shields.io/badge/container-Docker%20%7C%20Podman-2496ED?logo=docker&logoColor=white)](docs/docker.en.md)

Patrimeter tracks your **net worth** every month, shown as a **pyramid** (a model made popular by Finary), and your
**household budget**. It is a web application you host yourself, on Linux or in a container, under the
[MIT](LICENSE) license.

> **Patrimeter is a tracking tool, not financial advice.** Indicative benchmarks, to adapt to your situation: this
> is not investment advice.

Public issues: use [GitHub Issues](https://github.com/kartnico/patrimeter/issues); do not report vulnerabilities publicly.
**User documentation**, in French and in English: <https://kartnico.github.io/patrimeter/en/>.
What's new: [CHANGELOG.en.md](CHANGELOG.en.md); roadmap: [ROADMAP.md](ROADMAP.md).

## Features

- **Monthly net worth**: accounts, loans and real estate, pyramid, history, report to share as a picture, with or
  without the amounts.
- **Quick monthly update**: a single form, "=" for an unchanged balance, last balance carried over.
- **Guided first steps**: build your pyramid level by level, enter everything on one page, or answer a few simple
  questions.
- **Household budget**: joint account, 50/30/20 rule, emergency savings; savings goals and trends.
- **Challenges and stars** for good habits, can be turned off.
- **Privacy and security**: several users kept apart, data encrypted for each of them, two-factor authentication and
  passkeys.
- **And also**: family account, link with [Carbure](https://github.com/ltoinel/Carbure), REST API and MCP service,
  export and import, demo mode.
- Interface in French and in English, designed for the phone (sidebar on a computer), light or dark theme, accent
  colour and density of your choice, accessible.

### The pyramid

| Tier | Level | Examples |
|---|---|---|
| 1 | Main residence | value of the home, minus the loan still owed |
| 2 | Emergency savings | current account, Livret A, LDDS, LEP |
| 3 | Guaranteed funds | life insurance in a euro fund |
| 4 | Equities \| Real estate | PEA, securities account, PER \| rental, SCPI |
| 5 | Alternative | crowdfunding, crypto, gold… |

## Installation

Two ways, with the requirements at the top of each page:

- **With Docker** (or Podman): [docs/docker.en.md](docs/docker.en.md). The image of each version is published in
  Docker Hub: `kartnico1/patrimeter`.
- **On Linux**, from the archive of a version: [docs/installation.en.md](docs/installation.en.md).
- **Updating**: [docs/mise-a-jour.en.md](docs/mise-a-jour.en.md); **backup**: [docs/backup.en.md](docs/backup.en.md).
- **Configuration**: environment variables in `.env.local`, commented template in [.env.example](.env.example).
- **Security**: [SECURITY.en.md](SECURITY.en.md).

## Development

### Requirements

- PHP 8.3 to 8.5, packages from the Surý repository (`cli`, `sqlite3`, `xml`, `mbstring`, `intl`, and `pcov` for
  coverage).
- Composer and `make`.
- Node 22 or 24 for the JavaScript tools and the end-to-end tests.
- Python to build the documentation.

Node and Python only serve the tools: never in production.

### Commands

```sh
make install          # dependencies and development database (var/patrimeter.sqlite)
php bin/console app:user:create you@example.com
make serve            # http://localhost:8000
make test             # PHPUnit: unit, integration, functional, regression, architecture
make lint cs stan psalm
make js               # ESLint, Prettier, Stylelint, Vitest (after npm ci)
make ci               # PHP checks, W3C and audit; run make js separately
make docs             # documentation, in French and in English (documentation/site/)
make help             # every command
```

### Emails

In development, no email leaves as long as `SMTP_HOST` is empty. To read them, start
[Mailpit](https://mailpit.axllent.org/) and set in `.env.local`: `SMTP_HOST=localhost`, `SMTP_PORT=1025` and
`SMTP_ENCRYPTION=none`. The messages show on <http://localhost:8025>.

### Quality

The GitLab CI ([docs/ci.md](docs/ci.md), in French) checks every change:

- **Tests**: PHPUnit on PHP 8.3, 8.4 and 8.5, coverage with thresholds, mutation testing, property-based tests
  (fast-check), Playwright journeys.
- **Analysis**: PHPStan at max level, Psalm with taint analysis, SonarQube and its quality gate, PER-CS style,
  ESLint, Stylelint, markdownlint.
- **Pages**: W3C-valid HTML and CSS, accessibility checked by axe-core.
- **Security**: Semgrep, Gitleaks, ZAP, Nuclei, Trivy and dependency audits.
- **Installation and load**: real installation following the documentation, container image tested, load measured
  by k6.

Every day, Renovate offers the dependency updates. Every day, the security scans run again and the image of the
latest version is rebuilt.

### Going further

- Workflow (branches, merge requests, publishing a version): [docs/developpement.md](docs/developpement.md), in
  French.
- Architecture: [docs/architecture.md](docs/architecture.md); technical choices:
  [docs/choix-techniques.md](docs/choix-techniques.md), in French.
- Calculation rules: [AGENTS.md](AGENTS.md#règles-métier-clés), in French.
- Technologies used, with their links and licenses: [docs/technologies.en.md](docs/technologies.en.md).
- Bilingual documentation: every page exists in French and in English (`X.md` and `X.en.md`); the technical pages of
  the development stay in French.

### Layout

```text
assets/          CSS and JavaScript (AssetMapper, no build); Chart.js copied
bin/console      Symfony console (migrations, user accounts, key rotation, alerts)
ci/              CI scripts
config/          Symfony configuration
docker/          container image
documentation/   user documentation (Zensical, GitHub Pages), in French and in English
docs/            technical and operations documentation
migrations/      versioned SQL schema (explicit SQL only)
public/          web root
security/        justified and dated exclusions of the security gate
src/             Domain/ (pure calculations), Application/, Infrastructure/, Security/, Http/, Controller/, Twig/
templates/       Twig templates
tests/           PHPUnit (Unit, Integration, Functional, Regression, Architecture); e2e, js, perf, dast, deployment
tools/           CI tools (thresholds, security gate, report)
translations/    English translations of the interface (key: French text)
```
