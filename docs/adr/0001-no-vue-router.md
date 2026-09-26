# 0001 — No Vue Router

## Context

The application is one screen. Its entire navigable state is a single query parameter, `?v=`,
carrying a variant id (ADR 0006). There is no second view, no nested layout, no guard, no
redirect, and nothing to lazy-load per route (`docs/architecture.md`, code splitting).

Vue Router was left open by the project owner as a real question rather than a formality.

## Decision

No Vue Router. URL reading and writing is one composable, `app/url.ts`, built on
`URLSearchParams`, `history.pushState` and the `popstate` event.

## Consequences

- One dependency and roughly 7 KB gzipped not shipped, and no route table to keep in sync with a
  screen that has no routes.
- Back and forward buttons work, because `pushState` plus a `popstate` listener is exactly what
  the router would have used underneath.
- Deep-linking works: the composable reads `?v=` once on mount.
- If a second screen ever appears — a comparison view, or per-brand pages for search engines —
  the router is added then, and `app/url.ts` is the only file that changes. That is the trigger
  for revisiting this decision; it is a contained change, which is why deferring it is safe.

## Rejected alternatives

**Vue Router with a single route.** Pays a dependency, a router instance, a `<RouterView>` and a
route definition to obtain `pushState` — which the platform already provides. Its value is
matching many routes and splitting their code; with one route that value is zero. Adding it "so
it is there later" is precisely the pre-emptive abstraction this project treats as a defect.

**No URL state at all.** Rejected outright: the sketch specifies a shareable link, and a result
that cannot be sent to someone else fails a stated product requirement.

**Hash routing (`#v=…`).** Avoids server configuration for deep links, which is a real problem for
a static host with path-based routes — but we use a query string on a single path, which every
static host serves correctly. The hash would buy nothing and looks like a legacy URL.
