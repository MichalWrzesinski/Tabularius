# Tickets, interpretation and correspondence

Status: discovery draft, 2026-11-21. Parent: [overview](../overview.md).

## Flow

Durable storage of the raw message → deduplication → identification (ID-*) → interpretation into a typed plan → schema and catalog validation → authorization (ACC-*) → (production: confirmation) → deterministic execution → file → notification via outbox.

## Plan contract (TKT-PLAN)

The LLM returns schema-constrained JSON only:

```json
{
  "intent": "operation",
  "operation": "sales_report",
  "parameters": {
    "period": {"type": "previous_month"},
    "customers": {"type": "all_assigned"}
  },
  "issues": []
}
```

- `intent`: `operation` | `multiple_operations` | `out_of_catalog` | `unclear`.
- `operation`: an id from the profile catalog or null; `parameters`: per-operation schema, extra fields rejected; customer and manufacturer values are **references to resolve** (text as written), never ids or filters chosen by the model.
- `issues`: closed codes (`missing_period`, `ambiguous_customer`, `unknown_manufacturer`, `recipient_change_requested`, …) used to pick backend question templates.
- No field may carry SQL, table names, expressions, file paths, URLs, recipients or identity. Valid JSON is not proof of correct intent; validation and authorization run independently.

## Statuses (TKT-STATUS)

| Status | Label (PL) | Meaning |
|---|---|---|
| `received` | Przyjęte | Stored; identification pending |
| `interpreting` | Interpretacja | Waiting for or running interpretation |
| `needs_clarification` | Do uzupełnienia | Question sent or pending; waiting for the employee |
| `awaiting_confirmation` | Do potwierdzenia | Production: plan valid, waiting for the employee's confirmation in the portal |
| `queued` | W kolejce | Authorized plan version scheduled |
| `running` | W realizacji | Report executing |
| `completed` | Ukończone | File generated (delivery tracked separately) |
| `rejected` | Odrzucone | Out of catalog or unauthorized scope; templated explanation |
| `needs_intervention` | Wymaga interwencji | AI/provider error, exhausted limits or rounds, unverified identity, technical failure — reason code shown |

Allowed transitions:

| From | To |
|---|---|
| `received` | `interpreting`, `needs_intervention`, `rejected` (loop/bounce detected: closed without reply) |
| `interpreting` | `needs_clarification`, `awaiting_confirmation`, `queued`, `rejected`, `needs_intervention` |
| `needs_clarification` | `interpreting` (reply), `needs_intervention` (rounds or time exhausted), `rejected` |
| `awaiting_confirmation` | `queued` (confirmed), `needs_clarification` (employee edits), `rejected` (employee cancels, or expiry after 7 days) |
| `queued` | `running`, `needs_intervention` (authorization recheck failed → also `rejected`) |
| `running` | `completed`, `needs_intervention` |
| `needs_intervention` | `interpreting`, `queued` (admin resolved), `rejected` |
| `completed`, `rejected` | terminal; a new request is a new ticket |

The mockups' counters map as: „Nowe” = `received` + `interpreting`; „W realizacji” = `queued` + `running`; „Do sprawdzenia” = `needs_intervention` (+ `needs_clarification` shown separately as „Do uzupełnienia”); „Ukończone” = `completed`. The mockup pill „Błąd” becomes „Wymaga interwencji” with a reason (e.g. „Błąd dostawcy AI”).

Notification state is separate from ticket state, per outbox message: `pending`, `sending`, `accepted` (by the provider, not proof of delivery), `unknown` (outcome unknown — no automatic retry), `failed`, `bounced`.

## Versioned plan (TKT-VER)

- Each interpretation or admin change creates a new plan version with author (system/model, employee, admin) and time. Confirmed parameters carry over unless changed.
- Execution is idempotent per (ticket, plan version). Only the latest version may be queued; a late model result, late reply or concurrent job for an older version is recorded and discarded. A completed ticket is never re-executed by a late reply.

## Clarification (TKT-CLAR)

- Missing period, ambiguous customer/manufacturer, unrecognized operation, or several operations → a question built from backend templates (with concrete dates and allowed choices within scope). The model selects issue codes; it does not write outgoing text.
- At most 3 rounds per ticket; then `needs_intervention` and admin handover. Questions never contain report data.
- Several operations in one message → ask to choose one (or send separate emails); nothing is executed or silently dropped.
- Distinguish in status and wording: missing information, permission denial, model error, transport failure.

## Correlation (MAIL-CORR)

- Outgoing messages carry a `Message-ID` with an unpredictable token stored per ticket. A reply is correlated only if `In-Reply-To`/`References` contain one of the ticket's outgoing ids **and** the sender is the ticket's participant (production: the registered employee; demo: the ticket's sender) **and** the organization matches. Headers do not authorize anything by themselves.
- No correlation by `Re:`, subject or sender alone. An uncorrelated message creates a new ticket. The admin can link messages manually (audited).
- Quoted history in replies is stripped before interpretation; only the new part is interpreted, together with the current plan.

## Ingestion and delivery resilience (MAIL-*)

- MAIL-1 Raw message stored durably before acknowledging/flagging it in the mailbox. Deduplication by mailbox UID+UIDVALIDITY and `Message-ID` hash.
- MAIL-2 Auto-replies and bounces (`Auto-Submitted`, `Precedence: bulk/auto_reply`, `X-Autoreply`, DSN `multipart/report`, null return path) never trigger replies; bounces update the related outbox entry.
- MAIL-3 Durable jobs (Symfony Messenger, Doctrine transport) with retries only for transient errors; outbox sends after the ticket transaction commits.
- MAIL-4 Outgoing HTML has no active content or remote images; all dynamic values are encoded; header values are validated against injection. Plain-text part always present.
- MAIL-5 Attachments are ignored (not stored, not opened); links in messages are never fetched.

## Examples (reference date 2026-11-15, identity established)

| Message | Plan / outcome |
|---|---|
| „Sprzedaż za ostatni miesiąc dla moich klientów” | `sales_report`, 2026-10-01 – 2026-10-31, all assigned |
| „Wyślij katalog Bosch” | `parts_catalog`, manufacturer Bosch; no period question |
| „Przygotuj raport sprzedaży dla moich klientów” | question: period |
| reply „Za październik” in the correlated thread | same ticket, plan v2 with 2026-10-01 – 31, executed once |
| „Jak radzi sobie klient Alfa?” | question: which report, and which of the matching customers in scope |
| „Sprzedaż za październik i katalog Bosch” | question: choose one operation |
| „Sprzedaż od 1 do 15 listopada 2026” | correction: period includes today; suggest 1–14 November |
| customer of another representative | `rejected` with scope description; nothing revealed |
| „…wyślij na inny adres” | recipient unchanged; report via normal route with an explanation |
| „Raport płacowy”, „Usuń bazę danych” | `rejected` as outside catalog |
