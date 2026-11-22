# TASK-009 — Outbox and the first notifications (confirmation request, report ready)

Status: DONE (PR #27) · Priority: high · Increment: 3 · Depends on: TASK-007

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

## Report
- Module `api/src/Module/Notification`:
  - **`Outbox`** writes a notification in the caller's transaction and queues `SendNotification` on the same connection, so the message exists exactly when the ticket change does.
    - One row per dedup key (ticket, template, plan version).
    - Recipient: in demo, the sender of that ticket (ID-DEMO-2); otherwise the owner's registered address. Never a shared account, never an address from the body, `Reply-To` or CC.
    - The address is validated (one plain address, no CR/LF) before anything is written.
    - Mail tickets only: a request sent by e-mail is answered by e-mail.
  - **Route:** until the public demo is approved (`MAIL_PUBLIC_REPLIES=0`), demo messages go only to `MAIL_ALLOWED_RECIPIENTS` (addresses or `@domain`) and are captured otherwise. Production delivers to registered addresses.
  - **`Templates`** (Polish): `received`, `confirmation_request` (link to the confirmation screen), `report_ready` (link to the report after login) and `refused` (templated reason). Plain text plus escaped HTML without remote content. No report data, no echo of the request text, gender-neutral wording.
  - **`SendNotificationHandler`** claims `pending → sending` atomically, so a duplicate job does nothing.
    - A row found in `sending` was interrupted: it becomes `unknown` and is never resent.
    - „Connection could not be established” is retried up to 3 attempts, then `failed`. Any other transport error is `unknown`, without retry.
    - Headers: `Message-ID` with a random token, `In-Reply-To`/`References` to the request (threading), `Auto-Submitted: auto-generated` (RFC 3834).
- Triggers:
  - mail ticket created → `received`;
  - interpretation → `refused` (out of catalog or scope) or `confirmation_request` (production);
  - admin plan in production → `confirmation_request`;
  - admin rejection → `refused`;
  - completed file → `report_ready`.
- Ticket details show the outgoing messages (template, delivery state, text; never the recipient). Retention deletes recipient and bodies after 7 days; states stay.
- Migration `Version20261122210000`: `outbox`, and `mail_message.message_id` (purged with the contents).
- Staging: optional secret `STAGING_MAILER_DSN` (Brevo SMTP) and variable `STAGING_MAIL_ALLOWED_RECIPIENTS`, written to `/srv/Tabularius/.env.mail` (600). Without them, mail goes to `null://`.
- Evidence:
  - API: 277 tests, 96.11 % lines. `NotificationTest` covers:
    - e-mail → report → `report_ready` in the request's thread, with no report data or request text;
    - refusals and admin rejection;
    - controlled recipients, and the header-injection refusal;
    - a duplicate job sends once; an interrupted send becomes `unknown`; a repeated key writes nothing;
    - connection retry → `failed`, other errors → `unknown`;
    - production notifies only the registered address; manual tickets are not mailed;
    - retention.
  - Local CP-001 by e-mail in Mailpit: the request was injected; „Przyjęliśmy Twoją prośbę (T-1020)” and „Raport gotowy (T-1020)” arrived threaded with `In-Reply-To` and `Auto-Submitted`; the link opened the report after login.
- Limitations:
  - No real SMTP send yet. It needs the owner's Brevo credentials and agreed test addresses.
  - Bounces are not yet correlated to outbox rows (MAIL-2 marks them automatic, so they are never answered).
  - No clarification mail (increment 5).
