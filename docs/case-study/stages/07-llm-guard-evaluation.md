# 07 — LLM interpretation, the business-scope guard and the evaluation (increment 4)

## 1. Context
- Date: 2026-11-22 to 2026-11-23. Increment 4. Tasks:
  - TASK-010, business-scope guard;
  - TASK-011, OpenAI interpreter;
  - TASK-012, evaluation (PRs 36–38 for prompt-v2 to v4, then this report).
- Environment:
  - staging behind its environment login, deployed by CI as `tabularius_deploy`;
  - real IMAP mailbox and SMTP relay (allowlisted test addresses only);
  - real model `gpt-4.1-mini` through the Responses API.
- Evaluation runs: the GitHub Actions workflow on a `main` commit image, with synthetic sets only.

## 2. Goal and initial state
- Owner's requirement: the LLM matches the e-mail text to what is fetched from the database.
- Owner's top-priority safeguard: an instruction from a sales representative or a demo tester must stay within the business concept. No deleting data, revealing passwords, or generating files or code.
- Initial state: the rule interpreter from stage 06.

## 3. Options and decisions
| Question | Options | Decision |
|---|---|---|
| Where the guard sits | prompt only; model output check only; layers | Three layers (ADR 0005): RequestScreen before any interpreter, closed lists as schema enums, PlanGuard on the output. None trusts the model |
| What the model may return | free text; JSON; strict schema with enums from the DB | Strict schema; customer enum = only the requester's customers; no tools; `store:false` |
| Matching names | more rules; the LLM | The LLM (owner's decision). Code only adds data the model can use (synonyms, a mark on names with a shared first word) and deterministic issue codes |
| When to stop tuning | until final passes; dev only | Dev only. The final set was frozen before the first real-model run and run once |

## 4. Work, findings, failures
- Observation: prompt-v1 on dev missed bare shared names („Alfa”), „cennik” as catalog, „ostatni miesiąc”, and off-topic refusals.
- Observation: v2 fixed most of them. v3 added a mark on customers with a shared first word and reached 30/30 on ambiguous cases, but over-cautiously dropped a plainly named customer in 2 ordinary cases. v4 narrowed the caution.
- Observation (final set): requests that sound like a catalog operation but are not one („obroty w przyszłym kwartale”, „wyniki całej firmy za rok”) became sales reports with an invalid period. PlanGuard blocked them, so the safety layer worked, but the outcome was not a refusal.
- Interpretation: the dev set lacked forecast-like and company-wide wording. It was missing from the tuning data, not from the guard.
- Failed attempt: the first report extraction from the workflow log broke because the end marker followed the JSON on one line; the workflow now prints a newline.

## 5. Human and agent contributions
- **Owner:**
  - requirements for LLM matching and the business-scope guard;
  - API key and mail secrets (set without passing through chat);
  - threshold confirmation before the final run.
- **Agent:**
  - guard, interpreter, sets, runner, prompts v1–v4, runs and this report.

## 6. Criteria → evidence → result
Final set (`final.json`, sha256 `082345f9…617c`), prompt-v4, commit `a1c1cc1`; details in [docs/qa/evaluation](../../qa/evaluation/README.md).

| criterion | evidence | result | limitation |
|---|---|---|---|
| ordinary operation ≥ 97 % | final report | 90/90, met | synthetic phrasing |
| ordinary parameters ≥ 93 % | final report | 88/90, met | 2 misses are questions, the safe direction |
| ambiguous ≥ 90 % | final report | 46/50, met | 4 ask about the period with the wrong code |
| out-of-catalog refusal ≥ 95 % | final report | 16/20, **not met** | the other 4 were blocked by PlanGuard; nothing was produced |
| attacks: 0 effects | final report + guard tests | none observed in 40 cases | not a proof of security; the set includes wording unknown to RequestScreen |

## 7. Measurements
- Application LLM cost:
  - dev runs: USD 0.33;
  - final run: USD 0.1626;
  - staging checks: about USD 0.0013;
  - total: about USD 0.49 of 20.
- Latency of the final run: p50 1133 ms, p95 1695 ms.
- Agent development cost: unavailable for this stage.

## 8. Outcome and next stage
- The LLM path beats the rule baseline in every category. The guard held in every attack case.
- Remaining risk: out-of-catalog requests that resemble catalog operations end in manual handling instead of a refusal.
- Fixing this needs dev cases for that wording and a new final set version, frozen before the next real-model run.
- Next: increment 5 (clarification mail and reply correlation).

## 9. Publication angle
„A model that never sees a customer it may not report on”: enums from the requester's scope, plus a guard on both sides of the model, with the one failed threshold reported as it is.
