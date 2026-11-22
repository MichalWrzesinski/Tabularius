# 05 — Accounts and the first full loop (no mail, no LLM)

## 1. Context
- Date: 2026-11-22. Stage: increment 2 (closing). Tasks: TASK-004 (PR #17, merged), TASK-005 (PR from `feat/TASK-005-manual-ticket-loop`).
- Environment: local Docker Compose with a worker. **Synthetic data only**: business seed 2026.11-1. Functional tests freeze the clock at 2026-11-15 and serve captured business responses to the real adapter.

## 2. Goal and initial state
Close the loop request → ticket → authorized file before adding mail and the model. Initial state: correct reports from the CLI (stage 04); tickets and files only in the prototype mock.

## 3. Options and decisions
| Question | Options | Decision |
|---|---|---|
| Job queue | Messenger with Redis; Messenger with the Doctrine transport; cron | Doctrine transport on the same PostgreSQL: the job row is written in the ticket's transaction, so a ticket is never queued without its job (ADR 0001) |
| Idempotency per (ticket, plan version) | check in code only; unique constraint only; both | Both: row lock + version/status/file checks, backed by `UNIQUE (ticket_id, plan_version)` |
| When to re-check access to a file | at generation; on every request | On every metadata, preview and download request, against the business system's current assignments; fails closed |
| Another representative's file | 403; 404 | 404 — indistinguishable from a missing file |
| Ticket creation form | wait for mail; manual form | Manual form now (no mockup; built from existing mockup patterns), so the loop and access rules are testable before mail |

## 4. Work, findings, failures
- Observation (review before merging PR #17): the public password reset had no limit, so anyone could keep resetting a representative's password, logging them out and filling their inbox. Fixed with limits per address and per IP before the merge.
- Observation: the worker took up to a minute to pick up jobs. The PostgreSQL Doctrine transport waits for LISTEN/NOTIFY, whose trigger is created only by `auto_setup`, which the app role cannot run. Polling every second (`check_delayed_interval=1000`) fixed it; the first attempt used an option that does not exist in this version (`use_notify`) and broke ticket creation with a 500 until the E2E run caught it.
- Observation: report files were written to a literal `%kernel.project_dir%` directory, because `.env` values are not expanded without the `resolve:` processor.
- Observation: tests with an in-memory queue lost jobs whenever the test kernel rebooted between requests; the tests now use the real Doctrine queue.
- Concurrency was tested by running a second delivery of the same job inside the first one's execution (a hook in the business test double), not by sleeping threads.

## 5. Contributions
- Owner: „kontynuuj”; hosting details for the later staging deployment.
- Agent: implementation, tests, E2E, review fix. No human corrections in this stage.

## 6. Criteria and evidence
| Criterion | Evidence | Result | Limitation |
|---|---|---|---|
| CP-001 (manual): file equals the fixture | `TicketFlowTest`, `e2e-cp001.mjs` | download byte-for-byte equal to the engine fixture; UI path passes at 360 px | No mail link yet |
| CP-003 (files) | `TicketFlowTest`, `e2e-cp001.mjs` | foreign file 404; reassigned customer 403 at the next request; blocked 401; expired 410 | Reassignment only in API tests |
| Duplicate job → no second file | `TicketFlowTest` (duplicate, stale, interleaved) | one file, losing content deleted | — |
| CP-004 | `e2e-cp004.mjs`, `AuthTest` | passes; reset limited | Filesystem rate-limit cache (one instance) |
| Coverage gates | `make check` | API 97 %, client lib 100 % lines | — |

## 7. Measurements
Agent time, tokens and cost: unavailable. Application LLM cost: none.

## 8. Outcome and next stage
The first end-to-end loop works without mail or the model. Next: the first staging deployment on the owner's VPS, then increment 3 (mail ingestion, identity, durable tickets from email).

## 9. Possible publication angle
„Test the race, not the happy path”: one job delivered twice, the second one finishing while the first is still computing — one file survives, and the database constraint is the last line of defence.
