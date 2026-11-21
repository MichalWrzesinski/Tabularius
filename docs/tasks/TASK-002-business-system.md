# TASK-002 — Demo business system: schema, seed, read-only API

Status: PLANNED · Priority: high · Increment: 2 · Depends on: TASK-001

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
