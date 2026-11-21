# TASK-001 — Runnable foundation and CI

Status: READY · Priority: high · Increment: 2

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
