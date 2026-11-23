# TASK-014 — Failure scenarios: exhausted jobs, unknown sends, bounces

Status: IN PROGRESS · Priority: high · Increment: 6 · Depends on: TASK-005, TASK-009, TASK-013 · Rules: [tickets.md](../product/areas/tickets.md) (TKT-STATUS, notification state, MAIL-2, MAIL-3), brief §8 and §10

## Goal and scope
No failure leaves a ticket or a notification in a state that pretends work is still going on. No failure resends mail without a decision.
- **Exhausted jobs:** a job whose retries are exhausted (it reaches the `failed` transport) stops claiming progress:
  - **Ticket jobs:** an interpretation or execution job moves its ticket to `needs_intervention` with the reason `processing_failed`, but only if the ticket is still in a processing state for that job's version.
  - **Notification jobs:** the outbox row moves from `pending` to `failed`, or from `sending` to `unknown`.
- **Stalled tickets:** a crashed worker is covered by Messenger redelivery after one hour. A ticket still in `received`, `interpreting`, `queued` or `running` after 24 hours moves to `needs_intervention` with the reason `processing_stalled`. This runs in the daily `app:tickets:expire`. A late job for that ticket then finds nothing to claim.
- **Unknown or failed sends:** the administrator resolves them in the ticket details. The choice is recorded in the history and the audit log.
  - **Sent:** mark an `unknown` row as sent; it becomes `accepted`, with the note that the administrator decided it.
  - **Resend:** queue an `unknown` or `failed` row again with the same `Message-ID`, so a client that already has it shows one copy. Refused when the contents were already purged by retention.
  - Nothing is resent automatically.
- **Bounces (MAIL-2):**
  - **Detection:** a delivery-status report (`multipart/report; report-type=delivery-status`, or `X-Failed-Recipients`) that names one of our outgoing `Message-ID`s marks that outbox row `bounced` and records it in the ticket history. Our ids are found in `In-Reply-To`/`References` or in a `Message-ID` header of the returned part.
  - **Unaffected:** auto-replies (out of office) that only answer our message do not mark it `bounced`.
  - **Allowed states:** a row that was `accepted` or `unknown` may become `bounced`.
  - **No new tickets:** a bounce still never creates a ticket and is never answered.

## Acceptance
- **Exhausted jobs:** an execution job that throws on every attempt ends with the ticket in `needs_intervention` / `processing_failed`. The ticket is never left in `running` and is never reported as completed. Admin retry then completes it.
- **Stale jobs:** the failure handler of an older version does not touch a ticket that has moved on.
- **Notification jobs:** an exhausted notification job leaves its row `failed` (pending) or `unknown` (sending), never `sending`.
- **Stalled tickets:** a ticket stuck in `running` for over 24 hours becomes `needs_intervention` / `processing_stalled`, and one updated recently is left alone.
- **Resolving sends:**
  - an `unknown` row resolved as sent becomes `accepted`;
  - resend queues exactly one send;
  - resolving an `accepted` row or one whose contents were purged is refused (409);
  - only an administrator may resolve.
- **Bounces:** a DSN for our message marks it `bounced`. An out-of-office reply does not. A DSN naming an id that is not ours changes nothing.
- **Dashboard:** labels for `bounced`, `processing_failed` and `processing_stalled`; resolve buttons only on `unknown` and `failed` rows.

## Verification
- **API:** functional tests for every acceptance item.
- **Client:** unit tests for the labels.
- **Staging:** the deployment smoke tests.

## Report
- **Exhausted jobs:** `JobFailureSubscriber` listens to `WorkerMessageFailedEvent`. When Messenger's own retry listener gives up (3 retries), it acts on the job:
  - **`ExecutePlan`:** the ticket moves to `needs_intervention` / `processing_failed` while it is still `queued`/`running` for that version.
  - **`InterpretTicket`:** the same move, while the ticket is `received`/`interpreting` after that version.
  - **`SendNotification`:** the row goes from `pending` to `failed`, or from `sending` to `unknown`.
  - **Stale jobs:** a failure for an older version, or for a ticket that has moved on, changes nothing.
- **Stalled tickets:** `TicketService::expireStalled(24)` runs in `app:tickets:expire` (the daily `purge` container). A processing ticket untouched for 24 hours moves to `needs_intervention` / `processing_stalled`. A late job then finds nothing to claim.
- **Unknown or failed sends:** `POST /api/v1/admin/tickets/{id}/notifications/{nid}/resolve` with `sent` or `resend`, recorded in the ticket history and the audit log (`notification.resolve`).
  - **`sent`:** allowed only for `unknown`; the row becomes `accepted` with the note `resolved_by_admin`.
  - **`resend`:** allowed for `unknown` and `failed`. It queues the same row with the same `Message-ID`. It is refused with `notification_purged` once retention has deleted the contents.
  - **Dashboard:** the ticket details show both buttons with a confirmation dialog.
- **Bounces (MAIL-2):** `MessageParser` reads the ids named by a delivery-status report (`multipart/report; report-type=delivery-status` or `X-Failed-Recipients`): `In-Reply-To`/`References` and every `Message-ID` header of the returned part. `Ingestor` marks a matching `accepted`/`unknown` row `bounced` and records it in the ticket history. Auto-replies are still recorded as `auto_reply` and never mark anything.
- **Migration `Version20261123180000`:** outbox status `bounced`.
- **Dashboard labels:**
  - the delivery state `bounced` („zwrot — nie doręczono”);
  - the reasons `processing_failed` and `processing_stalled`, both retryable when the ticket has a plan.
- **Tests:**
  - API: 420 tests, 96.21 % lines. `FailureScenarioTest` (6) covers exhausted execution and retry, a stale failure, a failure after completion, an exhausted interpretation, exhausted notifications, stalled tickets, resolving sends (roles, validation, sent, resend once with the same id, purged), and a bounce against an auto-reply and a foreign id.
  - Client: 47 Vitest tests.
- **Not verified:** the resolve buttons were not checked in a browser (only by unit tests of their logic). A real bounce from the staging SMTP provider has not been observed.
