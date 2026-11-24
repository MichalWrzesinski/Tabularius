# TASK-016 — Legal pages, participant notice and release readiness

Status: IN PROGRESS · Priority: high · Increment: 6 · Depends on: TASK-015 · Standards: [privacy](../standards/development/privacy.md#documents-before-public-release), [deployment setup](../standards/development/deployment-setup.md#checklist-before-the-first-release)

## Context
- **Already public:** the owner opened the demo to everyone and turned on replies to every sender on 2026-11-24, before the legal pages were ready. The footer links still open placeholders.
- **Drafts only:** the owner chose drafts with explicit placeholders for the service provider's details. The content must be reviewed by a human before it counts as final.

## Goal and scope
- **Legal pages** (Polish), open without login at stable URLs, linked in the footers of the home page, the login pages and the dashboard:
  - `/polityka-prywatnosci` covers:
    - the controller (placeholder);
    - the data and where it comes from: sender address, subject and text of e-mails, account data of the demo, session cookie, technical logs;
    - the purposes and legal bases;
    - the recipients: the hosting provider (placeholder), Brevo (outgoing mail), OpenAI (interpretation, `store: false`), Sentry (error reports without personal data, once enabled);
    - transfers outside the EEA;
    - the retention periods the application enforces;
    - the rights and the supervisory authority;
    - that only a technically necessary cookie is used.
  - `/regulamin` covers the demo service:
    - what it does, that its data is synthetic, and that the views are shared;
    - the ban on confidential or real business data and on abuse;
    - the limits, availability without guarantees, and liability;
    - contact and complaints (placeholders), and changes.
  - Each page shows its version date and a notice that it is a draft awaiting the provider's review. Placeholders look like `[do uzupełnienia: …]`.
- **Participant notice (ID-DEMO-6):** the demo box on the employee login page says that views are shared and that confidential or real business data must not be sent, with links to both documents.
- **Retention gap:** the pseudonymous audit log (ID-DEMO-7: 90 days) is not deleted today. `app:audit:purge` deletes events older than 90 days and runs in the daily `purge` service.
- **Release readiness:** `docs/release/public-demo-readiness.md` holds the first-release checklist for the public demo on staging:
  - each item with its state, evidence, and what remains;
  - the risks the owner accepted, with dates;
  - the owner's open steps.

## Acceptance
- **Public pages:** both pages open without login, at 360 px and on desktop, without horizontal scrolling, with a heading structure and visible focus.
- **Footer links:** every footer that had a legal placeholder links to the pages; the placeholder dialog is gone.
- **Content:** it states only what the application really does (checked against the code and configuration); every unknown is a visible placeholder.
- **Notice:** the employee demo box shows the notice and the links.
- **Audit retention:** `app:audit:purge` deletes only events older than 90 days (test).
- **Readiness document:** it lists every first-release item with its state.

## Verification
- **API:** a test for the audit purge.
- **Client:** lint, typecheck, build.
- **Browser:** a check of both pages and the footers at 360 px and desktop on the local stack.
- **Staging:** after deployment, both URLs answer 200 without login.

## Report
- **Legal pages:** `/regulamin` and `/polityka-prywatnosci` are public routes (`LegalView`).
  - **Content:** it lives in `client/src/lib/legal.ts`; placeholders are highlighted by `LegalText`. Each page shows its version date and a draft notice.
  - **Footers:** `LegalLinks` replaces the placeholder dialog on the home page and adds the links to the login pages, the dashboard and the employee panel.
- **Facts checked in code:**
  - **OpenAI:** gets the subject and the full text (a signature included), never the header address.
  - **Retention:** the 7-, 7-, 7- and 90-day periods; logs are rotated by size.
  - **Cookie and limits:** the single session cookie and the actual limits.
- **Participant notice (ID-DEMO-6):** the employee demo box says that views are shared and forbids confidential or real data, with links to both documents.
- **Audit retention:** `app:audit:purge` (90 days) runs in the daily `purge` service. Nothing deleted the audit log before.
- **Release readiness:** [public-demo-readiness.md](../release/public-demo-readiness.md) covers the owner's decisions and accepted risks, the first-release checklist with states, the placeholders to fill in, and the facts the documents rely on.
- **Tests:**
  - API: 430 tests (`AuditPurgeTest`).
  - Client: 53 tests (`legal.test.ts`).
  - Browser, on the local stack:
    - both pages and the login page at 375 px without horizontal scrolling;
    - footers on the home page (desktop) and the login page;
    - the placeholder dialog is gone.
- **Open (owner):**
  - fill in the placeholders and review the texts;
  - decide on indexing;
  - check SPF, DKIM and DMARC.
