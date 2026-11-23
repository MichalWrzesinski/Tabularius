# Progress

As of: 2026-11-23 Europe/Warsaw | scope: first release (public demo on synthetic data)
Stage: implementation | increments 1–4 done; increment 5 in progress
Tasks: TASK-001–012 DONE; TASK-013 (clarification and replies) in progress — [docs/tasks/](tasks/)
Release: private repo `MichalWrzesinski/Tabularius`, CI on every PR; **staging live** at https://tabularius.gitpush.app since 2026-11-22 (private: environment login; deployed by CI as `tabularius_deploy`) | production: not planned before separate approval
Metrics: agent usage not measured (no tool export configured); application LLM cost: about USD 0.59 of the USD 20 evaluation budget ([evaluation](qa/evaluation/README.md))

| # | Increment (brief §11) | State | Depends on |
|---|---|---|---|
| 1 | Discovery: vocabulary, financial and access rules, threat model, prototype | discovery drafted, owner decisions recorded; prototype v1 rejected (did not match mockups); v2 on mockup sources accepted 2026-11-21 | — |
| 2 | Deterministic report engine on seeded demo business system, API, login, dashboard | done: TASK-001–005, first full loop without mail and LLM (2026-11-22) | 1 |
| 3 | Mail ingestion, secure identity, durable tickets | done: TASK-006–009; real IMAP and SMTP (Brevo, allowlisted test addresses) verified on staging 2026-11-22 | 2; mailbox details for the real adapter |
| 4 | LLM interpretation, evaluation, attack tests | done: TASK-010 business-scope guard, TASK-011 OpenAI interpreter, TASK-012 evaluation (final set: 3 of 4 thresholds met, out-of-catalog 16/20 accepted by the owner 2026-11-23) | 3; OpenAI key and evaluation budget for real runs |
| 5 | Clarifications, reply correlation, controlled notifications | in progress: TASK-013 | 4 |
| 6 | File delivery, failure scenarios, release readiness | planned | 5 |
| — | First staging deployment (owner's VPS, decided 2026-11-22) | done 2026-11-22: first deploy of d650e3d by CI; Basic Auth until the public demo is approved | VPS access; Brevo SMTP secrets |

Open owner questions: [case-study stage 01](case-study/stages/01-discovery.md#open-questions-for-the-owner).
