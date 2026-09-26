# ilejedzie.pl — frontend knowledge base

Vue 3 single-screen search over the Cars API. A text field and three cascading selects resolve
to **one fully specified car** and render its spec sheet.

This file holds **binding decisions**. An agent who believes any of them is wrong says so plainly
and argues it — but may never depart from them quietly.

The engineering culture of the backend (`../carsss/CLAUDE.md`) applies here verbatim: no DDD,
hard YAGNI, zero comments, English files and documentation, every fact has exactly one home,
real output or nothing. It is inherited, not restated.

## Stack

| | |
|---|---|
| Vue | 3.5.42, `<script setup>`, Composition API |
| TypeScript | 6.0.3, `strict` — **not** 7; ADR 0011 |
| Build | Vite 8 |
| Tests | Vitest 5 |
| Server state | `@tanstack/vue-query` 5 |
| Router | **none** — ADR 0001 |
| Store | **none** — ADR 0002 |

Runtime required by Vite 8 and Vitest 5: **Node ≥ 22.12**. This machine has v24.20.0 under `nvm`,
which is what the toolchain runs on, but the **default shell still resolves `node` to v11.15.0**
with npm 6. A command run in a fresh shell without selecting a Node fails with a syntax error from
inside a dependency, which reads like a broken package rather than a wrong runtime. Select the
Node first. Adding an `engines` field to `package.json` would turn that into a clear refusal.

Every further dependency must earn its place in writing, in an ADR, with what it does that the
platform cannot and what it costs in bundle size. A dependency that duplicates the platform is
a defect.

## The upstream contract

The backend at `../carsss` is **read-only and already built**. It is not ours to change. Its HTTP
contract lives in `../carsss/docs/api.md` and is the single source of truth for response shapes,
filters, paging and errors. **Nothing in this repository restates it.** When our documentation
needs a fact about the API, it links there.

Facts that bind the frontend, because they shape the UI rather than the contract:

- `null` means *the source does not know*. It is never absent from a response, and the UI must
  never collapse it into `""` or `0`. Rendering rule: `docs/ui.md`.
- Every `id` is an opaque public UUID. Never parsed, never sorted by, never used to derive
  anything, never shown to a user.
- `GET /v1/search` is capped at 20 results and has no paging. No paging is designed onto it.
- `GET /v1/variants` pages by opaque cursor. A cursor is valid only against the exact same
  filters and sort; changing either while keeping the cursor is a `400`.
- `gearbox`, `drive` and `fuelType` are closed English enums. Their Polish display text lives in
  exactly one place — `src/format` (ADR 0009).
- An unknown query parameter is a `400`. Never send one speculatively.

## Binding decisions

### 1. The four-level taxonomy is not collapsed

The backend taxonomy is `brand → model → version → variant`. The **variant** is the only level
at which a car is fully determined: acceleration, gearbox and drive live there. The sketch was
drawn against a three-level model and would have shown a version's spec sheet.

Measured on the live data: of Audi A4's 193 versions, 124 have more than one variant and **111
of those have variants with different 0–100 times**. Version `1.9 TDI 110KM 81kW 1995-2000` has
two variants — manual 11.3 s and automatic 12.8 s. Collapsing the fourth level displays a wrong
number for the majority of versions.

Therefore the unit of everything user-facing is the **variant**: the search result, the spec
sheet, the share URL. The version is a grouping label, never a destination. ADR 0005.

### 2. Search is the primary path, the selects are secondary

`GET /v1/search` answers in one request, ranks by relevance, corrects typos and prefix-matches
the last token while the user types. It is unaffected by catalogue size.

The cascading selects cannot be primary: enumerating a model's versions requires paging its
variants client-side, because the backend has no `GET /v1/versions` and deliberately never will
(`../carsss/docs/api.md` §2). That costs 2 requests and 107 KB for Audi A4, and grows with every
import. The selects survive as a browsing path for a user who does not know what to type, with
bounded paging and a visible failure mode. ADR 0004, ADR 0010.

### 3. Four levels are expressed in three selects

The drawn layout of three selects is kept exactly. The third select — labelled `Wersja` as drawn
— lists **variants grouped by version** using native `<optgroup>`: the group label is the version
name, each option is one variant identified by its gearbox and drive. Four levels, three
controls, no invented widget, native keyboard behaviour. ADR 0005.

### 4. The share URL carries the variant id and nothing else

`?v=<variantId>`. A variant id alone restores the entire result through
`GET /v1/variants/{id}`, which returns the version, model and brand nested inside it. The
sketch's `?marka=bmw&model=e91&wersja=325xi-at` cannot be implemented: this API has no slugs and
no resolve-by-name endpoint, and the backend is not ours to extend. ADR 0006.

### 5. Server state and client state are separate and neither has a store

Server state — anything cached, refetchable and keyed by request — lives in `vue-query` and
nowhere else. Client state is the search text, the three select values and the selected variant
id; it is owned by the one screen component and passed to its three children by props and
events. There is no Pinia, no `provide`/`inject`, no global reactive singleton. ADR 0002.

### 6. The visual design is kept, the data behind it is remapped

Layout, spacing, type scale, the blueprint frame and the three spec groups' shape are taken from
`design/Wyszukiwarka.dc.html` unchanged. The fields that fill them are remapped onto what the API
actually serves: the sketch's engine code, torque, cylinder layout, top speed and production
years are **not collected by the backend by design** and are dropped rather than faked; fuel
consumption, fuel tank, boot capacity and fuel type are added. Mapping table: `docs/ui.md`.
ADR 0007.

### 7. The desktop layout is frozen; phones get two breakpoints

The screen had exactly one layout rule that reacted to the viewport, and on a phone the spec
sheet's fixed label column starved the values beside it until the label text was painted on top of
them. The fix is two shared `max-width` breakpoints and nothing else: no CSS framework, no global
responsive stylesheet, no third tier. Every rule sits in the scoped block of the component that
owns the element, because `src/app/styles.css` is vendored and unwritable.

Above the wider breakpoint the rendering is the approved desktop design, unchanged to the pixel.
Values, the per-component rule table and what deliberately stays fixed: `docs/ui.md`. Why those
numbers, and the alternatives that were measured and rejected: ADR 0012.

### 8. The browser never sees the API key

The API sends no CORS headers — `OPTIONS /v1/search` with an `Origin` header returns `405`, so a
browser cannot call it directly. All traffic goes through a proxy that also injects `X-API-Key`.
In development that is the Vite dev proxy. The key is read at Vite config time from a
**non-`VITE_`-prefixed** variable so it is never inlined into the client bundle. ADR 0003.

## Code standard

- **Zero comments** — in code and in configuration. TypeScript, Vue SFCs, `vite.config.ts`,
  ESLint config, JSON, CI. Code that needs explaining gets a better name or an extracted
  function; configuration that needs explaining gets an entry in `docs/`, not a comment in the
  file. The one exception is `design/`, which is vendored source material and keeps its own
  comments; anything derived from it into `src/` is comment-free.
- **English** for all files, identifiers and documentation, including every `CLAUDE.md` and
  everything under `docs/`. User-facing copy is **Polish** — that is content, lives in the UI
  layer, and is confined to `src/format` and the `.vue` templates.
- `<script setup lang="ts">` everywhere. No Options API, no `defineComponent`.
- No `any`. No non-null assertion `!` on API data — `null` is a real value here and is handled.
- Components are named for what they show. A component with one call site and one variant is not
  a design system.

## Context engineering

Every module under `src/` has its own `CLAUDE.md`: what it owns, its boundaries, which local
decisions must not be reversed, and its traps. An agent working in a module reads that file
**before** changing it, and an agent changing the module's behaviour updates it in the same task.

## Agents

| Agent | Model | Role |
|---|---|---|
| `architect` | opus | structure, ADRs, module `CLAUDE.md`, type and signature contracts |
| `developer` | sonnet | all production code and tests |
| `code-reviewer` | sonnet | code review, edge cases, contract drift |

Flow: `architect` designs → `developer` implements → `code-reviewer` reviews. A blocking defect
goes back to its author; the reviewer does not fix it.

## Verification before reporting readiness

```
npm run typecheck
npm run test
npm run lint
npm run build
```

Real output or nothing. A claim that the app works is backed by a request that actually returned
data, not by a component that renders a fixture.

## Documents

| File | Content |
|---|---|
| `docs/architecture.md` | modules, dependency direction, data flow, state ownership |
| `docs/api-client.md` | proxy, key handling, endpoints used, paging, cancellation, error mapping |
| `docs/ui.md` | design-to-component mapping, field table, Polish formatting, combobox contract |
| `docs/adr/` | architectural decisions and their rejected alternatives |
| `../carsss/docs/api.md` | **upstream** HTTP contract — never duplicated here |

Every fact has **one** home. Restating a fact in a second document is a defect.
