# Design direction

Status: mockups received from the owner (2026-11-16, desktop v8 / mobile v8); working prototype built 2026-11-21 and awaiting the owner's assessment — [prototype](prototype/README.md). Source mockups: [desktop](mockups/desktop/index.html), [mobile](mockups/mobile/index.html) with their READMEs. The HTML/CSS sources are the reference; PNGs are previews.

## Kept from the mockups

- Ubuntu Light 300 / Bold 700 (local files, Ubuntu Font Licence in `mockups/*/assets/UFL.txt`).
- Logo: yellow `#ffcf78` tile, purple `#482341` mark (`assets/icon-yellow.svg`); wave motif `pattern-flow.svg`.
- Teal palette for the employee side, purple for the administrator side; light surfaces, rounded cards, buttons with a left icon, 3 px bottom edge.
- Mobile: tickets as cards, statistics 2 × 2, single-column details, header dropdown navigation, Previous / Next pagination with ≥ 48 px controls. Layout fluid from 360 px.
- Status colours always paired with text.

## Material deviations (required by the brief, to be confirmed with the prototype)

| Mockup | Prototype | Reason |
|---|---|---|
| Ticket details show the sender's address and raw body | Production admin: correspondence rendered as safe plain text. Public demo: templated card (participant alias, recognized operation, allowed parameters, status), no address, subject or body | Brief §4.1 public-view rule (ID-DEMO-3) |
| Status „Błąd” | „Wymaga interwencji” with a reason line (e.g. „Błąd dostawcy AI”, „Limit pytań wyczerpany”) | One status for error/intervention (TKT-STATUS); the cause is a reason, not a status |
| Statuses shown: Ukończone, W realizacji, Do uzupełnienia, Błąd | Adds „Do potwierdzenia”, „W kolejce”, „Odrzucone”, „Interpretacja”, „Przyjęte” | Full process statuses |
| Ticket title as free text („Sprzedaż za październik”) | Title generated from operation + resolved parameters | No free text from participants or the model in public views |
| Mailbox address `zgloszenie@gitpush.app` | Read from configuration | Actual address confirmed at mailbox integration |
| Nav items „Dostępne dane”, „Ustawienia” | Kept as read-only pages (catalog/profile description; demo info) or hidden | Menu items do not add MVP scope |

## Screens the prototype adds

Employee: „Moje raporty” list, report detail with download button (opened from the email link after login), request confirmation (production mode). Admin: ticket in clarification, access denial, provider error with intervention actions (clarify parameters, choose customer, retry), accounts list with add/block/delete, file list, audit log. States: loading, empty, error, success.

Tokens: [client/src/styles/tokens.css](../../client/src/styles/tokens.css); shared components: [components.css](../../client/src/styles/components.css).
