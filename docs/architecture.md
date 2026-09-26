# Architecture

## Module layout

```
src/
  api/         transport and the shape of what comes back
  format/      Polish text and number formatting
  search/      the combobox and its query
  browse/      the three cascading selects and their queries
  spec-sheet/  the result card
  app/         screen composition, URL synchronisation, query client setup
```

Modules are split by **feature**, and named for what is in them. There is no `domain/`,
`application/`, `infrastructure/` or `presentation/`. `api/` and `format/` are not layers — they
are leaf modules named for the thing they contain.

Feature modules are **flat**. A file is named for its role (`queries.ts`, `grouping.ts`,
`SpecSheet.vue`), and subdirectories appear only when a module exceeds roughly eight files.
Creating `components/`, `composables/` and `utils/` inside a four-file module is the layered
split under a different name.

## Dependency direction

```
                 app
        ┌─────────┼─────────┐
     search    browse    spec-sheet
        └─────────┼─────────┘
             ┌────┴────┐
            api      format
```

- `app` may import from every feature.
- Features may import from `api` and `format`.
- **Features never import from each other.** Anything two features need is either lifted into
  `app` as state, or belongs in `api`/`format`.
- `api` and `format` import nothing from `src/`. `api` does not import `format`: the transport
  layer never produces Polish text.

The rule is enforced by review, not by tooling. Adding an ESLint boundary plugin to police six
directories is the kind of machinery this project rejects.

## State ownership

### Server state — `vue-query`, and nowhere else

Anything cached, refetchable and keyed by a request is server state. It is never copied into a
`ref`, never mirrored into a store, and never "synced" back and forth. Components read it from
the query result.

Cache keys, staleness and retry policy: `docs/api-client.md`.

### Client state — one component, no store

The whole of it:

| State | Owner | Read by |
|---|---|---|
| search text | `app/SearchScreen.vue` | `search`, and as the enabling condition of the search query |
| selected brand id | `app/SearchScreen.vue` | `browse` |
| selected model id | `app/SearchScreen.vue` | `browse` |
| selected variant id | `app/SearchScreen.vue` | `spec-sheet`, `app/url.ts` |

There are four values, one owner and one or two readers each. **This does not need a store.**
Pinia would add a global singleton, devtools plumbing and a second place for state to live, in
exchange for nothing — the component tree is one level deep and props and events reach every
reader directly. `provide`/`inject` is rejected for the same reason: it hides a dependency that
a prop states outright. ADR 0002.

The selected version id is deliberately **not** state. A version is a grouping label in the third
select, never a destination; the variant determines everything, and the variant carries its
version inside it.

## Data flow

### The search path — primary

```
user types
  → useSearchSuggestions(query)            search/queries.ts, debounced 250 ms inside
  → GET /v1/search?q=…&limit=20            one request, no paging
  → listbox of variant rows
user picks a row
  → selectedVariantId = row.id
  → useVariant(id)                         spec-sheet/queries.ts
  → GET /v1/variants/{id}
  → spec sheet + history.pushState('?v=' + id)
```

A search hit is already a variant, so its `id` goes straight to `GET /v1/variants/{id}` with no
intermediate lookup.

### The browse path — secondary

```
brand select    → useBrands()                    GET /v1/brands?limit=200
model select    → useModels(brandId)             GET /v1/models?brandId=…&limit=200
version/variant → useModelVariants(modelId)      GET /v1/variants?modelId=…&limit=200, paged
                → groupVariantsByVersion()       browse/grouping.ts, client-side
                → <optgroup> per version, <option> per variant
user picks an option
  → selectedVariantId = variant.id
  → the same tail as the search path
```

The third select carries the fourth taxonomy level: the `<optgroup>` label is the version, each
`<option>` is one variant. ADR 0005.

Picking a variant from the browse path costs **no extra request**: the paged
`GET /v1/variants?modelId=…` response already contains the complete variant, and `useVariant(id)`
is seeded from it (`docs/api-client.md`, cache seeding).

### Restoring from a URL

```
page load with ?v=<uuid>
  → useVariant(id) → GET /v1/variants/{id} → spec sheet
```

One request restores the entire result. The selects are **not** back-filled on load: doing so
would fire two more requests to populate lists the user has not opened. They populate when the
user opens them, and the brand and model of the restored variant are shown in the result card
where they belong. ADR 0006.

## Why there is no router

One screen, one query parameter. Vue Router's value is matching many routes and code-splitting
them; neither exists here. `history.pushState`, `popstate` and `URLSearchParams` are platform
APIs that do the whole job in one composable, `app/url.ts`. ADR 0001 records the trigger for
revisiting: the second screen.

## Code splitting

There is one route, so there is nothing to split at route level, and **no dynamic `import()`
appears in this codebase**. The shipped bundle is Vue plus `@tanstack/vue-query` plus the screen
— splitting it would add round trips to a single-screen app that is already small.

What is deferred is not code but **requests**: the brands query does not run until the user opens
the brand select, and the models and variants queries do not run until their parent selection
exists. This is expressed with `enabled` on each query, not with lazy components.

## Rendering and the design system

`design/styles.css` is vendored as `src/app/styles.css` **unchanged**, comments and all — it is
third-party source material and is not ours to reformat. Component styles that are not in it go
in the SFC's `<style scoped>` block. No CSS framework, no CSS-in-JS: the design system is already
plain CSS on plain HTML and needs no build step.

The blueprint frame — `.card.blueprint` with four `<i class="corner">` marks — repeats on every
panel of the screen. That is four call sites, so it is extracted as `app/BlueprintPanel.vue`.
Nothing else from the sketch is extracted: a wrapper with one call site is not a component.

## Tests

Vitest 5, colocated as `*.test.ts` next to the unit they cover. What is worth testing here is the
logic that is easy to get wrong and invisible in review:

| Unit | What the test fixes |
|---|---|
| `format/numbers.ts` | Polish separators, decimal places per field, `null` rendering |
| `format/labels.ts` | every enum member maps, including `null` |
| `browse/grouping.ts` | grouping and ordering, versions with one variant, page boundaries |
| `api/errors.ts` | each `type` maps to its variant, non-JSON and network failure |
| `app/url.ts` | read, write, `popstate`, absent and malformed `?v=` |

Component tests are not written for rendering. A test asserting that a `<div>` contains text
restates the template and breaks on every design change.

Fixtures are captured from the live API and stored as JSON. No test performs a live HTTP request.
