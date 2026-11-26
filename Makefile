# Development commands of Patrimeter (see docs/ci.md).
# Prerequisites: PHP 8.3+ with pdo_sqlite and sodium, Composer; pcov for the coverage; Java 17+ and vnu.jar
# (VNU=path) for the W3C validation; Node, Mailpit, k6 and ZAP for make e2e, perf and dast.

PHP      ?= php
COMPOSER ?= composer
VNU      ?= vnu
CONSOLE  := $(PHP) bin/console
COVERAGE_THRESHOLDS := src=80 src/Domain=95 src/Security=95 src/Infrastructure/Crypto=95

.DEFAULT_GOAL := help
.PHONY: help install serve lint cs cs-fix stan psalm test test-unit test-integration test-functional test-regression test-architecture coverage infection js w3c licenses audit e2e dast perf ci docs

help: ## Shows this help
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  make %-18s %s\n", $$1, $$2}'

install: ## Installs the dependencies and creates the development database
	$(COMPOSER) install
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

serve: ## Development server on http://localhost:8000
	$(PHP) -S localhost:8000 -t public tools/dev-server-router.php

lint: ## PHP, Twig, YAML, container and composer.json syntax
	find bin/console config migrations public src tests tools -name '*.php' -o -name console | xargs -n1 $(PHP) -l > /dev/null
	$(CONSOLE) lint:twig templates
	$(CONSOLE) lint:yaml config translations docs/openapi.yaml docs/openapi.en.yaml --parse-tags
	$(CONSOLE) lint:container
	sh tools/container-deprecations.sh "$(PHP)"
	$(COMPOSER) validate --strict

cs: ## Checks the style (PER-CS 3.0)
	$(PHP) vendor/bin/php-cs-fixer check --diff

cs-fix: ## Fixes the style
	$(PHP) vendor/bin/php-cs-fixer fix

stan: ## PHPStan, max level
	$(CONSOLE) cache:warmup --env=test --quiet
	$(PHP) -d memory_limit=1G vendor/bin/phpstan analyse --no-progress

psalm: ## Psalm with taint analysis
	$(PHP) -d memory_limit=2G vendor/bin/psalm --taint-analysis --no-progress

test: ## All the PHPUnit tests
	$(PHP) vendor/bin/phpunit

test-unit test-integration test-functional test-regression test-architecture: ## A single test suite
	$(PHP) vendor/bin/phpunit --testsuite $(subst test-,,$@)

coverage: ## Tests with coverage (pcov) and threshold check
	$(PHP) -d pcov.enabled=1 vendor/bin/phpunit --coverage-clover var/coverage/clover.xml --coverage-cobertura var/coverage/cobertura.xml --coverage-text --colors=never
	$(PHP) tools/coverage-check.php var/coverage/clover.xml $(COVERAGE_THRESHOLDS)

infection: ## Mutation tests of src/Domain and of the encryption (Infection, pcov; thresholds in infection.json5)
	$(PHP) vendor/bin/infection --no-progress --threads=4

js: ## JavaScript, CSS and Markdown: ESLint, Prettier, Stylelint, markdownlint, Vitest unit tests (npm ci first)
	npx eslint .
	npx prettier --check .
	npx stylelint "assets/**/*.css"
	npx markdownlint-cli2
	npx vitest run

w3c: ## W3C validation of the rendered pages and of the CSS (Nu Html Checker)
	rm -rf var/html-snapshots var/cache/test
	HTML_SNAPSHOT_DIR=$(CURDIR)/var/html-snapshots $(PHP) vendor/bin/phpunit --filter PageSnapshotTest
	$(VNU) --skip-non-html var/html-snapshots/*.html
	$(VNU) --css assets/styles/app.css

licenses: ## THIRD-PARTY-LICENSES.md from composer.lock (shipped third-party code)
	$(PHP) tools/third-party-licenses.php

audit: ## Known vulnerabilities of the dependencies
	$(COMPOSER) audit

e2e: ## Playwright flows (Mailpit must listen: SMTP_PORT, MAILPIT_API)
	PHP="$(PHP)" npx playwright test
	rm -rf public/assets

dast: ## Authenticated ZAP baseline (zap-baseline.py in the PATH, /zap/wrk writable)
	PHP="$(PHP)" SEED_USERS=1 sh ci/run-zap.sh; status=$$?; kill "$$(cat var/e2e/server.pid)"; rm -rf public/assets; exit $$status

perf: ## k6 load: 20 users, 10 years of history
	mkdir -p reports
	$(PHP) tests/perf/argon-cost.php reports/argon-cost.json
	PHP="$(PHP)" SEED_USERS=20 SEED_YEARS=10 sh ci/start-app.sh --background
	k6 run --summary-export reports/k6-summary.json tests/perf/load.js; status=$$?; kill "$$(cat var/e2e/server.pid)"; rm -rf public/assets; exit $$status

ci: lint cs stan psalm test w3c audit ## Pipeline checks except e2e, dast and perf

docs: ## User documentation in French and English (Zensical, pip install zensical): documentation/site/
	sh documentation/build.sh
