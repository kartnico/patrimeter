# Changelog

All notable changes to Patrimeter are recorded here (English translation of `CHANGELOG.md`).
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); numbering:
[semantic versioning](https://semver.org/).

## [3.6.0] - 2026-11-27

### Changed

- **Redesigned Challenges page**: your two statuses at the top (**My level**, **My profile**), a dial of the stars and
  their split by family of challenges, how far the next level and the next stage are, then the entry streak. Each
  challenge shows its thresholds from left to right (earned, next, later), what is already done and what is missing for
  the next star; its rule unfolds. Indicative benchmarks, to adapt to your situation: this is not investment advice.
- **Clearer first start**: a "Step 2 of 4" thread at the top of each screen (account, security, recovery key, first
  accounts), without the misleading "Last step"; "Why two secrets?" tells the backup codes from the recovery key, both
  of which can be printed; the welcome recommends the questions, then offers the pyramid and folds the three other ways
  to start; a family account becomes a real choice there.
- **Family members page**: each member's status for the month in words ("Up to date", "To update"), their new file
  dropped on their card, "Remove" folded under "More actions", and the three monthly steps. Under the choice of the
  view, a line says what it adds up and flags each file to update; "This account" becomes "Family's accounts", and the
  imported balances are counted "of the month" or "carried over".
- CI: the test of the Docker Hub image promotion runs in every full pipeline, before any tag, and no longer only at
  publication time.

### Fixed

- Docker Hub publication: the job reads the version of the tested image with Buildah and shares its credentials with
  Skopeo. In 3.5.0, the registry refused it (403): the 3.5.0 image was not published, 3.6.0 replaces it.

## [3.5.0] - 2026-11-27

### Installation

- Docker Compose: use the Compose file from this release for the recommended configuration. The HTTP probe applies
  to the web service; a failed backup or verification is retried after one hour.
- Linux: reinstalling the same archive preserves data permissions and existing secrets.

### Changed

- Updated the affected Symfony components to 7.4.24, which includes security fixes.
- The account list decrypts only the latest applicable statement for each displayed balance.
- An older Docker rebuild can no longer replace a newer build of the same release.

### Fixed

- An account's opening date can no longer be moved beyond the month of an existing statement.
- Copying a property to an earlier month preserves the initial ownership share for the property and its prorated loan.
- Viewing challenges or reading the API no longer consumes the star announcement for the next monthly entry.
- On Linux, the 3.5 updater keeps the last confirmed entry in a verified snapshot outside backup rotation.
  Rollback rejects a missing or corrupt snapshot before taking the site offline.
- Monthly reminders can be retried after a mail transport failure.
- The calendar allows returning to the current month from next month; month and year buttons retain an accessible
  name that identifies their field.

## [3.4.1] - 2026-11-26

### Installation

- Compose: set `PATRIMETER_IMAGE=docker.io/kartnico1/patrimeter:latest` in the Compose `.env` file.
- Opt-in publication of releases to Docker Hub, GitHub and GitHub Pages from private GitLab CI.
- Daily system rebuilds and automatic releases for verified stable Composer security fixes.

## [3.4.0] - 2026-11-24

### Installation

- **Demonstration mode off by default**: to keep the "Try the demo" button, add `DEMO_MODE=true` to the settings
  ([Settings](docs/reglages.en.md#turning-the-demonstration-mode-on)).
- **On Linux, nginx configuration to take again** (bank statements and CSV import up to 2 MB, installation wizard);
  with Docker, nothing to do:

  ```sh
  # nginx configuration of the application, that of the new version, then reload of nginx
  install -m 0644 /srv/patrimeter/docs/nginx/patrimeter-app.conf /etc/nginx/snippets/patrimeter-app.conf
  nginx -t && systemctl reload nginx
  ```

### Added

- **Installation wizard** (`/installation`): on an instance still without any account (demonstration accounts
  aside), the sign-in page leads to it; it checks the configuration as seen from the browser (HTTPS, address of the
  site `DEFAULT_URI`, sending emails, administrator alerts), each point saying what to fix, then creates the first
  account (address deemed confirmed), signed in and taken to its authenticator app. Only one account can be created
  this way, even by two visitors at once; afterwards, the page no longer exists. The `app:user:create` command
  remains available.
- **Custom indicators**: your own totals, pyramid levels and chosen accounts (each account counted once), followed
  every month as the net worth, with their share of the net worth if you wish; **Indicators** page (values of the
  month, change over a month, last twelve months of each), **My indicators** box on the Net worth page. Encrypted as
  the other data, they follow the export (format 4, files 1 to 3 still import) and the key rotation
  ([indicators](https://kartnico.github.io/patrimeter/en/utilisation/indicateurs/)).
- **Reading a bank statement** on an account page: the file exported by the bank (OFX or CAMT.053) gives its closing
  balance and its date, which fill in the statement form, to check then save; nothing is saved nor kept before. QIF
  or CSV file (without a balance), in a foreign currency or with several accounts: refused with a message that says
  what to export; XML document without DOCTYPE, nor network access
  ([accounts](https://kartnico.github.io/patrimeter/en/utilisation/comptes/#reading-a-bank-statement)).
- **"Settings" page** in the documentation: changing a setting at any time, on Linux as with Docker (file to edit,
  service to restart), with examples (demonstration mode, email server) and the main settings, their default value
  and their effect.

### Changed

- **Fully automatic updates**: every Renovate merge request, major versions included, is merged on its own as soon
  as its pipeline is green; no more dashboard nor approval; the lock file of the PHP dependencies is refreshed every
  day. doctrine-bundle 3, which requires PHP 8.4, is held until 4.0.0 ([docs/ci.md](docs/ci.md#renovate), in
  French).

### Fixed

- **Twig 3.31.1**: security fixes published on 23 and 24 November 2026 (automatic escaping, sandbox), found by
  `composer audit`.
- **CSV import above 64 KB**: nginx refused it (error 413) although the application accepts 1 MB; it now follows the
  limit of the data import (checked by the `deployment` job).

## [3.3.0] - 2026-11-24

A quality release: no change to the screens or the database, nothing to do when updating.

### Added

- **SonarQube analysis** in the CI (`sonarqube` job): bugs, vulnerabilities, code smells, duplication and PHP
  coverage, against the server's quality gate; the job fails when the gate is not passed. On `main` and in the full
  pipelines (or on demand, `SONARQUBE=1`); server and token in the `SONAR_HOST_URL` and `SONAR_TOKEN` variables;
  SonarScanner pinned by its version and checksum, kept up to date by Renovate
  ([docs/ci.md](docs/ci.md#sonarqube), in French).

### Changed

- **Changelog**: versions only, without an "Unreleased" section; changes are written under the version they prepare.
- **Documentation**: "Linux" wherever the reader installs Patrimeter (README, home page, requirements, comments of
  the nginx and php-fpm files); Debian is only named for the container image and the CI, which start from it.
- **Renovate configuration migrated** (`dependencyDashboard` becomes an object), as its dashboard asked; checked by
  `renovate-config-validator --strict`.

### Fixed

- **Dependable load test**: k6 keeps the database, the sessions and the cache in memory and turns OPcache on as in
  production; it measures the application, no longer the stalls of the runner's disk, which made it fail without any
  code difference (p95 of 0.85 to 1.5 s for a 25 ms median). Same thresholds.
- **Findings of the first SonarQube analysis**: regular expressions with a non-linear running time rewritten
  (thousands separators, address of a Mastodon instance, base64url encoding); label of the hidden username field
  meant for password managers; the Data page reuses the shared identity confirmation form. The rules that contradict
  the W3C and the WCAG (scrollable areas reachable from the keyboard, charts described by `role="img"`, focus on the
  first field of single-form pages) are set aside in `sonar-project.properties`, each with its reason.

### Removed

- **"Required Debian packages" page**, orphaned and outdated since the archive installation: the requirements are in
  [installation.md](docs/installation.en.md#1-requirements), the image's packages in `docker/Dockerfile`.
- **3.0.0 proposals** (`propositions/`), delivered: they remain in the git history.

## [3.2.0] - 2026-11-24

### Installation

- **Two supported installations**: Docker (`compose.yaml`) or Linux, from the archive of a version; the installation
  from git is no longer documented. An installation made from git moves to the archive once: download the archive of
  this version and its checksum into `/tmp`, then, as root:

  ```sh
  # New patrimeter-update, taken from the archive
  ARCHIVE="$(ls /tmp/patrimeter-v*.tar.gz | sort -V | tail -n 1)"
  tar -xzf "$ARCHIVE" --strip-components=1 -C /tmp --wildcards '*/docs/update/patrimeter-update.sh'
  install -m 0755 /tmp/docs/update/patrimeter-update.sh /usr/local/sbin/patrimeter-update
  # Update from the archive; the old folder (with .git) stays in /srv/patrimeter.previous
  patrimeter-update "$ARCHIVE"
  ```

### Added

- **Property-based tests** (fast-check, a development tool): for any amount typed in the monthly entry, the field
  keeps exactly that amount once formatted, and the level total shows it.

### Changed

- **"Technologies and licenses" page** in the documentation: every project used, with its official site, its role and
  its license.
- **Same titles from one page to another**: the page's name in bold, then the month in grey ("Update November 2026" on
  the three tabs of the monthly update, as "Budget November 2026"); the head buttons in the same format.
- **Months and years picked from a calendar**: the month fields (copy of another month, an account's balance, goals)
  look like the month picker of the page heads ("Oct 2026"); the household's birth years are picked from a calendar
  of years. Without JavaScript, the text field remains.
- **Saver style offered from the first steps**: the "First steps" card asks for it as long as none is chosen.
- **Clearer challenges**: score in clear ("1 of 3") and, on each card, the ladder of its stars, from the first at the
  bottom (earned) to the last at the top, each threshold saying what it asks; total "8 stars of 28" and ladder of the
  six levels; the saver style is chosen in My profile; the passkey leaves the **First steps** (five steps), it stays in
  the **Protected account** challenge. Always with the notice "Indicative benchmarks, to adapt to your situation: this
  is not investment advice."
- **Level of the challenges in the net worth box** ("★ Level 4: Far-sighted · 15 stars"), with a link to the
  Challenges page; worked out from the stars already earned, without slowing the page down.
- **No more "My assets" menu**: the **Accounts** and **Real estate** buttons are at the top of the Net worth page,
  next to **Share**; the tax household opens from the Budget page.
- **Monthly update on the right of every menu**, always put forward (bold, outlined); red with a dot as long as the
  month is not entered.
- **Budget page aligned with Savings goals**: "Budget" title with the month in grey, **Set up tax household and
  categories** button at the top, a single **+ Enter the budget** button when the month has no budget.
- **`patrimeter-update` installs an archive** downloaded by the administrator: checksum checked, database backed up,
  new version installed next to the old one, migrations, restart; `patrimeter-update --rollback` brings back the
  version and the database from before. No more git, Composer or Packagist access on the server.
- **Docker**: the database is backed up on every new version, even without a migration (`var/backups-before-update`,
  the latest three); `compose.yaml` no longer builds the image, it pulls it from the registry.
- **Installation and update documentation rewritten**: requirements at the top of the page, commented commands ready
  to copy, without any version number to keep up to date; Release with direct links to the archive, its checksum,
  `compose.yaml` and `patrimeter.env.example`.
- **Security policy**: direct link to report a vulnerability (confidential issue), response and fix times,
  coordinated disclosure.

### Fixed

- **Delivery archive**: files and folders are no longer group-writable (`git archive` created them as 775 and 664).

## [3.1.0] - 2026-11-24

### Installation

- **Migrations**: a table for the "to refine" balances and a column for the savings rate aimed at; they apply with
  the usual update. No other address nor variable: nginx and `.env.local` do not change.

### Added

- **Challenges in the API and the MCP service**: `GET /api/v1/challenges` and the `get_challenges` tool give the
  stars, the level, the saver stage and style, the entry streak and each challenge with its thresholds, with the "not
  investment advice" notice; described in `docs/openapi.yaml`.
- **Entry streak in the monthly reminder**: with the challenges on, the email gives the current streak and what
  entering the month would bring to it (a new star or the next threshold), with the "not investment advice" notice;
  worked out from the months entered and the stars earned alone, still without any amount.
- **Simple questions for beginners**: a fifth choice at the first sign-in, one question at a time in everyday words,
  with amounts by ranges, an exact amount or "No"; "Why this question?" gives the level of the pyramid concerned; the
  last question, optional, chooses the saver style.
- **"To refine" balances**: the first balances created by the welcome pages (pyramid, express entry, questions) are
  marked in the monthly entry until they are saved again (`approximate_statements` table, without any amount).
- **"Savings rate kept" challenge**: the months in a row where the budget plans savings of at least the rate the user
  chooses themselves on the challenge's card (from 5% to 50%, no rate suggested by default); three more stars (29 in
  all), also in the API and the MCP.
- **Stars and level on the report**: **Show my stars** adds them to the card and to the shared picture, on request
  and without any amount (own net worth, challenges on).

### Changed

- **More compact monthly update**: an "=" button (or the `=` key) under each field takes the previous month's balance
  and moves to the next account; a level whose balances are all entered this month is folded, with its total; the
  change in net worth from the previous month follows the typing.

- **Updating on a single page** (`docs/mise-a-jour.en.md`): `patrimeter-update`, checking and rolling back, commands
  commented and ready to copy; the installation points to it. Shorter README.

### Fixed

- **k6 alone on the runner again**: it now waits for every job of the test, e2e and security stages (Nuclei, added
  in 2.25, scanned the application during the measurement and made the p95 threshold fail without any error), and
  the publication of the documentation waits for it; checked by `PerformancePipelineTest`.
- **Net worth page as fast as in 2.26**: the "First steps" card only measures its six steps, with simple queries,
  instead of every challenge (goals, safety cushion, streaks); it is not worked out at all once the six steps are done
  or the card is hidden. The other challenges are measured on the Challenges page and after the monthly entry.

## [3.0.0] - 2026-11-23

Guided first run and challenges: a new user builds their pyramid in a few minutes, then stars reward their good
habits. Indicative benchmarks, to adapt to your situation: this is not investment advice.

### Installation

- **Migration**: two tables for the challenges and stars; they apply with the usual update.
- **nginx**: `/defis` and `/bienvenue` join the list of the application's addresses in
  `docs/nginx/patrimeter-app.conf` (take the file again; it is part of the container image). Without it, these pages
  answer 404.
- **Configuration**: new optional variable `GAMIFICATION` (`true` by default; `false` removes the challenges from the
  whole instance).

### Added

- **First accounts** (Net worth page, as long as no account exists): four ways to start. **Build my pyramid** shows the
  empty pyramid and its six levels: each level offers its common accounts (current account, Livret A, LDDS, PEA, life
  insurance…) with today's balance, and takes its colour once filled. **Express entry** gathers all these accounts on
  one page. The accounts are created together, opened this month with their balance, all or nothing; a loan is entered
  by its outstanding principal. The CSV import and the creation account by account remain.
- **Challenges and stars** (**Challenges** menu): eight challenges of good habits (first steps, entry streak with one
  forgotten month forgiven a year, wealth up to date, safety cushion, no consumer loan, goal under way, budget kept up
  to date, protected account), up to three stars each; a star earned is never lost. The stars give a level from 1 to 6.
- **Saver profile**: a stage computed every month (Discovery, Organized, Protected, Serene, Builder) and an optional
  style (Ant, Project, Home, Horizon), which only changes the order of the challenges. Neither return, nor share of a
  level, nor product, nor ranking between users: an architecture test checks it.
- **"First steps" card** on the Net worth page: six steps with a link to each, one star each, can be hidden.
- **Stars announced** after the monthly entry.
- **Challenges can be turned off** by each user (Challenges page or My profile), their stars kept.

### Changed

- **Navigation**: the **Challenges** entry follows Monthly update; the bottom bar of a phone adapts to the number of
  entries.
- **Net worth page without any account**: the four ways to start replace "Create a first account"; then, the "First
  steps" card replaces "Complete your net worth", which stays if the challenges are turned off.
- **Renovate**: full image (Node, PHP and Composer already installed), without any GitHub token, every day at 3:00; the
  CI's Node image is looked up on Docker Hub; Buildah held below 1.44 and endroid/qr-code below 6.1 (PHP 8.4 needed);
  release notes not fetched.
- **Proposals of 3.0.0** (`propositions/3.0.0/`): ideas and mockups of the first run, the challenges and the interface.

### Fixed

- **Test of the demonstration's writes in bursts**: it takes the real elapsed time into account (unstable on a slow
  runner).
- **Renovate**: an update of the PHP dependencies also writes `THIRD-PARTY-LICENSES.md` again (otherwise its merge
  request failed on the licenses test).
- **CI**: Python stays at 3.14 (Semgrep cannot be installed with 3.15 yet) and OpenSSF Scorecard at 5.6 (5.7 answers
  404 on our GitLab); Renovate no longer offers them.

## [2.26.1] - 2026-11-18

### Changed

- **Tax household**: the number of adults (one or two) and the number of children (none to 12) are chosen in two
  lists, and the form only shows the rows of these people, each with their first name and year of birth. The first
  names of the children are no longer entered separated by commas; a child keeps their budget items when their first
  name changes, and lowering the number of children removes the last ones of the list. Without JavaScript, all the
  rows show and only those up to the numbers chosen count.
- **Not financial advice**: the README and the documentation home say so; 3.0.0 roadmap: guided first steps (tax
  household, accounts, properties) and a touch of gamification to support savers.

## [2.26.0] - 2026-11-18

### Added

- **CSV import of accounts, loans and properties** (Your data, "Create accounts from a CSV file"): one line per
  account (name, type, opening date; optionally balance and its month, level, nature, share of a property, property
  and mode of a loan, closing date), added to the existing accounts, all or nothing, with the line at fault in the
  error message. Model to download in the interface language; spreadsheet files accepted as they are (separator `;`,
  `,` or tab, UTF-8 or Windows-1252, columns in French or English, `DD/MM/YYYY` dates, type given by its code or its
  label). Format documented, with the list of the type codes (documentation, "CSV import").
- **Tax household**: the budget speaks of the tax household ("Two adults", without "a couple") and asks for the
  **year of birth** of each person; a child who is 16 in the year can have **incomes of their own**, entered with
  those of the adults and counted in the household's income (budget flow, export, family account).
- **"My assets" menu**, next to Monthly update: Accounts, Real estate, Tax household and categories.
- **Current month of the top bar** clickable: it goes back to the current month, on the same page when it shows a
  month.
- **First steps**: the empty Net worth page offers to create an account or a property (or the CSV import), then
  guides towards what is missing as long as an account and a property do not exist; the Budget part of the monthly
  update, without a tax household, says so with a single button, **Create my tax household**.
- **Net worth pyramid**: hovering or clicking a level highlights its lines in the other boxes (detail by level,
  split of the investments, since the start of the year), and a hovered line highlights its level; a click keeps the
  highlight, Escape removes it.
- **Language of the emails** in the profile: French, English, or that of the interface at the time of sending
  (default); it applies to all the emails, the monthly reminder included.
- **Backup codes and recovery key**: a **Copy the codes** button (one code per line) and a **Copy the key** button,
  to paste them into a password manager (shown when the browser allows copying; confirmation announced to screen
  readers).
- **Renovate every day** (schedule `RENOVATE=1`, job `renovate`, `renovate.json`): merge requests updating the PHP
  libraries and their dependencies (within the constraints of `composer.json`, lock file refreshed on Mondays), the
  Node tools, the images and tools of the CI (Composer, k6, Nuclei, OpenSSF Scorecard, Docker Compose, Semgrep,
  Zensical, Renovate itself) and the Debian image of the container. Versions published for at least 14 days, a known
  flaw without waiting; major versions after approval on the dependency dashboard; SHA-256 checksums and tag of the
  CI image recomputed by `ci/renovate-post-upgrade.sh`. This pipeline only runs Renovate (`RenovatePipelineTest`).
- **3.0.0 roadmap**: family mode to rework.

### Changed

- **Security alerts by email**: after an action that may have shut the user out of the account (password changed or
  reset, authenticator app replaced, account deleted), the email no longer advises to change the password from My
  account, a dead end for someone who can no longer sign in: it asks to tell the server administrator.
- **Without any account**, the Net worth and Monthly update pages stay on the current month (no arrows nor
  selector), the one where the first accounts are created.
- **Goals**: the "+ Set a goal" button, when no goal exists, becomes "View your goals".
- **Without any account**, the monthly update no longer offers to copy the balances of another month.
- **Months written with a capital** in French when they stand alone (top bar, titles, columns, calendar), as in
  English; within a sentence, the French lower case stays.
- **CSV import**: the model is downloaded with a button, shown before the form.
- **Logo of the header and of the report** aligned with the site icon (favicon): the same triangle with a light tip,
  in the same colours, the report image included.

### Removed

- **Home-made Monday dependency bot** (job `dependency-update`, `ci/update-dependencies.sh`, `tools/npm-update.mjs`,
  `tools/dependency-mr.mjs`): Renovate replaces it.

### Installation

- A migration (language of the emails): applied by the usual update. On the GitLab project: create the daily Renovate
  schedule (*Build > Pipeline schedules*, branch `main`, `0 5 * * *`, variable `RENOVATE` = `1`,
  [docs/ci.md](docs/ci.md#renovate), in French); the existing `DEPENDENCY_BOT_TOKEN` token is used by Renovate;
  `GITHUB_COM_TOKEN` (GitHub token without rights) is optional.

## [2.25.2] - 2026-11-18

### Fixed

- **Carbure synchronisation**: the address of the MCP service that Carbure shows (`https://…/api/mcp`) was kept as is,
  and Patrimeter called `…/api/mcp/api/mcp`, to which Carbure answers 404: linking failed, with a message blaming the
  token. This address is now brought back to that of the instance, and a link already saved this way works without
  entering anything again. The message now tells a refused token (401, 403) apart from an address without a Carbure
  MCP service (404, 405); a hint under the field says that both addresses will do.

### Added

- **AI agents**: a **Copy** button next to the address of the MCP service and that of the REST API, in "Connect an AI
  agent" (shown when the browser allows copying; "Copied" confirmation, announced to screen readers).

## [2.25.1] - 2026-11-18

### Fixed

- **Budget, "Budget trends" box on a phone**: the column headings ran over each other; they become "Expenses" and
  "Savings" (the introduction already says it is an average per month), and a column heading too long now wraps
  instead of running over its neighbour.

## [2.25.0] - 2026-11-17

Markdown checks of the documentation and Nuclei scan, of the application in the CI and of the deployed instance.

### Installation

- **CI** (developers): `PRODUCTION_URL` (`.gitlab-ci.yml`) gives the address of the deployed instance that
  `nuclei-production` scans on Mondays; empty: no scan. Nothing changes for an installation.

### Added

- **Nuclei** in the CI (ProjectDiscovery, pinned version and checksum, CI tool only): `nuclei` job on the application
  started in the pipeline (HTTP templates of the day, "low" severity and up, without the intrusive templates), report
  sent to the security gate; `nuclei-production` job on the deployed instance, without account and at a reduced rate,
  on Mondays and on demand (`PRODUCTION_SCAN=1`), which fails on a High or Critical finding without ever blocking a
  release ([CI](docs/ci.md), in French).
- **Markdown checked** in the fast pipeline: markdownlint (`.markdownlint-cli2.jsonc`, tuned for Zensical) on the
  repository, `docs/` and `documentation/`; the anchors of the links between pages of the site checked with Zensical's
  rule (`DocumentationLinksTest`).

### Fixed

- **Nested lists of the CHANGELOG and of the Accessibility page**: indented by 4 spaces, they finally show nested in
  the documentation site.

### Security

- **Development tools**: `re2js` forced to 2.9.1 (`overrides` of `package.json`) for advisory GHSA-gg6h-894w-4m9c of
  markdownlint; the picomatch advisory without a fix stays excluded until 1 December, `markdownlint-cli2` included.

## [2.24.0] - 2026-11-17

User list for the administrator; registrations never confirmed deleted after 24 hours.

### Added

- **`app:user:list`**: users of the instance (address, creation, address confirmation, second factor, number of
  accounts, last sign-in) and totals, without decrypting anything; `--json` for a script
  ([installation, § 6](docs/installation.en.md#4-database)).
- **Unconfirmed registrations deleted**: the hourly scheduled task erases a registration never confirmed 24 hours
  after the last link sent (the address becomes free again); never an account holding accounts, nor a demo account,
  nor an account without address.

### Security

- **Development tools**: advisory GHSA-fc5q-39xc-vjpf (picomatch, ReDoS), without a fixed version so far, is
  excluded from the gate until 1 December, with its justification (`security/exclusions.json`): it only affects
  Stylelint, Vitest and Vite, never production.

## [2.23.0] - 2026-11-17

Goals view redesigned; recognizable API tokens, checked origins of the MCP service, trusted browsers.

### Installation

- **Optional**: `MCP_ALLOWED_ORIGINS`, origins of web pages allowed to call the MCP service besides the site
  (separated by commas without spaces; empty by default) ([installation, § 3](docs/installation.en.md#3-configuring)).
  Nothing to do for a client outside a browser (Claude Code, script).
- **Database**: a migration adds the `trusted_browsers` table (run by the update).

### Added

- **Trusted browsers**: on the code screen, "Trust this browser for 30 days"; the next sign-ins on this browser only
  ask for the password. Listed in My account > Sign-in and security, removed one by one or all together; a new
  password or signing out the other sessions removes them too; sign-in without code recorded in the log.
- **MCP service origins**: a request whose `Origin` header is neither the site nor an origin of `MCP_ALLOWED_ORIGINS`
  gets `403`, even before the firewall (MCP specification, *DNS rebinding*).

### Changed

- **Goals view**: progress ring with a "where you should stand" mark, status pill, key figures as tiles (current
  amount, target, effort needed, pace observed), interactive projection chart (shaded area, bubble of the amounts on
  hover, axis zoomed on the data, target as a thin line); a sentence under the chart repeats the figures.
- **Recognizable API tokens**: `patrimeter_` followed by 24 hexadecimal characters, `_` and 43 characters; a secret
  detector spots a token published by mistake (`patrimeter-api-token` rule of `.gitleaks.toml`). The `pm1.…` tokens
  already issued stay valid until they expire.
- **Roadmap**: first-run setup wizard, single-file backup, bank statement import and custom indicators move to 3.0.0.

## [2.22.0] - 2026-11-17

Simpler goal form (lists, fields according to the choices, start month), steadier guided tour, hour and sender of the
monthly reminder; faster CI (ready-made image, reduced tag pipeline).

### Added

- **Start month of a goal**: this month by default, or a past month for a goal already under way; the value measured
  in that month is the starting point (computed again if the start month or what the goal measures changes).
- **Monthly reminder**: it leaves on the chosen day from 9:00 (the instance's time), never at night any more; the
  section of "My account" gives the hour and the sender's address (`MAIL_FROM`, already visible in every email).
- **Demo**: "Household and categories" link in the account menu, next to "Accounts" and "Real estate".
- **Recommendations**: icon before "Pyramid", as before "Emergency savings".

### Changed

- **Goal form**: "Target" and "What the goal measures" become lists; only the fields of the choice made are shown
  (amount or number of months of spending, level or accounts). Without JavaScript, all of them stay shown with the
  case they are for.
- **Guided tour**: the "Previous" button is always there (greyed out at the first step), so "Next" no longer moves from
  one step to the next.
- **CI** (developers): the PHP jobs start from a **ready-made image** (PHP 8.3 to 8.5 from Surý, pcov, Composer),
  built by the `ci-php-image` job only when what makes it up changes, and on Mondays; the **pipeline of a tag** no
  longer replays the checks already passed on `main` (`.not-tag` rule): it builds, analyses and publishes the release
  ([CI](docs/ci.md), in French). Every pipeline checks the syntax and runs all the PHPUnit suites on **PHP 8.3, 8.4
  and 8.5** (8.4 now linted too), a rule locked by `PhpVersionsCiTest`.

## [2.21.0] - 2026-11-16

OpenSSF Scorecard on the private repository; documentation, README and roadmap reviewed.

### Installation

- **CI** (developers): create once the read-only token of OpenSSF Scorecard and the `SCORECARD_TOKEN` variable
  ([CI, OpenSSF Scorecard](docs/ci.md#openssf-scorecard), in French). Nothing changes for an installation.

### Added

- **OpenSSF Scorecard** in every pipeline of `main`, the Monday one included: rating of the project's security
  practices (branch protection, review, tests, dependency updates, pinning…) read with a read-only token; report in
  the artifacts, **badge** in the README ("non mesuré", not measured, without a token).

### Changed

- **Documentation reviewed**: home and quick start (goals, trends, demo, entry reminder), account menu
  (Documentation), scheduled tasks, architecture, CI and workflow; README and roadmap up to date (the "Onboarding and
  data entry" version becomes 2.22.0).

## [2.20.0] - 2026-11-16

Goals in the main navigation and in the monthly update, monthly update recalled in the navigation; accounts and
properties reachable in the demo, waiting time for a new demo account, link to the documentation, security and testing
badges.

### Installation

- **Optional**: `DOCUMENTATION_URL` (address of the user documentation, the project's by default; empty: no link) and
  `DOCUMENTATION_ON_LOGIN` (`true` by default: link on the sign-in page)
  ([installation, § 3](docs/installation.en.md#3-configuring)).

### Added

- **Documentation** in the account menu and on the sign-in page, in the language of the interface.
- **Badges** of the README: `composer audit`, end-to-end tests (Playwright), SAST (GitLab and Semgrep), Semgrep.

### Changed

- **Goals** in the main navigation bar, between Budget and Monthly update: the Goals page shows where each one stands
  (status, chart, last months); they are no longer on the wealth page nor in My account.
- **Goals entry** in Monthly update, **Goals** tab, next to Net worth and Budget.
- **Monthly update highlighted** in the navigation as long as no balance of the current month is entered (in bold,
  with a dot; also said to screen readers).
- **Demo**: the "My account" menu leads to the accounts and the properties (instead of the profile, closed to the
  demo); the tabs only show what is open to it: adding an account or a property is therefore possible.
- **Demo unavailable**: the message tells how long to wait (creation limit reached, or demo full until the first
  running account ends).

## [2.19.0] - 2026-11-16

Goals in months of spending and compared with the budget, projection chart; trends over 3, 6 and 12 months; order of
the budget; monthly email reminder; goals and budget in the API and the MCP.

### Installation

- **Migrations**: order of the budget's categories and items, monthly reminders; they are applied with the usual
  update.
- **nginx**: `/rappel` (unsubscribe link of the emails) joins the list of the application's addresses in
  `docs/nginx/patrimeter-app.conf` (take the file again; it is part of the container image).
- The email reminder goes through the existing scheduled tasks and sends nothing without an SMTP server.

### Added

- **Goals in months of spending**: the target follows each month the spending (needs and wants) of the budget.
- **Savings planned in the budget** compared with the effort needed, for the savings items tied to the goal's
  accounts.
- **Projection chart** of each goal: reached, planned trajectory and projection at the current pace, told apart by the
  style of the line and described in text.
- **Trends** of the net worth (wealth page) and of the budget (average spending and savings, budget page) over 3, 6 and
  12 months, compared with the period before.
- **Order of the budget's categories and items**, with arrows in Household and categories.
- **Monthly email reminder** (My account > Profile), optional: on the chosen day, if the month's balances are not
  entered, without any amount; unsubscribe link without signing in. In the application, from the 20th, the wealth page
  points out the month's balances not entered.
- **API and MCP**: goals (`GET /api/v1/goals`, `list_goals` tool) and budget of the month (`GET /api/v1/budget`,
  `get_budget` tool), read-only.

## [2.18.0] - 2026-11-16

Savings goals followed every month, guided tour of the demo.

### Installation

- **Migration**: new table of the savings goals; it is applied with the usual update.
- **nginx**: `/objectifs` joins the list of the application's addresses in `docs/nginx/patrimeter-app.conf` (take the
  file again; it is part of the container image).

### Added

- **Savings goals** (My account > Goals): an amount to reach by a date, measured on the net worth, one level of the
  pyramid or chosen accounts, from the value of the month of creation. Every month, the effort still needed is
  compared with the pace of the last six months: **Reached**, **Ahead**, **On track**, **To watch**, **Behind** or
  **Too early to tell**, with what is missing each month and the month the goal would be reached at this pace; status
  of the last months.
- **"Savings goals" card** on the dashboard, and a message after the monthly entry for each goal whose status
  changes.
- **Export and import**: the goals travel with the data (format version 3; versions 1 and 2 can still be imported).
- **Demo**: two example goals, one behind, the other ahead, and their step in the tour.

- **Guided tour of the demo**: it starts with the demo account and presents, in thirteen steps and over several pages,
  the net worth, the goals, the pyramid, the history, the monthly entry, the accounts, the properties, the budget and
  the report,
  outlining the element it talks about. It can be stopped at any time and started again from the banner, or resumed
  at the step left; it also works without JavaScript (without the highlighting).

## [2.17.0] - 2026-11-16

Demo mode to try Patrimeter without an account, arrow on the collapsible budgets.

### Installation

- **Migration**: new table of the demo accounts; it is applied with the usual update.
- **Demo mode enabled by default** (`DEMO_MODE=true`): the sign-in page offers "Try the demo". On a private
  instance, add `DEMO_MODE=false` to `.env.local` (or to `patrimeter.env` for the container)
  ([installation, § 3.2](docs/installation.en.md#3-configuring)).
- **nginx**: `/demo` joins the sign-in forms in `docs/nginx/patrimeter-app.conf` (take the file again; it is part of
  the container image).

### Added

- **Demo mode**: from the sign-in page, **Try the demo** opens a temporary account at once, without registration,
  password or code, filled with fictitious data in the visitor's language (three years of wealth, a main residence
  held at half with its loan, the budget of a household of two adults and one child). A banner reminds when it is
  deleted: after 2 hours, or as soon as you sign out, with all its data.
- **Safeguards of the demo**: only the wealth, the monthly entry, the accounts, the properties, the budget and the
  export are reachable (no security settings, no API, no import, no family account, no Carbure, no email sent);
  2,000 rows of data per account, forms of 32 KiB, limited writes; 50 accounts at a time, 5 creations per IP address
  and per hour, 60 in all; expired accounts deleted by the scheduled tasks.

### Changed

- **Budget**: an arrow before "Shared budget" and each "Own budget of…" shows that they expand; it turns when the
  detail is open.
- **Data of the end-to-end tests and of the screenshots**: the same as the demo's.

## [2.16.0] - 2026-11-16

Open sessions visible and signed out, passkeys (FIDO2), encrypted copy of the backups off the server and monthly test
restore, optional check of the latest version, third-party licenses, documented workflow; Monday rebuild of the image
fixed.

### Installation

- **Migration**: new table of the open sessions; it applies with the usual update.
- **Debian server**: `patrimeter-update` installs and enables the monthly test restore
  (`patrimeter-restore-check.timer`). From the archive, install it as in
  [§ 1 of the backup page](docs/backup.en.md#1-setting-up).
- **Optional**: encrypted copy of the backups off the server (`apt-get install age`, `PATRIMETER_BACKUP_AGE_RECIPIENTS`
  and `PATRIMETER_BACKUP_COPY` variables, [backup, § 2](docs/backup.en.md#2-copying-off-the-server)); daily check of the
  latest version (`UPDATE_CHECK_URL`, `UPDATE_CHECK_TOKEN`, [monitoring](docs/supervision.en.md#checks)). Without them,
  nothing changes.
- **Container**: the image contains `age`; the same optional variables go into `patrimeter.env`.

### Added

- **Open sessions** (My account > Sign-in and security): a box lists, like the sign-in log, every signed-in device
  (sign-in, last activity, IP address, browser), the current session marked. Once the identity is confirmed,
  **Sign out** closes another session, **Sign out my other sessions** closes them all except yours; the device goes
  back to the sign-in page at its next request.
- **"Update my household" link** in the budget entry, next to adding an item and an account.
- **Encrypted copy of the backups off the server**: the archive encrypted with age for public keys only, then handed
  to a copy command of your choice (`rsync`, `rclone`…); a copy in clear is refused, a missed copy fails the backup and
  warns the administrator.
- **Monthly test restore** of the latest archive (`patrimeter-restore --check-latest`), email alert on failure; in the
  container, after each backup.
- **Daily check of the latest version**, disabled by default: a more recent version is reported by email to the
  administrator, by `/sante` and by a metric.
- **Third-party licenses**: `THIRD-PARTY-LICENSES.md` lists the third-party code shipped (Composer dependencies,
  Chart.js) and its license, kept up to date by a test.
- **Documented workflow** ([docs/developpement.md](docs/developpement.md), in French): branches, merge requests,
  pipelines and their variables, publishing a version, Release and image, to follow by hand.
- Quality **badges** in the README.

### Changed

- **Passkeys**: the interface says "passkey (FIDO2)" everywhere; in "Passkeys (FIDO2) and security keys", the reminder
  to confirm your identity before adding or changing a device is highlighted.
- **Dependencies** of 16/11 (Doctrine DBAL 4.6, PHPUnit 12.5.44, Psalm 7.7…).
- **Weekly dependency merge request** written in English, like the rest of the project.

### Fixed

- **Monday image**: rebuilding the latest version failed since `Dockerfile` moved into `docker/` (checked-out version
  that keeps it at the root).
- **Theme and language preferences**: their one-year lifetime counts in real time; they no longer depend on the
  application's clock.
- **Documentation**: number of account types, remaining mentions of sign-in with an external account.

## [2.15.0] - 2026-11-16

Hardening and tidying: nginx allowlist and limits on the sign-in forms, self-updating container, Docker files moved to
`docker/`, roadmap by version; report as a picture to share on social networks; next month shown on the Net worth
page.

### Installation

- **Debian server, nginx**: two files to take again ([installation, § 8](docs/installation.en.md#6-nginx)):
  `docs/nginx/patrimeter-limits.conf`, new, to copy into `/etc/nginx/conf.d/`, and `docs/nginx/patrimeter-app.conf`,
  to copy again into `/etc/nginx/snippets/`; then `nginx -t` and `systemctl reload nginx`. Without the first one,
  nginx refuses the second.
- **Container**: `Dockerfile` and `compose.yaml` are now in `docker/`, with the `patrimeter.env.example` template; the
  `docker compose` commands are run from that folder, where `patrimeter.env` also goes
  ([container](docs/docker.en.md#2-installing)). The image built by the CI keeps its address.
- **Boolean variables** (`REGISTRATION_ENABLED`, `API_ENABLED`, `MCP_ENABLED`, `SHOW_VERSION`): documented with `true`
  and `false`; `1` and `0` are still accepted.

### Added

- **Report as a picture, to share**: on a phone, the report card fills the whole screen; below it, Patrimeter turns it
  into a picture (1080 × 1350 PNG, in the browser) to share through the phone's share sheet, save, or post on
  Mastodon (any server), X (Twitter), Facebook, WhatsApp or Instagram. With amounts, a warning reminds that a posted
  picture makes them public. Patrimeter sends nothing.
- **nginx allowlist**: only the application's addresses reach PHP, any other gets a 404 error from nginx; HTTP methods
  limited to GET, HEAD and POST outside the API and the MCP; sign-in, registration, forgotten password and unlock
  forms limited to 30 submissions per minute and per address (429 error beyond). A test checks that every route of
  the application is covered.
- **Global registration cap**: 30 per hour, all addresses together, on top of the per-address limit; a script that
  changes address no longer creates accounts in bulk.
- **Self-updating container**: before applying the migrations of a new version, the `app` container backs up the
  database in the data volume (`var/backups-before-migration`, latest three); `scheduler` and `backup` wait until
  `app` has prepared the new version.
- **Roadmap** (`ROADMAP.md`) by version, the structural changes in 3.0.0; project quality badges on GitLab.

### Fixed

- **Next month on the Net worth page**: a month entered in advance was not offered in the month picker; it now is,
  and saving the update leads to it.
- **Budget entry**: the "Income for the month" title is outside its box, like the budget titles.

### Changed

- **Tidying**: Docker files in `docker/`, list of improvements (`TODO.md`) replaced by the roadmap; comments of all the
  code in English and reviewed, code unused since the removal of external accounts deleted.
- **Documentation**: last mentions of sign-in with an external account removed (API, first sign-in, Debian
  packages, architecture).

## [2.14.0] - 2026-11-16

First public version: sign-in with an external account removed, executable update script, installation and update
documentation without historical cases, translations checked.

### Installation

- **Migration**: the table of external accounts is dropped; it applies with the usual update.
- **Variables removed**: `OAUTH_*` are no longer used; they can be taken out of `.env.local`.
- **Account created by an external provider**, without password: it can no longer sign in directly. On the sign-in
  page, **Forgotten your password?** with the recovery key gives it a password, data kept.

### Removed

- **Sign-in with an external account** (Google, GitHub, OIDC), its linked accounts in My account and the unlock
  passphrase of accounts without password. Patrimeter's OAuth server, used by the AI agents and the MCP service, does
  not change.

### Fixed

- **Update script**: `docs/update/patrimeter-update.sh` (and the backup and restore scripts) are executable as they
  come out of git; `patrimeter-update` is now installed with Patrimeter.

### Changed

- **Installation and update documentation** rewritten for a first installation, without the cases of previous
  versions; README and documentation without external sign-in.
- **Translations**: English texts checked and harmonised, texts no longer used removed.

## [2.13.0] - 2026-11-15

More comfortable updates: next month, budget copy, links to add; second home and tangible alternative assets; whole
type lists on a click; email address to delete the account.

### Installation

- **Migration**: new account types are added (second home and its loan, tangible assets of the Alternative level) and
  the "Gold and precious metals" type becomes "Gold"; it applies with the usual update.

### Added

- **Second home**: new property type, in the Real estate level of the pyramid; its loans have their own type, second
  home mortgage.
- **Tangible assets in the Alternative level**: silver and other precious metals (platinum, palladium), jewellery,
  watches, works of art and collectibles, wines and spirits, car, motorbike; gold keeps its type, now "Gold".
- **Next month**: the net worth update, the budget entry and the Budget page go up to the following month, to prepare
  its balances or its budget in advance; the calendar still marks the current month.
- **Copy the budget of another month**: at the top of the budget entry, the form takes the amounts in force in the
  chosen month; nothing is saved until the budget is saved.
- **Links to add in the updates**: add an account or a property (Net worth part), an item, a category or an account
  (Budget part), in the copy box.

### Changed

- **Lists of account and property types**: a click on the field, or on its new arrow, shows the whole list; typing
  still filters.
- **Account deletion**: the email address of the account must be typed to confirm.
- **Budget**: the "Cost of the children" section only appears once an item is assigned to a child.

## [2.12.0] - 2026-11-15

Sign-in log in the Sign-in and security tab, current month in the top bar.

### Added

- **Current month** in the middle of the top bar, on every page once signed in: in full on a computer
  ("November 2026"), short on a phone ("Nov 2026"), where the Patrimeter name gives way to its logo alone.

### Changed

- **Sign-in log**: it leaves its tab and becomes the last section of the **Sign-in and security** tab of My account;
  the `/securite/journal` address leads there.
- **Monthly and budget update**: the field that receives keyboard focus stays above the save bar stuck at the bottom
  of the screen.

### Fixed

- **Sign-in with an external account** (Google, GitHub, OIDC): the cookie of the exchange with the provider becomes a
  session cookie; its 10 minutes of validity are still checked by the server. Its expiry no longer depends on the
  browser's clock.

## [2.11.0] - 2026-11-15

Three-entry navigation, all the settings in My account, monthly update in two parts, history at the bottom of the
Net worth page, savings of the year, password strength in real time, account deletion.

### Added

- **Since the start of the year**: under the source of the investment split, what was put into or taken out of each
  level of the pyramid since the end of December (or January), and for the net worth: an overview of the savings of
  the year, gains and repayments included
  ([net worth](https://kartnico.github.io/patrimeter/en/utilisation/tableau-de-bord/#since-the-start-of-the-year)).
- **Account deletion**: **Profile** tab of My account, after confirming your identity, a box to tick and a last
  message; the account and all its data are deleted, an email confirms it
  ([My account](https://kartnico.github.io/patrimeter/en/utilisation/securite/#deleting-your-account)).
- **Monthly update in two parts**: **Net worth** (balances of the accounts) and **Budget**, for the same month.
- **Password strength in real time**: at registration, password change, password reset and when choosing an unlock
  passphrase, a bar and a label (from Very weak to Very strong) estimate the strength the way the server does, and
  each requirement (length, without the email address, unpredictable enough, same confirmation) is ticked or crossed
  as you type.

### Changed

- **Main menu**: **Net worth** (formerly Dashboard), **Budget** on its right, then **Monthly update**.
- **My account**: new **Accounts**, **Real estate**, **Household and categories** and **Carbure** tabs; these pages
  keep their address and leave the main menu and the Budget page.
- **History**: at the very bottom of the Net worth page, up to the month shown; the History menu goes away and the
  `/historique` address leads there.
- **Real estate**: same layout as the accounts (one table per property, status of each balance, name of the property
  or of the loan that opens its page, net value at the bottom).
- **My account tabs**: they wrap instead of scrolling, so that all of them stay visible on a phone.

## [2.10.0] - 2026-11-14

Account menu and My account page in tabs, AI agents tab, update from git in one command, footer with copyright
and license, hideable version.

### Installation

- **Update from git with a script**, `patrimeter-update [main | vX.Y.Z]`, like the backup: it checks, backs up,
  sets up the new code then lets the script of the new version run its steps, and records the previous commit for
  rolling back. The first time, run it from `main`
  ([updating](docs/mise-a-jour.en.md)).
- **New optional variable** `SHOW_VERSION`: `0` hides the version at the bottom of the pages (shown by default).

### Added

- **Hideable version**: `SHOW_VERSION=0` hides the Patrimeter version at the bottom of the pages, sign-in page
  included; it is still shown by default
  ([installation § 3](docs/installation.en.md#3-configuring)).
- **Account menu**: the **My account** button opens a menu with **My profile**, **AI agents** (if the API or the MCP
  is enabled) and **Sign out**.
- **My account page in tabs**: **Profile**, **Sign-in and security**, **AI agents** (addresses of the MCP service and
  of the API, tokens and applications) and **Sign-in log**.
- **Footer**: copyright, license (free software under the MIT license) and link to the Patrimeter project, before
  the version.

### Changed

- **Sign out**: it moves into the account menu.
- **Project**: commits, merge requests, `TODO.md` and code comments are now written in English, for an international
  project; the interface and the documentation stay in French and English.

## [2.9.0] - 2026-11-14

Family account, dashboard recommendations, budget compared with net worth in charts, actual spending read from
Carbure; API and MCP disabled by default.

### Installation

- **REST API and MCP service disabled by default**: if you use them, add `API_ENABLED=1` and/or `MCP_ENABLED=1` to
  `.env.local` (or to the container's environment file) **before** updating ([api.en.md](docs/api.en.md#enabling)).
- **New optional variable** `CARBURE_ALLOWED_HOSTS`: hosts of the Carbure instances that users can link (empty: all,
  local network included); set it on an instance open to the public.
- **nginx configuration changed**: importing a member's file into a family account (`/famille/membres`) accepts
  2 MB, like the data import. From git or from the CI archive, copy `docs/nginx/patrimeter-app.conf` again into
  `/etc/nginx/snippets/` and reload nginx ([installation §
  8](docs/installation.en.md#6-nginx)); the container
  image already includes it.
- Two migrations create the tables of family account members and of Carbure links; the update procedure applies it, like
  the container
  image when it starts. From git, do not forget `php bin/console asset-map:compile`. Container image:
  `docker compose pull && docker compose up -d`.

### Added

- **Family account**: each of the two members of a couple keeps their own account and exports its data every
  month; a third account imports them (**Family members** page), each new import replacing the previous one:
    - the dashboard, the history, the report and the budget show the **whole family**, **one member** or **this
      account**; the choice is kept from one page to the next;
    - the family adds everything up, each person having entered their share of what is shared (half of the joint
      account, share of the house, share of the expenses); the family budget makes each member an adult, brings
      together children with the same first name and is read-only;
    - the imported file is validated like an ordinary import, then kept encrypted with the family account's key.
- **Recommendations** on the dashboard, to the right of how investments are split: emergency savings compared with
  the month's budget expenses on a gauge in months of expenses, with a finding (**Alert** below one month,
  **Warning** below the target with the missing amount, **Good**, or **Note** above twice the target); pyramid rules:
  base first, negative level, largest gap with the allocation benchmark.

- **Carbure** ([github.com/ltoinel/Carbure](https://github.com/ltoinel/Carbure), self-hosted bank spending tracker):
  each user links their own instance (**Budget** → **Carbure**: address and API token); their expense categories
  are mapped to the budget's (by name, then by hand), and the budget page shows the planned amount, the month's
  actual spending and the gap. Read-only through Carbure's MCP server, without a new dependency; encrypted token; a
  single path called, without redirects, with bounded delay, size and number of calls.

### Changed

- **REST API and MCP service disabled by default**, each enabled by its variable (`API_ENABLED`, `MCP_ENABLED`), as
  in Carbure: a disabled service answers 404 on all its addresses; the OAuth server and personal tokens only exist if
  one of the two is enabled.
- **Budget, "With your net worth" section**: key figures (net worth and its change, emergency savings, expenses of
  the month), the same gauge as the dashboard, "planned" and "change in balance" bars for each savings payment, with
  their finding.
- **Data export**: reading a file is shared by the ordinary import and a member's import.
- **TODO**: technical ideas taken from Carbure.

## [2.8.0] - 2026-11-13

Monthly household budget, read alongside the net worth of the same month.

### Installation

- Ordinary update ([docs/mise-a-jour.en.md](docs/mise-a-jour.en.md)): a migration creates the budget tables; the
  update procedure applies it, as does the container image when it starts. No variable or configuration to change.
  From git, do not forget `php bin/console asset-map:compile`. Container image:
  `docker compose pull && docker compose up -d`.

### Added

- **Monthly household budget** (**Budget** menu), modelled on a couple's spreadsheet:
    - household of one or two adults and their children; suggested categories and items, to rename, add to or
      delete, each category placed in a group of the 50/30/20 rule (needs, wants, savings) and paid from the joint
      account or by one adult;
    - entry of a month's amounts (each adult's net income, the amount of each item), carried over to the following
      months until the next change; totals worked out as you type;
    - joint account: total of the shared budget, split in proportion to income or by a fixed share; each adult's own
      expenses and savings, and what they have left;
    - income flows as a chart (Sankey diagram drawn by the server, with no new library), 50/30/20 rule, savings rate,
      cost of each child;
    - read alongside the net worth of the same month: emergency savings in months of expenses against a target,
      savings payments next to the change in the balance of the linked accounts, net worth;
    - everything is encrypted with the user's key, and the budget routes are crawled by the isolation robot.

### Changed

- **Data export**: format version 2, with the household budget; a version 1 file can still be imported. The
  characters `<`, `>`, `&`, `'` and `"` are escaped in the file.
- **Navigation**: six entries; short labels on medium-width screens, so that they fit on one line.
- **Back to the original display** for the monthly update, accounts, properties and the budget: one card per
  section, bold headings, the usual font (no more fixed-width figures or small caps), boxed fields, carried-over
  balance with a dashed border on an amber background. The features remain: totals as you type, statuses, change
  since the previous month, keyboard shortcuts. The new layout is postponed to version 3.

## [2.7.0] - 2026-11-13

Documentation and README in English, new layout for accounts and properties, amounts read in the language of the
page, up-to-date versions and code quality review.

### Installation

- Ordinary update ([docs/mise-a-jour.en.md](docs/mise-a-jour.en.md)): no variable, configuration or migration to
  change. From git, do not forget `php bin/console asset-map:compile`: without it, the old layout of the accounts
  remains. Container image: `docker compose pull && docker compose up -d`.
- Online documentation: new addresses, `…/patrimeter/fr/` and `…/patrimeter/en/`; the old ones lead to the French
  site.

### Added

- **Documentation and README in English**: the online documentation exists in French and in English, as two twin
  sites with the same paths (`…/patrimeter/fr/` and `…/patrimeter/en/`), whose language selector stays on the same
  page; the root opens the browser's language and the old addresses lead to the French site. User and operations
  pages, API reference (English OpenAPI description, `docs/openapi.en.yaml`), CHANGELOG and security policy
  translated (`README.en.md`, `CHANGELOG.en.md`, `SECURITY.en.md`, `docs/*.en.md`); screenshots taken in each
  language. The technical pages about developing Patrimeter (architecture, CI…) remain in French. A test checks that
  every page has its translation, with the same sections, code blocks and screenshots, and that the commands run by
  the CI are identical in both languages.

### Changed

- **New layout for accounts and properties**, in the style of the monthly update:
    - list of accounts as one table per level (status entered or carried over, balance for the month in tabular
      figures, loans marked "owed");
    - account page with its summary (type, level, nature, opening), its statements and its shares as tables;
    - properties as tables, loans detailed and net value in the footer;
    - status of a balance as a shared template and shared table styles (`.sheet`, `.ledger`); the fields of a form
      row no longer stretch to the height of their neighbour.
- **Up-to-date versions**:
    - CI on Debian 13 (like the container image and the checked installation), Node 24 (LTS) and Python 3.14;
    - JavaScript tools: Vitest 5 and jsdom 30;
    - doctrine/doctrine-bundle 2.19.4;
    - the other tools and images were already at their latest version published at least two weeks ago; more recent
      versions will come through the Monday merge request.
- **Documentation** built in strict mode (a broken link or anchor fails the build); command variables in English
  (`$TOKEN`, `$PASSWORD`, `NEW_PHP`), like the rest of the code; update guide: the installed version is read in
  `VERSION` or at the bottom of the page.
- **Code quality**: account nature and loan mode as enumerations (`Nature`, `LoanMode`) instead of strings; typed
  class constants (PHP 8.3), enforced by a test; mutation testing extended to the encryption code (MSI of 94%);
  `#[\Override]` exemption limited to the published migrations.

### Fixed

- **Amounts on an English page**: "1,000" (one thousand, English style) was refused as an amount with three decimals.
  Amounts are now read in the language of the page: comma as the thousands separator in English ("1,000"), dot in
  French ("1.000"); the other reading of the same text, with three decimals, is still refused. Same rule for the
  totals worked out as you type.
- **Password-too-short message left in French** on English pages (registration, forgotten password, password
  change): built before being translated, it escaped the translator. A test now refuses a computed constraint
  message; every page, rendered in English, has been reviewed.
- **Registration**: the page after sending the form said "If the address can be registered, a message has just
  been sent to it", an obscure wording. It now shows the address entered and explains both cases: confirmation link
  for a new registration, simple notice if the address already has an account. The page stays the same in both
  cases: it still does not reveal who is registered.

## [2.6.0] - 2026-11-11

Version shown on every page, and entering past months from a later month.

### Installation

- Ordinary update ([docs/mise-a-jour.en.md](docs/mise-a-jour.en.md)): no variable, configuration or migration to
  change. From git, do not forget `php bin/console asset-map:compile`. Container image:
  `docker compose pull && docker compose up -d`.

### Added

- **Version shown** at the bottom of every page, sign-in page included ("Patrimeter, version X.Y.Z"). It comes from
  the `VERSION` file, present in every installation (git, archive, image) and kept in line with the CHANGELOG by a
  test.
- **Entering a past month from a later month**: on a month without any open account, the page offers to copy the
  balances of a later month (the current month by default); the copy also includes the accounts opened after the
  month being entered, and saving moves their opening date back to the 1st of that month (in the same transaction
  as the balances). Example: Patrimeter started in November, October is filled in by copying November.

## [2.5.0] - 2026-11-11

Container deployment (Docker, Docker Compose, Podman), new monthly update, report to share as a screenshot,
OpenAPI description of the API, copying an entry, month calendar and type lists you can type into.

### Installation

- Ordinary update ([docs/mise-a-jour.en.md](docs/mise-a-jour.en.md)): no variable, configuration or migration to
  change. From git, do not forget `php bin/console asset-map:compile`: without it, the old layout remains.
- New: container deployment, described in [docs/docker.en.md](docs/docker.en.md).

### Added

- **Monday pipeline** ([docs/ci.md](docs/ci.md#pipeline-du-lundi), in French), on top of the security scans:
    - the image of the latest version is rebuilt with the Debian and PHP packages of the day, started, analysed by
      Trivy and, if everything passes, published again as `vX.Y.Z`, `latest` and `vX.Y.Z-YYYYMMDD`: containers get the
      security fixes with `docker compose pull`, without waiting for a version;
    - the dependencies (Composer, Node tools) are updated, checked by `make lint cs stan psalm test audit js` and
      proposed in a merge request, as a draft if a check fails. Token to create once: `DEPENDENCY_BOT_TOKEN`.
- **CI quality checks**:
    - JavaScript and CSS: ESLint ("flat" configuration) and Prettier, Stylelint;
    - JavaScript unit tests with Vitest;
    - mutation testing of the business code with Infection (MSI of at least 92%);
    - accessibility of every page with axe-core in Playwright (WCAG 2.2 AA, light and dark themes);
    - migrations run on a new database, and checked up to date (`doctrine:migrations:up-to-date`);
    - `lint:yaml` extended to the translations and the OpenAPI description;
    - container: hadolint at the strictest threshold, `docker compose config`, image built early in the pipeline,
      smoke test of the started container, Trivy on the built image.
- **OpenAPI 3.1 description of the REST API** ([docs/openapi.en.yaml](docs/openapi.en.yaml)): each operation, its
  parameters, its request bodies, its responses (errors included) and its schemas. Published in the online
  documentation: "API reference" page, generated from the description at every build, and a file to import into a
  client or a code generator. A test checks that it covers exactly the API routes and that the actual responses
  follow its schemas.
- **Container deployment, with Docker and Docker Compose or Podman** ([docs/docker.en.md](docs/docker.en.md)):
  `Dockerfile` (Debian 13 and PHP 8.5 from the Surý repository, the recommended versions; nginx and php-fpm, same
  settings as a server) and `compose.yaml` (application, scheduled tasks, daily backup; data and backup volumes).
  Migrations applied at start, `patrimeter-console` console, image checked when it is built. The CI builds the
  image with Buildah, without a daemon or privileged mode (`container-image` job), and publishes it in the project
  registry for every version: `docker pull registry.example.org/kartzone/patrimeter:v2.5.0`.
- **Report to share**: **Share**, on the dashboard, opens a card of the month the size of a phone screen (net
  worth, change over 1 month and 1 year, pyramid, assets and liabilities), without menus, to capture and send as a
  picture (WhatsApp, Signal, text message, email…). Only the levels appear, never the name of an account; **Hide
  amounts** only keeps the percentages. Nothing is published: the page remains private.
- **Copying an entry**: on the monthly update page, "Copy balances from another month" fills the form with the
  balances of the chosen month (entered or carried over), to check and then save; nothing is saved before. Handy to
  fill in a past year from a month already entered.
- **Calendar for month fields** (statement of an account, share of a property, copying an entry): a button opens the
  year + month grid of the month selector; typing YYYY-MM with the keyboard remains possible.

### Changed

- **Recommended versions: Debian 13 and PHP 8.5.** The published container image is built with them, and the
  documented installation is checked by the CI on them (PHP 8.3 to 8.5 remain supported). The documentation no
  longer names a Debian version: the PHP repository is configured with the code name read from the system, the same
  procedure works for any version. The part specific to LXC containers is removed.
- **New layout for the monthly update**: one slim table per level (account, status, previous month's balance,
  amount), "Level net" and the month's net worth worked out again as you type (property shares included), discreet
  difference with the previous month under a changed amount, "X/N entered" counter with its mini progress bar.
  Amounts in tabular figures, aligned on the decimal separator, with "€" everywhere; loans marked "owed", their "−"
  next to the amount (accepted when saving, with or without the sign). Keyboard: Enter moves to the next field
  without sending the form, Shift+Enter to the previous one, Esc restores the suggested amount. Shortcuts and
  reminders folded behind a "?" help. Documentation and screenshots updated (new screenshot of the help, in the dark
  theme).
- **Account and property types: list you can type into**: you type directly in the "Type" field and the list only
  keeps the types that contain the text (ignoring case and accents), groups kept; mouse, arrows, Enter, Esc. Replaces
  the separate "Filter" field; without JavaScript, the full list remains.
- **Online documentation**: Patrimeter's favicon replaces Zensical's; every "§" reference is a link to the section
  it refers to, and the accented anchors, ignored by Zensical, are fixed.

### Fixed

- **Up-to-date container image** (found by Trivy on the image): the packages of the Debian base image, pinned by its
  digest, did not receive their security fixes (zlib); every build now applies them. A version is only published in
  the registry once the smoke test and the security gate have passed.
- **Accessibility** (found by axe-core): the accessible name of the month selector includes the text shown (WCAG
  2.5.3), and the tables that scroll sideways (sign-in log, monthly values) can be reached with the keyboard.
- The application's JavaScript no longer adds its functions to `window`.
- **"My account" page in English**: the "Sign in" line of the profile stayed in French. The text was chosen before
  being translated, which hid it from the translation check; a test now refuses this construction.

## [2.4.0] - 2026-11-11

Investment split benchmark, language menu, rankings side by side, property type filter, English translations
reviewed and update guide completed.

### Installation

- Ordinary update ([docs/mise-a-jour.en.md](docs/mise-a-jour.en.md)): no variable or configuration to change. From
  git, do not forget `php bin/console asset-map:compile`: without it, the old layout remains.

### Added

- **Investment split benchmark**, under the pyramid: share of each level in your investments (guaranteed funds,
  equities, real estate, alternative), next to the "balanced" allocation of Avenue des Investisseurs (45 / 25 / 25 /
  5%), with its source; only a guide, excluding the main residence and emergency savings.
- **Property type filter** when creating a property, as for accounts: ignoring case and accents.

### Changed

- **Language selector**: the button shows the current language (globe, **FR** or **EN**) and opens a **Français** /
  **English** menu, the current language ticked; works without JavaScript, closes when clicking elsewhere or with
  Esc.
- **Rankings side by side**: position among French households and among adults worldwide, one card each on the
  same row, under the pyramid and the details by level (one under the other on a phone).
- **English translations reviewed**: more natural sign-in and entry vocabulary ("Sign in", "Monthly update",
  "balances"), "fonds euros" translated as "guaranteed funds", wording of the rankings.
- **Update guide** completed: compiling the assets among the steps, "Common problems" section (display still old,
  `git pull` refused, error 500, failed migration). User documentation: benchmarks, language, frequently asked
  questions.

- **Tidier repository**: merged branches deleted (repository and local copies), source branch deleted by default
  when a merge request is merged; rule for branches and tags in `docs/ci.md`.

### Fixed

- **Simultaneous entries refused** ("database is locked") when a write kept the database for more than 5 s on a slow
  disk: SQLite now waits up to 15 s for a lock before giving up.

### Removed

- `docs/plan.md`, historical plan of v2 (no longer kept up to date, kept in the git history).
- "Skip to content" link at the top of the pages: the Patrimeter logo in the header leads back to the dashboard, and
  the marked-up areas (header, navigation, content) are enough for screen readers.

## [2.3.0] - 2026-11-10

Interface in English, position among adults worldwide, accessibility, accounts and properties separated, "My
account" page and update guide.

### Installation

- Ordinary update ([docs/mise-a-jour.en.md](docs/mise-a-jour.en.md)): no variable, nor nginx or php-fpm
  configuration to change. The new dependency (`symfony/translation`) is installed by Composer, or already included
  in the version's archive.

### Added

- **Interface in English**: browser language by default (French otherwise), FR / EN button next to the theme button
  (choice kept in a cookie). Pages, messages, emails, amounts (`€1,234.56`), dates and months translated; Symfony's
  Translation component, `translations/*.en.yaml` catalogues, translation completeness test. The documentation, the
  API and the console remain in French.
- **Position among adults worldwide**, under the position among French households: share of adults whose net worth
  is lower, scale graduated at the band thresholds, thresholds in euros and dollars, source (UBS, Global Wealth
  Report 2026, net worth per adult at the end of 2025, converted at the ECB rate of 31/12/2025).
- Step-by-step **update guide** ([docs/mise-a-jour.en.md](docs/mise-a-jour.en.md)): installed version, what to read
  in the CHANGELOG, installing a specific version, verification, rollback. The CI archive contains a `RELEASE` file
  (name of the archive and commit).
- **Account type filter** when creating an account: only keeps the types that contain the text typed, ignoring case
  and accents; full list without JavaScript.

### Changed

- **Accessibility** (WCAG 2.2 AA targeted, [Accessibility](https://kartnico.github.io/patrimeter/en/utilisation/accessibilite/)
  page of the documentation):
    - level colours reviewed to remain distinct for colour-blind people (protanopia, deuteranopia, tritanopia) and
      readable under white text (light) or dark text (dark theme, new shades);
    - a pattern specific to each level in the history chart, on top of its colour;
    - input field borders and "carried over" text with enough contrast;
    - "Skip to content" link;
    - errors announced immediately to screen readers;
    - automatic test of the contrasts and of the difference between colours for colour-blind people, in both themes.
- **Accounts and properties separated**: one menu each ("Properties" in the navigation). Properties (main
  residence, rental, SCPI, SCI…) are only created from Real estate, with their share and their loans: the account
  form no longer offers their types and refuses an account that would become a property (Residence or Real estate
  level, asset). An existing property can still be changed.
- **"My account" page** in place of the Security page (same address, `/securite`): the user's profile first, then
  security and data; link with a profile icon, above "Sign out".

## [2.2.0] - 2026-11-10

### Added

- **Position among French households**, under the pyramid: share of households whose net worth is lower, decile,
  graduated scale, thresholds and source (Insee, Histoire de vie et Patrimoine survey 2023-2024, net worth in early
  2024). Calculated locally, by interpolation between the published thresholds.

## [2.1.0] - 2026-11-10

Monitoring, data export and import, server key rotation, CSP violation reports, rate limited per token, online user
documentation, Symfony and PHP best practices.

### Installation

- To update a server already installed: copy the php-fpm pool again (`docs/php-fpm/patrimeter.conf`, upload of the
  import file) and `docs/nginx/patrimeter-app.conf` (import address up to 2 MB), then
  `nginx -t && systemctl reload nginx`; the `var/uploads` folder is created by the update.

### Added

- **User documentation** published by GitLab Pages (Zensical): quick start, screen-by-screen guide with screenshots
  taken from the application at every `main` pipeline, installation and operations taken from `docs/` without a copy
  to maintain.
- **Data export and import** (Your data page, from Security): versioned JSON file, to move from one Patrimeter
  instance to another; all-or-nothing import into an empty account (`docs/export.en.md`).
- **Monitoring**: `/sante` (JSON status, 503 if the database does not answer) and `/sante/metriques` (Prometheus
  format, also read by the OpenTelemetry Collector): database, age of the last backup and of the scheduled tasks,
  free space (`docs/supervision.en.md`).
- **CSP violation reports** logged in `var/log/csp-*.log` (`report-uri`, `report-to`).
- **Rate limited per token** on the API and MCP: 120 requests in a row, then one per second (`429`).
- **Rotating `AUTH_SECRET_KEY`** without resetting the users: `AUTH_SECRET_KEY_PREVIOUS` and
  `app:auth-secrets:rotate` (installation § 12.5).
- **Security pipeline every Monday** (`SECURITY_SCAN=1`), even without new code; `main` protected.
- List of upcoming improvements: `TODO.md`.
- Procedure to publish a version (git tag, GitLab Release): `docs/ci.md`.
- `docs/choix-techniques.md`: all the technical building blocks, the alternatives considered and the reason for each
  choice.

### Changed

- **OPcache tuned as Symfony recommends** (`docs/php-fpm/patrimeter-opcache.ini`, to copy to
  `/etc/php/<version>/fpm/conf.d/90-patrimeter.ini`, installation § 7): 256 MB, file timestamps not checked. After
  any change to the code outside the update procedures, restart php-fpm. No preloading: with the pool's
  `disable_functions` list, it distorted function calls (error 500 found by the `deployment` job, fixed before any
  installation).
- Service container compiled into a single file; locale limited to French.
- API controllers on `AbstractController`, like the others.
- PHP 8.3 best practices: `#[\Override]` on every overridden method, required by PHPStan and Psalm;
  `#[\SensitiveParameter]` on the tokens, codes and secrets that did not have it.
- Services configured by attributes next to the code (`#[AsMiddleware]`, `#[AsAlias]`, `#[Autowire]`).
- Deprecations: PHPUnit reports those of Symfony and Doctrine at their caller (Symfony's recipe), Doctrine's are
  enabled in tests; the CI refuses the deprecations reported when compiling the container
  (`tools/container-deprecations.sh`).
- Status of Symfony best practices, deliberate deviations included: `docs/architecture.md`.
- `.gitattributes`: LF line endings enforced.
- PHP-CS-Fixer 3.95.30, Psalm 7.2.
- No more mention of PHP 8.2 in the repository (documentation, plan, php-fpm pool); the PHP 8.3 polyfill is no
  longer installed, useless since PHP 8.3 is the minimum (as Symfony's skeleton does).

### Removed

- `settings` table, inherited from v1 and unused since the move to multiple users (migration
  `Version20261110140000`, reversible); `config/preload.php`, OPcache preloading not being used.

### Fixed

- Changing the PHP version (installation § 12.4): the new php-fpm, started when its packages were installed, was not
  restarted and ignored Patrimeter's pool.
- Two signatures deprecated by Symfony 7.4, which would have broken with Symfony 8:
  `OwnedResourceVoter::voteOnAttribute()` (`Vote` argument) and `UserChecker::checkPostAuth()` (token).

## [2.0.0] - 2026-11-10

Batches 1 to 9 of v2 (original plan in the git history: `docs/plan.md`, removed after 2.3.0): technical
foundation, complete CI, light/dark theme, multiple users with a second factor, data encryption, passkeys and FIDO2
keys, OAuth2/OIDC, properties and ownership shares.

### Installation

- **PHP 8.3 minimum**; PHPUnit 12, Doctrine DBAL 4.3 or later.
- Installation reordered: scheduled tasks (§ 9), backup (§ 10), verification (§ 11), then update (§ 12: from git,
  from the CI archive, rollback, changing the PHP version).
- New systemd services: `patrimeter-scheduler` and the `patrimeter-alert@` template (installation § 9), reinstalled
  and restarted at every update. New variable: `ADMIN_EMAIL`.
- **Start from a new database**: v1 data is not carried over. The migrations refuse a database containing
  unencrypted accounts.
- Additional mandatory variables: `AUTH_SECRET_KEY` (`openssl rand -base64 32`) and `DEFAULT_URI`.
- **HTTPS mandatory**, on the server or on a reverse proxy. Behind a proxy, `TRUSTED_PROXIES` is essential, and the
  proxy must pass `Host`, `X-Forwarded-Proto` and `X-Forwarded-For`.
- New packages: `php*-curl` (OAuth) and `sqlite3` (backups). Composer comes from its official phar
  (`ci/install-composer.sh`), no longer from the Debian package.
- nginx: `docs/nginx/patrimeter.conf` is replaced by `patrimeter-proxied.conf` or `patrimeter-tls.conf`, plus the
  shared part `patrimeter-app.conf` to copy to `/etc/nginx/snippets/`.
- `bin/console app:set-password` is replaced by `app:user:create` and `app:user:set-password`.

### Changed

- **Sign-in with email address and password, then TOTP code** (instead of the single password).
- New passwords: at least 12 characters (10 before) and hard enough to guess.
- Limiting attempts: counters per IP address, per account (delay without blocking) and global (blocking at 100
  failures instead of 20, the global threshold now being shared by all users).
- `make serve` serves AssetMapper's assets (`tools/dev-server-router.php` router).
- Application ported to **Symfony 7.4 LTS** (Doctrine DBAL and Migrations, Twig, AssetMapper), without visible
  change: same addresses, same screens, same messages and same calculations, checked against a set of reference
  results frozen with v1.
- PHP 8.3 to 8.5, installed from Ondřej Surý's repository; **PHP 8.4 recommended**.
- Installation and update: Composer, `.env.local` file and Symfony console.
  `bin/console doctrine:migrations:migrate` replaces `bin/migrate.php`, `bin/console app:set-password` replaces
  `bin/set-password.php`, and `config.php` is replaced by environment variables. The migrations are no longer
  applied automatically at the first request: they are part of the update procedure.
- The php-fpm socket is now called `/run/php/patrimeter.sock`.
- Chart.js 3.9.1 → 4.6.1 (3.x is no longer maintained).

### Added

- **MCP: clients described by a metadata document** (*Client ID Metadata Documents*), read by Symfony's
  HttpClient behind `NoPrivateNetworkHttpClient`, checked by the serializer and the validator.
- **Validation by Symfony Validator**: account and property forms (`Collection` constraint), API request bodies read
  by `#[MapRequestPayload]` into classes with constraints (`src/Application/Payload/`). A decimal sent instead of an
  integer is now refused (it was truncated). The API requires `Content-Type: application/json`.
- **Scheduled tasks** (symfony/scheduler, `patrimeter-scheduler` service): hourly purge of what has expired (links
  sent by email, log older than 180 days, API tokens, security cache) and `PRAGMA optimize`, instead of purges done
  during users' requests.
- **Email alerts** to `ADMIN_EMAIL`: critical application error (Monolog, once per hour for the same error) and
  failure of a service, including the backup (`OnFailure=`, `patrimeter-alert@` template).
- New dependencies (Symfony): `symfony/scheduler`, `symfony/messenger`.
- **Leaked passwords refused** (Symfony's `NotCompromisedPassword`, Have I Been Pwned API using k-anonymity);
  strength estimated by Symfony's `PasswordStrength`, at the same threshold as before.
- **Limiting attempts on symfony/rate-limiter** (`config/packages/rate_limiter.yaml`): blocking after 5 failures per
  IP or 5 wrong codes over 15 minutes, 100 failures per hour in total, and per account one try per minute after 5
  failures. The exponential delay from the first failure disappears. Counters and TOTP codes already used are in a
  cache pool on the database (`cache_items` table), kept by `cache:clear`: a code can no longer be used again just
  after an update.
- New dependencies (Symfony): `symfony/validator`, `symfony/rate-limiter`, `symfony/http-client`.
- **CI**: fast pipeline (about 15 min) on branches and merge requests; full pipeline on `main`, on a tag, on a
  schedule or with `FULL_PIPELINE=1`. Delivery archive built by the CI (`release` job, `docs/installation.en.md`
  § 12.2).
- **REST API and MCP service** (`docs/api.en.md`), with the same authentication as the site: signing in in the
  browser and consenting (OAuth 2.1 authorization server, PKCE), or a personal token from the Security page. Bearer
  tokens through Symfony's native `access_token` authenticator; data decrypted with the key carried by the token,
  without the server being able to do it alone.
- New dependencies: `symfony/mcp-bundle` and `symfony/psr-http-message-bridge` (Symfony), `mcp/sdk` (official MCP
  SDK), `opis/json-schema`, `opis/string`, `opis/uri`, `php-http/discovery`, `psr/http-server-handler`,
  `psr/http-server-middleware`. The bundle and the SDK are **experimental**.
- **Daily backup and restore** (`docs/backup.en.md`): scripts and systemd timer, consistent live copy, integrity
  check, rotation by number of archives, protected restore.
- **`deployment` CI job**: runs the installation and backup documentation on a fresh Debian 12, and checks nginx,
  php-fpm, HTTPS, permissions, restore and update from the outside.
- **Complete CI**: Playwright journeys, authenticated ZAP, k6 load, Semgrep, Trivy, npm audit, GitLab SAST and
  Secret Detection templates, security gate with justified and dated exclusions, summary report, detection of N+1
  queries, PHP 8.6 ahead of time (`docs/ci.md`).
- The pipeline stops at the first job if the runner's disk is almost full.
- **Properties** (Properties page, from Accounts): value of the property, share, value counted, outstanding
  principal and net value; a single form creating the property and its loans in one transaction; an existing loan
  is linked to a property from its page.
- **Ownership shares**: history by effective month (100% by default), in millionths, rounded to the nearest cent.
  The total value of the property is still entered; the pyramid, the total and the history count the share in force
  each month. A linked loan counts prorata or as its own amount.
- **Multiple users**, strictly separated: repositories filtered by owner, voter, composite foreign key
  `(account_id, user_id)`, SQL forbidden outside the repositories (PHPStan rule), test robots going through every
  route with another user's identifiers.
- **Registration** from the sign-in page (can be disabled, allowed domains configurable): confirmation link by email
  (24 h, single use) which also requires the chosen password. No answer reveals whether an address already has an
  account.
- **Mandatory TOTP second factor** (QR code as SVG, key shown) and **10 single-use backup codes**; TOTP secrets
  encrypted and codes protected by the `AUTH_SECRET_KEY` server key.
- **Security page**: changing the password, changing the authenticator app, new backup codes, signing out the other
  sessions (after confirming the password within 5 minutes), sign-in log (180 days); alert emails.
- Sessions limited to 12 hours after signing in, on top of the 30 minutes of inactivity.
- Sending email through a configurable SMTP server (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`,
  `SMTP_ENCRYPTION`, `MAIL_FROM`).
- **Encrypted data** (names, types, dates, amounts) with a key per user, unreadable from the database, the backups or
  the sessions; **recovery key** shown once and confirmed; unlock screen. Details:
  [docs/encryption.en.md](docs/encryption.en.md).
- **Passkeys and FIDO2 security keys** (web-auth/webauthn-lib 5.3): passwordless sign-in with user verification, or
  a key as second factor instead of the code. With the PRF extension, the passkey also unlocks the data; without it,
  the password is asked. Adding, renaming and deleting from the Security page. **HTTPS mandatory in production.**
- **OAuth2/OIDC sign-in** (league/oauth2-client): Google, GitHub and a generic OIDC provider, active only if they are
  configured; PKCE, state, nonce. Never an automatic link based on the email address: it is done from the Security
  page. Account without a password: **unlock passphrase** mandatory. TOTP is still asked after OAuth. The last
  sign-in method cannot be removed.
- **Forgotten password**: 30-minute link; data kept with the recovery key, erased after explicit confirmation
  otherwise.
- Commands `app:user:create`, `app:user:set-password`, `app:user:reset-2fa`, `app:user:rotate-key`.
- Sending emails after the response: the response time does not reveal whether an account exists.
- Dependencies: scheb/2fa-bundle, scheb/2fa-totp and scheb/2fa-backup-code 7.14 (with spomky-labs/otphp),
  endroid/qr-code 6 (with bacon/bacon-qr-code), symfony/mailer and symfony/uid 7.4; all compatible with PHP 8.3 to
  8.5 and under the MIT or BSD license.
- Documentation: authentication flows ([docs/auth-flows.md](docs/auth-flows.md), in French) and data model
  ([docs/data-model.md](docs/data-model.md), in French).
- **Light or dark theme**: button in the header of every page (sign-in included), system theme by default, choice
  remembered for a year in a cookie. The server writes the theme into the page: no flash on loading, no inline
  script. Without JavaScript, the button remains a form; with it, switching is instant and the chart is redrawn.
- More than 370 PHPUnit tests: unit, integration, functional (real HTTP requests), non-regression against v1,
  architecture, migrations.
- Quality tooling: PHP-CS-Fixer (PER Coding Style 3.0), PHPStan at the max level, Psalm with taint analysis, W3C
  validation of the rendered HTML and of the CSS.
- GitLab continuous integration: lint, static analysis, tests on PHP 8.3 and 8.5, coverage with thresholds, W3C,
  `composer audit`, Gitleaks.
- HSTS header over HTTPS, application log `var/log/prod.log` (14 days).
- Documentation: architecture, continuous integration, security, v2 plan, environment variables (`.env.example`),
  Debian 12 procedure rewritten.

### Fixed

- **Restore**: refused while the scheduled tasks run (they kept the database open and would have kept writing to the
  old one); the procedure stops `patrimeter-scheduler` together with php-fpm.
- **Scheduled tasks alert**: restarted every 10 s without a reachable limit, the service never failed and its alert
  was never sent; it stops and sends a warning after 5 starts in 10 minutes.
- **Update** (`docs/installation.en.md` § 12): service stopped during the operation and production cache deleted
  before any command (Symfony does not rebuild it by itself, and the one from the old version might no longer suit
  the new code); systemd units and backup scripts reinstalled. From the CI archive, `/srv/patrimeter-v1` is no longer
  taken for the extracted archive.
- **Passkey without user verification** accepted by reusing the second factor's challenge.
- `app:user:rotate-key`: passkeys then opened the old key (error 500); also works with an unlock passphrase.
- Forgotten password: a TOTP code already used was accepted.
- Monthly entry: an account missing from the form (created since it was displayed) lost its balance for the month.
- Amounts: `0.500` was read as €500.00; it is now refused.
- A property with a share or loans can no longer change level, nor a linked loan become an asset, without removing
  them first: they kept applying without remaining editable.
- Linking an external account from Security: refused by the CSP in Chrome and Edge, and session lost on return.
- Properties: same opening and closing rule as the dashboard.
- Registration: beyond the quota, the links already received remain valid, and the first email counts among the
  three per hour.
- Errors 500: `/?mois=1900-03`, address `a..b@example.com` (which also left an orphan row), invalid property or
  passkey name.
- CI: merge request pipeline refused by GitLab (jobs of the SAST and Secret Detection templates missing), hence no
  pipeline on a branch whose merge request is open.
- Installation: `http2 on` refused by Debian 12's nginx 1.22, OAuth sign-in in error 500 without `php-curl`, log
  name, private repository, reverse proxy missing from the procedure.

- A FIDO2 key without a resident identifier was refused as second factor: it returns no `userHandle`, whereas the
  user is already identified. Found by the Playwright journeys.
- The Accounts page looked empty for a signed-in user: a navigation variable overwrote the list
  (`templates/base.html.twig`).

### Security

- A user's data inaccessible to the others (separation in five barriers, see
  [docs/architecture.md](docs/architecture.md), in French); links sent by email built from `DEFAULT_URI`, never from
  the request's Host header.
- Migrations written in SQL only: Doctrine's automatic schema comparison is disabled, because under SQLite it rebuilt
  tables and triggered cascades.

## [1.0.0] - 2026-11-07

First version: native PHP, SQLite, single user.

### Added

- Accounts and loans with a predefined type, pyramid level, nature, opening and archiving.
- Monthly entry of all accounts in one form, with the last balance carried over automatically.
- Dashboard: SVG pyramid, net worth, change over one month and over one year, month selector as a calendar.
- History: net worth curve or areas by level, over several periods.
- Authentication with a single password (Argon2id), limited attempts, hardened sessions, CSRF token, security
  headers including a strict CSP.

[3.6.0]: https://github.com/kartnico/patrimeter/compare/v3.5.0...v3.6.0
[3.5.0]: https://github.com/kartnico/patrimeter/compare/v3.4.1...v3.5.0
[3.4.1]: https://github.com/kartnico/patrimeter/tree/v3.4.1
[3.4.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v3.3.0...v3.4.0
[3.3.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v3.2.0...v3.3.0
[3.2.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v3.1.0...v3.2.0
[3.1.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v3.0.0...v3.1.0
[3.0.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.26.1...v3.0.0
[2.26.1]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.26.0...v2.26.1
[2.26.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.25.2...v2.26.0
[2.25.2]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.25.1...v2.25.2
[2.25.1]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.25.0...v2.25.1
[2.25.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.24.0...v2.25.0
[2.24.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.23.0...v2.24.0
[2.23.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.22.0...v2.23.0
[2.22.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.21.0...v2.22.0
[2.21.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.20.0...v2.21.0
[2.20.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.19.0...v2.20.0
[2.19.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.18.0...v2.19.0
[2.18.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.17.0...v2.18.0
[2.17.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.16.0...v2.17.0
[2.16.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.15.0...v2.16.0
[2.15.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.14.0...v2.15.0
[2.14.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.13.0...v2.14.0
[2.13.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.12.0...v2.13.0
[2.12.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.11.0...v2.12.0
[2.11.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.10.0...v2.11.0
[2.10.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.9.0...v2.10.0
[2.9.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.8.0...v2.9.0
[2.8.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.7.0...v2.8.0
[2.7.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.6.0...v2.7.0
[2.6.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.5.0...v2.6.0
[2.5.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.4.0...v2.5.0
[2.4.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.3.0...v2.4.0
[2.3.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.2.0...v2.3.0
[2.2.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.1.0...v2.2.0
[2.1.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/v2.0.0...v2.1.0
[2.0.0]: https://gitlab.example.org/kartzone/patrimeter/-/compare/e4217829...v2.0.0
[1.0.0]: https://gitlab.example.org/kartzone/patrimeter/-/commit/e4217829
