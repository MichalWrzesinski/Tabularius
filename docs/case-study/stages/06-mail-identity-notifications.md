# 06 — Mail in and out: identity, a baseline interpreter, notifications (increment 3)

## 1. Context
- Date: 2026-11-22. Increment 3. Tasks:
  - TASK-006, mail ingestion (PR #24);
  - TASK-007, identity and confirmation (PR #25);
  - TASK-008, rule-based interpreter (PR #26);
  - TASK-009, outbox and notifications (PR from `feat/TASK-009-outbox-notifications`).
- Environment: the local stack (Mailpit for outgoing mail, `app:mail:inject` instead of a mailbox) and staging behind its environment login, deployed by CI as `tabularius_deploy`.
- Test doubles: synthetic `.eml` fixtures, a scripted IMAP server, a recording mailer. **No real mailbox or SMTP was used**: they wait for the owner's credentials. No LLM.

## 2. Goal and initial state
Close the loop „e-mail → report → e-mail with a link” without an LLM, safely: exactly once, without trusting the sender address, without revealing data in the public demo. Initial state: manual tickets only (stage 05).

## 3. Options and decisions
| Question | Options | Decision |
|---|---|---|
| IMAP client | ext-imap; a library; own minimal client | Own minimal client. ext-imap left PHP core in 8.4; the commands needed are few and fully testable against a scripted server |
| Polling state | `\Seen` flag; UID high-water mark | UID per UIDVALIDITY, mailbox opened read-only. The owner reading mail changes nothing |
| What is stored | raw message; header block + text | Header block + text. Attachments are counted and dropped at ingestion |
| Sender identity (production) | trust `From`; DMARC header; portal confirmation | Portal confirmation (owner decision ID-2). The address only selects a candidate |
| Demo views | mask in the client; rule in the API | Rule in the API: a synthetic alias, checked on every endpoint of the shared accounts |
| Closing the loop before the LLM | wait for increment 4; rule-based interpreter | Rules: they are also the baseline the brief requires for evaluation |
| Outgoing mail | send inline; transactional outbox | Outbox in the ticket's transaction. An interrupted send is `unknown`, never resent |
| Real mail before launch | send to anyone; allowlist + capture | Allowlist of test addresses; everything else captured until launch approval |

## 4. Work, findings, failures
- Observation: the first parser run classified almost every message as „unparseable”: a call to a method that does not exist in the MIME library. Caught by the first manual injection, before commit.
- Observation: `X-Auto-Response-Suppress` was first treated as marking an automatic message. Some Outlook/Exchange setups add it to ordinary mail, so real requests would have vanished. Removed during self-review before merge.
- Observation: the Doctrine transport on PostgreSQL waits `check_delayed_interval` after an empty read. In production this delayed the worker by up to 60 s (stage 05). In tests a second worker round within milliseconds saw an empty queue; the test environment sets the interval to 0.
- Observation: the development set caught a matching bug: „Alfa-Tech” also added „Alfa Motors” through the shared word „alfa”.
- Assumption: a message without a declared charset is ISO-8859-1, as MIME prescribes; clients that send UTF-8 without declaring it are not handled.
- The prototype mock layer and panel were removed once the last screen moved to the API (TASK-007).

## 5. Contributions
- Owner: „Tak, zacznij” for each task; previously the hosting setup and the deploy-user model (stage 05).
- Agent: design, implementation, tests, local and staging verification, the self-review fix above. No human corrections in this stage.

## 6. Criteria and evidence
| Criterion | Evidence | Result | Limitation |
|---|---|---|---|
| Duplicate ingestion, restarts | `MailIngestionTest` (UID, Message-ID, poller high-water mark, mid-batch failure) | one ticket | Real IMAP server not yet used |
| Auto-replies and bounces never answered | parser and ingestion tests | no ticket | Bounce ↔ outbox correlation not done |
| CP-003 spoofed sender | `MessageIdentityTest` (production services) | nothing runs before the real owner confirms; others 404 | No environment runs in production mode yet |
| ID-DEMO-3 | endpoint-wide test of the shared accounts | no address, subject or body | — |
| Baseline interpretation | development set, 44 cases | all pass | Tuned on this set; frozen evaluation in increment 4 |
| CP-001 by e-mail | `InterpretationFlowTest` (byte-for-byte file), `NotificationTest`, local Mailpit | report and `report_ready` in the request's thread | Real SMTP pending credentials |
| Duplicate or interrupted sends | `NotificationTest` | at most once; unknown never resent | — |

## 7. Measurements
Agent time, tokens and cost: unavailable. Application LLM cost: none (no LLM).

## 8. Outcome and next stage
The mail loop works end to end on test doubles and is deployed to staging, where real mail is off. Remaining before real mail: IMAP details and Brevo credentials as GitHub secrets, plus the owner's list of test addresses. Next: increment 4 (LLM interpretation and evaluation), which needs the OpenAI key and the agreed evaluation budget.

## 9. Possible publication angle
„The sender address is not an identity”: a spoofed `From` produces a ticket that waits, visibly and harmlessly, until the real person logs in and confirms. The test is a single scenario anyone can read.
