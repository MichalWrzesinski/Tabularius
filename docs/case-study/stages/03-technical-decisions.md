# 03 — Technical decisions and repository

## 1. Context
- Date: 2026-11-21. Stage: technical decisions and setup start. Commits: `28f42a0` (decisions); branch `docs/increment-2-plan` (plan). Private GitHub repository `MichalWrzesinski/Tabularius` created and pushed the same day.
- No code or integrations yet. Versions were checked against Packagist and Docker Hub on 2026-11-21.

## 2. Goal and initial state
Goal: make the decisions needed for increment 2 (deterministic reports without an LLM): stack versions, module boundaries, how business data is separated, auth, the LLM boundary, the data model and roles. Initial state: accepted prototype; stack fixed by the owner (PHP/Symfony, PostgreSQL, Vue 3 + TS).

## 3. Options and decisions
| Question | Options | Decision | Source |
|---|---|---|---|
| Symfony line | 8.1 (latest, short support) vs 7.4 LTS | 7.4 LTS, because the coding standard prefers LTS when no newer feature is needed | technical profile |
| PHP | 8.6.0 (released ~2026-11-19) vs 8.5 | 8.5, because 8.6.0 is under the 14-day age rule | technical profile |
| Queue and locks | Valkey vs PostgreSQL (Messenger Doctrine transport) | PostgreSQL, so job state and business state commit atomically and there is one store | ADR 0001 |
| Business data access | direct read-only DB connection vs separate app with read-only API | separate app with API and view-only DB role, which matches the „customer API adapter” model | ADR 0002 |
| Auth | JWT vs server sessions | sessions in PostgreSQL, because blocking must take effect immediately | ADR 0003 |
| LLM boundary | tools/function calling vs closed plan schema | closed plan schema, no tools, backend-resolved periods | ADR 0004 |

## 4. Work and findings
- Observation: Packagist's `p2` metadata is minified; the first version check script failed on missing `time` fields and was redone with the full package endpoint.
- Observation: the owner's other projects use PostgreSQL 18. Version 18 was chosen for shared host tooling, although 19.0 exists. This is an assumption about future co-hosting, to be revisited at the hosting decision.

## 5. Contributions
- Owner: accepted the prototype („Accepted”). Earlier approvals cover the private repository (Q7) and the stack.
- Agent: all technical choices above, documents, repository creation and push.

## 6. Criteria and evidence
- Increment 2 has five tasks with criteria ([docs/tasks/](../../tasks/)) and a critical-path plan ([docs/qa/critical-paths.md](../../qa/critical-paths.md)). Feasibility is unproven until TASK-001 runs.

## 7. Measurements
Agent time, tokens and cost: unavailable. Application LLM cost: none.

## 8. Outcome and next stage
Next: TASK-001, the runnable foundation and CI. Open risk: hosting (Q3) and mailbox details (Q2) remain undecided. They affect increment 3 and staging, not increment 2.
