# 0004 — Search is the primary path; the cascading selects are secondary

## Context

The sketch treats the three selects as the main mechanism and loads an entire catalogue up front
to feed them. That catalogue does not exist here.

The backend has **no `GET /v1/versions`** and deliberately never will
(`../carsss/docs/api.md` §2). The only way to enumerate a model's versions is to page its variants
and group them client-side.

Measured:

| | variants per model |
|---|---|
| mean | 16 |
| median | 4 |
| p95 | 58 |
| max | 354 (Audi A4) |

Audi A4 costs 2 requests and 107 KB at `limit=200`. A model far above the p95 costs tens of
sequential round trips and megabytes to populate one select, and that cost grows with every
import.

`GET /v1/search` costs one request, is capped at 20 results, ranks by relevance, prefix-matches the
last token and corrects typos. Its cost does not grow with the catalogue.

## Decision

The search field is the primary path and is the first control on the screen, as drawn. The selects
remain, unchanged in appearance, as a secondary browsing path for a user who does not know what to
type.

Consequently:

- No catalogue is loaded on mount. Nothing is fetched until the user acts.
- Each select's query is `enabled` only once its parent selection exists, so opening the page costs
  zero API requests.
- The variant paging that feeds the third select is bounded, with a visible failure mode
  (ADR 0010).

## Consequences

- First paint requires no data, so the screen is interactive immediately.
- The design survives the full import without a rewrite: the primary path is unaffected by
  catalogue size, and the path that is affected is the optional one.
- A user who types nothing and only uses the selects pays 2 requests to reach a car (brands, then
  models, then variants — three, and the variant itself is free by cache seeding).
- Search quality is now load-bearing. It is good: prefix matching and typo correction are server
  side. Its weakness is that a partial import returns nothing for cars that are simply not there
  yet, which the no-results copy states plainly (`docs/ui.md`).

## Rejected alternatives

**Selects primary, as drawn.** Requires enumerating versions, which requires paging every variant
of a model. On a large model that is tens of sequential requests before the third select can open.
Keyset
paging cannot be parallelised — page *n+1* needs page *n*'s cursor — so this cannot be optimised
away.

**Load the whole catalogue once and cache it.** The catalogue is megabytes of JSON fetched over
hundreds of sequential requests before the app is usable, and it grows with every import.

**Drop the selects entirely and ship only search.** Tempting on cost, and honest about where the
API's strength lies, but it removes the only way to browse for a user who does not know a model
name, and it discards a part of the approved design for a saving that `enabled` already achieves.

**Client-side filtering of a search result set.** The search endpoint accepts no filters at all,
and 20 capped hits are not a set worth filtering.
