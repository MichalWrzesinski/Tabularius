# Tabularius — instructions for agents

„Od wiadomości do raportu”: an employee emails a request, Tabularius turns it into a ticket, interprets it with an LLM into a typed plan, authorizes it and runs one operation from a closed catalog against a read-only business system; the result is a CSV behind login.

Standards: `docs/standards/` — AppStandards suite 5.0.0, copied 2026-11-21 from `MichalWrzesinski/AppStandards@main` (commit `410ba9c`). Start with [core](docs/standards/core.md), [routing](docs/standards/routing.md), [workflow](docs/standards/workflow.md). Do not edit the copy; record gaps in `docs/standards-feedback.md`.

## Knowledge sources

- Product (source of rules, IDs like RPT-*, ACC-*, TKT-*): [docs/product/overview.md](docs/product/overview.md) and `docs/product/areas/`
- Design direction and mockups: [docs/design/README.md](docs/design/README.md)
- Owner's input brief (input material; findings move into `docs/product/`): [docs/input/brief.md](docs/input/brief.md)
- Progress view: [docs/progress.md](docs/progress.md)
- Case-study source material (required deliverable, facts only, never published by the agent): [docs/case-study/README.md](docs/case-study/README.md)
- Technical profile, architecture, decisions, API contract: added in the technical-decisions stage.

## Project overrides of standards

- Stack is fixed by the owner: PHP/Symfony + PostgreSQL backend, Vue 3 + TypeScript dashboard in `client/`. Do not ask about the stack or product name again.
- No AI attribution in commits or PRs (no `Co-Authored-By`, no tool footer).
- Documentation and code in English; UI, emails and demo communication in Polish; messages to the owner in Polish.
- Business data are synthetic only. The agent does not send real emails, enable real OpenAI calls, provision hosting or register domains until the owner has agreed the specific step and its cost.
- After a significant stage or finding, add a report under `docs/case-study/stages/` (format in the case-study README). Do not write posts or publish.

## Commands

None yet (repository contains documentation only).
