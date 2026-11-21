# TASK-005 — Manual ticket → report → authorized download (no mail, no LLM)

Status: PLANNED · Priority: high · Increment: 2 · Depends on: TASK-003, TASK-004

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
