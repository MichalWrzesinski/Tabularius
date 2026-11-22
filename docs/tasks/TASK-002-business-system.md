# TASK-002 — Demo business system: schema, seed, read-only API

Status: DONE (on merge) · Priority: high · Increment: 2 · Depends on: TASK-001

## Goal and scope
A synthetic, reproducible business system ([ADR 0002](../decisions/0002-separate-business-system-behind-read-only-api.md)):
- Schema and reporting views.
- A deterministic seed with a fixed random seed and data version. Planned volume: 5 manufacturers, 200 parts, 30 customers, 5 representatives, and documents spread around a reference date (past and future).
- Edge cases in the seed: customers without sales, adjustments, cancellations, similar names („Alfa Motors”, „Alfa-Tech”, „Alfatrans”), inactive records, discount boundary totals, text fields starting with formula characters.
- `make seed-refresh REF=YYYY-MM-DD` shifts dates relative to a new reference date and is documented in the README.
- Read-only bearer-token API for the profile operations, with pagination and scope checks.

## Acceptance
- Two seed runs produce identical data (checksum test).
- Future-dated documents exist and never appear in a period ending yesterday.
- The API rejects missing or invalid tokens and unknown parameters, and never returns non-permitted part fields.
- `business_reader` sees views only.

## Verification
API tests against the seeded database; checksum test. Critical paths: prepares CP-001, CP-003.

## Report
- Schema and views: `business/migrations/Version20261122090000.php`. `business_reader` can SELECT only on the `v_*` views (tests: DDL/DML/SELECT on tables denied with 42501; no purchase or supplier columns in the catalog view).
- Seed: `App\Seed\DemoDataSeeder` with fixed random seed 20261122, version 2026.11-1, dates relative to the reference date. Command `app:seed` / `make seed [REF=…]`; refuses unless `BUSINESS_ALLOW_SEED=1`. Two runs give the same content checksum, which is pinned in `SeedTest`. Refreshing to 2027-02-10 moves the known answers to January 2027. On the 1st of a month, the previous month's totals stay exact.
- Known answers for PH-01 (2026-11-15) checked through the views by `KnownAnswersTest`:
  - October: 754,10 / 745,00 / 319,20 / 50 000,10 / 1 289,00;
  - September: 410,00 (sale date, not issue date) / 9 999,99;
  - 1–14 November contains only yesterday's line; today and future documents are excluded;
  - the canceled invoice is excluded; the customer without sales and the inactive customer are covered.
- API: `ApiTest` covers token rejection (missing, wrong, other scheme), scope refusal without leaking names, 14 invalid query cases including injection attempts and array parameters, pagination, filters, permitted catalog fields, inactive manufacturer, fictional policy, and 405/404 as Problem Details.
- Checks: php-cs-fixer clean, PHPStan max clean, PHPUnit 48 tests, line coverage 97.93 % (gate 95 %).
- Also in this PR: the five review findings from PR #1 — coverage gate argument validation, CI caches download directories instead of installed trees, dead gitleaks allowlist entry removed, UID/GID written to `.env`, CSP header plus `/api` → `/api/` redirect.
