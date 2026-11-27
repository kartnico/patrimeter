# Roadmap

Planned versions, from the next one to the furthest. Shipped changes are in the [CHANGELOG](CHANGELOG.en.md); an item
may still move from one version to another. Minor versions add features and keep the installation working (a
migration or a configuration change, when needed, is listed under "Installation" in the CHANGELOG); a major version
may change the data model, the screens' structure or the requirements.

Every screen, email, API or MCP answer that shows benchmarks, challenges, profiles or suggestions carries the notice
"Indicative benchmarks, to adapt to your situation: this is not investment advice." Patrimeter is a tracking tool.

## 2.x — Shipped (up to 2.26.1, 2026-11-18)

Monthly net worth in a pyramid, encrypted per user; budget and tax household; family account; savings goals;
demo mode and guided tour; API, MCP service and recognizable tokens; sessions, passkeys and trusted browsers; CSV
import; encrypted off-server backups; container image; Renovate, Nuclei, OpenSSF Scorecard and markdownlint in the
CI. Details in the [CHANGELOG](CHANGELOG.en.md).

## 3.0.0 — Guided first run and challenges (shipped, 2026-11-23)

- [x] **CI and Renovate**: full Renovate image without any GitHub token, at 3:00; Node image looked up on Docker Hub;
  Buildah held below 1.44, endroid/qr-code below 6.1; Psalm 7.16 URL taints escaped by `InternalPath::sanitize()`.
- [x] **First accounts**: four ways to start on the empty Net worth page; the pyramid built level by level with the
  common account types of each level; express entry of every account on one page; creation all or nothing, opened
  this month with today's balance.
- [x] **Challenges and stars**: eight challenges of habits and safety, up to three stars each, never lost; one joker a
  year for the entry streak; six levels; Challenges page and navigation entry.
- [x] **Saver profile**: stage computed every month (Discovery, Organized, Protected, Serene, Builder) and declared
  style (Ant, Project, Home, Horizon) ordering the challenges; guard rails checked by an architecture test.
- [x] **"First steps" card** on the dashboard, stars announced after the monthly entry, challenges turned off per user
  or for the instance (`GAMIFICATION`).

## 3.1.0 — Challenges everywhere and the entry screen (shipped, 2026-11-24)

- [x] **Compact monthly entry** (proposal P): "=" for an unchanged balance (button or key), levels folded once
  entered, live net worth with the change from the previous month.
- [x] **Approximate amounts** of the first accounts flagged "to refine" in the next monthly entry.
- [x] **Simple questions for beginners** (proposal E): one question per screen, amounts by ranges.
- [x] **Challenges in the API and the MCP service** (profile, stars, challenges, with the notice), in
  `docs/openapi.yaml`.
- [x] **Monthly reminder email**: streak and the next star within reach, still without any amount.
- [x] **Stars and level on the report shared as a picture** (opt-in, without amounts).
- [x] **Savings rate challenge**, with the rate chosen by the user (no default).
- [x] **Demo**: the example account already shows stars earned and challenges to take up (DemoTest).

## 3.2.0 — Installation and a clearer interface (shipped, 2026-11-24)

- [x] **Two supported installations, on Linux: Docker (a maintained `docker compose`) and the release archive.**
    - The pages give the requirements (PHP version and extensions, web server, SQLite…), not how to install or upgrade
      PHP; no mention of git (clone, repository) nor of the CI in the installation and update pages (the CI keeps its
      own page).
    - `patrimeter-update` installs a local archive file, downloaded by the administrator (no token on the server).
    - The `deployment` job builds the archive and installs it by following the documentation.
    - Simple pages: every command commented, every variable set with an example, so that a copy and paste works.
- [x] **One look on every page**: the page's name in bold and the month in grey, head buttons in the same format; the
  budget page laid out as the goals page; the monthly entry last in the navigation and always put forward; no more
  "My assets" menu (accounts and properties open from the wealth page); months and years picked from a calendar.
- [x] **Clearer challenges**: score in clear, ladder of the stars from the bottom (earned) to the top (to reach), "x
  stars of 28" and ladder of the levels, larger stars; level on the wealth page; saver style in My profile and offered
  from the first steps; the passkey leaves the first steps.
- [x] **Technologies and licenses** page in the documentation.
- [x] **OpenSSF Scorecard**: property-based tests (fast-check), CI image base pinned by digest, security policy with a
  reporting link and timelines, green pipeline required before a merge, every MR approved.

## 3.3.0 — Quality and a dependable CI (shipped, 2026-11-24)

- [x] **SonarQube**: analysis on main and in the full pipelines, blocking on the server's quality gate, with the PHP
  coverage of the pipeline; scanner pinned and kept up to date by Renovate.
- [x] **k6 measures the application, not the runner**: database, sessions and cache in memory, OPcache as in
  production; same thresholds.
- [x] **Documentation**: Linux rather than Debian wherever the reader installs Patrimeter; the changelog shows
  versions only; orphan and outdated page on Debian packages removed.
- [x] **Clean-up**: 3.0.0 proposals removed from the repository (kept in its history), merged branches deleted,
  pending Renovate updates merged.

## 3.4.0 — Installation, settings and data (shipped, 2026-11-24)

- [x] **First-run setup wizard** in the browser: configuration checks (HTTPS, `DEFAULT_URI`, emails, alerts) and the
  first account, alongside `bin/console app:user:create`.
- [x] **Settings**: a documentation page to change any setting later (Linux and Docker); demonstration mode off by
  default.
- [x] **Bank statement read on an account page** (OFX, CAMT.053): its closing balance fills in the statement form, to
  confirm (QIF and CSV exports hold no balance).
- [x] **Custom indicators**: totals of chosen levels and accounts, their share of the net worth and their history.
- [x] **Fully automatic dependency updates**: every Renovate merge request merged once its pipeline is green, major
  versions included.

## 3.4.1 — Public distribution

- [x] Prepare release-only source snapshots, bilingual GitHub Pages and tested Docker Hub images from private GitLab CI.

## 3.5.0 — Reliable updates, reminders and monthly balances

- [x] Keep account dates consistent with statements and load only the last eligible balance for each account.
- [x] Retry failed reminders and backups; preserve existing data when repeating installation.
- [x] Protect rolling container tags from stale rebuilds and improve month navigation and accessibility.
- [x] The [updater](docs/update/patrimeter-update.sh) stops writers before taking the final snapshot, verifies it
  and keeps it with the previous release outside backup rotation. Rollback checks it before stopping the live site.
- [x] **Keep historical net worth correct when backdating a property.**
  [Monthly entry](src/Application/MonthlyEntry.php) moves the opening date and its initial share in one transaction
  without changing later shares.
  Copy a 50%-owned €200,000 property and its €80,000 prorated loan to the previous month:
  the net contribution must be €60,000 in both months, not €120,000 in the earlier one.
- [x] **Make earned stars independent of the order in which pages are opened.** The
  [game service](src/Application/Game/Game.php) records new stars while building a view.
  Separate awarding a star from announcing it: opening Challenges or reading
  the API must neither consume the announcement nor award it twice. Test both visit orders, a repeated save and
  turning the game off and on. Keep existing stars; with the game off, monthly entry still works without game prompts.

## 3.6.0 — Challenges page, first start and family account

- [x] **Redesigned Challenges page**: level and saver profile at the top, stars by family of challenges, distance to
  the next level and stage, entry streak; each card shows its thresholds on a rail, what is done and what is missing
  for the next star.
- [x] **Clearer first start** (mockups, version A): the steps of the first sign-in on each screen, both secrets
  explained, one recommended way to start and the family account offered apart.
- [x] **Clearer family account** (mockups, version A): the members page as the month's meeting point (status in words,
  import on each card), and the perimeter choice explained.
- [x] Run the Docker Hub promotion test in every full pipeline, before any tag; describe the reminder claim columns in
  the data model.

## 3.x — Game

- [ ] **Seasons** (three challenges a quarter, "Your year" summary in January), a tree growing with the level.

## 3.x — Code and screens

- [ ] **Interface ideas of the 3.0.0 proposals**: accent colour chosen by the user (Q), sidebar on a computer (R), density
  (T), consistent icon set (U), micro-animations respecting `prefers-reduced-motion` (V).
- [ ] **New look** of the entry, accounts, properties and budget screens (after the wizard has set the patterns).
- [ ] **Symfony Form component**, screen by screen (W3C-valid HTML and strict CSP kept).
- [ ] **Slimmer controllers** (`AccountController`, `OAuthServerController`): logic moved to services.
- [ ] **OpenAPI generated from the code** (attributes on the API controllers), then a locally served Swagger UI.

## 4.0.0 — Framework and PHP

- [ ] **Symfony 8.4 LTS** (November 2027) with PHP 8.4 minimum; Rector (new development dependency, to be approved)
  to automate part of the migration.
- [ ] **Renovate holds tied to PHP 8.3 lifted** (`renovate.json`: doctrine-bundle held below 3 and endroid/qr-code
  below 6.1, which need PHP 8.4).

### Additional review findings

GitLab API snapshot (2026-11-25): `main` disallows direct and force pushes; maintainers may merge. A green pipeline
and resolved discussions are required. No protected tag rule was listed. The approval rules endpoint returned 404,
so reviewer enforcement is unverified. Both the daily and weekly schedules are active on `main`.

- [ ] **P2 — Show the entry reminder only for an actionable month.** Match the monthly form's active-account rule in
  the [reminder query](src/Infrastructure/Persistence/StatementRepository.php). After the reminder date, test households
  with only closed or future accounts (no banner) and one active account (banner).
- [ ] **P2 — Make mobile report sharing match the action offered.** The [share handler](assets/js/report.js) opens
  the same native picker for every named destination. Offer one generic action when native file sharing is available;
  keep the download option. Test that cancellation is silent, while a refused share announces how to save the image
  in either language. Retain distinct destination actions where the site actually opens those destinations.

## Not tied to a version

- **Later**: single-file backup and restore of the instance from the interface (database and `.env.local` encrypted with
  a passphrase): it needs an administrator role first (who may download the whole database from a browser); meanwhile
  each user exports their own data (Data page), and the instance is backed up by the scripts of `docs/backup.md` and,
  with Docker, at each new version. Debt ratio challenge (needs the loan repayments in the budget, which it does not
  hold yet); "budget exceeded" alert and spending challenges (need the actual spending, so Carbure); entering the budget
  through the API.
- **Benchmark sources to refresh**: wealth distribution of French households (`Domain/WealthDistribution`) at each
  Insee "Histoire de vie et Patrimoine" survey (next one: 2026-2027); world pyramid
  (`Domain/WorldWealthDistribution`) at each UBS Global Wealth Report (June or July), with the ECB rate of
  December 31; allocation benchmark (`Domain/RecommendedAllocation`) if Avenue des Investisseurs revises it.
- **Symfony Routing PCRE2 warning** on Debian 13 ("branch too long in variable-length lookbehind assertion" when
  warming up the cache, no effect on routing): to report upstream, and to check again after each update.
- **Renovate's held updates**: Buildah 1.46 (adapt the `container-image` job to its fuse-overlayfs mount), Python
  3.15 (wait for the wheels Semgrep needs), OpenSSF Scorecard 5.7 (404 on the self-hosted GitLab): lift each hold
  once its blocker is gone.
- **Infrastructure side**: external probe (Uptime Kuma, Prometheus) wired to `/sante`; automated deployment from the
  CI archive.
- **Tooling**: PHPStan on the tests with its own configuration; development launcher and versioned git hooks; a
  dedicated public demo instance; YunoHost and Synology packages; a public mirror of the repository, for the public
  OpenSSF Scorecard service.

Several ideas come from [Carbure](https://github.com/ltoinel/Carbure) (self-hosted spending tracker, PHP, MIT
license), noted on 2026-11-13.
