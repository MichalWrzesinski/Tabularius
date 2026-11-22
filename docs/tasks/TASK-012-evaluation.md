# TASK-012 — Evaluation: development and frozen sets, baseline, attacks

Status: IN PROGRESS · Priority: high · Increment: 4 · Depends on: TASK-011 · Plan: [evaluation](../product/areas/evaluation.md)

## Goal and scope
- `dev` set of about 120 cases and `final` set of about 200 cases. The final set is written before the first real-model run, hashed and frozen.
- Runner `app:eval` that reports operation, parameter and end-to-end accuracy, issue codes, refusals and attack outcomes, latency and cost. It runs on the rule baseline and on the LLM, and records versions.
- Thresholds from the evaluation plan, agreed before measuring.

## Acceptance
A report in `docs/qa/evaluation/` with denominators, comparison with the baseline, attack outcomes („none observed in N cases”, never „secure”) and the cost used from the USD 20 budget.
