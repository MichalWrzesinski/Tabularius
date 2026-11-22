# TASK-007 — Message identity, portal confirmation and the demo exception

Status: PLANNED · Priority: high · Increment: 3 · Depends on: TASK-006

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
