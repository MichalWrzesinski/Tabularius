# Shared commands for humans, agents and CI. Only Docker and make are needed on the host.
SHELL := /bin/sh
export UID := $(shell id -u)
export GID := $(shell id -g)
DC := docker compose
API := $(DC) run --rm --no-deps api
BUSINESS := $(DC) run --rm --no-deps business
CLIENT := $(DC) run --rm --no-deps client

.PHONY: up down logs install migrate seed csv-samples csv-check test check check-backend check-frontend secrets hooks ci build clean-db

# Download caches are bind-mounted into the containers; create them as the current user first,
# otherwise Docker creates them as root and npm/composer cannot write (CI runners).
CACHE_DIRS := .cache/composer .cache/npm
$(CACHE_DIRS):
	mkdir -p $@

.env:
	cp .env.example .env
	printf 'UID=%s\nGID=%s\n' "$$(id -u)" "$$(id -g)" >> .env

up: .env build install ## start everything from scratch
	$(DC) up -d --wait db
	$(MAKE) migrate
	$(MAKE) seed
	$(DC) up -d
	@echo "Tabularius: http://127.0.0.1:8080  (API health: /api/health)"

build: .env
	$(DC) build

install: .env | $(CACHE_DIRS)
	$(API) composer install --no-interaction --no-progress
	$(BUSINESS) composer install --no-interaction --no-progress
	$(CLIENT) npm ci --no-audit --no-fund

down:
	$(DC) down

logs:
	$(DC) logs -f --tail=100

migrate: .env | $(CACHE_DIRS)
	$(DC) run --rm api sh -c 'DATABASE_URL="$$MIGRATIONS_DATABASE_URL" bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
	$(DC) run --rm business sh -c 'DATABASE_URL="$$MIGRATIONS_DATABASE_URL" bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'

seed: .env | $(CACHE_DIRS) ## (re)load synthetic business data around REF=YYYY-MM-DD (default: today, Europe/Warsaw)
	$(DC) run --rm business bin/console app:seed $(if $(REF),--reference-date=$(REF))

csv-samples: .env ## regenerate docs/qa/csv samples (seed for REF=2026-11-15 first: make seed REF=2026-11-15)
	$(DC) run --rm api sh -c 'mkdir -p var/csv && \
	  bin/console app:report sales_report "{\"period\":{\"type\":\"previous_month\"}}" --reference-date=2026-11-15 --output=var/csv/sales_PH-01_2026-10.csv -q && \
	  bin/console app:report customer_discount_simulation "{\"customer\":\"K-004\",\"period\":{\"type\":\"previous_month\"}}" --reference-date=2026-11-15 --output=var/csv/discount_K-004_2026-10.csv -q && \
	  bin/console app:report parts_catalog "{\"manufacturer\":\"BOSCH\"}" --output=var/csv/catalog_BOSCH.csv -q'
	cp api/var/csv/*.csv docs/qa/csv/

csv-check: ## open docs/qa/csv samples in headless LibreOffice Calc (pl-PL) and check numbers, text and formulas
	docker build -q -t tabularius-libreoffice -f infra/tools/libreoffice.Dockerfile infra/tools >/dev/null
	docker run --rm -v "$(CURDIR)":/w -w /w -e HOME=/tmp tabularius-libreoffice python3 scripts/csv_spreadsheet_check.py docs/qa/csv/*.csv

test: .env | $(CACHE_DIRS)
	$(DC) up -d --wait db
	$(DC) run --rm api vendor/bin/phpunit
	$(DC) run --rm business vendor/bin/phpunit
	$(CLIENT) npm test

check: check-backend check-frontend secrets ## full set of checks (same as CI)

check-backend: .env | $(CACHE_DIRS)
	$(DC) up -d --wait db
	@for app in api business; do \
	  echo "== $$app" && \
	  $(DC) run --rm $$app sh -c 'bin/console cache:warmup --env=dev >/dev/null && \
	    vendor/bin/php-cs-fixer check --diff --show-progress=none && \
	    vendor/bin/phpstan analyse --no-progress --memory-limit=1G && \
	    XDEBUG_MODE=coverage php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text=var/coverage.txt --only-summary-for-coverage-text && \
	    php scripts/coverage-threshold.php var/coverage.txt 95 && \
	    composer audit --no-interaction --locked' || exit 1; \
	done

check-frontend: .env | $(CACHE_DIRS)
	$(CLIENT) sh -c 'npm run lint && npm run typecheck && npm run test:coverage && npm run build && npm audit --omit=dev'

secrets:
	docker run --rm -v "$(CURDIR)":/repo zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f dir /repo --no-banner --redact --config /repo/.gitleaks.toml

hooks:
	git config core.hooksPath .githooks

ci: .env build install check ## what the CI job `check` runs

clean-db: ## drop the local database volume (data loss: local only)
	$(DC) down -v
