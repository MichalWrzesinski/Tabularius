# 0005 — Business-scope guard around every interpretation

Date: 2026-11-22. Status: accepted (owner requirement, 2026-11-22: „the most important and key safeguard”).

## Context
Representatives and public-demo testers write free text. Some of it will try to abuse the system: delete data, reveal passwords or secrets, generate code or files, change prices, read other people's data, or rewrite the assistant's instructions. The LLM (increment 4) can be manipulated, so its output cannot be the security boundary (ADR 0004). The owner requires that every instruction is checked against the business concept: Tabularius only *prepares reports from a closed catalog for the requester's own scope*, and changes, deletes, reveals and generates nothing else.

## Decision
Two deterministic layers in `api/src/Module/Interpretation/Guard`, independent of the interpreter (rules today, OpenAI next), on top of the existing ones (closed catalog, scope from trusted context, read-only business API, template-only mail):

1. **RequestScreen (before interpretation).** The text is normalized first: Unicode NFKC, zero-width characters removed, diacritics folded, common Cyrillic/Greek look-alikes mapped, letter-by-letter spacing collapsed, Base64 fragments decoded and screened too. It is then checked for abuse categories:
   - `data_modification`: delete, change or update data;
   - `credential_access`: passwords, tokens, keys, logins, secrets;
   - `code_generation`: code, scripts, SQL, macros, programs;
   - `system_manipulation`: „ignore the instructions”, role play, revealing the system prompt;
   - `foreign_data`: payroll, HR, personal data, other representatives' or all customers.

   A hit means **the model is never called**. The ticket is `rejected` (`outside_business_scope`), the requester gets the templated refusal and the audit records `security.request_refused` with the category only, never the text.
2. **PlanGuard (after interpretation).** Every plan, from any interpreter, is validated before it may be routed:
   - intent and operation from closed sets; issue codes from a closed set;
   - parameter names from the profile's schema for that operation;
   - every value typed and closed: a period that parses as a period expression; customer codes in the owner's scope; manufacturer codes that exist in the business system; categories from the profile; a policy version pattern;
   - no free text anywhere: no strings beyond those types, no nested structures, no URLs, paths, e-mail addresses, SQL or control characters.

   A violation means nothing runs: `needs_intervention` (`model_output_rejected`), audit `security.plan_rejected` with violation codes.

The engine's own validation (`ReportEngine::check`) stays the third line; executor re-checks at run time; files are re-authorized at download.

## Consequences
- A false positive of the screen refuses a legitimate request politely; the requester can rephrase. This is preferred to any chance of an abusive instruction reaching the model or the data. Screen rules are tested against a corpus of legitimate requests as well as attacks.
- The screen is keyword-based and can be evaded by phrasing it does not know. That is acceptable because it is not the only line: an evaded instruction still meets a model with no tools and a PlanGuard that admits only closed values.
- Every refusal is visible to the administrator with its category, and measured in the evaluation (increment 4) as an attack outcome.
