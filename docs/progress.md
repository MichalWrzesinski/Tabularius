# Progress

As of: 2026-11-22 Europe/Warsaw | scope: first release (public demo on synthetic data)
Stage: technical decisions and setup | increment: 1 done (prototype accepted 2026-11-21)
Tasks (increment 2): 5 DONE / 5 | TASK-001 (#1), TASK-002 (#15), TASK-003 (#16), TASK-004 (#17) merged; TASK-005 (#18) merged — [docs/tasks/](tasks/)
Release: private repo `MichalWrzesinski/Tabularius`, CI on every PR; **staging live** at https://tabularius.gitpush.app since 2026-11-22 (private: environment login; deployed by CI as `tabularius_deploy`) | production: not planned before separate approval
Metrics: agent usage not measured (no tool export configured); application LLM cost: none incurred

| # | Increment (brief §11) | State | Depends on |
|---|---|---|---|
| 1 | Discovery: vocabulary, financial and access rules, threat model, prototype | discovery drafted, owner decisions recorded; prototype v1 rejected (did not match mockups); v2 on mockup sources accepted 2026-11-21 | — |
| 2 | Deterministic report engine on seeded demo business system, API, login, dashboard | done: TASK-001–005, first full loop without mail and LLM (2026-11-22) | 1 |
| 3 | Mail ingestion, secure identity, durable tickets | in progress: TASK-006 (#24) and TASK-007 done; TASK-008–009 planned | 2; mailbox details for the real adapter |
| 4 | LLM interpretation, evaluation, attack tests | planned | 3; OpenAI key and evaluation budget for real runs |
| 5 | Clarifications, reply correlation, controlled notifications | planned | 4 |
| 6 | File delivery, failure scenarios, release readiness | planned | 5 |
| — | First staging deployment (owner's VPS, decided 2026-11-22) | done 2026-11-22: first deploy of d650e3d by CI; Basic Auth until the public demo is approved | VPS access; Brevo SMTP secrets |

Open owner questions: [case-study stage 01](case-study/stages/01-discovery.md#open-questions-for-the-owner).
