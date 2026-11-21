# Prototype (increment 1)

Built 2026-11-21 in the target stack (`client/`: Vue 3 + TypeScript, Vite) with a mocked API and synthetic data. Run instructions: [client/README.md](../../../client/README.md). Status: **waiting for the owner's assessment**.

## Scenarios covered (brief §9)

| Scenario | Where | Result of the automated walkthrough (Playwright, 1440 and 360 px) |
|---|---|---|
| Valid request → report | `/admin/zgloszenia/T-1042`, employee `/moje-raporty/f-7c1e9a` | file, notification, result preview, CSV download |
| Missing period → question → reply → same ticket, one result | `/admin/zgloszenia/T-1040` („Symulacja odpowiedzi”) | plan v1 → v2, queued once, completed, file created |
| Prohibited report (payroll, „delete the database”) | `T-1037` | rejected, no data read |
| Customer of another representative | `T-1034` | refused without naming other customers |
| Provider error → intervention | `T-1039` | ticket kept; retry (fails again by design) or manual parameters → report |
| Ambiguous customer after 3 rounds | `T-1035` | admin picks a customer within the owner's scope |
| Email link → login → report (no automatic download) | `/moje-raporty/f-7c1e9a` without a session | redirect to login, back to the report |
| Production confirmation (ID-2) | `/moje-raporty/potwierdz/T-1036` | confirm → discount simulation with the 2 500,01 half-up case |
| Foreign or missing file | `/moje-raporty/f-0000ff` | „Ten raport jest niedostępny” (no existence hint) |
| Loading / empty / error states | „Prototyp” panel: latency, empty employee, list error | skeletons, empty states, error with retry |

Automated checks (script `client/scripts/prototype-screens.mjs`): 55 checks, no horizontal page scroll at 1440 or 360 px, no console errors. Curated screenshots are in [screens/](screens/). A downloaded sample of the CSV is in [sample-sales-report.csv](sample-sales-report.csv): UTF-8 with BOM, `;`, CRLF, decimal comma, and `'+48 Auto Części` escaped.

## Decisions taken in the prototype

- Public demo views never show the sender address, subject or body. Incoming messages show as „Prośba od uczestnika · rozpoznano: …”. Production mode shows the body as plain text (no HTML rendering).
- Ticket titles come from the operation and the resolved parameters, not from the email subject.
- Overview keeps the mockups' four counters: Nowe (`received` + `interpreting`), W realizacji (`queued` + `running`), Wymagają interwencji, Ukończone. Clarification and confirmation are reached through the status filter.
- The administrator's interventions run within the ticket owner's scope (ACC-ADM-1). The UI says so, and every change appears in the ticket history.
- Icons come from Lucide (MIT licence), which matches the mockups' line style, instead of copying the inline SVGs.
- Contrast: the mockups' light grey text (`#a092ac`, about 2.7:1) was replaced by `--muted` `#74667f` (4.8–5.3:1). The teal button gradient was darkened to at least 4.77:1. Status colours are 5.1–6.4:1 and always come with text.
- The „Prototyp” control panel and „Symulacja odpowiedzi” exist only in the prototype (dashed outline).

## Limitations and gaps

- No backend. Permission checks in `src/mock/api.ts` only imitate the rules and are not a security boundary.
- Keyboard: every control is a native element with a visible focus ring, and the mobile menu and profile menu close with Esc. A full keyboard-only walkthrough and a screen-reader check have not been done.
- The CSV has not been opened in Excel or LibreOffice yet (RPT-OUT-2/4 require it in increment 2).
- The wide CSV preview scrolls horizontally inside its own frame on narrow screens. The page itself does not scroll sideways.
- Dates are fixed to the reference date 15.11.2026.
