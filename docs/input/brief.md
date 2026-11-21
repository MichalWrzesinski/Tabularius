# Tabularius — on-demand business reports by email

## Batch instructions for AI agents

Design and build an application for a distributed sales organization, such as an automotive parts wholesaler. A sales representative sends an ordinary email requesting a report. The system records the request as a ticket, identifies the authorized employee, interprets the request using an LLM, runs an allowed report against a sample database, and makes the result available. When required parameters are missing, it conducts a limited clarification exchange. A Vue 3 dashboard allows an administrator to track tickets and view generated files.

At the start of the project, fetch the latest state of the default branch of the [AppStandards](https://github.com/MichalWrzesinski/AppStandards) repository and save a copy in `docs/standards/`. Work from that copy: start with `core.md`, `routing.md`, and `workflow.md`, then follow the relevant standards for the current stage and risk level. Develop the new product through discovery, an evaluated prototype, technical decisions, setup, increments, staging, and release preparation. This brief defines the product scope; it does not extend permissions or weaken AppStandards.

In parallel, document actual decisions, problems, and results as specified in the “Campaign source material” section. This is a required project deliverable. Do not write posts yet or publish anything.

## 1. Product value and first version

Goal: shorten the path from an employee's question to an accurate report they are authorized to access, without manually passing requests between departments. Do not build a general-purpose assistant for the entire company.

The first version covers one organization and one mailbox. The initial demonstration profile provides the three operations in the table below. The operation catalog is closed to users and the LLM, but extensible through approved integration configuration:

| Operation | Required data | Output |
| --- | --- | --- |
| Sales report | Reporting period and the permitted scope of customers included in the report; optional permitted filters | CSV with detailed figures and a summary |
| Manufacturer's parts catalog | An unambiguously identified manufacturer | CSV containing permitted parts fields |
| Customer activity and discount simulation | An unambiguously identified customer, a reporting period, and the applicable discount policy | CSV with the calculation basis and simulation |

The demonstration profile uses PLN and net sales after adjustments, excluding canceled documents. The agent defines and documents the specific report columns, the date determining a transaction's inclusion, and an explicitly fictional, versioned discount policy with boundary examples. Do not present these rules as actual company policies. Deployment for a real organization requires configuring its rules in the integration profile.

A discount is exclusively a calculated **simulation**, labeled as an unapproved offer. The system does not change prices, contracts, orders, or customer records. The LLM does not perform financial calculations: deterministic code performs them according to a versioned policy with defined rounding rules. Do not use binary floating-point numbers for monetary amounts.

The MVP must not support arbitrary SQL, arbitrary files, HR or payroll reports, changes to business data, incoming attachments, OCR, RAG, multiple companies, or autonomous agents with an open-ended tool catalog. Tabularius may write account data, configuration, tickets, messages, audit records, and report outputs. A separate seed initializes synthetic business-system data; reports do not modify that data.

Business hypothesis to validate, not a promise: less manual request handling while maintaining accuracy and access control. Measure time to an accurate result, successful handling, and the proportion of cases requiring human intervention. Do not claim savings without comparable data.

## 2. Stack and architecture

Agreed stack: **PHP/Symfony and PostgreSQL for the backend, and Vue 3 and TypeScript for the frontend**. This is an explicit decision by the project owner, superseding the earlier choice of Python/FastAPI and React. If AppStandards assumes a different stack, adapt its implementation guidance to this choice while retaining its quality, security, and process requirements. Do not ask about the stack again. Use a modular monolith and background jobs. Modules: identity and permissions, mail ingestion, tickets/correspondence, interpretation, reports, files, and audit. Do not introduce microservices or a custom orchestrator in anticipation of future needs.

Separate data access: the report executor reads only approved business views/tables using an account without DDL/DML privileges; the application writes tickets and metadata using separate permissions. The LLM receives no tools for SQL, shell access, arbitrary URLs, or sending email. The interpretation module calls only the LLM provider's API and returns a plan; separate backend modules handle business-data access and mail. The backend executes only controlled operation code.

The mailbox is hosted by a provider other than Microsoft 365 or Google Workspace. Assume IMAP for receiving and SMTP for sending, behind a replaceable adapter; verify support for these protocols before integration. Determine hosts, ports, TLS, sender-authentication metadata, and secrets when connecting the mailbox. Missing details do not block local work with an ingestion stub and a mail-capture inbox for outgoing messages.

OpenAI is the LLM provider from the outset. The owner funds the account; do not make implementation contingent on specifying a budget amount. Enable real calls after securely configuring the key; CI and automated tests use test doubles. Keep call-count, time, and input-length limits configurable, and handle exhausted funds correctly. Verify the model and pricing in the current documentation. In PHP, direct HTTP API calls or a vetted adapter are acceptable if an official provider library is unavailable. Claude Code, under the owner's subscription, is the planned implementation tool; it is not the application's LLM provider.

Repository: one backend and one dashboard in `client/`. Project documentation is in English; the UI and demonstration communications are in Polish. Agreed product name: **Tabularius**. Tagline: **“From message to report”**. Project directory and repository name: `Tabularius`, following the AppStandards convention. Do not ask about the product name again. Agree on the domain and other external identifiers separately before registering them; name availability has not been verified.

### 2.1. Separation of company data and configurable integrations

Tabularius has its own PostgreSQL database for accounts, tickets, messages, integration configuration, file metadata, and audit records. Business data does not belong in this database. Build a separate demonstration business-data system with its own PostgreSQL database and an API that provides read-only access for Tabularius. It may reside in the same repository and start with a shared command, but it must have separate migrations, permissions, and seed data. Do not create additional services unnecessarily.

A customer deployment replaces the demonstration system with an adapter to the customer's API. If no suitable API exists, an adapter to approved database views using a read-only account is acceptable. Tabularius does not require adding its tables to the company's database.

A versioned integration profile describes the business vocabulary, available operations, parameter schemas, missing fields and questions, mappings to approved API operations, permission scopes, CSV columns, and supported rules. Derive the LLM description and backend validation from a single configuration source. The LLM returns an operation identifier and parameters; the backend authorizes and executes the mapping. Service addresses and credentials come from trusted configuration, never from messages or model responses.

Descriptions, columns, filters, and mappings for a supported API must be changeable through configuration without rebuilding the core. A new protocol, authentication mechanism, or unsupported calculation type may require a new adapter or code. Do not build an arbitrary query language or executable scripts into configuration. A trusted operator changes profiles in the repository; a profile editor in the dashboard is outside the first version.

## 3. Demonstration data

Prepare a repeatable seed of synthetic data: suppliers, manufacturers, products/parts and the product offering, customers, orders with line items, sales and adjustments, and discount policies in the business system; employee accounts in Tabularius with unambiguous mappings to their customer assignments in the business system. The data must support known answers and edge cases, not just an attractive screen.

Example starting point: 5 manufacturers, 200 parts, 30 customers, 5 sales representatives, and transactions covering both past and future dates. Future records keep the demonstrator useful over time; they must not appear in reports until their dates have passed. Provide a documented way to refresh the seed's date range so the demo remains useful. Scale is not a quality criterion; adapt it to useful scenarios. Include no-sales cases, adjustments, cancellations, similar customer names, inactive records, and discount boundaries. The random seed and data version must make results reproducible. Do not add HR data to the database “for an attack”; rejecting an operation outside the catalog does not require such data to exist.

## 4. Identity, permissions, and result recipient

An ordinary From header or knowledge of a ticket number does not prove identity. Propose and verify a way to associate a message with a known employee: for example, a trusted integration with the company's provider supplying reliable metadata, or confirmation in an authenticated portal. SPF/DKIM/DMARC can support assessment of message origin, but do not themselves establish an employee's authorization for a particular customer.

Until reliable identification is resolved, do not execute or return a confidential report based solely on From. Unclear identity requires secure manual handling; independent work on synthetic data may continue.

Agreed product policy: a sales representative sees only assigned customers; aggregate reports also cover only those customers. The parts catalog contains only fields allowed by the integration profile. The administrator can access all tickets and reports in the organization and manage sales representative accounts. If no rule permits access, deny it.

The backend checks permissions when accepting an operation, executing it, and making the result available, accounting for permission changes over time. Employee identity and data scope come from trusted context, not parameters returned by the LLM. Do not silently replace an unauthorized scope with another; describe the available scope without revealing anyone else's data.

In production, send notifications and clarification requests only to the registered employee; section 4.1 defines the demo exception. Do not honor a recipient specified in the email body, copy results to arbitrary Reply-To/CC addresses, or send conversation history to a new participant without authorization.

### 4.1. Accounts and environments

The administrator creates an immediately active sales representative account with a company email address. There is no self-registration or additional email-address activation. The system generates a random password and emails it to the employee; the administrator does not see it. Store only a secure password hash in the database; the outgoing message content must not remain in logs or audit records. Provide password changes and resets by generating a new password. The administrator dashboard supports adding, blocking, and deleting accounts and mapping them to sales representative identities in the data source. Blocking invalidates sessions and removes the ability to run or download reports. Deletion removes access while retaining history and audit records according to the retention policy.

Creating an account through the administrator establishes an authorized identity and address, but the From header alone still does not confirm message origin. The production adapter must provide verified origin as specified in section 4. If the mailbox does not provide sufficient evidence, a safe option is to require the employee to log in and confirm a new request before execution. Do not enable trust in From alone in production.

Demo and production have separate databases, secrets, and configuration. The public demo accepts any address without verification and assigns all requests to one shared demonstration sales representative account. Replies go to the sender address of the specific ticket, ignoring arbitrary recipients in the body and CC. This exception is explicitly limited to the demo and synthetic data. Maintain correlation between replies and the participant in the specific thread despite the shared account.

Credentials for the shared sales representative and administrator accounts are displayed in the demo. Participants share visibility of safe demonstration ticket views and synthetic results; inform them of this before use and prohibit submitting confidential or real business data. Sender addresses and incoming message content are not synthetic data. Shared demonstration accounts must not access actual addresses, raw subjects and message bodies, participant-identifying headers, or such data in audit records, APIs, or files. Build public views from approved fields and templates: a synthetic participant alias, the recognized operation, allowed demonstration parameters, and status. Do not publish free-form text from participants or the model; masking an email address alone is insufficient. Full correspondence and contact details are available only to a trusted operator through separate, nonpublic permissions. Do not use the shared demo administrator account for this. The public administrator may practice managing fictional accounts, but must not send passwords to arbitrary addresses or permanently change or block the shared accounts. Capture account-management messages in the demo; ordinary report replies go to the participant. Provide periodic demo-data cleanup and global and per-sender limits. The agent chooses retention periods for synthetic demonstration data. For actual addresses, participant correspondence, and related logs, propose minimal retention periods and a deletion method; the owner must approve them before the public demo launches. Until then, use synthetic messages and controlled test addresses. Do not include other participants' history in messages. Production must not expose public login credentials or demo exceptions.

## 5. Request interpretation and execution

Flow: durable storage → identification → interpretation into a typed plan → operation and parameter validation → authorization → deterministic execution → secure file → notification.

The LLM returns only schema-compliant data, for example:

```json
{
  "operation": "sales_report",
  "parameters": {"start_date": "2026-10-01", "end_date": "2026-10-31"},
  "missing_fields": [],
  "needs_clarification": false
}
```

This illustrates the structure, not the final date contract. Explicitly define and validate range semantics. Define a separate schema for each operation and reject extra fields. Do not accept SQL, table names, arbitrary expressions, file paths, recipients, or identity overrides. Queries are parameterized and implemented in code. The LLM does not define security filters.

Do not silently choose one of several customers with similar names. Resolve identifiers within the permitted scope without revealing names outside it. In the MVP, one request means one operation; multiple requests require choosing one or splitting them, without silently omitting any part.

### 5.1. Reporting periods

Periodic reports cover only completed days in the `Europe/Warsaw` time zone. The reference date is the date the message was received; user-specified start and end dates are inclusive. The backend converts them into an unambiguous time range. “Yesterday” means the previous day; “the last 7 days” means the seven days up to and including yesterday; “last month” means the full previous month; and a month without a year means the most recent completed occurrence of that month. “This month” means from the first day of the month through yesterday; on the first day of the month, indicate that there is no completed period. An explicitly specified period including today or the future requires correction, without silent truncation. A missing period requires a question. This rule does not impose a period on the parts catalog, which does not need one.

Show specific dates in questions and results. A follow-up reply updates a versioned plan while retaining confirmed parameters and dates unless the employee requests a change. An unambiguous request outside the catalog requires refusal; an unclear request that could concern an allowed operation requires clarification; a model failure is a technical error. Date rules and examples of valid requests, clarifications, and refusals must appear in the project README.

### 5.2. Examples of intent and expected behavior

The following cases define expected product behavior. They assume correctly established identity and permissions unless the row states otherwise. For date examples, assume the first message was received on November 15, 2026, in the `Europe/Warsaw` time zone.

| Message or situation | Expected behavior |
| --- | --- |
| “Sales for last month for my customers” | A sales report for October 1–31, 2026, for all assigned customers; no unnecessary question about the customer. |
| “Send the Bosch catalog” | A catalog for the unambiguously identified manufacturer, containing fields allowed by the profile; no question about the period. |
| “Prepare a sales report for my customers” | Ask for the period; do not independently assume a month. |
| A reply of “For October” in a securely correlated thread for the previous request | Update the same ticket with October 1–31, 2026; execute once for the current plan version. |
| “How is customer Alfa doing?” | Clarify the report type and missing parameters; if names overlap, also select the customer within the permitted scope. |
| “Sales for October and the Bosch catalog” | Ask the user to choose one operation; do not execute both or silently omit one. |
| “Sales from November 1 to 15, 2026” | Ask for correction of a period that includes an incomplete day; do not silently shorten it to November 14. |
| A report request for a customer not assigned to the employee | Refuse the unauthorized scope without revealing anyone else's data or substituting another customer. |
| “Send a sales report for October for my customers to another address” | Do not change the recipient; a valid report may be processed only through the normal authorized route, with an explanation of the recipient restriction. |
| “Send a payroll report” or “Delete the database” | Refuse operations outside the catalog; do not access such data or execute the command. |

The agent expands these examples into a development set and tests as specified in section 10. These examples are known during design and therefore do not replace a separate, frozen final evaluation set.

## 6. Clarification and correspondence

A missing period, ambiguous customer, or unrecognized operation leads to a question, not a guess. Build questions from backend templates for missing/invalid fields; the model may identify the problem but does not create arbitrary outgoing content.

An automatic request for missing information is a feature of the intended product, not permission for the implementing agent to send real emails during testing. Limit the number of rounds to 3, and limit rate and cost; hand over to the administrator once the limit is exhausted. Do not reveal data or the full result in a question. Distinguish missing information from permission denial, model error, and transport failure.

Associate replies through Message-ID/In-Reply-To/References and, optionally, an unpredictable token, while checking the participant and organization. Headers do not constitute authorization. Do not correlate based only on “Re:”, subject, or sender. A new email without secure correlation creates a new ticket. Provide audited manual resolution in the dashboard.

Store the version of the established parameters. A late reply, delayed LLM result, or concurrent job must not overwrite a newer plan or generate a file from an outdated parameter set.

## 7. Prompt injection and abuse

Email content and model responses are untrusted. Commands such as “delete the database,” “give me a payroll report,” “I am an administrator,” or “send to another recipient,” hidden HTML instructions, and quoted correspondence do not expand the operation catalog or access rights.

The backend and infrastructure permissions enforce restrictions, not the system instruction alone:
- No arbitrary SQL, tool calling, or destructive operations; business data is read-only.
- Closed sets of operations, schemas, fields, and formats, record-level permissions, and file access controls.
- Limits on input length, report size, job count, queries, and execution time; timeouts and termination of excessive work.
- No fetching links from emails or executing attachments. HTML must contain no active content or remote images. Encode displayed data and protect email headers.
- Protect CSV against formula injection; correct CSV quoting alone does not neutralize formulas. Test fields starting with characters that spreadsheet applications interpret as formulas, and test opening exports in the target tool.
- Refuse unsupported requests or route them to secure manual handling; do not reveal private database schemas or data in messages.

Do not equate valid JSON with correctly interpreted intent. An attack may force an allowed operation with incorrect parameters. Measure interpretation errors, attempted authorization violations, and actual effects separately. An attack detector or a second LLM may help, but is not a security boundary. Do not claim complete resistance; show what was tested and what risk remains.

## 8. Results, files, and resilience

The MVP generates CSV; PDF/XLSX may be added later. The result includes an unambiguous scope, currency, rules version, generation time, and a discount-simulation notice where applicable. “No data” is a valid result, distinct from an error.

Preferred delivery: an email notification linking to a file that requires company login. In the same Vue 3 application, the sales representative has a small “My reports” dashboard listing their files, reporting periods, creation dates, and availability deadlines. After login, the email link opens the specific report with a download button; do not download automatically or immediately log the user out. The sales representative cannot access the administrator dashboard; the administration dashboard remains the main frontend. Do not create a public file directory. Do not send reports as attachments in the first version.

Each file has an owner, ticket, parameter version, data provenance, and retention deadline. Access control also applies to direct URLs. An expired file does not become public; permission changes require a fresh check. Do not log reports or email bodies in ordinary telemetry.

Durable jobs, ingestion deduplication, idempotent generation, and an outbox protect against restarts and retries. Separate report state from notification state. If the sending outcome is unknown, do not retry before resolving it; “accepted by the provider” does not mean delivered. Recognize automatic replies and bounce messages to prevent loops.

## 9. Dashboard and mobile-first UX

A modern, calm operational interface: clear statuses, quick filters, and search. The administrator sees the ticket list, original correspondence presented safely, interpreted operation, parameters, missing fields, authorization state, execution progress, files, and audit trail. Do not display the LLM's internal chain of thought.

The administrator may clarify parameters, resolve ambiguity, and retry an allowed operation; their permissions are also checked in the backend. Every change has an author and history. Statuses reflect the actual process: awaiting interpretation, needs clarification, ready/queued, running, completed, rejected, error/needs intervention. Define names and transitions during discovery; do not conflate them with email delivery.

The working prototype must cover: a valid request, a missing period and subsequent reply, an attempt to request a prohibited report, a provider error, and a result preview. Support phones from 360 px and desktop without horizontal scrolling; address keyboard use, focus, labels, contrast, and loading/empty/error/success states. Use synthetic data only.

### 9.1. Mockups as the interface reference

Local mockups are included with the brief in `makiety/`. Use them from discovery onward as a reference for appearance, information hierarchy, and the application's responsive layout. Before designing the prototype, review both sets: [desktop gallery](makiety/desktop/index.html) and [mobile gallery](makiety/mobile/index.html). File descriptions and updates are documented in the [desktop README](makiety/desktop/README.md) and [mobile README](makiety/mobile/README.md).

| View and purpose | Desktop: source / preview | Mobile: source / preview |
| --- | --- | --- |
| Employee login — entry point for authenticated result retrieval | [HTML](makiety/desktop/01-logowanie-pracownik.html) · [PNG](makiety/desktop/01-logowanie-pracownik.png) | [HTML](makiety/mobile/01-logowanie-pracownik.html) · [PNG](makiety/mobile/01-logowanie-pracownik.png) |
| Administrator login — entry point to the operational dashboard | [HTML](makiety/desktop/02-logowanie-admin.html) · [PNG](makiety/desktop/02-logowanie-admin.png) | [HTML](makiety/mobile/02-logowanie-admin.html) · [PNG](makiety/mobile/02-logowanie-admin.png) |
| Ticket overview — counters, search, status filter, list, and pagination | [HTML](makiety/desktop/03-przeglad.html) · [PNG](makiety/desktop/03-przeglad.png) | [HTML](makiety/mobile/03-przeglad.html) · [PNG](makiety/mobile/03-przeglad.png) |
| Ticket details — correspondence, status, report parameters, CSV file, and access to history | [HTML](makiety/desktop/04-zgloszenie.html) · [PNG](makiety/desktop/04-zgloszenie.png) | [HTML](makiety/mobile/04-zgloszenie.html) · [PNG](makiety/mobile/04-zgloszenie.png) |

Preserve the shared visual direction: Ubuntu Light 300 and Bold 700 typography, a yellow logo background of `#ffcf78` with a purple mark in `#482341`, a teal employee palette and purple administrator palette, light content surfaces, rounded cards, subtle wave motifs, and buttons with icons on the left. Use the included HTML/CSS sources, SVGs, and local fonts with their license. Supplement status colors with text and keep their meanings consistent.

The desktop set shows the layout at 1500 × 1000 px, and the mobile set at a width of 390 px. On phones, the ticket table becomes cards, statistics use a 2 × 2 grid, details use one column, and navigation becomes a dropdown menu. Include mobile Previous / Next pagination with controls at least 48 px tall. The implementation must be fluid and meet the requirements from 360 px in section 9; screenshot dimensions are not a fixed application size.

The mockups are static design material. The current HTML/CSS sources and included assets are the basis for implementing the appearance; PNGs are previews and may show an earlier revision. If v7/v8 labels in the READMEs differ, compare sources with previews, record any material difference, and do not treat a version number in a description as a separate product decision. The mailbox address shown in the mockups is an example; the actual address comes from environment configuration and requires confirmation during integration. Login, search, filtering, pagination, downloading, and backend operations require implementation and verification. Sample names, dates, counters, filenames, and statuses are demonstration data. Menu items and placeholder links do not establish additional modules or MVP scope. Final statuses and their transitions follow the process described in the brief.

Extend the mockups in the prototype to cover missing scenarios: request clarification, access denial, provider error, administrator intervention, empty/loading states, and the employee's authenticated result-retrieval view. The employee login screen alone does not define that last view. Verify accessibility, contrast, and keyboard operation. In case of conflicts, the brief's functional, security, and accessibility requirements take precedence; justify and document material visual deviations in the prototype documentation. Evaluate the prototype by comparing it against the mockups on desktop and phone and against the scenarios in sections 9 and 10.

## 10. Criteria and evaluation

Before implementing dependent increments, record testable criteria. Required scenarios:
- A known query produces a file matching an independently calculated expected result, including adjustments, empty periods, and discount boundaries.
- A missing parameter triggers the correct question; the reply updates the same ticket and generates the result once.
- A spoofed sender, forged context, another employee's customer, and a direct link to someone else's file do not grant access. Rechecking detects revoked permissions before execution/download.
- Requests to delete data, generate a payroll report, or change the recipient remain within the contract, even with a manipulated model output.
- Duplicate ingestion, duplicate jobs, restarts, late replies, and provider timeouts have defined, tested outcomes.
- An AI failure or exceeded limit preserves the ticket and allows manual handling; it must not pretend that the request was successfully processed.

Build synthetic ordinary, ambiguous, out-of-catalog, and attack cases. Separate the development set from the final evaluation; do not tune prompts to frozen examples. Propose a representative set size and quality threshold before measuring. Compare against a simple form or rule-based parser as a baseline.

Measure operation selection, parameter completeness/accuracy, correctness of the entire result, relevance of questions, unnecessary questions, manual handling, unauthorized disclosures, time, and cost where available. Report denominators; separate the quality of ordinary requests from attack handling. Zero violations in a test is not a security guarantee. CI uses test doubles; real evaluation requires agreed costs and must identify model, prompt, and data versions.

## 11. Increments and owner decisions

Recommended order:
1. Discovery: operation vocabulary, financial and access rules, threat model, prototype.
2. Deterministic report engine using seeded data, API, login, and dashboard. First obtain correct results without an LLM.
3. Mail ingestion, secure identity, and durable tickets.
4. LLM interpretation into a plan, evaluation, and attack tests.
5. Clarifications, reply correlation, and controlled notifications.
6. File delivery, failure scenarios, staging, and release readiness.

Do not plan the entire product in detail before validating the main flow. Use independent review and AppStandards gates. Production requires separate approval.

Do not repeat questions settled in this brief. The agent chooses reversible implementation details, demonstration report columns, reasonable limits, and retention periods for synthetic demo data, and records material assumptions. Gather only new material decisions and information needed for the dependent stage, such as mailbox and hosting settings. Lack of service access does not block local work with test doubles. The build includes preparing real mail and OpenAI integrations. Send test messages to real addresses after configuring the mailbox and specifying test addresses; public demo replies begin only after approval to launch that environment. Do not use real business data or enter into new paid commitments without agreement. Production deployment requires separate approval. Share secrets only through a secure channel.

## 12. Source material for campaign no. 2 — implementation requirement

From discovery onward, maintain an index at `docs/case-study/README.md` and reports on significant stages in `docs/case-study/stages/`. This is a record of facts and links to sources, not a second backlog. Tasks, ADRs, PRs, QA, metrics, and the release record remain the sources of truth under AppStandards.

Create a report at the natural end of a stage or upon a significant finding: prototype, architectural decision, first correct report, email authentication, attack experiment, evaluation, bug/fix, staging. Not after every edit, and no separate PRs merely to increase the count. Project documentation is in English.

Each report must include:
1. Date, stage, task/PR/commit, and environment; explicit identification of fixtures, test doubles, or real integrations.
2. Problem, goal, and initial state; known limitations and unknowns.
3. Options actually considered and the rationale for the decision; a link to the relevant ADR.
4. Work performed, unsuccessful attempts, hypotheses, and results; a distinction between observations, assumptions, and interpretations.
5. Human and agent contributions: who proposed, decided, implemented, and corrected; do not invent interventions.
6. Criterion → evidence → result → limitation; code, prompt, model, and dataset versions. Explicit gaps in evidence.
7. Available measurements with sources and scope: time, tokens, cost, latency, and quality. Separate the cost of agent-assisted development from the application's LLM cost. Missing values are unavailable/null, not zero; do not infer active work time from the interval between dates.
8. Outcome, remaining risk, and impact on the next stage.
9. An optional publication angle supported by evidence: problem → decision → verified result → useful lesson. No finished post and no forced success narrative.

Preserve useful diagrams, phone/desktop UI screenshots, and request–plan–result examples, using synthetic data only. Record limitations and negative results as rigorously as successes. Do not manufacture failures for the story; label controlled experiments as experiments. Do not store the model's internal chain of thought, secrets, private correspondence, or full logs containing data.

At the end, prepare `docs/case-study/final-summary.md`: chronology, decisions, test/evaluation results, interventions, measurements, limitations, and an evidence index. Propose campaign topics grounded in the material, without requiring 12 posts. Materials remain private pending human review. Do not automatically generate a campaign or publish on LinkedIn.

## 13. Expected outcome

The first release is a public demo using synthetic business data, with real mail and OpenAI integrations and deployment. Connecting a specific organization and its real data is a separate stage requiring agreement on its rules, permissions, and integrations. Implement production-mode mechanisms within this brief's scope and verify them using synthetic data; do not claim that this verifies deployment for a real customer. The first release aims to deliver a complete, working demonstration application that is publicly accessible after launch approval, not just a local demonstrator. Local work, CI, connecting services, secured staging, and publication are delivery stages. Missing access blocks only the dependent stage. Agree on hosting and its cost before provisioning resources; do not postpone investigating worker, mail, and file limitations until the end of development.

A working application with a PHP/Symfony API, a PostgreSQL database, and a Vue 3/TypeScript frontend; a synthetic database and repeatable seed; a controlled report catalog; a secure correspondence flow; files protected by authorization; tests and evaluation; CI and documentation following AppStandards; staging; and campaign source material. Report implementation, integration, and production status separately.

The agent's first response should summarize the MVP, identify only new material gaps with recommendations, and begin independent discovery/prototype work. Do not stop at declaring readiness.

## Sources and notes

- AppStandards: [GitHub repository](https://github.com/MichalWrzesinski/AppStandards). At project start, fetch the latest state of the default branch without specifying a particular standards-set version. Then use the project copy according to the standards, without editing the standards repository.
- Authorization: https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html
- Prompt injection: https://cheatsheetseries.owasp.org/cheatsheets/LLM_Prompt_Injection_Prevention_Cheat_Sheet.html
- Correspondence headers: https://www.rfc-editor.org/rfc/rfc5322.html#section-3.6.4

Verify details of selected services in their current official documentation during implementation. This brief defines the product and criteria; it does not yet confirm integration feasibility or market demand.
