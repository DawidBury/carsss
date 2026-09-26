# `src/Entity` — Doctrine entities

Read this file **before** touching anything here. If you change what an entity means, update this
file in the same task.

The full design is in `docs/orm.md`; the schema itself is in `docs/database.md`; the decision that
created this directory is ADR 0018. This file only records what must not be undone.

## What this is

A typed projection of five tables: `import_run`, `brand`, `car_model`, `version`, `variant`.
Mapping and nothing else.

This is **not** a module and not a domain layer. `CLAUDE.md` §1 is unchanged: no aggregates, no
invariant enforcement in PHP, no value objects around scalars, no domain events, no repository
interfaces. Every invariant lives in the database, where 30 `CHECK` constraints and 4 triggers
enforce it against every writer, not only against this one.

## Boundaries

**Depends on:** Doctrine ORM, `symfony/uid`. **Nothing from `App\`.**

Every module may depend on `App\Entity`. `App\Entity` may depend on no module. That is why:

- no entity declares `#[ORM\Entity(repositoryClass: …)]` — the repository class would have to live
  in a module and the entity would point back at it,
- queries live where they are used: `src/Import/Writer` for the lookup by `source_ref`,
  `src/Catalog/Reader` for the single-record DQL.

Entities are **not** allowed to reach `App\Api`. `Catalog` maps them to its result DTOs, exactly as
it maps DBAL rows. An entity handed to the serializer drags lazy loading into the HTTP layer and
puts `id` and `source_ref` one property access away from the response.

## Local decisions that must not be reverted

1. **`public_id` is generated in PHP** (`Uuid::v4()`), in the constructor, `readonly`,
   `updatable: false`. The database default stays for the rows DBAL writes. Do not switch to
   `generated: 'INSERT'`: measured, it costs one extra `SELECT` per insert *and* per update, and
   leaves the identifier unknown until after `flush()`.
2. **`firstSeenRun` is `readonly` and has no setter.** This is the whole mechanism that keeps
   "first seen" from turning into "last seen". Adding a setter defeats it silently.
3. **`Brand`, `CarModel` and `ImportRun` have no setters at all.** Their rows are written by DBAL
   (ADR 0005, ADR 0018). They exist here so the foreign keys can be mapped, so
   `EntityManager::getReference()` works on the write path, and so `Catalog` can join to them.
4. **`Version::$variants` is `cascade: ['persist']`, `orphanRemoval: true`,
   `indexBy: 'sourceRef'`.** The `indexBy` is what lets the writer match the incoming page against
   the stored set by natural key. Never add `cascade: ['remove']`: deleting a version is an
   operator procedure and the database cascade does it in one statement.
5. **No inverse side is mapped unless something reads it.** `Brand` has no `models`, `ImportRun`
   has no collections. An unused collection is one accidental `count()` away from loading 1 400
   rows.
6. **Every column names itself explicitly**, and every index and unique constraint is declared with
   the same name as in the migration. The underscore naming strategy would turn
   `acceleration0To100S` into `acceleration0_to100_s`, and an index that is not declared shows up
   in the drift check as noise.
7. **Dictionary columns use `Types::TEXT` with `enumType:`**, mirroring the `CHECK` lists. That is
   the only place where a database constraint is also expressed in PHP, and it is free.
8. **The canonical value and its unmapped label are set together** — one method takes both
   (`setGearbox(?Gearbox $gearbox, ?string $label)`). The database forbids having both
   (`variant_gearbox_mapped_or_labelled`); two independent setters would let a caller reach the
   forbidden state in two legal-looking steps.

## Traps

1. **`NUMERIC` is `string` in PHP, not `float`.** `acceleration_0_100_s`, `power_hp` and
   `fuel_consumption_l_100km` are `Types::DECIMAL` and the property type is `string`. Casting to
   `float` breaks keyset pagination (`src/Catalog/CLAUDE.md` trap 1).
2. **The partial index predicate must be spelled the way PostgreSQL prints it** —
   `(status = 'running'::text)`, one pair of parentheses, with the cast. Anything else compares
   unequal and the drift check reports a drop-and-create of an index that did not change.
3. **`options: ['default' => …]` on `public_id` and `started_at` exists only so the drift check
   stays quiet.** It also means the mapping is not a legal source of DDL — `schema:create` would
   emit the default as a string literal. Those commands are forbidden anyway.
4. **A `readonly` property is hydrated once by Doctrine and refuses every later write.** That is the
   point. If you find yourself wanting to "refresh" `firstSeenRun`, you are on the wrong path — read
   ADR 0018 first.
4a. **`EntityManager::refresh()` does not work on these entities and never will.** Re-hydrating a
   `readonly` property with a different value raises `LogicException`, so `refresh()` is closed off
   by the same decision that protects `publicId` and `firstSeenRun`. This matters because
   `refresh()` is the reflex after a `23505` on a converging page ("re-read and try again"), and it
   is the wrong reflex here. **Recovery from a lost race belongs to Messenger and only to
   Messenger:** the page write is one transaction, it rolled back whole, and the retry re-runs the
   handler from the top with a clean entity manager. Do not build an in-process retry, and do not
   reach for `refresh()` to make one work.
5. **Adding a column here does not add it to the database.** Migrations are hand-written
   (`migrations/CLAUDE.md`); the mapping follows the schema, never the other way round. The drift
   test tells you when the two disagree.
6. **The `uuid` DBAL type is registered here, not in `config/packages/doctrine.yaml`.**
   `Symfony\Bridge\Doctrine\Types\UuidType` exists in `symfony/doctrine-bridge` but is never
   wired up as a named DBAL type anywhere in this project — `Doctrine\DBAL\Types\Type::hasType('uuid')`
   is `false` without it, and `type: 'uuid'` on `public_id` would fail at the first metadata load.
   `Type/UuidType.php` closes that gap with `#[AsDbalType(name: 'uuid')]` on a subclass of
   `AbstractUidType` (the Symfony class is `final`, so it cannot be extended directly). Doctrine
   Bundle auto-registers any autoconfigured service carrying that attribute, and this class is
   picked up for free by the project's blanket `App\: resource: '../src/'` service definition —
   no entry in `config/services.yaml` or `config/packages/doctrine.yaml` was needed or added.
   Verified: `doctrine:schema:validate --skip-sync` is clean and the live-schema diff is exactly
   the five `GENERATED BY DEFAULT AS IDENTITY` statements from §9, nothing about `public_id`'s
   type or a missing comment hint.
