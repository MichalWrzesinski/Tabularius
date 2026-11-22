# Technical profile

As of 2026-11-21 (TASK-001 foundation). Versions are checked against registries on that date. Packages released less than 14 days ago are not used. Manifests become the source of exact versions once they exist.

## Stack

- Backend (`api/`): PHP 8.5 (`php:8.5-fpm-alpine`; 8.6.0 is newer than 14 days), Symfony 7.4 LTS, Doctrine ORM 3 and Migrations. Owner decision: PHP/Symfony instead of the AppStandards default FastAPI.
- Demo business system (`business/`): a separate small Symfony 7.4 app (DBAL only, no ORM) with its own PostgreSQL database, migrations and deterministic seed. It exposes a read-only REST API ([ADR 0002](decisions/0002-separate-business-system-behind-read-only-api.md), endpoints and known answers in [business/README.md](../business/README.md)).
- Database: PostgreSQL 18 (`postgres:18-alpine`). The same major version as the owner's other projects, so they share host tooling. Two databases on one server in development: `tabularius` and `business`, each with separate roles.
- Background work: Symfony Messenger with the Doctrine (PostgreSQL) transport. Symfony Scheduler handles mailbox polling, retention cleanup and stuck-job checks. The worker is a separate process from the same image. There is no Redis/Valkey until a demonstrated need ([ADR 0001](decisions/0001-modular-monolith-symfony-messenger.md)).
- Money: `brick/math` 1.x `BigDecimal` (decimal strings end to end; the adapter rejects floats); DB `numeric(14,2)`; rounding `HalfUp` only in the discount simulation (RPT-DISC-3).
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

## Quality checks

- Backend: PHPUnit (unit and integration against a test database), PHPStan at the maximum level, PHP-CS-Fixer, `composer audit`.
- Frontend: vue-tsc, ESLint, Prettier, Vitest, `npm audit`; Playwright for the main flow and the 360 px / desktop check.
- Coverage gates: backend line coverage ≥ 95 % per app (`scripts/coverage-threshold.php`; money, dates and access rules live there); client logic modules `src/lib/**` ≥ 90 % lines (Vitest), views are covered by Playwright paths.
- Secrets: gitleaks in pre-commit and CI.
- CI: GitHub Actions `ci.yml` — job `check` runs `make ci` and builds the three production images; job `publish` pushes `ghcr.io/michalwrzesinski/tabularius-{api,business,client}:<sha>` after merge to main. Actions pinned to commit SHAs. Repository settings: `scripts/setup-github.sh` (run by the owner).
- Images: PHP-FPM (`infra/php/Dockerfile`, targets `dev`/`prod`, non-root `www-data`, FastCGI healthcheck), client static build on Caddy (non-root). Local reverse proxy: `infra/caddy/Caddyfile` (public `:80` → 127.0.0.1:8080; internal `:8081` for the business API).

## Pitfalls

- `vue-tsc` 3.3 does not work with TypeScript 7; keep TypeScript 5.9.
- Mockup CSS uses `:has()` and fixed artboard sizes; the fluid adjustments are listed in `docs/design/prototype/README.md`.

## Hosting and mail (owner, 2026-11-22)

- Server: the owner's VPS `151.240.121.172`. Application directory `/srv/Tabularius`, which currently holds the placeholder page; those files are removed only with the owner's confirmation at the first deployment. TLS is already set up on the host. The agent deploys when verification requires it, not at a fixed stage.
- Mailbox: `tabularius@gitpush.app`. Outgoing mail from the server goes through Brevo, which the owner has configured and tested for this address. SMTP credentials come only from secrets.
- Still to verify at the first deployment (needed for increment 3):
  - ~~IMAP host, port and TLS for incoming mail~~ — `mail.gitpush.app:993` (implicit TLS, valid certificate; 143 closed), verified 2026-11-22;
  - the Brevo SMTP port that works from this server (on the Profilo staging host, 25, 465 and 587 are blocked; 2525 works);
  - free resources for two databases, a worker and report files.
- Staging setup (2026-11-22, [runbook](runbook.md#staging-owners-shared-vps)): the host's nginx forwards the domain to the stack's Caddy on `127.0.0.1:8091` (Docker network 172.29.0.0/24). The placeholder page is moved to `/srv/Tabularius-placeholder`, not deleted. A dedicated `tabularius_deploy` user is restricted to key login. GitHub Actions deploys the GHCR images of each green commit on `main`. Server secrets are generated on the server. No real mail yet (demo capture transport is `null://`).
