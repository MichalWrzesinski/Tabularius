# 01 — Project start and discovery

## 1. Context

- Date: 2026-11-21. Stage: project start, discovery (increment 1). Commit: initial documentation commit of the local repository (no remote yet).
- Environment: owner's Mac, local files only. No fixtures, test doubles or real integrations used yet; no code.
- Inputs: owner's brief `start-info.md` (copied to [docs/input/brief.md](../../input/brief.md)), owner's static mockups desktop v8 / mobile v8 (copied to [docs/design/mockups/](../../design/mockups/)), AppStandards `main` at `410ba9c` (copied to `docs/standards/`).

## 2. Problem, goal, initial state

Goal of the stage: turn the brief into testable product rules for the next increment — operation vocabulary, financial and date rules, access and identity, ticket lifecycle, threat model and evaluation plan — and identify only new material gaps. Initial state: no repository; a static placeholder page for the product already exists separately (`TabulariusPlaceholder`, pointing at `tabularius.gitpush.app`).

Unknowns: mailbox provider and its authentication metadata, hosting, OpenAI model and pricing (to be checked in current docs at increment 4), real participant data retention.

## 3. Options and decisions (no ADRs yet; product rules are in `docs/product/`)

| Question | Options considered | Decision (agent, reversible unless marked) | Where |
|---|---|---|---|
| How the LLM expresses dates | (a) model returns ISO dates; (b) model returns a closed set of period expressions resolved by backend | (b): removes date arithmetic from the model and makes DATE rules testable deterministically | [reports § periods](../../product/areas/reports.md#reporting-periods) |
| Inclusion date | invoice issue date; sale date; adjustments on original or own date | Invoice by sale date, adjustment by its own issue date, canceled excluded | RPT-SALES-1 |
| Discount policy | percentage tiers, per-product rules, annualized thresholds | Simple fictional 4-tier policy `DEMO-RABAT 2026.1`, half-up rounding on the final amount, boundary table incl. a half-up vs half-even case | RPT-DISC |
| Production identity | trust `From`; DMARC/Authentication-Results only; confirmation in portal | Recommend portal confirmation by default, provider metadata only as a verified opt-in (needs owner awareness, see questions) | ID-2, ID-3 |
| Admin actions scope | admin's own (all customers) vs ticket owner's | Ticket owner's scope, so admin intervention cannot widen a report | ACC-ADM-1 |
| CSV format | comma/point vs `;`/decimal comma | `;` + decimal comma + BOM for Excel pl-PL; metadata rows with `row_type` | RPT-OUT-2/3 |

## 4. Work and findings

- Observation: the `/new-app` skill points to `MichalWrzesinski/AiAppFactory`, which returned HTTP 404 on 2026-11-21; the skeleton could not be used. The project was created per AppStandards setup (`~/projects/Tabularius`, PascalCase) with the minimum documentation foundation instead.
- Observation: the mockups' ticket-details view shows the sender's address and raw body; the brief forbids that in public demo views. Recorded as a deviation in [design README](../../design/README.md).
- Observation: the brief's status list merges „error” and „needs intervention”, while the mockup has a separate „Błąd” pill. Resolved as one status with a reason code.
- Observation from a sibling project (Profilo runbook, not verified for this project): the owner's shared staging VPS blocks outgoing ports 25/465/587. If reused, SMTP must go through a relay on another port; IMAP (993) reachability is unverified. Relevant to the hosting question.
- Interpretation: the portal confirmation step costs the employee little because downloading already requires login; this is an assumption to check in the prototype review.

## 5. Contributions

- Owner: brief, product name, tagline, stack, mockups, AppStandards.
- Agent (Claude Code): repository creation, standards copy, discovery documents, rule proposals and examples above, open questions. No human corrections yet in this stage.

## 6. Criteria and evidence

| Criterion | Evidence | Result | Limitation |
|---|---|---|---|
| Brief examples (§5.2) have defined outcomes | [tickets § examples](../../product/areas/tickets.md#examples-reference-date-2026-11-15-identity-established) | all 10 mapped | Not yet executable tests |
| Date examples consistent with the calendar | weekday check of 2026-11-15 (Sunday), 2026-11-02 (Monday) by script | consistent | — |
| Discount boundaries computed | hand calculation in [reports](../../product/areas/reports.md#rpt-disc--customer-activity-and-discount-simulation-customer_discount_simulation) | 8 cases | To be confirmed by fixture tests in increment 2 |

## 7. Measurements

- Agent development time, tokens, cost: unavailable (no usage export configured for this session).
- Application LLM cost: none incurred (no calls).

## 8. Outcome and next stage

Discovery draft complete for increment 1–2 dependencies; nothing blocks the prototype. Next: working prototype in the target stack (`client/`, Vue 3 + TS, mocked API) covering valid request, missing period + reply, prohibited report, provider error, result preview, employee report retrieval, desktop and 360 px. Remaining risk: production identity mechanism depends on the real mailbox provider.

## 9. Possible publication angle

„Don't let the model do the calendar”: the LLM returns a closed period expression, deterministic code resolves dates — to be supported by evaluation numbers later, not yet.

## Open questions for the owner

| # | Question | Impact / blocks | Recommendation |
|---|---|---|---|
| Q1 | Production identity: accept portal confirmation before execution as the default? | Production mode design (increment 3); not the demo | Yes; provider metadata only as a later verified opt-in |
| Q2 | Mailbox provider, address (mockup: `zgloszenie@gitpush.app`), IMAP/SMTP hosts | Real adapter (increment 3); local work uses a stub | Send provider name and address; secrets later via a secure channel |
| Q3 | Hosting for staging and the public demo, and its cost | Staging (increment 6); worker/IMAP/SMTP limits must be checked early | Check the existing shared VPS (used by Profilo staging) for 2× PostgreSQL, PHP workers, cron, outgoing SMTP port; otherwise a small dedicated VPS |
| Q4 | Domain `tabularius.gitpush.app` (existing placeholder) for the app | Persistent identifiers, mail links | Use it |
| Q5 | Budget cap for real OpenAI evaluation runs | Increment 4 real evaluation | e.g. USD 20 for development and final runs, with a daily guard in the app |
| Q6 | Retention for real demo participants (addresses, messages, related logs) | Public demo launch only | Raw messages and addresses 7 days, then deleted; pseudonymous audit 90 days; synthetic files 7 days; logs without bodies 14 days |
| Q7 | Private GitHub repository `MichalWrzesinski/Tabularius` | Setup stage (CI) | Create it as private at setup (AppStandards default) |
