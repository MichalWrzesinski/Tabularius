# 0004 — The LLM only interprets into a closed plan

Date: 2026-11-21. Status: accepted.

## Context
Email content and model output are untrusted (brief §5, §7). The model must not compute money or dates, choose security filters, call tools or write outgoing text.

## Decision
- The `Interpretation` module sends the stripped message text, the current plan version and a catalog description to OpenAI. The catalog description is generated from the same integration profile that drives backend validation.
- The request uses schema-constrained output and no tools. The response is the plan contract in `docs/product/areas/tickets.md`: intent, operation id, parameters per operation schema, and closed issue codes. Periods are closed expressions resolved by the backend (`docs/product/areas/reports.md`).
- The backend validates the schema (extra fields are rejected), resolves references within the owner's scope, authorizes, and only then executes coded operations. Questions are built from backend templates.
- The provider sits behind an `Interpreter` interface with a deterministic test double. A rule-based baseline implements the same interface for evaluation.
- Limits come from configuration: input length, calls per ticket, timeout, retries and a daily spend guard. Exhausted funds have their own reason code.

## Consequences
- A prompt injection can at worst select a wrong *allowed* operation or wrong parameters. Validation, scope checks, confirmation in production, and evaluation of interpretation errors handle that.
- Real-model evaluation runs within the approved USD 20 budget, recording model, prompt, profile and dataset versions.
