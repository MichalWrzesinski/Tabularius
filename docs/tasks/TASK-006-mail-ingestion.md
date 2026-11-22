# TASK-006 — Mail ingestion: durable raw messages and tickets from e-mail

Status: DONE (PR #24) · Priority: high · Increment: 3 · Depends on: TASK-005

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

## Report
- Module `api/src/Module/Mail`:
  - `MessageParser` (zbateson/mail-mime-parser 4) and `TextExtractor` (plain preferred; HTML through DOM without fetching; quoted history and signature stripped);
  - `Ingestor`, `MailStore`, `MailPoller`;
  - `MailboxSource` with `Imap\ImapMailbox` and `Imap\ImapConnection`: own minimal IMAP4rev1 client (ext-imap left PHP core in 8.4); TLS mandatory with a verified certificate; read-only `EXAMINE`; `BODY.PEEK`; byte-exact literals;
  - `Job/MailSchedule` (Symfony Scheduler, every `MAIL_POLL_SECONDS`, lock, missed runs collapsed); the worker consumes `async scheduler_mail`.
- Commands:
  - `app:mail:inject <file.eml> [--received-at]`: local stand-in for the mailbox; refused when a real mailbox is configured;
  - `app:mail:purge`: retention of contents, 7 days; runs daily next to `app:files:purge`.
- Migration `Version20261122170000`: `mail_message` with uniqueness on (mailbox, UIDVALIDITY, UID) and on the Message-ID hash. Mail tickets may have no owner until TASK-007 assigns one (`CHECK owner OR source = 'mail'`).
- Decisions:
  - **State is the UID high-water mark** per mailbox and UIDVALIDITY, not the `\Seen` flag, so the owner reading mail in a client changes nothing. A copy with another UID is recorded as `duplicate`, without contents, so polling moves on.
  - **What is stored:** the header block and the extracted text. Attachments are counted and dropped at ingestion; they are never stored or opened (MAIL-5). Automatic, duplicate and rate-limited messages keep no contents.
  - **Receive time** is IMAP INTERNALDATE, never the `Date` header (DATE-1). The ticket's reference date is that time's date in Warsaw.
  - **Nothing is dropped silently:** oversized (headers only), unparseable, empty and too long (> 4 000 characters, never truncated) messages become tickets in `needs_intervention` with a reason. Automatic messages (`Auto-Submitted`, `Precedence`, `X-Autoreply`, DSN, `Return-Path: <>`) create no ticket, so they can never be answered.
  - **Demo limits** are counted in the database per pseudonymous sender hash (HMAC with the app secret): 5/h and 20/day per sender, 200/day globally. They apply in demo mode only.
  - Logs carry outcome and size only. The admin API shows mail tickets as „Nadawca nieprzypisany”, without sender, subject or body (tested).
- Staging: optional GitHub secret `STAGING_MAILBOX_DSN`. The deploy workflow writes it to `/srv/Tabularius/.env.mail` (mode 600) and removes the file when the secret is unset.
- Evidence:
  - API: 215 tests, PHPStan max, 95.9 % lines.
  - Unit tests: extractor (signature, Polish/English/Outlook quote headers, HTML), parser (fixtures in `tests/Fixtures/mail/`), and the IMAP client against a scripted server. The scripted server covers: the exact command transcript, literals, headers-only for large messages, the refused login without credentials, 7 protocol failures, connection failure, and CRLF injection in a folder name.
  - Functional tests: outcomes per fixture, duplicates by UID and Message-ID, intervention cases, demo and global limits, the poller's high-water mark and failure in mid-batch, admin API without contents, the inject command and retention.
  - Local stack: 7 fixtures injected; the worker runs the mail schedule (`debug:scheduler`).
- Limitations:
  - The IMAP client is tested against a scripted server, not yet a real one. The first real check comes when the owner supplies the mailbox details (`STAGING_MAILBOX_DSN`).
  - A message without a declared charset is read as ISO-8859-1, as MIME prescribes.
  - Tickets wait in `received` until identity (TASK-007) and interpretation (TASK-008).
