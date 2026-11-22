# business — demo business system (synthetic)

A separate Symfony app with its own PostgreSQL database `business`. It plays the „company system” that Tabularius reads through a read-only API ([ADR 0002](../docs/decisions/0002-separate-business-system-behind-read-only-api.md)). All data is synthetic, and the discount policy is fictional.

## Roles and access

- `business_owner` runs the migrations and the seed.
- `business_reader` is the role the API runs as. It can SELECT only from the `v_*` views (`migrations/Version20261122090000.php`). Purchase prices and suppliers are not visible through the views.
- Every `/v1/*` request needs `Authorization: Bearer $BUSINESS_API_TOKEN`. An empty token rejects everything. The API is published only inside Compose, at `http://caddy:8081`.

## Seed

```bash
make seed
```

`make seed` reloads the data around today (Europe/Warsaw). To reload it around a chosen reference date instead:

```bash
make seed REF=2026-11-15
```

- The same reference date always gives identical data: seed version `2026.11-1`, random seed `20261122`. The tests pin the content checksum.
- All dates are relative to the reference date R, so refreshing with a new R moves the whole history. Run `make seed` from time to time to keep the public demo current.
- Volume: 6 manufacturers (one inactive), 200 parts, 30 customers, 5 representatives, and documents from R − 13 months to R + 2 months. Future documents never fall into a period that ends yesterday.
- Seeding replaces all data and runs only where `BUSINESS_ALLOW_SEED=1` (local, tests, public demo).

### Known answers (customers of the demo representative PH-01)

P is the last completed month before R, P2 the month before it, C the month of R. Values are net PLN.

| Customer | P | P2 | Notes |
|---|---|---|---|
| K-001 Alfa Motors Sp. z o.o. | 754,10 | — | C: 100,00 yesterday (when R is not the 1st) and 77,00 today |
| K-002 Alfa-Tech Hurtownia | 745,00 | 410,00 | P: 894,00 invoice − 149,00 adjustment. P2: sale date on P2's last day, issued on P's 1st. C: −50,00 adjustment issued on R |
| K-003 +48 Auto Części | 319,20 | — | Name starts with a formula character |
| K-004 Moto-Lux Gdańsk | 50 000,10 | 9 999,99 | Discount boundaries (5 % → 2 500,01 half up; 0 %). Future invoice at R + 10 |
| K-005 Serwis Bielany | 1 289,00 | — | A canceled 500,00 invoice in P is excluded. Future invoice at R + 5 |
| K-006 Warsztat Pod Lipami | — | — | No sales at all |
| K-007 Auto-Kombi Radom | — | — | Inactive. One invoice of 1 200,00 six months before C |

Similar names on purpose: „Alfatrans Logistyka” (K-008) belongs to PH-02.

## API (`GET` only)

| Endpoint | Purpose |
|---|---|
| `/v1/meta` | Seed version and reference date |
| `/v1/sales-reps/{code}/customers` | Customers currently assigned to a representative |
| `/v1/sales-lines?salesRep=&from=&to=[&customers=K-001,…][&manufacturer=][&category=][&page=&perPage=]` | Sales lines by inclusion date: invoices by sale date, adjustments by issue date, canceled documents excluded. Requested customers outside the representative's scope → `403 out_of_scope`. Periods are capped at 400 days and `perPage` at 1000 |
| `/v1/manufacturers` | Manufacturers with aliases and the active flag |
| `/v1/manufacturers/{code}/parts` | Active parts, permitted fields only |
| `/v1/discount-policies` | Fictional discount policy tiers |

Errors use Problem Details (`application/problem+json`) with a `code`. Unknown query parameters return `400 unknown_parameter`.
