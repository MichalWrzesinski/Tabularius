# TASK-011 — OpenAI interpreter behind the guard

Status: PLANNED · Priority: high · Increment: 4 · Depends on: TASK-010 · Decision: [ADR 0004](../decisions/0004-llm-interprets-into-closed-plan-only.md)

## Goal and scope
The LLM maps the free text of a request to the database's closed lists: operations, the owner's customers, manufacturers with aliases, categories, and period expressions. It returns a schema-constrained plan; the backend never trusts it (TASK-010 PlanGuard, engine check, scope).
- OpenAI Responses API with strict JSON schema output generated from the integration profile; no tools. The untrusted text is clearly delimited; the system prompt states the business boundary. The prompt contains no secrets or other people's data.
- Limits: input length, calls per ticket, timeout, retries with backoff for transient errors, and a daily spend guard. A provider failure or exhausted budget → `needs_intervention` (`ai_provider_error` / `ai_limit`), never reported as success (CP-005, AC-6).
- Records model id, prompt version, tokens and cost per call (no message text in logs).
- Test double in CI; real calls only with the owner's key (GitHub secret `STAGING_OPENAI_API_KEY`), within the USD 20 budget.

## Acceptance
- With the double: the contract, the limits and the failure paths are tested.
- With the real model (owner's key): the e-mail loop works on staging, and inflections and typos („katalog Boscha”) are resolved by the model.
