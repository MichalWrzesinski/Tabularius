# 04 — First correct report (deterministic engine, no LLM)

## 1. Context
- Date: 2026-11-22. Stage: increment 2. Tasks: TASK-002 (PR #15, merged), TASK-003 (PR to be opened from `feat/TASK-003-report-engine`).
- Environment: local Docker Compose. **Synthetic data**: business seed 2026.11-1, reference date 2026-11-15. Engine tests use **captured business API responses** served to the real adapter by a mock HTTP client. No LLM, mail or real organization data.

## 2. Goal and initial state
Brief §11.2: „first obtain correct results without an LLM”. Initial state: rules in `docs/product/areas/reports.md` and an empty Symfony API.

## 3. Options and decisions
| Question | Options | Decision |
|---|---|---|
| How to get known answers | random seed and compute expectations from it; hand-placed records for one representative | Hand-placed records for PH-01 in the two months before the reference date, random data elsewhere. Expected values were written before the engine existed (business/README.md) |
| Where money is computed | in SQL; in PHP with floats; in PHP with decimals | PHP with `BigDecimal`. The business API returns decimal strings, and the adapter rejects floats |
| Dates from the interpreter | ISO dates; closed period expressions | Expressions resolved by the backend (decided in stage 01). Now implemented and tested |
| Test fixtures | live business API in tests; captured responses | Captured responses through the real adapter: fast and deterministic. Drift risk recorded |
| Spreadsheet check | manual; headless LibreOffice | Headless LibreOffice (`make csv-check`). Excel is still manual |

## 4. Work, findings, failures
- Observation: a first API run returned 500 because DBAL 4 rejects `PDO::PARAM_INT` as a parameter type. Fixed with `ParameterType::INTEGER`.
- Observation: the adapter first passed whole response bodies instead of `items` for non-paginated lists. The live `app:report` run caught it.
- Observation: Alpine's `libreoffice-calc` package alone crashed on headless conversion (`RuntimeException`); the full `libreoffice` package works.
- Observation: in LibreOffice Calc (pl-PL) the formula guard works, but the apostrophe remains visible in the cell.
- Observation: the brief demands the CSV be tested in the target tool. Excel was not available to the agent, so that part is open.

## 5. Contributions
- Owner: accepted the plan („ok, kontynuuj”) and approved closing 12 Dependabot PRs.
- Agent: seed design, known answers, engine, tests, LibreOffice check. No human corrections in this stage.

## 6. Criteria and evidence
| Criterion | Evidence | Result | Limitation |
|---|---|---|---|
| AC-1 known query = independent expectation | `api/tests/Unit/Report/ReportEngineTest.php` | October CSV byte-for-byte; discount 2 500,01 | Fixture inputs tied to seed 2026.11-1 |
| DATE rules | `PeriodResolverTest` (19 valid, 12 invalid, 9 malformed cases) | pass | — |
| Data-source failures controlled | `RestBusinessApiTest` | pass | Circuit breaker not implemented yet |
| CSV opens correctly | `make csv-check` | LibreOffice: 68 numbers, 4 guarded texts, 0 formulas | Excel not checked |
| Seed reproducible | `business/tests/Functional/SeedTest.php` (pinned checksum) | pass | — |

## 7. Measurements
Agent time, tokens and cost: unavailable. Application LLM cost: none (no LLM yet).

## 8. Outcome and next stage
Correct reports exist without an LLM. Next: TASK-004 (login, sessions, accounts) and TASK-005 (manual ticket → authorized download). Remaining risks: fixture drift and the visible apostrophe in guarded cells.

## 9. Possible publication angle
„Write the expected numbers before the code”: the discount boundary 50 000,10 → 2 500,01 (half up vs. half even) was planted in the seed on day one and caught by a byte-level CSV test.
