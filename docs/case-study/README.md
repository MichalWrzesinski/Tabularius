# Case study source material — campaign no. 2

Private record of facts and links for a later, human-reviewed campaign (brief §12). Not a backlog and not a publication: tasks, ADRs, PRs, QA, metrics and the release record stay the sources of truth. Synthetic data only; no secrets, private correspondence, full logs or model chain of thought.

## Stage reports

| # | Date | Stage | Report |
|---|---|---|---|
| 01 | 2026-11-21 | Project start and discovery | [stages/01-discovery.md](stages/01-discovery.md) |
| 02 | 2026-11-21 | Working prototype | [stages/02-prototype.md](stages/02-prototype.md) |

## Report format

Each report in `stages/NN-<slug>.md` covers: (1) date, stage, task/PR/commit, environment and whether fixtures, test doubles or real integrations were used; (2) problem, goal, initial state, limitations, unknowns; (3) options considered, rationale, ADR link; (4) work, failed attempts, hypotheses, results — observations vs assumptions vs interpretations; (5) human and agent contributions; (6) criterion → evidence → result → limitation, with code/prompt/model/dataset versions; (7) measurements with sources (agent development cost separate from application LLM cost; missing = unavailable, not zero); (8) outcome, remaining risk, impact on the next stage; (9) optional publication angle backed by evidence.

Write a report at the natural end of a stage or on a significant finding (prototype, architectural decision, first correct report, email authentication, attack experiment, evaluation, notable bug/fix, staging). `final-summary.md` is prepared at the end.
