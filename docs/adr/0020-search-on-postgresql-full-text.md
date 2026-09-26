# ADR 0020 — Search served by PostgreSQL, not by a separate engine

Status: accepted · Date: 2026-09-04

## Context

A new requirement: the frontend offers a type-ahead box. The user types a phrase, the backend
returns the top few matches, the user picks one and the frontend fetches the full record for it.

The project owner proposed Elasticsearch, expecting PostgreSQL to be too slow. That expectation
deserved a measurement rather than an opinion, because the scale here is small and known
(ADR 0014): `brand` ~91, `car_model` ~1 400, `version` ~22 800, `variant` ~32 000, one write
per day.

Two questions had to be settled before choosing an engine:

1. **What is a suggestion?** The row shows brand, model, version and variant, so the unit is the
   **variant** — the only level at which all four are determined. This also means step two of the
   flow needs no new code: `GET /v1/variants/{id}` already returns the full record.
2. **Does the search have to tolerate typos?** Yes — decided by the owner.

## Measurements

Taken on live PostgreSQL 17.11. Target-scale figures come from a 26 221-row replica of
`variant_search` and a 12 202-term replica of `search_term`, both carrying the production
indexes, `ANALYZE`d.

| Approach | Top-5 latency |
|---|---|
| trigram `word_similarity` over the whole label + GIN | **45–54 ms** |
| trigram over a shortened label | 45 ms |
| **`tsvector` + prefix on the last token + GIN** | **3,9 ms** |
| token correction, `similarity() >= 0.3` alone | 39,4 ms |
| **token correction, `%` operator + explicit threshold** | **1,2 ms** |
| `REFRESH MATERIALIZED VIEW CONCURRENTLY` | 261 ms, once per import run |

The whole search index is 2,3 MB. It fits in `shared_buffers` many times over.

Elasticsearch on this data would answer in roughly 1–3 ms. The difference against 3,9 ms is
invisible next to a browser round trip of 20–80 ms.

## Decision

Search is served by PostgreSQL full-text search.

- A materialized view **`variant_search`** joins the four taxonomy tables and stores one
  `tsvector` per variant, built from brand, model, generation, body style, unresolved segment
  and version name, with the `simple` dictionary.
- A materialized view **`search_term`** holds the distinct words of those same names, three
  characters or longer, indexed with `gin_trgm_ops`.
- The happy path is one indexed `tsvector` match with a prefix on the last token only.
- When it returns fewer rows than asked for, tokens that are **not a prefix of any known word**
  are corrected against `search_term`, and the query runs once more. Exact hits keep priority
  over corrected ones.
- Both views are refreshed by `app:search:refresh`, a console command owned by `Catalog`.

`simple` is used deliberately instead of `polish`: the labels are proper nouns and engine
designations such as `2.0 TDI 140KM`, which a stemmer would damage.

The correction query pins `pg_trgm.similarity_threshold` per call and *also* carries an explicit
`similarity() >= 0.3` predicate. The operator makes the GIN index usable; the predicate makes
the result independent of server configuration. Dropping either one costs 32× or determinism.

## Consequences

- No second datastore, no synchronization path, no drift detection, no extra failure mode where
  the API is up and search is down.
- The search index is **stale between import runs**. This is correct here: the source is scraped
  once a day and the taxonomy does not change in between.
- `app:search:refresh` is a **separate step in the import runbook**. `Import` must not call it —
  `Import → Catalog` is a forbidden dependency (`docs/architecture.md` §2). The order is
  enforced by the runbook, not by code.
- The read path is one class, `Catalog\Reader\SearchReader`, with one method. Replacing
  PostgreSQL with a search engine later changes that class and nothing else; the HTTP contract
  does not move.

## Revision threshold

Stated, so this stays a decision rather than a preference. Revisit when any of these holds:

1. the median of `/v1/search` in `pg_stat_statements` exceeds **50 ms** (the ADR 0014 threshold),
2. `variant` grows by an order of magnitude (~1 million rows),
3. synonyms, "did you mean", per-field boosting or faceting are requested.

The third is the likeliest, and it is the point at which PostgreSQL stops being the cheaper
answer rather than merely the slower one.

## Rejected alternatives

**Elasticsearch now.** It wins nothing measurable at this scale and costs a JVM container in
every local stack, the first managed service in an `infra/` that currently holds no Terraform
at all, a second source of truth with no shared transaction, and drift detection this project
already paid for once (ADR 0017). It also collides with `CLAUDE.md` §3. Decisive argument:
starting on PostgreSQL keeps the move to Elasticsearch cheap, while starting on Elasticsearch
makes the way back expensive. At equal measurements, the reversible option wins.

**Trigram similarity over the whole label.** One uniform path, best typo tolerance, and 45–54 ms
per keystroke — measured, not estimated. The cost is the `word_similarity` recheck over
thousands of candidates.

**A `tsvector` column on `variant`, written by the import writer.** Avoids the refresh step, but
`Import` would take on a search responsibility, the column needs an ORM mapping for a type DBAL
does not carry, and `docs/orm.md` §9 drift detection would have to absorb it. The materialized
view keeps search entirely on the read side.

**Computing the `tsvector` at query time over the join.** No extra object to refresh and no
staleness, but no index either — every keystroke scans 22 800 versions.

**Cursor pagination for search results.** Keyset pagination needs a stable total order.
Relevance rank is not stable across queries, and nobody pages through type-ahead suggestions.
The endpoint returns `data` only, with `limit` capped at 20.
