# ORM layer — entities, mapping and the write contract

This file is the only place that describes the entity mapping. The schema itself has one place and
it is `docs/database.md`; nothing about columns, indexes or constraints is justified again here.
The decision that produced this layer, and the alternatives that were rejected, are in ADR 0018.

Everything stated as measured in this document was measured on PostgreSQL 17.11 with
`doctrine/orm` 3.6.8 and `doctrine/dbal` 4.4.4 — on a throwaway database restored from the
production schema, and, for schema comparison, against the live `cars` database.

## 1. Where the entities live

```
src/Entity/                 namespace App\Entity
├── CLAUDE.md
├── ImportRun.php
├── ImportRunStatus.php
├── Brand.php
├── CarModel.php
├── Version.php
├── Variant.php
├── FuelType.php
├── Gearbox.php
└── Drive.php
```

`config/packages/doctrine.yaml` already points at this directory with the prefix `App\Entity` and
`auto_mapping: false`; that part of the configuration does not change.

`App\Entity` sits outside every module because the entities are shared — `Import` writes them,
`Catalog` reads them — and putting them in either module would create a dependency that
`docs/architecture.md` §4 forbids. The dependency rule is one line: **`App\Entity` may depend on
nothing from `App\`, and every module may depend on `App\Entity`.**

Two consequences of that rule, both load-bearing:

- No entity carries `#[ORM\Entity(repositoryClass: …)]`. A repository class would have to live in
  some module, and the entity would then point at it. Queries live where they are used: DQL for the
  single-record read in `Catalog/Reader`, the lookup by `source_ref` in `Import/Writer`.
- **Entities never leave `Catalog` and never enter `Api`.** `Catalog` maps them to its own result
  DTOs, exactly as it maps DBAL rows today. Handing an entity to the serializer would drag lazy
  loading into the HTTP layer and put `id` and `source_ref` one property access away from the
  response.

## 2. What is mapped and what is not

| Table | Mapped | Written by | Read by |
|---|---|---|---|
| `import_run` | yes | `Import`, through DBAL | nobody through ORM; referenced only |
| `brand` | yes | `Import`, through DBAL | `Catalog` (ORM, single record; DBAL, lists) |
| `car_model` | yes | `Import`, through DBAL | `Catalog` (ORM, single record; DBAL, lists) |
| `version` | yes | `Import`, through ORM | `Catalog` (ORM, single record; DBAL, lists) |
| `variant` | yes | `Import`, through ORM | `Catalog` (ORM, single record; DBAL, lists) |
| `api_key` | **no** | `Api`, through DBAL | `Api`, through DBAL |
| `autocentrum_page_cache` | **no** | adapter, through DBAL | adapter, through DBAL |

`api_key` is not mapped because nothing would use the entity: the authentication filter needs one
row by hash on every request and the console command writes one row a year. `autocentrum_page_cache`
is not mapped because it belongs to the adapter (ADR 0008) and must disappear with it; an entity for
it in the shared `App\Entity` namespace would make that impossible to see.

Both are excluded from schema comparison, together with the migrations table:

```yaml
doctrine:
    dbal:
        schema_filter: '~^(?!api_key$|autocentrum_page_cache$|doctrine_migration_versions$)~'
```

Without this filter, the drift check would propose dropping all three tables.

`ImportRun`, `Brand` and `CarModel` are **reference-only** on the write path: they are never
persisted through the ORM. The writer obtains them with `EntityManager::getReference()`, which
issues no query — measured.

## 3. What these entities are

A typed projection of a table row. Nothing else.

- A constructor that takes exactly what the table declares `NOT NULL` and cannot default.
- Setters only for the columns the import actually updates. No setter exists for a column the
  import never changes.
- No invariant enforcement, no value objects around scalars, no domain events, no repository
  interface. `CLAUDE.md` §1 is unchanged by ADR 0018: every invariant is in the database.
- `final`, `declare(strict_types=1)`, typed properties. **Not** `readonly` as a class — Doctrine
  must be able to hydrate and update — but individual properties are `readonly` where the column
  must never change.

## 4. Mapping rules that apply to every entity

| Rule | Reason |
|---|---|
| every `#[Column]` names its column explicitly | the underscore naming strategy turns `acceleration0To100S` into `acceleration0_to100_s`. Explicit names remove the whole class of question |
| every index and unique constraint is declared with the **same name as in the migration** | the comparison in §9 matches on name; an undeclared index shows up as a difference |
| primary keys: `Types::BIGINT` + `#[GeneratedValue(strategy: 'IDENTITY')]`, PHP property `?int` | DBAL 4 returns a `BIGINT` as `int` when it fits, so the property can be typed `int`. `identity_generation_preferences` in `doctrine.yaml` already pins the strategy for PostgreSQL |
| `public_id`: `type: 'uuid'`, `readonly`, `updatable: false`, `options: ['default' => 'gen_random_uuid()']` | §6 |
| `NUMERIC` columns: `Types::DECIMAL` with `precision`/`scale`, PHP type `string` | measured: values come back as `string`. Casting to `float` breaks keyset pagination (`src/Catalog/CLAUDE.md` trap 1) |
| dictionary columns: `Types::TEXT` with `enumType:` | the PHP enum mirrors the `CHECK` list, so three of the thirty checks become unreachable from PHP — at a cost, see below |
| `TIMESTAMPTZ`: `Types::DATETIMETZ_IMMUTABLE` | the schema stores time zones; mutable date objects in an entity are a bug generator |
| columns with a database default that the ORM never writes declare `options: ['default' => …]` | without it the comparison proposes `DROP DEFAULT`. The declared value is the literal introspected by DBAL (`gen_random_uuid()`, `now()`) |
| no inverse side is mapped unless something reads it | `Brand` has no `models` collection and `ImportRun` has no collections: nothing traverses those directions, and an unused collection is one accidental `count()` away from loading 1 400 rows |

### The dictionaries now have four definitions, and nothing holds them together for free

`fuel_type`, `gearbox` and `drive` are each defined in **four** places:

| Definition | Why it cannot be merged with the others |
|---|---|
| `CHECK` in the schema | the only one that binds every writer, including `psql` |
| `App\Source\Contract\*` | the port's vocabulary; `Source` must not depend on the database or on `App\Entity` |
| `App\Catalog\Criteria\*` | the query vocabulary, and the only one `Api` may touch — `Api` may depend on `Catalog`, never on `App\Entity` |
| `App\Entity\*` | the storage vocabulary, added by ADR 0018 |

The dependency rules that forbid merging them are the same rules that keep the modules apart, so
the duplication is a consequence of the architecture, not an oversight — and a shared "vocabulary"
module to hold one copy is exactly the sort of thing `CLAUDE.md` §3 was written against.

What must be understood is what the duplication costs, because ADR 0018 made it worse by one copy
and the drift check of §9 **cannot see `CHECK` constraints at all**. Adding `case Lpg` to one enum
compiles, passes static analysis, passes `doctrine:schema:validate`, passes the drift test, and
fails as `SQLSTATE 23514` on the first production page that uses it.

**The only glue is a behavioural test**, and it is not optional: read the allowed values out of
`pg_constraint` for `version_fuel_type_allowed`, `variant_gearbox_allowed` and
`variant_drive_allowed`, and assert that each of the three PHP enums has exactly those cases.
It is the cheapest possible test and it is the sole thing standing between a one-line enum change
and a production error.

## 5. The entities

Only mapping decisions are listed. Column semantics are in `docs/database.md`.

### `ImportRun`

| Property | Column | Mapping |
|---|---|---|
| `id` | `id` | identity primary key |
| `source` | `source` | `TEXT` |
| `status` | `status` | `TEXT`, `enumType: ImportRunStatus::class` |
| `startedAt` | `started_at` | `DATETIMETZ_IMMUTABLE`, `options: ['default' => 'now()']` |
| `finishedAt` | `finished_at` | `DATETIMETZ_IMMUTABLE`, nullable |

The partial unique index is declared on the class and is compared correctly — measured, including
the case where someone drops the `WHERE` clause:

```php
#[ORM\UniqueConstraint(
    name: 'import_run_one_running_per_source',
    columns: ['source'],
    options: ['where' => "(status = 'running'::text)"],
)]
```

The predicate must be written exactly as PostgreSQL prints it (`pg_get_expr`), including the
`::text` cast and the single pair of parentheses. Anything else compares unequal and the index shows
up as a spurious drop-and-create.

`ImportRun` exists so the four provenance foreign keys can be mapped. The run lifecycle stays in
DBAL (`src/Import/Run/`): two statements per run, and the status command reads aggregates.

### `Brand`, `CarModel`

| Property | Column | Mapping |
|---|---|---|
| `id` | `id` | identity primary key |
| `publicId` | `public_id` | `uuid`, readonly, `updatable: false`, default option |
| `brand` (`CarModel` only) | `brand_id` | `ManyToOne` to `Brand`, `nullable: false`, `onDelete: 'RESTRICT'` |
| `sourceRef` | `source_ref` | `TEXT`, unique |
| `name` | `name` | `TEXT` |
| `firstSeenRun` | `first_seen_run_id` | `ManyToOne` to `ImportRun`, readonly, `nullable: false`, `onDelete: 'RESTRICT'` |

Both are read-only through the ORM: no setters at all, and no constructor either — Doctrine
hydrates without calling one, and nothing else builds these objects. Their rows are created by the
DBAL upsert (ADR 0005, ADR 0018). The same is true of `ImportRun` as long as its lifecycle stays on
DBAL.

### `Version`

| Property | Column | Mapping |
|---|---|---|
| `id` | `id` | identity primary key |
| `publicId` | `public_id` | `uuid`, readonly, `updatable: false`, default option |
| `carModel` | `car_model_id` | `ManyToOne`, `nullable: false`, `onDelete: 'RESTRICT'` |
| `sourceRef` | `source_ref` | `TEXT`, unique; the natural key the writer looks up |
| `name` | `name` | `TEXT` |
| `fuelType` | `fuel_type` | `TEXT`, `enumType: FuelType::class` |
| `generation`, `bodyStyle`, `unresolvedSegment` | same | `TEXT`, nullable |
| `powerHp` | `power_hp` | `DECIMAL(5,1)`, nullable, PHP `?string` |
| `engineDisplacementCcm`, `bootCapacityL` | same | `INTEGER`, nullable |
| `firstSeenRun` | `first_seen_run_id` | `ManyToOne`, **`readonly`**, no setter |
| `lastSeenRun` | `last_seen_run_id` | `ManyToOne`, set by `markSeen()` |
| `variants` | — | `OneToMany` |

```php
#[ORM\OneToMany(
    targetEntity: Variant::class,
    mappedBy: 'version',
    cascade: ['persist'],
    orphanRemoval: true,
    indexBy: 'sourceRef',
)]
private Collection $variants;
```

`indexBy: 'sourceRef'` keys the collection by the natural key the unique index already uses, which
is what makes §8 a lookup rather than a loop. Verified: keys are the `source_ref` values, `get()`
and `containsKey()` work, and removal semantics are unaffected.

`cascade: ['persist']` and nothing else. There is deliberately **no** `cascade: ['remove']`:
deleting a version is an operator procedure (`docs/database.md` §8) and the database cascade handles
the variants in one statement, whereas Doctrine's cascade would load and delete them one by one.

The three taxonomy columns are written as they arrive from the port, in one call, so the
`version_unresolved_segment_excludes_resolved` combination is decided in one place.

### `Variant`

| Property | Column | Mapping |
|---|---|---|
| `id` | `id` | identity primary key |
| `publicId` | `public_id` | `uuid`, readonly, `updatable: false`, default option |
| `version` | `version_id` | `ManyToOne`, `inversedBy: 'variants'`, `nullable: false`, `onDelete: 'CASCADE'` |
| `sourceRef` | `source_ref` | `TEXT`, unique per version |
| `acceleration0To100S` | `acceleration_0_100_s` | `DECIMAL(4,1)`, PHP `string`, not nullable |
| `fuelConsumptionL100Km` | `fuel_consumption_l_100km` | `DECIMAL(4,1)`, nullable, PHP `?string` |
| `fuelTankL` | `fuel_tank_l` | `INTEGER`, nullable |
| `gearbox` / `gearboxUnmappedLabel` | same | `TEXT` + `enumType: Gearbox::class` / `TEXT` nullable |
| `drive` / `driveUnmappedLabel` | same | `TEXT` + `enumType: Drive::class` / `TEXT` nullable |
| `firstSeenRun` | `first_seen_run_id` | `ManyToOne`, `readonly`, no setter |

The canonical value and its unmapped label are set together by one method
(`setGearbox(?Gearbox $gearbox, ?string $unmappedLabel)`), because the database forbids having
both — `variant_gearbox_mapped_or_labelled`. Two independent setters would let a caller build the
forbidden state in two legal-looking steps.

`variant` has no `last_seen_run_id` and does not need one: §8 keeps the set complete.

## 6. `public_id`

Generated in PHP, in the entity constructor, with `Symfony\Component\Uid\Uuid::v4()` — the same
version `gen_random_uuid()` produces, so nothing distinguishes a row written by the ORM from one
written by DBAL.

Consequences, all verified:

- `flush()` needs no extra round-trip, and the identifier is known **before** the flush, so it can
  be logged next to the record that produced it.
- The column is `readonly` and `updatable: false`, so it can never appear in an `UPDATE`. Measured
  across a second import run: the `public_id` of a variant that survives is unchanged.
- The database default stays and is still used — by the `brand` and `car_model` upserts, which do
  not list the column.
- The mapping declares the default as an option purely so the comparison in §9 stays quiet. The
  price is that **no DDL derived from this mapping is executable** — the default comes out as a
  string literal, `DEFAULT 'gen_random_uuid()'`, which PostgreSQL rejects with `SQLSTATE 22P02`.
  That covers `doctrine:schema:create` and `doctrine:schema:update`, which are forbidden anyway
  (`migrations/CLAUDE.md`), but also the **repair statement the drift test itself prints**: when
  someone drops the default, §9 reports
  `ALTER TABLE version ALTER public_id SET DEFAULT 'gen_random_uuid()'`, and pasting that into
  `psql` fails. That output is a diagnosis, never a fix — the fix is a hand-written migration, as
  it is for everything else in this schema.

## 7. Provenance: `first_seen_run_id` and `last_seen_run_id`

`first_seen_run_id` must never be overwritten; `last_seen_run_id` must be written on every touch.
Three mechanisms, in order of strength:

1. **`private readonly ImportRun $firstSeenRun`.** Reassignment raises
   `Error: Cannot modify readonly property` — measured. Doctrine hydrates it once and is not
   affected.
2. **No setter.** There is no method that takes a run and writes it to `firstSeenRun`.
3. **Doctrine writes only what changed.** Measured on the update path, the statement is
   `UPDATE version SET name = ?, last_seen_run_id = ? WHERE id = ?`. `first_seen_run_id`,
   `public_id` and `car_model_id` are absent because nothing assigned them.

The old rule "`first_seen_run_id` never appears in `DO UPDATE`" (`docs/database.md` §7) is unchanged
for `brand` and `car_model`, which are still written by DBAL.

The 304 path stays on DBAL — one `UPDATE version SET last_seen_run_id = :run WHERE source_ref = :ref`
(**Q-O**). This is a deliberate, named exception to "writes to `version` go through the ORM".

The reason is **not** that the ORM would need a `SELECT` first: a bulk DQL `UPDATE` returns the
affected row count without one. It is that a bulk DQL update bypasses the unit of work and leaves
any managed entity stale, so it buys a foot-gun in exchange for nothing — on this path there is no
entity to hydrate, nothing to change-track, and the whole operation is one statement whose **row
count is the signal** ADR 0017 depends on: zero means the adapter's cache claims "unchanged" for a
version the core does not have. One line of SQL is simply the smaller thing.

## 8. Writing one page

```php
final readonly class RecordWriter
{
    public function write(int $runId, SourceRecord $record): void;
    public function markSeen(int $runId, string $ref): int;
}
```

`markSeen()` is unchanged as a statement: one DBAL `UPDATE`, returning the affected row count. What
changed with ADR 0019 is the value of `$ref` — it is the canonical reference the port hands back in
`SourceFetchResult::$ref`, never the reference that arrived in the message. Matching on the key the
row was actually written with is what gives the return value its single meaning.

`write()` in order:

1. Reject a record with no variants **before** opening anything, with the existing
   `no_usable_variants` log entry.
2. `Connection::beginTransaction()`.
3. DBAL upsert of `brand`, then `car_model` — `ON CONFLICT (source_ref) DO NOTHING RETURNING id`,
   falling back to `SELECT id … WHERE source_ref = :ref`. Unchanged from today.
4. `$em->getRepository(Version::class)->findOneBy(['sourceRef' => $record->ref])`.
   - not found: construct a `Version` with `getReference(CarModel::class, $modelId)` and
     `getReference(ImportRun::class, $runId)`, then `persist()`.
   - found: write the mutable fields and call `markSeen($runRef)`.
5. Resynchronise the variants (below).
6. `$em->flush()`.
7. `Connection::commit()`.

**Which fields a second run may change.** These are exactly the columns that appear in today's
`DO UPDATE` clauses, and they are exactly the columns that get a setter. Everything else has none.

| Entity | Mutable | Never |
|---|---|---|
| `Version` | `carModel`, `name`, `fuelType`, `generation`, `bodyStyle`, `unresolvedSegment`, `powerHp`, `engineDisplacementCcm`, `bootCapacityL`, `lastSeenRun` | `publicId`, `sourceRef`, `firstSeenRun` |
| `Variant` | `acceleration0To100S`, `fuelConsumptionL100Km`, `fuelTankL`, `gearbox` + label, `drive` + label | `publicId`, `sourceRef`, `version`, `firstSeenRun` |

`carModel` is mutable on purpose: the old upsert wrote `car_model_id = EXCLUDED.car_model_id`, so
a version whose taxonomy changed at the source moves with it. The three taxonomy columns are
written together in one call, because the database forbids one of their combinations
(`version_unresolved_segment_excludes_resolved`).

**The variant resync — the ORM form of "a complete set per page":**

```php
foreach ($version->variants() as $ref => $variant) {
    if (!isset($incoming[$ref])) {
        $version->removeVariant($variant);
    }
}

foreach ($incoming as $ref => $payload) {
    $existing = $version->variants()->get($ref);

    if ($existing === null) {
        $version->addVariant(new Variant($version, $ref, $payload->acceleration0To100S, $runRef));
        continue;
    }

    $existing->update($payload);
}
```

This is the ORM equivalent of `DELETE … WHERE version_id = :v AND source_ref <> ALL(:refs)` followed
by an upsert of the rest, and it must be written this way round.

**Never `$version->variants()->clear()` and re-add.** Measured: the unit of work orders inserts
before deletes, so re-adding a `source_ref` that is still in the table fails with `SQLSTATE 23505`
on `variant_version_id_source_ref_key`. Where it did not fail it would rotate every `public_id` on
every run.

### Why this passes the deferred trigger

Measured statement order inside one flush: `INSERT` version → `INSERT` new variants → `UPDATE`
existing rows → `DELETE` removed variants. Constraint triggers are
`DEFERRABLE INITIALLY DEFERRED`, so none of them fires before `COMMIT`; the order is therefore not
what makes this work, and a total replacement of every variant in one flush commits cleanly —
verified.

What makes it work is the **enclosing explicit transaction**. Verified, all three cases:

| Shape | Result |
|---|---|
| explicit transaction, version and variants in one `flush()` | passes |
| explicit transaction, version in one `flush()`, variants in a second | **passes** — the deferred check runs at the real `COMMIT`, not at a savepoint release |
| no explicit transaction, version in its own `flush()` | rejected — `flush()` opened and closed its own transaction, and `version … has no variant` was raised at its commit |

### Why there is exactly one transaction

`Connection::beginTransaction()` is already open when `flush()` runs, so DBAL emits
`SAVEPOINT DOCTRINE_2` / `RELEASE SAVEPOINT DOCTRINE_2` instead of a second `BEGIN` — measured, and
guaranteed by DBAL 4, where nesting transactions without savepoints is no longer supported. One
`BEGIN`, one `COMMIT`, the deferred triggers fire once.

The reverse order — `flush()` first, `beginTransaction()` after — would put the version in its own
transaction and lose the page. There is no configuration that softens this.

### Error handling

Three facts that decide the shape of the `catch` block, all measured:

1. **The invariant is reported at `COMMIT`,** as `Doctrine\DBAL\Exception\DriverException` with
   `SQLSTATE 23000` and a message carrying the version's `source_ref`, not its `id`. The `try` must
   therefore wrap the commit, not only the statements.
2. **After a failed `COMMIT` the transaction is already gone.** `isTransactionActive()` returns
   `false`, so an unconditional `rollBack()` in the `catch` throws "There is no active transaction"
   and buries the real error. The rollback must be guarded:
   `if ($connection->isTransactionActive()) { $connection->rollBack(); }`.
   The writer as it stands today rolls back unconditionally and has this defect.
3. **A failed `flush()` leaves the outer transaction alive** — DBAL rolls back to the savepoint —
   but the entity manager closed. The outer transaction still has to be rolled back explicitly.

Exceptions worth naming, by `SQLSTATE`:

| Code | Meaning here | What to do |
|---|---|---|
| `23000` at commit | a version would be left without a variant | a bug in the writer; let it fail, the message names the `source_ref` |
| `23505` on `version_source_ref_key` | another worker wrote this page first | let Messenger retry; the retry gets a 304 and takes the `markSeen` path, so it costs one conditional GET — true only because ADR 0019 persists the canonical reference, and only for the first run of a converging pair |
| `23514` | a `CHECK` was violated | log the constraint name from the message; the value came from the adapter |
| `40P01` | deadlock | retryable, unchanged from before |

### Between pages: `clear()` after success, `resetManager()` after failure

These are two different calls and confusing them produces a failure that looks like a bug somewhere
else.

**After a successful page — `clear()`.** Measured, 2 000 pages: **4.0 s** with a clear between
pages, **45.4 s** without, and the gap grows with the number of managed entities, because every
`flush()` recomputes the change set of everything the manager holds. In the worker this is done by
`DoctrineClearEntityManagerWorkerSubscriber`, which doctrine-bundle registers automatically and
which clears every manager after each handled and each failed message. It must not be disabled.

**After a failed `flush()` — `resetManager()`.** A failed flush **closes** the entity manager, and
`clear()` does not reopen it: the call succeeds and leaves the manager closed. The next page — a
perfectly valid one — then fails with `EntityManagerClosed`, an exception carrying **no
`SQLSTATE`**, pointing at nothing that is actually wrong. Only
`ManagerRegistry::resetManager()` puts a working manager back.

In the worker that happens on its own: services tagged `kernel.reset` are reset between messages,
and the registry replaces the closed manager. **Every loop that writes more than one page inside
one process — a console command, a test, a fixture loader — has to call `resetManager()` itself.**

ADR 0018 is what makes this urgent. Before it, a failed flush meant a bug; after it, a failed flush
is routine — a `23505` on a converging pair is a normal event on the first run — so the poisoned-
manager path is walked, not theoretical.

Do **not** add `messenger.middleware.doctrine_transaction` to the import bus. It would wrap the
whole handler — including the ~2.5 s HTTP fetch — in a database transaction.

## 9. Detecting drift between mapping and schema

Two checks, both cheap, both required before declaring work done.

**1. The mapping must be internally valid.**

```
php bin/console doctrine:schema:validate --skip-sync
```

Exit code 1 on any mapping error. This part can and must be clean.

**2. The difference against the real schema must equal a committed list.**

`doctrine:schema:validate` can never report "in sync" in this project, because DBAL 4.4 does not
introspect `GENERATED ALWAYS AS IDENTITY` as an identity column — verified: `BY DEFAULT` is
introspected, `ALWAYS` is not. Five statements are therefore permanently in the difference:

```
ALTER TABLE brand ALTER id ADD GENERATED BY DEFAULT AS IDENTITY
ALTER TABLE car_model ALTER id ADD GENERATED BY DEFAULT AS IDENTITY
ALTER TABLE import_run ALTER id ADD GENERATED BY DEFAULT AS IDENTITY
ALTER TABLE variant ALTER id ADD GENERATED BY DEFAULT AS IDENTITY
ALTER TABLE version ALTER id ADD GENERATED BY DEFAULT AS IDENTITY
```

The check is a test that asserts **equality** with that list, after sorting both sides:

```php
$actual = (new SchemaValidator($entityManager))->getUpdateSchemaList();
$expected = self::EXPECTED_SCHEMA_DIFFERENCE;

sort($actual);
sort($expected);

self::assertSame($expected, $actual);
```

**Sort both sides — do not write the list in a fixed order and compare positionally.** The order
`getUpdateSchemaList()` produces follows the order in which the mapping driver discovers the
entity classes, not anything about the schema: measured alphabetically here
(`brand, car_model, import_run, variant, version`) and reported in dependency order
(`import_run, brand, car_model, version, variant`) on another machine. Statement order between
independent `ALTER TABLE`s is not a schema fact, so a test that depends on it fails on day one
against a perfectly correct schema — and the developer who then "fixes" it is one step away from
weakening the assertion itself.

Sorting costs nothing in strength. Both directions are still detected: an extra statement appears
in `$actual` and fails, a missing baseline statement disappears from `$actual` and fails.
**What is forbidden is the subset form** — `self::assertSame([], array_diff($actual, $expected))`
or any `assertContains` loop — because that is what actually loses the disappearing-line case, and
the disappearing line is how a weakened primary key shows up (below).

Equality, not "contains". A drift can also make a baseline statement *disappear*: a migration that
turned one of the primary keys into `GENERATED BY DEFAULT AS IDENTITY` — which DBAL does introspect,
and which lets a writer supply its own key — removes that table's line from the list and a subset
assertion would call it a pass. The test runs against the test database after
`doctrine:migrations:migrate`, so it compares the mapping with what the migrations actually produce,
not with a schema built from the mapping.

Changing the list is allowed and is the point: a schema change that the mapping does not follow
fails the test until someone decides which of the two is wrong.

### What this detects — measured, one mutation at a time

| Mutation applied to the database | Detected |
|---|---|
| unique index dropped | yes |
| partial unique index dropped | yes |
| partial index turned into a total one (`WHERE` removed) | yes |
| column added that the mapping does not know | yes |
| `NOT NULL` dropped | yes |
| column type changed (`INTEGER` → `BIGINT`) | yes |
| foreign key `ON DELETE` changed (`CASCADE` → `RESTRICT`) | yes |
| column default dropped (`gen_random_uuid()`) | yes |
| foreign key dropped | yes |
| index added that the mapping does not declare | yes (as a `DROP INDEX` in the list) |
| `GENERATED ALWAYS` weakened to `GENERATED BY DEFAULT` | yes (a baseline statement disappears) |
| **`CHECK` constraint dropped** | **no** |
| **constraint trigger dropped** | **no** |
| **PL/pgSQL function dropped or altered** | **no** |
| **anything in `api_key` or `autocentrum_page_cache`** | **no** (excluded by `schema_filter`) |
| **foreign key renamed** | **no** (names are not compared) |

So the blind spots are exactly the parts of the schema that carry the invariants: 30 `CHECK`
constraints, 4 triggers, 4 functions. These are covered by the behavioural tests recorded in
`docs/database.md` §12 — a version without a variant must be rejected at `COMMIT`, an unmapped
gearbox label must not fail the import, `TRUNCATE variant` must be refused — and nothing about ORM
changes that. **A migration that silently drops a trigger passes the drift check.** Anyone touching
a trigger or a check has to have the corresponding behavioural test.

## 10. Reading

Lists stay on DBAL, unchanged: filters, keyset pagination and the measured plans in
`docs/database.md` §6 are not improved by hydrating objects.

The single record by public identifier (**Q-J**) goes through the ORM as one DQL query with join
fetch:

```
SELECT va, ve, m, b
FROM App\Entity\Variant va
JOIN va.version ve
JOIN ve.carModel m
JOIN m.brand b
WHERE va.publicId = :publicId
```

Measured: one statement. Walking the same chain lazily from `findOneBy(['publicId' => …])` costs
four. Reading a run identifier off an unloaded `ImportRun` reference costs nothing — the identifier
is already known — so the provenance associations never surprise a reader with a query.

`Catalog` maps the entity to its existing result DTO. The DTO does not change, `id` and `source_ref`
still do not leave the module, and `Api` never sees an entity.

## 11. Rules that must not be broken

1. One page is one explicit transaction; `flush()` runs inside it, never before it.
2. Guard `rollBack()` with `isTransactionActive()` — a failed commit has already ended the
   transaction.
2a. `clear()` after a successful page, `resetManager()` after a failed one. `clear()` on a closed
   manager succeeds and leaves it closed.
2b. Nothing that reaches the database uses the reference from the queue message. Records are
   written, stamped and logged under `SourceFetchResult::$ref` (ADR 0019).
3. Never `clear()` the variants collection and re-add them.
4. `firstSeenRun` is `readonly` and has no setter. Do not add one.
5. `public_id` is generated in PHP, once, in the constructor.
6. Entities do not leave `Catalog` and do not enter `Api`.
7. The entity manager is cleared between pages.
8. No `cascade: ['remove']`, no `cascade: ['all']`, no unused inverse sides.
9. Every index in the schema is declared in the mapping with the same name, or the drift check
   turns it into noise.
10. `doctrine:migrations:diff`, `doctrine:schema:update` and `doctrine:schema:create` remain
    forbidden as sources of committed change.
