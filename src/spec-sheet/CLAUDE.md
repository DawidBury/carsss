# `spec-sheet` — the result card

## Owns

Everything that renders once a variant is chosen: the header, the three spec groups, the share
link, and the query that fetches a variant by id.

Files: `queries.ts`, `rows.ts`, `SpecSheet.vue`, `SpecRow.vue`.
The field-by-field mapping table and the responsive rules: `docs/ui.md`. Why fields were dropped and
added: ADR 0007. Share URL: ADR 0006. Breakpoints: ADR 0012. Cache and seeding:
`docs/api-client.md`.

## Boundaries

- Imports `api` and `format`. Never `search` or `browse`.
- Receives a variant id as a prop. It does not know whether that id came from the search field, a
  select, or the URL, and it must not care.
- `rows.ts` is pure and holds no Vue. It turns a `Variant` into groups of already-formatted strings,
  so the whole mapping is testable without mounting anything.
- Does not format numbers or translate enums itself. It composes `format`.

## Decisions not to reverse

- **The unit is the variant, never the version.** Acceleration, gearbox and drive live on the
  variant, and one version routinely has several variants with different numbers — measured, 111 of
  Audi A4's 193 versions. Rendering version-level data as if it described a car is the exact defect
  ADR 0005 exists to prevent.
- **Every row is always rendered, in a fixed order, `null` or not.** A sheet whose shape changes per
  car cannot be scanned or compared. Do not hide unknown rows.
- **Three groups.** The third is `Osiągi i eksploatacja`, renamed because it carries the two
  capacities. Adding a fourth group breaks the approved design; a row that fits nowhere is a signal
  to re-read ADR 0007, not to add a section.
- **Dropped fields stay dropped.** Engine code, torque, cylinder layout, top speed and production
  years are not collected by the backend by design. Do not add empty rows for them, and do not parse
  them out of `version.name`.
- **No id is displayed.** The sketch showed readable slugs; ours are opaque UUIDs and mean nothing
  to a person.
- **The `170px minmax(0, 1fr)` group grid is a desktop-only layout and stacks below the phone
  breakpoint.** That fixed column plus the insets consume a constant 274px of viewport, so on a
  phone it starves the column carrying every value the visitor came for — measured, 46px at 320px,
  with the label crushed to zero and painted on top of the value. Do not reintroduce a fixed label
  column at phone widths, and do not "simplify" the stacked rule away (ADR 0012, `docs/ui.md`).
- **`SpecRow.vue` is not responsive and does not need to be.** Once the group stacks, a row has
  263.6px at a 320px viewport against a measured 226px requirement, so label and value stay on one
  line everywhere. Stacking the row as well would double the sheet's height on the device with the
  least of it.

## Traps

- **`404` is not an error panel.** A shared `?v=` pointing at a variant that no longer exists is an
  expected outcome of a kept link, and renders as its own "no longer exists" state. Showing "błąd
  połączenia z API" tells the user to retry something that can never succeed
  (`docs/api-client.md`).
- **The header deliberately repeats gearbox and drive** that also appear as rows in group two. The
  title is a version name and is therefore ambiguous on its own. This is not duplication to clean
  up.
- **The header meta omits a missing segment; it does not dash it.** Prose, not a table — the
  opposite of the row rule. Both rules are in `docs/ui.md`.
- **`generation` and `bodyStyle` are rendered verbatim**, mixed casing and all. They are
  unnormalised source labels.
- **Only `acceleration0To100S` and `version.fuelType` can be assumed present.** Everything else is
  `null` often enough to see daily — boot capacity 16.3% of variants, fuel tank 11.2%, generation
  and body style 23.4% each. Never use `!` on these.
- **The share URL is built from `location`, not stored.** Building it at render keeps it correct when
  the app is served from a different origin or path.
- **The share row shares the group's grid, and must share its breakpoint.** Left two-column on a
  phone, a 55-character URL breaks into a 205px-tall stack of characters inside a 46px column.
  Whatever happens to `.spec-sheet-group`'s columns happens to `.spec-sheet-share`'s.
- **The 34px title is reduced on phones for vertical economy, not because it overflows.** Measured:
  the longest unbreakable token in the catalogue's version names renders inside its box at 320px
  with zero ink overflow. Do not add `overflow-wrap` or `word-break` to the title to fix a problem
  that was measured not to exist (ADR 0012).
