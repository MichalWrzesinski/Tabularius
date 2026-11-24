# 08 — Clarification questions and reply correlation (increment 5)

## 1. Context
- Date: 2026-11-23. Increment 5, [TASK-013](../../tasks/TASK-013-clarification-and-replies.md).
  - PR 40 (code, merge `907f199`), PR 41 (prompt-v5 dev run), PR 42 (staging verification).
- Environment:
  - **Tests:** functional tests with the rule interpreter and a recording mailer.
  - **Staging:** at the time still behind its environment login. It had the real mailbox, real SMTP (Brevo) to allowlisted test addresses, and the real model `gpt-4.1-mini` with prompt-v5.

## 2. Goal and initial state
- **Goal:** a mail request that lacks information gets a question by e-mail. The answer in the same thread completes the same ticket and produces the report once.
- **Before:** a missing period or customer sent the ticket to the administrator (`needs_intervention`), so the requester was never asked.
- **Constraints carried over:**
  - **Outgoing text (stage 07):** the model never writes text that leaves the system.
  - **Correlation (MAIL-CORR):** headers alone never authorize anything.

## 3. Options and decisions
| Question | Options | Decision |
|---|---|---|
| Who writes the question | the model; backend templates | Backend templates from issue codes. The model only picks codes. Choices (the requester's customers, manufacturers, example dates) come from trusted context |
| How a reply joins a ticket | subject `Re:`; sender; our `Message-ID` | Only our unpredictable `Message-ID` (128 bits) in `In-Reply-To`/`References`, **and** the sender equal to the ticket's original sender (in production, still the owner's registered address) |
| How the answer is interpreted | patch the old plan; interpret the whole thread again | The whole thread again (request plus „Uzupełnienie: …” lines), through RequestScreen, the interpreter, PlanGuard and the engine check, as plan v(n+1) |
| Limits | unlimited; rounds and time | 3 rounds, 7 days, then the administrator |

## 4. Work, findings, failures
- **Correlation and states (code):** `MessageParser` reads reply ids; `Ingestor` correlates. The ticket moves to `interpreting` in the same transaction that stores the reply, so a second reply cannot start a second interpretation.
- **Template and limits (code):** `Clarification` builds the questions; template `clarification`; round counter; expiry in the daily job.
- **prompt-v5:** one added rule for supplements.
  - **Dev result:** 118/120, against 120/120 for v4, with 0 attack effects. Both misses ended safely: an unnecessary question in one, a PlanGuard block in the other.
  - **Not on the final set:** v5 was not measured there, so the final numbers of stage 07 describe v4, while staging runs v5.
- **Failure during the work:** an expiry test used `now() - 8 days`, which conflicted with the frozen test clock. Fixed with a fixed date.
- **Observation on staging (T-1008, 2026-11-23):** the owner's request without a period got one period question, with correct example dates and without the request's text. The reply „Za październik” in the thread produced the report link for the same ticket.
- **Observation:** the question reached the owner's mailbox before the „received” acknowledgement.
  - **Cause (found on 2026-11-24):** ingestion queued the interpretation job before the acknowledgement, and the queue is FIFO. The acknowledgement therefore waited for the model call, and the question was sent right after it.
  - **Fix:** the acknowledgement is now queued first, with a test of the queue order that failed before the fix.

## 5. Human and agent contributions
- **Owner:**
  - accepted the evaluation result and asked to move on („Akceptujemy i idziemy dalej”);
  - sent the real test thread;
  - accepted the delivered report as sufficient, without a database check.
- **Agent:** specification, code, tests, prompt-v5, deployment through CI, documentation.

## 6. Criteria → evidence → result
| Criterion | Evidence | Result | Limitation |
|---|---|---|---|
| Question → reply → same ticket, plan v2, one file, `report_ready` | `ReplyFlowTest` (8 tests); staging thread T-1008 | met | the ticket state on staging was not inspected in the database |
| Stranger, forged id or subject alone → new ticket | `ReplyFlowTest` | met | — |
| Attack inside a reply is refused | `ReplyFlowTest` (guard path) | met | the rule interpreter in tests; real model on staging not attacked through a reply |
| Duplicate or late reply executes nothing | `ReplyFlowTest` | met | — |
| 4th missing answer → administrator; 7 days → administrator | `ReplyFlowTest` | met | — |
| No report data or request text in questions | template tests, staging mail | met | — |

## 7. Measurements
- **Application LLM cost:** the prompt-v5 dev run cost USD 0.0932. The evaluation budget total is about USD 0.59 of 20.
- **Staging thread:** the cost was not measured separately (two model calls).
- **Agent development cost:** unavailable.

## 8. Outcome and next stage
- **Done:** the clarification loop works end to end on real mail.
- **Remaining risk:**
  - **Evaluation gap:** v5 is unmeasured on a final set. The dev set has no reply cases.
  - **Acknowledgement order:** explained and fixed on 2026-11-24, not yet observed in a real thread.
- **Next:** increment 6 (failure scenarios, operations, launch).

## 9. Publication angle
„The model picks the question, the code writes it.” Clarification by e-mail where the model only chooses issue codes, and a reply joins a ticket only by an id nobody can guess.
