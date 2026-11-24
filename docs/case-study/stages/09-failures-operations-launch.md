# 09 — Failure scenarios, operations and opening the demo (increment 6)

## 1. Context
- Date: 2026-11-23 to 2026-11-24. Increment 6. Tasks:
  - [TASK-014](../../tasks/TASK-014-failure-scenarios.md), failure scenarios (PR 43);
  - [TASK-015](../../tasks/TASK-015-monitoring-and-operations.md), monitoring and operations (PR 44);
  - the launch switches (PRs 45 and 46);
  - [TASK-016](../../tasks/TASK-016-legal-pages-and-readiness.md), legal pages and readiness (PR 47, merge `5975131`).
- Environment:
  - **Tests:** functional tests with test doubles (recording mailer, mock HTTP client for Sentry).
  - **Staging:** became the public demo on 2026-11-24. It has the real mailbox, Brevo, and OpenAI. Sentry and Better Stack are not connected yet.
- Release record: [public demo readiness](../../release/public-demo-readiness.md).

## 2. Goal and initial state
- **Brief §8 and §10:**
  - a failure must never pretend that the request succeeded;
  - an unknown send outcome must not be retried before it is resolved;
  - bounces must not cause loops.
- **Before, failure handling:** a job that exhausted its retries left its ticket in `running` or `interpreting` forever. An `unknown` notification could not be resolved from the dashboard. Bounces were recognized as automatic mail (never answered), but not tied to the message that bounced.
- **Before, operations:** no monitoring, no alerts, no legal pages (placeholder dialogs), no release record.

## 3. Options and decisions
| Question | Options | Decision |
|---|---|---|
| Exhausted jobs | ignore (stays in the failed queue); a sweeper only; react to Messenger's final failure | Both. A listener on the final failure moves the ticket to `needs_intervention` (`processing_failed`). A daily sweep handles anything still processing after 24 h (`processing_stalled`) |
| Unknown sends | retry automatically; administrator decides | Administrator: „uznaj za wysłane” or „wyślij ponownie” with the same `Message-ID`, audited |
| Browser errors to Sentry | browser direct to Sentry (CSP change); through the API | Through the API tunnel: CSP stays `connect-src 'self'`, and the API scrubs every event again |
| Demo environment, backups, monitoring | separate production stack; off-server backups | **Owner (2026-11-24):** demo on the current staging; backups and monitoring as in Profilo; legal pages as drafts |
| Opening the demo | after legal pages and monitoring; immediately | **Owner:** immediately. The environment login was removed, and right after that replies to every sender were turned on. Both are switches (repository variables), so they can be reversed |

## 4. Work, findings, failures
- **Finding (test design):** sending a fake "failed" event in tests did nothing at first. Messenger's own retry listener runs before ours and marks a first failure as retryable. The tests now attach a `RedeliveryStamp(3)` to simulate an exhausted job.
- **Finding (bounces):** an out-of-office reply also answers our `Message-ID`. Only delivery-status reports (`multipart/report; report-type=delivery-status`, or `X-Failed-Recipients`) mark a message `bounced`.
- **Finding (scrubbing):**
  - the first sensitive-key pattern contained `text`, which also matched `monolog.context` and erased the whole log context;
  - the long-secret pattern also erased event ids and the release.

  Both were caught by the scrubber's own tests before deployment.
- **Finding (retention gap):** while writing the privacy policy from the code, the agent found that nothing deleted the audit log, although the owner had approved 90 days on 2026-11-21. Fixed in TASK-016 (`app:audit:purge`).
- **Finding (privacy wording):** OpenAI receives the full mail text, a signature included. The draft first said „without the sender's address”, which is true only of the header address. The policy now says so.
- **Blocked action:** the agent could not read the local demo password from `.env` to check the resolve buttons in a browser, because the permission was denied. The buttons are covered only by unit tests of their logic.
- **Checked locally:**
  - the launch switch against Caddy 2.11.9: `true` gives 200, `false` or empty gives 401, and `/api/ready` stays open;
  - the client image serves 0 source maps and keeps 31 outside the web root.

## 5. Human and agent contributions
- **Owner:**
  - **Decisions (2026-11-24):** demo environment, backups, monitoring, legal drafts.
  - **Launch:** the instruction to open staging to everyone, and to enable replies immediately; the agent recommended waiting for the legal pages.
  - **Deferred:** the provider's details for the legal pages.
- **Agent:**
  - specifications, code, tests, deployment through CI, legal drafts, runbook, readiness record;
  - the recommendations the owner overrode (waiting before replies to all).

## 6. Criteria → evidence → result
| Criterion | Evidence | Result | Limitation |
|---|---|---|---|
| No failure leaves a ticket claiming progress | `FailureScenarioTest` (exhausted, stale, stalled) | met | the real worker crash path relies on Messenger redelivery, not tested end to end |
| Unknown send never resent automatically; administrator resolves | `NotificationTest`, `FailureScenarioTest` | met | buttons not checked in a browser |
| Bounce marks the message; auto-reply does not | `FailureScenarioTest` | met | no real bounce observed on staging |
| Error reports carry no personal data | `ScrubberTest`, `MonitoringTest` | met in tests | Sentry not connected: no real event checked |
| Demo open; API still needs the application's login | deploy smoke tests and external checks (`7214abd`, `a56ae05`, `5975131`) | met | — |
| Legal documents ready and reviewed | pages live with placeholders | **not met** | drafts; the owner's review and details pending |

## 7. Measurements
- **Tests at `5975131`:** API 430 tests (96.06 % lines), business 49, client 53.
- **Application LLM cost in this stage:** not measured separately. The model was called only by real demo traffic, and the cost is visible in `ai_usage` on the server.
- **Agent development cost:** unavailable.

## 8. Outcome and next stage
- **Outcome:** the demo is public at https://tabularius.gitpush.app and answers every sender.
- **Remaining risk, accepted by the owner:**
  - public use with draft legal pages and without monitoring;
  - no off-server backup;
  - prompt-v5 at the edge of the ambiguous threshold (45/50, measured 2026-11-24);
  - `noindex` undecided;
  - SPF/DKIM/DMARC unchecked.
- **Next:** the owner's open steps in the [readiness record](../../release/public-demo-readiness.md).

## 9. Publication angle
„Opening a demo is a switch, not a migration”: the environment login and public replies became reversible repository variables, checked by the deployment's smoke tests in both positions. Also: writing the privacy policy from the code found a retention rule that had never been implemented.
