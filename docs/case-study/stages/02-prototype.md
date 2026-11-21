# 02 — Working prototype

## 1. Context

- Date: 2026-11-21. Stage: prototype, increment 1. The commit is the second local commit (no remote yet).
- Environment: owner's Mac, Docker (OrbStack) with `node:26-alpine` 26.13.1 for Vite 8.3.6 and Vue 3.5.45. Playwright 1.64.1 was used for checks.
- **Mocked API and synthetic fixtures only.** No backend, mail or OpenAI integration. Reference date fixed at 2026-11-15 10:50 Europe/Warsaw.

## 2. Problem, goal, initial state

Goal: let the owner check the main flow and the visual direction before costly implementation (brief §9.1, AppStandards UX/UI). The scenarios the prototype had to show were a valid request, missing period plus reply, prohibited report, provider error, result preview and employee result retrieval, on phone and desktop. Initial state: static mockups (four screens per size) and discovery rules from stage 01.

## 3. Options and decisions

| Question | Options | Decision and reason |
|---|---|---|
| Prototype medium | static HTML extension of mockups; prototype in target stack | Target stack (`client/`), so tokens and components can be reused; marked experimental |
| Data source | MSW / fake server; in-memory module | In-memory module with latency and failure switches: fewest dependencies, enough for flows |
| Session across reloads | memory only; sessionStorage | sessionStorage, standing in for the real HttpOnly cookie, so the email-link → login → report flow survives navigation |
| Overview counters | five (separate clarification); four as in mockups | Four, following the mockups. Clarification is still visible as its own status and filter |
| Icons | copy inline SVGs; Lucide | Lucide 1.0.0, which matches the line style and avoids hand-maintained SVGs |
| Public demo correspondence | masked address; templated card | Templated card only (ID-DEMO-3); production mode shows plain-text body |

## 4. Work and findings

- Observations:
  - Host Node 16 is too old for Vite 8, so all tooling runs in Docker.
  - `vue-tsc` 3.3.14 fails with TypeScript 7.0.2 (`ERR_PACKAGE_PATH_NOT_EXPORTED` for `typescript/lib/tsc`). Pinned TypeScript 5.9.3.
- Observation: the first CSV export wrote money with a thousands separator (non-breaking space, „1 289,00”). Spreadsheets would read that as text. Fixed by formatting CSV money without grouping. The on-screen views still group digits.
- Observation:
  - The mockups' secondary grey text (`#a092ac` on white) is about 2.7:1, below WCAG AA. The prototype uses `#74667f` (4.8–5.3:1).
  - The light end of the teal button gradient (`#168c91`) gave 4.04:1 with white text. It was darkened to `#0e7f86` (4.77:1).
- Failed attempt: the first Playwright run timed out on `waitForURL('**/logowanie?next=*')`. In a Playwright glob, `?` is not a literal question mark. Switched to a regex.
- Interpretation (to confirm with the owner): the confirmation step in production mode costs one screen, because the employee logs in anyway to download.

## 5. Contributions

- Owner: accepted the stage-01 recommendations Q1–Q7 („Yes, I accept”). Supplied the mockups earlier.
- Agent: built the prototype, scripted the checks, made the contrast and CSV fixes, and wrote the documentation. No human corrections to the prototype yet.

## 6. Criteria and evidence

| Criterion | Evidence | Result | Limitation |
|---|---|---|---|
| Required scenarios walkable | [prototype README](../../design/prototype/README.md#scenarios-covered-brief-9), script `client/scripts/prototype-screens.mjs` | all walked automatically, both sizes | Mocked rules |
| No horizontal page scroll at 360 px and desktop | same script, 55 checks | no overflow found | Only listed routes and states |
| No console errors | same script | none | — |
| Discount rounding and CSV guard in shared helpers | ad-hoc check of `lib/format.ts` (8 boundary cases, 7 guard cases) | 15/15 | Not a committed test; real engine is PHP |
| Contrast of tokens | computed WCAG ratios | text ≥ 4.77:1 | Gradients and focus states not measured individually |
| Keyboard and screen reader | not performed | gap | Next: manual pass before increment 2 UI work |

Screenshots (synthetic data): [screens/](../../design/prototype/screens/).

## 7. Measurements

- Agent development time, tokens, cost: unavailable (no usage export configured).
- Application LLM cost: none (no calls).

## 8. Outcome and next stage

The prototype is ready for the owner's assessment. Feedback will be handled as a requirements change. Next: technical decisions (Symfony modules, Messenger, two databases, API contract mode, auth), repository setup with CI and the private GitHub repository, then increment 2 (deterministic report engine with the seeded business system). Remaining risk: the CSV has not been opened in Excel or LibreOffice yet.

## 9. Possible publication angle

„The mockup showed the sender's email — the brief forbade it”: how the public-demo privacy rule changed a designed screen. Supported by the before/after screens.
