# TASK-001 — Runnable foundation and CI

Status: DONE (on merge) · Priority: high · Increment: 2

## Goal and scope
After cloning, `make up` starts the whole stack locally: caddy, client, api, worker, business, db, mailpit. `make check` runs all checks, and CI runs the same set as job `check`.
- Symfony 7.4 skeletons in `api/` and `business/`. Health endpoints: `/health` (process only) and `/ready` (with dependencies).
- PostgreSQL 18 with databases `tabularius` and `business` and the four roles from [architecture](../architecture.md#database-roles), created by an init script.
- `client/` keeps the prototype views and is served through caddy. The API is reachable at `/api`.
- Makefile: `up`, `down`, `migrate`, `seed`, `test`, `check`, `check-backend`, `check-frontend`.
- Gitleaks pre-commit hook and CI step. Pinned images (patch version + digest) and actions pinned to commit SHAs. Dependabot with a 14-day cooldown.
Out of scope: business data, auth, reports.

## Acceptance
- A fresh clone plus `make up` serves the prototype at http://127.0.0.1:8080. `/api/health` returns 200 and `/api/ready` returns 200 with the database up.
- Ports bind to 127.0.0.1 only.
- `business_reader` cannot run DDL or DML. The test tries it and expects a failure.
- `make check` passes locally. The same check passes in GitHub Actions on the PR.

## Verification
CI job `check`; manual `make up` from a clean clone. Critical paths: no impact yet (foundation).

## Report
- `make up` from the working tree: all services start; `/api/health` 200, `/api/ready` 200 with the database, business `/ready` 200 on the internal port; only `127.0.0.1:8080` is published.
- `make check-backend` (api, business): php-cs-fixer clean, PHPStan level max without errors, PHPUnit green (api 3, business 5 tests incl. `business_reader` DDL/DML/SELECT denial with SQLSTATE 42501), line coverage 100 % (gate 95 %), `composer audit` clean.
- `make check-frontend`: ESLint + Prettier clean, vue-tsc clean, Vitest 26 tests, logic coverage 100 % lines (gate 90 %), production build, `npm audit --omit=dev` 0 vulnerabilities. `make secrets`: no leaks (after removing recipe-generated `.env.dev` files).
- Production images build and report healthy; processes run as non-root; `/api/ready` without a database returns 503 JSON.
- Not yet verified: fresh-clone run and CI on GitHub (this PR); branch protection depends on `scripts/setup-github.sh` run by the owner.
- Decisions: Composer cooldown policy enforces the 14-day rule; Node 26.13.0 instead of 26.13.1 and PostgreSQL 18.6 instead of 18.7 for the same rule; `final_class` fixer rule dropped (Doctrine proxies).
