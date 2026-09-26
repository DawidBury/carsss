# API client

How this frontend consumes the Cars API. The HTTP contract itself — resources, response shapes,
filters, paging, error bodies — is `../carsss/docs/api.md` and is not repeated here. This document
states only what is **ours**: the proxy, the key, which endpoints we call and why, and how
responses and failures become things a component can render.

## The proxy exists because the API has no CORS

`OPTIONS /v1/search` with an `Origin` header returns `405 Method Not Allowed` with `Allow: GET`.
There is no preflight handler and no `Access-Control-Allow-Origin` on any response, so a browser
cannot call the API directly. Two problems are solved by the same proxy:

1. the browser needs a same-origin endpoint;
2. `X-API-Key` must never reach the browser.

The backend is read-only and not ours to change, so adding CORS to it is not an option. ADR 0003.

### Development

`vite.config.ts` proxies `/api` to the backend and injects the key:

- `/api/v1/search?q=…` → `http://localhost:8080/v1/search?q=…`
- the `X-API-Key` header is added by the proxy, per request
- the client's base path is `/api/v1`

The key is read at **config time** with `loadEnv(mode, process.cwd(), '')` and lives in
`.env.local` as `CARS_API_KEY`.

**Trap.** The variable is deliberately *not* prefixed `VITE_`. Vite exposes only `VITE_`-prefixed
variables to client code, and `import.meta.env.VITE_CARS_API_KEY` would inline the secret into the
shipped bundle. Reading it in `vite.config.ts` keeps it in the Node process. The third argument to
`loadEnv` must be `''`, because its default only loads `VITE_`-prefixed variables and the key would
silently read as `undefined` — producing a `401` on every request with nothing to point at.

`.env.local` is git-ignored. `.env.example` is committed with the variable name and an empty value.

### Production

There is none yet — the owner confirmed local-only hosting. The consequence is recorded so it is
not discovered later: **the dev proxy is not a deployment**. Shipping this requires a reverse proxy
(nginx, Caddy) performing the same rewrite and header injection, because the two reasons the proxy
exists do not go away when the mode changes. The static bundle can be served from anywhere; the
`/api` prefix must be terminated by something holding the key. ADR 0003.

## Transport

One `fetch` wrapper, `api/client.ts`. It:

- prefixes the path with `/api/v1`;
- serialises query parameters, **omitting `undefined` and `null` entirely** — an unknown or empty
  parameter is a `400`, and a speculative `?cursor=` is the easiest way to earn one;
- forwards the `AbortSignal` it is given;
- parses JSON and, on a non-2xx status, throws `ApiError`.

No `axios`. `fetch` with a signal does everything needed; a client library would add 12 KB to
duplicate the platform.

No interceptors, no retry logic inside the wrapper, no request queue. Retries are `vue-query`'s
job and are configured in one place.

## Endpoints used

| Endpoint | Used for | Notes |
|---|---|---|
| `GET /v1/search?q&limit` | the combobox | `limit=20`, the hard cap; no paging exists |
| `GET /v1/variants/{id}` | the spec sheet | one request restores a shared URL |
| `GET /v1/brands?limit=200` | the brand select | 31 rows today, one page |
| `GET /v1/models?brandId&limit=200` | the model select | ≤19 rows per brand today, one page |
| `GET /v1/variants?modelId&limit=200` | the version and variant select | paged, see below |

Not used: `accelerationMin`, `accelerationMax`, `fuelType`, `gearbox`, `drive`, `sort`,
`versionId`, `brandId` on `/v1/variants`. No screen asks for them. Each is one line to add when a
screen does.

`GET /health` is not called. A health check that the user cannot act on is a request that buys
nothing; a failed real request already produces the error panel.

## Paging

Only `GET /v1/variants?modelId=…` pages, and only because the backend has no `GET /v1/versions`
and deliberately never will — the sole way to enumerate a model's versions is to page its variants
and group them client-side.

Rules:

- `limit=200`, the maximum, to minimise round trips.
- Follow `page.next` until it is `null`, concatenating `data`.
- **The cursor is re-sent with the identical filter set.** Changing `modelId` or adding a filter
  while keeping a cursor is a `400`, not a silent reset. In practice this means the paging loop
  owns the cursor and nothing outside it ever holds one.
- The loop is **bounded at 5 pages (1000 variants)**. If `page.next` is still non-`null` after
  that, paging stops and the query resolves as `{ variants, truncated: true }`.

Why bounded, and why 5: keyset paging is inherently sequential — page *n+1* needs the cursor from
page *n*, so *k* pages is *k* round trips that cannot be parallelised. Variants per model are
mean 16, median 4, p95 58, max 354 (Audi A4), so **one** page covers all but one model and two
cover every one of them. A model far above the p95 costs tens of sequential requests and megabytes
to populate a single select.

The bound is not a silent truncation. `truncated: true` is rendered: the select is disabled and
the user is told to use the search field, which has no such limit. A list that quietly omits the
car someone is looking for is worse than a list that admits it. ADR 0010.

## Cache keys and staleness

The source is scraped once a day, so within a session nothing the user can do makes this data
stale.

| Key | `staleTime` | `enabled` when |
|---|---|---|
| `['brands']` | `Infinity` | the brand select has been opened |
| `['models', brandId]` | `Infinity` | `brandId` is set |
| `['model-variants', modelId]` | `Infinity` | `modelId` is set |
| `['variant', variantId]` | `Infinity` | `variantId` is set |
| `['search', query]` | 5 minutes | `query.length >= 2` |

Global defaults, set once in `app/queryClient.ts`:

- `refetchOnWindowFocus: false` — daily data; refetching because a user changed tabs is pure waste.
- `refetchOnReconnect: false` — same reason.
- `retry` — retry only on `5xx` and on network failure, at most twice, with backoff. **A `400`,
  `401` or `404` is never retried**: the request is wrong or the row does not exist, and repeating
  it three times turns one visible bug into three and slows the error the user is waiting for.
  `vue-query`'s default of three unconditional retries is wrong for this API.

`['search', query]` uses `placeholderData: keepPreviousData` so the listbox keeps the previous
results while the next request is in flight instead of collapsing to empty on every keystroke.

### Cache seeding

Picking a variant from the browse path must not refetch what is already in memory. The
`['model-variants', modelId]` response contains complete variant objects, so selecting one seeds
`['variant', id]` with `queryClient.setQueryData` before the id is set. With `staleTime: Infinity`
the seeded entry is never refetched, so the browse path costs zero extra requests.

Search hits are **not** seeded: a search hit is a suggestion row, not a full variant — it has no
acceleration, consumption or engine fields — and seeding a partial object under a key that
promises a whole one is how a `undefined is not an object` reaches production.

## Cancellation and debouncing

Both live on the search field and nowhere else.

- The typed text is debounced **250 ms** before it becomes part of a query key. 250 ms is below the
  threshold at which typing feels laggy and above the interval of ordinary keystrokes, so a word
  typed at speed produces one request rather than eight.
- The query key contains the debounced text. When it changes, `vue-query` aborts the in-flight
  request through the `AbortSignal` it passes to the query function — **which only works if the
  function forwards `signal` to `fetch`.** Forgetting that argument is the single most common way
  this goes wrong: nothing errors, requests simply pile up and a slow response overwrites a fast
  newer one.
- Queries shorter than 2 characters are `enabled: false`. The API accepts a 1-character `q`, but a
  single letter matches most of the catalogue and produces 20 rows of noise while the user is still
  typing the first word.

No other request is debounced. Selects fire on a deliberate change, not on every keystroke.

## Errors

`api/errors.ts` turns every failure into one `ApiError` with a discriminated `kind`, so components
branch on a closed set instead of on status codes.

| Source | `kind` | What the UI does |
|---|---|---|
| `fetch` rejected, no response | `network` | error panel, retry button |
| `400` | `invalid-request` | error panel, retry button; a bug in our code, logged with `detail` |
| `401` | `unauthorized` | error panel, no retry — the proxy key is missing or wrong |
| `404` on `/v1/variants/{id}` | `not-found` | *not* the error panel — see below |
| `5xx` | `server` | error panel, retry button, `traceId` shown |
| non-JSON or unparseable body | `unexpected` | error panel, retry button |

The problem body is `application/problem+json` (RFC 9457). The **`type` field identifies the
error**, not `detail`, which is human text and may change. `traceId` is present only on `500` and
is displayed verbatim, because it is the only thing that connects a user's report to a server log.

`404` is not a failure of the application. A shared `?v=` pointing at a variant that no longer
exists is an expected outcome of a URL someone kept, and it renders as its own state — "ten
samochód już nie istnieje" with a cleared field — not as a connection error. Rendering a `404` as
"błąd połączenia z API" tells the user to retry something that will never succeed.

A `400` is always our defect: every parameter we send is from a closed set. It is rendered as the
generic error panel and the `detail` and `errors[]` are logged to the console, since only a
developer can act on it.
