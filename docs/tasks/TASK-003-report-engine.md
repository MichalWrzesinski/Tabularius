# TASK-003 — Deterministic report engine

Status: PLANNED · Priority: high · Increment: 2 · Depends on: TASK-002

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
