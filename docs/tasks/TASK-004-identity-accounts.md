# TASK-004 — Login, sessions and account administration

Status: PLANNED · Priority: high · Increment: 2 · Depends on: TASK-001

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
