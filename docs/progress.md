# Progress

As of: 2026-11-21 Europe/Warsaw | scope: first release (public demo on synthetic data)
Stage: technical decisions and setup | increment: 1 done (prototype accepted 2026-11-21)
Tasks (increment 2): 2 DONE / 5 | TASK-001 (PR #1) and TASK-002 (PR #15) DONE, TASK-003 in review, TASK-004–005 PLANNED — [docs/tasks/](tasks/)
Release: private repo `MichalWrzesinski/Tabularius` (pushed 2026-11-21), no CI yet, no hosting | production: not planned before separate approval
Metrics: agent usage not measured (no tool export configured); application LLM cost: none incurred

| # | Increment (brief §11) | State | Depends on |
|---|---|---|---|
| 1 | Discovery: vocabulary, financial and access rules, threat model, prototype | discovery drafted, owner decisions recorded; prototype v1 rejected (did not match mockups); v2 on mockup sources accepted 2026-11-21 | — |
| 2 | Deterministic report engine on seeded demo business system, API, login, dashboard | tasks planned (TASK-001–005); technical decisions recorded 2026-11-21 | 1 |
| 3 | Mail ingestion, secure identity, durable tickets | planned | 2; mailbox details for the real adapter |
| 4 | LLM interpretation, evaluation, attack tests | planned | 3; OpenAI key and evaluation budget for real runs |
| 5 | Clarifications, reply correlation, controlled notifications | planned | 4 |
| 6 | File delivery, failure scenarios, staging, release readiness | planned | 5; hosting decision and cost |

Open owner questions: [case-study stage 01](case-study/stages/01-discovery.md#open-questions-for-the-owner).
