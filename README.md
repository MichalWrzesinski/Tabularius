# Tabularius

**Od wiadomości do raportu** — on-demand business reports by email for a distributed sales organization.

A sales representative sends an ordinary email („Sprzedaż za ostatni miesiąc dla moich klientów”). Tabularius records a ticket, establishes who the employee is, interprets the request with an LLM into a typed plan, validates and authorizes it, runs one report from a closed catalog against a read-only business system, and lets the employee download the CSV after logging in. Missing parameters lead to at most three clarification questions. Administrators track tickets in a Vue 3 dashboard.

Status: **prototype** — clickable dashboard on a mocked API in [client/](client/README.md); no backend yet. See [docs/progress.md](docs/progress.md).

- Product rules, date semantics and request examples: [docs/product/overview.md](docs/product/overview.md), [reports and periods](docs/product/areas/reports.md), [tickets and examples](docs/product/areas/tickets.md)
- Design: [docs/design/README.md](docs/design/README.md)
- Agent instructions: [AGENTS.md](AGENTS.md)

Stack (agreed): PHP/Symfony, PostgreSQL, Vue 3 + TypeScript, OpenAI, IMAP/SMTP.
