# TASK-006 — Mail ingestion: durable raw messages and tickets from e-mail

Status: PLANNED · Priority: high · Increment: 3 · Depends on: TASK-005

## Goal and scope
An e-mail to the Tabularius mailbox becomes a durable ticket, safely and exactly once (MAIL-1, MAIL-2, MAIL-5; threat model T-10).
- `MailboxSource` adapter interface. Two implementations:
  - a local stub with `app:mail:inject <file.eml>` for development, tests and E2E;
  - a real IMAP adapter (TLS only, read-only folder access plus a "processed" flag), wired to the mailbox when the owner supplies the IMAP details.
- The raw message is stored before it is acknowledged. Size limit 256 KB; larger messages keep metadata only and go to intervention.
- Deduplication by mailbox UIDVALIDITY+UID and by a `Message-ID` hash. Restarts and double polling create no second ticket.
- Auto-replies, bounces and loops never create a ticket that answers (`Auto-Submitted`, `Precedence`, `X-Autoreply`, DSN, null return path).
- Text extraction:
  - plain text preferred;
  - HTML converted to text without fetching anything;
  - quoted history stripped;
  - 4 000-character limit with intervention above it, never silent truncation;
  - attachments ignored, neither stored nor opened.
- Demo limits per sender (5/h, 20/day) and global (200/day). Retention: raw message, subject and address deleted after 7 days by the daily job.
- Polling worker job (Messenger scheduler) with an interval set in configuration.

## Acceptance
- An injected request creates one ticket in `received` with its message.
- Injecting the same message twice, or polling twice after a restart, creates no second ticket.
- An auto-reply, a bounce and an oversized message each end as defined (no ticket, or intervention with a reason).
- Attachments do not reach storage; links are never fetched.
- No body, subject or address appears in logs.

## Verification
Unit tests for MIME and edge cases (synthetic `.eml` fixtures: multipart, charset, HTML only, quoted reply, DSN, auto-reply, oversize, attachment). Functional ingestion tests. On staging, after the owner supplies the IMAP details: one controlled test message.
