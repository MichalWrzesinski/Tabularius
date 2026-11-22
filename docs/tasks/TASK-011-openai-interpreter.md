# TASK-011 — OpenAI interpreter behind the guard

Status: DONE (PR #33; real model verified on staging 2026-11-22) · Priority: high · Increment: 4 · Depends on: TASK-010 · Decision: [ADR 0004](../decisions/0004-llm-interprets-into-closed-plan-only.md)

## Goal and scope
The LLM maps the free text of a request to the database's closed lists: operations, the owner's customers, manufacturers with aliases, categories, and period expressions. It returns a schema-constrained plan; the backend never trusts it (TASK-010 PlanGuard, engine check, scope).
- OpenAI Responses API with strict JSON schema output generated from the integration profile; no tools. The untrusted text is clearly delimited; the system prompt states the business boundary. The prompt contains no secrets or other people's data.
- Limits: input length, calls per ticket, timeout, retries with backoff for transient errors, and a daily spend guard. A provider failure or exhausted budget → `needs_intervention` (`ai_provider_error` / `ai_limit`), never reported as success (CP-005, AC-6).
- Records model id, prompt version, tokens and cost per call (no message text in logs).
- Test double in CI; real calls only with the owner's key (GitHub secret `STAGING_OPENAI_API_KEY`), within the USD 20 budget.

## Acceptance
- With the double: the contract, the limits and the failure paths are tested.
- With the real model (owner's key): the e-mail loop works on staging, and inflections and typos („katalog Boscha”) are resolved by the model.

## Report (part 1: implementation with doubles)
- `api/src/Module/Interpretation/OpenAi/OpenAiInterpreter`: Responses API with `store: false`, `temperature: 0`, no tools, and a strict JSON schema built per request.
  - The schema's enums are the profile's operations, the owner's customer codes, the manufacturer codes, the categories, the period types and the closed issue codes. The model can only point at values that exist; it matches inflections, typos and informal names to them.
  - The system prompt (Polish, `prompt-v1`) states the business boundary and that the delimited text is data, never instructions. The delimiter cannot be closed from inside the message.
  - The prompt holds only the catalog and the owner's own customers: no secrets, no other representatives.
- The answer is mapped to the chosen operation's fields only; nulls are dropped and `customers: null` means all assigned. A refusal by the model becomes `out_of_catalog`. The plan then goes through PlanGuard (ADR 0005) like any other.
- Limits:
  - timeout 30 s;
  - 2 retries with backoff for 5xx, rate limits and transport errors;
  - a daily spend guard (`OPENAI_DAILY_BUDGET_USD`, default 2) checked before every call;
  - usage per call in `ai_usage`: model, prompt version, tokens, cost, latency and outcome, never text.
- Failures become `AiUnavailableException`:
  - `ai_limit` when the budget or the provider quota is exhausted;
  - `ai_provider_error` for anything else.
  
  The ticket goes to `needs_intervention` with that reason and the admin sets the parameters (CP-005, AC-6); there is no plan that pretends success.
- `SelectedInterpreter` uses the LLM when `OPENAI_API_KEY` is set and the rule-based baseline otherwise (local stack, CI).
- Staging: optional secret `STAGING_OPENAI_API_KEY` and variable `STAGING_OPENAI_MODEL` (default `gpt-4.1-mini`, configurable), written to the server's 600 file.
- Evidence: `OpenAiInterpreterTest`, against an HTTP double:
  - the request contract (no tools, store off, enums from the trusted lists, no key, secrets or foreign data in the prompt, undelimitable message);
  - mapping of three operations and the model's refusal;
  - retries; quota → `ai_limit`; bad key, invalid output, incomplete answer, unreachable → `ai_provider_error`;
  - the budget guard stops the call before it is sent;
  - usage and cost recorded;
  - a provider failure keeps the ticket for manual handling.
  
  API: 400 tests, 96.26 % lines.
- Pending (part 2): a real-model check on staging with the owner's key — the e-mail loop, and inflections and typos resolved by the model. Model choice and prices to be confirmed against the provider's current list.

## Report (part 2: real model on staging, 2026-11-22)
- Model `gpt-4.1-mini`, `prompt-v1`, the owner's key. Two test e-mails from the owner:
  - „Poproszę katalog części Boscha.” → `parts_catalog`, manufacturer `BOSCH`; completed with a file. The same request had failed with the rule baseline (T-1005, `missing_manufacturer`).
  - „Ile sprzedałem tym z Gdańska w zeszłym miesiącu?” → `sales_report`, `previous_month`, customers `["K-004"]` (Moto-Lux Gdańsk); completed. The model resolved an indirect reference to the owner's own customer.
- Usage: 2 calls, about 1 410 input and 38–66 output tokens each, USD 0.000623 and 0.000672 at the configured prices.
- Not yet observed on staging: an abusive e-mail through the real mailbox; the guard is covered by tests (TASK-010).
