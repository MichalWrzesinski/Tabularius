# Reports, periods and financial rules

Status: discovery draft, 2026-11-21. Owner of the area: [overview](../overview.md). All business rules below belong to the **fictional** demo profile `demo-autoparts` v1 and must not be presented as policies of a real company. A real deployment configures its own rules in its integration profile.

## Integration profile

RPT-PROF-1 One versioned profile file (repository, trusted operator) defines: business vocabulary and aliases, operations, a parameter schema per operation, missing-field questions (template keys), mapping to business-system API operations, permission scopes, CSV columns, and supported rule types (e.g. a tiered discount policy). Both the LLM operation description and backend validation are derived from it; nothing is maintained twice.

RPT-PROF-2 The profile contains no service addresses, credentials, SQL, expressions or scripts. Addresses and secrets come from environment configuration. A new protocol, authentication mechanism or calculation type needs code.

RPT-PROF-3 Every result records the profile id and version, rules version (e.g. discount policy version), business-data version (seed version) and generation time.

## Common output rules

- RPT-OUT-1 Currency PLN. Money is net, after adjustments, excluding canceled documents. Monetary values are decimal (business DB `numeric(14,2)`, PHP decimal arithmetic, never binary floats).
- RPT-OUT-2 CSV for Excel (pl-PL) and LibreOffice Calc: UTF-8 with BOM, `;` delimiter, CRLF, RFC 4180 quoting, decimal comma without thousands separator, dates `YYYY-MM-DD`. Columns per operation are defined in the integration profile (`api/config/integration/demo-autoparts.v1.yaml`); every file starts with `typ_wiersza` (`meta`, `pozycja`, `suma_klienta`, `suma_calkowita`, `suma`). Metadata rows carry the label in column 2 and the value in column 3. Samples: [docs/qa/csv/](../../qa/csv/).
- RPT-OUT-3 Each file begins with metadata rows (`row_type = meta`): operation, scope (described, e.g. „Twoi klienci (12)”), period with concrete dates, currency, profile/rules/data versions, generation time (`Europe/Warsaw`), and for simulations the notice „SYMULACJA — oferta niezatwierdzona, nie zmienia cen ani umów”.
- RPT-OUT-4 Formula injection: every **text** cell whose first character is `=`, `+`, `-`, `@`, tab, carriage return, or their full-width variants is prefixed with `'`. Numeric cells are formatted by code from decimals and are not escaped. Tests cover hostile customer/part names. `make csv-check` opens the samples in LibreOffice Calc (pl-PL): amounts import as numbers, guarded text stays text, and no cell becomes a formula. Trade-off: the apostrophe stays visible in the cell (e.g. `'+48 Auto Części`). The Excel check is manual and pending.
- RPT-OUT-5 „No data” is a valid result: a file with metadata and zero-value summary, and a message saying no transactions matched. It is not an error.
- RPT-OUT-6 Limits: at most 50 000 detail rows and 10 s query time per report (configurable); exceeding them ends the ticket in intervention with a reason, never a truncated file.

## Operations

### RPT-SALES — Sales report (`sales_report`)

Parameters: `period` (required, see periods), `customers` (required scope: `all_assigned` or a list of customer references resolved within the employee's scope), optional filters `manufacturer` and `category` (closed lists from the profile).

- RPT-SALES-1 Inclusion date: an invoice line counts on the document's **sale date**; an adjustment (correction document) counts on **its own issue date**, not the original invoice's date. Canceled documents (invoices or adjustments) are excluded entirely.
- RPT-SALES-2 Line net value = value stored by the business system after line discounts; the report only sums, it does not recompute prices.
- RPT-SALES-3 Rows: detail lines (date, document number and type, customer code and name, part number, manufacturer, quantity, net value), then a total per customer, then a grand total. A customer in scope with no sales appears in the per-customer totals with 0,00 so the scope is visible.
- RPT-SALES-4 Scope comes from trusted context (the employee's current assignments), never from model parameters. A requested customer outside scope is refused (ACC-SCOPE-2); the rest of a mixed request is not silently executed.

### RPT-CAT — Manufacturer parts catalog (`parts_catalog`)

Parameter: `manufacturer` (required, resolved unambiguously via profile vocabulary and aliases). No period.

- RPT-CAT-1 Only active parts. Columns limited to the profile's allowed fields: manufacturer, part number, name, category, unit, list net price PLN. The business data also contain non-permitted fields (purchase price, supplier, margin) that must never appear.
- RPT-CAT-2 Ambiguous or unknown manufacturer → clarification listing the matching manufacturers (manufacturer names are not confidential).

### RPT-DISC — Customer activity and discount simulation (`customer_discount_simulation`)

Parameters: `customer` (one, resolved within scope), `period` (required), `policy` (the version valid on the reference date by default; a named version must exist in the profile, otherwise clarification).

- RPT-DISC-1 Basis = the customer's net sales in the period by RPT-SALES rules.
- RPT-DISC-2 Fictional policy `DEMO-RABAT` version `2026.1` — tier by basis (lower bound inclusive):

  | Basis (PLN, net) | Rate |
  |---|---|
  | below 10 000,00 (including ≤ 0) | 0 % |
  | 10 000,00 – 49 999,99 | 3 % |
  | 50 000,00 – 99 999,99 | 5 % |
  | from 100 000,00 | 7 % |

  An inactive customer gets 0 % with the reason „klient nieaktywny”.
- RPT-DISC-3 Simulated discount = basis × rate, rounded **half up** to 0,01 PLN, once, on the final amount. Net after simulated discount = basis − discount.
- RPT-DISC-4 Output: activity (documents count, lines, net sales, adjustments total, last sale date in period), calculation basis, tier, rate, discount, net after discount, policy id and version, and the simulation notice. The system changes no prices, contracts, orders or customer records.

Boundary examples (fixture-backed tests):

| Basis | Rate | Discount | Note |
|---|---|---|---|
| 9 999,99 | 0 % | 0,00 | just below tier 2 |
| 10 000,00 | 3 % | 300,00 | lower bound inclusive |
| 49 999,99 | 3 % | 1 500,00 | 1 499,9997 rounded |
| 50 000,00 | 5 % | 2 500,00 | |
| 50 000,10 | 5 % | 2 500,01 | 2 500,005 → half up (half-even would give 2 500,00) |
| 99 999,99 | 5 % | 5 000,00 | 4 999,9995 rounded |
| 100 000,00 | 7 % | 7 000,00 | |
| −120,00 | 0 % | 0,00 | adjustments exceed sales |

## Reporting periods

The LLM never computes dates. It returns a **period expression** from a closed set; the backend resolves it against the reference date. Each expression type has its own schema; extra fields are rejected.

| Expression | Example text | Resolution (reference 2026-11-15) |
|---|---|---|
| `yesterday` | „wczoraj” | 2026-11-14 – 2026-11-14 |
| `last_n_days` (n 1–366) | „ostatnie 7 dni” | 2026-11-08 – 2026-11-14 (n days up to and including yesterday) |
| `previous_week` | „zeszły tydzień” | 2026-11-02 – 2026-11-08 (previous full Monday–Sunday week) |
| `previous_month` | „ostatni miesiąc”, „zeszły miesiąc” | 2026-10-01 – 2026-10-31 |
| `current_month_to_date` | „ten miesiąc” | 2026-11-01 – 2026-11-14; on the 1st: no completed period → clarification |
| `month` (month, optional year) | „październik”, „za listopad” | Oct → 2026-10-01 – 31; **Nov without year → 2025-11-01 – 30** (most recent completed occurrence) |
| `quarter` (q, optional year) | „Q3” | 2026-07-01 – 2026-09-30 |
| `year` (optional year) | „2025”, „zeszły rok” | 2025-01-01 – 2025-12-31 |
| `current_year_to_date` | „od początku roku” | 2026-01-01 – 2026-11-14 |
| `explicit` (start, end) | „od 1 do 15 listopada 2026” | inclusive dates; here invalid (includes today) → correction question |

- DATE-1 Time zone `Europe/Warsaw`. Reference date = the Warsaw date on which the mailbox received the message (server receive time), never the sender-controlled `Date` header.
- DATE-2 Start and end are inclusive calendar dates; the backend converts them to the half-open instant range [start 00:00, end+1 00:00) in `Europe/Warsaw`, handling DST, and queries in UTC.
- DATE-3 Only completed days: end ≤ reference − 1 day. An explicit period that includes today or the future requires correction; it is never silently shortened.
- DATE-4 Missing period → question; no default month. Start after end → correction. Longer than 366 days or before 2020-01-01 → correction with the allowed range.
- DATE-5 Questions and results show concrete dates („1–31 października 2026”), not only the expression.
- DATE-6 A follow-up keeps confirmed parameters and their resolved dates (resolved against the **first** message's reference date) unless the employee changes them; a changed period is resolved against the reference date of the message that changed it.
- DATE-7 The parts catalog has no period; a period mentioned with it is ignored and stated in the result („katalog nie zależy od okresu”).
