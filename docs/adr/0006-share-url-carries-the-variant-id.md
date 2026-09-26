# 0006 — The share URL carries the variant id and nothing else

## Context

The sketch shares results as `?marka=bmw&model=e91&wersja=325xi-at` — three human-readable slugs
from its mock, whose ids were slugs by construction.

This API has no slugs. Every id is an opaque UUID, and there is no resolve-by-name endpoint. The
backend is read-only and not ours to extend, so one cannot be added.

`GET /v1/variants/{id}` returns the variant with its version, model and brand nested inside it, so
a variant id alone is sufficient to restore the entire result in one request.

## Decision

`?v=<variantId>`. One parameter, one opaque UUID.

On mount the value is read once and, if present, drives `useVariant(id)`. Selecting a variant
writes it with `history.pushState`. Clearing the field writes the bare path.

## Consequences

- A shared link restores the complete result with **one** request.
- The URL is opaque and not human-editable. That is an honest reflection of the API: there is no
  stable, meaningful name for a variant, since two variants of one version differ only by gearbox
  and drive.
- Back and forward move between viewed cars, because each selection is a `pushState` entry.
- The selects are not back-filled when restoring, which would cost two more requests to populate
  lists the user has not opened. The restored car's brand and model appear in the result header,
  where the user actually looks.
- Ids come from `gen_random_uuid()` and never change, so links stay valid across re-imports. A
  variant that disappears from the source yields `404`, which renders as its own "no longer exists"
  state rather than a connection error (`docs/api-client.md`).

## Rejected alternatives

**The sketch's `?marka&model&wersja` slugs.** Unimplementable. It needs name-to-id resolution the
API does not offer, and the middle level does not even exist as a resource. Building slugs
client-side would mean inventing identifiers that nothing on the server recognises, and they would
break the moment a version name changed.

**All four ids, `?brandId&modelId&versionId&variantId`.** Three of the four are derivable from the
fourth, so it is 108 redundant characters of URL and four ways for a link to become internally
inconsistent.

**`?versionId=…`.** Restores a version, not a car, and so cannot pick between its variants — the
exact failure ADR 0005 exists to prevent.

**Path segments, `/wariant/<uuid>`.** Prettier, but needs either a router (ADR 0001) or server
rewrite rules for deep links. A query parameter on one path is served correctly by every static
host with no configuration.
