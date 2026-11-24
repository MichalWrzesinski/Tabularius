# Prototype (increment 1)

Run instructions: [client/README.md](../../../client/README.md). Status: **accepted by the owner on 2026-11-21** (second version with the corrections listed below; open questions: admin login heading, mockup text contrast — kept as in the mockups until decided). The owner rejected the first version the same day because it did not look like the mockups (for example, the home page had different content).

## How the second version is built

- The visual layer is [client/src/styles/mockup.css](../../../client/src/styles/mockup.css), generated from the mockups' `desktop/style.css`, `desktop/home.css`, `mobile/mobile.css` and `mobile/home.css`. Class names, HTML structure, copy and inline SVG icons are taken from the mockup sources. Phone rules apply below 860 px.
- Changes against the sources are needed because the mockups are fixed artboards (1500 × 1000 and 390 px):
  - fluid widths instead of `.screen` 1500 × 1000, `.main` 1232 px, `.login` 650 + 850 px and `.form` 460 px;
  - a sticky sidebar;
  - the footer in the normal page flow.
- Elements the mockups do not define live in [extras.css](../../../client/src/styles/extras.css) with an `x-` prefix, so they never override a mockup class. These cover states, actions, the employee screens and the CSV preview.
- Comparison screenshots of the app at the mockup sizes are in [compare/](compare/), named like the mockup PNGs in [../mockups/](../mockups/). Script: `client/scripts/compare-mockups.mjs`.

## Differences from the mockups, and why

| Where | Mockup | Prototype | Reason |
|---|---|---|---|
| Employee login, demo box | Mailbox address only | Adds „Przykładowe dane logowania” with `handlowiec@example.com` / `Demo123!`, in the same format as the admin box (owner request, 2026-11-21) | Brief §4.1: shared demo credentials are displayed. Without them the employee cannot open the linked report |
| Admin login, demo box | „Konto demonstracyjne do skonfigurowania” | „Konto demonstracyjne, wspólne dla uczestników” | The account exists in the prototype. `admin@example.com` / `Demo123!` are taken from the mockup |
| Admin login heading | „Odbierz raport” | unchanged | Kept as in the mockup. It may be a copy of the employee heading; **owner to confirm** |
| Login screens | Static fields | Validation errors, show/hide password, error and „after login we open the report” notes | Brief §9 states |
| Overview counters | 8 / 3 / 2 / 24 | Calculated from the synthetic tickets | Sample numbers in the mockups are demo data |
| Status „Do sprawdzenia” vs „Błąd” | Both labels shown | Same status `needs_intervention`. The badge reads „Błąd” for technical failures and „Do sprawdzenia” otherwise | One intervention status in the process (TKT-STATUS) |
| Ticket details | Correspondence, file card, parameters | Same layout. Extra: a status banner for states other than completed, an action card for interventions, a reply simulation (prototype only), a collapsible result preview, and the history tab | Scenarios from brief §9 |
| Ticket details in the **public demo** | Shows name, address and body | „Prototyp” → „Widoki publicznego demo” replaces them with an alias and the recognized operation | Brief §4.1 / ID-DEMO-3. Off by default so the screen matches the mockup (trusted view) |
| „Ustawienia” | Menu item | A read-only page with environment information | A menu item does not add scope |
| Employee screens („Moje raporty”, report, confirmation, settings) | None | No sidebar: a teal header in the colours of the employee login holds all links (Moje raporty, Ustawienia, Wyloguj; a menu on phones). Content uses the mockup classes (file cards, parameter cards, badges). Owner request, 2026-11-21 | Brief §8, §9.1 asks for these screens. A one-item sidebar wasted space |
| Text contrast | Grey secondary text such as `#a092ac` | Unchanged in mockup elements | Kept as designed. Contrast is flagged under limitations |

## Scenarios covered (brief §9)

| Scenario | Where | Automated walkthrough (Playwright, 1440 and 360 px) |
|---|---|---|
| Valid request → report | `T-1042`, employee `/moje-raporty/f-7c1e9a` | file, preview, CSV download |
| Missing period → question → reply → same ticket, one result | `T-1040` (discount simulation, „Odpowiedź pracownika”) | plan v1 → v2, queued once, completed |
| Prohibited report (payroll, „delete the database”) | `T-1037` | rejected, no data read |
| Customer of another representative | `T-1034` | refused without naming other customers |
| Data-source error → retry | `T-1039` („Błąd”) | retry → completed |
| AI provider error → manual handling | `T-1033` | retry interpretation (fails again by design) or manual parameters → report |
| Ambiguous customer after 3 rounds | `T-1035` | admin picks a customer within the owner's scope |
| E-mail link → login → report, no automatic download | `/moje-raporty/f-7c1e9a` without a session | login, then back to the report |
| Production confirmation (ID-2) | `/moje-raporty/potwierdz/T-1036` | confirm → discount simulation (2 500,01 half-up case) |
| Foreign or missing file | `/moje-raporty/f-0000ff` | „Ten raport jest niedostępny” |
| Loading, empty, error states | „Prototyp” panel | skeletons, empty states, error with retry |

Results:
- Automated checks (the prototype walkthrough script, removed on 2026-11-24 and kept in Git history): 57 checks, no horizontal page scroll at 1440 or 360 px, no console errors.
- Curated screenshots: [screens/](screens/).
- Sample CSV: [sample-sales-report.csv](sample-sales-report.csv).

## Limitations

- No backend. Permission checks in `src/mock/api.ts` only imitate the rules.
- Contrast: some secondary texts taken from the mockups are below WCAG AA, for example `#a092ac` on white at about 2.7:1. The first version darkened them; the second keeps the mockup values. **Owner to decide** whether to darken them (recommended; the change is not visible at a glance).
- No full keyboard-only or screen-reader pass yet. The controls are native elements with focus styles, and Esc closes the menus.
- The CSV has not been opened in Excel or LibreOffice yet.
