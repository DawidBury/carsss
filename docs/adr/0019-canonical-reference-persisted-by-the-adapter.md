# ADR 0019 — The adapter persists the sitemap-address → canonical-reference mapping

Status: accepted (2026-09-03). Amends ADR 0008 (port contract) and repairs a promise made by
ADR 0017 and a cost claim made by ADR 0018.

## Context

`SourceCatalog` promises opaque record references. In practice the adapter answers a question
about one reference with a record carrying a different one:

- `listRecords()` yields the addresses in `cars.xml`,
- `fetchRecord($ref, …)` returns a `SourceRecord` whose `ref` is the canonical URL taken from
  `link[rel=canonical]` **after** the fetch (`PageParser`), because part of the sitemap 301s onto
  a canonical form (`docs/source-contract.md` §6.5).

`RecordWriter::write()` stores the canonical form in `version.source_ref`. But
`ImportRecordHandler::handleUnchanged()` calls `markSeen($runId, $message->ref)` — the sitemap
address. For every page whose two forms differ, that `UPDATE` matches nothing, on every run,
forever.

Measured on the live `cars` database (interrupted run, 1 388 versions) against the 34 969 `<loc>`
entries of `cars.xml`: **86 stored references, 6.2%, do not appear in the sitemap at all.** The
divergence is wider than the `limuzyna` / `limousine` case that ADR 0008 anticipated:

```
sitemap:  …/acura/tl/iv/silnik-benzynowy-3.5-280km-od-2008/
stored:   …/acura/tl/iv/silnik-benzynowy-3.5-280km-2008-2011/

sitemap:  …/alfa-romeo/tonale/silnik-hybrydowy-1.5-t4-hybrid-130km-od-2022/
stored:   …/alfa-romeo/tonale/suv/silnik-hybrydowy-1.5-t4-hybrid-130km-od-2022/
```

Date ranges are rewritten and body segments are inserted — the second example even changes the path
depth, which is the input to the taxonomy rule of ADR 0003. The parser is not affected by that,
because everything it derives comes from the canonical URL; only the core is told about the page
under the wrong name.

Two consequences, both of which invalidate claims other documents rely on:

1. **ADR 0017 fires on healthy pages.** `markSeen` returns zero, ADR 0017 reads zero as cache
   drift and forces `FetchMode::Full`. At target scale that is ~1 700 of 27 384 pages fetched
   unconditionally on **every** incremental run, against ADR 0017's stated "cost on a healthy run:
   zero".
2. **ADR 0018's retry cost was wrong exactly where it mattered.** The `SQLSTATE 23505` race on
   `version_source_ref_key` can only happen when two sitemap addresses converge on one canonical
   form — which is precisely the set of pages where `markSeen` returns zero. So the retry did not
   cost "one conditional GET"; it cost an unconditional fetch and a full page write.

## Decision

**1. The port never answers about a record under a name other than the record's own.**

```php
final readonly class SourceFetchResult
{
    public SourceRecordState $state;
    public ?SourceRecord $record;
    public ?string $ref;

    public static function record(SourceRecord $record): self;   // ref = $record->ref
    public static function unchanged(string $ref): self;
    public static function absent(): self;                       // ref = null
}
```

`Unchanged` now carries the reference under which the record is known to the core. `Absent` carries
none, because there is no record to name.

**2. A rule for the core, checkable by reading one file.** The reference that arrives in the
message is only ever handed back to the port. Anything that reaches the database — writing a
record, stamping `last_seen_run_id`, logging a skipped page — uses `$result->ref`.

**3. The adapter persists the mapping in its own table**, because resolving one source address to
another is exactly what an adapter is for:

```sql
ALTER TABLE autocentrum_page_cache ADD COLUMN canonical_ref TEXT NOT NULL;
```

Written in the same statement as the ETag, read in the same `SELECT` — one read and one write per
page, unchanged from today (**Q-Q**).

**4. The cache row is written after a successful parse, not before the parse.** The canonical
reference does not exist until the page has been parsed, so `PageCache::store()` moves out of
`AutocentrumHttpClient::get()` and into `AutocentrumCatalog::fetchRecord()`, which calls it with
the ETag and `$record->ref` together.

**5. One migration** (`Version20260903100000`): empty the table, add the column `NOT NULL`, add the
blank check.

### What happens on the first fetch, when the mapping does not exist yet

Nothing special, and that is the point of the design rather than a gap in it. A conditional GET
requires a cache row; a cache row is only written after a successful parse; a successful parse
produces the canonical reference. "Unchanged with an unknown canonical reference" is therefore
unreachable, and `NOT NULL` makes it unrepresentable — verified: an insert of an ETag without a
canonical reference is rejected by the database.

The first fetch of a page is an unconditional GET that stores both values. Every later fetch has
both available before the request is sent.

### Does `markSeen` still make sense as a blind `UPDATE`

Yes, and more than before. The key it matches on is now the same key the row was written with, so
its return value regains a single meaning: zero means the version genuinely is not in the core
(ADR 0017's drift), and nothing else. It stays one statement with no `SELECT`, and the row count
stays the signal.

### A second defect that the same change removes

Today the ETag is stored **before** the page is parsed. A page that fails to parse
(`unknown_fuel_token`, no canonical link, mismatched variant blocks) therefore keeps a validator it
never earned: the next run gets a 304, the core has no such version, and ADR 0017 forces a full
refetch — every run, forever. After this change such a page stores nothing and is fetched normally
next time.

## Consequences

- ADR 0017's mechanism is unchanged and its promise becomes true: on a healthy run the forced
  refetch does not fire.
- ADR 0018's retry cost becomes what it claimed: the losing worker of a 23505 race retries, gets a
  304, and takes the `markSeen` path. This is true **because** the mapping is persisted; without
  this ADR the sentence was false.
- The 23505 race is confined to the first run of a converging pair. Once the version row exists,
  both addresses take the `Unchanged` path on later runs, and if the page changed, both take the
  `UPDATE` path and serialise on a row lock instead of failing.
- `canonical_ref` is deliberately **not** unique: two sitemap addresses converging on one canonical
  form is the phenomenon being recorded, not an error. Verified.
- The migration costs one run of unconditional fetches for pages already cached. Measured now:
  `autocentrum_page_cache` holds 0 rows, so the cost today is zero. Emptying that table is the
  documented way to force a full fetch anyway (`docs/database.md` §5).
- The port gains one parameter. A source that has no aliasing problem passes back the reference it
  was given — one line in the adapter. The swap-the-source list in `docs/architecture.md` §7 does
  not grow.

## Rejected alternatives

**Store the sitemap address in the core, next to `version.source_ref`.** It would work and needs no
adapter state. It also puts a column shaped by one particular source — a *sitemap* — into the core
schema, and ADR 0008's control test ("no name from the HTTP or HTML domain appears in the port
signatures or in the taxonomy tables") stops passing. The core is supposed to know exactly one
reference per record. Rejected.

**Make `listRecords()` yield canonical references.** Canonicalisation is only known after fetching
the page, so building that list means fetching all 27 384 pages first. Rejected.

**Key the cache by the canonical reference.** The lookup happens before the request, when the only
thing known is the sitemap address. Rejected.

**Nullable `canonical_ref`, with "do not send `If-None-Match` when it is NULL".** Self-healing and
needs no data loss in the migration. It also leaves a nullable column and a permanent branch in the
client for a state that can only arise from one migration, and it makes "ETag without canonical
reference" representable — the exact state the design wants to forbid. Rejected.

**Leave it and let ADR 0017 absorb it.** It is not incorrect: the forced refetch does repair the
page. But it costs ~1 700 unconditional fetches per run forever, it makes ADR 0017's signal fire
constantly on healthy pages so that genuine drift is invisible in the noise, and it leaves ADR
0018's retry three times more expensive than stated. Rejected.
