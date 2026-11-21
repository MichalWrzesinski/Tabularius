# Progress

As of: 2026-11-21 Europe/Warsaw | scope: first release (public demo on synthetic data)
Stage: discovery → prototype | increment: 1 (discovery, threat model, prototype)
Tasks: none yet — increments below are goals, not `READY` tasks
Release: no code, no CI, no hosting | production: not planned before separate approval
Metrics: agent usage not measured (no tool export configured); application LLM cost: none incurred

| # | Increment (brief §11) | State | Depends on |
|---|---|---|---|
| 1 | Discovery: vocabulary, financial and access rules, threat model, prototype | discovery drafted 2026-11-21; prototype next | — |
| 2 | Deterministic report engine on seeded demo business system, API, login, dashboard | planned | 1, technical decisions, setup |
| 3 | Mail ingestion, secure identity, durable tickets | planned | 2; mailbox details for the real adapter |
| 4 | LLM interpretation, evaluation, attack tests | planned | 3; OpenAI key and evaluation budget for real runs |
| 5 | Clarifications, reply correlation, controlled notifications | planned | 4 |
| 6 | File delivery, failure scenarios, staging, release readiness | planned | 5; hosting decision and cost |

Open owner questions: [case-study stage 01](case-study/stages/01-discovery.md#open-questions-for-the-owner).
