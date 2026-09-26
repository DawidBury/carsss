# `browse` — the three cascading selects

## Owns

The secondary path to a car (ADR 0004): brand → model → version/variant, and the client-side
derivation of versions that the backend does not serve.

Files: `queries.ts`, `paging.ts`, `grouping.ts`, `CascadingSelects.vue`.
Why the third select carries two taxonomy levels: ADR 0005. Why paging is bounded: ADR 0010.
Ordering, option labels and the responsive rules: `docs/ui.md`. Why the breakpoints are what they
are: ADR 0012. Cache keys and seeding: `docs/api-client.md`.

## Boundaries

- Imports `api` and `format`. Never `search` or `spec-sheet`.
- Owns no selection. The three chosen ids live in `app/SearchScreen.vue`; this module receives them
  as props and emits changes.
- Owns the paging loop, because the bound on it is a product decision rather than a transport one.
  `api` deliberately returns one page at a time.
- Owns grouping. `groupVariantsByVersion` exists here and nowhere else, and it is a pure function
  so it can be tested without a component.

## Decisions not to reverse

- **Versions are derived, never fetched.** There is no `GET /v1/versions` and there deliberately
  never will be (`../carsss/docs/api.md` §2). Do not add a query for one; do not build a lookup
  table keyed by version id and treat it as a resource.
- **Native `<select>` and `<optgroup>`.** Keyboard navigation, type-ahead and group announcement
  come free and correct. Replacing them with a custom listbox means rebuilding all three by hand.
- **The popup is themed with `appearance: base-select`, never with a custom listbox.** It is the
  one mechanism that styles the open dropdown without giving up the native element, so the decision
  above survives. Every rule for it lives behind `@supports (appearance: base-select)`; a browser
  without it keeps its own popup, which is what all of them rendered before. Look: `docs/ui.md`.
- **A version with one variant still renders a group with one option.** Two shapes for the same
  thing is worse than one redundant one.
- **Group order is `Intl.Collator('pl', { numeric: true })` on the version name; option order is a
  fixed enum order.** Never the API's acceleration order — it interleaves variants of versions
  whose ranges overlap, so the same version's options would not sit together.
- **`truncated` is rendered, never swallowed.** A list that quietly omits the car someone wants is
  the worst available outcome (ADR 0010).
- **Nothing is fetched on mount.** Each query is `enabled` only once its parent selection exists.
- **The 900px breakpoint is measured, and it does two jobs.** Below it the three selects are one
  column *and* their text is 16px. Both rules belong to that one block.

## Traps

- **The brand query is gated on `@focusin`, not `@focus`.** Under `base-select` a click puts focus
  straight onto an `<option>`, and `focus` does not bubble — so a `@focus` listener on the
  `<select>` never runs and the brand list silently stays empty. `focusin` bubbles and fires in both
  appearances.
- **Do not add a `<legend>` to the `<optgroup>`s.** Chrome renders the `label` attribute in the base
  appearance and styles it from the `optgroup` rule; a `<legend>` is redundant there and is markup
  that older browsers would have to drop.
- **The cursor belongs to the paging loop and never leaves it.** A cursor is valid only against the
  identical filter set and sort; re-sending one with a changed `modelId` is a `400`, not a silent
  reset. Nothing outside `paging.ts` should ever hold a cursor value.
- **Keyset paging is sequential.** Page *n+1* needs page *n*'s cursor, so the loop cannot be
  parallelised and its cost is round trips, not bytes. This is why the bound exists.
- **Do not lower `limit` below 200.** It is the maximum the API allows and it is what keeps the
  common case at one request; halving it doubles the round trips for no benefit.
- **Changing the brand must clear the model and the variant; changing the model must clear the
  variant.** A stale child id silently points at a car from a different brand.
- **Selecting an option must seed `['variant', id]` before setting the id**, or the browse path pays
  a request for a variant it already holds in full (`docs/api-client.md`).
- **The option label omits a `null` attribute rather than showing a dash.** A dropdown reading
  `manualna · —` is noise; unknown drive simply does not appear in the label. Contrast with the
  spec sheet, where the row does show a dash — the rules differ on purpose.
- **The selects' 16px text must stay inside the 900px block.** It exists because iOS Safari zooms
  the page on focus of a control computing below 16px, and `.input`'s 14px lives in vendored
  `styles.css` which is not ours to edit. Applied at every width instead, it widens each select
  until three of them no longer fit side by side below ~986px — breaking the very breakpoint it
  sits in.
- **`Model / generacja` is a label inherited from the sketch, and slightly untrue.** `GET /v1/models`
  returns a bare name (`A4`); generation lives on the version below. Do not try to make the label
  honest by fetching generations to build it — that is the whole variant page load, per model, to
  decorate a dropdown.
