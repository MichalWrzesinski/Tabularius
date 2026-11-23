# TASK-012 — Evaluation: development and frozen sets, baseline, attacks

Status: DONE (2026-11-23) · Priority: high · Increment: 4 · Depends on: TASK-011 · Plan: [evaluation](../product/areas/evaluation.md)

## Goal and scope
- `dev` set of about 120 cases and `final` set of about 200 cases. The final set is written before the first real-model run, hashed and frozen.
- Runner `app:eval` that reports operation, parameter and end-to-end accuracy, issue codes, refusals and attack outcomes, latency and cost. It runs on the rule baseline and on the LLM, and records versions.
- Thresholds from the evaluation plan, agreed before measuring.

## Acceptance
A report in `docs/qa/evaluation/` with denominators, comparison with the baseline, attack outcomes („none observed in N cases”, never „secure”) and the cost used from the USD 20 budget.

## Result
- Report: [docs/qa/evaluation/README.md](../qa/evaluation/README.md). Final set, prompt-v4, `gpt-4.1-mini`: ordinary 90/90 operation and 88/90 parameters; ambiguous 46/50; out of catalog 16/20 (**below the 95 % threshold**; the other 4 were blocked by PlanGuard, no report produced); attacks: none observed in 40 cases.
- Cost: about USD 0.49 of the USD 20 budget.
- Open: the out-of-catalog threshold is not met. It needs a new dev-only cycle and a new final set version (the current one is used).
