# UI design

The interface implements the owner's mockups (desktop v8 / mobile v8, received 2026-11-16). The owner rejected a first prototype on 2026-11-21 because it only followed the general direction. Since then the mockup HTML/CSS has been the implementation basis. The second prototype was accepted the same day.

**Where the design-period material is:** the mockups, the prototype record (screenshots, mockup comparisons) and the input brief were removed from the repository on 2026-11-24. They remain in Git history at commit [`f269996`](https://github.com/MichalWrzesinski/Tabularius/tree/f269996/docs/design) (the brief: [`docs/input/brief.md`](https://github.com/MichalWrzesinski/Tabularius/blob/f269996/docs/input/brief.md)). Their findings live in [the product documents](../product/overview.md) and in this file.

## Visual direction (kept from the mockups)
- **Type:** Ubuntu Light 300 / Bold 700, from local font files.
- **Logo:** a yellow `#ffcf78` tile with a purple `#482341` mark, and the wave motif.
- **Palettes:**
  - teal for the employee side, purple for the administrator side;
  - light surfaces, rounded cards, buttons with a left icon and a 3 px bottom edge;
  - status colours always paired with text.
- **Phones:**
  - tickets shown as cards, statistics as a 2 × 2 grid, details in one column;
  - navigation in a header dropdown;
  - Previous / Next pagination with controls at least 48 px tall.

  The layout is fluid from 360 px.

## How it is built
- **Visual layer:** [`client/src/styles/mockup.css`](../../client/src/styles/mockup.css) was generated from the mockups' `desktop/style.css`, `desktop/home.css`, `mobile/mobile.css` and `mobile/home.css`.
  - Class names, HTML structure, copy and inline SVG icons come from the mockup sources.
  - Phone rules apply below 860 px.
- **Fixed artboards made fluid:** the mockups are fixed artboards (1500 × 1000 and 390 px). The app changes them as follows:
  - fluid widths instead of `.screen` 1500 × 1000, `.main` 1232 px, `.login` 650 + 850 px and `.form` 460 px;
  - a sticky sidebar;
  - the footer in the normal page flow.
- **Elements the mockups do not define** live in [`extras.css`](../../client/src/styles/extras.css) with an `x-` prefix, so they never override a mockup class. They cover:
  - states and actions;
  - the employee screens;
  - the CSV preview;
  - the legal pages;
  - the resolve buttons.

## Differences from the mockups, and why
| Where | Mockup | App | Reason |
|---|---|---|---|
| Employee login, demo box | Mailbox address only | Adds the shared demo credentials („Przykładowe dane logowania”); since 2026-11-24 also the notice that views are shared, with links to the legal pages | Brief §4.1 (credentials displayed), ID-DEMO-6. Owner request, 2026-11-21 |
| Admin login, demo box | „Konto demonstracyjne do skonfigurowania” | „Konto demonstracyjne, wspólne dla uczestników” | The account exists |
| Login screens | Static fields | Validation errors, show/hide password, error and „after login we open the report” notes | States required by brief §9 |
| Overview counters | Sample numbers | Calculated from tickets | Sample numbers were demo data |
| Status „Do sprawdzenia” vs „Błąd” | Both labels | One status `needs_intervention`: „Błąd” for technical failures, „Do sprawdzenia” otherwise | One intervention status in the process (TKT-STATUS) |
| Ticket details | Correspondence, file card, parameters | Same layout. Extra: a status banner, an action card for interventions, resolve buttons for unknown or failed sends, a collapsible result preview, a history tab | Brief §9 scenarios |
| Ticket details in the public demo | Name, address and body of the sender | Participant alias and the recognized operation | ID-DEMO-3 |
| „Ustawienia” | Menu item | A read-only page with environment information | A menu item does not add scope |
| Employee screens („Moje raporty”, report, confirmation, settings) | None | A teal header in the colours of the employee login, with all links (a menu on phones). Content uses the mockup classes | Brief §8, §9.1. Owner request, 2026-11-21 |
| Legal pages | Placeholder links | `/regulamin`, `/polityka-prywatnosci` in the home page's layout | Privacy standard, TASK-016 |
| Text contrast | Grey secondary text such as `#a092ac` | Unchanged in mockup elements | Kept as designed; see open questions |

## Open questions (owner)
- **Admin login heading:** it is „Odbierz raport”, as in the mockup. It may be a copy of the employee heading.
- **Contrast:** some secondary texts taken from the mockups fall below WCAG AA, e.g. `#a092ac` on white at about 2.7:1. The recommendation is to darken them; the change is hard to notice at a glance.
