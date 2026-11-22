# TASK-005 — Manual ticket → report → authorized download (no mail, no LLM)

Status: DONE (PR #18) · Priority: high · Increment: 2 · Depends on: TASK-003, TASK-004

## Goal and scope
Close the first end-to-end loop before mail and the LLM:
- The admin creates a ticket on behalf of a representative by choosing an operation and parameters in a form. The ticket stores plan v1 with author `admin`.
- Execution runs in the worker, idempotent per (ticket, plan version).
- The file is stored with metadata and retention.
- „Moje raporty” and the report view use the real API. The overview, ticket list and ticket details read real tickets.
- The prototype mock layer remains only for screens not yet backed by the API, clearly marked.

## Acceptance
- CP-001 without email: the admin creates a sales ticket for October 2026, the representative downloads a file equal to the TASK-003 fixture.
- CP-003 file part:
  - another representative gets 404 on the file URL;
  - a reassigned customer blocks the download at the next request;
  - a blocked account cannot download.
- A duplicate job for the same plan version produces no second file.

## Verification
API tests and Playwright (desktop and 360 px). Critical paths: CP-001 (partial), CP-003 (partial).

## Report
- Modules:
  - `api/src/Module/Ticket`: `TicketStatus` (transition table from TKT-STATUS), `TicketStore` (DBAL, row locks), `TicketService` (manual creation, retry, reject — each in one transaction with its events and audit), `Job/ExecutePlan` + `ExecutePlanHandler`, `Http/AdminTicketController`, `Http/TicketView` (resolved parameters with concrete dates).
  - `api/src/Module/File`: `FileStore`/`LocalFileStore` (outside the web root, random keys, atomic writes, mode 0600), `ReportFiles` (metadata), `FileAccess` (ACC-FILE-1), `Http/FileController`; `app:files:purge` for retention.
  - `ReportEngine::check()` validates a plan with the same parsing and scope rules as `run()`, without reading report data; `ReportResult::customers` lists every customer a file may contain.
  - Symfony Messenger with the Doctrine transport on PostgreSQL; `worker` service in Compose. Migration `Version20261122150000` (ticket, ticket_plan, ticket_event, report_file, messenger_messages).
- Execution (TKT-VER) in three steps under the ticket's row lock: claim (current version, queued or running, no file for the version, owner re-checked) → deterministic engine outside the transaction → keep the file only if the ticket still runs that version and no file exists. `UNIQUE (ticket_id, plan_version)` backs it in the database. Business failures become `needs_intervention` with a reason code (`data_source_error`, `internal_error`, `out_of_scope`, `invalid_parameters`, `too_large`); a blocked owner before execution → `rejected` (`owner_inactive`).
- File access (ACC-FILE-1) on every metadata, preview and download request: another representative's file is 404 (same as missing); the owner needs the same representative code and every customer in the file still assigned (business API, fails closed with 503); expired → 410; the administrator sees all non-expired files. Downloads are audited (`file.download`), `Cache-Control: no-store`.
- API: `GET/POST /api/v1/admin/tickets`, `GET /admin/tickets/{id}`, `POST /admin/tickets/{id}/retry|reject`, `GET /admin/catalog`, `GET /admin/accounts/{id}/customers`, `GET /admin/files`, `GET /me/files`, `GET /me/tickets`, `GET /files/{id}`, `GET /files/{id}/content`. Manual creation is limited to 60 per hour per administrator account.
- Dashboard: overview, ticket list, ticket details (retry/reject, history, file and preview), „Pliki”, „Moje raporty” and the report view use the API. New screen „Nowe zgłoszenie” (no mockup; built from the „Pracownicy” form patterns). The ticket view polls while queued or running. Prototype mocks remain only for the production confirmation screen and the prototype panel toggles.
- `make seed` also runs `app:tickets:demo`, which queues three sample tickets for the shared demo representative (one per operation).
- Review fix carried into this branch: password reset is now limited per address (3/h) and per client IP (10/h) — previously anyone could reset a representative's password repeatedly (merged with PR #17).
- Evidence:
  - API: 183 tests, PHPStan max, line coverage 97 %. `TicketFlowTest` covers CP-001 byte-for-byte (the download equals `tests/Fixtures/expected/sales_PH-01_2026-10.csv`, the same bytes the engine test asserts), duplicate and stale jobs, a second job interleaved with a running one (one file, the loser's content deleted), another representative 404, reassigned customer 403 at the next request, business system down → 503 on download, blocked account 401, expired 410 and purge, blocked owner before execution, data-source failure → retry → completed, creation validation (catalog, extra parameter, period, scope, owner), role checks, failures during execution.
  - Client: 45 Vitest tests, 100 % lines in `src/lib`.
  - UI E2E `client/scripts/e2e-cp001.mjs` passed locally: admin creates the October sales ticket in the form → worker completes it → the shared representative opens it at 360 px (no horizontal scroll) and downloads the CSV → a second representative gets 404 on metadata and content and sees „Ten raport jest niedostępny”.
  - `compare-mockups.mjs`: 03 and 04 on real data match the mockups at 1500 and 390 px, no overflow.
- Found and fixed during the task:
  - the Doctrine transport on PostgreSQL waits for LISTEN/NOTIFY; without the trigger (the app role has no DDL, `auto_setup=0`) the worker read the queue only every 60 s → `check_delayed_interval=1000`;
  - `%kernel.project_dir%` in `.env` is not expanded without the `resolve:` processor (files landed in a literal `%kernel.project_dir%` directory);
  - in-memory Messenger transport loses jobs when the test kernel reboots between requests → tests use the Doctrine queue;
  - `count()` is final in PHPUnit `TestCase` (added to AGENTS.md pitfalls).
- Limitations:
  - no notification e-mail when a report is ready (mail arrives in increment 3);
  - reassignment and blocking are verified in API tests, not in the browser E2E (the demo business data are read-only);
  - `app:files:purge` must be scheduled on the server (staging deployment);
  - E2E scripts still run only against the local stack.
