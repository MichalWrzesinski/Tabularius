# TASK-007 — Message identity, portal confirmation and the demo exception

Status: DONE (PR pending CI) · Priority: high · Increment: 3 · Depends on: TASK-006

## Goal and scope
Decide on whose behalf a message may run, without trusting headers (ID-1 to ID-5, ID-DEMO-1 to ID-DEMO-3; CP-003 spoofed sender).
- Production mode (configuration):
  - attribution by exact, normalized sender address to an active account (ID-1);
  - an attributed ticket with a valid plan waits in `awaiting_confirmation`; the representative confirms or cancels it in the portal („Potwierdź prośbę” on the real API);
  - unknown, blocked or conflicting sender → `needs_intervention` (`identity_unverified`), with nothing revealed (ID-4).
- Demo mode:
  - any sender runs as the shared demo representative, without confirmation (ID-DEMO-1);
  - each ticket gets a synthetic participant alias;
  - public views and API show the alias, operation, parameters, status and files, never addresses, subjects, bodies or headers (ID-DEMO-3, enforced in the API, not in the client).
- Ticket list and details show mail tickets next to manual ones. The prototype panel's „anonymize” toggle is replaced by the server-side rule.

## Acceptance
- Production mode:
  - a spoofed `From` of another representative runs nothing in their scope until that representative confirms;
  - an unknown sender ends in `identity_unverified`;
  - a confirmed ticket runs once, a cancelled one never.
- Demo mode: API responses of the shared accounts contain no sender address, subject or body (checked by a test on every endpoint).

## Verification
Functional tests in both modes; E2E of the confirmation screen at 360 px.

## Report
- `api/src/Module/Mail/MessageIdentity` resolves the owner at ingestion:
  - production: an exact, normalized match to an active representative account (not shared, not admin), only as a candidate (ID-1); unknown, blocked, admin or shared addresses → `needs_intervention` (`identity_unverified`) with no owner (ID-4);
  - demo: the shared demo representative, plus a synthetic alias `Uczestnik #XXXX`, stable per sender and derived from the pseudonymous sender hash (ID-DEMO-1).
  
  Migration `Version20261122190000` adds `ticket.participant_alias`.
- `TicketService`:
  - `setPlan`: the admin sets parameters for a ticket with an owner, validated in the owner's scope. A production mail ticket goes to `awaiting_confirmation` (ID-2); otherwise it is queued.
  - `confirm` and `cancel` by the owner. Someone else's ticket is 404.
  - `expireUnconfirmed` after 7 days (`app:tickets:expire`, run daily in the staging purge loop).
  - Transitions go through `interpreting` where there is no direct one.
- API: `POST /admin/tickets/{id}/plan`; `GET /me/tickets/{id}`, `POST /me/tickets/{id}/confirm|cancel`. `/auth/session` also returns `mode` and `mailbox`.
- ID-DEMO-3 is enforced on the server. Demo mail tickets show only the alias: no shared account, address, subject or body on any endpoint of the shared accounts (tested across all GET endpoints they can call).
- Client:
  - „Potwierdź prośbę” uses the API;
  - the admin action „Ustaw parametry” reuses the manual-ticket form with a fixed owner;
  - environment and mailbox address come from the server.
  - **The prototype mock layer and the prototype panel are removed**: nothing used them any more.
- Evidence:
  - API: 6 new functional tests (`MessageIdentityTest`) and an updated `MailIngestionTest`. They cover:
    - production attribution and its refusals;
    - the spoofed sender: an attributed ticket runs nothing until the real owner confirms; another representative gets 404; confirmation works once; the file belongs to the owner;
    - cancel and expiry;
    - the demo admin plan going straight to the queue;
    - unverified tickets can only be rejected;
    - the endpoint-wide demo privacy check.
  - Client: 46 tests, 100 % lines in `src/lib`.
  - Local check at 375 px: the confirmation screen, then confirm → queued → completed, once. The ticket was put in `awaiting_confirmation` directly in the database, because the local stack runs in demo mode, which has no confirmation.
- Limitations:
  - No notification e-mail yet, so the representative reaches the confirmation screen only from „Moje raporty” (`confirmation_request` comes with TASK-009).
  - Production mode is exercised in tests with explicitly built services; no environment runs in production mode yet.
  - Assigning an unverified sender to an account is deliberately not possible (ID-4).
