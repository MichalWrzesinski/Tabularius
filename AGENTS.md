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

`make up`, `make down`, `make migrate`, `make test`, `make check` (= CI job `check`), `make secrets`, `make hooks` — see [README](README.md#quick-start). Only Docker and make on the host (host Node is too old); one-off tools: `docker compose run --rm api|business|client …`.

## Pitfalls

- Dependency age: Composer enforces 14 days via `config.policy.cooldown` in both `composer.json`; npm versions are pinned by hand to releases ≥ 14 days old; Dependabot uses `cooldown: 14`. Image tags are pinned with digests.
- PHP 8.5 has OPcache built in: do not `docker-php-ext-install opcache` (the build fails with `cp: can't stat 'modules/*'`).
- Symfony recipes write `.env.dev` with a random `APP_SECRET`; it is git-ignored and must not be committed (gitleaks catches it). Secrets come from the root `.env` via Compose.
- Migrations run as the migrator/owner roles (`MIGRATIONS_DATABASE_URL`); the apps run as `tabularius_app` / `business_reader`. A table created by another role is invisible to the app roles unless granted.
- `vue-tsc` 3.3 does not work with TypeScript 7 (missing `typescript/lib/tsc` export); keep TypeScript 5.9 until vue-tsc supports it.
