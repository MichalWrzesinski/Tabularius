# TASK-008 — Rule-based interpreter (baseline) closing the mail loop without an LLM

Status: PLANNED · Priority: high · Increment: 3 · Depends on: TASK-006

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
