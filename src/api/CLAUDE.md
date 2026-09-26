# `api` — transport

## Owns

The only place in this codebase that performs HTTP. Response types, one `fetch` wrapper, and the
mapping from any failure to a single `ApiError`.

Contracts: `types.ts`, `client.ts`, `errors.ts`. How and why: `docs/api-client.md`.

## Boundaries

- Imports nothing from `src/`. In particular it never imports `format` — **the transport layer
  never produces Polish text**, and never a display string of any kind.
- Knows nothing about Vue. No `ref`, no composable, no component. It is plain async functions, so
  it is testable without mounting anything.
- Owns no cache and no state. Deduplication, staleness and retries belong to `vue-query`
  (ADR 0008). This module does not memoise.
- Exposes one page at a time. `getVariantsPage` returns exactly what the server returned; the
  bounded paging loop lives in `browse` because its bound is a product decision (ADR 0010).

## Decisions not to reverse

- **No HTTP client dependency.** `fetch` with an `AbortSignal` does the whole job; a client library
  would add weight to duplicate the platform.
- **No interceptors and no retry inside the wrapper.** One place decides retries, and it is
  `app/queryClient.ts`.
- **`null` is preserved exactly as received.** Never defaulted to `0`, `''` or a placeholder. It
  carries meaning, and erasing it here erases it everywhere downstream.
- **Types are hand-written, not generated.** The API publishes no OpenAPI document. These types are
  transcribed from `../carsss/docs/api.md` and verified against live responses; a code generator
  would need a schema that does not exist.

## Traps

- **`signal` must be forwarded to `fetch`.** Every function takes `RequestOptions` for exactly this
  reason. Dropping it breaks cancellation silently — nothing errors, requests simply pile up and a
  slow response can overwrite a newer fast one.
- **Never send a parameter speculatively.** An unknown query parameter is a `400`, not a silent
  ignore, and so is an empty `cursor=`. The serialiser omits `undefined` and `null` keys entirely
  rather than emitting `?cursor=`.
- **`powerHp` is `number | null` even though it is non-null in every current version.** The
  contract marks power optional, so the type says optional. Do not narrow it because today's data
  happens to be complete.
- **`powerHp` is a float.** It arrives as `110.0`, not `110`. Formatting, not the type, deals with
  that (`format/numbers.ts`).
- **`acceleration0To100S` and `version.fuelType` are the only fields that are never `null`.** Both
  are enforced upstream. Everything else in the response can be `null` and is, in practice.
- **A `404` is only meaningful for `GET /v1/variants/{id}`.** An unknown id used as a *filter*
  (`?modelId=…`) returns `200` with an empty collection, not `404`. Do not treat an empty
  collection as an error.
- **The error identity is `problem.type`, not `detail`.** `detail` is human text and may change
  wording at any time; matching on it will break.
