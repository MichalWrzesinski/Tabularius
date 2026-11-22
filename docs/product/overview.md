# Tabularius — product overview

Status: discovery, 2026-11-21. Input: [brief](../input/brief.md) (owner-supplied, kept until its findings are fully moved here). Detailed rule areas: [reports and financial rules](areas/reports.md), [identity and access](areas/identity-access.md), [tickets and correspondence](areas/tickets.md), [threat model](areas/threat-model.md).

Tagline: **„Od wiadomości do raportu”** (From message to report). UI and demo communication in Polish; documentation and code in English.

## Problem and goal

In a distributed sales organization (demo: an automotive parts wholesaler) a sales representative who needs figures asks a colleague, who passes the request between departments. Tabularius shortens the path from an ordinary email to an **accurate report the employee is authorized to see**: the email becomes a ticket, an LLM turns it into a typed plan, the backend validates and authorizes the plan, deterministic code runs one operation from a closed catalog against a read-only business system, and the employee downloads a CSV after logging in.

It is not a general company assistant. The LLM interprets; it never computes money, chooses security filters, queries data, or sends mail.

Business hypothesis (to validate, not promised): less manual request handling at equal accuracy and access control. Measures: time from received message to accurate result, share of tickets completed without a human, share needing intervention, interpretation accuracy against a baseline parser. No savings claims without comparable data.

## Users and roles

| ID | Role | Can | Cannot |
|---|---|---|---|
| R-REP | Sales representative | Send requests by email; reply to clarifications; confirm requests (production); log in to „Moje raporty”; download own reports | See other representatives' customers, tickets, or files; open the admin dashboard |
| R-ADM | Administrator (organization) | See all tickets and reports; manage representative accounts and their mapping to representative identities in the business system; clarify parameters, resolve ambiguity, retry an allowed operation | Read another person's password; bypass the operation catalog; change business data |
| R-OPS | Trusted operator (nonpublic) | Read full correspondence and contact data of demo participants; change integration profiles in the repository | — (not a dashboard role in the public demo) |
| R-DEMO | Shared demo accounts (one representative, one administrator) | As R-REP / R-ADM, restricted to safe, templated views | See real addresses, raw subjects or bodies, identifying headers; permanently change or block shared accounts; send passwords to arbitrary addresses |

Rule ACC-1: if no rule permits access, deny it. Details: [identity and access](areas/identity-access.md).

## First release scope

The first release is a **public demo on synthetic business data** with real mail (IMAP/SMTP) and OpenAI integrations, plus production-mode mechanisms verified on synthetic data. Connecting a real organization is a separate stage.

In scope:

1. Closed operation catalog from the integration profile `demo-autoparts` v1: `sales_report`, `parts_catalog`, `customer_discount_simulation` ([reports](areas/reports.md)).
2. Mail ingestion (IMAP behind an adapter, local stub), durable tickets, deduplication, reply correlation, at most 3 clarification rounds, outbox for notifications (SMTP behind an adapter, local capture inbox).
3. Interpretation into a typed, versioned plan (OpenAI, schema-constrained output; test doubles in CI) with limits; rule-based baseline for evaluation.
4. Deterministic report engine, CSV with formula-injection protection, files behind authorization with retention.
5. Vue 3 application in `client/`: admin dashboard (tickets, details, files, accounts, audit), representative „Moje raporty”, login, mobile from 360 px.
6. Separate demo business system: own PostgreSQL database, migrations, seed, read-only API.
7. Demo mode exceptions (shared accounts, replies to ticket sender, templated public views, limits, cleanup) separated from production mode by configuration.

Out of scope for the first release (brief §1, §2.1): arbitrary SQL or queries, arbitrary files, incoming attachments, OCR, RAG, HR/payroll data, business-data changes, multiple organizations, autonomous agents with open tools, profile editor in the dashboard, PDF/XLSX, reports as attachments, self-registration.

## Material rules (index)

| ID | Rule | Source |
|---|---|---|
| RPT-* | Operations, columns, inclusion date, money, rounding, discount policy | [reports](areas/reports.md) |
| DATE-* | Reporting periods in `Europe/Warsaw`, relative expressions | [reports § periods](areas/reports.md#reporting-periods) |
| ID-*, ACC-* | Identity of a message, permissions, account lifecycle, demo exception | [identity and access](areas/identity-access.md) |
| TKT-*, MAIL-* | Ticket statuses, transitions, clarification, correlation, outbox | [tickets](areas/tickets.md) |
| SEC-* | Prompt injection, CSV, HTML, limits | [threat model](areas/threat-model.md) |

## Critical paths

| ID | Name | Roles | Requirements | Why critical |
|---|---|---|---|---|
| CP-001 | Email request → authorized report → download after login | R-REP | RPT-SALES, DATE-*, ID-*, TKT-* | The core value; wrong figures or wrong scope are the main business risk |
| CP-002 | Missing parameter → question → reply → same ticket → exactly one result | R-REP | TKT-CLAR, MAIL-CORR, TKT-VER | Misrouted replies or duplicate execution corrupt the process |
| CP-003 | Access denial: another rep's customer, direct link to another file, revoked or blocked account, spoofed sender | R-REP, R-ADM | ACC-*, ID-* | Data disclosure between employees |
| CP-004 | Account lifecycle: admin creates rep → password by email → login → block invalidates sessions and downloads | R-ADM, R-REP | ACC-LIFE | Access control starts here |
| CP-005 | AI/provider failure or exhausted limit → ticket kept → admin resolves manually → result | R-ADM | TKT-ERR, SEC-LIM | The system must not pretend success or lose requests |

Verification plan: `docs/qa/critical-paths.md` (created in planning of increment 2).

## Acceptance criteria for the release (from brief §10)

- AC-1 A known query produces a file equal to an independently calculated expected result, including adjustments, empty periods and discount boundaries.
- AC-2 A missing parameter triggers the correct question; the reply updates the same ticket and generates the result once.
- AC-3 Spoofed sender, forged context, another employee's customer, and a direct link to someone else's file grant no access; revoked permissions are detected before execution and download.
- AC-4 Requests to delete data, produce payroll, or change the recipient stay within the contract even with a manipulated model output.
- AC-5 Duplicate ingestion, duplicate jobs, restarts, late replies and provider timeouts have defined, tested outcomes.
- AC-6 AI failure or exceeded limit keeps the ticket and allows manual handling; never reported as success.
- AC-7 Evaluation: separate development and frozen final sets; thresholds agreed before measuring ([evaluation plan](areas/evaluation.md)).
- AC-8 UI works from 360 px to desktop without horizontal scroll; keyboard, focus, labels, contrast; loading/empty/error/success states.

## Constraints

- Stack fixed by the owner: PHP/Symfony + PostgreSQL, Vue 3 + TypeScript; modular monolith with background jobs. Modules: identity and permissions, mail ingestion, tickets/correspondence, interpretation, reports, files, audit.
- Two databases: Tabularius (accounts, tickets, messages, configuration, file metadata, audit) and the demo business system (synthetic, separate migrations, seed, read-only API). Report executor uses an account without DDL/DML.
- Mailbox is not Microsoft 365/Google Workspace: IMAP + SMTP behind adapters; support to be verified with the provider.
- OpenAI from the start; real calls only after the key is configured securely; CI uses doubles.
- Production deployment needs separate approval; public demo replies only after launch approval.

## Later ideas (not in the first release)

PDF/XLSX output; profile editor in the dashboard; provider-level identity integration (e.g. a mail gateway that stamps verified sender); multiple organizations; scheduled recurring reports.

## Owner decisions (2026-11-21)

- Production identity: portal confirmation before execution (ID-2).
- Domain for the application: `tabularius.gitpush.app` (mail links, cookies); the mailbox address is confirmed at mail integration.
- Budget cap for real OpenAI evaluation runs: USD 20 in total for development and final runs, plus a daily spend guard in the application.
- Demo participant data retention: [ID-DEMO-7](areas/identity-access.md#demo-mode-id-demo).
- Repository: private `MichalWrzesinski/Tabularius`, created at setup.

- Hosting and mail (2026-11-22): the owner's VPS, mailbox `tabularius@gitpush.app`, outgoing mail through Brevo. Details and pending checks: [technical profile](../technical-profile.md#hosting-and-mail-owner-2026-11-22).

## Open questions

- IMAP access details for `tabularius@gitpush.app` (needed for the real adapter in increment 3).
