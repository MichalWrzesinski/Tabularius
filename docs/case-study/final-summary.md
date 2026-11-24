# Final summary — Tabularius, first release (public demo)

Private source material for campaign no. 2 (brief §12), for review by the owner before any use. Status as of 2026-11-24, commit `5975131`. Facts come from the [stage reports](README.md#stage-reports), tasks, PRs and the [readiness record](../release/public-demo-readiness.md); where they disagree, those sources win.

## What exists
- **The application:** a mail request becomes a ticket. The LLM turns it into a closed plan, and a guard checks that plan against the business concept. The deterministic engine then produces a CSV report on synthetic data, and the reply links to the file behind a login.
- **Stack and checks:** PHP/Symfony API, a separate read-only business system, PostgreSQL, Vue 3 dashboard. CI and CD run on GitHub Actions.
- **Public demo:** https://tabularius.gitpush.app since 2026-11-24, on the owner's shared VPS. It uses a real mailbox, Brevo for outgoing mail and OpenAI `gpt-4.1-mini`.
- **Status, reported separately:**
  - **Implementation:** increments 1–6 are built.
  - **Integration:** real mail, model, Sentry and Better Stack work.
  - **Production:** no production release for a real organisation; that is a separate stage (brief §13).

## Chronology
| Date | Stage | What happened | Report |
|---|---|---|---|
| 2026-11-21 | Discovery | Brief, mockups and standards in; vocabulary, rules, threat model; owner accepted Q1–Q7 | [01](stages/01-discovery.md) |
| 2026-11-21 | Prototype | v1 rejected by the owner (did not match the mockups); v2 built on the mockup sources accepted | [02](stages/02-prototype.md) |
| 2026-11-21 | Technical decisions | ADR 0001–0004; private GitHub repository | [03](stages/03-technical-decisions.md) |
| 2026-11-22 | First correct report | Seeded business system and engine; results equal to independently computed answers, no LLM | [04](stages/04-first-correct-report.md) |
| 2026-11-22 | First full loop | Accounts, manual ticket → worker → authorized download; first staging deployment | [05](stages/05-first-full-loop.md) |
| 2026-11-22 | Mail | Ingestion, identity, rule interpreter, outbox; real IMAP and SMTP on staging | [06](stages/06-mail-identity-notifications.md) |
| 2026-11-22 – 23 | LLM and guard | Business-scope guard (ADR 0005), OpenAI interpreter, dev/final evaluation | [07](stages/07-llm-guard-evaluation.md) |
| 2026-11-23 | Clarification | Questions by e-mail, reply correlation; real thread T-1008 on staging | [08](stages/08-clarification-and-replies.md) |
| 2026-11-23 – 24 | Failures, operations, launch | Failure handling, error reporting with scrubbing, demo opened, legal drafts | [09](stages/09-failures-operations-launch.md) |

## Key decisions
| Decision | Why | Source |
|---|---|---|
| Modular monolith with durable jobs in PostgreSQL | One deployable, restarts and retries without a broker | [ADR 0001](../decisions/0001-modular-monolith-symfony-messenger.md) |
| Business data behind a separate read-only API | The report engine can never write company data | [ADR 0002](../decisions/0002-separate-business-system-behind-read-only-api.md) |
| Server sessions in PostgreSQL | Blocking an account ends its sessions at once | [ADR 0003](../decisions/0003-server-sessions-in-postgresql.md) |
| The LLM only fills a closed plan; code resolves dates and runs reports | No model-written SQL, files or outgoing text | [ADR 0004](../decisions/0004-llm-interprets-into-closed-plan-only.md) |
| Guard on both sides of the model: request screen, schema enums from the requester's scope, plan guard | The owner's top-priority requirement: requests stay within the business concept | [ADR 0005](../decisions/0005-business-scope-guard.md) |
| Clarification text from templates; replies correlate only by our unpredictable Message-ID and the original sender | Neither the model nor headers alone can steer outgoing mail or tickets | [TASK-013](../tasks/TASK-013-clarification-and-replies.md) |
| Demo on the current staging; backups and monitoring as in Profilo; launch switches as repository variables | Owner's choice on cost and simplicity, with reversible switches | [TASK-015](../tasks/TASK-015-monitoring-and-operations.md), [readiness](../release/public-demo-readiness.md) |

## Test and evaluation results
- **Tests at `5975131`:** API 430 tests (96.06 % lines), business system 49 (97.95 %), client 53. PHPStan at max level, php-cs-fixer, ESLint/Prettier, vue-tsc, gitleaks and `composer`/`npm` audits run in CI on every PR.
- **Critical paths ([plan](../qa/critical-paths.md)):**
  - **CP-001:** by e-mail, byte-for-byte equal to the fixture.
  - **CP-002:** question → reply → one file, including a real thread on staging.
  - **CP-003:** foreign files 404, revoked rights detected, spoofed sender does not execute.
  - **CP-004:** account lifecycle.
  - **CP-005:** failures kept for manual handling.
- **Evaluation of the interpreter** ([details](../qa/evaluation/README.md)): final set, prompt-v4, run once on a frozen set.

  | Category | Threshold | Result |
  |---|---|---|
  | Ordinary operation | ≥ 97 % | 90/90 |
  | Ordinary parameters | ≥ 93 % | 88/90 |
  | Ambiguous | ≥ 90 % | 46/50 |
  | Out-of-catalog refusal | ≥ 95 % | **16/20, not met** (the 4 others were blocked by the plan guard; nothing was produced) |
  | Attacks | 0 effects | 0 effects in 40 cases |

  - **Acceptance:** the owner accepted this result on 2026-11-23.
  - **Version gap:** the demo runs prompt-v5 (one added rule for replies), measured only on the dev set (118/120).
  - **Baseline:** the rule-based interpreter was weaker in every category.

## Human interventions (owner)
- **Inputs:** the brief, the mockups, the standards, the stack, and the product name.
- **Corrections:**
  - **Prototype v1 rejected** because it did not match the mockups. Since then the mockup HTML/CSS is the implementation basis.
  - **LLM requirement:** the LLM, not rules, must match mail text to data. The rule interpreter stayed only as the baseline.
- **The security requirement:** requests from sales representatives or demo testers must stay within the business concept (no deleting data, revealing passwords, or generating files or code), with strong tests.
- **Approvals and decisions:**
  - discovery Q1–Q7 and the retention periods (2026-11-21);
  - the hosting, the secrets (set directly in GitHub, never through the chat) and the evaluation budget;
  - the evaluation thresholds and their result (2026-11-23);
  - the demo environment, backups, monitoring, and legal drafts (2026-11-24).
- **Launch:** opening the demo and replies to every sender on 2026-11-24, before the legal pages were final. The agent had recommended finishing them first.
- **Real-world checks:** the first staging e-mail (it revealed inflected manufacturer names, fixed in TASK-008), and the clarification thread T-1008.

## Measurements
- **Application LLM cost:** about USD 0.59 of the USD 20 evaluation budget (dev runs v1–v5, the final run, staging checks). Demo traffic since the launch is recorded per call in `ai_usage` on the server and is not included.
- **Latency of the final run:** p50 1133 ms, p95 1695 ms.
- **Agent-assisted development cost (time, tokens):** unavailable; no export was configured. Not inferred from dates.
- **Elapsed calendar time:** 2026-11-21 to 2026-11-24. This is not a measure of active work.

## Limitations and open risks
- **Legal pages:** drafts with placeholders, not reviewed. The demo is public meanwhile.
- **Monitoring:** Sentry and Better Stack were connected on 2026-11-24 (test events from the API and the dashboard arrived). Not confirmed: the Sentry error-count alert and a Better Stack test alert.
- **Backups:** no off-server copy and no restoration test (accepted).
- **Interpreter quality:**
  - out-of-catalog requests that resemble catalog operations end in manual handling, not a refusal;
  - prompt-v5 is unmeasured on a final set.
- **Not observed for real:** a bounce, and the resolve buttons in a browser.
- **Unchecked:** SPF, DKIM and DMARC. Indexing was allowed on 2026-11-24.
- **Not explained:** the order of the acknowledgement and the question in the T-1008 thread.
- **Scope of the evidence:** synthetic data only. Nothing here verifies a deployment for a real organisation (brief §13).

## Repository clean-up
- **Removed on 2026-11-24:**
  - `scripts/__pycache__/`, a compiled file (`__pycache__/` is now ignored);
  - `client/scripts/prototype-screens.mjs`, the prototype walkthrough, obsolete since TASK-005. Its screenshots stay as the record of the accepted prototype.
- **Proposed, needs the owner's approval:**
  - after the brief's findings are confirmed in the product documents, remove `docs/input/brief.md` and the mockups;
  - remove the `DONE` task files once a release covers them, as the artifact-lifecycle rule says.

  Both are linked from this summary and the stage reports. Git history keeps them.

## Evidence index
- **Product and rules:**
  - [overview](../product/overview.md);
  - [tickets](../product/areas/tickets.md), [reports](../product/areas/reports.md), [identity and access](../product/areas/identity-access.md), [threat model](../product/areas/threat-model.md), [evaluation](../product/areas/evaluation.md).
- **Architecture:** [architecture](../architecture.md), [technical profile](../technical-profile.md), [ADRs](../decisions/).
- **Tasks:** [TASK-001 … TASK-016](../tasks/).
- **QA:** [critical paths](../qa/critical-paths.md), [evaluation reports](../qa/evaluation/), [CSV samples](../qa/csv/).
- **Operations:** [runbook](../runbook.md), [readiness record](../release/public-demo-readiness.md), [progress](../progress.md).
- **Design:** [mockups](../design/mockups/), [prototype record](../design/prototype/).
- **Pull requests:** GitHub `MichalWrzesinski/Tabularius`, PRs 1–47.

## Campaign topics grounded in the material
Each topic is an angle with evidence, not a finished post. Choosing and writing them is the owner's call.
1. **„A model that never sees a customer it may not report on”:** enums from the requester's scope, and a guard on both sides of the model. Evidence: ADR 0005, `AbuseFlowTest`, 0 attack effects in 40 final cases. Report the failed out-of-catalog threshold as it is.
2. **„Don't let the model do the calendar”:** the model returns a period expression, and code resolves dates. Evidence: stage 07, the period rules, the final-set parameter accuracy of 88/90.
3. **„Freeze the test set before you look”:** a dev/final split, the final run once at USD 0.16, and a threshold missed and accepted openly. Evidence: the evaluation README.
4. **„The model picks the question, the code writes it”:** clarification by e-mail with replies correlated by an unguessable id. Evidence: stage 08, T-1008.
5. **„A rejected prototype”:** why the mockup sources became the implementation basis after v1 was rejected. Evidence: stage 02, progress.
6. **„Writing the privacy policy from the code found a missing retention rule”:** evidence: stage 09, TASK-016.
7. **„Opening a demo is a switch”:** reversible launch variables checked by smoke tests in both positions. Evidence: PRs 45–46.
