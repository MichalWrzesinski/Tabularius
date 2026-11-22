# Tabularius — instructions for agents

„Od wiadomości do raportu”: an employee emails a request, Tabularius turns it into a ticket, interprets it with an LLM into a typed plan, authorizes it and runs one operation from a closed catalog against a read-only business system; the result is a CSV behind login.

Standards: `docs/standards/` — AppStandards suite 5.0.0, copied 2026-11-21 from `MichalWrzesinski/AppStandards@main` (commit `410ba9c`). Start with [core](docs/standards/core.md), [routing](docs/standards/routing.md), [workflow](docs/standards/workflow.md). Do not edit the copy; record gaps in `docs/standards-feedback.md`.

## Knowledge sources

- Product (source of rules, IDs like RPT-*, ACC-*, TKT-*): [docs/product/overview.md](docs/product/overview.md) and `docs/product/areas/`
- Design direction and mockups: [docs/design/README.md](docs/design/README.md)
- Owner's input brief (input material; findings move into `docs/product/`): [docs/input/brief.md](docs/input/brief.md)
- Progress view: [docs/progress.md](docs/progress.md)
- Case-study source material (required deliverable, facts only, never published by the agent): [docs/case-study/README.md](docs/case-study/README.md)
- Technical profile: [docs/technical-profile.md](docs/technical-profile.md); architecture: [docs/architecture.md](docs/architecture.md); decisions: `docs/decisions/`; API contract (generated, from increment 2): `docs/api/openapi.yaml`

## Project overrides of standards

- Stack is fixed by the owner: PHP/Symfony + PostgreSQL backend, Vue 3 + TypeScript dashboard in `client/`. Do not ask about the stack or product name again.
- No AI attribution in commits or PRs (no `Co-Authored-By`, no tool footer).
- Documentation and code in English; UI, emails and demo communication in Polish; messages to the owner in Polish.
- Business data are synthetic only. The agent does not send real emails, enable real OpenAI calls, provision hosting or register domains until the owner has agreed the specific step and its cost.
- After a significant stage or finding, add a report under `docs/case-study/stages/` (format in the case-study README). Do not write posts or publish.

## Commands

`make up`, `make down`, `make migrate`, `make seed [REF=…]`, `make csv-samples` / `make csv-check` (LibreOffice import check), `make test`, `make check` (= CI job `check`), `make secrets`, `make hooks` — see [README](README.md#quick-start). Only Docker and make on the host (host Node is too old); one-off tools: `docker compose run --rm api|business|client …`.

## Pitfalls

- Dependency age: Composer enforces 14 days via `config.policy.cooldown` in both `composer.json`; npm versions are pinned by hand to releases ≥ 14 days old; Dependabot uses `cooldown: 14`. Image tags are pinned with digests.
- PHP 8.5 has OPcache built in: do not `docker-php-ext-install opcache` (the build fails with `cp: can't stat 'modules/*'`).
- Symfony recipes write `.env.dev` with a random `APP_SECRET`; it is git-ignored and must not be committed (gitleaks catches it). Secrets come from the root `.env` via Compose.
- Migrations run as the migrator/owner roles (`MIGRATIONS_DATABASE_URL`); the apps run as `tabularius_app` / `business_reader`. A table created by another role is invisible to the app roles unless granted.
- Business tests: `business/tests/bootstrap.php` migrates and seeds `business_test` for 2026-11-15 before every run; `SeedTest` pins the seed checksum, so a seed change needs a new `DemoDataSeeder::DATA_VERSION` and checksum. Known answers: `business/README.md`.
- DBAL 4 needs `ParameterType::INTEGER` for typed parameters (not `PDO::PARAM_INT`, which throws a TypeError). Narrow DB values with `App\Db\Value` instead of casting `mixed` (PHPStan max).
- php-cs-fixer keeps inline `/** @var */` (`phpdoc_to_comment` ignores `var`); do not turn them into `/* */` comments.
- Report engine tests run the real `RestBusinessApi` against captured responses (`api/tests/Fixtures/business`, seed 2026.11-1, REF 2026-11-15); regenerate them when the seed version changes. A test helper must not be called `run()` or `count()` (final in PHPUnit `TestCase`).
- Sessions (`PdoSessionHandler`) need their own PDO connection; sharing Doctrine's connection breaks login with „There is already an active transaction”. The session→account index is written on the response (the id changes after login).
- `api/phpunit.dist.xml` forces `MAILER_DSN`, `APP_MODE` etc. over the container environment, so tests never send mail. Symfony removes the `X-Transport` header when sending; assert transport choice at unit level (`AccountMailerTest`).
- State-changing API calls need the header `X-Tabularius-Request: 1` (CSRF); `client/src/lib/http.ts` adds it.
- `vue-tsc` 3.3 does not work with TypeScript 7 (missing `typescript/lib/tsc` export); keep TypeScript 5.9 until vue-tsc supports it.
- `infra/server/compose.yml` (staging) has the same Compose project name as the local stack (`tabularius`). A local rehearsal must set `COMPOSE_PROJECT_NAME`, otherwise it replaces the dev containers. Staging steps that need `sudo` on the owner's server are the owner's ([runbook](docs/runbook.md#staging-owners-shared-vps)).
- Incoming mail: there is no `ext-imap` (removed from PHP 8.4 core); the IMAP client is `api/src/Module/Mail/Imap/ImapConnection.php`. Test it with a scripted server over `stream_socket_pair`. Local mail enters through `bin/console app:mail:inject file.eml` (fixtures in `api/tests/Fixtures/mail/`). A MIME part without a charset is decoded as ISO-8859-1.
