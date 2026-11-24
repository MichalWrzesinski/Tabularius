# client — Vue 3 dashboard

**Status:** login, sessions, accounts and the audit log use the real API (`src/lib/http.ts`, `src/lib/session.ts`). Tickets, files and reports still use the in-memory prototype mock (`src/mock/`) until TASK-005. The prototype checks flows and layout; it says nothing about whether the backend works. Before this code goes into the product, it needs the real API client, tests and quality checks (increment 2).

Run it (needs Docker only):

```bash
docker run --rm -it -u $(id -u):$(id -g) -e npm_config_cache=/tmp/.npm -v "$PWD":/app -w /app node:26-alpine npm install
```

```bash
docker run --rm -it --name tabularius-proto -u $(id -u):$(id -g) -p 127.0.0.1:5173:5173 -v "$PWD":/app -w /app node:26-alpine npm run dev
```

Then open http://localhost:5173. Demo logins (shown on the login screens): `admin@example.com` / `Demo123!` and `handlowiec@example.com` / `Demo123!`. The „Prototyp” button switches between demo and production mode and turns on the public-demo views. It also simulates list errors, empty employee data and slow responses, and links to every scenario.

The appearance comes from the owner's mockups: `src/styles/mockup.css` is generated from `docs/design/mockups/` (keep class names identical), and anything extra lives in `src/styles/extras.css` with an `x-` prefix.

Typecheck: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app node:26-alpine npx vue-tsc --noEmit`

Comparison with the mockups (writes app screenshots at 1500 × 1000 and 390 px; mount the mockups at `/mock`): `scripts/compare-mockups.mjs`.

## Scripts against the running stack (`make up`)

From `client/`, after `npm install playwright@1.64.1` in `scripts/`. Both scripts default to `BASE_URL=http://host.docker.internal:8080`.

```bash
docker run --rm --add-host=host.docker.internal:host-gateway -v "$PWD/scripts":/pw -w /pw mcr.microsoft.com/playwright:v1.64.1-noble node e2e-cp004.mjs
```

- `e2e-cp004.mjs` runs critical path CP-004 through the UI and Mailpit, and deletes its own account afterwards.
- `e2e-cp001.mjs` runs CP-001 without mail and the file part of CP-003: manual ticket → worker → representative downloads at 360 px → another representative gets 404. Needs the worker (`make up` starts it).
- The prototype-era walkthrough script was removed on 2026-11-24 (its steps no longer applied after TASK-005; it is in Git history). Its screenshots in `docs/design/prototype/screens` remain the record of the accepted prototype.
