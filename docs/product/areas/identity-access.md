# Identity, permissions and accounts

Status: discovery draft, 2026-11-21. Parent: [overview](../overview.md).

## Message identity (production mode)

A `From` header, a ticket number, `Reply-To`, or a claim in the body proves nothing. SPF/DKIM/DMARC results help assess origin but do not establish authorization for a customer.

- ID-1 A message is **attributed** to an employee only when the envelope/header sender equals the registered company address of an active account (exact, case-insensitive address match after normalization) — this only selects a candidate.
- ID-2 **Production mechanism: confirmation in the authenticated portal** (owner decision 2026-11-21). A newly attributed ticket waits in `awaiting_confirmation`. The notification goes only to the registered address and links to the ticket; the employee logs in, sees the operation and parameters with concrete dates, and confirms. Nothing confidential is executed or returned before confirmation. Since result download already needs login, this adds one click to a step the employee takes anyway.
- ID-3 Optional relaxation, off by default: if the mailbox adapter supplies trustworthy evidence (e.g. `Authentication-Results` added by the organization's own receiving server showing an aligned DMARC pass for the company domain, plus provider confirmation that this header cannot be injected by senders), the profile may skip confirmation for that organization. Enabling it requires verification against the real provider and an ADR; it is never enabled for `From` alone.
- ID-4 Unknown sender, inactive/blocked account, or conflicting evidence → no interpretation of confidential intent beyond a templated reply; the ticket goes to secure manual handling (`needs_intervention`, reason `identity_unverified`) and no data is revealed.
- ID-5 Clarification replies in an established thread are additionally bound to the ticket's participant (MAIL-CORR). A parameter change arriving by email after confirmation requires confirmation again before execution.

## Permissions

- ACC-1 Default deny. Permissions are checked when a plan is accepted, immediately before execution, and when a file is listed or downloaded; each check uses the current state (account active, role, current customer assignments).
- ACC-SCOPE-1 A representative's scope = customers currently assigned to their mapped representative identity in the business system. Aggregates cover only that scope.
- ACC-SCOPE-2 A requested customer outside scope is refused without saying whether it exists and without substituting another customer. The reply describes the available scope („możesz zamawiać raporty dla swoich 12 klientów”) without names outside it. Name resolution runs only within scope.
- ACC-SCOPE-3 Identity and scope come from trusted context; LLM output cannot set them. Plan parameters that reference identity, recipients, scope widening or other employees are rejected by the schema.
- ACC-ADM-1 The administrator sees all tickets, plans, files and audit of the organization and manages representative accounts. Administrator actions on a ticket (clarify, choose a customer, retry) execute **within the ticket owner's scope**, never the administrator's; the result belongs to the ticket owner.
- ACC-FILE-1 A file is downloadable by its owner (if the file's scope is still within the owner's current scope and the file has not expired) and by the administrator. Direct URLs are subject to the same check; there is no public file directory. Expired files are deleted, never made public.
- ACC-REP-1 A representative cannot open the admin dashboard or its API.

## Account lifecycle (ACC-LIFE)

- The administrator creates an active representative account with a company address and maps it to exactly one representative identity in the business system (unique mapping). No self-registration, no address activation.
- The system generates a random password, sends it to the employee, and stores only an Argon2id hash. The administrator never sees it. The outgoing message body is not kept in logs, audit or the outbox after sending (outbox keeps template key and recipient reference only).
- Password change by the employee (current password required); reset generates a new random password sent to the registered address. Reset requests do not reveal whether an account exists.
- Blocking invalidates all sessions immediately and removes the ability to confirm, run or download. Unblocking does not restore old sessions.
- Deletion removes access and personal login data; tickets, files metadata and audit stay under the retention policy with a pseudonymous reference.
- Every account change is audited with actor and time.

## Demo mode (ID-DEMO)

Applies only to the demo environment with synthetic business data. Separate database, secrets and configuration from production; production must not contain these exceptions or public credentials.

- ID-DEMO-1 Any sender address is accepted without verification; all requests run as one shared demo representative account (scope: that representative's synthetic customers). No portal confirmation step.
- ID-DEMO-2 Report notifications and clarifications go to the sender address of that specific ticket; recipients named in the body, `Reply-To` and `CC` are ignored. Thread correlation keeps each participant separate despite the shared account; a participant never receives another participant's history.
- ID-DEMO-3 Shared demo accounts (representative and administrator) see only templated public views: synthetic participant alias (e.g. „Uczestnik #K7”), recognized operation, allowed demo parameters, status, files. Never real addresses, raw subjects or bodies, identifying headers, or model free text — in views, API, audit or files. Masking an address is not sufficient.
- ID-DEMO-4 Full correspondence is visible only to the trusted operator through a separate nonpublic permission (not the shared admin account).
- ID-DEMO-5 The public admin may manage fictional accounts; account-management mail is captured (never delivered), and shared accounts cannot be permanently changed or blocked.
- ID-DEMO-6 Before use, participants are told that views are shared and that confidential or real business data must not be sent.
- ID-DEMO-7 Global and per-sender limits and periodic cleanup apply ([threat model](threat-model.md#limits)). Retention of real participant data (owner decision 2026-11-21): raw messages, subjects and addresses 7 days, then deleted; pseudonymous audit 90 days; synthetic report files 7 days; application logs (never containing bodies) 14 days. Deletion by a daily scheduled job recorded in the operation register. Public demo replies still start only after launch approval; until then only synthetic messages and controlled test addresses.
