# TASK-009 — Outbox and the first notifications (confirmation request, report ready)

Status: PLANNED · Priority: high · Increment: 3 · Depends on: TASK-007

## Goal and scope
Outgoing mail is written in the ticket's transaction and sent afterwards (MAIL-3, MAIL-4). It goes only to the ticket's own recipient: the registered address in production, the sender of that ticket in demo (ID-DEMO-2).
- Outbox table with delivery states `pending`, `sending`, `accepted`, `unknown` and `failed`. There is no automatic retry after an unknown outcome.
- `Message-ID` with an unpredictable token stored per ticket, so increment 5 can correlate replies.
- Templates (Polish, plain text plus safe HTML without remote content):
  - `received`;
  - `confirmation_request` (production);
  - `report_ready`, with a link to the report after login and no data in the mail;
  - `refused`.
- Transports:
  - locally, Mailpit;
  - on staging, Brevo over SMTP, only to controlled test addresses until the public demo is approved; any other recipient is captured, not delivered.
- Ticket history shows delivery events.

## Acceptance
- A duplicate send job sends nothing twice.
- A crash between commit and send leaves the message `pending`, and it is sent once later.
- Header injection in any value is rejected.
- No report data appears in any mail.
- CP-001 by e-mail works locally: request → ticket → plan → file → `report_ready` in Mailpit → login → download.

## Verification
Functional tests with a mailer double. E2E CP-001 by e-mail against the local stack. On staging, after the owner agrees and names the test addresses: one controlled message per template.
