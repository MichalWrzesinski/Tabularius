# Interpretation evaluation plan

Status: discovery proposal, 2026-11-21. Parent: [overview](../overview.md). Thresholds are agreed **before** measuring.

## Datasets

| Set | Purpose | Size (proposal) | Rules |
|---|---|---|---|
| `dev` | Prompt and schema development, CI regression with recorded responses | ~120 cases | Includes the brief's examples; may be tuned against |
| `final` | Frozen evaluation | ~200 cases | Written before the first real-model run, hashed and frozen in git; never used for tuning; new version only with a recorded reason |

Composition of each set (approximate shares): ordinary requests 45 %, ambiguous/missing parameters 25 %, out-of-catalog 10 %, attacks (injection, recipient change, scope escalation, hidden HTML, quoted history) 20 %. Polish language with realistic variety (typos, no diacritics, informal phrasing, signatures, quoted replies). Synthetic data only. Each case: message(s), reference date, employee scope, expected plan or expected issue codes, expected final outcome.

## Metrics (denominators always reported; ordinary and attack cases reported separately)

- Operation selection accuracy.
- Parameter accuracy (exact match after backend resolution) and completeness.
- End-to-end correctness (final file equals expected).
- Question relevance (right issue code) and unnecessary-question rate.
- Manual handling rate.
- Attack outcomes split into: interpretation error, attempted authorization violation (blocked), actual effect (any disclosure/side effect).
- Latency p50/p95, tokens and cost per ticket (model, prompt version, dataset version identified).

## Proposed thresholds (final set, real model)

- Ordinary requests: operation ≥ 97 %, parameters ≥ 93 %, end-to-end ≥ 93 %.
- Ambiguous: correct question ≥ 90 %; unnecessary questions on ordinary requests ≤ 5 %.
- Out-of-catalog: correct refusal ≥ 95 %.
- Attacks: 0 actual effects (disclosure, recipient change, out-of-scope data). A zero is reported as „none observed in N cases”, not as a guarantee.

## Baseline

A rule-based parser (keywords, month names, manufacturer aliases, customer names within scope) run on the same sets. The LLM is justified only if it beats the baseline materially on ordinary and ambiguous cases without worse attack outcomes.

## Cost

CI uses test doubles and recorded responses. Real runs need the agreed budget ([stage 01 questions](../../case-study/stages/01-discovery.md#open-questions-for-the-owner)); each run records model id, prompt version, profile version, dataset version and cost.
