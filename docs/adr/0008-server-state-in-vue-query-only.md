# 0008 — Server state lives in `vue-query` only, with per-query staleness

## Context

`@tanstack/vue-query` 5 is fixed by the project owner. What is not fixed is how it is used: cache
keys, staleness, retry policy, and whether anything is copied out of it.

The backing data is scraped once a day, so within a session nothing a user can do makes it stale.
`vue-query`'s defaults assume the opposite — `staleTime: 0`, refetch on window focus, three
unconditional retries.

## Decision

Every request-keyed value lives in `vue-query` and is read from the query result. It is never
copied into a `ref`, never mirrored into a store, never "synced".

Keys and staleness (the table is in `docs/api-client.md`): catalogue and variant queries use
`staleTime: Infinity`; `['search', query]` uses five minutes with `keepPreviousData`.

Global defaults in `app/queryClient.ts`: `refetchOnWindowFocus: false`, `refetchOnReconnect: false`,
and `retry` only on `5xx` or network failure, at most twice.

Selecting a variant from the browse path seeds `['variant', id]` from the already-fetched
model-variants response. Search hits are **not** seeded.

## Consequences

- A session issues each catalogue request at most once. Reopening a select is free.
- Refetch storms are impossible: nothing refetches on focus, and nothing is stale.
- A page reload is the refresh mechanism, which matches a daily import. If the import ever becomes
  frequent, this is the ADR to revisit — the change is one number per key.
- `4xx` is never retried. Retrying a `400` triples a bug we caused and delays the error the user is
  waiting for; retrying a `404` cannot succeed by definition.
- Cache seeding makes the browse path cost zero extra requests to open a car.
- Not seeding from search hits is deliberate. A hit carries only enough to draw a suggestion row —
  no acceleration, no consumption, no engine data. Writing it under a key that promises a full
  variant is how a template ends up reading a property of `undefined`.

## Rejected alternatives

**`vue-query` defaults.** Would refetch the whole catalogue whenever the user changed tabs, for
data that changes once a day, and would retry a malformed request three times.

**`staleTime: 0` with manual invalidation.** There is no write path in this application — the API
is read-only — so there is no event that could invalidate anything.

**Copying query results into `ref`s for the components to read.** Two copies of one fact,
guaranteed to disagree, and it discards the loading and error states that come with the query.

**Hand-rolled caching with `ref` plus a `Map`.** Re-implements deduplication, cancellation,
staleness and retry — the reasons the dependency was chosen — with fewer of them working.

**Seeding `['variant', id]` from a search hit to skip its request.** Saves one ~40 ms request in
exchange for a partially-populated object under a key typed as complete.
