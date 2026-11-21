# 0003 — Server sessions stored in PostgreSQL

Date: 2026-11-21. Status: accepted.

## Context
Blocking an account must immediately invalidate its sessions and downloads (ACC-LIFE). The dashboard is served from the same origin as the API. There are no third-party API clients.

## Decision
- Symfony Security with form-style JSON login and a server session in an HttpOnly, Secure, SameSite=Lax cookie.
- Sessions are stored in PostgreSQL (`PdoSessionHandler`), with a session-to-account index so blocking or deleting an account deletes its sessions.
- CSRF tokens protect state-changing requests. Login is rate-limited. Passwords are hashed with Argon2id.
- An email link opens the specific report after login, with no automatic download and no forced logout.

## Consequences
- No JWT refresh logic, and revocation is immediate.
- Every request needs a database lookup for the session, which is acceptable at the expected load.
