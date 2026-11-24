# Tabularius

**Od wiadomości do raportu** — on-demand business reports by email for a distributed sales organization.

A sales representative sends an ordinary email („Sprzedaż za ostatni miesiąc dla moich klientów”). Tabularius records a ticket, establishes who the employee is, interprets the request with an LLM into a typed plan, validates and authorizes it, runs one report from a closed catalog against a read-only business system, and lets the employee download the CSV after logging in. Missing parameters lead to at most three clarification questions. Administrators track tickets in a Vue 3 dashboard.

Status: **increment 2 in progress** — runnable foundation (Symfony API, demo business system, PostgreSQL, CI); the dashboard still runs on a mocked API ([client/](client/README.md)).

## Quick start

Requirements: Docker (with Compose) and `make`. Nothing else on the host.

```bash
make up
```

Opens on http://127.0.0.1:8080 (API health: `/api/health`, readiness: `/api/ready`). `make up` creates `.env` from `.env.example` (local-only values plus your UID/GID), builds images, installs dependencies, runs migrations, seeds the synthetic business data and starts `caddy`, `client`, `api`, `business` and `db`.

| Command | What it does |
|---|---|
| `make up` / `make down` | start / stop the stack |
| `make migrate` | run migrations of both databases (as the migrator roles) |
| `make seed [REF=YYYY-MM-DD]` | reload synthetic business data around a reference date (default today); see [business/README.md](business/README.md) |
| `make test` | backend and frontend tests |
| `make check` | everything CI runs: formatters, linters, PHPStan max, tests with coverage gates (backend 95 % lines, client logic 90 %), audits, gitleaks |
| `make hooks` | install the gitleaks pre-commit hook |
| `make clean-db` | drop the local database volume |

Demo logins (created by `make seed`): `admin@example.com` / `Demo123!`, `handlowiec@example.com` / `Demo123!` (shared demo accounts; local and public-demo only, never in production). All local e-mail lands in Mailpit at http://127.0.0.1:8025. See [docs/progress.md](docs/progress.md).

- Product rules, date semantics and request examples: [docs/product/overview.md](docs/product/overview.md), [reports and periods](docs/product/areas/reports.md), [tickets and examples](docs/product/areas/tickets.md)
- Design: [docs/design/README.md](docs/design/README.md)
- Agent instructions: [AGENTS.md](AGENTS.md)

Stack (agreed): PHP/Symfony, PostgreSQL, Vue 3 + TypeScript, OpenAI, IMAP/SMTP.

## License

[MIT](LICENSE) © 2026 Michał Wrzesiński.
