# TASK-004 — Login, sessions and account administration

Status: DONE (on merge) · Priority: high · Increment: 2 · Depends on: TASK-001

## Goal and scope
Implement ACC-LIFE and [ADR 0003](../decisions/0003-server-sessions-in-postgresql.md):
- Login and logout, roles, and representative mapping.
- Admin create, block, unblock and delete.
- Generated password sent by email (Mailpit locally; captured in demo mode).
- Password change and reset.
- Shared demo accounts protected from blocking and deletion.
- The dashboard login and the „Pracownicy” view connected to the API.

## Acceptance
- CP-004: admin creates a representative, the password arrives in Mailpit, the representative logs in, the admin blocks them, and the next request returns 401 with the session row deleted.
- The admin never receives the password in any response. The outgoing body is not kept in the outbox, logs or audit.
- Reset requests return the same response for existing and unknown addresses.
- A representative calling an admin endpoint gets 403. Login is rate-limited.

## Verification
API tests and Playwright CP-004. Critical paths: CP-004, part of CP-003.

## Report
- Module `api/src/Module/Identity` (plus `Audit`, `Shared/Http`):
  - `Account` entity (UUIDv7, roles admin/rep, statuses active/blocked/deleted, unique e-mail and representative code);
  - `AccountProvider`, which rejects non-active accounts on every request;
  - server sessions in PostgreSQL (`PdoSessionHandler` on its own connection with advisory locks) and the session→account index `account_session`;
  - `AccountService`, generated 16-character passwords, `AccountMailer` (capture transport in demo mode) and the audit log.
- API:
  - `/api/v1/auth/{login,logout,session,me,password,password-reset}`;
  - `/api/v1/admin/{accounts, accounts/{id}/block|unblock, DELETE accounts/{id}, sales-reps, audit}`;
  - Problem Details errors; CSRF via the `X-Tabularius-Request` header and `Sec-Fetch-Site`; login throttling at 5 attempts per 15 minutes.
- The business API got `GET /v1/sales-reps` (validates the representative mapping).
- Dashboard: real login with the role checked per panel, session probe, route guard, logout, „Pracownicy” and „Dziennik zdarzeń” on the API. Tickets and files stay on the prototype mock until TASK-005; the mock shows its data only to PH-01.
- Evidence:
  - API tests: 158 tests, PHPStan max, 96.81 % line coverage. They cover:
    - the same 401 for unknown, wrong-password and blocked accounts; 429 throttling; CSRF;
    - 401/403 by role; logout; password change and reset without enumeration;
    - demo mail goes to the capture transport;
    - creation without the password in responses or audit; duplicates;
    - blocking deletes session rows and refuses the next request; deletion keeps a pseudonymous row;
    - shared, own and admin accounts are protected.
  - Client: 40 Vitest tests, 100 % lines in `src/lib`.
  - UI E2E `client/scripts/e2e-cp004.mjs` (CP-004) passed locally: create → Mailpit password → login → 403 on admin API → block → session revoked → delete.
  - The walkthrough `prototype-screens.mjs`: 57 checks at 1440/360 px, no console errors.
- Fixed during the task:
  - sharing one PDO between sessions and Doctrine raised „There is already an active transaction”; sessions now have their own connection;
  - the session id changes after login, so the index is written on the response;
  - Symfony removes the `X-Transport` header when sending, so transport selection is tested at unit level.
- Limitations:
  - E2E scripts run against the local stack and are not in CI yet (planned with staging);
  - throttling uses the filesystem cache (one instance).
