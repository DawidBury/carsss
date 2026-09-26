# ADR 0018 — Doctrine ORM with entities, DBAL where it earns its place

Status: accepted (2026-09-03). Replaces the earlier ruling "Doctrine as DBAL only, no entities"
recorded in `CLAUDE.md` §9 and in `docs/database.md` §11.

## Context

The project owner changed a binding decision. Until now the project had no entities and no ORM:
every statement was hand-written SQL executed through DBAL. The new ruling is that the schema is
mapped with ORM entities, that `version` and `variant` are written through the ORM, and that DBAL
stays where the query shape earns it.

Nothing about the database changed with this decision, and nothing may. The schema is still the
source of truth. It carries things no ORM mapping can express:

| In the database | Count |
|---|---|
| `CHECK` constraints | 30 (27 on the mapped tables) |
| constraint triggers, `DEFERRABLE INITIALLY DEFERRED` | 3 |
| `TRUNCATE` trigger | 1 |
| PL/pgSQL functions | 4 |
| partial unique index | 1 |
| `GENERATED ALWAYS AS IDENTITY` primary keys | 5 |

The write path is also unusual for an ORM: one source page is one transaction, the invariant
"a version has at least one variant" is checked by a deferred trigger at `COMMIT`, and variants are
written as a complete set per page.

The decision below was taken **after** measuring every claim in it against PostgreSQL 17.11 —
first on a throwaway database restored from the production schema, then, for schema comparison,
against the live `cars` database. The numbers are in `docs/orm.md`.

## Decision

### 1. The split

| Layer | Tool |
|---|---|
| entities and schema mapping | ORM |
| writing `version` and `variant` | ORM |
| upsert of `brand` and `car_model` | DBAL, `ON CONFLICT (source_ref) DO NOTHING` |
| stamping `last_seen_run_id` on the unchanged (HTTP 304) path | DBAL, one `UPDATE` |
| reading one record by public identifier | ORM, one DQL query with join fetch |
| filtered, keyset-paginated lists | DBAL |
| `import_run` lifecycle and the status command | DBAL |
| migrations | hand-written; `diff` for inspection only, never committed |

### 2. Entities live in `src/Entity`, outside every module

`App\Entity` is shared: `Import` writes it, `Catalog` reads it. It depends on nothing from `App\`
and every module may depend on it. It is a directory named after the role of the files in it,
not a fifth module — it holds mapping and no behaviour.

Five tables are mapped: `import_run`, `brand`, `car_model`, `version`, `variant`. `api_key` and
`autocentrum_page_cache` are not, and are excluded from schema comparison by
`doctrine.dbal.schema_filter`.

### 3. These entities are not domain objects

The ban on DDD from `CLAUDE.md` §1 stands unchanged. The entity classes are a typed projection of
table rows: a constructor that fills what is `NOT NULL`, and setters only for the columns the
import actually updates. They enforce no invariants, wrap no scalars in value objects, publish no
events, and no repository interface is declared for them anywhere. Every invariant stays in the
database, exactly as `docs/database.md` rule 1 requires.

### 4. One page is one explicit transaction; `flush()` runs inside it

`Connection::beginTransaction()` is opened by the writer, the DBAL upserts and `EntityManager::flush()`
both run inside it, and `Connection::commit()` closes it. Measured: DBAL 4 always nests with
savepoints — nesting without them is no longer supported — so the flush emits
`SAVEPOINT DOCTRINE_2` / `RELEASE SAVEPOINT DOCTRINE_2` and no second transaction is opened. The
deferred trigger therefore fires exactly once, at the real `COMMIT`.

### 5. `public_id` is generated in PHP

`Uuid::v4()` in the entity constructor, mapped `readonly` and `updatable: false`. The column keeps
its `DEFAULT gen_random_uuid()` for the rows DBAL writes, and the mapping declares that default so
schema comparison stays quiet.

### 6. Provenance columns are associations; `firstSeenRun` is `readonly`

`first_seen_run_id` and `last_seen_run_id` are mapped as `ManyToOne` to `ImportRun`.
`Version::$firstSeenRun` is a PHP `readonly` property with no setter: reassignment is refused by the
language, and Doctrine only ever writes changed columns, so `first_seen_run_id` cannot appear in an
`UPDATE`.

### 7. Variants are resynchronised, never cleared and re-inserted

The collection is mapped `indexBy: 'sourceRef'`, `orphanRemoval: true`, `cascade: ['persist']`.
The writer updates the variants the page still carries, adds the new ones, and removes the rest.

### 8. The 30 `CHECK` constraints are not mirrored in PHP

Not one of them is duplicated. The mapping already covers what is worth covering, for free: PHP
enums for the three dictionaries, non-nullable typed properties for `NOT NULL`. The single
pre-transaction check that exists today — rejecting a record with no usable variant — stays,
because it answers a question the database cannot: "the source published nothing" versus "the
writer has a bug".

### 9. Mapping drift is detected by a test, not by reading

`doctrine:schema:validate --skip-sync` must pass, and a test compares
`SchemaValidator::getUpdateSchemaList()` against a committed list of exactly five known-acceptable
statements. The list is compared by equality, so drift is caught in both directions.

## Why `brand` and `car_model` stay on DBAL

This is the one place where the shape of the ORM write is not merely slower but wrong.

`brand` has ~91 rows and `car_model` ~1 400, and every one of the 27 384 pages of a run touches one
of each. They are the only hot, shared rows in the schema; everything else a page writes belongs to
that page alone.

The ORM's only way to write a row it may or may not own is `SELECT` by natural key, then `INSERT`
if nothing came back. That is a check-then-act across two statements with no lock in between. Under
four workers two transactions read "no such brand" and both insert; one of them gets
`SQLSTATE 23505` and its entire page — brand, model, version and every variant — is rolled back.
The failure rate is a function of how often two pages of the same brand are handled at the same
moment, which for 91 brands spread over 27 384 pages is often.

`ON CONFLICT (source_ref) DO NOTHING` has no such window: the conflict is resolved inside one
statement, and — unlike `DO UPDATE` — it takes no lock on the conflicting row, which is the reason
ADR 0005 chose it in the first place. Moving these two tables to the ORM would reintroduce, as a
lost page, precisely the contention ADR 0005 was written to remove.

The cost of keeping them on DBAL is one already-accepted consequence, unchanged from ADR 0005: a
brand renamed at the source is not picked up automatically.

`version` and `variant` do not have this property. A version belongs to one page, so the ORM's
`SELECT`-then-`INSERT` is only exposed when two messages carry the same page — which does happen
(280 leaves in `cars.xml` converge after a 301, `src/Import/CLAUDE.md`) and is handled by a
Messenger retry rather than by a lock. That is a real regression against the old
`ON CONFLICT DO UPDATE`, it is named in `docs/orm.md`, and it is the price of the decision.

## Consequences

**Good**

- The schema now has a machine-checked description in PHP. Nine of ten schema mistakes a migration
  could make are caught by a test — measured, list in `docs/orm.md`.
- The write path stops being string SQL. `first_seen_run_id` can no longer be overwritten, because
  the property is `readonly`; before, it was protected only by the reviewer noticing it in a
  `DO UPDATE` clause.
- The single-record read is one query instead of one hand-written join, and the DTO shape does not
  change.

**Bad, and accepted**

- A concurrent write of the same version now raises `UniqueConstraintViolationException` instead of
  waiting for a lock. The message is retried, and the retry converges — it finds the winner's row
  and takes the update path. The retry costs one conditional GET, but only because ADR 0019
  persists the canonical reference: as first written, this ADR claimed that cost for pages where
  `markSeen` could not match at all, so the true cost was an unconditional fetch plus a full page
  write. The race is also confined to the first run of a converging pair; afterwards both
  addresses find the row.
- Schema comparison can never report "in sync": DBAL 4.4 does not introspect
  `GENERATED ALWAYS AS IDENTITY` as an identity column, so five statements are permanently in the
  diff. `doctrine:schema:validate` will always exit non-zero on the sync check. This is why the
  test compares against a golden list rather than reading the exit code.
- The unit of work must be cleared between pages. Measured on 2 000 pages: 4.0 s with a clear
  between pages, 45.4 s without, and the gap widens with the number of managed entities. In the
  worker this is done for us by `DoctrineClearEntityManagerWorkerSubscriber`, registered by
  doctrine-bundle. Disabling it turns the import quadratic.
- The mapping is no longer a legal source of DDL: the declared `default` options are literal
  strings, so `doctrine:schema:create` would emit `DEFAULT 'gen_random_uuid()'`. The commands that
  could act on it were already forbidden; the ban now also protects correctness, not just triggers.

**Neutral**

- Throughput is unchanged in any way that matters. Measured over 500 pages, twice, on different
  machines:

  | | DBAL insert | DBAL update | ORM insert | ORM update | ratio |
  |---|---|---|---|---|---|
  | first run | 1.57 ms | 1.32 ms | 1.90 ms | 1.45 ms | 1.1–1.2× |
  | second run | 1.02 ms | 0.84 ms | 1.61 ms | 1.20 ms | 1.4–1.6× |

  The ratio is not stable across machines, so quoting a single multiplier would be false
  precision; take **1.1–1.6×** as the measured range. The conclusion survives either end of it:
  the whole page write is ~2 ms against ~2.5 s of HTTP and parsing, so the ORM costs on the order
  of 0.03% of the time it takes to handle a page.

## Rejected alternatives

**Everything through the ORM, including `brand` and `car_model`.** Rejected for the reason above:
it converts a contention problem that has been solved into a lost page under concurrency.

**Entities per module, duplicated in `Import` and `Catalog`.** Two mappings for one table cannot
coexist in one entity manager, and two entity managers to make them coexist would double every
schema change and halve the value of the drift test. Rejected.

**Entities inside `Catalog` (or inside `Import`), used by the other.** Either choice creates a
dependency that `docs/architecture.md` §4 forbids: `Import → Catalog` or `Catalog → Import`.
Rejected.

**A fifth module for persistence.** A module is a unit of behaviour named after what it does.
This directory does nothing; naming it a module invites putting logic in it, which is how the
previous attempt grew the layers that were rejected. Rejected.

**`public_id` generated by the database via `#[Column(generated: 'INSERT')]`.** Measured: Doctrine
issues an extra `SELECT public_id FROM … WHERE id = ?` after every insert **and** after every
update of the row, roughly 55 000 extra round-trips per run, in exchange for nothing. It also
leaves the identifier unknown until after `flush()`, so it cannot be logged with the record that
produced it. Rejected.

**Mapping `first_seen_run_id` and `last_seen_run_id` as plain `BIGINT` columns with
`updatable: false`.** It works, and `updatable: false` is a genuine mapping-level guard. But
without the association the mapping has no foreign key, and schema comparison then wants to drop
all five foreign keys to `import_run` — ten statements of permanent noise in which a genuinely
dropped foreign key would be invisible. Measured: with associations the diff is 5 statements, with
scalars 15. `readonly` gives the same guarantee at the PHP level. Rejected.

**Rewriting the variant set as `clear()` then re-add.** It is the literal translation of today's
`DELETE … WHERE source_ref <> ALL(:refs)` and it does not work. Measured: the unit of work orders
inserts before deletes, so re-adding a `source_ref` that is still in the table fails with
`SQLSTATE 23505` on `variant_version_id_source_ref_key`. Even where it did not fail it would be
wrong: every variant would be re-inserted and every `public_id` would rotate on every run.
Rejected.

**Generating migrations with `doctrine:migrations:diff`.** The mapping does not express the 30
checks, 4 triggers, 4 functions or the identity columns, so a generated migration proposes dropping
what it cannot see. `diff` may be run to look at a difference and must never produce a committed
file. Unchanged from before; the reason has changed from "there is no metadata" to "the metadata is
incomplete by construction".

**Mirroring the 30 `CHECK` constraints as PHP validation.** Twenty-seven of them exist so that a
program error cannot silently write nonsense; their violation is a bug, not a data condition
(`docs/database.md` rule 4). Reimplementing them in PHP would double the definition of every rule,
and the second copy would drift. The database already names the violated constraint in its error
message, which is as good as anything PHP would print. Rejected in favour of logging the constraint
name from `SQLSTATE 23514`.

**Teaching DBAL to introspect `GENERATED ALWAYS AS IDENTITY` with a custom schema manager.**
It would remove the five-statement baseline. It is also a private patch on a library's
introspection layer, maintained by us, to make a test print a nicer number. The golden list costs
five lines. Rejected.

**Changing the schema to `GENERATED BY DEFAULT AS IDENTITY` so DBAL recognises it.** Measured: DBAL
4.4 does introspect `BY DEFAULT` correctly, so this would work. It also lets any writer supply an
explicit primary key value, which is exactly what `GENERATED ALWAYS` exists to prevent, and it would
mean changing the database to suit the mapping. The schema is the source of truth. Rejected.
