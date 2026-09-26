# `app` — screen composition

## Owns

The one screen, the four pieces of client state, the URL, the query client, and the two pieces of
chrome shared across panels.

Files: `SearchScreen.vue`, `BlueprintPanel.vue`, `ErrorPanel.vue`, `EmptyState.vue`, `url.ts`,
`queryClient.ts`, `styles.css`, `main.ts`.

State ownership table: `docs/architecture.md`. Cache and retry policy: `docs/api-client.md`.
Why there is no router: ADR 0001. Why there is no store: ADR 0002. Breakpoints and the
per-component responsive rules: ADR 0012 and `docs/ui.md`.

## Boundaries

- The only module allowed to import from `search`, `browse` and `spec-sheet`. They never import each
  other, so anything two of them need is lifted to here.
- Holds client state only. Server state is `vue-query`'s and is never copied into a `ref` here.
- Composes; it does not compute. Grouping belongs to `browse`, row building to `spec-sheet`,
  formatting to `format`. A calculation appearing in `SearchScreen.vue` belongs somewhere else.

## Decisions not to reverse

- **Four `ref`s in `SearchScreen.vue`, passed as props, changed by events.** No store, no
  `provide`/`inject`, no reactive module singleton. The tree is one level deep and every dependency
  is visible in a template (ADR 0002).
- **The selected version id is not state.** A version is a grouping label, never a destination; the
  variant carries its version inside it.
- **`history.pushState` plus a `popstate` listener, in `url.ts` and nowhere else.** No router
  (ADR 0001). If a second screen ever arrives, this file is the only one that changes.
- **No dynamic `import()` anywhere in the codebase.** One route means nothing to split at route
  level; what is deferred is requests, via `enabled`, not code.
- **`styles.css` is vendored from `design/` unchanged, comments included.** It is third-party source
  material and is not ours to reformat. Everything derived from it into components is comment-free.
- **`BlueprintPanel.vue` exists because the frame has four call sites.** Nothing else from the
  sketch is extracted — a wrapper with one call site is not a component.
- **Responsive rules live in the scoped block of the component that owns the element.** There is no
  global responsive stylesheet and there is no responsive layer in `styles.css`, which is vendored
  and unwritable. This screen owns the screen and panel insets; `spec-sheet` owns the sheet's;
  `browse` owns the selects'. The breakpoint value is consequently repeated across scoped blocks —
  CSS offers no way to share one without a build step this project does not have. Its single
  documented home is `docs/ui.md`; do not "fix" the repetition by adding a preprocessor.
- **The screen and panel insets shrink on a phone because the spec sheet needs the width**, not for
  looks. They are what puts the stacked value column above its measured requirement at 320px
  (ADR 0012). Restoring `--space-6` at phone widths silently re-breaks the sheet.

## Traps

- **`refetchOnWindowFocus` and `refetchOnReconnect` are off, and `retry` is conditional.**
  `vue-query`'s defaults are wrong for daily-scraped data and for an API where `4xx` means the
  request itself was wrong. Restoring the defaults would refetch the catalogue on every tab switch
  and retry a `400` three times.
- **Restoring from `?v=` must not back-fill the selects.** That fires two requests to populate lists
  the user has not opened. The restored car's brand and model appear in the result header
  (ADR 0006).
- **`pushVariantId` on selection, not on every keystroke.** The search text is not in the URL; only
  a chosen variant is. Writing history entries while someone types fills the back button with
  garbage.
- **`popstate` must update the id without pushing another entry**, or back and forward fight each
  other.
- **A malformed or unknown `?v=` is not a crash.** Anything that is not a UUID is treated as absent;
  a well-formed id the server does not have yields `404`, which `spec-sheet` renders as its own
  state.
- **`main.ts` mounts and installs the query client. Nothing else.** It is not a place for
  configuration that belongs in `queryClient.ts`.
- **`.search-screen` uses `100dvh`, not `100vh`.** On a phone `100vh` counts the browser toolbar, so
  the empty state gains a toolbar's worth of scroll with nothing under it. The two units are
  identical on desktop, so this is not a desktop change and must not be reverted as one.
