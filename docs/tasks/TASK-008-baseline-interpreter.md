# TASK-008 — Rule-based interpreter (baseline) closing the mail loop without an LLM

Status: DONE (PR pending CI) · Priority: high · Increment: 3 · Depends on: TASK-006

## Goal and scope
A deterministic interpreter that turns the text of simple requests into the typed plan (TKT-PLAN) behind the same `Interpreter` interface the OpenAI one will use in increment 4. It closes the e-mail → report loop now, and it is the evaluation baseline the brief requires (§10).
- Recognizes the three operations, the closed period expressions (Polish month names, „ostatni miesiąc”, „Q3”, explicit dates…), manufacturer names and aliases from the profile, and customer references within scope.
- Anything else becomes issues (`missing_period`, `unknown_operation`, `multiple_operations`, `out_of_catalog`…).
- Without clarification mail (increment 5), a ticket with issues goes to `needs_intervention`, where the admin sets the parameters manually (reuses TASK-005). A complete plan is validated by `ReportEngine::check()` and queued.
- Plan author `system`, version 1.

## Acceptance
- The examples table in `docs/product/areas/tickets.md`: the ordinary rows give the expected plan, and the rest give the expected issue codes.
- Hostile texts never produce a recipient, SQL, a path or a scope from the text: „usuń bazę”, „raport płacowy”, „wyślij na inny adres”, and an instruction to ignore the rules.

## Verification
Table-driven unit tests (development set only; the frozen evaluation set is created in increment 4 and not used for tuning).

## Report
- Module `api/src/Module/Interpretation`:
  - the `Interpreter` interface, `InterpretedPlan`, `InterpretationContext`;
  - `RuleInterpreter` (`baseline:rules-v1`): keyword rules over Polish text folded to ASCII. It recognizes:
    - the operations;
    - the closed period expressions: month name in three cases with an optional year, quarter (Q3, III, „trzeci”), the last N days, yesterday, previous week/month/year, this month, year to date, a year, and explicit ranges (ISO, dotted, „od 1 do 15 listopada”);
    - the owner's customers by code, full name, first two words or a distinctive single word (a word shared by two customers is ambiguous);
    - manufacturers by name or alias, and categories by stem.
  - When unsure it returns issue codes, never guesses. Out-of-catalog keywords (payroll, HR, deleting or changing data) mark the request.
  - A recipient in the text is only an issue (`recipient_change_requested`); it never changes the recipient, the scope or the filters.
- `Job/InterpretTicket` is queued by ingestion in the ticket's transaction. `InterpretTicketHandler` claims `received` (or `interpreting` without a plan after a crash), then reads the subject and the new text, builds context from the owner's scope, and stores plan v1 (author `system`, ref `baseline:rules-v1`). Routes:
  - out of catalog → `rejected`;
  - blocking issues → `needs_intervention` (`clarification_needed`; clarification mail comes in increment 5);
  - the plan is validated like any input: a customer outside the scope → `rejected` (`out_of_scope`), other refusals → `needs_intervention`;
  - a valid plan goes to `awaiting_confirmation` in production and to `queued` in demo;
  - context or text unavailable → `needs_intervention` (`interpretation_unavailable`). The admin can set the parameters (TASK-007).
- Evidence:
  - Development set: 44 table-driven cases in `RuleInterpreterTest`. It covers the `tickets.md` examples, every period form, customers, manufacturers and categories, the discount simulation, and hostile texts (injection, payroll, deletion, price change, recipient change). It is used to build the rules; the frozen evaluation set of increment 4 stays separate.
  - `InterpretationFlowTest`:
    - an e-mail produces the expected October CSV byte-for-byte (CP-001 by e-mail, without the notification);
    - every route: payroll, missing period, outside scope, period including today, recipient ignored;
    - production waits for confirmation; duplicate jobs are harmless; context or text unavailable; a ticket rejected before interpretation stays rejected.
  - API: 270 tests, PHPStan max.
  - Local stack: an injected „Sprzedaż za październik” was completed with a file; „raport płacowy” was rejected as out of catalog.
- Found during the task:
  - The Doctrine transport on PostgreSQL waits for `check_delayed_interval` after an empty read, so tests looping the worker within milliseconds saw an empty queue. The test environment uses `check_delayed_interval=0`.
  - `get()` returns one message at a time, so test helpers drain in a loop.
- Limitations:
  - Rules are tuned on the development set only; the quality measurement against a frozen set comes in increment 4.
  - No clarification e-mail: tickets with issues wait for the administrator.
