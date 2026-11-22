# TASK-010 — Business-scope guard: abusive instructions never reach the model or the data

Status: DONE (PR pending CI) · Priority: highest (owner requirement) · Increment: 4 · Depends on: TASK-008 · Decision: [ADR 0005](../decisions/0005-business-scope-guard.md)

## Goal and scope
Every message is checked against the business concept: Tabularius only prepares reports from the closed catalog for the requester's own scope. Two deterministic layers apply, independent of the interpreter:
- **RequestScreen**, before interpretation, refuses abusive instructions. Categories: data modification, credential access, code generation, system manipulation, foreign data. The model is not called.
- **PlanGuard**, after interpretation, admits only plans built from closed, typed values in the owner's scope, whatever any interpreter returns.

Refusals send the templated reply and record audit events with codes only.

## Acceptance
- Attack corpus (Polish and English, without diacritics, upper case, letter-spaced, zero-width, look-alike letters, Base64-encoded, hidden in HTML, mixed with a legitimate request): every case is refused or ends without execution; no file, no data and no echo of the request in any outgoing mail.
- Corpus of legitimate requests: no false refusals.
- A compromised interpreter returning hostile plans is stopped by PlanGuard every time. Examples: an operation outside the catalog, extra parameters (`sql`, `recipient`, `path`), customers outside scope, values with SQL or URLs, nested structures, unknown issue codes.
- Audit shows the category or violation codes, never the text.

## Verification
Unit tests for both layers (large tables), plus an end-to-end abuse test through ingestion → interpretation → routing → outbox, with the rule interpreter and with a compromised-interpreter double.

## Report
- `api/src/Module/Interpretation/Guard/RequestScreen`, layer 1, before any interpreter:
  - normalization against evasion: NFKC, zero-width removal, Cyrillic/Greek look-alikes, diacritics, letter-spacing collapse, digit-for-letter substitution for long keywords, URL and Base64 decoding;
  - categories: `data_modification`, `credential_access`, `code_generation`, `file_generation`, `system_manipulation`, `foreign_data`;
  - a hit → `rejected` (`outside_business_scope`), the interpreter is not called, the templated refusal is sent, and the audit records `security.request_refused` with the category only.
- `Guard/PlanGuard`, layer 2, after any interpreter. It admits only:
  - intent, operation and issue codes from closed sets (`InterpretedPlan::INTENTS`, `ISSUES`, the profile catalog);
  - parameter names from the operation's schema;
  - typed values: a strict period expression, customer codes in the owner's scope (at most 100), manufacturer codes that exist, profile categories, a policy pattern;
  - plain strings only: at most 64 safe characters, no nesting deeper than a period object or a list of codes.

  Outcomes:
  - a violation → `needs_intervention` (`model_output_rejected`), audit `security.plan_rejected` with violation codes, nothing stored as an executable plan, nothing queued;
  - a customer outside the scope as the only violation → an ordinary refusal (`out_of_scope`, audit `security.scope_refused`).
- The refusal template states the boundary: reports from the catalog for the requester's customers only; no changes, no deletions, no passwords or access data, no code or other files.
- The dashboard audit labels cover the ticket and security events.
- Evidence (API: 395 tests, 2 147 assertions, 96.16 % lines):
  - `RequestScreenTest`: 55 attacks across all categories and evasion forms, each refused with its category; 21 legitimate requests including signatures, greetings, „wszystkich moich klientów”, „do Excela”, „alternatorów”, none refused.
  - `PlanGuardTest`: 25 hostile plans, each stopped with its violation code. Examples: an operation outside the catalog, `sql`/`recipient`/`path`/`salesRep` parameters, foreign customers, SQL in codes, free-text periods, nested values, unknown intents and issues. 8 legitimate plans pass.
  - `AbuseFlowTest` (end to end: ingestion → guard → interpretation → execution → outbox, 380 assertions):
    - 15 abusive e-mails (including hidden HTML, Base64, look-alike letters, abuse mixed with a legitimate request) → all rejected with the right audit category, no executable plan stored, no file, and every outgoing mail a template with no fragment of any request;
    - a compromised interpreter returning 8 hostile plans → all stopped, audited, nothing queued;
    - ordinary requests still complete.
- Limitations:
  - The screen is keyword-based. Unknown phrasing can evade it, but then still meets PlanGuard, the engine check and an interpreter without tools. Measured again with the LLM in TASK-012.
  - A false refusal is possible, by design preferred to the opposite; the legitimate corpus guards against regressions.
