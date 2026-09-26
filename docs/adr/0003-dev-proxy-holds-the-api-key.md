# 0003 — The API key lives in a proxy, never in the browser

## Context

Two measured facts about the backend:

1. It sends no CORS headers. `OPTIONS /v1/search` with an `Origin` header returns
   `405 Method Not Allowed` with `Allow: GET`. A browser cannot call it directly.
2. Every `/v1/**` request requires `X-API-Key`. Anything the browser sends, a user can read.

The backend is read-only and already built. It is not ours to change, so adding CORS to it is not
an available move. The project owner has confirmed local-only hosting for now.

## Decision

All API traffic goes through a proxy on the frontend's own origin, which rewrites `/api/*` to
`/*` on the backend and injects `X-API-Key`. In development this is the Vite dev proxy.

The key is read in `vite.config.ts` at config time via `loadEnv(mode, process.cwd(), '')` from
`CARS_API_KEY` in `.env.local` — deliberately **without** a `VITE_` prefix.

## Consequences

- The client's base path is `/api/v1`, and client code never sees or sets an auth header.
- The key stays in the Node process. A `VITE_`-prefixed variable would be inlined into the shipped
  bundle by design, which is the trap this naming avoids. The `''` third argument to `loadEnv` is
  required for the same reason: its default loads only `VITE_`-prefixed variables, and the key
  would silently be `undefined`, producing `401` on every request with nothing to point at.
- Same-origin requests, so no preflight is ever issued and the `405` never occurs.
- **The dev proxy is not a deployment.** Both reasons the proxy exists survive the switch to
  production, so shipping requires a reverse proxy (nginx, Caddy) doing the same rewrite and
  header injection. The static bundle can be served from anywhere; the `/api` prefix must be
  terminated by something holding the key. This is recorded now so it is not discovered on the
  day of deployment.

## Rejected alternatives

**Call the API directly from the browser with the key in `VITE_` config.** Fails twice: CORS makes
it impossible, and it would publish the key to every visitor.

**Ask for CORS and a public read-only key on the backend.** Would remove the proxy, but the
backend is outside this project's scope and explicitly not ours to modify. If the owner ever wants
a browser-callable API, that is a backend change with its own ADR over there.

**A small Node/Express server of our own in front of the API.** A second process, a second
deployment and a second thing to secure, to do what one Vite config block and, later, four lines of
nginx already do.

**A serverless function as the proxy.** Adds a hosting dependency and a cold start to a project
whose owner has confirmed local-only hosting. Revisit only if a real deployment target appears.
