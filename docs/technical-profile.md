# Technical profile

As of 2026-11-21 (technical decisions; no backend code yet). Versions are checked against registries on that date. Packages released less than 14 days ago are not used. Manifests become the source of exact versions once they exist.

## Stack

- Backend (`api/`): PHP 8.5 (`php:8.5-fpm-alpine`; 8.6.0 is newer than 14 days), Symfony 7.4 LTS, Doctrine ORM 3 and Migrations. Owner decision: PHP/Symfony instead of the AppStandards default FastAPI.
- Demo business system (`business/`): a separate small Symfony 7.4 app with its own PostgreSQL database, migrations and seed. It exposes a read-only REST API ([ADR 0002](decisions/0002-separate-business-system-behind-read-only-api.md)).
- Database: PostgreSQL 18 (`postgres:18-alpine`). The same major version as the owner's other projects, so they share host tooling. Two databases on one server in development: `tabularius` and `business`, each with separate roles.
- Background work: Symfony Messenger with the Doctrine (PostgreSQL) transport. Symfony Scheduler handles mailbox polling, retention cleanup and stuck-job checks. The worker is a separate process from the same image. There is no Redis/Valkey until a demonstrated need ([ADR 0001](decisions/0001-modular-monolith-symfony-messenger.md)).
- Money: `brick/math` / `brick/money` (decimal); DB `numeric(14,2)`; rounding `HALF_UP` only where a rule says so (RPT-DISC-3).
- Time: UTC `timestamptz` in DB; period resolution in `Europe/Warsaw` (DATE-*); clock injected (`symfony/clock`) for tests.
- CSV: `league/csv` with the project's own formula guard (RPT-OUT-4).
- Mail:
  - outgoing through `symfony/mailer` (SMTP) behind an adapter; Mailpit locally.
  - incoming through IMAP behind an adapter; the library is chosen in increment 3, after the provider's IMAP/SMTP support is verified; a stub until then.
- LLM: the OpenAI API, called directly with `symfony/http-client`. Schema-constrained output, no tools ([ADR 0004](decisions/0004-llm-interprets-into-closed-plan-only.md)). The model and prices are verified in the official docs in increment 4. CI uses test doubles.
- Frontend (`client/`): Vue 3, TypeScript, Vite, vue-router. The appearance comes from `src/styles/mockup.css` (generated from the owner's mockups) plus `extras.css`.
- Tooling: Docker Compose and `make` only. The host's Node 16 is too old, so everything runs in containers.

## Contracts and conventions

- REST/JSON under `/api/v1`, camelCase fields, Problem Details (RFC 9457) with `code`, page pagination (`page`, `perPage` ≤ 100).
- API contract: code is the source (typed DTOs and `nelmio/api-doc-bundle` attributes). CI exports `docs/api/openapi.yaml` and fails if the committed file differs. The client generates types from it with `openapi-typescript`.
- Auth: a server session in an HttpOnly, Secure, SameSite=Lax cookie, with sessions stored in PostgreSQL. CSRF tokens protect state-changing requests. Argon2id password hashes ([ADR 0003](decisions/0003-server-sessions-in-postgresql.md)).
- Same origin: a reverse proxy (Caddy) serves `client` at `/` and the `api` at `/api`.
- Business system access: only through an integration-profile adapter (`rest-json` for the demo), using a bearer token from the environment. The business API's runtime database role has SELECT on approved views only.

## Quality checks (planned for setup)

- Backend: PHPUnit (unit and integration against a test database), PHPStan at the maximum level, PHP-CS-Fixer, `composer audit`.
- Frontend: vue-tsc, ESLint, Prettier, Vitest, `npm audit`; Playwright for the main flow and the 360 px / desktop check.
- Coverage threshold per the quality-checks standard, enforced in CI.
- Secrets: gitleaks in pre-commit and CI.
- CI: GitHub Actions, job `check`.

## Pitfalls

- `vue-tsc` 3.3 does not work with TypeScript 7; keep TypeScript 5.9.
- Mockup CSS uses `:has()` and fixed artboard sizes; the fluid adjustments are listed in `docs/design/prototype/README.md`.

## Open

- Hosting for staging and the demo (owner question Q3). The candidate is the owner's shared VPS, which blocks outgoing SMTP ports 25, 465 and 587 (Profilo runbook). Needs an SMTP relay port check and an IMAP 993 reachability check before increment 3.
