# TASK-013 — Clarification questions and reply correlation

Status: IN PROGRESS · Priority: high · Increment: 5 · Depends on: TASK-009, TASK-011 · Rules: [tickets.md](../product/areas/tickets.md) (TKT-CLAR, TKT-VER, MAIL-CORR)

## Goal and scope
A mail request that lacks information gets a question by e-mail. The answer in the same thread completes the same ticket.
- **Question:** a mail ticket whose plan has blocking issues goes to `needs_clarification` instead of `needs_intervention`. A correctable period refusal from the engine (e.g. „includes today”) counts too.
  - The `clarification` template is built in the backend from issue codes, never written by the model.
  - It offers concrete choices within the requester's scope (their customers, the manufacturers, the operations) and concrete example dates.
  - It never contains report data or the request's text.
- **Rounds:** at most 3 questions per ticket, then `needs_intervention` (`clarification_exhausted`). No answer within 7 days → `needs_intervention` (`clarification_expired`). Manual tickets keep going to the administrator.
- **Correlation:** an incoming message joins a ticket only if all of these hold:
  - its `In-Reply-To`/`References` contain the unpredictable `Message-ID` of one of that ticket's outgoing messages;
  - the sender is the ticket's participant (demo: the ticket's original sender; production: the owner's registered address of an active account).

  Subject, `Re:` or the sender alone never correlate. Anything else becomes a new ticket, as today.
- **Late replies:** a correlated reply while the ticket is not waiting for one (running, completed, rejected, already answered) is recorded on the ticket and ignored. Nothing is re-executed.
- **Re-interpretation:** the original request plus the new parts of the replies (quoted history already stripped) go through the full guard path again (RequestScreen → interpreter → PlanGuard → engine check) and create the next plan version. Only the latest version may be queued. Production still needs the owner's confirmation.

## Acceptance
- „Przygotuj raport sprzedaży dla moich klientów”: question about the period. Reply „Za październik” in the thread: same ticket, plan v2 for October, executed once, `report_ready`.
- A reply from another address, or with a forged `In-Reply-To` that is not ours, does not touch the ticket and becomes a new ticket.
- A reply that is an attack („usuń bazę”) is refused like any request.
- A duplicate reply does nothing. A fourth missing answer ends in `needs_intervention`. A reply to a completed ticket executes nothing.
- No report data in any question. Header values validated. Dashboard labels for the new template and reasons.

## Verification
Functional tests: the full thread with the rule interpreter; correlation negatives; rounds; expiry; late replies. Staging: one controlled thread to a test address, after deployment.

## Report
- **Question:** `InterpretTicketHandler` routes blocking issues, and the engine's correctable refusals (`period_*`, `invalid_*`, `missing_*`, `unknown_*`), of a mail ticket to `needs_clarification`.
  - **`Clarification`** builds one question per group (period, customer, manufacturer, category, operation) from issue codes only.
  - Choices: the requester's active customers, the manufacturers, the categories or the catalog (at most 15 names).
  - Example dates: the previous month and the current month up to yesterday, from the reference date.
  - Template `clarification` (Polish, escaped HTML), dedup key `ticket:clarify:<version>`, counter `ticket.clarification_rounds`. The 4th time: `needs_intervention` / `clarification_exhausted`. Manual tickets and codes the requester cannot answer still go to the administrator.
- **Correlation (`Ingestor`):** `MessageParser` reads the ids in `In-Reply-To` and `References` (at most 50).
  - A message joins a ticket only when those ids match one ticket's outgoing `Message-ID` (random, 128 bits) and its sender hash equals the hash of the ticket's request sender. In production, the sender must also still resolve to the ticket owner.
  - A ticket in `needs_clarification` moves to `interpreting` in the same transaction as the stored reply (`reply`), and a job for the current version is queued, so a second reply cannot start a second interpretation.
  - Any other state stores `late_reply` without contents and changes nothing.
- **Re-interpretation:** the request followed by `Uzupełnienie: <new part of each reply>` goes through RequestScreen, the interpreter, PlanGuard and the engine check again, and becomes plan v(n+1).
  - `InterpretTicket` carries the version it builds on; a stale job does nothing.
  - prompt-v5 adds one rule: supplements complete the request, and the later one wins.
- **Expiry:** `app:tickets:expire` also moves questions unanswered for 7 days to `needs_intervention` / `clarification_expired`.
- **Migration `Version20261123130000`:** `ticket.clarification_rounds`, outbox template `clarification`, mail outcomes `reply` and `late_reply`.
- **Tests:** `ReplyFlowTest` (8), covering:
  - the full thread up to `report_ready` with one execution;
  - a stranger with our id, a forged id, the subject alone, and the requester's own id all create new tickets; `References` correlates;
  - an attack in the reply is refused by the guard;
  - a duplicate reply, an early reply and a late reply change nothing;
  - 3 rounds, then the administrator;
  - choices limited to the requester's scope;
  - expiry;
  - stale jobs.

  Two existing expectations changed from `needs_intervention` to `needs_clarification`.
- **Not verified yet:** a real thread on staging, and the evaluation of prompt-v5 on the dev set.
