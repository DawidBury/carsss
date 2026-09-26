# ilejedzie.pl — frontend

Vue 3 single-screen search over the Cars API. A text field and three cascading selects resolve
to one fully specified car variant and render its spec sheet.

The API this talks to lives in `../carsss` and is read-only. It is not part of this repository
and is not modified from here.

## Stack

Vue 3.5 · TypeScript (`strict`) · Vite 8 · Vitest 5 · TanStack Query 5

No router, no store, no CSS framework, no HTTP client. Each of those absences is a decision with
an ADR behind it, not an omission.

## Prerequisites

**Node ≥ 22.12**, enforced by `engines` in `package.json`. The repository pins a version in
`.nvmrc`:

```bash
nvm use
```

Run this in every new shell. If the shell resolves `node` to an older version, commands fail with
a syntax error from inside a dependency, which looks like a broken package rather than a wrong
runtime.

The backend must be running and reachable at `http://localhost:8080`:

```bash
cd ../carsss && make up
```

## Setup

```bash
nvm use
npm install
cp .env.example .env.local
```

Put an API key in `.env.local` as `CARS_API_KEY`. Issue one from the backend:

```bash
cd ../carsss && docker compose exec api php bin/console app:api-key:create frontend-dev
```

The key is printed once. It is read by `vite.config.ts` at config time and injected into proxied
requests by the dev server — **it never reaches the browser**, and it is deliberately not
`VITE_`-prefixed so it cannot be inlined into the bundle. If it is missing, the dev server refuses
to start rather than failing later with an unexplained `401`.

## Running

```bash
npm run dev
```

The app is served at `http://localhost:5173` and proxies `/api` to the backend. Requests go through
the proxy because the API sends no CORS headers, so the browser cannot call it directly.

## Verification

```bash
npm run typecheck
npm run test
npm run lint
npm run build
```

All four must pass before any change is considered done. Tests run against fixtures captured from
the live API; no test performs an HTTP request.

## If the search returns nothing

The backend's import is partial. Check what is actually loaded before assuming a frontend bug:

```bash
cd ../carsss && docker compose exec -T database psql -U cars -d cars \
  -c "select (select count(*) from brand) brands, (select count(*) from variant) variants;"
```

A brand absent from the database returns no suggestions, correctly. The search index also needs
`make search-refresh` in the backend after an import, or results reflect the previous run.

## Documentation

| File | Content |
|---|---|
| `CLAUDE.md` | binding decisions, stack, code standard |
| `docs/architecture.md` | modules, dependency direction, state ownership |
| `docs/api-client.md` | proxy, key handling, paging, caching, error mapping |
| `docs/ui.md` | design-to-component mapping, Polish formatting, combobox contract |
| `docs/adr/` | decisions with their rejected alternatives |
| `design/` | the approved Claude Design source, vendored unchanged |
| `../carsss/docs/api.md` | the upstream HTTP contract — never duplicated here |

## Agents

| Agent | Model | Role |
|---|---|---|
| `architect` | opus | structure, ADRs, module `CLAUDE.md`, type contracts |
| `developer` | sonnet | production code and tests |
| `code-reviewer` | sonnet | line-by-line review |

Definitions are in `.claude/agents/`. Flow: `architect` designs → `developer` implements →
`code-reviewer` reviews. A blocking defect goes back to its author.
