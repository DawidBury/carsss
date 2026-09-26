# ADR 0017 — Forced re-fetch when the adapter cache drifts from the core

Status: accepted · Date: 2026-09-02

> Written in English under the rule that new documentation is English. Neighbouring ADRs are
> Polish and are deliberately not translated.

## Context

The adapter keeps its own conditional-GET validators in `autocentrum_page_cache`; the core keeps
version rows. These are two independent stores and nothing keeps them consistent.

The `Unchanged` path did a single statement:

```sql
UPDATE version SET last_seen_run_id = :run WHERE source_ref = :ref;
```

If the row is missing, this is `UPDATE 0` — no error, no log, no retry. The record is then
unreachable **forever**, because every later run gets `304` again from the surviving validator.

The sequence is not hypothetical; the design permits every step of it:

1. A run dies halfway. The operator closes it with `app:import:finish <id> --failed`.
2. The operator runs the stale-row purge (`docs/database.md` §8). Version rows disappear.
3. The next run fetches, gets `304` because the `ETag` survived in the adapter's cache.
4. `UPDATE 0`. The versions never come back, at any number of runs.

The core cannot fix this on its own: the port had no way to say *fetch unconditionally*.
`SourceFetchResult` can report `Unchanged`, but nothing could refuse it.

## Decision

**The writer verifies, and the port gains a fetch mode.**

```php
public function fetchRecord(string $ref, FetchMode $mode): SourceFetchResult;

enum FetchMode { case Incremental; case Full; }
```

Contract rule, binding on every adapter: **under `FetchMode::Full` the adapter must not return
`Unchanged`.** It must return `Record` or `Absent`. An adapter with no change-detection concept
ignores the parameter and satisfies the rule for free.

`RecordWriter::markSeen(int $runId, string $ref): int` returns the affected row count. The
handler treats `0` as the drift signal:

```
result = fetchRecord(ref, FetchMode::Incremental)

if result is Unchanged:
    if writer.markSeen(runId, ref) === 0:
        result = fetchRecord(ref, FetchMode::Full)

handle result   // Record → write, Absent → log
```

There is no default value for `$mode`. Both call sites state their intent.

## Consequences

- The failure is self-healing regardless of cause — purge, hand-deleted row, restored backup,
  or a transaction that rolled back after the adapter had already cached the validator.
- Cost on a healthy run: **zero**. `markSeen` is the `UPDATE` that had to happen anyway, and
  the second fetch only ever runs when a row is genuinely missing.

  This was not true as written until ADR 0019 (2026-09-03). `markSeen` was being called with the
  reference from the queue message — the sitemap address — while the row had been written under
  the canonical form the adapter derived after fetching. Measured: they differ for **6.2%** of
  pages, so the mechanism above fired on every healthy run for ~1 700 of 27 384 pages instead of
  never. The mechanism in this ADR was never at fault and is unchanged; it was being fed a key
  that could not match. ADR 0019 persists the mapping in the adapter and makes this sentence
  true.
- The change token still never crosses the port (ADR 0008 rule 2). `FetchMode` says *what we
  want*, not *how the adapter detects change* — an adapter using `If-Modified-Since`, a vendor
  `updated_since` filter, or nothing at all all satisfy the same contract.
- Swapping the source still costs a new adapter directory plus one config line. `FetchMode` is
  part of the port, so a new adapter implements it once, in its own file.
- One more state the adapter must be able to express, and one more branch in the handler.

## Rejected alternatives

**Make the purge truncate the adapter cache.** One documented step in `docs/database.md` §8 and
no code change. Rejected as the *primary* fix: it couples a core operator procedure to a
specific adapter's private table, and it only covers drift caused by that one procedure —
a hand-deleted row or a restored backup still produce the permanent silence. It is kept as a
**recommended optimisation** so the drift usually never happens, not as the guarantee.

**Let `markSeen` insert the version when missing.** Impossible: the 304 response carries no
body, so there is nothing to insert. It would also violate the `NOT NULL` invariants on
`fuel_type` and acceleration.

**Treat `UPDATE 0` as an error and let Messenger retry.** The retry re-runs the same
`Incremental` fetch and gets `304` again. It converts a silent failure into a loud one that
still never converges, and burns the retry budget.

**Drop conditional GET entirely.** Removes the drift by removing the cache, at the cost of
re-downloading 27 384 pages every run — the etiquette rule makes that ~3.8 h every time instead
of only on the first run.

**Give the core a `last_fetched_token` column so it can invalidate the adapter's cache.** Puts
an HTTP concept into the core schema — exactly the leak ADR 0008 rule 2 forbids, and the defect
a previous review raised against the earlier design.
