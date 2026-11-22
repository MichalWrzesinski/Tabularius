# TASK-003 — Deterministic report engine

Status: DONE (on merge) · Priority: high · Increment: 2 · Depends on: TASK-002

## Goal and scope
Correct reports without an LLM (brief §11.2):
- Integration profile `demo-autoparts` v1.
- Period resolver for all DATE-* expressions.
- Executors for `sales_report`, `parts_catalog` and `customer_discount_simulation` through the `rest-json` adapter.
- CSV writer following RPT-OUT-* (BOM, `;`, decimal comma, metadata rows, formula guard), with decimal money.

## Acceptance
- AC-1: for fixed fixture queries, files equal expected results computed independently in the test (not by the engine). Covers adjustments, cancellations, empty periods and every boundary in RPT-DISC-2, including 50 000,10 → 2 500,01.
- DATE examples from `reports.md` pass, including „listopad” on 2026-11-15 → November 2025, „this month” on the 1st, and explicit periods that include today (rejected, never shortened).
- A business API timeout or 5xx gives a controlled data-source error, not a partial file.
- The CSV opens correctly in LibreOffice Calc (scripted headless conversion check). An Excel check is recorded manually.

## Verification
Unit and integration tests; fixture files under `api/tests/fixtures/reports/`. Critical paths: CP-001 (engine part).

## Report
- Module `api/src/Module/Report`:
  - integration profile `config/integration/demo-autoparts.v1.yaml` with its loader;
  - period expressions and resolver (DATE-*);
  - `rest-json` adapter to the business API;
  - engine for the three operations;
  - decimal money via brick/math (rounding half up only in the discount);
  - CSV writer.
- Operator and developer tool: `bin/console app:report <operation> '<json>' --rep=PH-01 --reference-date=YYYY-MM-DD [--output=file]`.
- AC-1 (`tests/Unit/Report/ReportEngineTest`), run through the real adapter on captured API responses for 2026-11-15 (`tests/Fixtures/business`):
  - the full October CSV equals a hand-written expectation, including the adjustment, the excluded canceled invoice, zero rows for customers without sales, and the total 53 107,40;
  - discount simulation for Moto-Lux at 50 000,10 → 5 % → 2 500,01 (half up), below the tier → 0 %, inactive customer → 0 % with a reason;
  - an empty period is a valid result.
- DATE examples (`PeriodResolverTest`): „listopad” on 2026-11-15 → November 2025; „this month” on the 1st → question; explicit 1–15 November → correction with the suggestion 1–14; DST-correct instants. Malformed expressions are rejected.
- Refusals and validation:
  - scope refusal before any sales data is read;
  - operations outside the catalog are rejected before any data access;
  - extra parameters (e.g. a recipient) are rejected;
  - unknown manufacturer, category or policy are rejected.
- Data-source errors (`RestBusinessApiTest`):
  - timeout and 5xx → controlled data-source error;
  - 403 → scope refusal; 401 → contract bug;
  - floats, invalid decimals and invalid lists are rejected;
  - pagination works, and exceeding the row limit refuses the report instead of truncating it.
- `make csv-check` (LibreOffice Calc headless, pl-PL) on `docs/qa/csv/*`: 68 amounts imported as numbers, 4 guarded cells stay text, no formulas. Excel check still manual (not done).
- Checks: php-cs-fixer, PHPStan max, PHPUnit 120 tests, line coverage 97.86 % (gate 95 %).
- Limitations:
  - fixtures can drift from the business API if the seed changes. They are tied to seed 2026.11-1, and the README says how to regenerate them;
  - the apostrophe of guarded cells remains visible in spreadsheets.
