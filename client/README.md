# client — Vue 3 dashboard

**Status: prototype.** Every screen runs against a mocked, in-memory API (`src/mock/`) filled with synthetic data. Nothing is sent or stored outside the browser tab. The prototype checks flows and layout; it says nothing about whether the backend works. Before this code goes into the product, it needs the real API client, tests and quality checks (increment 2).

Run it (needs Docker only):

```bash
docker run --rm -it -u $(id -u):$(id -g) -e npm_config_cache=/tmp/.npm -v "$PWD":/app -w /app node:26-alpine npm install
```

```bash
docker run --rm -it --name tabularius-proto -u $(id -u):$(id -g) -p 127.0.0.1:5173:5173 -v "$PWD":/app -w /app node:26-alpine npm run dev
```

Then open http://localhost:5173. Demo logins are on the login screens („Wypełnij dane demo”). The „Prototyp” button switches between demo and production mode, simulates list errors, empty employee data and slow responses, and links to every scenario.

Typecheck: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app node:26-alpine npx vue-tsc --noEmit`

Screenshots and overflow check (while the dev server runs, from `client/`; needs `npm install playwright@1.64.1` in `scripts/`):

```bash
docker run --rm --add-host=host.docker.internal:host-gateway -v "$PWD/scripts":/pw -v "$PWD/../docs/design/prototype/screens":/out -w /pw mcr.microsoft.com/playwright:v1.64.1-noble node prototype-screens.mjs
```
