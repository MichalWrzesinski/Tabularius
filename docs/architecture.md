# Architecture

As of 2026-11-21 (design; code follows in increment 2). Decisions: [docs/decisions/](decisions/). Stack: [technical profile](technical-profile.md). Product rules: [docs/product/](product/overview.md).

## Runtime

```text
browser ──> caddy ──/──────> client (Vue build, static)
                 └─/api───> api (Symfony, php-fpm) ──> PostgreSQL: tabularius
                                     │                       (accounts, tickets, jobs, files meta, audit, sessions)
                                     ├─ HTTP (bearer) ──> business (Symfony) ──> PostgreSQL: business (synthetic)
                                     ├─ HTTPS ──> OpenAI API (interpretation only)
                                     └─ SMTP ──> mail provider (Mailpit locally)
worker (same image as api): Messenger consumer + Scheduler
    ├─ IMAP poll ──> mailbox (stub locally)
    ├─ interpretation / report jobs / outbox dispatch / retention cleanup
report files: persistent volume via Flysystem (object storage later by configuration)
```

## Modules (`api/src/Module/*`)

| Module | Owns | Public interface (examples) |
|---|---|---|
| Identity | accounts, representative mapping, sessions, password lifecycle, roles | `CurrentUser`, `ScopeProvider::customersFor(account)`, `AccountAdmin` |
| Mail | raw inbound messages (retention), mailbox cursor, outbox, delivery state, correlation tokens | `Inbox::ingest()`, `Outbox::enqueue(template, ticket, recipientRef)` |
| Ticket | tickets, status transitions, plan versions, clarification rounds, admin interventions | `TicketService::transition()`, `PlanVersions::append()` |
| Interpretation | prompt/profile rendering, provider adapter, baseline parser, limits, spend guard | `Interpreter::interpret(text, currentPlan): Plan` |
| Report | integration profile, period resolver, operation executors, CSV writer, discount policy | `Executor::run(planVersion, scope): ReportResult` |
| File | report file metadata, storage, retention, authorized download | `Files::store()`, `Files::authorize(account, fileId)` |
| Audit | append-only audit events, operation register for deletions | `Audit::record(actor, action, target)` |

The integration profile (`api/config/integration/demo-autoparts.v1.yaml`) is read by both Interpretation (catalog description) and Report (validation, mapping, columns). It has one source.

## Data model — Tabularius database (outline)

| Table | Key fields | Notes |
|---|---|---|
| `account` | id (UUIDv7), email (unique, citext), role, status, password_hash, rep_code (unique, nullable) | Admin has no rep_code. Deletion leaves a pseudonymous id |
| `session` | id, account_id, data, expires_at | Deleted on block or delete (ADR 0003) |
| `inbound_message` | id, mailbox_uid, uidvalidity, message_id_hash (unique), received_at, raw (encrypted at rest, retention 7 days in demo), sender_hash | Raw content is visible only to the trusted operator |
| `ticket` | id (T-number), owner_account_id, participant_alias, status, reason_code, clarification_rounds, version (optimistic lock) | Status transitions per TKT-STATUS |
| `ticket_message` | ticket_id, inbound_message_id or outbox_id, direction, kind, at | Correspondence thread |
| `plan_version` | ticket_id, version, author, operation, params (jsonb, validated), issues, created_at | Unique (ticket_id, version) |
| `report_job` | ticket_id, plan_version, state, attempts | Unique (ticket_id, plan_version), so execution is idempotent |
| `report_file` | id (UUID), ticket_id, owner_account_id, plan_version, scope (jsonb), profile_version, data_version, storage_key, expires_at | Access check: ACC-FILE-1 |
| `outbox_message` | id, ticket_id, template, recipient_ref, message_id_token, state, attempts | `unknown` means no auto retry |
| `audit_event` | at, actor_ref, action, target_ref, details (codes only) | Append-only; no bodies, report rows or passwords |
| `messenger_messages`, `lock_keys`, `rate_limit` | framework tables | — |

Money and report rows are not stored in Tabularius. Only the CSV file is stored, and it is deleted at expiry.

## Data model — business database (synthetic)

`manufacturer`, `supplier`, `part` (purchase_price and margin exist but are not permitted fields), `customer` (active flag, similar names), `sales_rep`, `customer_assignment` (rep → customer), `sales_document` (invoice or adjustment, status, sale_date or issue_date), `sales_line` (quantity, net_value numeric(14,2)), `discount_policy` (versioned tiers), `seed_meta` (data version, random seed, reference date). Reporting views: `v_sales_line`, `v_customer`, `v_part_catalog`. Only these are granted to `business_reader`.

## Database roles

| Role | Database | Rights |
|---|---|---|
| `tabularius_migrator` | tabularius | DDL (migrations only) |
| `tabularius_app` | tabularius | DML on its own tables, no DDL |
| `business_owner` | business | DDL and seed |
| `business_reader` | business | SELECT on reporting views only |

## Sensitive data inventory

| Data | Purpose | Where | Recipients | Retention | Deletion |
|---|---|---|---|---|---|
| Employee name, company email, password hash | login, notifications | `account` | mail provider (address only) | while the account exists | account deletion removes login data; audit keeps a pseudonymous id |
| Inbound raw message, sender address, subject | ticket processing, operator review | `inbound_message` | OpenAI (stripped body text only, no headers or addresses) | demo: 7 days (owner decision 2026-11-21); production: to be configured | scheduled job, recorded in the operation register |
| Demo participant correspondence | demo replies | as above | mail provider | 7 days | as above |
| Pseudonymous audit | security and accountability | `audit_event` | — | 90 days in demo | scheduled job |
| Report files (synthetic business data) | delivery | storage volume | — | 7 days in demo | scheduled job |
| Application logs | operations | stdout | hosting | 14 days, never message bodies or report content | log rotation |

## Higher-risk flows and controls

- Identity and scope: ID-*, ACC-* (checks at acceptance, execution and download), tests in CP-003.
- LLM boundary: ADR 0004, threat model T-2 to T-5.
- Mail: no recipient from the message, correlation tokens, loop protection (MAIL-*).
- Files: no public directory, and the same authorization check on direct URLs.
