# TASK-015 — Monitoring, error reporting and operations

Status: DONE · Priority: high · Increment: 6 · Depends on: TASK-014 · Standards: [operations](../standards/development/operations.md), [deployment setup](../standards/development/deployment-setup.md#checklist-before-the-first-release)

## Owner decisions (2026-11-24)
- **Demo environment:** the public demo runs on the current staging, https://tabularius.gitpush.app, once the owner approves the launch. At launch the environment login is removed and replies go to every sender. There is no separate test environment then; changes are verified locally and in CI. The launch switch belongs to TASK-016.
- **Backups:** the same arrangement as in Profilo:
  - the VPS provider's daily backup of the whole server;
  - the dump before each deployment (last 3 kept on the server).

  There is no copy at another provider; the owner accepts this risk. The data is synthetic, apart from tester addresses and texts kept for 7 days.
- **Monitoring:** the same arrangement as in Profilo:
  - Better Stack checks `/api/ready`;
  - Sentry collects API and dashboard errors without personal data;
  - GitHub mails failed workflows.

  The owner creates the accounts and secrets. The agent wires the code and CI.
- **Legal pages:** drafts with explicit placeholders for the service provider's details, reviewed by the owner before launch (TASK-016).

## Goal and scope
- **Sentry in the API** (`sentry/sentry-symfony`). It is enabled only when `SENTRY_DSN` is set; release = the deployed commit, environment = `staging`.
  - Every event is scrubbed of personal data and secrets before sending: e-mail addresses, passwords, tokens, cookies, session ids, mail texts, SQL parameters.
  - Error-level log entries are reported too.
- **Sentry in the dashboard** (`@sentry/vue`). It starts only when the API says monitoring is enabled.
  - Events go through the API (`POST /api/monitoring/envelope`), not straight to Sentry. The CSP stays `connect-src 'self'`.
  - The API scrubs every browser event the same way and forwards it to the DSN configured on the server. The size of an envelope and the request rate are limited.
- **Source maps:**
  - the client build makes hidden source maps with debug ids;
  - the maps leave the served directory;
  - after each deployment CI creates the Sentry release, uploads the maps from the published image and records the deployment. Without a Sentry token this step is skipped with a notice.
- **Runbook:** monitoring and alerts, the alert route (kontakt@webhome.pl, owner), the backup arrangement and its accepted risk, the first steps on an alert. The owner's setup steps are listed in it: the Better Stack monitor, the Sentry project and alert, GitHub e-mails, secrets.

## Acceptance
- **Disabled without a DSN:** the API and the dashboard report nothing and the monitoring endpoint answers 404.
- **Scrubbing:** a test event with an e-mail, a password field, a token, a cookie, a mail body and SQL parameters leaves with none of them. The release and environment are set.
- **Tunnel:**
  - it accepts a valid browser envelope, scrubs it and forwards it to the configured DSN (a recorded HTTP client in tests);
  - it rejects an envelope that is too large, malformed, or for a different project;
  - it is rate-limited.
- **Images:** the client image serves no `.map` file and contains the maps outside the web root.
- **CI:** the deploy workflow runs the Sentry release step only with a token, and the token never reaches the server.

## Verification
- **API:** functional tests (scrubber, tunnel, disabled state).
- **Client:** unit tests for the monitoring setup.
- **Image:** a local image build checks that no maps are served.
- **Staging:** after the owner sets the secrets, a test error from the API and one from the dashboard arrive in Sentry with no personal data, and a test alert from Better Stack arrives.

## Report
- **API:** `sentry/sentry-symfony`. Errors reach Sentry through a Monolog handler in prod (error level, uncaught exceptions included); the SDK's own error listener and the Messenger integration are off, so nothing is reported twice. Missing variables mean off, never a broken container.
  - **`Scrubber`** (`before_send`) applies to every event:
    - it drops the user, cookies, request bodies and env;
    - it replaces the values under sensitive keys whole;
    - it cleans texts of e-mail addresses, Basic/Bearer and JWT tokens, `token=`-style query values, long opaque strings and the SQL parameters of Doctrine messages;
    - UUIDs, the release and event ids stay.
- **Tunnel:** `GET /api/monitoring/config` (404 when off) and `POST /api/monitoring/envelope`.
  - **Checks:** the envelope must name the configured DSN, is at most 200 kB, is rate-limited to 30 per minute per IP, and needs the CSRF header like any API call.
  - **Forwarding:** only `event` items, scrubbed, go to the configured project, with the server's key.
- **Dashboard:** `@sentry/vue`, loaded only when the config endpoint says on.
  - **Transport:** the tunnel, with the CSRF header.
  - **Collected:** no default PII, no breadcrumbs, sessions, tracing or replay. The browser also drops the user, cookies and the query string.
- **Source maps:**
  - the build makes hidden maps and `sentry-cli sourcemaps inject` adds debug ids;
  - the client image moves the maps to `/opt/sourcemaps`. A local image build checked it: 0 `.map` files served, 31 kept, 31 scripts with debug ids.
  - the `Sentry release` job in `deploy-staging` (its own job, the token never reaches the server) creates the release, uploads the maps and records the deployment. Without `STAGING_SENTRY_AUTH_TOKEN` it ends with a notice.
- **Deployment:** the secret `STAGING_SENTRY_DSN` is validated and written to `.env.mail`; the compose file sets `SENTRY_RELEASE` (commit) and `SENTRY_ENVIRONMENT=staging`.
- **`app:monitoring:test`:** logs one test error with a fake address and token, for the owner's check on staging.
- **Runbook:** new sections „Monitoring and alerts” (what watches what, the owner's setup steps, first steps on an alert) and „Backups” (the accepted arrangement, restoring from a dump).
- **Tests:** API 429 (96.06 % lines): `ScrubberTest`, `MonitoringTest` (off without a DSN, SDK options, config, forwarding and scrubbing, invalid envelopes, CSRF, rate limit), `MonitoringTestCommandTest`. Client 50 (`monitoring.test.ts`). shellcheck and actionlint clean.
- **Owner's setup (2026-11-24):**
  - Sentry project `tabularius` in the organisation `webhome-6c`;
  - secrets `STAGING_SENTRY_DSN` and `STAGING_SENTRY_AUTH_TOKEN` (a separate organisation token, `tabularius-ci`);
  - variables `SENTRY_ORG` and `SENTRY_PROJECT` (set by the agent);
  - the Better Stack monitor on `/api/ready`, reported done by the owner.
- **Verified on staging (2026-11-24, `6720185`):**
  - **Release:** the `Sentry release` job created the release, uploaded the dashboard's source maps with debug ids and recorded the `staging` deployment.
  - **Monitoring on:** `/api/monitoring/config` reports it, with environment `staging`.
  - **Dashboard:** a test error thrown in the browser went through the tunnel, and Sentry accepted it (200).
  - **API:** `app:monitoring:test`, run by the owner over SSH, logged a test error.
  - **Events:** the owner confirmed that both events arrived in Sentry.
- **Not confirmed:**
  - the absence of the test address and token in the Sentry events, checked only by tests;
  - the Sentry error-count alert;
  - a Better Stack test alert.
